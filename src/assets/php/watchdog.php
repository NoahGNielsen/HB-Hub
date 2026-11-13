<?php
// The Watchdog: keeps track of the times the site answered "508 Resource Limit Is Reached". The hosting only runs about
// 10 PHP requests at the same time for the whole site, and every request over that gets a 508 instead (see
// assets/js/pictureQueue.js), so this shows when it happens, where, and to whom.
//
// assets/js/watchdog.js (on every page, header.php) counts the 508s the page gets: from its own fetch() calls, and, in
// browsers that tell (Chromium), from pictures, styles and scripts too. 3 seconds after the first one it sends how many
// there were to /watchdog (otherPages/watchdog.php), and when that doesn't go through (it's a PHP request too, so it
// often gets a 508 itself) it tries again every 3 seconds until it does. hbHubWatchdogSave() saves it here.
// Site admins read it under Watchdog in the admin panel's side menu (admin/watchdog.php).
//
// Each report is a row in HBHub-Watchdog: who (NULL when not logged in), how many, the path of the page they were on,
// which addresses answered 508 and how often (paths only, never the query, so no chat ids or search words), their IP
// address and user agent, whether it came from the dev site, and when the first and last of them happened and when the
// report got through. Rows go after HBHUB_WATCHDOG_KEEP_DAYS (after each new one and in the nightly clean-up,
// cron/nightly.php), and with the account (account.php).
//
// Anyone can send one, also visitors who aren't logged in (the free games get 508s too), so nothing in it is trusted:
// the numbers are capped, the paths checked, and one IP address only gets HBHUB_WATCHDOG_ROWS_PER_HOUR new rows an hour.
// After that its reports are added to its newest row instead, so they're still counted but can't fill the table.
// hbHubWatchdogSave() needs session.php (hbHubClientIp()), which every page and endpoint has loaded first anyway.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/feedback.php'; // hbHubFeedbackIsDev()

const HBHUB_WATCHDOG_KEEP_DAYS = 30;
const HBHUB_WATCHDOG_MAX_COUNT = 100000; // 508s in one report, far more than a page can get in the time it waits
const HBHUB_WATCHDOG_MAX_ADDRESSES = 20; // different addresses kept in one report (watchdog.js sends at most this many)
const HBHUB_WATCHDOG_MAX_AGE_SECONDS = 7 * 86400; // a report that waited longer than this (a tab left open) counts as this old
const HBHUB_WATCHDOG_ROWS_PER_HOUR = 60; // new rows per IP address, see the top

/**
 * A path as it's kept: only when it's plain enough to show and link to on the admin panel (letters, digits and
 * / . _ ~ -, like feedback's hbHubFeedbackPage()), or null. The query is never part of it.
 */
function hbHubWatchdogPath(mixed $path): ?string
{
    return is_string($path) && preg_match('~^/[A-Za-z0-9/._\~-]{0,254}$~', $path) === 1 ? $path : null;
}

/**
 * The addresses that answered 508, from the report's `addresses` field (JSON: {"/path": count, ...}), as they're
 * kept: [['path' => '/chat/', 'count' => 3], ...], the most first, at most HBHUB_WATCHDOG_MAX_ADDRESSES. Anything that
 * isn't a plain path with a whole number is left out.
 *
 * @return list<array{path: string, count: int}>
 */
function hbHubWatchdogAddresses(mixed $json): array
{
    $addresses = is_string($json) && strlen($json) <= 20000 ? json_decode($json, true, 4) : null;
    if (!is_array($addresses)) {
        return [];
    }
    $kept = [];
    foreach ($addresses as $path => $count) {
        $path = hbHubWatchdogPath((string) $path);
        if ($path !== null && is_int($count) && $count > 0) {
            $kept[$path] = min(HBHUB_WATCHDOG_MAX_COUNT, ($kept[$path] ?? 0) + $count);
        }
    }
    arsort($kept);
    $list = [];
    foreach (array_slice($kept, 0, HBHUB_WATCHDOG_MAX_ADDRESSES, true) as $path => $count) {
        $list[] = ['path' => (string) $path, 'count' => $count];
    }
    return $list;
}

/**
 * Adds two address lists together (when a report is added to an older row), keeping the most-hit
 * HBHUB_WATCHDOG_MAX_ADDRESSES.
 *
 * @param list<array{path: string, count: int}> $a
 * @param list<array{path: string, count: int}> $b
 * @return list<array{path: string, count: int}>
 */
