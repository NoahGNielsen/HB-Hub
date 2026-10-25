<?php
// Chat attachments: files and pictures sent in a message, up to HBHUB_CHAT_ATTACHMENT_FILES_MAX in one. The chat page
// (chat/index.php) takes the upload, chat/attachment.php sends a file back, and chat/attachments.php lists the user's
// own and deletes them.
//
// The server's disk is small, so attachments are paid for with tickets. Everyone has HBHUB_CHAT_ATTACHMENT_TICKETS
// (site admins can add or take away some for one user in the admin panel), and each started HBHUB_CHAT_ATTACHMENT_TICKET_BYTES of a file takes one
// (as it was sent, so zipping it here doesn't make it cheaper - it matches the size people see). Every file in a
// message pays for itself, so three small files take three tickets.
// A file keeps its tickets for 90 days, or only 3 when it takes more than 5 of them, and then it expires: the file
// is deleted and the tickets are free again. Deleting the message (or just the attachment) frees them straight away.
// Expired files are swept up by hbHubChatAttachmentsSweep(), which the chat page and the upload call, and which the
// server's crontab runs every night until they're all gone (cron/nightly.php). Once all attachments together take the attachments_max_total_bytes in the
// config (9 GB by default), nobody can send new ones until some expire or are deleted.
//
// Pictures are turned into JPEG XL in the browser before they're sent (chat.js, chatJxlWorker.js), unless the user
// ticks the box to keep the original: lossless, except JPEGs, which get one that looks the same. They're stored as they come. Any other file is zipped here and
// unzipped again when it's downloaded.
//
// Files live on disk, not in the database: attachments_path in the config, by default a folder next to the config
// file, above the web root. Each one is named after its random attachmentKey, so the name never says what's in it.
// The rows are in HBHub-ChatAttachments, and each one points at the message it was sent in with messageId.
// Profile banners (profile.php) are kept here too, without a chat or a message, and never expire.
require_once __DIR__ . '/session.php';

const HBHUB_CHAT_ATTACHMENT_TICKETS = 10; // per user
const HBHUB_CHAT_ATTACHMENT_TICKET_BYTES = 5 * 1024 * 1024;
// All the files in one message together, as uploaded: all tickets (post_max_size in src/.htaccess fits it)
const HBHUB_CHAT_ATTACHMENT_MAX_BYTES = HBHUB_CHAT_ATTACHMENT_TICKETS * HBHUB_CHAT_ATTACHMENT_TICKET_BYTES;
const HBHUB_CHAT_ATTACHMENT_FILES_MAX = 10; // files in one message (PHP's max_file_uploads is 20)
const HBHUB_CHAT_ATTACHMENT_DAYS = 90;
const HBHUB_CHAT_ATTACHMENT_BIG_TICKETS = 5; // a file taking more tickets than this...
const HBHUB_CHAT_ATTACHMENT_BIG_DAYS = 3; // ...only keeps them for this many days
const HBHUB_CHAT_ATTACHMENT_NAME_MAX = 200; // characters of the file name that are kept
const HBHUB_CHAT_ATTACHMENT_ZIP_ENTRY = 'file'; // the one file inside a zipped attachment
const HBHUB_CHAT_ATTACHMENT_DEFAULT_MAX_TOTAL = 9 * 1024 * 1024 * 1024; // attachments_max_total_bytes when the config leaves it out

// attachmentKind in HBHub-ChatAttachments: how it's stored
const HBHUB_CHAT_ATTACHMENT_KIND_IMAGE = 0; // a picture, stored as it came (the user kept the original, or it's a kind that isn't converted)
const HBHUB_CHAT_ATTACHMENT_KIND_JXL = 1; // a picture the browser turned into JPEG XL
const HBHUB_CHAT_ATTACHMENT_KIND_ZIP = 2; // any other file, zipped
const HBHUB_CHAT_ATTACHMENT_KIND_FILE = 3; // any other file, stored as it came because zipping didn't make it smaller

// attachmentStatus in HBHub-ChatAttachments
const HBHUB_CHAT_ATTACHMENT_KEPT = 0; // the file is there and takes its tickets
const HBHUB_CHAT_ATTACHMENT_DELETED = 1; // deleted with its message, from the attachments popup, or with the chat or account
const HBHUB_CHAT_ATTACHMENT_EXPIRED = 2; // its tickets ran out

// Pictures that are shown in the chat, by what's in the file (never SVG, it can carry scripts).
// Anything else is a file to download.
const HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/jxl'];

/**
 * The site config, read once. The attachment functions need it from pages that don't load it themselves.
 */
