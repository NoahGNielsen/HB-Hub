<?php
// Ordle, a Wordle-style word game: guess the day's five-letter word in six tries, in English or Danish. Everyone gets
// the same two words each day (one per language), picked at random from words/<language>-answers.txt the first time
// someone asks for that day's word, and stored in HBHub-OrdleWords so it stays the same all day. A word isn't picked
// again until every other one has been used (and then only after HBHUB_ORDLE_REPEAT_DAYS).
// Each user plays each language once a day: one row in HBHub-OrdleGames per user, language and day, made by their
// first guess, with the guesses so far ("crane,slate") and how it's going (gameResult). The guesses are checked here,
// so the browser never knows the word until the game is over (hbHubOrdleView()).
// A day is a day in Denmark: the new words come at midnight there. A game that isn't won by the end of its day counts
// as lost, so giving up halfway doesn't save a streak or the win %.
// Used by games/ordle/ (the page), games/ordle/guess (the JSON the page sends guesses to) and games/ordle/leaderboard.
require_once __DIR__ . '/../../database.php';

const HBHUB_ORDLE_LANGUAGES = ['en' => 'English', 'da' => 'Danish']; // wordLanguage / gameLanguage
const HBHUB_ORDLE_LANGUAGE_NAMES = ['en' => 'English', 'da' => 'Dansk']; // what each one is called on its own tab
const HBHUB_ORDLE_ALPHABETS = ['en' => 'abcdefghijklmnopqrstuvwxyz', 'da' => 'abcdefghijklmnopqrstuvwxyzæøå'];
const HBHUB_ORDLE_KEYBOARDS = [ // the on-screen keyboard's rows of letters, like the language's own keyboard
    'en' => ['qwertyuiop', 'asdfghjkl', 'zxcvbnm'],
    'da' => ['qwertyuiopå', 'asdfghjklæø', 'zxcvbnm'],
];
const HBHUB_ORDLE_LENGTH = 5;
const HBHUB_ORDLE_TRIES = 6;
const HBHUB_ORDLE_TIME_ZONE = 'Europe/Copenhagen';
const HBHUB_ORDLE_REPEAT_DAYS = 365;

const HBHUB_ORDLE_PLAYING = 0; // gameResult in HBHub-OrdleGames
const HBHUB_ORDLE_WON = 1;
const HBHUB_ORDLE_LOST = 2;

/**
 * Today's date in Denmark, like 2026-10-19: which words are being played.
 */
function hbHubOrdleToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(HBHUB_ORDLE_TIME_ZONE)))->format('Y-m-d');
}

/**
 * When the next words come (midnight in Denmark), in Unix milliseconds, for the countdown on the page.
 */
function hbHubOrdleNextWordAt(): int
{
    return (new DateTimeImmutable('tomorrow', new DateTimeZone(HBHUB_ORDLE_TIME_ZONE)))->getTimestamp() * 1000;
}

/**
 * A date like 2026-10-19 as a number that goes up by one a day, to tell whether two days follow each other.
 */
function hbHubOrdleDayNumber(string $date): int
{
    return intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), 86400);
}

/**
 * A word list from words/: 'answers' (the daily words) or 'words' (everything that can be guessed), skipping the
 * # comments at the top. Read once per request. Lines are trimmed, so the lists work with Windows line endings too.
 *
 * @return string[]
 */
function hbHubOrdleWords(string $language, string $list): array
{
    static $lists = [];
    return $lists[$language . $list] ??= array_values(array_filter(
        array_map('trim', file(__DIR__ . '/words/' . $language . '-' . $list . '.txt', FILE_IGNORE_NEW_LINES)),
        static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
    ));
}

/**
 * Whether a (clean, see hbHubOrdleCleanGuess()) guess is a real word in the language.
 */
function hbHubOrdleIsWord(string $language, string $word): bool
{
    static $words = [];
    $words[$language] ??= array_flip(hbHubOrdleWords($language, 'words'));
    return isset($words[$language][$word]);
}

/**
 * A guess as it's stored: lowercase, or null when it isn't five letters of the language's alphabet.
 */
function hbHubOrdleCleanGuess(string $language, mixed $word): ?string
{
    if (!is_string($word)) {
        return null;
    }
    $word = mb_strtolower(trim($word));
    $letters = mb_str_split($word);
    if (count($letters) !== HBHUB_ORDLE_LENGTH) {
        return null;
    }
    foreach ($letters as $letter) {
        if (!str_contains(HBHUB_ORDLE_ALPHABETS[$language], $letter)) {
            return null;
        }
    }
    return $word;
}

/**
 * The word for the language on a day (normally today), picking it first if nobody has asked for it yet. Two requests
 * picking at once is fine: only the first one's word is stored (INSERT IGNORE), and both read that one back.
 */
