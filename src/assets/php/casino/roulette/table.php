<?php
// The Roulette table the whole site shares: one wheel, and everyone's bets on it, which everyone at the table can see.
// The table is one row in HBHub-RouletteTable (made the first time it's needed), and the bets on its round are in
// HBHub-RouletteBets, one row per player. The rules are in roulette.php.
//
// A round goes like this (hbHubRoulettePhase()):
// - open: nobody has bets on the table. The first bet starts the round, and betting closes HBHUB_ROULETTE_BET_MS later.
// - betting: anyone can put bets on and take them off until then. A player who presses Ready can't change theirs
//   anymore, and when every player with bets is ready, betting closes straight away.
// - the wheel spins (hbHubRouletteTableSpin()): one ball for everyone. Only then are the bets taken from the chips and
//   the winning bets paid, each player's spin logged in HBHub-RouletteSpins (spinRound is the round), and the bets are
//   cleared off the table. A player who doesn't have the chips for their bets by then (spent at another table, say),
//   or can't use the casino anymore, has their bets taken off instead: nothing is taken or paid.
// - spinning: for HBHUB_ROULETTE_RESULT_MS the wheel turns and the result is shown, and no bets go on. Then it's open
//   again, for the next round.
// There's no cron, so the spin happens on the first request after betting closed, whoever's it is: everything that
// changes the table goes through hbHubRouletteTablePlay(), which locks the table's row first, so it spins only once.
require_once __DIR__ . '/roulette.php';
require_once __DIR__ . '/../access.php';
require_once __DIR__ . '/../../userAvatar.php';