function hbHubChatAttachmentsConfig(): array
{
    static $config = null;
    return $config ??= require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
}

/**
 * The folder the files are kept in, made the first time it's needed. A .htaccess that denies everything goes in with
 * it, in case attachments_path is ever set to somewhere the web server can reach.
 * Null when it can't be made.
 */
function hbHubChatAttachmentsDir(): ?string
{
    $dir = rtrim((string) (hbHubChatAttachmentsConfig()['attachments_path'] ?? dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubAttachments'), '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/**
 * How many bytes all attachments together may take on disk (attachments_max_total_bytes in the config).
 */
function hbHubChatAttachmentsMaxTotal(): int
{
    return max(0, (int) (hbHubChatAttachmentsConfig()['attachments_max_total_bytes'] ?? HBHUB_CHAT_ATTACHMENT_DEFAULT_MAX_TOTAL));
}

/**
 * Bytes on disk taken by all attachments that are still there.
 */
function hbHubChatAttachmentsTotalBytes(PDO $db): int
{
    $statement = $db->prepare('SELECT COALESCE(SUM(attachmentStoredSize), 0) FROM `HBHub-ChatAttachments` WHERE attachmentStatus = ?');
    $statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT]);
    return (int) $statement->fetchColumn();
}

/**
 * Why new attachments can't be sent right now, or null when they can: 'disabled' when the config turns uploads off
 * (site_save_diskspace 2), 'storageFull' when all attachments together take the most they may.
 */
function hbHubChatAttachmentsBlocked(PDO $db): ?string
{
    if ((int) (hbHubChatAttachmentsConfig()['site_save_diskspace'] ?? 0) === 2) {
        return 'disabled';
    }
    return hbHubChatAttachmentsTotalBytes($db) >= hbHubChatAttachmentsMaxTotal() ? 'storageFull' : null;
}

/**
 * How many tickets a file of $bytes (as it was sent, not zipped) takes: one for every started
 * HBHUB_CHAT_ATTACHMENT_TICKET_BYTES, at least one. chat.js works out the same from the file it's about to send.
 */
function hbHubChatAttachmentTickets(int $bytes): int
{
    return max(1, (int) ceil($bytes / HBHUB_CHAT_ATTACHMENT_TICKET_BYTES));
}

/**
 * How many days a file taking $tickets keeps them.
 */
function hbHubChatAttachmentDays(int $tickets): int
{
    return $tickets > HBHUB_CHAT_ATTACHMENT_BIG_TICKETS ? HBHUB_CHAT_ATTACHMENT_BIG_DAYS : HBHUB_CHAT_ATTACHMENT_DAYS;
}

/**
 * How many tickets the user has in all: HBHUB_CHAT_ATTACHMENT_TICKETS, plus the ones a site admin added or minus the
 * ones they took away in the admin panel (userAttachmentTicketsExtra in HBHub-Users, admin/user.php). Never below 0.
 * Taking tickets away doesn't touch the attachments already sent, the user just can't send more until they're under.
 * One attachment can still be at most HBHUB_CHAT_ATTACHMENT_TICKETS big, however many tickets they have.
 */
function hbHubChatAttachmentTicketsTotal(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT userAttachmentTicketsExtra FROM `HBHub-Users` WHERE userId = ?');
    $statement->execute([$userId]);
    return max(0, HBHUB_CHAT_ATTACHMENT_TICKETS + (int) $statement->fetchColumn());
}

/**
 * How many tickets the user's attachments take right now. Expired ones that haven't been swept up yet don't count.
 */
function hbHubChatAttachmentTicketsUsed(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT COALESCE(SUM(attachmentTickets), 0) FROM `HBHub-ChatAttachments`
        WHERE userId = ? AND attachmentStatus = ? AND attachmentExpiresTimestamp > NOW()');
    $statement->execute([$userId, HBHUB_CHAT_ATTACHMENT_KEPT]);
    return (int) $statement->fetchColumn();
}

/**
 * How many of the user's tickets are free. Expired ones that haven't been swept up yet count as free already.
 */
function hbHubChatAttachmentTicketsFree(PDO $db, int $userId): int
{
    return max(0, hbHubChatAttachmentTicketsTotal($db, $userId) - hbHubChatAttachmentTicketsUsed($db, $userId));
}

/**
 * Marks the attachments that match $condition as gone ($status), deletes their files and so frees their tickets.
 * $condition is SQL on `a` (HBHub-ChatAttachments) and `m` (the message it's in, if any), written in the code -
 * only $params come from outside. Returns how many were removed.
 * Call it after the change that removes them is committed, as a deleted file can't come back if that's rolled back.
 */
function hbHubChatAttachmentsRemove(PDO $db, string $condition, array $params, int $status = HBHUB_CHAT_ATTACHMENT_DELETED): int
{
    $statement = $db->prepare('SELECT a.attachmentId, a.attachmentKey FROM `HBHub-ChatAttachments` a
        LEFT JOIN `HBHub-Messages` m ON m.messageId = a.messageId
        WHERE a.attachmentStatus = ? AND (' . $condition . ')');
    $statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT, ...$params]);
    $attachments = $statement->fetchAll();
    if ($attachments === []) {
        return 0;
    }

    // The name goes with the file: only that there was an attachment, how big and when stays with the message
    $update = $db->prepare('UPDATE `HBHub-ChatAttachments` SET attachmentStatus = ?, attachmentName = NULL, attachmentRemovedTimestamp = NOW()
        WHERE attachmentId = ? AND attachmentStatus = ?');
    $dir = hbHubChatAttachmentsDir();
    $removed = 0;
    foreach ($attachments as $attachment) {
        $update->execute([$status, $attachment['attachmentId'], HBHUB_CHAT_ATTACHMENT_KEPT]);
        if ($update->rowCount() === 0) {
            continue; // someone else got to it first
        }
        $removed++;
        if ($dir !== null && is_file($dir . '/' . $attachment['attachmentKey'])) {
            @unlink($dir . '/' . $attachment['attachmentKey']);
        }
    }
    return $removed;
}

/**
 * Deletes the files whose tickets have run out, a batch at a time, so a request is never held up for long.
 * Returns how many were removed (the nightly cron/nightly.php sweeps again until it's 0).
 */
function hbHubChatAttachmentsSweep(PDO $db): int
{
    return hbHubChatAttachmentsRemove($db, 'a.attachmentId IN (SELECT attachmentId FROM (
            SELECT attachmentId FROM `HBHub-ChatAttachments`
            WHERE attachmentStatus = ' . HBHUB_CHAT_ATTACHMENT_KEPT . ' AND attachmentExpiresTimestamp <= NOW()
            LIMIT 50
        ) expired)', [], HBHUB_CHAT_ATTACHMENT_EXPIRED);
}

