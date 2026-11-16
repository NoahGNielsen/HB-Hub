<?php
// The Watchdog: keeps track of the errors that happen in people's browsers on the site, so site admins can see what
// goes wrong, when, where and for whom. It started out as only the "508 Resource Limit Is Reached" answers (the hosting
// only runs about 10 PHP requests at the same time for the whole site, and every request over that gets a 508 instead,
// see assets/js/pictureQueue.js), and those are still counted on their own on the admin panel.
//
// assets/js/watchdog.js (on every page, header.php) notes every error the page gets, as one of HBHUB_WATCHDOG_KINDS:
// answers of 400 or more ('http', with the status), requests with no answer ('network'), pictures/scripts/styles that
// didn't load ('load'), uncaught JavaScript errors ('script'), uncaught rejected promises ('promise') and what the
// Content-Security-Policy blocked ('csp'). An endpoint's answer that's a normal part of how things work (Wordle's "not a
// word", a stopped Matador game) sends the header "X-HBHub-Expected: 1" and isn't counted. 3 seconds after the first one it sends them to /watchdog
// (otherPages/watchdog.php), and when that doesn't go through (it's a PHP request too, so it often gets a 508 itself) it
// tries again every 3 seconds until it does. hbHubWatchdogSave() saves it here. Site admins read it under Browser errors
// in the admin panel's side menu (admin/watchdog.php).
//
// Each report is a row in HBHub-Watchdog: who (NULL when not logged in), how many errors, the path of the page they were
// on, which errors and how often (watchdogErrors: kind, address, HTTP status, message, line; addresses as paths, never
// the query, so no chat ids or search words), their IP address and user agent, whether it came from the dev site, and
// when the first and last of them happened and when the report got through, and whether an admin has cleared it off the
// list (it still counts in the totals). Rows from before every kind was counted have entries of only {path, count}:
// those are 508s ('http', status 508), and everything that reads them treats a missing kind/status that way. Rows go
// after HBHUB_WATCHDOG_KEEP_DAYS (after each new one and in the nightly clean-up, cron/nightly.php), and with the
// account (account.php).
//
// Anyone can send one, also visitors who aren't logged in (the free games have errors too), so nothing in it is trusted:
// the numbers are capped, the addresses and lines checked, messages cut short, and one IP address only gets
// HBHUB_WATCHDOG_ROWS_PER_HOUR new rows an hour. After that its reports are added to its newest row instead, so they're
// still counted but can't fill the table.
// hbHubWatchdogSave() needs session.php (hbHubClientIp()), which every page and endpoint has loaded first anyway.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/feedback.php'; // hbHubFeedbackIsDev()

const HBHUB_WATCHDOG_KEEP_DAYS = 30;
const HBHUB_WATCHDOG_MAX_COUNT = 100000; // errors in one report, far more than a page can get in the time it waits
const HBHUB_WATCHDOG_MAX_ERRORS = 20; // different errors kept in one report (watchdog.js sends at most this many)
const HBHUB_WATCHDOG_MAX_MESSAGE = 300; // characters of an error's message (watchdog.js cuts them to this too)
const HBHUB_WATCHDOG_MAX_AGE_SECONDS = 7 * 86400; // a report that waited longer than this (a tab left open) counts as this old
const HBHUB_WATCHDOG_ROWS_PER_HOUR = 60; // new rows per IP address, see the top
const HBHUB_WATCHDOG_BUSY_STATUS = 508; // "Resource Limit Is Reached": the server was busy (the old rows' only kind)
// The kinds of error (`kind` in watchdogErrors), with what the admin panel calls them
const HBHUB_WATCHDOG_KINDS = [
    'http' => 'HTTP error',
    'network' => 'No answer',
    'load' => "Didn't load",
    'script' => 'JavaScript error',
    'promise' => 'Uncaught promise',
    'csp' => 'Blocked by CSP',
];

/**
 * A path as it's kept for the page the user was on: only when it's plain enough to show and link to on the admin panel
 * (letters, digits and / . _ ~ -, like feedback's hbHubFeedbackPage()), or null. The query is never part of it.
 */
function hbHubWatchdogPath(mixed $path): ?string
{
    return is_string($path) && preg_match('~^/[A-Za-z0-9/._\~-]{0,254}$~', $path) === 1 ? $path : null;
}

/**
 * An error's address as it's kept: a path on this site (/chat/, percent-escapes allowed), or another site's origin and
 * path (https://media.giphy.com/media/x/giphy.gif), never with a query, or null. It's only ever shown as text.
 */
