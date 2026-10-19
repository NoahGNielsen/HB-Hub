<?php
// Changes to a user's account that the settings page (the user themself) and the admin panel (admin/user.php) both make.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/chatAttachments.php'; // a deleted account's attachments go with it

/**
 * Why the name can't be used, or null when it's fine. Taken names are found when saving (userNameLower is unique).
 */
function hbHubNameError(string $name, int $nameMax): ?string
{
    if ($name === '') {
        return 'Please tell us what you should be known as.';
    } elseif (preg_match('/^[^\p{Cc}]+$/u', $name) !== 1) {
        return 'That name contains characters that can\'t be used.';
    } elseif (mb_strlen($name) > $nameMax) {
        return "Please keep it to $nameMax characters or fewer.";
    } elseif (hbHubIsDeletedUserName($name)) {
        return 'That name can\'t be used. Please choose another one.';
    }
    return null;
}

/**
 * Takes away the user's profile picture.
 */
function hbHubRemoveAvatar(PDO $db, int $userId): void
{
    $db->beginTransaction();
    $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
    $statement->execute([$userId]);
    $avatarId = $statement->fetchColumn() ?: null;

    $db->prepare('UPDATE `HBHub-Users` SET userAvatarAttachmentId = NULL WHERE userId = ?')->execute([$userId]);
    if ($avatarId !== null) {
        $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$avatarId]);
    }
    $db->commit();
}

/**
 * Deletes the account. Everything in the user's row is cleared except userId, userCreatedTimestamp and
 * userLastSeenTimestamp, and the name becomes "Deleted User", so their messages stay but nothing about them does.
 * Their profile picture, every one of their sessions and their known devices (loginLimit.php) are deleted too,
 * and so are the files of their chat attachments (the messages they were in stay, showing them as deleted).
 * userNameLower and userPasswordHash end up NULL, so nobody can log in to it or find it in the user search.
 * userStatus, userRole and userFailedLoginCount end up NULL too, so a NULL userStatus means a deleted account.
 */
function hbHubDeleteAccount(PDO $db, int $userId): void
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $avatarId = $statement->fetchColumn() ?: null;

        $db->prepare('UPDATE `HBHub-Users` SET userName = ?, userNameLower = NULL, userDescription = NULL, userSettings = NULL,
                userAvatarAttachmentId = NULL, userIpv4AdresseOnAccountCreate = NULL, userIpv6AdresseOnAccountCreate = NULL,
                userIpv4AdresseLastAccessed = NULL, userIpv6AdresseLastAccessed = NULL, userPasswordHash = NULL,
                userTotpSecret = NULL, userTotpLastStep = NULL, userFailedLoginCount = NULL, userStatus = NULL,
                userRole = NULL, userAccountLockedTimestamp = NULL, userBannedTimestamp = NULL, userBannedReason = NULL
            WHERE userId = ?')
            ->execute([HBHUB_DELETED_USER_NAME, $userId]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
        $db->prepare('DELETE FROM `HBHub-LoginDevices` WHERE userId = ?')->execute([$userId]);
        if ($avatarId !== null) {
            $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$avatarId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    hbHubChatAttachmentsRemove($db, 'a.userId = ?', [$userId]);
}
