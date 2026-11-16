<?php
// Admin panel's Overview: the start page of the admin panel, shown at /admin/ when there's no ?list= (admin/index.php
// requires this file and stops), and "Overview" at the top of the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here. Opened as /admin/overview it sends
// the browser on to /admin/, so the page only has the one address.
//
// A dashboard of numbers about the whole site, all worked out on each load (nothing is stored for it), laid out as a
// grid of panels (.admin-dash: 12 columns on a wide screen, one on a phone):
// - Needs a look: a strip at the top with new feedback, browser errors, crash reports, and locked, ghost banned and
//   banned accounts, each linking to where it's dealt with. The first three light up when there are any.
// - four headline numbers: online now, active in the last 24 hours, messages in the last 24 hours (up or down on the
//   24 hours before) and accounts (with how many are new this week, up or down on the week before)
// - two bar charts of the last ADMIN_OVERVIEW_DAYS days: messages sent and new accounts each day (inline SVG, a
//   tooltip on each day, and the same numbers as a table under "Show as a table")
// - Chat: chats, messages, reactions and attachments, and the busiest chats and people of the last 7 days, each with
//   a bar showing how they compare to the busiest one
// - Page views: a bar chart of the pages opened each day of the last ADMIN_OVERVIEW_DAYS days, and the
//   ADMIN_OVERVIEW_TOP_PAGES most visited pages of the last 7 days with how many of their views were guests. These are
//   the only numbers that are stored for it: counts per page and day, kept by header.php (assets/php/pageViews.php).
// - Browser errors: a bar chart of the errors in people's browsers each day of the last ADMIN_OVERVIEW_DAYS days
//   (HBHub-Watchdog, assets/php/watchdog.php), by the day the last error in a report happened, like admin/watchdog.php
//   counts them, cleared reports and the dev site's included
// - Visits from links: the pages opened from an address with ?utm_source= in the last ADMIN_OVERVIEW_DAYS days, per
//   source and page, with how many were guests and the last day it happened (counts in HBHub-PageSources, same file)
// - Casino: the chips in everyone's wallets, and each game's rounds, players, chips bet and what the house won or
//   lost in the last 7 days, with a total
// - Games: each game's games played and players in the last 7 days, and games played ever
// The casino's and the games' tables keep their times in UTC (UTC_TIMESTAMP()), the users', chats' and messages' in
// the database's own time (NOW(), CURDATE()), so each query goes by its table's clock.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pageViews.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/watchdog.php'; // HBHUB_WATCHDOG_KEEP_DAYS

