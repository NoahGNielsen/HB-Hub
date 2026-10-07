<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chat.css') ?>">
    <title>Chat - HB Hub</title>
    <meta name="description" content="Chat page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main>
        <h1>Chat - HB Hub</h1>
        <p>Welcome to the Chat!</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>