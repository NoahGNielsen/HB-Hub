<?php
// Admin panel's Ore Mining Inc. crash reports: /admin/crashes, in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// The reports players agreed to send after the game froze or closed badly (games/spaceOreMiner/crash.php,
// assets/php/games/spaceOreMiner/crashReports.php has what's in one), newest first, ADMIN_CRASHES_SHOWN at a time
// (?page=). Each shows who sent it, when, its browser and graphics card, what happened in a line (what the page was
// doing when it froze, for how long, whether it came back), the steps it did just before it froze, the older crashes
// sent along with it (earlier) in a line each, every 30 seconds'
// memory, elements, animations, frame rate and slowest steps, the last things it wrote down (the trail), and the whole
// report as JSON under "Everything" (the save as the page had it is in there, to play it again). Copy everything
// (admin.js) copies all of that as text (adminCrashCopyText()), to paste somewhere else.
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
        $doing = is_string($hang['step'] ?? null)
            ? 'while doing ' . $hang['step'] . ' (at it for ' . adminCrashLength($hang['stepMs'] ?? null) . ' when the freeze was noticed)'
            : 'between its own tasks (in something it doesn\'t name, like the browser itself or code typed in the console)';
        return 'Froze ' . $doing . ', '
            . (!empty($hang['recovered']) ? 'and came back after ' . adminCrashLength($hang['lasted'] ?? null)
                : 'and was still frozen ' . adminCrashLength($hang['lasted'] ?? null) . ' later, when it was last heard from');
    }
    return 'The page went away while it was being looked at, without closing properly (killed, crashed, ran out of memory, or the computer turned off)';
}

/**
 * When and where it was open, and the device, in a line.
 */
function adminCrashPageLine(array $report, string $sentOn): string
{
    $page = is_array($report['page'] ?? null) ? $report['page'] : [];
    $switches = is_array($page['switches'] ?? null) ? array_filter($page['switches'], 'is_string') : [];
    return 'Opened ' . adminCrashTime($page['opened'] ?? null, $sentOn) . ', last heard from ' . adminCrashTime($page['lastSeen'] ?? null, $sentOn)
        . ' on the ' . (is_string($page['view'] ?? null) ? $page['view'] : '?') . ' view, '
        . (int) ($report['pending'] ?? 0) . ' actions not saved yet. Screen ' . (is_string($page['screen'] ?? null) ? $page['screen'] : '?')
        . ', ' . (int) ($page['cores'] ?? 0) . ' cores' . (isset($page['deviceMemory']) ? ', ' . (float) $page['deviceMemory'] . ' GB memory' : '')
        . ', graphics card ' . (is_string($page['gpu'] ?? null) ? $page['gpu'] : 'not known')
        . '. Switches on: ' . ($switches !== [] ? implode(', ', $switches) : 'none') . '.';
}

/**
 * One of the older crashes sent along with a report (its earlier: {kind, page, hang}) in a line: when, and what
 * happened. Null when it isn't one.
 */
function adminCrashEarlierLine(mixed $entry, string $sentOn): ?string
{
    if (!is_array($entry) || !is_string($entry['kind'] ?? null) || !is_array($entry['page'] ?? null)) {
        return null;
    }
    return adminCrashTime($entry['hang']['at'] ?? $entry['page']['lastSeen'] ?? null, $sentOn) . ' '
        . adminCrashSummary($entry['kind'], $entry) . ' (the page was opened ' . adminCrashTime($entry['page']['opened'] ?? null, $sentOn) . ')';
}

/**
 * One of the steps before a freeze ([Unix ms, name, times in a row, longest ms]) in a line, or null when it isn't one.
 */
function adminCrashStepLine(mixed $entry, string $sentOn): ?string
{
    if (!is_array($entry) || count($entry) !== 4 || !is_string($entry[1])) {
        return null;
    }
    $times = (int) $entry[2];
    return adminCrashTime($entry[0], $sentOn) . ' ' . $entry[1] . ($times > 1 ? ' ×' . $times . ', the longest ' : ', ') . adminCrashLength($entry[3]);
}

/**
 * The whole report as text, for the Copy button: what the page shows, then all of it as JSON.
 */
function adminCrashCopyText(array $crash, array $report, string $sentOn): string
{
    $lines = ['Ore Mining Inc. crash report #' . (int) $crash['crashReportId'] . ' (' . ($crash['crashReportKind']) . '), sent '
        . adminTime($crash['crashReportCreatedTimestamp']) . ' by ' . ($crash['userName'] ?? 'Deleted User'),
        adminCrashSummary($crash['crashReportKind'], $report) . '.', adminCrashPageLine($report, $sentOn)];
    if ($crash['crashReportUserAgent'] !== null) {
        $lines[] = $crash['crashReportUserAgent'];
    }
    $list = static fn (mixed $value): array => is_array($value) ? $value : [];
    $before = array_map(static fn (mixed $entry): ?string => adminCrashStepLine($entry, $sentOn), $list($report['hang']['before'] ?? null));
    if ($before !== [] && is_string($report['hang']['step'] ?? null)) {
        $before[] = $report['hang']['step'] . ', where it froze';
    }
    $sections = [
        'The last steps before it froze' => $before,
        'It also crashed before that' => array_map(static fn (mixed $entry): ?string => adminCrashEarlierLine($entry, $sentOn), $list($report['earlier'] ?? null)),
        'Every 30 seconds' => array_map(static fn (mixed $sample): ?string => adminCrashSample($sample, $sentOn), $list($report['samples'] ?? null)),
        'The last things it wrote down' => array_map(static fn (mixed $entry): ?string => is_array($entry) && count($entry) === 2 && is_string($entry[1])
            ? adminCrashTime($entry[0], $sentOn) . ' ' . $entry[1] : null, $list($report['trail'] ?? null)),
    ];
    foreach ($sections as $heading => $sectionLines) {
        $sectionLines = array_filter($sectionLines, 'is_string');
        if ($sectionLines !== []) {
            $lines[] = '';
            $lines[] = $heading . ':';
            array_push($lines, ...$sectionLines);
        }
    }
    $lines[] = '';
    $lines[] = 'Everything:';
    $lines[] = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return implode("\n", $lines);
}

