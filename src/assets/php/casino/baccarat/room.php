<?php
// Baccarat rooms (tables) in the database: making and joining them, who sits where, invites, and loading and saving
// the hand being played (HBHub-BaccaratRooms, HBHub-BaccaratSeats and HBHub-BaccaratInvites). The hands themselves
// are played in round.php. Built the same way as the Blackjack tables (casino/blackjack/room.php), solo tables too.
//
// Who can join (hbHubBaccaratJoin()): anyone a public room, anyone with its code a code room, and only invited users an
// invite-only room (its code alone isn't enough). Anyone invited can join any room. A solo room is just its maker's.
// Every room has a code, whatever its type. The host (who made it, at first) is the one who can invite people.
//
// Everything that changes a table goes through hbHubBaccaratPlay(), which locks the room's row first, so only one
// request at a time moves a table on, whatever the players click at the same time. That's also where players who
// haven't been seen for the grace period leave the table, the host moves on when they've gone, and an empty room is
// deleted. hbHubBaccaratSweep() does the same for tables nobody has open anymore.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/../../userAvatar.php';
require_once __DIR__ . '/round.php';

const HBHUB_BACCARAT_ROOM_PUBLIC = 1; // roomType
const HBHUB_BACCARAT_ROOM_INVITE = 2;
const HBHUB_BACCARAT_ROOM_CODE = 3;
const HBHUB_BACCARAT_ROOM_SOLO = 4;
const HBHUB_BACCARAT_ROOM_TYPES = [ // as the forms and baccaratTable.js name them
    HBHUB_BACCARAT_ROOM_PUBLIC => 'public',
    HBHUB_BACCARAT_ROOM_INVITE => 'invite',
    HBHUB_BACCARAT_ROOM_CODE => 'code',
    HBHUB_BACCARAT_ROOM_SOLO => 'solo',
];
const HBHUB_BACCARAT_ROOM_TYPE_NAMES = [
    HBHUB_BACCARAT_ROOM_PUBLIC => 'Public table',
    HBHUB_BACCARAT_ROOM_INVITE => 'Invite-only table',
    HBHUB_BACCARAT_ROOM_CODE => 'Table with a code',
    HBHUB_BACCARAT_ROOM_SOLO => 'Solo table',
];
const HBHUB_BACCARAT_SEATS = 6; // a solo table has 1
const HBHUB_BACCARAT_ROOM_NAME_MAX = 48; // roomName column
const HBHUB_BACCARAT_MIN_BETS = [10, 50, 100, 500]; // what a room's minimum bet can be
const HBHUB_BACCARAT_SOLO_MIN_BET = 10;
const HBHUB_BACCARAT_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_BACCARAT_CODE_LENGTH = 6;
const HBHUB_BACCARAT_AWAY_SECONDS = 10; // a table that hasn't checked in for this long counts as away
const HBHUB_BACCARAT_GRACE_SECONDS = 60; // and after this long the player has left it (their seat was kept until then)
const HBHUB_BACCARAT_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp, as the table checks in every second
const HBHUB_BACCARAT_SWEEP_ROOMS = 10; // the most rooms hbHubBaccaratSweep() tidies at once
const HBHUB_BACCARAT_LOBBY_ROOMS = 50; // the most public rooms in the lobby
const HBHUB_BACCARAT_LOBBY_INVITES = 20;
const HBHUB_BACCARAT_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * Now, as Unix time in milliseconds (the timers in a table's state).
 */
function hbHubBaccaratNow(): int
{
    return (int) floor(microtime(true) * 1000);
}

function hbHubBaccaratMaxSeats(int $type): int
{
    return $type === HBHUB_BACCARAT_ROOM_SOLO ? 1 : HBHUB_BACCARAT_SEATS;
}

/**
 * The table the user sits at, or null when they aren't at one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubBaccaratUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-BaccaratSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Makes a room with the user as its host, sitting in seat 1, and returns its id. Throws a PDOException with the
 * duplicate key error (1062) when the user already sits at a table.
 */
function hbHubBaccaratCreateRoom(PDO $db, int $userId, string $name, int $type, int $minBet): int
{
    $state = json_encode(hbHubBaccaratNewState(), JSON_THROW_ON_ERROR);

    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO `HBHub-BaccaratRooms` (roomName, roomType, roomCode, roomMinBet, roomHostUserId, roomState, roomCreatedTimestamp)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$name, $type, hbHubBaccaratNewCode(), $minBet, $userId, $state]);
            $roomId = (int) $db->lastInsertId();

            $db->prepare('INSERT INTO `HBHub-BaccaratSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
                VALUES (?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$roomId, $userId]);

            $db->commit();
            return $roomId;
        } catch (PDOException $e) {
            $db->rollBack();
            // Another room already has the code: try another one (31^6, almost 900 million codes, so this is rare)
            $isCodeTaken = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'roomCode');
            if ($isCodeTaken && $attempt < 5) {
                continue;
            }
            throw $e;
        }
    }
}

function hbHubBaccaratNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_BACCARAT_CODE_LENGTH; $i++) {
        $code .= HBHUB_BACCARAT_CODE_LETTERS[random_int(0, strlen(HBHUB_BACCARAT_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * A join code as typed in: any case, spaces and dashes left out. Null when it can't be one of the codes.
 */
function hbHubBaccaratCleanCode(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    return strlen($code) === HBHUB_BACCARAT_CODE_LENGTH && strspn($code, HBHUB_BACCARAT_CODE_LETTERS) === HBHUB_BACCARAT_CODE_LENGTH
        ? $code
        : null;
}

/**
 * The name for a new room as typed in, on one line and cut to fit. Without one it's "Alice's table".
 */
function hbHubBaccaratRoomName(mixed $name, string $userName): string
{
    $name = is_string($name) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $name) ?? '') : '';
    if ($name === '') {
        $suffix = '\'s table';
        return mb_substr($userName, 0, HBHUB_BACCARAT_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
    return mb_substr($name, 0, HBHUB_BACCARAT_ROOM_NAME_MAX);
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubBaccaratPlay() does that.
 */
function hbHubBaccaratRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomMinBet, roomHostUserId, roomRevision, roomState
        FROM `HBHub-BaccaratRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$roomId]);
    $room = $statement->fetch();
    if ($room === false) {
        return null;
    }

    $room['roomId'] = (int) $room['roomId'];
    $room['roomType'] = (int) $room['roomType'];
    $room['roomMinBet'] = (int) $room['roomMinBet'];
    $room['roomHostUserId'] = $room['roomHostUserId'] !== null ? (int) $room['roomHostUserId'] : null;
    $room['roomRevision'] = (int) $room['roomRevision'];
    $room['state'] = json_decode($room['roomState'], true, 512, JSON_THROW_ON_ERROR);
    unset($room['roomState']);
    return $room;
}

/**
 * The room the code is for, or null when there's none.
 */
function hbHubBaccaratRoomIdByCode(PDO $db, string $code): ?int
{
    $statement = $db->prepare('SELECT roomId FROM `HBHub-BaccaratRooms` WHERE roomCode = ?');
    $statement->execute([$code]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false ? (int) $roomId : null;
}

/**
 * Who sits at the table, in seat order, as round.php wants them (and how long since their table last checked in).
 *
 * @return list<array{userId: int, seat: int, sittingOut: bool, away: bool, idleSeconds: int}>
 */
function hbHubBaccaratSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, seatSittingOut,
            TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-BaccaratSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'sittingOut' => (bool) $seat['seatSittingOut'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_BACCARAT_AWAY_SECONDS,
        'idleSeconds' => (int) $seat['idleSeconds'],
    ], $statement->fetchAll());
}

/**
 * Notes that the user's table is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubBaccaratTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-BaccaratSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_BACCARAT_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * Locks the room, moves it on (players gone for the grace period leave, timers that ran out), runs $action on it (if
 * any) and saves it when anything changed. A room nobody is left at plays its hand out straight away, paying out what's
 * owed, and is deleted. Returns [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats, int $now): mixed.
 * It can change both - hbHubBaccaratRemoveSeat() takes a player out of $seats, hbHubBaccaratJoin() adds one.
 * Its chip changes (hbHubCasinoChange()) are part of the same transaction, so the room and the chips change together.
 */
function hbHubBaccaratPlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubBaccaratRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubBaccaratSeats($db, $roomId);
            $now = hbHubBaccaratNow();
            $before = [$room['state'], $room['roomHostUserId']];

            foreach ($seats as $seat) {
                if ($seat['idleSeconds'] >= HBHUB_BACCARAT_GRACE_SECONDS) {
                    hbHubBaccaratRemoveSeat($db, $room, $seats, $seat['userId'], $now);
                }
            }

            hbHubBaccaratAdvance($db, $room, $seats, $now);
            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats, $now);
                hbHubBaccaratAdvance($db, $room, $seats, $now); // what it set off, like the deal once everyone has bet
            }

            if ($seats === []) {
                hbHubBaccaratAdvance($db, $room, $seats, $now, true); // nobody left to wait for
                $db->prepare('DELETE FROM `HBHub-BaccaratRooms` WHERE roomId = ?')->execute([$roomId]);
                $room['isDeleted'] = true;
            } else {
                if (hbHubBaccaratSeatOf($seats, (int) $room['roomHostUserId']) === null) {
                    $room['roomHostUserId'] = $seats[0]['userId']; // the host's account is gone, or they never left properly
                }
                if ([$room['state'], $room['roomHostUserId']] !== $before) {
                    $db->prepare('UPDATE `HBHub-BaccaratRooms` SET roomPhase = ?, roomDeadline = ?, roomState = ?, roomHostUserId = ?,
                            roomRevision = roomRevision + 1
                        WHERE roomId = ?')
                        ->execute([
                            HBHUB_BACCARAT_PHASES[$room['state']['phase']],
                            $room['state']['deadline'],
                            json_encode($room['state'], JSON_THROW_ON_ERROR),
                            $room['roomHostUserId'],
                            $roomId,
                        ]);
                    $room['roomRevision']++;
                }
            }

            $db->commit();
            return [$room, $seats, $result];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Two requests waiting on each other's locks: MySQL gives up on one, which can just try again
            $isDeadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1205, 1213], true);
            if ($isDeadlock && $attempt < 3) {
                continue;
            }
            throw $e;
        }
    }
}

/**
 * Whether just looking at the table has to go through hbHubBaccaratPlay(), as there's something to move on:
 * a timer is running, someone has been gone for the grace period, or the host isn't at the table.
 */
function hbHubBaccaratNeedsPlay(array $room, array $seats): bool
{
    if ($room['state']['deadline'] !== null || hbHubBaccaratSeatOf($seats, (int) $room['roomHostUserId']) === null) {
        return true;
    }
    foreach ($seats as $seat) {
        if ($seat['idleSeconds'] >= HBHUB_BACCARAT_GRACE_SECONDS) {
            return true;
        }
    }
    return false;
}

/**
 * The table's version for baccaratTable.js, which only gets the whole table again when it's different: it changes
 * with the room's state (roomRevision), and with who sits where, who's away or sitting out and who the host is.
 */
function hbHubBaccaratVersion(array $room, array $seats): string
{
    $seated = array_map(static fn (array $seat): array => [$seat['userId'], $seat['seat'], $seat['away'], $seat['sittingOut']], $seats);
    return $room['roomRevision'] . '-' . hash('crc32b', json_encode([$seated, $room['roomHostUserId']]));
}

/**
 * Sits the user down at the room, when they may (see the top of this file) and there's a seat free.
 * $hasCode says they typed in its code. Returns null when they sat down, otherwise why not.
 */
function hbHubBaccaratJoin(PDO $db, int $userId, int $roomId, bool $hasCode): ?string
{
    if (hbHubBaccaratUserSeat($db, $userId) !== null) {
        return 'You\'re already at a table. Leave it first.';
    }

    try {
        $played = hbHubBaccaratPlay($db, $roomId, function (array &$room, array &$seats, int $now) use ($db, $userId, $hasCode): ?string {
            $type = $room['roomType'];
            if ($type === HBHUB_BACCARAT_ROOM_SOLO) {
                return 'That\'s a solo table, just for the one who opened it.';
            }
            $mayJoin = hbHubBaccaratIsInvited($db, $room['roomId'], $userId)
                || $type === HBHUB_BACCARAT_ROOM_PUBLIC
                || ($type === HBHUB_BACCARAT_ROOM_CODE && $hasCode);
            if (!$mayJoin) {
                return $type === HBHUB_BACCARAT_ROOM_INVITE
                    ? 'That table is invite-only. Ask its host to invite you.'
                    : 'That table can only be joined with its code.';
            }

            $seatNumber = hbHubBaccaratFreeSeat($room, $seats);
            if ($seatNumber === null) {
                return 'That table is full.';
            }
            $db->prepare('INSERT INTO `HBHub-BaccaratSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
                VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$room['roomId'], $userId, $seatNumber]);
            $seats[] = ['userId' => $userId, 'seat' => $seatNumber, 'sittingOut' => false, 'away' => false, 'idleSeconds' => 0];
            usort($seats, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            return null;
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return 'You\'re already at a table. Leave it first.'; // sat down in another tab meanwhile
        }
        throw $e;
    }

    return $played !== null ? $played[2] : 'That table isn\'t there anymore.';
}

/**
 * The first free seat, or null when the table is full. A seat isn't free while someone who left during the hand
 * still has their bets in it - not until that hand is paid out.
 */
function hbHubBaccaratFreeSeat(array $room, array $seats): ?int
{
    $taken = array_column($seats, 'seat');
    $round = $room['state']['round'];
    if ($round !== null && !$round['settled']) {
        $taken = [...$taken, ...array_column($round['players'], 'seat')];
    }

    for ($seat = 1; $seat <= hbHubBaccaratMaxSeats($room['roomType']); $seat++) {
        if (!in_array($seat, $taken, true)) {
            return $seat;
        }
    }
    return null;
}

/**
 * Takes the user away from the table: their seat goes, their bets on the next hand are given back, and when they were
 * the host, the next player round the table takes over. Their bets in the hand being dealt stay, and are paid out like
 * anyone's.
 */
function hbHubBaccaratRemoveSeat(PDO $db, array &$room, array &$seats, int $userId, int $now): void
{
    $seat = hbHubBaccaratSeatOf($seats, $userId);
    if ($seat === null) {
        return;
    }

    $db->prepare('DELETE FROM `HBHub-BaccaratSeats` WHERE roomId = ? AND userId = ?')->execute([$room['roomId'], $userId]);
    $seats = array_values(array_filter($seats, static fn (array $other): bool => $other['userId'] !== $userId));
    hbHubBaccaratReturnBet($db, $room, $userId, $now);

    if ($room['roomHostUserId'] === $userId) {
        $room['roomHostUserId'] = hbHubBaccaratNextHost($seats, $seat['seat']);
    }
}

/**
 * The next player round the table after $afterSeat (the seats are in order), or null when there's nobody.
 */
function hbHubBaccaratNextHost(array $seats, int $afterSeat): ?int
{
    foreach ($seats as $seat) {
        if ($seat['seat'] > $afterSeat) {
            return $seat['userId'];
        }
    }
    return $seats[0]['userId'] ?? null;
}

/**
 * Takes the user away from their table, if they sit at one (hbHubBaccaratRemoveSeat()).
 */
function hbHubBaccaratLeave(PDO $db, int $userId): void
{
    $seat = hbHubBaccaratUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubBaccaratPlay($db, $seat['roomId'], function (array &$room, array &$seats, int $now) use ($db, $userId): void {
        hbHubBaccaratRemoveSeat($db, $room, $seats, $userId, $now);
    });
}

/**
 * Sits the user out (from the next hand on, a hand they're in is dealt as usual) or back in. Sitting out gives back
 * their bets on the next hand. Returns null when it's done, otherwise why not.
 */
function hbHubBaccaratSitOut(PDO $db, array &$room, array &$seats, int $userId, bool $sitOut, int $now): ?string
{
    if ($room['roomType'] === HBHUB_BACCARAT_ROOM_SOLO) {
        return 'There\'s nobody to sit out for at a solo table.';
    }

    $db->prepare('UPDATE `HBHub-BaccaratSeats` SET seatSittingOut = ? WHERE roomId = ? AND userId = ?')
        ->execute([$sitOut ? 1 : 0, $room['roomId'], $userId]);
    foreach ($seats as &$seat) {
        if ($seat['userId'] === $userId) {
            $seat['sittingOut'] = $sitOut;
        }
    }
    unset($seat);

    if ($sitOut) {
        hbHubBaccaratReturnBet($db, $room, $userId, $now);
    }
    return null;
}

/**
 * The host invites $userId (as sent) to the room: they get a notification, and can join it from the lobby, also when
 * it's invite-only. Returns null when they're invited, otherwise why not.
 */
function hbHubBaccaratInvite(PDO $db, array $room, array $seats, int $hostId, mixed $userId): ?string
{
    if ($room['roomType'] === HBHUB_BACCARAT_ROOM_SOLO) {
        return 'Nobody can join a solo table. Open a table for more players from the Baccarat page.';
    }
    if ($room['roomHostUserId'] !== $hostId) {
        return 'Only the host can invite people.';
    }

    $statement = $db->prepare('SELECT userName FROM `HBHub-Users` WHERE userId = ? AND userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([is_string($userId) && ctype_digit($userId) && strlen($userId) <= 10 ? (int) $userId : 0, HBHUB_BACCARAT_STATUS_BANNED]);
    $name = $statement->fetchColumn();
    if ($name === false) {
        return 'That user can\'t be found.';
    }
    $userId = (int) $userId;

    if (hbHubBaccaratSeatOf($seats, $userId) !== null) {
        return $name . ' is already at the table.';
    }
    if (hbHubBaccaratIsInvited($db, $room['roomId'], $userId)) {
        return $name . ' is already invited.';
    }

    $db->prepare('INSERT INTO `HBHub-BaccaratInvites` (roomId, userId, invitedByUserId, inviteTimestamp) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$room['roomId'], $userId, $hostId]);
    hbHubNotify($db, $userId, 'gambling.baccaratInvite', $hostId, ['roomId' => $room['roomId'], 'roomName' => $room['roomName']]);
    return null;
}

function hbHubBaccaratIsInvited(PDO $db, int $roomId, int $userId): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-BaccaratInvites` WHERE roomId = ? AND userId = ?');
    $statement->execute([$roomId, $userId]);
    return $statement->fetchColumn() !== false;
}

/**
 * Tidies up tables nobody has open anymore: players who haven't been seen for the grace period leave (a hand they're
 * in is dealt out and paid), and rooms left empty are deleted. A table someone has open does this itself on every check-in
 * (hbHubBaccaratPlay()); this is for the rest, run from the lobby a few rooms at a time.
 */
function hbHubBaccaratSweep(PDO $db): void
{
    $stale = $db->query('SELECT DISTINCT roomId FROM `HBHub-BaccaratSeats`
        WHERE seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_BACCARAT_GRACE_SECONDS . ' SECOND
        LIMIT ' . HBHUB_BACCARAT_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);
    $empty = $db->query('SELECT r.roomId FROM `HBHub-BaccaratRooms` r
        WHERE NOT EXISTS (SELECT 1 FROM `HBHub-BaccaratSeats` s WHERE s.roomId = r.roomId)
        LIMIT ' . HBHUB_BACCARAT_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_unique([...$stale, ...$empty]) as $roomId) {
        try {
            hbHubBaccaratPlay($db, (int) $roomId);
        } catch (PDOException $e) {
            error_log('Baccarat: could not tidy room ' . $roomId . ': ' . $e->getMessage()); // the next lobby visit tries again
        }
    }
}

/**
 * The public tables for the lobby: ones with a seat free first, the fullest of those first.
 *
 * @return list<array{id: int, name: string, host: ?string, players: int, seats: int, minBet: int, inHand: bool}>
 */
function hbHubBaccaratPublicRooms(PDO $db): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, r.roomMinBet, r.roomPhase, u.userName AS hostName, COUNT(*) AS players
        FROM `HBHub-BaccaratRooms` r
        JOIN `HBHub-BaccaratSeats` s ON s.roomId = r.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = r.roomHostUserId
        WHERE r.roomType = ?
        GROUP BY r.roomId, r.roomName, r.roomMinBet, r.roomPhase, u.userName
        ORDER BY players >= ?, players DESC, r.roomId DESC
        LIMIT ' . HBHUB_BACCARAT_LOBBY_ROOMS);
    $statement->execute([HBHUB_BACCARAT_ROOM_PUBLIC, HBHUB_BACCARAT_SEATS]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'host' => $room['hostName'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_BACCARAT_SEATS,
        'minBet' => (int) $room['roomMinBet'],
        'inHand' => (int) $room['roomPhase'] === HBHUB_BACCARAT_PHASES['dealing'],
    ], $statement->fetchAll());
}

/**
 * The tables the user has been invited to (and doesn't sit at), the newest invite first.
 *
 * @return list<array{id: int, name: string, type: string, invitedBy: ?string, players: int, seats: int, minBet: int}>
 */
function hbHubBaccaratInvitesFor(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, r.roomType, r.roomMinBet, u.userName AS invitedBy,
            (SELECT COUNT(*) FROM `HBHub-BaccaratSeats` s WHERE s.roomId = r.roomId) AS players
        FROM `HBHub-BaccaratInvites` i
        JOIN `HBHub-BaccaratRooms` r ON r.roomId = i.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = i.invitedByUserId
        WHERE i.userId = ? AND NOT EXISTS (SELECT 1 FROM `HBHub-BaccaratSeats` s WHERE s.roomId = r.roomId AND s.userId = ?)
        ORDER BY i.inviteTimestamp DESC
        LIMIT ' . HBHUB_BACCARAT_LOBBY_INVITES);
    $statement->execute([$userId, $userId]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'type' => HBHUB_BACCARAT_ROOM_TYPES[(int) $room['roomType']] ?? 'public',
        'invitedBy' => $room['invitedBy'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_BACCARAT_SEATS,
        'minBet' => (int) $room['roomMinBet'],
    ], $statement->fetchAll());
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubBaccaratPeople(PDO $db, array $userIds): array
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
