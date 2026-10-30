<?php
// Ore Mining Inc. leaderboard, from the numbers each save keeps in its own columns in HBHub-MinerSaves (written by
// hbHubMinerSync() in assets/php/games/spaceOreMiner/miner.php on every save): ore mined ever, ore per second (without
// comets), Dark Matter earned in this universe, Antimatter earned in this multiverse, Strange Matter ever earned, Warp
// Jumps, clicks and achievements. Sorted by any column with ?sort=column&dir=asc|desc, which the headings link to.
// The other tabs (?board=, LEADERBOARD_BOARDS): rising is the same list for the players who haven't learned the whole
// skill tree yet (saveSkills), so the ones still on their way have a board of their own. engine and engineAll list who
// has given the most Engine Parts to the Cosmic Engine, from HBHub-MinerContributions
// (assets/php/games/spaceOreMiner/engine.php): to the one being built, and to every Engine ever, with how many came from
// Strange Matter, ore, Engine Shifts and Anomalies. They're always by parts. anomalies has the fastest times for one
// Anomaly or stack (?anomaly=key) and weekly the fastest this week (or ?week=n) for the weekly Anomaly, both from
// HBHub-MinerAnomalyTimes. Players' titles (hbHubMinerTitles()) are under their names on every tab.
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
const LEADERBOARD_BOARDS = [ // ?board= => the tab's text
    'players' => 'Players',
    'rising' => 'Rising',
    'engine' => 'Starbound contributors',
    'engineAll' => 'Starbound contributors, all time',
    'anomalies' => 'Anomaly times',
    'weekly' => 'Weekly Anomaly',
];
const LEADERBOARD_SORTED_BOARDS = ['players', 'rising']; // the ones LEADERBOARD_COLUMNS sorts

/**
 * The names of big numbers as [size, word] pairs, biggest first: from a million (10^6) up to a centillion (10^303),
 * which is as far as a float goes. Past nonillion they're put together from Latin bits, like un + vigint + illion for
 * an unvigintillion (10^66). Pairs rather than size => word, as an array key can't hold a number past 9.2 quintillion.
 * BIG_NUMBERS in assets/js/spaceOreMiner.js is the same list for the game itself.
 */
function leaderboardNumberNames(): array
{
    static $names = null;
    if ($names === null) {
        $words = ['million', 'billion', 'trillion', 'quadrillion', 'quintillion', 'sextillion', 'septillion', 'octillion',
            'nonillion'];
        foreach (['dec', 'vigint', 'trigint', 'quadragint', 'quinquagint', 'sexagint', 'septuagint', 'octogint', 'nonagint'] as $ten) {
            foreach (['', 'un', 'duo', 'tre', 'quattuor', 'quin', 'sex', 'septen', 'octo', 'novem'] as $unit) {
                $words[] = $unit . $ten . 'illion';
            }
        }
        $words[] = 'centillion';
        $names = [];
        foreach ($words as $index => $word) {
            $names[] = [(float) ('1e' . (3 * $index + 6)), $word]; // parsed rather than 10 ** n, so it's exact
        }
        $names = array_reverse($names);
    }
    return $names;
}

/**
 * A big number, short: 1,234 under a million, then like 1.23 million, as HTML. The word goes in its own span, which
 * casino.css puts small under the number, so names like "quattuordecillion" don't make the table too wide.
 */
function leaderboardNumber(float $number): string
{
    foreach (leaderboardNumberNames() as [$size, $word]) {
        if ($number >= $size) {
            return number_format($number / $size, 2) . ' <span class="casino-table-unit">' . $word . '</span>';
        }
    }
    return number_format(floor($number));
}

/**
 * A run's time as 1:02:03 (or 2:03 under an hour), like formatClock() in the game.
 */
