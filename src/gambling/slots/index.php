<?php
// Slots: the slot machine, the bet and the user's last spins. Every spin is the user's own (no table to share), sent
// by slots.js to /gambling/slots/spin, where the reels' stops are picked and the chips are paid
// (assets/php/casino/slots/slots.php has the rules).
// The machine is drawn here as three reels of three symbols, standing where the user's last spin stopped (or at the
// top of each strip before their first). The strips, the symbols and the paylines go to slots.js in data attributes
// (data-reels, data-symbols, data-lines), so it can roll the reels round the real strips to where the server says they
// stop. The paylines are drawn over the reels as an SVG, one path each, and their numbers are down both sides.
// The bet and the Spin button are on the machine itself, under the reels, so the whole thing fits on the screen
// without scrolling (slots.css sizes the reels to the window's height). A banner over the machine, a flash, a glow round
// the screen's edges and a canvas over the page are there for slots.js's win effects, and the lever beside the machine,
// the bulbs down its sides, a "comet" on each payline and a badge for what a line paid for the rest of its show.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
$slotsHistory = hbHubSlotsHistory($db, $userId);
$slotsWindow = hbHubSlotsWindow($slotsHistory[0]['stops'] ?? [0, 0, 0]);
$slotsBets = [10, 50, 100, 500, 1000]; // the quick bets under the machine
$slotsLineCount = count(HBHUB_SLOTS_LINES);

/**
 * A symbol on a reel, as slots.js makes them too.
 */
function slotsSymbolHtml(string $symbol): string
{
    [$name, $shown] = HBHUB_SLOTS_SYMBOLS[$symbol];
    return '<span class="sl-symbol sl-symbol-' . $symbol . '" data-sl-symbol="' . $symbol . '" title="' . $name . '">'
        . '<span class="sl-symbol-face" aria-hidden="true">' . $shown . '</span></span>';
}

/**
 * A payline over the reels as an SVG path, in a 0..300 view box where each reel and each row is 100 wide.
 */
function slotsLinePath(array $rows): string
{
    $points = [];
    foreach ($rows as $reel => $row) {
        $points[] = ($reel * 100 + 50) . ' ' . ($row * 100 + 50);
    }
    return 'M4 ' . ($rows[0] * 100 + 50) . ' L' . implode(' L', $points) . ' L296 ' . (end($rows) * 100 + 50);
}

/**
 * What a spin from the history says to a screen reader, and its title.
 */
