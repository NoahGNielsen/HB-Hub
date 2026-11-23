<?php
// Blockfall: HB Hub's falling-block puzzle. Stack the pieces in a 10x20 well, clear full lines, and don't let it fill
// to the top, in Enduro, Rush (60 seconds) or Sprint (20 lines). A normal Games page (side bar, back link, title, a
// Leaderboard button, and the rules under it from assets/php/games/blockfall/rules.php).
// The game runs in the browser (assets/js/blockfall.js draws it on the data-blockfall-canvas canvas, with the hold and
// next pieces on their own small canvases), and the server plays along with it for the leaderboard
// (assets/php/games/blockfall/blockfall.php). So opening the page makes the next game on the server: data-blockfall-game
// has it ({"game": id, "piecesFrom": 0, "pieces": "TIOS..."}), and data-blockfall-bests the user's best in each mode
// ({"enduro": score, "rush": score, "sprint": ticks or null}). Only the chosen mode is kept in localStorage
// (hbHubBlockfall). The line above the well (data-blockfall-note) says when a game doesn't count, and why. The buttons
// under the well (data-blockfall-pad) are for phones and tablets; assets/css/blockfall.css only shows them there.
//
// It's also the guest Blockfall at /play/blockfall: guestGames/blockfall/ sets $guestGame and includes this page (see
// assets/php/guestGames/guestGames.php). Then it has the frontpage's top bar, the guest bar and the text for search
// engines, no leaderboard and no server game: data-blockfall-guest tells blockfall.js to play it only in the browser.
$guestGame ??= null;
$blockfallGame = null;
$blockfallBests = null;
if ($guestGame === null) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/blockfall/blockfall.php';
    hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

    $db = hbHubDatabase();
    $blockfallGame = hbHubBlockfallNew($db, (int) $hbHubUser['userId']);
    $blockfallBests = hbHubBlockfallBests($db, (int) $hbHubUser['userId']);
}

// The buttons for phones: input letter (see blockfall.php) => [label, what it does]
$blockfallPad = [
    'H' => ['Hold', 'Hold'],
    '-' => ['&#x21BA;', 'Turn anticlockwise'],
    '+' => ['&#x21BB;', 'Turn clockwise'],
    'F' => ['&#x2913;', 'Hard drop'],
    'L' => ['&#x2190;', 'Left'],
    'D' => ['&#x2193;', 'Soft drop'],
    'R' => ['&#x2192;', 'Right'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $headerHomeNav = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/blockfall.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/blockfall.css') ?>">
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/head.php'; ?>
    <?php else: ?>
        <title>Blockfall - HB Hub</title>
        <meta name="robots" content="noindex">
        <meta name="description" content="Play Blockfall, the falling-block puzzle, on the HB Hub">
    <?php endif; ?>
</head>
<body<?= $guestGame !== null ? ' class="home-body"' : '' ?>>
    <?php if ($guestGame !== null): ?>
        <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>
    <?php else: ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <?php endif; ?>
    <?php if ($guestGame !== null): ?>
    <main class="casino games blockfall" data-blockfall data-blockfall-guest>
    <?php else: ?>
    <main class="casino games blockfall" data-blockfall data-blockfall-game="<?= htmlspecialchars(json_encode($blockfallGame)) ?>"
          data-blockfall-bests="<?= htmlspecialchars(json_encode($blockfallBests)) ?>">
    <?php endif; ?>
        <?php if ($guestGame !== null): ?>
            <a class="casino-back" href="/">&larr; HB Hub</a>
        <?php else: ?>
            <a class="casino-back" href="/games/">&larr; Games</a>
        <?php endif; ?>
        <div class="casino-title-row">
            <h1>Blockfall</h1>
            <?php if ($guestGame === null): ?>
                <a class="casino-btn" href="/games/blockfall/leaderboard">Leaderboard</a>
            <?php endif; ?>
        </div>
        <?php if ($guestGame !== null): ?>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/top.php'; ?>
        <?php endif; ?>
        <p class="casino-intro">
            Stack the falling pieces, fill whole lines to clear them, and don't let the well fill up to the top. Move with
            the arrow keys, turn with Up, X or Z, drop with Space and hold a piece with C, or use the buttons on a phone.
            <?= $guestGame !== null ? 'Make a free account to get your scores on the leaderboard.' : 'Every mode has a leaderboard.' ?>
        </p>

        <p class="blockfall-note" data-blockfall-note aria-live="polite" hidden></p>

        <div class="blockfall-frame">
            <div class="blockfall-bar">
                <span class="blockfall-stat"><span class="blockfall-stat-name">Score</span> <span data-blockfall-score>0</span></span>
                <span class="blockfall-stat"><span class="blockfall-stat-name">Lines</span> <span data-blockfall-lines>0</span></span>
                <span class="blockfall-stat"><span class="blockfall-stat-name">Level</span> <span data-blockfall-level>1</span></span>
                <span class="blockfall-stat"><span class="blockfall-stat-name">Time</span> <span data-blockfall-time>0:00</span></span>
                <span class="blockfall-stat" title="Your best in this mode"><span class="blockfall-stat-name">Best</span> <span data-blockfall-best>0</span></span>
                <span class="blockfall-bar-gap"></span>
                <label class="blockfall-mode">
                    <span class="games-visually-hidden">Mode</span>
                    <select data-blockfall-mode>
                        <option value="enduro" selected>Enduro</option>
                        <option value="rush">Rush</option>
                        <option value="sprint">Sprint</option>
                    </select>
                </label>
                <button type="button" class="blockfall-pause" data-blockfall-pause aria-keyshortcuts="P Escape" disabled>Pause</button>
            </div>
            <div class="blockfall-field">
                <div class="blockfall-side">
                    <p class="blockfall-side-name">Hold</p>
                    <canvas class="blockfall-hold" data-blockfall-hold role="img" aria-label="The piece on hold"></canvas>
                </div>
                <div class="blockfall-well" data-blockfall-well>
                    <canvas data-blockfall-canvas role="img" aria-label="Blockfall well"></canvas>
                    <p class="blockfall-callout" data-blockfall-callout aria-hidden="true"></p>
                    <div class="blockfall-cover" data-blockfall-cover data-state="ready">
                        <p class="blockfall-cover-title" data-blockfall-cover-title>Ready?</p>
                        <p class="blockfall-cover-text" role="status" data-blockfall-cover-text>Press Space or Enter, or the button, to start.</p>
                        <button type="button" class="casino-btn casino-btn-primary" data-blockfall-play>Play</button>
                    </div>
                </div>
                <div class="blockfall-side">
                    <p class="blockfall-side-name">Next</p>
                    <canvas class="blockfall-next" data-blockfall-next role="img" aria-label="The next three pieces"></canvas>
                </div>
            </div>
            <div class="blockfall-pad" data-blockfall-pad>
                <?php foreach ($blockfallPad as $letter => [$label, $name]): ?>
                    <button type="button" class="blockfall-key" data-blockfall-key="<?= htmlspecialchars($letter) ?>" aria-label="<?= $name ?>"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <section class="casino-card casino-rules" aria-labelledby="blockfall-rules-title">
            <h2 id="blockfall-rules-title">How to play</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/blockfall/rules.php'; ?>
        </section>
    </main>
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php endif; ?>
    <?php $hbHubShowSiteFooter = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/blockfall.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/blockfall.js') ?>" defer></script>
</body>
</html>
