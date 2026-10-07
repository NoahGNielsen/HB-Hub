<?php
// Checks a name and password against HBHub-Users and logs the user in when they match.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

// userStatus: 1 Online, 2 Offline, 3 Account Locked, 4 Banned
const LOGIN_STATUS_ONLINE = 1;
const LOGIN_STATUS_LOCKED = 3;
const LOGIN_STATUS_BANNED = 4;

/**
 * Returns null when the user is logged in, otherwise a message to show on the form.
 * After $attemptsMax wrong passwords in a row the account is locked for $lockoutMinutes.
 */
function loginCheckDB(string $name, string $password, int $attemptsMax, int $lockoutMinutes): ?string
{
    $wrongMessage = 'Wrong name or password.';
    [$ipv4, $ipv6] = hbHubClientIp();

    $db = null;
    try {
        $db = hbHubDatabase();

        // lockMinutesLeft is NULL when the account has never been locked, 0 or less once the lock has run out
        $statement = $db->prepare('SELECT userId, userPasswordHash, userStatus,
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
            $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = LEAST(userFailedLoginCount + 1, 65535) WHERE userId = ?')
                ->execute([$userId]);

            // The count starts over once locked, so there are $attemptsMax new tries after the lock runs out.
            // The WHERE makes sure only one of several simultaneous wrong attempts does the locking.
            $lock = $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NOW(),
                    userStatus = IF(userStatus = ' . LOGIN_STATUS_BANNED . ', userStatus, ' . LOGIN_STATUS_LOCKED . ')
                WHERE userId = ? AND userFailedLoginCount >= ?');
            $lock->execute([$userId, $attemptsMax]);
            if ($lock->rowCount() > 0) {
                return 'Too many wrong passwords. ' . loginLockedMessage($lockoutMinutes);
            }
            return $wrongMessage;
        }

        // Only said after the right password, so it doesn't reveal anything about other people's accounts
        if ((int) $user['userStatus'] === LOGIN_STATUS_BANNED) {
            return 'This account has been banned.';
        }

        $db->beginTransaction();

        $db->prepare('UPDATE `HBHub-Users` SET userFailedLoginCount = 0, userAccountLockedTimestamp = NULL, userStatus = ?,
                userLastSeenTimestamp = NOW(), userIpv4AdresseLastAccessed = ?, userIpv6AdresseLastAccessed = ? WHERE userId = ?')
            ->execute([LOGIN_STATUS_ONLINE, $ipv4, $ipv6, $userId]);

        // Upgrade the stored hash if PHP's default algorithm or cost has changed since it was made
        if (password_needs_rehash($user['userPasswordHash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE `HBHub-Users` SET userPasswordHash = ? WHERE userId = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }

        $sessionId = hbHubCreateSession($db, $userId);

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

function loginLockedMessage(int $minutes): string
{
    return 'This account is locked. Please try again in ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.');
}
