<?php
// Space Ore Miner leaderboard, from the numbers each save keeps in its own columns in HBHub-MinerSaves (written by
// hbHubMinerSync() in assets/php/games/spaceOreMiner/miner.php on every save): ore mined ever, ore per second (without
// comets), Dark Matter earned in this universe, Antimatter earned in this multiverse, Strange Matter ever earned, Warp
// Jumps, clicks and achievements. Sorted by any column with ?sort=column&dir=asc|desc, which the headings link to.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_STATUS_BANNED = 4; // userStatus in HBHub-Users
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first, the SQL it sorts by]
    'player' => ['Player', 'asc', 'u.userName'],
    'ore' => ['Ore mined', 'desc', 's.saveOreAllTime'],
    'rate' => ['Ore per second', 'desc', 's.saveOrePerSecond'],
    'dark' => ['Dark Matter', 'desc', 's.saveDarkMatter'],
    'anti' => ['Antimatter', 'desc', 's.saveAntimatter'],
    'strange' => ['Strange Matter', 'desc', 's.saveStrangeMatter'],
    'warps' => ['Warp Jumps', 'desc', 's.saveWarps'],
    'clicks' => ['Clicks', 'desc', 's.saveClicks'],
    'achievements' => ['Achievements', 'desc', 's.saveAchievements'],
];

/**
 * A big number, short: 1,234 under a million, then like 1.23 million.
 */
function leaderboardNumber(float $number): string
{
    // Pairs rather than size => word, as an array key can't hold a number past 9.2 quintillion
    foreach ([[1e24, 'septillion'], [1e21, 'sextillion'], [1e18, 'quintillion'], [1e15, 'quadrillion'], [1e12, 'trillion'],
        [1e9, 'billion'], [1e6, 'million']] as [$size, $word]) {
        if ($number >= $size) {
            return number_format($number / $size, 2) . ' ' . $word;
        }
    }
    return number_format(floor($number));
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'ore';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];

// Everyone who has mined anything. Banned and deleted accounts (userStatus NULL) are left out. A tie goes to the most
// ore, then by name. The column and direction come from the lists above, never straight from the address.
$statement = $db->prepare('SELECT s.userId, u.userName, u.userAvatarAttachmentId, s.saveOreAllTime, s.saveOrePerSecond,
        s.saveDarkMatter, s.saveAntimatter, s.saveStrangeMatter, s.saveWarps, s.saveClicks, s.saveAchievements
    FROM `HBHub-MinerSaves` s
    JOIN `HBHub-Users` u ON u.userId = s.userId
    WHERE s.saveOreAllTime > 0 AND u.userStatus IS NOT NULL AND u.userStatus <> ?
    ORDER BY ' . LEADERBOARD_COLUMNS[$sort][2] . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC') . ', s.saveOreAllTime DESC, u.userName
    LIMIT ' . LEADERBOARD_ROWS);
$statement->execute([LEADERBOARD_STATUS_BANNED]);
$rows = $statement->fetchAll();

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
    <title>Space Ore Miner leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who has built the biggest mining company in Space Ore Miner on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games">
        <a class="casino-back" href="/games/spaceOreMiner/">&larr; Space Ore Miner</a>
        <h1>Space Ore Miner leaderboard</h1>
        <p class="casino-intro">
            Ore mined is everything someone has ever mined, in every galaxy. Ore per second is how fast they mine right now,
            without comets. It's updated every time their game saves.
        </p>

        <section class="casino-card" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has mined anything yet. <a href="/games/spaceOreMiner/">Be the first!</a></p>
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
                                $rowUserId = (int) $row['userId'];
                                $avatarSrc = hbHubAvatarUrl($rowUserId, $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null);
                            ?>
                                <tr<?= $rowUserId === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"><?= $index + 1 ?></td>
                                    <td>
                                        <a class="casino-player" href="<?= htmlspecialchars(hbHubProfileUrl($rowUserId)) ?>">
                                            <span class="casino-avatar" aria-hidden="true">
                                                <?php if ($avatarSrc !== null): ?>
                                                    <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($row['userName'], 0, 1))) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="casino-player-name"><?= htmlspecialchars($row['userName']) ?></span>
                                        </a>
                                    </td>
                                    <td><?= leaderboardNumber((float) $row['saveOreAllTime']) ?></td>
                                    <td><?= leaderboardNumber((float) $row['saveOrePerSecond']) ?></td>
                                    <td><?= leaderboardNumber((float) $row['saveDarkMatter']) ?></td>
                                    <td><?= leaderboardNumber((float) $row['saveAntimatter']) ?></td>
                                    <td><?= number_format((int) $row['saveStrangeMatter']) ?></td>
                                    <td><?= number_format((int) $row['saveWarps']) ?></td>
                                    <td><?= number_format((int) $row['saveClicks']) ?></td>
                                    <td><?= (int) $row['saveAchievements'] ?> / <?= count(HBHUB_MINER_ACHIEVEMENTS) ?></td>
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
