<?php
// Where Chrome (and other Chromium browsers that have it switched on) sends its own note when the tab of Ore Mining Inc.
// dies, so we can tell a tab that ran out of memory or hung from everything else (crash report #36 couldn't). The game's
// page names this address in its Reporting-Endpoints header (games/spaceOreMiner/index.php, accounts only), and the
// browser POSTs here by itself, later on, with no page and no script involved: the page is gone by then.
//
// It's on purpose that this doesn't load session.php, unlike every other endpoint. The browser may send this site's
// cookies along, and nobody was asked about this report, so it mustn't know who it's about: the privacy page promises
// that reports tied to a player are only sent if they say yes. So it's also open to anyone, which is why only a few
// fixed fields are kept and there's a limit a day for all reports together (see hbHubMinerBrowserCrashSave()).
//
// The body (Content-Type: application/reports+json) is a JSON list of reports: {type, age (ms since it happened), url,
// user_agent, body: {reason}}. The reason is 'oom' or 'unresponsive', or left out when the browser doesn't know it (kept
// as 'unknown'). Not every crash sends one, and a computer that turned off never does, so no note proves nothing.
// Answers 204, also for reports it ignored or over the limit (an error would make the browser try again later). Errors:
// - 400 {"error": "badRequest"}: not a JSON list of the right type, or too big. A form on another site can't send this
//   type without asking first, so that also keeps other sites from sending reports through a visitor's browser.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/crashReports.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
$body = file_get_contents('php://input', false, null, 0, HBHUB_MINER_BROWSER_CRASH_MAX_BYTES + 1);
$reports = is_string($body) && strlen($body) <= HBHUB_MINER_BROWSER_CRASH_MAX_BYTES ? json_decode($body, true, 8) : null;
if ($contentType !== 'application/reports+json' || !is_array($reports) || !array_is_list($reports)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$db = hbHubDatabase();
foreach (array_slice($reports, 0, HBHUB_MINER_BROWSER_CRASH_PER_POST) as $report) {
    hbHubMinerBrowserCrashSave($db, $report);
}
http_response_code(204);
