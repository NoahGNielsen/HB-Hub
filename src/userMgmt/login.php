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

// Defaults used when an older config file doesn't have these settings yet
$loginAttemptsMax = max(1, (int) ($siteConfig['login_attempts_max'] ?? 5));
$loginLockoutMinutes = max(1, (int) ($siteConfig['login_attempts_lockout_time'] ?? 15));

$loginName = '';
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginName = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($loginName === '' || $password === '') {
        $loginError = 'Please enter your name and password.';
    } elseif (mb_strlen($loginName) > 64 || strlen($password) > 72) {
        // Longer than the userName column / what onboarding allows, so it can't match an account
        $loginError = 'Wrong name or password.';
    } else {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginCheckDB.php';
        $loginError = loginCheckDB($loginName, $password, $loginAttemptsMax, $loginLockoutMinutes);
        if ($loginError === null) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
            hbHubSetToast('login');
            header('Location: ' . hbHubReturnPath(), true, 303);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/login.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/login.js') ?>" defer></script>
    <title>Login - HB Hub</title>
    <meta name="description" content="Login page for the HB Hub">
</head>
<body>
    <main>
        <form class="login" action="/userMgmt/login<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post">
            <h1>Welcome back!</h1>

            <section class="onboarding-step" aria-labelledby="login-q1">
                <h2 id="login-q1"><label for="login-name">What are you known as?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="text" id="login-name" name="name" maxlength="64" required autocomplete="username"
                           value="<?= htmlspecialchars($loginName) ?>"<?= $loginName === '' ? ' autofocus' : '' ?>>
                </div>
            </section>

            <section class="onboarding-step onboarding-reveal" aria-labelledby="login-q2" data-password-step>
                <h2 id="login-q2"><label for="login-password">What's your password?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="password" id="login-password" name="password" maxlength="72" required autocomplete="current-password"<?= $loginName !== '' ? ' autofocus' : '' ?>>
                </div>
            </section>

            <?php if ($loginError !== null): ?>
                <p class="onboarding-error" role="alert"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Next</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Log in</button>
            </div>

            <p class="userMgmt-switch">New here? <a href="/userMgmt/onboarding<?= htmlspecialchars(hbHubReturnQuery()) ?>">Go to onboarding</a></p>
        </form>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
