<?php
// Sudoku leaderboard, one for each level (?level=easy, medium, hard or expert, as tabs), from HBHub-SudokuGames: the
// fastest solve, the average solve time and how many were solved. Only solves without a hint count (gameResult
// HBHUB_SUDOKU_SOLVED); one with a hint, or one the anticheat found too fast, is left out. The times are the server's
// own, pauses left out (see assets/php/games/sudoku/sudoku.php), so they can be trusted.
// Sorted by any column with ?sort=column&dir=asc|desc, which the headings link to; the fastest solve first to begin
// with. Only players with a ranked solve are on it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/sudoku.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'best' => ['Best time', 'asc'],
    'average' => ['Avg. time', 'asc'],
    'solved' => ['Solved', 'desc'],
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$levels = []; // 'easy' => 1, ...
foreach (HBHUB_SUDOKU_LEVELS as $number => $info) {
    $levels[$info['key']] = $number;
}
$levelKey = is_string($_GET['level'] ?? null) && isset($levels[$_GET['level']]) ? $_GET['level'] : 'medium';
$level = $levels[$levelKey];
$levelName = HBHUB_SUDOKU_LEVELS[$level]['name'];
$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'best';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Everyone's ranked solves on the level. Banned and deleted accounts (userStatus NULL) are left out.
$statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, COUNT(*) AS solved,
        MIN(g.gameMs) AS best, AVG(g.gameMs) AS average
    FROM `HBHub-SudokuGames` g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    WHERE g.gameLevel = ? AND g.gameResult = ? AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY g.userId, u.userName, u.userAvatarAttachmentId');
$statement->execute([$level, HBHUB_SUDOKU_SOLVED, LEADERBOARD_STATUS_BANNED]);

$rows = [];
foreach ($statement->fetchAll() as $row) {
    $rows[] = [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'best' => (int) $row['best'],
        'average' => (int) round((float) $row['average']),
        'solved' => (int) $row['solved'],
    ];
}

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to the faster best time, then who solved more, then by name
    return $order ?: ($a['best'] <=> $b['best'] ?: $b['solved'] <=> $a['solved']
        ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $levelKey, string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['level' => $levelKey, 'sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Sudoku leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="The fastest Sudoku players on the HB Hub, on Easy, Medium, Hard and Expert">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games">
        <a class="casino-back" href="/games/sudoku/">&larr; Sudoku</a>
        <h1>Sudoku leaderboard</h1>
        <p class="casino-intro">
            Easy, Medium, Hard and Expert have a leaderboard each, of everyone who has solved a puzzle there without a
            hint. A time is timed by HB Hub's server, from the start of the game to the last number, without the time it
            was paused or you were away from the page. A solve faster than a person can fill in the board doesn't count.
        </p>

        <nav class="casino-board-links" aria-label="Level">
            <?php foreach (HBHUB_SUDOKU_LEVELS as $info): ?>
                <a class="casino-btn<?= $info['key'] === $levelKey ? ' casino-btn-primary' : '' ?>"
                   href="?<?= htmlspecialchars(http_build_query(['level' => $info['key'], 'sort' => $sort, 'dir' => $dir])) ?>"<?= $info['key'] === $levelKey ? ' aria-current="page"' : '' ?>><?= $info['name'] ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="<?= $levelName ?> leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has solved a <?= $levelName ?> puzzle without a hint yet. <a href="/games/sudoku/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($levelKey, $column, $sort, $dir)) ?>">
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
                                    <td><?= hbHubSudokuTime($row['best']) ?></td>
                                    <td><?= hbHubSudokuTime($row['average']) ?></td>
                                    <td><?= number_format($row['solved']) ?></td>
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
