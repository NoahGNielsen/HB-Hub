<?php
// /discord: where "Log in with Discord" starts, and where Discord sends the browser back to (assets/php/discord.php
// explains the whole flow).
// - POST action=login (the button on the login page) sends the browser to Discord to log in.
// - GET ?code=...&state=... is Discord sending it back, after a login, a link from the settings page or a link right
//   after signing up (onboarding). ?error=... instead means they clicked Cancel on Discord (or it went wrong there).
// Where it goes afterwards depends on what was started: a login back to /login (with ?discord=<why> when it didn't
// work, login.php shows the message), a link to the settings' Discord card with a toast, a signup on to where they
// were headed. 404 when Discord login isn't set up in hbHubSiteConfig.php.
// Checked here as well as in header.php, so nobody logs in while the site is in maintenance mode
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/maintenance.php';
$hbHubSessionOptional = true; // logging in has to work without being logged in
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/discord.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

$discordClient = hbHubDiscordClient($siteConfig);
if ($discordClient === null) {
    http_response_code(404);
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}

/**
 * On to $location, with a toast when $toast is given.
 */
function discordRedirect(string $location, ?string $toast = null): never
{
    if ($toast !== null) {
        hbHubSetToast($toast);
    }
    header('Location: ' . $location, true, 303);
    exit;
}

/**
 * The login page, still headed for $returnPath, with ?discord=$why for its message when the login didn't work.
 */
function discordLoginPage(string $returnPath, ?string $why = null): string
{
    $query = hbHubReturnQuery($returnPath);
    if ($why !== null) {
        $query .= ($query === '' ? '?' : '&') . 'discord=' . $why;
    }
    return '/login' . $query;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? null) !== 'login') {
        http_response_code(400);
        require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/400.php';
        exit;
    }
    if ($hbHubUser !== null) {
        discordRedirect(hbHubReturnPath()); // already logged in
    }
    hbHubDiscordStart($discordClient, 'login', hbHubReturnPath());
}

$discordState = hbHubDiscordTakeState();
if ($discordState === null) {
    // Took longer than HBHUB_DISCORD_STATE_MINUTES, opened twice, or not started in this browser
    if ($hbHubUser !== null) {
        discordRedirect('/settings#discord', 'discordFailed');
    }
    discordRedirect(discordLoginPage(HBHUB_RETURN_DEFAULT, 'expired'));
}
['mode' => $discordMode, 'returnPath' => $discordReturnPath] = $discordState;

// Cancel on Discord: back to where they came from, without a fuss. The new account is made either way, so it's welcomed.
if (isset($_GET['error'])) {
    if ($discordMode === 'login') {
        discordRedirect(discordLoginPage($discordReturnPath));
    }
    discordRedirect($discordMode === 'signup' ? $discordReturnPath : '/settings#discord', $discordMode === 'signup' ? 'onboarding' : null);
}

$discordUser = hbHubDiscordFetchUser($discordClient, $_GET['code'] ?? null);

if ($discordMode === 'login') {
    if ($hbHubUser !== null) {
        discordRedirect($discordReturnPath);
    }
    if ($discordUser === null) {
        discordRedirect(discordLoginPage($discordReturnPath, 'failed'));
    }
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginCheckDB.php';
    $discordLoginError = loginDiscord($discordUser['id'], $discordNeedsTotp);
    if ($discordLoginError !== null) {
        discordRedirect(discordLoginPage($discordReturnPath, $discordLoginError));
    }
    if ($discordNeedsTotp) {
        discordRedirect(discordLoginPage($discordReturnPath)); // login.php asks for the 2FA code
    }
    discordRedirect($discordReturnPath, 'login');
}

// Linking (from the settings page, or right after signing up): to the account that's logged in now
$discordDonePage = $discordMode === 'signup' ? $discordReturnPath : '/settings#discord';
if ($hbHubUser === null) {
    discordRedirect('/login?return=' . rawurlencode($discordDonePage));
}
if ($discordUser === null) {
    discordRedirect($discordDonePage, 'discordFailed');
}
try {
    hbHubDatabase()->prepare('UPDATE `HBHub-Users` SET userDiscordId = ?, userDiscordName = ? WHERE userId = ?')
        ->execute([$discordUser['id'], $discordUser['name'], $hbHubUser['userId']]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userDiscordId is unique
        discordRedirect($discordDonePage, 'discordTaken');
    }
    error_log('Discord: could not link the account: ' . $e->getMessage());
    discordRedirect($discordDonePage, 'discordFailed');
}
discordRedirect($discordDonePage, $discordMode === 'signup' ? 'onboardingDiscord' : 'discordLinked');
