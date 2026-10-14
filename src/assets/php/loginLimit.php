<?php
// Limits on guessing a password or a 2FA code: at the login, and on the settings page, where changing the password,
// turning off two-factor login and deleting the account ask for one too.
//
// After login_attempts_max wrong ones in a row, guessing is locked for login_attempts_lockout_time minutes. Which lock
// depends on the browser:
// - A browser that has logged in to the account before is a known device for it (the hbHubDevice cookie, with its hash
//   in HBHub-LoginDevices). It has its own count and lock, so only that browser is locked.
// - Every other browser shares the account's count and lock (userFailedLoginCount and userAccountLockedTimestamp).
// So guessing someone's password from another browser only locks out other new browsers, and the owner can still log
// in on their own phone or computer. Otherwise anyone who knows a name could keep its owner locked out.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

const HBHUB_DEVICE_COOKIE = 'hbHubDevice';
const HBHUB_DEVICE_DAYS = 180; // not logged in to the account from the browser for this long, it's a new device again
const HBHUB_LOGIN_STATUS_LOCKED = 3; // userStatus in HBHub-Users

/**
 * The login_attempts_* settings, with the defaults used when an older config file doesn't have them yet.
 *
 * @return array{0: int, 1: int} [attemptsMax, lockoutMinutes]
 */
function hbHubLoginLimits(array $siteConfig): array
{
    return [
        max(1, (int) ($siteConfig['login_attempts_max'] ?? 5)),
        max(1, (int) ($siteConfig['login_attempts_lockout_time'] ?? 15)),
    ];
}

/**
 * The browser's device token from its cookie, or null when it doesn't have one (or it can't be one of ours).
 */
function hbHubDeviceToken(): ?string
{
    $token = $_COOKIE[HBHUB_DEVICE_COOKIE] ?? null;
    return is_string($token) && strlen($token) === 32 && ctype_xdigit($token) ? $token : null;
}

/**
 * The browser's deviceTokenHash when it's a known device for the account, otherwise null.
 */
function hbHubKnownDevice(PDO $db, int $userId): ?string
{
    $token = hbHubDeviceToken();
    if ($token === null) {
        return null;
    }

    $hash = hash('sha256', $token);
    $statement = $db->prepare('SELECT 1 FROM `HBHub-LoginDevices` WHERE deviceTokenHash = ? AND userId = ?
        AND deviceLastUsedTimestamp > NOW() - INTERVAL ' . HBHUB_DEVICE_DAYS . ' DAY');
    $statement->execute([$hash, $userId]);
    return $statement->fetchColumn() !== false ? $hash : null;
}

/**
 * Makes the browser a known device for the account, once the password (and 2FA code) were right, or keeps it one
 * for longer. Its wrong tries so far are forgotten and its lock lifted. Run inside the login's transaction: if that's
 * rolled back, the cookie just holds a token that isn't known for anything.
 */
function hbHubLoginTrustDevice(PDO $db, int $userId): void
{
    $token = hbHubDeviceToken() ?? bin2hex(random_bytes(16)); // one token per browser, known for every account used in it

    $db->prepare('INSERT INTO `HBHub-LoginDevices` (deviceTokenHash, userId) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE deviceLastUsedTimestamp = NOW(), deviceFailedLoginCount = 0, deviceLockedTimestamp = NULL')
        ->execute([hash('sha256', $token), $userId]);
    // The account's devices that haven't been used for too long aren't known anymore, so they can go
    $db->prepare('DELETE FROM `HBHub-LoginDevices` WHERE userId = ? AND deviceLastUsedTimestamp <= NOW() - INTERVAL '
        . HBHUB_DEVICE_DAYS . ' DAY')
        ->execute([$userId]);

    setcookie(HBHUB_DEVICE_COOKIE, $token, [
        'expires' => time() + HBHUB_DEVICE_DAYS * 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * How many minutes are left of the lock the browser is under for the account, 0 when it isn't locked.
 */
function hbHubLoginLockMinutesLeft(PDO $db, int $userId, int $lockoutMinutes): int
{
    // NULL when it has never been locked, 0 or less once the lock has run out
    $device = hbHubKnownDevice($db, $userId);
    if ($device !== null) {
        $statement = $db->prepare('SELECT CEIL(TIMESTAMPDIFF(SECOND, NOW(), deviceLockedTimestamp + INTERVAL ? MINUTE) / 60)
            FROM `HBHub-LoginDevices` WHERE deviceTokenHash = ? AND userId = ?');
        $statement->execute([$lockoutMinutes, $device, $userId]);
    } else {
        $statement = $db->prepare('SELECT CEIL(TIMESTAMPDIFF(SECOND, NOW(), userAccountLockedTimestamp + INTERVAL ? MINUTE) / 60)
            FROM `HBHub-Users` WHERE userId = ?');
        $statement->execute([$lockoutMinutes, $userId]);
    }
    return max(0, (int) $statement->fetchColumn());
}

/**
 * Counts a wrong password or code from the browser. Returns true when that locked it out.
 * The count starts over once locked, so there are $attemptsMax new tries after the lock runs out.
 * The WHERE makes sure only one of several simultaneous wrong attempts does the locking.
 */
function hbHubLoginRecordFailure(PDO $db, int $userId, int $attemptsMax): bool
{
    $device = hbHubKnownDevice($db, $userId);
    if ($device !== null) {
        $db->prepare('UPDATE `HBHub-LoginDevices` SET deviceFailedLoginCount = LEAST(deviceFailedLoginCount + 1, 65535)
            WHERE deviceTokenHash = ? AND userId = ?')
            ->execute([$device, $userId]);
        $lock = $db->prepare('UPDATE `HBHub-LoginDevices` SET deviceFailedLoginCount = 0, deviceLockedTimestamp = NOW()
            WHERE deviceTokenHash = ? AND userId = ? AND deviceFailedLoginCount >= ?');
        $lock->execute([$device, $userId, $attemptsMax]);
        return $lock->rowCount() > 0;
    }

    $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = LEAST(userFailedLoginCount + 1, 65535) WHERE userId = ?')
        ->execute([$userId]);
    $lock = $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NOW(),
            userStatus = IF(userStatus = ' . HBHUB_SESSION_STATUS_BANNED . ', userStatus, ' . HBHUB_LOGIN_STATUS_LOCKED . ')
        WHERE userId = ? AND userFailedLoginCount >= ?');
    $lock->execute([$userId, $attemptsMax]);
    return $lock->rowCount() > 0;
}

/**
 * The right password or code from the browser, without logging in (the settings page): its wrong ones so far are forgotten.
 */
function hbHubLoginForgetFailures(PDO $db, int $userId): void
{
    $device = hbHubKnownDevice($db, $userId);
    if ($device !== null) {
        $db->prepare('UPDATE `HBHub-LoginDevices` SET deviceFailedLoginCount = 0 WHERE deviceTokenHash = ? AND userId = ?')
            ->execute([$device, $userId]);
    } else {
        $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0 WHERE userId = ?')->execute([$userId]);
    }
}

/**
 * Lifts every lock on the account, its known devices' too (when an admin resets the password).
 */
function hbHubLoginUnlockAll(PDO $db, int $userId): void
{
    $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NULL WHERE userId = ?')
        ->execute([$userId]);
    $db->prepare('UPDATE `HBHub-LoginDevices` SET deviceFailedLoginCount = 0, deviceLockedTimestamp = NULL WHERE userId = ?')
        ->execute([$userId]);
}

function hbHubLoginLockedMessage(int $minutes): string
{
    return 'This account is locked on this device. Please try again in ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.');
}
