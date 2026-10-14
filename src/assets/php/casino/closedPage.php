<?php
// Shown by hbHubCasinoPage() (access.php) instead of the casino while it isn't open to the user (casino_access in the
// site config). Looks the same as the "blocked" page (blockedPage.php).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <title>Casino - HB Hub</title>
    <meta name="description" content="Casino page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="blocked">
        <h1>The casino is coming soon</h1>
        <p>It isn't open yet. Check back later!</p>
        <a class="blocked-link" href="/chat/">Go to the chat</a>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
