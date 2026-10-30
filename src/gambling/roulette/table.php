<?php
// The shared Roulette table's JSON, for roulette.js (the page is index.php). Everyone on the site plays at this one
// table (assets/php/casino/roulette/table.php):
// - GET ?v=version is the table as the user sees it (hbHubRouletteTableView()), with their chips and the table's
//   version. When the version hasn't changed it's just {"v": version, "same": true}. The page asks about every
//   second while it's in view, and spins the wheel when betting has closed (whoever asks first). About every 15
//   seconds navUpdates.js sends the nav bar's request along (?nav=), and then the answer has "nav" too
//   (hbHubNavRide() in assets/php/navUpdates.php).
// - POST action=bet&bets={"17": 10, "red": 50}&ready=0|1 puts the user's bets on the table instead of what they had on
//   it ({} takes them all off), and with ready=1 they're done for the round. Answers with the whole table the same way,
//   plus "error" when it couldn't be done: badBets, spinning, ready (already pressed Ready) or notEnoughChips.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/table.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$isPost && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'badMethod']);
    exit;
}

$do = null;
if ($isPost) {
    if (($_POST['action'] ?? null) !== 'bet') {
        http_response_code(400);
        echo json_encode(['error' => 'unknownAction']);
        exit;
    }
    $bets = in_array($_POST['bets'] ?? null, ['{}', '[]'], true) ? [] : hbHubRouletteCleanBets($_POST['bets'] ?? null);
    if ($bets === null) {
        http_response_code(400);
        echo json_encode(['error' => 'badBets']);
        exit;
    }
    $ready = ($_POST['ready'] ?? null) === '1';
    $do = fn (array &$table, int $now): ?string => hbHubRouletteTableBet($db, $table, $userId, $bets, $ready, $now);
}

// Just looking only locks the table when the wheel is due to spin
$now = hbHubRouletteNow();
$table = hbHubRouletteTable($db);
$error = null;
if ($do !== null || hbHubRoulettePhase($table, $now) === 'due') {
    [$table, $error] = hbHubRouletteTablePlay($db, $do);
    $now = hbHubRouletteNow();
}

$version = hbHubRouletteTableVersion($table, $now);
$nav = hbHubNavRide($db, $hbHubUser); // the nav bar's numbers, when navUpdates.js sent its request along (?nav=)
if (!$isPost && ($_GET['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true] + ($nav !== null ? ['nav' => $nav] : []), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$response = [
    'v' => $version,
    'chips' => hbHubCasinoWallet($db, $userId)['chips'],
    'table' => hbHubRouletteTableView($db, $table, $userId, $now),
];
if ($error !== null) {
    $response['error'] = $error;
}
if ($nav !== null) {
    $response['nav'] = $nav;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
