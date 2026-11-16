<?php
// Sudoku's JSON, for the game on the page (assets/js/sudoku.js). POST only, with action= and, for all but new, game
// (the id the new game's answer gave). The game itself is in assets/php/games/sudoku/sudoku.php.
//   action=new and level (easy, medium, hard or expert): starts a new game, ending the one being played (as left).
//     {"game": id, "level": key, "puzzle": "81 digits", "hints": 0, "ms": 0}
//   action=pause / action=resume: stops or starts the game's clock, for the Pause button and leaving the page and
//     coming back. Doing it twice is fine. {"game": id, "ms": played so far}
//   action=hint, square (0-80, or -1 for any) and values (the board as the page has it, 81 digits, 0 = empty): the
//     right number for that square, or a random empty or wrong one. {"game": id, "square": 0-80, "digit": 1-9,
//     "hints": used so far}
//   action=solve and values (the full board): checks it and ends the game. {"game": id, "result": 1-4, "ms": its time,
//     "hints": n, "best": ms or null, "isBest": bool, "solved": ranked solves on the level} (result as gameResult in
//     HBHub-SudokuGames: 1 solved, 2 solved with hints, 4 too fast)
// Errors:
// - 400 {"error": "badRequest"}: no such action, level, square or board.
// - 404 {"error": "notFound"}: not one of the user's games.
// - 409 {"error": "gameOver"}: the game is over already, like when another tab started a new one.
// - 409 {"error": "notSolved"}: the board sent to solve isn't the answer.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/sudoku.php';
hbHubGamesJson($hbHubUser); // the JSON headers, and blocked in settings or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$action = $_POST['action'] ?? null;
$gameId = filter_var($_POST['game'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($action, ['new', 'pause', 'resume', 'hint', 'solve'], true) || ($action !== 'new' && $gameId === false)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$view = match ($action) {
    'new' => hbHubSudokuNew($db, $userId, $_POST['level'] ?? null),
    'pause', 'resume' => hbHubSudokuPause($db, $userId, $gameId, $action === 'pause'),
    'hint' => hbHubSudokuHint($db, $userId, $gameId, $_POST['square'] ?? null, $_POST['values'] ?? null),
    'solve' => hbHubSudokuSolve($db, $userId, $gameId, $_POST['values'] ?? null),
};
if (is_string($view)) {
    http_response_code(match ($view) {
        'notFound' => 404,
        'gameOver', 'notSolved' => 409,
        default => 400,
    });
    echo json_encode(['error' => $view]);
    exit;
}
echo json_encode($view);