function hbHubWatchdogAddress(mixed $address): ?string
{
    if (!is_string($address) || strlen($address) > 255) {
        return null;
    }
    return preg_match('~^(https?://[A-Za-z0-9.-]{1,253}(:\d{1,5})?)?/[A-Za-z0-9/._\~%+@!$&\'()*,;=:-]*$~', $address) === 1
        ? $address : null;
}

/**
 * One error as it's kept, from a report's entry (or a row's), or null when it isn't one: ['kind', 'path', 'status',
 * 'message', 'line', 'count']. An entry without a kind is an old row's 508 (see the top). status is only for 'http'
 * (400-599), line is "120:5", message one line of at most HBHUB_WATCHDOG_MAX_MESSAGE characters.
 *
 * @return array{kind: string, path: ?string, status: ?int, message: ?string, line: ?string, count: int}|null
 */
function hbHubWatchdogError(mixed $entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }
    $kind = $entry['kind'] ?? 'http';
    $count = $entry['count'] ?? null;
    if (!is_string($kind) || !isset(HBHUB_WATCHDOG_KINDS[$kind]) || !is_int($count) || $count < 1) {
        return null;
    }
    $status = null;
    if ($kind === 'http') {
        $status = array_key_exists('kind', $entry) ? ($entry['status'] ?? null) : HBHUB_WATCHDOG_BUSY_STATUS;
        if (!is_int($status) || $status < 400 || $status > 599) {
            return null;
        }
    }
    $message = $entry['message'] ?? null;
    if (is_string($message)) {
        $message = trim(preg_replace('~[\p{C}\s]+~u', ' ', mb_scrub($message, 'UTF-8')) ?? '');
        $message = $message !== '' ? mb_substr($message, 0, HBHUB_WATCHDOG_MAX_MESSAGE) : null;
    } else {
        $message = null;
    }
    $line = $entry['line'] ?? null;
    return [
        'kind' => $kind,
        'path' => hbHubWatchdogAddress($entry['path'] ?? null),
        'status' => $status,
        'message' => $message,
        'line' => is_string($line) && preg_match('~^\d{1,7}:\d{1,7}$~', $line) === 1 ? $line : null,
        'count' => min(HBHUB_WATCHDOG_MAX_COUNT, $count),
    ];
}

/**
 * Adds lists of errors together (the same kind, address, status, message and line is one error with the counts added),
 * keeping the most common HBHUB_WATCHDOG_MAX_ERRORS, the most first. Anything that isn't an error (hbHubWatchdogError())
 * is left out. Used for a new report and when a report is added to an older row.
 *
 * @param list<mixed> ...$lists
 * @return list<array{kind: string, path: ?string, status: ?int, message: ?string, line: ?string, count: int}>
 */
