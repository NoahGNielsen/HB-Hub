<?php
// Chess games in the database: opening and joining them, who sits where, invites, and loading and saving the game
// (HBHub-ChessRooms, HBHub-ChessSeats and HBHub-ChessInvites). The game itself is played in game.php. Built the same way
// as Matador's rooms (games/matador/room.php), for two players and without saves.
//
// Who can join (hbHubChessJoin()): only while the room waits for a second player. Anyone a public game, anyone with
// its code a code game, and only invited users an invite-only game (anyone invited can join any game). Every game has
// a code, whatever its type. The game starts as soon as the second player sits down.
//
// Leaving a game that's being played loses it (or aborts it, before both players have moved). A player whose game
// hasn't checked in for HBHUB_CHESS_CLAIM_SECONDS can be beaten by the other one (game.php's hbHubChessClaim()), and
// after HBHUB_CHESS_PLAYING_GRACE_SECONDS they leave on their own. The one left waits for a new player in the same room.
//
// Everything that changes a game goes through hbHubChessPlay(), which locks the room's row first, so only one request
// at a time moves a game on, whatever the players click at the same time. hbHubChessSweep() tidies up rooms nobody has
// open anymore.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/../../userAvatar.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/results.php';

const HBHUB_CHESS_ROOM_PUBLIC = 1; // roomType
const HBHUB_CHESS_ROOM_INVITE = 2;
const HBHUB_CHESS_ROOM_CODE = 3;
const HBHUB_CHESS_ROOM_TYPES = [ // as the forms and chessGame.js name them
    HBHUB_CHESS_ROOM_PUBLIC => 'public',
    HBHUB_CHESS_ROOM_INVITE => 'invite',
    HBHUB_CHESS_ROOM_CODE => 'code',
];
const HBHUB_CHESS_ROOM_TYPE_NAMES = [
    HBHUB_CHESS_ROOM_PUBLIC => 'Public game',
    HBHUB_CHESS_ROOM_INVITE => 'Invite-only game',
    HBHUB_CHESS_ROOM_CODE => 'Game with a code',
];
const HBHUB_CHESS_PHASES = ['lobby' => 1, 'playing' => 2, 'ended' => 3]; // roomPhase
const HBHUB_CHESS_SEATS = 2;
const HBHUB_CHESS_ROOM_NAME_MAX = 48; // roomName column
const HBHUB_CHESS_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_CHESS_CODE_LENGTH = 6;
const HBHUB_CHESS_AWAY_SECONDS = 10; // a game that hasn't checked in for this long counts as away
const HBHUB_CHESS_GRACE_SECONDS = 60; // and after this long the player has left a room that isn't playing
const HBHUB_CHESS_PLAYING_GRACE_SECONDS = 600; // or one that's playing (the other player can claim the win long before)
const HBHUB_CHESS_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp, as the game checks in every second
const HBHUB_CHESS_SWEEP_ROOMS = 10; // the most rooms hbHubChessSweep() tidies at once
const HBHUB_CHESS_LOBBY_ROOMS = 50; // the most public games in the lobby
const HBHUB_CHESS_LOBBY_INVITES = 20;
const HBHUB_CHESS_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * The game the user sits at, or null when they aren't in one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubChessUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-ChessSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Opens a new game with the user as its host, sitting in seat 1, and returns its id. Throws a PDOException with the
 * duplicate key error (1062) when the user already sits in a game.
 */
function hbHubChessCreateRoom(PDO $db, int $userId, string $userName, string $name, int $type, string $clock, string $colour): int
{
    $state = hbHubChessNewState($clock, $colour);
    $state['players'][] = hbHubChessNewPlayer(1, $userId, $userName);

    $db->beginTransaction();
    try {
        $roomId = hbHubChessInsertRoom($db, $name, $type, $state, $userId);
        hbHubChessInsertSeat($db, $roomId, $userId, 1);
        $db->commit();
        return $roomId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Adds the room (in a transaction the caller started), with $userId as its host, and returns its id. A code another
 * room already has is just tried again with another one - InnoDB only undoes the failed INSERT, not the transaction.
 */
function hbHubChessInsertRoom(PDO $db, string $name, int $type, array $state, int $userId): int
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $db->prepare('INSERT INTO `HBHub-ChessRooms` (roomName, roomType, roomCode, roomHostUserId, roomPhase, roomState, roomCreatedTimestamp)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$name, $type, hbHubChessNewCode(), $userId, HBHUB_CHESS_PHASES[$state['phase']], json_encode($state, JSON_THROW_ON_ERROR)]);
            return (int) $db->lastInsertId();
        } catch (PDOException $e) {
            $isCodeTaken = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'roomCode');
            if (!$isCodeTaken || $attempt >= 5) {
                throw $e;
            }
        }
    }
}

