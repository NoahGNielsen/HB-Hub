<?php
// Group icons: /chat/icon?chat=id sends the chat's icon. Only for members of the chat,
// message requests included, so the requests popup can show the icon too.
// The page adds &v=<attachment id>, so a changed icon gets a new address and the old one can be cached for good.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats

$chatId = $_GET['chat'] ?? null;
if (!is_string($chatId) || !ctype_digit($chatId)) {
    http_response_code(404);
    exit;
}

$db = hbHubDatabase();
$statement = $db->prepare('SELECT c.chatIconAttachmentId FROM `HBHub-ChatMembers` cm
    JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
    WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND c.chatIconAttachmentId IS NOT NULL');
$statement->execute([(int) $chatId, $hbHubUser['userId'], HBHUB_CHAT_STATUS_ACTIVE]);
$iconId = $statement->fetchColumn();
if ($iconId === false) {
    http_response_code(404);
    exit;
}

// Private, as it's only for the chat's members
header('Cache-Control: private, max-age=31536000, immutable');
$etag = '"chat-icon-' . (int) $iconId . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    exit;
}

// Fetched separately, so a 304 never loads the image from the database
$statement = $db->prepare('SELECT attachmentType, attachmentFile FROM `HBHub-Attachments` WHERE attachmentId = ?');
$statement->execute([$iconId]);
$icon = $statement->fetch();
if ($icon === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $icon['attachmentType']);
header('Content-Length: ' . strlen($icon['attachmentFile']));
echo $icon['attachmentFile'];
