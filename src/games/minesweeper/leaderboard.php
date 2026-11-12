<?php
// Minesweeper leaderboard, one for each difficulty (?size=easy, medium or hard, as tabs), from HBHub-MinesweeperGames:
// the fastest win and the average win time, wins, games played and win %. The times are the server's own (see
// assets/php/games/minesweeper/minesweeper.php), so they can be trusted. Sorted by any column with
// ?sort=column&dir=asc|desc, which the headings link to; the fastest win first to begin with.
// Only players with a win are on it. Win % only counts from LEADERBOARD_RANKED_GAMES games, so one lucky game doesn't
// top it: anyone with fewer has "–", and comes after everyone with one when sorting by it (whichever way).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/minesweeper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_GAMES = 5;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'best' => ['Best time', 'asc'],
    'average' => ['Avg. time', 'asc'],
    'wins' => ['Wins', 'desc'],
    'played' => ['Played', 'desc'],
    'winRate' => ['Win %', 'desc'],
];
const LEADERBOARD_NULLABLE = ['winRate']; // "–" when there's nothing to show yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sizes = []; // 'easy' => 9, ...
foreach (HBHUB_MINESWEEPER_SIZES as $squares => $info) {
    $sizes[$info['key']] = $squares;
}
$sizeKey = is_string($_GET['size'] ?? null) && isset($sizes[$_GET['size']]) ? $_GET['size'] : 'medium';
$size = $sizes[$sizeKey];
$sizeName = HBHUB_MINESWEEPER_SIZES[$size]['name'];
$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'best';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Everyone's totals on the size, for players with a win. Banned and deleted accounts (userStatus NULL) are left out.
// A game still being played counts as played, as it isn't known yet whether it'll be finished.
$statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, COUNT(*) AS played,
        SUM(g.gameResult = ?) AS wins, MIN(g.gameMs) AS best, AVG(g.gameMs) AS average
    FROM `HBHub-MinesweeperGames` g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    WHERE g.gameSize = ? AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY g.userId, u.userName, u.userAvatarAttachmentId
    HAVING wins > 0');
$statement->execute([HBHUB_MINESWEEPER_WON, $size, LEADERBOARD_STATUS_BANNED]);

$rows = [];
foreach ($statement->fetchAll() as $row) {
    $rows[] = [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'best' => (int) $row['best'],
        'average' => (int) round((float) $row['average']),
        'wins' => (int) $row['wins'],
        'played' => (int) $row['played'],
        'winRate' => (int) $row['played'] >= LEADERBOARD_RANKED_GAMES ? (int) $row['wins'] / (int) $row['played'] : null,
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
    // A tie goes to the faster best time, then who won more, then by name
    return $order ?: ($a['best'] <=> $b['best'] ?: $b['wins'] <=> $a['wins']
        ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $sizeKey, string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['size' => $sizeKey, 'sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Minesweeper leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="The fastest Minesweeper players on the HB Hub, on Easy, Medium and Hard">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games">
        <a class="casino-back" href="/games/minesweeper/">&larr; Minesweeper</a>
        <h1>Minesweeper leaderboard</h1>
        <p class="casino-intro">
            Easy, Medium and Hard have a leaderboard each, of everyone who has won there. A time is from your first click
            to the one that wins, timed by HB Hub's server. Win % is only shown from <?= LEADERBOARD_RANKED_GAMES ?>
            games, and a game you leave or start over counts as played.
        </p>

        <nav class="casino-board-links" aria-label="Difficulty">
            <?php foreach (HBHUB_MINESWEEPER_SIZES as $info): ?>
                <a class="casino-btn<?= $info['key'] === $sizeKey ? ' casino-btn-primary' : '' ?>"
                   href="?<?= htmlspecialchars(http_build_query(['size' => $info['key'], 'sort' => $sort, 'dir' => $dir])) ?>"<?= $info['key'] === $sizeKey ? ' aria-current="page"' : '' ?>><?= $info['name'] ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="<?= $sizeName ?> leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has won on <?= $sizeName ?> yet. <a href="/games/minesweeper/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($sizeKey, $column, $sort, $dir)) ?>">
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
                                    <td><?= hbHubMinesweeperTime($row['best']) ?></td>
                                    <td><?= hbHubMinesweeperTime($row['average']) ?></td>
                                    <td><?= number_format($row['wins']) ?></td>
                                    <td><?= number_format($row['played']) ?></td>
                                    <td>
                                        <?php if ($row['winRate'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_GAMES ?> games">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['winRate'] * 100, 1) ?>%
                                        <?php endif; ?>
                                    </td>
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