function slotsHistoryLabel(array $spin): string
{
    $symbols = implode(', ', array_map(static fn (string $symbol): string => HBHUB_SLOTS_SYMBOLS[$symbol][0], $spin['symbols']));
    return $symbols . ': bet ' . number_format($spin['bet']) . ', ' . ($spin['payout'] > 0 ? 'won ' . number_format($spin['payout']) : 'no win');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/slots.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/slots.css') ?>">
    <title>Slots - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="The slot machine in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="sl" data-slots data-api="/gambling/slots/spin" data-chips="<?= $navCasinoChips ?>"
          data-bet-step="<?= HBHUB_SLOTS_BET_STEP ?>" data-min-bet="<?= HBHUB_SLOTS_MIN_BET ?>"
          data-reels="<?= htmlspecialchars(json_encode(HBHUB_SLOTS_REELS)) ?>"
          data-symbols="<?= htmlspecialchars(json_encode(HBHUB_SLOTS_SYMBOLS, JSON_UNESCAPED_UNICODE)) ?>"
          data-lines="<?= htmlspecialchars(json_encode(HBHUB_SLOTS_LINES)) ?>"
          data-stops="<?= implode(' ', $slotsHistory[0]['stops'] ?? [0, 0, 0]) ?>">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <div class="sl-header">
            <div>
                <h1 class="sl-title">Slots</h1>
            </div>
            <a class="casino-btn" href="/gambling/slots/leaderboard">Leaderboard</a>
        </div>

        <section class="sl-machine" aria-label="The slot machine">
            <!-- A row of bulbs down each side, and the lever, which pulls itself on every spin and spins when clicked
                 (only for the mouse: Spin and Space do the same for everyone else) -->
            <span class="sl-bulbs sl-bulbs-left" aria-hidden="true"></span>
            <span class="sl-bulbs sl-bulbs-right" aria-hidden="true"></span>
            <button type="button" class="sl-lever" data-sl-lever tabindex="-1" aria-hidden="true" title="Pull the lever">
                <span class="sl-lever-arm"><span class="sl-lever-knob"></span></span>
                <span class="sl-lever-base"></span>
            </button>

            <div class="sl-marquee" aria-hidden="true">
                <span class="sl-marquee-lights"></span>
                <span class="sl-marquee-text">Lucky 7s</span>
                <span class="sl-marquee-lights"></span>
            </div>

            <div class="sl-cabinet">
                <ol class="sl-line-numbers sl-line-numbers-left" aria-hidden="true">
                    <?php foreach (HBHUB_SLOTS_LINES as $slotsIndex => $slotsRows): ?>
                        <li class="sl-line-number sl-line-at-<?= $slotsRows[0] ?>" data-sl-line-number="<?= $slotsIndex ?>"><?= $slotsIndex + 1 ?></li>
                    <?php endforeach; ?>
                </ol>

                <div class="sl-window">
                    <!-- What the reels show now, for screen readers: slots.js writes it after each spin -->
                    <p class="casino-sr-only" data-sl-window-text>
                        <?= htmlspecialchars(implode('. ', array_map(static fn (array $row): string => implode(', ',
                            array_map(static fn (string $symbol): string => HBHUB_SLOTS_SYMBOLS[$symbol][0], $row)), $slotsWindow))) ?>
                    </p>
                    <div class="sl-reels" aria-hidden="true">
                        <?php for ($slotsReel = 0; $slotsReel < 3; $slotsReel++): ?>
                            <div class="sl-reel">
                                <div class="sl-reel-strip" data-sl-reel="<?= $slotsReel ?>">
                                    <?php foreach ($slotsWindow as $slotsRow): ?>
                                        <?= slotsSymbolHtml($slotsRow[$slotsReel]) ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <svg class="sl-lines" viewBox="0 0 300 300" preserveAspectRatio="none" aria-hidden="true">
                        <?php foreach (HBHUB_SLOTS_LINES as $slotsIndex => $slotsRows): ?>
                            <path class="sl-line sl-line-<?= $slotsIndex ?>" d="<?= slotsLinePath($slotsRows) ?>" data-sl-line="<?= $slotsIndex ?>"/>
                            <!-- A spot of light running along the line while it's won -->
                            <path class="sl-line-comet" d="<?= slotsLinePath($slotsRows) ?>" data-sl-line-comet="<?= $slotsIndex ?>"/>
                        <?php endforeach; ?>
                    </svg>
                    <!-- What a winning line paid, at its right end (slots.js), one line at a time -->
                    <span class="sl-line-badge" data-sl-line-badge aria-hidden="true" hidden></span>
                </div>

                <ol class="sl-line-numbers sl-line-numbers-right" aria-hidden="true">
                    <?php foreach (HBHUB_SLOTS_LINES as $slotsIndex => $slotsRows): ?>
                        <li class="sl-line-number sl-line-at-<?= end($slotsRows) ?>" data-sl-line-number="<?= $slotsIndex ?>"><?= $slotsIndex + 1 ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div class="sl-display">
                <p class="sl-result" role="status" data-sl-result>Pick a bet and pull the lever.</p>
                <p class="sl-win" data-sl-win hidden></p>
                <p class="sl-message" role="alert" data-sl-message></p>
                <p class="sl-hint" data-sl-broke hidden>You're out of chips. <a href="/gambling/">Collect your daily chips</a></p>
            </div>

            <!-- The bet and the Spin button: the stepper over the quick bets on the left, the big button on the right -->
            <div class="sl-deck" role="group" aria-label="Your bet">
                <div class="sl-bet">
                    <button type="button" class="casino-btn sl-bet-step" data-sl-less aria-label="Bet less">&minus;</button>
                    <p class="sl-bet-line">
                        <span class="sl-bet-label">Bet</span>
                        <strong class="sl-bet-amount" data-sl-bet><?= HBHUB_SLOTS_MIN_BET ?></strong>
                        <span class="sl-bet-per-line"><span data-sl-line-bet><?= HBHUB_SLOTS_MIN_BET / $slotsLineCount ?></span> a line</span>
                    </p>
                    <button type="button" class="casino-btn sl-bet-step" data-sl-more aria-label="Bet more">+</button>
                </div>
                <div class="sl-chip-row" role="group" aria-label="Quick bets">
                    <?php foreach ($slotsBets as $slotsIndex => $slotsBet): ?>
                        <button type="button" class="sl-chip sl-chip-<?= $slotsBet ?>" data-sl-chip="<?= $slotsBet ?>"
                                aria-pressed="<?= $slotsIndex === 0 ? 'true' : 'false' ?>" aria-label="Bet <?= $slotsBet ?>"><?= $slotsBet >= 1000 ? ($slotsBet / 1000) . 'K' : $slotsBet ?></button>
                    <?php endforeach; ?>
                    <!-- Only with fewer than 10 chips (slots.js) -->
                    <button type="button" class="sl-chip sl-chip-all-in" data-sl-all-in="<?= HBHUB_CASINO_ALL_IN_BELOW ?>"
                            aria-pressed="false" aria-label="All in: bet all your chips on one spin" hidden>All in</button>
                </div>
                <button type="button" class="casino-btn casino-btn-primary sl-spin" data-sl-spin title="Spin (Space)" aria-keyshortcuts="Space">Spin</button>
            </div>

            <!-- Over the machine on a big win: "Big win!", "Mega win!" or "Jackpot!" and the win counting up (slots.js) -->
            <div class="sl-banner" data-sl-banner aria-hidden="true" hidden>
                <span class="sl-banner-rays"></span>
                <span class="sl-banner-title" data-sl-banner-title></span>
                <span class="sl-banner-amount" data-sl-banner-amount></span>
            </div>
        </section>

        <section class="sl-side casino-card" aria-labelledby="sl-history-title">
            <h2 class="sl-label" id="sl-history-title">Your last spins</h2>
            <ol class="sl-history" data-sl-history>
                <?php foreach ($slotsHistory as $slotsSpin): ?>
                    <li class="sl-history-item<?= $slotsSpin['payout'] > 0 ? ' is-win' : '' ?>" title="<?= htmlspecialchars(slotsHistoryLabel($slotsSpin)) ?>">
                        <span class="sl-history-symbols" aria-hidden="true"><?= implode('', array_map(static fn (string $symbol): string => HBHUB_SLOTS_SYMBOLS[$symbol][1], $slotsSpin['symbols'])) ?></span>
                        <span class="casino-sr-only"><?= htmlspecialchars(slotsHistoryLabel($slotsSpin)) ?></span>
                        <span class="sl-history-net <?= $slotsSpin['net'] > 0 ? 'casino-net-up' : ($slotsSpin['net'] < 0 ? 'casino-net-down' : '') ?>" aria-hidden="true"><?= $slotsSpin['net'] > 0 ? '+' : ($slotsSpin['net'] < 0 ? '−' : '') ?><?= number_format(abs($slotsSpin['net'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="sl-hint" data-sl-history-empty<?= $slotsHistory !== [] ? ' hidden' : '' ?>>No spins yet.</p>
        </section>

        <details class="sl-rules casino-card">
            <summary>Rules and pay table</summary>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/rules.php'; ?>
        </details>

        <!-- The win effects over the whole page (slots.js): a glow round the edges, a flash, and the coins, confetti,
             sparkles, falling 7s and fireworks -->
        <div class="sl-aura" aria-hidden="true"></div>
        <div class="sl-flash" data-sl-flash aria-hidden="true"></div>
        <canvas class="sl-fx" data-sl-fx aria-hidden="true"></canvas>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/slots.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/slots.js') ?>" defer></script>
</body>
</html>
