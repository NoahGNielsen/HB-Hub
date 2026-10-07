<?php
// Login sessions: a row in HBHub-Sessions plus a cookie holding its id.
// Shared by onboarding (new account) and login.
//
// Every page includes this with require_once at the very top, before any output. It checks the session cookie,
// updates the user's last seen time and IP, and sends visitors without a valid session to the login page.
// Pages that work without being logged in set $hbHubSessionOptional = true before including it.
// The logged in user ends up in $hbHubUser (null when not logged in).
require_once __DIR__ . '/maintenance.php'; // so the database isn't touched while the site is in maintenance mode
require_once __DIR__ . '/database.php';

const HBHUB_SESSION_COOKIE = 'hbHubSession';
const HBHUB_SESSION_DAYS = 30;
const HBHUB_SESSION_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * Stores a new session for the user and returns its id. Pass it to hbHubSetSessionCookie() once
 * the surrounding transaction (if any) is committed.
 */
function hbHubCreateSession(PDO $db, int $userId): string
{
    $sessionId = bin2hex(random_bytes(16)); // 32 characters, matches the CHAR(32) column

    $db->prepare('INSERT INTO `HBHub-Sessions` (sessionId, userId, sessionValidUntil)
        VALUES (?, ?, NOW() + INTERVAL ' . HBHUB_SESSION_DAYS . ' DAY)')
        ->execute([$sessionId, $userId]);

    return $sessionId;
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
 * The user behind the session cookie, or null when there's no valid session (missing, expired or banned).
 * Also updates the user's last seen time and IP, so it's only called once per request (at the bottom of this file).
 *
 * @return ?array{userId: int, userName: string, userRole: int, userAvatarAttachmentId: ?int}
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

        $statement = $db->prepare('SELECT u.userId, u.userName, u.userRole, u.userAvatarAttachmentId
            FROM `HBHub-Sessions` s JOIN `HBHub-Users` u ON u.userId = s.userId
            WHERE s.sessionId = ? AND s.sessionValidUntil > NOW() AND u.userStatus <> ?');
        $statement->execute([$sessionId, HBHUB_SESSION_STATUS_BANNED]);
        $user = $statement->fetch();

        if ($user === false) {
            return null;
        }

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

/**
 * Where to go after logging in: the ?return= path the login redirect added, or / when it's missing or points off the site.
 */
function hbHubReturnPath(): string
{
    $path = $_GET['return'] ?? null;
    // One leading slash only - "//host" and "/\host" are treated by browsers as another site
    if (!is_string($path) || preg_match('#^/(?![/\\\\])[^\p{Cc}]*$#u', $path) !== 1) {
        return '/';
    }
    return $path;
}

/**
 * "?return=..." for passing the return path on to the next login/onboarding URL, or '' when it's just /.
 */
function hbHubReturnQuery(): string
{
    $path = hbHubReturnPath();
    return $path === '/' ? '' : '?return=' . rawurlencode($path);
}

$hbHubUser = hbHubSessionUser();

if ($hbHubUser === null && empty($hbHubSessionOptional)) {
    // Remember the page, so login can send them back to it
    header('Location: /userMgmt/login?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
    exit;
}
