<?php
// Sudoku: fill the 9x9 board so every row, column and 3x3 box has 1-9 once, on Easy, Medium, Hard or Expert. A normal
// Games page (side bar, back link, title, a Leaderboard button and the rules under it from
// assets/php/games/sudoku/rules.php). The puzzles are made and checked on the server (assets/php/games/sudoku/sudoku.php,
// through games/sudoku/move), so the leaderboard can trust them; assets/js/sudoku.js draws the board's 81 squares into
// data-sudoku-board and keeps the numbers and notes you put in, in localStorage (hbHubSudoku).
// data-sudoku-state hands the script what the server has: {"current": the game being played (hbHubSudokuCurrent()) or
// null, "best": {level key: your fastest ranked solve in ms}}.
// The free version for guests at /play/sudoku is a page of its own (guestGames/sudoku/), played only in the browser.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/sudoku.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$best = [];
foreach (hbHubSudokuBest($db, $userId) as $level => $ms) {
    $best[HBHUB_SUDOKU_LEVELS[$level]['key'] ?? 'unknown'] = $ms;
}
$sudokuState = ['current' => hbHubSudokuCurrent($db, $userId), 'best' => (object) $best];

$sudokuLevels = []; // 'easy' => 'Easy', ...
foreach (HBHUB_SUDOKU_LEVELS as $info) {
    $sudokuLevels[$info['key']] = $info['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/sudoku.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/sudoku.css') ?>">
    <title>Sudoku - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Play Sudoku on the HB Hub, on Easy, Medium, Hard or Expert">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games sudoku" data-sudoku data-sudoku-state="<?= htmlspecialchars(json_encode($sudokuState)) ?>">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Sudoku</h1>
            <a class="casino-btn" href="/games/sudoku/leaderboard" data-sudoku-leaderboard>Leaderboard</a>
        </div>
        <p class="casino-intro">
            Fill in the board so every row, column and 3&times;3 box has the numbers 1 to 9 once each. A new puzzle every
            time, on four levels. Solve it fast without a hint for a spot on the leaderboard.
        </p>

        <nav class="sudoku-levels" aria-label="New game">
            <?php foreach ($sudokuLevels as $key => $name): ?>
                <button type="button" class="sudoku-level" data-sudoku-new="<?= $key ?>">
                    <span class="sudoku-level-name"><?= $name ?></span>
                    <span class="sudoku-level-best" data-sudoku-best="<?= $key ?>"></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="sudoku-play">
            <div class="sudoku-main">
                <div class="sudoku-bar">
                    <span class="sudoku-bar-level" data-sudoku-level></span>
                    <span class="sudoku-bar-time" role="timer" aria-label="Time" data-sudoku-time>0:00</span>
                    <button type="button" class="sudoku-pause" data-sudoku-pause aria-pressed="false">Pause</button>
                </div>
                <div class="sudoku-board-wrap">
                    <div class="sudoku-board" role="grid" aria-label="Sudoku board" data-sudoku-board></div>
                    <div class="sudoku-cover" data-sudoku-cover hidden>
                        <p data-sudoku-cover-text>Paused</p>
                        <button type="button" class="casino-btn casino-btn-primary" data-sudoku-resume>Carry on</button>
                    </div>
                </div>
                <p class="sudoku-message" role="status" data-sudoku-message></p>
            </div>

            <div class="sudoku-controls">
                <div class="sudoku-tools">
                    <button type="button" class="sudoku-tool" data-sudoku-tool="undo" aria-keyshortcuts="Control+Z">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5 M4 9h10a6 6 0 0 1 0 12h-3"/></svg>
                        <span>Undo</span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="erase" aria-keyshortcuts="Backspace">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 20H8l-5-5 10-10 8 8-7 7 M9 9l7 7"/></svg>
                        <span>Erase</span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="notes" aria-pressed="false" aria-keyshortcuts="N">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4-1 11-11-3-3L5 16z M14 7l3 3"/></svg>
                        <span>Notes <b class="sudoku-tool-state" data-sudoku-notes-state>off</b></span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="hint">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18h6 M10 21h4 M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z"/></svg>
                        <span>Hint</span>
                    </button>
                </div>
                <div class="sudoku-pad" role="group" aria-label="Numbers">
                    <?php for ($digit = 1; $digit <= 9; $digit++): ?>
                        <button type="button" class="sudoku-pad-key" data-sudoku-digit="<?= $digit ?>">
                            <span class="sudoku-pad-digit"><?= $digit ?></span>
                            <span class="sudoku-pad-left" data-sudoku-left></span>
                        </button>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <section class="casino-card sudoku-result" aria-labelledby="sudoku-result-title" data-sudoku-result hidden>
            <h2 id="sudoku-result-title">Solved!</h2>
            <p class="sudoku-result-text"><span data-sudoku-result-text></span> <a href="/games/sudoku/leaderboard" data-sudoku-result-board>See the leaderboard</a></p>
            <div class="casino-actions">
                <?php foreach ($sudokuLevels as $key => $name): ?>
                    <button type="button" class="casino-btn<?= $key === 'medium' ? ' casino-btn-primary' : '' ?>" data-sudoku-new="<?= $key ?>">New <?= $name ?> game</button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="sudoku-rules-title">
            <h2 id="sudoku-rules-title">How to play</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/rules.php'; ?>
        </section>

        <dialog class="games-dialog" data-sudoku-confirm aria-labelledby="sudoku-confirm-title">
            <form method="dialog">
                <h2 id="sudoku-confirm-title">Start a new game?</h2>
                <p data-sudoku-confirm-text>The game you're playing now will be gone.</p>
                <div class="casino-actions">
                    <button type="submit" class="casino-btn casino-btn-primary" value="yes">New game</button>
                    <button type="submit" class="casino-btn" value="no">Keep playing</button>
                </div>
            </form>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/sudoku.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/sudoku.js') ?>" defer></script>
</body>
</html>
