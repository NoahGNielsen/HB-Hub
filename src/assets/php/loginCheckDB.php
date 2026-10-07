<?php
// Checks a name and password against HBHub-Users and logs the user in when they match.
// With two-factor login turned on (userTotpSecret), the right password only starts the login: it's kept as a
// session waiting for the code (sessionTotpPending) for a few minutes, and loginCheckTotp() finishes it.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/totp.php';

// userStatus: 1 Online, 2 Offline, 3 Account Locked, 4 Banned
const LOGIN_STATUS_ONLINE = 1;
const LOGIN_STATUS_LOCKED = 3;
const LOGIN_STATUS_BANNED = 4;

const LOGIN_PENDING_COOKIE = 'hbHubPendingLogin'; // the session waiting for the 2FA code, never the real session cookie
const LOGIN_PENDING_MINUTES = 5; // how long there is to enter the code

/**
 * Returns null when the password was right, otherwise a message to show on the form.
 * $needsTotp is set to true when the user isn't logged in yet, because the 2FA code is needed next.
 * After $attemptsMax wrong passwords (or 2FA codes) in a row the account is locked for $lockoutMinutes.
 */
function loginCheckDB(string $name, string $password, int $attemptsMax, int $lockoutMinutes, ?bool &$needsTotp = null): ?string
{
    $needsTotp = false;
    $wrongMessage = 'Wrong name or password.';

    $db = null;
    try {
        $db = hbHubDatabase();

        // lockMinutesLeft is NULL when the account has never been locked, 0 or less once the lock has run out
        $statement = $db->prepare('SELECT userId, userPasswordHash, userStatus, userTotpSecret,
                CEIL(TIMESTAMPDIFF(SECOND, NOW(), userAccountLockedTimestamp + INTERVAL ? MINUTE) / 60) AS lockMinutesLeft
            FROM `HBHub-Users` WHERE userNameLower = ?');
        $statement->execute([$lockoutMinutes, mb_strtolower($name)]);
        $user = $statement->fetch();

        if ($user === false) {
            // Hash anyway, so an unknown name takes as long as a wrong password and doesn't reveal which names exist
            password_hash($password, PASSWORD_DEFAULT);
            return $wrongMessage;
        }

        $userId = (int) $user['userId'];

        // Checked before the password, so guessing can't carry on while locked
        if ((int) $user['lockMinutesLeft'] > 0) {
            return loginLockedMessage((int) $user['lockMinutesLeft']);
        }

        if (!password_verify($password, $user['userPasswordHash'])) {
            if (loginRecordFailure($db, $userId, $attemptsMax)) {
                return 'Too many wrong passwords. ' . loginLockedMessage($lockoutMinutes);
            }
            return $wrongMessage;
        }

        // Only said after the right password, so it doesn't reveal anything about other people's accounts
        if ((int) $user['userStatus'] === LOGIN_STATUS_BANNED) {
            return 'This account has been banned.';
        }

        $db->beginTransaction();

        // Upgrade the stored hash if PHP's default algorithm or cost has changed since it was made
        if (password_needs_rehash($user['userPasswordHash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE `HBHub-Users` SET userPasswordHash = ? WHERE userId = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }

        if ($user['userTotpSecret'] !== null) {
            // The wrong password count isn't reset yet, wrong codes carry on counting from it
            $pendingId = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO `HBHub-Sessions` (sessionId, userId, sessionValidUntil, sessionTotpPending)
                VALUES (?, ?, NOW() + INTERVAL ' . LOGIN_PENDING_MINUTES . ' MINUTE, 1)')
                ->execute([$pendingId, $userId]);
            $db->commit();

            loginSetPendingCookie($pendingId);
            $needsTotp = true;
            return null;
        }

        $sessionId = loginFinish($db, $userId);

        $db->commit();
    } catch (PDOException $e) {
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Login: could not log in: ' . $e->getMessage());
        return 'Something went wrong logging you in. Please try again.';
    }

    hbHubSetSessionCookie($sessionId);

    return null;
}

/**
 * Finishes a login waiting for its 2FA code. Returns null when the user is logged in, otherwise a message to show.
 * Once the waiting login is gone (timed out, or locked by too many wrong codes) loginPendingUserId() is null again,
 * and the password has to be entered again.
 */
function loginCheckTotp(mixed $code, int $attemptsMax, int $lockoutMinutes): ?string
{
    $pendingId = loginPendingId();

    $db = null;
    try {
        $db = hbHubDatabase();

        $user = null;
        if ($pendingId !== null) {
            $statement = $db->prepare('SELECT u.userId, u.userTotpSecret, u.userTotpLastStep,
                    CEIL(TIMESTAMPDIFF(SECOND, NOW(), u.userAccountLockedTimestamp + INTERVAL ? MINUTE) / 60) AS lockMinutesLeft
                FROM `HBHub-Sessions` s JOIN `HBHub-Users` u ON u.userId = s.userId
                WHERE s.sessionId = ? AND s.sessionTotpPending = 1 AND s.sessionValidUntil > NOW()
                    AND u.userStatus <> ? AND u.userTotpSecret IS NOT NULL');
            $statement->execute([$lockoutMinutes, $pendingId, LOGIN_STATUS_BANNED]);
            $user = $statement->fetch() ?: null;
        }

        if ($user === null) {
            loginCancelPending();
            return 'That took too long. Please log in again.';
        }

        $userId = (int) $user['userId'];

        if ((int) $user['lockMinutesLeft'] > 0) {
            loginCancelPending();
            return loginLockedMessage((int) $user['lockMinutesLeft']);
        }

        $step = hbHubTotpVerify($user['userTotpSecret'], $code, $user['userTotpLastStep'] !== null ? (int) $user['userTotpLastStep'] : null);
        if ($step === null) {
            if (loginRecordFailure($db, $userId, $attemptsMax)) {
                loginCancelPending();
                return 'Too many wrong codes. ' . loginLockedMessage($lockoutMinutes);
            }
            return 'That code isn\'t right. Please try again.';
        }

        $db->beginTransaction();

        // Only if no newer code was used meanwhile, so the same code can't log in twice at once
        $used = $db->prepare('UPDATE `HBHub-Users` SET userTotpLastStep = ?
            WHERE userId = ? AND (userTotpLastStep IS NULL OR userTotpLastStep < ?)');
        $used->execute([$step, $userId, $step]);
        if ($used->rowCount() === 0) {
            $db->rollBack();
            return 'That code isn\'t right. Please try again.';
        }

        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$pendingId]);
        $sessionId = loginFinish($db, $userId);

        $db->commit();
    } catch (PDOException $e) {
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Login: could not check the 2FA code: ' . $e->getMessage());
        return 'Something went wrong logging you in. Please try again.';
    }

    loginClearPendingCookie();
    hbHubSetSessionCookie($sessionId);

    return null;
}

/**
 * The user the waiting login (if any) is for, or null when there isn't one that can still be finished.
 */
function loginPendingUserId(): ?int
{
    $pendingId = loginPendingId();
    if ($pendingId === null) {
        return null;
    }

    try {
        $statement = hbHubDatabase()->prepare('SELECT userId FROM `HBHub-Sessions`
            WHERE sessionId = ? AND sessionTotpPending = 1 AND sessionValidUntil > NOW()');
        $statement->execute([$pendingId]);
        $userId = $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Login: could not check the waiting login: ' . $e->getMessage());
        return null;
    }
    return $userId !== false ? (int) $userId : null;
}

/**
 * Drops the waiting login, e.g. for "Use another account".
 */
function loginCancelPending(): void
{
    $pendingId = loginPendingId();
    if ($pendingId !== null) {
        try {
            hbHubDatabase()->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ? AND sessionTotpPending = 1')->execute([$pendingId]);
        } catch (PDOException $e) {
            error_log('Login: could not drop the waiting login: ' . $e->getMessage());
        }
    }
    loginClearPendingCookie();
}

/**
 * Logs the user in, once the password (and 2FA code) were right: resets the wrong password count and the lock,
 * and stores a new session. Run inside a transaction, then pass the id to hbHubSetSessionCookie() once committed.
 */
function loginFinish(PDO $db, int $userId): string
{
    [$ipv4, $ipv6] = hbHubClientIp();

    $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NULL, userStatus = ?,
            userLastSeenTimestamp = NOW(), userIpv4AdresseLastAccessed = ?, userIpv6AdresseLastAccessed = ? WHERE userId = ?')
        ->execute([LOGIN_STATUS_ONLINE, $ipv4, $ipv6, $userId]);

    return hbHubCreateSession($db, $userId);
}

/**
 * Counts a wrong password or code. Returns true when that locked the account.
 */
function loginRecordFailure(PDO $db, int $userId, int $attemptsMax): bool
{
    $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = LEAST(userFailedLoginCount + 1, 65535) WHERE userId = ?')
        ->execute([$userId]);

    // The count starts over once locked, so there are $attemptsMax new tries after the lock runs out.
    // The WHERE makes sure only one of several simultaneous wrong attempts does the locking.
    $lock = $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NOW(),
            userStatus = IF(userStatus = ' . LOGIN_STATUS_BANNED . ', userStatus, ' . LOGIN_STATUS_LOCKED . ')
        WHERE userId = ? AND userFailedLoginCount >= ?');
    $lock->execute([$userId, $attemptsMax]);
    return $lock->rowCount() > 0;
}

function loginPendingId(): ?string
{
    $pendingId = $_COOKIE[LOGIN_PENDING_COOKIE] ?? null;
    return is_string($pendingId) && strlen($pendingId) === 32 && ctype_xdigit($pendingId) ? $pendingId : null;
}

function loginSetPendingCookie(string $pendingId): void
{
    setcookie(LOGIN_PENDING_COOKIE, $pendingId, [
        'expires' => time() + LOGIN_PENDING_MINUTES * 60,
        'path' => '/userMgmt/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function loginClearPendingCookie(): void
{
    setcookie(LOGIN_PENDING_COOKIE, '', [
        'expires' => 1,
        'path' => '/userMgmt/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function loginLockedMessage(int $minutes): string
{
    return 'This account is locked. Please try again in ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.');
}
