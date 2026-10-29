<?php
// The guest Ore Mining Inc.'s JSON (/play/ore-mining-inc/save), for spaceOreMiner.js on /play/ore-mining-inc - the same
// as games/spaceOreMiner/save for members, but the game comes and goes as a sealed token instead of a row in
// HBHub-MinerSaves (see assets/php/guestGames/guestGames.php). POST only:
//   action=sync, token (the guest's game, or none for a new one), actions and recovered (as the members' version).
// Plays the actions on the game with the same rules (hbHubMinerReplay() in miner.php) and answers like the members'
// version, plus "token": the game after them, for the page to keep and send with its next save.
// The page itself makes sure only one tab plays (it keeps which one in the browser next to the token), and there's
// no limit on how often it saves, as there's nothing to count it against: each save is still at most
// HBHUB_MINER_MAX_ACTIONS actions. Errors: 400 {"error": "badRequest"} for no such action, or actions or recovered
// that aren't a JSON list.
// A guest can see the Cosmic Engine and gets its perks, but can't give it parts (nothing about guests is kept), so the
// save gets the Engine's stages and engineOpen false, and the answer its "engine" without who gave what (players'
// names are only shown to members).
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';
hbHubGuestGameJson('oreMiningInc'); // only while the games are open to everyone, and only POST

$actions = json_decode(is_string($_POST['actions'] ?? null) ? $_POST['actions'] : '[]', true, 8);
$recovered = json_decode(is_string($_POST['recovered'] ?? null) ? $_POST['recovered'] : '[]', true, 8);
if (($_POST['action'] ?? null) !== 'sync' || !is_array($actions) || !array_is_list($actions) || !is_array($recovered) || !array_is_list($recovered)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$now = hbHubMinerNow();
$saved = hbHubGuestOpen('miner', $_POST['token'] ?? null)['state'] ?? null;
$state = is_array($saved) ? hbHubMinerNormalize($saved, $now) : hbHubMinerNewState($now);
$replay = hbHubMinerReplay($state, $actions, $recovered, $now);
$db = hbHubDatabase();
$engine = hbHubMinerEngineRow($db, false);
hbHubMinerEngineAdopt($state, $engine, false);

echo json_encode([
    'state' => hbHubMinerView($state),
    'now' => $now,
    'offline' => $replay['offline'],
    'recovered' => $replay['recovered'],
    'engine' => hbHubMinerEngineSummary($db, $engine, null, false), // never who gave what: names are only for members
    'token' => hbHubGuestSeal('miner', ['state' => hbHubMinerView($state)]),
], JSON_PRESERVE_ZERO_FRACTION);
