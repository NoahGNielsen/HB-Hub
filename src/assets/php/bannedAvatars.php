<?php
// Banned profile pictures: a list of pictures (HBHub-BannedAvatars) that gets an account banned straight away when it
// uploads one of them as its profile picture, on the settings page (otherPages/settings.php) or when signing up
// (onboardingSaveToDB.php). It stops someone who was banned from making a new account that looks just like the old one.
//
// A picture is matched two ways:
// - The exact same file, by the SHA-256 of it (bannedAvatarHash).
// - A similar copy (resized, re-saved, a bit cropped) by a fingerprint of what the picture looks like: it's shrunk to
//   17 x 16 pixels in greyscale, and each of the 256 bits says whether a pixel is darker than the one to its right
//   (a "difference hash"). Resizing and re-saving hardly change it, so two fingerprints that agree on at least
//   banned_avatar_similarity percent of the bits (the site config, 97.5 by default, so 6 of the 256 bits may differ)
//   count as the same picture. Cropping does change it, so a banned picture is fingerprinted as it is and as crops of
//   it (HBHUB_BANNED_AVATAR_CROP_SCALES from each corner, side and the middle, and the biggest square), all of them
//   in bannedAvatarFingerprints, 32 bytes each. A crop much smaller than those, a mirrored copy or a picture drawn
//   over isn't caught. Needs PHP's GD extension; without it only the exact file is caught.
//   bannedAvatarFingerprints is NULL until it's worked out (hbHubBannedAvatarFillFingerprints(), done the first time
//   it's needed), and empty when the picture can't be read or is too plain (one colour, a smooth gradient) to tell
//   apart from other plain pictures, so only the exact file bans then.
// Each row holds a copy of the file, so admins can still see it after the account it came from changes or loses its
// picture.
//
// Pictures get on the list in three ways: a site admin bans an account (admin/user.php copies its current picture),
// removes and bans an account's picture without banning the account (admin/user.php's "Remove and ban picture", so
// only uploading it again bans them), or uploads one on the Banned pictures page (admin/banned.php). Unbanning an
// account takes the pictures that came from it off again, and admins can remove any of them from the Banned pictures page.
//
// Profile banners (profile.php) are checked against the same list, and can be put on it the same ways from the admin
// panel. A banner is a JPEG XL file, which GD can't read, so on its own it could only be caught as the exact file.
// That's why a "check copy" goes with it: a small PNG of the same picture, at most HBHUB_BANNED_AVATAR_CHECK_SIDE on its
// longest side, made by the browser that has the picture anyway. The uploader's browser sends one with a new banner
// (settings.js), which is fingerprinted to find similar copies and then thrown away. The admin's browser sends one when
// a banner is banned (admin.js), which the banner's fingerprints are made from. The banned row itself keeps the JPEG XL
// file and its hash, so the exact file is always caught, also without a check copy.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/adminLog.php';
require_once __DIR__ . '/chatAttachments.php'; // where banner pictures are kept on disk

const HBHUB_BAN_REASON_ADMIN = 1; // userBannedReason in HBHub-Users
const HBHUB_BAN_REASON_AVATAR = 2;

const HBHUB_BANNED_AVATAR_GRID = 16; // a fingerprint is 16 x 16 = 256 bits
const HBHUB_BANNED_AVATAR_BYTES = 32; // ... so 32 bytes
const HBHUB_BANNED_AVATAR_CROP_SCALES = [0.95, 0.9, 0.85, 0.8]; // the crops of a banned picture that are fingerprinted too
const HBHUB_BANNED_AVATAR_PLAIN_BITS = 26; // a fingerprint with fewer 1s (or 0s) than this, about 10%, is too plain
const HBHUB_BANNED_AVATAR_WORK_SIZE = 256; // pictures are shrunk to this first, so the crops are quick to make
const HBHUB_BANNED_AVATAR_MAX_PIXELS = 40_000_000; // a bigger picture isn't opened, it takes too much memory
const HBHUB_BANNED_AVATAR_CHECK_SIDE = 256; // a banner's check copy, the longest side (the same as the work size)
const HBHUB_BANNED_AVATAR_CHECK_MAX_BYTES = 1024 * 1024; // a PNG that size never gets near this

