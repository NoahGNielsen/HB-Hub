<?php
// Who can use the Games section right now. Every Games page and JSON endpoint calls one of these first, right after
// session.php, the same as the casino does (casino/access.php):
// - hbHubGamesPage() on pages: shows the "blocked" page (block.php) or the "coming soon" page instead, and stops
// - hbHubGamesJson() on the JSON endpoints the games' scripts call: answers 403 instead, and stops. A game left open
//   in a tab can't carry on once the user's block starts.
//
// games_access in the site config opens the games to everyone (1), only to admins while they're being tested (2, also
// when it's missing from the config), or to nobody (0).
require_once __DIR__ . '/../block.php';

const HBHUB_GAMES_ACCESS_NOBODY = 0;
const HBHUB_GAMES_ACCESS_EVERYONE = 1;
const HBHUB_GAMES_ACCESS_ADMINS = 2;
const HBHUB_GAMES_ROLE_ADMIN = 2; // userRole in HBHub-Users

function hbHubGamesIsOpenTo(array $user): bool
{
    static $access = null;
    if ($access === null) {
        $siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
        $access = (int) ($siteConfig['games_access'] ?? HBHUB_GAMES_ACCESS_ADMINS);
    }

    return match ($access) {
        HBHUB_GAMES_ACCESS_EVERYONE => true,
        HBHUB_GAMES_ACCESS_ADMINS => (int) ($user['userRole'] ?? 0) === HBHUB_GAMES_ROLE_ADMIN,
        default => false,
    };
}

/**
 * For Games pages: shows the "blocked" or "coming soon" page instead and stops, when the user can't use them now.
 */
function hbHubGamesPage(array $user): void
{
    hbHubBlockPage($user, 'Games');

    if (!hbHubGamesIsOpenTo($user)) {
        require __DIR__ . '/closedPage.php';
        exit;
    }
}

/**
 * For the games' JSON endpoints: sends the JSON headers, and answers 403 with {"error": "blocked"} (blocked in
 * settings or by a site admin) or "closed" (not open to the user) and stops when it can't go on.
 * A POST from another site never gets this far, session.php turns those away for the whole site.
 */
function hbHubGamesJson(array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $error = match (true) {
        hbHubBlockIsBlocked(hbHubUserSettings($user['userSettings'] ?? null)) => 'blocked',
        !hbHubGamesIsOpenTo($user) => 'closed',
        default => null,
    };

    if ($error !== null) {
        http_response_code(403);
        echo json_encode(['error' => $error]);
        exit;
    }
}
