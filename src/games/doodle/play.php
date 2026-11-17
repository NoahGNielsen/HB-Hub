<?php
// Doodle Chain's JSON, for doodleGame.js (the page around it is game.php). Only for the user's own game:
// - GET ?room=id&v=version&have=1,2,3 is the game as the user sees it (hbHubDoodleView() in room.php), with the game's
//   version (hbHubDoodleVersion()). When the version hasn't changed it's just {"v": version, "same": true}. have is the
//   entry ids of the drawings the page already has, which then come without their strokes.
//   The game asks every second or so. About every 15 seconds navUpdates.js sends the nav bar's request along (?nav=),
//   and then either answer has "nav" too (hbHubNavRide() in assets/php/navUpdates.php).
// - POST room=id&action=... does something in the game, and answers with the whole game the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     hand (step: the step the user saw, so a double click or an answer that was too slow only counts once; text in a
//       step that's written, drawing - the strokes' JSON, game.php - in one that's drawn), room.php's hbHubDoodleHandIn()
//     start, for the host once enough players are in, also for a new game
//     next (the next entry of the chain being shown) and show (chain: once all have been shown), for the host
//     settings (drawSeconds, drawings), for the host while no game is being played
//     leave
// Answers 404 with {"error": "notSeated"} when the user isn't in that game (anymore).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$isPost && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}
$input = $isPost ? $_POST : $_GET;

$seat = hbHubDoodleUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubDoodleTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubDoodleLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

$isHost = static fn (array $room): bool => $room['roomHostUserId'] === $userId;
$do = match ($action) {
    null => null,
    // Only in the step the user saw: otherwise it was already handed in (a double click), or the step has moved on
    'hand' => static fn (array &$room): ?string => $room['state']['phase'] === 'playing'
        && ($input['step'] ?? null) === (string) $room['state']['game']['step']
            ? hbHubDoodleHandIn($db, $room, $userId, $input['text'] ?? null, $input['drawing'] ?? null)
            : 'Too late: that round is already over.',
    'start' => static fn (array &$room): ?string => hbHubDoodleStartGame($db, $room, $userId),
    'next' => static fn (array &$room): ?string => hbHubDoodleRevealNext($room['state'], $isHost($room)),
    'show' => static fn (array &$room): ?string => hbHubDoodleShowChain($room['state'], $isHost($room), $input['chain'] ?? null),
    'settings' => static fn (array &$room): ?string => hbHubDoodleChangeSettings($room['state'], $isHost($room),
        $input['drawSeconds'] ?? null, $input['drawings'] ?? null),
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking only locks the room when there's something to move on (a step's time is up, someone has gone, or the
// host isn't there)
$played = null;
if ($do === null) {
    $room = hbHubDoodleRoom($db, $roomId);
    $seats = $room !== null ? hbHubDoodleSeats($db, $roomId) : [];
    if ($room !== null && !hbHubDoodleNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubDoodlePlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted']) || !hbHubDoodleSeated($played[1], $userId)) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // taken out of the game (gone too long)
    exit;
}
[$room, $seats, $error] = $played;

$version = hbHubDoodleVersion($room, $seats);
$nav = hbHubNavRide($db, $hbHubUser); // the nav bar's numbers, when navUpdates.js sent its request along (?nav=)
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true] + ($nav !== null ? ['nav' => $nav] : []), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$have = [];
if (is_string($input['have'] ?? null)) {
    foreach (array_slice(explode(',', $input['have']), 0, HBHUB_DOODLE_HAVE_MAX) as $id) {
        if (ctype_digit($id) && strlen($id) <= 10) {
            $have[] = (int) $id;
        }
    }
}

$response = [
    'v' => $version,
    'game' => [
        'room' => [
            'id' => $room['roomId'],
            'name' => $room['roomName'],
            'code' => $room['roomCode'],
            'type' => HBHUB_DOODLE_ROOM_TYPES[$room['roomType']] ?? 'public',
        ],
    ] + hbHubDoodleView($db, $room, $seats, hbHubDoodlePeople($db, array_column($room['state']['players'], 'userId')),
        $userId, $have, hbHubDoodleNowMs()),
];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($nav !== null) {
    $response['nav'] = $nav;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
