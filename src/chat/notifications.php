<?php
// New messages for notifications.js: /chat/notifications?after=messageId returns, as JSON, the newest message id
// in the user's chats (to ask after next time) and the unread messages from others that came after that id.
// Without ?after= only the newest id is returned, so turning notifications on doesn't announce old messages.
// Returns {"enabled": false} when the user has notifications turned off in settings.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';

const HBHUB_NOTIFY_LIMIT = 5; // at most this many notifications at once, the newest ones
const HBHUB_NOTIFY_BODY_MAX = 120;
const HBHUB_NOTIFY_CHAT_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_NOTIFY_CHAT_GROUP = 2; // chatType in HBHub-Chats

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ((hbHubUserSettings($hbHubUser['userSettings'])['notifications'] ?? false) !== true) {
    echo json_encode(['enabled' => false]);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$statement = $db->prepare('SELECT COALESCE(MAX(m.messageId), 0) FROM `HBHub-Messages` m
    JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ?
    JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?');
$statement->execute([$userId, HBHUB_NOTIFY_CHAT_ACTIVE]);
$latest = (int) $statement->fetchColumn();

$after = $_GET['after'] ?? null;
$messages = [];
if (is_string($after) && ctype_digit($after) && strlen($after) <= 19) {
    // Newest first for the LIMIT, turned around below. Message requests (not accepted yet) are included too.
    $statement = $db->prepare('SELECT m.messageId, m.chatId, m.userId, m.messageContent, c.chatType, c.chatName,
            cm.userAcknowledgedJoin, u.userName, u.userAvatarAttachmentId
        FROM `HBHub-Messages` m
        JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ?
        JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?
        JOIN `HBHub-Users` u ON u.userId = m.userId
        WHERE m.messageId > ? AND m.messageId <= ? AND m.userId <> ? AND m.messageDeletedTimestamp IS NULL
            AND m.messageId > COALESCE(cm.lastReadMessageId, 0) AND u.userStatus <> ?
        ORDER BY m.messageId DESC LIMIT ' . HBHUB_NOTIFY_LIMIT);
    $statement->execute([$userId, HBHUB_NOTIFY_CHAT_ACTIVE, (int) $after, $latest, $userId, HBHUB_SESSION_STATUS_BANNED]);

    foreach (array_reverse($statement->fetchAll()) as $message) {
        $isGroup = (int) $message['chatType'] === HBHUB_NOTIFY_CHAT_GROUP;
        if (!$message['userAcknowledgedJoin']) {
            $title = 'Message request from ' . $message['userName'];
        } elseif ($isGroup && $message['chatName'] !== null && $message['chatName'] !== '') {
            $title = $message['userName'] . ' in ' . $message['chatName'];
        } else {
            $title = $message['userName'];
        }

        $content = $message['messageContent'];
        if ($content === null) {
            $body = 'Sent an attachment';
        } elseif (preg_match('~^https://media\.giphy\.com/media/[A-Za-z0-9]{1,64}/giphy\.gif$~', $content) === 1) {
            $body = 'Sent a GIF'; // a GIF message is just its address, see chatGifUrl() in chat/index.php
        } else {
            $content = preg_replace('~\s+~u', ' ', $content) ?? $content;
            $body = mb_strlen($content) > HBHUB_NOTIFY_BODY_MAX ? mb_substr($content, 0, HBHUB_NOTIFY_BODY_MAX - 1) . '…' : $content;
        }

        $avatarId = $message['userAvatarAttachmentId'] !== null ? (int) $message['userAvatarAttachmentId'] : null;
        $messages[] = [
            'id' => (int) $message['messageId'],
            'chatId' => (int) $message['chatId'],
            'title' => $title,
            'body' => $body,
            'icon' => hbHubAvatarUrl((int) $message['userId'], $avatarId) ?? '/assets/images/icons/chat.png',
        ];
    }
}

echo json_encode(['enabled' => true, 'latest' => $latest, 'messages' => $messages], JSON_INVALID_UTF8_SUBSTITUTE);
