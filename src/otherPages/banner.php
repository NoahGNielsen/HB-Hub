<?php
// Profile banners: /banner?user=id sends the user's banner picture (assets/php/profile.php), a JPEG XL file kept with
// the chat attachments. For any logged in user, except for banned users' banners (they don't have a profile), which
// only site admins get, for the admin panel (admin/user.php).
// The page adds &v=<attachment id>, so a changed banner gets a new address and the old one can be cached for good.
// Browsers that can't show JPEG XL get it drawn by profile.js instead, from the same address.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/profile.php';

const BANNER_ROLE_ADMIN = 2; // userRole in HBHub-Users

$userId = $_GET['user'] ?? null;
if (!is_string($userId) || !ctype_digit($userId) || strlen($userId) > 10) {
    http_response_code(404);
    exit;
}

$db = hbHubDatabase();
$statement = $db->prepare('SELECT b.attachmentId, b.attachmentKey, b.attachmentType, b.attachmentSize
    FROM `HBHub-Users` u JOIN `HBHub-ChatAttachments` b ON b.attachmentId = u.userBannerAttachmentId AND b.attachmentStatus = ?
    WHERE u.userId = ? AND (u.userStatus <> ? OR ?)');
$statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT, (int) $userId, HBHUB_SESSION_STATUS_BANNED,
    (int) $hbHubUser['userRole'] === BANNER_ROLE_ADMIN ? 1 : 0]);
$banner = $statement->fetch();

$dir = hbHubChatAttachmentsDir();
$path = $banner !== false && $dir !== null ? $dir . '/' . $banner['attachmentKey'] : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

// Private, as it's only for logged in users
header('Cache-Control: private, max-age=31536000, immutable');
$etag = '"user-banner-' . (int) $banner['attachmentId'] . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $banner['attachmentType']);
header('Content-Length: ' . (int) $banner['attachmentSize']);
readfile($path);
