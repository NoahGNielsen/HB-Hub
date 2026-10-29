<?php
// Wordle leaderboard, one for each language (?lang=en or ?lang=da, as tabs), from HBHub-WordleGames: games played and
// won, win %, the average guesses in a win, and the current and longest streak of days in a row won (hbHubWordleStats()
// in wordle.php works them all out). Sorted by any column with ?sort=column&dir=asc|desc, which the headings link to.
// Win % only counts from LEADERBOARD_RANKED_GAMES games, so one lucky day doesn't top it: anyone with fewer has "–",
// and comes after everyone with one when sorting by it (whichever way). The same for the average without a win.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_GAMES = 5;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'played' => ['Played', 'desc'],
    'wins' => ['Wins', 'desc'],
    'winRate' => ['Win %', 'desc'],
    'averageGuesses' => ['Avg. guesses', 'asc'],
    'currentStreak' => ['Current streak', 'desc'],
    'longestStreak' => ['Longest streak', 'desc'],
];
const LEADERBOARD_NULLABLE = ['winRate', 'averageGuesses']; // "–" when there's nothing to show yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$today = hbHubWordleToday();

$language = is_string($_GET['lang'] ?? null) && isset(HBHUB_WORDLE_LANGUAGES[$_GET['lang']]) ? $_GET['lang'] : 'en';
$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'currentStreak';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Every counted game in the language, grouped by player below. Banned and deleted accounts (userStatus NULL) are left out.
$statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, g.gameDate, g.gameResult, g.gameGuesses
    FROM `HBHub-WordleGames` g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    WHERE g.gameLanguage = ? AND ' . HBHUB_WORDLE_COUNTED_SQL . ' AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    ORDER BY g.userId, g.gameDate');
$statement->execute([$language, $today, LEADERBOARD_STATUS_BANNED]);

$players = [];
foreach ($statement->fetchAll() as $row) {
    $players[(int) $row['userId']] ??= [
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'games' => [],
    ];
    $players[(int) $row['userId']]['games'][] = hbHubWordleStatsGame($row);
}

$rows = [];
foreach ($players as $playerId => $player) {
    $stats = hbHubWordleStats($player['games'], $today);
    $rows[] = [
        'userId' => $playerId,
        'player' => $player['player'],
        'avatarId' => $player['avatarId'],
        'played' => $stats['played'],
        'wins' => $stats['wins'],
        'winRate' => $stats['played'] >= LEADERBOARD_RANKED_GAMES ? $stats['winRate'] : null,
        'averageGuesses' => $stats['averageGuesses'],
        'currentStreak' => $stats['currentStreak'],
        'longestStreak' => $stats['longestStreak'],
    ];
}

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    if (in_array($sort, LEADERBOARD_NULLABLE, true) && ($a[$sort] === null) !== ($b[$sort] === null)) {
        return $a[$sort] === null ? 1 : -1; // nothing to show: after everyone with one
    }
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to the longer best streak, then who played more, then by name
    return $order ?: ($b['longestStreak'] <=> $a['longestStreak'] ?: $b['played'] <=> $a['played']
        ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $language, string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['lang' => $language, 'sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/wordle.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/wordle.css') ?>">
    <title>Wordle leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who guesses the daily word best on the HB Hub, in English and Danish">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games wordle">
        <a class="casino-back" href="/games/wordle/?lang=<?= $language ?>">&larr; Wordle</a>
        <h1>Wordle leaderboard</h1>
        <p class="casino-intro">
            English and Danish have a leaderboard each. A streak is the days in a row someone guessed the word, and the
            current one ends with a day they missed or didn't get it. Win % is only shown from <?= LEADERBOARD_RANKED_GAMES ?>
            games, so one lucky day doesn't top the board. Avg. guesses is how many tries a win takes.
        </p>

        <nav class="wordle-languages wordle-leaderboard-languages" aria-label="Language">
            <?php foreach (HBHUB_WORDLE_LANGUAGES as $code => $name): ?>
                <a class="wordle-language<?= $code === $language ? ' is-active' : '' ?>"
                   href="?<?= htmlspecialchars(http_build_query(['lang' => $code, 'sort' => $sort, 'dir' => $dir])) ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>>
                    <span class="wordle-language-name"><?= $name ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="<?= HBHUB_WORDLE_LANGUAGES[$language] ?> leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has finished a <?= HBHUB_WORDLE_LANGUAGES[$language] ?> word yet. <a href="/games/wordle/?lang=<?= $language ?>">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($language, $column, $sort, $dir)) ?>">
                                            <?= $heading ?><span class="casino-sort-arrow" aria-hidden="true"><?= $column === $sort ? ($dir === 'asc' ? '↑' : '↓') : '' ?></span>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row):
                                $avatarSrc = hbHubAvatarUrl($row['userId'], $row['avatarId']);
                                $isUnranked = in_array($sort, LEADERBOARD_NULLABLE, true) && $row[$sort] === null;
                                $place = $podium && !$isUnranked && $index < 3 ? $index + 1 : null;
                            ?>
                                <tr<?= $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $place !== null ? ' data-podium="' . $place . '"' : '' ?>><?= $isUnranked ? '–' : $index + 1 ?></td>
                                    <td>
                                        <a class="casino-player" href="<?= htmlspecialchars(hbHubProfileUrl($row['userId'], $_SERVER['REQUEST_URI'])) ?>">
                                            <span class="casino-avatar" aria-hidden="true">
                                                <?php if ($avatarSrc !== null): ?>
                                                    <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($row['player'], 0, 1))) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="casino-player-name"><?= htmlspecialchars($row['player']) ?></span>
                                        </a>
                                    </td>
                                    <td><?= number_format($row['played']) ?></td>
                                    <td><?= number_format($row['wins']) ?></td>
                                    <td>
                                        <?php if ($row['winRate'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_GAMES ?> games">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['winRate'] * 100, 1) ?>%
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['averageGuesses'] === null): ?>
                                            <span class="casino-unranked" title="No wins yet">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['averageGuesses'], 2) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($row['currentStreak']) ?> <?= $row['currentStreak'] === 1 ? 'day' : 'days' ?></td>
                                    <td><?= number_format($row['longestStreak']) ?> <?= $row['longestStreak'] === 1 ? 'day' : 'days' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
