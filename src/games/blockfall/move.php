<?php
// Blockfall's JSON, for the game on the page (assets/js/blockfall.js). POST only, with action= (and game=, the id, for
// all but new). The rules and the anticheat are in assets/php/games/blockfall/blockfall.php.
//   new: the next game, ready to start: {"game": id, "piecesFrom": 0, "pieces": "TIOS..."} (the page usually has one
//     already, from the page itself or the answer to the last game).
//   start: the first piece has come in, so the clock starts. {"game": id}
//   tick / pause / resume / finish, ticks (how many the game has played in all) and inputs (since the last report,
//     like "120L,120+,131F"): {"game": id, "result": 0-6, "score", "lines", "ticks"} and, while it's going, the next
//     pieces ("piecesFrom", "pieces"). A finished game (1) also has "best", "isBest" and "next", the next game.
//   scripted: a key press or tap came from code, so the game ends as scripted. {"game": id, "result": 3}
// Errors:
// - 400 {"error": "badRequest"}: no such action, or no game id.
// - 404 {"error": "notFound"}: not one of the user's games.
// - 409 {"error": "gameOver"}: started or over already, like when another tab made a new one.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/blockfall/blockfall.php';
hbHubGamesJson($hbHubUser); // the JSON headers, and blocked in settings or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$action = $_POST['action'] ?? null;
$gameId = filter_var($_POST['game'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($action === 'new') {
    $view = hbHubBlockfallNew($db, $userId);
} elseif ($gameId === false || !in_array($action, ['start', 'tick', 'pause', 'resume', 'finish', 'scripted'], true)) {
    $view = 'badRequest';
} elseif ($action === 'start') {
    $view = hbHubBlockfallStart($db, $userId, $gameId);
} elseif ($action === 'scripted') {
    $view = hbHubBlockfallScripted($db, $userId, $gameId);
} else {
    $view = hbHubBlockfallReport($db, $userId, $gameId, $action, $_POST['ticks'] ?? null, $_POST['inputs'] ?? '');
    if (is_array($view) && $view['result'] === HBHUB_BLOCKFALL_FINISHED) {
        $view['next'] = hbHubBlockfallNew($db, $userId); // saves the page asking for it
    }
}

if (is_string($view)) {
    http_response_code(match ($view) {
        'notFound' => 404,
        'gameOver' => 409,
        default => 400,
    });
    echo json_encode(['error' => $view]);
    exit;
}
echo json_encode($view);
