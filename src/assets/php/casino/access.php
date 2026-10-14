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
 * settings), "closed" (not open to the user) or "forbidden" (a POST from another site) and stops when it can't go on.
 */
function hbHubCasinoJson(array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $error = match (true) {
        hbHubBlockIsActive(hbHubBlockSettings(hbHubUserSettings($user['userSettings'] ?? null))) => 'blocked',
        !hbHubCasinoIsOpenTo($user) => 'closed',
        $_SERVER['REQUEST_METHOD'] === 'POST' && !hbHubCasinoIsSameSite() => 'forbidden',
        default => null,
    };

    if ($error !== null) {
        http_response_code(403);
        echo json_encode(['error' => $error]);
        exit;
    }
}

/**
 * Whether the request came from the site's own pages. The session cookie is SameSite=Lax, so other sites can't send
 * it with a POST anyway - this is just a second lock on the door, as POSTs here spend chips.
 */
function hbHubCasinoIsSameSite(): bool
{
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($fetchSite !== null) {
        return $fetchSite === 'same-origin';
    }
    // Older browsers without Sec-Fetch-Site still send Origin with a POST
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    return $origin === null || $origin === 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
}
