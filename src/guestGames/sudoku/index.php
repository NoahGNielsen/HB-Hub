<?php
// The guest Sudoku (/play/sudoku), for visitors without an account. Unlike the other guest games this is a page of its
// own instead of the members' one (games/sudoku/index.php): the members' Sudoku is made and checked on the server for
// its leaderboard, while this one is played only in the browser. assets/js/sudokuGuest.js makes the puzzles, draws the
// board's 81 squares into data-sudoku-board, and keeps the game and the best times in localStorage (hbHubSudokuGuest),
// so there's no token, no JSON endpoint and nothing on the server. It looks the same as the members' page (sudoku.css),
// with the frontpage's top bar, the guest bar and the text for search engines from assets/php/guestGames/.
// Someone who's logged in is sent to the members' page instead (hbHubGuestGamePage()).
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('sudoku', $hbHubUser);

// The levels, as in DIFFICULTIES in sudokuGuest.js
const SUDOKU_GUEST_LEVELS = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'expert' => 'Expert'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/sudoku.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/sudoku.css') ?>">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/head.php'; ?>
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>
    <main class="casino games sudoku" data-sudoku>
        <a class="casino-back" href="/">&larr; HB Hub</a>
        <div class="casino-title-row">
            <h1>Sudoku</h1>
        </div>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/top.php'; ?>
        <p class="casino-intro">
            Fill in the board so every row, column and 3&times;3 box has the numbers 1 to 9 once each. A new puzzle every
            time, on four levels. Make a free account to get your times on the leaderboard.
        </p>

        <nav class="sudoku-levels" aria-label="New game">
            <?php foreach (SUDOKU_GUEST_LEVELS as $key => $name): ?>
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
            <p class="sudoku-result-text" data-sudoku-result-text></p>
            <div class="casino-actions">
                <?php foreach (SUDOKU_GUEST_LEVELS as $key => $name): ?>
                    <button type="button" class="casino-btn<?= $key === 'medium' ? ' casino-btn-primary' : '' ?>" data-sudoku-new="<?= $key ?>">New <?= $name ?> game</button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="sudoku-rules-title">
            <h2 id="sudoku-rules-title">How to play</h2>
            <!-- The rules of this browser-only version, written out here instead of the members' rules.php (which is
                 about their server-checked game and its leaderboard). Change them with sudokuGuest.js -->
            <ul class="casino-rule-list">
                <li>Fill in the board so every row, every column and every 3&times;3 box has each of the numbers 1 to 9 exactly once.</li>
                <li>Pick a level: Easy starts with about 38 numbers filled in, Medium about 32, Hard about 28 and Expert as few as it can (usually 22 to 25). Every puzzle has exactly one answer, and Easy and Medium can always be solved without guessing.</li>
                <li>Click a square, then a number under the board (or type it). <kbd>Backspace</kbd> or Erase takes it out again, and the arrow keys move around.</li>
                <li>Turn on Notes (or press <kbd>N</kbd>) to write small numbers in a square, for the ones it could be. Putting a number in a square takes it out of the notes in its row, column and box.</li>
                <li>Two of the same number in a row, column or box turn red. The board doesn't tell you when a number is wrong in any other way.</li>
                <li>Stuck? Hint fills in the square you picked (or a random one) with the right number. Undo (<kbd>Ctrl</kbd> + <kbd>Z</kbd>) takes back your last move.</li>
                <li>The clock only runs while the page is on screen and the game isn't paused. Your game is saved in this browser, so you can close the page and pick it up later. Your best time for each level is kept there too.</li>
            </ul>
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
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/sudokuGuest.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/sudokuGuest.js') ?>" defer></script>
</body>
</html>
