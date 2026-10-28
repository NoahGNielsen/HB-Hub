<?php
// Roulette: a single-zero wheel (0-36), played alone against the house with the casino's chips. A spin is one request:
// the bets go in, the ball lands and the winning bets are paid, all in one transaction (hbHubRouletteSpin()). Nothing
// is kept between spins but the log in HBHub-RouletteSpins, so there's no table to sit at or leave.
//
// A bet is a key for the numbers it covers (hbHubRouletteBets()): the number for a straight bet ("17"), the numbers
// from low to high joined with "-" for a split, street, corner or line ("17-20", "0-1-2-3"), and a name for the outside
// bets ("red", "dozen2", "column3"...). Every bet pays back 36 / (how many numbers it covers) times the bet, the bet
// included: a straight bet pays 35:1, a split 17:1, red 1:1. A 0 loses every outside bet.
require_once __DIR__ . '/../wallet.php';

const HBHUB_ROULETTE_GAME = 'roulette'; // transactionGame in HBHub-CasinoTransactions
const HBHUB_ROULETTE_BET_STEP = 10; // every bet in steps of 10, like at the Blackjack tables
const HBHUB_ROULETTE_MAX_BET = 1000000000000; // only so the sums can't overflow, the chips are what really limits it
const HBHUB_ROULETTE_BETS_MAX_LENGTH = 8192; // the bets' JSON - every spot on the table at once fits easily
const HBHUB_ROULETTE_HISTORY = 12; // the user's last spins shown above the table
const HBHUB_ROULETTE_RED = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];
// The numbers around the wheel clockwise, from 0
const HBHUB_ROULETTE_WHEEL = [0, 32, 15, 19, 4, 21, 2, 25, 17, 34, 6, 27, 13, 36, 11, 30, 8, 23, 10, 5, 24, 16, 33, 1, 20,
    14, 31, 9, 22, 18, 29, 7, 28, 12, 35, 3, 26];

/**
 * Every bet that can be made, as key => the numbers it covers.
 * On the table the numbers go in 12 rows of 3 (1 2 3, 4 5 6...): n + 1 is next to n in its row, n + 3 the one after it.
 *
 * @return array<string, list<int>>
 */
function hbHubRouletteBets(): array
{
    static $bets = null;
    if ($bets !== null) {
        return $bets;
    }

    $bets = [];
    $add = static function (array $numbers) use (&$bets): void {
        $bets[implode('-', $numbers)] = $numbers;
    };

    for ($n = 0; $n <= 36; $n++) {
        $add([$n]); // straight
    }
    foreach ([[0, 1], [0, 2], [0, 3], [0, 1, 2], [0, 2, 3], [0, 1, 2, 3]] as $numbers) {
        $add($numbers); // splits and trios with the 0, and the first four
    }
    for ($n = 1; $n <= 36; $n++) {
        if ($n <= 33) {
            $add([$n, $n + 3]); // split with the number in the next row
        }
        if ($n % 3 !== 0) {
            $add([$n, $n + 1]); // split with the number next to it in its row
            if ($n <= 32) {
                $add([$n, $n + 1, $n + 3, $n + 4]); // corner
            }
        }
        if ($n % 3 === 1) {
            $add([$n, $n + 1, $n + 2]); // street: the row
            if ($n <= 31) {
                $add(range($n, $n + 5)); // line: two rows
            }
        }
    }

    $where = static fn (callable $test): array => array_values(array_filter(range(1, 36), $test));
    $bets += [
        'red' => HBHUB_ROULETTE_RED,
        'black' => $where(static fn (int $n): bool => !in_array($n, HBHUB_ROULETTE_RED, true)),
        'odd' => $where(static fn (int $n): bool => $n % 2 === 1),
        'even' => $where(static fn (int $n): bool => $n % 2 === 0),
        'low' => range(1, 18),
        'high' => range(19, 36),
        'dozen1' => range(1, 12),
        'dozen2' => range(13, 24),
        'dozen3' => range(25, 36),
        'column1' => $where(static fn (int $n): bool => $n % 3 === 1),
        'column2' => $where(static fn (int $n): bool => $n % 3 === 2),
        'column3' => $where(static fn (int $n): bool => $n % 3 === 0),
    ];
    return $bets;
}

/**
 * What a bet pays back for each chip on it when it wins, the chip included (36 for a straight bet, 2 for red).
 * Every bet covers 1, 2, 3, 4, 6, 12 or 18 numbers, so it always comes out even.
 */
function hbHubRouletteReturn(string $bet): int
{
    return intdiv(36, count(hbHubRouletteBets()[$bet]));
}

/**
 * "red", "black" or "green" (the 0).
 */
