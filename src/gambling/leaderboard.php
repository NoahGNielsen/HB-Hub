<?php
// The whole casino's leaderboard: everyone who has played any casino game, with the rounds they've played (Blackjack
// and Baccarat hands and Roulette spins together), the chips they've won or lost in each game, all of it together, and
// their chips now. The games' own rounds come from HBHub-BlackjackPlayerRounds, HBHub-BaccaratPlayerRounds and
// HBHub-RouletteSpins (playerNet / spinNet: chips won or lost, insurance included), the chips from HBHub-CasinoWallets.
// Sorted by any column with ?sort=column&dir=asc|desc, which the column headings link to. A game someone has never
// played is "–", and comes after everyone who has when sorting by it (whichever way).
// Each game's own leaderboard (gambling/<game>/leaderboard.php) has the wins, losses and win %.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'rounds' => ['Rounds', 'desc'],
    'blackjack' => ['Blackjack', 'desc'],
    'baccarat' => ['Baccarat', 'desc'],
    'roulette' => ['Roulette', 'desc'],
    'net' => ['Total won / lost', 'desc'],
    'chips' => ['Chips', 'desc'],
];
const LEADERBOARD_NULLABLE = ['blackjack', 'baccarat', 'roulette']; // "–" for a game never played
const LEADERBOARD_GAMES = [ // the games' own leaderboards, linked above the table
    'Blackjack' => '/gambling/blackjack/leaderboard',
    'Baccarat' => '/gambling/baccarat/leaderboard',
    'Roulette' => '/gambling/roulette/leaderboard',
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'net';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Each game's rounds and chips won or lost per player, one line per game, added up per player. Banned and deleted
// accounts (userStatus NULL) are left out
$statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, COALESCE(w.walletChips, 0) AS chips,
        SUM(g.blackjackRounds) AS blackjackRounds, SUM(g.blackjackNet) AS blackjackNet,
        SUM(g.baccaratRounds) AS baccaratRounds, SUM(g.baccaratNet) AS baccaratNet,
        SUM(g.rouletteRounds) AS rouletteRounds, SUM(g.rouletteNet) AS rouletteNet
    FROM (
        SELECT userId, COUNT(*) AS blackjackRounds, SUM(playerNet) AS blackjackNet,
            0 AS baccaratRounds, 0 AS baccaratNet, 0 AS rouletteRounds, 0 AS rouletteNet
        FROM `HBHub-BlackjackPlayerRounds` GROUP BY userId
        UNION ALL
        SELECT userId, 0, 0, COUNT(*), SUM(playerNet), 0, 0 FROM `HBHub-BaccaratPlayerRounds` GROUP BY userId
        UNION ALL
        SELECT userId, 0, 0, 0, 0, COUNT(*), SUM(spinNet) FROM `HBHub-RouletteSpins` GROUP BY userId
    ) g
    JOIN `HBHub-Users` u ON u.userId = g.userId
    LEFT JOIN `HBHub-CasinoWallets` w ON w.userId = g.userId
    WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
    GROUP BY g.userId, u.userName, u.userAvatarAttachmentId, w.walletChips');
$statement->execute([LEADERBOARD_STATUS_BANNED]);

$rows = array_map(static function (array $row): array {
    $games = [];
    foreach (['blackjack', 'baccarat', 'roulette'] as $game) {
        $games[$game] = (int) $row[$game . 'Rounds'] > 0 ? (int) $row[$game . 'Net'] : null;
    }
    return [
        'userId' => (int) $row['userId'],
        'player' => $row['userName'],
        'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
        'rounds' => (int) $row['blackjackRounds'] + (int) $row['baccaratRounds'] + (int) $row['rouletteRounds'],
        ...$games,
        'net' => (int) $row['blackjackNet'] + (int) $row['baccaratNet'] + (int) $row['rouletteNet'],
        'chips' => (int) $row['chips'],
    ];
}, $statement->fetchAll());

usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    if (in_array($sort, LEADERBOARD_NULLABLE, true) && ($a[$sort] === null) !== ($b[$sort] === null)) {
        return $a[$sort] === null ? 1 : -1; // never played it: after everyone who has
    }
    $order = $sort === 'player'
        ? strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']))
        : $a[$sort] <=> $b[$sort];
    if ($dir === 'desc') {
        $order = -$order;
    }
    // A tie goes to who played more, then by name
    return $order ?: ($b['rounds'] <=> $a['rounds'] ?: strcmp(mb_strtolower($a['player']), mb_strtolower($b['player'])));
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

/**
 * A won / lost cell: green with a "+" for chips won, red with a "−" for chips lost, and a faint "–" for a game never
 * played. $class is added to the cell (casino-board-total for the total).
 */
function leaderboardNetCell(?int $net, string $class = ''): string
{
    if ($net === null) {
        return '<td><span class="casino-unranked" title="Never played">–</span></td>';
    }
    $classes = trim($class . ' ' . ($net > 0 ? 'casino-net-up' : ($net < 0 ? 'casino-net-down' : '')));
    return '<td' . ($classes !== '' ? ' class="' . $classes . '"' : '') . '>'
        . ($net > 0 ? '+' : ($net < 0 ? '−' : '')) . number_format(abs($net)) . '</td>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Casino leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who has won the most chips in the HB Hub casino, in every game together">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <h1>Casino leaderboard</h1>
        <p class="casino-intro">
            How many chips everyone has won or lost in each game, and in the whole casino together: what their rounds paid
            back, minus what they bet on them. The daily chips don't count towards it, but they're in Chips, which is
            everyone's chips right now. Wins, losses and win % are on each game's own leaderboard.
        </p>

        <nav class="casino-board-links" aria-label="Each game's leaderboard">
            <?php foreach (LEADERBOARD_GAMES as $name => $url): ?>
                <a class="casino-btn" href="<?= $url ?>"><?= $name ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has played in the casino yet. <a href="/gambling/">Be the first!</a></p>
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
                                    <td><?= number_format($row['rounds']) ?></td>
                                    <?= leaderboardNetCell($row['blackjack']) ?>
                                    <?= leaderboardNetCell($row['baccarat']) ?>
                                    <?= leaderboardNetCell($row['roulette']) ?>
                                    <?= leaderboardNetCell($row['net'], 'casino-board-total') ?>
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
