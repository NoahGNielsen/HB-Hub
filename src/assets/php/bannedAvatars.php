<?php
// Banned profile pictures: a list of pictures (HBHub-BannedAvatars) that gets an account banned straight away when it
// uploads one of them as its profile picture, on the settings page (otherPages/settings.php) or when signing up
// (onboardingSaveToDB.php). It stops someone who was banned from making a new account that looks just like the old one.
//
// A picture is matched by the SHA-256 of its file, so only the exact same file counts: a resized, cropped or
// re-saved copy has a different hash. Each row holds a copy of the file, so admins can still see it after the account
// it came from changes or loses its picture.
//
// Pictures get on the list in two ways: a site admin bans an account (admin/user.php copies its current picture), or
// uploads one on the Banned pictures page (admin/banned.php). Unbanning an account takes its picture off again, and
// admins can remove any of them from the Banned pictures page.
require_once __DIR__ . '/session.php';

const HBHUB_BAN_REASON_ADMIN = 1; // userBannedReason in HBHub-Users
const HBHUB_BAN_REASON_AVATAR = 2;

/**
 * The SHA-256 of a picture's file, as lowercase hex like MySQL's SHA2(), which is what bannedAvatarHash holds.
 */
function hbHubBannedAvatarHash(string $file): string
{
    return hash('sha256', $file);
}

/**
 * The id of the row on the list that this file is, or null when it isn't on the list.
 */
function hbHubBannedAvatarFind(PDO $db, string $file): ?int
{
    $statement = $db->prepare('SELECT bannedAvatarId FROM `HBHub-BannedAvatars` WHERE bannedAvatarHash = ?');
    $statement->execute([hbHubBannedAvatarHash($file)]);
    $id = $statement->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Counts an upload the picture caught, for the Banned pictures page.
 */
function hbHubBannedAvatarCountMatch(PDO $db, int $bannedAvatarId): void
{
    $db->prepare('UPDATE `HBHub-BannedAvatars` SET bannedAvatarMatchCount = bannedAvatarMatchCount + 1,
            bannedAvatarLastMatchTimestamp = NOW() WHERE bannedAvatarId = ?')
        ->execute([$bannedAvatarId]);
}

/**
 * Puts a picture an admin uploaded on the list. False when it's already on it.
 */
function hbHubBannedAvatarAdd(PDO $db, string $type, string $file, int $adminId): bool
{
    $statement = $db->prepare('INSERT INTO `HBHub-BannedAvatars` (bannedAvatarHash, bannedAvatarType, bannedAvatarFile, bannedAvatarAddedByUserId)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE bannedAvatarId = bannedAvatarId');
    $statement->bindValue(1, hbHubBannedAvatarHash($file));
    $statement->bindValue(2, $type);
    $statement->bindValue(3, $file, PDO::PARAM_LOB);
    $statement->bindValue(4, $adminId, PDO::PARAM_INT);
    $statement->execute();
    return $statement->rowCount() === 1; // 0 when the row was already there
}

/**
 * Puts the user's current profile picture on the list, copied, as the picture of a banned account. Nothing happens
 * when they have no picture or it's already on the list. The hash is worked out by MySQL, so the file never has to
 * come out of the database.
 */
function hbHubBannedAvatarAddFromUser(PDO $db, int $userId, int $adminId): void
{
    $db->prepare('INSERT INTO `HBHub-BannedAvatars` (bannedAvatarHash, bannedAvatarType, bannedAvatarFile, bannedAvatarFromUserId, bannedAvatarAddedByUserId)
        SELECT SHA2(a.attachmentFile, 256), a.attachmentType, a.attachmentFile, u.userId, ?
        FROM `HBHub-Users` u JOIN `HBHub-Attachments` a ON a.attachmentId = u.userAvatarAttachmentId
        WHERE u.userId = ?
        ON DUPLICATE KEY UPDATE bannedAvatarId = bannedAvatarId')
        ->execute([$adminId, $userId]);
}

/**
 * Takes the pictures that came from the user's ban off the list, for when they're unbanned. Pictures an admin
 * uploaded stay, as do the ones from other banned accounts.
 */
function hbHubBannedAvatarRemoveFromUser(PDO $db, int $userId): void
{
    $db->prepare('DELETE FROM `HBHub-BannedAvatars` WHERE bannedAvatarFromUserId = ?')->execute([$userId]);
}

/**
 * Takes one picture off the list, from the Banned pictures page.
 */
function hbHubBannedAvatarRemove(PDO $db, int $bannedAvatarId): void
{
    $db->prepare('DELETE FROM `HBHub-BannedAvatars` WHERE bannedAvatarId = ?')->execute([$bannedAvatarId]);
}

/**
 * Bans the user, for $reason (HBHUB_BAN_REASON_*). Their sessions stay until their next visit: session.php then
 * marks the browser, so they can't just make a new account. Nothing happens when they're already banned or deleted.
 */
function hbHubBanUser(PDO $db, int $userId, int $reason): void
{
    $db->prepare('UPDATE `HBHub-Users` SET userStatus = ?, userBannedTimestamp = NOW(), userBannedReason = ?
        WHERE userId = ? AND userStatus <> ?')
        ->execute([HBHUB_SESSION_STATUS_BANNED, $reason, $userId, HBHUB_SESSION_STATUS_BANNED]);
}

/**
 * For the settings page: the user uploaded a picture that's on the list. The picture becomes their profile picture
 * anyway, so admins can see what got them banned (nobody else sees a banned account's picture, otherPages/avatar.php),
 * and they're banned. Their other changes on the form aren't saved.
 *
 * @param array{0: string, 1: string} $avatar [type, file]
 */
function hbHubBannedAvatarBanUploader(PDO $db, int $userId, array $avatar, int $bannedAvatarId): void
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $oldAvatarId = $statement->fetchColumn() ?: null;

        $statement = $db->prepare('INSERT INTO `HBHub-Attachments` (attachmentType, attachmentFile) VALUES (?, ?)');
        $statement->bindValue(1, $avatar[0]);
        $statement->bindValue(2, $avatar[1], PDO::PARAM_LOB);
        $statement->execute();
        $db->prepare('UPDATE `HBHub-Users` SET userAvatarAttachmentId = ? WHERE userId = ?')
            ->execute([(int) $db->lastInsertId(), $userId]);
        if ($oldAvatarId !== null) {
            $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$oldAvatarId]);
        }

        hbHubBanUser($db, $userId, HBHUB_BAN_REASON_AVATAR);
        hbHubBannedAvatarCountMatch($db, $bannedAvatarId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