/**
 * The picture type of the file by what's in it (one of HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES), or null when it isn't one
 * of those pictures.
 */
function hbHubChatAttachmentImageType(string $path): ?string
{
    // JPEG XL: a bare codestream, or one in its ISO box container. PHP's getimagesize() doesn't know it.
    $start = (string) @file_get_contents($path, false, null, 0, 12);
    if (str_starts_with($start, "\xFF\x0A") || $start === "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A") {
        return 'image/jxl';
    }

    $image = @getimagesize($path);
    if ($image === false) {
        return null;
    }
    $type = $image['mime'] === 'image/x-ms-bmp' ? 'image/bmp' : $image['mime'];
    return in_array($type, HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES, true) ? $type : null;
}

/**
 * SQL for how many attachments the message $alias (an `HBHub-Messages` alias, written in the code) was sent with,
 * gone ones included, for lists that only name them ("3 attachments") rather than show them.
 */
function hbHubChatAttachmentsCountSql(string $alias): string
{
    return '(SELECT COUNT(*) FROM `HBHub-ChatAttachments` ca WHERE ca.messageId = ' . $alias . '.messageId)';
}

/**
 * What a message's attachments are called where they're only named: "Attachment", or "3 attachments".
 */
function hbHubChatAttachmentsLabel(int $count): string
{
    return $count === 1 ? 'Attachment' : $count . ' attachments';
}

/**
 * The files of one upload ($_FILES['files'], sent as files[]) as a list, one $_FILES-style array each, in the order
 * they were sent. Null when it isn't a list of files at all. A single file sent without the [] counts as one too.
 *
 * @return ?list<array{name: mixed, tmp_name: mixed, error: mixed}>
 */
function hbHubChatAttachmentUploads(mixed $files): ?array
{
    if (!is_array($files) || !isset($files['error'])) {
        return null;
    }
    if (!is_array($files['error'])) {
        return [$files];
    }
    $uploads = [];
    foreach (array_keys($files['error']) as $index) {
        $uploads[] = [
            'name' => $files['name'][$index] ?? null,
            'tmp_name' => $files['tmp_name'][$index] ?? null,
            'error' => $files['error'][$index],
        ];
    }
    return $uploads;
}

