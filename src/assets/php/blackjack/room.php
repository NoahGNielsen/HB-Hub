<?php
// Blackjack rooms (tables) in the database: making them, who sits where, and loading and saving the hand being played
// (HBHub-BlackjackRooms and HBHub-BlackjackSeats). The hands themselves are played in round.php.
//
// Everything that changes a table goes through hbHubBlackjackPlay(), which locks the room's row first. So only one
// request at a time moves a table on, whatever the players click at the same time.
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../userAvatar.php';
require_once __DIR__ . '/round.php';

const HBHUB_BLACKJACK_ROOM_TYPES = [1 => 'public', 2 => 'invite', 3 => 'code', 4 => 'solo']; // roomType
const HBHUB_BLACKJACK_ROOM_SOLO = 4;
const HBHUB_BLACKJACK_SEATS = 5; // a solo table has 1
const HBHUB_BLACKJACK_ROOM_NAME_MAX = 48; // roomName column
const HBHUB_BLACKJACK_MIN_BETS = [10, 50, 100, 500]; // what a room's minimum bet can be
const HBHUB_BLACKJACK_SOLO_MIN_BET = 10;
const HBHUB_BLACKJACK_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_BLACKJACK_CODE_LENGTH = 6;
const HBHUB_BLACKJACK_AWAY_SECONDS = 10; // a table that hasn't checked in for this long counts as away
const HBHUB_BLACKJACK_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp, as the table checks in every second

/**
 * Now, as Unix time in milliseconds (the timers in a table's state).
 */
function hbHubBlackjackNow(): int
{
    return (int) floor(microtime(true) * 1000);
}

function hbHubBlackjackMaxSeats(int $type): int
{
    return $type === HBHUB_BLACKJACK_ROOM_SOLO ? 1 : HBHUB_BLACKJACK_SEATS;
}

/**
 * The table the user sits at, or null when they aren't at one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubBlackjackUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-BlackjackSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Makes a room with the user as its host, sitting in seat 1, and returns its id. Throws a PDOException with the
 * duplicate key error (1062) when the user already sits at a table.
 */
function hbHubBlackjackCreateRoom(PDO $db, int $userId, string $name, int $type, int $minBet): int
{
    $state = json_encode(hbHubBlackjackNewState(), JSON_THROW_ON_ERROR);

    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO `HBHub-BlackjackRooms` (roomName, roomType, roomCode, roomMinBet, roomHostUserId, roomState, roomCreatedTimestamp)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$name, $type, hbHubBlackjackNewCode(), $minBet, $userId, $state]);
            $roomId = (int) $db->lastInsertId();

            $db->prepare('INSERT INTO `HBHub-BlackjackSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
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

function hbHubBlackjackNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_BLACKJACK_CODE_LENGTH; $i++) {
        $code .= HBHUB_BLACKJACK_CODE_LETTERS[random_int(0, strlen(HBHUB_BLACKJACK_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubBlackjackPlay() does that.
 */
function hbHubBlackjackRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomMinBet, roomHostUserId, roomRevision, roomState
        FROM `HBHub-BlackjackRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
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
 * Who sits at the table, in seat order, as round.php wants them.
 *
 * @return list<array{userId: int, seat: int, sittingOut: bool, away: bool}>
 */
function hbHubBlackjackSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, seatSittingOut,
            TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-BlackjackSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'sittingOut' => (bool) $seat['seatSittingOut'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_BLACKJACK_AWAY_SECONDS,
    ], $statement->fetchAll());
}

/**
 * Notes that the user's table is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubBlackjackTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-BlackjackSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_BLACKJACK_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * Locks the room, moves it on for every timer that ran out, runs $action on it (if any) and saves it when anything
 * changed. Returns [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats, int $now): mixed.
 * It can change both - a player leaving takes their seat out of $seats - and set $room['isDeleted'] to delete the room.
 * Its chip changes (hbHubCasinoChange()) are part of the same transaction, so the room and the chips change together.
 */
function hbHubBlackjackPlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubBlackjackRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubBlackjackSeats($db, $roomId);
            $now = hbHubBlackjackNow();
            $before = $room['state'];

            hbHubBlackjackAdvance($db, $room, $seats, $now);
            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats, $now);
                hbHubBlackjackAdvance($db, $room, $seats, $now); // what it set off, like the deal once everyone has bet
            }

            if (!empty($room['isDeleted'])) {
                $db->prepare('DELETE FROM `HBHub-BlackjackRooms` WHERE roomId = ?')->execute([$roomId]);
            } elseif ($room['state'] !== $before) {
                $db->prepare('UPDATE `HBHub-BlackjackRooms` SET roomPhase = ?, roomDeadline = ?, roomState = ?, roomRevision = roomRevision + 1
                    WHERE roomId = ?')
                    ->execute([
                        HBHUB_BLACKJACK_PHASES[$room['state']['phase']],
                        $room['state']['deadline'],
                        json_encode($room['state'], JSON_THROW_ON_ERROR),
                        $roomId,
                    ]);
                $room['roomRevision']++;
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
 * Takes the user away from their table, if they sit at one. Their bet on the next hand is given back, and their
 * hands in the hand being played stand when their turn comes (and are paid out). When nobody is left at the table,
 * its hand is played out straight away and the room is deleted.
 */
function hbHubBlackjackLeave(PDO $db, int $userId): void
{
    $seat = hbHubBlackjackUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubBlackjackPlay($db, $seat['roomId'], function (array &$room, array &$seats, int $now) use ($db, $userId): void {
        $db->prepare('DELETE FROM `HBHub-BlackjackSeats` WHERE userId = ?')->execute([$userId]);
        $seats = array_values(array_filter($seats, static fn (array $seat): bool => $seat['userId'] !== $userId));
        hbHubBlackjackReturnBet($db, $room, $userId, $now);

        if ($seats === []) {
            hbHubBlackjackAdvance($db, $room, $seats, $now, true); // nobody left to wait for
            $room['isDeleted'] = true;
        }
    });
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubBlackjackPeople(PDO $db, array $userIds): array
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
