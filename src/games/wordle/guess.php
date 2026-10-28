<?php
// Wordle's JSON, for wordle.js (the page around it is index.php). POST only:
//   action=guess, lang (en or da), date (the day the page is playing, like 2026-10-19), turn (how many guesses the
//   page shows, so a double click only counts once) and word.
// Answers with the user's game of today in that language as the page shows it (hbHubWordleView() in wordle.php) in
// "game", and their stats in it (hbHubWordleStats()) in "stats". Errors:
// - 422 {"error": "notWord"}: not a word in the word list. Nothing is stored.
// - 409 {"error": "newDay"}: the page is still on yesterday's word. It reloads to get today's.
// - 400 {"error": "badRequest"}: no such language or action.
// A guess on an older view of the game (another tab, a double click) or in a game that's over isn't an error: the
// answer is just the game as it is, for the page to catch up with.
// Also action=import, tokens (a JSON object, language => token): brings the games the user played today as a guest on
// /play/wordle over to their account, after they logged in or signed up from there (hbHubWordleImport() in wordle.php).
// The page sends it when it finds the guest's tokens in the browser. Answers {"imported": [the languages brought over]}.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

if (($_POST['action'] ?? null) === 'import') {
    $tokens = json_decode(is_string($_POST['tokens'] ?? null) ? $_POST['tokens'] : '{}', true, 4);
    echo json_encode(['imported' => hbHubWordleImport(hbHubDatabase(), (int) $hbHubUser['userId'], is_array($tokens) ? $tokens : [])]);
    exit;
}

$language = $_POST['lang'] ?? null;
if (($_POST['action'] ?? null) !== 'guess' || !is_string($language) || !isset(HBHUB_WORDLE_LANGUAGES[$language])) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

if (($_POST['date'] ?? null) !== hbHubWordleToday()) {
    http_response_code(409);
    echo json_encode(['error' => 'newDay']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

if (hbHubWordleGuess($db, $userId, $language, $_POST['word'] ?? null, $_POST['turn'] ?? null) === 'notWord') {
    http_response_code(422);
    echo json_encode(['error' => 'notWord']);
    exit;
}

$date = hbHubWordleToday();
echo json_encode([
    'game' => hbHubWordleView(hbHubWordleGame($db, $userId, $language, $date), hbHubWordleDailyWord($db, $language, $date)),
    'stats' => hbHubWordleUserStats($db, $userId, $language),
]);
