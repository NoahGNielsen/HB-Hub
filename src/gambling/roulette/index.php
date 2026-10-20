<?php
// Roulette: the wheel, the user's last spins and the table to bet on. roulette.js places the bets and spins them
// through /gambling/roulette/spin, where the ball is decided (assets/php/casino/roulette/roulette.php).
// The table is drawn here, every bet a button with the bet's key (data-rl-bet) and the numbers it covers. Besides each
// number's own button, a number has small buttons on its edges and corners for the bets it shares with the numbers
// around it (a split to the next row, a corner and so on). Each one sits on the line it's named after in roulette.css,
// which lays the table out across on wide screens and down on narrow ones. Where each cell goes is in classes there
// too (rl-row-*, rl-place-*, rl-at-*), as the site's CSP doesn't allow style attributes.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
$rouletteHistory = hbHubRouletteHistory($db, $userId);
$rouletteChips = [10, 50, 100, 500, 1000]; // the chips to bet with

const ROULETTE_OUTSIDE_NAMES = [
    'red' => 'Red', 'black' => 'Black', 'odd' => 'Odd', 'even' => 'Even', 'low' => '1-18', 'high' => '19-36',
    'dozen1' => '1st 12', 'dozen2' => '2nd 12', 'dozen3' => '3rd 12',
    'column1' => '2 to 1', 'column2' => '2 to 1', 'column3' => '2 to 1',
];

/**
 * What a screen reader says for a bet's button, like "Split 17 and 20, pays 17 to 1".
 */
function rouletteSpotLabel(string $bet): string
{
    $numbers = hbHubRouletteBets()[$bet];
    $list = static fn (array $numbers): string => implode(', ', array_slice($numbers, 0, -1)) . ' and ' . end($numbers);
    $name = match (true) {
        str_starts_with($bet, 'column') => 'Column ' . implode(', ', array_slice($numbers, 0, 3)) . ' and on',
        isset(ROULETTE_OUTSIDE_NAMES[$bet]) => ROULETTE_OUTSIDE_NAMES[$bet],
        count($numbers) === 1 => (string) $numbers[0],
        count($numbers) === 2 => 'Split ' . $list($numbers),
        count($numbers) === 3 => ($numbers[0] === 0 ? 'Trio ' : 'Street ') . $list($numbers),
        count($numbers) === 4 => ($numbers[0] === 0 ? 'First four ' : 'Corner ') . $list($numbers),
        default => 'Line ' . $numbers[0] . ' to ' . end($numbers),
    };
    return $name . ', pays ' . (hbHubRouletteReturn($bet) - 1) . ' to 1';
}

/**
 * A bet's button. $place says where on its cell it sits (roulette.css): fill (the whole cell), or a line or corner.
 */
function rouletteSpot(string $bet, string $place, string $text = ''): string
{
    return '<button type="button" class="rl-spot rl-spot-' . $place . '" data-rl-bet="' . $bet . '"'
        . ' data-rl-numbers="' . implode(' ', hbHubRouletteBets()[$bet]) . '"'
        . ' aria-label="' . htmlspecialchars(rouletteSpotLabel($bet)) . '"'
        . ($place === 'fill' ? '' : ' tabindex="-1"') // only whole cells by keyboard, or it would be ~150 stops
        . '>' . $text . '</button>';
}

/**
 * The wheel's pockets as SVG paths and numbers, 0 at the top and clockwise from there, in a -160..160 view box.
 */
