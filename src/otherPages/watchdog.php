<?php
// Where assets/js/watchdog.js sends how many "508 Resource Limit Is Reached" answers the page got (assets/php/watchdog.php
// says what the Watchdog is and what's kept). Open to visitors who aren't logged in too, since every page has the
// script; who's logged in, the IP address and the user agent come from this request, not from the form.
//
// POST /watchdog (form-encoded): action=report, count (how many), page (the path the user was on), addresses (JSON,
// {"/path": count, ...}), firstAgo and lastAgo (how many milliseconds ago the first and last one happened).
// Answers {"ok": true}. Errors:
// - 405 {"error": "methodNotAllowed"}: not a POST.
// - 400 {"error": "badRequest"}: no action=report, or count isn't a whole number above 0. watchdog.js gives up on
//   that report instead of trying again, since it would never go through.
// Anything else (a 508 for this request too, mostly) makes watchdog.js try again 3 seconds later.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/watchdog.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

/**
 * A whole number of at most 12 digits from the form, or null.
 */
function watchdogNumber(mixed $value): ?int
{
    return is_string($value) && $value !== '' && strlen($value) <= 12 && ctype_digit($value) ? (int) $value : null;
}

$count = watchdogNumber($_POST['count'] ?? null);
if (($_POST['action'] ?? null) !== 'report' || $count === null || $count < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

hbHubWatchdogSave(
    hbHubDatabase(),
    $hbHubUser !== null ? (int) $hbHubUser['userId'] : null,
    $count,
    hbHubWatchdogPath($_POST['page'] ?? null),
    hbHubWatchdogAddresses($_POST['addresses'] ?? null),
    watchdogNumber($_POST['firstAgo'] ?? null) ?? 0,
    watchdogNumber($_POST['lastAgo'] ?? null) ?? 0,
);
echo json_encode(['ok' => true]);
