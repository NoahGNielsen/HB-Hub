<?php
// The user's chips for casino.js, as JSON (hbHubCasinoStatus() in assets/php/casino/wallet.php):
// - GET /gambling/chips is the chips, and whether the daily chips can be collected (or how long until they can)
// - POST action=claimDaily collects the daily chips. "claimed" says whether it did: false when they'd already been
//   collected this casino day, like in another tab.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$response = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? null) !== 'claimDaily') {
        http_response_code(400);
        echo json_encode(['error' => 'unknownAction']);
        exit;
    }
    $response['claimed'] = hbHubCasinoClaimDaily($db, $userId) !== null;
}

echo json_encode($response + hbHubCasinoStatus($db, $userId));
