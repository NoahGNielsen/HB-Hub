<?php
// Doodle Chain rooms in the database: opening and joining them, who sits where, the texts and drawings, and loading and
// saving the game (HBHub-DoodleRooms, HBHub-DoodleSeats and HBHub-DoodleEntries). The rules are in game.php. Built the
// same way as the UNO rooms (games/uno/room.php), without invites, a chat or a leaderboard for now.
//
// Who can join (hbHubDoodleJoin()): while no game is being played - before the first one, or while the chains are
// shown - and there's a free seat. Anyone a public game, anyone with its code a code game. The host starts a game once
// at least HBHUB_DOODLE_MIN_PLAYERS are in.
//
// Leaving while a game is being played is fine: the others play on, and the chains just have nothing from you in the
// steps that are left. A player whose game hasn't checked in for HBHUB_DOODLE_GRACE_SECONDS leaves on their own.
//
// Everything that changes a game goes through hbHubDoodlePlay(), which locks the room's row first, so only one request
// at a time moves a game on. hbHubDoodleSweep() tidies up rooms nobody has open anymore.
//
// The texts and drawings never go into roomState: each is a row in HBHub-DoodleEntries, so the state stays small. A
// player is only ever sent what they're handed in the step being played (hbHubDoodlePrompt()), and everyone the
// entries the host has shown so far (hbHubDoodleView()).
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../userAvatar.php';
require_once __DIR__ . '/game.php';

const HBHUB_DOODLE_ROOM_PUBLIC = 1; // roomType
const HBHUB_DOODLE_ROOM_CODE = 3; // the same numbers as UNO's, so invite-only games can be 2 later
const HBHUB_DOODLE_ROOM_TYPES = [ // as the forms and doodleGame.js name them
    HBHUB_DOODLE_ROOM_PUBLIC => 'public',
    HBHUB_DOODLE_ROOM_CODE => 'code',
];
const HBHUB_DOODLE_ROOM_TYPE_NAMES = [
    HBHUB_DOODLE_ROOM_PUBLIC => 'Public game',
    HBHUB_DOODLE_ROOM_CODE => 'Game with a code',
];
const HBHUB_DOODLE_PHASES = ['lobby' => 1, 'playing' => 2, 'reveal' => 3]; // roomPhase
const HBHUB_DOODLE_ROOM_NAME_MAX = 48; // roomName column
const HBHUB_DOODLE_CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0 O 1 I L, as they look alike
const HBHUB_DOODLE_CODE_LENGTH = 6;
const HBHUB_DOODLE_AWAY_SECONDS = 10; // a game that hasn't checked in for this long counts as away
const HBHUB_DOODLE_GRACE_SECONDS = 60; // and after this long the player has left the room
const HBHUB_DOODLE_SEEN_EVERY = 2; // seconds between updates of seatLastSeenTimestamp
const HBHUB_DOODLE_SWEEP_ROOMS = 10; // the most rooms hbHubDoodleSweep() tidies at once
const HBHUB_DOODLE_LOBBY_ROOMS = 50; // the most public games in the lobby
const HBHUB_DOODLE_HAVE_MAX = 40; // the most entry ids a page can say it already has (?have=)

/**
 * The game the user sits at, or null when they aren't in one.
 *
 * @return ?array{roomId: int, seat: int}
 */
function hbHubDoodleUserSeat(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT roomId, seatNumber FROM `HBHub-DoodleSeats` WHERE userId = ?');
    $statement->execute([$userId]);
    $seat = $statement->fetch();
    return $seat !== false ? ['roomId' => (int) $seat['roomId'], 'seat' => (int) $seat['seatNumber']] : null;
}

/**
 * Opens a new game with the user as its host, sitting in seat 1, and returns its id. Throws a PDOException with the
 * duplicate key error (1062) when the user already sits in a game.
 */
