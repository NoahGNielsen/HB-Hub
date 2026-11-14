<?php
// Minesweeper: the classic, Windows 95 look and all. This is a normal Games page (side bar, back link, title, a
// Leaderboard button and the rules under it, from assets/php/games/minesweeper/rules.php), with the game itself in a
// frame: frame.php, Nick Arocho's Minesweeper (see the credits), whose mines are on the server for the leaderboard
// (assets/php/games/minesweeper/minesweeper.php). The frame is made as tall as the game by assets/js/minesweeper.js,
// so it never needs its own scroll bar, and the same script fills in the line above it (data-minesweeper-result) with
// how a game went: the time and your best after a win, or what went wrong.
//
// It's also the guest Minesweeper at /play/minesweeper, for visitors without an account: guestGames/minesweeper/ sets
// $guestGame and includes this page (see assets/php/guestGames/guestGames.php). Then it has the frontpage's top bar,
// the guest bar and the text for search engines, no leaderboard, and the frame is the guest one
// (/play/minesweeper/frame), whose games aren't stored. data-minesweeper-guest has where signing up goes, for the line
// after a win.
$guestGame ??= null;
if ($guestGame === null) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
    hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $headerHomeNav = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/minesweeper.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/minesweeper.css') ?>">
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/head.php'; ?>
    <?php else: ?>
        <title>Minesweeper - HB Hub</title>
        <meta name="robots" content="noindex">
        <meta name="description" content="Play Minesweeper on the HB Hub">
    <?php endif; ?>
</head>
<body<?= $guestGame !== null ? ' class="home-body"' : '' ?>>
    <?php if ($guestGame !== null): ?>
        <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>
    <?php else: ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <?php endif; ?>
    <main class="casino games minesweeper"<?= $guestGame !== null ? ' data-minesweeper-guest="' . htmlspecialchars(hbHubGuestAccountLinks($guestGame)['signup']) . '"' : '' ?>>
        <?php if ($guestGame !== null): ?>
            <a class="casino-back" href="/">&larr; HB Hub</a>
        <?php else: ?>
            <a class="casino-back" href="/games/">&larr; Games</a>
        <?php endif; ?>
        <div class="casino-title-row">
            <h1>Minesweeper</h1>
            <?php if ($guestGame === null): ?>
                <a class="casino-btn" href="/games/minesweeper/leaderboard" data-minesweeper-leaderboard>Leaderboard</a>
            <?php endif; ?>
        </div>
        <?php if ($guestGame !== null): ?>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/top.php'; ?>
        <?php endif; ?>
        <p class="casino-intro">
            Open every square that doesn't hide a mine. The numbers tell you how many mines are next to a square.
            <?= $guestGame !== null ? 'Make a free account to get your wins on the leaderboard.' : 'Win fast for a spot on the leaderboard.' ?>
        </p>

        <p class="minesweeper-result" data-minesweeper-result aria-live="polite" hidden></p>

        <iframe class="minesweeper-frame" src="<?= $guestGame !== null ? '/play/minesweeper/frame' : '/games/minesweeper/frame' ?>" title="Minesweeper" data-minesweeper-frame></iframe>

        <section class="casino-card casino-rules" aria-labelledby="minesweeper-rules-title">
            <h2 id="minesweeper-rules-title">How to play</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/rules.php'; ?>
        </section>
    </main>
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php endif; ?>
    <?php $hbHubShowSiteFooter = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/minesweeper.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/minesweeper.js') ?>" defer></script>
</body>
</html>