const HBHUB_ROULETTE_TABLE_ID = 1; // tableId in HBHub-RouletteTable, its only row
const HBHUB_ROULETTE_BET_MS = 20000; // how long betting stays open after the round's first bet
const HBHUB_ROULETTE_SPIN_MS = 5000; // how long the wheel turns (roulette.js)
const HBHUB_ROULETTE_RESULT_MS = 9000; // from the spin until the next round's bets can go on: the wheel, then the result
const HBHUB_ROULETTE_RECENT = 12; // the table's last numbers, shown next to the wheel
const HBHUB_ROULETTE_PLAYING_SECONDS = 120; // who played a spin this recently counts as playing now (the casino home)
const HBHUB_ROULETTE_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * Now, as Unix time in milliseconds (the table's times).
 */
function hbHubRouletteNow(): int
{
    return (int) floor(microtime(true) * 1000);
}

/**
 * The table's row, locked until the transaction ends when $lock (only inside one). Made the first time.
 *
 * @return array{round: int, closesAt: ?int, number: ?int, spunAt: ?int, version: int}
 */
function hbHubRouletteTable(PDO $db, bool $lock = false): array
{
    $statement = $db->prepare('SELECT tableRound, tableClosesAt, tableNumber, tableSpunAt, tableVersion
        FROM `HBHub-RouletteTable` WHERE tableId = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([HBHUB_ROULETTE_TABLE_ID]);
    $row = $statement->fetch();
    if ($row === false) {
        $db->prepare('INSERT IGNORE INTO `HBHub-RouletteTable` (tableId) VALUES (?)')->execute([HBHUB_ROULETTE_TABLE_ID]);
        $statement->execute([HBHUB_ROULETTE_TABLE_ID]);
        $row = $statement->fetch();
    }

    $number = static fn (mixed $value): ?int => $value !== null ? (int) $value : null;
    return [
        'round' => (int) $row['tableRound'],
        'closesAt' => $number($row['tableClosesAt']),
        'number' => $number($row['tableNumber']),
        'spunAt' => $number($row['tableSpunAt']),
        'version' => (int) $row['tableVersion'],
    ];
}

/**
 * Where the round is: 'open' (no bets on it, the first one starts it), 'betting', 'due' (betting has closed, so the
 * wheel spins on this request) or 'spinning' (the wheel turns and the result is shown, no bets go on).
 *
 * @param array{round: int, closesAt: ?int, number: ?int, spunAt: ?int, version: int} $table
 */
function hbHubRoulettePhase(array $table, int $now): string
{
    return match (true) {
        $table['number'] !== null => $now < $table['spunAt'] + HBHUB_ROULETTE_RESULT_MS ? 'spinning' : 'open',
        $table['closesAt'] === null => 'open',
        $now >= $table['closesAt'] => 'due',
        default => 'betting',
    };
}

/**
 * Changes the table: locks it, spins it when betting has closed, then runs $do (when given) and spins again if that
 * closed the betting (everyone ready). $do gets the table and now, changes the table where it needs to and returns an
 * error code, or null when it went fine. Returns the table after, and $do's error.
 *
 * @param ?callable(array &$table, int $now): ?string $do
 * @return array{0: array{round: int, closesAt: ?int, number: ?int, spunAt: ?int, version: int}, 1: ?string}
 */
function hbHubRouletteTablePlay(PDO $db, ?callable $do = null): array
{
    return hbHubCasinoTransaction($db, function () use ($db, $do): array {
        $table = hbHubRouletteTable($db, true);
        $before = $table;
        $now = hbHubRouletteNow();

        if (hbHubRoulettePhase($table, $now) === 'due') {
            hbHubRouletteTableSpin($db, $table, $now);
        }
        $error = $do !== null ? $do($table, $now) : null;
        if (hbHubRoulettePhase($table, $now) === 'due') {
            hbHubRouletteTableSpin($db, $table, $now);
        }

        if ($table !== $before) {
            $table['version']++;
            $db->prepare('UPDATE `HBHub-RouletteTable` SET tableRound = ?, tableClosesAt = ?, tableNumber = ?,
                    tableSpunAt = ?, tableVersion = ? WHERE tableId = ?')
                ->execute([$table['round'], $table['closesAt'], $table['number'], $table['spunAt'], $table['version'],
                    HBHUB_ROULETTE_TABLE_ID]);
        }
        return [$table, $error];
    });
}

/**
 * Spins the wheel for the round's bets: takes them from each player's chips, pays the winning ones, logs each player's
 * spin and clears the table. Only from hbHubRouletteTablePlay(), with the table locked.
 */
function hbHubRouletteTableSpin(PDO $db, array &$table, int $now): void
{
    $number = random_int(0, 36);

    // In userId order, so two spins can't ever lock the same wallets the other way round
    $statement = $db->prepare('SELECT b.userId, b.betBets, b.betWagered, u.userRole, u.userStatus, u.userSettings
        FROM `HBHub-RouletteBets` b
        JOIN `HBHub-Users` u ON u.userId = b.userId
        WHERE b.betRound = ? ORDER BY b.userId');
    $statement->execute([$table['round']]);
    foreach ($statement->fetchAll() as $row) {
        $userId = (int) $row['userId'];
        $wagered = (int) $row['betWagered'];
        $bets = json_decode($row['betBets'], true);
        $canPlay = $row['userStatus'] !== null && (int) $row['userStatus'] !== HBHUB_ROULETTE_STATUS_BANNED
            && hbHubCasinoDenied($row) === null; // blocked gambling in their settings since, or the casino closed
        // Locked until the spin is paid, so the chips can't be spent somewhere else at the same time
        if (!is_array($bets) || !$canPlay || hbHubCasinoLockWallet($db, $userId)['chips'] < $wagered) {
            continue; // their bets are taken off, nothing is taken or paid
        }

        $spin = hbHubRouletteResolve($bets, $number);
        $db->prepare('INSERT INTO `HBHub-RouletteSpins` (userId, spinRound, spinNumber, spinWagered, spinPayout, spinNet, spinBets, spinTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$userId, $table['round'], $number, $wagered, $spin['payout'], $spin['net'], json_encode($bets, JSON_FORCE_OBJECT)]);
        $spinId = (int) $db->lastInsertId();

        hbHubCasinoChange($db, $userId, -$wagered, 'bet', HBHUB_ROULETTE_GAME, $spinId);
        hbHubCasinoChange($db, $userId, $spin['payout'], 'payout', HBHUB_ROULETTE_GAME, $spinId);
    }

    $db->prepare('DELETE FROM `HBHub-RouletteBets` WHERE betRound <= ?')->execute([$table['round']]);
    $table['number'] = $number;
    $table['spunAt'] = $now;
}

/**
 * Puts the user's bets on the table, instead of what they had on it ($bets from hbHubRouletteCleanBets(), or [] to
 * take them all off). With $ready they're done: their bets can't change anymore this round. For hbHubRouletteTablePlay().
 * Returns an error code, or null when it went fine: spinning (no bets while the wheel turns), ready (they already
 * pressed Ready) or notEnoughChips.
 *
 * @param array<string, int> $bets
 */
function hbHubRouletteTableBet(PDO $db, array &$table, int $userId, array $bets, bool $ready, int $now): ?string
{
    $phase = hbHubRoulettePhase($table, $now);
    if ($phase === 'spinning') {
        return 'spinning';
    }
    if ($table['number'] !== null) { // the last round's result is over: this is the first bet on the next one
        if ($bets === []) {
            return null;
        }
        $table = ['round' => $table['round'] + 1, 'closesAt' => null, 'number' => null, 'spunAt' => null] + $table;
    }

    $statement = $db->prepare('SELECT betReady FROM `HBHub-RouletteBets` WHERE userId = ? AND betRound = ?');
    $statement->execute([$userId, $table['round']]);
    if ((int) $statement->fetchColumn() === 1) {
        return 'ready';
    }

    $wagered = array_sum($bets);
    if ($bets === []) {
        $db->prepare('DELETE FROM `HBHub-RouletteBets` WHERE userId = ?')->execute([$userId]);
    } elseif (hbHubCasinoWallet($db, $userId)['chips'] < $wagered) {
        return 'notEnoughChips'; // checked again when the wheel spins, as they can still spend them elsewhere
    } else {
        $db->prepare('INSERT INTO `HBHub-RouletteBets` (userId, betRound, betBets, betWagered, betReady, betTimestamp)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE betRound = VALUES(betRound), betBets = VALUES(betBets),
                    betWagered = VALUES(betWagered), betReady = VALUES(betReady), betTimestamp = VALUES(betTimestamp)')
            ->execute([$userId, $table['round'], json_encode($bets, JSON_FORCE_OBJECT), $wagered, $ready ? 1 : 0]);
    }

    $statement = $db->prepare('SELECT COUNT(*) AS players, COALESCE(SUM(betReady), 0) AS ready
        FROM `HBHub-RouletteBets` WHERE betRound = ?');
    $statement->execute([$table['round']]);
    $counts = $statement->fetch();
    if ((int) $counts['players'] === 0) {
        $table['closesAt'] = null; // the last bets were taken off: open again, and the next bet starts the clock over
    } else {
        $table['closesAt'] ??= $now + HBHUB_ROULETTE_BET_MS;
        if ((int) $counts['ready'] === (int) $counts['players']) {
            $table['closesAt'] = min($table['closesAt'], $now); // everyone is ready: spin now
        }
    }
    $table['version']++; // the bets changed, so the table did too, even when nothing in its row did
    return null;
}

/**
 * The table's version, sent with every answer: when it's the same as the one the page has, nothing changed.
 */
function hbHubRouletteTableVersion(array $table, int $now): string
{
    return $table['round'] . '.' . $table['version'] . '.' . hbHubRoulettePhase($table, $now);
}

/**
 * Names and avatars, as userId => [name, avatar].
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubRoulettePeople(PDO $db, array $userIds): array
{
    $userIds = array_values(array_unique($userIds));
    if ($userIds === []) {
        return [];
    }

    $statement = $db->prepare('SELECT userId, userName, userAvatarAttachmentId FROM `HBHub-Users`
        WHERE userId IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')');
    $statement->execute($userIds);

    $people = [];
    foreach ($statement->fetchAll() as $user) {
        $userId = (int) $user['userId'];
        $people[$userId] = [
            'name' => $user['userName'],
            'avatar' => hbHubAvatarUrl($userId, $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null),
        ];
    }
    return $people;
}

/**
 * The table as roulette.js shows it, for the user. Everyone at the table sees everyone's bets, so nothing is hidden.
 * - phase: open, betting or spinning (hbHubRoulettePhase()), closesIn: ms until betting closes (while betting), and
 *   spunAgo: ms since the last round was spun (while spinning, so the wheel can catch up if the page was late)
 * - players: who has bets on this round, the user first: {id, name, avatar, me, bets: {bet: chips}, wagered, ready}
 * - last: the last round that was spun, {round, number, color, players: [{id, name, avatar, me, bets, wagered, payout,
 *   net, wins: {bet: what it paid}}]}, or null. Players whose bets were taken off at the spin aren't in it.
 * - recent: the table's last numbers, newest first, [{number, color}]
 *
 * @param array{round: int, closesAt: ?int, number: ?int, spunAt: ?int, version: int} $table
 */
function hbHubRouletteTableView(PDO $db, array $table, int $userId, int $now): array
{
    $phase = hbHubRoulettePhase($table, $now);

    $players = [];
    if ($table['number'] === null) {
        $statement = $db->prepare('SELECT userId, betBets, betWagered, betReady FROM `HBHub-RouletteBets`
            WHERE betRound = ? ORDER BY userId = ? DESC, betTimestamp, userId');
        $statement->execute([$table['round'], $userId]);
        $players = $statement->fetchAll();
    }

    // The last round spun: this one when it has been, else the one before (if there's been one)
    $lastRound = $table['number'] !== null ? $table['round'] : $table['round'] - 1;
    $spins = [];
    if ($lastRound >= 1) {
        $statement = $db->prepare('SELECT userId, spinNumber, spinBets, spinWagered, spinPayout, spinNet
            FROM `HBHub-RouletteSpins` WHERE spinRound = ? ORDER BY userId = ? DESC, spinNet DESC, spinId');
        $statement->execute([$lastRound, $userId]);
        $spins = $statement->fetchAll();
    }
    $lastNumber = $lastRound === $table['round'] ? $table['number'] : ($spins !== [] ? (int) $spins[0]['spinNumber'] : null);

    $people = hbHubRoulettePeople($db, [...array_map('intval', array_column($players, 'userId')),
        ...array_map('intval', array_column($spins, 'userId'))]);
    $person = static function (int $id) use ($people, $userId): array {
        return ['id' => $id, 'name' => $people[$id]['name'] ?? 'Deleted User', 'avatar' => $people[$id]['avatar'] ?? null, 'me' => $id === $userId];
    };
    $decode = static fn (string $json): array => is_array($bets = json_decode($json, true)) ? $bets : [];

    $statement = $db->prepare('SELECT spinRound, MIN(spinNumber) AS spinNumber FROM `HBHub-RouletteSpins`
        WHERE spinRound IS NOT NULL GROUP BY spinRound ORDER BY spinRound DESC LIMIT ' . HBHUB_ROULETTE_RECENT);
    $statement->execute();
    $recent = array_map(static fn (array $row): array => [
        'number' => (int) $row['spinNumber'],
        'color' => hbHubRouletteColor((int) $row['spinNumber']),
    ], $statement->fetchAll());
    if ($lastNumber !== null && $spins === []) {
        // A spin where everyone's bets were taken off isn't in the log, but the ball still landed
        array_unshift($recent, ['number' => $lastNumber, 'color' => hbHubRouletteColor($lastNumber)]);
        $recent = array_slice($recent, 0, HBHUB_ROULETTE_RECENT);
    }

    return [
        'round' => $table['number'] === null ? $table['round'] : $table['round'] + 1,
        'phase' => $phase,
        'closesIn' => $phase === 'betting' ? max(0, $table['closesAt'] - $now) : null,
        'spunAgo' => $table['number'] !== null ? $now - $table['spunAt'] : null,
        'betMs' => HBHUB_ROULETTE_BET_MS,
        'spinMs' => HBHUB_ROULETTE_SPIN_MS,
        'resultMs' => HBHUB_ROULETTE_RESULT_MS,
        'players' => array_map(static fn (array $row): array => $person((int) $row['userId']) + [
            'bets' => (object) $decode($row['betBets']), // {} also with no bets, or only one on 0 (which would be a list)
            'wagered' => (int) $row['betWagered'],
            'ready' => (int) $row['betReady'] === 1,
        ], $players),
        'last' => $lastNumber === null ? null : [
            'round' => $lastRound,
            'number' => $lastNumber,
            'color' => hbHubRouletteColor($lastNumber),
            'players' => array_map(static function (array $row) use ($person, $decode, $lastNumber): array {
                $bets = $decode($row['spinBets']);
                return $person((int) $row['userId']) + [
                    'bets' => (object) $bets,
                    'wagered' => (int) $row['spinWagered'],
                    'payout' => (int) $row['spinPayout'],
                    'net' => (int) $row['spinNet'],
                    'wins' => (object) hbHubRouletteResolve($bets, $lastNumber)['wins'],
                ];
            }, $spins),
        ],
        'recent' => $recent,
    ];
}

/**
 * How many people are playing Roulette right now: with bets on the table, or on a spin in the last
 * HBHUB_ROULETTE_PLAYING_SECONDS (for the casino home).
 */
function hbHubRoulettePlayingNow(PDO $db): int
{
    $statement = $db->query('SELECT COUNT(DISTINCT userId) FROM (
            SELECT userId FROM `HBHub-RouletteBets`
            UNION ALL
            SELECT userId FROM `HBHub-RouletteSpins`
            WHERE spinRound IS NOT NULL AND spinTimestamp > UTC_TIMESTAMP() - INTERVAL ' . HBHUB_ROULETTE_PLAYING_SECONDS . ' SECOND
        ) p');
    return (int) $statement->fetchColumn();
}
