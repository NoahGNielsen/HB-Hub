<?php
// Checks a name and password against HBHub-Users and logs the user in when they match. Logging in with a linked
// Discord account (discord.php, userMgmt/discord.php) ends up here too, in loginDiscord().
// With two-factor login turned on (userTotpSecret), the right password only starts the login: it's kept as a
// session waiting for the code (sessionTotpPending) for a few minutes, and loginCheckTotp() finishes it.
// Wrong passwords and codes are counted, and locked out, by loginLimit.php.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/loginLimit.php';

// userStatus: 1 Online, 2 Offline, 3 Account Locked, 4 Banned
const LOGIN_STATUS_ONLINE = 1;
const LOGIN_STATUS_BANNED = 4;

const LOGIN_PENDING_COOKIE = 'hbHubPendingLogin'; // the session waiting for the 2FA code, never the real session cookie
const LOGIN_PENDING_MINUTES = 5; // how long there is to enter the code

/**
 * Returns null when the password was right, otherwise a message to show on the form.
 * $needsTotp is set to true when the user isn't logged in yet, because the 2FA code is needed next.
 * After $attemptsMax wrong passwords (or 2FA codes) in a row the browser is locked out for $lockoutMinutes (loginLimit.php).
 */
function loginCheckDB(string $name, string $password, int $attemptsMax, int $lockoutMinutes, ?bool &$needsTotp = null): ?string
{
    $needsTotp = false;
    $wrongMessage = 'Wrong name or password.';

    $db = null;
    try {
        $db = hbHubDatabase();

        $statement = $db->prepare('SELECT userId, userPasswordHash, userStatus, userTotpSecret
            FROM `HBHub-Users` WHERE userNameLower = ?');
        $statement->execute([mb_strtolower($name)]);
        $user = $statement->fetch();

        if ($user === false) {
            // Hash anyway, so an unknown name takes as long as a wrong password and doesn't reveal which names exist
            password_hash($password, PASSWORD_DEFAULT);
            return $wrongMessage;
        }

        $userId = (int) $user['userId'];

        // Checked before the password, so guessing can't carry on while locked
        $lockMinutesLeft = hbHubLoginLockMinutesLeft($db, $userId, $lockoutMinutes);
        if ($lockMinutesLeft > 0) {
            return hbHubLoginLockedMessage($lockMinutesLeft);
        }

        if (!password_verify($password, $user['userPasswordHash'])) {
            if (hbHubLoginRecordFailure($db, $userId, $attemptsMax)) {
                return 'Too many wrong passwords. ' . hbHubLoginLockedMessage($lockoutMinutes);
            }
            return $wrongMessage;
        }

        // Only said after the right password, so it doesn't reveal anything about other people's accounts
        if ((int) $user['userStatus'] === LOGIN_STATUS_BANNED) {
            hbHubSetBannedCookie($userId); // so a new account can't be made from this browser instead
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
            $pendingId = loginStorePending($db, $userId);
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
 * Logs in the account linked to the Discord account (discord.php), once Discord has said who it is. That counts as
 * the right password: two-factor login still asks for the code ($needsTotp, like loginCheckDB()), and a banned account
 * gets its browser marked. The login lock is about guessing passwords, which this isn't, so it doesn't stop it.
 * $discordName is the username Discord just gave, saved over the one kept (it's on the profile) in case it changed.
 * Returns null when it worked, otherwise why not: 'notLinked', 'banned' or 'failed'.
 */
function loginDiscord(string $discordId, string $discordName, ?bool &$needsTotp = null): ?string
{
    $needsTotp = false;

    $db = null;
    try {
        $db = hbHubDatabase();

        // A deleted account's userDiscordId is NULL, so it can't be one
        $statement = $db->prepare('SELECT userId, userStatus, userTotpSecret FROM `HBHub-Users` WHERE userDiscordId = ?');
        $statement->execute([$discordId]);
        $user = $statement->fetch();
        if ($user === false) {
            return 'notLinked';
        }

        $userId = (int) $user['userId'];
        if ((int) $user['userStatus'] === LOGIN_STATUS_BANNED) {
            hbHubSetBannedCookie($userId);
            return 'banned';
        }

        $db->prepare('UPDATE `HBHub-Users` SET userDiscordName = ? WHERE userId = ? AND userDiscordId = ?')
            ->execute([$discordName, $userId, $discordId]);

        $db->beginTransaction();
        if ($user['userTotpSecret'] !== null) {
            $pendingId = loginStorePending($db, $userId);
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
        error_log('Login: could not log in with Discord: ' . $e->getMessage());
        return 'failed';
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
            $statement = $db->prepare('SELECT u.userId, u.userTotpSecret, u.userTotpLastStep
                FROM `HBHub-Sessions` s JOIN `HBHub-Users` u ON u.userId = s.userId
                WHERE s.sessionId = ? AND s.sessionTotpPending = 1 AND s.sessionValidUntil > NOW()
                    AND u.userStatus <> ? AND u.userTotpSecret IS NOT NULL');
            $statement->execute([$pendingId, LOGIN_STATUS_BANNED]);
            $user = $statement->fetch() ?: null;
        }

        if ($user === null) {
            loginCancelPending();
            return 'That took too long. Please log in again.';
        }

        $userId = (int) $user['userId'];

        $lockMinutesLeft = hbHubLoginLockMinutesLeft($db, $userId, $lockoutMinutes);
        if ($lockMinutesLeft > 0) {
            loginCancelPending();
            return hbHubLoginLockedMessage($lockMinutesLeft);
        }

        $step = hbHubTotpVerify($user['userTotpSecret'], $code, $user['userTotpLastStep'] !== null ? (int) $user['userTotpLastStep'] : null);
        if ($step === null) {
            if (hbHubLoginRecordFailure($db, $userId, $attemptsMax)) {
                loginCancelPending();
                return 'Too many wrong codes. ' . hbHubLoginLockedMessage($lockoutMinutes);
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
 * Logs the user in, once the password (and 2FA code) were right: makes the browser a known device for the account
 * (loginLimit.php) and stores a new session. Run inside a transaction, then pass the id to hbHubSetSessionCookie()
 * once committed. A browser marked for this account being banned isn't anymore, as it's been unbanned since.
 * When a ghost-banned account is known in the browser, an account made in the last HBHUB_GHOST_NEW_ACCOUNT_DAYS that
 * logs in there is ghost banned too, and so is one known there when the ghost-banned one logs in (hbHubGhostBanFromDevice()).
 */
function loginFinish(PDO $db, int $userId): string
{
    [$ipv4, $ipv6] = hbHubClientIp();

    if (hbHubBannedCookieUserId() === $userId) {
        hbHubClearBannedCookie();
    }

    // From a new browser the right password lifts the lock that new browsers share. From a known device it only lifts
    // that device's own lock (hbHubLoginTrustDevice()), so logging in on your phone doesn't let someone carry on guessing.
    $isKnownDevice = hbHubKnownDevice($db, $userId) !== null;
    $db->prepare('UPDATE `HBHub-Users` SET ' . ($isKnownDevice ? '' : 'userFailedLoginCount = 0, userAccountLockedTimestamp = NULL, ')
            . 'userStatus = ?, userLastSeenTimestamp = NOW(), userIpv4AdresseLastAccessed = ?, userIpv6AdresseLastAccessed = ?
        WHERE userId = ?')
        ->execute([LOGIN_STATUS_ONLINE, $ipv4, $ipv6, $userId]);
    hbHubLoginTrustDevice($db, $userId);
    hbHubGhostBanFromDevice($db); // a new account used where a ghost-banned one is gets ghost banned too (loginLimit.php)

    return hbHubCreateSession($db, $userId);
}

/**
 * Stores a login waiting for its 2FA code (sessionTotpPending) and returns its id. Run inside a transaction, then
 * pass the id to loginSetPendingCookie() once committed.
 */
function loginStorePending(PDO $db, int $userId): string
{
    $pendingId = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO `HBHub-Sessions` (sessionId, userId, sessionValidUntil, sessionTotpPending)
        VALUES (?, ?, NOW() + INTERVAL ' . LOGIN_PENDING_MINUTES . ' MINUTE, 1)')
        ->execute([$pendingId, $userId]);
    return $pendingId;
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
        'path' => '/login',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function loginClearPendingCookie(): void
{
    setcookie(LOGIN_PENDING_COOKIE, '', [
        'expires' => 1,
        'path' => '/login',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
