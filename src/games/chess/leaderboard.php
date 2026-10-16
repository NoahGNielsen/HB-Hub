<?php
// Chess leaderboard: everyone who has finished a game, from HBHub-ChessResults (one row per player per game, results.php):
// games, wins, draws, losses, score (a win is 1 point and a draw half a point, out of every game) and the fastest
// checkmate (the fewest moves). Sorted by any column with ?sort=column&dir=asc|desc, which the column headings link to.
// Score only counts from LEADERBOARD_RANKED_GAMES games, so one lucky game doesn't top it: anyone with fewer has "–",
// and comes after everyone with a score when sorting by it (whichever way). The same for the fastest mate without one.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/results.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_GAMES = 5;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'games' => ['Games', 'desc'],
    'wins' => ['Wins', 'desc'],
    'draws' => ['Draws', 'desc'],
    'losses' => ['Losses', 'desc'],
    'score' => ['Score', 'desc'],
    'fastestMate' => ['Fastest mate', 'asc'],
];
const LEADERBOARD_NULLABLE = ['score', 'fastestMate']; // "–" when there's nothing to show yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'score';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];

// Banned and deleted accounts (userStatus NULL) are left out
$statement = $db->prepare('SELECT r.userId, u.userName, u.userAvatarAttachmentId,
        COUNT(*) AS games,
        SUM(r.playerResult = ?) AS wins,
        SUM(r.playerResult = ?) AS draws,
        MIN(CASE WHEN r.playerResult = ? AND r.resultReason = \'checkmate\' THEN r.gameMoves END) AS fastestMate
    FROM `HBHub-ChessResults` r
    JOIN `HBHub-Users` u ON u.userId = r.userId
    WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY r.userId, u.userName, u.userAvatarAttachmentId');
$statement->execute([HBHUB_CHESS_RESULT_WIN, HBHUB_CHESS_RESULT_DRAW, HBHUB_CHESS_RESULT_WIN, LEADERBOARD_STATUS_BANNED]);

$rows = array_map(static function (array $row): array {
    $games = (int) $row['games'];
    $wins = (int) $row['wins'];
    $draws = (int) $row['draws'];
    return [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'games' => $games,
        'wins' => $wins,
        'draws' => $draws,
        'losses' => $games - $wins - $draws,
        'score' => $games >= LEADERBOARD_RANKED_GAMES ? ($wins + $draws / 2) / $games : null,
        'fastestMate' => $row['fastestMate'] !== null ? (int) $row['fastestMate'] : null,
    ];
}, $statement->fetchAll());

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
    // A tie goes to who played more, then by name
    return $order ?: ($b['games'] <=> $a['games'] ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Chess leaderboard - HB Hub</title>
    <meta name="description" content="Who wins the most chess games on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games">
        <a class="casino-back" href="/games/chess/">&larr; Chess</a>
        <h1>Chess leaderboard</h1>
        <p class="casino-intro">
            Every finished game counts, except aborted ones. Score is a point for a win and half a point for a draw, out of
            every game played. It's only shown from <?= LEADERBOARD_RANKED_GAMES ?> games, so one lucky game doesn't top the
            board. Fastest mate is the fewest moves a player has checkmated someone in.
        </p>

        <section class="casino-card" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has finished a game yet. <a href="/games/chess/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($column, $sort, $dir)) ?>">
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
                            ?>
                                <tr<?= $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"><?= $isUnranked ? '–' : $index + 1 ?></td>
                                    <td>
                                        <a class="casino-player" href="<?= htmlspecialchars(hbHubProfileUrl($row['userId'])) ?>">
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
                                    <td><?= number_format($row['games']) ?></td>
                                    <td><?= number_format($row['wins']) ?></td>
                                    <td><?= number_format($row['draws']) ?></td>
                                    <td><?= number_format($row['losses']) ?></td>
                                    <td>
                                        <?php if ($row['score'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_GAMES ?> games">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['score'] * 100, 1) ?>%
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['fastestMate'] === null): ?>
                                            <span class="casino-unranked" title="No checkmates yet">–</span>
                                        <?php else: ?>
                                            <?= $row['fastestMate'] ?> <?= $row['fastestMate'] === 1 ? 'move' : 'moves' ?>
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
