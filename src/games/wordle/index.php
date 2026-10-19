<?php
// Wordle: guess the day's five-letter word in six tries, in English or Danish (?lang=en or ?lang=da). Without ?lang,
// the first language the user hasn't finished today opens. The words and rules are in assets/php/games/wordle/wordle.php.
// The page only has the board's and keyboard's empty spaces: wordle.js fills them in from the game in data-game
// (hbHubWordleView(): the guesses so far with how their letters did, the result, and the word once it's over) and the
// stats in data-stats (hbHubWordleStats()), and sends guesses to /games/wordle/guess.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const WORDLE_STATUS_NAMES = ['new' => 'New word', 'playing' => 'Playing', 'won' => 'Solved', 'lost' => 'Missed'];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$today = hbHubWordleToday();

// How far the user is with each language today, for the tabs
$wordleStatus = [];
foreach (array_keys(HBHUB_WORDLE_LANGUAGES) as $code) {
    $game = hbHubWordleGame($db, $userId, $code, $today);
    $wordleStatus[$code] = match ($game['result'] ?? null) {
        null => 'new',
        HBHUB_WORDLE_WON => 'won',
        HBHUB_WORDLE_LOST => 'lost',
        default => 'playing',
    };
}

$language = $_GET['lang'] ?? null;
if (!is_string($language) || !isset(HBHUB_WORDLE_LANGUAGES[$language])) {
    $language = array_key_first(array_filter($wordleStatus, static fn (string $status): bool => in_array($status, ['new', 'playing'], true)))
        ?? 'en';
}

