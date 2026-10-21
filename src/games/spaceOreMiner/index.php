<?php
// Space Ore Miner: the clicker game. Click the asteroid for ore, spend it on buildings and upgrades that mine for you,
// catch Golden Comets, and Warp Jump to a new galaxy for Dark Matter. The rules are in
// assets/php/games/spaceOreMiner/miner.php.
// The page only has the empty spaces: spaceOreMiner.js loads the save from /games/spaceOreMiner/save on its first save
// (claiming the game for this tab, with the random id in data-tab), runs the game, fills everything in, and saves
// every 30 seconds. data-config has the game's data and numbers (hbHubMinerConfig()), so the script plays by the same
// rules as the server.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/spaceOreMiner.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/spaceOreMiner.css') ?>">
    <title>Space Ore Miner - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Mine an asteroid, build a galaxy-wide mining company and Warp Jump to new galaxies in Space Ore Miner on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games miner is-loading" data-miner
          data-tab="<?= bin2hex(random_bytes(16)) ?>"
          data-config="<?= htmlspecialchars(json_encode(hbHubMinerConfig())) ?>">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Space Ore Miner</h1>
            <div class="miner-title-actions">
                <button type="button" class="casino-btn miner-sound" aria-pressed="true" data-miner-sound>
                    <svg class="miner-sound-icon is-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z M16 8.5a5 5 0 0 1 0 7 M18.5 6a8.5 8.5 0 0 1 0 12"/></svg>
                    <svg class="miner-sound-icon is-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z M16 9l6 6 M22 9l-6 6"/></svg>
                    <span class="games-visually-hidden">Sound</span>
                </button>
                <a class="casino-btn" href="/games/spaceOreMiner/leaderboard">Leaderboard</a>
            </div>
        </div>
        <p class="casino-intro">
            You're a lone miner on a tiny asteroid. Click it to chip off ore, spend the ore on machines that mine for you,
            and grow into a galaxy-wide mining company.
        </p>

        <p class="miner-ticker" data-miner-ticker><span class="miner-ticker-text" data-miner-ticker-text>Loading the news from space…</span></p>

        <div class="miner-layout">
            <section class="casino-card miner-mine" aria-labelledby="miner-ore-title">
                <h2 id="miner-ore-title" class="games-visually-hidden">Your asteroid</h2>
                <p class="miner-ore"><span class="miner-ore-number" data-miner-ore>0</span> <span class="miner-ore-unit">ore</span></p>
                <p class="miner-rate"><span data-miner-rate>0</span> ore per second</p>
                <ul class="miner-buffs" data-miner-buffs aria-label="Comet effects"></ul>

                <div class="miner-field" data-miner-field>
                    <div class="miner-orbit" data-miner-bot hidden aria-hidden="true"><span class="miner-bot">🤖</span></div>
                    <button type="button" class="miner-asteroid" data-miner-asteroid aria-label="Mine the asteroid" disabled>
                        <span class="miner-crater is-1"></span>
                        <span class="miner-crater is-2"></span>
                        <span class="miner-crater is-3"></span>
                        <span class="miner-crater is-4"></span>
                    </button>
                </div>

                <p class="miner-click-value"><span data-miner-click>1</span> ore per click</p>
                <p class="miner-stage" data-miner-stage></p>
                <p class="miner-save" role="status" data-miner-save>Loading your save…</p>
            </section>

            <section class="casino-card miner-store" aria-labelledby="miner-store-title">
                <h2 id="miner-store-title" class="games-visually-hidden">Store</h2>
                <div class="miner-store-part">
                    <h3 class="miner-store-title">Upgrades</h3>
                    <ul class="miner-upgrades" data-miner-upgrades></ul>
                    <p class="casino-hint miner-empty" data-miner-upgrades-empty>Mine some more ore and upgrades will show up here.</p>
                </div>
                <div class="miner-store-part">
                    <div class="miner-store-head">
                        <h3 class="miner-store-title">Buildings</h3>
                        <div class="miner-amounts" role="group" aria-label="How many to buy" data-miner-amounts hidden>
                            <button type="button" class="miner-amount is-active" data-amount="1" aria-pressed="true">×1</button>
                            <button type="button" class="miner-amount" data-amount="10" aria-pressed="false">×10</button>
                            <button type="button" class="miner-amount" data-amount="100" aria-pressed="false">×100</button>
                        </div>
                    </div>
                    <ul class="miner-buildings" data-miner-buildings></ul>
                </div>
            </section>
        </div>

        <section class="casino-card miner-warp" aria-labelledby="miner-warp-title">
            <div class="miner-section-head">
                <h2 id="miner-warp-title">Warp Jump</h2>
                <p class="miner-dark"><span data-miner-dark>0</span> Dark Matter</p>
            </div>
            <p class="casino-hint">
                Once you've mined <?= number_format(HBHUB_MINER_WARP_ORE) ?> ore in a run, you can jump to a new galaxy. You
                start over with no ore, buildings or upgrades, but you get Dark Matter for all the ore you've ever mined.
                Every Dark Matter you've earned makes everything mine <?= HBHUB_MINER_DARK_BONUS * 100 ?>% faster for good, even
                after you spend it on the upgrades below.
            </p>
            <dl class="miner-numbers miner-warp-numbers">
                <div><dt>Mined this run</dt><dd data-miner-warp="runOre">0</dd></div>
                <div><dt>Dark Matter from a jump now</dt><dd data-miner-warp="gain">0</dd></div>
                <div><dt>Bonus from Dark Matter</dt><dd data-miner-warp="bonus">+0%</dd></div>
            </dl>
            <div class="casino-actions">
                <button type="button" class="casino-btn casino-btn-primary" data-miner-warp-button disabled>Warp Jump</button>
            </div>
            <p class="casino-hint miner-warp-hint" data-miner-warp-hint></p>
            <h3 class="miner-store-title">Dark Matter upgrades</h3>
            <ul class="miner-upgrades miner-dark-upgrades" data-miner-dark-upgrades></ul>
        </section>

        <section class="casino-card miner-achievements" aria-labelledby="miner-achievements-title">
            <div class="miner-section-head">
                <h2 id="miner-achievements-title">Achievements</h2>
                <p class="miner-achievement-count" data-miner-achievement-count>0 / <?= count(HBHUB_MINER_ACHIEVEMENTS) ?></p>
            </div>
            <ul class="miner-achievement-list" data-miner-achievements></ul>
        </section>

        <section class="casino-card miner-stats" aria-labelledby="miner-stats-title">
            <h2 id="miner-stats-title">Your stats</h2>
            <dl class="miner-numbers" data-miner-stats></dl>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="miner-rules-title">
            <h2 id="miner-rules-title">How to play</h2>
            <ul class="casino-rule-list">
                <li>Click the asteroid to mine ore. Spend it in the store on buildings, which mine for you every second, and on upgrades that make clicks and buildings better.</li>
                <li>Every building costs 15% more than the one before. Each one gets three upgrades, at 10, 25 and 50 owned, that double what it mines.</li>
                <li>Now and then a Golden Comet flies across the screen. Click it before it's gone for a big pile of ore, or seven times the production for a while.</li>
                <li>
                    The game saves by itself every <?= HBHUB_MINER_SAVE_SECONDS ?> seconds and when you leave. While you're away,
                    your machines keep mining at <?= HBHUB_MINER_OFFLINE_RATE * 100 ?>% of the speed.
                </li>
                <li class="miner-hot-keys">
                    Hot keys: <kbd>Space</kbd> mines the asteroid, <kbd>C</kbd> catches a comet (once you have the Comet Hot Key upgrade), <kbd>1</kbd>-<kbd>9</kbd>
                    buy the buildings in the order they're listed, <kbd>U</kbd> buys the cheapest upgrade you can afford,
                    <kbd>A</kbd> switches between buying 1, 10 and 100, <kbd>W</kbd> opens the Warp Jump and <kbd>M</kbd>
                    turns the sound on or off. Holding Space down only mines once.
                </li>
                <li>Play in one tab at a time: opening the game somewhere else stops it here.</li>
                <li>
                    Clicks are counted up to <?= HBHUB_MINER_CLICKS_PER_SECOND ?> a second. Auto-clickers and scripts aren't allowed, and buy
                    you nothing anyway: clicks that come in at exactly the same pace for <?= HBHUB_MINER_STEADY_MS / 1000 ?> seconds, or
                    <?= HBHUB_MINER_EVEN_CLICKS ?> clicks in a row whose gaps vary less than <?= HBHUB_MINER_EVEN_DEVIATION * 100 ?>%, only
                    mine <?= HBHUB_MINER_STEADY_VALUE * 100 ?>% of what they would.
                </li>
            </ul>
        </section>

        <button type="button" class="miner-comet" data-miner-comet hidden aria-label="Catch the Golden Comet"><span class="miner-comet-head"></span></button>
        <ol class="miner-toasts" data-miner-toasts aria-live="polite"></ol>

        <dialog class="games-dialog" data-miner-warp-dialog aria-labelledby="miner-warp-dialog-title">
            <h2 id="miner-warp-dialog-title">Warp to a new galaxy?</h2>
            <p data-miner-warp-dialog-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-warp-cancel>Not yet</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-warp-confirm>Warp Jump</button>
            </div>
        </dialog>

        <dialog class="games-dialog" data-miner-other-tab aria-labelledby="miner-other-tab-title">
            <h2 id="miner-other-tab-title">Playing somewhere else</h2>
            <p>You opened Space Ore Miner in another tab or on another device, so it's stopped here. Your game carries on there.</p>
            <div class="games-dialog-actions">
                <a class="casino-btn" href="/games/">Back to Games</a>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-reload>Play here instead</button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/spaceOreMiner.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/spaceOreMiner.js') ?>" defer></script>
</body>
</html>
