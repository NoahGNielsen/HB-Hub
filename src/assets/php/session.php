<?php
// Login sessions: a row in HBHub-Sessions plus a cookie holding its id.
// Shared by onboarding (new account) and login.

const HBHUB_SESSION_COOKIE = 'hbHubSession';
const HBHUB_SESSION_DAYS = 30;

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