/**
 * The SHA-256 of a picture's file, as lowercase hex like MySQL's SHA2(), which is what bannedAvatarHash holds.
 */
function hbHubBannedAvatarHash(string $file): string
{
    return hash('sha256', $file);
}

/**
 * How alike two fingerprints have to be to count as the same picture, in percent: banned_avatar_similarity from the
 * site config, kept between 90 (lower would ban people for pictures that only look a bit alike) and 100.
 */
function hbHubBannedAvatarSimilarity(): float
{
    static $similarity = null;
    if ($similarity === null) {
        $siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
        $value = $siteConfig['banned_avatar_similarity'] ?? 97.5;
        $similarity = is_numeric($value) ? max(90.0, min(100.0, (float) $value)) : 97.5;
    }
    return $similarity;
}

/**
 * The picture as a GD image, shrunk to at most HBHUB_BANNED_AVATAR_WORK_SIZE on its longest side and put on white
 * (so a transparent background counts as white), or null when GD isn't there or can't read it (an animated WebP,
 * a broken file, one with too many pixels to open). Never throws: a picture that can't be read only isn't fingerprinted.
 */
function hbHubBannedAvatarImage(string $file): ?GdImage
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    try {
        $size = @getimagesizefromstring($file);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > HBHUB_BANNED_AVATAR_MAX_PIXELS) {
            return null;
        }
        // A big photo takes about 5 bytes a pixel once it's open, more than the default memory limit allows
        $needed = $size[0] * $size[1] * 5 + 32 * 1024 * 1024;
        $limit = ini_get('memory_limit');
        if ($limit !== false && $limit !== '-1' && ini_parse_quantity($limit) < $needed) {
            @ini_set('memory_limit', (string) $needed);
            if (ini_parse_quantity(ini_get('memory_limit')) < $needed) {
                return null; // not allowed to raise it, and opening it could stop the request
            }
        }

        $source = @imagecreatefromstring($file);
        if ($source === false) {
            return null;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, HBHUB_BANNED_AVATAR_WORK_SIZE / max($width, $height));
        $work = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($work, 0, 0, imagecolorallocate($work, 255, 255, 255));
        imagealphablending($work, true);
        imagecopyresampled($work, $source, 0, 0, 0, 0, imagesx($work), imagesy($work), $width, $height);
        return $work;
    } catch (Throwable $e) {
        error_log('Banned pictures: could not read a picture: ' . $e->getMessage());
        return null;
    }
}

/**
 * The fingerprint of part of the picture: the box from ($x, $y), $width x $height, all as fractions of the picture
 * (0 to 1). 32 bytes, or null when it's too plain to tell apart from other plain pictures (almost all of its bits
 * are the same, like a picture in one colour).
 */
function hbHubBannedAvatarFingerprint(GdImage $image, float $x, float $y, float $width, float $height): ?string
{
    $grid = HBHUB_BANNED_AVATAR_GRID;
    $imageWidth = imagesx($image);
    $imageHeight = imagesy($image);
    $small = imagecreatetruecolor($grid + 1, $grid);
    imagecopyresampled($small, $image, 0, 0, (int) round($x * $imageWidth), (int) round($y * $imageHeight), $grid + 1, $grid,
        max(1, (int) round($width * $imageWidth)), max(1, (int) round($height * $imageHeight)));

    $fingerprint = '';
    $byte = 0;
    $ones = 0;
    for ($row = 0; $row < $grid; $row++) {
        $left = hbHubBannedAvatarBrightness(imagecolorat($small, 0, $row));
        for ($column = 1; $column <= $grid; $column++) {
            $right = hbHubBannedAvatarBrightness(imagecolorat($small, $column, $row));
            $bit = $left < $right ? 1 : 0;
            $byte = ($byte << 1) | $bit;
            $ones += $bit;
            if ((($row * $grid) + $column) % 8 === 0) {
                $fingerprint .= chr($byte);
                $byte = 0;
            }
            $left = $right;
        }
    }

    $bits = $grid * $grid;
    return $ones < HBHUB_BANNED_AVATAR_PLAIN_BITS || $ones > $bits - HBHUB_BANNED_AVATAR_PLAIN_BITS ? null : $fingerprint;
}

