<?php
// Slots: a three-reel slot machine with five paylines, played against the house with the casino's chips. These are the
// rules; gambling/slots/spin.php plays them (hbHubSlotsSpin()), and every spin is logged in HBHub-SlotSpins, which the
// leaderboards and the user's last spins come from.
//
// Each reel is a strip of HBHUB_SLOTS_REEL_LENGTH symbols that goes round, and a spin picks where each reel stops
// (random_int(), every stop as likely as the next). The machine shows three rows: the symbol a reel stopped on in the
// middle, the one before it on its strip above and the one after it below. The five paylines (HBHUB_SLOTS_LINES) are
// the three rows and the two diagonals, and the bet is split evenly across them, so a line's prize is
// (what it pays, HBHUB_SLOTS_PAYS) x the bet / 5. The bet goes in steps of 10, so that always comes out even; only an
// all in (the last few chips) can have a bit cut off, and then it's rounded down once, for the whole spin.
//
// A line pays its best match only, read from the left reel:
// - three of a kind: what HBHUB_SLOTS_PAYS says for the symbol
// - three fruit that aren't all the same (cherries, lemons and grapes, mixed): HBHUB_SLOTS_PAY_MIXED_FRUIT
// - a cherry on the first two reels: HBHUB_SLOTS_PAY_TWO_CHERRIES, and on only the first one: HBHUB_SLOTS_PAY_CHERRY
// The strips and the pays are tuned together: hbHubSlotsReturn() works out exactly what the machine pays back (about
// 96%), so check it after changing either.
require_once __DIR__ . '/../wallet.php';

const HBHUB_SLOTS_GAME = 'slots'; // transactionGame in HBHub-CasinoTransactions
const HBHUB_SLOTS_BET_STEP = 10; // every bet in steps of 10, like at the other tables, so it splits evenly across the 5 lines
const HBHUB_SLOTS_MIN_BET = 10;
const HBHUB_SLOTS_MAX_BET = 1000000000; // only so the sums can't overflow, the chips are what really limits it
const HBHUB_SLOTS_HISTORY = 10; // the user's last spins on the machine's page
const HBHUB_SLOTS_PLAYING_SECONDS = 120; // who spun this recently counts as playing now (the casino home)
const HBHUB_SLOTS_REQUEST_PATTERN = '~^[A-Za-z0-9]{8,32}$~'; // spinRequestId in HBHub-SlotSpins, made by slots.js

// The symbols, best first: key => [name, what's shown]. The keys are what the strips and the pays use.
const HBHUB_SLOTS_SYMBOLS = [
    'seven' => ['Seven', '7'],
    'diamond' => ['Diamond', '💎'],
    'star' => ['Star', '⭐'],
    'bell' => ['Bell', '🔔'],
    'grape' => ['Grapes', '🍇'],
    'lemon' => ['Lemon', '🍋'],
    'cherry' => ['Cherry', '🍒'],
];
const HBHUB_SLOTS_FRUIT = ['cherry', 'lemon', 'grape'];

// Three of a kind, as how many times the line's share of the bet it pays (the share included)
const HBHUB_SLOTS_PAYS = [
    'seven' => 300,
    'diamond' => 80,
    'star' => 40,
    'bell' => 25,
    'grape' => 12,
    'lemon' => 10,
    'cherry' => 6,
];
const HBHUB_SLOTS_PAY_MIXED_FRUIT = 2;
const HBHUB_SLOTS_PAY_TWO_CHERRIES = 2;
const HBHUB_SLOTS_PAY_CHERRY = 1; // just the line's share back

// The reels' strips, from the top. All three have the same symbols (1 seven, 2 diamonds, 3 stars, 3 bells, 4 grapes,
// 4 lemons and 4 cherries), each in its own order, so the same symbol is never next to itself.
const HBHUB_SLOTS_REEL_LENGTH = 21;
const HBHUB_SLOTS_REELS = [
    ['seven', 'cherry', 'lemon', 'bell', 'grape', 'star', 'cherry', 'diamond', 'lemon', 'grape', 'bell', 'cherry', 'star',
        'lemon', 'grape', 'diamond', 'cherry', 'bell', 'lemon', 'star', 'grape'],
    ['cherry', 'star', 'grape', 'lemon', 'diamond', 'bell', 'cherry', 'grape', 'seven', 'lemon', 'star', 'cherry', 'bell',
        'grape', 'lemon', 'diamond', 'cherry', 'star', 'lemon', 'bell', 'grape'],
    ['lemon', 'bell', 'cherry', 'grape', 'star', 'lemon', 'diamond', 'cherry', 'bell', 'grape', 'lemon', 'seven', 'star',
        'cherry', 'grape', 'bell', 'lemon', 'diamond', 'cherry', 'star', 'grape'],
];

