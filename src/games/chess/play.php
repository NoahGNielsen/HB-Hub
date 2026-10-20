<?php
// The chess game's JSON, for chessGame.js (the page around it is game.php). Only for the user's own game:
// - GET ?room=id&v=version is the game as the user sees it (hbHubChessView() in game.php), with the game's version
//   (hbHubChessVersion() in room.php). When the version hasn't changed it's just {"v": version, "same": true}.
//   The game asks every second.
// - POST room=id&action=... does something in the game, and answers with the whole game the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     move (from, to: squares like "e2", promotion: q, r, b or n, ply: how many moves the game had when it was made, so
//       a double click only counts once), resign, abort, offerDraw, answerDraw (answer: accept or decline), claim
//       (the win, when the other player has gone), rematch and cancelRematch
//     invite (user), for the host while the room waits for a player
//     start, for the host once a second player has joined (game.php's hbHubChessStart())
//     pause and resume, for the host while a game is being played (game.php's hbHubChessPause())
//     settings (clock: a key of HBHUB_CHESS_CLOCKS, colour: random, white or black), for the host while no game is being
//       played (game.php's hbHubChessChangeSettings())
//     chat (text) writes in the game's chat (chat.php), and leave
//   The game has the chat's newest messages in "chat", with isYou on the user's own and the text cut up into its
//   @mentions in "parts" (hbHubMentionParts()), and who can be mentioned in "chatMentions" (for mentions.js).
// Answers 404 with {"error": "notSeated"} when the user isn't in that game (anymore).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;

$seat = hbHubChessUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubChessTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubChessLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

// Writing in the chat doesn't change the game, so it's answered like just looking at it
$chatError = null;
if ($action === 'chat') {
    $chatError = hbHubChessSay($db, $roomId, $userId, $input['text'] ?? null);
    $action = null;
}

/**
 * Something the user does as a player of the game: $do gets the state, their colour, the seats and the host's userId.
 */
$player = static fn (callable $do): callable => static function (array &$room, array &$seats) use ($do, $userId): ?string {
    $colour = hbHubChessColourOfUser($room['state'], $userId);
    if ($colour === null || $room['state']['phase'] === 'lobby') {
        return 'You aren\'t playing a game right now.';
    }
    return $do($room['state'], $colour, $seats, $room['roomHostUserId']);
};

$do = match ($action) {
    null => null,
    // Only when the game still has as many moves as the user saw (otherwise it was already played - a double click)
    'move' => $player(static fn (array &$state, string $colour): ?string => ($input['ply'] ?? null) === (string) count($state['game']['moves'])
        ? hbHubChessMove($state, $colour, $input['from'] ?? null, $input['to'] ?? null, $input['promotion'] ?? null, hbHubChessNowMs())
        : null),
    'resign' => $player(static fn (array &$state, string $colour): ?string => hbHubChessResign($state, $colour, hbHubChessNowMs())),
    'abort' => $player(static fn (array &$state): ?string => hbHubChessAbort($state, hbHubChessNowMs())),
    'offerDraw' => $player(static fn (array &$state, string $colour): ?string => hbHubChessOfferDraw($state, $colour, hbHubChessNowMs())),
    'answerDraw' => $player(static fn (array &$state, string $colour): ?string => hbHubChessAnswerDraw($state, $colour, $input['answer'] ?? null, hbHubChessNowMs())),
    'claim' => $player(static function (array &$state, string $colour, array $seats, ?int $hostId): ?string {
        $otherId = $state['game'][$colour === 'w' ? 'black' : 'white']['userId'];
        $other = array_values(array_filter($seats, static fn (array $seat): bool => $seat['userId'] === $otherId))[0] ?? null;
        return hbHubChessClaim($state, $colour, $other['idleSeconds'] ?? PHP_INT_MAX, $otherId === $hostId, hbHubChessNowMs());
    }),
    'pause', 'resume' => $player(static fn (array &$state, string $colour, array $seats, ?int $hostId): ?string => hbHubChessPause(
        $state, $hostId === $userId, $action === 'pause', hbHubChessNowMs())),
    'rematch', 'cancelRematch' => static fn (array &$room): ?string => hbHubChessColourOfUser($room['state'], $userId) !== null
        ? hbHubChessRematch($room['state'], hbHubChessColourOfUser($room['state'], $userId), $action === 'rematch', $room['roomHostUserId'])
        : 'You aren\'t in this game.',
    'invite' => static fn (array &$room): ?string => hbHubChessInvite($db, $room, $userId, $input['user'] ?? null),
    'start' => static fn (array &$room): ?string => hbHubChessStart($room['state'], $room['roomHostUserId'] === $userId, $room['roomHostUserId']),
    'settings' => static fn (array &$room): ?string => hbHubChessChangeSettings($room['state'], $room['roomHostUserId'] === $userId,
        $input['clock'] ?? null, $input['colour'] ?? null),
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking only locks the room when there's something to move on (a clock ran out, someone has gone, or the host
// isn't there)
$played = null;
if ($do === null) {
    $room = hbHubChessRoom($db, $roomId);
    $seats = $room !== null ? hbHubChessSeats($db, $roomId) : [];
    if ($room !== null && !hbHubChessNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubChessPlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted']) || !hbHubChessSeated($played[1], $userId)) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // taken out of the game (gone too long)
    exit;
}
[$room, $seats, $error] = $played;
$error ??= $chatError;

$version = hbHubChessVersion($room, $seats, hbHubChessLastChatId($db, $roomId));
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true]);
    exit;
}

$state = $room['state'];
$people = hbHubChessPeople($db, array_filter([
    ...array_column($state['players'], 'userId'),
    $state['game']['white']['userId'] ?? null,
    $state['game']['black']['userId'] ?? null,
], static fn (?int $id): bool => $id !== null));

// The chat's @mentions (assets/php/mentions.php) are of the players in the room. Being back in the game means the user
// has seen any they were sent a notification about.
$chatPeople = hbHubChessChatPeople($db, $roomId, $userId);
$chatMentions = hbHubMentionLookup($chatPeople);
hbHubMarkNotificationsReadAbout($db, $userId, 'games.chessMention', ['roomId' => $roomId]);
$chat = array_map(static fn (array $message): array => [
    'id' => $message['id'],
    'name' => $message['name'],
    'text' => $message['text'],
    'parts' => hbHubMentionParts($message['text'], $chatMentions),
    'time' => $message['time'],
    'isYou' => $message['userId'] === $userId,
], hbHubChessChat($db, $roomId));
$chatNames = [];
foreach ($chatPeople as $person) {
    if (!$person['isYou']) {
        $chatNames[] = ['name' => $person['name'], 'userName' => $person['name']];
    }
}

$response = [
    'v' => $version,
    'game' => [
        'room' => [
            'id' => $room['roomId'],
            'name' => $room['roomName'],
            'code' => $room['roomCode'],
            'type' => HBHUB_CHESS_ROOM_TYPES[$room['roomType']] ?? 'public',
        ],
    ] + hbHubChessView($state, $people, $seats, $userId, $room['roomHostUserId'], hbHubChessNowMs())
        + ['chat' => $chat, 'chatMentions' => $chatNames],
];
if (is_string($error)) {
    $response['error'] = $error;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
