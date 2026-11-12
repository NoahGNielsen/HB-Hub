<?php
// The slot machine's JSON, for slots.js (the page is index.php). The rules are in assets/php/casino/slots/slots.php.
// - POST action=spin&bet=50&request=<slots.js's own id for the spin> spins the reels: takes the bet, pays what it won
//   and logs it. Answers {chips, spin: {id, stops: [3, 17, 9], window: [[top row], [middle], [bottom]], bet, payout,
//   net, lines: [{line, pays, payout}]}}. The same request id twice gives the first spin back, so it only counts once.
//   Errors: badBet, badRequest or notEnoughChips (with "chips", so the page can show what's really there).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'badMethod']);
    exit;
}
if (($_POST['action'] ?? null) !== 'spin') {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$requestId = $_POST['request'] ?? null;
if (!is_string($requestId) || preg_match(HBHUB_SLOTS_REQUEST_PATTERN, $requestId) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$betText = $_POST['bet'] ?? null;
$bet = is_string($betText) && preg_match('~^[1-9][0-9]{0,11}$~', $betText) === 1 ? (int) $betText : null;
$chips = hbHubCasinoWallet($db, $userId)['chips'];
if (!hbHubSlotsIsBet($bet, $chips)) {
    http_response_code(400);
    echo json_encode(['error' => 'badBet']);
    exit;
}

$result = hbHubSlotsSpin($db, $userId, $bet, $requestId);
if ($result === null) {
    http_response_code(409);
    echo json_encode(['error' => 'notEnoughChips', 'chips' => hbHubCasinoWallet($db, $userId)['chips']]);
    exit;
}

echo json_encode(['chips' => $result['chips'], 'spin' => $result['spin']]);
