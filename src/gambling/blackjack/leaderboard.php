<?php
// Blackjack leaderboard: everyone who has played a hand, from HBHub-BlackjackPlayerRounds (one row per player per
// round): hands, wins (blackjacks included), losses, draws and win %. Sorted by any column with ?sort=column&dir=asc|desc,
// which the column headings link to. Everyone's chips are on the whole casino's leaderboard (gambling/leaderboard.php).
// Win % only counts from LEADERBOARD_RANKED_HANDS hands, so one lucky hand doesn't top it: anyone with fewer has "–",
// and comes after everyone with a win % when sorting by it (whichever way).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_HANDS = 20;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'hands' => ['Hands', 'desc'],
    'wins' => ['Wins', 'desc'],
    'losses' => ['Losses', 'desc'],
    'draws' => ['Draws', 'desc'],
    'winRate' => ['Win %', 'desc'],
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'winRate';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Banned and deleted accounts (userStatus NULL) are left out
$statement = $db->prepare('SELECT p.userId, u.userName, u.userAvatarAttachmentId,
        COUNT(*) AS hands,
        SUM(p.playerResult IN (\'win\', \'blackjack\')) AS wins,
        SUM(p.playerResult = \'loss\') AS losses,
        SUM(p.playerResult = \'push\') AS draws
    FROM `HBHub-BlackjackPlayerRounds` p
    JOIN `HBHub-Users` u ON u.userId = p.userId
    WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY p.userId, u.userName, u.userAvatarAttachmentId');
$statement->execute([LEADERBOARD_STATUS_BANNED]);

$rows = array_map(static function (array $row): array {
    $hands = (int) $row['hands'];
    return [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'hands' => $hands,
        'wins' => (int) $row['wins'],
        'losses' => (int) $row['losses'],
        'draws' => (int) $row['draws'],
        'winRate' => $hands >= LEADERBOARD_RANKED_HANDS ? (int) $row['wins'] / $hands : null,
    ];
}, $statement->fetchAll());

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    if ($sort === 'winRate' && ($a['winRate'] === null) !== ($b['winRate'] === null)) {
        return $a['winRate'] === null ? 1 : -1; // too few hands: after everyone with a win %
    }
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to who played more, then by name
    return $order ?: ($b['hands'] <=> $a['hands'] ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
});
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)

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
    <title>Blackjack leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who wins the most at Blackjack in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard">
        <a class="casino-back" href="/gambling/blackjack/">&larr; Blackjack</a>
        <h1>Blackjack leaderboard</h1>
        <p class="casino-intro">
            Wins count blackjacks too. A hand you split counts once: won, lost or a draw by the chips it made overall.
            Win % is only shown from <?= LEADERBOARD_RANKED_HANDS ?> hands, so one lucky hand doesn't top the board.
            Everyone's chips are on the <a href="/gambling/leaderboard">casino leaderboard</a>.
        </p>

        <section class="casino-card casino-board" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has played a hand yet. <a href="/gambling/blackjack/">Be the first!</a></p>
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
                                $isUnranked = $sort === 'winRate' && $row['winRate'] === null;
                                $place = $podium && !$isUnranked && $index < 3 ? $index + 1 : null;
                            ?>
                                <tr<?= $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $place !== null ? ' data-podium="' . $place . '"' : '' ?>><?= $isUnranked ? '–' : $index + 1 ?></td>
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
                                    <td><?= number_format($row['hands']) ?></td>
                                    <td><?= number_format($row['wins']) ?></td>
                                    <td><?= number_format($row['losses']) ?></td>
                                    <td><?= number_format($row['draws']) ?></td>
                                    <td>
                                        <?php if ($row['winRate'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_HANDS ?> hands">–</span>
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
