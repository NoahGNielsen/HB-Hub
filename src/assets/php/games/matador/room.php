<?php
// Matador games in the database: opening and joining them, who sits where, invites, and loading and saving the game
// (HBHub-MatadorRooms, HBHub-MatadorSeats and HBHub-MatadorInvites). The game itself is played in game.php, and saves
// are in save.php. Built the same way as the Blackjack tables (casino/blackjack/room.php).
//
// Who can join (hbHubMatadorJoin()): before the game starts, anyone a public game, anyone with its code a code game,
// and only invited users an invite-only game (anyone invited can join any game). Once it has started, only to a seat
// that's kept for them: their own, after they left, or one the host gave them. Every game has a code, whatever its type.
//
// Everything that changes a game goes through hbHubMatadorPlay(), which locks the room's row first, so only one request
// at a time moves a game on, whatever the players click at the same time. That's also where players who haven't been
// seen for the grace period leave (their seat is kept for them), and an empty room is deleted. hbHubMatadorSweep() does
// the same for games nobody has open anymore.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/../../userAvatar.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/save.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/results.php';

const HBHUB_MATADOR_ROOM_PUBLIC = 1; // roomType
const HBHUB_MATADOR_ROOM_INVITE = 2;
const HBHUB_MATADOR_ROOM_CODE = 3;
const HBHUB_MATADOR_ROOM_TYPES = [ // as the forms and matadorGame.js name them
    HBHUB_MATADOR_ROOM_PUBLIC => 'public',
    HBHUB_MATADOR_ROOM_INVITE => 'invite',
    HBHUB_MATADOR_ROOM_CODE => 'code',
];
const HBHUB_MATADOR_ROOM_TYPE_NAMES = [
    HBHUB_MATADOR_ROOM_PUBLIC => 'Public game',
    HBHUB_MATADOR_ROOM_INVITE => 'Invite-only game',
    HBHUB_MATADOR_ROOM_CODE => 'Game with a code',
];
const HBHUB_MATADOR_PHASES = ['lobby' => 1, 'resume' => 2, 'playing' => 3, 'ended' => 4]; // roomPhase
const HBHUB_MATADOR_ROOM_NAME_MAX = 48; // roomName and saveName columns
const HBHUB_MATADOR_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_MATADOR_CODE_LENGTH = 6;
const HBHUB_MATADOR_AWAY_SECONDS = 10; // a game that hasn't checked in for this long counts as away
const HBHUB_MATADOR_GRACE_SECONDS = 60; // and after this long the player has left it (their seat is kept for them)
const HBHUB_MATADOR_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp, as the game checks in every second
const HBHUB_MATADOR_SWEEP_ROOMS = 10; // the most rooms hbHubMatadorSweep() tidies at once
const HBHUB_MATADOR_LOBBY_ROOMS = 50; // the most public games in the lobby
const HBHUB_MATADOR_LOBBY_INVITES = 20;
const HBHUB_MATADOR_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * The game the user sits at, or null when they aren't in one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubMatadorUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-MatadorSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Opens a new game with the user as its host and owner (the save goes in their slot), sitting in seat 1, and returns
 * its id. Throws a PDOException with the duplicate key error (1062) when the user already sits in a game.
 */
function hbHubMatadorCreateRoom(PDO $db, int $userId, string $userName, string $name, int $type): int
{
    $state = hbHubMatadorNewState($name, $type);
    $state['players'][] = hbHubMatadorNewPlayer(1, $userId, $userName);

    $db->beginTransaction();
    try {
        $roomId = hbHubMatadorInsertRoom($db, $state, $userId);
        hbHubMatadorInsertSeat($db, $roomId, $userId, 1);
        $db->commit();
        return $roomId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Adds the room for the state (in a transaction the caller started), with $userId as its host and owner, and returns
 * its id. A code another room already has is just tried again with another one (31^6, almost 900 million codes, so
 * this is rare) - InnoDB only undoes the failed INSERT, not the transaction.
 */
function hbHubMatadorInsertRoom(PDO $db, array $state, int $userId): int
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $db->prepare('INSERT INTO `HBHub-MatadorRooms` (roomName, roomType, roomCode, roomHostUserId, roomOwnerUserId, roomPhase, roomState, roomCreatedTimestamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([
                    $state['roomName'],
                    $state['roomType'],
                    hbHubMatadorNewCode(),
                    $userId,
                    $userId,
                    HBHUB_MATADOR_PHASES[$state['phase']],
                    json_encode($state, JSON_THROW_ON_ERROR),
                ]);
            return (int) $db->lastInsertId();
        } catch (PDOException $e) {
            $isCodeTaken = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'roomCode');
            if (!$isCodeTaken || $attempt >= 5) {
                throw $e;
            }
        }
    }
}

