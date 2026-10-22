<?php
// UNO leaderboard: everyone who has finished a game, from HBHub-UnoResults (one row per player per game, results.php):
// games, wins, losses (leaving a game counts as one), win % (out of every game), points (what all their wins scored,
// the cards left in everyone else's hands) and their best win (the most points in one game). Sorted by any column with
// ?sort=column&dir=asc|desc, which the column headings link to.
// Win % only counts from LEADERBOARD_RANKED_GAMES games, so one lucky game doesn't top it: anyone with fewer has "–",
// and comes after everyone with one when sorting by it (whichever way).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/results.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_GAMES = 5;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'games' => ['Games', 'desc'],
    'wins' => ['Wins', 'desc'],
    'losses' => ['Losses', 'desc'],
    'winRate' => ['Win %', 'desc'],
    'points' => ['Points', 'desc'],
    'bestWin' => ['Best win', 'desc'],
];
const LEADERBOARD_NULLABLE = ['winRate', 'bestWin']; // "–" when there's nothing to show yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'wins';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];

// Banned and deleted accounts (userStatus NULL) are left out
$statement = $db->prepare('SELECT r.userId, u.userName, u.userAvatarAttachmentId,
        COUNT(*) AS games,
        SUM(r.playerResult = ?) AS wins,
        SUM(r.playerPoints) AS points,
        MAX(CASE WHEN r.playerResult = ? THEN r.playerPoints END) AS bestWin
    FROM `HBHub-UnoResults` r
    JOIN `HBHub-Users` u ON u.userId = r.userId
    WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY r.userId, u.userName, u.userAvatarAttachmentId');
$statement->execute([HBHUB_UNO_RESULT_WIN, HBHUB_UNO_RESULT_WIN, LEADERBOARD_STATUS_BANNED]);

$rows = array_map(static function (array $row): array {
    $games = (int) $row['games'];
    $wins = (int) $row['wins'];
    return [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'games' => $games,
        'wins' => $wins,
        'losses' => $games - $wins,
        'winRate' => $games >= LEADERBOARD_RANKED_GAMES ? $wins / $games : null,
        'points' => (int) $row['points'],
        'bestWin' => $row['bestWin'] !== null ? (int) $row['bestWin'] : null,
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
    <title>UNO leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who wins the most UNO games on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games">
        <a class="casino-back" href="/games/uno/">&larr; UNO</a>
        <h1>UNO leaderboard</h1>
        <p class="casino-intro">
            Every finished game counts, and leaving a game before it's over counts as a loss. Win % is only shown from
            <?= LEADERBOARD_RANKED_GAMES ?> games, so one lucky game doesn't top the board. A win scores the cards left in
            everyone else's hands: Points is all of them together, and Best win the most in one game.
        </p>

        <section class="casino-card" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has finished a game yet. <a href="/games/uno/">Be the first!</a></p>
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
                                    <td><?= number_format($row['losses']) ?></td>
                                    <td>
                                        <?php if ($row['winRate'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_GAMES ?> games">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['winRate'] * 100, 1) ?>%
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($row['points']) ?></td>
                                    <td>
                                        <?php if ($row['bestWin'] === null): ?>
                                            <span class="casino-unranked" title="No wins yet">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['bestWin']) ?> <?= $row['bestWin'] === 1 ? 'point' : 'points' ?>
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
