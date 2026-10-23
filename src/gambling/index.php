<?php
// Casino home (the Gambling section), laid out like a casino floor: a felt header with the user's chips, their place on
// the casino leaderboard and the daily chips; the 30-day calendar of daily chips as a row of chips; the three games
// with what's going on at them right now (open tables and players for Blackjack and Baccarat, the user's last spins for
// Roulette) and the user's own result in each; and the top of the casino leaderboard.
// casino.js (loaded by navPanel.php along with the chip count in the top bar) keeps the chips, the daily chips, the
// calendar and the countdown up to date. The rest is as it was when the page was made.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/leaderboard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const CASINO_BIG_DAYS = [7, 14, 21]; // the end of each week on the calendar, gold like day 30
const CASINO_TOP_PLAYERS = 5; // how many of the casino leaderboard are on the page
const CASINO_LAST_SPINS = 6; // the user's last Roulette numbers on its card

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$casinoWallet = hbHubCasinoStatus($db, $userId);
$casinoDaily = $casinoWallet['daily'];
$navCasinoChips = $casinoWallet['chips']; // the chip count in the top bar (navPanel.php)

// The casino leaderboard by chips won or lost, like gambling/leaderboard.php shows it first, and the user's place on it
$casinoBoard = hbHubCasinoLeaderboardSort(hbHubCasinoLeaderboardRows($db), 'net', true);
$casinoPlace = null;
foreach ($casinoBoard as $casinoIndex => $casinoRow) {
    if ($casinoRow['userId'] === $userId) {
        $casinoPlace = $casinoIndex + 1;
        break;
    }
}
$casinoMe = $casinoPlace !== null ? $casinoBoard[$casinoPlace - 1] : null;

/**
 * How many public tables have someone at them, and how many people sit at any table (solo ones too), counting only
 * players seen within the grace period, as the rest have left (they just haven't been tidied away yet).
 * $game is the start of the tables' names: "Blackjack" for HBHub-BlackjackRooms and HBHub-BlackjackSeats.
 *
 * @return array{tables: int, players: int}
 */
