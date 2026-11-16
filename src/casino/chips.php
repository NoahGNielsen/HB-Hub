<?php
// The user's chips for casino.js, as JSON (hbHubCasinoStatus() in assets/php/casino/wallet.php):
// - GET /casino/chips is the chips, and whether the daily chips can be collected (or how long until they can)
// - POST action=claimDaily collects the daily chips. "claimed" says whether it did (false when they'd already been
//   collected this casino day, like in another tab), and "claimedAmount" how many chips it gave (0 when it didn't),
//   which is only part of the day's chips when another of the person's accounts already had theirs (daily.shared).
// - POST action=claimBonus collects the 3-hour bonus (bonus in the answer), answered the same way: "claimed" is false
//   when it wasn't ready yet.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$response = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $claimedAmount = match ($_POST['action'] ?? null) {
        'claimDaily' => hbHubCasinoClaimDaily($db, $userId),
        'claimBonus' => hbHubCasinoClaimBonus($db, $userId),
        default => false,
    };
    if ($claimedAmount === false) {
        http_response_code(400);
        echo json_encode(['error' => 'unknownAction']);
        exit;
    }
    $response['claimed'] = $claimedAmount !== null;
    $response['claimedAmount'] = $claimedAmount ?? 0;
}

echo json_encode($response + hbHubCasinoStatus($db, $userId));