function hbHubDoodleCreateRoom(PDO $db, int $userId, string $userName, string $name, int $type): int
{
    $state = hbHubDoodleNewState();
    $state['players'][] = hbHubDoodleNewPlayer(1, $userId, $userName);

    $db->beginTransaction();
    try {
        for ($attempt = 1; ; $attempt++) {
            try {
                $db->prepare('INSERT INTO `HBHub-DoodleRooms` (roomName, roomType, roomCode, roomHostUserId, roomPhase, roomState, roomCreatedTimestamp)
                    VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                    ->execute([$name, $type, hbHubDoodleNewCode(), $userId, HBHUB_DOODLE_PHASES['lobby'], json_encode($state, JSON_THROW_ON_ERROR)]);
                break;
            } catch (PDOException $e) {
                // A code another room already has is just tried again - InnoDB only undoes the failed INSERT
                $isCodeTaken = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'roomCode');
                if (!$isCodeTaken || $attempt >= 5) {
                    throw $e;
                }
            }
        }
        $roomId = (int) $db->lastInsertId();
        hbHubDoodleInsertSeat($db, $roomId, $userId, 1);
        $db->commit();
        return $roomId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function hbHubDoodleInsertSeat(PDO $db, int $roomId, int $userId, int $seat): void
{
    $db->prepare('INSERT INTO `HBHub-DoodleSeats` (roomId, userId, seatNumber, seatJoinedTimestamp, seatLastSeenTimestamp)
        VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$roomId, $userId, $seat]);
}

function hbHubDoodleNewCode(): string
{
    $code = '';
    for ($i = 0; $i < HBHUB_DOODLE_CODE_LENGTH; $i++) {
        $code .= HBHUB_DOODLE_CODE_LETTERS[random_int(0, strlen(HBHUB_DOODLE_CODE_LETTERS) - 1)];
    }
    return $code;
}

/**
 * A join code as typed in: any case, spaces and dashes left out. Null when it can't be one of the codes.
 */
function hbHubDoodleCleanCode(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $code = strtoupper(preg_replace('~[\s-]+~', '', $code) ?? '');
    return strlen($code) === HBHUB_DOODLE_CODE_LENGTH && strspn($code, HBHUB_DOODLE_CODE_LETTERS) === HBHUB_DOODLE_CODE_LENGTH
        ? $code
        : null;
}

/**
 * The name for a new game as typed in, on one line and cut to fit. Without one it's "Alice's game".
 */
function hbHubDoodleRoomName(mixed $name, string $userName): string
{
    $name = is_string($name) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $name) ?? '') : '';
    if ($name === '') {
        $suffix = '\'s game';
        return mb_substr($userName, 0, HBHUB_DOODLE_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
    return mb_substr($name, 0, HBHUB_DOODLE_ROOM_NAME_MAX);
}

/**
 * The room with its state (roomState decoded into 'state'), or null when there's no such room.
 * With $lock it's locked until the transaction ends - only hbHubDoodlePlay() does that.
 */
function hbHubDoodleRoom(PDO $db, int $roomId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT roomId, roomName, roomType, roomCode, roomHostUserId, roomRevision, roomState
        FROM `HBHub-DoodleRooms` WHERE roomId = ?' . ($lock ? ' FOR UPDATE' : ''));
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

function hbHubDoodleRoomIdByCode(PDO $db, string $code): ?int
{
    $statement = $db->prepare('SELECT roomId FROM `HBHub-DoodleRooms` WHERE roomCode = ?');
    $statement->execute([$code]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false ? (int) $roomId : null;
}

/**
 * Who sits in the room, in seat order (and how long since their game last checked in).
 *
 * @return list<array{userId: int, seat: int, away: bool, idleSeconds: int}>
 */
function hbHubDoodleSeats(PDO $db, int $roomId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT userId, seatNumber, TIMESTAMPDIFF(SECOND, seatLastSeenTimestamp, UTC_TIMESTAMP()) AS idleSeconds
        FROM `HBHub-DoodleSeats` WHERE roomId = ? ORDER BY seatNumber');
    $statement->execute([$roomId]);

    return array_map(static fn (array $seat): array => [
        'userId' => (int) $seat['userId'],
        'seat' => (int) $seat['seatNumber'],
        'away' => (int) $seat['idleSeconds'] >= HBHUB_DOODLE_AWAY_SECONDS,
        'idleSeconds' => (int) $seat['idleSeconds'],
    ], $statement->fetchAll());
}

function hbHubDoodleSeated(array $seats, int $userId): bool
{
    return in_array($userId, array_column($seats, 'userId'), true);
}

/**
 * Notes that the user's game is open, so they don't count as away. Only every few seconds, not on every check-in.
 */
function hbHubDoodleTouchSeat(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-DoodleSeats` SET seatLastSeenTimestamp = UTC_TIMESTAMP()
        WHERE userId = ? AND seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_DOODLE_SEEN_EVERY . ' SECOND')
        ->execute([$userId]);
}

/**
 * Locks the room, moves it on (players gone for the grace period leave, a step that's over ends), runs $action on it
 * (if any), and saves it when anything changed. A room nobody is left in is deleted, with its texts and drawings.
 * Returns [room, seats, what $action returned], or null when the room is gone.
 *
 * $action gets the room and the seats by reference: function (array &$room, array &$seats): mixed.
 */
function hbHubDoodlePlay(PDO $db, int $roomId, ?callable $action = null): ?array
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $room = hbHubDoodleRoom($db, $roomId, true);
            if ($room === null) {
                $db->rollBack();
                return null;
            }
            $seats = hbHubDoodleSeats($db, $roomId);
            $before = [$room['state'], $room['roomHostUserId']];

            foreach ($seats as $seat) {
                if ($seat['idleSeconds'] >= HBHUB_DOODLE_GRACE_SECONDS) {
                    hbHubDoodleRemoveSeat($db, $room, $seats, $seat['userId'], true);
                }
            }
            // Someone in the state without a seat (their account was deleted, which takes the seat with it)
            $seatedIds = array_column($seats, 'userId');
            $room['state']['players'] = array_values(array_filter($room['state']['players'],
                static fn (array $player): bool => in_array($player['userId'], $seatedIds, true)));
            hbHubDoodleAdvance($room['state'], $seatedIds, hbHubDoodleNowMs());

            $result = null;
            if ($action !== null) {
                $result = $action($room, $seats);
                hbHubDoodleAdvance($room['state'], array_column($seats, 'userId'), hbHubDoodleNowMs());
            }

            if ($seats === []) {
                $db->prepare('DELETE FROM `HBHub-DoodleRooms` WHERE roomId = ?')->execute([$roomId]); // entries go with it
                $room['isDeleted'] = true;
            } else {
                if (!hbHubDoodleSeated($seats, (int) $room['roomHostUserId'])) {
                    $room['roomHostUserId'] = $seats[0]['userId']; // the host's account is gone, or they never left properly
                }
                if ([$room['state'], $room['roomHostUserId']] !== $before) {
                    $db->prepare('UPDATE `HBHub-DoodleRooms` SET roomPhase = ?, roomState = ?, roomHostUserId = ?, roomRevision = roomRevision + 1
                        WHERE roomId = ?')
                        ->execute([
                            HBHUB_DOODLE_PHASES[$room['state']['phase']],
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
 * Whether just looking at the game has to go through hbHubDoodlePlay(), as there's something to move on: someone has
 * been gone for the grace period, the host isn't in the room, or the step's time is up.
 */
function hbHubDoodleNeedsPlay(array $room, array $seats): bool
{
    if (!hbHubDoodleSeated($seats, (int) $room['roomHostUserId']) || hbHubDoodleTimeUp($room['state'], hbHubDoodleNowMs())) {
        return true;
    }
    foreach ($seats as $seat) {
        if ($seat['idleSeconds'] >= HBHUB_DOODLE_GRACE_SECONDS) {
            return true;
        }
    }
    return false;
}

/**
 * The game's version for doodleGame.js, which only gets the whole game again when it's different: it changes with the
 * room's state (roomRevision) and with who sits where, who's away and who the host is. It starts with "revision-",
 * which only goes up, so doodleGame.js can tell older answers.
 */
function hbHubDoodleVersion(array $room, array $seats): string
{
    $seated = array_map(static fn (array $seat): array => [$seat['userId'], $seat['seat'], $seat['away']], $seats);
    return $room['roomRevision'] . '-' . hash('crc32b', json_encode([$seated, $room['roomHostUserId']]));
}

/**
 * Sits the user down in the game, when they may (see the top of this file). $hasCode says they typed in its code.
 * Returns null when they sat down, otherwise why not.
 */
function hbHubDoodleJoin(PDO $db, int $userId, string $userName, int $roomId, bool $hasCode): ?string
{
    if (hbHubDoodleUserSeat($db, $userId) !== null) {
        return 'You\'re already in a Doodle Chain game. Leave it first.';
    }

    try {
        $played = hbHubDoodlePlay($db, $roomId, function (array &$room, array &$seats) use ($db, $userId, $userName, $hasCode): ?string {
            $state = &$room['state'];
            if ($state['phase'] === 'playing') {
                return 'That game is being played. You can join once it\'s over.';
            }
            if ($room['roomType'] !== HBHUB_DOODLE_ROOM_PUBLIC && !$hasCode) {
                return 'That game can only be joined with its code.';
            }
            $free = array_values(array_diff(range(1, HBHUB_DOODLE_MAX_PLAYERS), array_column($seats, 'seat')));
            if ($free === []) {
                return 'That game is full.';
            }

            $state['players'][] = hbHubDoodleNewPlayer($free[0], $userId, $userName);
            usort($state['players'], static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            $state['note'] = null;
            hbHubDoodleInsertSeat($db, $room['roomId'], $userId, $free[0]);
            $seats[] = ['userId' => $userId, 'seat' => $free[0], 'away' => false, 'idleSeconds' => 0];
            usort($seats, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
            return null;
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return 'You\'re already in a Doodle Chain game. Leave it first.'; // sat down in another tab meanwhile
        }
        throw $e;
    }

    return $played !== null ? $played[2] : 'That game isn\'t there anymore.';
}

/**
 * Takes the user out of the room. In a game being played the others play on without them. When the host leaves, the
 * next player round the table becomes the host. $isGone says they didn't leave themselves, their game just stopped
 * checking in.
 */
function hbHubDoodleRemoveSeat(PDO $db, array &$room, array &$seats, int $userId, bool $isGone = false): void
{
    $db->prepare('DELETE FROM `HBHub-DoodleSeats` WHERE roomId = ? AND userId = ?')->execute([$room['roomId'], $userId]);
    $seat = null;
    foreach ($seats as $other) {
        if ($other['userId'] === $userId) {
            $seat = $other['seat'];
        }
    }
    $seats = array_values(array_filter($seats, static fn (array $other): bool => $other['userId'] !== $userId));

    $state = &$room['state'];
    foreach ($state['players'] as $player) {
        if ($player['userId'] === $userId) {
            $state['note'] = $player['name'] . ($isGone ? ' was gone too long, and has left.' : ' left.');
        }
    }
    $state['players'] = array_values(array_filter($state['players'], static fn (array $player): bool => $player['userId'] !== $userId));
    unset($state);

    if ($room['roomHostUserId'] === $userId) {
        $next = null;
        foreach ($seats as $other) {
            if ($next === null && $other['seat'] > ($seat ?? 0)) {
                $next = $other['userId'];
            }
        }
        $room['roomHostUserId'] = $next ?? $seats[0]['userId'] ?? null;
    }
}

/**
 * Takes the user out of their game, if they're in one (hbHubDoodleRemoveSeat()).
 */
function hbHubDoodleLeave(PDO $db, int $userId): void
{
    $seat = hbHubDoodleUserSeat($db, $userId);
    if ($seat === null) {
        return;
    }

    hbHubDoodlePlay($db, $seat['roomId'], function (array &$room, array &$seats) use ($db, $userId): void {
        hbHubDoodleRemoveSeat($db, $room, $seats, $userId);
    });
}

/**
 * The host starts a game (game.php's hbHubDoodleStart()). The texts and drawings of the room's last game are deleted.
 */
function hbHubDoodleStartGame(PDO $db, array &$room, int $userId): ?string
{
    $error = hbHubDoodleStart($room['state'], $room['roomHostUserId'] === $userId, hbHubDoodleNowMs());
    if ($error === null) {
        $db->prepare('DELETE FROM `HBHub-DoodleEntries` WHERE roomId = ?')->execute([$room['roomId']]);
    }
    return $error;
}

/**
 * Hands in the user's text or drawing for the step being played. $text is used in a step that's written, $drawing
 * (JSON, game.php) in one that's drawn. Handing in nothing (an empty text or drawing) is allowed: the user is done, and
 * nothing is stored. Returns null when it's handed in, otherwise why not.
 */
function hbHubDoodleHandIn(PDO $db, array &$room, int $userId, mixed $text, mixed $drawing): ?string
{
    $turn = hbHubDoodleTurnOf($room['state'], $userId);
    if (is_string($turn)) {
        return $turn;
    }
    [$chain, $step, $kind] = $turn;

    if ($kind === 'write') {
        $text = hbHubDoodleCleanText($text);
        $drawing = null;
        $isEmpty = $text === '';
    } else {
        $drawing = hbHubDoodleCleanDrawing($drawing);
        if ($drawing === null) {
            return 'That drawing couldn\'t be read. Try clearing a bit of it and handing it in again.';
        }
        $text = null;
        $isEmpty = $drawing === '';
    }

    if (!$isEmpty) {
        $db->prepare('INSERT INTO `HBHub-DoodleEntries` (roomId, gameNumber, chainNumber, stepNumber, userId, entryKind, entryText, entryDrawing, entryTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                $room['roomId'], $room['state']['game']['number'], $chain, $step, $userId,
                $kind === 'write' ? HBHUB_DOODLE_KIND_TEXT : HBHUB_DOODLE_KIND_DRAWING, $text, $drawing,
            ]);
    }
    hbHubDoodleMarkDone($room['state'], $userId);
    return null;
}

/**
 * What the user is handed in the step being played: the newest text further back in their chain in a step that's
 * drawn, or the newest drawing in one that's written (usually the step right before, unless whoever had it handed in
 * nothing). Null in the first step, or when there's nothing to hand them.
 *
 * @return ?array{id: int, kind: string, text: ?string, drawing: ?string}
 */
function hbHubDoodlePrompt(PDO $db, array $room, int $userId): ?array
{
    $state = $room['state'];
    if ($state['phase'] !== 'playing' || $state['game']['step'] === 0) {
        return null;
    }
    $game = $state['game'];
    $chain = hbHubDoodleChainOf($game, $userId);
    if ($chain === null) {
        return null;
    }
    $wanted = hbHubDoodleStepKind($game['step']) === 'draw' ? HBHUB_DOODLE_KIND_TEXT : HBHUB_DOODLE_KIND_DRAWING;

    $statement = $db->prepare('SELECT entryId, entryKind, entryText, entryDrawing FROM `HBHub-DoodleEntries`
        WHERE roomId = ? AND gameNumber = ? AND chainNumber = ? AND stepNumber < ? AND entryKind = ?
        ORDER BY stepNumber DESC LIMIT 1');
    $statement->execute([$room['roomId'], $game['number'], $chain, $game['step'], $wanted]);
    $entry = $statement->fetch();
    return $entry !== false ? hbHubDoodleEntryOut($entry) : null;
}

/**
 * An entry's row as it's sent: {id, kind: 'text' | 'drawing', text, drawing (the strokes' JSON as stored)}.
 */
function hbHubDoodleEntryOut(array $entry): array
{
    $isText = (int) $entry['entryKind'] === HBHUB_DOODLE_KIND_TEXT;
    return [
        'id' => (int) $entry['entryId'],
        'kind' => $isText ? 'text' : 'drawing',
        'text' => $isText ? $entry['entryText'] : null,
        'drawing' => $isText ? null : $entry['entryDrawing'],
    ];
}

/**
 * The entries of the chain being shown, as far as the host has shown it. Steps nobody handed anything in for are in the
 * list too, with kind null, so the chain can say so.
 *
 * @return list<array{step: int, by: string, id: ?int, kind: ?string, text: ?string, drawing: ?string}>
 */
function hbHubDoodleShownEntries(PDO $db, array $room): array
{
    $game = $room['state']['game'];
    $reveal = $game['reveal'];
    $count = count($game['order']);

    $statement = $db->prepare('SELECT entryId, stepNumber, entryKind, entryText, entryDrawing FROM `HBHub-DoodleEntries`
        WHERE roomId = ? AND gameNumber = ? AND chainNumber = ? AND stepNumber < ?');
    $statement->execute([$room['roomId'], $game['number'], $reveal['chain'], $reveal['shown']]);
    $byStep = [];
    foreach ($statement->fetchAll() as $entry) {
        $byStep[(int) $entry['stepNumber']] = hbHubDoodleEntryOut($entry);
    }

    $entries = [];
    for ($step = 0; $step < $reveal['shown']; $step++) {
        $place = (($reveal['chain'] - $step) % $count + $count) % $count; // who had the chain in that step
        $entries[] = ['step' => $step, 'by' => $game['names'][$place]]
            + ($byStep[$step] ?? ['id' => null, 'kind' => null, 'text' => null, 'drawing' => null]);
    }
    return $entries;
}

/**
 * The game as $userId sees it, for doodleGame.js. Drawings the page says it already has ($have, entry ids) are sent
 * without their strokes (drawing: null, cached: true), as they're the biggest part of an answer.
 *
 * @param array<int, array{name: string, avatar: ?string}> $people
 * @param list<int> $have
 */
function hbHubDoodleView(PDO $db, array $room, array $seats, array $people, int $userId, array $have, int $nowMs): array
{
    $state = $room['state'];
    $game = $state['game'];
    $settings = hbHubDoodleSettings($state);
    $isPlaying = $state['phase'] === 'playing';
    $awayIds = array_column(array_filter($seats, static fn (array $seat): bool => $seat['away']), 'userId');

    $strip = static function (?array $entry) use ($have): ?array {
        if ($entry !== null && $entry['kind'] === 'drawing' && in_array($entry['id'], $have, true)) {
            $entry['drawing'] = null;
            $entry['cached'] = true;
        }
        return $entry;
    };

    $view = [
        'phase' => $state['phase'],
        'isHost' => $room['roomHostUserId'] === $userId,
        'note' => $state['note'],
        'settings' => $settings,
        'settingsText' => hbHubDoodleSettingsText($settings),
        'minPlayers' => HBHUB_DOODLE_MIN_PLAYERS,
        'maxPlayers' => HBHUB_DOODLE_MAX_PLAYERS,
        'players' => array_map(static fn (array $player): array => [
            'name' => $player['name'],
            'avatar' => $people[$player['userId']]['avatar'] ?? null,
            'isYou' => $player['userId'] === $userId,
            'isHost' => $player['userId'] === $room['roomHostUserId'],
            'away' => in_array($player['userId'], $awayIds, true),
            'inGame' => $game !== null && $state['phase'] !== 'lobby' && in_array($player['userId'], $game['order'], true),
            'done' => $isPlaying && in_array($player['userId'], $game['done'], true),
        ], $state['players']),
        'turn' => null,
        'reveal' => null,
    ];

    if ($isPlaying) {
        $seatedIds = array_column($seats, 'userId');
        $chain = hbHubDoodleChainOf($game, $userId);
        $view['turn'] = [
            'game' => $game['number'],
            'step' => $game['step'],
            'steps' => count($game['order']),
            'kind' => hbHubDoodleStepKind($game['step']),
            'timeLeftMs' => max(0, $game['stepEndsMs'] - $nowMs),
            'timeTotalMs' => hbHubDoodleStepSeconds($state, $game['step']) * 1000,
            'inGame' => $chain !== null,
            'done' => in_array($userId, $game['done'], true),
            'doneCount' => count(array_intersect($game['done'], $seatedIds)),
            'playingCount' => count(hbHubDoodleActive($game, $seatedIds)),
            // The prompt only matters until the user has handed in
            'prompt' => $chain !== null && !in_array($userId, $game['done'], true) ? $strip(hbHubDoodlePrompt($db, $room, $userId)) : null,
        ];
    }

    if ($state['phase'] === 'reveal') {
        $reveal = $game['reveal'];
        $view['reveal'] = [
            'chain' => $reveal['chain'],
            'chains' => count($game['order']),
            'shown' => $reveal['shown'],
            'finished' => $reveal['finished'],
            'startedBy' => $game['names'],
            'entries' => array_map($strip, hbHubDoodleShownEntries($db, $room)),
        ];
    }

    return $view;
}

/**
 * Tidies up rooms nobody has open anymore: players who haven't been seen for the grace period leave, and rooms left
 * empty are deleted. A room someone has open does this itself on every check-in (hbHubDoodlePlay()); this is for the
 * rest, run from the lobby (and the nightly clean-up) a few rooms at a time.
 */
function hbHubDoodleSweep(PDO $db): void
{
    $stale = $db->query('SELECT DISTINCT roomId FROM `HBHub-DoodleSeats`
        WHERE seatLastSeenTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_DOODLE_GRACE_SECONDS . ' SECOND
        LIMIT ' . HBHUB_DOODLE_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);
    $empty = $db->query('SELECT r.roomId FROM `HBHub-DoodleRooms` r
        WHERE NOT EXISTS (SELECT 1 FROM `HBHub-DoodleSeats` s WHERE s.roomId = r.roomId)
        LIMIT ' . HBHUB_DOODLE_SWEEP_ROOMS)->fetchAll(PDO::FETCH_COLUMN);

    foreach (array_unique([...$stale, ...$empty]) as $roomId) {
        try {
            hbHubDoodlePlay($db, (int) $roomId);
        } catch (PDOException $e) {
            error_log('Doodle Chain: could not tidy room ' . $roomId . ': ' . $e->getMessage()); // the next lobby visit tries again
        }
    }
}

/**
 * The public games that can be joined (no game being played, and a free seat), for the lobby: the fullest first.
 *
 * @return list<array{id: int, name: string, host: ?string, players: int, seats: int, settings: string}>
 */
function hbHubDoodlePublicRooms(PDO $db): array
{
    $statement = $db->prepare('SELECT r.roomId, r.roomName, u.userName AS hostName, r.roomState->>\'$.settings\' AS settings, COUNT(*) AS players
        FROM `HBHub-DoodleRooms` r
        JOIN `HBHub-DoodleSeats` s ON s.roomId = r.roomId
        LEFT JOIN `HBHub-Users` u ON u.userId = r.roomHostUserId
        WHERE r.roomType = ? AND r.roomPhase IN (?, ?)
        GROUP BY r.roomId, r.roomName, u.userName, settings
        HAVING players < ?
        ORDER BY players DESC, r.roomId DESC
        LIMIT ' . HBHUB_DOODLE_LOBBY_ROOMS);
    $statement->execute([HBHUB_DOODLE_ROOM_PUBLIC, HBHUB_DOODLE_PHASES['lobby'], HBHUB_DOODLE_PHASES['reveal'], HBHUB_DOODLE_MAX_PLAYERS]);

    return array_map(static fn (array $room): array => [
        'id' => (int) $room['roomId'],
        'name' => $room['roomName'],
        'host' => $room['hostName'],
        'players' => (int) $room['players'],
        'seats' => HBHUB_DOODLE_MAX_PLAYERS,
        'settings' => hbHubDoodleSettingsText(hbHubDoodleSettings(['settings' => json_decode($room['settings'] ?? 'null', true) ?? []])),
    ], $statement->fetchAll());
}

/**
 * The names and profile pictures of the users, by userId.
 *
 * @param list<int> $userIds
 * @return array<int, array{name: string, avatar: ?string}>
 */
function hbHubDoodlePeople(PDO $db, array $userIds): array
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
