<?php
// Checked here as well as in header.php, so nobody logs in while the site is in maintenance mode
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/maintenance.php';
$hbHubSessionOptional = true; // has to work without being logged in
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

// Already logged in, so go straight to where they were headed
if ($hbHubUser !== null) {
    header('Location: ' . hbHubReturnPath(), true, 302);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginCheckDB.php';
[$loginAttemptsMax, $loginLockoutMinutes] = hbHubLoginLimits($siteConfig);

$loginName = '';
$loginError = null;

// "Log in with Discord" under the form, when it's set up (assets/php/discord.php). /discord sends the browser back
// here with ?discord=<why> when it didn't work.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/discord.php';
$loginHasDiscord = hbHubDiscordClient($siteConfig) !== null;
$loginError = match ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['discord'] ?? null) : null) {
    'notLinked' => 'Den Discord-konto er ikke koblet til en HB Hub-konto. Log ind med dit navn og din adgangskode, og kobl Discord til under dine indstillinger.',
    'banned' => 'Denne konto er blevet udelukket.',
    'failed' => 'Det virkede ikke at logge ind med Discord. Prøv igen.',
    'expired' => 'Det tog for lang tid. Prøv at logge ind med Discord igen.',
    default => null,
};

/**
 * Logged in, so on to where they were headed with the "welcome back" toast.
 */
function loginDone(): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast('login');
    header('Location: ' . hbHubReturnPath(), true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'cancelTotp') {
    // "Use another account" on the 2FA step
    loginCancelPending();
    header('Location: /login' . hbHubReturnQuery(), true, 303);
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('code', $_POST)) {
    // The 2FA code, after the right password
    $loginError = loginCheckTotp($_POST['code'], $loginAttemptsMax, $loginLockoutMinutes);
    if ($loginError === null) {
        loginDone();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginName = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($loginName === '' || $password === '') {
        $loginError = 'Skriv dit navn og din adgangskode.';
    } elseif (mb_strlen($loginName) > 35 || strlen($password) > 72) {
        // Longer than the userName column / what onboarding allows, so it can't match an account
        $loginError = 'Forkert navn eller adgangskode.';
    } else {
        $loginError = loginCheckDB($loginName, $password, $loginAttemptsMax, $loginLockoutMinutes, $loginNeedsTotp);
        if ($loginError === null && $loginNeedsTotp) {
            // On to the 2FA step, as a GET so reloading doesn't send the password again
            header('Location: /login' . hbHubReturnQuery(), true, 303);
            exit;
        }
        if ($loginError === null) {
            loginDone();
        }
    }
}

// The right password was entered and the 2FA code is next
$loginIsTotpStep = loginPendingUserId() !== null;
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <script src="/assets/js/login.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/login.js') ?>" defer></script>
    <title>Log ind - HB Hub</title>
    <meta name="description" content="Log ind på HB Hub, den elevlavede chat-, casino- og spilside for HANSENBERG Tekniske Gymnasium (HTX): din klassechat, private beskeder, legechips og spil.">
</head>
<body>
    <main>
        <?php if ($loginIsTotpStep): ?>
        <form class="login login-totp" action="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post">
            <h1>Totrinslogin</h1>

            <section class="onboarding-step" aria-labelledby="login-q3">
                <h2 id="login-q3"><label for="login-code">Skriv koden fra din godkendelsesapp</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="text" id="login-code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required
                           autocomplete="one-time-code" autofocus aria-describedby="login-code-hint">
                </div>
                <p class="onboarding-hint" id="login-code-hint">Den 6-cifrede kode til HB Hub. Den skifter hvert 30. sekund.</p>
            </section>

            <?php if ($loginError !== null): ?>
                <p class="onboarding-error" role="alert"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>

            <div class="onboarding-nav">
                <!-- Belongs to the form below this one, so pressing Enter in the code field logs in instead -->
                <button type="submit" class="onboarding-btn" form="login-cancel-totp">Brug en anden konto</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary">Log ind</button>
            </div>
        </form>
        <form id="login-cancel-totp" action="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" hidden>
            <input type="hidden" name="action" value="cancelTotp">
        </form>
        <?php else: ?>
        <form class="login" action="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post">
            <h1>Velkommen tilbage!</h1>

            <section class="onboarding-step" aria-labelledby="login-q1">
                <h2 id="login-q1"><label for="login-name">Hvad er dit brugernavn?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="text" id="login-name" name="name" maxlength="35" required autocomplete="username" aria-describedby="login-name-about"
                           value="<?= htmlspecialchars($loginName) ?>"<?= $loginName === '' ? ' autofocus' : '' ?>>
                </div>
            </section>

            <section class="onboarding-step onboarding-reveal" aria-labelledby="login-q2" data-password-step>
                <h2 id="login-q2"><label for="login-password">Hvad er din adgangskode?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="password" id="login-password" name="password" maxlength="72" required autocomplete="current-password"<?= $loginName !== '' ? ' autofocus' : '' ?>>
                </div>
            </section>

            <?php if ($loginError !== null): ?>
                <p class="onboarding-error" role="alert"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Næste</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Log ind</button>
            </div>

            <?php if ($loginHasDiscord): ?>
                <p class="userMgmt-or"><span>eller</span></p>
                <!-- Belongs to the form below this one, so it doesn't need the name and password -->
                <button type="submit" class="onboarding-btn userMgmt-discord" form="login-discord"><?= hbHubDiscordLogo() ?>Log ind med Discord</button>
            <?php endif; ?>

            <p class="userMgmt-switch">Ny her? <a href="/onboarding<?= htmlspecialchars(hbHubReturnQuery()) ?>">Opret en konto!</a></p>
        </form>
        <?php if ($loginHasDiscord): ?>
        <form id="login-discord" action="/discord<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" hidden>
            <input type="hidden" name="action" value="login">
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
