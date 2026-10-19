<?php
// Roulette leaderboard: everyone who has spun, from HBHub-RouletteSpins (one row per spin): spins, wins and losses (by
// whether the spin won or lost chips overall), win %, the biggest win on one spin, chips won or lost on Roulette
// altogether and their chips now. Sorted by any column with ?sort=column&dir=asc|desc, which the column headings link to.
// Win % only counts from LEADERBOARD_RANKED_SPINS spins, so one lucky spin doesn't top it: anyone with fewer has "–",
// and comes after everyone with a win % when sorting by it (whichever way).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_RANKED_SPINS = 20;
const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'spins' => ['Spins', 'desc'],
    'wins' => ['Wins', 'desc'],
    'losses' => ['Losses', 'desc'],
    'winRate' => ['Win %', 'desc'],
    'biggestWin' => ['Biggest win', 'desc'],
    'net' => ['Won / lost', 'desc'],
    'chips' => ['Chips', 'desc'],
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'net';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];

// Banned and deleted accounts (userStatus NULL) are left out
$statement = $db->prepare('SELECT s.userId, u.userName, u.userAvatarAttachmentId, COALESCE(w.walletChips, 0) AS chips,
        COUNT(*) AS spins,
        SUM(s.spinNet > 0) AS wins,
        SUM(s.spinNet < 0) AS losses,
        GREATEST(MAX(s.spinNet), 0) AS biggestWin,
        SUM(s.spinNet) AS net
    FROM `HBHub-RouletteSpins` s
    JOIN `HBHub-Users` u ON u.userId = s.userId
    LEFT JOIN `HBHub-CasinoWallets` w ON w.userId = s.userId
    WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY s.userId, u.userName, u.userAvatarAttachmentId, w.walletChips');
$statement->execute([LEADERBOARD_STATUS_BANNED]);

$rows = array_map(static function (array $row): array {
    $spins = (int) $row['spins'];
    return [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'spins' => $spins,
        'wins' => (int) $row['wins'],
        'losses' => (int) $row['losses'],
        'winRate' => $spins >= LEADERBOARD_RANKED_SPINS ? (int) $row['wins'] / $spins : null,
        'biggestWin' => (int) $row['biggestWin'],
        'net' => (int) $row['net'],
        'chips' => (int) $row['chips'],
    ];
}, $statement->fetchAll());

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    if ($sort === 'winRate' && ($a['winRate'] === null) !== ($b['winRate'] === null)) {
        return $a['winRate'] === null ? 1 : -1; // too few spins: after everyone with a win %
    }
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to who spun more, then by name
    return $order ?: ($b['spins'] <=> $a['spins'] ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
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
    <title>Roulette leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who wins the most at Roulette in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino">
        <a class="casino-back" href="/gambling/roulette/">&larr; Roulette</a>
        <h1>Roulette leaderboard</h1>
        <p class="casino-intro">
            A spin is a win when it paid back more than everything bet on it, and a loss when it paid back less.
            Win % is only shown from <?= LEADERBOARD_RANKED_SPINS ?> spins, so one lucky spin doesn't top the board.
        </p>

        <section class="casino-card" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has spun yet. <a href="/gambling/roulette/">Be the first!</a></p>
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
                                    <td><?= number_format($row['spins']) ?></td>
                                    <td><?= number_format($row['wins']) ?></td>
                                    <td><?= number_format($row['losses']) ?></td>
                                    <td>
                                        <?php if ($row['winRate'] === null): ?>
                                            <span class="casino-unranked" title="Shown from <?= LEADERBOARD_RANKED_SPINS ?> spins">–</span>
                                        <?php else: ?>
                                            <?= number_format($row['winRate'] * 100, 1) ?>%
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['biggestWin'] === 0): ?>
                                            <span class="casino-unranked">–</span>
                                        <?php else: ?>
                                            +<?= number_format($row['biggestWin']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $row['net'] > 0 ? 'casino-net-up' : ($row['net'] < 0 ? 'casino-net-down' : '') ?>">
                                        <?= $row['net'] > 0 ? '+' : ($row['net'] < 0 ? '−' : '') ?><?= number_format(abs($row['net'])) ?>
                                    </td>
                                    <td><?= number_format($row['chips']) ?></td>
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