function hbHubMatadorInsertSeat(PDO $db, int $roomId, int $userId, int $seat): void
{
    $db->prepare('INSERT INTO `HBHub-MatadorSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
        VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$roomId, $userId, $seat]);
}

function hbHubMatadorNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_MATADOR_CODE_LENGTH; $i++) {
        $code .= HBHUB_MATADOR_CODE_LETTERS[random_int(0, strlen(HBHUB_MATADOR_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * A join code as typed in: any case, spaces and dashes left out. Null when it can't be one of the codes.
 */
function hbHubMatadorCleanCode(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    return strlen($code) === HBHUB_MATADOR_CODE_LENGTH && strspn($code, HBHUB_MATADOR_CODE_LETTERS) === HBHUB_MATADOR_CODE_LENGTH
        ? $code
        : null;
}

/**
 * The name for a new game as typed in, on one line and cut to fit. Without one it's "Alice's game".
 */
function hbHubMatadorRoomName(mixed $name, string $userName): string
{
    $name = is_string($name) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $name) ?? '') : '';
    if ($name === '') {
        $suffix = '\'s game';
        return mb_substr($userName, 0, HBHUB_MATADOR_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
    return mb_substr($name, 0, HBHUB_MATADOR_ROOM_NAME_MAX);
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubMatadorPlay() does that.
 */
function hbHubMatadorRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomHostUserId, roomOwnerUserId, roomRevision, roomState
        FROM `HBHub-MatadorRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$roomId]);
    $room = $statement->fetch();
    if ($room === false) {
        return null;
    }

    $room['roomId'] = (int) $room['roomId'];
    $room['roomType'] = (int) $room['roomType'];
    $room['roomHostUserId'] = $room['roomHostUserId'] !== null ? (int) $room['roomHostUserId'] : null;
    $room['roomOwnerUserId'] = $room['roomOwnerUserId'] !== null ? (int) $room['roomOwnerUserId'] : null;
    $room['roomRevision'] = (int) $room['roomRevision'];
    $room['state'] = json_decode($room['roomState'], true, 512, JSON_THROW_ON_ERROR);
    unset($room['roomState']);
    return $room;
}

/**
 * The room the code is for, or null when there's none.
 */
function hbHubMatadorRoomIdByCode(PDO $db, string $code): ?int
{
    $statement = $db->prepare('SELECT roomId FROM `HBHub-MatadorRooms` WHERE roomCode = ?');
    $statement->execute([$code]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false ? (int) $roomId : null;
}

/**
 * Who sits in the game, in seat order (and how long since their game last checked in).
 *
 * @return list<array{userId: int, seat: int, away: bool, idleSeconds: int}>
 */
function hbHubMatadorSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-MatadorSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_MATADOR_AWAY_SECONDS,
        'idleSeconds' => (int) $seat['idleSeconds'],
    ], $statement->fetchAll());
}

function hbHubMatadorSeated(array $seats, int $userId): bool
{
    return in_array($userId, array_column($seats, 'userId'), true);
}

/**
 * Notes that the user's game is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubMatadorTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-MatadorSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_MATADOR_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * Locks the room, moves it on (players gone for the grace period leave, turns of players who aren't there are skipped),
 * runs $action on it (if any) and saves it when anything changed - in the owner's save slot too, while it's being
 * played (save.php). A room nobody is left in, or that the host stopped ($room['stop']), is deleted. Returns
 * [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats): mixed.
 * It can change both - hbHubMatadorRemoveSeat() takes a player out of $seats, hbHubMatadorJoin() adds one.
 */
function hbHubMatadorPlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubMatadorRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubMatadorSeats($db, $roomId);
            $before = [$room['state'], $room['roomHostUserId']];

            foreach ($seats as $seat) {
                if ($seat['idleSeconds'] >= HBHUB_MATADOR_GRACE_SECONDS) {
                    hbHubMatadorRemoveSeat($db, $room, $seats, $seat['userId']);
                }
            }

            hbHubMatadorAdvance($room['state']);
            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats);
                hbHubMatadorAdvance($room['state']); // like the next turn, when the player whose turn it is left
            }
            if ($before[0]['phase'] === 'playing' && $room['state']['phase'] === 'ended') {
                hbHubMatadorRecordResults($db, $room); // for the leaderboard
            }

            if ($seats === [] || !empty($room['stop'])) {
                if ($room['state']['phase'] === 'playing') {
                    hbHubMatadorAutosave($db, $room); // as it was left, to continue it from
                }
                $db->prepare('DELETE FROM `HBHub-MatadorRooms` WHERE roomId = ?')->execute([$roomId]);
                $room['isDeleted'] = true;
            } else {
                if (!hbHubMatadorSeated($seats, (int) $room['roomHostUserId'])) {
                    $room['roomHostUserId'] = $seats[0]['userId']; // the host's account is gone, or they never left properly
                }
                if ([$room['state'], $room['roomHostUserId']] !== $before) {
                    if (in_array($room['state']['phase'], ['playing', 'ended'], true)) {
                        hbHubMatadorAutosave($db, $room);
                    }
                    $db->prepare('UPDATE `HBHub-MatadorRooms` SET roomPhase = ?, roomState = ?, roomHostUserId = ?, roomRevision = roomRevision + 1
                        WHERE roomId = ?')
                        ->execute([
                            HBHUB_MATADOR_PHASES[$room['state']['phase']],
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
 * Whether just looking at the game has to go through hbHubMatadorPlay(), as there's something to move on: someone has
 * been gone for the grace period, the host isn't in the game, the player whose turn it is has run out of time, or a
 * minimized trade offer has.
 */
function hbHubMatadorNeedsPlay(array $room, array $seats): bool
{
    if (!hbHubMatadorSeated($seats, (int) $room['roomHostUserId']) || hbHubMatadorTimeUp($room['state'])
        || hbHubMatadorTradesRunOut($room['state'])) {
        return true;
    }
    foreach ($seats as $seat) {
        if ($seat['idleSeconds'] >= HBHUB_MATADOR_GRACE_SECONDS) {
            return true;
        }
    }
    return false;
}

/**
 * The game's version for matadorGame.js, which only gets the whole game again when it's different: it changes with
 * the room's state (roomRevision), the chat (its newest message, chat.php), and with who sits where, who's away and who
 * the host is. It starts with "revision-newest message-", which only go up, so matadorGame.js can tell older answers.
 */
function hbHubMatadorVersion(array $room, array $seats, int $lastChatId): string
{
    $seated = array_map(static fn (array $seat): array => [$seat['userId'], $seat['seat'], $seat['away']], $seats);
    return $room['roomRevision'] . '-' . $lastChatId . '-' . hash('crc32b', json_encode([$seated, $room['roomHostUserId']]));
}

/**
 * Sits the user down in the game, when they may (see the top of this file). $hasCode says they typed in its code.
 * Returns null when they sat down, otherwise why not.
 */
function hbHubMatadorJoin(PDO $db, int $userId, string $userName, int $roomId, bool $hasCode): ?string
{
    if (hbHubMatadorUserSeat($db, $userId) !== null) {
        return 'You\'re already in a game. Leave it first.';
    }

    try {
        $played = hbHubMatadorPlay($db, $roomId, function (array &$room, array &$seats) use ($db, $userId, $userName, $hasCode): ?string {
            $state = &$room['state'];
            $kept = null;
            foreach ($state['players'] as $i => $player) {
                if ($player['userId'] === null && !$player['bankrupt']
                    && ($player['originalUserId'] === $userId || $player['reservedFor'] === $userId)) {
                    $kept = $i;
                }
            }

            if ($kept !== null) {
                $player = &$state['players'][$kept];
                $isBack = $player['originalUserId'] === $userId;
                $before = $player['name'];
                $player['userId'] = $userId;
                $player['originalUserId'] = $userId; // they take the seat over, so it's kept for them from now on
                $player['reservedFor'] = null;
                $player['name'] = $userName;
                $seatNumber = $player['seat'];
                unset($player);
                if ($state['phase'] === 'playing') {
                    hbHubMatadorLog($state, $isBack ? $userName . ' is back.' : $userName . ' took over ' . $before . '\'s seat.');
                }
            } elseif ($state['phase'] === 'lobby') {
                $type = $room['roomType'];
                $mayJoin = hbHubMatadorIsInvited($db, $room['roomId'], $userId)
                    || $type === HBHUB_MATADOR_ROOM_PUBLIC
                    || ($type === HBHUB_MATADOR_ROOM_CODE && $hasCode);
                if (!$mayJoin) {
                    return $type === HBHUB_MATADOR_ROOM_INVITE
                        ? 'That game is invite-only. Ask its host to invite you.'
                        : 'That game can only be joined with its code.';
                }
                $taken = array_column($state['players'], 'seat');
                $seatNumber = null;
                for ($seat = 1; $seat <= HBHUB_MATADOR_MAX_PLAYERS && $seatNumber === null; $seat++) {
                    if (!in_array($seat, $taken, true)) {
                        $seatNumber = $seat;
                    }
                }
                if ($seatNumber === null) {
                    return 'That game is full.';
                }
                $state['players'][] = hbHubMatadorNewPlayer($seatNumber, $userId, $userName);
            } elseif ($state['phase'] === 'ended') {
                return 'That game is over.';
            } else {
                return 'That game has already started. Only its players can come back to it.';
            }

            hbHubMatadorInsertSeat($db, $room['roomId'], $userId, $seatNumber);
            $seats[] = ['userId' => $userId, 'seat' => $seatNumber, 'away' => false, 'idleSeconds' => 0];
            usort($seats, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            return null;
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return 'You\'re already in a game. Leave it first.'; // sat down in another tab meanwhile
        }
        throw $e;
    }

    return $played !== null ? $played[2] : 'That game isn\'t there anymore.';
}

/**
 * Takes the user out of the game. Before it starts their seat just goes. After that it's kept for them, empty (their
 * turns are skipped, game.php's hbHubMatadorAdvance()), with an invite back in the lobby, and the host can give it to
 * someone else. Leaving while it's being played counts as a loss, unless the host has paused it (results.php's
 * hbHubMatadorRecordLeft()). When they were the host, the next player takes over.
 */
function hbHubMatadorRemoveSeat(PDO $db, array &$room, array &$seats, int $userId): void
{
    $db->prepare('DELETE FROM `HBHub-MatadorSeats` WHERE roomId = ? AND userId = ?')->execute([$room['roomId'], $userId]);
    $seats = array_values(array_filter($seats, static fn (array $other): bool => $other['userId'] !== $userId));

    $state = &$room['state'];
    $seat = hbHubMatadorSeatOfUser($state, $userId);
    if ($seat !== null) {
        if ($state['phase'] === 'lobby') {
            $state['players'] = array_values(array_filter($state['players'], static fn (array $player): bool => $player['seat'] !== $seat));
        } else {
            $i = hbHubMatadorIndex($state, $seat);
            $forfeitedBefore = count($state['forfeited'] ?? []);
            hbHubMatadorRecordLeft($db, $room, $seat, $userId); // a loss, unless the host paused the game (results.php)
            $isLoss = count($state['forfeited'] ?? []) > $forfeitedBefore;
            $state['players'][$i]['userId'] = null;
            hbHubMatadorCancelTrades($state, $seat);
            if (!$state['players'][$i]['bankrupt'] && in_array($state['phase'], ['resume', 'playing'], true)) {
                hbHubMatadorAddInvite($db, $room['roomId'], $userId, null, $seat);
                if ($state['phase'] === 'playing') {
                    hbHubMatadorLog($state, $state['players'][$i]['name'] . ' left' . ($isLoss ? ', which counts as a loss for them' : '')
                        . '. Their seat is kept for them, and their turns are skipped.');
                }
            }
        }
    }
    unset($state);

    if ($room['roomHostUserId'] === $userId) {
        $room['roomHostUserId'] = hbHubMatadorNextHost($seats, $seat ?? 0);
    }
}

/**
 * The next player round the table after $afterSeat (the seats are in order), or null when there's nobody.
 */
function hbHubMatadorNextHost(array $seats, int $afterSeat): ?int
{
    foreach ($seats as $seat) {
        if ($seat['seat'] > $afterSeat) {
            return $seat['userId'];
        }
    }
    return $seats[0]['userId'] ?? null;
}

/**
 * Takes the user out of their game, if they're in one (hbHubMatadorRemoveSeat()).
 */
function hbHubMatadorLeave(PDO $db, int $userId): void
{
    $seat = hbHubMatadorUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubMatadorPlay($db, $seat['roomId'], function (array &$room, array &$seats) use ($db, $userId): void {
        hbHubMatadorRemoveSeat($db, $room, $seats, $userId);
    });
}

/**
 * Invites the user to the room (or updates their invite). $seat is the seat kept for them, null for a plain invite.
 */
function hbHubMatadorAddInvite(PDO $db, int $roomId, int $userId, ?int $byUserId, ?int $seat): void
{
    $db->prepare('INSERT INTO `HBHub-MatadorInvites` (roomId, userId, invitedByUserId, inviteSeat, inviteTimestamp)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE invitedByUserId = VALUES(invitedByUserId), inviteSeat = VALUES(inviteSeat), inviteTimestamp = VALUES(inviteTimestamp)')
        ->execute([$roomId, $userId, $byUserId, $seat]);
}

function hbHubMatadorIsInvited(PDO $db, int $roomId, int $userId): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-MatadorInvites` WHERE roomId = ? AND userId = ?');
    $statement->execute([$roomId, $userId]);
    return $statement->fetchColumn() !== false;
}

/**
 * The name of the user (as sent, a userId) when they can be invited: they exist and aren't banned. Null otherwise.
 *
 * @return ?array{0: int, 1: string} [userId, name]
 */
function hbHubMatadorInvitable(PDO $db, mixed $userId): ?array
{
    $statement = $db->prepare('SELECT userName FROM `HBHub-Users` WHERE userId = ? AND userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([is_string($userId) && ctype_digit($userId) && strlen($userId) <= 10 ? (int) $userId : 0, HBHUB_MATADOR_STATUS_BANNED]);
    $name = $statement->fetchColumn();
    return $name !== false ? [(int) $userId, $name] : null;
}

/**
 * The host invites $userId (as sent) before the game starts: they get a notification, and can join it from the lobby,
 * also when it's invite-only. Returns null when they're invited, otherwise why not.
 */
function hbHubMatadorInvite(PDO $db, array $room, int $hostId, mixed $userId): ?string
{
    if ($room['roomHostUserId'] !== $hostId) {
        return 'Only the host can invite people.';
    }
    if ($room['state']['phase'] !== 'lobby') {
        return 'The game has started. Give an empty seat to someone instead.';
    }
    $user = hbHubMatadorInvitable($db, $userId);
    if ($user === null) {
        return 'That user can\'t be found.';
    }
    [$userId, $name] = $user;

    if (hbHubMatadorSeatOfUser($room['state'], $userId) !== null) {
        return $name . ' is already in the game.';
    }
    if (hbHubMatadorIsInvited($db, $room['roomId'], $userId)) {
        return $name . ' is already invited.';
    }

    hbHubMatadorAddInvite($db, $room['roomId'], $userId, $hostId, null);
    hbHubNotify($db, $userId, 'games.matadorInvite', $hostId, ['roomId' => $room['roomId'], 'roomName' => $room['roomName']]);
    return null;
}

/**
 * The host gives an empty seat (as sent) to $userId: when they join, they take it over - its money, properties and
 * cards. Until then its own player can still come back to it. Returns null when it's done, otherwise why not.
 */
function hbHubMatadorGiveSeat(PDO $db, array &$room, int $hostId, mixed $seat, mixed $userId): ?string
{
    $state = &$room['state'];
    if ($room['roomHostUserId'] !== $hostId) {
        return 'Only the host can give seats away.';
    }
    if (!in_array($state['phase'], ['resume', 'playing'], true)) {
        return 'Seats can only be given away once the game has started.';
    }
    $i = is_string($seat) && ctype_digit($seat) ? hbHubMatadorIndex($state, (int) $seat) : null;
    if ($i === null || $state['players'][$i]['userId'] !== null || $state['players'][$i]['bankrupt']) {
        return 'Choose an empty seat.';
    }
    $user = hbHubMatadorInvitable($db, $userId);
    if ($user === null) {
        return 'That user can\'t be found.';
    }
    [$userId, $name] = $user;
    if (hbHubMatadorSeatOfUser($state, $userId) !== null) {
        return $name . ' is already in the game.';
    }

    $state['players'][$i]['reservedFor'] = $userId;
    hbHubMatadorAddInvite($db, $room['roomId'], $userId, $hostId, $state['players'][$i]['seat']);
    hbHubNotify($db, $userId, 'games.matadorSeat', $hostId, [
        'roomId' => $room['roomId'],
        'roomName' => $room['roomName'],
        'player' => $state['players'][$i]['name'],
    ]);
    return null;
}

/**
 * The host starts the game: a new one once 2-4 players are in, or a saved one once at least 2 of its players are back.
 * A new game takes over the owner's save slot (save.php). Returns null when it's started, otherwise why not.
 */
function hbHubMatadorStart(PDO $db, array &$room, int $userId): ?string
{
    $state = &$room['state'];
    if ($room['roomHostUserId'] !== $userId) {
        return 'Only the host can start the game.';
    }

    if ($state['phase'] === 'lobby') {
        if (count($state['players']) < HBHUB_MATADOR_MIN_PLAYERS) {
            return 'Wait for at least ' . HBHUB_MATADOR_MIN_PLAYERS . ' players.';
        }
        hbHubMatadorBegin($state);
        hbHubMatadorClaimSave($db, $room);
        return null;
    }

    if ($state['phase'] === 'resume') {
        $here = array_filter(hbHubMatadorActivePlayers($state), static fn (array $player): bool => $player['userId'] !== null);
        if (count($here) < HBHUB_MATADOR_MIN_PLAYERS) {
            return 'Wait for at least ' . HBHUB_MATADOR_MIN_PLAYERS . ' players to be back.';
        }
        $state['phase'] = 'playing';
        $state['paused'] = false; // saved while it was paused
        hbHubMatadorResetTimer($state); // the saved deadline is long gone
        hbHubMatadorLog($state, 'The game goes on where it was saved.');
        return null;
    }

    return 'The game has already started.';
}

/**
 * The host changes the room's settings (game.php's hbHubMatadorSettings(), as sent: dice, jailRent, parkingPot and
 * building, game.php's HBHUB_MATADOR_BUILDING_CHOICES), before the game starts. A saved game keeps the ones it was
 * started with. Returns null when they're changed, otherwise why not.
 */
function hbHubMatadorChangeSettings(array &$room, int $userId, mixed $dice, mixed $jailRent, mixed $parkingPot, mixed $building): ?string
{
    $state = &$room['state'];
    if ($room['roomHostUserId'] !== $userId) {
        return 'Only the host can change the room\'s settings.';
    }
    if ($state['phase'] !== 'lobby') {
        return 'The settings can only be changed before the game starts.';
    }
    $settings = hbHubMatadorCleanSettings($dice, $jailRent, $parkingPot, $building);
    if ($settings === null) {
        return 'Choose the settings.';
    }
    if ($settings !== hbHubMatadorSettings($state)) {
        $state['settings'] = $settings;
        hbHubMatadorLog($state, 'The host changed the room\'s settings: ' . hbHubMatadorSettingsText($settings) . '.');
    }
    return null;
}

/**
 * The host pauses the game ($pause) or lets it go on (game.php's hbHubMatadorIsPaused()). While it's paused nobody can
 * do anything in the game (play.php), and no turn or trade offer runs out or is skipped. The player whose turn it is,
 * and the minimized trade offers, get their full time again when it goes on. Returns null when it's done, otherwise why not.
 */
function hbHubMatadorPause(array &$room, int $userId, bool $pause): ?string
{
    $state = &$room['state'];
    if ($room['roomHostUserId'] !== $userId) {
        return 'Only the host can pause the game.';
    }
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    if ($pause === hbHubMatadorIsPaused($state)) {
        return null; // it already is (a double click, or another tab)
    }
    $seat = hbHubMatadorSeatOfUser($state, $userId);
    $name = $seat !== null ? hbHubMatadorName($state, $seat) : 'The host';
    $state['paused'] = $pause;
    if ($pause) {
        hbHubMatadorLog($state, $name . ' paused the game.');
    } else {
        hbHubMatadorResetTimer($state);
        hbHubMatadorResetTradeTimers($state);
        hbHubMatadorLog($state, $name . ' let the game go on.');
    }
    return null;
}

/**
 * The host saves the game and stops it for everyone (hbHubMatadorPlay() deletes the room). It's continued from the
 * owner's saved game on the Matador page. Returns null when it's done, otherwise why not.
 */
function hbHubMatadorSaveAndStop(PDO $db, array &$room, int $userId): ?string
{
    if ($room['roomHostUserId'] !== $userId) {
        return 'Only the host can save and stop the game.';
    }
    if (!in_array($room['state']['phase'], ['resume', 'playing'], true)) {
        return 'Only a game that has started can be saved.';
    }
    if (!hbHubMatadorHoldsSave($db, $room)) {
        return 'This game can\'t be saved anymore, as its save slot has another game in it now.';
    }
    $room['stop'] = true;
    return null;
}

/**
 * Tidies up games nobody has open anymore: players who haven't been seen for the grace period leave, and rooms left
 * empty are deleted (a game being played is saved first). A game someone has open does this itself on every check-in
 * (hbHubMatadorPlay()); this is for the rest, run from the lobby a few rooms at a time.
 */
function hbHubMatadorSweep(PDO $db): void
{
    $stale = $db->query('SELECT DISTINCT roomId FROM `HBHub-MatadorSeats`
        WHERE seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_MATADOR_GRACE_SECONDS . ' SECOND
        LIMIT ' . HBHUB_MATADOR_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);
    $empty = $db->query('SELECT r.roomId FROM `HBHub-MatadorRooms` r
        WHERE NOT EXISTS (SELECT 1 FROM `HBHub-MatadorSeats` s WHERE s.roomId = r.roomId)
        LIMIT ' . HBHUB_MATADOR_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_unique([...$stale, ...$empty]) as $roomId) {
        try {
            hbHubMatadorPlay($db, (int) $roomId);
        } catch (PDOException $e) {
            error_log('Matador: could not tidy room ' . $roomId . ': ' . $e->getMessage()); // the next lobby visit tries again
        }
    }
}

/**
 * The public games waiting for players, for the lobby: the fullest first.
 *
 * @return list<array{id: int, name: string, host: ?string, players: int, seats: int}>
 */
function hbHubMatadorPublicRooms(PDO $db): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, u.userName AS hostName, COUNT(*) AS players
        FROM `HBHub-MatadorRooms` r
        JOIN `HBHub-MatadorSeats` s ON s.roomId = r.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = r.roomHostUserId
        WHERE r.roomType = ? AND r.roomPhase = ?
        GROUP BY r.roomId, r.roomName, u.userName
        ORDER BY players >= ?, players DESC, r.roomId DESC
        LIMIT ' . HBHUB_MATADOR_LOBBY_ROOMS);
    $statement->execute([HBHUB_MATADOR_ROOM_PUBLIC, HBHUB_MATADOR_PHASES['lobby'], HBHUB_MATADOR_MAX_PLAYERS]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'host' => $room['hostName'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_MATADOR_MAX_PLAYERS,
    ], $statement->fetchAll());
}

/**
 * The games the user has been invited to (and isn't in) that they can still join: ones waiting for players, and ones
 * with a seat kept for them. The newest invite first.
 *
 * @return list<array{id: int, name: string, type: string, invitedBy: ?string, players: int, seats: int, seat: ?int, phase: string}>
 */
function hbHubMatadorInvitesFor(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, r.roomType, r.roomPhase, i.inviteSeat, u.userName AS invitedBy,
            (SELECT COUNT(*) FROM `HBHub-MatadorSeats` s WHERE s.roomId = r.roomId) AS players
        FROM `HBHub-MatadorInvites` i
        JOIN `HBHub-MatadorRooms` r ON r.roomId = i.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = i.invitedByUserId
        WHERE i.userId = ?
            AND (r.roomPhase = ? OR (i.inviteSeat IS NOT NULL AND r.roomPhase IN (?, ?)))
            AND NOT EXISTS (SELECT 1 FROM `HBHub-MatadorSeats` s WHERE s.roomId = r.roomId AND s.userId = ?)
        ORDER BY i.inviteTimestamp DESC
        LIMIT ' . HBHUB_MATADOR_LOBBY_INVITES);
    $statement->execute([$userId, HBHUB_MATADOR_PHASES['lobby'], HBHUB_MATADOR_PHASES['resume'], HBHUB_MATADOR_PHASES['playing'], $userId]);

    $phases = array_flip(HBHUB_MATADOR_PHASES);
    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'type' => HBHUB_MATADOR_ROOM_TYPES[(int) $room['roomType']] ?? 'public',
        'invitedBy' => $room['invitedBy'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_MATADOR_MAX_PLAYERS,
        'seat' => $room['inviteSeat'] !== null ? (int) $room['inviteSeat'] : null,
        'phase' => $phases[(int) $room['roomPhase']] ?? 'lobby',
    ], $statement->fetchAll());
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubMatadorPeople(PDO $db, array $userIds): array
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
