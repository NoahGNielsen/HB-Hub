<?php
// Admin panel's Space Ore Miner crash reports: /admin/crashes, in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// The reports players agreed to send after the game froze or closed badly (games/spaceOreMiner/crash.php,
// assets/php/games/spaceOreMiner/crashReports.php has what's in one), newest first, ADMIN_CRASHES_SHOWN at a time
// (?page=). Each shows who sent it, when, its browser, what happened in a line (what the page was doing when it froze,
// for how long, whether it came back), the last things it wrote down (the trail) and the memory it used, and the whole
// report as JSON under "Everything" (the save as the page had it is in there, to play it again).
// Form (action=delete, crash=id) removes one for good, and goes in the admin log (assets/php/adminLog.php). They also
// go by themselves after HBHUB_MINER_CRASH_KEEP_DAYS.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/crashReports.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_CRASHES_SHOWN = 20; // per page, as each one can be long

adminOnly($hbHubUser);

$db = hbHubDatabase();
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'delete') {
    $crashId = $_POST['crash'] ?? null;
    if (is_string($crashId) && ctype_digit($crashId) && strlen($crashId) <= 10 && hbHubMinerCrashDelete($db, (int) $crashId)) {
        adminLog($db, (int) $hbHubUser['userId'], 'crash.delete', null, null, ['crashReportId' => (int) $crashId]);
    }

    // Back to the same page with a toast, so reloading doesn't send the form again
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast('adminCrashDeleted');
    header('Location: /admin/crashes' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#list', true, 303);
    exit;
}

hbHubMinerCrashSweep($db); // in case the nightly clean-up hasn't run
$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-MinerCrashReports`')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_CRASHES_SHOWN - 1, ADMIN_CRASHES_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminCrashes = $db->query('SELECT c.crashReportId, c.crashReportUserId, u.userName, u.userAvatarAttachmentId, c.crashReportKind,
        c.crashReportData, c.crashReportUserAgent, c.crashReportCreatedTimestamp
    FROM `HBHub-MinerCrashReports` c
    LEFT JOIN `HBHub-Users` u ON u.userId = c.crashReportUserId
    ORDER BY c.crashReportId DESC
    LIMIT ' . ADMIN_CRASHES_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_CRASHES_SHOWN))->fetchAll();
$adminFormAction = '/admin/crashes' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#list';

/**
 * A time from a report (Unix ms) as a time of day, with the date when it isn't the day the report was sent.
 */
function adminCrashTime(mixed $ms, string $sentOn): string
{
    if (!is_int($ms) && !is_float($ms)) {
        return '?';
    }
    $seconds = (int) floor($ms / 1000);
    return date('Y-m-d', $seconds) === $sentOn ? date('H:i:s', $seconds) : date('d/m/Y H:i:s', $seconds);
}

/**
 * Milliseconds as "850 ms", "12.3 s" or "4 min".
 */
function adminCrashLength(mixed $ms): string
{
    if (!is_int($ms) && !is_float($ms)) {
        return '?';
    }
    return match (true) {
        $ms < 1000 => round($ms) . ' ms',
        $ms < 120000 => round($ms / 1000, 1) . ' s',
        default => round($ms / 60000) . ' min',
    };
}

/**
 * What happened, in a line: for a freeze what the page was doing, for how long and whether it came back.
 */
function adminCrashSummary(string $kind, array $report): string
{
    $hang = is_array($report['hang'] ?? null) ? $report['hang'] : null;
    if ($kind === 'hang' && $hang !== null) {
        $step = is_string($hang['step'] ?? null) ? $hang['step'] : 'nothing it knew of (between its own tasks)';
        return 'Froze while doing ' . $step . ' (at it for ' . adminCrashLength($hang['stepMs'] ?? null) . ' when the freeze was noticed), '
            . (!empty($hang['recovered']) ? 'and came back after ' . adminCrashLength($hang['lasted'] ?? null)
                : 'and was still frozen ' . adminCrashLength($hang['lasted'] ?? null) . ' later, when it was last heard from');
    }
    return 'The page went away while it was being looked at, without closing properly (killed, crashed, ran out of memory, or the computer turned off)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title>Crash reports - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'crashes') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading">Space Ore Miner crash reports</h2>
                            <p class="settings-intro">
                                Sent by players who said yes when the game asked, the next time they opened it after it froze or
                                closed badly. Kept for <?= HBHUB_MINER_CRASH_KEEP_DAYS ?> days.
                            </p>
                        </div>
                    </div>

                    <?php if ($adminCrashes === []): ?>
                        <p class="settings-hint">No crash reports.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_CRASHES_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_CRASHES_SHOWN + count($adminCrashes)) ?>
                            of <?= number_format($adminTotal) ?>, newest first
                        </p>
                        <ul class="admin-feedback-list">
                            <?php foreach ($adminCrashes as $crash): ?>
                                <?php
                                $crashId = (int) $crash['crashReportId'];
                                $crashName = $crash['userName'] ?? 'Deleted User';
                                $crashKind = $crash['crashReportKind'];
                                $crashReport = json_decode($crash['crashReportData'], true) ?: [];
                                $crashSentOn = date('Y-m-d', strtotime($crash['crashReportCreatedTimestamp']));
                                $crashPage = is_array($crashReport['page'] ?? null) ? $crashReport['page'] : [];
                                $crashTrail = is_array($crashReport['trail'] ?? null) ? $crashReport['trail'] : [];
                                $crashSamples = is_array($crashReport['samples'] ?? null) ? $crashReport['samples'] : [];
                                ?>
                                <li class="admin-feedback-item">
                                    <div class="admin-feedback-head">
                                        <span class="admin-feedback-from">
                                            <?= adminAvatarHtml(hbHubAvatarUrl((int) $crash['crashReportUserId'], $crash['userAvatarAttachmentId'] !== null ? (int) $crash['userAvatarAttachmentId'] : null), $crashName) ?>
                                            <a class="admin-link" href="/admin/user?user=<?= (int) $crash['crashReportUserId'] ?>"><?= htmlspecialchars($crashName) ?></a>
                                        </span>
                                        <span class="admin-small"><?= adminTime($crash['crashReportCreatedTimestamp']) ?> &middot; #<?= $crashId ?></span>
                                        <span class="settings-status is-on"><?= htmlspecialchars(HBHUB_MINER_CRASH_KINDS[$crashKind] ?? $crashKind) ?></span>
                                    </div>
                                    <p class="admin-feedback-text"><strong><?= htmlspecialchars(adminCrashSummary($crashKind, $crashReport)) ?>.</strong></p>
                                    <p class="admin-feedback-text admin-small">
                                        Opened <?= htmlspecialchars(adminCrashTime($crashPage['opened'] ?? null, $crashSentOn)) ?>,
                                        last heard from <?= htmlspecialchars(adminCrashTime($crashPage['lastSeen'] ?? null, $crashSentOn)) ?>
                                        on the <?= htmlspecialchars(is_string($crashPage['view'] ?? null) ? $crashPage['view'] : '?') ?> view,
                                        <?= (int) ($crashReport['pending'] ?? 0) ?> actions not saved yet.
                                        Screen <?= htmlspecialchars(is_string($crashPage['screen'] ?? null) ? $crashPage['screen'] : '?') ?>,
                                        <?= (int) ($crashPage['cores'] ?? 0) ?> cores<?= isset($crashPage['deviceMemory']) ? ', ' . htmlspecialchars((string) (float) $crashPage['deviceMemory']) . ' GB memory' : '' ?>.
                                        Switches on: <?= htmlspecialchars(is_array($crashPage['switches'] ?? null) && $crashPage['switches'] !== [] ? implode(', ', array_filter($crashPage['switches'], 'is_string')) : 'none') ?>.
                                    </p>
                                    <?php if ($crash['crashReportUserAgent'] !== null): ?>
                                        <p class="admin-feedback-text admin-small admin-mono"><?= htmlspecialchars($crash['crashReportUserAgent']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($crashSamples !== []): ?>
                                        <p class="admin-feedback-text admin-small">
                                            Memory used (MB) and elements on the page, every 30 seconds:
                                            <?php foreach ($crashSamples as $sample): ?>
                                                <?php if (is_array($sample) && count($sample) === 3): ?>
                                                    <span class="admin-mono"><?= htmlspecialchars(adminCrashTime($sample[0], $crashSentOn)) ?> <?= is_numeric($sample[1]) ? round((float) $sample[1]) . ' MB' : '?' ?> / <?= (int) $sample[2] ?></span>&ensp;
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($crashTrail !== []): ?>
                                        <details>
                                            <summary>The last <?= count($crashTrail) ?> things it wrote down</summary>
                                            <ol class="admin-crash-trail">
                                                <?php foreach ($crashTrail as $entry): ?>
                                                    <?php if (is_array($entry) && count($entry) === 2 && is_string($entry[1])): ?>
                                                        <li><span class="admin-mono"><?= htmlspecialchars(adminCrashTime($entry[0], $crashSentOn)) ?></span> <?= htmlspecialchars($entry[1]) ?></li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </ol>
                                        </details>
                                    <?php endif; ?>
                                    <details>
                                        <summary>Everything (with the save)</summary>
                                        <pre class="admin-crash-json"><?= htmlspecialchars(json_encode($crashReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) ?></pre>
                                    </details>
                                    <div class="admin-feedback-foot">
                                        <form action="<?= $adminFormAction ?>" method="post" data-confirm="Delete this crash report? It can't be brought back.">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="crash" value="<?= $crashId ?>">
                                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Delete</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="/admin/crashes<?= $adminPage > 2 ? '?page=' . ($adminPage - 1) : '' ?>#list" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="/admin/crashes?page=<?= $adminPage + 1 ?>#list" rel="next">Next</a>
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