const ADMIN_OVERVIEW_DAYS = 30; // days in the bar charts
const ADMIN_OVERVIEW_TOP = 5; // chats and people in the "busiest" lists
const ADMIN_OVERVIEW_TOP_PAGES = 10; // pages in "Most visited pages"
const ADMIN_OVERVIEW_TOP_SOURCES = 50; // source and page rows in "Visits from links"
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
 * The headline numbers and the rest of the single numbers on the page, in one query. The "Prev" ones are the same
 * stretch of time just before, for the up or down under a headline number. Deleted accounts (userStatus
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
        (SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userCreatedTimestamp >= NOW() - INTERVAL 14 DAY
            AND userCreatedTimestamp < NOW() - INTERVAL 7 DAY) AS newPrevWeek,
        (SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 1 DAY) AS messagesDay,
        (SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0 AND messageSentTimestamp >= NOW() - INTERVAL 2 DAY
            AND messageSentTimestamp < NOW() - INTERVAL 1 DAY) AS messagesPrevDay,
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
 * The pages opened the most in the last 7 days (today and the 6 days before, as page views are kept per day), the
 * most first, with how many of those views weren't logged in. What counts as a view is in assets/php/pageViews.php.
 *
 * @return list<array{viewPath: string, views: mixed, guests: mixed}>
 */
function adminOverviewTopPages(PDO $db): array
{
    return $db->query('SELECT viewPath, SUM(viewCount) AS views, SUM(viewGuestCount) AS guests FROM `HBHub-PageViews`
        WHERE viewDate > CURDATE() - INTERVAL 7 DAY
        GROUP BY viewPath ORDER BY views DESC, viewPath LIMIT ' . ADMIN_OVERVIEW_TOP_PAGES)->fetchAll();
}

/**
 * The pages opened from a link with ?utm_source= in the last ADMIN_OVERVIEW_DAYS days, one row per source and page,
 * the most first: how many times, how many weren't logged in, and the last day it happened. At most
 * ADMIN_OVERVIEW_TOP_SOURCES rows.
 *
 * @return list<array{viewSource: string, viewPath: string, views: mixed, guests: mixed, lastDay: string}>
 */
function adminOverviewSources(PDO $db): array
{
    return $db->query('SELECT viewSource, viewPath, SUM(viewCount) AS views, SUM(viewGuestCount) AS guests, MAX(viewDate) AS lastDay
        FROM `HBHub-PageSources` WHERE viewDate > CURDATE() - INTERVAL ' . ADMIN_OVERVIEW_DAYS . ' DAY
        GROUP BY viewSource, viewPath ORDER BY views DESC, lastDay DESC, viewSource, viewPath LIMIT ' . ADMIN_OVERVIEW_TOP_SOURCES)->fetchAll();
}

/**
 * A bar chart of $days (from adminOverviewPerDay()), one bar per day with its top corners rounded, as HTML: the
 * total and today's number as its caption, the highest number over the chart, the first, middle and last dates under it, a tooltip per day (an SVG <title> on a
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

    // The panel it's in has the title, so the caption is the total, big, and today's number
    return '<figure class="admin-chart"><figcaption class="admin-chart-title"><span class="admin-chart-total">' . number_format(array_sum($days)) . '</span>'
        . ' <span class="admin-small">in ' . $count . ' days &middot; ' . number_format(end($days)) . ' today</span>'
        . '<span class="admin-visually-hidden">: ' . htmlspecialchars($label) . '</span></figcaption>'
        . '<div class="admin-chart-plot"><span class="admin-chart-max admin-small">' . number_format($max) . '</span>'
        . '<span class="admin-chart-mid admin-small">' . number_format($max / 2, $max % 2 === 0 ? 0 : 1) . '</span>' . $svg . '</div>'
        . '<div class="admin-chart-dates admin-small"><span>' . date('d/m', strtotime($dates[0])) . '</span><span>'
        . date('d/m', strtotime($dates[intdiv($count, 2)])) . '</span><span>Today</span></div>'
        . '<details class="admin-chart-table"><summary class="admin-small">Show as a table</summary><div class="admin-table-wrap"><table class="admin-table">'
        . '<thead><tr><th scope="col">Day</th><th scope="col">' . htmlspecialchars(ucfirst($noun)) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>'
        . '</figure>';
}

/**
 * One headline number: what it is over it, the number big, and a line about it under it. $trend is extra HTML for the
 * end of that line, like adminOverviewTrendHtml()'s up or down. $live puts a pulsing green dot by the label.
 */
function adminOverviewKpiHtml(string $label, int $value, string $note, string $trend = '', bool $live = false): string
{
    return '<li class="admin-kpi">'
        . '<span class="admin-kpi-label">' . ($live ? '<span class="admin-live-dot" aria-hidden="true"></span>' : '') . htmlspecialchars($label) . '</span>'
        . '<span class="admin-kpi-value">' . number_format($value) . '</span>'
        . '<span class="admin-kpi-note">' . $trend . '<span>' . htmlspecialchars($note) . '</span></span></li>';
}

/**
 * How $now compares to $before, as a small green arrow up or red arrow down with the change in percent ("▲ 12%"), or
 * "No change". From nothing it's the number itself ("▲ 5"), as a percent of nothing means nothing.
 */
function adminOverviewTrendHtml(int $now, int $before): string
{
    if ($now === $before) {
        return '<span class="admin-trend">No change</span>';
    }
    $up = $now > $before;
    $change = $before === 0 ? number_format($now) : number_format(abs($now - $before) / $before * 100) . '%';
    return '<span class="admin-trend ' . ($up ? 'is-up' : 'is-down') . '"><span aria-hidden="true">' . ($up ? '▲' : '▼') . '</span> '
        . '<span class="admin-visually-hidden">' . ($up ? 'Up' : 'Down') . ' </span>' . $change . '</span>';
}

/**
 * A panel on the dashboard's grid: a settings card with its heading and, if given, a tag on the right of it (like
 * "Last 7 days"), taking $span of the grid's 12 columns on a wide screen. $body is its HTML.
 */
function adminOverviewPanelHtml(string $id, string $title, string $tag, int $span, string $body): string
{
    return '<section class="settings-card admin-panel admin-span-' . $span . '" aria-labelledby="' . $id . '">'
        . '<div class="admin-panel-head"><h2 id="' . $id . '">' . htmlspecialchars($title) . '</h2>'
        . ($tag !== '' ? '<span class="admin-panel-tag">' . htmlspecialchars($tag) . '</span>' : '') . '</div>'
        . $body . '</section>';
}

/**
 * A thin bar showing $value next to the highest one, $max, as an SVG (a width in a style isn't allowed by the CSP).
 */
function adminOverviewMeterHtml(int $value, int $max): string
{
    $width = $max > 0 ? round($value / $max * 100, 1) : 0;
    return '<svg class="admin-meter" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true">'
        . '<rect class="admin-meter-track" width="100" height="4" rx="2"/><rect class="admin-meter-fill" width="' . $width . '" height="4" rx="2"/></svg>';
}

/**
 * A ranked list of the busiest chats, people or pages: each row is $rows' link (from $linkHtml), its number (the
 * row's $key, the most first) on the right, and a bar under it showing how it compares to the top one. $empty is what
 * it says when there are none.
 *
 * @param list<array<string, mixed>> $rows each with $key
 * @param callable(array<string, mixed>): string $linkHtml the HTML of a row's link and anything after it
 */
function adminOverviewRankHtml(array $rows, callable $linkHtml, string $key = 'messages', string $empty = 'No messages in the last 7 days.'): string
{
    if ($rows === []) {
        return '<p class="admin-empty">' . htmlspecialchars($empty) . '</p>';
    }
    $max = (int) $rows[0][$key];
    $html = '<ol class="admin-rank">';
    foreach ($rows as $row) {
        $html .= '<li><div class="admin-rank-row">' . $linkHtml($row)
            . '<span class="admin-rank-value">' . number_format((int) $row[$key]) . '</span></div>'
            . adminOverviewMeterHtml((int) $row[$key], $max) . '</li>';
    }
    return $html . '</ol>';
}

/**
 * A number of chips won or lost, with + for more than nothing, red when it's below nothing.
 */
function adminOverviewSignedHtml(int $chips): string
{
    return '<span class="' . ($chips < 0 ? 'admin-overview-minus' : ($chips > 0 ? 'admin-overview-plus' : '')) . '">'
        . ($chips > 0 ? '+' : '') . number_format($chips) . '</span>';
}

$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);

$adminTotals = array_map('intval', adminOverviewTotals($db, $adminLockoutMinutes));
$adminMessagesPerDay = adminOverviewPerDay($db, 'SELECT DATE(messageSentTimestamp) AS day, COUNT(*) FROM `HBHub-Messages`
    WHERE messageEvent = 0 AND messageSentTimestamp >= ? GROUP BY day');
$adminSignupsPerDay = adminOverviewPerDay($db, 'SELECT DATE(userCreatedTimestamp) AS day, COUNT(*) FROM `HBHub-Users`
    WHERE userId <> 0 AND userCreatedTimestamp >= ? GROUP BY day');
$adminViewsPerDay = adminOverviewPerDay($db, 'SELECT viewDate, SUM(viewCount) FROM `HBHub-PageViews`
    WHERE viewDate >= ? GROUP BY viewDate');
$adminErrorsPerDay = adminOverviewPerDay($db, 'SELECT DATE(watchdogLastTimestamp) AS day, SUM(watchdogCount) FROM `HBHub-Watchdog`
    WHERE watchdogLastTimestamp >= ? GROUP BY day');
$adminTopPages = adminOverviewTopPages($db);
$adminSources = adminOverviewSources($db);
$adminCasino = adminOverviewCasino($db);
$adminGames = adminOverviewGames($db);
$adminBusiestChats = adminOverviewBusiestChats($db);
$adminBusiestUsers = adminOverviewBusiestUsers($db);
// The "Needs a look" strip: label => [count, where it's dealt with, whether a count above 0 is worth pointing out]
$adminAttention = [
    'New feedback' => [$adminTotals['feedback'], '/admin/feedback', true],
    'Browser errors (24 h)' => [$adminTotals['browserErrors'], '/admin/watchdog', true],
    'Crash reports' => [$adminTotals['crashReports'], '/admin/crashes', true],
    'Locked' => [$adminTotals['locked'], '/admin/?list=users&sort=status', false],
    'Ghost banned' => [$adminTotals['ghostBanned'], '/admin/?list=users&sort=status', false],
    'Banned' => [$adminTotals['banned'], '/admin/?list=users&sort=status', false],
];
$adminNeedsLook = 0;
foreach ($adminAttention as [$count, , $pointOut]) {
    $adminNeedsLook += $pointOut ? $count : 0;
}
// The casino's totals for the week. Players can't be added up, as the same person plays more than one game.
$adminCasinoTotal = ['rounds' => 0, 'wagered' => 0, 'house' => 0];
foreach ($adminCasino as $game) {
    foreach ($adminCasinoTotal as $key => $sum) {
        $adminCasinoTotal[$key] = $sum + $game[$key];
    }
}
$adminActivePercent = $adminTotals['accounts'] > 0 ? round($adminTotals['seenDay'] / $adminTotals['accounts'] * 100) : 0;
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
                <header class="admin-dash-header">
                    <h1>Overview</h1>
                    <p class="admin-small">Worked out at <?= date('H:i') ?>. Reload the page for the newest numbers.</p>
                </header>

                <section class="admin-alerts<?= $adminNeedsLook > 0 ? ' is-on' : '' ?>" aria-labelledby="admin-overview-attention-heading">
                    <h2 id="admin-overview-attention-heading" class="admin-alerts-title">
                        <?= $adminNeedsLook > 0 ? 'Needs a look' : 'All clear' ?>
                    </h2>
                    <ul class="admin-alerts-list">
                        <?php foreach ($adminAttention as $label => [$count, $href, $pointOut]): ?>
                            <li>
                                <a class="admin-alert<?= $count === 0 ? ' is-zero' : ($pointOut ? ' is-hot' : '') ?>" href="<?= htmlspecialchars($href) ?>">
                                    <span class="admin-alert-count"><?= number_format($count) ?></span>
                                    <span><?= htmlspecialchars($label) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <div class="admin-dash">
                    <section class="admin-span-12" aria-label="The main numbers">
                        <ul class="admin-kpis">
                            <?= adminOverviewKpiHtml('Online now', $adminTotals['online'], 'seen in the last ' . intdiv(HBHUB_SESSION_ONLINE_SECONDS, 60) . ' minutes', '', true) ?>
                            <?= adminOverviewKpiHtml('Active today', $adminTotals['seenDay'], $adminActivePercent . '% of accounts · ' . number_format($adminTotals['seenWeek']) . ' this week') ?>
                            <?= adminOverviewKpiHtml('Messages today', $adminTotals['messagesDay'], 'on the 24 hours before', adminOverviewTrendHtml($adminTotals['messagesDay'], $adminTotals['messagesPrevDay'])) ?>
                            <?= adminOverviewKpiHtml('Accounts', $adminTotals['accounts'], '+' . number_format($adminTotals['newWeek']) . ' new this week', adminOverviewTrendHtml($adminTotals['newWeek'], $adminTotals['newPrevWeek'])) ?>
                        </ul>
                    </section>

                    <?= adminOverviewPanelHtml('admin-overview-messages-heading', 'Messages', 'Last ' . ADMIN_OVERVIEW_DAYS . ' days', 8,
                        adminOverviewChartHtml($adminMessagesPerDay, 'messages', 'Messages sent each day')) ?>
                    <?= adminOverviewPanelHtml('admin-overview-signups-heading', 'New accounts', 'Last ' . ADMIN_OVERVIEW_DAYS . ' days', 4,
                        adminOverviewChartHtml($adminSignupsPerDay, 'new accounts', 'New accounts each day')) ?>

                    <?php ob_start(); ?>
                    <dl class="admin-stats">
                        <div><dt>Chats in use</dt><dd><?= number_format($adminTotals['chatsWeek']) ?></dd></div>
                        <div><dt>Messages</dt><dd><?= number_format($adminTotals['messagesWeek']) ?></dd></div>
                        <div><dt>Reactions</dt><dd><?= number_format($adminTotals['reactionsWeek']) ?></dd></div>
                        <div><dt>Attachments</dt><dd><?= number_format($adminTotals['attachmentsWeek']) ?></dd></div>
                    </dl>
                    <p class="admin-panel-sub">All time</p>
                    <dl class="admin-stats">
                        <div><dt>DMs</dt><dd><?= number_format($adminTotals['dms']) ?></dd></div>
                        <div><dt>Groups</dt><dd><?= number_format($adminTotals['groupChats']) ?></dd></div>
                        <div class="admin-stats-wide"><dt>Messages ever</dt><dd><?= number_format($adminTotals['messagesEver']) ?></dd></div>
                    </dl>
                    <p class="admin-panel-foot">Chats in use had a message this week. DMs and groups don't count deleted ones, and messages are only the ones people wrote, not the notices about changes in a chat.</p>
                    <?= adminOverviewPanelHtml('admin-overview-chat-heading', 'Chat', 'Last 7 days', 4, ob_get_clean()) ?>

                    <?= adminOverviewPanelHtml('admin-overview-chats-heading', 'Busiest chats', 'Last 7 days', 4,
                        adminOverviewRankHtml($adminBusiestChats, static function (array $chat): string {
                            $chatId = (int) $chat['chatId'];
                            return '<a class="admin-list-link" href="/admin/chat?chat=' . $chatId . '">'
                                . adminAvatarHtml(adminChatIconUrl($chatId, $chat['chatIconAttachmentId']), $chat['chatTitle'], true)
                                . '<span class="admin-list-name">' . htmlspecialchars($chat['chatTitle']) . '</span></a>'
                                . '<span class="admin-small">' . ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group') . '</span>';
                        })) ?>
                    <?= adminOverviewPanelHtml('admin-overview-users-heading', 'Most messages sent', 'Last 7 days', 4,
                        adminOverviewRankHtml($adminBusiestUsers, static function (array $user): string {
                            $userId = (int) $user['userId'];
                            return '<a class="admin-list-link" href="/admin/user?user=' . $userId . '">'
                                . adminAvatarHtml(hbHubAvatarUrl($userId, $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null), $user['userName'], true)
                                . '<span class="admin-list-name">' . htmlspecialchars($user['userName']) . '</span></a>';
                        })) ?>

                    <?= adminOverviewPanelHtml('admin-overview-views-heading', 'Page views', 'Last ' . ADMIN_OVERVIEW_DAYS . ' days', 8,
                        adminOverviewChartHtml($adminViewsPerDay, 'page views', 'Pages opened each day')
                        . '<p class="admin-panel-foot">Every time a page is opened, including the chat switching to another chat. Reloads after sending something, prefetches, error pages and bots don\'t count. Only a count per page and day is kept, never who, and only for ' . HBHUB_PAGE_VIEWS_KEEP_DAYS . ' days.</p>') ?>
                    <?= adminOverviewPanelHtml('admin-overview-pages-heading', 'Most visited pages', 'Last 7 days', 4,
                        adminOverviewRankHtml($adminTopPages, static function (array $page): string {
                            $guests = (int) $page['views'] > 0 ? round((int) $page['guests'] / (int) $page['views'] * 100) : 0;
                            // Chat pages are /chat/ whichever chat it was, as the query isn't kept
                            return '<a class="admin-list-link" href="' . htmlspecialchars($page['viewPath']) . '">'
                                . '<code class="admin-list-name">' . htmlspecialchars($page['viewPath']) . '</code></a>'
                                . ($guests > 0 ? '<span class="admin-small" title="Not logged in">' . $guests . '% guests</span>' : '');
                        }, 'views', 'No page views in the last 7 days.')) ?>

                    <?= adminOverviewPanelHtml('admin-overview-errors-heading', 'Browser errors', 'Last ' . ADMIN_OVERVIEW_DAYS . ' days', 12,
                        adminOverviewChartHtml($adminErrorsPerDay, 'errors', 'Errors in people\'s browsers each day')
                        . '<p class="admin-panel-foot">Every error the Watchdog noted in someone\'s browser: failed requests, pictures or scripts that didn\'t load, JavaScript errors and what the CSP blocked, by the day the last error in a report happened. Cleared reports still count. Reports are kept for ' . HBHUB_WATCHDOG_KEEP_DAYS . ' days, so the first day can be short. <a href="/admin/watchdog">See them under Browser errors</a>.</p>') ?>

                    <?php ob_start(); ?>
                    <?php if ($adminSources === []): ?>
                        <p class="admin-empty">Nobody has opened a page from a link with ?utm_source= in the last <?= ADMIN_OVERVIEW_DAYS ?> days.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-dash-table">
                                <caption class="admin-visually-hidden">Pages opened from links with utm_source</caption>
                                <thead><tr><th scope="col">Source</th><th scope="col">Page</th><th scope="col">Visits</th><th scope="col" title="Not logged in">Guests</th><th scope="col">Last time</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminSources as $source): ?>
                                        <tr>
                                            <th scope="row"><?= htmlspecialchars($source['viewSource']) ?></th>
                                            <td><a href="<?= htmlspecialchars($source['viewPath']) ?>"><code><?= htmlspecialchars($source['viewPath']) ?></code></a></td>
                                            <td><?= number_format((int) $source['views']) ?></td>
                                            <td><?= number_format((int) $source['guests']) ?></td>
                                            <td class="admin-nowrap"><?= date('D d/m', strtotime($source['lastDay'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <p class="admin-panel-foot">Page views from an address with ?utm_source= on it, like <code>/?utm_source=instagram</code>, counted the same way as the page views above. Only the source, the page and a count per day are kept, never who, and only for <?= HBHUB_PAGE_VIEWS_KEEP_DAYS ?> days.</p>
                    <?= adminOverviewPanelHtml('admin-overview-sources-heading', 'Visits from links', 'Last ' . ADMIN_OVERVIEW_DAYS . ' days', 12, ob_get_clean()) ?>

                    <?php ob_start(); ?>
                    <dl class="admin-stats admin-stats-3">
                        <div><dt>Chips in wallets</dt><dd><?= number_format($adminTotals['chips']) ?></dd></div>
                        <div><dt title="People who have opened the casino">Wallets</dt><dd><?= number_format($adminTotals['wallets']) ?></dd></div>
                        <div><dt title="In the last 24 hours">Daily chips today</dt><dd><?= number_format($adminTotals['dailyBonuses']) ?></dd></div>
                    </dl>
                    <div class="admin-table-wrap">
                        <table class="admin-table admin-dash-table">
                            <caption class="admin-visually-hidden">The casino's games in the last 7 days</caption>
                            <thead><tr><th scope="col">Game</th><th scope="col">Rounds</th><th scope="col">Players</th><th scope="col">Chips bet</th><th scope="col" title="What the players lost, minus what they won">House won</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminCasino as $name => $game): ?>
                                    <tr<?= $game['rounds'] === 0 ? ' class="is-quiet"' : '' ?>>
                                        <th scope="row"><?= htmlspecialchars($name) ?></th>
                                        <td><?= number_format($game['rounds']) ?></td>
                                        <td><?= number_format($game['players']) ?></td>
                                        <td><?= number_format($game['wagered']) ?></td>
                                        <td><?= adminOverviewSignedHtml($game['house']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row">Total</th>
                                    <td><?= number_format($adminCasinoTotal['rounds']) ?></td>
                                    <td></td>
                                    <td><?= number_format($adminCasinoTotal['wagered']) ?></td>
                                    <td><?= adminOverviewSignedHtml($adminCasinoTotal['house']) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="admin-panel-foot">House won is what the players lost minus what they won, so red means the players came out ahead.</p>
                    <?= adminOverviewPanelHtml('admin-overview-casino-heading', 'Casino', 'Last 7 days', 6, ob_get_clean()) ?>

                    <?php ob_start(); ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table admin-dash-table">
                            <caption class="admin-visually-hidden">The games' numbers</caption>
                            <thead><tr><th scope="col">Game</th><th scope="col">Games</th><th scope="col">Players</th><th scope="col">Ever</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminGames as $name => $game): ?>
                                    <tr<?= $game['players'] === 0 ? ' class="is-quiet"' : '' ?>>
                                        <th scope="row"><?= htmlspecialchars($name) ?></th>
                                        <td><?= $game['week'] !== null ? number_format($game['week']) : '<span class="admin-muted" title="It has no games, only saves">–</span>' ?></td>
                                        <td><?= number_format($game['players']) ?></td>
                                        <td><?= number_format($game['ever']) . ($game['week'] === null ? ' <span class="admin-small">saves</span>' : '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="admin-panel-foot">Games and players are for the last 7 days. Each player's game counts as one, so a chess game counts twice. Games still going, and Minesweeper and Sudoku games left for a new one, aren't counted. For Ore Mining Inc., players are the ones who saved this week.</p>
                    <?= adminOverviewPanelHtml('admin-overview-games-heading', 'Games', 'Last 7 days', 6, ob_get_clean()) ?>
                </div>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
