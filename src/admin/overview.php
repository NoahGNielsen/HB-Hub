<?php
// Admin panel's Overview: the start page of the admin panel, shown at /admin/ when there's no ?list= (admin/index.php
// requires this file and stops), and "Overview" at the top of the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here. Opened as /admin/overview it sends
// the browser on to /admin/, so the page only has the one address.
//
// A dashboard of numbers about the whole site, all worked out on each load (nothing is stored for it):
// - the headline numbers: accounts, online now, seen in the last 24 hours and 7 days, new accounts and messages
// - two bar charts of the last ADMIN_OVERVIEW_DAYS days: messages sent and new accounts each day (inline SVG, a
//   tooltip on each day, and the same numbers as a table under "Show as a table")
// - Chat: chats, messages, reactions and attachments, and the busiest chats and people of the last 7 days
// - Casino: the chips in everyone's wallets, and each game's rounds, players, chips bet and what the house won or
//   lost in the last 7 days
// - Games: each game's games played and players in the last 7 days, and games played ever
// - Needs a look: new feedback, browser errors, crash reports, and locked, banned and ghost banned accounts, each
//   linking to where it's dealt with
// The casino's and the games' tables keep their times in UTC (UTC_TIMESTAMP()), the users', chats' and messages' in
// the database's own time (NOW(), CURDATE()), so each query goes by its table's clock.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_OVERVIEW_DAYS = 30; // days in the bar charts
const ADMIN_OVERVIEW_TOP = 5; // chats and people in the "busiest" lists
// The bar charts' drawing area, in SVG units (it's stretched to the card's width)
const ADMIN_OVERVIEW_CHART_W = 600;
const ADMIN_OVERVIEW_CHART_H = 140;
const ADMIN_OVERVIEW_BAR_GAP = 2; // space between bars

adminOnly($hbHubUser);

if (basename($_SERVER['SCRIPT_NAME']) === 'overview.php') {
    header('Location: /admin/', true, 301);
    exit;
}

$db = hbHubDatabase();

/**
 * The headline numbers and the rest of the single numbers on the page, in one query. Deleted accounts (userStatus
 * NULL) and the HB Hub System account (userId 0) don't count as accounts; messages are only real ones, not the notices
 * about changes in a chat (messageEvent 1). An account counts as locked the same way as in the lists (index.php):
 * locked less than $lockoutMinutes ago.
 *
 * @return array<string, int|string|null>
 */
function adminOverviewTotals(PDO $db, int $lockoutMinutes): array
{
    $statement = $db->prepare('SELECT
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userId <> 0) AS accounts,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND ' . adminUserOnlineSql() . ') AS online,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userLastSeenTimestamp >= NOW() - INTERVAL 1 DAY) AS seenDay,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userLastSeenTimestamp >= NOW() - INTERVAL 7 DAY) AS seenWeek,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userCreatedTimestamp >= NOW() - INTERVAL 7 DAY) AS newWeek,
        (SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 1 DAY) AS messagesDay,
        (SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 7 DAY) AS messagesWeek,
        (SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0) AS messagesEver,
        (SELECT COUNT(*) FROM `HBHub-Chats` WHERE chatStatus = ' . HBHUB_CHAT_STATUS_ACTIVE . ' AND chatType = ' . HBHUB_CHAT_TYPE_DM . ') AS dms,
        (SELECT COUNT(*) FROM `HBHub-Chats` WHERE chatStatus = ' . HBHUB_CHAT_STATUS_ACTIVE . ' AND chatType <> ' . HBHUB_CHAT_TYPE_DM . ') AS groupChats,
        (SELECT COUNT(DISTINCT chatId) FROM `HBHub-Messages` WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 7 DAY) AS chatsWeek,
        (SELECT COUNT(*) FROM `HBHub-MessageReactions` WHERE reactionTimestamp >= NOW() - INTERVAL 7 DAY) AS reactionsWeek,
        (SELECT COUNT(*) FROM `HBHub-ChatAttachments` WHERE chatId IS NOT NULL AND attachmentUploadedTimestamp >= NOW() - INTERVAL 7 DAY) AS attachmentsWeek,
        (SELECT COALESCE(SUM(walletChips), 0) FROM `HBHub-CasinoWallets`) AS chips,
        (SELECT COUNT(*) FROM `HBHub-CasinoWallets`) AS wallets,
        (SELECT COUNT(*) FROM `HBHub-CasinoTransactions` WHERE transactionReason = \'dailyBonus\' AND transactionTimestamp >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS dailyBonuses,
        (SELECT COUNT(*) FROM `HBHub-Feedback` WHERE feedbackDoneTimestamp IS NULL) AS feedback,
        (SELECT COALESCE(SUM(watchdogCount), 0) FROM `HBHub-Watchdog` WHERE watchdogLastTimestamp > NOW() - INTERVAL 1 DAY) AS browserErrors,
        (SELECT COUNT(*) FROM `HBHub-MinerCrashReports`) AS crashReports,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus = ' . HBHUB_SESSION_STATUS_BANNED . ') AS banned,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userGhostBannedTimestamp IS NOT NULL) AS ghostBanned,
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ' . HBHUB_SESSION_STATUS_BANNED . '
            AND userAccountLockedTimestamp + INTERVAL ? MINUTE > NOW()) AS locked');
    $statement->execute([$lockoutMinutes]);
    return $statement->fetch();
}

