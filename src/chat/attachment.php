<?php
// One attachment's file: /chat/attachment?id=attachmentId sends it, shown in the page for a picture, or as a download
// with &download=1 (and always for anything that isn't a picture). Zipped files (assets/php/chatAttachments.php) are
// unzipped on the way out, so what's downloaded is the file as it was sent.
// Only for members of the chat it was sent in, while its message isn't deleted and its tickets haven't run out.
// An attachment never changes, so its id is its ETag, and the browser's copy is used again after a quick check.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatAttachments.php';

const HBHUB_CHAT_ATTACHMENT_CHAT_ACTIVE = 1; // chatStatus in HBHub-Chats

$attachmentId = $_GET['id'] ?? null;
if (!is_string($attachmentId) || !ctype_digit($attachmentId) || strlen($attachmentId) > 10) {
    http_response_code(404);
    exit;
}

$db = hbHubDatabase();
$statement = $db->prepare('SELECT a.attachmentId, a.attachmentKey, a.attachmentName, a.attachmentType, a.attachmentKind, a.attachmentSize
    FROM `HBHub-ChatAttachments` a
    JOIN `HBHub-Messages` m ON m.attachmentId = a.attachmentId AND m.messageDeletedTimestamp IS NULL
    JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?
    JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ? AND cm.userAcknowledgedJoin = 1
    WHERE a.attachmentId = ? AND a.attachmentStatus = ? AND a.attachmentExpiresTimestamp > NOW()');
$statement->execute([HBHUB_CHAT_ATTACHMENT_CHAT_ACTIVE, $hbHubUser['userId'], (int) $attachmentId, HBHUB_CHAT_ATTACHMENT_KEPT]);
$attachment = $statement->fetch();

$dir = hbHubChatAttachmentsDir();
$path = $attachment !== false && $dir !== null ? $dir . '/' . $attachment['attachmentKey'] : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

// Private, as it's only for the chat's members. Checked above first, so a deleted or expired one is never a 304.
header('Cache-Control: private, no-cache');
$etag = '"chat-attachment-' . (int) $attachment['attachmentId'] . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    exit;
}

// Pictures can be shown in the page. Anything else only ever downloads, as a plain stream of bytes the browser won't open.
$isImage = in_array($attachment['attachmentType'], HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES, true);
$isDownload = !$isImage || ($_GET['download'] ?? null) === '1';
$name = $attachment['attachmentName'];
$asciiName = preg_replace('~[^A-Za-z0-9._ -]+~', '_', $name) ?? 'file';
header('Content-Type: ' . ($isImage ? $attachment['attachmentType'] : 'application/octet-stream'));
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (int) $attachment['attachmentSize']);

// Up to 50 MB, so it's streamed rather than read into memory, and isn't cut off on a slow connection
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ((int) $attachment['attachmentKind'] === HBHUB_CHAT_ATTACHMENT_KIND_ZIP) {
    $zip = new ZipArchive();
    $stream = $zip->open($path, ZipArchive::RDONLY) === true ? $zip->getStream(HBHUB_CHAT_ATTACHMENT_ZIP_ENTRY) : false;
    if ($stream === false) {
        // The headers are already set, but nothing has been sent yet, so this still turns into an error
        header_remove('Content-Length');
        header_remove('Content-Disposition');
        http_response_code(500);
        exit;
    }
    fpassthru($stream);
    fclose($stream);
    $zip->close();
} else {
    readfile($path);
}