$wordleGame = hbHubWordleView(hbHubWordleGame($db, $userId, $language, $today), hbHubWordleDailyWord($db, $language, $today));
$wordleStats = hbHubWordleUserStats($db, $userId, $language);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/wordle.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/wordle.css') ?>">
    <title>Wordle - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Guess the daily five-letter word on the HB Hub, in English or Danish">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games wordle" data-wordle
          data-lang="<?= $language ?>"
          data-language-name="<?= HBHUB_WORDLE_LANGUAGES[$language] ?>"
          data-date="<?= $today ?>"
          data-next="<?= hbHubWordleNextWordAt() ?>"
          data-alphabet="<?= HBHUB_WORDLE_ALPHABETS[$language] ?>"
          data-length="<?= HBHUB_WORDLE_LENGTH ?>"
          data-game="<?= htmlspecialchars(json_encode($wordleGame)) ?>"
          data-stats="<?= htmlspecialchars(json_encode($wordleStats)) ?>">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Wordle</h1>
            <a class="casino-btn" href="/games/wordle/leaderboard?lang=<?= $language ?>">Leaderboard</a>
        </div>
        <p class="casino-intro">
            Guess the day's five-letter word in six tries. There's a new word every day at midnight, one in English and
            one in Danish, and everyone gets the same ones.
        </p>

        <nav class="wordle-languages" aria-label="Language">
            <?php foreach (HBHUB_WORDLE_LANGUAGE_NAMES as $code => $name): ?>
                <a class="wordle-language<?= $code === $language ? ' is-active' : '' ?>" href="?lang=<?= $code ?>"
                   lang="<?= $code ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>>
                    <span class="wordle-language-name"><?= $name ?></span>
                    <span class="wordle-language-status is-<?= $wordleStatus[$code] ?>" lang="en"<?= $code === $language ? ' data-wordle-status' : '' ?>><?= WORDLE_STATUS_NAMES[$wordleStatus[$code]] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="wordle-play">
            <div class="wordle-board" role="group" aria-label="Your guesses" data-wordle-board>
                <?php for ($row = 0; $row < HBHUB_WORDLE_TRIES; $row++): ?>
                    <div class="wordle-row" role="img" aria-label="Guess <?= $row + 1 ?>: empty">
                        <?php for ($tile = 0; $tile < HBHUB_WORDLE_LENGTH; $tile++): ?>
                            <span class="wordle-tile"></span>
                        <?php endfor; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <p class="wordle-message" role="status" data-wordle-message></p>

            <div class="wordle-keyboard" lang="<?= $language ?>" data-wordle-keyboard>
                <?php foreach (HBHUB_WORDLE_KEYBOARDS[$language] as $index => $keys): ?>
                    <div class="wordle-keyboard-row">
                        <?php if ($index === 2): ?>
                            <button type="button" class="wordle-key is-wide" data-key="Enter">Enter</button>
                        <?php endif; ?>
                        <?php foreach (mb_str_split($keys) as $key): ?>
                            <button type="button" class="wordle-key" data-key="<?= $key ?>"><?= $key ?></button>
                        <?php endforeach; ?>
                        <?php if ($index === 2): ?>
                            <button type="button" class="wordle-key is-wide" data-key="Backspace" aria-label="Delete">
                                <svg class="wordle-key-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 5H9l-6 7 6 7h12z M13 9l6 6 M19 9l-6 6"/></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <section class="casino-card wordle-result" aria-labelledby="wordle-result-title" data-wordle-result hidden>
            <h2 id="wordle-result-title" data-wordle-result-title></h2>
            <p class="wordle-result-text" data-wordle-result-text></p>
            <div class="casino-actions">
                <button type="button" class="casino-btn casino-btn-primary" data-wordle-share>Share</button>
                <?php foreach (HBHUB_WORDLE_LANGUAGES as $code => $name): ?>
                    <?php if ($code !== $language && in_array($wordleStatus[$code], ['new', 'playing'], true)): ?>
                        <a class="casino-btn" href="?lang=<?= $code ?>">Play the <?= $name ?> word</a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="casino-hint wordle-next" data-wordle-next></p>
        </section>

        <section class="casino-card wordle-stats" aria-labelledby="wordle-stats-title">
            <h2 id="wordle-stats-title">Your <?= HBHUB_WORDLE_LANGUAGES[$language] ?> stats</h2>
            <dl class="wordle-numbers">
                <div><dt>Played</dt><dd data-wordle-stat="played">0</dd></div>
                <div><dt>Win %</dt><dd data-wordle-stat="winRate">–</dd></div>
                <div><dt>Current streak</dt><dd data-wordle-stat="currentStreak">0</dd></div>
                <div><dt>Longest streak</dt><dd data-wordle-stat="longestStreak">0</dd></div>
            </dl>
            <h3 class="wordle-distribution-title">Guesses in a win</h3>
            <ol class="wordle-distribution" data-wordle-distribution>
                <?php for ($tries = 1; $tries <= HBHUB_WORDLE_TRIES; $tries++): ?>
                    <li>
                        <span class="wordle-distribution-tries"><?= $tries ?></span>
                        <span class="wordle-distribution-track" aria-hidden="true"><span class="wordle-distribution-bar"></span></span>
                        <span class="wordle-distribution-count">0</span>
                    </li>
                <?php endfor; ?>
            </ol>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="wordle-rules-title">
            <h2 id="wordle-rules-title">How to play</h2>
            <ul class="casino-rule-list">
                <li>Guess the word in <?= HBHUB_WORDLE_TRIES ?> tries. Every guess has to be a real <?= HBHUB_WORDLE_LENGTH ?>-letter word<?= $language === 'da' ? ' (any form of it counts, like "huset" or "spist")' : '' ?>.</li>
                <li>After each guess the letters change colour: <span class="wordle-rule-tile is-correct">green</span> is the right letter in the right place, <span class="wordle-rule-tile is-present">yellow</span> is in the word but somewhere else, and <span class="wordle-rule-tile is-absent">grey</span> isn't in the word (or not that many times).</li>
                <li>Type on your keyboard or tap the letters<?= $language === 'da' ? '. The Danish word can have æ, ø and å in it' : '' ?>.</li>
                <li>There's one English and one Danish word a day, and you get one go at each. A new one comes at midnight (Danish time).</li>
                <li>Your streak is the days in a row you've guessed the word. Missing a day, or not getting it, starts it over. A word you start but don't finish by midnight counts as missed.</li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/wordle.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/wordle.js') ?>" defer></script>
</body>
</html>
