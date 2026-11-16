<?php
// The guest Sudoku (/play/sudoku), for visitors without an account. Unlike the other guest games this is a page of its
// own instead of the members' one (games/sudoku/index.php): the members' Sudoku is made and checked on the server for
// its leaderboard, while this one is played only in the browser. assets/js/sudokuGuest.js makes the puzzles, draws the
// board's 81 squares into data-sudoku-board, and keeps the game and the best times in localStorage (hbHubSudokuGuest),
// so there's no token, no JSON endpoint and nothing on the server. It looks the same as the members' page (sudoku.css),
// with the frontpage's top bar, the guest bar and the text for search engines from assets/php/guestGames/.
// Being its own page, it's all in Danish, like every public page (the levels keep the names the members' Sudoku uses).
// Someone who's logged in is sent to the members' page instead (hbHubGuestGamePage()).
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('sudoku', $hbHubUser);

// The levels, as in DIFFICULTIES in sudokuGuest.js
const SUDOKU_GUEST_LEVELS = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'expert' => 'Expert'];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
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
            Udfyld brættet, så hver række, kolonne og 3&times;3-boks har tallene 1 til 9 én gang hver. En ny opgave hver
            gang, på fire niveauer. Opret en gratis konto for at få dine tider på ranglisten.
        </p>

        <nav class="sudoku-levels" aria-label="Nyt spil">
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
                    <span class="sudoku-bar-time" role="timer" aria-label="Tid" data-sudoku-time>0:00</span>
                    <button type="button" class="sudoku-pause" data-sudoku-pause aria-pressed="false">Pause</button>
                </div>
                <div class="sudoku-board-wrap">
                    <div class="sudoku-board" role="grid" aria-label="Sudoku-bræt" data-sudoku-board></div>
                    <div class="sudoku-cover" data-sudoku-cover hidden>
                        <p data-sudoku-cover-text>Sat på pause</p>
                        <button type="button" class="casino-btn casino-btn-primary" data-sudoku-resume>Fortsæt</button>
                    </div>
                </div>
                <p class="sudoku-message" role="status" data-sudoku-message></p>
            </div>

            <div class="sudoku-controls">
                <div class="sudoku-tools">
                    <button type="button" class="sudoku-tool" data-sudoku-tool="undo" aria-keyshortcuts="Control+Z">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5 M4 9h10a6 6 0 0 1 0 12h-3"/></svg>
                        <span>Fortryd</span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="erase" aria-keyshortcuts="Backspace">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 20H8l-5-5 10-10 8 8-7 7 M9 9l7 7"/></svg>
                        <span>Slet</span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="notes" aria-pressed="false" aria-keyshortcuts="N">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4-1 11-11-3-3L5 16z M14 7l3 3"/></svg>
                        <span>Noter <b class="sudoku-tool-state" data-sudoku-notes-state>fra</b></span>
                    </button>
                    <button type="button" class="sudoku-tool" data-sudoku-tool="hint">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18h6 M10 21h4 M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z"/></svg>
                        <span>Hint</span>
                    </button>
                </div>
                <div class="sudoku-pad" role="group" aria-label="Tal">
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
            <h2 id="sudoku-result-title">Løst!</h2>
            <p class="sudoku-result-text" data-sudoku-result-text></p>
            <div class="casino-actions">
                <?php foreach (SUDOKU_GUEST_LEVELS as $key => $name): ?>
                    <button type="button" class="casino-btn<?= $key === 'medium' ? ' casino-btn-primary' : '' ?>" data-sudoku-new="<?= $key ?>">Nyt <?= $name ?>-spil</button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="sudoku-rules-title">
            <h2 id="sudoku-rules-title">Sådan spiller du</h2>
            <!-- The rules of this browser-only version, written out here instead of the members' rules.php (which is
                 about their server-checked game and its leaderboard). Change them with sudokuGuest.js -->
            <ul class="casino-rule-list">
                <li>Udfyld brættet, så hver række, hver kolonne og hver 3&times;3-boks har hvert af tallene 1 til 9 præcis én gang.</li>
                <li>Vælg et niveau: Easy starter med cirka 38 tal udfyldt, Medium cirka 32, Hard cirka 28 og Expert så få som muligt (som regel 22 til 25). Hver opgave har præcis én løsning, og Easy og Medium kan altid løses uden at gætte.</li>
                <li>Klik på et felt, og så på et tal under brættet (eller skriv det). <kbd>Backspace</kbd> eller Slet tager det ud igen, og piletasterne flytter rundt.</li>
                <li>Slå Noter til (eller tryk <kbd>N</kbd>) for at skrive små tal i et felt, for dem det kunne være. Sætter du et tal i et felt, bliver det fjernet fra noterne i dets række, kolonne og boks.</li>
                <li>To af det samme tal i en række, kolonne eller boks bliver røde. Brættet fortæller dig ikke på andre måder, når et tal er forkert.</li>
                <li>Sidder du fast? Hint udfylder det felt, du har valgt (eller et tilfældigt), med det rigtige tal. Fortryd (<kbd>Ctrl</kbd> + <kbd>Z</kbd>) tager dit sidste træk tilbage.</li>
                <li>Uret kører kun, mens siden er på skærmen, og spillet ikke er sat på pause. Dit spil bliver gemt i denne browser, så du kan lukke siden og fortsætte senere. Din bedste tid på hvert niveau bliver også gemt dér.</li>
            </ul>
        </section>

        <dialog class="games-dialog" data-sudoku-confirm aria-labelledby="sudoku-confirm-title">
            <form method="dialog">
                <h2 id="sudoku-confirm-title">Start et nyt spil?</h2>
                <p data-sudoku-confirm-text>Det spil, du er i gang med, forsvinder.</p>
                <div class="casino-actions">
                    <button type="submit" class="casino-btn casino-btn-primary" value="yes">Nyt spil</button>
                    <button type="submit" class="casino-btn" value="no">Spil videre</button>
                </div>
            </form>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/sudokuGuest.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/sudokuGuest.js') ?>" defer></script>
</body>
</html>
