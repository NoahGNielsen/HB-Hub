<?php
// Browser notifications for new messages (notifications.js), sent along with the nav bar's numbers by
// otherPages/navUpdates.php: the newest message id in the user's chats (to ask after next time) and the unread
// messages from others that came after the id the browser last asked about, leaving out the chats the user muted.
// Only for users who turned notifications on in settings. Needs session.php (included first by every page anyway).
require_once __DIR__ . '/chatFormat.php';
require_once __DIR__ . '/userAvatar.php';
require_once __DIR__ . '/userSettings.php';

const HBHUB_NOTIFY_LIMIT = 5; // at most this many notifications at once, the newest ones
const HBHUB_NOTIFY_BODY_MAX = 120;
const HBHUB_NOTIFY_CHAT_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_NOTIFY_CHAT_GROUP = 2; // chatType in HBHub-Chats

/**
 * The new messages to show a browser notification for. Without $after only the newest id is returned, so turning
 * notifications on doesn't announce old messages. Just {"enabled": false} when the user has notifications turned off
 * in settings (maybe in another tab), so notifications.js stops asking.
 *
 * @return array{enabled: bool, latest?: int, messages?: list<array{id: int, chatId: int, title: string, body: string, icon: string}>}
 */
function hbHubMessageNotifications(PDO $db, int $userId, array $userSettings, mixed $after): array
{
    if (($userSettings['notifications'] ?? false) !== true) {
        return ['enabled' => false];
    }

    $mutedChatIds = hbHubMutedChatIds($userSettings); // muted from the chat menu, so no notifications from them

    $statement = $db->prepare('SELECT COALESCE(MAX(m.messageId), 0) FROM `HBHub-Messages` m
        JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ?
        JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?');
    $statement->execute([$userId, HBHUB_NOTIFY_CHAT_ACTIVE]);
    $latest = (int) $statement->fetchColumn();

    $messages = [];
    if (is_string($after) && ctype_digit($after) && strlen($after) <= 19) {
        // Newest first for the LIMIT, turned around below. Message requests (not accepted yet) are included too,
        // notices about a change in the chat (messageEvent, like a new group name) aren't. Neither are ghosts: messages
        // from a ghost-banned user, and chats the user only has a ghost invite to (assets/php/chat.php), which they can't see.
        $statement = $db->prepare('SELECT m.messageId, m.chatId, m.userId, m.messageContent, c.chatType, c.chatName,
                cm.userAcknowledgedJoin, u.userName, u.userAvatarAttachmentId
            FROM `HBHub-Messages` m
            JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = ? AND cm.memberIsGhost = 0
            JOIN `HBHub-Chats` c ON c.chatId = m.chatId AND c.chatStatus = ?
            JOIN `HBHub-Users` u ON u.userId = m.userId
            WHERE m.messageId > ? AND m.messageId <= ? AND m.userId <> ? AND m.messageDeletedTimestamp IS NULL AND m.messageEvent = 0
                AND m.messageIsGhost = 0
                AND m.messageId > COALESCE(cm.lastReadMessageId, 0) AND (u.userStatus IS NULL OR u.userStatus <> ?)'
                . ($mutedChatIds !== [] ? ' AND m.chatId NOT IN (' . implode(', ', array_fill(0, count($mutedChatIds), '?')) . ')' : '') . '
            ORDER BY m.messageId DESC LIMIT ' . HBHUB_NOTIFY_LIMIT);
        $statement->execute([$userId, HBHUB_NOTIFY_CHAT_ACTIVE, (int) $after, $latest, $userId, HBHUB_SESSION_STATUS_BANNED, ...$mutedChatIds]);

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
                $content = chatFormatPlain($content); // without the ** and __ (assets/php/chatFormat.php)
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

    return ['enabled' => true, 'latest' => $latest, 'messages' => $messages];
}
