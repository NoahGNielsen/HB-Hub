<?php
// The UNO game's JSON, for unoGame.js (the page around it is game.php). Only for the user's own game:
// - GET ?room=id&v=version is the game as the user sees it (hbHubUnoView() in game.php), with the game's version
//   (hbHubUnoVersion() in room.php). When the version hasn't changed it's just {"v": version, "same": true}.
//   The game asks every second. About every 15 seconds navUpdates.js sends the nav bar's request along (?nav=), and
//   then either answer has "nav" too (hbHubNavRide() in assets/php/navUpdates.php).
// - POST room=id&action=... does something in the game, and answers with the whole game the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     play (card: like "r7" or "W", or several with the same number or symbol in the order they were clicked, like
//       "r7,r7,b7" - game.php's hbHubUnoPlayCard(); colour: r, y, g or b for a wild card, uno: 1 when they called UNO
//       with it), draw and
//       keep (the card just drawn) - all three with turn: the turn's id the user saw, so a double click only counts once
//     uno (call it after playing the second-to-last card) and catch (someone who didn't)
//     invite (user), for the host while there's a free seat
//     start, for the host once at least 2 players are in (game.php's hbHubUnoStart()), also for a new game
//     pause and resume, for the host while a game is being played (game.php's hbHubUnoPause())
//     settings (turnSeconds, stacking: on or off, drawUntil: on or off), for the host while no game is being played
//       (game.php's hbHubUnoChangeSettings())
//     chat (text) writes in the game's chat (chat.php), and leave
//   The game has the chat's newest messages in "chat", with isYou on the user's own and the text cut up into its
//   @mentions in "parts" (hbHubMentionParts()), and who can be mentioned in "chatMentions" (for mentions.js).
// Answers 404 with {"error": "notSeated"} when the user isn't in that game (anymore).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;

$seat = hbHubUnoUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubUnoTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubUnoLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

// Writing in the chat doesn't change the game, so it's answered like just looking at it
$chatError = null;
if ($action === 'chat') {
    $chatError = hbHubUnoSay($db, $roomId, $userId, $input['text'] ?? null);
    $action = null;
}

/**
 * Something the user does as a player in the game being played: $do gets the state and their seat in the game.
 */
$player = static fn (callable $do): callable => static function (array &$room) use ($do, $userId): ?string {
    $seat = $room['state']['phase'] === 'playing' ? hbHubUnoGameSeatOfUser($room['state'], $userId) : null;
    if ($seat === null) {
        return 'You aren\'t playing a game right now.';
    }
    return $do($room['state'], $seat);
};

/**
 * A move in the user's turn: only when it's still the turn they saw (otherwise it was already made - a double click).
 */
$move = static fn (callable $do): callable => $player(static fn (array &$state, int $seat): ?string
    => ($input['turn'] ?? null) === (string) $state['game']['turn']['id'] ? $do($state, $seat) : null);

$do = match ($action) {
    null => null,
    'play' => $move(static fn (array &$state, int $seat): ?string => hbHubUnoPlayCard($state, $seat, $input['card'] ?? null,
        $input['colour'] ?? null, ($input['uno'] ?? null) === '1', hbHubUnoNowMs())),
    'draw' => $move(static fn (array &$state, int $seat): ?string => hbHubUnoDraw($state, $seat, hbHubUnoNowMs())),
    'keep' => $move(static fn (array &$state, int $seat): ?string => hbHubUnoKeep($state, $seat, hbHubUnoNowMs())),
    'uno' => $player(static fn (array &$state, int $seat): ?string => hbHubUnoCallUno($state, $seat)),
    'catch' => $player(static fn (array &$state, int $seat): ?string => hbHubUnoCatch($state, $seat)),
    'pause', 'resume' => static fn (array &$room): ?string => hbHubUnoPause($room['state'], $room['roomHostUserId'] === $userId,
        $action === 'pause', hbHubUnoNowMs()),
    'invite' => static fn (array &$room): ?string => hbHubUnoInvite($db, $room, $userId, $input['user'] ?? null),
    'start' => static fn (array &$room): ?string => hbHubUnoStart($room['state'], $room['roomHostUserId'] === $userId, hbHubUnoNowMs()),
    'settings' => static fn (array &$room): ?string => hbHubUnoChangeSettings($room['state'], $room['roomHostUserId'] === $userId,
        $input['turnSeconds'] ?? null, $input['stacking'] ?? null, $input['drawUntil'] ?? null),
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking only locks the room when there's something to move on (a turn ran out, someone has gone, or the host
// isn't there)
$played = null;
if ($do === null) {
    $room = hbHubUnoRoom($db, $roomId);
    $seats = $room !== null ? hbHubUnoSeats($db, $roomId) : [];
    if ($room !== null && !hbHubUnoNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubUnoPlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted']) || !hbHubUnoSeated($played[1], $userId)) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // taken out of the game (gone too long)
    exit;
}
[$room, $seats, $error] = $played;
$error ??= $chatError;

$version = hbHubUnoVersion($room, $seats, hbHubUnoLastChatId($db, $roomId));
$nav = hbHubNavRide($db, $hbHubUser); // the nav bar's numbers, when navUpdates.js sent its request along (?nav=)
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true] + ($nav !== null ? ['nav' => $nav] : []), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$state = $room['state'];
$people = hbHubUnoPeople($db, [
    ...array_column($state['players'], 'userId'),
    ...array_column($state['game']['players'] ?? [], 'userId'),
]);

// The chat's @mentions (assets/php/mentions.php) are of the players in the room. Being back in the game means the user
// has seen any they were sent a notification about.
$chatPeople = hbHubUnoChatPeople($db, $roomId, $userId);
$chatMentions = hbHubMentionLookup($chatPeople);
hbHubMarkNotificationsReadAbout($db, $userId, 'games.unoMention', ['roomId' => $roomId]);
$chat = array_map(static fn (array $message): array => [
    'id' => $message['id'],
    'name' => $message['name'],
    'text' => $message['text'],
    'parts' => hbHubMentionParts($message['text'], $chatMentions),
    'time' => $message['time'],
    'isYou' => $message['userId'] === $userId,
], hbHubUnoChat($db, $roomId));
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
            'type' => HBHUB_UNO_ROOM_TYPES[$room['roomType']] ?? 'public',
        ],
    ] + hbHubUnoView($state, $people, $seats, $userId, $room['roomHostUserId'], hbHubUnoNowMs())
        + ['chat' => $chat, 'chatMentions' => $chatNames],
];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($nav !== null) {
    $response['nav'] = $nav;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