function hbHubChessInsertSeat(PDO $db, int $roomId, int $userId, int $seat): void
{
    $db->prepare('INSERT INTO `HBHub-ChessSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
        VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$roomId, $userId, $seat]);
}

function hbHubChessNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_CHESS_CODE_LENGTH; $i++) {
        $code .= HBHUB_CHESS_CODE_LETTERS[random_int(0, strlen(HBHUB_CHESS_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * A join code as typed in: any case, spaces and dashes left out. Null when it can't be one of the codes.
 */
function hbHubChessCleanCode(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    return strlen($code) === HBHUB_CHESS_CODE_LENGTH && strspn($code, HBHUB_CHESS_CODE_LETTERS) === HBHUB_CHESS_CODE_LENGTH
        ? $code
        : null;
}

/**
 * The name for a new game as typed in, on one line and cut to fit. Without one it's "Alice's game".
 */
function hbHubChessRoomName(mixed $name, string $userName): string
{
    $name = is_string($name) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $name) ?? '') : '';
    if ($name === '') {
        $suffix = '\'s game';
        return mb_substr($userName, 0, HBHUB_CHESS_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
    return mb_substr($name, 0, HBHUB_CHESS_ROOM_NAME_MAX);
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubChessPlay() does that.
 */
function hbHubChessRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomHostUserId, roomRevision, roomState
        FROM `HBHub-ChessRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$roomId]);
    $room = $statement->fetch();
    if ($room === false) {
        return null;
    }

    $room['roomId'] = (int) $room['roomId'];
    $room['roomType'] = (int) $room['roomType'];
    $room['roomHostUserId'] = $room['roomHostUserId'] !== null ? (int) $room['roomHostUserId'] : null;
    $room['roomRevision'] = (int) $room['roomRevision'];
    $room['state'] = json_decode($room['roomState'], true, 512, JSON_THROW_ON_ERROR);
    unset($room['roomState']);
    return $room;
}

/**
 * The room the code is for, or null when there's none.
 */
function hbHubChessRoomIdByCode(PDO $db, string $code): ?int
{
    $statement = $db->prepare('SELECT roomId FROM `HBHub-ChessRooms` WHERE roomCode = ?');
    $statement->execute([$code]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false ? (int) $roomId : null;
}

/**
 * Who sits in the game, in seat order (and how long since their game last checked in).
 *
 * @return list<array{userId: int, seat: int, away: bool, idleSeconds: int}>
 */
function hbHubChessSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-ChessSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_CHESS_AWAY_SECONDS,
        'idleSeconds' => (int) $seat['idleSeconds'],
    ], $statement->fetchAll());
}

function hbHubChessSeated(array $seats, int $userId): bool
{
    return in_array($userId, array_column($seats, 'userId'), true);
}

/**
 * Notes that the user's game is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubChessTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-ChessSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_CHESS_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * How long a player can be gone before they've left the room: longer while a game is being played.
 */
function hbHubChessGraceSeconds(array $state): int
{
    return $state['phase'] === 'playing' ? HBHUB_CHESS_PLAYING_GRACE_SECONDS : HBHUB_CHESS_GRACE_SECONDS;
}

/**
 * Locks the room, moves it on (a clock that has run out ends the game, players gone for the grace period leave), runs
 * $action on it (if any), stores the result of a game that just ended, and saves it when anything changed. A room
 * nobody is left in is deleted. Returns [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats): mixed.
 * It can change both - hbHubChessRemoveSeat() takes a player out of $seats, hbHubChessJoin() adds one.
 */
function hbHubChessPlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubChessRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubChessSeats($db, $roomId);
            $before = [$room['state'], $room['roomHostUserId']];
            $now = hbHubChessNowMs();

            hbHubChessAdvance($room['state'], $now); // out of time before anything else
            $grace = hbHubChessGraceSeconds($room['state']);
            $gone = array_filter($seats, static fn (array $seat): bool => $seat['idleSeconds'] >= $grace);
            if ($gone !== [] && count($gone) === count($seats) && $room['state']['phase'] === 'playing') {
                hbHubChessEnd($room['state'], null, 'stopped', $now); // both gone: neither of them loses for it
            }
            foreach ($gone as $seat) {
                hbHubChessRemoveSeat($db, $room, $seats, $seat['userId'], true);
            }

            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats);
                hbHubChessAdvance($room['state'], hbHubChessNowMs());
            }
            hbHubChessRecordResults($db, $room); // for the leaderboard, once a game is over

            if ($seats === []) {
                $db->prepare('DELETE FROM `HBHub-ChessRooms` WHERE roomId = ?')->execute([$roomId]);
                $room['isDeleted'] = true;
            } else {
                if (!hbHubChessSeated($seats, (int) $room['roomHostUserId'])) {
                    $room['roomHostUserId'] = $seats[0]['userId']; // the host's account is gone, or they never left properly
                }
                if ([$room['state'], $room['roomHostUserId']] !== $before) {
                    $db->prepare('UPDATE `HBHub-ChessRooms` SET roomPhase = ?, roomState = ?, roomHostUserId = ?, roomRevision = roomRevision + 1
                        WHERE roomId = ?')
                        ->execute([
                            HBHUB_CHESS_PHASES[$room['state']['phase']],
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
 * Whether just looking at the game has to go through hbHubChessPlay(), as there's something to move on: someone has
 * been gone for the grace period, the host isn't in the room, or the player to move has run out of time.
 */
function hbHubChessNeedsPlay(array $room, array $seats): bool
{
    if (!hbHubChessSeated($seats, (int) $room['roomHostUserId']) || hbHubChessTimeUp($room['state'], hbHubChessNowMs())) {
        return true;
    }
    $grace = hbHubChessGraceSeconds($room['state']);
    foreach ($seats as $seat) {
        if ($seat['idleSeconds'] >= $grace) {
            return true;
        }
    }
    return false;
}

/**
 * The game's version for chessGame.js, which only gets the whole game again when it's different: it changes with the
 * room's state (roomRevision), the chat (its newest message, chat.php), and with who sits where, who's away and who
 * the host is. It starts with "revision-newest message-", which only go up, so chessGame.js can tell older answers.
 */
function hbHubChessVersion(array $room, array $seats, int $lastChatId): string
{
    $seated = array_map(static fn (array $seat): array => [$seat['userId'], $seat['seat'], $seat['away']], $seats);
    return $room['roomRevision'] . '-' . $lastChatId . '-' . hash('crc32b', json_encode([$seated, $room['roomHostUserId']]));
}

/**
 * Sits the user down in the game, when they may (see the top of this file), and starts it when they're the second
 * player. $hasCode says they typed in its code. Returns null when they sat down, otherwise why not.
 */
function hbHubChessJoin(PDO $db, int $userId, string $userName, int $roomId, bool $hasCode): ?string
{
    if (hbHubChessUserSeat($db, $userId) !== null) {
        return 'You\'re already in a chess game. Leave it first.';
    }

    try {
        $played = hbHubChessPlay($db, $roomId, function (array &$room, array &$seats) use ($db, $userId, $userName, $hasCode): ?string {
            $state = &$room['state'];
            if ($state['phase'] !== 'lobby') {
                return 'That game has already started.';
            }
            $type = $room['roomType'];
            $mayJoin = hbHubChessIsInvited($db, $room['roomId'], $userId)
                || $type === HBHUB_CHESS_ROOM_PUBLIC
                || ($type === HBHUB_CHESS_ROOM_CODE && $hasCode);
            if (!$mayJoin) {
                return $type === HBHUB_CHESS_ROOM_INVITE
                    ? 'That game is invite-only. Ask its host to invite you.'
                    : 'That game can only be joined with its code.';
            }
            $taken = array_column($state['players'], 'seat');
            $free = array_values(array_diff(range(1, HBHUB_CHESS_SEATS), $taken));
            if ($free === []) {
                return 'That game is full.';
            }

            $state['players'][] = hbHubChessNewPlayer($free[0], $userId, $userName);
            usort($state['players'], static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            hbHubChessInsertSeat($db, $room['roomId'], $userId, $free[0]);
            $seats[] = ['userId' => $userId, 'seat' => $free[0], 'away' => false, 'idleSeconds' => 0];
            usort($seats, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);

            if (count($state['players']) === HBHUB_CHESS_SEATS) {
                hbHubChessBegin($state, $room['roomHostUserId']);
            }
            return null;
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return 'You\'re already in a chess game. Leave it first.'; // sat down in another tab meanwhile
        }
        throw $e;
    }

    return $played !== null ? $played[2] : 'That game isn\'t there anymore.';
}

/**
 * Takes the user out of the room. Leaving a game that's being played loses it, or aborts it before both players have
 * moved. The player left waits in the room for someone new (the last game stays on the board until then). When the
 * host leaves, the other player becomes the host. $isGone says they didn't leave themselves, their game just stopped
 * checking in.
 */
function hbHubChessRemoveSeat(PDO $db, array &$room, array &$seats, int $userId, bool $isGone = false): void
{
    $db->prepare('DELETE FROM `HBHub-ChessSeats` WHERE roomId = ? AND userId = ?')->execute([$room['roomId'], $userId]);
    $seats = array_values(array_filter($seats, static fn (array $other): bool => $other['userId'] !== $userId));

    $state = &$room['state'];
    $seat = hbHubChessSeatOfUser($state, $userId);
    if ($seat === null) {
        return;
    }
    $name = '';
    foreach ($state['players'] as $player) {
        if ($player['seat'] === $seat) {
            $name = $player['name'];
        }
    }

    $colour = hbHubChessColourOfUser($state, $userId);
    if ($state['phase'] === 'playing' && $colour !== null) {
        if (count($state['game']['moves']) < HBHUB_CHESS_ABORT_MOVES) {
            hbHubChessEnd($state, null, 'aborted', hbHubChessNowMs());
        } else {
            hbHubChessEnd($state, hbHubChessOpponent($colour), 'left', hbHubChessNowMs());
        }
    }
    hbHubChessRecordResults($db, $room); // before the room waits for someone new

    $state['players'] = array_values(array_filter($state['players'], static fn (array $player): bool => $player['seat'] !== $seat));
    if ($state['phase'] !== 'lobby') {
        $state['phase'] = 'lobby';
        $state['note'] = $name . ($isGone ? ' was gone too long, and has left.' : ' left.');
    }
    unset($state);

    if ($room['roomHostUserId'] === $userId) {
        $room['roomHostUserId'] = $seats[0]['userId'] ?? null;
    }
}

/**
 * Takes the user out of their game, if they're in one (hbHubChessRemoveSeat()).
 */
function hbHubChessLeave(PDO $db, int $userId): void
{
    $seat = hbHubChessUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubChessPlay($db, $seat['roomId'], function (array &$room, array &$seats) use ($db, $userId): void {
        hbHubChessRemoveSeat($db, $room, $seats, $userId);
    });
}

function hbHubChessIsInvited(PDO $db, int $roomId, int $userId): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-ChessInvites` WHERE roomId = ? AND userId = ?');
    $statement->execute([$roomId, $userId]);
    return $statement->fetchColumn() !== false;
}

/**
 * The host invites $userId (as sent) while the room waits for a player: they get a notification, and can join it from
 * the chess page, also when it's invite-only. Returns null when they're invited, otherwise why not.
 */
function hbHubChessInvite(PDO $db, array $room, int $hostId, mixed $userId): ?string
{
    if ($room['roomHostUserId'] !== $hostId) {
        return 'Only the host can invite people.';
    }
    if ($room['state']['phase'] !== 'lobby') {
        return 'The game has started already.';
    }

    $statement = $db->prepare('SELECT userName FROM `HBHub-Users` WHERE userId = ? AND userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([is_string($userId) && ctype_digit($userId) && strlen($userId) <= 10 ? (int) $userId : 0, HBHUB_CHESS_STATUS_BANNED]);
    $name = $statement->fetchColumn();
    if ($name === false) {
        return 'That user can\'t be found.';
    }
    $userId = (int) $userId;
    if (hbHubChessSeatOfUser($room['state'], $userId) !== null) {
        return $name . ' is already in the game.';
    }
    if (hbHubChessIsInvited($db, $room['roomId'], $userId)) {
        return $name . ' is already invited.';
    }

    $db->prepare('INSERT INTO `HBHub-ChessInvites` (roomId, userId, invitedByUserId, inviteTimestamp) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$room['roomId'], $userId, $hostId]);
    hbHubNotify($db, $userId, 'games.chessInvite', $hostId, ['roomId' => $room['roomId'], 'roomName' => $room['roomName']]);
    return null;
}

/**
 * Tidies up rooms nobody has open anymore: players who haven't been seen for the grace period leave, and rooms left
 * empty are deleted. A room someone has open does this itself on every check-in (hbHubChessPlay()); this is for the
 * rest, run from the lobby a few rooms at a time.
 */
function hbHubChessSweep(PDO $db): void
{
    $stale = $db->prepare('SELECT DISTINCT s.roomId FROM `HBHub-ChessSeats` s
        JOIN `HBHub-ChessRooms` r ON r.roomId = s.roomId
        WHERE s.seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL IF(r.roomPhase = ?, ' . HBHUB_CHESS_PLAYING_GRACE_SECONDS . ', '
            . HBHUB_CHESS_GRACE_SECONDS . ') SECOND
        LIMIT ' . HBHUB_CHESS_SWEEP_ROOMS);
    $stale->execute([HBHUB_CHESS_PHASES['playing']]);
    $empty = $db->query('SELECT r.roomId FROM `HBHub-ChessRooms` r
        WHERE NOT EXISTS (SELECT 1 FROM `HBHub-ChessSeats` s WHERE s.roomId = r.roomId)
        LIMIT ' . HBHUB_CHESS_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_unique([...$stale->fetchAll(PDO::FETCH_COLUMN), ...$empty]) as $roomId) {
        try {
            hbHubChessPlay($db, (int) $roomId);
        } catch (PDOException $e) {
            error_log('Chess: could not tidy room ' . $roomId . ': ' . $e->getMessage()); // the next lobby visit tries again
        }
    }
}

/**
 * The public games waiting for a player, for the lobby: the newest first.
 *
 * @return list<array{id: int, name: string, host: ?string, clock: string, colour: string}>
 */
function hbHubChessPublicRooms(PDO $db): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, u.userName AS hostName,
            r.roomState->>\'$.clock\' AS clock, r.roomState->>\'$.colour\' AS colour
        FROM `HBHub-ChessRooms` r
        LEFT JOIN `HBHub-Users` u ON u.userId = r.roomHostUserId
        WHERE r.roomType = ? AND r.roomPhase = ?
            AND (SELECT COUNT(*) FROM `HBHub-ChessSeats` s WHERE s.roomId = r.roomId) BETWEEN 1 AND ?
        ORDER BY r.roomId DESC
        LIMIT ' . HBHUB_CHESS_LOBBY_ROOMS);
    $statement->execute([HBHUB_CHESS_ROOM_PUBLIC, HBHUB_CHESS_PHASES['lobby'], HBHUB_CHESS_SEATS - 1]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'host' => $room['hostName'],
        'clock' => HBHUB_CHESS_CLOCK_NAMES[$room['clock']] ?? '',
        'colour' => $room['colour'],
    ], $statement->fetchAll());
}

/**
 * The games the user has been invited to that are waiting for a player (and they aren't in). The newest invite first.
 *
 * @return list<array{id: int, name: string, type: string, invitedBy: ?string, clock: string, colour: string}>
 */
function hbHubChessInvitesFor(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, r.roomType, u.userName AS invitedBy,
            r.roomState->>\'$.clock\' AS clock, r.roomState->>\'$.colour\' AS colour
        FROM `HBHub-ChessInvites` i
        JOIN `HBHub-ChessRooms` r ON r.roomId = i.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = i.invitedByUserId
        WHERE i.userId = ? AND r.roomPhase = ?
            AND NOT EXISTS (SELECT 1 FROM `HBHub-ChessSeats` s WHERE s.roomId = r.roomId AND s.userId = ?)
        ORDER BY i.inviteTimestamp DESC
        LIMIT ' . HBHUB_CHESS_LOBBY_INVITES);
    $statement->execute([$userId, HBHUB_CHESS_PHASES['lobby'], $userId]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'type' => HBHUB_CHESS_ROOM_TYPES[(int) $room['roomType']] ?? 'public',
        'invitedBy' => $room['invitedBy'],
        'clock' => HBHUB_CHESS_CLOCK_NAMES[$room['clock']] ?? '',
        'colour' => $room['colour'],
    ], $statement->fetchAll());
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubChessPeople(PDO $db, array $userIds): array
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
