<?php
// What the admin panel does to an account that both a user's admin panel (admin/user.php) and a person's admin panel
// (admin/person.php, every account used in the same browsers) can do: ghost ban, ban, the gambling block, logging out
// and deleting. Each function changes one account and puts it in the admin log (adminLog.php); the pages call it once
// per account. None of them check whether the account can be changed (not deleted, not a site admin), the pages do.
//
// $logData is added to the log row's details. The pages pass ['personOf' => userId] when it was done to the account
// as one of a person's accounts (from the person page, or with "Also ... their other accounts" on a user's page), with
// the account the admin started from, so the log shows that (admin/log.php, adminLogText()).
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/bannedAvatars.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/block.php';
require_once __DIR__ . '/userSettings.php';

const ADMIN_STATUS_OFFLINE = 2; // userStatus in HBHub-Users (HBHUB_SESSION_STATUS_BANNED is 4)

/**
 * Bans the account, and with $banAvatar puts its picture on the banned pictures list too. The sessions stay until the
 * user's next visit: session.php then marks their browser, so they can't just make a new account. Call
 * hbHubBannedAvatarFillFingerprints() once every account is done, so the ban doesn't wait on it.
 */
function adminAccountBan(PDO $db, int $adminId, int $userId, bool $banAvatar, array $logData = []): void
{
    $db->beginTransaction();
    hbHubBanUser($db, $userId, HBHUB_BAN_REASON_ADMIN);
    if ($banAvatar) {
        hbHubBannedAvatarAddFromUser($db, $userId, $adminId);
    }
    adminLog($db, $adminId, 'user.ban', $userId, null, ['avatar' => $banAvatar] + $logData);
    $db->commit();
}

/**
 * Unbans the account. Sessions from before the ban don't come back, they log in again. The pictures their ban put on
 * the banned list come off it, so uploading their own picture again doesn't ban them.
 */
function adminAccountUnban(PDO $db, int $adminId, int $userId, array $logData = []): void
{
    $db->beginTransaction();
    $db->prepare('UPDATE `HBHub-Users` SET userStatus = ?, userBannedTimestamp = NULL, userBannedReason = NULL WHERE userId = ? AND userStatus = ?')
        ->execute([ADMIN_STATUS_OFFLINE, $userId, HBHUB_SESSION_STATUS_BANNED]);
    $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
    hbHubBannedAvatarRemoveFromUser($db, $userId);
    adminLog($db, $adminId, 'user.unban', $userId, null, $logData ?: null);
    $db->commit();
}

/**
 * Ghost bans the account, or with $on false lifts its ghost ban. Nothing tells them and they stay logged in: from
 * their next request on, what they do in chats only shows to themselves (see the top of assets/php/chat.php), or
 * shows to everyone again. What they did before stays as it was. Only logged when it changed something.
 */
function adminAccountGhostBan(PDO $db, int $adminId, int $userId, bool $on, array $logData = []): void
{
    $statement = $db->prepare($on
        ? 'UPDATE `HBHub-Users` SET userGhostBannedTimestamp = NOW() WHERE userId = ? AND userGhostBannedTimestamp IS NULL'
        : 'UPDATE `HBHub-Users` SET userGhostBannedTimestamp = NULL WHERE userId = ? AND userGhostBannedTimestamp IS NOT NULL');
    $statement->execute([$userId]);
    if ($statement->rowCount() > 0) {
        adminLog($db, $adminId, $on ? 'user.ghostBan' : 'user.ghostUnban', $userId, null, $logData ?: null);
    }
}

/**
 * Sets the account's own gambling block to $mode (a key of HBHUB_BLOCK_MODES) straight away, either way: the wait is
 * only there to stop the user turning it off in the moment. A change they were waiting for is cancelled.
 */
function adminAccountBlock(PDO $db, int $adminId, int $userId, string $mode, array $logData = []): void
{
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($mode): array {
        $settings['block'] = ['mode' => $mode, 'pendingMode' => null, 'pendingFrom' => null];
        return $settings;
    });
    adminLog($db, $adminId, 'user.block', $userId, null, ['mode' => $mode] + $logData);
}

/**
 * Puts on ($on) or lifts the admin block on Gambling and Games, on top of their own block. They can't lift it in
 * settings, only an admin can (block.php). Their own block stays as it was. Only logged when it changed something.
 */
function adminAccountBlockAdmin(PDO $db, int $adminId, int $userId, bool $on, array $logData = []): void
{
    $wasOn = null;
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($on, &$wasOn): array {
        $wasOn = hbHubBlockIsAdmin($settings);
        if ($on) {
            $settings['blockAdmin'] = true;
        } else {
            unset($settings['blockAdmin']);
        }
        return $settings;
    });
    if ($wasOn !== $on) {
        adminLog($db, $adminId, $on ? 'user.blockAdmin' : 'user.blockAdminLift', $userId, null, $logData ?: null);
    }
}

/**
 * Logs the account out of every session. They can log in again with their password.
 */
function adminAccountLogout(PDO $db, int $adminId, int $userId, array $logData = []): void
{
    $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
    adminLog($db, $adminId, 'user.logout', $userId, null, $logData ?: null);
}

/**
 * Deletes the account (account.php, hbHubDeleteAccount()): their messages stay, from "Deleted User".
 */
function adminAccountDelete(PDO $db, int $adminId, int $userId, array $logData = []): void
{
    hbHubDeleteAccount($db, $userId);
    adminLog($db, $adminId, 'user.delete', $userId, null, $logData ?: null);
}
