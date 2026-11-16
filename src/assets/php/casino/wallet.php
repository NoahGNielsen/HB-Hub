<?php
// The casino's chips, shared by every game: one wallet per user (HBHub-CasinoWallets) and a log of every change to it
// (HBHub-CasinoTransactions). Nothing changes walletChips except hbHubCasinoChange(), which logs the change and never
// lets the chips go below 0. The wallet row is locked while that happens, so two requests at once can't both spend
// the same chips.
//
// Every user gets a wallet with HBHUB_CASINO_START_CHIPS the first time it's needed, old accounts too.
// The daily chips can be collected once per casino day, which starts at 3 AM Danish time. Only the last day they were
// collected on is kept (walletDailyDay), so days that were missed can't add up. They follow a 30-day calendar
// (HBHUB_CASINO_DAILY_CALENDAR): each day in a row moves one day further on it, which mostly gives more, and
// walletDailyStreak is the day on it that was collected last. Missing a casino day starts it over at day 1, and so
// does finishing day 30.
// The daily chips are per person, not per account: once one of someone's accounts (the ones used in the same browser,
// personAccounts.php) has collected them on a casino day, their other accounts only get HBHUB_CASINO_DAILY_ALT_PERCENT
// of their own day's chips that day. Their streaks still go on as normal.
// After the daily chips comes the 3-hour bonus: HBHUB_CASINO_BONUS_PERCENT of what the daily chips gave that account
// today (walletBonusChips, so an account that only got the 20% gets 15% of that), every HBHUB_CASINO_BONUS_SECONDS.
// The first one is ready 3 hours after the daily chips were collected, and each one after that 3 hours after the one
// before was collected (walletBonusTimestamp), so they don't pile up while nobody collects them. They only count on
// the casino day the daily chips were collected: one that would be ready after the next casino day starts isn't
// given, and the next day's daily chips start them over.
//
// To use the chips in a game:
//   $chips = hbHubCasinoChange($db, $userId, -$bet, 'bet', 'blackjack', $roundId); // null when there aren't enough
// Inside the game's own transaction it's part of that one, so the bet and the game's own tables change together.
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../personAccounts.php';

const HBHUB_CASINO_START_CHIPS = 2500;
// The daily chips on each day of the calendar, day 1 first: mostly more the further in, with a few lean days in between,
// a big one at the end of each week and the biggest on day 30. Day 1 is what everyone gets without a streak.
const HBHUB_CASINO_DAILY_CALENDAR = [
    2500, 2750, 3000, 3250, 2000, 3500, 5000,
    3500, 3750, 4000, 2500, 4250, 4500, 7500,
    4500, 4750, 3000, 5000, 5250, 5500, 10000,
    5500, 3500, 5750, 6000, 6250, 6500, 4000, 7000,
    15000,
];
const HBHUB_CASINO_DAILY_DAYS = 30; // how many days the calendar has, count(HBHUB_CASINO_DAILY_CALENDAR)
// What an account gets of its day's daily chips when another of the same person's accounts already had them that day
const HBHUB_CASINO_DAILY_ALT_PERCENT = 20;
// The 3-hour bonus: this % of what the daily chips gave today, every this many seconds after them
const HBHUB_CASINO_BONUS_PERCENT = 15;
const HBHUB_CASINO_BONUS_SECONDS = 3 * 3600;
const HBHUB_CASINO_TIME_ZONE = 'Europe/Copenhagen';
const HBHUB_CASINO_DAY_START = 3; // 3 AM, when a new casino day starts
const HBHUB_CASINO_CHIPS_PER_KRONE = 100; // what 1 kr buys, when someone buys extra chips from the owner (casino/buy-chips.php)
// With fewer chips than this, a member can go all in: bet every chip they have on one thing, at any table, whatever its
// minimum bet and the steps of 10 (hbHubCasinoIsAllIn()). Otherwise those last few chips could never be played.
const HBHUB_CASINO_ALL_IN_BELOW = 10;

