<?php
// Login sessions: a row in HBHub-Sessions plus a cookie holding its id.
// Shared by onboarding (new account) and login.
//
// Every page includes this with require_once at the very top, before any output. It turns away POSTs from other
// sites, checks the session cookie, updates the user's last seen time and IP (and the session's, for the list of
// logged in devices in settings), and sends visitors without a valid session to the login page.
// Pages that work without being logged in set $hbHubSessionOptional = true before including it.
// The logged in user ends up in $hbHubUser (null when not logged in).
require_once __DIR__ . '/maintenance.php'; // so the database isn't touched while the site is in maintenance mode
require_once __DIR__ . '/database.php';

const HBHUB_SESSION_COOKIE = 'hbHubSession';
const HBHUB_SESSION_DAYS = 30;
const HBHUB_SESSION_STATUS_BANNED = 4; // userStatus in HBHub-Users
const HBHUB_DELETED_USER_NAME = 'Deleted User'; // userName of a deleted account, so nobody else can choose it
// Set in a browser once a banned account is used in it, so onboarding doesn't make a new account there.
// Holds the banned account's id, so logging in to that account again (after it's unbanned) takes it away.
const HBHUB_BANNED_COOKIE = 'hbHubBanned';
const HBHUB_BANNED_COOKIE_DAYS = 400; // the longest browsers keep a cookie

const HBHUB_SESSION_LAST_SEEN_MINUTES = 1; // how often sessionLastSeen is written, so polling doesn't write on every request

/**
 * Stores a new session for the user and returns its id. Pass it to hbHubSetSessionCookie() once
 * the surrounding transaction (if any) is committed.
 */
