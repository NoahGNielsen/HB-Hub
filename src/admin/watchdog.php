<?php
// Admin panel's Watchdog: /admin/watchdog, "Browser errors" in the admin panel's side menu (adminListsMenuHtml()), with
// how many errors there were in the last 24 hours. Everyone but site admins gets a 404, so it doesn't show that the
// page is here.
//
// The errors people's browsers had on the site (assets/php/watchdog.php has what the Watchdog is and the kinds,
// assets/js/watchdog.js what counts). At the top, how many there were in the last hour, day, week and
// HBHUB_WATCHDOG_KEEP_DAYS days, how many of them were 508s (the server was busy), and in how many reports, from how
// many users and IP addresses; then how many of each kind in the last 7 days, and the most common errors in those days.
// Under that every report, newest first, ADMIN_WATCHDOG_SHOWN at a time (?page=): when the first and last of them
// happened (and when the report got through, if that was later), how many, who (or "Not logged in"), the page they were
// on, which errors (kind/status, address, line, message) and how often, the IP address, the browser's user agent and a
// Dev mark when it came from the dev site.
// Form (action=clear) under the list takes every report off the list for good, and goes in the admin log
// (assets/php/adminLog.php). It only clears the list: the rows stay (watchdogCleared) so the numbers at the top still
// count them, and go by themselves after HBHUB_WATCHDOG_KEEP_DAYS like the rest.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/watchdog.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_WATCHDOG_SHOWN = 50; // per page
const ADMIN_WATCHDOG_TOP = 15; // errors in the "most common" list
const ADMIN_WATCHDOG_TOP_DAYS = 7;
// The numbers at the top: label => hours back (the last one is everything that's kept)
const ADMIN_WATCHDOG_PERIODS = ['Last hour' => 1, 'Last 24 hours' => 24, 'Last 7 days' => 168, 'Last ' . HBHUB_WATCHDOG_KEEP_DAYS . ' days' => HBHUB_WATCHDOG_KEEP_DAYS * 24];
// Every report's errors as rows, for the SQL below (w is HBHub-Watchdog). An old row's entries have no kind or status:
// those are 508s (see assets/php/watchdog.php), hence the COALESCEs where it's used
const ADMIN_WATCHDOG_ERRORS_TABLE = 'JSON_TABLE(w.watchdogErrors, \'$[*]\' COLUMNS (kind varchar(16) PATH \'$.kind\', status int PATH \'$.status\',
    path varchar(255) PATH \'$.path\', message varchar(400) PATH \'$.message\', line varchar(16) PATH \'$.line\', hits int UNSIGNED PATH \'$.count\')) a';

adminOnly($hbHubUser);

$db = hbHubDatabase();
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'clear') {
    $cleared = hbHubWatchdogClear($db);
    adminLog($db, (int) $hbHubUser['userId'], 'watchdog.clear', null, null, ['count' => $cleared]);

    // Back to the page with a toast, so reloading doesn't send the form again
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast('adminWatchdogCleared');
    header('Location: /admin/watchdog', true, 303);
    exit;
}