function hbHubOrdleDailyWord(PDO $db, string $language, string $date): string
{
    $select = $db->prepare('SELECT wordText FROM `HBHub-OrdleWords` WHERE wordLanguage = ? AND wordDate = ?');
    $select->execute([$language, $date]);
    $word = $select->fetchColumn();
    if ($word !== false) {
        return $word;
    }

    // The words that haven't been used yet. Once they all have, the ones not used for a year, and failing that, any.
    $statement = $db->prepare('SELECT wordText, MAX(wordDate) AS lastUsed FROM `HBHub-OrdleWords` WHERE wordLanguage = ? GROUP BY wordText');
    $statement->execute([$language]);
    $lastUsed = array_column($statement->fetchAll(), 'lastUsed', 'wordText');
    $answers = hbHubOrdleWords($language, 'answers');
    $repeatFrom = (new DateTimeImmutable($date))->modify('-' . HBHUB_ORDLE_REPEAT_DAYS . ' days')->format('Y-m-d');
    $candidates = array_values(array_filter($answers, static fn (string $answer): bool => !isset($lastUsed[$answer])))
        ?: array_values(array_filter($answers, static fn (string $answer): bool => $lastUsed[$answer] < $repeatFrom))
        ?: $answers;

    $db->prepare('INSERT IGNORE INTO `HBHub-OrdleWords` (wordLanguage, wordDate, wordText) VALUES (?, ?, ?)')
        ->execute([$language, $date, $candidates[random_int(0, count($candidates) - 1)]]);
    $select->execute([$language, $date]);
    return $select->fetchColumn();
}

/**
 * How each letter of a guess did: 'correct' (right letter, right place), 'present' (in the word, elsewhere) or
 * 'absent'. A letter is only 'present' as many times as the word has it left over, like in Wordle: guessing "speed"
 * for "abide" makes the first e 'present' and the second one 'absent', as "abide" only has one.
 *
 * @return string[]
 */
function hbHubOrdleScore(string $guess, string $answer): array
{
    $guessLetters = mb_str_split($guess);
    $answerLetters = mb_str_split($answer);
    $score = array_fill(0, count($guessLetters), 'absent');
    $leftOver = []; // letter => how many of it in the word aren't matched yet

    foreach ($guessLetters as $index => $letter) {
        if ($letter === $answerLetters[$index]) {
            $score[$index] = 'correct';
        } else {
            $leftOver[$answerLetters[$index]] = ($leftOver[$answerLetters[$index]] ?? 0) + 1;
        }
    }
    foreach ($guessLetters as $index => $letter) {
        if ($score[$index] !== 'correct' && ($leftOver[$letter] ?? 0) > 0) {
            $score[$index] = 'present';
            $leftOver[$letter]--;
        }
    }
    return $score;
}

/**
 * The user's game for a language and day, or null before their first guess: ['guesses' => string[], 'result' => int].
 */
function hbHubOrdleGame(PDO $db, int $userId, string $language, string $date, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT gameGuesses, gameResult FROM `HBHub-OrdleGames` WHERE userId = ? AND gameLanguage = ? AND gameDate = ?'
        . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$userId, $language, $date]);
    $row = $statement->fetch();
    return $row === false ? null : [
        'guesses' => $row['gameGuesses'] === '' ? [] : explode(',', $row['gameGuesses']),
        'result' => (int) $row['gameResult'],
    ];
}

/**
 * Makes a guess in the user's game of today in the language. Returns null when it counted, or why it didn't:
 * 'notWord' (not five letters, or not in the word list - nothing is stored then), 'finished' (the game is already
 * won or lost) or 'stale' (the guess was made on an older view of the game, like a double click or another tab: $turn
 * is how many guesses the page had shown).
 */