/**
 * How bright a pixel from imagecolorat() is, 0 to 255000 (the usual weights for how bright red, green and blue look).
 */
function hbHubBannedAvatarBrightness(int $color): int
{
    return 299 * (($color >> 16) & 0xFF) + 587 * (($color >> 8) & 0xFF) + 114 * ($color & 0xFF);
}

/**
 * How many bits two fingerprints differ by.
 */
function hbHubBannedAvatarDistance(string $a, string $b): int
{
    static $bitCounts = null;
    $bitCounts ??= array_map(fn(int $byte): int => substr_count(decbin($byte), '1'), range(0, 255));

    $difference = $a ^ $b;
    $distance = 0;
    for ($i = 0, $length = strlen($difference); $i < $length; $i++) {
        $distance += $bitCounts[ord($difference[$i])];
    }
    return $distance;
}

/**
 * Every fingerprint of a banned picture, for bannedAvatarFingerprints: of the whole picture, of crops of it from each
 * corner, side and the middle (HBHUB_BANNED_AVATAR_CROP_SCALES of its width and height), and of the biggest square in
 * it when it isn't square (from the start, middle and end of the long side). The ones that are too plain are left out.
 * Null when GD isn't there, so it's tried again later; empty when the picture can't be read or is too plain.
 */
function hbHubBannedAvatarFingerprints(string $file): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $image = hbHubBannedAvatarImage($file);
    if ($image === null) {
        return '';
    }

    $boxes = [[0, 0, 1, 1]];
    foreach (HBHUB_BANNED_AVATAR_CROP_SCALES as $scale) {
        $rest = 1 - $scale;
        foreach ([0, $rest / 2, $rest] as $y) {
            foreach ([0, $rest / 2, $rest] as $x) {
                $boxes[] = [$x, $y, $scale, $scale];
            }
        }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width !== $height) {
        $side = min($width, $height) / max($width, $height);
        foreach ([0, (1 - $side) / 2, 1 - $side] as $start) {
            $boxes[] = $width > $height ? [$start, 0, $side, 1] : [0, $start, 1, $side];
        }
    }

    $fingerprints = '';
    foreach ($boxes as [$x, $y, $boxWidth, $boxHeight]) {
        $fingerprints .= hbHubBannedAvatarFingerprint($image, $x, $y, $boxWidth, $boxHeight) ?? '';
    }
    return $fingerprints;
}

/**
 * Works out bannedAvatarFingerprints for the pictures on the list that don't have them yet: the ones a ban put there
 * (copied by MySQL, so PHP never saw the file) and the ones from before fingerprints. Nothing happens without GD.
 */
function hbHubBannedAvatarFillFingerprints(PDO $db): void
{
    if (!function_exists('imagecreatefromstring')) {
        return;
    }
    $statement = $db->query('SELECT bannedAvatarId, bannedAvatarFile FROM `HBHub-BannedAvatars` WHERE bannedAvatarFingerprints IS NULL');
    $update = $db->prepare('UPDATE `HBHub-BannedAvatars` SET bannedAvatarFingerprints = ? WHERE bannedAvatarId = ?');
    foreach ($statement->fetchAll() as $row) {
        $update->bindValue(1, hbHubBannedAvatarFingerprints($row['bannedAvatarFile']), PDO::PARAM_LOB);
        $update->bindValue(2, (int) $row['bannedAvatarId'], PDO::PARAM_INT);
        $update->execute();
    }
}

/**
 * The id of the row on the list that this file is, or is a similar copy of (the closest one, when it's like more than
 * one), or null when it isn't on the list.
 */