function casinoTableCounts(PDO $db, string $game, int $publicType, int $graceSeconds): array
{
    $statement = $db->prepare('SELECT COUNT(DISTINCT CASE WHEN r.roomType = ? THEN r.roomId END) AS tables, COUNT(*) AS players
        FROM `HBHub-' . $game . 'Rooms` r
        JOIN `HBHub-' . $game . 'Seats` s ON s.roomId = r.roomId
        WHERE s.seatLastSeenTimestamp > UTC_TIMESTAMP() - INTERVAL ' . $graceSeconds . ' SECOND');
    $statement->execute([$publicType]);
    $counts = $statement->fetch();
    return ['tables' => (int) $counts['tables'], 'players' => (int) $counts['players']];
}

// The table games: what's on at them now, and the table the user sits at (one per game at most)
$casinoBlackjackSeat = hbHubBlackjackUserSeat($db, $userId);
$casinoBaccaratSeat = hbHubBaccaratUserSeat($db, $userId);
$casinoTables = [
    'blackjack' => [
        ...casinoTableCounts($db, 'Blackjack', HBHUB_BLACKJACK_ROOM_PUBLIC, HBHUB_BLACKJACK_GRACE_SECONDS),
        'seat' => $casinoBlackjackSeat !== null ? hbHubBlackjackRoom($db, $casinoBlackjackSeat['roomId']) : null,
    ],
    'baccarat' => [
        ...casinoTableCounts($db, 'Baccarat', HBHUB_BACCARAT_ROOM_PUBLIC, HBHUB_BACCARAT_GRACE_SECONDS),
        'seat' => $casinoBaccaratSeat !== null ? hbHubBaccaratRoom($db, $casinoBaccaratSeat['roomId']) : null,
    ],
];
$casinoSpins = hbHubRouletteHistory($db, $userId, CASINO_LAST_SPINS);

/**
 * Seconds as "H:MM:SS", the same as casino.js shows the countdown.
 */
function casinoCountdown(int $seconds): string
{
    return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
}

/**
 * A calendar day's chips, short enough to go under its chip on a phone: 2500 is "2.5k", 15000 "15k".
 */
function casinoShortChips(int $chips): string
{
    return rtrim(rtrim(number_format($chips / 1000, 2), '0'), '.') . 'k';
}

/**
 * The streak over the daily chips, the same as casino.js writes it.
 */
function casinoStreakText(int $collected): string
{
    return match ($collected) {
        0 => 'No streak yet',
        1 => '1 day in a row',
        default => $collected . ' days in a row',
    };
}

/**
 * Chips won or lost, with a "+" or a "−" and coloured by casino.css: casino-net-up, casino-net-down or neither for 0.
 *
 * @return array{text: string, class: string}
 */
function casinoNet(int $net): array
{
    return [
        'text' => ($net > 0 ? '+' : ($net < 0 ? '−' : '')) . number_format(abs($net)),
        'class' => $net > 0 ? 'casino-net-up' : ($net < 0 ? 'casino-net-down' : ''),
    ];
}

/**
 * What's going on at a table game, for its card: "2 open tables · 5 playing", or what to do when nothing is.
 */
function casinoTablesText(array $tables): string
{
    if ($tables['players'] === 0) {
        return 'Quiet right now. Open a table!';
    }
    $open = match ($tables['tables']) {
        0 => 'No open tables',
        1 => '1 open table',
        default => $tables['tables'] . ' open tables',
    };
    return $open . ' · ' . $tables['players'] . ' playing';
}

/**
 * The user's result in a game for its card, from their casino leaderboard row (null when they're not on it).
 */
function casinoYouHtml(?array $me, string $game): string
{
    if ($me === null || $me[$game] === null) {
        return '<span class="casino-home-game-you is-new">Not played yet</span>';
    }
    $net = casinoNet($me[$game]);
    return '<span class="casino-home-game-you">You: <strong class="' . $net['class'] . '">' . $net['text'] . '</strong></span>';
}

/**
 * A player on the top players' list. The profile picture is loaded by pictureQueue.js, as every one is a PHP request.
 */
function casinoTopPlayerHtml(array $row, int $place, int $userId): string
{
    $avatarSrc = hbHubAvatarUrl($row['userId'], $row['avatarId']);
    $avatar = $avatarSrc !== null
        ? '<img data-queued-picture="' . htmlspecialchars($avatarSrc) . '" alt=""><noscript><img src="' . htmlspecialchars($avatarSrc) . '" alt="" loading="lazy"></noscript>'
        : htmlspecialchars(mb_strtoupper(mb_substr($row['player'], 0, 1)));
    $net = casinoNet($row['net']);

    return '<li class="casino-home-player' . ($row['userId'] === $userId ? ' is-you' : '') . '">'
        . '<span class="casino-home-place"' . ($place <= 3 ? ' data-podium="' . $place . '"' : '') . '>' . $place . '</span>'
        . '<a class="casino-player" href="' . htmlspecialchars(hbHubProfileUrl($row['userId'])) . '">'
        . '<span class="casino-avatar" aria-hidden="true">' . $avatar . '</span>'
        . '<span class="casino-player-name">' . htmlspecialchars($row['player']) . '</span></a>'
        . '<span class="casino-home-player-net ' . $net['class'] . '">' . $net['text'] . '</span>'
        . '</li>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title>Casino - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Casino page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-home">
        <header class="casino-home-hero">
            <!-- Kept faint on purpose: buying chips is there for whoever looks, not something to push -->
            <a class="casino-pay-link" href="/gambling/buy-chips" aria-label="Buy chips" title="Buy chips">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <path d="M2 10h20M6 15h4"/>
                </svg>
            </a>

            <div class="casino-home-wallet">
                <p class="casino-kicker">Chips, not cash</p>
                <h1>Casino</h1>
                <p class="casino-home-label">Your chips</p>
                <p class="casino-home-balance">
                    <img class="casino-home-balance-icon" src="/assets/images/icons/chip.svg" alt="">
                    <span data-casino-chips="<?= $casinoWallet['chips'] ?>"><?= number_format($casinoWallet['chips']) ?></span>
                </p>
                <dl class="casino-home-stats">
                    <?php if ($casinoMe === null): ?>
                        <div><dt>Place</dt><dd>Play a round to get one</dd></div>
                    <?php else:
                        $casinoMyNet = casinoNet($casinoMe['net']); ?>
                        <div><dt>Place</dt><dd>#<?= $casinoPlace ?> <span class="casino-home-of">of <?= count($casinoBoard) ?></span></dd></div>
                        <div><dt>Won / lost</dt><dd class="<?= $casinoMyNet['class'] ?>"><?= $casinoMyNet['text'] ?></dd></div>
                        <div><dt>Rounds</dt><dd><?= number_format($casinoMe['rounds']) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </div>

            <section class="casino-home-daily<?= $casinoDaily['ready'] ? ' is-ready' : '' ?>" aria-labelledby="casino-daily-title"
                     data-casino-daily data-seconds="<?= $casinoDaily['seconds'] ?>" data-amount="<?= $casinoDaily['amount'] ?>"
                     data-day="<?= $casinoDaily['day'] ?>" data-collected="<?= $casinoDaily['collected'] ?>">
                <div class="casino-home-daily-head">
                    <h2 id="casino-daily-title">Daily chips</h2>
                    <p class="casino-home-streak" data-casino-streak><?= casinoStreakText($casinoDaily['collected']) ?></p>
                </div>
                <p class="casino-home-daily-amount">
                    <span>+<span data-casino-daily-amount><?= number_format($casinoDaily['amount']) ?></span></span>
                </p>
                <p class="casino-home-daily-next">
                    <span data-casino-daily-when><?= $casinoDaily['ready'] ? 'Today' : 'Tomorrow' ?></span>:
                    day <span data-casino-daily-day><?= $casinoDaily['day'] ?></span> of <?= HBHUB_CASINO_DAILY_DAYS ?>
                </p>
                <button type="button" class="casino-btn casino-btn-primary" data-casino-claim<?= $casinoDaily['ready'] ? '' : ' disabled' ?>>Collect daily chips</button>
                <p class="casino-home-daily-timer" role="timer" data-casino-daily-timer>
                    <?= $casinoDaily['ready'] ? 'Your daily chips are ready.' : 'Next daily chips in ' . casinoCountdown($casinoDaily['seconds']) ?>
                </p>
                <p class="casino-home-daily-message" role="status" data-casino-daily-message></p>
            </section>
        </header>

        <section class="casino-card casino-home-calendar" aria-labelledby="casino-calendar-title">
            <div class="casino-home-section-head">
                <div>
                    <h2 id="casino-calendar-title">30-day calendar</h2>
                    <p class="casino-hint">
                        Collect every day to move along: the further you get, the more you get, with a bonus at the end of
                        every week. Miss a day and you start over at day 1.
                    </p>
                </div>
                <div class="casino-home-progress">
                    <progress max="<?= HBHUB_CASINO_DAILY_DAYS ?>" value="<?= $casinoDaily['collected'] ?>" aria-labelledby="casino-progress-text" data-casino-progress></progress>
                    <p id="casino-progress-text"><span data-casino-progress-text><?= $casinoDaily['collected'] ?></span> of <?= HBHUB_CASINO_DAILY_DAYS ?> days</p>
                </div>
            </div>
            <!-- A chip for each day. casino.js moves the ticks and the highlighted day along (data-casino-calendar, data-day). -->
            <ol class="casino-calendar" data-casino-calendar>
                <?php foreach (HBHUB_CASINO_DAILY_CALENDAR as $casinoIndex => $casinoChips):
                    $casinoDay = $casinoIndex + 1;
                    $casinoClasses = ['casino-calendar-day'];
                    if (in_array($casinoDay, CASINO_BIG_DAYS, true)) {
                        $casinoClasses[] = 'is-big';
                    }
                    if ($casinoDay === HBHUB_CASINO_DAILY_DAYS) {
                        $casinoClasses[] = 'is-last';
                    }
                    if ($casinoIndex > 0 && $casinoChips < HBHUB_CASINO_DAILY_CALENDAR[$casinoIndex - 1]) {
                        $casinoClasses[] = 'is-lean'; // less than the day before
                    }
                    if ($casinoDay <= $casinoDaily['collected']) {
                        $casinoClasses[] = 'is-collected';
                    } elseif ($casinoDay === $casinoDaily['day']) {
                        $casinoClasses[] = $casinoDaily['ready'] ? 'is-next is-ready' : 'is-next';
                    }
                    ?>
                    <li class="<?= implode(' ', $casinoClasses) ?>" data-day="<?= $casinoDay ?>"
                        title="Day <?= $casinoDay ?>: <?= number_format($casinoChips) ?> chips">
                        <span class="casino-calendar-chip">
                            <span class="casino-calendar-number"><span class="casino-sr-only">Day </span><?= $casinoDay ?></span>
                            <svg class="casino-calendar-tick" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 12.5l4.5 4.5L19 7.5"/>
                            </svg>
                        </span>
                        <span class="casino-calendar-short" aria-hidden="true"><?= casinoShortChips($casinoChips) ?></span>
                        <span class="casino-sr-only">: <?= number_format($casinoChips) ?> chips</span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="casino-home-tables" aria-labelledby="casino-games-title">
            <div class="casino-home-section-head">
                <h2 id="casino-games-title">Tables</h2>
                <p class="casino-hint">Every game uses the same chips.</p>
            </div>
            <ul class="casino-home-games">
                <li>
                    <a class="casino-home-game casino-home-game-blackjack" href="/gambling/blackjack/">
                        <span class="casino-home-game-felt">
                            <?php if ($casinoTables['blackjack']['seat'] !== null): ?>
                                <span class="casino-home-game-seated">You're at <?= htmlspecialchars($casinoTables['blackjack']['seat']['roomName']) ?></span>
                            <?php endif; ?>
                            <svg class="casino-home-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect x="8" y="14" width="26" height="38" rx="4" transform="rotate(-12 21 33)"/>
                                <rect x="28" y="10" width="26" height="38" rx="4" transform="rotate(10 41 29)"/>
                                <path class="casino-home-game-mark" d="M41 19c-3.5 4.5-7.5 6.5-7.5 10.5a3.8 3.8 0 0 0 6.5 2.5l-1 5h4l-1-5a3.8 3.8 0 0 0 6.5-2.5c0-4-4-6-7.5-10.5z" transform="rotate(10 41 29)"/>
                            </svg>
                        </span>
                        <span class="casino-home-game-body">
                            <span class="casino-home-game-head">
                                <span class="casino-home-game-name">Blackjack</span>
                                <span class="casino-home-game-tag">1 to <?= HBHUB_BLACKJACK_SEATS ?> players</span>
                            </span>
                            <span class="casino-home-game-text">Get closer to 21 than the dealer without going over. Blackjack pays 3:2.</span>
                            <span class="casino-home-game-foot">
                                <span class="casino-home-game-live<?= $casinoTables['blackjack']['players'] > 0 ? ' is-live' : '' ?>"><?= casinoTablesText($casinoTables['blackjack']) ?></span>
                                <?= casinoYouHtml($casinoMe, 'blackjack') ?>
                            </span>
                        </span>
                    </a>
                </li>
                <li>
                    <a class="casino-home-game casino-home-game-baccarat" href="/gambling/baccarat/">
                        <span class="casino-home-game-felt">
                            <?php if ($casinoTables['baccarat']['seat'] !== null): ?>
                                <span class="casino-home-game-seated">You're at <?= htmlspecialchars($casinoTables['baccarat']['seat']['roomName']) ?></span>
                            <?php endif; ?>
                            <svg class="casino-home-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect x="6" y="14" width="26" height="38" rx="4" transform="rotate(-8 19 33)"/>
                                <rect x="32" y="14" width="26" height="38" rx="4" transform="rotate(8 45 33)"/>
                                <path class="casino-home-game-mark" d="M19 25l6 8-6 8-6-8z" transform="rotate(-8 19 33)"/>
                                <path class="casino-home-game-mark" d="M45 41s-8-5-8-10a4 4 0 0 1 8-1.5 4 4 0 0 1 8 1.5c0 5-8 10-8 10z" transform="rotate(8 45 33)"/>
                            </svg>
                        </span>
                        <span class="casino-home-game-body">
                            <span class="casino-home-game-head">
                                <span class="casino-home-game-name">Baccarat</span>
                                <span class="casino-home-game-tag">1 to <?= HBHUB_BACCARAT_SEATS ?> players</span>
                            </span>
                            <span class="casino-home-game-text">Bet on the Player or the Banker, whichever hand ends closest to 9.</span>
                            <span class="casino-home-game-foot">
                                <span class="casino-home-game-live<?= $casinoTables['baccarat']['players'] > 0 ? ' is-live' : '' ?>"><?= casinoTablesText($casinoTables['baccarat']) ?></span>
                                <?= casinoYouHtml($casinoMe, 'baccarat') ?>
                            </span>
                        </span>
                    </a>
                </li>
                <li>
                    <a class="casino-home-game casino-home-game-roulette" href="/gambling/roulette/">
                        <span class="casino-home-game-felt">
                            <svg class="casino-home-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <circle cx="32" cy="32" r="26"/>
                                <circle class="casino-home-game-line" cx="32" cy="32" r="17"/>
                                <path class="casino-home-game-line" d="M32 6v52M6 32h52M13.6 13.6l36.8 36.8M50.4 13.6L13.6 50.4"/>
                                <circle class="casino-home-game-mark" cx="32" cy="32" r="6"/>
                                <circle class="casino-home-game-mark" cx="47" cy="19" r="3.5"/>
                            </svg>
                        </span>
                        <span class="casino-home-game-body">
                            <span class="casino-home-game-head">
                                <span class="casino-home-game-name">Roulette</span>
                                <span class="casino-home-game-tag">Solo</span>
                            </span>
                            <span class="casino-home-game-text">Bet on a number, a color or anything in between on a single-zero wheel.</span>
                            <span class="casino-home-game-foot">
                                <?php if ($casinoSpins === []): ?>
                                    <span class="casino-home-game-live">Spin on your own, any time</span>
                                <?php else: ?>
                                    <span class="casino-home-spins">
                                        <span class="casino-sr-only">Your last spins: </span>
                                        <?php foreach ($casinoSpins as $casinoSpin): ?>
                                            <span class="casino-home-spin is-<?= $casinoSpin['color'] ?>"><?= $casinoSpin['number'] ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <?= casinoYouHtml($casinoMe, 'roulette') ?>
                            </span>
                        </span>
                    </a>
                </li>
            </ul>
        </section>

        <div class="casino-home-bottom">
            <section class="casino-card casino-home-top" aria-labelledby="casino-top-title">
                <div class="casino-home-section-head">
                    <h2 id="casino-top-title">Top players</h2>
                    <a class="casino-btn" href="/gambling/leaderboard">Full leaderboard</a>
                </div>
                <?php if ($casinoBoard === []): ?>
                    <p class="casino-hint">Nobody has played in the casino yet. Be the first!</p>
                <?php else: ?>
                    <p class="casino-hint">By chips won or lost in every game together.</p>
                    <ol class="casino-home-players">
                        <?php foreach (array_slice($casinoBoard, 0, CASINO_TOP_PLAYERS) as $casinoIndex => $casinoRow): ?>
                            <?= casinoTopPlayerHtml($casinoRow, $casinoIndex + 1, $userId) ?>
                        <?php endforeach; ?>
                        <?php if ($casinoPlace !== null && $casinoPlace > CASINO_TOP_PLAYERS): ?>
                            <li class="casino-home-players-gap" aria-hidden="true">···</li>
                            <?= casinoTopPlayerHtml($casinoMe, $casinoPlace, $userId) ?>
                        <?php endif; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="casino-card casino-home-info" aria-labelledby="casino-info-title">
                <h2 id="casino-info-title">Good to know</h2>
                <ul class="casino-home-facts">
                    <li>A new casino day starts at <?= HBHUB_CASINO_DAY_START ?> AM, Danish time.</li>
                    <li>Chips are only for fun here: they can't be cashed out, and nothing you win is real money.</li>
                    <li>Each game has its own leaderboard with wins, losses and win %.</li>
                </ul>
                <nav class="casino-home-links" aria-label="More about the casino">
                    <a href="/gambling/blackjack/leaderboard">Blackjack leaderboard</a>
                    <a href="/gambling/baccarat/leaderboard">Baccarat leaderboard</a>
                    <a href="/gambling/roulette/leaderboard">Roulette leaderboard</a>
                    <a href="/docs/casino/">How the casino works</a>
                </nav>
            </section>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