/**
 * How many of something there were on each of the last ADMIN_OVERVIEW_DAYS days, today last. $sql selects one row per
 * day as (day, count) for the days since ? (a date), and goes by the database's own clock. Days with none are 0.
 *
 * @return array<string, int> 'Y-m-d' => count, oldest first
 */
function adminOverviewPerDay(PDO $db, string $sql): array
{
    // Today as the database sees it, so a day starts at the same time as the rows' own timestamps do
    $today = new DateTimeImmutable($db->query('SELECT CURDATE()')->fetchColumn());
    $first = $today->modify('-' . (ADMIN_OVERVIEW_DAYS - 1) . ' days');
    $days = [];
    for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
        $days[$day->format('Y-m-d')] = 0;
    }

    $statement = $db->prepare($sql);
    $statement->execute([$first->format('Y-m-d')]);
    foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$day, $count]) {
        if (isset($days[$day])) {
            $days[$day] = (int) $count;
        }
    }
    return $days;
}

/**
 * The casino's games in the last 7 days: rounds or spins, how many played, everything bet and what the house won
 * (the players' net the other way round, so a negative number is the players winning). Blackjack's insurance counts
 * as bet.
 *
 * @return array<string, array{rounds: int, players: int, wagered: int, house: int}> the game's name => its numbers
 */
function adminOverviewCasino(PDO $db): array
{
    $week = 'UTC_TIMESTAMP() - INTERVAL 7 DAY';
    $games = [
        'Blackjack' => 'SELECT COUNT(DISTINCT roundId), COUNT(DISTINCT userId), COALESCE(SUM(playerWagered + playerInsurance), 0), COALESCE(-SUM(playerNet), 0)
            FROM `HBHub-BlackjackPlayerRounds` WHERE playerTimestamp >= ' . $week,
        'Baccarat' => 'SELECT COUNT(DISTINCT roundId), COUNT(DISTINCT userId), COALESCE(SUM(playerWagered), 0), COALESCE(-SUM(playerNet), 0)
            FROM `HBHub-BaccaratPlayerRounds` WHERE playerTimestamp >= ' . $week,
        'Roulette' => 'SELECT COUNT(*), COUNT(DISTINCT userId), COALESCE(SUM(spinWagered), 0), COALESCE(-SUM(spinNet), 0)
            FROM `HBHub-RouletteSpins` WHERE spinTimestamp >= ' . $week,
        'Slots' => 'SELECT COUNT(*), COUNT(DISTINCT userId), COALESCE(SUM(spinBet), 0), COALESCE(-SUM(spinNet), 0)
            FROM `HBHub-SlotSpins` WHERE spinTimestamp >= ' . $week,
    ];
    $casino = [];
    foreach ($games as $name => $sql) {
        [$rounds, $players, $wagered, $house] = $db->query($sql)->fetch(PDO::FETCH_NUM);
        $casino[$name] = ['rounds' => (int) $rounds, 'players' => (int) $players, 'wagered' => (int) $wagered, 'house' => (int) $house];
    }
    return $casino;
}

/**
 * The games' numbers: games played in the last 7 days, how many played them, and games played ever. Each player's
 * game is one, so a chess game between two people counts as two. Games still being played, and Minesweeper's and
 * Sudoku's left ones, don't count. Ore Mining Inc. has no games, so it's the players who saved in the last 7 days
 * and everyone who has a save (null for the games played).
 *
 * @return array<string, array{week: ?int, players: int, ever: int}> the game's name => its numbers
 */
