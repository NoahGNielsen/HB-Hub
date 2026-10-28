<?php
// /discord: where "Log in with Discord" and "Sign up with Discord" start, and where Discord sends the browser back to
// (assets/php/discord.php explains the whole flow).
// - POST action=login (the button on the login page) sends the browser to Discord to log in.
// - POST action=onboarding (the button on onboarding's first step) sends it to Discord to sign up with it.
// - GET ?code=...&state=... is Discord sending it back, after a login, a sign-up or a link from the settings page.
//   ?error=... instead means they clicked Cancel on Discord (or it went wrong there).
// Where it goes afterwards depends on what was started: a login back to /login (with ?discord=<why> when it didn't
// work, login.php shows the message), a sign-up back to /onboarding to fill in the rest (with ?discord=failed when it
// didn't work; a Discord account that's already linked just logs in instead), a link to the settings' Discord card
// with a toast. 404 when Discord login isn't set up in hbHubSiteConfig.php.
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
 * $page (/login or /onboarding), still headed for $returnPath, with ?discord=$why for its message when it didn't work.
 */
function discordBackTo(string $page, string $returnPath, ?string $why = null): string
{
    $query = hbHubReturnQuery($returnPath);
    if ($why !== null) {
        $query .= ($query === '' ? '?' : '&') . 'discord=' . $why;
    }
    return $page . $query;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $discordAction = $_POST['action'] ?? null;
    if (!in_array($discordAction, ['login', 'onboarding'], true)) {
        http_response_code(400);
        require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/400.php';
        exit;
    }
    if ($hbHubUser !== null) {
        discordRedirect(hbHubReturnPath()); // already logged in
    }
    hbHubDiscordStart($discordClient, $discordAction, hbHubReturnPath());
}

$discordState = hbHubDiscordTakeState();
if ($discordState === null) {
    // Took longer than HBHUB_DISCORD_STATE_MINUTES, opened twice, or not started in this browser
    if ($hbHubUser !== null) {
        discordRedirect('/settings#discord', 'discordFailed');
    }
    discordRedirect(discordBackTo('/login', HBHUB_RETURN_DEFAULT, 'expired'));
}
['mode' => $discordMode, 'returnPath' => $discordReturnPath] = $discordState;

// Cancel on Discord: back to where they came from, without a fuss
if (isset($_GET['error'])) {
    discordRedirect(match ($discordMode) {
        'login' => discordBackTo('/login', $discordReturnPath),
        'onboarding' => discordBackTo('/onboarding', $discordReturnPath),
        default => '/settings#discord',
    });
}

$discordUser = hbHubDiscordFetchUser($discordClient, $_GET['code'] ?? null);

// Logging in, or signing up - which is logging in when that Discord account already has an HB Hub account
if ($discordMode === 'login' || $discordMode === 'onboarding') {
    if ($hbHubUser !== null) {
        discordRedirect($discordReturnPath);
    }
    if ($discordUser === null) {
        discordRedirect(discordBackTo($discordMode === 'login' ? '/login' : '/onboarding', $discordReturnPath, 'failed'));
    }
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginCheckDB.php';
    $discordLoginError = loginDiscord($discordUser['id'], $discordNeedsTotp);
    if ($discordMode === 'onboarding' && $discordLoginError === 'notLinked') {
        // Not on HB Hub yet: back to onboarding, which fills in the form from it and links it once the account is made
        hbHubDiscordSignupSet($discordClient, $discordUser);
        discordRedirect(discordBackTo('/onboarding', $discordReturnPath));
    }
    if ($discordLoginError !== null) {
        discordRedirect(discordBackTo('/login', $discordReturnPath, $discordLoginError));
    }
    if ($discordNeedsTotp) {
        discordRedirect(discordBackTo('/login', $discordReturnPath)); // login.php asks for the 2FA code
    }
    discordRedirect($discordReturnPath, 'login');
}

// Linking from the settings page: to the account that's logged in now
if ($hbHubUser === null) {
    discordRedirect('/login?return=' . rawurlencode('/settings#discord'));
}
if ($discordUser === null) {
    discordRedirect('/settings#discord', 'discordFailed');
}
try {
    hbHubDatabase()->prepare('UPDATE `HBHub-Users` SET userDiscordId = ?, userDiscordName = ? WHERE userId = ?')
        ->execute([$discordUser['id'], $discordUser['name'], $hbHubUser['userId']]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userDiscordId is unique
        discordRedirect('/settings#discord', 'discordTaken');
    }
    error_log('Discord: could not link the account: ' . $e->getMessage());
    discordRedirect('/settings#discord', 'discordFailed');
}
discordRedirect('/settings#discord', 'discordLinked');