// The paylines, as the row (0 top, 1 middle, 2 bottom) on each reel, in the order the machine numbers them
const HBHUB_SLOTS_LINES = [
    [1, 1, 1], // the middle row
    [0, 0, 0], // the top row
    [2, 2, 2], // the bottom row
    [0, 1, 2], // top left to bottom right
    [2, 1, 0], // bottom left to top right
];

/**
 * The symbol at $position on a reel's strip, going round at the ends.
 */
function hbHubSlotsSymbolAt(int $reel, int $position): string
{
    $length = count(HBHUB_SLOTS_REELS[$reel]);
    return HBHUB_SLOTS_REELS[$reel][(($position % $length) + $length) % $length];
}

/**
 * What the machine shows when the reels stop at $stops: three rows of three symbols, the top row first.
 *
 * @param list<int> $stops where each reel stopped, 0 to HBHUB_SLOTS_REEL_LENGTH - 1
 * @return list<list<string>>
 */
function hbHubSlotsWindow(array $stops): array
{
    $rows = [];
    for ($row = 0; $row < 3; $row++) {
        $rows[] = array_map(static fn (int $reel): string => hbHubSlotsSymbolAt($reel, $stops[$reel] + $row - 1), [0, 1, 2]);
    }
    return $rows;
}

/**
 * What three symbols on a line pay, as how many times the line's share of the bet: its best match, or 0.
 *
 * @param list<string> $symbols from the left reel
 */
function hbHubSlotsLinePays(array $symbols): int
{
    [$a, $b, $c] = $symbols;
    $pays = 0;
    if ($a === $b && $b === $c) {
        $pays = HBHUB_SLOTS_PAYS[$a];
    } elseif (in_array($a, HBHUB_SLOTS_FRUIT, true) && in_array($b, HBHUB_SLOTS_FRUIT, true) && in_array($c, HBHUB_SLOTS_FRUIT, true)) {
        $pays = HBHUB_SLOTS_PAY_MIXED_FRUIT;
    }
    if ($a === 'cherry') {
        $pays = max($pays, $b === 'cherry' ? HBHUB_SLOTS_PAY_TWO_CHERRIES : HBHUB_SLOTS_PAY_CHERRY);
    }
    return $pays;
}

/**
 * Works out a spin of $bet when the reels stop at $stops (picks them when it's null), without touching any chips.
 *
 * @param ?list<int> $stops
 * @return array{stops: list<int>, window: list<list<string>>, bet: int, payout: int, net: int,
 *               lines: list<array{line: int, pays: int, payout: int}>}
 *         lines is every winning line (its index in HBHUB_SLOTS_LINES) with what it pays: how many times its share
 *         of the bet, and in chips (the share included)
 */
function hbHubSlotsResolve(int $bet, ?array $stops = null): array
{
    $stops ??= array_map(static fn (): int => random_int(0, HBHUB_SLOTS_REEL_LENGTH - 1), [0, 1, 2]);
    $window = hbHubSlotsWindow($stops);
    $lineCount = count(HBHUB_SLOTS_LINES);

    $lines = [];
    $totalPays = 0;
    foreach (HBHUB_SLOTS_LINES as $index => $rows) {
        $pays = hbHubSlotsLinePays(array_map(static fn (int $reel): string => $window[$rows[$reel]][$reel], [0, 1, 2]));
        if ($pays > 0) {
            $lines[] = ['line' => $index, 'pays' => $pays, 'payout' => intdiv($bet * $pays, $lineCount)];
            $totalPays += $pays;
        }
    }
    // Rounded down once for the whole spin rather than per line, so an all in loses as little as can be
    $payout = intdiv($bet * $totalPays, $lineCount);

    return [
        'stops' => $stops,
        'window' => $window,
        'bet' => $bet,
        'payout' => $payout,
        'net' => $payout - $bet,
        'lines' => $lines,
    ];
}

/**
 * What the machine pays back on average, as a share of what's bet (0.96 is 96%), worked out exactly from the strips
 * and the pays: each line sees one symbol from each reel, every one as likely as the next. For the rules and the docs.
 */
function hbHubSlotsReturn(): float
{
    static $return = null;
    if ($return !== null) {
        return $return;
    }

    $total = 0;
    foreach (HBHUB_SLOTS_REELS[0] as $a) {
        foreach (HBHUB_SLOTS_REELS[1] as $b) {
            foreach (HBHUB_SLOTS_REELS[2] as $c) {
                $total += hbHubSlotsLinePays([$a, $b, $c]);
            }
        }
    }
    $return = $total / (count(HBHUB_SLOTS_REELS[0]) * count(HBHUB_SLOTS_REELS[1]) * count(HBHUB_SLOTS_REELS[2]));
    return $return;
}

/**
 * How likely a line is to get three $symbol, as "1 in N" (for the pay table in the rules and docs).
 */