function hbHubOrdleGuess(PDO $db, int $userId, string $language, mixed $word, mixed $turn): ?string
{
    $guess = hbHubOrdleCleanGuess($language, $word);
    if ($guess === null || !hbHubOrdleIsWord($language, $guess)) {
        return 'notWord';
    }
    $date = hbHubOrdleToday();
    $answer = hbHubOrdleDailyWord($db, $language, $date);

    $db->beginTransaction();
    try {
        $db->prepare('INSERT IGNORE INTO `HBHub-OrdleGames` (userId, gameLanguage, gameDate, gameGuesses, gameResult, gameStartTimestamp)
            VALUES (?, ?, ?, \'\', ?, UTC_TIMESTAMP())')->execute([$userId, $language, $date, HBHUB_ORDLE_PLAYING]);
        $game = hbHubOrdleGame($db, $userId, $language, $date, true);

        $error = match (true) {
            $game['result'] !== HBHUB_ORDLE_PLAYING => 'finished',
            !is_string($turn) || $turn !== (string) count($game['guesses']) => 'stale',
            default => null,
        };
        if ($error !== null) {
            $db->rollBack(); // also takes back the row made above, so a game always has at least one guess
            return $error;
        }

        $game['guesses'][] = $guess;
        $result = match (true) {
            $guess === $answer => HBHUB_ORDLE_WON,
            count($game['guesses']) >= HBHUB_ORDLE_TRIES => HBHUB_ORDLE_LOST,
            default => HBHUB_ORDLE_PLAYING,
        };
        $db->prepare('UPDATE `HBHub-OrdleGames` SET gameGuesses = ?, gameResult = ?,
                gameEndTimestamp = IF(? = ?, NULL, UTC_TIMESTAMP())
            WHERE userId = ? AND gameLanguage = ? AND gameDate = ?')
            ->execute([implode(',', $game['guesses']), $result, $result, HBHUB_ORDLE_PLAYING, $userId, $language, $date]);
        $db->commit();
        return null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
/**
 * The game as the page shows it: every guess with how its letters did, 'playing', 'won' or 'lost', and the word,
 * but only once the game is over.
 */
function hbHubOrdleView(?array $game, string $answer): array
{
    $result = $game['result'] ?? HBHUB_ORDLE_PLAYING;
    return [
        'guesses' => array_map(static fn (string $guess): array => [
            'word' => $guess,
            'score' => hbHubOrdleScore($guess, $answer),
        ], $game['guesses'] ?? []),
        'result' => match ($result) {
            HBHUB_ORDLE_WON => 'won',
            HBHUB_ORDLE_LOST => 'lost',
            default => 'playing',
        },
        'answer' => $result !== HBHUB_ORDLE_PLAYING ? $answer : null,
    ];
}

/**
 * The SQL condition for the games that count in stats: finished ones, and ones from before today (not won in time,
 * so lost). Today's game counts once it's over. Takes today's date as its one parameter.
 */
const HBHUB_ORDLE_COUNTED_SQL = '(g.gameResult <> ' . HBHUB_ORDLE_PLAYING . ' OR g.gameDate < ?)';

/**
 * Stats from one user's counted games in one language, oldest first (['date' => 'Y-m-d', 'won' => bool,
 * 'guesses' => int] each): how many played and won, the win % (null before any), the average number of guesses in a
 * win (null before any), how many wins took 1 to 6 guesses, and the streaks of days in a row won.
 * The current streak is still going when the last win was today or yesterday (today's word may just not be done yet),
 * and ends with a loss or a day without a win.
 */
function hbHubOrdleStats(array $games, string $today): array
{
    $stats = ['played' => count($games), 'wins' => 0, 'winRate' => null, 'averageGuesses' => null,
        'distribution' => array_fill(1, HBHUB_ORDLE_TRIES, 0), 'currentStreak' => 0, 'longestStreak' => 0];
    $run = 0;
    $lastWinDay = null;
    foreach ($games as $game) {
        if (!$game['won']) {
            $run = 0;
            $lastWinDay = null;
            continue;
        }
        $day = hbHubOrdleDayNumber($game['date']);
        $run = $lastWinDay === $day - 1 ? $run + 1 : 1;
        $lastWinDay = $day;
        $stats['longestStreak'] = max($stats['longestStreak'], $run);
        $stats['wins']++;
        $stats['distribution'][$game['guesses']]++;
    }

    if ($lastWinDay !== null && $lastWinDay >= hbHubOrdleDayNumber($today) - 1) {
        $stats['currentStreak'] = $run;
    }
    if ($stats['played'] > 0) {
        $stats['winRate'] = $stats['wins'] / $stats['played'];
    }
    if ($stats['wins'] > 0) {
        $guesses = 0;
        foreach ($stats['distribution'] as $tries => $count) {
            $guesses += $tries * $count;
        }
        $stats['averageGuesses'] = $guesses / $stats['wins'];
    }
    return $stats;
}

/**
 * A games row from the database, as hbHubOrdleStats() takes it.
 */
function hbHubOrdleStatsGame(array $row): array
{
    return [
        'date' => $row['gameDate'],
        'won' => (int) $row['gameResult'] === HBHUB_ORDLE_WON,
        'guesses' => substr_count($row['gameGuesses'], ',') + 1,
    ];
}

/**
 * The user's own stats in a language (hbHubOrdleStats()), for the page.
 */
function hbHubOrdleUserStats(PDO $db, int $userId, string $language): array
{
    $today = hbHubOrdleToday();
    $statement = $db->prepare('SELECT g.gameDate, g.gameResult, g.gameGuesses FROM `HBHub-OrdleGames` g
        WHERE g.userId = ? AND g.gameLanguage = ? AND ' . HBHUB_ORDLE_COUNTED_SQL . ' ORDER BY g.gameDate');
    $statement->execute([$userId, $language, $today]);
    return hbHubOrdleStats(array_map('hbHubOrdleStatsGame', $statement->fetchAll()), $today);
}
