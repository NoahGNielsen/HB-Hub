<?php
// Shown by hbHubGamesPage() (access.php) instead of the Games section while it isn't open to the user (games_access in
// the site config). Looks the same as the "blocked" page (blockedPage.php).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <title>Games - HB Hub</title>
    <meta name="description" content="Games page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="blocked">
        <h1>Games are coming soon</h1>
        <p>They aren't open yet. Check back later!</p>
        <a class="blocked-link" href="/chat/">Go to the chat</a>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