function leaderboardClock(int $ms): string
{
    $seconds = intdiv($ms, 1000);
    $hours = intdiv($seconds, 3600);
    $rest = str_pad((string) (intdiv($seconds, 60) % 60), $hours > 0 ? 2 : 1, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    return $hours > 0 ? $hours . ':' . $rest : $rest;
}

/**
 * An Anomaly's (or a stack's) name, from its key: the names joined with +.
 */
function leaderboardAnomalyName(string $key): string
{
    return implode(' + ', array_map(static fn (string $id): string => HBHUB_MINER_ANOMALIES[$id]['name'], hbHubMinerAnomalyMods($key) ?? []));
}

/**
 * The player cell of a row ($row has userId, userName and userAvatarAttachmentId), with their title under the name.
 */
function leaderboardPlayer(array $row, array $titles): string
{
    $rowUserId = (int) $row['userId'];
    $avatarSrc = hbHubAvatarUrl($rowUserId, $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null);
    $avatar = $avatarSrc !== null ? '<img src="' . htmlspecialchars($avatarSrc) . '" alt="" loading="lazy">'
        : htmlspecialchars(mb_strtoupper(mb_substr($row['userName'], 0, 1)));
    $title = isset($titles[$rowUserId]) ? '<span class="casino-table-unit miner-board-title">' . htmlspecialchars($titles[$rowUserId]) . '</span>' : '';
    return '<a class="casino-player" href="' . htmlspecialchars(hbHubProfileUrl($rowUserId, $_SERVER['REQUEST_URI'])) . '">'
        . '<span class="casino-avatar" aria-hidden="true">' . $avatar . '</span>'
        . '<span class="casino-player-name">' . htmlspecialchars($row['userName']) . $title . '</span></a>';
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$board = is_string($_GET['board'] ?? null) && isset(LEADERBOARD_BOARDS[$_GET['board']]) ? $_GET['board'] : 'players';

// The Engine tabs: everyone who has given parts, most first (a tie goes to who gave first). Banned and deleted
// accounts are left out, like on the players' tab, though their parts still count in the Engine.
$engineRows = [];
$engineNumber = 0;
if ($board === 'engine' || $board === 'engineAll') {
    $engine = hbHubMinerEngineRow($db, false);
    $engineNumber = (int) $engine['engineNumber'];
    $statement = $db->prepare('SELECT c.contributionUserId AS userId, u.userName, u.userAvatarAttachmentId, SUM(c.contributionParts) AS parts,
            SUM(IF(c.contributionSource = ?, c.contributionParts, 0)) AS fromStrange,
            SUM(IF(c.contributionSource = ?, c.contributionParts, 0)) AS fromOre,
            SUM(IF(c.contributionSource = ?, c.contributionParts, 0)) AS fromShifts,
            SUM(IF(c.contributionSource = ?, c.contributionParts, 0)) AS fromAnomalies
        FROM `HBHub-MinerContributions` c
        JOIN `HBHub-Users` u ON u.userId = c.contributionUserId
        WHERE (? OR c.contributionEngineId = ?) AND u.userStatus IS NOT NULL AND u.userStatus <> ?
        GROUP BY c.contributionUserId, u.userName, u.userAvatarAttachmentId
        ORDER BY parts DESC, MIN(c.contributionId)
        LIMIT ' . LEADERBOARD_ROWS);
    $statement->execute([HBHUB_MINER_ENGINE_SOURCE_STRANGE, HBHUB_MINER_ENGINE_SOURCE_ORE, HBHUB_MINER_ENGINE_SOURCE_SHIFT, HBHUB_MINER_ENGINE_SOURCE_ANOMALY,
        (int) ($board === 'engineAll'), (int) $engine['engineId'], LEADERBOARD_STATUS_BANNED]);
    $engineRows = $statement->fetchAll();
}

// The Anomaly tabs: the fastest time each player has, for one Anomaly or stack (timeWeek 0, every run of it), or for
// a week's weekly Anomaly in that week. The Anomalies to pick from are every one on its own, and the stacks someone has
// finished.
$anomalyRows = [];
$anomalyKey = '';
$anomalyKeys = [];
$week = hbHubMinerWeek(hbHubMinerNow());
$thisWeek = $week;
if ($board === 'anomalies' || $board === 'weekly') {
    if ($board === 'anomalies') {
        $anomalyKeys = array_keys(HBHUB_MINER_ANOMALIES);
        foreach ($db->query('SELECT DISTINCT timeAnomaly FROM `HBHub-MinerAnomalyTimes` WHERE timeWeek = 0 ORDER BY timeAnomaly')->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!in_array($key, $anomalyKeys, true) && hbHubMinerAnomalyMods($key) !== null) {
                $anomalyKeys[] = $key;
            }
        }
        $anomalyKey = is_string($_GET['anomaly'] ?? null) && in_array($_GET['anomaly'], $anomalyKeys, true) ? $_GET['anomaly'] : $anomalyKeys[0];
        $timeWeek = 0;
    } else {
        $week = is_numeric($_GET['week'] ?? null) ? max(1, min($thisWeek, (int) $_GET['week'])) : $thisWeek;
        $anomalyKey = hbHubMinerAnomalyKey(hbHubMinerWeeklyMods($week));
        $timeWeek = $week;
    }
    $statement = $db->prepare('SELECT t.timeUserId AS userId, u.userName, u.userAvatarAttachmentId, t.timeBest, t.timeDone, t.timeUpdatedTimestamp
        FROM `HBHub-MinerAnomalyTimes` t
        JOIN `HBHub-Users` u ON u.userId = t.timeUserId
        WHERE t.timeWeek = ? AND t.timeAnomaly = ? AND u.userStatus IS NOT NULL AND u.userStatus <> ?
        ORDER BY t.timeBest, t.timeUpdatedTimestamp
        LIMIT ' . LEADERBOARD_ROWS);
    $statement->execute([$timeWeek, $anomalyKey, LEADERBOARD_STATUS_BANNED]);
    $anomalyRows = $statement->fetchAll();
}

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'ore';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Everyone who has mined anything (on Rising, only those without the whole skill tree). Banned and deleted accounts
// (userStatus NULL) are left out. A tie goes to the most ore, then by name. The column and direction come from the
// lists above, never straight from the address.
$rows = [];
if (in_array($board, LEADERBOARD_SORTED_BOARDS, true)) {
    $statement = $db->prepare('SELECT s.userId, u.userName, u.userAvatarAttachmentId, s.saveOreAllTime, s.saveOrePerSecond,
            s.saveDarkMatter, s.saveAntimatter, s.saveStrangeMatter, s.saveWarps, s.saveClicks, s.saveAchievements
        FROM `HBHub-MinerSaves` s
        JOIN `HBHub-Users` u ON u.userId = s.userId
        WHERE s.saveOreAllTime > 0 AND u.userStatus IS NOT NULL AND u.userStatus <> ?' . ($board === 'rising' ? ' AND s.saveSkills < ?' : '') . '
        ORDER BY ' . LEADERBOARD_COLUMNS[$sort][2] . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC') . ', s.saveOreAllTime DESC, u.userName
        LIMIT ' . LEADERBOARD_ROWS);
    $statement->execute($board === 'rising' ? [LEADERBOARD_STATUS_BANNED, count(HBHUB_MINER_SKILLS)] : [LEADERBOARD_STATUS_BANNED]);
    $rows = $statement->fetchAll();
}

$titles = hbHubMinerTitles($db, array_map(static fn (array $row): int => (int) $row['userId'], [...$rows, ...$engineRows, ...$anomalyRows]));

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first. On the
 * same tab.
 */
function leaderboardSortUrl(string $column, string $sort, string $dir, string $board): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(($board !== 'players' ? ['board' => $board] : []) + ['sort' => $column, 'dir' => $newDir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/spaceOreMiner.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/spaceOreMiner.css') ?>">
    <title>Ore Mining Inc. leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who has built the biggest mining company in Ore Mining Inc. on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard games">
        <a class="casino-back" href="/games/ore-mining-inc/">&larr; Ore Mining Inc.</a>
        <h1>Ore Mining Inc. leaderboard</h1>
        <!-- The tabs, in the game's own menu style -->
        <nav class="miner-menu" aria-label="Leaderboards">
            <?php foreach (LEADERBOARD_BOARDS as $boardId => $boardText): ?>
                <a class="miner-menu-link" href="<?= $boardId === 'players' ? '?' : '?board=' . $boardId ?>"<?= $boardId === $board ? ' aria-current="page"' : '' ?>>
                    <span class="miner-menu-text"><?= $boardText ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($board === 'engine' || $board === 'engineAll'): ?>
        <p class="casino-intro">
            Who has given the most Starbound Parts to <?= $board === 'engine' ? 'the ' . htmlspecialchars(HBHUB_MINER_ENGINES[$engineNumber]['name'] ?? 'Cosmic Engine') . ' (Build #' . $engineNumber . '), the one being built' : 'every build so far' ?>,
            and how many came from Strange Matter, ore, Starbound Shifts and Anomalies. It's updated every time their game saves.
        </p>

        <section class="casino-card casino-board" aria-label="Project Starbound contributors">
            <?php if ($engineRows === []): ?>
                <p class="casino-hint">Nobody has given Project Starbound any parts yet. <a href="/games/ore-mining-inc/?view=starbound">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <th scope="col">Player</th>
                                <th scope="col" aria-sort="descending">Starbound Parts</th>
                                <th scope="col">From Strange Matter</th>
                                <th scope="col">From ore</th>
                                <th scope="col">From Starbound Shifts</th>
                                <th scope="col">From Anomalies</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($engineRows as $index => $row): ?>
                                <tr<?= (int) $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $index < 3 ? ' data-podium="' . ($index + 1) . '"' : '' ?>><?= $index + 1 ?></td>
                                    <td><?= leaderboardPlayer($row, $titles) ?></td>
                                    <td><?= number_format((int) $row['parts']) ?></td>
                                    <td><?= number_format((int) $row['fromStrange']) ?></td>
                                    <td><?= number_format((int) $row['fromOre']) ?></td>
                                    <td><?= number_format((int) $row['fromShifts']) ?></td>
                                    <td><?= number_format((int) $row['fromAnomalies']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php elseif ($board === 'anomalies' || $board === 'weekly'): ?>
        <p class="casino-intro">
            <?php if ($board === 'anomalies'): ?>
                The fastest time each player has finished an Anomaly (or a stack of them) in, from going in to reaching its
                goal, time away included. Pick one:
            <?php else: ?>
                The weekly Anomaly is a stack picked for everyone each week (from Monday 00:00 UTC), worth twice the Starbound Parts
                that week. <?= $week === $thisWeek ? 'This week' : 'That week' ?> it's <strong><?= htmlspecialchars(leaderboardAnomalyName($anomalyKey)) ?></strong>.
                The fastest times finished in the week:
            <?php endif; ?>
        </p>
        <?php if ($board === 'anomalies'): ?>
            <nav class="miner-board-picks" aria-label="Anomalies">
                <?php foreach ($anomalyKeys as $key): ?>
                    <a class="casino-btn<?= $key === $anomalyKey ? ' casino-btn-primary' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['board' => 'anomalies', 'anomaly' => $key])) ?>"<?= $key === $anomalyKey ? ' aria-current="page"' : '' ?>><?= htmlspecialchars(leaderboardAnomalyName($key)) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <nav class="miner-board-picks" aria-label="Weeks">
                <?php if ($week > 1): ?>
                    <a class="casino-btn" href="?board=weekly&amp;week=<?= $week - 1 ?>">&larr; The week before</a>
                <?php endif; ?>
                <?php if ($week < $thisWeek): ?>
                    <a class="casino-btn" href="?board=weekly&amp;week=<?= $week + 1 ?>">The week after &rarr;</a>
                    <a class="casino-btn" href="?board=weekly">This week</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <section class="casino-card casino-board" aria-label="<?= htmlspecialchars(leaderboardAnomalyName($anomalyKey)) ?> times">
            <?php if ($anomalyRows === []): ?>
                <p class="casino-hint">Nobody has finished <?= htmlspecialchars(leaderboardAnomalyName($anomalyKey)) ?> yet<?= $board === 'weekly' ? ' this week' : '' ?>.
                    <a href="/games/ore-mining-inc/?view=anomalies">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <th scope="col">Player</th>
                                <th scope="col" aria-sort="ascending">Best time</th>
                                <th scope="col">Times finished</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anomalyRows as $index => $row): ?>
                                <tr<?= (int) $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $index < 3 ? ' data-podium="' . ($index + 1) . '"' : '' ?>><?= $index + 1 ?></td>
                                    <td><?= leaderboardPlayer($row, $titles) ?></td>
                                    <td><?= leaderboardClock((int) $row['timeBest']) ?></td>
                                    <td><?= number_format((int) $row['timeDone']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <p class="casino-intro">
            <?php if ($board === 'rising'): ?>
                The players still on their way: everyone who hasn't learned the whole skill tree yet.
            <?php endif; ?>
            Ore mined is everything someone has ever mined, in every galaxy. Ore per second is how fast they mine right now,
            without comets. It's updated every time their game saves.
        </p>

        <section class="casino-card casino-board" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has mined anything yet. <a href="/games/ore-mining-inc/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($column, $sort, $dir, $board)) ?>">
                                            <?= $heading ?><span class="casino-sort-arrow" aria-hidden="true"><?= $column === $sort ? ($dir === 'asc' ? '↑' : '↓') : '' ?></span>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row):
                                $place = $podium && $index < 3 ? $index + 1 : null;
                            ?>
                                <tr<?= (int) $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $place !== null ? ' data-podium="' . $place . '"' : '' ?>><?= $index + 1 ?></td>
                                    <td><?= leaderboardPlayer($row, $titles) ?></td>
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
        <?php endif; ?>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