$adminPeriods = [];
$statement = $db->prepare('SELECT COALESCE(SUM(watchdogCount), 0) AS errors, COUNT(*) AS reports, COUNT(DISTINCT watchdogUserId) AS users,
        COUNT(DISTINCT COALESCE(watchdogIpv4, watchdogIpv6)) AS ips
    FROM `HBHub-Watchdog` WHERE watchdogLastTimestamp > NOW() - INTERVAL ? HOUR');
$busyStatement = $db->prepare('SELECT COALESCE(SUM(a.hits), 0) FROM `HBHub-Watchdog` w, ' . ADMIN_WATCHDOG_ERRORS_TABLE . '
    WHERE w.watchdogLastTimestamp > NOW() - INTERVAL ? HOUR AND COALESCE(a.kind, \'http\') = \'http\' AND COALESCE(a.status, ?) = ?');
foreach (ADMIN_WATCHDOG_PERIODS as $label => $hours) {
    $statement->execute([$hours]);
    $adminPeriods[$label] = $statement->fetch();
    $busyStatement->execute([$hours, HBHUB_WATCHDOG_BUSY_STATUS, HBHUB_WATCHDOG_BUSY_STATUS]);
    $adminPeriods[$label]['busy'] = (int) $busyStatement->fetchColumn();
}

// How many of each kind, and the most common errors, in the last ADMIN_WATCHDOG_TOP_DAYS days
$adminKinds = array_fill_keys(array_keys(HBHUB_WATCHDOG_KINDS), 0);
foreach ($db->query('SELECT COALESCE(a.kind, \'http\') AS errorKind, SUM(a.hits) AS hits
    FROM `HBHub-Watchdog` w, ' . ADMIN_WATCHDOG_ERRORS_TABLE . '
    WHERE w.watchdogLastTimestamp > NOW() - INTERVAL ' . ADMIN_WATCHDOG_TOP_DAYS . ' DAY
    GROUP BY errorKind')->fetchAll() as $row) {
    if (isset($adminKinds[$row['errorKind']])) {
        $adminKinds[$row['errorKind']] = (int) $row['hits'];
    }
}
$adminTop = [];
foreach ($db->query('SELECT COALESCE(a.kind, \'http\') AS errorKind,
        CASE WHEN COALESCE(a.kind, \'http\') = \'http\' THEN COALESCE(a.status, ' . HBHUB_WATCHDOG_BUSY_STATUS . ') END AS errorStatus,
        a.path AS errorPath, a.message AS errorMessage, a.line AS errorLine, SUM(a.hits) AS hits
    FROM `HBHub-Watchdog` w, ' . ADMIN_WATCHDOG_ERRORS_TABLE . '
    WHERE w.watchdogLastTimestamp > NOW() - INTERVAL ' . ADMIN_WATCHDOG_TOP_DAYS . ' DAY
    GROUP BY errorKind, errorStatus, errorPath, errorMessage, errorLine ORDER BY hits DESC LIMIT ' . ADMIN_WATCHDOG_TOP)->fetchAll() as $row) {
    $error = hbHubWatchdogError(['kind' => $row['errorKind'], 'path' => $row['errorPath'],
        'status' => $row['errorStatus'] !== null ? (int) $row['errorStatus'] : null, 'message' => $row['errorMessage'],
        'line' => $row['errorLine'], 'count' => max(1, (int) $row['hits'])]);
    if ($error !== null) {
        $adminTop[] = $error;
    }
}

// The list only has the reports nobody has cleared yet (the numbers above count those too)
$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-Watchdog` WHERE watchdogCleared = 0')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_WATCHDOG_SHOWN - 1, ADMIN_WATCHDOG_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminReports = $db->query('SELECT w.watchdogId, w.watchdogUserId, u.userName, u.userAvatarAttachmentId, w.watchdogCount, w.watchdogPage,
        w.watchdogErrors, w.watchdogIpv4, w.watchdogIpv6, w.watchdogUserAgent, w.watchdogFromDev,
        w.watchdogFirstTimestamp, w.watchdogLastTimestamp, w.watchdogCreatedTimestamp
    FROM `HBHub-Watchdog` w
    LEFT JOIN `HBHub-Users` u ON u.userId = w.watchdogUserId
    WHERE w.watchdogCleared = 0
    ORDER BY w.watchdogId DESC
    LIMIT ' . ADMIN_WATCHDOG_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_WATCHDOG_SHOWN))->fetchAll();

/**
 * A database timestamp with seconds, since errors come in bursts and the minute alone says little.
 */
function adminWatchdogTime(string $timestamp): string
{
    return date('d/m/Y H:i:s', strtotime($timestamp));
}

/**
 * When the first and last error of a report happened, as "13/11/2026 14:03:21 – 14:03:40" (the second one only when
 * it's another second, with the date only when it's another day).
 */
function adminWatchdogWhen(string $first, string $last): string
{
    $firstAt = strtotime($first);
    $lastAt = strtotime($last);
    if ($lastAt <= $firstAt) {
        return adminWatchdogTime($first);
    }
    return adminWatchdogTime($first) . ' – ' . date(date('Y-m-d', $firstAt) === date('Y-m-d', $lastAt) ? 'H:i:s' : 'd/m/Y H:i:s', $lastAt);
}

/**
 * One error as a list item's contents: its kind (or HTTP status) as a tag, the address and line, the message and how
 * many times. A 508 gets its own colour, since that's the server being busy rather than something broken.
 *
 * @param array{kind: string, path: ?string, status: ?int, message: ?string, line: ?string, count: int} $error from hbHubWatchdogError()
 */
function adminWatchdogErrorHtml(array $error): string
{
    $class = 'settings-status admin-watchdog-kind admin-watchdog-kind-' . $error['kind']
        . ($error['status'] === HBHUB_WATCHDOG_BUSY_STATUS ? ' admin-watchdog-kind-busy' : '');
    $html = '<span class="' . $class . '">' . htmlspecialchars(hbHubWatchdogLabel($error)) . '</span> ';
    if ($error['path'] !== null) {
        $html .= '<span class="admin-mono">' . htmlspecialchars($error['path'] . ($error['line'] !== null ? ':' . $error['line'] : '')) . '</span> ';
    }
    if ($error['message'] !== null) {
        $html .= '<span class="admin-watchdog-message">' . htmlspecialchars($error['message']) . '</span> ';
    }
    return $html . '<span class="admin-small">&times;' . number_format($error['count']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title>Browser errors - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'watchdog') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" aria-labelledby="admin-watchdog-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-watchdog-heading">Browser errors</h2>
                            <p class="settings-intro">
                                Every error people's browsers had on the site: answers of 400 or more (508 is &ldquo;Resource Limit
                                Is Reached&rdquo;, too many PHP requests at once), requests that got no answer, files that didn't
                                load, uncaught JavaScript errors and promises, and what the Content-Security-Policy blocked.
                                Each page reports them 3 seconds after the first one, trying again every 3 seconds until the
                                report gets through. Kept for <?= HBHUB_WATCHDOG_KEEP_DAYS ?> days.
                            </p>
                        </div>
                    </div>

                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th scope="col"></th><th scope="col">Errors</th><th scope="col" title="508 Resource Limit Is Reached: the server was busy">Of them 508s</th><th scope="col">Reports</th><th scope="col">Users</th><th scope="col">IP addresses</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminPeriods as $label => $period): ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars($label) ?></th>
                                        <td><?= number_format((int) $period['errors']) ?></td>
                                        <td><?= number_format($period['busy']) ?></td>
                                        <td><?= number_format((int) $period['reports']) ?></td>
                                        <td><?= number_format((int) $period['users']) ?></td>
                                        <td><?= number_format((int) $period['ips']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (array_sum($adminKinds) > 0): ?>
                        <h3 class="admin-watchdog-subheading">By kind, last <?= ADMIN_WATCHDOG_TOP_DAYS ?> days</h3>
                        <ul class="admin-watchdog-kinds">
                            <?php foreach ($adminKinds as $kind => $hits): ?>
                                <li><span class="settings-status admin-watchdog-kind admin-watchdog-kind-<?= $kind ?>"><?= htmlspecialchars(HBHUB_WATCHDOG_KINDS[$kind]) ?></span> <?= number_format($hits) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($adminTop !== []): ?>
                        <h3 class="admin-watchdog-subheading">Most common, last <?= ADMIN_WATCHDOG_TOP_DAYS ?> days</h3>
                        <ol class="admin-watchdog-top">
                            <?php foreach ($adminTop as $error): ?>
                                <li><?= adminWatchdogErrorHtml($error) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>

                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading">Reports</h2>
                            <p class="settings-intro">Who got the errors, on which page, and what they were.</p>
                        </div>
                    </div>

                    <?php if ($adminReports === []): ?>
                        <p class="settings-hint">No reports to show (none since the list was last cleared, or in the last <?= HBHUB_WATCHDOG_KEEP_DAYS ?> days).</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_WATCHDOG_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_WATCHDOG_SHOWN + count($adminReports)) ?>
                            of <?= number_format($adminTotal) ?>, newest first
                        </p>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">When</th><th scope="col">Errors</th><th scope="col">User</th><th scope="col">Page and errors</th><th scope="col">IP address</th><th scope="col">Browser</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminReports as $report): ?>
                                        <?php
                                        $errors = json_decode($report['watchdogErrors'], true);
                                        $errors = is_array($errors) && array_is_list($errors) ? hbHubWatchdogMergeErrors($errors) : [];
                                        // When it got through, if that was more than a few seconds after the last one (it had to try again)
                                        $lateBy = strtotime($report['watchdogCreatedTimestamp']) - strtotime($report['watchdogLastTimestamp']);
                                        ?>
                                        <tr>
                                            <td class="admin-nowrap">
                                                <?= adminWatchdogWhen($report['watchdogFirstTimestamp'], $report['watchdogLastTimestamp']) ?>
                                                <?php if ($lateBy > 5): ?>
                                                    <br><span class="admin-small" title="The report had to be tried again until the server answered">reported <?= adminWatchdogTime($report['watchdogCreatedTimestamp']) ?></span>
                                                <?php endif; ?>
                                                <?php if ((int) $report['watchdogFromDev'] === 1): ?>
                                                    <br><span class="settings-status admin-feedback-dev" title="From the dev site, not the live one">Dev</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?= number_format((int) $report['watchdogCount']) ?></strong></td>
                                            <td class="admin-log-by">
                                                <?php if ($report['watchdogUserId'] !== null): ?>
                                                    <?php $reportUserId = (int) $report['watchdogUserId']; $reportName = $report['userName'] ?? 'Deleted User'; ?>
                                                    <a class="admin-list-link" href="/admin/user?user=<?= $reportUserId ?>" title="<?= htmlspecialchars($reportName) ?>">
                                                        <?= adminAvatarHtml(hbHubAvatarUrl($reportUserId, $report['userAvatarAttachmentId'] !== null ? (int) $report['userAvatarAttachmentId'] : null), $reportName, true) ?>
                                                        <span class="admin-list-name"><?= htmlspecialchars($reportName) ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="admin-muted">Not logged in</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-message-text">
                                                <?php if ($report['watchdogPage'] !== null): ?>
                                                    On <a class="admin-link admin-mono" href="<?= htmlspecialchars($report['watchdogPage']) ?>"><?= htmlspecialchars($report['watchdogPage']) ?></a>
                                                <?php else: ?>
                                                    <span class="admin-muted">Page not known</span>
                                                <?php endif; ?>
                                                <?php if ($errors !== []): ?>
                                                    <ul class="admin-watchdog-errors">
                                                        <?php foreach ($errors as $error): ?>
                                                            <li><?= adminWatchdogErrorHtml($error) ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-mono admin-nowrap"><?= htmlspecialchars(adminIps($report['watchdogIpv4'], $report['watchdogIpv6'])) ?></td>
                                            <td class="admin-small admin-watchdog-agent"><?= $report['watchdogUserAgent'] !== null ? htmlspecialchars($report['watchdogUserAgent']) : '–' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="/admin/watchdog<?= $adminPage > 2 ? '?page=' . ($adminPage - 1) : '' ?>#list" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="/admin/watchdog?page=<?= $adminPage + 1 ?>#list" rel="next">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>

                    <?php if ($adminTotal > 0): ?>
                        <form action="/admin/watchdog" method="post" class="admin-watchdog-clear" data-confirm="Clear all the reports from this list? It can't be brought back, but the numbers at the top still count them.">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Clear the reports</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
