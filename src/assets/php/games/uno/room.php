<?php
// UNO games in the database: opening and joining them, who sits where, invites, and loading and saving the game
// (HBHub-UnoRooms, HBHub-UnoSeats and HBHub-UnoInvites). The game itself is played in game.php. Built the same way as
// the chess rooms (games/chess/room.php), for 2-6 players.
//
// Who can join (hbHubUnoJoin()): while no game is being played - before the first one, or between games - and there's
// a free seat. Anyone a public game, anyone with its code a code game, and only invited users an invite-only game
// (anyone invited can join any game). Every game has a code, whatever its type. The host starts the game once at least
// 2 players are in (game.php's hbHubUnoStart()), and a new one when it's over.
//
// Leaving a game that's being played takes the player out of it (game.php's hbHubUnoDropPlayer()), and counts as a
// loss (results.php). A player whose game hasn't checked in for HBHUB_UNO_GRACE_SECONDS leaves on their own; until then
// the turn timer plays their turns for them.
//
// Everything that changes a game goes through hbHubUnoPlay(), which locks the room's row first, so only one request at
// a time moves a game on, whatever the players click at the same time. hbHubUnoSweep() tidies up rooms nobody has open
// anymore.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/../../userAvatar.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/results.php';

const HBHUB_UNO_ROOM_PUBLIC = 1; // roomType
const HBHUB_UNO_ROOM_INVITE = 2;
const HBHUB_UNO_ROOM_CODE = 3;
const HBHUB_UNO_ROOM_TYPES = [ // as the forms and unoGame.js name them
    HBHUB_UNO_ROOM_PUBLIC => 'public',
    HBHUB_UNO_ROOM_INVITE => 'invite',
    HBHUB_UNO_ROOM_CODE => 'code',
];
const HBHUB_UNO_ROOM_TYPE_NAMES = [
    HBHUB_UNO_ROOM_PUBLIC => 'Public game',
    HBHUB_UNO_ROOM_INVITE => 'Invite-only game',
    HBHUB_UNO_ROOM_CODE => 'Game with a code',
];
const HBHUB_UNO_PHASES = ['lobby' => 1, 'playing' => 2, 'ended' => 3]; // roomPhase
const HBHUB_UNO_ROOM_NAME_MAX = 48; // roomName column
const HBHUB_UNO_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_UNO_CODE_LENGTH = 6;
const HBHUB_UNO_AWAY_SECONDS = 10; // a game that hasn't checked in for this long counts as away
const HBHUB_UNO_GRACE_SECONDS = 60; // and after this long the player has left the room
const HBHUB_UNO_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp, as the game checks in every second
const HBHUB_UNO_SWEEP_ROOMS = 10; // the most rooms hbHubUnoSweep() tidies at once
const HBHUB_UNO_LOBBY_ROOMS = 50; // the most public games in the lobby
const HBHUB_UNO_LOBBY_INVITES = 20;
const HBHUB_UNO_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * The game the user sits at, or null when they aren't in one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubUnoUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-UnoSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Opens a new game with the user as its host, sitting in seat 1, and returns its id. It has the default settings,
 * which the host changes in the game. Throws a PDOException with the duplicate key error (1062) when the user already
 * sits in a game.
 */
function hbHubUnoCreateRoom(PDO $db, int $userId, string $userName, string $name, int $type): int
{
    $state = hbHubUnoNewState();
    $state['players'][] = hbHubUnoNewPlayer(1, $userId, $userName);

    $db->beginTransaction();
    try {
        $roomId = hbHubUnoInsertRoom($db, $name, $type, $state, $userId);
        hbHubUnoInsertSeat($db, $roomId, $userId, 1);
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
function hbHubUnoInsertRoom(PDO $db, string $name, int $type, array $state, int $userId): int
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $db->prepare('INSERT INTO `HBHub-UnoRooms` (roomName, roomType, roomCode, roomHostUserId, roomPhase, roomState, roomCreatedTimestamp)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$name, $type, hbHubUnoNewCode(), $userId, HBHUB_UNO_PHASES[$state['phase']], json_encode($state, JSON_THROW_ON_ERROR)]);
            return (int) $db->lastInsertId();
        } catch (PDOException $e) {
            $isCodeTaken = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'roomCode');
            if (!$isCodeTaken || $attempt >= 5) {
                throw $e;
            }
        }
    }
}