function hbHubWatchdogMergeAddresses(array $a, array $b): array
{
    $counts = [];
    foreach ([...$a, ...$b] as $address) {
        if (is_array($address) && is_string($address['path'] ?? null) && is_int($address['count'] ?? null)) {
            $counts[$address['path']] = min(HBHUB_WATCHDOG_MAX_COUNT, ($counts[$address['path']] ?? 0) + $address['count']);
        }
    }
    arsort($counts);
    $list = [];
    foreach (array_slice($counts, 0, HBHUB_WATCHDOG_MAX_ADDRESSES, true) as $path => $count) {
        $list[] = ['path' => (string) $path, 'count' => $count];
    }
    return $list;
}

/**
 * Saves one report from watchdog.js. $userId is who's logged in (null for visitors), $count how many 508s (already a
 * whole number, capped here), $page the path they were on, $addresses from hbHubWatchdogAddresses(), and
 * $firstAgo/$lastAgo how many milliseconds ago the first and last of them happened (the browser's clock is never used,
 * so a wrong clock doesn't matter). The IP address and user agent are this request's.
 *
 * @param list<array{path: string, count: int}> $addresses
 */
function hbHubWatchdogSave(PDO $db, ?int $userId, int $count, ?string $page, array $addresses, int $firstAgo, int $lastAgo): void
{
    $count = max(1, min(HBHUB_WATCHDOG_MAX_COUNT, $count));
    $firstAgo = intdiv(max(0, min($firstAgo, HBHUB_WATCHDOG_MAX_AGE_SECONDS * 1000)), 1000);
    $lastAgo = min($firstAgo, intdiv(max(0, min($lastAgo, HBHUB_WATCHDOG_MAX_AGE_SECONDS * 1000)), 1000));
    [$ipv4, $ipv6] = hbHubClientIp();
    $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) && $_SERVER['HTTP_USER_AGENT'] !== ''
        ? mb_substr(mb_scrub($_SERVER['HTTP_USER_AGENT'], 'UTF-8'), 0, 255) : null;

    $db->beginTransaction();
    try {
        // Over the limit for this address: added to its newest row of the last hour instead (see the top)
        $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Watchdog`
            WHERE watchdogCreatedTimestamp > NOW() - INTERVAL 1 HOUR AND watchdogIpv4 <=> ? AND watchdogIpv6 <=> ?');
        $statement->execute([$ipv4, $ipv6]);
        if ((int) $statement->fetchColumn() >= HBHUB_WATCHDOG_ROWS_PER_HOUR) {
            $statement = $db->prepare('SELECT watchdogId, watchdogAddresses FROM `HBHub-Watchdog`
                WHERE watchdogCreatedTimestamp > NOW() - INTERVAL 1 HOUR AND watchdogIpv4 <=> ? AND watchdogIpv6 <=> ?
                ORDER BY watchdogId DESC LIMIT 1 FOR UPDATE');
            $statement->execute([$ipv4, $ipv6]);
            $newest = $statement->fetch();
            if ($newest !== false) {
                $merged = hbHubWatchdogMergeAddresses(json_decode($newest['watchdogAddresses'], true) ?: [], $addresses);
                $db->prepare('UPDATE `HBHub-Watchdog` SET watchdogCount = LEAST(watchdogCount + ?, 4294967295), watchdogAddresses = ?,
                        watchdogLastTimestamp = GREATEST(watchdogLastTimestamp, NOW() - INTERVAL ? SECOND)
                    WHERE watchdogId = ?')
                    ->execute([$count, json_encode($merged), $lastAgo, $newest['watchdogId']]);
                $db->commit();
                return;
            }
        }

        $db->prepare('INSERT INTO `HBHub-Watchdog` (watchdogUserId, watchdogCount, watchdogPage, watchdogAddresses, watchdogIpv4,
                watchdogIpv6, watchdogUserAgent, watchdogFromDev, watchdogFirstTimestamp, watchdogLastTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? SECOND, NOW() - INTERVAL ? SECOND)')
            ->execute([$userId, $count, $page, json_encode($addresses), $ipv4, $ipv6, $userAgent, hbHubFeedbackIsDev() ? 1 : 0,
                $firstAgo, $lastAgo]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    hbHubWatchdogSweep($db);
}

/**
 * Deletes every row, from the admin panel's Clear the log button. Returns how many went.
 */
function hbHubWatchdogClear(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-Watchdog`');
}

/**
 * Deletes the rows older than HBHUB_WATCHDOG_KEEP_DAYS: after each new one, and in the nightly clean-up
 * (cron/nightly.php). Returns how many went.
 */
function hbHubWatchdogSweep(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-Watchdog`
        WHERE watchdogCreatedTimestamp < NOW() - INTERVAL ' . HBHUB_WATCHDOG_KEEP_DAYS . ' DAY');
}
