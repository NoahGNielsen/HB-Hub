<?php
// Minesweeper: the classic, Windows 95 look and all. This is a normal Games page (side bar, back link, title, a
// Leaderboard button and the rules under it, from assets/php/games/minesweeper/rules.php), with the game itself in a
// frame: frame.php, Nick Arocho's Minesweeper (see the credits), whose mines are on the server for the leaderboard
// (assets/php/games/minesweeper/minesweeper.php). The frame is made as tall as the game by assets/js/minesweeper.js,
// so it never needs its own scroll bar, and the same script fills in the line above it (data-minesweeper-result) with
// how a game went: the time and your best after a win, or what went wrong.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/minesweeper.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/minesweeper.css') ?>">
    <title>Minesweeper - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Play Minesweeper on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games minesweeper">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Minesweeper</h1>
            <a class="casino-btn" href="/games/minesweeper/leaderboard" data-minesweeper-leaderboard>Leaderboard</a>
        </div>
        <p class="casino-intro">
            Open every square that doesn't hide a mine. The numbers tell you how many mines are next to a square. Win
            fast for a spot on the leaderboard.
        </p>

        <p class="minesweeper-result" data-minesweeper-result aria-live="polite" hidden></p>

        <iframe class="minesweeper-frame" src="/games/minesweeper/frame" title="Minesweeper" data-minesweeper-frame></iframe>

        <section class="casino-card casino-rules" aria-labelledby="minesweeper-rules-title">
            <h2 id="minesweeper-rules-title">How to play</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/rules.php'; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/minesweeper.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/minesweeper.js') ?>" defer></script>
</body>
</html>
