<?php
// Who can use the casino (the Gambling pages) right now. Every casino page and JSON endpoint calls one of these first,
// right after session.php:
// - hbHubCasinoPage() on pages: shows the "blocked" page (block.php) or the "coming soon" page instead, and stops
// - hbHubCasinoJson() on the JSON endpoints the casino's scripts call: answers 403 instead, and stops. A table left open
//   in a tab can't carry on playing once the user's block starts.
//
// casino_access in the site config opens the casino to everyone (1), only to admins while it's being tested (2, also
// when it's missing from the config), or to nobody (0).
require_once __DIR__ . '/../block.php';

const HBHUB_CASINO_ACCESS_NOBODY = 0;
const HBHUB_CASINO_ACCESS_EVERYONE = 1;
const HBHUB_CASINO_ACCESS_ADMINS = 2;
const HBHUB_CASINO_ROLE_ADMIN = 2; // userRole in HBHub-Users

function hbHubCasinoIsOpenTo(array $user): bool
{
    static $access = null;
    if ($access === null) {
        $siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
        $access = (int) ($siteConfig['casino_access'] ?? HBHUB_CASINO_ACCESS_ADMINS);
    }

    return match ($access) {
        HBHUB_CASINO_ACCESS_EVERYONE => true,
        HBHUB_CASINO_ACCESS_ADMINS => (int) ($user['userRole'] ?? 0) === HBHUB_CASINO_ROLE_ADMIN,
        default => false,
    };
}

/**
 * Why the user can't use the casino right now: 'blocked' (blocked in settings or by a site admin) or 'closed' (not open
 * to them), or null when they can.
 */
function hbHubCasinoDenied(array $user): ?string
{
    return match (true) {
        hbHubBlockIsBlocked(hbHubUserSettings($user['userSettings'] ?? null)) => 'blocked',
        !hbHubCasinoIsOpenTo($user) => 'closed',
        default => null,
    };
}

/**
 * For casino pages: shows the "blocked" or "coming soon" page instead and stops, when the user can't use it now.
 */
function hbHubCasinoPage(array $user): void
{
    hbHubBlockPage($user, 'Gambling');

    if (!hbHubCasinoIsOpenTo($user)) {
        require __DIR__ . '/closedPage.php';
        exit;
    }
}

/**
 * For the casino's JSON endpoints: sends the JSON headers, and answers 403 with {"error": "blocked"} (blocked in
 * settings) or "closed" (not open to the user) and stops when it can't go on.
 * A POST from another site never gets this far, session.php turns those away for the whole site.
 */
function hbHubCasinoJson(array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $error = hbHubCasinoDenied($user);
    if ($error !== null) {
        http_response_code(403);
        echo json_encode(['error' => $error]);
        exit;
    }
}