function hbHubCreateSession(PDO $db, int $userId): string
{
    $sessionId = bin2hex(random_bytes(16)); // 32 characters, matches the CHAR(32) column

    $db->prepare('INSERT INTO `HBHub-Sessions` (sessionId, userId, sessionValidUntil, sessionDevice, sessionLastSeen)
        VALUES (?, ?, NOW() + INTERVAL ' . HBHUB_SESSION_DAYS . ' DAY, ?, NOW())')
        ->execute([$sessionId, $userId, hbHubSessionDevice()]);

    return $sessionId;
}

/**
 * A short name for the browser and system the request came from, like "Chrome on Windows", for the list of logged in
 * devices on the settings page. Only this name is stored (sessionDevice), never the whole user agent.
 */
function hbHubSessionDevice(): string
{
    $agent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '';

    // Order matters: Edge, Opera and Samsung Internet say "Chrome" too, and Chrome says "Safari"
    $browser = match (true) {
        str_contains($agent, 'Edg/') || str_contains($agent, 'EdgiOS/') || str_contains($agent, 'EdgA/') => 'Edge',
        str_contains($agent, 'OPR/') || str_contains($agent, 'OPiOS/') => 'Opera',
        str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
        str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
        str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
        str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
        default => null,
    };
    // iPhone and iPad before Mac, as they say "like Mac OS X". Android before Linux, as it says "Linux" too.
    $system = match (true) {
        str_contains($agent, 'iPhone') || str_contains($agent, 'iPod') => 'iPhone',
        str_contains($agent, 'iPad') => 'iPad',
        str_contains($agent, 'Android') => 'Android',
        str_contains($agent, 'Windows') => 'Windows',
        str_contains($agent, 'CrOS') => 'ChromeOS',
        str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X') => 'Mac',
        str_contains($agent, 'Linux') => 'Linux',
        default => null,
    };

    return match (true) {
        $browser !== null && $system !== null => "$browser on $system",
        $browser !== null => $browser,
        $system !== null => $system,
        default => 'Unknown device',
    };
}

function hbHubSetSessionCookie(string $sessionId): void
{
    setcookie(HBHUB_SESSION_COOKIE, $sessionId, [
        'expires' => time() + HBHUB_SESSION_DAYS * 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Removes the session cookie from the browser, when logging out or deleting the account.
 */
function hbHubClearSessionCookie(): void
{
    setcookie(HBHUB_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Marks the browser as one a banned account was used in (HBHUB_BANNED_COOKIE).
 */
function hbHubSetBannedCookie(int $userId): void
{
    setcookie(HBHUB_BANNED_COOKIE, (string) $userId, [
        'expires' => time() + HBHUB_BANNED_COOKIE_DAYS * 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function hbHubClearBannedCookie(): void
{
    setcookie(HBHUB_BANNED_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * The banned account's id from HBHUB_BANNED_COOKIE, or null when the browser isn't marked.
 */
function hbHubBannedCookieUserId(): ?int
{
    $userId = $_COOKIE[HBHUB_BANNED_COOKIE] ?? null;
    return is_string($userId) && ctype_digit($userId) && strlen($userId) <= 10 ? (int) $userId : null;
}

/**
 * Whether the name is the one deleted accounts get (in any capitalisation), so it can't be chosen.
 */
function hbHubIsDeletedUserName(string $name): bool
{
    return mb_strtolower($name) === mb_strtolower(HBHUB_DELETED_USER_NAME);
}

/**
 * The user behind the session cookie, or null when there's no valid session (missing, expired or banned).
 * Also updates the user's last seen time and IP, so it's only called once per request (at the bottom of this file).
 * Banning keeps the user's sessions, so the first visit after it can mark the browser (hbHubSetBannedCookie())
 * before the session is deleted.
 *
 * @return ?array{userId: int, userName: string, userRole: int, userAvatarAttachmentId: ?int, userSettings: ?string}
 */
function hbHubSessionUser(): ?array
{
    $sessionId = $_COOKIE[HBHUB_SESSION_COOKIE] ?? null;
    // Don't bother the database with cookies that can't be one of our ids
    if (!is_string($sessionId) || strlen($sessionId) !== 32 || !ctype_xdigit($sessionId)) {
        return null;
    }

    [$ipv4, $ipv6] = hbHubClientIp();

    try {
        $db = hbHubDatabase();

        // A login still waiting for its 2FA code (sessionTotpPending) doesn't count until the code is entered.
        // A deleted account's userStatus is NULL.
        $statement = $db->prepare('SELECT u.userId, u.userName, u.userRole, u.userAvatarAttachmentId, u.userSettings, u.userStatus,
                COALESCE(s.sessionLastSeen < NOW() - INTERVAL ' . HBHUB_SESSION_LAST_SEEN_MINUTES . ' MINUTE, 1) AS sessionIsStale
            FROM `HBHub-Sessions` s JOIN `HBHub-Users` u ON u.userId = s.userId
            WHERE s.sessionId = ? AND s.sessionValidUntil > NOW() AND s.sessionTotpPending = 0 AND u.userStatus IS NOT NULL');
        $statement->execute([$sessionId]);
        $user = $statement->fetch();

        if ($user === false) {
            return null;
        }
        if ((int) $user['userStatus'] === HBHUB_SESSION_STATUS_BANNED) {
            $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$sessionId]);
            hbHubSetBannedCookie((int) $user['userId']);
            return null;
        }
        // When this device was last used, for the settings page. The device name is refreshed too, in case the
        // browser or system changed (or the session is from before device names were saved).
        if ($user['sessionIsStale']) {
            $db->prepare('UPDATE `HBHub-Sessions` SET sessionLastSeen = NOW(), sessionDevice = ? WHERE sessionId = ?')
                ->execute([hbHubSessionDevice(), $sessionId]);
        }
        unset($user['userStatus'], $user['sessionIsStale']);

        $db->prepare('UPDATE `HBHub-Users` SET userLastSeenTimestamp = NOW(),
                userIpv4AdresseLastAccessed = ?, userIpv6AdresseLastAccessed = ? WHERE userId = ?')
            ->execute([$ipv4, $ipv6, $user['userId']]);
    } catch (PDOException $e) {
        error_log('Session: could not check the session: ' . $e->getMessage());
        return null;
    }

    return $user;
}

/**
 * The visitor's address split into the IPv4/IPv6 columns of HBHub-Users.
 * A connection only has one address, so the other one is null.
 *
 * @return array{0: ?string, 1: ?string} [ipv4, ipv6]
 */
function hbHubClientIp(): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return [
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null,
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $ip : null,
    ];
}

const HBHUB_RETURN_DEFAULT = '/chat/'; // where logging in / signing up from the homepage leads

/**
 * Where to go after logging in: the ?return= path the login redirect added, or the chat when it's missing, points off
 * the site or is the homepage (whose Log in / Sign up buttons don't add one) - there's nothing to do there once
 * logged in.
 */
function hbHubReturnPath(): string
{
    $path = $_GET['return'] ?? null;
    // One leading slash only - "//host" and "/\host" are treated by browsers as another site
    if (!is_string($path) || preg_match('#^/(?![/\\\\])[^\p{Cc}]*$#u', $path) !== 1) {
        return HBHUB_RETURN_DEFAULT;
    }
    // "/", "/index" or "/index.php", with or without a query or #fragment
    if (preg_match('#^/(index(\.php)?)?([?\#].*)?$#', $path) === 1) {
        return HBHUB_RETURN_DEFAULT;
    }
    return $path;
}

/**
 * "?return=..." for passing the return path on to the next login/onboarding URL, or '' when it's just the default.
 */
function hbHubReturnQuery(): string
{
    $path = hbHubReturnPath();
    return $path === HBHUB_RETURN_DEFAULT ? '' : '?return=' . rawurlencode($path);
}

/**
 * Whether the request came from the site's own pages (or the user themself, like reloading a form they sent).
 * SameSite=Lax on the session cookie isn't enough on its own: every other subdomain of noahgajnielsen.dk counts as
 * the same site, so a page there could still post a form here with the user's session.
 */
function hbHubIsSameOriginRequest(): bool
{
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($fetchSite !== null) {
        return $fetchSite === 'same-origin' || $fetchSite === 'none';
    }
    // Older browsers without Sec-Fetch-Site still send Origin with a POST
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    return $origin === null || $origin === 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
}

// Every form and script on the site posts to the site itself, so a POST from anywhere else is turned away
// before it can change anything
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hbHubIsSameOriginRequest()) {
    require __DIR__ . '/statusCodesPages/403.php';
    exit;
}

$hbHubUser = hbHubSessionUser();

if ($hbHubUser === null && empty($hbHubSessionOptional)) {
    // Remember the page, so login can send them back to it
    header('Location: /userMgmt/login?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
    exit;
}
