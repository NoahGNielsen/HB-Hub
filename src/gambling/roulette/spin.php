<?php
// Roulette's spins, for roulette.js (the page is index.php): POST bets={"17": 10, "red": 50} takes the bets from the
// user's chips, spins and pays out (hbHubRouletteSpin() in assets/php/casino/roulette/roulette.php). Answers with
// where the ball landed, what each winning bet paid and the chips after.
// Bets that aren't bets there are answer 400 with {"error": "badBets"}, and not having the chips for them
// {"error": "notEnoughChips", "chips": 1234}.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'postOnly']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$bets = hbHubRouletteCleanBets($_POST['bets'] ?? null);
if ($bets === null) {
    http_response_code(400);
    echo json_encode(['error' => 'badBets']);
    exit;
}

$spin = hbHubRouletteSpin($db, $userId, $bets);
if ($spin === null) {
    echo json_encode(['error' => 'notEnoughChips', 'chips' => hbHubCasinoWallet($db, $userId)['chips']]);
    exit;
}

$spin['wins'] = (object) $spin['wins']; // always {}, also with none or only a straight bet on 0 (which would be a list)
echo json_encode($spin);
