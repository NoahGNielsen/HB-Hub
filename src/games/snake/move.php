<?php
// Snake's JSON, for the game on the page (assets/js/snake.js). POST only, with action= (and game=, the id, for all but
// new). The rules and the anticheat are in assets/php/games/snake/snake.php.
//   new: the next game, ready to start: {"game": id, "rollsFrom": 0, "rolls": [...]} (the page usually has one already,
//     from the page itself or the answer to the last game, so this is for when that didn't work).
//   start, speed (slow, normal or fast): the snake has started moving, so the clock starts. {"game": id}
//   tick / pause / resume / finish, steps (how many the snake has made in all) and turns (since the last report, like
//     "12u,15l"): {"game": id, "result": 0-6, "score": apples} and, while it's going, the next rolls ("rollsFrom",
//     "rolls"). A finished game (1) also has "best", "isBest" and "next", the next game (like new's answer).
//   scripted: a key press or swipe came from code, so the game ends as scripted. {"game": id, "result": 3}
// Errors:
// - 400 {"error": "badRequest"}: no such action or speed, or no game id.
// - 404 {"error": "notFound"}: not one of the user's games.
// - 409 {"error": "gameOver"}: started or over already, like when another tab made a new one.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/snake/snake.php';
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
    $view = hbHubSnakeNew($db, $userId);
} elseif ($gameId === false || !in_array($action, ['start', 'tick', 'pause', 'resume', 'finish', 'scripted'], true)) {
    $view = 'badRequest';
} elseif ($action === 'start') {
    $view = hbHubSnakeStart($db, $userId, $gameId, $_POST['speed'] ?? null);
} elseif ($action === 'scripted') {
    $view = hbHubSnakeScripted($db, $userId, $gameId);
} else {
    $view = hbHubSnakeReport($db, $userId, $gameId, $action, $_POST['steps'] ?? null, $_POST['turns'] ?? '');
    if (is_array($view) && $view['result'] === HBHUB_SNAKE_FINISHED) {
        $view['next'] = hbHubSnakeNew($db, $userId); // saves the page asking for it
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
