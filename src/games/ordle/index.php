<?php
// Ordle: guess the day's five-letter word in six tries, in English or Danish (?lang=en or ?lang=da). Without ?lang,
// the first language the user hasn't finished today opens. The words and rules are in assets/php/games/ordle/ordle.php.
// The page only has the board's and keyboard's empty spaces: ordle.js fills them in from the game in data-game
// (hbHubOrdleView(): the guesses so far with how their letters did, the result, and the word once it's over) and the
// stats in data-stats (hbHubOrdleStats()), and sends guesses to /games/ordle/guess.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/ordle/ordle.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const ORDLE_STATUS_NAMES = ['new' => 'New word', 'playing' => 'Playing', 'won' => 'Solved', 'lost' => 'Missed'];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$today = hbHubOrdleToday();

// How far the user is with each language today, for the tabs
$ordleStatus = [];
foreach (array_keys(HBHUB_ORDLE_LANGUAGES) as $code) {
    $game = hbHubOrdleGame($db, $userId, $code, $today);
    $ordleStatus[$code] = match ($game['result'] ?? null) {
        null => 'new',
        HBHUB_ORDLE_WON => 'won',
        HBHUB_ORDLE_LOST => 'lost',
        default => 'playing',
    };
}

$language = $_GET['lang'] ?? null;
if (!is_string($language) || !isset(HBHUB_ORDLE_LANGUAGES[$language])) {
    $language = array_key_first(array_filter($ordleStatus, static fn (string $status): bool => in_array($status, ['new', 'playing'], true)))
        ?? 'en';
}