function hbHubWatchdogMergeErrors(array ...$lists): array
{
    $errors = [];
    foreach ($lists as $list) {
        foreach ($list as $entry) {
            $error = hbHubWatchdogError($entry);
            if ($error === null) {
                continue;
            }
            $key = implode('|', [$error['kind'], $error['path'], $error['status'], $error['message'], $error['line']]);
            if (isset($errors[$key])) {
                $errors[$key]['count'] = min(HBHUB_WATCHDOG_MAX_COUNT, $errors[$key]['count'] + $error['count']);
            } else {
                $errors[$key] = $error;
            }
        }
    }
    $errors = array_values($errors);
    usort($errors, fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return array_slice($errors, 0, HBHUB_WATCHDOG_MAX_ERRORS);
}

/**
 * The errors from a report: its `errors` field (JSON list of {kind, path, status, message, line, count}), or, from a
 * tab that still has the older watchdog.js, its `addresses` field (JSON {"/path": count}, all 508s). As they're kept,
 * see hbHubWatchdogMergeErrors().
 *
 * @return list<array{kind: string, path: ?string, status: ?int, message: ?string, line: ?string, count: int}>
 */
function hbHubWatchdogErrors(mixed $errorsJson, mixed $addressesJson = null): array
{
    if (is_string($errorsJson)) {
        $errors = strlen($errorsJson) <= 50000 ? json_decode($errorsJson, true, 4) : null;
        return is_array($errors) && array_is_list($errors) ? hbHubWatchdogMergeErrors($errors) : [];
    }
    $addresses = is_string($addressesJson) && strlen($addressesJson) <= 20000 ? json_decode($addressesJson, true, 4) : null;
    if (!is_array($addresses)) {
        return [];
    }
    $errors = [];
    foreach ($addresses as $path => $count) {
        $errors[] = ['path' => (string) $path, 'count' => $count]; // no kind: a 508, see hbHubWatchdogError()
    }
    return hbHubWatchdogMergeErrors($errors);
}

/**
 * What an error is called on the admin panel: "HTTP 404", "JavaScript error", ...
 *
 * @param array{kind: string, status: ?int} $error from hbHubWatchdogError()
 */
function hbHubWatchdogLabel(array $error): string
{
    return $error['kind'] === 'http' ? 'HTTP ' . $error['status'] : HBHUB_WATCHDOG_KINDS[$error['kind']];
}

/**
 * Saves one report from watchdog.js. $userId is who's logged in (null for visitors), $count how many errors (already a
 * whole number, capped here), $page the path they were on, $errors from hbHubWatchdogErrors(), and $firstAgo/$lastAgo
 * how many milliseconds ago the first and last of them happened (the browser's clock is never used, so a wrong clock
 * doesn't matter). The IP address and user agent are this request's.
 *
 * @param list<array{kind: string, path: ?string, status: ?int, message: ?string, line: ?string, count: int}> $errors
 */
function hbHubWatchdogSave(PDO $db, ?int $userId, int $count, ?string $page, array $errors, int $firstAgo, int $lastAgo): void
{
    $count = max(1, min(HBHUB_WATCHDOG_MAX_COUNT, $count));
    $firstAgo = intdiv(max(0, min($firstAgo, HBHUB_WATCHDOG_MAX_AGE_SECONDS * 1000)), 1000);
    $lastAgo = min($firstAgo, intdiv(max(0, min($lastAgo, HBHUB_WATCHDOG_MAX_AGE_SECONDS * 1000)), 1000));
    [$ipv4, $ipv6] = hbHubClientIp();
    $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) && $_SERVER['HTTP_USER_AGENT'] !== ''
        ? mb_substr(mb_scrub($_SERVER['HTTP_USER_AGENT'], 'UTF-8'), 0, 255) : null;
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    $db->beginTransaction();
    try {
        // Over the limit for this address: added to its newest row of the last hour instead (see the top). Never to one
        // an admin has cleared, or it wouldn't show in the list; with none left, it gets a new row after all
        $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Watchdog`
            WHERE watchdogCreatedTimestamp > NOW() - INTERVAL 1 HOUR AND watchdogIpv4 <=> ? AND watchdogIpv6 <=> ?');
        $statement->execute([$ipv4, $ipv6]);
        if ((int) $statement->fetchColumn() >= HBHUB_WATCHDOG_ROWS_PER_HOUR) {
            $statement = $db->prepare('SELECT watchdogId, watchdogErrors FROM `HBHub-Watchdog`
                WHERE watchdogCreatedTimestamp > NOW() - INTERVAL 1 HOUR AND watchdogIpv4 <=> ? AND watchdogIpv6 <=> ?
                    AND watchdogCleared = 0
                ORDER BY watchdogId DESC LIMIT 1 FOR UPDATE');
            $statement->execute([$ipv4, $ipv6]);
            $newest = $statement->fetch();
            if ($newest !== false) {
                $old = json_decode($newest['watchdogErrors'], true);
                $merged = hbHubWatchdogMergeErrors(is_array($old) && array_is_list($old) ? $old : [], $errors);
                $db->prepare('UPDATE `HBHub-Watchdog` SET watchdogCount = LEAST(watchdogCount + ?, 4294967295), watchdogErrors = ?,
                        watchdogLastTimestamp = GREATEST(watchdogLastTimestamp, NOW() - INTERVAL ? SECOND)
                    WHERE watchdogId = ?')
                    ->execute([$count, json_encode($merged, $jsonFlags), $lastAgo, $newest['watchdogId']]);
                $db->commit();
                return;
            }
        }

        $db->prepare('INSERT INTO `HBHub-Watchdog` (watchdogUserId, watchdogCount, watchdogPage, watchdogErrors, watchdogIpv4,
                watchdogIpv6, watchdogUserAgent, watchdogFromDev, watchdogFirstTimestamp, watchdogLastTimestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? SECOND, NOW() - INTERVAL ? SECOND)')
            ->execute([$userId, $count, $page, json_encode($errors, $jsonFlags), $ipv4, $ipv6, $userAgent, hbHubFeedbackIsDev() ? 1 : 0,
                $firstAgo, $lastAgo]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    hbHubWatchdogSweep($db);
}

/**
 * Takes every report off the admin panel's list of reports, from its Clear the reports button. The rows stay (marked
 * watchdogCleared), so the numbers at the top (last hour ... last HBHUB_WATCHDOG_KEEP_DAYS days) still count them, and
 * go by themselves with hbHubWatchdogSweep() like the rest. Returns how many were cleared.
 */
function hbHubWatchdogClear(PDO $db): int
{
    return $db->exec('UPDATE `HBHub-Watchdog` SET watchdogCleared = 1 WHERE watchdogCleared = 0');
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
