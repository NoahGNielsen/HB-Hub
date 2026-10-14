<?php
// The Blackjack table's JSON, for blackjackTable.js (the page around it is table.php). Only for the user's own table:
// - GET ?room=id&v=version is the table as the user may see it (hbHubBlackjackTableView() in round.php), with their
//   chips and the table's version (hbHubBlackjackVersion() in room.php). When the version hasn't changed it's just
//   {"v": version, "same": true}. The table asks every second.
// - POST room=id&action=... does something at the table, and answers with the whole table the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     bet (amount), clearBet, insurance (take: 1 or 0), hit, stand, double and split (turn: the turnId the table
//     showed when it was clicked, so a double click only counts once), sitOut (out: 1 or 0), invite (user: their id,
//     only for the host) and leave
// Answers 404 with {"error": "notSeated"} when the user doesn't sit at that table (anymore).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/blackjack/room.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;

$seat = hbHubBlackjackUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubBlackjackTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubBlackjackLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

$do = match ($action) {
    null => null,
    'bet' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackBet($db, $room, $seats, $userId, $input['amount'] ?? null, $now),
    'clearBet' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackClearBet($db, $room, $userId, $now),
    'insurance' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackInsurance($db, $room, $userId, ($input['take'] ?? null) === '1'),
    'hit', 'stand', 'double', 'split' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackMove($db, $room, $userId, $action, $input['turn'] ?? null, $now),
    'sitOut' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackSitOut($db, $room, $seats, $userId, ($input['out'] ?? null) === '1', $now),
    'invite' => fn (array &$room, array &$seats, int $now): ?string
        => hbHubBlackjackInvite($db, $room, $seats, $userId, $input['user'] ?? null),
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
    $room = hbHubBlackjackRoom($db, $roomId);
    $seats = $room !== null ? hbHubBlackjackSeats($db, $roomId) : [];
    if ($room !== null && !hbHubBlackjackNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubBlackjackPlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted']) || hbHubBlackjackSeatOf($played[1], $userId) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // the room is gone, or the user was taken off it (gone too long)
    exit;
}
[$room, $seats, $error] = $played;

$version = hbHubBlackjackVersion($room, $seats);
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true]);
    exit;
}

$chips = hbHubCasinoWallet($db, $userId)['chips'];
$people = hbHubBlackjackPeople($db, [
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
            'type' => HBHUB_BLACKJACK_ROOM_TYPES[$room['roomType']] ?? 'public',
            'minBet' => $room['roomMinBet'],
            'seats' => hbHubBlackjackMaxSeats($room['roomType']),
            'players' => count($seats),
        ],
    ] + hbHubBlackjackTableView($room['state'], $seats, $people, $userId, $chips, $room['roomHostUserId'], hbHubBlackjackNow()),
];
if (is_string($error)) {
    $response['error'] = $error;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