$ordleGame = hbHubOrdleView(hbHubOrdleGame($db, $userId, $language, $today), hbHubOrdleDailyWord($db, $language, $today));
$ordleStats = hbHubOrdleUserStats($db, $userId, $language);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/ordle.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/ordle.css') ?>">
    <title>Ordle - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Guess the daily five-letter word on the HB Hub, in English or Danish">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games ordle" data-ordle
          data-lang="<?= $language ?>"
          data-language-name="<?= HBHUB_ORDLE_LANGUAGES[$language] ?>"
          data-date="<?= $today ?>"
          data-next="<?= hbHubOrdleNextWordAt() ?>"
          data-alphabet="<?= HBHUB_ORDLE_ALPHABETS[$language] ?>"
          data-length="<?= HBHUB_ORDLE_LENGTH ?>"
          data-game="<?= htmlspecialchars(json_encode($ordleGame)) ?>"
          data-stats="<?= htmlspecialchars(json_encode($ordleStats)) ?>">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Ordle</h1>
            <a class="casino-btn" href="/games/ordle/leaderboard?lang=<?= $language ?>">Leaderboard</a>
        </div>
        <p class="casino-intro">
            Guess the day's five-letter word in six tries. There's a new word every day at midnight, one in English and
            one in Danish, and everyone gets the same ones.
        </p>

        <nav class="ordle-languages" aria-label="Language">
            <?php foreach (HBHUB_ORDLE_LANGUAGE_NAMES as $code => $name): ?>
                <a class="ordle-language<?= $code === $language ? ' is-active' : '' ?>" href="?lang=<?= $code ?>"
                   lang="<?= $code ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>>
                    <span class="ordle-language-name"><?= $name ?></span>
                    <span class="ordle-language-status is-<?= $ordleStatus[$code] ?>" lang="en"<?= $code === $language ? ' data-ordle-status' : '' ?>><?= ORDLE_STATUS_NAMES[$ordleStatus[$code]] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ordle-play">
            <div class="ordle-board" role="group" aria-label="Your guesses" data-ordle-board>
                <?php for ($row = 0; $row < HBHUB_ORDLE_TRIES; $row++): ?>
                    <div class="ordle-row" role="img" aria-label="Guess <?= $row + 1 ?>: empty">
                        <?php for ($tile = 0; $tile < HBHUB_ORDLE_LENGTH; $tile++): ?>
                            <span class="ordle-tile"></span>
                        <?php endfor; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <p class="ordle-message" role="status" data-ordle-message></p>

            <div class="ordle-keyboard" lang="<?= $language ?>" data-ordle-keyboard>
                <?php foreach (HBHUB_ORDLE_KEYBOARDS[$language] as $index => $keys): ?>
                    <div class="ordle-keyboard-row">
                        <?php if ($index === 2): ?>
                            <button type="button" class="ordle-key is-wide" data-key="Enter">Enter</button>
                        <?php endif; ?>
                        <?php foreach (mb_str_split($keys) as $key): ?>
                            <button type="button" class="ordle-key" data-key="<?= $key ?>"><?= $key ?></button>
                        <?php endforeach; ?>
                        <?php if ($index === 2): ?>
                            <button type="button" class="ordle-key is-wide" data-key="Backspace" aria-label="Delete">
                                <svg class="ordle-key-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 5H9l-6 7 6 7h12z M13 9l6 6 M19 9l-6 6"/></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <section class="casino-card ordle-result" aria-labelledby="ordle-result-title" data-ordle-result hidden>
            <h2 id="ordle-result-title" data-ordle-result-title></h2>
            <p class="ordle-result-text" data-ordle-result-text></p>
            <div class="casino-actions">
                <button type="button" class="casino-btn casino-btn-primary" data-ordle-share>Share</button>
                <?php foreach (HBHUB_ORDLE_LANGUAGES as $code => $name): ?>
                    <?php if ($code !== $language && in_array($ordleStatus[$code], ['new', 'playing'], true)): ?>
                        <a class="casino-btn" href="?lang=<?= $code ?>">Play the <?= $name ?> word</a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="casino-hint ordle-next" data-ordle-next></p>
        </section>

        <section class="casino-card ordle-stats" aria-labelledby="ordle-stats-title">
            <h2 id="ordle-stats-title">Your <?= HBHUB_ORDLE_LANGUAGES[$language] ?> stats</h2>
            <dl class="ordle-numbers">
                <div><dt>Played</dt><dd data-ordle-stat="played">0</dd></div>
                <div><dt>Win %</dt><dd data-ordle-stat="winRate">–</dd></div>
                <div><dt>Current streak</dt><dd data-ordle-stat="currentStreak">0</dd></div>
                <div><dt>Longest streak</dt><dd data-ordle-stat="longestStreak">0</dd></div>
            </dl>
            <h3 class="ordle-distribution-title">Guesses in a win</h3>
            <ol class="ordle-distribution" data-ordle-distribution>
                <?php for ($tries = 1; $tries <= HBHUB_ORDLE_TRIES; $tries++): ?>
                    <li>
                        <span class="ordle-distribution-tries"><?= $tries ?></span>
                        <span class="ordle-distribution-track" aria-hidden="true"><span class="ordle-distribution-bar"></span></span>
                        <span class="ordle-distribution-count">0</span>
                    </li>
                <?php endfor; ?>
            </ol>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="ordle-rules-title">
            <h2 id="ordle-rules-title">How to play</h2>
            <ul class="casino-rule-list">
                <li>Guess the word in <?= HBHUB_ORDLE_TRIES ?> tries. Every guess has to be a real <?= HBHUB_ORDLE_LENGTH ?>-letter word<?= $language === 'da' ? ' (any form of it counts, like "huset" or "spist")' : '' ?>.</li>
                <li>After each guess the letters change colour: <span class="ordle-rule-tile is-correct">green</span> is the right letter in the right place, <span class="ordle-rule-tile is-present">yellow</span> is in the word but somewhere else, and <span class="ordle-rule-tile is-absent">grey</span> isn't in the word (or not that many times).</li>
                <li>Type on your keyboard or tap the letters<?= $language === 'da' ? '. The Danish word can have æ, ø and å in it' : '' ?>.</li>
                <li>There's one English and one Danish word a day, and you get one go at each. A new one comes at midnight (Danish time).</li>
                <li>Your streak is the days in a row you've guessed the word. Missing a day, or not getting it, starts it over. A word you start but don't finish by midnight counts as missed.</li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/ordle.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/ordle.js') ?>" defer></script>
</body>
</html>
