<?php
// The guest Wordle's JSON (/play/wordle/guess), for wordle.js on /play/wordle - the same as games/wordle/guess for
// members, but the game comes and goes as a sealed token instead of being stored (see
// assets/php/guestGames/guestGames.php and "Guests" in assets/php/games/wordle/wordle.php). POST only:
// - action=view, lang and tokens (a JSON object of the guest's tokens of today, language => token): the game in that
//   language as the page shows it (hbHubWordleView()) in "game", and how far they are in each language in
//   "statuses" (language => new, playing, won or lost), for the tabs. The page asks once it's loaded, when the
//   browser has a token from today.
// - action=guess, lang, date (the day the page is playing), turn (how many guesses the page shows), word and token
//   (the language's token, or none for a new game): the game after the guess in "game", and its new token in "token".
//   "stats" is null, as guests have none.
// - mode=random: the guest's random word instead (see "Random words" in wordle.php), in its own token ("token", for
//   action=view, guess and new, which only gives a new word once the last one is over). The answer always has the
//   game's new token in "token", and for action=view the tabs' "statuses" from the daily tokens in "tokens".
// Errors like the members' version: 422 {"error": "notWord"} (nothing changes), 409 {"error": "newDay"} and 400
// {"error": "badRequest"}. A guess on an older view of the game, or in a game that's over, answers with the game as
// it is.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';
hbHubGuestGameJson('wordle'); // only while the games are open to everyone, and only POST

const WORDLE_GUEST_STATUSES = [HBHUB_WORDLE_PLAYING => 'playing', HBHUB_WORDLE_WON => 'won', HBHUB_WORDLE_LOST => 'lost'];

$action = $_POST['action'] ?? null;
$language = $_POST['lang'] ?? null;
$isRandom = ($_POST['mode'] ?? null) === 'random';
if (!in_array($action, ['view', 'guess', 'new'], true) || !is_string($language) || !isset(HBHUB_WORDLE_LANGUAGES[$language])
    || ($action === 'new' && !$isRandom)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$db = hbHubDatabase();
$date = hbHubWordleToday();

/**
 * How far the guest is with each language's daily word, from their tokens (language => new, playing, won or lost).
 */
function wordleGuestStatuses(PDO $db, array $tokens, string $date): array
{
    $statuses = [];
    foreach (array_keys(HBHUB_WORDLE_LANGUAGES) as $code) {
        $guesses = hbHubWordleGuestGuesses($tokens[$code] ?? null, $code, $date);
        $statuses[$code] = $guesses === [] ? 'new' : WORDLE_GUEST_STATUSES[hbHubWordleResult($guesses, hbHubWordleDailyWord($db, $code, $date))];
    }
    return $statuses;
}

$tokens = json_decode(is_string($_POST['tokens'] ?? null) ? $_POST['tokens'] : '{}', true, 4);
$tokens = is_array($tokens) ? $tokens : [];

// Random words: the game is in its own token ("token"), and a broken or missing one is just a new word
if ($isRandom) {
    $game = hbHubWordleGuestRandomGame($db, $_POST['token'] ?? null, $language);
    $result = hbHubWordleResult($game['guesses'], $game['word']);
    if ($action === 'new' && $result !== HBHUB_WORDLE_PLAYING) {
        $game = ['word' => hbHubWordleRandomWord($db, $language, $game['word']), 'guesses' => []];
    } elseif ($action === 'guess') {
        $guess = hbHubWordleCleanGuess($language, $_POST['word'] ?? null);
        if ($guess === null || !hbHubWordleIsWord($language, $guess)) {
            http_response_code(422);
            echo json_encode(['error' => 'notWord']);
            exit;
        }
        $turn = $_POST['turn'] ?? null;
        if ($result === HBHUB_WORDLE_PLAYING && is_string($turn) && $turn === (string) count($game['guesses'])) {
            $game['guesses'][] = $guess;
        }
    }
    echo json_encode([
        'game' => hbHubWordleView(['guesses' => $game['guesses'], 'result' => hbHubWordleResult($game['guesses'], $game['word'])], $game['word']),
        'stats' => null,
        'token' => hbHubWordleGuestRandomToken($language, $game),
        'statuses' => $action === 'view' ? wordleGuestStatuses($db, $tokens, $date) : null,
    ]);
    exit;
}

if ($action === 'view') {
    $statuses = wordleGuestStatuses($db, $tokens, $date);
    $guesses = hbHubWordleGuestGuesses($tokens[$language] ?? null, $language, $date);
    $answer = hbHubWordleDailyWord($db, $language, $date);
    echo json_encode([
        'game' => hbHubWordleView(['guesses' => $guesses, 'result' => hbHubWordleResult($guesses, $answer)], $answer),
        'statuses' => $statuses,
    ]);
    exit;
}

if (($_POST['date'] ?? null) !== $date) {
    http_response_code(409);
    echo json_encode(['error' => 'newDay']);
    exit;
}

$guess = hbHubWordleCleanGuess($language, $_POST['word'] ?? null);
if ($guess === null || !hbHubWordleIsWord($language, $guess)) {
    http_response_code(422);
    echo json_encode(['error' => 'notWord']);
    exit;
}

$guesses = hbHubWordleGuestGuesses($_POST['token'] ?? null, $language, $date);
$answer = hbHubWordleDailyWord($db, $language, $date);
// Only on the game the page showed, and while it's still going (like hbHubWordleGuess() for members)
$turn = $_POST['turn'] ?? null;
if (hbHubWordleResult($guesses, $answer) === HBHUB_WORDLE_PLAYING && is_string($turn) && $turn === (string) count($guesses)) {
    $guesses[] = $guess;
}

echo json_encode([
    'game' => hbHubWordleView(['guesses' => $guesses, 'result' => hbHubWordleResult($guesses, $answer)], $answer),
    'stats' => null,
    'token' => hbHubWordleGuestToken($language, $date, $guesses),
]);