function hbHubBannedAvatarFind(PDO $db, string $file): ?int
{
    $statement = $db->prepare('SELECT bannedAvatarId FROM `HBHub-BannedAvatars` WHERE bannedAvatarHash = ?');
    $statement->execute([hbHubBannedAvatarHash($file)]);
    $id = $statement->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    // The upload is only fingerprinted as it is: the crops are on the banned pictures' side
    $image = hbHubBannedAvatarImage($file);
    $fingerprint = $image !== null ? hbHubBannedAvatarFingerprint($image, 0, 0, 1, 1) : null;
    if ($fingerprint === null) {
        return null;
    }

    hbHubBannedAvatarFillFingerprints($db);
    $bits = HBHUB_BANNED_AVATAR_GRID ** 2;
    $maxDistance = (int) floor($bits * (100 - hbHubBannedAvatarSimilarity()) / 100 + 1e-9);
    $closestId = null;
    $closestDistance = $maxDistance + 1;
    $rows = $db->query('SELECT bannedAvatarId, bannedAvatarFingerprints FROM `HBHub-BannedAvatars` WHERE LENGTH(bannedAvatarFingerprints) > 0');
    foreach ($rows->fetchAll() as $row) {
        $fingerprints = $row['bannedAvatarFingerprints'];
        for ($i = 0; $i + HBHUB_BANNED_AVATAR_BYTES <= strlen($fingerprints); $i += HBHUB_BANNED_AVATAR_BYTES) {
            $distance = hbHubBannedAvatarDistance($fingerprint, substr($fingerprints, $i, HBHUB_BANNED_AVATAR_BYTES));
            if ($distance < $closestDistance) {
                $closestId = (int) $row['bannedAvatarId'];
                $closestDistance = $distance;
            }
        }
    }
    return $closestId;
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
 * Puts the user's current profile picture on the list, copied, as coming from them (bannedAvatarFromUserId), for a ban
 * or for "Remove and ban picture" in admin/user.php. Returns the new row's id, or null when they have no picture or
 * it's already on the list. The hash is worked out by MySQL, so the file never has to come out of the database.
 * The fingerprints aren't, so call hbHubBannedAvatarFillFingerprints() once it's committed.
 */
function hbHubBannedAvatarAddFromUser(PDO $db, int $userId, int $adminId): ?int
{
    $statement = $db->prepare('INSERT INTO `HBHub-BannedAvatars` (bannedAvatarHash, bannedAvatarType, bannedAvatarFile, bannedAvatarFromUserId, bannedAvatarAddedByUserId)
        SELECT SHA2(a.attachmentFile, 256), a.attachmentType, a.attachmentFile, u.userId, ?
        FROM `HBHub-Users` u JOIN `HBHub-Attachments` a ON a.attachmentId = u.userAvatarAttachmentId
        WHERE u.userId = ?
        ON DUPLICATE KEY UPDATE bannedAvatarId = bannedAvatarId');
    $statement->execute([$adminId, $userId]);
    return $statement->rowCount() === 1 ? (int) $db->lastInsertId() : null; // 0 when it was already there
}

/**
 * A banner's check copy (see the top) from the form's file field $name, or null when there isn't one or it isn't a
 * small enough PNG. The file itself, never kept.
 */
function hbHubBannedAvatarCheckCopy(string $name): ?string
{
    $file = $_FILES[$name] ?? null;
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])
        || $file['size'] > HBHUB_BANNED_AVATAR_CHECK_MAX_BYTES) {
        return null;
    }
    $png = file_get_contents($file['tmp_name']);
    $size = $png !== false ? @getimagesizefromstring($png) : false;
    return $size !== false && $size[2] === IMAGETYPE_PNG ? $png : null;
}

/**
 * The id of the row on the list a new banner is (its JPEG XL file, by hash), or that its check copy is a similar copy
 * of, or null when it isn't on the list. Without a check copy only the exact file is caught.
 */
function hbHubBannedAvatarFindBanner(PDO $db, string $jxl, ?string $checkCopy): ?int
{
    return hbHubBannedAvatarFind($db, $jxl) ?? ($checkCopy !== null ? hbHubBannedAvatarFind($db, $checkCopy) : null);
}

