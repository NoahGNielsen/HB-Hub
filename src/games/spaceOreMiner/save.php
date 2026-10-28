<?php
// Ore Mining Inc.'s JSON, for spaceOreMiner.js (the page around it is index.php). POST only:
//   action=sync, tab (the page's own random id, 32 hex characters), claim=1 on the page's first save (it takes the game
//   over from any other tab) and actions (a JSON list of what the player did since the last save, each with "at", the
//   time in Unix ms; see hbHubMinerApply() and hbHubMinerSync() in assets/php/games/spaceOreMiner/miner.php), and on
//   the first save, recovered (the same kind of list: what a page of the game that crashed or was closed never got to
//   save; only what's newer than the save counts), and guest (the token of a game played as a guest at
//   /play/ore-mining-inc, which the page finds in the browser after logging in or signing up from there).
// Plays the actions on the saved game and answers with {"state": the save, "now": the server's time in Unix ms,
// "offline": null or {"ms": time away, "ore": mined meanwhile}, "recovered": how many recovered actions counted}, and
// with a guest token, "guest": "imported" when the guest game became the save (the user had none yet), or "kept"
// when they already had one, which is never written over (hbHubMinerSync() in miner.php).
// Anything that couldn't have happened just doesn't count, the page carries on from the save it gets back. Errors:
// - 409 {"error": "otherTab"}: the game has been opened in another tab since. Nothing is saved.
// - 429 {"error": "tooFast"}: more saves than HBHUB_MINER_SAVES_MAX in HBHUB_MINER_SAVES_SECONDS, far more than the page
//   ever sends. Nothing is saved; the page keeps the actions and sends them again with its next save.
// - 400 {"error": "badRequest"}: no such action, a bad tab id, or actions or recovered that aren't a JSON list.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$tab = $_POST['tab'] ?? null;
$actions = json_decode(is_string($_POST['actions'] ?? null) ? $_POST['actions'] : '[]', true, 8);
$recovered = json_decode(is_string($_POST['recovered'] ?? null) ? $_POST['recovered'] : '[]', true, 8);
if (($_POST['action'] ?? null) !== 'sync' || !is_string($tab) || !preg_match(HBHUB_MINER_TAB_PATTERN, $tab)
    || !is_array($actions) || !array_is_list($actions) || !is_array($recovered) || !array_is_list($recovered)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$claim = ($_POST['claim'] ?? null) === '1';
$guestState = $claim ? hbHubGuestOpen('miner', $_POST['guest'] ?? null)['state'] ?? null : null;
$result = hbHubMinerSync(hbHubDatabase(), (int) $hbHubUser['userId'], $tab, $claim, $actions, $recovered,
    is_array($guestState) ? $guestState : null);
if ($result === 'otherTab') {
    http_response_code(409);
    echo json_encode(['error' => 'otherTab']);
    exit;
}
if ($result === 'tooFast') {
    http_response_code(429);
    header('Retry-After: ' . HBHUB_MINER_SAVES_SECONDS);
    echo json_encode(['error' => 'tooFast']);
    exit;
}

echo json_encode($result);
