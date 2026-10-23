<?php
// Ore Mining Inc.'s crash reports, for spaceOreMiner.js. POST only, and only after the player said yes when the page
// asked (the next time the game was opened after it froze or closed badly):
//   action=report, kind (a key of HBHUB_MINER_CRASH_KINDS) and report (the report as a JSON object, see
//   assets/php/games/spaceOreMiner/crashReports.php for what's in it).
// Answers {"ok": true}. Errors:
// - 400 {"error": "badRequest"}: no such action or kind, or a report that isn't a JSON object or is too big.
// - 429 {"error": "tooMany"}: HBHUB_MINER_CRASH_PER_DAY reports in the last day already. Nothing is saved.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/crashReports.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$kind = $_POST['kind'] ?? null;
$report = $_POST['report'] ?? null;
$report = is_string($report) && strlen($report) <= HBHUB_MINER_CRASH_MAX_BYTES ? json_decode($report, true, 32) : null;
if (($_POST['action'] ?? null) !== 'report' || !is_string($kind) || !isset(HBHUB_MINER_CRASH_KINDS[$kind])
    || !is_array($report) || array_is_list($report)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

if (!hbHubMinerCrashSave(hbHubDatabase(), (int) $hbHubUser['userId'], $kind, $report, $_SERVER['HTTP_USER_AGENT'] ?? null)) {
    http_response_code(429);
    echo json_encode(['error' => 'tooMany']);
    exit;
}

echo json_encode(['ok' => true]);
