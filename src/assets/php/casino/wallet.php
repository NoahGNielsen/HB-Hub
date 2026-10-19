<?php
// The casino's chips, shared by every game: one wallet per user (HBHub-CasinoWallets) and a log of every change to it
// (HBHub-CasinoTransactions). Nothing changes walletChips except hbHubCasinoChange(), which logs the change and never
// lets the chips go below 0. The wallet row is locked while that happens, so two requests at once can't both spend
// the same chips.
//
// Every user gets a wallet with HBHUB_CASINO_START_CHIPS the first time it's needed, old accounts too.
// The daily chips can be collected once per casino day, which starts at 3 AM Danish time. Only the last day they were
// collected on is kept (walletDailyDay), so days that were missed can't add up.
//
// To use the chips in a game:
//   $chips = hbHubCasinoChange($db, $userId, -$bet, 'bet', 'blackjack', $roundId); // null when there aren't enough
// Inside the game's own transaction it's part of that one, so the bet and the game's own tables change together.
require_once __DIR__ . '/../database.php';

const HBHUB_CASINO_START_CHIPS = 2500;
const HBHUB_CASINO_DAILY_CHIPS = 2500;
const HBHUB_CASINO_TIME_ZONE = 'Europe/Copenhagen';
const HBHUB_CASINO_DAY_START = 3; // 3 AM, when a new casino day starts
const HBHUB_CASINO_CHIPS_PER_KRONE = 100; // what 1 kr buys, when someone buys extra chips from the owner (gambling/buy-chips.php)

// transactionReason of the casino's own changes. Games use their own (like "bet" and "payout"), with transactionGame set.
const HBHUB_CASINO_REASON_START = 'start';
const HBHUB_CASINO_REASON_DAILY = 'dailyBonus';
const HBHUB_CASINO_REASON_ADMIN = 'admin'; // added or taken by a site admin (admin/user.php), transactionReference is the admin's userId
const HBHUB_CASINO_NAME_PATTERN = '~^[A-Za-z][A-Za-z0-9]{0,31}$~'; // transactionReason and transactionGame (ascii, 32 long)

/**
 * Runs $work in a transaction and returns what it returns. When the caller has already started one, $work is part of
 * theirs instead, so a game can change chips and its own tables together, all or nothing.
 */
