<?php
// Pictures on disk: where profile pictures, group chat icons and the banned pictures' copies are kept, and the only
// place that knows how. They used to be BLOBs in the database, which made it big and slow to back up, so now each one
// is a file, like the chat attachments (chatAttachments.php), and the row only holds its name:
// - Profile pictures and group icons: HBHub-Attachments.attachmentKey, files in pictures/
// - Banned pictures' copies:          HBHub-BannedAvatars.bannedAvatarKey, files in bannedPictures/
// Both folders are inside the attachments folder (attachments_path in the config, outside the web root, behind its
// "Require all denied" .htaccess), so Apache never serves them: otherPages/avatar.php, chat/icon.php and
// admin/bannedPicture.php check who's asking and send them. A file is named after its random 32-character key, so the
// name never says what's in it. They don't take tickets and don't count towards attachments_max_total_bytes: that
// sum only reads HBHub-ChatAttachments.
//
// The one rule: a row never points at a file that isn't there. A file nobody points at is only a bit of wasted disk.
// So a file is written first (to a temp name, then renamed into place, so a half-written one is never under its real
// name), then its row is saved. And a file is only deleted once no row points at it any more, which
// hbHubPictureFileRelease() looks after: tell it about every key that may have become unused (the old picture that was
// replaced, the new one whose row may not have been saved after all), and at the end of the request, when everything
// is committed or rolled back, it deletes the ones no row has.
//
// Who uses it: the settings page and sign-up (profile pictures), chat.php (group icons), account.php (removing a
// picture, deleting an account), bannedAvatars.php (the banned copies), and the three scripts that send them.
//
// The changeover: rows from before have their picture in attachmentFile / bannedAvatarFile and no key yet, until
// admin/pictureExport.php has written them out. Until then hbHubPictureFileSend() can send the BLOB instead.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/chatAttachments.php'; // the folder they're in

const HBHUB_PICTURE_FILES_PICTURES = 'pictures'; // profile pictures and group icons
const HBHUB_PICTURE_FILES_BANNED = 'bannedPictures'; // the banned pictures' copies
// Folder => [the table whose rows point into it, the column with the key]
const HBHUB_PICTURE_FILES_TABLES = [
    HBHUB_PICTURE_FILES_PICTURES => ['HBHub-Attachments', 'attachmentKey'],
    HBHUB_PICTURE_FILES_BANNED => ['HBHub-BannedAvatars', 'bannedAvatarKey'],
];

/**
 * The folder (HBHUB_PICTURE_FILES_*) inside the attachments folder, made the first time it's needed. The attachments
 * folder's own .htaccess denies everything below it too. Null when it can't be made.
 */
function hbHubPictureFilesDir(string $folder): ?string
{
    $attachmentsDir = hbHubChatAttachmentsDir();
    if ($attachmentsDir === null || !isset(HBHUB_PICTURE_FILES_TABLES[$folder])) {
        return null;
    }
    $dir = $attachmentsDir . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }
    return $dir;
}

/**
 * Where the file with $key is, or null when there's no such file (or no key, like a row that hasn't been exported yet).
 */
function hbHubPictureFilePath(string $folder, ?string $key): ?string
{
    if ($key === null || preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
        return null;
    }
    $dir = hbHubPictureFilesDir($folder);
    return $dir !== null && is_file($dir . '/' . $key) ? $dir . '/' . $key : null;
}

/**
 * Makes a new file in the folder under a new random key: $write gets a temp name in the same folder to write to and
 * says whether that worked, and then it's renamed into place, so the real name only ever holds a whole file.
 * Returns the key. Throws when the file can't be made, so nothing ever saves a row for it.
 *
 * @param callable(string): bool $write
 */
function hbHubPictureFileStore(string $folder, callable $write): string
{
    $dir = hbHubPictureFilesDir($folder);
    if ($dir === null) {
        throw new RuntimeException('Pictures: the folder ' . $folder . ' can\'t be made');
    }
    $key = bin2hex(random_bytes(16));
    $temp = $dir . '/tmp-' . $key;
    if (!$write($temp) || !@rename($temp, $dir . '/' . $key)) {
        @unlink($temp);
        throw new RuntimeException('Pictures: could not write a file in ' . $folder);
    }
    return $key;
}

/**
 * Saves the picture's bytes as a new file in the folder. Returns its key; throws when it can't be written.
 */
function hbHubPictureFileSave(string $folder, string $bytes): string
{
    return hbHubPictureFileStore($folder, fn (string $temp): bool => @file_put_contents($temp, $bytes) === strlen($bytes));
}

/**
 * Copies the file at $fromPath to a new file of its own in the folder. Returns its key; throws when it can't be copied.
 */