function rouletteWheelPockets(): string
{
    $step = 360 / count(HBHUB_ROULETTE_WHEEL);
    $point = static function (float $radius, float $degrees): string {
        $radians = deg2rad($degrees);
        return sprintf('%.2f %.2f', $radius * sin($radians), -$radius * cos($radians));
    };

    $svg = '';
    foreach (HBHUB_ROULETTE_WHEEL as $index => $number) {
        $from = ($index - 0.5) * $step;
        $to = ($index + 0.5) * $step;
        $svg .= sprintf('<path class="rl-pocket rl-pocket-%s" d="M%s A150 150 0 0 1 %s L%s A98 98 0 0 0 %s Z"/>',
            hbHubRouletteColor($number), $point(150, $from), $point(150, $to), $point(98, $to), $point(98, $from));
        $svg .= sprintf('<text class="rl-pocket-number" x="0" y="-136" transform="rotate(%.2f)">%d</text>', $index * $step, $number);
    }
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/roulette.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/roulette.css') ?>">
    <title>Roulette - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Roulette in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="rl" data-roulette data-chips="<?= $navCasinoChips ?>" data-bet-step="<?= HBHUB_ROULETTE_BET_STEP ?>">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <div class="rl-header">
            <div>
                <h1 class="rl-title">Roulette</h1>
                <p class="rl-meta">Single zero &middot; A number pays 35:1 &middot; Bets in steps of <?= HBHUB_ROULETTE_BET_STEP ?></p>
            </div>
            <a class="casino-btn" href="/gambling/roulette/leaderboard">Leaderboard</a>
        </div>

        <section class="rl-top" aria-label="The wheel">
            <div class="rl-wheel">
                <svg viewBox="-160 -160 320 320" role="img" aria-label="Roulette wheel" data-rl-wheel
                     data-order="<?= implode(' ', HBHUB_ROULETTE_WHEEL) ?>">
                    <circle class="rl-wheel-rim" r="159"/>
                    <g class="rl-wheel-rotor" data-rl-rotor>
                        <?= rouletteWheelPockets() ?>
                        <circle class="rl-wheel-cone" r="98"/>
                        <circle class="rl-wheel-hub" r="26"/>
                    </g>
                    <g class="rl-wheel-ball" data-rl-ball>
                        <circle r="150" fill="none"/><!-- so the group's box is centred on the wheel, which it turns around -->
                        <circle class="rl-ball" cx="0" cy="-110" r="7"/>
                    </g>
                    <path class="rl-wheel-pointer" d="M-8 -160 L8 -160 L0 -146 Z"/>
                </svg>
            </div>

            <div class="rl-info">
                <p class="rl-result" role="status" data-rl-result>Place your bets, then spin.</p>
                <p class="rl-detail" data-rl-detail></p>
                <h2 class="rl-label">Your last spins</h2>
                <ol class="rl-history" data-rl-history>
                    <?php foreach ($rouletteHistory as $spin): ?>
                        <li class="rl-history-item rl-<?= $spin['color'] ?>" title="<?= $spin['net'] >= 0 ? '+' : '−' ?><?= number_format(abs($spin['net'])) ?> chips"><?= $spin['number'] ?></li>
                    <?php endforeach; ?>
                </ol>
                <p class="rl-hint" data-rl-history-empty<?= $rouletteHistory !== [] ? ' hidden' : '' ?>>No spins yet.</p>
            </div>
        </section>

        <section class="rl-board-wrap" aria-label="The table">
            <div class="rl-board" data-rl-board>
                <div class="rl-cell rl-green rl-at-zero" data-rl-number="0">
                    <?= rouletteSpot('0', 'fill', '<span class="rl-number">0</span>') ?>
                </div>
                <?php for ($n = 1; $n <= 36; $n++):
                    $row = intdiv($n - 1, 3) + 1; // 1-12, the row of 3 it's in
                    $place = ($n - 1) % 3 + 1; // 1-3, where in its row
                ?>
                    <div class="rl-cell rl-<?= hbHubRouletteColor($n) ?> rl-row-<?= $row ?> rl-place-<?= $place ?>" data-rl-number="<?= $n ?>">
                        <?= rouletteSpot((string) $n, 'fill', '<span class="rl-number">' . $n . '</span>') ?>
                        <?php if ($n <= 3): ?>
                            <?= rouletteSpot('0-' . $n, 'zero') ?>
                        <?php endif; ?>
                        <?php if ($n <= 2): ?>
                            <?= rouletteSpot('0-' . $n . '-' . ($n + 1), 'trio') ?>
                        <?php endif; ?>
                        <?php if ($n === 1): ?>
                            <?= rouletteSpot('0-1-2-3', 'four') ?>
                        <?php endif; ?>
                        <?php if ($n <= 33): ?>
                            <?= rouletteSpot($n . '-' . ($n + 3), 'next') ?>
                        <?php endif; ?>
                        <?php if ($place !== 3): ?>
                            <?= rouletteSpot($n . '-' . ($n + 1), 'side') ?>
                            <?php if ($n <= 32): ?>
                                <?= rouletteSpot(implode('-', [$n, $n + 1, $n + 3, $n + 4]), 'corner') ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($place === 1): ?>
                            <?= rouletteSpot(implode('-', [$n, $n + 1, $n + 2]), 'street') ?>
                            <?php if ($n <= 31): ?>
                                <?= rouletteSpot(implode('-', range($n, $n + 5)), 'line') ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>

                <?php for ($column = 1; $column <= 3; $column++): ?>
                    <div class="rl-cell rl-outside rl-at-column<?= $column ?>">
                        <?= rouletteSpot('column' . $column, 'fill', ROULETTE_OUTSIDE_NAMES['column' . $column]) ?>
                    </div>
                <?php endfor; ?>
                <?php for ($dozen = 1; $dozen <= 3; $dozen++): ?>
                    <div class="rl-cell rl-outside rl-outside-long rl-at-dozen<?= $dozen ?>">
                        <?= rouletteSpot('dozen' . $dozen, 'fill', ROULETTE_OUTSIDE_NAMES['dozen' . $dozen]) ?>
                    </div>
                <?php endfor; ?>
                <?php foreach (['low', 'even', 'red', 'black', 'odd', 'high'] as $bet):
                    $text = in_array($bet, ['red', 'black'], true)
                        ? '<span class="rl-diamond rl-diamond-' . $bet . '" aria-hidden="true"></span>'
                        : ROULETTE_OUTSIDE_NAMES[$bet];
                ?>
                    <div class="rl-cell rl-outside rl-outside-long rl-at-<?= $bet ?>">
                        <?= rouletteSpot($bet, 'fill', $text) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rl-controls" aria-label="Your bets">
            <p class="rl-message" role="alert" data-rl-message></p>
            <div class="rl-chip-row" role="group" aria-label="The chip to bet with">
                <?php foreach ($rouletteChips as $index => $chip): ?>
                    <button type="button" class="rl-chip rl-chip-<?= $chip ?>" data-rl-chip="<?= $chip ?>"
                            aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>" aria-label="Bet <?= $chip ?> at a time"><?= $chip >= 1000 ? ($chip / 1000) . 'K' : $chip ?></button>
                <?php endforeach; ?>
            </div>
            <p class="rl-bet-line">Total bet: <strong class="rl-bet-amount" data-rl-total>0</strong></p>
            <div class="rl-actions">
                <button type="button" class="casino-btn" data-rl-undo disabled>Undo</button>
                <button type="button" class="casino-btn" data-rl-clear disabled>Clear</button>
                <button type="button" class="casino-btn" data-rl-double disabled>&times;2</button>
                <button type="button" class="casino-btn casino-btn-primary rl-spin" data-rl-spin title="Spin (Space)" aria-keyshortcuts="Space" disabled>Spin</button>
            </div>
            <p class="rl-hint" data-rl-broke hidden>
                You're out of chips. <a href="/gambling/">Collect your daily chips</a>
            </p>
        </section>

        <details class="rl-rules casino-card">
            <summary>Rules</summary>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/rules.php'; ?>
        </details>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/roulette.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/roulette.js') ?>" defer></script>
</body>
</html>