/**
 * One of a report's samples (every 30 seconds) in a line: memory, elements, animations, frame rate and the slowest
 * steps. Reports from before the frame rate was added have [Unix ms, MB, elements] instead.
 */
function adminCrashSample(mixed $sample, string $sentOn): ?string
{
    if (is_array($sample) && array_is_list($sample) && count($sample) === 3) {
        $sample = ['at' => $sample[0], 'memory' => $sample[1], 'elements' => $sample[2]];
    }
    if (!is_array($sample) || !isset($sample['at'])) {
        return null;
    }
    $parts = [adminCrashTime($sample['at'], $sentOn)];
    $parts[] = is_numeric($sample['memory'] ?? null) ? round((float) $sample['memory']) . ' MB' : '? MB';
    $parts[] = (int) ($sample['elements'] ?? 0) . ' elements';
    if (is_numeric($sample['animations'] ?? null)) {
        $parts[] = (int) $sample['animations'] . ' animations';
    }
    if (is_numeric($sample['fps'] ?? null)) {
        $parts[] = (int) $sample['fps'] . ' fps' . (is_numeric($sample['lowestFps'] ?? null) ? ' (lowest ' . (int) $sample['lowestFps'] . ')' : '');
    }
    $slowest = [];
    foreach (is_array($sample['slowest'] ?? null) ? $sample['slowest'] : [] as $step) {
        if (is_array($step) && count($step) === 4 && is_string($step[0])) {
            $slowest[] = $step[0] . ' ' . adminCrashLength($step[1]) . ' (' . (int) $step[3] . '×, ' . adminCrashLength($step[2]) . ' in all)';
        }
    }
    if ($slowest !== []) {
        $parts[] = 'slowest: ' . implode(', ', $slowest);
    }
    return implode(' · ', $parts);
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
                            <h2 id="admin-list-heading">Ore Mining Inc. crash reports</h2>
                            <p class="settings-intro">
                                Reports kept for <?= HBHUB_MINER_CRASH_KEEP_DAYS ?> days.
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
                                $crashTrail = is_array($crashReport['trail'] ?? null) ? $crashReport['trail'] : [];
                                $crashSamples = is_array($crashReport['samples'] ?? null) ? $crashReport['samples'] : [];
                                $crashBefore = is_array($crashReport['hang']['before'] ?? null) ? $crashReport['hang']['before'] : [];
                                $crashEarlier = array_filter(array_map(static fn (mixed $entry): ?string => adminCrashEarlierLine($entry, $crashSentOn),
                                    is_array($crashReport['earlier'] ?? null) ? $crashReport['earlier'] : []), 'is_string');
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
                                    <p class="admin-feedback-text admin-small"><?= htmlspecialchars(adminCrashPageLine($crashReport, $crashSentOn)) ?></p>
                                    <?php if ($crash['crashReportUserAgent'] !== null): ?>
                                        <p class="admin-feedback-text admin-small admin-mono"><?= htmlspecialchars($crash['crashReportUserAgent']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($crashBefore !== []): ?>
                                        <details open>
                                            <summary>The last steps before it froze (the same step several times in a row is one line)</summary>
                                            <ol class="admin-crash-trail">
                                                <?php foreach ($crashBefore as $entry): ?>
                                                    <?php $stepLine = adminCrashStepLine($entry, $crashSentOn); ?>
                                                    <?php if ($stepLine !== null): ?>
                                                        <li><?= htmlspecialchars($stepLine) ?></li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (is_string($crashReport['hang']['step'] ?? null)): ?>
                                                    <li><strong><?= htmlspecialchars($crashReport['hang']['step']) ?>, where it froze</strong></li>
                                                <?php endif; ?>
                                            </ol>
                                        </details>
                                    <?php endif; ?>
                                    <?php if ($crashEarlier !== []): ?>
                                        <details open>
                                            <summary>It also crashed <?= count($crashEarlier) ?> <?= count($crashEarlier) === 1 ? 'time' : 'times' ?> before that (newest first, the full steps are under Everything)</summary>
                                            <ol class="admin-crash-trail">
                                                <?php foreach ($crashEarlier as $earlierLine): ?>
                                                    <li><?= htmlspecialchars($earlierLine) ?></li>
                                                <?php endforeach; ?>
                                            </ol>
                                        </details>
                                    <?php endif; ?>
                                    <?php if ($crashSamples !== []): ?>
                                        <details>
                                            <summary>Every 30 seconds: memory, elements and animations on the page, frame rate and the slowest steps</summary>
                                            <ol class="admin-crash-trail">
                                                <?php foreach ($crashSamples as $sample): ?>
                                                    <?php $sampleLine = adminCrashSample($sample, $crashSentOn); ?>
                                                    <?php if ($sampleLine !== null): ?>
                                                        <li><?= htmlspecialchars($sampleLine) ?></li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </ol>
                                        </details>
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
                                        <!-- admin.js shows it: it copies everything above, as text, from the hidden copy next to it -->
                                        <button type="button" class="settings-btn settings-btn-small" data-crash-copy hidden>Copy everything</button>
                                        <pre hidden data-crash-copy-text><?= htmlspecialchars(adminCrashCopyText($crash, $crashReport, $crashSentOn)) ?></pre>
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