// transactionReason of the casino's own changes. Games use their own (like "bet" and "payout"), with transactionGame set.
const HBHUB_CASINO_REASON_START = 'start';
const HBHUB_CASINO_REASON_DAILY = 'dailyBonus';
const HBHUB_CASINO_REASON_BONUS = 'threeHourBonus';
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
 * Gives the user their daily chips for their next day on the calendar, unless they've already had them this casino day.
 * When another of the person's accounts already had theirs today, it's HBHUB_CASINO_DAILY_ALT_PERCENT of them.
 * Returns how many chips that was, or null when they'd already had them.
 */
function hbHubCasinoClaimDaily(PDO $db, int $userId, ?int $now = null): ?int
{
    $now ??= time();
    $today = hbHubCasinoDay($now);
    $personIds = hbHubPersonUserIds($db, $userId);
    hbHubCasinoWallet($db, $userId); // makes their wallet first if they have none, so the lock below has a row to lock

    return hbHubCasinoTransaction($db, function () use ($db, $userId, $now, $today, $personIds): ?int {
        // Every one of the person's wallets is locked, in one go and in order (so two claims can't deadlock), so two of
        // their accounts claiming at once can't both see today as not collected yet. Accounts without a wallet have
        // never collected anything, so there's nothing of theirs to lock.
        $statement = $db->prepare('SELECT userId, walletDailyDay FROM `HBHub-CasinoWallets`
            WHERE userId IN (' . implode(',', array_fill(0, count($personIds), '?')) . ') ORDER BY userId FOR UPDATE');
        $statement->execute($personIds);
        $isShared = false;
        foreach ($statement->fetchAll() as $row) {
            if ((int) $row['userId'] !== $userId && $row['walletDailyDay'] !== null && $row['walletDailyDay'] >= $today) {
                $isShared = true;
            }
        }

        $wallet = hbHubCasinoLockWallet($db, $userId); // already locked above, this just reads it
        if ($wallet['dailyDay'] !== null && $wallet['dailyDay'] >= $today) {
            return null;
        }

        $day = hbHubCasinoDailyCalendar($wallet, $today)['day'];
        $amount = hbHubCasinoDailyAmount($day, $isShared);
        // The 3-hour bonus starts from here: its chips are a part of these, and the first one is 3 hours away
        $db->prepare('UPDATE `HBHub-CasinoWallets` SET walletDailyDay = ?, walletDailyStreak = ?, walletBonusChips = ?,
                walletBonusTimestamp = ? WHERE userId = ?')
            ->execute([$today, $day, hbHubCasinoBonusAmount($amount), gmdate('Y-m-d H:i:s', $now + HBHUB_CASINO_BONUS_SECONDS), $userId]);
        hbHubCasinoChange($db, $userId, $amount, HBHUB_CASINO_REASON_DAILY);
        return $amount;
    });
}

/**
 * Gives the user their 3-hour bonus when it's ready (hbHubCasinoBonusState()) and moves the next one 3 hours on.
 * Returns how many chips that was, or null when it wasn't ready (already collected, like in another tab, or no daily
 * chips yet today).
 */
function hbHubCasinoClaimBonus(PDO $db, int $userId, ?int $now = null): ?int
{
    $now ??= time();
    return hbHubCasinoTransaction($db, function () use ($db, $userId, $now): ?int {
        $bonus = hbHubCasinoBonusState(hbHubCasinoLockWallet($db, $userId), $now);
        if (!$bonus['ready']) {
            return null;
        }
        $db->prepare('UPDATE `HBHub-CasinoWallets` SET walletBonusTimestamp = ? WHERE userId = ?')
            ->execute([gmdate('Y-m-d H:i:s', $now + HBHUB_CASINO_BONUS_SECONDS), $userId]);
        hbHubCasinoChange($db, $userId, $bonus['amount'], HBHUB_CASINO_REASON_BONUS);
        return $bonus['amount'];
    });
}

/**
 * What each 3-hour bonus gives when the daily chips gave $dailyChips: HBHUB_CASINO_BONUS_PERCENT of them, rounded down.
 */
function hbHubCasinoBonusAmount(int $dailyChips): int
{
    return intdiv($dailyChips * HBHUB_CASINO_BONUS_PERCENT, 100);
}

/**
 * The user's 3-hour bonus at $now:
 * - available: whether there's another one to come today (the daily chips were collected this casino day, and the next
 *   bonus is ready before the next casino day starts)
 * - ready: whether it can be collected right now, and seconds how long until it can (0 when ready or not available)
 * - amount: what it gives (0 while the daily chips haven't been collected today, as it isn't known yet)
 *
 * @param array{dailyDay: ?string, bonusChips: int, bonusAt: ?int} $wallet
 * @return array{available: bool, ready: bool, amount: int, seconds: int}
 */
function hbHubCasinoBonusState(array $wallet, int $now): array
{
    $isCollectedToday = $wallet['dailyDay'] !== null && $wallet['dailyDay'] >= hbHubCasinoDay($now);
    $isAvailable = $isCollectedToday && $wallet['bonusChips'] > 0 && $wallet['bonusAt'] !== null
        && $wallet['bonusAt'] < hbHubCasinoNextDay($now);
    $isReady = $isAvailable && $wallet['bonusAt'] <= $now;

    return [
        'available' => $isAvailable,
        'ready' => $isReady,
        'amount' => $isCollectedToday ? $wallet['bonusChips'] : 0,
        'seconds' => $isAvailable && !$isReady ? max(1, $wallet['bonusAt'] - $now) : 0,
    ];
}

/**
 * Where the user is on the daily chips' calendar on the casino day $today:
 * - collected: how many of its days are ticked off (0 to 30), which is their streak while it's still going
 * - day: the day the next daily chips are for (1 to 30), so today's when they haven't been collected yet, else tomorrow's
 * A streak is still going when the last ones were collected today or yesterday. One that finished day 30 yesterday
 * gets a new calendar today, and one that finishes it today shows the full calendar until tomorrow.
 *
 * @param array{dailyDay: ?string, dailyStreak: int} $wallet
 * @return array{collected: int, day: int}
 */
function hbHubCasinoDailyCalendar(array $wallet, string $today): array
{
    $yesterday = (new DateTimeImmutable($today . ' 12:00'))->modify('-1 day')->format('Y-m-d');
    $isCollectedToday = $wallet['dailyDay'] !== null && $wallet['dailyDay'] >= $today;
    $isGoing = $isCollectedToday || $wallet['dailyDay'] === $yesterday;
    $collected = $isGoing ? min(max($wallet['dailyStreak'], 0), HBHUB_CASINO_DAILY_DAYS) : 0;

    if (!$isCollectedToday && $collected === HBHUB_CASINO_DAILY_DAYS) {
        $collected = 0;
    }
    return ['collected' => $collected, 'day' => $collected % HBHUB_CASINO_DAILY_DAYS + 1];
}

/**
 * The daily chips day $day of the calendar gives, or HBHUB_CASINO_DAILY_ALT_PERCENT of them when $isShared (another of
 * the person's accounts already had theirs that day).
 */
function hbHubCasinoDailyAmount(int $day, bool $isShared): int
{
    $amount = HBHUB_CASINO_DAILY_CALENDAR[$day - 1];
    return $isShared ? intdiv($amount * HBHUB_CASINO_DAILY_ALT_PERCENT, 100) : $amount;
}

/**
 * Whether another of the person's accounts (not $userId itself) has collected its daily chips on the casino day $today.
 */
function hbHubCasinoDailyIsShared(PDO $db, int $userId, string $today): bool
{
    $otherIds = array_values(array_diff(hbHubPersonUserIds($db, $userId), [$userId]));
    if ($otherIds === []) {
        return false;
    }
    $statement = $db->prepare('SELECT 1 FROM `HBHub-CasinoWallets`
        WHERE userId IN (' . implode(',', array_fill(0, count($otherIds), '?')) . ') AND walletDailyDay >= ? LIMIT 1');
    $statement->execute([...$otherIds, $today]);
    return $statement->fetchColumn() !== false;
}

/**
 * The user's chips and daily chips, as sent to casino.js. ready says whether the daily chips can be collected now,
 * seconds how long until they can (0 when ready). collected and day are where they are on the calendar
 * (hbHubCasinoDailyCalendar()), and amount is what that day gives: today's chips when ready, else tomorrow's.
 * shared says that another of the person's accounts already had theirs today, so amount is only
 * HBHUB_CASINO_DAILY_ALT_PERCENT of the day's chips (only while ready: tomorrow's can't be known yet).
 * bonus is the 3-hour bonus (hbHubCasinoBonusState()).
 *
 * @return array{chips: int, daily: array{amount: int, day: int, collected: int, ready: bool, shared: bool, seconds: int},
 *     bonus: array{available: bool, ready: bool, amount: int, seconds: int}}
 */
function hbHubCasinoStatus(PDO $db, int $userId, ?int $now = null): array
{
    $now ??= time();
    $today = hbHubCasinoDay($now);
    $wallet = hbHubCasinoWallet($db, $userId);
    $isReady = $wallet['dailyDay'] === null || $wallet['dailyDay'] < $today;
    $calendar = hbHubCasinoDailyCalendar($wallet, $today);
    $isShared = $isReady && hbHubCasinoDailyIsShared($db, $userId, $today); // only looked up when it matters

    return [
        'chips' => $wallet['chips'],
        'daily' => [
            'amount' => hbHubCasinoDailyAmount($calendar['day'], $isShared),
            'day' => $calendar['day'],
            'collected' => $calendar['collected'],
            'ready' => $isReady,
            'shared' => $isShared,
            'seconds' => $isReady ? 0 : max(1, hbHubCasinoNextDay($now) - $now),
        ],
        'bonus' => hbHubCasinoBonusState($wallet, $now),
    ];
}

/**
 * Whether a bet of $amount is an all in: every one of the $chips the user has, when that's fewer than
 * HBHUB_CASINO_ALL_IN_BELOW. The games let such a bet through without their minimum and steps. The wallet still has
 * the last word when the chips are taken (hbHubCasinoChange()), so chips spent elsewhere in between can't be bet twice.
 */
function hbHubCasinoIsAllIn(int $amount, int $chips): bool
{
    return $amount > 0 && $amount === $chips && $chips < HBHUB_CASINO_ALL_IN_BELOW;
}

/**
 * The user's wallet, made with the starting chips if they don't have one yet. Not locked, so only for showing it.
 *
 * @return array{chips: int, dailyDay: ?string, dailyStreak: int, bonusChips: int, bonusAt: ?int}
 */
function hbHubCasinoWallet(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT ' . HBHUB_CASINO_WALLET_COLUMNS . ' FROM `HBHub-CasinoWallets` WHERE userId = ?');
    $statement->execute([$userId]);
    $wallet = $statement->fetch();
    if ($wallet === false) {
        return hbHubCasinoTransaction($db, fn (): array => hbHubCasinoLockWallet($db, $userId));
    }
    return hbHubCasinoWalletRow($wallet);
}

/**
 * Locks the user's wallet until the transaction ends and returns it. A user without one gets it now, with the
 * starting chips (logged like any other change). Only inside a transaction.
 *
 * @return array{chips: int, dailyDay: ?string, dailyStreak: int, bonusChips: int, bonusAt: ?int}
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

    $statement = $db->prepare('SELECT ' . HBHUB_CASINO_WALLET_COLUMNS . ' FROM `HBHub-CasinoWallets` WHERE userId = ? FOR UPDATE');
    $statement->execute([$userId]);
    return hbHubCasinoWalletRow($statement->fetch());
}

// What hbHubCasinoWallet() and hbHubCasinoLockWallet() read, for hbHubCasinoWalletRow()
const HBHUB_CASINO_WALLET_COLUMNS = 'walletChips, walletDailyDay, walletDailyStreak, walletBonusChips, walletBonusTimestamp';

/**
 * A wallet row (HBHUB_CASINO_WALLET_COLUMNS) as the array the rest of this file uses. bonusAt is a Unix time.
 *
 * @return array{chips: int, dailyDay: ?string, dailyStreak: int, bonusChips: int, bonusAt: ?int}
 */
function hbHubCasinoWalletRow(array $wallet): array
{
    return [
        'chips' => (int) $wallet['walletChips'],
        'dailyDay' => $wallet['walletDailyDay'],
        'dailyStreak' => (int) $wallet['walletDailyStreak'],
        'bonusChips' => (int) $wallet['walletBonusChips'],
        'bonusAt' => $wallet['walletBonusTimestamp'] !== null
            ? (new DateTimeImmutable($wallet['walletBonusTimestamp'], new DateTimeZone('UTC')))->getTimestamp()
            : null,
    ];
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
