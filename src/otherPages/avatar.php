<?php
// Profile pictures: /otherPages/avatar?user=id sends the user's profile picture. For any logged in user,
// except for banned users' pictures (they don't have a profile).
// The page adds &v=<attachment id>, so a changed picture gets a new address and the old one can be cached for good.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$userId = $_GET['user'] ?? null;
if (!is_string($userId) || !ctype_digit($userId)) {
    http_response_code(404);
    exit;
}

$db = hbHubDatabase();
$statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users`
    WHERE userId = ? AND userStatus <> ? AND userAvatarAttachmentId IS NOT NULL');
$statement->execute([(int) $userId, HBHUB_SESSION_STATUS_BANNED]);
$avatarId = $statement->fetchColumn();
if ($avatarId === false) {
    http_response_code(404);
    exit;
}

// Private, as it's only for logged in users
header('Cache-Control: private, max-age=31536000, immutable');
$etag = '"user-avatar-' . (int) $avatarId . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    exit;
}

// Fetched separately, so a 304 never loads the image from the database
$statement = $db->prepare('SELECT attachmentType, attachmentFile FROM `HBHub-Attachments` WHERE attachmentId = ?');
$statement->execute([$avatarId]);
$avatar = $statement->fetch();
if ($avatar === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $avatar['attachmentType']);
header('Content-Length: ' . strlen($avatar['attachmentFile']));
echo $avatar['attachmentFile'];
