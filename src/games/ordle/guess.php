<?php
// Ordle's JSON, for ordle.js (the page around it is index.php). POST only:
//   action=guess, lang (en or da), date (the day the page is playing, like 2026-10-19), turn (how many guesses the
//   page shows, so a double click only counts once) and word.
// Answers with the user's game of today in that language as the page shows it (hbHubOrdleView() in ordle.php) in
// "game", and their stats in it (hbHubOrdleStats()) in "stats". Errors:
// - 422 {"error": "notWord"}: not a word in the word list. Nothing is stored.
// - 409 {"error": "newDay"}: the page is still on yesterday's word. It reloads to get today's.
// - 400 {"error": "badRequest"}: no such language or action.
// A guess on an older view of the game (another tab, a double click) or in a game that's over isn't an error: the
// answer is just the game as it is, for the page to catch up with.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/ordle/ordle.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$language = $_POST['lang'] ?? null;
if (($_POST['action'] ?? null) !== 'guess' || !is_string($language) || !isset(HBHUB_ORDLE_LANGUAGES[$language])) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

if (($_POST['date'] ?? null) !== hbHubOrdleToday()) {
    http_response_code(409);
    echo json_encode(['error' => 'newDay']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

if (hbHubOrdleGuess($db, $userId, $language, $_POST['word'] ?? null, $_POST['turn'] ?? null) === 'notWord') {
    http_response_code(422);
    echo json_encode(['error' => 'notWord']);
    exit;
}

$date = hbHubOrdleToday();
echo json_encode([
    'game' => hbHubOrdleView(hbHubOrdleGame($db, $userId, $language, $date), hbHubOrdleDailyWord($db, $language, $date)),
    'stats' => hbHubOrdleUserStats($db, $userId, $language),
]);