/**
 * Puts the user's current banner picture on the list, copied, as coming from them, for a ban or for "Remove and ban
 * banner" in admin/user.php. Its fingerprints come from $checkCopy, the admin's browser's PNG of it; without one it
 * only catches the exact file. Returns the new row's id, or null when they have no banner, its file is gone or it's
 * already on the list.
 */
function hbHubBannedAvatarAddBannerFromUser(PDO $db, int $userId, int $adminId, ?string $checkCopy): ?int
{
    $statement = $db->prepare('SELECT b.attachmentKey, b.attachmentType FROM `HBHub-Users` u
        JOIN `HBHub-ChatAttachments` b ON b.attachmentId = u.userBannerAttachmentId AND b.attachmentStatus = ?
        WHERE u.userId = ?');
    $statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT, $userId]);
    $banner = $statement->fetch();
    $dir = hbHubChatAttachmentsDir();
    $file = $banner !== false && $dir !== null ? @file_get_contents($dir . '/' . $banner['attachmentKey']) : false;
    if ($file === false) {
        return null;
    }

    // Empty instead of NULL without a check copy, so hbHubBannedAvatarFillFingerprints() doesn't try GD on the JPEG XL
    $fingerprints = $checkCopy !== null ? hbHubBannedAvatarFingerprints($checkCopy) : '';
    $statement = $db->prepare('INSERT INTO `HBHub-BannedAvatars` (bannedAvatarHash, bannedAvatarType, bannedAvatarFile, bannedAvatarFingerprints,
            bannedAvatarFromUserId, bannedAvatarAddedByUserId)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bannedAvatarId = bannedAvatarId');
    $statement->bindValue(1, hbHubBannedAvatarHash($file));
    $statement->bindValue(2, $banner['attachmentType']);
    $statement->bindValue(3, $file, PDO::PARAM_LOB);
    $statement->bindValue(4, $fingerprints, $fingerprints !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
    $statement->bindValue(5, $userId, PDO::PARAM_INT);
    $statement->bindValue(6, $adminId, PDO::PARAM_INT);
    $statement->execute();
    return $statement->rowCount() === 1 ? (int) $db->lastInsertId() : null; // 0 when it was already there
}

/**
 * Takes the pictures that came from the user (their ban, or "Remove and ban picture") off the list, for when they're unbanned. Pictures an admin
 * uploaded stay, as do the ones from other banned accounts.
 */
function hbHubBannedAvatarRemoveFromUser(PDO $db, int $userId): void
{
    $db->prepare('DELETE FROM `HBHub-BannedAvatars` WHERE bannedAvatarFromUserId = ?')->execute([$userId]);
}

/**
 * Takes one picture off the list, from the Banned pictures page. Returns whether it was on it.
 */
function hbHubBannedAvatarRemove(PDO $db, int $bannedAvatarId): bool
{
    $statement = $db->prepare('DELETE FROM `HBHub-BannedAvatars` WHERE bannedAvatarId = ?');
    $statement->execute([$bannedAvatarId]);
    return $statement->rowCount() > 0;
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
 * Bans the user for uploading the banned picture $bannedAvatarId, counts the match for the Banned pictures page, and
 * puts it in the admin log as 'user.autoban' with no admin, so it shows up next to the bans admins made. $signup says
 * whether it was when making the account (onboardingSaveToDB.php) or on the settings page, $banner whether it was
 * uploaded as their banner instead of their profile picture. Call it in the transaction that saves the picture.
 */
function hbHubBannedAvatarAutoban(PDO $db, int $userId, int $bannedAvatarId, bool $signup, bool $banner = false): void
{
    hbHubBanUser($db, $userId, HBHUB_BAN_REASON_AVATAR);
    hbHubBannedAvatarCountMatch($db, $bannedAvatarId);
    adminLog($db, null, 'user.autoban', $userId, null, ['pictureId' => $bannedAvatarId, 'signup' => $signup] + ($banner ? ['banner' => true] : []));
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

        hbHubBannedAvatarAutoban($db, $userId, $bannedAvatarId, false);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
