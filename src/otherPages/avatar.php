<?php
// Profile pictures: /avatar?user=id sends the user's profile picture. For any logged in user,
// except for banned users' pictures (they don't have a profile) - only site admins see those, in the admin panel.
// The page adds &v=<attachment id>, so a changed picture gets a new address and the old one can be cached for good.
// The picture is a file on disk (assets/php/pictureFiles.php, which also has the cache headers).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pictureFiles.php';

const AVATAR_ROLE_ADMIN = 2; // userRole in HBHub-Users

$userId = $_GET['user'] ?? null;
if (!is_string($userId) || !ctype_digit($userId)) {
    http_response_code(404);
    exit;
}

// Who may see it comes first, and nothing about caching is sent before the picture is known to be there
$db = hbHubDatabase();
$statement = $db->prepare('SELECT a.attachmentId, a.attachmentType, a.attachmentKey
    FROM `HBHub-Users` u JOIN `HBHub-Attachments` a ON a.attachmentId = u.userAvatarAttachmentId
    WHERE u.userId = ? AND (u.userStatus <> ? OR ?)');
$statement->execute([(int) $userId, HBHUB_SESSION_STATUS_BANNED, (int) ((int) $hbHubUser['userRole'] === AVATAR_ROLE_ADMIN)]);
$avatar = $statement->fetch();
$path = $avatar !== false ? hbHubPictureFilePath(HBHUB_PICTURE_FILES_PICTURES, $avatar['attachmentKey']) : null;
if ($path === null) {
    http_response_code(404);
    exit;
}

hbHubPictureFileSend('"user-avatar-' . (int) $avatar['attachmentId'] . '"', $avatar['attachmentType'], $path);