function hbHubPictureFileCopy(string $folder, string $fromPath): string
{
    return hbHubPictureFileStore($folder, function (string $temp) use ($fromPath): bool {
        if (!@copy($fromPath, $temp)) {
            return false;
        }
        clearstatcache(true, $temp);
        return filesize($temp) === filesize($fromPath);
    });
}

/**
 * Deletes the file with $key straight away. Only for a file that's known to have no row (the export's own failed
 * tries); everything else uses hbHubPictureFileRelease(), which checks first.
 */
function hbHubPictureFileDelete(string $folder, ?string $key): void
{
    $path = hbHubPictureFilePath($folder, $key);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Says that the file with $key may not be needed any more: its row was deleted or replaced, or it was just written and
 * its row may never be saved (the insert fails, the transaction is rolled back, the picture was already on the banned
 * list). Nothing is deleted now. At the end of the request, when it's settled what was committed, each of them is
 * deleted if no row points at it. That way it's always right, whoever's transaction it was in, and a rolled-back
 * delete keeps its file. If the request dies in the middle of a transaction, the files are left where they are.
 */
function hbHubPictureFileRelease(PDO $db, string $folder, ?string $key): void
{
    static $released = null; // the [folder, key] pairs of this request
    if ($key === null) {
        return;
    }
    if ($released === null) {
        $released = new ArrayObject();
        register_shutdown_function(function () use ($db, $released): void {
            try {
                if ($db->inTransaction()) {
                    return; // not settled, so it can't be known which rows there'll be
                }
                foreach ($released as [$folder, $key]) {
                    [$table, $column] = HBHUB_PICTURE_FILES_TABLES[$folder];
                    $statement = $db->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` = ? LIMIT 1');
                    $statement->execute([$key]);
                    if ($statement->fetchColumn() === false) {
                        hbHubPictureFileDelete($folder, $key);
                    }
                }
            } catch (Throwable $e) {
                error_log('Pictures: could not tidy up the files: ' . $e->getMessage());
            }
        });
    }
    $released[] = [$folder, $key];
}

/**
 * Saves a new profile picture or group icon: the file first, then its row in HBHub-Attachments. Returns the new
 * attachmentId. Works inside the caller's transaction; if that's rolled back, the file goes again at the end of the
 * request. Throws a RuntimeException when the file can't be written (and a PDOException when the row can't be saved).
 */
function hbHubPictureAdd(PDO $db, string $type, string $bytes): int
{
    $key = hbHubPictureFileSave(HBHUB_PICTURE_FILES_PICTURES, $bytes);
    hbHubPictureFileRelease($db, HBHUB_PICTURE_FILES_PICTURES, $key);
    $db->prepare('INSERT INTO `HBHub-Attachments` (attachmentType, attachmentKey) VALUES (?, ?)')->execute([$type, $key]);
    return (int) $db->lastInsertId();
}

/**
 * Deletes a profile picture's or group icon's row in HBHub-Attachments, and its file once that's committed.
 * Works inside the caller's transaction.
 */
function hbHubPictureDelete(PDO $db, int $attachmentId): void
{
    $statement = $db->prepare('SELECT attachmentKey FROM `HBHub-Attachments` WHERE attachmentId = ?');
    $statement->execute([$attachmentId]);
    $key = $statement->fetchColumn();
    $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$attachmentId]);
    hbHubPictureFileRelease($db, HBHUB_PICTURE_FILES_PICTURES, is_string($key) ? $key : null);
}

/**
 * Sends a picture and stops, for the scripts that have already checked that the asker may see it and that it's there:
 * $path is its file (hbHubPictureFilePath()), or null during the changeover when it's still only in the database, and
 * then $fromDatabase gets its bytes (null when it's gone after all, which is a 404).
 *
 * The cache rules are here, the same for all of them: "private" as they're never for everyone, and kept for good
 * ("immutable"), as the page links them with &v=<id>, so a new picture is a new address. $etag is that id's name
 * ('"user-avatar-12"'), and a browser that sends it back gets a 304 without the file being opened or the BLOB loaded.
 * The headers are only set here, after the checks, so a 404 never goes out saying it can be kept for a year.
 *
 * @param ?Closure(): ?string $fromDatabase
 */
function hbHubPictureFileSend(string $etag, string $type, ?string $path, ?Closure $fromDatabase = null): never
{
    $isSame = ($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag;
    $bytes = null;
    if (!$isSame && $path === null) {
        $bytes = $fromDatabase !== null ? $fromDatabase() : null;
        if (!is_string($bytes)) {
            http_response_code(404);
            exit;
        }
    }

    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if ($isSame) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $type);
    if ($path !== null) {
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