/**
 * Deletes the files of uploads that were got ready (hbHubChatAttachmentPrepare()) but are never saved after all.
 *
 * @param list<array{path: string}> $prepared
 */
function hbHubChatAttachmentsDiscard(array $prepared): void
{
    foreach ($prepared as $attachment) {
        @unlink($attachment['path']);
    }
}

/**
 * The uploaded file's name made safe to keep and send back in a header: no folders, control characters or quotes,
 * and not too long (the end, with the extension, is kept).
 */
function hbHubChatAttachmentName(mixed $name): string
{
    $name = is_string($name) ? $name : '';
    $name = preg_replace('~[\p{Cc}"\\\\/]+~u', '', $name) ?? '';
    $name = trim($name, " .\t");
    if (mb_strlen($name) > HBHUB_CHAT_ATTACHMENT_NAME_MAX) {
        $extension = mb_substr(mb_strrchr($name, '.') ?: '', 0, 20);
        $name = mb_substr($name, 0, HBHUB_CHAT_ATTACHMENT_NAME_MAX - mb_strlen($extension)) . $extension;
    }
    return $name !== '' ? $name : 'file';
}

/**
 * Gets an uploaded attachment ready to save: checks it, and puts it in the attachments folder under a new random key,
 * zipped when it isn't a picture. $converted says the browser turned it into JPEG XL.
 * Returns what to save in HBHub-ChatAttachments, or an error code for chat.js: tooBig, empty, uploadFailed, storageError.
 * When it's never saved after all, the file at $prepared['path'] has to be deleted.
 *
 * @return array{path: string, key: string, name: string, type: string, kind: int, size: int, storedSize: int}|string
 */
function hbHubChatAttachmentPrepare(mixed $file, bool $converted): array|string
{
    if (!is_array($file) || !is_int($file['error'] ?? null)) {
        return 'uploadFailed';
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return 'tooBig';
    }
    $tmp = $file['tmp_name'] ?? null;
    if ($file['error'] !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
        return 'uploadFailed';
    }
    $size = (int) filesize($tmp);
    if ($size === 0) {
        return 'empty';
    }
    if ($size > HBHUB_CHAT_ATTACHMENT_MAX_BYTES) {
        return 'tooBig';
    }

    $dir = hbHubChatAttachmentsDir();
    if ($dir === null) {
        return 'storageError';
    }
    $key = bin2hex(random_bytes(16));
    $path = $dir . '/' . $key;
    $name = hbHubChatAttachmentName($file['name'] ?? null);

    // A picture is stored as it is - JPEG XL only counts as converted when it really is one
    $imageType = hbHubChatAttachmentImageType($tmp);
    if ($imageType !== null) {
        if (!move_uploaded_file($tmp, $path)) {
            return 'storageError';
        }
        $kind = $converted && $imageType === 'image/jxl' ? HBHUB_CHAT_ATTACHMENT_KIND_JXL : HBHUB_CHAT_ATTACHMENT_KIND_IMAGE;
        return ['path' => $path, 'key' => $key, 'name' => $name, 'type' => $imageType, 'kind' => $kind, 'size' => $size, 'storedSize' => $size];
    }

    // Anything else is zipped as hard as deflate goes. Already-packed files (videos, zips) don't get any smaller,
    // so when the zip isn't smaller the file is kept as it came instead.
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        $zipped = $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true
            && $zip->addFile($tmp, HBHUB_CHAT_ATTACHMENT_ZIP_ENTRY)
            && $zip->setCompressionName(HBHUB_CHAT_ATTACHMENT_ZIP_ENTRY, ZipArchive::CM_DEFLATE, 9)
            && $zip->close();
        clearstatcache(true, $path);
        if ($zipped && is_file($path) && filesize($path) < $size) {
            @unlink($tmp);
            return ['path' => $path, 'key' => $key, 'name' => $name, 'type' => 'application/octet-stream',
                'kind' => HBHUB_CHAT_ATTACHMENT_KIND_ZIP, 'size' => $size, 'storedSize' => (int) filesize($path)];
        }
        @unlink($path);
    }

    if (!move_uploaded_file($tmp, $path)) {
        return 'storageError';
    }
    return ['path' => $path, 'key' => $key, 'name' => $name, 'type' => 'application/octet-stream',
        'kind' => HBHUB_CHAT_ATTACHMENT_KIND_FILE, 'size' => $size, 'storedSize' => $size];
}

/**
 * A size for people: "820 KB", "4.3 MB".
 */
function hbHubChatAttachmentSizeLabel(int $bytes): string
{
    if ($bytes < 1024 * 1024) {
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
    return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . ' MB';
}