function adminOverviewGames(PDO $db): array
{
    $week = 'UTC_TIMESTAMP() - INTERVAL 7 DAY';
    // name => [table, timestamp column, WHERE for a finished game]
    $tables = [
        'Chess' => ['HBHub-ChessResults', 'resultTimestamp', '1'],
        'Matador' => ['HBHub-MatadorResults', 'resultTimestamp', '1'],
        'UNO' => ['HBHub-UnoResults', 'resultTimestamp', '1'],
        'Wordle' => ['HBHub-WordleGames', 'gameStartTimestamp', 'gameResult <> 0 OR gameDate < CURDATE()'],
        'Minesweeper' => ['HBHub-MinesweeperGames', 'gameEndTimestamp', 'gameResult IN (1, 2)'],
        'Sudoku' => ['HBHub-SudokuGames', 'gameEndTimestamp', 'gameResult IN (1, 2)'],
    ];
    $games = [];
    foreach ($tables as $name => [$table, $column, $finished]) {
        [$inWeek, $players, $ever] = $db->query('SELECT COALESCE(SUM(' . $column . ' >= ' . $week . '), 0),
                COUNT(DISTINCT CASE WHEN ' . $column . ' >= ' . $week . ' THEN userId END), COUNT(*)
            FROM `' . $table . '` WHERE (' . $finished . ')')->fetch(PDO::FETCH_NUM);
        $games[$name] = ['week' => (int) $inWeek, 'players' => (int) $players, 'ever' => (int) $ever];
    }
    [$players, $ever] = $db->query('SELECT COALESCE(SUM(saveUpdatedTimestamp >= ' . $week . '), 0), COUNT(*) FROM `HBHub-MinerSaves`')->fetch(PDO::FETCH_NUM);
    $games['Ore Mining Inc.'] = ['week' => null, 'players' => (int) $players, 'ever' => (int) $ever];
    return $games;
}

/**
 * The chats with the most messages in the last 7 days, the most first.
 *
 * @return list<array{chatId: mixed, chatType: mixed, chatIconAttachmentId: mixed, chatTitle: string, messages: mixed}>
 */
function adminOverviewBusiestChats(PDO $db): array
{
    return $db->query('SELECT c.chatId, c.chatType, c.chatIconAttachmentId, ' . adminChatTitleSql() . ' AS chatTitle, m.messages
        FROM (SELECT chatId, COUNT(*) AS messages FROM `HBHub-Messages`
            WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 7 DAY
            GROUP BY chatId ORDER BY messages DESC, chatId LIMIT ' . ADMIN_OVERVIEW_TOP . ') m
        JOIN `HBHub-Chats` c ON c.chatId = m.chatId
        ORDER BY m.messages DESC, c.chatId')->fetchAll();
}

/**
 * The people who sent the most messages in the last 7 days, the most first.
 *
 * @return list<array{userId: mixed, userName: string, userAvatarAttachmentId: mixed, messages: mixed}>
 */
function adminOverviewBusiestUsers(PDO $db): array
{
    return $db->query('SELECT u.userId, u.userName, u.userAvatarAttachmentId, m.messages
        FROM (SELECT userId, COUNT(*) AS messages FROM `HBHub-Messages`
            WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 7 DAY
            GROUP BY userId ORDER BY messages DESC, userId LIMIT ' . ADMIN_OVERVIEW_TOP . ') m
        JOIN `HBHub-Users` u ON u.userId = m.userId
        ORDER BY m.messages DESC, u.userId')->fetchAll();
}

/**
 * A bar chart of $days (from adminOverviewPerDay()), one bar per day with its top corners rounded, as HTML: the
 * highest number over the chart, the first, middle and last dates under it, a tooltip per day (an SVG <title> on a
 * column as tall as the chart, so it's easy to point at), and the numbers as a table under "Show as a table".
 * $noun is what's counted, like "messages". No inline styles, as the CSP doesn't allow them: it's all attributes and
 * classes in admin.css.
 *
 * @param array<string, int> $days 'Y-m-d' => count, oldest first
 */
function adminOverviewChartHtml(array $days, string $noun, string $label): string
{
    $max = max(1, ...array_values($days));
    $count = count($days);
    $slot = ADMIN_OVERVIEW_CHART_W / $count;
    $barW = $slot - ADMIN_OVERVIEW_BAR_GAP;
    $dates = array_keys($days);

    $svg = '<svg class="admin-chart-svg" viewBox="0 0 ' . ADMIN_OVERVIEW_CHART_W . ' ' . ADMIN_OVERVIEW_CHART_H . '" preserveAspectRatio="none" role="img" aria-label="'
        . htmlspecialchars($label) . '">'
        . '<line class="admin-chart-grid" x1="0" y1="0.5" x2="' . ADMIN_OVERVIEW_CHART_W . '" y2="0.5"/>'
        . '<line class="admin-chart-grid" x1="0" y1="' . ADMIN_OVERVIEW_CHART_H / 2 . '" x2="' . ADMIN_OVERVIEW_CHART_W . '" y2="' . ADMIN_OVERVIEW_CHART_H / 2 . '"/>';
    $i = 0;
    foreach ($days as $date => $value) {
        $x = $i * $slot + ADMIN_OVERVIEW_BAR_GAP / 2;
        $h = $value / $max * ADMIN_OVERVIEW_CHART_H;
        $svg .= '<g class="admin-chart-day' . ($i === $count - 1 ? ' is-today' : '') . '"><title>'
            . htmlspecialchars(date('D d/m', strtotime($date)) . ': ' . number_format($value) . ' ' . $noun) . '</title>'
            . '<rect class="admin-chart-hit" x="' . round($i * $slot, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . ADMIN_OVERVIEW_CHART_H . '"/>';
        if ($value > 0) {
            // Rounded at the top only, square on the baseline
            $r = min(4, $barW / 2, $h);
            $top = ADMIN_OVERVIEW_CHART_H - $h;
            $svg .= '<path class="admin-chart-bar" d="M' . round($x, 2) . ' ' . ADMIN_OVERVIEW_CHART_H
                . 'V' . round($top + $r, 2) . 'Q' . round($x, 2) . ' ' . round($top, 2) . ' ' . round($x + $r, 2) . ' ' . round($top, 2)
                . 'H' . round($x + $barW - $r, 2) . 'Q' . round($x + $barW, 2) . ' ' . round($top, 2) . ' ' . round($x + $barW, 2) . ' ' . round($top + $r, 2)
                . 'V' . ADMIN_OVERVIEW_CHART_H . 'Z"/>';
        }
        $svg .= '</g>';
        $i++;
    }
    $svg .= '<line class="admin-chart-axis" x1="0" y1="' . (ADMIN_OVERVIEW_CHART_H - 0.5) . '" x2="' . ADMIN_OVERVIEW_CHART_W . '" y2="' . (ADMIN_OVERVIEW_CHART_H - 0.5) . '"/></svg>';

    $rows = '';
    foreach (array_reverse($days, true) as $date => $value) {
        $rows .= '<tr><td class="admin-nowrap">' . date('D d/m/Y', strtotime($date)) . '</td><td>' . number_format($value) . '</td></tr>';
    }

    return '<figure class="admin-chart"><figcaption class="admin-chart-title">' . htmlspecialchars($label)
        . ' <span class="admin-small">' . number_format(array_sum($days)) . ' in all</span></figcaption>'
        . '<div class="admin-chart-plot"><span class="admin-chart-max admin-small">' . number_format($max) . '</span>'
        . '<span class="admin-chart-mid admin-small">' . number_format($max / 2, $max % 2 === 0 ? 0 : 1) . '</span>' . $svg . '</div>'
        . '<div class="admin-chart-dates admin-small"><span>' . date('d/m', strtotime($dates[0])) . '</span><span>'
        . date('d/m', strtotime($dates[intdiv($count, 2)])) . '</span><span>Today</span></div>'
        . '<details class="admin-chart-table"><summary class="admin-small">Show as a table</summary><div class="admin-table-wrap"><table class="admin-table">'
        . '<thead><tr><th scope="col">Day</th><th scope="col">' . htmlspecialchars(ucfirst($noun)) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>'
        . '</figure>';
}

/**
 * One headline number: big, with what it is under it and, if given, a smaller note.
 */
function adminOverviewTileHtml(string $label, int $value, string $note = ''): string
{
    return '<li class="admin-tile"><span class="admin-tile-value">' . number_format($value) . '</span>'
        . '<span class="admin-tile-label">' . htmlspecialchars($label) . '</span>'
        . ($note !== '' ? '<span class="admin-small">' . htmlspecialchars($note) . '</span>' : '') . '</li>';
}

/**
 * A number of chips won or lost, with + for more than nothing, red when it's below nothing.
 */
function adminOverviewSignedHtml(int $chips): string
{
    return '<span class="' . ($chips < 0 ? 'admin-overview-minus' : '') . '">' . ($chips > 0 ? '+' : '') . number_format($chips) . '</span>';
}

$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);

$adminTotals = array_map('intval', adminOverviewTotals($db, $adminLockoutMinutes));
$adminMessagesPerDay = adminOverviewPerDay($db, 'SELECT DATE(messageSentTimestamp) AS day, COUNT(*) FROM `HBHub-Messages`
    WHERE messageEvent = 0 AND messageSentTimestamp >= ? GROUP BY day');
$adminSignupsPerDay = adminOverviewPerDay($db, 'SELECT DATE(userCreatedTimestamp) AS day, COUNT(*) FROM `HBHub-Users`
    WHERE userId <> 0 AND userCreatedTimestamp >= ? GROUP BY day');
$adminCasino = adminOverviewCasino($db);
$adminGames = adminOverviewGames($db);
$adminBusiestChats = adminOverviewBusiestChats($db);
$adminBusiestUsers = adminOverviewBusiestUsers($db);
// The "Needs a look" list: label => [count, where it's dealt with, whether a count above 0 is worth pointing out]
$adminAttention = [
    'New feedback' => [$adminTotals['feedback'], '/admin/feedback', true],
    'Browser errors, last 24 hours' => [$adminTotals['browserErrors'], '/admin/watchdog', true],
    'Ore Mining Inc. crash reports' => [$adminTotals['crashReports'], '/admin/crashes', true],
    'Locked accounts' => [$adminTotals['locked'], '/admin/?list=users&sort=status', false],
    'Ghost banned accounts' => [$adminTotals['ghostBanned'], '/admin/?list=users&sort=status', false],
    'Banned accounts' => [$adminTotals['banned'], '/admin/?list=users&sort=status', false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title>Overview - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'overview') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" aria-labelledby="admin-overview-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-overview-heading">Overview</h2>
                            <p class="settings-intro">How the site is doing right now. Deleted accounts don't count, and messages are only the ones people wrote, not the notices about changes in a chat.</p>
                        </div>
                    </div>

                    <ul class="admin-tiles">
                        <?= adminOverviewTileHtml('Accounts', $adminTotals['accounts'], '+' . number_format($adminTotals['newWeek']) . ' in the last 7 days') ?>
                        <?= adminOverviewTileHtml('Online now', $adminTotals['online'], 'seen in the last ' . intdiv(HBHUB_SESSION_ONLINE_SECONDS, 60) . ' minutes') ?>
                        <?= adminOverviewTileHtml('Seen today', $adminTotals['seenDay'], 'in the last 24 hours') ?>
                        <?= adminOverviewTileHtml('Seen this week', $adminTotals['seenWeek'], 'in the last 7 days') ?>
                        <?= adminOverviewTileHtml('Messages today', $adminTotals['messagesDay'], 'in the last 24 hours') ?>
                        <?= adminOverviewTileHtml('Messages this week', $adminTotals['messagesWeek'], 'in the last 7 days') ?>
                    </ul>
                </section>

                <section class="settings-card" aria-labelledby="admin-overview-days-heading">
                    <h2 id="admin-overview-days-heading">Last <?= ADMIN_OVERVIEW_DAYS ?> days</h2>
                    <div class="admin-charts">
                        <?= adminOverviewChartHtml($adminMessagesPerDay, 'messages', 'Messages sent each day') ?>
                        <?= adminOverviewChartHtml($adminSignupsPerDay, 'new accounts', 'New accounts each day') ?>
                    </div>
                </section>

                <section class="settings-card" aria-labelledby="admin-overview-attention-heading">
                    <h2 id="admin-overview-attention-heading">Needs a look</h2>
                    <ul class="admin-attention">
                        <?php foreach ($adminAttention as $label => [$count, $href, $pointOut]): ?>
                            <li>
                                <a class="admin-attention-link" href="<?= htmlspecialchars($href) ?>">
                                    <span class="settings-menu-count<?= $pointOut && $count > 0 ? ' is-new' : '' ?>"><?= number_format($count) ?></span>
                                    <?= htmlspecialchars($label) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="settings-card" aria-labelledby="admin-overview-chat-heading">
                    <h2 id="admin-overview-chat-heading">Chat</h2>
                    <ul class="admin-tiles">
                        <?= adminOverviewTileHtml('DMs', $adminTotals['dms'], 'not deleted') ?>
                        <?= adminOverviewTileHtml('Groups', $adminTotals['groupChats'], 'not deleted') ?>
                        <?= adminOverviewTileHtml('Chats in use', $adminTotals['chatsWeek'], 'a message in the last 7 days') ?>
                        <?= adminOverviewTileHtml('Messages ever', $adminTotals['messagesEver']) ?>
                        <?= adminOverviewTileHtml('Reactions', $adminTotals['reactionsWeek'], 'in the last 7 days') ?>
                        <?= adminOverviewTileHtml('Attachments', $adminTotals['attachmentsWeek'], 'sent in the last 7 days') ?>
                    </ul>

                    <div class="admin-overview-lists">
                        <div>
                            <h3 class="admin-watchdog-subheading">Busiest chats, last 7 days</h3>
                            <?php if ($adminBusiestChats === []): ?>
                                <p class="settings-hint">No messages in the last 7 days.</p>
                            <?php else: ?>
                                <ol class="admin-overview-top">
                                    <?php foreach ($adminBusiestChats as $chat): ?>
                                        <?php $topChatId = (int) $chat['chatId']; ?>
                                        <li>
                                            <a class="admin-list-link" href="/admin/chat?chat=<?= $topChatId ?>">
                                                <?= adminAvatarHtml(adminChatIconUrl($topChatId, $chat['chatIconAttachmentId']), $chat['chatTitle'], true) ?>
                                                <span class="admin-list-name"><?= htmlspecialchars($chat['chatTitle']) ?></span>
                                            </a>
                                            <span class="admin-small"><?= (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group' ?> &middot; <?= number_format((int) $chat['messages']) ?> messages</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="admin-watchdog-subheading">Most messages sent, last 7 days</h3>
                            <?php if ($adminBusiestUsers === []): ?>
                                <p class="settings-hint">No messages in the last 7 days.</p>
                            <?php else: ?>
                                <ol class="admin-overview-top">
                                    <?php foreach ($adminBusiestUsers as $user): ?>
                                        <?php $topUserId = (int) $user['userId']; ?>
                                        <li>
                                            <a class="admin-list-link" href="/admin/user?user=<?= $topUserId ?>">
                                                <?= adminAvatarHtml(hbHubAvatarUrl($topUserId, $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null), $user['userName'], true) ?>
                                                <span class="admin-list-name"><?= htmlspecialchars($user['userName']) ?></span>
                                            </a>
                                            <span class="admin-small"><?= number_format((int) $user['messages']) ?> messages</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="settings-card" aria-labelledby="admin-overview-casino-heading">
                    <h2 id="admin-overview-casino-heading">Casino</h2>
                    <ul class="admin-tiles">
                        <?= adminOverviewTileHtml('Chips in wallets', $adminTotals['chips'], 'everyone\'s together') ?>
                        <?= adminOverviewTileHtml('Wallets', $adminTotals['wallets'], 'people who have opened the casino') ?>
                        <?= adminOverviewTileHtml('Daily chips collected', $adminTotals['dailyBonuses'], 'in the last 24 hours') ?>
                    </ul>
                    <h3 class="admin-watchdog-subheading">Last 7 days</h3>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th scope="col">Game</th><th scope="col">Rounds</th><th scope="col">Players</th><th scope="col">Chips bet</th><th scope="col" title="What the players lost, minus what they won">House won</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminCasino as $name => $game): ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars($name) ?></th>
                                        <td><?= number_format($game['rounds']) ?></td>
                                        <td><?= number_format($game['players']) ?></td>
                                        <td><?= number_format($game['wagered']) ?></td>
                                        <td><?= adminOverviewSignedHtml($game['house']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="settings-card" aria-labelledby="admin-overview-games-heading">
                    <h2 id="admin-overview-games-heading">Games</h2>
                    <p class="settings-intro">Each player's game counts as one, so a chess game counts twice. Games still going, and Minesweeper and Sudoku games left for a new one, aren't counted.</p>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th scope="col">Game</th><th scope="col">Games, last 7 days</th><th scope="col">Players, last 7 days</th><th scope="col">Games ever</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminGames as $name => $game): ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars($name) ?></th>
                                        <td><?= $game['week'] !== null ? number_format($game['week']) : '<span class="admin-muted" title="It has no games, only saves">–</span>' ?></td>
                                        <td><?= number_format($game['players']) ?></td>
                                        <td><?= $game['week'] !== null ? number_format($game['ever']) : number_format($game['ever']) . ' <span class="admin-small">saves</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