function hbHubSlotsOdds(string $symbol): int
{
    $chance = 1.0;
    foreach (HBHUB_SLOTS_REELS as $strip) {
        $chance *= count(array_keys($strip, $symbol, true)) / count($strip);
    }
    return (int) round(1 / $chance);
}

/**
 * Whether $bet is a bet the machine takes: in steps of 10 from the minimum, or an all in (the user's last few chips,
 * hbHubCasinoIsAllIn()). The spin checks the chips again, with the wallet locked.
 */
function hbHubSlotsIsBet(mixed $bet, int $chips): bool
{
    if (!is_int($bet)) {
        return false;
    }
    return hbHubCasinoIsAllIn($bet, $chips)
        || ($bet >= HBHUB_SLOTS_MIN_BET && $bet <= HBHUB_SLOTS_MAX_BET && $bet % HBHUB_SLOTS_BET_STEP === 0);
}

/**
 * Spins the machine for the user: takes the bet, pays what it won and logs the spin, all in one transaction with the
 * wallet locked. $requestId is slots.js's own id for the spin, so a spin sent twice (a double click, or sent again after
 * the answer got lost) only counts once: the second time it answers with the first one's result.
 * Returns the spin (hbHubSlotsResolve(), plus its id) and the chips after it, or null when there aren't enough chips.
 *
 * @return ?array{spin: array, chips: int}
 */
function hbHubSlotsSpin(PDO $db, int $userId, int $bet, string $requestId): ?array
{
    return hbHubCasinoTransaction($db, function () use ($db, $userId, $bet, $requestId): ?array {
        $wallet = hbHubCasinoLockWallet($db, $userId); // locked until the spin is paid, so the chips can't be spent twice

        $statement = $db->prepare('SELECT spinId, spinBet, spinStops FROM `HBHub-SlotSpins` WHERE userId = ? AND spinRequestId = ?');
        $statement->execute([$userId, $requestId]);
        $earlier = $statement->fetch();
        if ($earlier !== false) {
            $spin = hbHubSlotsResolve((int) $earlier['spinBet'], hbHubSlotsStops($earlier['spinStops']));
            return ['spin' => ['id' => (int) $earlier['spinId']] + $spin, 'chips' => $wallet['chips']];
        }

        if ($wallet['chips'] < $bet) {
            return null;
        }

        $spin = hbHubSlotsResolve($bet);
        $db->prepare('INSERT INTO `HBHub-SlotSpins` (userId, spinRequestId, spinBet, spinStops, spinPayout, spinNet, spinTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$userId, $requestId, $bet, implode(',', $spin['stops']), $spin['payout'], $spin['net']]);
        $spinId = (int) $db->lastInsertId();

        hbHubCasinoChange($db, $userId, -$bet, 'bet', HBHUB_SLOTS_GAME, $spinId);
        $chips = hbHubCasinoChange($db, $userId, $spin['payout'], 'payout', HBHUB_SLOTS_GAME, $spinId);
        return ['spin' => ['id' => $spinId] + $spin, 'chips' => $chips];
    });
}

/**
 * How many people have spun the slots in the last HBHUB_SLOTS_PLAYING_SECONDS (for the casino home).
 */
function hbHubSlotsPlayingNow(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(DISTINCT userId) FROM `HBHub-SlotSpins`
        WHERE spinTimestamp > UTC_TIMESTAMP() - INTERVAL ' . HBHUB_SLOTS_PLAYING_SECONDS . ' SECOND')->fetchColumn();
}

/**
 * The stops as spinStops in HBHub-SlotSpins keeps them ("3,17,9"), as a list.
 *
 * @return list<int>
 */
function hbHubSlotsStops(string $stops): array
{
    return array_map('intval', explode(',', $stops));
}

/**
 * The user's last spins, newest first: the bet, what it paid, where the reels stopped and the middle row that showed.
 *
 * @return list<array{bet: int, payout: int, net: int, stops: list<int>, symbols: list<string>}>
 */
function hbHubSlotsHistory(PDO $db, int $userId, int $limit = HBHUB_SLOTS_HISTORY): array
{
    $statement = $db->prepare('SELECT spinBet, spinStops, spinPayout, spinNet FROM `HBHub-SlotSpins`
        WHERE userId = ? ORDER BY spinId DESC LIMIT ' . max(1, $limit));
    $statement->execute([$userId]);

    return array_map(static fn (array $row): array => [
        'bet' => (int) $row['spinBet'],
        'payout' => (int) $row['spinPayout'],
        'net' => (int) $row['spinNet'],
        'stops' => hbHubSlotsStops($row['spinStops']),
        'symbols' => hbHubSlotsWindow(hbHubSlotsStops($row['spinStops']))[1],
    ], $statement->fetchAll());
}
