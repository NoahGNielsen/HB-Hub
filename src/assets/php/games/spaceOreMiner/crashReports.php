<?php
// Ore Mining Inc.'s crash reports. When the game froze or closed without saving properly, the page asks the next time
// it's opened whether it may send a report about it (see "Crash reports" in spaceOreMiner.js), and only sends one if the
// player says yes. games/spaceOreMiner/crash takes it and saves it here with hbHubMinerCrashSave(), and site admins
// read them on the admin panel's Crash reports list (admin/crashes.php).
//
// Each one is a row in HBHub-MinerCrashReports: who sent it, the kind (HBHUB_MINER_CRASH_KINDS), the report as JSON,
// the browser's user agent and when. The report is what the page wrote down while it ran, as made by crashReport() in
// spaceOreMiner.js: {kind, hang (for a freeze: what the page was doing, for how long, whether it came back, and the
// steps it did just before, see spaceOreMinerWatchdog.js), page (when it was opened and last heard from, the view, the
// screen, cores, memory and graphics card, the auto switches), trail (the last things that happened: saves, errors, slow
// frames, freezes, frame rate drops, as [Unix ms, text]), samples (every 30 seconds: memory used, elements and
// animations on the page, frame rate, time spent drawing and the slowest steps), state (the save as the page had it), pending (how many
// actions weren't saved), earlier (when the page found more than one crash: the older ones, newest first, as {kind,
// page, hang}, so one yes is one report)}.
// Nothing in it is checked here beyond being a JSON object of at most HBHUB_MINER_CRASH_MAX_BYTES: it's only ever read
// by admins, and escaped when shown. They're kept HBHUB_MINER_CRASH_KEEP_DAYS, and deleted with the account.
//
// The browser's own notes about a crash are kept here too, in HBHub-MinerBrowserCrashes: see
// games/spaceOreMiner/browserCrash.php, which takes them, and hbHubMinerBrowserCrashSave().
require_once __DIR__ . '/../../database.php';

const HBHUB_MINER_CRASH_KINDS = [
    'hang' => 'Froze', // the page stopped answering (the watchdog worker saw it), whether it came back or not
    'closed' => 'Crashed', // not "Closed", which read like someone had closed the report. The page went away while it was being looked at, without saying goodbye (killed, crashed, or the computer turned off)
];
const HBHUB_MINER_CRASH_MAX_BYTES = 200000; // of JSON, a report with a big save and a full trail is about 20 KB
const HBHUB_MINER_CRASH_PER_DAY = 20; // per user, so the endpoint can't be used to fill the database
const HBHUB_MINER_CRASH_KEEP_DAYS = 90;
// The browser's own notes. Unlike the reports above they aren't tied to anyone: nobody is asked about them, so they hold
// nothing that says who it was.
const HBHUB_MINER_BROWSER_CRASH_REASONS = [ // browserCrashReason in HBHub-MinerBrowserCrashes => what the admin panel says
    'oom' => 'Ran out of memory',
    'unresponsive' => 'Stopped responding',
    'unknown' => 'No reason given',
];
const HBHUB_MINER_BROWSER_CRASH_MAX_BYTES = 65536; // of a request, one report is well under 1 KB
const HBHUB_MINER_BROWSER_CRASH_PER_POST = 10; // a browser can send a few at once
const HBHUB_MINER_BROWSER_CRASH_PER_DAY = 200; // for everyone together: anyone can post these, so it can't be per user

/**
 * Saves a crash report a player agreed to send. $report is the decoded JSON object. Returns false (and saves nothing)
 * when the user has sent HBHUB_MINER_CRASH_PER_DAY in the last day already.
 */
