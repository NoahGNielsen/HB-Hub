<?php
// One attachment's file: /chat/attachment?id=attachmentId sends it, shown in the page for a picture or a video, or as a
// download with &download=1 (and always for anything else). Zipped files (assets/php/chatAttachments.php) are
// unzipped on the way out, so what's downloaded is the file as it was sent.
// A video's player asks for one piece at a time (a Range header), so it can start straight away and jump around, and
// Safari won't play it at all otherwise. Only files stored as they are can be sent in pieces, which videos always are.
// Only for members of the chat it was sent in, while its message isn't deleted and its tickets haven't run out.
// One sent by a ghost-banned user (messageIsGhost, assets/php/chat.php) is only for them.
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
    JOIN `HBHub-Messages` m ON m.messageId = a.messageId AND m.messageDeletedTimestamp IS NULL
    JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?
    JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ? AND cm.userAcknowledgedJoin = 1
    WHERE a.attachmentId = ? AND a.attachmentStatus = ? AND a.attachmentExpiresTimestamp > NOW()
        AND (m.messageIsGhost = 0 OR m.userId = ?)');
$statement->execute([HBHUB_CHAT_ATTACHMENT_CHAT_ACTIVE, $hbHubUser['userId'], (int) $attachmentId, HBHUB_CHAT_ATTACHMENT_KEPT, $hbHubUser['userId']]);
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

// Pictures and videos can be shown in the page. Anything else only ever downloads, as a plain stream of bytes the browser won't open.
$isShown = in_array($attachment['attachmentType'], [...HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES, ...HBHUB_CHAT_ATTACHMENT_VIDEO_TYPES], true);
$isDownload = !$isShown || ($_GET['download'] ?? null) === '1';
$name = $attachment['attachmentName'];
$asciiName = preg_replace('~[^A-Za-z0-9._ -]+~', '_', $name) ?? 'file';
header('Content-Type: ' . ($isShown ? $attachment['attachmentType'] : 'application/octet-stream'));
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));

// The piece asked for, as [first byte, last byte], when it's one piece of a file stored as it is. Several pieces at
// once are never asked for by a player, so those just get the whole file, which a Range header allows.
$size = (int) $attachment['attachmentSize'];
$range = null;
$isStoredAsIs = (int) $attachment['attachmentKind'] !== HBHUB_CHAT_ATTACHMENT_KIND_ZIP;
if ($isStoredAsIs) {
    header('Accept-Ranges: bytes');
    if (preg_match('~^bytes=(\d*)-(\d*)$~', trim((string) ($_SERVER['HTTP_RANGE'] ?? '')), $match) === 1 && $match[1] . $match[2] !== '') {
        // "bytes=500-" is from byte 500 to the end, "bytes=-500" is the last 500 bytes
        $first = $match[1] !== '' ? (int) $match[1] : max(0, $size - (int) $match[2]);
        $last = $match[1] !== '' && $match[2] !== '' ? min((int) $match[2], $size - 1) : $size - 1;
        if ($first > $last || $first >= $size) {
            header_remove('Content-Disposition');
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $range = [$first, $last];
    }
}
if ($range !== null) {
    http_response_code(206);
    header('Content-Range: bytes ' . $range[0] . '-' . $range[1] . '/' . $size);
    header('Content-Length: ' . ($range[1] - $range[0] + 1));
} else {
    header('Content-Length: ' . $size);
}

// Up to 50 MB, so it's streamed rather than read into memory, and isn't cut off on a slow connection
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($range !== null) {
    $file = fopen($path, 'rb');
    fseek($file, $range[0]);
    $left = $range[1] - $range[0] + 1;
    while ($left > 0 && !feof($file) && !connection_aborted()) {
        $chunk = fread($file, min(64 * 1024, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($file);
} elseif ((int) $attachment['attachmentKind'] === HBHUB_CHAT_ATTACHMENT_KIND_ZIP) {
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
