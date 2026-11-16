<?php
// Group icons: /chat/icon?chat=id sends the chat's icon. Only for members of the chat,
// message requests included, so the requests popup can show the icon too.
// Site admins get every chat's icon, deleted chats' too, for the admin panel (admin/index.php and admin/chat.php).
// The page adds &v=<attachment id>, so a changed icon gets a new address and the old one can be cached for good.
// The icon is a file on disk (assets/php/pictureFiles.php, which also has the cache headers).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pictureFiles.php';

const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_CHAT_ICON_SITE_ROLE_ADMIN = 2; // userRole in HBHub-Users

$chatId = $_GET['chat'] ?? null;
if (!is_string($chatId) || !ctype_digit($chatId)) {
    http_response_code(404);
    exit;
}

$db = hbHubDatabase();
if ((int) $hbHubUser['userRole'] === HBHUB_CHAT_ICON_SITE_ROLE_ADMIN) {
    $statement = $db->prepare('SELECT chatIconAttachmentId FROM `HBHub-Chats` WHERE chatId = ? AND chatIconAttachmentId IS NOT NULL');
    $statement->execute([(int) $chatId]);
} else {
    $statement = $db->prepare('SELECT c.chatIconAttachmentId FROM `HBHub-ChatMembers` cm
        JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND c.chatIconAttachmentId IS NOT NULL AND cm.memberIsGhost = 0');
    $statement->execute([(int) $chatId, $hbHubUser['userId'], HBHUB_CHAT_STATUS_ACTIVE]);
}
$iconId = $statement->fetchColumn();
if ($iconId === false) {
    http_response_code(404);
    exit;
}
$iconId = (int) $iconId;

// They may see it. Nothing about caching is sent before the icon's file is known to be there.
$statement = $db->prepare('SELECT attachmentType, attachmentKey FROM `HBHub-Attachments` WHERE attachmentId = ?');
$statement->execute([$iconId]);
$icon = $statement->fetch();
$path = $icon !== false ? hbHubPictureFilePath(HBHUB_PICTURE_FILES_PICTURES, $icon['attachmentKey']) : null;
if ($path === null) {
    http_response_code(404);
    exit;
}

hbHubPictureFileSend('"chat-icon-' . $iconId . '"', $icon['attachmentType'], $path);
