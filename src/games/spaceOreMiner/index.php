<?php
// Ore Mining Inc.: the clicker game. Click the asteroid for ore, spend it on buildings and upgrades that mine for you,
// catch Golden Comets, Warp Jump to a new galaxy for Dark Matter, Big Crunch the universe for Antimatter and Big Rip
// the multiverse for Strange Matter, which buys skills in the skill tree. The rules are in
// assets/php/games/spaceOreMiner/miner.php.
// The page only has the empty spaces: spaceOreMiner.js loads the save from /games/ore-mining-inc/save on its first save
// (claiming the game for this tab, with the random id in data-tab), runs the game, fills everything in, and saves
// every 30 seconds. data-config has the game's data and numbers (hbHubMinerConfig()), so the script plays by the same
// rules as the server, and data-icons is where the game's drawn icons are (assets/images/spaceOreMiner/icons.svg).
// It has seven views, picked in the menu down the right side (over the views on small screens; Engine, Anomalies and
// the Shard Shop in a second group): Ore (the asteroid and the store, the default), Dark Matter
// (?view=darkMatter, the Warp Jump), Antimatter (?view=antimatter, the Big Crunch), Strange Matter
// (?view=strangeMatter, the Big Rip and the skill tree), Project Starbound (?view=starbound, the Engine the whole site
// builds together, see assets/php/games/spaceOreMiner/engine.php), Anomalies (?view=anomalies, the challenge runs,
// HBHUB_MINER_ANOMALIES; while the player is in one, a banner over every view says so) and the Shard Shop (?view=shop,
// spending Star Shards, HBHUB_MINER_SHOP). They're all on the page, and the script switches between them without
// loading it again (changing the address, so Back works), so the game keeps running; ?view= only picks the one shown
// first. Stats, achievements and how to play are popups.
// data-watchdog is the worker that notices when the page freezes (spaceOreMinerWatchdog.js). After a crash, the next
// time the game is opened, the script asks in data-miner-crash-dialog whether it may send a crash report.
//
// It's also the guest Ore Mining Inc. at /play/ore-mining-inc, for visitors without an account: guestGames/spaceOreMiner/
// sets $guestGame and includes this page (see assets/php/guestGames/guestGames.php). Then it has the frontpage's top
// bar, the guest bar and the text for search engines, no leaderboard or crash reports, and saves to
// /play/ore-mining-inc/save (data-save), which hands the game back as a token for the script to keep (data-guest).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/crashReports.php';
$guestGame ??= null;
if ($guestGame === null) {
    hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet
}

const MINER_VIEWS = [ // ?view= => [the menu's text (HTML), its address after the game's own]
    'ore' => ['Ore', ''],
    'darkMatter' => ['Dark Matter', '?view=darkMatter'],
    'antimatter' => ['Antimatter', '?view=antimatter'],
    'strangeMatter' => ['Strange Matter', '?view=strangeMatter'],
    'starbound' => ['<span class="miner-menu-long">Project </span>Starbound', '?view=starbound'], // just "Starbound" in the bar on small screens
    'anomalies' => ['Anomalies', '?view=anomalies'],
    'shop' => ['Shard Shop', '?view=shop'],
];
const MINER_LATER_VIEWS = ['starbound' => true, 'anomalies' => true, 'shop' => true]; // in the second group of the menu
$minerAddress = $guestGame !== null ? $guestGame['path'] : '/games/ore-mining-inc/';
// Project Starbound's view was ?view=engine before it was renamed: old links and bookmarks go to the new one
if (($_GET['view'] ?? null) === 'engine') {
    header('Location: ' . $minerAddress . '?view=starbound', true, 301);
    exit;
}
$minerView = is_string($_GET['view'] ?? null) && isset(MINER_VIEWS[$_GET['view']]) ? $_GET['view'] : 'ore';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $headerHomeNav = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/spaceOreMiner.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/spaceOreMiner.css') ?>">
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/head.php'; ?>
    <?php else: ?>
        <title>Ore Mining Inc. - HB Hub</title>
        <meta name="robots" content="noindex">
        <meta name="description" content="Mine an asteroid, build a galaxy-wide mining company and Warp Jump to new galaxies in Ore Mining Inc. on the HB Hub">
    <?php endif; ?>