function hbHubCasinoTransaction(PDO $db, callable $work): mixed
{
    if ($db->inTransaction()) {
        return $work();
    }

    $db->beginTransaction();
    try {
        $result = $work();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $result;
}

/**
 * Adds $amount chips to the user's wallet (takes them when it's negative) and logs it. Every change to anyone's chips
 * goes through here. Returns the chips after, or null when there aren't enough (then nothing is changed).
 *
 * @param string $reason why, like "bet" or "payout" (a letter, then letters and digits, up to 32)
 * @param ?string $game the game it's for, like "blackjack" (same rules), or null for the casino's own changes
 * @param ?int $reference the game's id for what it's about, like the round, to find it in the log again
 */
function hbHubCasinoChange(PDO $db, int $userId, int $amount, string $reason, ?string $game = null, ?int $reference = null): ?int
{
    if (preg_match(HBHUB_CASINO_NAME_PATTERN, $reason) !== 1 || ($game !== null && preg_match(HBHUB_CASINO_NAME_PATTERN, $game) !== 1)) {
        throw new InvalidArgumentException('Bad casino reason or game: ' . $reason . ', ' . $game); // a typo, so it's caught straight away
    }

    return hbHubCasinoTransaction($db, function () use ($db, $userId, $amount, $reason, $game, $reference): ?int {
        $chips = hbHubCasinoLockWallet($db, $userId)['chips'];
        if ($chips + $amount < 0) {
            return null;
        }
        if ($amount === 0) {
            return $chips; // nothing to change or log, like the payout of a lost bet
        }

        $chips += $amount;
        $db->prepare('UPDATE `HBHub-CasinoWallets` SET walletChips = ? WHERE userId = ?')->execute([$chips, $userId]);
        hbHubCasinoLog($db, $userId, $amount, $chips, $reason, $game, $reference);
        return $chips;
    });
}

/**
 * Gives the user their daily chips, unless they've already had them this casino day.
 * Returns the chips after, or null when they'd already had them.
 */
function hbHubCasinoClaimDaily(PDO $db, int $userId, ?int $now = null): ?int
{
    $today = hbHubCasinoDay($now ?? time());

    return hbHubCasinoTransaction($db, function () use ($db, $userId, $today): ?int {
        // Locked, so two claims at once can't both see today as not collected yet
        $wallet = hbHubCasinoLockWallet($db, $userId);
        if ($wallet['dailyDay'] !== null && $wallet['dailyDay'] >= $today) {
            return null;
        }

        $db->prepare('UPDATE `HBHub-CasinoWallets` SET walletDailyDay = ? WHERE userId = ?')->execute([$today, $userId]);
        return hbHubCasinoChange($db, $userId, HBHUB_CASINO_DAILY_CHIPS, HBHUB_CASINO_REASON_DAILY);
    });
}

/**
 * The user's chips and daily chips, as sent to casino.js. ready says whether the daily chips can be collected now,
 * seconds how long until they can (0 when ready).
 *
 * @return array{chips: int, daily: array{amount: int, ready: bool, seconds: int}}
 */
function hbHubCasinoStatus(PDO $db, int $userId, ?int $now = null): array
{
    $now ??= time();
    $wallet = hbHubCasinoWallet($db, $userId);
    $isReady = $wallet['dailyDay'] === null || $wallet['dailyDay'] < hbHubCasinoDay($now);

    return [
        'chips' => $wallet['chips'],
        'daily' => [
            'amount' => HBHUB_CASINO_DAILY_CHIPS,
            'ready' => $isReady,
            'seconds' => $isReady ? 0 : max(1, hbHubCasinoNextDay($now) - $now),
        ],
    ];
}

/**
 * The user's wallet, made with the starting chips if they don't have one yet. Not locked, so only for showing it.
 *
 * @return array{chips: int, dailyDay: ?string}
 */
function hbHubCasinoWallet(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT walletChips, walletDailyDay FROM `HBHub-CasinoWallets` WHERE userId = ?');
    $statement->execute([$userId]);
    $wallet = $statement->fetch();
    if ($wallet === false) {
        return hbHubCasinoTransaction($db, fn (): array => hbHubCasinoLockWallet($db, $userId));
    }
    return ['chips' => (int) $wallet['walletChips'], 'dailyDay' => $wallet['walletDailyDay']];
}

/**
 * Locks the user's wallet until the transaction ends and returns it. A user without one gets it now, with the
 * starting chips (logged like any other change). Only inside a transaction.
 *
 * @return array{chips: int, dailyDay: ?string}
 */
function hbHubCasinoLockWallet(PDO $db, int $userId): array
{
    $exists = $db->prepare('SELECT 1 FROM `HBHub-CasinoWallets` WHERE userId = ?');
    $exists->execute([$userId]);
    if ($exists->fetchColumn() === false) {
        // Checked without FOR UPDATE: locking a row that isn't there locks the gap around it, and two requests doing
        // that at once would deadlock when both insert. Here the second one waits for the first and changes nothing.
        $create = $db->prepare('INSERT INTO `HBHub-CasinoWallets` (userId, walletChips, walletCreatedTimestamp)
            VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE userId = userId');
        $create->execute([$userId, HBHUB_CASINO_START_CHIPS]);
        if ($create->rowCount() === 1) { // 1 when it was made, 0 when another request just made it
            hbHubCasinoLog($db, $userId, HBHUB_CASINO_START_CHIPS, HBHUB_CASINO_START_CHIPS, HBHUB_CASINO_REASON_START, null, null);
        }
    }

    $statement = $db->prepare('SELECT walletChips, walletDailyDay FROM `HBHub-CasinoWallets` WHERE userId = ? FOR UPDATE');
    $statement->execute([$userId]);
    $wallet = $statement->fetch();
    return ['chips' => (int) $wallet['walletChips'], 'dailyDay' => $wallet['walletDailyDay']];
}

function hbHubCasinoLog(PDO $db, int $userId, int $amount, int $balance, string $reason, ?string $game, ?int $reference): void
{
    $db->prepare('INSERT INTO `HBHub-CasinoTransactions` (userId, transactionAmount, transactionBalance, transactionReason,
            transactionGame, transactionReference, transactionTimestamp)
        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$userId, $amount, $balance, $reason, $game, $reference]);
}

function hbHubCasinoLocalTime(int $time): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone(HBHUB_CASINO_TIME_ZONE));
}

/**
 * The casino day $time is in, as Y-m-d: the Danish date, except that before 3 AM it's still the day before.
 */
function hbHubCasinoDay(int $time): string
{
    $local = hbHubCasinoLocalTime($time);
    if ((int) $local->format('G') < HBHUB_CASINO_DAY_START) {
        $local = $local->setTime(12, 0)->modify('-1 day'); // from noon, so a summer time change can't move it a date too far
    }
    return $local->format('Y-m-d');
}

/**
 * When the casino day after the one $time is in starts (3 AM Danish time), as a Unix time.
 * 3 AM happens exactly once on every Danish date, summer time changes included (they're at 2 and 3 AM).
 */
function hbHubCasinoNextDay(int $time): int
{
    return (new DateTimeImmutable(hbHubCasinoDay($time) . ' 12:00', new DateTimeZone(HBHUB_CASINO_TIME_ZONE)))
        ->modify('+1 day')
        ->setTime(HBHUB_CASINO_DAY_START, 0)
        ->getTimestamp();
}
