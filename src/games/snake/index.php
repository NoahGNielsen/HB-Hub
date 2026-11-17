<?php
// Snake: steer a snake around a 20x12 board, eat the apples to grow, and don't hit the edge or your own body. A normal
// Games page (side bar, back link, title, a Leaderboard button, and the rules under it from
// assets/php/games/snake/rules.php).
// The game runs in the browser (assets/js/snake.js draws it on the data-snake-canvas canvas), and the server plays
// along with it for the leaderboard (assets/php/games/snake/snake.php). So opening the page makes the next game on the
// server: data-snake-game has it ({"game": id, "rollsFrom": 0, "rolls": [...]}), and data-snake-bests the user's best
// score at each speed ({"slow": apples, ...}). Only the chosen speed is kept in localStorage (hbHubSnake). The line
// above the board (data-snake-note) says when a game doesn't count for the leaderboard, and why. The board's look (the
// brown frame, the olive bar on top, the checkered sand) is in assets/css/snake.css.
//
// It's also the guest Snake at /play/snake, for visitors without an account: guestGames/snake/ sets $guestGame and
// includes this page (see assets/php/guestGames/guestGames.php). Then it has the frontpage's top bar, the guest bar and
// the text for search engines, no leaderboard, and no server game: data-snake-guest tells snake.js to play it only in
// the browser, with the best scores kept in localStorage (hbHubSnake) instead of on the server.
$guestGame ??= null;
$snakeGame = null;
$snakeBests = null;
if ($guestGame === null) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/snake/snake.php';
    hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

    $db = hbHubDatabase();
    $snakeGame = hbHubSnakeNew($db, (int) $hbHubUser['userId']);
    $snakeBests = hbHubSnakeBests($db, (int) $hbHubUser['userId']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $headerHomeNav = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/snake.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/snake.css') ?>">
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/head.php'; ?>
    <?php else: ?>
        <title>Snake - HB Hub</title>
        <meta name="robots" content="noindex">
        <meta name="description" content="Play Snake on the HB Hub">
    <?php endif; ?>
</head>
<body<?= $guestGame !== null ? ' class="home-body"' : '' ?>>
    <?php if ($guestGame !== null): ?>
        <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>
    <?php else: ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <?php endif; ?>
    <?php if ($guestGame !== null): ?>
    <main class="casino games snake" data-snake data-snake-guest>
    <?php else: ?>
    <main class="casino games snake" data-snake data-snake-game="<?= htmlspecialchars(json_encode($snakeGame)) ?>"
          data-snake-bests="<?= htmlspecialchars(json_encode($snakeBests)) ?>">
    <?php endif; ?>
        <?php if ($guestGame !== null): ?>
            <a class="casino-back" href="/">&larr; HB Hub</a>
        <?php else: ?>
            <a class="casino-back" href="/games/">&larr; Games</a>
        <?php endif; ?>
        <div class="casino-title-row">
            <h1>Snake</h1>
            <?php if ($guestGame === null): ?>
                <a class="casino-btn" href="/games/snake/leaderboard">Leaderboard</a>
            <?php endif; ?>
        </div>
        <?php if ($guestGame !== null): ?>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/top.php'; ?>
        <?php endif; ?>
        <p class="casino-intro">
            Eat the apples to grow longer, and don't crash into the edge or your own tail. Use the arrow keys or WASD, or
            swipe on the board on a phone.
            <?= $guestGame !== null ? 'Make a free account to get your scores on the leaderboard.' : 'Normal and Fast have a leaderboard of the most apples eaten.' ?>
        </p>

        <p class="snake-note" data-snake-note aria-live="polite" hidden></p>

        <div class="snake-frame">
            <div class="snake-bar">
                <span class="snake-count" title="Apples eaten">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path class="snake-icon-apple" d="M12 7C14 5 20 5 20 12C20 17 16 21 14 21C13 21 12.5 20.5 12 20.5C11.5 20.5 11 21 10 21C8 21 4 17 4 12C4 5 10 5 12 7Z"/>
                        <path class="snake-icon-stem" d="M12 7Q12 4 13.5 2.5"/>
                        <path class="snake-icon-leaf" d="M13 4.5Q15 1.5 19 2.5Q17 5.5 13 4.5Z"/>
                    </svg>
                    <span class="games-visually-hidden">Score:</span>
                    <span data-snake-score>0</span>
                </span>
                <span class="snake-count" title="Your best at this speed">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path class="snake-icon-trophy" d="M7 3H17V5H21V8C21 11 19 13 16.5 13.5C15.8 15 14.5 16 13 16.3V19H17V21H7V19H11V16.3C9.5 16 8.2 15 7.5 13.5C5 13 3 11 3 8V5H7ZM5 7V8C5 9.6 6 10.9 7.2 11.4C7.1 10.9 7 10.5 7 10V7ZM19 7H17V10C17 10.5 16.9 10.9 16.8 11.4C18 10.9 19 9.6 19 8Z"/>
                    </svg>
                    <span class="games-visually-hidden">Best:</span>
                    <span data-snake-best>0</span>
                </span>
                <span class="snake-bar-gap"></span>
                <label class="snake-speed">
                    <span class="games-visually-hidden">Speed</span>
                    <select data-snake-speed>
                        <option value="slow">Slow</option>
                        <option value="normal" selected>Normal</option>
                        <option value="fast">Fast</option>
                    </select>
                </label>
                <button type="button" class="snake-pause" data-snake-pause aria-keyshortcuts="P Escape" disabled>Pause</button>
            </div>
            <div class="snake-field">
                <div class="snake-board" data-snake-board>
                    <canvas data-snake-canvas role="img" aria-label="Snake board"></canvas>
                    <div class="snake-cover" data-snake-cover data-state="ready">
                        <p class="snake-cover-title" data-snake-cover-title>Ready?</p>
                        <p class="snake-cover-text" role="status" data-snake-cover-text>Press an arrow key or WASD, or swipe, to start.</p>
                        <button type="button" class="casino-btn casino-btn-primary" data-snake-play>Play</button>
                    </div>
                </div>
            </div>
        </div>

        <section class="casino-card casino-rules" aria-labelledby="snake-rules-title">
            <h2 id="snake-rules-title">How to play</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/snake/rules.php'; ?>
        </section>
    </main>
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php endif; ?>
    <?php $hbHubShowSiteFooter = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/snake.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/snake.js') ?>" defer></script>
</body>
</html>
