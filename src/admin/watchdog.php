<?php
// Admin panel's Watchdog: /admin/watchdog, in the admin panel's side menu (adminListsMenuHtml()), with how many 508s
// there were in the last 24 hours. Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// The "508 Resource Limit Is Reached" answers browsers got from the site (assets/php/watchdog.php has what the Watchdog
// is, assets/js/watchdog.js what counts). At the top, how many there were in the last hour, day, week and
// HBHUB_WATCHDOG_KEEP_DAYS days (and in how many reports, from how many users and IP addresses), and the addresses that
// answered 508 the most in the last 7 days. Under that every report, newest first, ADMIN_WATCHDOG_SHOWN at a time
// (?page=): when the first and last of them happened (and when the report got through, if that was later), how many,
// who (or "Not logged in"), the page they were on, which addresses answered 508 and how often, the IP address, the
// browser's user agent and a Dev mark when it came from the dev site.
// Form (action=clear) deletes all of it for good, and goes in the admin log (assets/php/adminLog.php). The rows also go
// by themselves after HBHUB_WATCHDOG_KEEP_DAYS.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/watchdog.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_WATCHDOG_SHOWN = 50; // per page
const ADMIN_WATCHDOG_TOP = 10; // addresses in the "answered 508 the most" list
const ADMIN_WATCHDOG_TOP_DAYS = 7;
// The numbers at the top: label => hours back (the last one is everything that's kept)
const ADMIN_WATCHDOG_PERIODS = ['Last hour' => 1, 'Last 24 hours' => 24, 'Last 7 days' => 168, 'Last ' . HBHUB_WATCHDOG_KEEP_DAYS . ' days' => HBHUB_WATCHDOG_KEEP_DAYS * 24];

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
foreach (ADMIN_WATCHDOG_PERIODS as $label => $hours) {
    $statement->execute([$hours]);
    $adminPeriods[$label] = $statement->fetch();
}

$adminTop = $db->query('SELECT a.path, SUM(a.hits) AS hits
    FROM `HBHub-Watchdog` w,
        JSON_TABLE(w.watchdogAddresses, \'$[*]\' COLUMNS (path varchar(255) PATH \'$.path\', hits int UNSIGNED PATH \'$.count\')) a
    WHERE w.watchdogLastTimestamp > NOW() - INTERVAL ' . ADMIN_WATCHDOG_TOP_DAYS . ' DAY AND a.path IS NOT NULL
    GROUP BY a.path ORDER BY hits DESC LIMIT ' . ADMIN_WATCHDOG_TOP)->fetchAll();

$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-Watchdog`')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_WATCHDOG_SHOWN - 1, ADMIN_WATCHDOG_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminReports = $db->query('SELECT w.watchdogId, w.watchdogUserId, u.userName, u.userAvatarAttachmentId, w.watchdogCount, w.watchdogPage,
        w.watchdogAddresses, w.watchdogIpv4, w.watchdogIpv6, w.watchdogUserAgent, w.watchdogFromDev,
        w.watchdogFirstTimestamp, w.watchdogLastTimestamp, w.watchdogCreatedTimestamp
    FROM `HBHub-Watchdog` w
    LEFT JOIN `HBHub-Users` u ON u.userId = w.watchdogUserId
    ORDER BY w.watchdogId DESC
    LIMIT ' . ADMIN_WATCHDOG_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_WATCHDOG_SHOWN))->fetchAll();

/**
 * A database timestamp with seconds, since 508s come in bursts and the minute alone says little.
 */
function adminWatchdogTime(string $timestamp): string
{
    return date('d/m/Y H:i:s', strtotime($timestamp));
}

/**
 * When the first and last 508 of a report happened, as "13/11/2026 14:03:21 – 14:03:40" (the second one only when it's
 * another second, with the date only when it's another day).
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title>Watchdog - Admin panel - HB Hub</title>
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
                            <h2 id="admin-watchdog-heading">Watchdog</h2>
                            <p class="settings-intro">
                                Every time the server answered &ldquo;508 Resource Limit Is Reached&rdquo; (too many PHP requests at once).
                                Each page counts the 508s it gets and reports them 3 seconds after the first one, trying again every
                                3 seconds until the report gets through. Kept for <?= HBHUB_WATCHDOG_KEEP_DAYS ?> days.
                            </p>
                        </div>
                    </div>

                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th scope="col"></th><th scope="col">508s</th><th scope="col">Reports</th><th scope="col">Users</th><th scope="col">IP addresses</th></tr></thead>
                            <tbody>
                                <?php foreach ($adminPeriods as $label => $period): ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars($label) ?></th>
                                        <td><?= number_format((int) $period['errors']) ?></td>
                                        <td><?= number_format((int) $period['reports']) ?></td>
                                        <td><?= number_format((int) $period['users']) ?></td>
                                        <td><?= number_format((int) $period['ips']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($adminTop !== []): ?>
                        <h3 class="admin-watchdog-subheading">Answered 508 the most, last <?= ADMIN_WATCHDOG_TOP_DAYS ?> days</h3>
                        <ol class="admin-watchdog-top">
                            <?php foreach ($adminTop as $address): ?>
                                <li><span class="admin-mono"><?= htmlspecialchars($address['path']) ?></span> <span class="admin-small">&times;<?= number_format((int) $address['hits']) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if ($adminTotal > 0): ?>
                        <form action="/admin/watchdog" method="post" class="admin-watchdog-clear" data-confirm="Clear the whole Watchdog log? It can't be brought back.">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Clear the log</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading">Reports</h2>
                            <p class="settings-intro">Who got the 508s, on which page, and which addresses answered with them.</p>
                        </div>
                    </div>

                    <?php if ($adminReports === []): ?>
                        <p class="settings-hint">No 508s have been reported in the last <?= HBHUB_WATCHDOG_KEEP_DAYS ?> days.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_WATCHDOG_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_WATCHDOG_SHOWN + count($adminReports)) ?>
                            of <?= number_format($adminTotal) ?>, newest first
                        </p>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">When</th><th scope="col">508s</th><th scope="col">User</th><th scope="col">Page and addresses</th><th scope="col">IP address</th><th scope="col">Browser</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminReports as $report): ?>
                                        <?php
                                        $addresses = json_decode($report['watchdogAddresses'], true);
                                        $addresses = is_array($addresses) ? $addresses : [];
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
                                                <?php if ($addresses !== []): ?>
                                                    <ul class="admin-watchdog-addresses">
                                                        <?php foreach ($addresses as $address): ?>
                                                            <?php if (is_array($address) && is_string($address['path'] ?? null)): ?>
                                                                <li><span class="admin-mono"><?= htmlspecialchars($address['path']) ?></span> <span class="admin-small">&times;<?= number_format((int) ($address['count'] ?? 0)) ?></span></li>
                                                            <?php endif; ?>
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
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
