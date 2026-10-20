<?php
// The red number on Chat in the side bar (navPanel.php): how many of the user's chats have messages they haven't read.
// Kept up to date by chatUnread.js, with the count from /otherPages/navUpdates, and on the chat page by chat.js's own polling.
require_once __DIR__ . '/userSettings.php';

const HBHUB_UNREAD_CHAT_ACTIVE = 1; // chatStatus in HBHub-Chats

/**
 * How many chats have unread messages: the ones with an unread count in the chat list (chat/index.php), so messages
 * from others, not deleted, not notices and not ghosts (only their ghost-banned sender sees those, assets/php/chat.php),
 * that came after the last one the user read.
 * Chats the user muted don't count, and neither do message requests, which have their own button in the chat page.
 */
function hbHubUnreadChatCount(PDO $db, int $userId, array $userSettings): int
{
    $mutedChatIds = hbHubMutedChatIds($userSettings);
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-ChatMembers` cm
        JOIN `HBHub-Chats` c ON c.chatId = cm.chatId AND c.chatStatus = ?
        WHERE cm.userId = ? AND cm.userAcknowledgedJoin = 1'
            . ($mutedChatIds !== [] ? ' AND cm.chatId NOT IN (' . implode(', ', array_fill(0, count($mutedChatIds), '?')) . ')' : '') . '
            AND EXISTS (
                SELECT 1 FROM `HBHub-Messages` m
                WHERE m.chatId = cm.chatId AND m.messageId > COALESCE(cm.lastReadMessageId, 0)
                    AND m.userId <> ? AND m.messageDeletedTimestamp IS NULL AND m.messageEvent = 0 AND m.messageIsGhost = 0
            )');
    $statement->execute([HBHUB_UNREAD_CHAT_ACTIVE, $userId, ...$mutedChatIds, $userId]);
    return (int) $statement->fetchColumn();
}
