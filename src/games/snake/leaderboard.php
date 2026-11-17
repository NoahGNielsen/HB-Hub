<?php
// Snake leaderboard, one for Normal and one for Fast (?speed=normal or fast, as tabs; Slow has none, see 'leaderboard'
// in HBHUB_SNAKE_SPEEDS), from HBHub-SnakeGames: the most apples
// eaten in one game, the average, all the apples eaten, and games played. The scores are the server's own (it plays
// every game along with the page, see assets/php/games/snake/snake.php), and only games that count are in the best,
// the average and the apples: a game the anticheat stopped (scripted, out of time with the clock, moves the server
// couldn't make, too many pauses), or one that was left, only counts as played. Sorted by any column with
// ?sort=column&dir=asc|desc, which the headings link to; the best score first to begin with.
// Only players with a game that counts are on it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/snake/snake.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'best' => ['Best', 'desc'],
    'average' => ['Average', 'desc'],
    'apples' => ['Apples', 'desc'],
    'played' => ['Played', 'desc'],
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

// The speeds with a leaderboard: 'normal' => 2, 'fast' => 3 (?speed=slow shows Normal)
$speeds = array_filter(hbHubSnakeSpeedKeys(), static fn(int $speed): bool => HBHUB_SNAKE_SPEEDS[$speed]['leaderboard']);
$speedKey = is_string($_GET['speed'] ?? null) && isset($speeds[$_GET['speed']]) ? $_GET['speed'] : 'normal';
$speed = $speeds[$speedKey];
$speedName = HBHUB_SNAKE_SPEEDS[$speed]['name'];
$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'best';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Everyone's totals at the speed, for players with a game that counts. Banned and deleted accounts (userStatus NULL)
// are left out. Only started games count as played (gameSpeed is set when a game starts), and one still being played
// does, as it isn't known yet how it'll end.
$statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, COUNT(*) AS played,
        SUM(g.gameResult = ?) AS counted, MAX(IF(g.gameResult = ?, g.gameScore, NULL)) AS best,
        AVG(IF(g.gameResult = ?, g.gameScore, NULL)) AS average, SUM(IF(g.gameResult = ?, g.gameScore, 0)) AS apples
    FROM `HBHub-SnakeGames` g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    WHERE g.gameSpeed = ? AND g.gameStartTimestamp IS NOT NULL AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY g.userId, u.userName, u.userAvatarAttachmentId
    HAVING counted > 0');
$statement->execute([HBHUB_SNAKE_FINISHED, HBHUB_SNAKE_FINISHED, HBHUB_SNAKE_FINISHED, HBHUB_SNAKE_FINISHED, $speed,
    LEADERBOARD_STATUS_BANNED]);

$rows = [];
foreach ($statement->fetchAll() as $row) {
    $rows[] = [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'best' => (int) $row['best'],
        'average' => (float) $row['average'],
        'apples' => (int) $row['apples'],
        'played' => (int) $row['played'],
    ];
}

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to the higher best, then the higher average, then by name
    return $order ?: ($b['best'] <=> $a['best'] ?: $b['average'] <=> $a['average']
        ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $speedKey, string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['speed' => $speedKey, 'sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Snake leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="The best Snake players on the HB Hub, at Normal and Fast">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games">
        <a class="casino-back" href="/games/snake/">&larr; Snake</a>
        <h1>Snake leaderboard</h1>
        <p class="casino-intro">
            Normal and Fast have a leaderboard each (Slow doesn't): the most apples eaten in one game. HB Hub's server plays every
            game along with you, so a score only counts when it can make the same moves in the same time. A game that's
            sped up or slowed down, steered by code, or paused more than <?= HBHUB_SNAKE_MAX_PAUSES ?> times only counts
            as played, like one you leave.
        </p>

        <nav class="casino-board-links" aria-label="Speed">
            <?php foreach (HBHUB_SNAKE_SPEEDS as $info): if (!$info['leaderboard']) { continue; } ?>
                <a class="casino-btn<?= $info['key'] === $speedKey ? ' casino-btn-primary' : '' ?>"
                   href="?<?= htmlspecialchars(http_build_query(['speed' => $info['key'], 'sort' => $sort, 'dir' => $dir])) ?>"<?= $info['key'] === $speedKey ? ' aria-current="page"' : '' ?>><?= $info['name'] ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="<?= $speedName ?> leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has played on <?= $speedName ?> yet. <a href="/games/snake/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($speedKey, $column, $sort, $dir)) ?>">
                                            <?= $heading ?><span class="casino-sort-arrow" aria-hidden="true"><?= $column === $sort ? ($dir === 'asc' ? '↑' : '↓') : '' ?></span>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row):
                                $avatarSrc = hbHubAvatarUrl($row['userId'], $row['avatarId']);
                                $place = $podium && $index < 3 ? $index + 1 : null;
                            ?>
                                <tr<?= $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $place !== null ? ' data-podium="' . $place . '"' : '' ?>><?= $index + 1 ?></td>
                                    <td>
                                        <a class="casino-player" href="<?= htmlspecialchars(hbHubProfileUrl($row['userId'], $_SERVER['REQUEST_URI'])) ?>">
                                            <span class="casino-avatar" aria-hidden="true">
                                                <?php if ($avatarSrc !== null): ?>
                                                    <img data-queued-picture="<?= htmlspecialchars($avatarSrc) ?>" alt=""><noscript><img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy"></noscript>
                                                <?php else: ?>
                                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($row['player'], 0, 1))) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="casino-player-name"><?= htmlspecialchars($row['player']) ?></span>
                                        </a>
                                    </td>
                                    <td><?= number_format($row['best']) ?></td>
                                    <td><?= number_format($row['average'], 1) ?></td>
                                    <td><?= number_format($row['apples']) ?></td>
                                    <td><?= number_format($row['played']) ?></td>
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