function hbHubUnoInsertSeat(PDO $db, int $roomId, int $userId, int $seat): void
{
    $db->prepare('INSERT INTO `HBHub-UnoSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
        VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$roomId, $userId, $seat]);
}

function hbHubUnoNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_UNO_CODE_LENGTH; $i++) {
        $code .= HBHUB_UNO_CODE_LETTERS[random_int(0, strlen(HBHUB_UNO_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * A join code as typed in: any case, spaces and dashes left out. Null when it can't be one of the codes.
 */
function hbHubUnoCleanCode(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    return strlen($code) === HBHUB_UNO_CODE_LENGTH && strspn($code, HBHUB_UNO_CODE_LETTERS) === HBHUB_UNO_CODE_LENGTH
        ? $code
        : null;
}

/**
 * The name for a new game as typed in, on one line and cut to fit. Without one it's "Alice's game".
 */
function hbHubUnoRoomName(mixed $name, string $userName): string
{
    $name = is_string($name) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $name) ?? '') : '';
    if ($name === '') {
        $suffix = '\'s game';
        return mb_substr($userName, 0, HBHUB_UNO_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
    return mb_substr($name, 0, HBHUB_UNO_ROOM_NAME_MAX);
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubUnoPlay() does that.
 */
function hbHubUnoRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomHostUserId, roomRevision, roomState
        FROM `HBHub-UnoRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
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
function hbHubUnoRoomIdByCode(PDO $db, string $code): ?int
{
    $statement = $db->prepare('SELECT roomId FROM `HBHub-UnoRooms` WHERE roomCode = ?');
    $statement->execute([$code]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false ? (int) $roomId : null;
}

/**
 * Who sits in the game, in seat order (and how long since their game last checked in).
 *
 * @return list<array{userId: int, seat: int, away: bool, idleSeconds: int}>
 */
function hbHubUnoSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-UnoSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_UNO_AWAY_SECONDS,
        'idleSeconds' => (int) $seat['idleSeconds'],
    ], $statement->fetchAll());
}

function hbHubUnoSeated(array $seats, int $userId): bool
{
    return in_array($userId, array_column($seats, 'userId'), true);
}

/**
 * Notes that the user's game is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubUnoTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-UnoSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_UNO_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * Locks the room, moves it on (a turn that has run out is played for its player, players gone for the grace period
 * leave), runs $action on it (if any), stores the result of a game that just ended, and saves it when anything changed.
 * A room nobody is left in is deleted. Returns [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats): mixed.
 * It can change both - hbHubUnoRemoveSeat() takes a player out of $seats, hbHubUnoJoin() adds one.
 */
function hbHubUnoPlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubUnoRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubUnoSeats($db, $roomId);
            $before = [$room['state'], $room['roomHostUserId']];

            hbHubUnoAdvance($room['state'], hbHubUnoNowMs());
            $gone = array_filter($seats, static fn (array $seat): bool => $seat['idleSeconds'] >= HBHUB_UNO_GRACE_SECONDS);
            if ($gone !== [] && count($gone) === count($seats) && $room['state']['phase'] === 'playing') {
                hbHubUnoEnd($room['state'], null, 'stopped'); // everyone gone: nobody wins or loses for it
            }
            foreach ($gone as $seat) {
                hbHubUnoRemoveSeat($db, $room, $seats, $seat['userId'], true);
            }

            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats);
                hbHubUnoAdvance($room['state'], hbHubUnoNowMs());
            }
            hbHubUnoRecordResults($db, $room); // for the leaderboard, once a game is over

            if ($seats === []) {
                $db->prepare('DELETE FROM `HBHub-UnoRooms` WHERE roomId = ?')->execute([$roomId]);
                $room['isDeleted'] = true;
            } else {
                if (!hbHubUnoSeated($seats, (int) $room['roomHostUserId'])) {
                    $room['roomHostUserId'] = $seats[0]['userId']; // the host's account is gone, or they never left properly
                }
                if ([$room['state'], $room['roomHostUserId']] !== $before) {
                    $db->prepare('UPDATE `HBHub-UnoRooms` SET roomPhase = ?, roomState = ?, roomHostUserId = ?, roomRevision = roomRevision + 1
                        WHERE roomId = ?')
                        ->execute([
                            HBHUB_UNO_PHASES[$room['state']['phase']],
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
 * Whether just looking at the game has to go through hbHubUnoPlay(), as there's something to move on: someone has been
 * gone for the grace period, the host isn't in the room, or the player whose turn it is has run out of time.
 */
function hbHubUnoNeedsPlay(array $room, array $seats): bool
{
    if (!hbHubUnoSeated($seats, (int) $room['roomHostUserId']) || hbHubUnoTimeUp($room['state'], hbHubUnoNowMs())) {
        return true;
    }
    foreach ($seats as $seat) {
        if ($seat['idleSeconds'] >= HBHUB_UNO_GRACE_SECONDS) {
            return true;
        }
    }
    return false;
}

/**
 * The game's version for unoGame.js, which only gets the whole game again when it's different: it changes with the
 * room's state (roomRevision), the chat (its newest message, chat.php), and with who sits where, who's away and who the
 * host is. It starts with "revision-newest message-", which only go up, so unoGame.js can tell older answers.
 */
function hbHubUnoVersion(array $room, array $seats, int $lastChatId): string
{
    $seated = array_map(static fn (array $seat): array => [$seat['userId'], $seat['seat'], $seat['away']], $seats);
    return $room['roomRevision'] . '-' . $lastChatId . '-' . hash('crc32b', json_encode([$seated, $room['roomHostUserId']]));
}

/**
 * Sits the user down in the game, when they may (see the top of this file). $hasCode says they typed in its code.
 * Returns null when they sat down, otherwise why not.
 */
function hbHubUnoJoin(PDO $db, int $userId, string $userName, int $roomId, bool $hasCode): ?string
{
    if (hbHubUnoUserSeat($db, $userId) !== null) {
        return 'You\'re already in an UNO game. Leave it first.';
    }

    try {
        $played = hbHubUnoPlay($db, $roomId, function (array &$room, array &$seats) use ($db, $userId, $userName, $hasCode): ?string {
            $state = &$room['state'];
            if ($state['phase'] === 'playing') {
                return 'That game is being played. You can join once it\'s over.';
            }
            $type = $room['roomType'];
            $mayJoin = hbHubUnoIsInvited($db, $room['roomId'], $userId)
                || $type === HBHUB_UNO_ROOM_PUBLIC
                || ($type === HBHUB_UNO_ROOM_CODE && $hasCode);
            if (!$mayJoin) {
                return $type === HBHUB_UNO_ROOM_INVITE
                    ? 'That game is invite-only. Ask its host to invite you.'
                    : 'That game can only be joined with its code.';
            }
            $free = array_values(array_diff(range(1, HBHUB_UNO_MAX_PLAYERS), array_column($state['players'], 'seat')));
            if ($free === []) {
                return 'That game is full.';
            }

            $state['players'][] = hbHubUnoNewPlayer($free[0], $userId, $userName);
            usort($state['players'], static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            $state['note'] = null;
            hbHubUnoInsertSeat($db, $room['roomId'], $userId, $free[0]);
            $seats[] = ['userId' => $userId, 'seat' => $free[0], 'away' => false, 'idleSeconds' => 0];
            usort($seats, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            return null;
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return 'You\'re already in an UNO game. Leave it first.'; // sat down in another tab meanwhile
        }
        throw $e;
    }

    return $played !== null ? $played[2] : 'That game isn\'t there anymore.';
}

/**
 * Takes the user out of the room. Leaving a game that's being played takes them out of it too (game.php's
 * hbHubUnoDropPlayer()): the others play on, and the last player left in wins. When the host leaves, the next player
 * round the table becomes the host. $isGone says they didn't leave themselves, their game just stopped checking in.
 */
function hbHubUnoRemoveSeat(PDO $db, array &$room, array &$seats, int $userId, bool $isGone = false): void
{
    $db->prepare('DELETE FROM `HBHub-UnoSeats` WHERE roomId = ? AND userId = ?')->execute([$room['roomId'], $userId]);
    $seats = array_values(array_filter($seats, static fn (array $other): bool => $other['userId'] !== $userId));

    $state = &$room['state'];
    $seat = hbHubUnoSeatOfUser($state, $userId);
    if ($seat === null) {
        return;
    }
    $name = '';
    foreach ($state['players'] as $player) {
        if ($player['seat'] === $seat) {
            $name = $player['name'];
        }
    }

    hbHubUnoDropPlayer($state, $userId, $isGone, hbHubUnoNowMs());
    $state['players'] = array_values(array_filter($state['players'], static fn (array $player): bool => $player['seat'] !== $seat));
    if ($state['phase'] !== 'playing') {
        $state['note'] = $name . ($isGone ? ' was gone too long, and has left.' : ' left.');
    }
    unset($state);

    if ($room['roomHostUserId'] === $userId) {
        $room['roomHostUserId'] = hbHubUnoNextHost($seats, $seat);
    }
}

/**
 * The next player round the table after $afterSeat (the seats are in order), or null when there's nobody.
 */
function hbHubUnoNextHost(array $seats, int $afterSeat): ?int
{
    foreach ($seats as $seat) {
        if ($seat['seat'] > $afterSeat) {
            return $seat['userId'];
        }
    }
    return $seats[0]['userId'] ?? null;
}

/**
 * Takes the user out of their game, if they're in one (hbHubUnoRemoveSeat()).
 */
function hbHubUnoLeave(PDO $db, int $userId): void
{
    $seat = hbHubUnoUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubUnoPlay($db, $seat['roomId'], function (array &$room, array &$seats) use ($db, $userId): void {
        hbHubUnoRemoveSeat($db, $room, $seats, $userId);
    });
}

function hbHubUnoIsInvited(PDO $db, int $roomId, int $userId): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-UnoInvites` WHERE roomId = ? AND userId = ?');
    $statement->execute([$roomId, $userId]);
    return $statement->fetchColumn() !== false;
}

/**
 * The host invites $userId (as sent) while there's a free seat: they get a notification, and can join it from the UNO
 * page while no game is being played, also when it's invite-only. Returns null when they're invited, otherwise why not.
 */
function hbHubUnoInvite(PDO $db, array $room, int $hostId, mixed $userId): ?string
{
    if ($room['roomHostUserId'] !== $hostId) {
        return 'Only the host can invite people.';
    }
    if (count($room['state']['players']) >= HBHUB_UNO_MAX_PLAYERS) {
        return 'The game is full.';
    }

    $statement = $db->prepare('SELECT userName FROM `HBHub-Users` WHERE userId = ? AND userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([is_string($userId) && ctype_digit($userId) && strlen($userId) <= 10 ? (int) $userId : 0, HBHUB_UNO_STATUS_BANNED]);
    $name = $statement->fetchColumn();
    if ($name === false) {
        return 'That user can\'t be found.';
    }
    $userId = (int) $userId;
    if (hbHubUnoSeatOfUser($room['state'], $userId) !== null) {
        return $name . ' is already in the game.';
    }
    if (hbHubUnoIsInvited($db, $room['roomId'], $userId)) {
        return $name . ' is already invited.';
    }

    $db->prepare('INSERT INTO `HBHub-UnoInvites` (roomId, userId, invitedByUserId, inviteTimestamp) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$room['roomId'], $userId, $hostId]);
    hbHubNotify($db, $userId, 'games.unoInvite', $hostId, ['roomId' => $room['roomId'], 'roomName' => $room['roomName']]);
    return null;
}

/**
 * Tidies up rooms nobody has open anymore: players who haven't been seen for the grace period leave, and rooms left
 * empty are deleted. A room someone has open does this itself on every check-in (hbHubUnoPlay()); this is for the rest,
 * run from the lobby (and the nightly clean-up) a few rooms at a time.
 */
function hbHubUnoSweep(PDO $db): void
{
    $stale = $db->query('SELECT DISTINCT roomId FROM `HBHub-UnoSeats`
        WHERE seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_UNO_GRACE_SECONDS . ' SECOND
        LIMIT ' . HBHUB_UNO_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);
    $empty = $db->query('SELECT r.roomId FROM `HBHub-UnoRooms` r
        WHERE NOT EXISTS (SELECT 1 FROM `HBHub-UnoSeats` s WHERE s.roomId = r.roomId)
        LIMIT ' . HBHUB_UNO_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_unique([...$stale, ...$empty]) as $roomId) {
        try {
            hbHubUnoPlay($db, (int) $roomId);
        } catch (PDOException $e) {
            error_log('UNO: could not tidy room ' . $roomId . ': ' . $e->getMessage()); // the next lobby visit tries again
        }
    }
}

/**
 * The public games that can be joined (no game being played, and a free seat), for the lobby: the fullest first.
 *
 * @return list<array{id: int, name: string, host: ?string, players: int, seats: int, settings: string}>
 */
function hbHubUnoPublicRooms(PDO $db): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, u.userName AS hostName, r.roomState->>\'$.settings\' AS settings, COUNT(*) AS players
        FROM `HBHub-UnoRooms` r
        JOIN `HBHub-UnoSeats` s ON s.roomId = r.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = r.roomHostUserId
        WHERE r.roomType = ? AND r.roomPhase IN (?, ?)
        GROUP BY r.roomId, r.roomName, u.userName, settings
        HAVING players < ?
        ORDER BY players DESC, r.roomId DESC
        LIMIT ' . HBHUB_UNO_LOBBY_ROOMS);
    $statement->execute([HBHUB_UNO_ROOM_PUBLIC, HBHUB_UNO_PHASES['lobby'], HBHUB_UNO_PHASES['ended'], HBHUB_UNO_MAX_PLAYERS]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'host' => $room['hostName'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_UNO_MAX_PLAYERS,
        'settings' => hbHubUnoSettingsText(hbHubUnoSettings(['settings' => json_decode($room['settings'] ?? 'null', true) ?? []])),
    ], $statement->fetchAll());
}

/**
 * The games the user has been invited to (and isn't in) that can be joined right now. The newest invite first.
 *
 * @return list<array{id: int, name: string, type: string, invitedBy: ?string, players: int, seats: int, settings: string}>
 */
function hbHubUnoInvitesFor(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, r.roomType, u.userName AS invitedBy, r.roomState->>\'$.settings\' AS settings,
            (SELECT COUNT(*) FROM `HBHub-UnoSeats` s WHERE s.roomId = r.roomId) AS players
        FROM `HBHub-UnoInvites` i
        JOIN `HBHub-UnoRooms` r ON r.roomId = i.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = i.invitedByUserId
        WHERE i.userId = ? AND r.roomPhase IN (?, ?)
            AND NOT EXISTS (SELECT 1 FROM `HBHub-UnoSeats` s WHERE s.roomId = r.roomId AND s.userId = ?)
            AND (SELECT COUNT(*) FROM `HBHub-UnoSeats` s WHERE s.roomId = r.roomId) < ?
        ORDER BY i.inviteTimestamp DESC
        LIMIT ' . HBHUB_UNO_LOBBY_INVITES);
    $statement->execute([$userId, HBHUB_UNO_PHASES['lobby'], HBHUB_UNO_PHASES['ended'], $userId, HBHUB_UNO_MAX_PLAYERS]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'type' => HBHUB_UNO_ROOM_TYPES[(int) $room['roomType']] ?? 'public',
        'invitedBy' => $room['invitedBy'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_UNO_MAX_PLAYERS,
        'settings' => hbHubUnoSettingsText(hbHubUnoSettings(['settings' => json_decode($room['settings'] ?? 'null', true) ?? []])),
    ], $statement->fetchAll());
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubUnoPeople(PDO $db, array $userIds): array
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