function hbHubMinerCrashSave(PDO $db, int $userId, string $kind, array $report, ?string $userAgent): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-MinerCrashReports`
        WHERE crashReportUserId = ? AND crashReportCreatedTimestamp > NOW() - INTERVAL 1 DAY');
    $statement->execute([$userId]);
    if ((int) $statement->fetchColumn() >= HBHUB_MINER_CRASH_PER_DAY) {
        return false;
    }

    $userAgent = $userAgent !== null ? mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, 255) : null;
    $db->prepare('INSERT INTO `HBHub-MinerCrashReports` (crashReportUserId, crashReportKind, crashReportData, crashReportUserAgent)
        VALUES (?, ?, ?, ?)')
        ->execute([$userId, $kind, json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR), $userAgent]); // a number too big for a double (INF) becomes 0
    hbHubMinerCrashSweep($db);
    return true;
}

/**
 * Saves one of the browser's own crash notes: one report from the list games/spaceOreMiner/browserCrash.php was sent,
 * as it came, so nothing in it can be trusted. Only the reason (from a fixed list), the browser's user agent and when it
 * happened are kept. Anything that isn't a crash on the game's page is ignored, as is all of it over
 * HBHUB_MINER_BROWSER_CRASH_PER_DAY.
 */
function hbHubMinerBrowserCrashSave(PDO $db, mixed $report): void
{
    if (!is_array($report) || ($report['type'] ?? null) !== 'crash' || !is_string($report['url'] ?? null)) {
        return;
    }
    $path = parse_url($report['url'], PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/games/ore-mining-inc/')) {
        return;
    }
    $count = (int) $db->query('SELECT COUNT(*) FROM `HBHub-MinerBrowserCrashes`
        WHERE browserCrashCreatedTimestamp > NOW() - INTERVAL 1 DAY')->fetchColumn();
    if ($count >= HBHUB_MINER_BROWSER_CRASH_PER_DAY) {
        return;
    }

    $reason = is_array($report['body'] ?? null) ? ($report['body']['reason'] ?? null) : null;
    $reason = is_string($reason) && isset(HBHUB_MINER_BROWSER_CRASH_REASONS[$reason]) ? $reason : 'unknown';
    $userAgent = is_string($report['user_agent'] ?? null) ? mb_substr(mb_scrub($report['user_agent'], 'UTF-8'), 0, 255) : null;
    // Browsers can hold on to a report for a while before sending it, so age is how long ago it happened (in ms)
    $age = is_int($report['age'] ?? null) ? intdiv(max(0, $report['age']), 1000) : 0;
    $db->prepare('INSERT INTO `HBHub-MinerBrowserCrashes` (browserCrashReason, browserCrashUserAgent, browserCrashHappenedTimestamp)
        VALUES (?, ?, NOW() - INTERVAL ? SECOND)')
        ->execute([$reason, $userAgent, min($age, HBHUB_MINER_CRASH_KEEP_DAYS * 86400)]);
    hbHubMinerBrowserCrashSweep($db);
}

/**
 * Deletes one crash report, from the admin panel. Returns whether there was one.
 */
function hbHubMinerCrashDelete(PDO $db, int $crashReportId): bool
{
    $statement = $db->prepare('DELETE FROM `HBHub-MinerCrashReports` WHERE crashReportId = ?');
    $statement->execute([$crashReportId]);
    return $statement->rowCount() > 0;
}

/**
 * Deletes the reports older than HBHUB_MINER_CRASH_KEEP_DAYS: after each new one, and in the nightly clean-up
 * (cron/nightly.php). Returns how many went.
 */
function hbHubMinerCrashSweep(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-MinerCrashReports`
        WHERE crashReportCreatedTimestamp < NOW() - INTERVAL ' . HBHUB_MINER_CRASH_KEEP_DAYS . ' DAY');
}

/**
 * The same for the browser's notes. Its own function so that the reports above keep working on a database that hasn't
 * had HBHub-MinerBrowserCrashes added yet (migrations are run by hand).
 */
function hbHubMinerBrowserCrashSweep(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-MinerBrowserCrashes`
        WHERE browserCrashCreatedTimestamp < NOW() - INTERVAL ' . HBHUB_MINER_CRASH_KEEP_DAYS . ' DAY');
}