function hbHubRouletteColor(int $number): string
{
    return match (true) {
        $number === 0 => 'green',
        in_array($number, HBHUB_ROULETTE_RED, true) => 'red',
        default => 'black',
    };
}

/**
 * The bets sent by roulette.js, as JSON like {"17": 10, "red": 50}. Returns them as bet => chips, or null when
 * there's none, or any of them isn't a bet there is or isn't a whole number of chips in steps of 10.
 *
 * @return ?array<string, int>
 */
function hbHubRouletteCleanBets(mixed $json): ?array
{
    if (!is_string($json) || strlen($json) > HBHUB_ROULETTE_BETS_MAX_LENGTH) {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    $bets = [];
    foreach ($decoded as $bet => $amount) {
        $bet = (string) $bet; // straight bets come out of json_decode() as int keys
        if (!isset(hbHubRouletteBets()[$bet]) || !is_int($amount) || $amount < HBHUB_ROULETTE_BET_STEP
            || $amount > HBHUB_ROULETTE_MAX_BET || $amount % HBHUB_ROULETTE_BET_STEP !== 0) {
            return null;
        }
        $bets[$bet] = $amount;
    }
    return $bets;
}

/**
 * Spins the wheel for the bets and works out what they pay, without touching any chips: the part of a spin that's the
 * same for members (hbHubRouletteSpin()) and guests (/play/roulette, whose play chips are in a token instead).
 *
 * @param array<string, int> $bets from hbHubRouletteCleanBets()
 * @return array{number: int, color: string, wagered: int, payout: int, net: int, wins: array<string, int>}
 *         wins is every winning bet => what it paid back
 */
function hbHubRouletteResolve(array $bets): array
{
    $number = random_int(0, 36);
    $wins = [];
    foreach ($bets as $bet => $amount) {
        if (in_array($number, hbHubRouletteBets()[$bet], true)) {
            $wins[(string) $bet] = $amount * hbHubRouletteReturn((string) $bet);
        }
    }
    $wagered = array_sum($bets);
    $payout = array_sum($wins);

    return [
        'number' => $number,
        'color' => hbHubRouletteColor($number),
        'wagered' => $wagered,
        'payout' => $payout,
        'net' => $payout - $wagered,
        'wins' => $wins,
    ];
}

/**
 * Takes the bets from the user's chips, spins and pays the winning bets, all at once.
 * Returns null when the user doesn't have the chips for every bet (then nothing changes).
 *
 * @param array<string, int> $bets from hbHubRouletteCleanBets()
 * @return ?array{spinId: int, number: int, color: string, wagered: int, payout: int, net: int, chips: int, wins: array<string, int>}
 *         wins is every winning bet => what it paid back
 */
function hbHubRouletteSpin(PDO $db, int $userId, array $bets): ?array
{
    $wagered = array_sum($bets);

    return hbHubCasinoTransaction($db, function () use ($db, $userId, $bets, $wagered): ?array {
        // Locked until the spin is paid, so two spins at once can't both spend the same chips
        if (hbHubCasinoLockWallet($db, $userId)['chips'] < $wagered) {
            return null;
        }

        $spin = hbHubRouletteResolve($bets);
        $db->prepare('INSERT INTO `HBHub-RouletteSpins` (userId, spinNumber, spinWagered, spinPayout, spinNet, spinBets, spinTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$userId, $spin['number'], $wagered, $spin['payout'], $spin['net'], json_encode($bets, JSON_FORCE_OBJECT)]);
        $spinId = (int) $db->lastInsertId();

        hbHubCasinoChange($db, $userId, -$wagered, 'bet', HBHUB_ROULETTE_GAME, $spinId);
        $chips = hbHubCasinoChange($db, $userId, $spin['payout'], 'payout', HBHUB_ROULETTE_GAME, $spinId);

        return ['spinId' => $spinId] + $spin + ['chips' => $chips];
    });
}

/**
 * The user's last spins, newest first.
 *
 * @return list<array{number: int, color: string, net: int}>
 */
function hbHubRouletteHistory(PDO $db, int $userId, int $limit = HBHUB_ROULETTE_HISTORY): array
{
    $statement = $db->prepare('SELECT spinNumber, spinNet FROM `HBHub-RouletteSpins`
        WHERE userId = ? ORDER BY spinId DESC LIMIT ' . max(1, $limit));
    $statement->execute([$userId]);

    return array_map(static fn (array $row): array => [
        'number' => (int) $row['spinNumber'],
        'color' => hbHubRouletteColor((int) $row['spinNumber']),
        'net' => (int) $row['spinNet'],
    ], $statement->fetchAll());
}
