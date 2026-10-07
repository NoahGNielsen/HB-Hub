<?php
// Checked here as well as in header.php, so nobody logs in while the site is in maintenance mode
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/maintenance.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

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
            header('Location: /', true, 303);
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
    <title>Login - HB Hub</title>
    <meta name="description" content="Login page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main>
        <form class="login" action="/userMgmt/login" method="post">
            <h1>Log in to HB Hub</h1>

            <?php if ($loginError !== null): ?>
                <p class="onboarding-error" role="alert"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>

            <div class="onboarding-field">
                <label for="login-name">Name</label>
                <div class="onboarding-input-wrap">
                    <input type="text" id="login-name" name="name" maxlength="64" required autocomplete="username"
                           value="<?= htmlspecialchars($loginName) ?>"<?= $loginName === '' ? ' autofocus' : '' ?>>
                </div>
            </div>

            <div class="onboarding-field">
                <label for="login-password">Password</label>
                <input type="password" id="login-password" name="password" maxlength="72" required autocomplete="current-password"<?= $loginName !== '' ? ' autofocus' : '' ?>>
            </div>

            <div class="onboarding-nav">
                <button type="submit" class="onboarding-btn onboarding-btn-primary">Log in</button>
            </div>

            <p class="login-switch">New here? <a href="/userMgmt/onboarding">Create an account</a></p>
        </form>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
