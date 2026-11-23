<?php
// Blockfall leaderboard, one for each mode (?mode=enduro, rush or sprint, as tabs), from HBHub-BlockfallGames.
// Enduro and Rush rank by score: the best, the average, all the lines cleared, and games played. Sprint ranks by time:
// the fastest 20 lines, the average of the Sprints that got there, how many did, and games played. The numbers are the
// server's own (it plays every game along with the page, see assets/php/games/blockfall/blockfall.php), and only
// games that count are in them: one the anticheat stopped, or one that was left, only counts as played. Sorted by any
// column with ?sort=column&dir=asc|desc, which the headings link to; the best first to begin with.
// Only players with a game that counts are on it (for Sprint: one that cleared the 20 lines).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/blockfall/blockfall.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
// sort => [heading, which way it sorts first], for a mode ranked by score and one ranked by time
const LEADERBOARD_COLUMNS = [
    'score' => [
        'player' => ['Player', 'asc'],
        'best' => ['Best', 'desc'],
        'average' => ['Average', 'desc'],
        'lines' => ['Lines', 'desc'],
        'played' => ['Played', 'desc'],
    ],
    'time' => [
        'player' => ['Player', 'asc'],
        'best' => ['Best time', 'asc'],
        'average' => ['Average', 'asc'],
        'cleared' => ['Finished', 'desc'],
        'played' => ['Played', 'desc'],
    ],
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$modes = hbHubBlockfallModeKeys();
$modeKey = is_string($_GET['mode'] ?? null) && isset($modes[$_GET['mode']]) ? $_GET['mode'] : 'enduro';
$modeNumber = $modes[$modeKey];
$mode = HBHUB_BLOCKFALL_MODES[$modeNumber];
$columns = LEADERBOARD_COLUMNS[$mode['board']];
$sort = is_string($_GET['sort'] ?? null) && isset($columns[$_GET['sort']]) ? $_GET['sort'] : 'best';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : $columns[$sort][1];
$podium = $sort !== 'player' && $dir === $columns[$sort][1];

// Everyone's totals in the mode. A game that counts is a finished one, and for Sprint one that cleared the goal too.
// Banned and deleted accounts are left out. Only started games count as played (gameMode is set when one starts).
$counts = 'g.gameResult = ' . HBHUB_BLOCKFALL_FINISHED . ($mode['goal'] > 0 ? ' AND g.gameLines >= ' . (int) $mode['goal'] : '');
$statement = $db->prepare("SELECT g.userId, u.userName, u.userAvatarAttachmentId, COUNT(*) AS played,
        SUM($counts) AS counted, MAX(IF($counts, g.gameScore, NULL)) AS bestScore, AVG(IF($counts, g.gameScore, NULL)) AS averageScore,
        MIN(IF($counts, g.gameTicks, NULL)) AS bestTicks, AVG(IF($counts, g.gameTicks, NULL)) AS averageTicks,
        SUM(IF($counts, g.gameLines, 0)) AS totalLines
    FROM `HBHub-BlockfallGames` g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    WHERE g.gameMode = ? AND g.gameStartTimestamp IS NOT NULL AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY g.userId, u.userName, u.userAvatarAttachmentId
    HAVING counted > 0");
$statement->execute([$modeNumber, LEADERBOARD_STATUS_BANNED]);

$byTime = $mode['board'] === 'time';
$rows = [];
foreach ($statement->fetchAll() as $row) {
    $rows[] = [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        // Sprint's in ms (ticks of HBHUB_BLOCKFALL_TICK_MS)
        'best' => $byTime ? (int) $row['bestTicks'] * HBHUB_BLOCKFALL_TICK_MS : (int) $row['bestScore'],
        'average' => $byTime ? (float) $row['averageTicks'] * HBHUB_BLOCKFALL_TICK_MS : (float) $row['averageScore'],
        'lines' => (int) $row['totalLines'], // not "lines": that's a reserved word in MySQL
        'cleared' => (int) $row['counted'],
        'played' => (int) $row['played'],
    ];
}

usort($rows, static function (array $a, array $b) use ($sort, $dir, $byTime): int {
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to the better best, then the better average, then by name
    $better = $byTime ? ($a['best'] <=> $b['best'] ?: $a['average'] <=> $b['average'])
        : ($b['best'] <=> $a['best'] ?: $b['average'] <=> $a['average']);
    return $order ?: ($better ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $modeKey, array $columns, string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : $columns[$column][1];
    return '?' . http_build_query(['mode' => $modeKey, 'sort' => $column, 'dir' => $newDir]);
}

/**
 * A time in ms as "0:41.27".
 */
function leaderboardTime(float $ms): string
{
    $ms = (int) round($ms);
    return sprintf('%d:%02d.%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 10));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Blockfall leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="The best Blockfall players on the HB Hub, in Enduro, Rush and Sprint">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games">
        <a class="casino-back" href="/games/blockfall/">&larr; Blockfall</a>
        <h1>Blockfall leaderboard</h1>
        <p class="casino-intro">
            Enduro and Rush are ranked by the best score in one game, Sprint by the fastest <?= HBHUB_BLOCKFALL_MODES[3]['goal'] ?>
            lines. HB Hub's server plays every game along with you, so a game only counts when it can play the same game in
            the same time. A game that's sped up or slowed down, played by code, or paused more than
            <?= HBHUB_BLOCKFALL_MAX_PAUSES ?> times only counts as played, like one you leave.
        </p>

        <nav class="casino-board-links" aria-label="Mode">
            <?php foreach (HBHUB_BLOCKFALL_MODES as $info): ?>
                <a class="casino-btn<?= $info['key'] === $modeKey ? ' casino-btn-primary' : '' ?>"
                   href="?<?= htmlspecialchars(http_build_query(['mode' => $info['key']])) ?>"<?= $info['key'] === $modeKey ? ' aria-current="page"' : '' ?>><?= $info['name'] ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="<?= $mode['name'] ?> leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has <?= $byTime ? 'finished a Sprint' : 'played ' . $mode['name'] ?> yet. <a href="/games/blockfall/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach ($columns as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($modeKey, $columns, $column, $sort, $dir)) ?>">
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
                                    <?php if ($byTime): ?>
                                        <td><?= leaderboardTime($row['best']) ?></td>
                                        <td><?= leaderboardTime($row['average']) ?></td>
                                        <td><?= number_format($row['cleared']) ?></td>
                                    <?php else: ?>
                                        <td><?= number_format($row['best']) ?></td>
                                        <td><?= number_format($row['average']) ?></td>
                                        <td><?= number_format($row['lines']) ?></td>
                                    <?php endif; ?>
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
