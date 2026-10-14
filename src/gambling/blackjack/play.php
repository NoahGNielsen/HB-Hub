<?php
// The Blackjack table's JSON, for blackjackTable.js (the page around it is table.php). Only for the user's own table:
// - GET ?room=id&rev=n is the table as the user may see it (hbHubBlackjackTableView() in round.php), with their chips.
//   When nothing has changed since revision n it's just {"rev": n, "same": true}. The table asks every second.
// - POST room=id&action=... does something at the table, and answers with the whole table the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     bet (amount), clearBet, insurance (take: 1 or 0), leave, and hit, stand, double and split (turn: the turnId the
//     table showed when it was clicked, so a double click only counts once)
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
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking: while nothing is timed (waiting for the first bet) there's nothing to catch up with, so the room
// doesn't need locking
$room = $do === null ? hbHubBlackjackRoom($db, $roomId) : null;
if ($room !== null && $room['state']['deadline'] === null) {
    $played = [$room, hbHubBlackjackSeats($db, $roomId), null];
} else {
    $played = hbHubBlackjackPlay($db, $roomId, $do);
}
if ($played === null) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // the room was deleted meanwhile
    exit;
}
[$room, $seats, $error] = $played;

$rev = $room['roomRevision'];
if (!$isPost && ($input['rev'] ?? null) === (string) $rev) {
    echo json_encode(['rev' => $rev, 'same' => true]);
    exit;
}

$chips = hbHubCasinoWallet($db, $userId)['chips'];
$people = hbHubBlackjackPeople($db, [
    ...array_column($seats, 'userId'),
    ...array_column($room['state']['round']['players'] ?? [], 'userId'),
]);

$response = [
    'rev' => $rev,
    'chips' => $chips,
    'table' => [
        'room' => [
            'id' => $room['roomId'],
            'name' => $room['roomName'],
            'code' => $room['roomCode'],
            'type' => HBHUB_BLACKJACK_ROOM_TYPES[$room['roomType']] ?? 'public',
            'minBet' => $room['roomMinBet'],
            'seats' => hbHubBlackjackMaxSeats($room['roomType']),
        ],
    ] + hbHubBlackjackTableView($room['state'], $seats, $people, $userId, $chips, hbHubBlackjackNow()),
];
if (is_string($error)) {
    $response['error'] = $error;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
