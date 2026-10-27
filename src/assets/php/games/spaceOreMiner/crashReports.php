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
// animations on the page, frame rate and the slowest steps), state (the save as the page had it), pending (how many
// actions weren't saved), earlier (when the page found more than one crash: the older ones, newest first, as {kind,
// page, hang}, so one yes is one report)}.
// Nothing in it is checked here beyond being a JSON object of at most HBHUB_MINER_CRASH_MAX_BYTES: it's only ever read
// by admins, and escaped when shown. They're kept HBHUB_MINER_CRASH_KEEP_DAYS, and deleted with the account.
require_once __DIR__ . '/../../database.php';

const HBHUB_MINER_CRASH_KINDS = [
    'hang' => 'Froze', // the page stopped answering (the watchdog worker saw it), whether it came back or not
    'closed' => 'Closed', // the page went away while it was being looked at, without saying goodbye (killed, crashed, or the computer turned off)
];
const HBHUB_MINER_CRASH_MAX_BYTES = 200000; // of JSON, a report with a big save and a full trail is about 20 KB
const HBHUB_MINER_CRASH_PER_DAY = 20; // per user, so the endpoint can't be used to fill the database
const HBHUB_MINER_CRASH_KEEP_DAYS = 90;

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
