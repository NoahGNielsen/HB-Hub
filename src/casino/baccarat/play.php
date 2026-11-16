<?php
// The Baccarat table's JSON, for baccaratTable.js (the page around it is table.php). Only for the user's own table:
// - GET ?room=id&v=version is the table as the user may see it (hbHubBaccaratTableView() in round.php), with their
//   chips and the table's version (hbHubBaccaratVersion() in room.php). When the version hasn't changed it's just
//   {"v": version, "same": true}. The table asks every second. About every 15 seconds navUpdates.js sends the
//   nav bar's request along (?nav=), and then either answer has "nav" too (hbHubNavRide() in assets/php/navUpdates.php).
// - POST room=id&action=... does something at the table, and answers with the whole table the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     bet (bets[spot]=chips for each spot bet on: player, banker, tie, playerPair, bankerPair - all of them at once),
//     clearBet, sitOut (out: 1 or 0), invite (user: their id, only for the host) and leave
//   There's nothing to decide once the hand is dealt, so no moves.
// Answers 404 with {"error": "notSeated"} when the user doesn't sit at that table (anymore).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;

$seat = hbHubBaccaratUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubBaccaratTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubBaccaratLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

$do = match ($action) {
    null => null,
    'bet' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBaccaratBet($db, $room, $seats, $userId, $input['bets'] ?? null, $now),
    'clearBet' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBaccaratClearBet($db, $room, $userId, $now),
    'sitOut' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBaccaratSitOut($db, $room, $seats, $userId, ($input['out'] ?? null) === '1', $now),
    'invite' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBaccaratInvite($db, $room, $seats, $userId, $input['user'] ?? null),
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking only locks the room when there's something to move on (a timer is running, or someone has gone)
$played = null;
if ($do === null) {
    $room = hbHubBaccaratRoom($db, $roomId);
    $seats = $room !== null ? hbHubBaccaratSeats($db, $roomId) : [];
    if ($room !== null && !hbHubBaccaratNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubBaccaratPlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted']) || hbHubBaccaratSeatOf($played[1], $userId) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // the room is gone, or the user was taken off it (gone too long)
    exit;
}
[$room, $seats, $error] = $played;

$version = hbHubBaccaratVersion($room, $seats);
$nav = hbHubNavRide($db, $hbHubUser); // the nav bar's numbers, when navUpdates.js sent its request along (?nav=)
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true] + ($nav !== null ? ['nav' => $nav] : []), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$chips = hbHubCasinoWallet($db, $userId)['chips'];
$people = hbHubBaccaratPeople($db, [
    ...array_column($seats, 'userId'),
    ...array_column($room['state']['round']['players'] ?? [], 'userId'),
]);

$response = [
    'v' => $version,
    'chips' => $chips,
    'table' => [
        'room' => [
            'id' => $room['roomId'],
            'name' => $room['roomName'],
            'code' => $room['roomCode'],
            'type' => HBHUB_BACCARAT_ROOM_TYPES[$room['roomType']] ?? 'public',
            'minBet' => $room['roomMinBet'],
            'seats' => hbHubBaccaratMaxSeats($room['roomType']),
            'players' => count($seats),
        ],
    ] + hbHubBaccaratTableView($room['state'], $seats, $people, $userId, $room['roomHostUserId'], hbHubBaccaratNow()),
];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($nav !== null) {
    $response['nav'] = $nav;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