</head>
<body<?= $guestGame !== null ? ' class="home-body"' : '' ?>>
    <?php if ($guestGame !== null): ?>
        <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>
    <?php else: ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <?php endif; ?>
    <main class="casino games miner is-loading" data-miner data-view="<?= $minerView ?>"
          data-save="<?= $guestGame !== null ? '/play/ore-mining-inc/save' : '/games/ore-mining-inc/save' ?>"<?= $guestGame !== null ? ' data-guest' : '' ?>
          data-tab="<?= bin2hex(random_bytes(16)) ?>" data-icons="<?= htmlspecialchars(hbHubMinerIconsUrl()) ?>"
          data-watchdog="/assets/js/spaceOreMinerWatchdog.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/spaceOreMinerWatchdog.js') ?>"
          data-config="<?= htmlspecialchars(json_encode(hbHubMinerConfig())) ?>">
        <?php if ($guestGame !== null): ?>
            <a class="casino-back" href="/">&larr; HB Hub</a>
        <?php else: ?>
            <a class="casino-back" href="/games/">&larr; Games</a>
        <?php endif; ?>
        <div class="casino-title-row">
            <h1>Ore Mining Inc.</h1>
            <div class="miner-title-actions">
                <button type="button" class="casino-btn miner-sound" aria-pressed="true" data-miner-sound>
                    <svg class="miner-sound-icon is-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z M16 8.5a5 5 0 0 1 0 7 M18.5 6a8.5 8.5 0 0 1 0 12"/></svg>
                    <svg class="miner-sound-icon is-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z M16 9l6 6 M22 9l-6 6"/></svg>
                    <span class="games-visually-hidden">Sound</span>
                </button>
                <!-- On phones only the icons show; the words stay for screen readers -->
                <?php if ($guestGame === null): ?>
                    <a class="casino-btn miner-title-btn" href="/games/ore-mining-inc/leaderboard"><?= hbHubMinerIcon('podium', 'miner-title-icon') ?> <span class="miner-title-label">Leaderboard</span></a>
                <?php endif; ?>
                <button type="button" class="casino-btn miner-title-btn" data-miner-open="stats" aria-haspopup="dialog"><?= hbHubMinerIcon('stats', 'miner-title-icon') ?> <span class="miner-title-label">Stats</span></button>
                <button type="button" class="casino-btn miner-title-btn" data-miner-open="achievements" aria-haspopup="dialog">
                    <?= hbHubMinerIcon('trophy', 'miner-title-icon') ?> <span class="miner-title-label">Achievements</span>
                    <span class="miner-title-count" data-miner-achievement-count>0 / <?= count(HBHUB_MINER_ACHIEVEMENTS) ?></span>
                </button>
                <button type="button" class="casino-btn miner-title-btn" data-miner-open="help" aria-haspopup="dialog"><?= hbHubMinerIcon('question', 'miner-title-icon') ?> <span class="miner-title-label">How to play</span></button>
            </div>
        </div>
        <?php if ($guestGame !== null): ?>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/top.php'; ?>
        <?php endif; ?>

        <p class="miner-ticker" data-miner-ticker><span class="miner-ticker-text" data-miner-ticker-text>Loading the news from space…</span></p>

        <!-- The menu and the views together in miner-body: on wide screens the menu is a column down the right side of
             the views, on small ones a bar over them -->
        <div class="miner-body">
        <!-- The views. Plain links, which spaceOreMiner.js takes over so the page isn't loaded again. -->
        <!-- Two groups: the matters first, and the Engine, Anomalies and Shard Shop after them. Those three are always
             there, and their views say what's still locked (giving parts needs a Warp Jump, the Anomalies a Big Rip,
             and shopping a Star Shard). -->
        <nav class="miner-menus" aria-label="Views" data-miner-menu>
            <?php foreach ([array_diff_key(MINER_VIEWS, MINER_LATER_VIEWS), array_intersect_key(MINER_VIEWS, MINER_LATER_VIEWS)] as $row => $rowViews): ?>
                <div class="miner-menu<?= $row === 1 ? ' is-later' : '' ?>">
                    <?php foreach ($rowViews as $view => [$text, $query]): ?>
                        <a class="miner-menu-link is-<?= $view ?>" href="<?= htmlspecialchars($minerAddress . $query) ?>" data-miner-view-link="<?= $view ?>"<?= $view === $minerView ? ' aria-current="page"' : '' ?>>
                            <span class="miner-menu-text"><?= $text ?></span>
                            <?php if ($view !== 'ore'): ?>
                                <span class="miner-menu-count" data-miner-menu-count="<?= $view ?>"></span>
                                <span class="miner-menu-news" data-miner-menu-news="<?= $view ?>" hidden><span class="games-visually-hidden">(something to do here)</span></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="miner-stage">
        <!-- Only while the player is in an Anomaly (renderAnomalyBanner() in spaceOreMiner.js): which, how long it's
             been (or how long is left), the Antimatter towards its goal, and the ways out (keeping the run, or not) -->
        <div class="miner-anomaly-banner" data-miner-anomaly-banner hidden>
            <span class="miner-anomaly-banner-icon" data-miner-anomaly-banner-icon aria-hidden="true"></span>
            <p class="miner-anomaly-banner-text">
                <strong data-miner-anomaly-banner-name></strong>
                <span data-miner-anomaly-banner-info></span>
            </p>
            <button type="button" class="casino-btn casino-btn-primary" data-miner-anomaly-save>Save and leave</button>
            <button type="button" class="casino-btn" data-miner-anomaly-leave>Leave</button>
        </div>

        <!-- Ore: the asteroid stays put, the store next to it scrolls -->
        <div class="miner-view miner-layout" data-miner-view="ore"<?= $minerView !== 'ore' ? ' hidden' : '' ?>>
            <section class="casino-card miner-mine" aria-labelledby="miner-ore-title">
                <h2 id="miner-ore-title" class="games-visually-hidden">Your asteroid</h2>
                <p class="miner-ore"><span class="miner-ore-number" data-miner-ore>0</span> <span class="miner-ore-unit">ore</span></p>
                <p class="miner-rate"><span data-miner-rate>0</span> ore per second</p>
                <ul class="miner-buffs" data-miner-buffs aria-label="Comet effects and Afterglow"></ul>

                <!-- The buildings you own show up in the picture: around the asteroid in data-miner-scene, and standing on
                     it in data-miner-surface (inside it, so they move with it) -->
                <div class="miner-field" data-miner-field>
                    <div class="miner-scene" data-miner-scene aria-hidden="true"></div>
                    <div class="miner-orbit" data-miner-bot hidden aria-hidden="true"><?= hbHubMinerIcon('robot', 'miner-bot') ?></div>
                    <button type="button" class="miner-asteroid" data-miner-asteroid aria-label="Mine the asteroid" disabled>
                        <span class="miner-crater is-1"></span>
                        <span class="miner-crater is-2"></span>
                        <span class="miner-crater is-3"></span>
                        <span class="miner-crater is-4"></span>
                        <span class="miner-surface" data-miner-surface aria-hidden="true"></span>
                    </button>
                </div>

                <p class="miner-click-value"><span data-miner-click>1</span> ore per click</p>
                <p class="miner-save" role="status" data-miner-save>Loading your save…</p>
            </section>

            <section class="casino-card miner-store" aria-labelledby="miner-store-title">
                <h2 id="miner-store-title" class="games-visually-hidden">Store</h2>
                <div class="miner-store-part">
                    <h3 class="miner-store-title">Upgrades</h3>
                    <!-- Only the first row (the three cheapest) until the arrow under it is pointed at or clicked -->
                    <div class="miner-upgrades-box" data-miner-upgrades-box>
                        <ul class="miner-upgrades miner-ore-upgrades" id="miner-ore-upgrades" data-miner-upgrades></ul>
                        <button type="button" class="miner-upgrades-more" aria-expanded="false" aria-controls="miner-ore-upgrades"
                                data-miner-upgrades-more hidden></button>
                    </div>
                    <p class="casino-hint miner-empty" data-miner-upgrades-empty>Mine some more ore and upgrades will show up here.</p>
                </div>
                <div class="miner-store-part">
                    <div class="miner-store-head">
                        <h3 class="miner-store-title">Buildings</h3>
                        <div class="miner-store-tools">
                            <!-- With Auto Buy: buys buildings and upgrades by itself while it's on (autoBuy() in spaceOreMiner.js) -->
                            <button type="button" class="miner-amount miner-auto-switch" aria-pressed="false"
                                    title="Buy buildings and upgrades by itself" data-miner-auto-switch hidden>
                                Auto buy
                            </button>
                            <!-- With Long-Range Hot Keys: the 1-9 and 0 keys buy the last ten buildings instead of the first ten -->
                            <button type="button" class="miner-amount miner-key-switch" aria-pressed="false" aria-keyshortcuts="K"
                                    title="Make the 1-9 and 0 keys buy your last ten buildings instead of your first ten (K)" data-miner-key-switch hidden>
                                Late-game keys
                            </button>
                            <!-- With Bulk Buying: A goes through these in turn (the hot keys at the bottom of spaceOreMiner.js).
                                 Max only shows with Max Buying. -->
                            <div class="miner-amounts" role="group" aria-label="How many to buy" data-miner-amounts hidden>
                                <button type="button" class="miner-amount is-active" data-amount="1" aria-pressed="true" aria-keyshortcuts="A"
                                        title="Buy 1 at a time (A switches between the amounts)">×1</button>
                                <button type="button" class="miner-amount" data-amount="10" aria-pressed="false" aria-keyshortcuts="A"
                                        title="Buy 10 at a time (A switches between the amounts)">×10</button>
                                <button type="button" class="miner-amount" data-amount="100" aria-pressed="false" aria-keyshortcuts="A"
                                        title="Buy 100 at a time (A switches between the amounts)">×100</button>
                                <button type="button" class="miner-amount" data-amount="max" aria-pressed="false" aria-keyshortcuts="A"
                                        title="Buy as many as you can afford (A switches between the amounts)" hidden>Max</button>
                            </div>
                        </div>
                    </div>
                    <ul class="miner-buildings" data-miner-buildings></ul>
                </div>
            </section>
        </div>

        <!-- Dark Matter: the Warp Jump and what Dark Matter buys. On top (miner-prestige-hero) the portal on one side and
             the jump on the other: the Dark Matter you have, how far along the next jump is and its button. The upgrades
             are under it, cheapest first. spaceOreMiner.js puts is-ready on the section when a jump can be done. -->
        <section class="miner-view casino-card miner-prestige is-dark" aria-labelledby="miner-warp-title" data-miner-view="darkMatter" data-miner-warp-view<?= $minerView !== 'darkMatter' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- A dark core bending the light around it, with the rings of what it's pulling in -->
                <div class="miner-prestige-art" aria-hidden="true">
                    <span class="miner-art-ring is-1"></span>
                    <span class="miner-art-ring is-2"></span>
                    <span class="miner-art-ring is-3"></span>
                    <span class="miner-art-core"></span>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-warp-title" class="miner-prestige-title">Warp Jump</h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-dark>0</span> Dark Matter</p>
                    <p class="miner-prestige-bonus"><strong data-miner-warp="bonus">+0%</strong> production from the Dark Matter you've earned</p>
                    <div class="miner-progress">
                        <div class="miner-progress-bar" role="progressbar" aria-labelledby="miner-warp-progress-label"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-miner-warp-progress><span class="miner-progress-fill"></span></div>
                        <p class="miner-progress-label" id="miner-warp-progress-label">
                            <span data-miner-warp="runOre">0</span> of <span data-miner-warp="target"><?= number_format(HBHUB_MINER_WARP_ORE) ?></span> ore mined this run
                        </p>
                    </div>
                    <div class="miner-prestige-actions">
                        <button type="button" class="miner-prestige-btn" data-miner-warp-button disabled>
                            <span class="miner-prestige-btn-text">Warp Jump</span>
                            <span class="miner-prestige-btn-gain">+<span data-miner-warp="gain">0</span> Dark Matter</span>
                        </button>
                        <!-- With the Autopilot skill: Warp Jumps by itself while it's on (autoPrestige() in spaceOreMiner.js) -->
                        <button type="button" class="miner-amount miner-auto-prestige" aria-pressed="false"
                                title="Warp Jump by itself whenever a jump would multiply your Dark Matter by at least the number next to it" data-miner-auto-warp hidden>
                            Autopilot
                        </button>
                        <!-- How many times as much Dark Matter a jump has to give before Autopilot makes it (4 to start with) -->
                        <label class="miner-auto-times" title="Autopilot waits until a jump would multiply your Dark Matter by at least this much" data-miner-auto-warp-times hidden>
                            &times;<input type="number" min="2" step="any" value="4" inputmode="decimal" aria-label="Autopilot jumps at this many times your Dark Matter">
                        </label>
                    </div>
                    <p class="miner-prestige-hint" data-miner-warp-hint></p>
                </div>
            </div>
            <p class="casino-hint miner-prestige-about">
                A Warp Jump starts you over in a new galaxy with no ore, buildings or upgrades, but you get Dark Matter for all
                the ore you've ever mined. Every Dark Matter you've earned makes everything mine <?= HBHUB_MINER_DARK_BONUS * 100 ?>%
                faster until the next Big Crunch, even after you spend it on the upgrades below.
            </p>
            <div class="miner-store-head miner-prestige-store-head">
                <h3 class="miner-store-title">Dark Matter upgrades</h3>
                <p class="miner-prestige-owned" data-miner-dark-owned></p>
            </div>
            <ul class="miner-perks" data-miner-dark-upgrades></ul>
        </section>

        <!-- Antimatter: the Big Crunch and what Antimatter buys, built like the Dark Matter view. Locked until there's Dark
             Matter to talk about: then only the picture, dimmed, and the lock show (is-locked on the section). -->
        <section class="miner-view casino-card miner-prestige is-anti is-locked" aria-labelledby="miner-crunch-title" data-miner-view="antimatter" data-miner-crunch-view<?= $minerView !== 'antimatter' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- A white-hot point with the universe's rings falling into it -->
                <div class="miner-prestige-art" aria-hidden="true">
                    <span class="miner-art-ring is-1"></span>
                    <span class="miner-art-ring is-2"></span>
                    <span class="miner-art-ring is-3"></span>
                    <span class="miner-art-core"></span>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-crunch-title" class="miner-prestige-title">Big Crunch</h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-anti>0</span> Antimatter</p>
                    <p class="miner-locked" data-miner-crunch-locked>
                        <?= hbHubMinerIcon('lock', 'miner-locked-icon') ?>
                        Something even bigger than a Warp Jump is out there. Do your first Warp Jump to find out what.
                    </p>
                    <div data-miner-crunch-section hidden>
                        <p class="miner-prestige-bonus"><strong data-miner-crunch="bonus">+0%</strong> production and Dark Matter from your Antimatter</p>
                        <div class="miner-progress">
                            <div class="miner-progress-bar" role="progressbar" aria-labelledby="miner-crunch-progress-label"
                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-miner-crunch-progress><span class="miner-progress-fill"></span></div>
                            <p class="miner-progress-label" id="miner-crunch-progress-label">
                                <span data-miner-crunch="dark">0</span> of <span data-miner-crunch="target"><?= number_format(HBHUB_MINER_CRUNCH_DARK) ?></span> Dark Matter earned this universe
                            </p>
                        </div>
                        <div class="miner-prestige-actions">
                            <button type="button" class="miner-prestige-btn" data-miner-crunch-button disabled>
                                <span class="miner-prestige-btn-text">Big Crunch</span>
                                <span class="miner-prestige-btn-gain">+<span data-miner-crunch="gain">0</span> Antimatter</span>
                            </button>
                            <!-- With the Auto Crunch skill: Big Crunches by itself while it's on (autoPrestige() in spaceOreMiner.js) -->
                            <button type="button" class="miner-amount miner-auto-prestige" aria-pressed="false"
                                    title="Big Crunch by itself whenever a crunch would multiply the Antimatter you've earned by at least the number next to it" data-miner-auto-crunch hidden>
                                Auto Crunch
                            </button>
                            <!-- The same for Auto Crunch and Antimatter -->
                            <label class="miner-auto-times" title="Auto Crunch waits until a crunch would multiply the Antimatter you've earned by at least this much" data-miner-auto-crunch-times hidden>
                                &times;<input type="number" min="2" step="any" value="4" inputmode="decimal" aria-label="Auto Crunch crunches at this many times your Antimatter">
                            </label>
                        </div>
                        <p class="miner-prestige-hint" data-miner-crunch-hint></p>
                    </div>
                </div>
            </div>
            <div data-miner-crunch-section hidden>
                <p class="casino-hint miner-prestige-about">
                    A Big Crunch collapses the whole universe and starts it over. Your Dark Matter and its upgrades go too, but
                    you get Antimatter for the Dark Matter you earned. Every Antimatter you've earned makes everything mine
                    <?= HBHUB_MINER_ANTI_BONUS * 100 ?>% faster, and Warp Jumps give more Dark Matter too: the square root of how
                    much faster it mines (twice the Dark Matter when it's four times as fast), until a Big Rip tears the
                    multiverse apart.
                </p>
                <div class="miner-store-head miner-prestige-store-head">
                    <h3 class="miner-store-title">Antimatter upgrades</h3>
                    <p class="miner-prestige-owned" data-miner-anti-owned></p>
                </div>
                <ul class="miner-perks" data-miner-anti-upgrades></ul>
            </div>
        </section>

        <!-- Strange Matter: the Big Rip on top, built like the other two, and the skill tree Strange Matter buys under
             it. Locked until the first Big Crunch. The tree (data-miner-tree) is drawn by spaceOreMiner.js from the
             skills' places in HBHUB_MINER_SKILLS: a button per skill, with the lines to the skills it needs behind them.
             Picking one shows it in data-miner-skill-info, over the tree next to it, with the button that learns it. -->
        <section class="miner-view casino-card miner-prestige is-strange is-locked" aria-labelledby="miner-rip-title" data-miner-view="strangeMatter" data-miner-rip-view<?= $minerView !== 'strangeMatter' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- A wobbling strangelet with its three quarks flying round it, one on each orbit -->
                <div class="miner-prestige-art" aria-hidden="true">
                    <span class="miner-art-ring is-1"><span class="miner-art-quark"></span></span>
                    <span class="miner-art-ring is-2"><span class="miner-art-quark"></span></span>
                    <span class="miner-art-ring is-3"><span class="miner-art-quark"></span></span>
                    <span class="miner-art-core"></span>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-rip-title" class="miner-prestige-title">Big Rip</h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-strange>0</span> Strange Matter</p>
                    <p class="miner-locked" data-miner-rip-locked>
                        <?= hbHubMinerIcon('lock', 'miner-locked-icon') ?>
                        Something even stranger than a Big Crunch is out there. Do your first Big Crunch to find out what.
                    </p>
                    <div data-miner-rip-section hidden>
                        <p class="miner-prestige-bonus"><strong data-miner-rip="bonus">+0%</strong> production from the Strange Matter you've earned</p>
                        <div class="miner-progress">
                            <div class="miner-progress-bar" role="progressbar" aria-labelledby="miner-rip-progress-label"
                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-miner-rip-progress><span class="miner-progress-fill"></span></div>
                            <p class="miner-progress-label" id="miner-rip-progress-label">
                                <span data-miner-rip="anti">0</span> of <span data-miner-rip="target"><?= number_format(HBHUB_MINER_RIP_ANTI) ?></span> Antimatter earned this multiverse
                            </p>
                        </div>
                        <button type="button" class="miner-prestige-btn" data-miner-rip-button disabled>
                            <span class="miner-prestige-btn-text">Big Rip</span>
                            <span class="miner-prestige-btn-gain">+<span data-miner-rip="gain">0</span> Strange Matter</span>
                        </button>
                        <p class="miner-prestige-hint" data-miner-rip-hint></p>
                    </div>
                </div>
            </div>
            <div data-miner-rip-section hidden>
                <p class="casino-hint miner-prestige-about">
                    A Big Rip tears the whole multiverse apart and starts it over. Your Antimatter and its upgrades go too, and
                    everything a Big Crunch takes, but you get Strange Matter for the Antimatter you earned:
                    <?= hbHubMinerStrangeTotal(HBHUB_MINER_RIP_ANTI) ?> for <?= number_format(HBHUB_MINER_RIP_ANTI) ?>, and
                    <?= count(HBHUB_MINER_STRANGE_STEPS) ?> more for every ten times as much. Like a Warp Jump, it only gives
                    what that's more than all the Strange Matter you've earned before, so each rip has to beat your best
                    multiverse. Every Strange Matter you've earned
                    makes everything mine <?= HBHUB_MINER_STRANGE_BONUS * 100 ?>% faster for good, and you spend it on skills in
                    the tree below, which you keep for good too. A skill can be learned once every skill linked to the left of it is.
                </p>
                <div class="miner-store-head miner-prestige-store-head">
                    <h3 class="miner-store-title">Skill tree</h3>
                    <p class="miner-prestige-owned" data-miner-skills-owned></p>
                </div>
                <div class="miner-tree-wrap" data-miner-tree-wrap>
                    <!-- Scrolls sideways on smaller screens, where the tree would be too small to tap -->
                    <div class="miner-tree-scroll" data-miner-tree-scroll>
                        <div class="miner-tree" role="group" aria-label="Skill tree" data-miner-tree></div>
                    </div>
                    <!-- The picked skill, laid over the tree next to it (placeSkillInfo() in spaceOreMiner.js). Hidden
                         until a skill is picked; closed with ×, Escape, a click outside it or a click on the skill again. -->
                    <div class="miner-skill-info" role="region" aria-labelledby="miner-skill-name" data-miner-skill-info hidden>
                        <div class="miner-skill-info-head">
                            <span class="miner-skill-info-icon" data-miner-skill-icon></span>
                            <div class="miner-skill-info-title">
                                <h4 class="miner-skill-info-name" id="miner-skill-name" data-miner-skill-name></h4>
                                <p class="miner-skill-info-branch" data-miner-skill-branch></p>
                            </div>
                            <button type="button" class="miner-popup-close" data-miner-skill-close aria-label="Close">&times;</button>
                        </div>
                        <p class="miner-skill-info-text" data-miner-skill-text></p>
                        <p class="miner-skill-info-needs" data-miner-skill-needs></p>
                        <button type="button" class="miner-prestige-btn miner-skill-learn" data-miner-skill-learn>
                            <span class="miner-prestige-btn-text">Learn</span>
                            <span class="miner-prestige-btn-gain" data-miner-skill-cost></span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Engine: the Cosmic Engine the whole site builds together, built like the other views. On top the Engine
             drawn as a rocket being built, the stage being built, its parts and its lock; under it what you can give, today's Starbound Shifts, every stage with
             its perk, and who gave the most and last. All filled in by spaceOreMiner.js (renderEngine()) from every
             save's answer. -->
        <section class="miner-view casino-card miner-prestige is-engine" aria-labelledby="miner-engine-title" data-miner-view="starbound" data-miner-engine-view<?= $minerView !== 'starbound' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- The Engine as a rocket on its launch pad, one part for each stage (renderRocket() in spaceOreMiner.js).
                     Parts of stages done are in Project Starbound's paint (data-engine), the stage being built fills up from the
                     bottom as parts come in (a copy of it in data-miner-rocket-fill, clipped at the line, with the welder
                     on the line and the crane's hook at its top), and the rest are blueprint outlines. data-miner-rocket-part
                     is the stage in Project Starbound counting from 0, data-box its x1 y1 x2 y2 here. They're drawn back to front,
                     so not in stage order. Builds done before this one fly in the sky (data-miner-rocket-fleet). -->
                <div class="miner-rocket-art">
                    <svg class="miner-rocket" viewBox="0 0 240 320" role="img" aria-label="Project Starbound, drawn as a rocket being built" data-miner-rocket>
                        <defs>
                            <!-- The hull's shine, round like a can: dark at the edges, light in the middle -->
                            <linearGradient id="miner-rocket-shine">
                                <stop offset="0" class="is-shade"></stop>
                                <stop offset="0.35" class="is-hull"></stop>
                                <stop offset="0.5" class="is-light"></stop>
                                <stop offset="0.65" class="is-hull"></stop>
                                <stop offset="1" class="is-shade"></stop>
                            </linearGradient>
                            <clipPath id="miner-rocket-clip"><rect x="0" y="320" width="240" height="0" data-miner-rocket-clip></rect></clipPath>
                        </defs>
                        <g data-miner-rocket-fleet></g>

                        <!-- The crane on the right, its trolley over the part being built -->
                        <g class="miner-rocket-crane">
                            <path class="is-line" d="M214 296 V12 M20 12 H232 M214 30 L232 12 M214 30 L196 12 M214 60 H220 M214 120 H220 M214 180 H220 M214 240 H220"></path>
                            <line class="miner-rocket-cable" x1="120" y1="14" x2="120" y2="40" data-miner-rocket-cable></line>
                            <rect class="miner-rocket-trolley" x="114" y="9" width="12" height="6" rx="1" data-miner-rocket-trolley></rect>
                        </g>
                        <rect class="miner-rocket-ground" x="0" y="296" width="240" height="24"></rect>
                        <path class="miner-rocket-pad" d="M66 284 H174 L180 296 H60 Z"></path>

                        <!-- The tower: Scaffolding and the Countdown Clock on top -->
                        <g class="miner-rocket-part" data-miner-rocket-part="0" data-box="26 56 62 296">
                            <path class="is-line" d="M32 296 V60 M56 296 V60 M32 272 H56 M32 224 H56 M32 176 H56 M32 128 H56 M32 80 H56 M32 296 L56 272 L32 248 L56 224 L32 200 L56 176 L32 152 L56 128 L32 104 L56 80"></path>
                            <rect class="is-metal" x="26" y="56" width="36" height="5" rx="1"></rect>
                        </g>
                        <g class="miner-rocket-part" data-miner-rocket-part="22" data-box="14 30 74 56">
                            <path class="is-line" d="M36 52 V56 M52 52 V56"></path>
                            <rect class="is-screen" x="14" y="30" width="60" height="22" rx="3"></rect>
                            <text class="miner-rocket-clock" x="44" y="45" text-anchor="middle" data-miner-rocket-clock>T-25</text>
                        </g>

                        <!-- The rocket itself, which lifts off once it's done -->
                        <g class="miner-rocket-ship">
                            <g class="miner-rocket-part" data-miner-rocket-part="24" data-box="100 284 140 318">
                                <path class="is-flame" d="M100 284 Q102 304 120 318 Q138 304 140 284 Z"></path>
                                <path class="is-flame-core" d="M108 284 Q110 298 120 307 Q130 298 132 284 Z"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="10" data-box="74 216 166 280">
                                <path class="is-hull-2" d="M95 216 L74 262 L74 280 L95 258 Z M145 216 L166 262 L166 280 L145 258 Z"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="7" data-box="84 150 156 194">
                                <rect class="is-hull-2" x="84" y="150" width="11" height="44" rx="4"></rect>
                                <rect class="is-hull-2" x="145" y="150" width="11" height="44" rx="4"></rect>
                                <circle class="is-lamp" cx="89.5" cy="188" r="2"></circle>
                                <circle class="is-lamp is-2" cx="150.5" cy="188" r="2"></circle>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="21" data-box="84 167 156 183">
                                <path class="is-coil" d="M86 168 l7 3 l-7 3 l7 3 l-7 3 l7 3 M147 168 l7 3 l-7 3 l7 3 l-7 3 l7 3"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="11" data-box="96 252 144 266">
                                <rect class="is-metal" x="97" y="252" width="4" height="14" rx="1"></rect>
                                <rect class="is-metal" x="139" y="252" width="4" height="14" rx="1"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="12" data-box="103 254 137 264">
                                <rect class="is-metal" x="103" y="254" width="34" height="10" rx="3"></rect>
                                <rect class="is-glow" x="108" y="257" width="24" height="4" rx="2"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="13" data-box="100 264 140 284">
                                <path class="is-metal" d="M109 264 H131 L140 284 H100 Z"></path>
                                <path class="is-line" d="M105 274 H135"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="20" data-box="84 264 156 277">
                                <path class="is-spark" d="M98 265 L90 268 L95 271 L86 276 M142 265 L150 268 L145 271 L154 276"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="1" data-box="95 214 145 250">
                                <rect class="is-hull" x="95" y="214" width="50" height="36"></rect>
                                <rect class="is-metal" x="110" y="220" width="20" height="18" rx="2"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="3" data-box="95 178 145 214">
                                <rect class="is-hull" x="95" y="178" width="50" height="36"></rect>
                                <circle class="is-glass" cx="110" cy="196" r="4"></circle>
                                <circle class="is-glass" cx="120" cy="196" r="4"></circle>
                                <circle class="is-glass" cx="130" cy="196" r="4"></circle>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="5" data-box="95 142 145 178">
                                <rect class="is-hull" x="95" y="142" width="50" height="36"></rect>
                                <circle class="is-metal" cx="120" cy="160" r="11"></circle>
                                <circle class="is-glow" cx="120" cy="160" r="6"></circle>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="15" data-box="95 106 145 142">
                                <rect class="is-hull" x="95" y="106" width="50" height="36"></rect>
                                <path class="is-trim" d="M127 115 L129.5 121.5 L136 122 L131 126 L132.5 132.5 L127 129 L121.5 132.5 L123 126 L118 122 L124.5 121.5 Z"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="18" data-box="95 30 145 106">
                                <path class="is-line" d="M120 42 V30"></path>
                                <path class="is-hull" d="M95 106 C95 76 106 56 120 40 C134 56 145 76 145 106 Z"></path>
                                <ellipse class="is-glass" cx="120" cy="82" rx="8" ry="10"></ellipse>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="6" data-box="101 146 139 246">
                                <path class="is-pipe" d="M103 148 V238 Q103 244 109 244 H131 Q137 244 137 238 V148"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="8" data-box="84 155 156 164">
                                <path class="is-wire" d="M86 157 H110 M86 162 H110 M130 157 H154 M130 162 H154"></path>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="17" data-box="100 111 115 124">
                                <rect class="is-screen" x="100" y="111" width="15" height="13" rx="2"></rect>
                                <circle class="is-lamp" cx="104" cy="117.5" r="1.5"></circle>
                                <circle class="is-lamp is-2" cx="107.5" cy="117.5" r="1.5"></circle>
                                <circle class="is-lamp is-3" cx="111" cy="117.5" r="1.5"></circle>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="16" data-box="86 117 154 131">
                                <ellipse class="is-ring" cx="120" cy="124" rx="34" ry="7"></ellipse>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="14" data-box="93 246 147 254">
                                <rect class="is-trim" x="93" y="246" width="54" height="8" rx="2"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="4" data-box="93 210 147 218">
                                <rect class="is-trim" x="93" y="210" width="54" height="8" rx="2"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="9" data-box="93 174 147 182">
                                <rect class="is-trim" x="93" y="174" width="54" height="8" rx="2"></rect>
                            </g>
                            <g class="miner-rocket-part" data-miner-rocket-part="19" data-box="93 138 147 146">
                                <rect class="is-trim" x="93" y="138" width="54" height="8" rx="2"></rect>
                            </g>
                        </g>

                        <!-- From the tower to the rocket: the Fuel Lines and the Launch Clamps, let go at lift-off -->
                        <g class="miner-rocket-part is-hoses" data-miner-rocket-part="2" data-box="56 194 95 240">
                            <path class="is-hose" d="M56 232 Q76 242 95 232 M56 194 Q76 204 95 194"></path>
                        </g>
                        <g class="miner-rocket-part is-clamps" data-miner-rocket-part="23" data-box="56 114 97 210">
                            <path class="is-line is-thick" d="M56 120 H92 M56 204 H92"></path>
                            <rect class="is-hull-2" x="90" y="114" width="7" height="12" rx="1"></rect>
                            <rect class="is-hull-2" x="90" y="198" width="7" height="12" rx="1"></rect>
                        </g>

                        <!-- The stage being built: its filled-in part, the welder on the line, or a padlock while it waits on its lock -->
                        <g class="miner-rocket-fill" clip-path="url(#miner-rocket-clip)" data-miner-rocket-fill></g>
                        <g class="miner-rocket-welder" data-miner-rocket-welder>
                            <circle class="is-ball" r="4"></circle>
                            <path class="is-spark" d="M0 0 L-7 -5 M0 0 L7 -6 M0 0 L-5 6 M0 0 L6 5"></path>
                        </g>
                        <g class="miner-rocket-padlock" data-miner-rocket-padlock>
                            <path class="is-shackle" d="M-6 -2 V-7 A6 6 0 0 1 6 -7 V-2"></path>
                            <rect x="-9" y="-2" width="18" height="14" rx="3"></rect>
                            <circle class="is-hole" cx="0" cy="5" r="2"></circle>
                        </g>
                        <g class="miner-rocket-smoke">
                            <circle cx="66" cy="300" r="10"></circle>
                            <circle cx="174" cy="300" r="10"></circle>
                            <circle cx="88" cy="296" r="14"></circle>
                            <circle cx="152" cy="296" r="14"></circle>
                            <circle cx="120" cy="302" r="16"></circle>
                        </g>
                    </svg>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-engine-title" class="miner-prestige-title"><span data-miner-engine="title">Project Starbound</span> <span data-miner-engine="number"></span></h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-engine="name">Loading…</span></p>
                    <p class="miner-prestige-bonus" data-miner-engine="stage"></p>
                    <!-- One pip a stage, the same as the rocket (only for the mouse: the Stages list below says it all) -->
                    <ol class="miner-rocket-track" aria-hidden="true" data-miner-rocket-track></ol>
                    <div class="miner-progress">
                        <div class="miner-progress-bar" role="progressbar" aria-labelledby="miner-engine-progress-label"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-miner-engine-progress><span class="miner-progress-fill"></span></div>
                        <p class="miner-progress-label" id="miner-engine-progress-label">
                            <span data-miner-engine="parts">0</span> of <span data-miner-engine="cost">0</span> Starbound Parts
                            (<span data-miner-engine="total">0</span> given in all)
                        </p>
                    </div>
                    <p class="miner-prestige-hint" data-miner-engine-lock hidden></p>
                </div>
            </div>
            <p class="casino-hint miner-prestige-about">
                Everyone on HB Hub builds Project Starbound together, one stage at a time, out of Starbound Parts. Each stage
                finished gives everyone who plays its perk, for good. Some stages have a lock as well, which has to be met
                before they finish, however many parts they have. Once a build is done, the next one starts, with locks
                that count again from nothing. Anyone who has made a Warp Jump can give parts. The rocket shows how far
                it is: each stage done adds its part, and the one being built fills up as parts come in. Point at a
                part to see its stage.
            </p>

            <div class="miner-engine-grid">
                <section class="miner-engine-card" aria-labelledby="miner-engine-give-title">
                    <h3 class="miner-store-title" id="miner-engine-give-title">Give parts</h3>
                    <p class="miner-engine-note" data-miner-engine-give-note hidden></p>
                    <div class="miner-engine-give">
                        <button type="button" class="miner-prestige-btn" data-miner-donate="ore" disabled>
                            <span class="miner-prestige-btn-text">Give 1 part</span>
                            <span class="miner-prestige-btn-gain">for <span data-miner-engine="oreCost"></span> ore</span>
                        </button>
                        <p class="miner-engine-small" data-miner-engine="oreLeft"></p>
                    </div>
                    <div class="miner-engine-give">
                        <label class="miner-engine-count">
                            <span>Parts from Strange Matter</span>
                            <input type="number" min="1" step="1" value="1" inputmode="numeric" data-miner-donate-count>
                        </label>
                        <button type="button" class="miner-prestige-btn" data-miner-donate="strange" disabled>
                            <span class="miner-prestige-btn-text">Give</span>
                            <span class="miner-prestige-btn-gain">for <span data-miner-engine="strangeCost"></span> Strange Matter</span>
                        </button>
                        <p class="miner-engine-small">You have <span data-miner-engine="strangeHave">0</span> Strange Matter to spend. Giving it doesn't make you mine slower: the bonus counts all you've earned.</p>
                    </div>
                    <p class="miner-engine-small">
                        Parts from ore: the first of the day costs <?= hbHubMinerSkillNumber(HBHUB_MINER_ENGINE_ORE_PART) ?> ore and each next one ten times
                        as much, <?= HBHUB_MINER_ENGINE_ORE_PARTS ?> a day at most (more with some perks). Parts from Strange Matter: <?= HBHUB_MINER_ENGINE_STRANGE_PART ?> each, as many as you like.
                    </p>
                    <p class="miner-engine-small" data-miner-engine="mine" hidden></p>
                </section>

                <section class="miner-engine-card" aria-labelledby="miner-shifts-title">
                    <h3 class="miner-store-title" id="miner-shifts-title">Today's Starbound Shifts</h3>
                    <ul class="miner-shifts" data-miner-shifts></ul>
                    <p class="miner-engine-small" data-miner-shifts-note></p>
                </section>

                <section class="miner-engine-card" aria-labelledby="miner-engine-top-title">
                    <h3 class="miner-store-title" id="miner-engine-top-title">Top contributors</h3>
                    <ol class="miner-engine-list" data-miner-engine-top></ol>
                    <?php if ($guestGame === null): ?>
                        <p class="miner-engine-small"><a href="/games/ore-mining-inc/leaderboard?board=starbound">Everyone who has given parts</a></p>
                    <?php endif; ?>
                </section>

                <section class="miner-engine-card" aria-labelledby="miner-engine-feed-title">
                    <h3 class="miner-store-title" id="miner-engine-feed-title">Latest parts</h3>
                    <ul class="miner-engine-list" data-miner-engine-feed></ul>
                </section>

                <!-- Every Engine done so far, once the Starforge's Alloy Bins are (the hall perk, renderEngineHall()) -->
                <section class="miner-engine-card is-wide" aria-labelledby="miner-engine-hall-title" data-miner-engine-hall hidden>
                    <h3 class="miner-store-title" id="miner-engine-hall-title">Starbound Hall</h3>
                    <ul class="miner-engine-list" data-miner-engine-hall-list></ul>
                </section>

                <section class="miner-engine-card is-wide" aria-labelledby="miner-engine-stages-title">
                    <h3 class="miner-store-title" id="miner-engine-stages-title">Stages</h3>
                    <ol class="miner-engine-stages" data-miner-engine-stages></ol>
                </section>
            </div>
        </section>

        <!-- Anomalies: the challenge runs, by tier (HBHUB_MINER_ANOMALY_TIERS), each with its rule, goal, parts, best time
             and badge, and a button to go in. Filled in by spaceOreMiner.js (renderAnomalies()). -->
        <section class="miner-view casino-card miner-prestige is-anomaly" aria-labelledby="miner-anomalies-title" data-miner-view="anomalies"<?= $minerView !== 'anomalies' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- A rip in space: two rings pulling the wrong way round a dark core -->
                <div class="miner-prestige-art" aria-hidden="true">
                    <span class="miner-art-ring is-1"></span>
                    <span class="miner-art-ring is-2"></span>
                    <span class="miner-art-core"></span>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-anomalies-title" class="miner-prestige-title">Anomalies</h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-anomalies-count>0</span> finished</p>
                    <p class="miner-prestige-hint" data-miner-anomalies-now></p>
                    <!-- With the Tool Racks perk: the last Anomaly or stack gone into, again (renderAgain()) -->
                    <button type="button" class="casino-btn casino-btn-primary" data-miner-anomaly-again hidden>Again</button>
                </div>
            </div>
            <p class="casino-hint miner-prestige-about">
                An Anomaly is a universe of its own with one rule changed. In Tiers I and II you go in with your skill tree
                and your Strange Matter, but no Dark Matter or Antimatter, and finish it by earning its goal of Antimatter in
                there. Tier III starts you from nothing: no skills, no Strange Matter and no upgrades. While you're in one,
                your own game waits and mines as if you were away. You can leave whenever you like: with Save and leave,
                you can carry on where you were later (up to <?= HBHUB_MINER_ANOMALY_SAVED_MAX ?> saved at once, not Speed of Light),
                otherwise you'll start over next time. A saved run's clock keeps going, and it mines as if you were away until
                you carry on. Finishing one gives Project Starbound being built parts: all of them the first time for each build
                and whenever you beat your best time, <?= HBHUB_MINER_ANOMALY_REPEAT * 100 ?>% otherwise. Every time you finish one, your own
                game also gets an Afterglow: it mines <?= HBHUB_MINER_AFTERGLOW_TIMES ?> times as much for a while (an hour for Tier I, 3 for
                Tier II, 12 for Tier III, 24 for The Gauntlet, and a stack's added up), on top of any Afterglow still running, up to
                <?= HBHUB_MINER_AFTERGLOW_MAX_MS / 3600000 ?> hours ahead. What you do in there doesn't count
                towards your stats or the leaderboard, but finishing them has achievements of its own.
            </p>
            <!-- The Anomalies saved to carry on later, filled in by spaceOreMiner.js (renderSaved()); hidden when there are none -->
            <section class="miner-anomaly-tier miner-anomaly-saved" aria-labelledby="miner-saved-title" data-miner-saved hidden>
                <h3 class="miner-store-title" id="miner-saved-title">Saved Anomalies</h3>
                <p class="miner-engine-small">Their clocks keep going while they're saved, and they mine as if you were away until you carry on.</p>
                <ul class="miner-anomaly-list" data-miner-saved-list></ul>
            </section>
            <!-- This week's Anomaly and the stack builder, filled in by spaceOreMiner.js (renderWeekly(), renderStack()) -->
            <section class="miner-anomaly-tier miner-anomaly-weekly" aria-labelledby="miner-weekly-title">
                <h3 class="miner-store-title" id="miner-weekly-title">This week's Anomaly</h3>
                <p class="miner-engine-small" data-miner-weekly-text></p>
                <p class="miner-engine-small" data-miner-weekly-best></p>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-weekly-start>Go in</button>
                <a class="casino-btn" href="/games/ore-mining-inc/leaderboard?board=weekly">Fastest this week</a>
                <!-- With the Heat Shields perk -->
                <p class="miner-engine-small" data-miner-weekly-next hidden></p>
            </section>
            <section class="miner-anomaly-tier miner-anomaly-stack" aria-labelledby="miner-stack-title">
                <h3 class="miner-store-title" id="miner-stack-title">Stack Anomalies</h3>
                <p class="miner-engine-small">
                    Play Tier I and II Anomalies together, with all their rules at once. The goal is the lowest of theirs,
                    and the parts are theirs added up, times <?= HBHUB_MINER_ANOMALY_STACK ?> for every one after the first. Their Afterglows
                    are added up too. Unstable Vacuum only goes on its own, and some pairs can't be done together, so those can't
                    be picked (point at a greyed-out one to see why).
                </p>
                <div class="miner-stack-picks" data-miner-stack-picks></div>
                <p class="miner-engine-small" data-miner-stack-text></p>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-stack-start disabled>Go in</button>
            </section>
            <div class="miner-anomaly-tiers" data-miner-anomaly-tiers></div>
        </section>

        <!-- The Shard Shop (HBHUB_MINER_SHOP): on top a big Star Shard and how many you have, then a list per section,
             filled in by spaceOreMiner.js (renderShop()) -->
        <section class="miner-view casino-card miner-prestige is-shop" aria-labelledby="miner-shop-title" data-miner-view="shop"<?= $minerView !== 'shop' ? ' hidden' : '' ?>>
            <div class="miner-prestige-hero">
                <!-- A Star Shard glittering in a ring of its own light -->
                <div class="miner-prestige-art" aria-hidden="true">
                    <span class="miner-art-ring is-1"></span>
                    <span class="miner-art-ring is-2"></span>
                    <span class="miner-art-core"></span>
                </div>
                <div class="miner-prestige-main">
                    <h2 id="miner-shop-title" class="miner-prestige-title">Shard Shop</h2>
                    <p class="miner-prestige-balance"><span class="miner-prestige-amount" data-miner-shard-count>0</span> <span data-miner-shard-word>Star Shards</span></p>
                    <p class="miner-prestige-hint">
                        A Star Shard flies past about every <?= HBHUB_MINER_SHARD_SLOT_MS / 60000 ?> minutes while the game is open, the
                        other way from the comets, and only stays <?= HBHUB_MINER_SHARD_STAY_MS / 1000 ?> seconds. Click it to keep it.
                    </p>
                    <p class="miner-prestige-hint miner-shop-note" data-miner-shop-note hidden>
                        You're in an Anomaly: boosts, ore and comets are for your own game, so they wait until you're back.
                    </p>
                </div>
            </div>
            <p class="casino-hint miner-prestige-about">
                Star Shards are yours for good: Warp Jumps, Big Crunches and Big Rips don't take them, and neither do Anomalies.
            </p>
            <h3 class="miner-store-title">Boosts</h3>
            <ul class="miner-shop-list" data-miner-shop-list="boost"></ul>
            <h3 class="miner-store-title">Ore</h3>
            <ul class="miner-shop-list" data-miner-shop-list="ore"></ul>
            <h3 class="miner-store-title">Asteroid skins</h3>
            <ul class="miner-shop-list" data-miner-shop-list="skin"></ul>
            <h3 class="miner-store-title">Click effects</h3>
            <ul class="miner-shop-list" data-miner-shop-list="effect"></ul>
        </section>
        </div><!-- .miner-stage -->
        </div><!-- .miner-body -->

        <button type="button" class="miner-comet" data-miner-comet hidden aria-label="Catch the Golden Comet"><span class="miner-comet-head"></span></button>
        <!-- A Star Shard, flying the other way (HBHUB_MINER_SHARD_SLOT_MS in miner.php: one every 15 minutes or so) -->
        <button type="button" class="miner-shard" data-miner-shard hidden aria-label="Catch the Star Shard"><span class="miner-shard-gem"></span></button>
        <ol class="miner-toasts" data-miner-toasts aria-live="polite"></ol>

        <!-- The popups from the buttons by the title. Clicking outside one closes it. -->
        <dialog class="games-dialog miner-popup" data-miner-popup="stats" aria-labelledby="miner-stats-title">
            <div class="miner-popup-body">
                <div class="miner-popup-head">
                    <h2 id="miner-stats-title">Your stats</h2>
                    <button type="button" class="miner-popup-close" data-miner-close aria-label="Close">&times;</button>
                </div>
                <dl class="miner-numbers" data-miner-stats></dl>
                <!-- Asteroid skins (HBHUB_MINER_SKINS), filled in by spaceOreMiner.js (renderSkins()) -->
                <h3 class="miner-store-title">Asteroid skin</h3>
                <div class="miner-skins" data-miner-skins></div>
                <!-- With the Starforge's perks (renderLooks()): backgrounds (HBHUB_MINER_BACKDROPS) and the sparks switch -->
                <div data-miner-looks hidden>
                    <h3 class="miner-store-title">Background</h3>
                    <div class="miner-skins" data-miner-backdrops></div>
                    <label class="miner-sparks-switch" data-miner-sparks-box hidden>
                        <input type="checkbox" data-miner-sparks> Sparks when clicking the asteroid
                    </label>
                </div>
            </div>
        </dialog>

        <dialog class="games-dialog miner-popup" data-miner-popup="achievements" aria-labelledby="miner-achievements-title">
            <div class="miner-popup-body">
                <div class="miner-popup-head">
                    <h2 id="miner-achievements-title">Achievements</h2>
                    <p class="miner-achievement-count" data-miner-achievement-count>0 / <?= count(HBHUB_MINER_ACHIEVEMENTS) ?></p>
                    <button type="button" class="miner-popup-close" data-miner-close aria-label="Close">&times;</button>
                </div>
                <ul class="miner-achievement-list" data-miner-achievements></ul>
            </div>
        </dialog>

        <dialog class="games-dialog miner-popup" data-miner-popup="help" aria-labelledby="miner-rules-title">
            <div class="miner-popup-body">
                <div class="miner-popup-head">
                    <h2 id="miner-rules-title">How to play</h2>
                    <button type="button" class="miner-popup-close" data-miner-close aria-label="Close">&times;</button>
                </div>
                <p>
                    You're a lone miner on a tiny asteroid. Click it to chip off ore, spend the ore on machines that mine for you,
                    and grow into a galaxy-wide mining company.
                </p>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/rules.php'; ?>
            </div>
        </dialog>

        <dialog class="games-dialog miner-prestige-dialog is-dark" data-miner-warp-dialog aria-labelledby="miner-warp-dialog-title">
            <h2 id="miner-warp-dialog-title">Warp to a new galaxy?</h2>
            <p data-miner-warp-dialog-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-warp-cancel>Not yet</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-warp-confirm>Warp Jump</button>
            </div>
        </dialog>

        <dialog class="games-dialog miner-prestige-dialog is-anti" data-miner-crunch-dialog aria-labelledby="miner-crunch-dialog-title">
            <h2 id="miner-crunch-dialog-title">Collapse the universe?</h2>
            <p data-miner-crunch-dialog-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-crunch-cancel>Not yet</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-crunch-confirm>Big Crunch</button>
            </div>
        </dialog>

        <dialog class="games-dialog miner-prestige-dialog is-strange" data-miner-rip-dialog aria-labelledby="miner-rip-dialog-title">
            <h2 id="miner-rip-dialog-title">Tear the multiverse apart?</h2>
            <p data-miner-rip-dialog-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-rip-cancel>Not yet</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-rip-confirm>Big Rip</button>
            </div>
        </dialog>

        <dialog class="games-dialog miner-prestige-dialog is-anomaly" data-miner-anomaly-dialog aria-labelledby="miner-anomaly-dialog-title">
            <h2 id="miner-anomaly-dialog-title" data-miner-anomaly-dialog-title></h2>
            <p data-miner-anomaly-dialog-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-anomaly-cancel>Not yet</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-anomaly-confirm></button>
            </div>
        </dialog>

        <!-- Shown the first time the Hot Key Panel upgrade is bought in this browser (showKeysHelp() in spaceOreMiner.js) -->
        <dialog class="games-dialog" data-miner-keys-dialog aria-labelledby="miner-keys-dialog-title">
            <h2 id="miner-keys-dialog-title">Hot keys unlocked</h2>
            <p>
                <kbd>1</kbd>-<kbd>9</kbd> and <kbd>0</kbd> now buy the buildings in the store, <kbd>B</kbd> the best one you
                can afford and <kbd>U</kbd> the cheapest upgrade. Hold a key down to keep buying.
            </p>
            <p><a href="/docs/games/ore-mining-inc#hot-keys" target="_blank" rel="noopener">See all the hot keys</a> (opens in a new tab)</p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn casino-btn-primary" data-miner-keys-close>Got it</button>
            </div>
        </dialog>

        <dialog class="games-dialog" data-miner-other-tab aria-labelledby="miner-other-tab-title">
            <h2 id="miner-other-tab-title">Playing somewhere else</h2>
            <p>You opened Ore Mining Inc. in another tab or on another device, so it's stopped here. Your game carries on there.</p>
            <div class="games-dialog-actions">
                <?php if ($guestGame !== null): ?>
                    <a class="casino-btn" href="/">Back to HB Hub</a>
                <?php else: ?>
                    <a class="casino-btn" href="/games/">Back to Games</a>
                <?php endif; ?>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-reload>Play here instead</button>
            </div>
        </dialog>

        <?php if ($guestGame === null): ?>
        <!-- Only shown after a crash: whether the reports the page found may be sent (see "Crash reports" in
             spaceOreMiner.js). What would be sent is under "What's in it", word for word. Not for guests, who
             can't send crash reports. -->
        <dialog class="games-dialog miner-crash-dialog" data-miner-crash-dialog aria-labelledby="miner-crash-title">
            <h2 id="miner-crash-title">The game crashed last time</h2>
            <p data-miner-crash-text></p>
            <p>
                Can we save a crash report about it? It would help a lot in finding out what went wrong. It has what the
                game was doing when it happened, the last things that happened in it (saves, errors, slow moments), how
                much memory it used, its frame rate, your save, your screen size, your graphics card and which browser you
                use. Only site admins can see it,
                and it's deleted after <?= HBHUB_MINER_CRASH_KEEP_DAYS ?> days.
            </p>
            <details class="miner-crash-details">
                <summary>What's in it</summary>
                <pre class="miner-crash-json" data-miner-crash-json></pre>
            </details>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-miner-crash-skip>Don't send</button>
                <button type="button" class="casino-btn casino-btn-primary" data-miner-crash-send>Send crash report</button>
            </div>
        </dialog>
        <?php endif; ?>
    </main>
    <?php if ($guestGame !== null): ?>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/bottom.php'; ?>
    <?php endif; ?>
    <?php $hbHubShowSiteFooter = $guestGame !== null; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/spaceOreMiner.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/spaceOreMiner.js') ?>" defer></script>
</body>
</html>
