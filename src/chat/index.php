<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/account.php'; // the emoji names may use, which reactions are made of too
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chat.php'; // the kinds of chat, roles, notices and the changes the admin panel makes too
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatAttachments.php'; // files and pictures in messages, and the tickets they take
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatFormat.php'; // **bold**, lists, code blocks and links in messages
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatUnread.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/giphy.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php'; // the nav bar's request, which polling takes along
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // the class chat everyone at HTX is put in
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/urlBlacklist.php'; // IP loggers and other links that can't be sent
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';

const HBHUB_CHAT_MEMBERS_MAX = 200; // per group DM, the creator included
const HBHUB_CHAT_REQUESTS_PER_HOUR = 100; // message requests one user can send in an hour, new chats and Add to chat together
const HBHUB_CHAT_NICKNAME_MAX = 35; // same as userName
const HBHUB_CHAT_MESSAGE_MAX = 2500; // messageContent column
const HBHUB_CHAT_MESSAGES_SHOWN = 20; // the newest ones when a chat opens, and how many more each time chat.js loads older ones
const HBHUB_CHAT_MESSAGES_UNREAD_MAX = 100; // with more unread than SHOWN, a chat opens with up to this many, so the "New messages" line is there
const HBHUB_CHAT_MESSAGES_POLLED = 100; // new ones per poll, a later poll gets the rest
const HBHUB_CHAT_MESSAGES_JUMP_MAX = 500; // per request when clicking a reply quote loads back to its message, chat.js asks again for the rest
const HBHUB_CHAT_SEND_MAX = 10; // messages one user can send (in all chats together, attachments and GIFs too) per HBHUB_CHAT_SEND_SECONDS
const HBHUB_CHAT_SEND_SECONDS = 5;
const HBHUB_CHAT_REACTIONS_MAX = 20; // different emoji under one message
const HBHUB_CHAT_LOOKING_SECONDS = 10; // a member whose open chat hasn't checked in for this long isn't looking at it (no @mention notification then)
const HBHUB_CHAT_LOOKING_EVERY = 4; // seconds between updates of memberLastViewedTimestamp, as chat.js checks in every 2
const HBHUB_CHAT_TYPING_SECONDS = 8; // "is typing..." goes away when a member's typing hasn't checked in for this long (closed the tab, went offline)
const HBHUB_CHAT_TYPING_EVERY = 3; // seconds between updates of memberTypingTimestamp while typing, as chat.js says so every 2
const HBHUB_CHAT_REACTION_LENGTH_MAX = 32; // reactionEmoji column, in characters (👨‍👩‍👧‍👦 is 7 joined together)
const HBHUB_CHAT_REACTION_NAMES_SHOWN = 10; // who reacted, when pointing at a reaction, before it says "and 4 others"
// What a reaction can be made of besides the emoji names may use (HBHUB_LATIN_TEXT_EMOJI): the few emoji outside those
// ranges (© ® ‼ ⁉ ™ ℹ Ⓜ ▪ ▶ ◀ ◻ ⤴ ⤵ 〰 〽 ㊗ ㊙), and what changes or joins emoji (the emoji look, joiners, flag tags)
const HBHUB_CHAT_REACTION_EXTRA_EMOJI = '\x{A9}\x{AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{24C2}\x{25AA}-\x{25FE}\x{2934}\x{2935}'
    . '\x{3030}\x{303D}\x{3297}\x{3299}';
const HBHUB_CHAT_REACTION_JOINERS = '\x{FE0F}\x{200D}\x{E0020}-\x{E007F}'; // skin tones (1F3FB-1F3FF) are already among the emoji
const HBHUB_CHAT_ICON_MAX_BYTES = 2 * 1024 * 1024;
const HBHUB_CHAT_ICON_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']; // no SVG, it can carry scripts
// Mute chat in the chat menu: minutes => label, 0 lasts until the user unmutes it
const HBHUB_CHAT_MUTE_OPTIONS = [
    15 => 'For 15 minutes',
    60 => 'For 1 hour',
    180 => 'For 3 hours',
    480 => 'For 8 hours',
    1440 => 'For 24 hours',
    0 => 'Forever',
];
// messageDeletedByAdmin in HBHub-Messages: who deleted a deleted message => what it shows as.
// Site admins delete messages from the admin panel (admin/user.php and admin/chat.php).
const HBHUB_CHAT_DELETED_LABELS = [0 => 'Message deleted', 1 => 'Deleted by Group Admin', 2 => 'Deleted by Site Admin'];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
// Site admins get "Admin panel" in the right-click menus on chats, messages and group members
$chatIsSiteAdmin = (int) $hbHubUser['userRole'] === HBHUB_CHAT_SITE_ROLE_ADMIN;
// Ghost banned by a site admin (see the top of assets/php/chat.php): what they do here only shows to themselves.
// Nothing on the page is any different for them, so they can't tell.
$chatIsGhost = (bool) $hbHubUser['userGhostBanned'];
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
$chatGifsEnabled = hbHubGiphyApiKey($siteConfig) !== null; // the GIF button and picker

// The class chat is renamed once the class has moved up a year (July 1st), and accounts from before class chats get theirs
hbHubClassChatSyncIfDue($db, $userId, hbHubUserSettings($hbHubUser['userSettings']));

/**
 * The chat with the id, or null when it doesn't exist, isn't active or the user isn't a member of it.
 * A chat the user hasn't accepted yet (a message request) only counts when $isRequest is true,
 * and then it's the only kind that counts. An invite from a ghost-banned user (memberIsGhost) never counts.
 *
 * @return ?array{chatId: int, chatType: int, chatClassKey: ?string, memberRole: int, memberMutedUntil: ?string, lastReadMessageId: ?int}
 */
function chatMembership(PDO $db, int $userId, mixed $chatId, bool $isRequest = false): ?array
{
    if (!is_scalar($chatId) || !ctype_digit((string) $chatId)) {
        return null;
    }

    $statement = $db->prepare('SELECT c.chatId, c.chatType, c.chatClassKey, cm.memberRole, cm.memberMutedUntil, cm.lastReadMessageId
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND cm.userAcknowledgedJoin = ? AND cm.memberIsGhost = 0');
    $statement->execute([(int) $chatId, $userId, HBHUB_CHAT_STATUS_ACTIVE, $isRequest ? 0 : 1]);
    return $statement->fetch() ?: null;
}

/**
 * Whether the user is an admin of the group, the owner included.
 *
 * @param array{chatType: int, memberRole: int} $chat from chatMembership()
 */
function chatIsGroupAdmin(array $chat): bool
{
    return (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP
        && in_array((int) $chat['memberRole'], [HBHUB_CHAT_ROLE_ADMIN, HBHUB_CHAT_ROLE_OWNER], true);
}

/**
 * Whether the user is the group's owner.
 *
 * @param array{chatType: int, memberRole: int} $chat from chatMembership()
 */
function chatIsGroupOwner(array $chat): bool
{
    return (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP && (int) $chat['memberRole'] === HBHUB_CHAT_ROLE_OWNER;
}

/**
 * Whether the user is muted in the chat right now, so they can't send or edit messages.
 *
 * @param array{memberMutedUntil: ?string} $chat from chatMembership()
 */
function chatIsMuted(array $chat): bool
{
    return $chat['memberMutedUntil'] !== null && strtotime($chat['memberMutedUntil']) > time();
}

/**
 * The message with the id, or null when the chat has no such message, or it's someone else's ghost (a message only its
 * ghost-banned sender sees, assets/php/chat.php). Its text is what the user sees, so their own edits while ghost banned.
 *
 * @return ?array{messageId: int, userId: int, messageContent: ?string, messageDeletedTimestamp: ?string, messageIsGhost: int}
 */
function chatMessage(PDO $db, int $userId, int $chatId, mixed $messageId): ?array
{
    if (!is_scalar($messageId) || !ctype_digit((string) $messageId)) {
        return null;
    }

    // Not a notice, so they can't be edited, deleted or replied to
    $statement = $db->prepare('SELECT m.messageId, m.userId, ' . chatGhostContentSql('m', $userId) . ' AS messageContent,
            m.messageDeletedTimestamp, m.messageIsGhost
        FROM `HBHub-Messages` m WHERE m.messageId = ? AND m.chatId = ? AND m.messageEvent = 0
            AND ' . chatGhostVisibleSql('m', 'messageIsGhost', $userId));
    $statement->execute([(int) $messageId, $chatId]);
    return $statement->fetch() ?: null;
}

/**
 * Messages in the chat, oldest at the top, each with the message it replies to (r) if any.
 * Notices are in there too, with the name of who they're about (eventUserName).
 * Each one has its attachments (attachments, from chatAttachments()).
 * Without $afterId it's the newest $limit ones (before $beforeId, if given, for scrolling up),
 * with only $afterId the first ones after that message (the ones polling hasn't shown yet),
 * and with both the newest ones between them (loading back to the message a reply quote answers).
 * Each one has its reactions too (reactions, from chatReactions()).
 * Only what the user can see: other people's ghosts (assets/php/chat.php) are left out, and their own edits made while
 * ghost banned are shown as edited.
 */
function chatMessages(PDO $db, int $userId, int $chatId, int $limit, ?int $afterId = null, ?int $beforeId = null): array
{
    $statement = $db->prepare('SELECT * FROM (
            SELECT m.messageId, m.userId, ' . chatGhostContentSql('m', $userId) . ' AS messageContent, m.messageSentTimestamp,
                m.messageDeletedTimestamp, m.messageDeletedByAdmin, m.messageReplyToId,
                m.messageIsEdited OR (m.userId = ' . $userId . ' AND m.messageGhostContent IS NOT NULL) AS messageIsEdited,
                m.messageEvent, m.messageEventType, m.messageEventUserId, COALESCE(ecm.memberNickname, eu.userName) AS eventUserName,
                COALESCE(cm.memberNickname, u.userName) AS senderName,
                u.userAvatarAttachmentId AS senderAvatarId, u.userStatus = ' . HBHUB_SESSION_STATUS_BANNED . ' AS senderBanned,
                r.messageId AS replyId, r.userId AS replyUserId, ' . chatGhostContentSql('r', $userId) . ' AS replyContent,
                r.messageDeletedTimestamp AS replyDeletedTimestamp, r.messageDeletedByAdmin AS replyDeletedByAdmin, COALESCE(rcm.memberNickname, ru.userName) AS replySenderName
            FROM `HBHub-Messages` m
            JOIN `HBHub-Users` u ON u.userId = m.userId
            LEFT JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = m.userId
            LEFT JOIN `HBHub-Messages` r ON r.messageId = m.messageReplyToId AND r.chatId = m.chatId
            LEFT JOIN `HBHub-Users` ru ON ru.userId = r.userId
            LEFT JOIN `HBHub-ChatMembers` rcm ON rcm.chatId = r.chatId AND rcm.userId = r.userId
            LEFT JOIN `HBHub-Users` eu ON eu.userId = m.messageEventUserId
            LEFT JOIN `HBHub-ChatMembers` ecm ON ecm.chatId = m.chatId AND ecm.userId = m.messageEventUserId
            WHERE m.chatId = ? AND m.messageId > ? AND m.messageId < ? AND ' . chatGhostVisibleSql('m', 'messageIsGhost', $userId) . '
            ORDER BY m.messageId ' . ($afterId !== null && $beforeId === null ? 'ASC' : 'DESC') . ' LIMIT ' . $limit . '
        ) shown ORDER BY messageId ASC');
    $statement->execute([$chatId, $afterId ?? 0, $beforeId ?? PHP_INT_MAX]);
    $messages = $statement->fetchAll();
    if ($messages === []) {
        return [];
    }

    $fromId = (int) $messages[0]['messageId'];
    $toId = (int) end($messages)['messageId'];
    $reactions = chatReactions($db, $userId, $chatId, $fromId, $toId);
    $attachments = chatAttachments($db, $chatId, $fromId, $toId);
    foreach ($messages as &$message) {
        $message['reactions'] = $reactions[(int) $message['messageId']] ?? [];
        $message['attachments'] = $attachments[(int) $message['messageId']] ?? [];
    }
    unset($message);
    return $messages;
}

/**
 * The attachments of the chat's messages from $fromId to $toId (both included), as [messageId => [attachment, ...]],
 * each message's in the order they were sent. Gone ones are there too (to say they were deleted or expired), and
 * attachmentIsLive says whether the file can still be fetched. Whose message it is was already checked by chatMessages().
 *
 * @return array<int, list<array{attachmentId: int, attachmentName: ?string, attachmentType: string, attachmentSize: int,
 *     attachmentStatus: int, attachmentExpiresTimestamp: string, attachmentIsLive: int}>>
 */
function chatAttachments(PDO $db, int $chatId, int $fromId, int $toId): array
{
    $statement = $db->prepare('SELECT a.messageId, a.attachmentId, a.attachmentName, a.attachmentType, a.attachmentSize,
            a.attachmentStatus, a.attachmentExpiresTimestamp,
            a.attachmentStatus = ? AND a.attachmentExpiresTimestamp > NOW() AS attachmentIsLive
        FROM `HBHub-ChatAttachments` a
        WHERE a.chatId = ? AND a.messageId BETWEEN ? AND ?
        ORDER BY a.attachmentId');
    $statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT, $chatId, $fromId, $toId]);
    $attachments = [];
    foreach ($statement->fetchAll() as $attachment) {
        $attachments[(int) $attachment['messageId']][] = $attachment;
    }
    return $attachments;
}

/**
 * The reactions under the chat's messages from $fromId to $toId (both included), leaving out deleted messages, as
 * [messageId => [emoji => [userId => name]]]. The emoji are in the order they were first used on the message, and
 * the people in the order they reacted. The names are the ones the chat shows, so a group's nicknames.
 * Other people's ghost reactions (made while ghost banned, assets/php/chat.php) are left out.
 *
 * @return array<int, array<string, array<int, string>>>
 */
function chatReactions(PDO $db, int $userId, int $chatId, int $fromId, int $toId): array
{
    $statement = $db->prepare('SELECT r.messageId, r.reactionEmoji, r.userId, COALESCE(cm.memberNickname, u.userName) AS reactorName
        FROM `HBHub-MessageReactions` r
        JOIN `HBHub-Messages` m ON m.messageId = r.messageId
        JOIN `HBHub-Users` u ON u.userId = r.userId
        LEFT JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = r.userId
        WHERE m.chatId = ? AND m.messageId BETWEEN ? AND ? AND m.messageDeletedTimestamp IS NULL
            AND ' . chatGhostVisibleSql('r', 'reactionIsGhost', $userId) . '
        ORDER BY r.reactionId');
    $statement->execute([$chatId, $fromId, $toId]);
    $reactions = [];
    foreach ($statement->fetchAll() as $reaction) {
        $reactions[(int) $reaction['messageId']][$reaction['reactionEmoji']][(int) $reaction['userId']] = $reaction['reactorName'];
    }
    return $reactions;
}

/**
 * Whether it's one emoji - one character as it's shown, like 👍, 👍🏽, ❤️, 1️⃣, 🇩🇰 or 👨‍👩‍👧 - so a reaction can't be text.
 */
function chatIsReactionEmoji(mixed $emoji): bool
{
    if (!is_string($emoji) || $emoji === '' || mb_strlen($emoji) > HBHUB_CHAT_REACTION_LENGTH_MAX) {
        return false;
    }
    $emojiChars = HBHUB_LATIN_TEXT_EMOJI . HBHUB_CHAT_REACTION_EXTRA_EMOJI;
    // \X is one character as it's shown, however many it's built from. Both are false on invalid UTF-8 too.
    return preg_match('/^\X\z/u', $emoji) === 1
        && preg_match('/^(?:[0-9#*]\x{FE0F}?\x{20E3}' // a keycap
            . '|[' . $emojiChars . '][' . $emojiChars . HBHUB_CHAT_REACTION_JOINERS . ']*)\z/u', $emoji) === 1;
}

/**
 * What chatSenderName() needs to know about the chat (its type, and in a DM the nickname the other person was given),
 * for answers that come before the chat list, which works it out for $openChat.
 *
 * @param array{chatId: int, chatType: int} $chat from chatMembership()
 * @return array{chatType: int, chatNickname: ?string}
 */
function chatNamesContext(PDO $db, int $userId, array $chat): array
{
    $nickname = null;
    if ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM) {
        $statement = $db->prepare('SELECT c.chatSettings, om.userId AS dmUserId FROM `HBHub-Chats` c
            LEFT JOIN `HBHub-ChatMembers` om ON om.chatId = c.chatId AND om.userId <> ?
            WHERE c.chatId = ? LIMIT 1');
        $statement->execute([$userId, $chat['chatId']]);
        $dm = $statement->fetch();
        if ($dm !== false && $dm['dmUserId'] !== null) {
            $nickname = chatSettings($dm['chatSettings'])->chatNicknames->{$dm['dmUserId']} ?? null;
        }
    }
    return ['chatType' => (int) $chat['chatType'], 'chatNickname' => is_string($nickname) ? $nickname : null];
}

/**
 * Everyone who can be @mentioned in the chat (assets/php/mentions.php): the members who have accepted it, leaving out
 * banned and deleted accounts. By userId, each with the name the chat shows them by and every name they can be
 * mentioned by: their username, and their nickname in the chat if they have one (a group's memberNickname, or the one
 * given in a DM). The user is in there too (isYou), so their own name lights up in others' messages.
 *
 * @return array<int, array{name: string, names: list<string>, userName: string, isYou: bool}>
 */
function chatMentionPeople(PDO $db, int $chatId, int $userId): array
{
    $statement = $db->prepare('SELECT cm.userId, cm.memberNickname, u.userName, c.chatType, c.chatSettings
        FROM `HBHub-ChatMembers` cm
        JOIN `HBHub-Users` u ON u.userId = cm.userId
        JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userAcknowledgedJoin = 1 AND u.userStatus IS NOT NULL AND u.userStatus <> ?');
    $statement->execute([$chatId, HBHUB_SESSION_STATUS_BANNED]);

    $people = [];
    foreach ($statement->fetchAll() as $member) {
        $memberId = (int) $member['userId'];
        $nickname = (int) $member['chatType'] === HBHUB_CHAT_TYPE_DM
            ? chatSettings($member['chatSettings'])->chatNicknames->{$memberId} ?? null
            : $member['memberNickname'];
        $nickname = is_string($nickname) && $nickname !== '' ? $nickname : null;
        $people[$memberId] = [
            'name' => $nickname ?? $member['userName'],
            'names' => $nickname !== null ? [$member['userName'], $nickname] : [$member['userName']],
            'userName' => $member['userName'],
            'isYou' => $memberId === $userId,
        ];
    }
    return $people;
}

/**
 * Notes that the user has the chat open and is looking at it (memberLastViewedTimestamp), so being @mentioned in it
 * doesn't send them a notification. At most every HBHUB_CHAT_LOOKING_EVERY seconds, as chat.js checks in every 2.
 */
function chatMarkLooking(PDO $db, int $chatId, int $userId): void
{
    $db->prepare('UPDATE `HBHub-ChatMembers` SET memberLastViewedTimestamp = UTC_TIMESTAMP()
        WHERE chatId = ? AND userId = ? AND (memberLastViewedTimestamp IS NULL
            OR memberLastViewedTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_CHAT_LOOKING_EVERY . ' SECOND)')
        ->execute([$chatId, $userId]);
}

/**
 * Saves whether the user is typing a message in the chat right now. chat.js says so with each poll while they are, and
 * once more when they stop (emptied the box, or didn't press a key for a few seconds); sending a message stops it too.
 * Everyone else in the chat sees "... is typing" from it (chatTyping()). While typing it's only written every
 * HBHUB_CHAT_TYPING_EVERY seconds, like chatMarkLooking().
 */
function chatMarkTyping(PDO $db, int $chatId, int $userId, bool $isTyping): void
{
    if ($isTyping) {
        $db->prepare('UPDATE `HBHub-ChatMembers` SET memberTypingTimestamp = UTC_TIMESTAMP()
            WHERE chatId = ? AND userId = ? AND (memberTypingTimestamp IS NULL
                OR memberTypingTimestamp <= UTC_TIMESTAMP() - INTERVAL ' . HBHUB_CHAT_TYPING_EVERY . ' SECOND)')
            ->execute([$chatId, $userId]);
    } else {
        $db->prepare('UPDATE `HBHub-ChatMembers` SET memberTypingTimestamp = NULL
            WHERE chatId = ? AND userId = ? AND memberTypingTimestamp IS NOT NULL')
            ->execute([$chatId, $userId]);
    }
}

/**
 * The names of everyone else typing in the chat right now (chatMarkTyping()), as the chat shows them (a group's
 * nicknames), A to Z. A ghost-banned user's typing is never saved (assets/php/chat.php), so nobody sees them typing.
 *
 * @return string[]
 */
function chatTyping(PDO $db, int $chatId, int $userId): array
{
    $statement = $db->prepare('SELECT COALESCE(cm.memberNickname, u.userName) AS typerName
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Users` u ON u.userId = cm.userId
        WHERE cm.chatId = ? AND cm.userId <> ? AND cm.userAcknowledgedJoin = 1 AND cm.memberIsGhost = 0
            AND cm.memberTypingTimestamp > UTC_TIMESTAMP() - INTERVAL ' . HBHUB_CHAT_TYPING_SECONDS . ' SECOND
        ORDER BY typerName');
    $statement->execute([$chatId, $userId]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Tells everyone the message @mentions about it (chat.mention), unless they muted the chat, blocked the sender, or have
 * the chat open in front of them right now (chatMarkLooking()) - they can see it already.
 * Only a new message does - editing a mention into one doesn't, like on Discord.
 */
function chatNotifyMentions(PDO $db, int $userId, int $chatId, int $messageId, string $content): void
{
    $mentionedIds = chatFormatMentionedUserIds($content, hbHubMentionLookup(chatMentionPeople($db, $chatId, $userId)));
    if ($mentionedIds === []) {
        return;
    }

    $data = chatNotificationData($db, $chatId) + ['messageId' => $messageId];
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT u.userId, u.userSettings,
            COALESCE(TIMESTAMPDIFF(SECOND, cm.memberLastViewedTimestamp, UTC_TIMESTAMP()) < ' . HBHUB_CHAT_LOOKING_SECONDS . ', 0) AS isLooking
        FROM `HBHub-Users` u
        JOIN `HBHub-ChatMembers` cm ON cm.userId = u.userId AND cm.chatId = ?
        WHERE u.userId IN (' . implode(', ', array_fill(0, count($mentionedIds), '?')) . ')');
    $statement->execute([$chatId, ...$mentionedIds]);
    foreach ($statement->fetchAll() as $mentioned) {
        $settings = hbHubUserSettings($mentioned['userSettings']);
        if (!$mentioned['isLooking'] && !hbHubChatIsMuted($settings, $chatId) && !chatHasBlocked($settings, $userId)) {
            hbHubNotify($db, (int) $mentioned['userId'], 'chat.mention', $userId, $data);
        }
    }
}

/**
 * The newest $limit messages (before $beforeId, if given, and back to $fromId at the furthest),
 * and whether there are older ones left for chat.js to load.
 *
 * @return array{0: array, 1: bool}
 */
function chatMessagesPage(PDO $db, int $userId, int $chatId, int $limit, ?int $beforeId = null, ?int $fromId = null): array
{
    $messages = chatMessages($db, $userId, $chatId, $limit, $fromId !== null ? $fromId - 1 : null, $beforeId);
    if ($messages === []) {
        return [[], false];
    }

    $statement = $db->prepare('SELECT EXISTS (SELECT 1 FROM `HBHub-Messages` m WHERE m.chatId = ? AND m.messageId < ?
        AND ' . chatGhostVisibleSql('m', 'messageIsGhost', $userId) . ')');
    $statement->execute([$chatId, $messages[0]['messageId']]);
    return [$messages, (bool) $statement->fetchColumn()];
}

/**
 * Back to the chat page after a form post, with the chat open if there is one. 303, so a refresh doesn't post again.
 * With $keepUnread, opening the chat doesn't mark it as read (after "Mark unread").
 */
function chatRedirect(?int $chatId, bool $keepUnread = false): never
{
    header('Location: /chat/' . ($chatId !== null ? '?chat=' . $chatId . ($keepUnread ? '&unread=1' : '') : ''), true, 303);
    exit;
}

/**
 * Back to the chat after a message with a blacklisted link wasn't sent or saved (assets/php/urlBlacklist.php).
 * ?linkBlocked=1 opens the blocked link popup, which says why. chat.js normally stops it before it gets here,
 * so this is only for when it didn't (no JS, or the GIF picker sending the text in the box with a GIF).
 */
function chatRedirectLinkBlocked(int $chatId): never
{
    header('Location: /chat/?chat=' . $chatId . '&linkBlocked=1', true, 303);
    exit;
}

/**
 * Whether sending $count more messages now would take the user over HBHUB_CHAT_SEND_MAX in the last
 * HBHUB_CHAT_SEND_SECONDS, in all their chats together. Notices (messageEvent) don't count.
 * It only looks at the user's newest few messages: when the one that would be the oldest of the allowed ones is
 * still that recent, there's no room. Inside a transaction it locks the user's row first, so two tabs sending at
 * once can't both squeeze in.
 */
function chatSendingTooFast(PDO $db, int $userId, int $count = 1): bool
{
    if ($db->inTransaction()) {
        $db->prepare('SELECT userId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE')->execute([$userId]);
    }
    $statement = $db->prepare('SELECT messageSentTimestamp > NOW() - INTERVAL ' . HBHUB_CHAT_SEND_SECONDS . ' SECOND
        FROM `HBHub-Messages` WHERE userId = ? AND messageEvent = 0 ORDER BY messageId DESC LIMIT 1 OFFSET ' . max(0, HBHUB_CHAT_SEND_MAX - $count));
    $statement->execute([$userId]);
    return (bool) $statement->fetchColumn();
}

/**
 * Back to the chat after a message wasn't sent because the user is sending too fast (chatSendingTooFast()).
 * ?tooFast=1 opens the Slow down popup, and chat.js keeps what was typed in the box so it can be sent again.
 */
function chatRedirectTooFast(int $chatId): never
{
    header('Location: /chat/?chat=' . $chatId . '&tooFast=1', true, 303);
    exit;
}

/**
 * The userIds picked in a user picker (the New chat and Add to chat popups), without duplicates or the user themself.
 *
 * @return int[]
 */
function chatPickedUserIds(int $userId, mixed $pickedIds): array
{
    $memberIds = [];
    foreach (is_array($pickedIds) ? $pickedIds : [] as $pickedId) {
        if (is_string($pickedId) && ctype_digit($pickedId) && strlen($pickedId) <= 10 && (int) $pickedId !== $userId) {
            $memberIds[(int) $pickedId] = true;
        }
    }
    return array_keys($memberIds);
}

/**
 * Adds the picked users to the group, with a notice for each. Like when the group was made, they get it as
 * a message request they have to accept first, and a notification about it. Anyone already in it (or invited) is left out, and so are unknown,
 * banned and deleted users, and anyone who blocked the user from sending them requests. Nobody is added when they'd make
 * the group bigger than HBHUB_CHAT_MEMBERS_MAX, or the user would send more requests this hour than HBHUB_CHAT_REQUESTS_PER_HOUR.
 * A ghost-banned user's invites are ghosts (memberIsGhost, see assets/php/chat.php): no request, no notification, and
 * a notice only they see. Anyone else adding someone with a ghost invite turns it into a real one.
 *
 * @param array $userSettings the user's settings, as picking someone they blocked unblocks them
 * @param bool $isGhost the user is ghost banned
 */
function chatAddMembers(PDO $db, int $userId, array $userSettings, int $chatId, mixed $pickedIds, bool $isGhost): void
{
    $pickedIds = chatPickedUserIds($userId, $pickedIds);
    if ($pickedIds === [] || count($pickedIds) >= HBHUB_CHAT_MEMBERS_MAX) {
        return;
    }
    chatUnblockPicked($db, $userId, $userSettings, $pickedIds);
    $requestsLeft = chatRequestsLeft($db, $userId);

    // Locked, so two people adding at once can't take the group past the limit
    $db->beginTransaction();
    $db->prepare('SELECT chatId FROM `HBHub-Chats` WHERE chatId = ? FOR UPDATE')->execute([$chatId]);

    // A deleted account's userStatus is NULL, so it's left out by <> too.
    // Someone with a ghost invite isn't in the group yet, except to the ghost-banned user.
    $statement = $db->prepare('SELECT u.userId, u.userSettings FROM `HBHub-Users` u
        WHERE u.userStatus <> ? AND u.userId IN (' . implode(', ', array_fill(0, count($pickedIds), '?')) . ')
            AND NOT EXISTS (SELECT 1 FROM `HBHub-ChatMembers` cm WHERE cm.chatId = ? AND cm.userId = u.userId'
                . ($isGhost ? '' : ' AND cm.memberIsGhost = 0') . ')
        ORDER BY u.userNameLower');
    $statement->execute([HBHUB_SESSION_STATUS_BANNED, ...$pickedIds, $chatId]);
    $newIds = [];
    foreach ($statement->fetchAll() as $picked) {
        if (!chatHasBlocked(hbHubUserSettings($picked['userSettings']), $userId)) {
            $newIds[] = (int) $picked['userId'];
        }
    }

    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-ChatMembers` WHERE chatId = ?');
    $statement->execute([$chatId]);
    $memberCount = (int) $statement->fetchColumn();

    if ($newIds !== [] && count($newIds) <= $requestsLeft && $memberCount + count($newIds) <= HBHUB_CHAT_MEMBERS_MAX) {
        if (!$isGhost) {
            // Their ghost invites make way for the real ones
            $db->prepare('DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND memberIsGhost = 1
                AND userId IN (' . implode(', ', array_fill(0, count($newIds), '?')) . ')')
                ->execute([$chatId, ...$newIds]);
        }
        $memberRows = [];
        foreach ($newIds as $newId) {
            array_push($memberRows, $chatId, $newId, HBHUB_CHAT_ROLE_MEMBER, 0, (int) $isGhost);
        }
        $db->prepare('INSERT INTO `HBHub-ChatMembers` (chatId, userId, memberRole, userAcknowledgedJoin, memberIsGhost)
            VALUES ' . implode(', ', array_fill(0, count($newIds), '(?, ?, ?, ?, ?)')))
            ->execute($memberRows);
        $notificationData = chatNotificationData($db, $chatId);
        foreach ($newIds as $newId) {
            chatNotice($db, $userId, $chatId, HBHUB_CHAT_EVENT_ADD, $newId, null, false, $isGhost);
            if (!$isGhost) {
                hbHubNotify($db, $newId, 'chat.messageRequest', $userId, $notificationData);
            }
        }
    }
    $db->commit();
}

/**
 * The uploaded group icon as [type, data], or null when it's missing, too big or not a PNG, JPEG, GIF or WebP image.
 *
 * @return ?array{0: string, 1: string}
 */
function chatIconUpload(mixed $file): ?array
{
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > HBHUB_CHAT_ICON_MAX_BYTES) {
        return null;
    }

    // Checked by what's in the file, not its name or the type the browser says it is
    $image = @getimagesize($file['tmp_name']);
    if ($image === false || !in_array($image['mime'], HBHUB_CHAT_ICON_TYPES, true)) {
        return null;
    }

    $data = file_get_contents($file['tmp_name']);
    return $data === false ? null : [$image['mime'], $data];
}

/**
 * A GIF is sent as a message holding just its Giphy address, in this one form.
 */
function chatGifUrl(string $gifId): string
{
    return 'https://media.giphy.com/media/' . $gifId . '/giphy.gif';
}

/**
 * The Giphy id when the message is a GIF (exactly an address from chatGifUrl()), otherwise null.
 */
function chatGifId(?string $content): ?string
{
    return $content !== null && preg_match('~^https://media\.giphy\.com/media/([A-Za-z0-9]{1,64})/giphy\.gif$~', $content, $match)
        ? $match[1] : null;
}

/**
 * Whether the user hid the chat from their chat list (chatHidden in the user's settings, keyed by chatId).
 */
function chatIsHidden(array $userSettings, int $chatId): bool
{
    return ($userSettings['chatHidden'][$chatId] ?? false) === true;
}

/**
 * Hides the chat from the user's chat list until a new message arrives, or shows it again.
 * Only hidden chats are saved, so the list doesn't grow with every chat the user ever had.
 */
function chatSetHidden(PDO $db, int $userId, int $chatId, bool $isHidden): void
{
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($chatId, $isHidden): array {
        $hidden = is_array($settings['chatHidden'] ?? null) ? $settings['chatHidden'] : [];
        if ($isHidden) {
            $hidden[$chatId] = true;
        } else {
            unset($hidden[$chatId]);
        }
        // Saved as an object even when empty, so the keys stay chatIds
        $settings['chatHidden'] = (object) $hidden;
        return $settings;
    });
}

/**
 * Whether the user blocked $fromUserId from sending them message requests (chatBlocked in the user's settings, keyed by
 * userId), with Block on a request from them. It lasts until the user starts a chat with them or adds them to one.
 */
function chatHasBlocked(array $userSettings, int $fromUserId): bool
{
    return ($userSettings['chatBlocked'][$fromUserId] ?? false) === true;
}

/**
 * Blocks the users from sending the user message requests, or unblocks them.
 *
 * @param int[] $fromUserIds
 */
function chatSetBlocked(PDO $db, int $userId, array $fromUserIds, bool $isBlocked): void
{
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($fromUserIds, $isBlocked): array {
        $blocked = is_array($settings['chatBlocked'] ?? null) ? $settings['chatBlocked'] : [];
        foreach ($fromUserIds as $fromUserId) {
            if ($isBlocked) {
                $blocked[$fromUserId] = true;
            } else {
                unset($blocked[$fromUserId]);
            }
        }
        // Saved as an object even when empty, so the keys stay userIds
        $settings['chatBlocked'] = (object) $blocked;
        return $settings;
    });
}

/**
 * Starting a chat with people, or adding them to one, unblocks the ones the user had blocked.
 * Not inside a transaction, as changing the settings is one of its own.
 *
 * @param array $userSettings the user's settings, to only change them when someone picked was blocked
 * @param int[] $pickedIds
 */
function chatUnblockPicked(PDO $db, int $userId, array $userSettings, array $pickedIds): void
{
    $blockedIds = array_values(array_filter($pickedIds, fn (int $pickedId): bool => chatHasBlocked($userSettings, $pickedId)));
    if ($blockedIds !== []) {
        chatSetBlocked($db, $userId, $blockedIds, false);
    }
}

/**
 * How many more message requests the user can send this hour (HBHUB_CHAT_REQUESTS_PER_HOUR), counted from the
 * notifications people got about the ones they sent.
 */
function chatRequestsLeft(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Notifications`
        WHERE notificationByUserId = ? AND notificationType = ? AND notificationCreatedTimestamp > NOW() - INTERVAL 1 HOUR');
    $statement->execute([$userId, 'chat.messageRequest']);
    return max(0, HBHUB_CHAT_REQUESTS_PER_HOUR - (int) $statement->fetchColumn());
}

/**
 * Mutes the chat for the user for $minutes (0 until they unmute it), or unmutes it when $minutes is null.
 * Muted, it sends them no notifications. Saved in their settings like hbHubChatIsMuted() reads it,
 * and only mutes that haven't run out are kept, so the lists don't grow with every chat the user ever muted.
 */
function chatSetMuted(PDO $db, int $userId, int $chatId, ?int $minutes): void
{
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($chatId, $minutes): array {
        $muted = [];
        $mutedUntil = [];
        foreach (hbHubMutedChatIds($settings) as $mutedChatId) {
            $muted[$mutedChatId] = true;
            if (isset($settings['chatMutedUntil'][$mutedChatId])) {
                $mutedUntil[$mutedChatId] = $settings['chatMutedUntil'][$mutedChatId];
            }
        }

        unset($muted[$chatId], $mutedUntil[$chatId]);
        if ($minutes !== null) {
            $muted[$chatId] = true;
            if ($minutes > 0) {
                $mutedUntil[$chatId] = time() + $minutes * 60;
            }
        }

        // Saved as objects even when empty, so the keys stay chatIds
        $settings['chatMuted'] = (object) $muted;
        $settings['chatMutedUntil'] = (object) $mutedUntil;
        return $settings;
    });
}

/**
 * How the chat list says the chat is muted: "Muted until 14:30" (with the date when it isn't today),
 * or just "Muted" until the user unmutes it. Null when it isn't muted.
 */
function chatMutedLabel(array $userSettings, int $chatId): ?string
{
    if (!hbHubChatIsMuted($userSettings, $chatId)) {
        return null;
    }
    $until = $userSettings['chatMutedUntil'][$chatId] ?? null;
    if (!is_int($until)) {
        return 'Muted';
    }
    return 'Muted until ' . date(date('Y-m-d', $until) === date('Y-m-d') ? 'H:i' : 'd/m/Y H:i', $until);
}

/**
 * Shows the chat again for every member who hid it, when a new message arrives.
 */
function chatUnhideForEveryone(PDO $db, int $chatId): void
{
    $statement = $db->prepare('SELECT u.userId, u.userSettings FROM `HBHub-ChatMembers` cm
        JOIN `HBHub-Users` u ON u.userId = cm.userId WHERE cm.chatId = ?');
    $statement->execute([$chatId]);
    foreach ($statement->fetchAll() as $member) {
        if (chatIsHidden(hbHubUserSettings($member['userSettings']), $chatId)) {
            chatSetHidden($db, (int) $member['userId'], $chatId, false);
        }
    }
}

/**
 * Gives the other person in a DM a nickname, or takes it away when it's empty.
 * It's saved in the chat's settings under the userId it's for, and shown as the chat's name.
 */
function chatSetNickname(PDO $db, int $userId, int $chatId, mixed $nickname): void
{
    $nickname = is_string($nickname) ? trim($nickname) : '';

    $statement = $db->prepare('SELECT userId FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId <> ? LIMIT 1');
    $statement->execute([$chatId, $userId]);
    $otherUserId = $statement->fetchColumn();
    if ($otherUserId === false) {
        return; // nobody to give a nickname when the other person left
    }

    chatChangeSettings($db, $chatId, function (stdClass $settings) use ($otherUserId, $nickname): void {
        $settings->chatNicknames->{$otherUserId} = $nickname === '' ? null : mb_substr($nickname, 0, HBHUB_CHAT_NICKNAME_MAX);
    });
}

/**
 * Starts a chat with the picked users: a DM for one, a group DM for more.
 * A DM that already exists with that user is reused instead of making a second one.
 * Anyone who blocked the user from sending them requests is left out of a new chat, and picking someone the user
 * blocked unblocks them.
 * Returns the chat id, or null when the picked users aren't valid (none, too many, unknown or banned), when everyone
 * picked blocked the user, or when it would be more requests this hour than HBHUB_CHAT_REQUESTS_PER_HOUR.
 * A ghost-banned user's new chat only exists for them: everyone they picked gets a ghost invite (memberIsGhost, see
 * assets/php/chat.php) instead of a request.
 *
 * @param array $userSettings the user's settings
 * @param bool $isGhost the user is ghost banned
 */
function chatCreate(PDO $db, int $userId, array $userSettings, mixed $pickedIds, mixed $name, bool $isGhost): ?int
{
    $memberIds = chatPickedUserIds($userId, $pickedIds);
    if ($memberIds === [] || count($memberIds) + 1 > HBHUB_CHAT_MEMBERS_MAX) {
        return null;
    }

    // Every picked user has to exist and not be banned or deleted (a deleted account's userStatus is NULL)
    $statement = $db->prepare('SELECT userId, userSettings FROM `HBHub-Users`
        WHERE userStatus <> ? AND userId IN (' . implode(', ', array_fill(0, count($memberIds), '?')) . ')');
    $statement->execute([HBHUB_SESSION_STATUS_BANNED, ...$memberIds]);
    $memberSettings = array_column($statement->fetchAll(), 'userSettings', 'userId');
    if (count($memberSettings) !== count($memberIds)) {
        return null;
    }
    chatUnblockPicked($db, $userId, $userSettings, $memberIds);

    $isGroup = count($memberIds) > 1;

    if (!$isGroup) {
        $statement = $db->prepare('SELECT c.chatId FROM `HBHub-Chats` c
            JOIN `HBHub-ChatMembers` me ON me.chatId = c.chatId AND me.userId = ?
            JOIN `HBHub-ChatMembers` them ON them.chatId = c.chatId AND them.userId = ?
            WHERE c.chatType = ? AND c.chatStatus = ? LIMIT 1');
        $statement->execute([$userId, $memberIds[0], HBHUB_CHAT_TYPE_DM, HBHUB_CHAT_STATUS_ACTIVE]);
        $existingChatId = $statement->fetchColumn();
        if ($existingChatId !== false) {
            // Starting a DM with someone who already sent you a request accepts it, and a hidden one comes back.
            // So does one they started while ghost banned, which the user never saw: it's a normal DM from now on,
            // and what they sent while ghost banned stays hidden.
            $db->prepare('UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1, memberIsGhost = 0 WHERE chatId = ? AND userId = ?')
                ->execute([$existingChatId, $userId]);
            // The other way around: the user started it while ghost banned and isn't any more, so it's a real request now
            if (!$isGhost) {
                $statement = $db->prepare('UPDATE `HBHub-ChatMembers` SET memberIsGhost = 0 WHERE chatId = ? AND userId = ? AND memberIsGhost = 1');
                $statement->execute([$existingChatId, $memberIds[0]]);
                if ($statement->rowCount() > 0) {
                    hbHubNotify($db, $memberIds[0], 'chat.messageRequest', $userId, chatNotificationData($db, (int) $existingChatId));
                }
            }
            hbHubMarkNotificationsReadAbout($db, $userId, 'chat.messageRequest', ['chatId' => (int) $existingChatId]);
            chatSetHidden($db, $userId, (int) $existingChatId, false);
            return (int) $existingChatId;
        }
    }

    // Only a new chat sends requests, so only then are the people who blocked the user left out, and the hourly limit checked
    $memberIds = array_values(array_filter($memberIds,
        fn (int $memberId): bool => !chatHasBlocked(hbHubUserSettings($memberSettings[$memberId]), $userId)));
    if ($memberIds === [] || count($memberIds) > chatRequestsLeft($db, $userId)) {
        return null;
    }

    // Only groups get a name - left empty, they're named after the members
    $chatName = $isGroup && is_string($name) ? trim($name) : '';
    $chatName = $chatName === '' ? null : mb_substr($chatName, 0, HBHUB_CHAT_NAME_MAX);

    $db->beginTransaction();
    $db->prepare('INSERT INTO `HBHub-Chats` (chatName, chatType, chatCreatedBy) VALUES (?, ?, ?)')
        ->execute([$chatName, $isGroup ? HBHUB_CHAT_TYPE_GROUP : HBHUB_CHAT_TYPE_DM, $userId]);
    $chatId = (int) $db->lastInsertId();

    // The creator is the group's owner. In a DM both are normal members.
    // Everyone but the creator gets the chat as a message request they have to accept first, and a notification about it,
    // unless the creator is ghost banned: then it's a ghost invite they never see.
    $memberRows = [$chatId, $userId, $isGroup ? HBHUB_CHAT_ROLE_OWNER : HBHUB_CHAT_ROLE_MEMBER, 1, 0];
    foreach ($memberIds as $memberId) {
        array_push($memberRows, $chatId, $memberId, HBHUB_CHAT_ROLE_MEMBER, 0, (int) $isGhost);
    }
    $db->prepare('INSERT INTO `HBHub-ChatMembers` (chatId, userId, memberRole, userAcknowledgedJoin, memberIsGhost)
        VALUES ' . implode(', ', array_fill(0, count($memberIds) + 1, '(?, ?, ?, ?, ?)')))
        ->execute($memberRows);
    $notificationData = chatNotificationData($db, $chatId);
    foreach ($isGhost ? [] : $memberIds as $memberId) {
        hbHubNotify($db, $memberId, 'chat.messageRequest', $userId, $notificationData);
    }
    $db->commit();

    return $chatId;
}

// Starting a new chat, then straight into it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'create') {
    chatRedirect(chatCreate($db, $userId, hbHubUserSettings($hbHubUser['userSettings']), $_POST['users'] ?? null, $_POST['name'] ?? null, $chatIsGhost));
}

// The right-click menu in the chat list: mute, unmute, hide or leave a chat, give the other person in a DM a nickname,
// and for group admins rename it, change its icon or delete it. Group admins and both people in a DM can change its background.
// Then back to the chat that was open (sent along as "open"), unless that's the one that was just hidden, left or deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? null, ['mute', 'unmute', 'hide', 'leave', 'nickname', 'rename', 'icon', 'background', 'delete'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $openChatId = is_string($_POST['open'] ?? null) && ctype_digit($_POST['open']) ? (int) $_POST['open'] : null;
    $isGroupAdmin = $chat !== null && chatIsGroupAdmin($chat);

    switch ($chat !== null ? $_POST['action'] : null) {
        case 'nickname':
            if ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM) {
                chatSetNickname($db, $userId, (int) $chat['chatId'], $_POST['nickname'] ?? null);
            }
            break;
        case 'mute':
            // Only the lengths Mute chat offers
            $minutes = is_string($_POST['minutes'] ?? null) && ctype_digit($_POST['minutes']) && strlen($_POST['minutes']) <= 5
                ? (int) $_POST['minutes'] : null;
            if ($minutes !== null && array_key_exists($minutes, HBHUB_CHAT_MUTE_OPTIONS)) {
                chatSetMuted($db, $userId, (int) $chat['chatId'], $minutes);
            }
            break;
        case 'unmute':
            chatSetMuted($db, $userId, (int) $chat['chatId'], null);
            break;
        case 'hide':
            chatSetHidden($db, $userId, (int) $chat['chatId'], true);
            break;
        case 'leave':
            // Everyone at HTX is in their class chat (assets/php/schoolClass.php), so it can only be hidden
            if ($chat['chatClassKey'] === null) {
                chatLeave($db, $userId, $chat);
            }
            break;
        case 'rename':
            // Left empty, the group is named after its members again
            if ($isGroupAdmin) {
                chatRename($db, $userId, (int) $chat['chatId'], $_POST['name'] ?? null);
            }
            break;
        case 'icon':
            $isRemoving = ($_POST['remove'] ?? null) === '1';
            $icon = $isRemoving ? null : chatIconUpload($_FILES['icon'] ?? null);
            if ($isGroupAdmin && ($isRemoving || $icon !== null) && chatSetIcon($db, (int) $chat['chatId'], $icon)) {
                chatNotice($db, $userId, (int) $chat['chatId'], $isRemoving ? HBHUB_CHAT_EVENT_ICON_REMOVE : HBHUB_CHAT_EVENT_ICON);
            }
            break;
        case 'background':
            // Reset goes back to the default background
            $isRemoving = ($_POST['remove'] ?? null) === '1';
            $background = $isRemoving ? null : chatBackgroundOf($_POST['color1'] ?? null, $_POST['color2'] ?? null, $_POST['angle'] ?? null);
            if (($isGroupAdmin || (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM) && ($isRemoving || $background !== null)
                && chatSetBackground($db, (int) $chat['chatId'], $background)) {
                chatNotice($db, $userId, (int) $chat['chatId'], $isRemoving ? HBHUB_CHAT_EVENT_BACKGROUND_RESET : HBHUB_CHAT_EVENT_BACKGROUND);
            }
            break;
        case 'delete':
            if ($isGroupAdmin) {
                $db->prepare('UPDATE `HBHub-Chats` SET chatStatus = ? WHERE chatId = ?')
                    ->execute([HBHUB_CHAT_STATUS_DELETED, $chat['chatId']]);
                // Nobody can see the group's files any more, so they go, and everyone's tickets for them are freed
                hbHubChatAttachmentsRemove($db, 'a.chatId = ?', [$chat['chatId']]);
            }
            break;
    }

    $isGone = $chat !== null && in_array($_POST['action'], ['hide', 'leave', 'delete'], true);
    chatRedirect($isGone && (int) $chat['chatId'] === $openChatId ? null : $openChatId);
}

// Answering a message request: accepting opens the chat, declining leaves it. Either way its notification has been seen.
// Block declines it too, and stops whoever sent it from sending the user new requests (chatHasBlocked()). Without that,
// a declined DM could just be sent again straight away.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['accept', 'decline', 'block'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null, true);
    $isAccepted = $_POST['action'] === 'accept';

    if ($chat !== null && $_POST['action'] === 'block') {
        // Who sent it: whoever added the user to the group with Add to chat, otherwise whoever made the chat
        $statement = $db->prepare('SELECT COALESCE((
                SELECT m.userId FROM `HBHub-Messages` m
                WHERE m.chatId = c.chatId AND m.messageEventType = ? AND m.messageEventUserId = ? AND m.messageIsGhost = 0
                ORDER BY m.messageId DESC LIMIT 1
            ), c.chatCreatedBy) FROM `HBHub-Chats` c WHERE c.chatId = ?');
        $statement->execute([HBHUB_CHAT_EVENT_ADD, $userId, $chat['chatId']]);
        $fromUserId = (int) $statement->fetchColumn();
        if ($fromUserId !== $userId) {
            chatSetBlocked($db, $userId, [$fromUserId], true);
        }
    }

    if ($chat !== null) {
        $db->prepare($isAccepted
            ? 'UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1 WHERE chatId = ? AND userId = ?'
            : 'DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?')
            ->execute([$chat['chatId'], $userId]);
        hbHubMarkNotificationsReadAbout($db, $userId, 'chat.messageRequest', ['chatId' => (int) $chat['chatId']]);
        // The group sees who joined. A DM request accepted is just the chat starting.
        if ($isAccepted && (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP) {
            chatNotice($db, $userId, (int) $chat['chatId'], HBHUB_CHAT_EVENT_JOIN);
        }
    }

    chatRedirect($chat !== null && $isAccepted ? $chat['chatId'] : null);
}

// The Members popup, for group admins: make another member an admin too, or kick them out of the group.
// Only members who accepted the group can be made admin. Admins can kick normal members, an invite is taken back that way.
// The group owner can also kick admins, or remove them as admin so they're a normal member again.
// The member gets a notification about it, except for an invite taken back: then its message request notification goes.
// A site admin who gets kicked gets one with an Undo kick button instead.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['makeAdmin', 'removeAdmin', 'kick'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $isGroupAdmin = $chat !== null && chatIsGroupAdmin($chat);
    $isGroupOwner = $chat !== null && chatIsGroupOwner($chat);

    $member = false;
    if ($isGroupAdmin && is_string($_POST['member'] ?? null) && ctype_digit($_POST['member']) && (int) $_POST['member'] !== $userId) {
        $statement = $db->prepare('SELECT userId, memberRole, userAcknowledgedJoin, memberIsGhost FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?');
        $statement->execute([$chat['chatId'], (int) $_POST['member']]);
        $member = $statement->fetch();
        // A ghost invite (assets/php/chat.php) is only there for the ghost-banned user who made it
        if ($member !== false && $member['memberIsGhost'] && !$chatIsGhost) {
            $member = false;
        }
    }

    $isNormalMember = $member !== false && (int) $member['memberRole'] === HBHUB_CHAT_ROLE_MEMBER;

    $event = null; // the notice for the change, when there was one
    $notification = null; // and the member's notification about it (its type)
    if ($isNormalMember && $_POST['action'] === 'makeAdmin') {
        $statement = $db->prepare('UPDATE `HBHub-ChatMembers` SET memberRole = ? WHERE chatId = ? AND userId = ? AND memberRole = ? AND userAcknowledgedJoin = 1');
        $statement->execute([HBHUB_CHAT_ROLE_ADMIN, $chat['chatId'], $member['userId'], HBHUB_CHAT_ROLE_MEMBER]);
        if ($statement->rowCount() > 0) {
            [$event, $notification] = [HBHUB_CHAT_EVENT_MAKE_ADMIN, 'chat.madeAdmin'];
        }
    } elseif ($member !== false && $isGroupOwner && $_POST['action'] === 'removeAdmin') {
        $statement = $db->prepare('UPDATE `HBHub-ChatMembers` SET memberRole = ? WHERE chatId = ? AND userId = ? AND memberRole = ?');
        $statement->execute([HBHUB_CHAT_ROLE_MEMBER, $chat['chatId'], $member['userId'], HBHUB_CHAT_ROLE_ADMIN]);
        if ($statement->rowCount() > 0) {
            [$event, $notification] = [HBHUB_CHAT_EVENT_REMOVE_ADMIN, 'chat.removedAdmin'];
        }
    } elseif ($member !== false && $member['memberIsGhost'] && $_POST['action'] === 'kick') {
        // Taking back a ghost invite: nobody else ever knew about it, so only the ghost-banned user gets a notice
        $db->prepare('DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ? AND memberIsGhost = 1')
            ->execute([$chat['chatId'], $member['userId']]);
        chatNotice($db, $userId, (int) $chat['chatId'], HBHUB_CHAT_EVENT_KICK, (int) $member['userId'], null, false, true);
    } elseif ($member !== false && ($isNormalMember || $isGroupOwner) && $_POST['action'] === 'kick') {
        // A site admin kicked by a group admin can undo it from the notification, as if it never happened
        // (assets/php/chat.php's chatUndoKick()), so how they were in the group is kept with it
        $statement = $db->prepare('SELECT userRole FROM `HBHub-Users` WHERE userId = ?');
        $statement->execute([$member['userId']]);
        $snapshot = (int) $statement->fetchColumn() === HBHUB_CHAT_SITE_ROLE_ADMIN
            ? chatKickSnapshot($db, (int) $chat['chatId'], (int) $member['userId'])
            : null;

        $noticeId = chatLeave($db, (int) $member['userId'], $chat, $userId); // posts the notice itself
        if ($snapshot !== null) {
            hbHubNotify($db, (int) $member['userId'], 'chat.kickedUndoable', $userId,
                chatNotificationData($db, (int) $chat['chatId']) + ['noticeId' => $noticeId, 'member' => $snapshot]);
        } elseif ($member['userAcknowledgedJoin']) {
            $notification = 'chat.kicked';
        } else {
            hbHubRemoveNotifications($db, (int) $member['userId'], 'chat.messageRequest', ['chatId' => (int) $chat['chatId']]);
        }
    }
    if ($event !== null) {
        chatNotice($db, $userId, (int) $chat['chatId'], $event, (int) $member['userId']);
    }
    if ($notification !== null) {
        hbHubNotify($db, (int) $member['userId'], $notification, $userId, chatNotificationData($db, (int) $chat['chatId']));
    }

    chatRedirect($chat !== null ? (int) $chat['chatId'] : null);
}

// Add to chat, for every member of a group (not only admins): the picked users are invited to it.
// Not a class chat: who's in it goes by their class.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'add') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    if ($chat !== null && (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP && $chat['chatClassKey'] === null) {
        chatAddMembers($db, $userId, hbHubUserSettings($hbHubUser['userSettings']), (int) $chat['chatId'], $_POST['users'] ?? null, $chatIsGhost);
    }
    chatRedirect($chat !== null ? (int) $chat['chatId'] : null);
}

// The right-click menu on a message: edit or delete your own message, or mark the chat unread from that message on
// (the message before it becomes the last one read). Group admins can also delete other members' messages,
// which then show as deleted by a group admin. Deleted messages stay in the database, but only show as deleted.
// A deleted message's attachment doesn't stay: its file is deleted, and the sender gets its tickets back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['edit', 'deleteMessage', 'unread'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = $chat !== null ? chatMessage($db, $userId, (int) $chat['chatId'], $_POST['message'] ?? null) : null;
    $isOwnMessage = $message !== null && (int) $message['userId'] === $userId && $message['messageDeletedTimestamp'] === null;
    $isGroupAdmin = $chat !== null && chatIsGroupAdmin($chat);

    switch ($message !== null ? $_POST['action'] : null) {
        case 'edit':
            $content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
            if ($isOwnMessage && hbHubUrlBlacklisted($content)) {
                chatRedirectLinkBlocked((int) $chat['chatId']); // the message stays as it was
            }
            if ($isOwnMessage && !chatIsMuted($chat) && $message['messageContent'] !== null && $content !== ''
                && mb_strlen($content) <= HBHUB_CHAT_MESSAGE_MAX && $content !== $message['messageContent']) {
                // Ghost banned (assets/php/chat.php), an edit to a message everyone sees is only saved for the user.
                // Otherwise it's a real edit, which also takes the place of one made while they were ghost banned.
                $db->prepare($chatIsGhost && !$message['messageIsGhost']
                    ? 'UPDATE `HBHub-Messages` SET messageGhostContent = ? WHERE messageId = ? AND userId = ? AND messageDeletedTimestamp IS NULL'
                    : 'UPDATE `HBHub-Messages` SET messageContent = ?, messageIsEdited = 1, messageGhostContent = NULL
                        WHERE messageId = ? AND userId = ? AND messageDeletedTimestamp IS NULL')
                    ->execute([$content, $message['messageId'], $userId]);
            }
            break;
        case 'deleteMessage':
            if ($isOwnMessage) {
                $db->prepare('UPDATE `HBHub-Messages` SET messageDeletedTimestamp = NOW()
                    WHERE messageId = ? AND userId = ? AND messageDeletedTimestamp IS NULL')
                    ->execute([$message['messageId'], $userId]);
                hbHubChatAttachmentsRemove($db, 'm.messageId = ?', [$message['messageId']]);
            } elseif ($isGroupAdmin && $message['messageDeletedTimestamp'] === null) {
                $db->prepare('UPDATE `HBHub-Messages` SET messageDeletedTimestamp = NOW(), messageDeletedByAdmin = 1
                    WHERE messageId = ? AND messageDeletedTimestamp IS NULL')
                    ->execute([$message['messageId']]);
                hbHubChatAttachmentsRemove($db, 'm.messageId = ?', [$message['messageId']]);
            }
            if ($isOwnMessage || ($isGroupAdmin && $message['messageDeletedTimestamp'] === null)) {
                // Whoever it mentioned isn't told about it anymore
                hbHubRemoveNotifications($db, null, 'chat.mention', ['messageId' => $message['messageId']]);
            }
            break;
        case 'unread':
            $db->prepare('UPDATE `HBHub-ChatMembers` SET lastReadMessageId = (
                    SELECT MAX(messageId) FROM `HBHub-Messages` WHERE chatId = ? AND messageId < ?
                ) WHERE chatId = ? AND userId = ?')
                ->execute([$chat['chatId'], $message['messageId'], $chat['chatId'], $userId]);
            chatRedirect((int) $chat['chatId'], true);
    }

    chatRedirect($chat !== null ? (int) $chat['chatId'] : null);
}

/**
 * The answer to chat.js sending an attachment, as JSON, and nothing else after it.
 */
function chatUploadAnswer(int $status, array $answer): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($answer, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// A post bigger than PHP's post_max_size arrives with nothing in it at all. Only an attachment can be that big
// (every form on the page sends at least its action), so it's answered like one that's too big.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    chatUploadAnswer(413, ['error' => 'tooBig']);
}

// Sending files and pictures (chat.js, with XMLHttpRequest - answered as JSON): one message with up to
// HBHUB_CHAT_ATTACHMENT_FILES_MAX attachments (files[], in the order they're shown) and whatever was typed in the box,
// as a reply if there is one. converted[] says for each one whether the browser turned it into JPEG XL (unless the user
// kept the original), and anything that isn't a picture is zipped here (assets/php/chatAttachments.php).
// Together they can be HBHUB_CHAT_ATTACHMENT_MAX_BYTES as sent. The user's tickets pay for each file. Without enough of
// them free, the answer says how many they need together, and chat.js opens the attachments popup so some can be
// deleted first. All of them go, or none do: an error about one file says which (file, its place in files[]).
// While all attachments together take the most they may, or uploads are turned off in the config, nobody can send any.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'upload') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
    if ($chat === null) {
        chatUploadAnswer(404, ['error' => 'chatNotFound']);
    }
    if (chatIsMuted($chat)) {
        chatUploadAnswer(403, ['error' => 'muted']);
    }
    if (mb_strlen($message) > HBHUB_CHAT_MESSAGE_MAX) {
        chatUploadAnswer(400, ['error' => 'messageTooLong']);
    }
    if (hbHubUrlBlacklisted($message)) {
        chatUploadAnswer(400, ['error' => 'linkBlocked']);
    }
    if (chatSendingTooFast($db, $userId)) { // before the file is worked on, and checked again under the lock below
        chatUploadAnswer(429, ['error' => 'tooFast']);
    }

    hbHubChatAttachmentsSweep($db); // expired ones first, so their tickets and space count as free
    $blocked = hbHubChatAttachmentsBlocked($db);
    if ($blocked !== null) {
        chatUploadAnswer($blocked === 'storageFull' ? 507 : 403, ['error' => $blocked]);
    }

    $uploads = hbHubChatAttachmentUploads($_FILES['files'] ?? null);
    if ($uploads === null || $uploads === []) {
        chatUploadAnswer(400, ['error' => 'uploadFailed']);
    }
    if (count($uploads) > HBHUB_CHAT_ATTACHMENT_FILES_MAX) {
        chatUploadAnswer(400, ['error' => 'tooManyFiles']);
    }

    // Each file is got ready in turn. When one can't be, the ones before it are thrown away again.
    $converted = is_array($_POST['converted'] ?? null) ? $_POST['converted'] : [];
    $prepared = [];
    $tickets = 0;
    $sentBytes = 0;
    $storedBytes = 0;
    foreach ($uploads as $index => $upload) {
        $attachment = hbHubChatAttachmentPrepare($upload, ($converted[$index] ?? null) === '1');
        if (is_string($attachment)) {
            hbHubChatAttachmentsDiscard($prepared);
            chatUploadAnswer(match ($attachment) { 'tooBig' => 413, 'storageError' => 500, default => 400 }, ['error' => $attachment, 'file' => $index]);
        }
        $attachment['tickets'] = hbHubChatAttachmentTickets($attachment['size']);
        $prepared[] = $attachment;
        $tickets += $attachment['tickets'];
        $sentBytes += $attachment['size'];
        $storedBytes += $attachment['storedSize'];
    }
    if ($sentBytes > HBHUB_CHAT_ATTACHMENT_MAX_BYTES) {
        hbHubChatAttachmentsDiscard($prepared);
        chatUploadAnswer(413, ['error' => 'tooBigTogether']);
    }

    // Like a normal message, only one in this chat that isn't deleted can be replied to
    $replyTo = chatMessage($db, $userId, (int) $chat['chatId'], $_POST['reply'] ?? null);
    $replyToId = $replyTo !== null && $replyTo['messageDeletedTimestamp'] === null ? (int) $replyTo['messageId'] : null;

    // One upload at a time across the site, so two can't both squeeze into the user's last tickets or the last free space.
    // Lock names are shared by everyone on the MySQL server, so it's named after the database too.
    if ((int) $db->query('SELECT GET_LOCK(CONCAT(DATABASE(), \'.hbHubChatAttachments\'), 30)')->fetchColumn() !== 1) {
        hbHubChatAttachmentsDiscard($prepared);
        chatUploadAnswer(503, ['error' => 'busy']);
    }
    try {
        $ticketsFree = hbHubChatAttachmentTicketsFree($db, $userId);
        $error = null;
        if ($tickets > $ticketsFree) {
            $error = [409, ['error' => 'notEnoughTickets', 'ticketsNeeded' => $tickets, 'ticketsFree' => $ticketsFree]];
        } elseif (hbHubChatAttachmentsTotalBytes($db) + $storedBytes > hbHubChatAttachmentsMaxTotal()) {
            $error = [507, ['error' => 'storageFull']];
        } else {
            $db->beginTransaction();
            if (chatSendingTooFast($db, $userId)) {
                $db->rollBack();
                $error = [429, ['error' => 'tooFast']];
            } else {
                $db->prepare('INSERT INTO `HBHub-Messages` (userId, chatId, messageContent, messageReplyToId, messageIsGhost)
                    VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $chat['chatId'], $message !== '' ? $message : null, $replyToId, (int) $chatIsGhost]);
                $messageId = (int) $db->lastInsertId();
                // In the order they were sent, which is the order they're shown in (by attachmentId)
                $insert = $db->prepare('INSERT INTO `HBHub-ChatAttachments` (userId, chatId, messageId, attachmentKey, attachmentName,
                        attachmentType, attachmentKind, attachmentSize, attachmentStoredSize, attachmentTickets, attachmentExpiresTimestamp)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? DAY)');
                foreach ($prepared as $attachment) {
                    $insert->execute([$userId, $chat['chatId'], $messageId, $attachment['key'], $attachment['name'], $attachment['type'],
                        $attachment['kind'], $attachment['size'], $attachment['storedSize'], $attachment['tickets'],
                        hbHubChatAttachmentDays($attachment['tickets'])]);
                }
                if (!$chatIsGhost) { // a ghost doesn't move the chat up anyone's list
                    $db->prepare('UPDATE `HBHub-Chats` SET lastMessageTimestamp = NOW() WHERE chatId = ?')
                        ->execute([$chat['chatId']]);
                }
                $db->commit();
            }
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        hbHubChatAttachmentsDiscard($prepared);
        throw $e;
    } finally {
        $db->query('SELECT RELEASE_LOCK(CONCAT(DATABASE(), \'.hbHubChatAttachments\'))')->fetchColumn();
    }

    if ($error !== null) {
        hbHubChatAttachmentsDiscard($prepared);
        chatUploadAnswer(...$error);
    }
    chatMarkTyping($db, (int) $chat['chatId'], $userId, false); // like sending a message
    // A new message brings the chat back for everyone who hid it, and the text with the file can @mention too.
    // Not a ghost (assets/php/chat.php), nobody else can see it.
    if (!$chatIsGhost) {
        chatUnhideForEveryone($db, (int) $chat['chatId']);
        if ($message !== '') {
            chatNotifyMentions($db, $userId, (int) $chat['chatId'], $messageId, $message);
        }
    }
    chatUploadAnswer(200, ['chatId' => (int) $chat['chatId']]);
}

// Reacting to a message (chat.js, with fetch - answered as JSON like an upload): puts an emoji under it, or with
// remove=1 takes the user's own back. Like sending, not while muted, and not on a deleted message or a notice.
// A message can have up to HBHUB_CHAT_REACTIONS_MAX different emoji, and each person can use each of them once.
// The answer is the message's reactions as they are now, as HTML, which chat.js puts in place of the old ones.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'react') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = $chat !== null ? chatMessage($db, $userId, (int) $chat['chatId'], $_POST['message'] ?? null) : null;
    $emoji = $_POST['emoji'] ?? null;
    if ($message === null || $message['messageDeletedTimestamp'] !== null) {
        chatUploadAnswer(404, ['error' => 'messageNotFound']);
    }
    if (chatIsMuted($chat)) {
        chatUploadAnswer(403, ['error' => 'muted']);
    }
    if (!chatIsReactionEmoji($emoji)) {
        chatUploadAnswer(400, ['error' => 'invalidEmoji']);
    }

    // The message is locked, so two people reacting at once can't take it past the limit
    $messageId = (int) $message['messageId'];
    $db->beginTransaction();
    $db->prepare('SELECT messageId FROM `HBHub-Messages` WHERE messageId = ? FOR UPDATE')->execute([$messageId]);
    if (($_POST['remove'] ?? null) === '1') {
        $db->prepare('DELETE FROM `HBHub-MessageReactions` WHERE messageId = ? AND userId = ? AND reactionEmoji = ?')
            ->execute([$messageId, $userId, $emoji]);
    } else {
        // A new emoji only while there's room for one more. One that's already there can always be added to.
        // Counted from the ones the user can see, so a ghost-banned user's reactions (assets/php/chat.php) don't take up room.
        $statement = $db->prepare('SELECT COUNT(DISTINCT r.reactionEmoji) AS emojiCount, COALESCE(SUM(r.reactionEmoji = ?), 0) AS emojiIsThere
            FROM `HBHub-MessageReactions` r WHERE r.messageId = ? AND ' . chatGhostVisibleSql('r', 'reactionIsGhost', $userId));
        $statement->execute([$emoji, $messageId]);
        $counts = $statement->fetch();
        if ((int) $counts['emojiIsThere'] === 0 && (int) $counts['emojiCount'] >= HBHUB_CHAT_REACTIONS_MAX) {
            $db->rollBack();
            chatUploadAnswer(409, ['error' => 'tooManyReactions']);
        }
        // IGNORE, so a double click only adds it once (messageUserEmoji is unique)
        $db->prepare('INSERT IGNORE INTO `HBHub-MessageReactions` (messageId, userId, reactionEmoji, reactionIsGhost) VALUES (?, ?, ?, ?)')
            ->execute([$messageId, $userId, $emoji, (int) $chatIsGhost]);
    }
    $db->commit();

    $reactions = chatReactions($db, $userId, (int) $chat['chatId'], $messageId, $messageId)[$messageId] ?? [];
    chatUploadAnswer(200, ['reactions' => chatReactionsHtml(chatNamesContext($db, $userId, $chat), $userId, $reactions)]);
}

// Sending a message, maybe as a reply to another one, then back to the chat so a refresh doesn't send it again.
// A GIF picked in the GIF picker comes as "gif" (its Giphy id), with whatever was typed in the box sent just before it.
// A message with a blacklisted link isn't sent at all, not even the GIF with it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
    $gifId = $chatGifsEnabled && hbHubGiphyIsId($_POST['gif'] ?? null) ? $_POST['gif'] : null;
    if ($chat !== null && hbHubUrlBlacklisted($message)) {
        chatRedirectLinkBlocked((int) $chat['chatId']);
    }

    $contents = $message !== '' ? [$message] : [];
    if ($gifId !== null) {
        $contents[] = chatGifUrl($gifId);
    }

    if ($chat !== null && !chatIsMuted($chat) && $contents !== [] && mb_strlen($message) <= HBHUB_CHAT_MESSAGE_MAX) {
        // Only a message in this chat that isn't deleted can be replied to, otherwise it's sent as a normal message
        $replyTo = chatMessage($db, $userId, (int) $chat['chatId'], $_POST['reply'] ?? null);
        $replyToId = $replyTo !== null && $replyTo['messageDeletedTimestamp'] === null ? (int) $replyTo['messageId'] : null;

        // A ghost-banned user's messages are ghosts (assets/php/chat.php): only they see them.
        // Text and a GIF are two messages, so both have to fit under the limit.
        $db->beginTransaction();
        if (chatSendingTooFast($db, $userId, count($contents))) {
            $db->rollBack();
            chatRedirectTooFast((int) $chat['chatId']);
        }
        $statement = $db->prepare('INSERT INTO `HBHub-Messages` (userId, chatId, messageContent, messageReplyToId, messageIsGhost) VALUES (?, ?, ?, ?, ?)');
        $messageIds = [];
        foreach ($contents as $i => $content) {
            $statement->execute([$userId, $chat['chatId'], $content, $i === 0 ? $replyToId : null, (int) $chatIsGhost]); // the reply goes on the first
            $messageIds[] = (int) $db->lastInsertId();
        }
        if (!$chatIsGhost) {
            $db->prepare('UPDATE `HBHub-Chats` SET lastMessageTimestamp = NOW() WHERE chatId = ?')
                ->execute([$chat['chatId']]);
        }
        $db->commit();
        // Their "is typing" goes away as the message comes in, not a few seconds later
        chatMarkTyping($db, (int) $chat['chatId'], $userId, false);

        // A new message brings the chat back for everyone who hid it, and the ones it @mentions hear about it.
        // Not a ghost, nobody else can see it.
        if (!$chatIsGhost) {
            chatUnhideForEveryone($db, (int) $chat['chatId']);
            if ($message !== '') {
                chatNotifyMentions($db, $userId, (int) $chat['chatId'], $messageIds[0], $message);
            }
        }
    }

    chatRedirect($chat !== null ? $chat['chatId'] : null);
}

// Opening a chat marks all its messages as read, unless it was just marked unread (?unread=1).
// Where the user had read up to before is kept for the "New messages" line.
$openChatId = $_GET['chat'] ?? null;
$openMembership = chatMembership($db, $userId, $openChatId);
$lastReadMessageId = $openMembership['lastReadMessageId'] ?? null;

// chat.js polls the page every 2 seconds while it's in view (?poll=1, answered as JSON at the end of this file), which
// also keeps the user's last seen time up to date like any other request. It asks for the open chat's messages after ?after= (the newest
// one it shows), and marks up to the newest one read only while the page is being looked at (?read=1).
// While it's being looked at (?looking=1, even after "Mark unread") the user counts as having the chat in front of them,
// so @mentions in it don't send a notification (chatMarkLooking()).
// The reactions on the messages it already shows, from ?from= (the oldest one) to ?after=, come along too, as they change
// on old messages as well.
// ?typing=1 while the user is typing a message in it, and ?typing=0 once when they stop (chatMarkTyping()). Not for a
// ghost-banned user (nobody else can see what they send anyway) or one a group admin muted (they can't send).
// Who else is typing comes back in the answer (chatTyping()).
$isPoll = ($_GET['poll'] ?? null) === '1';
$pollMessages = [];
$pollReactions = [];
$pollTyping = [];
$pollAfter = is_string($_GET['after'] ?? null) && ctype_digit($_GET['after']) && strlen($_GET['after']) <= 19 ? (int) $_GET['after'] : null;
$pollFrom = is_string($_GET['from'] ?? null) && ctype_digit($_GET['from']) && strlen($_GET['from']) <= 19 ? (int) $_GET['from'] : null;
if ($isPoll && $openMembership !== null && ($_GET['looking'] ?? null) === '1') {
    chatMarkLooking($db, (int) $openMembership['chatId'], $userId);
}
if ($isPoll && $openMembership !== null && in_array($_GET['typing'] ?? null, ['0', '1'], true)) {
    chatMarkTyping($db, (int) $openMembership['chatId'], $userId,
        $_GET['typing'] === '1' && !$chatIsGhost && !chatIsMuted($openMembership));
}
if ($isPoll && $openMembership !== null) {
    $pollTyping = chatTyping($db, (int) $openMembership['chatId'], $userId);
}
if ($isPoll && $openMembership !== null && $pollAfter !== null) {
    if ($pollFrom !== null && $pollFrom <= $pollAfter) {
        $pollReactions = chatReactions($db, $userId, (int) $openMembership['chatId'], $pollFrom, $pollAfter);
    }
    $pollMessages = chatMessages($db, $userId, (int) $openMembership['chatId'], HBHUB_CHAT_MESSAGES_POLLED, $pollAfter);
    if (($_GET['read'] ?? null) === '1') {
        $readUpTo = $pollMessages !== [] ? (int) end($pollMessages)['messageId'] : $pollAfter;
        $db->prepare('UPDATE `HBHub-ChatMembers` SET lastReadMessageId = (
                SELECT MAX(messageId) FROM `HBHub-Messages` WHERE chatId = ? AND messageId <= ?
            ) WHERE chatId = ? AND userId = ? AND COALESCE(lastReadMessageId, 0) < ?')
            ->execute([$openMembership['chatId'], $readUpTo, $openMembership['chatId'], $userId, $readUpTo]);
        if ($pollMessages !== []) {
            // Seen being mentioned, so the bell doesn't say it again
            hbHubMarkNotificationsReadAbout($db, $userId, 'chat.mention', ['chatId' => $openMembership['chatId']]);
        }
    }
}

// Scrolling up to the top of the open chat makes chat.js ask for the messages before ?older= (the oldest one it shows),
// answered as JSON at the end of this file too. Clicking a reply quote whose message isn't loaded yet asks the same way,
// with ?until= that message, to get everything back to it at once.
// Neither that nor polling is the page itself, so they don't count as opening the chat.
$olderThan = is_string($_GET['older'] ?? null) && ctype_digit($_GET['older']) && strlen($_GET['older']) <= 19 ? (int) $_GET['older'] : null;
$olderUntil = is_string($_GET['until'] ?? null) && ctype_digit($_GET['until']) && strlen($_GET['until']) <= 19 ? (int) $_GET['until'] : null;
if ($olderUntil !== null && $olderUntil >= ($olderThan ?? 0)) {
    $olderUntil = null; // already on the page, so just the next batch
}
$isOlder = !$isPoll && $olderThan !== null;
$isPage = !$isPoll && !$isOlder;

// Opening the chat page sweeps up attachments whose tickets have run out (nothing runs on a timer on the server),
// and works out whether new ones can be sent at all, for the Attach menu
$chatAttachBlocked = null;
if ($isPage) {
    hbHubChatAttachmentsSweep($db);
    $chatAttachBlocked = hbHubChatAttachmentsBlocked($db);
}

if ($isPage && $openMembership !== null) {
    chatMarkLooking($db, (int) $openMembership['chatId'], $userId); // just opened it, so they're looking at it
}
if ($isPage && $openMembership !== null && ($_GET['unread'] ?? null) !== '1') {
    $db->prepare('UPDATE `HBHub-ChatMembers` SET lastReadMessageId = (
            SELECT MAX(messageId) FROM `HBHub-Messages` WHERE chatId = ?
        ) WHERE chatId = ? AND userId = ?')
        ->execute([$openMembership['chatId'], $openMembership['chatId'], $userId]);
    hbHubMarkNotificationsReadAbout($db, $userId, 'chat.mention', ['chatId' => $openMembership['chatId']]); // its mentions too
}

// Every chat the user is in, newest activity first.
// DMs (and groups without a name) are named after the other members, or a DM after the nickname the other person was given.
// Unread messages are the ones from others, not deleted, that came after the last one the user read.
// Notices (a new group name, someone kicked) aren't the last message and aren't unread.
// A group request says who added the user: whoever did it with Add to chat, otherwise the one who made the group.
// Ghosts (assets/php/chat.php) are left out: other people's messages and ghost invites, and chats the user only has a
// ghost invite to. A ghost-banned user's own messages don't move a chat up anyone's list (lastMessageTimestamp),
// so for them it's worked out from those too, and the list looks like it always does.
$statement = $db->prepare('SELECT c.chatId, c.chatType, c.chatName, c.chatIconAttachmentId, c.chatSettings, c.chatClassKey,
        cm.userAcknowledgedJoin, cm.memberRole,
        ' . ($chatIsGhost
            ? 'GREATEST(COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp), COALESCE((
                SELECT MAX(gm.messageSentTimestamp) FROM `HBHub-Messages` gm
                WHERE gm.chatId = c.chatId AND gm.userId = ' . $userId . ' AND gm.messageIsGhost = 1 AND gm.messageEvent = 0
            ), c.chatCreatedTimestamp))'
            : 'COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp)') . ' AS chatActivity,
        COALESCE((
            SELECT au.userName FROM `HBHub-Messages` am JOIN `HBHub-Users` au ON au.userId = am.userId
            WHERE am.chatId = c.chatId AND cm.userAcknowledgedJoin = 0 AND am.messageEventType = ' . HBHUB_CHAT_EVENT_ADD . '
                AND am.messageEventUserId = cm.userId AND am.messageIsGhost = 0
            ORDER BY am.messageId DESC LIMIT 1
        ), (SELECT cu.userName FROM `HBHub-Users` cu WHERE cu.userId = c.chatCreatedBy)) AS chatAddedByName,
        (
            SELECT om.userId FROM `HBHub-ChatMembers` om
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserId,
        (
            SELECT ou.userName FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserName,
        (
            SELECT ou.userStatus = ' . HBHUB_SESSION_STATUS_BANNED . ' FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserBanned,
        (
            SELECT ou.userAvatarAttachmentId FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserAvatarId,
        COALESCE(NULLIF(c.chatName, \'\'), (
            SELECT GROUP_CONCAT(COALESCE(om.memberNickname, ou.userName) ORDER BY ou.userName SEPARATOR \', \')
            FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ?' . ($chatIsGhost ? '' : ' AND om.memberIsGhost = 0') . '
        )) AS chatTitle,
        (
            SELECT COALESCE(' . chatGhostContentSql('m', $userId) . ', CASE ' . hbHubChatAttachmentsCountSql('m') . '
                    WHEN 0 THEN NULL WHEN 1 THEN \'Attachment\' ELSE CONCAT(' . hbHubChatAttachmentsCountSql('m') . ', \' attachments\') END)
                FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageDeletedTimestamp IS NULL AND m.messageEvent = 0
                AND ' . chatGhostVisibleSql('m', 'messageIsGhost', $userId) . '
            ORDER BY m.messageId DESC LIMIT 1
        ) AS chatLastMessage,
        (
            SELECT COUNT(*) FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageId > COALESCE(cm.lastReadMessageId, 0)
                AND m.userId <> ? AND m.messageDeletedTimestamp IS NULL AND m.messageEvent = 0 AND m.messageIsGhost = 0
        ) AS chatUnreadCount
    FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
    WHERE cm.userId = ? AND c.chatStatus = ? AND cm.memberIsGhost = 0
    ORDER BY chatActivity DESC');
$statement->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, HBHUB_CHAT_STATUS_ACTIVE]);
$chats = [];
$chatRequests = []; // chats the user was added to but hasn't accepted yet
$userSettings = hbHubUserSettings($hbHubUser['userSettings']);
foreach ($statement->fetchAll() as $chat) {
    $chat['chatTitle'] ??= 'Just you';
    $settings = chatSettings($chat['chatSettings']);
    $chat['chatHiddenSetting'] = chatIsHidden($userSettings, (int) $chat['chatId']);
    $chat['chatMutedLabel'] = chatMutedLabel($userSettings, (int) $chat['chatId']);
    $nickname = $chat['dmUserId'] !== null ? $settings->chatNicknames->{$chat['dmUserId']} ?? null : null;
    $chat['chatNickname'] = is_string($nickname) ? $nickname : null;
    $chat['chatTitle'] = $chat['chatNickname'] ?? $chat['chatTitle'];
    $background = chatBackground($settings);
    $chat['chatBackground'] = $background !== null ? implode(' ', $background) : null; // "#a1b2c3 #d4e5f6 90", how chat.js reads it
    if (chatGifId($chat['chatLastMessage']) !== null) {
        $chat['chatLastMessage'] = 'GIF';
    } elseif ($chat['chatLastMessage'] !== null) {
        $chat['chatLastMessage'] = chatFormatPlain($chat['chatLastMessage']); // "bold", not "**bold**"
    }
    if ($chat['userAcknowledgedJoin']) {
        $chats[] = $chat;
    } else {
        $chatRequests[] = $chat;
    }
}

// Without JS the search bar submits ?q= and the list is filtered here instead.
// Chats the user hid stay out of the list until a new message arrives or they open it again, but searching still finds them.
// A DM with a nickname is found by the nickname and by the real username.
$chatSearch = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
foreach ($chats as &$chat) {
    $chat['chatIsOpen'] = is_string($openChatId) && (string) $chat['chatId'] === $openChatId;
    $chat['chatIsGroupAdmin'] = chatIsGroupAdmin($chat);
    $chat['chatIsGroupOwner'] = chatIsGroupOwner($chat);

    // Opening a hidden chat (found by searching for it) brings it back for good
    if ($chat['chatIsOpen'] && $chat['chatHiddenSetting']) {
        chatSetHidden($db, $userId, (int) $chat['chatId'], false);
        $chat['chatHiddenSetting'] = false;
    }
    $chat['chatHiddenByUser'] = $chat['chatHiddenSetting'];
    $chat['chatHidden'] = $chatSearch === ''
        ? $chat['chatHiddenByUser']
        : mb_stripos($chat['chatTitle'], $chatSearch) === false
            && ($chat['chatNickname'] === null || mb_stripos($chat['dmUserName'], $chatSearch) === false);
}
unset($chat);
$chatListHasShown = in_array(false, array_column($chats, 'chatHidden'), true);

// The chat shown on the right, if any
$openChat = null;
$messages = [];
foreach ($chats as $chat) {
    if ($chat['chatIsOpen']) {
        $openChat = $chat;
        break;
    }
}

// Who can be @mentioned in the open chat, for the mentions in its messages (chatMentions, only worked out when there
// are messages to show) and for the list that comes up when typing @ in the message box or Edit (mentions.js),
// which leaves out the user: [{name: the name shown, userName: what goes in the message}]
$openChatMentionChoices = [];
if ($openChat !== null && ($isPage || $isOlder || $pollMessages !== [])) {
    $mentionPeople = chatMentionPeople($db, (int) $openChat['chatId'], $userId);
    $openChat['chatMentions'] = hbHubMentionLookup($mentionPeople);
    foreach ($mentionPeople as $person) {
        if (!$person['isYou']) {
            $openChatMentionChoices[] = ['name' => $person['name'], 'userName' => $person['userName']];
        }
    }
    usort($openChatMentionChoices, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
}

// Everyone in the open group, for the Members popup: site admins first, then the group owner and admins, then the ones who accepted,
// then by name. Banned and deleted accounts get no profile link, like their messages.
// Ghost invites (assets/php/chat.php) are only there for a ghost-banned user, to whom they look like any other invite.
$openChatMembers = [];
if ($openChat !== null && $isPage && (int) $openChat['chatType'] === HBHUB_CHAT_TYPE_GROUP) {
    $statement = $db->prepare('SELECT cm.userId, cm.memberRole, cm.userAcknowledgedJoin, u.userRole = ? AS memberIsSiteAdmin,
            COALESCE(cm.memberNickname, u.userName) AS memberName, u.userAvatarAttachmentId,
            u.userStatus IS NULL OR u.userStatus = ? AS memberNoProfile
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Users` u ON u.userId = cm.userId
        WHERE cm.chatId = ?' . ($chatIsGhost ? '' : ' AND cm.memberIsGhost = 0') . '
        ORDER BY memberIsSiteAdmin DESC, cm.memberRole DESC, cm.userAcknowledgedJoin DESC, memberName');
    $statement->execute([HBHUB_CHAT_SITE_ROLE_ADMIN, HBHUB_SESSION_STATUS_BANNED, $openChat['chatId']]);
    $openChatMembers = $statement->fetchAll();
}

$firstUnreadMessageId = null; // the "New messages" line goes above this one
$messagesHaveOlder = false; // there are older messages than the ones shown, which chat.js loads when scrolling up
if ($openChat !== null && $isPage) {
    // The newest ones, or when more are unread, enough to start at the last one read (up to a limit), so the "New messages" line is there
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Messages` WHERE chatId = ? AND messageId > ?');
    $statement->execute([$openChat['chatId'], (int) $lastReadMessageId]);
    $messagesShown = min(max(HBHUB_CHAT_MESSAGES_SHOWN, (int) $statement->fetchColumn() + 1), HBHUB_CHAT_MESSAGES_UNREAD_MAX);
    [$messages, $messagesHaveOlder] = chatMessagesPage($db, $userId, (int) $openChat['chatId'], $messagesShown);

    foreach ($messages as $message) {
        if ($firstUnreadMessageId === null && (int) $message['messageId'] > (int) $lastReadMessageId
            && (int) $message['userId'] !== $userId && $message['messageDeletedTimestamp'] === null && !$message['messageEvent']) {
            $firstUnreadMessageId = (int) $message['messageId'];
        }
    }
}

/**
 * Short time for the chat list: just the time for today, otherwise the date.
 */
function chatTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    return date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : date('d/m/Y', $time);
}

/**
 * Time for a message or notice in the open chat: just the time for today, otherwise the date and the time.
 */
function chatMessageTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    return date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : date('d/m/Y • H.i', $time);
}

function chatInitial(string $name): string
{
    return mb_strtoupper(mb_substr($name, 0, 1));
}

/**
 * The round picture for a chat: the group's icon, or in a DM the other person's profile picture,
 * otherwise the first letter of its name.
 * The attachment id in the icon's address changes with every new icon, so an old one is never shown from the cache.
 */
function chatAvatarHtml(array $chat): string
{
    if ($chat['chatIconAttachmentId'] !== null) {
        return '<span class="chat-avatar" aria-hidden="true">'
            . chatQueuedImgHtml('/chat/icon?chat=' . (int) $chat['chatId'] . '&v=' . (int) $chat['chatIconAttachmentId']) . '</span>';
    }
    if ($chat['dmUserId'] !== null && !$chat['dmUserBanned']) {
        return chatUserAvatarHtml((int) $chat['dmUserId'], $chat['chatTitle'], $chat['dmUserAvatarId']);
    }
    return '<span class="chat-avatar" aria-hidden="true">' . htmlspecialchars(chatInitial($chat['chatTitle'])) . '</span>';
}

/**
 * A user's round profile picture, or the first letter of their name when they don't have one.
 */
function chatUserAvatarHtml(int $userId, string $name, mixed $avatarId, string $class = ''): string
{
    $src = hbHubAvatarUrl($userId, $avatarId !== null ? (int) $avatarId : null);
    return '<span class="chat-avatar' . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true">'
        . ($src !== null ? chatQueuedImgHtml($src) : htmlspecialchars(chatInitial($name)))
        . '</span>';
}

/**
 * A profile picture or chat icon that pictureQueue.js loads, two at a time (data-queued-picture, see there): each one
 * is its own PHP request, and a chat list or a page of messages would otherwise send them all at once.
 * Without JS the <noscript> one loads instead.
 */
function chatQueuedImgHtml(string $src): string
{
    $src = htmlspecialchars($src);
    return '<img data-queued-picture="' . $src . '" alt=""><noscript><img src="' . $src . '" alt="" loading="lazy"></noscript>';
}

/**
 * What a deleted message shows instead of itself, from its messageDeletedByAdmin.
 */
function chatDeletedLabel(mixed $deletedByAdmin): string
{
    return HBHUB_CHAT_DELETED_LABELS[(int) $deletedByAdmin] ?? HBHUB_CHAT_DELETED_LABELS[0];
}

/**
 * The quote above a reply: who and what it answers, on one line.
 * A link to the original, unless it's gone, so it can be scrolled to. When the original isn't loaded yet
 * (only the newest messages are at first), chat.js loads back to it first (data-reply-id).
 */
function chatReplyHtml(array $openChat, int $userId, array $message): string
{
    if ($message['replyId'] === null) {
        $sender = '';
        $text = '<span class="chat-message-reply-text is-deleted">Original message is gone</span>';
    } else {
        $sender = '<span class="chat-message-reply-sender">'
            . htmlspecialchars(chatSenderName($openChat, $userId, (int) $message['replyUserId'], $message['replySenderName'])) . '</span>';
        if ($message['replyDeletedTimestamp'] !== null) {
            $text = '<span class="chat-message-reply-text is-deleted">' . chatDeletedLabel($message['replyDeletedByAdmin']) . '</span>';
        } elseif ($message['replyContent'] === null) {
            $text = '<span class="chat-message-reply-text is-deleted">Attachment</span>';
        } elseif (chatGifId($message['replyContent']) !== null) {
            $text = '<span class="chat-message-reply-text is-deleted">GIF</span>';
        } else {
            // Shortened here too, so a long message isn't sent twice. Without its formatting, it's only one line.
            $content = chatFormatPlain($message['replyContent']);
            $content = preg_replace('~\s+~u', ' ', $content) ?? $content;
            $text = '<span class="chat-message-reply-text">' . htmlspecialchars(mb_substr($content, 0, 150)) . '</span>';
        }
    }

    $label = '<span class="chat-visually-hidden">Reply to </span>';
    if ($message['replyId'] === null) {
        return '<span class="chat-message-reply">' . $label . $sender . $text . '</span>';
    }
    return '<a class="chat-message-reply" href="#message-' . (int) $message['replyId'] . '" data-reply-link data-reply-id="'
        . (int) $message['replyId'] . '">' . $label . $sender . $text . '</a>';
}

/**
 * The reactions under a message, for the page, polling and reacting: a button for each emoji with how many reacted
 * with it, which adds the user's own or takes it back (chat.js). The user's own are lit up (is-mine), and pointing at
 * one says who reacted, by the names the chat shows. Nothing at all when there are none, so chat.css can hide the row.
 *
 * @param array<string, array<int, string>> $reactions emoji => [userId => name], one message's from chatReactions()
 */
function chatReactionsHtml(array $openChat, int $userId, array $reactions): string
{
    $html = '';
    foreach ($reactions as $emoji => $reactors) {
        $emoji = (string) $emoji;
        $names = [];
        foreach ($reactors as $reactorId => $name) {
            $names[] = chatSenderName($openChat, $userId, (int) $reactorId, $name);
        }
        // "You, Alice and 3 others reacted with 👍"
        $shown = array_slice($names, 0, HBHUB_CHAT_REACTION_NAMES_SHOWN);
        $others = count($names) - count($shown);
        if ($others > 0) {
            $shown[] = $others . ($others === 1 ? ' other' : ' others');
        }
        $last = array_pop($shown);
        $who = htmlspecialchars(($shown !== [] ? implode(', ', $shown) . ' and ' : '') . $last . ' reacted with ' . $emoji);

        $isMine = isset($reactors[$userId]);
        $html .= '<button type="button" class="chat-reaction' . ($isMine ? ' is-mine' : '') . '" data-reaction="' . htmlspecialchars($emoji)
            . '" aria-pressed="' . ($isMine ? 'true' : 'false') . '" aria-label="' . $who . '" title="' . $who . '">'
            . '<span class="chat-reaction-emoji">' . htmlspecialchars($emoji) . '</span>'
            . '<span class="chat-reaction-count">' . count($reactors) . '</span></button>';
    }
    return $html;
}

/**
 * A chat in the list on the left, for the page and for polling.
 * Right-clicking it opens the chat menu (chat.js), the data-chat-* attributes say what it offers.
 */
function chatListItem(array $chat): void
{
    ?>
                    <li data-chat-title="<?= htmlspecialchars($chat['chatTitle']) ?>"
                        data-chat-id="<?= $chat['chatId'] ?>"
                        data-chat-type="<?= (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'dm' : 'group' ?>"
                        data-chat-name="<?= htmlspecialchars($chat['chatName'] ?? '') ?>"<?=
                        $chat['dmUserId'] !== null ? ' data-chat-user-id="' . (int) $chat['dmUserId'] . '"' : '' ?><?=
                        $chat['dmUserName'] !== null ? ' data-chat-username="' . htmlspecialchars($chat['dmUserName']) . '"' : '' ?><?=
                        $chat['chatNickname'] !== null ? ' data-chat-nickname="' . htmlspecialchars($chat['chatNickname']) . '"' : '' ?><?=
                        $chat['chatIsGroupAdmin'] ? ' data-chat-admin' : '' ?><?=
                        $chat['chatIsGroupOwner'] ? ' data-chat-owner' : '' ?><?=
                        $chat['chatClassKey'] !== null ? ' data-chat-class' : '' ?><?=
                        $chat['chatIconAttachmentId'] !== null ? ' data-chat-icon' : '' ?><?=
                        $chat['chatBackground'] !== null ? ' data-chat-background="' . htmlspecialchars($chat['chatBackground']) . '"' : '' ?><?=
                        $chat['chatMutedLabel'] !== null ? ' data-chat-muted' : '' ?><?=
                        $chat['chatHiddenByUser'] ? ' data-chat-hidden-by-user' : '' ?><?=
                        $chat['chatHidden'] ? ' hidden' : '' ?>>
                        <a class="chat-list-item<?= $chat['chatIsOpen'] ? ' active' : '' ?><?= $chat['chatUnreadCount'] > 0 ? ' is-unread' : '' ?><?= $chat['chatMutedLabel'] !== null ? ' is-muted' : '' ?>" href="/chat/?chat=<?= $chat['chatId'] ?>"<?= $chat['chatIsOpen'] ? ' aria-current="page"' : '' ?>>
                            <?= chatAvatarHtml($chat) ?>
                            <span class="chat-list-text">
                                <span class="chat-list-top">
                                    <span class="chat-list-title"><?= htmlspecialchars($chat['chatTitle']) ?></span>
                                    <?php if ($chat['chatMutedLabel'] !== null): ?>
                                        <img class="chat-list-muted" src="/assets/images/icons/muted.svg"
                                             alt="<?= htmlspecialchars($chat['chatMutedLabel']) ?>" title="<?= htmlspecialchars($chat['chatMutedLabel']) ?>">
                                    <?php endif; ?>
                                    <span class="chat-list-time"><?= chatTime($chat['chatActivity']) ?></span>
                                </span>
                                <span class="chat-list-bottom">
                                    <span class="chat-list-preview"><?= htmlspecialchars($chat['chatLastMessage'] ?? 'No messages yet') ?></span>
                                    <?php if ($chat['chatUnreadCount'] > 0): ?>
                                        <span class="chat-list-unread"><?= $chat['chatUnreadCount'] > 99 ? '99+' : (int) $chat['chatUnreadCount'] ?><span class="chat-visually-hidden"> unread</span></span>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </a>
                    </li>
    <?php
}

/**
 * A message request in the Message requests popup, for the page and for polling.
 * Block, Decline and Accept are plain form posts.
 */
function chatRequestItem(array $request): void
{
    $isGroup = (int) $request['chatType'] !== HBHUB_CHAT_TYPE_DM;
    ?>
                            <li class="chat-request">
                                <?= chatAvatarHtml($request) ?>
                                <span class="chat-list-text">
                                    <span class="chat-list-top">
                                        <span class="chat-list-title"><?= htmlspecialchars($request['chatTitle']) ?></span>
                                        <span class="chat-list-time"><?= chatTime($request['chatActivity']) ?></span>
                                    </span>
                                    <span class="chat-list-preview">
                                        <?= $isGroup ? 'Group, added by ' . htmlspecialchars($request['chatAddedByName'] ?? 'someone') : 'Wants to send you messages' ?>
                                    </span>
                                    <?php if ($request['chatLastMessage'] !== null): ?>
                                        <span class="chat-list-preview chat-request-message"><?= htmlspecialchars($request['chatLastMessage']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <form class="chat-request-answer" action="/chat/" method="post">
                                    <input type="hidden" name="chat" value="<?= $request['chatId'] ?>">
                                    <button type="submit" class="chat-btn is-danger" name="action" value="block"
                                            title="Decline, and stop <?= $isGroup ? 'whoever added you' : 'them' ?> sending you new requests">Block</button>
                                    <button type="submit" class="chat-btn" name="action" value="decline">Decline</button>
                                    <button type="submit" class="chat-new-btn" name="action" value="accept">Accept</button>
                                </form>
                            </li>
    <?php
}

/**
 * A notice in the middle of the open chat about a change (a new group name, someone kicked...), for the page and for polling.
 * It has data-notice-id, not data-message-id: polling goes on after it, but it isn't a message to open the menu on or tab to.
 */
function chatNoticeItem(array $openChat, int $userId, array $message): void
{
    ?>
                        <li class="chat-notice" data-notice-id="<?= (int) $message['messageId'] ?>">
                            <span class="chat-notice-text"><?= chatNoticeHtml($openChat, $userId, $message) ?>
                                <time class="chat-notice-time" datetime="<?= htmlspecialchars($message['messageSentTimestamp']) ?>"><?= chatMessageTime($message['messageSentTimestamp']) ?></time></span>
                        </li>
    <?php
}

/**
 * What the little document on a file card says: the file's extension in capitals ("PDF", "DOCX"), or FILE without one.
 * chat.js works it out the same way for files waiting to be sent.
 */
function chatAttachmentExtension(string $name): string
{
    $extension = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));
    return preg_match('~^[A-Z0-9]{1,5}$~', $extension) === 1 ? $extension : 'FILE';
}

/**
 * The gone ones' line ("Attachment deleted", "2 attachments expired"), or '' when none are gone.
 * chat.js writes the same when one is deleted from the Your attachments popup (data-attachments-deleted/-expired).
 */
function chatAttachmentsGoneHtml(int $deleted, int $expired): string
{
    $parts = [];
    if ($deleted > 0) {
        $parts[] = ($deleted === 1 ? 'Attachment' : $deleted . ' attachments') . ' deleted';
    }
    if ($expired > 0) {
        $parts[] = ($expired === 1 ? 'Attachment' : $expired . ' attachments') . ' expired';
    }
    return $parts === [] ? '' : '<p class="chat-message-deleted chat-attachment-gone" data-attachments-deleted="' . $deleted . '"'
        . ' data-attachments-expired="' . $expired . '">' . implode(' &middot; ', $parts) . '</p>';
}

/**
 * A message's attachments, above its text (data-attachments): first its pictures, then its other files, then one line
 * for the ones whose file is gone (deleted or expired).
 * One picture is shown 200px tall with its name below (which downloads it). Several go in a gallery
 * (chat-attachment-gallery) of square tiles, two or three to a row, without names - the picture viewer shows each one's.
 * Any other file is a card with its extension on a little document, which downloads it.
 * A JPEG XL picture has data-attachment-jxl, so chat.js can draw it itself in browsers that can't show JPEG XL.
 * Clicking a picture opens it in the picture viewer (chat.js), which goes through the message's pictures, and whose
 * Info shows each one's data-attachment-name, -size, -type and when it expires (data-attachment-expires, Unix seconds).
 * chat.js builds the same for the message shown while it's being sent (showSending()).
 *
 * @param list<array> $attachments from chatAttachments()
 */
function chatAttachmentsHtml(array $attachments): string
{
    $pictures = [];
    $files = [];
    $deleted = 0;
    $expired = 0;
    foreach ($attachments as $attachment) {
        if (!$attachment['attachmentIsLive']) {
            if ((int) $attachment['attachmentStatus'] === HBHUB_CHAT_ATTACHMENT_DELETED) {
                $deleted++;
            } else {
                $expired++;
            }
            continue;
        }

        $url = '/chat/attachment?id=' . (int) $attachment['attachmentId'];
        $downloadUrl = $url . '&amp;download=1';
        $name = htmlspecialchars($attachment['attachmentName']);
        $size = hbHubChatAttachmentSizeLabel((int) $attachment['attachmentSize']);
        if (in_array($attachment['attachmentType'], HBHUB_CHAT_ATTACHMENT_IMAGE_TYPES, true)) {
            $pictures[] = '<figure class="chat-attachment is-image" data-attachment-name="' . $name . '" data-attachment-size="' . $size . '"'
                . ' data-attachment-download="' . $downloadUrl . '" data-attachment-type="' . htmlspecialchars($attachment['attachmentType']) . '"'
                . ' data-attachment-expires="' . (int) strtotime($attachment['attachmentExpiresTimestamp']) . '">'
                . '<img class="chat-attachment-image" src="' . $url . '" alt="' . $name . '" title="' . $name . '" height="200" loading="lazy"'
                . ($attachment['attachmentType'] === 'image/jxl' ? ' data-attachment-jxl' : '') . '>'
                . '<figcaption class="chat-attachment-caption">'
                . '<a class="chat-attachment-download" href="' . $downloadUrl . '" download="' . $name . '" title="Download">' . $name . '</a>'
                . '<span class="chat-attachment-size">' . $size . '</span></figcaption>'
                . '</figure>';
            continue;
        }
        $files[] = '<a class="chat-attachment is-file" href="' . $downloadUrl . '" download="' . $name . '" title="Download ' . $name . '">'
            . '<span class="chat-attachment-ext" aria-hidden="true">' . chatAttachmentExtension($attachment['attachmentName']) . '</span>'
            . '<span class="chat-attachment-text"><span class="chat-attachment-name">' . $name . '</span>'
            . '<span class="chat-attachment-size">' . $size . '</span></span>'
            . '<span class="chat-attachment-save" aria-hidden="true"></span>'
            . '</a>';
    }

    $html = '';
    if (count($pictures) === 1) {
        $html .= $pictures[0];
    } elseif ($pictures !== []) {
        // Two to a row for 2 and 4, three for the rest, so no row is left with just one when it can be helped
        $columns = in_array(count($pictures), [2, 4], true) ? 2 : 3;
        $html .= '<div class="chat-attachment-gallery is-cols-' . $columns . '">' . implode('', $pictures) . '</div>';
    }
    if ($files !== []) {
        $html .= '<div class="chat-attachment-files">' . implode('', $files) . '</div>';
    }
    return '<div class="chat-attachments" data-attachments>' . $html . chatAttachmentsGoneHtml($deleted, $expired) . '</div>';
}

/**
 * A message in the open chat, for the page and for polling.
 * Right-clicking it opens the message menu (chat.js), the data-message-* attributes say what it offers.
 * data-message-attachment names its attachments whose file is still there (the name of one, or "3 attachments"), for the
 * reply bar and Delete, and data-message-attachments is how many of them there are.
 * data-message-time is when it was sent (Unix seconds), for grouping messages sent close together (chat.js).
 */
function chatMessageItem(array $openChat, int $userId, array $message): void
{
    if ($message['messageEvent']) {
        chatNoticeItem($openChat, $userId, $message);
        return;
    }

    $isOwn = (int) $message['userId'] === $userId;
    $isDeleted = $message['messageDeletedTimestamp'] !== null;
    $gifId = $isDeleted ? null : chatGifId($message['messageContent']);
    $hasAttachment = !$isDeleted && $message['attachments'] !== [];
    $liveAttachments = $hasAttachment ? array_values(array_filter($message['attachments'], fn (array $attachment): bool => (bool) $attachment['attachmentIsLive'])) : [];
    $attachmentLabel = count($liveAttachments) === 1 ? $liveAttachments[0]['attachmentName'] : hbHubChatAttachmentsLabel(count($liveAttachments));
    // The text with its formatting and @mentions. One that mentions the user lights up (is-mention).
    $textHtml = !$isDeleted && $gifId === null && $message['messageContent'] !== null
        ? chatFormatHtml($message['messageContent'], $openChat['chatMentions'] ?? [])
        : null;
    $isMention = !$isOwn && $textHtml !== null && str_contains($textHtml, ' data-mention-you');
    ?>
                        <li class="chat-message<?= $isOwn ? ' is-own' : '' ?><?= $isMention ? ' is-mention' : '' ?>" id="message-<?= (int) $message['messageId'] ?>" tabindex="-1"
                            data-message-id="<?= (int) $message['messageId'] ?>" data-message-user-id="<?= (int) $message['userId'] ?>"
                            data-message-time="<?= (int) strtotime($message['messageSentTimestamp']) ?>"
                            data-message-sender="<?= htmlspecialchars(chatSenderName($openChat, $userId, (int) $message['userId'], $message['senderName'])) ?>"<?=
                            $isOwn ? ' data-message-own' : '' ?><?= $isDeleted ? ' data-message-deleted' : '' ?><?= $gifId !== null ? ' data-message-gif' : '' ?><?=
                            $liveAttachments !== [] ? ' data-message-attachment="' . htmlspecialchars($attachmentLabel) . '" data-message-attachments="' . count($liveAttachments) . '"' : '' ?>>
                            <?php if (!$isOwn && (int) $openChat['chatType'] !== HBHUB_CHAT_TYPE_DM): ?>
                                <?php if ($message['senderBanned']): ?>
                                    <span class="chat-message-sender"><?= chatUserAvatarHtml((int) $message['userId'], $message['senderName'], null, 'is-small') ?><?= htmlspecialchars($message['senderName']) ?></span>
                                <?php else: ?>
                                    <a class="chat-message-sender chat-profile-link" href="<?= htmlspecialchars(hbHubProfileUrl((int) $message['userId'])) ?>" title="View profile"><?=
                                        chatUserAvatarHtml((int) $message['userId'], $message['senderName'], $message['senderAvatarId'], 'is-small') ?><?= htmlspecialchars($message['senderName']) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($message['messageReplyToId'] !== null && !$isDeleted): ?>
                                <?= chatReplyHtml($openChat, $userId, $message) ?>
                            <?php endif; ?>
                            <div class="chat-message-bubble<?= $gifId !== null ? ' is-gif' : '' ?><?= $hasAttachment ? ' has-attachment' : '' ?>">
                                <?php if ($isDeleted): ?>
                                    <p class="chat-message-deleted"><?= chatDeletedLabel($message['messageDeletedByAdmin']) ?></p>
                                <?php elseif ($gifId !== null): ?>
                                    <!-- Giphy's 200px tall version, so a chat full of GIFs doesn't load every full-size one -->
                                    <img class="chat-message-gif" src="https://media.giphy.com/media/<?= $gifId ?>/200.webp" alt="GIF"
                                         height="200" loading="lazy" referrerpolicy="no-referrer">
                                <?php else: ?>
                                    <?= $hasAttachment ? chatAttachmentsHtml($message['attachments']) : '' ?>
                                    <?php if ($textHtml !== null): ?>
                                        <!-- data-message-source is the text as it was typed, for Edit (chat.js) - only on the user's own -->
                                        <div class="chat-message-text" data-message-text<?= $isOwn ? ' data-message-source="' . htmlspecialchars($message['messageContent']) . '"' : ''
                                            ?>><?= $textHtml ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isDeleted): ?>
                                <!-- Reactions (chatReactionsHtml): chat.js adds and takes them back, and polling keeps them up to date -->
                                <div class="chat-reactions" role="group" aria-label="Reactions" data-reactions><?= chatReactionsHtml($openChat, $userId, $message['reactions']) ?></div>
                            <?php endif; ?>
                            <span class="chat-message-meta">
                                <time datetime="<?= htmlspecialchars($message['messageSentTimestamp']) ?>"><?= chatMessageTime($message['messageSentTimestamp']) ?></time>
                                <?= $message['messageIsEdited'] && !$isDeleted ? ' &middot; edited' : '' ?>
                            </span>
                        </li>
    <?php
}

// The answer to chat.js scrolling up (?older=): the messages before that one as HTML, and whether there are even older ones.
// With ?until= it's every message back to that one, as many as JUMP_MAX at a time.
if ($isOlder) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($openChat === null) {
        http_response_code(404);
        echo json_encode(['error' => 'chatNotFound']);
        exit;
    }

    [$olderMessages, $hasOlder] = $olderUntil !== null
        ? chatMessagesPage($db, $userId, (int) $openChat['chatId'], HBHUB_CHAT_MESSAGES_JUMP_MAX, $olderThan, $olderUntil)
        : chatMessagesPage($db, $userId, (int) $openChat['chatId'], HBHUB_CHAT_MESSAGES_SHOWN, $olderThan);
    ob_start();
    foreach ($olderMessages as $message) {
        chatMessageItem($openChat, $userId, $message);
    }
    echo json_encode(['messages' => ob_get_clean(), 'hasOlder' => $hasOlder], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// The answer to chat.js polling: the open chat's new messages, the whole chat list and the message requests, all as HTML.
// openChat is false when the open chat is gone (left, deleted or removed from it), so chat.js can leave it.
// background is the open chat's background, so a new one shows up for everyone in it.
// typing is the names of everyone else typing in the open chat right now, for the "... is typing" line.
// The chat list and the requests come with a version (chatListV, requestsV: a hash of the HTML), and chat.js sends back
// the ones it shows (?listV=, ?requestsV=). Most polls nothing in them changed, so then the HTML is left out, and the
// same few KB aren't sent every 2 seconds to every open chat.
// About every 15 seconds navUpdates.js sends the nav bar's request along too (?nav=), answered in "nav"
// (hbHubNavRide() in assets/php/navUpdates.php).
if ($isPoll) {
    ob_start();
    foreach ($pollMessages as $message) {
        chatMessageItem($openChat, $userId, $message);
    }
    $pollMessagesHtml = ob_get_clean();

    ob_start();
    foreach ($chats as $chat) {
        chatListItem($chat);
    }
    $pollChatListHtml = ob_get_clean();

    ob_start();
    foreach ($chatRequests as $request) {
        chatRequestItem($request);
    }
    $pollRequestsHtml = ob_get_clean();

    // messageId => its reactions as HTML, only for messages that have any (chat.js empties the rest)
    $pollReactionsHtml = [];
    foreach ($openChat !== null ? $pollReactions : [] as $messageId => $reactions) {
        $pollReactionsHtml[$messageId] = chatReactionsHtml($openChat, $userId, $reactions);
    }

    $pollAnswer = [
        'openChat' => $openChat !== null,
        'background' => $openChat['chatBackground'] ?? null,
        'messages' => $pollMessagesHtml,
        'reactions' => (object) $pollReactionsHtml, // an object even when empty
        'typing' => $pollTyping,
        'chatListV' => hash('xxh64', $pollChatListHtml),
        'requestsV' => hash('xxh64', $pollRequestsHtml),
        'requestCount' => count($chatRequests),
        'unreadChats' => hbHubUnreadChatCount($db, $userId, $userSettings), // the number on Chat in the side bar
    ];
    if (($_GET['listV'] ?? null) !== $pollAnswer['chatListV']) {
        $pollAnswer['chatList'] = $pollChatListHtml;
    }
    if (($_GET['requestsV'] ?? null) !== $pollAnswer['requestsV']) {
        $pollAnswer['requests'] = $pollRequestsHtml;
    }
    $pollNav = hbHubNavRide($db, $hbHubUser);
    if ($pollNav !== null) {
        $pollAnswer['nav'] = $pollNav;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($pollAnswer, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chat.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/mentions.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/mentions.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chat.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chat.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/mentions.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/mentions.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title><?= $openChat !== null ? htmlspecialchars($openChat['chatTitle']) . ' - ' : '' ?>Chat - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Chat page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <!-- data-jxl-worker: the script that turns pictures into JPEG XL, and draws JPEG XL ones in browsers that can't (chat.js) -->
    <main class="chat<?= $openChat !== null ? ' has-open-chat' : '' ?>"
          data-jxl-worker="/assets/js/chatJxlWorker.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chatJxlWorker.js') ?>">
        <h1 class="chat-visually-hidden">Chat</h1>

        <!-- Left: search, new chat and every chat the user is in -->
        <aside class="chat-sidebar" aria-label="Your chats">
            <form class="chat-search" action="/chat/" method="get" role="search">
                <label class="chat-visually-hidden" for="chat-search">Search chats</label>
                <input type="search" id="chat-search" name="q" placeholder="Search chats" autocomplete="off"
                       value="<?= htmlspecialchars($chatSearch) ?>">
            </form>

            <div class="chat-actions">
                <button type="button" class="chat-new-btn" data-new-chat-open>New chat</button>
                <!-- Hidden while there are no message requests, chat.js polling shows it when one comes in -->
                <button type="button" class="chat-requests-btn" data-requests-open
                        aria-label="Message requests (<?= count($chatRequests) ?>)" title="Message requests"<?= $chatRequests === [] ? ' hidden' : '' ?>>
                    <img src="/assets/images/icons/notification.png" alt="">
                    <span class="chat-requests-count" aria-hidden="true" data-requests-count><?= count($chatRequests) > 9 ? '9+' : count($chatRequests) ?></span>
                </button>
            </div>

            <h2 class="chat-list-heading">Chats</h2>
            <!-- Right-clicking a chat opens the chat menu (chat.js), the data-chat-* attributes say what it offers -->
            <ul class="chat-list">
                <?php foreach ($chats as $chat): ?>
                    <?php chatListItem($chat); ?>
                <?php endforeach; ?>
            </ul>
            <p class="chat-list-empty"<?= $chats !== [] ? ' hidden' : '' ?> data-empty-all>You don't have any chats yet.</p>
            <p class="chat-list-empty"<?= $chats === [] || $chatSearch !== '' || $chatListHasShown ? ' hidden' : '' ?> data-empty-hidden>All your chats are hidden. Search to find them again.</p>
            <p class="chat-list-empty"<?= $chats === [] || $chatSearch === '' || $chatListHasShown ? ' hidden' : '' ?> data-empty-search>No chats match your search.</p>

            <!-- The logged in user: picture and name go to their profile, the cog to their settings -->
            <div class="chat-me">
                <a class="chat-profile-link chat-me-profile" href="/profile" title="Your profile">
                    <?= chatUserAvatarHtml($userId, $hbHubUser['userName'], $hbHubUser['userAvatarAttachmentId']) ?>
                    <span class="chat-me-name"><?= htmlspecialchars($hbHubUser['userName']) ?></span>
                </a>
                <a class="chat-me-settings" href="/settings" aria-label="Settings" title="Settings">
                    <img src="/assets/images/icons/settings.png" alt="">
                </a>
            </div>
        </aside>

        <!-- Right: the open chat -->
        <section class="chat-window" aria-label="<?= $openChat !== null ? htmlspecialchars($openChat['chatTitle']) : 'No chat open' ?>">
            <?php if ($openChat === null): ?>
                <div class="chat-placeholder">
                    <img class="chat-placeholder-icon" src="/assets/images/icons/chat.png" alt="">
                    <p>Pick a chat on the left, or start a new one.</p>
                </div>
            <?php else: ?>
                <header class="chat-window-header">
                    <a class="chat-back" href="/chat/" aria-label="Back to chats">&larr;</a>
                    <?php if ($openChat['dmUserId'] !== null && !$openChat['dmUserBanned']): ?>
                        <!-- In a DM the picture and name both go to the other person's profile -->
                        <a class="chat-profile-link chat-window-profile" href="<?= htmlspecialchars(hbHubProfileUrl((int) $openChat['dmUserId'])) ?>" title="View profile">
                            <?= chatAvatarHtml($openChat) ?>
                            <h2 class="chat-window-title"><?= htmlspecialchars($openChat['chatTitle']) ?></h2>
                        </a>
                    <?php elseif ($openChat['chatIsGroupAdmin']): ?>
                        <!-- Group admins: clicking the name turns it into a text box (chat.js), Enter saves it and Escape puts it back -->
                        <?= chatAvatarHtml($openChat) ?>
                        <h2 class="chat-window-title is-editable">
                            <button type="button" class="chat-window-rename" title="Change name" data-rename-open><?= htmlspecialchars($openChat['chatTitle']) ?></button>
                        </h2>
                        <form class="chat-window-rename-form" action="/chat/" method="post" hidden data-rename-form>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                            <input type="hidden" name="open" value="<?= $openChat['chatId'] ?>">
                            <label class="chat-visually-hidden" for="chat-window-name">Group name</label>
                            <input type="text" id="chat-window-name" name="name" maxlength="<?= HBHUB_CHAT_NAME_MAX ?>" autocomplete="off"
                                   value="<?= htmlspecialchars($openChat['chatName'] ?? '') ?>" placeholder="Named after its members"
                                   title="Enter saves, Escape cancels. Leave it empty to name the group after its members.">
                        </form>
                    <?php else: ?>
                        <?= chatAvatarHtml($openChat) ?>
                        <h2 class="chat-window-title"><?= htmlspecialchars($openChat['chatTitle']) ?></h2>
                    <?php endif; ?>
                    <?php if ($openChatMembers !== []): ?>
                        <!-- Groups: the person with a plus opens the Add to chat popup (any member can add people, but not
                             to a class chat), the person button the Members popup (chat.js) -->
                        <?php if ($openChat['chatClassKey'] === null): ?>
                        <button type="button" class="chat-icon-btn chat-add-btn" aria-label="Add to chat" title="Add to chat"
                                aria-haspopup="dialog" data-add-open>
                            <img src="/assets/images/icons/addUser.svg" alt="">
                        </button>
                        <?php endif; ?>
                        <button type="button" class="chat-icon-btn chat-members-btn" aria-label="Members (<?= count($openChatMembers) ?>)"
                                title="Members" aria-haspopup="dialog" data-members-open>
                            <img src="/assets/images/icons/user.png" alt="">
                        </button>
                    <?php endif; ?>
                </header>

                <!-- The arrow keys move between messages (tabindex is set by chat.js), and new ones are added as they come
                     in (chat.js polls for them). data-chat-messages is the open chat's id,
                     data-group-admin lets the message menu offer deleting other members' messages.
                     data-muted is there while the user is muted in the chat, so the message menu doesn't offer reactions.
                     data-background is the chat's background gradient, which chat.js sets (the content security policy
                     doesn't allow style attributes).
                     Only the newest messages are on the page at first: while there are older ones, the line at the top
                     (data-messages-older) is there, and scrolling up to it makes chat.js load them above it. -->
                <ol class="chat-messages" data-chat-messages="<?= $openChat['chatId'] ?>"<?= $openChat['chatIsGroupAdmin'] ? ' data-group-admin' : '' ?><?=
                    chatIsMuted($openMembership) ? ' data-muted' : '' ?><?=
                    $openChat['chatBackground'] !== null ? ' data-background="' . htmlspecialchars($openChat['chatBackground']) . '"' : '' ?>>
                    <?php if ($messagesHaveOlder): ?>
                        <li class="chat-messages-older" data-messages-older>Loading older messages...</li>
                    <?php endif; ?>
                    <?php if ($messages === []): ?>
                        <li class="chat-messages-empty" data-messages-empty>No messages yet - say hi!</li>
                    <?php endif; ?>
                    <?php foreach ($messages as $message): ?>
                        <?php if ((int) $message['messageId'] === $firstUnreadMessageId): ?>
                            <li class="chat-messages-unread" data-unread-divider><span>New messages</span></li>
                        <?php endif; ?>
                        <?php chatMessageItem($openChat, $userId, $message); ?>
                    <?php endforeach; ?>
                </ol>

                <!-- "Alice is typing..." over the bottom of the messages, just above the message box (chat.js fills it from polling) -->
                <div class="chat-typing" aria-live="polite">
                    <p class="chat-typing-text" hidden data-chat-typing>
                        <span class="chat-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                        <span data-chat-typing-names></span>
                    </p>
                </div>

                <!-- Dropping files anywhere on the open chat attaches them (chat.js shows this over it while they're dragged in) -->
                <?php if ($chatAttachBlocked === null): ?>
                    <div class="chat-drop" hidden data-attach-drop>
                        <span class="chat-drop-text"><strong>Drop to attach</strong>Up to <?= HBHUB_CHAT_ATTACHMENT_FILES_MAX ?> files in one message</span>
                    </div>
                <?php endif; ?>

                <!-- data-attach-max-bytes (all files in a message together), data-attach-max-files and data-ticket-bytes are
                     for chat.js to say how big the picked files are in tickets, and what fits in one message -->
                <form class="chat-composer" action="/chat/" method="post"
                      data-attach-max-bytes="<?= HBHUB_CHAT_ATTACHMENT_MAX_BYTES ?>" data-attach-max-files="<?= HBHUB_CHAT_ATTACHMENT_FILES_MAX ?>"
                      data-ticket-bytes="<?= HBHUB_CHAT_ATTACHMENT_TICKET_BYTES ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                    <!-- Set by Reply in the message menu (chat.js). autocomplete="off" so the browser doesn't bring it back after a refresh. -->
                    <input type="hidden" name="reply" value="" autocomplete="off" data-reply-input>
                    <div class="chat-composer-reply" hidden data-reply-bar>
                        <span class="chat-composer-reply-text">
                            <span>Replying to <strong data-reply-sender></strong></span>
                            <span class="chat-composer-reply-preview" data-reply-preview></span>
                        </span>
                        <button type="button" class="chat-new-close" aria-label="Cancel reply" data-reply-cancel>&times;</button>
                    </div>
                    <!-- The attachments picked with Files (or pasted or dropped), waiting to be sent (chat.js): a row of tiles,
                         each with a preview, its name, how big it is in tickets and a × to take it out, and a + tile to add
                         more. Under them how much they come to, the box to keep pictures as they are instead of turning them
                         into JPEG XL (not ticked to begin with, only there when a picture would be), and Remove all.
                         The bar along the top fills up while they're on their way. -->
                    <div class="chat-tray" hidden data-attach-tray>
                        <div class="chat-tray-progress" hidden data-attach-progress><span class="chat-tray-progress-fill" data-attach-progress-fill></span></div>
                        <ul class="chat-tray-list" aria-label="Attachments to send" data-attach-list></ul>
                        <div class="chat-tray-footer">
                            <span class="chat-tray-status" role="status" data-attach-status></span>
                            <label class="chat-tray-keep" hidden data-attach-keep-label>
                                <input type="checkbox" data-attach-keep> Keep original pictures
                                <span class="chat-tray-keep-hint">(don't convert to JPEG XL)</span>
                            </label>
                            <button type="button" class="chat-tray-clear" data-attach-clear>Remove all</button>
                        </div>
                    </div>
                    <!-- No name, so it's never sent with the form itself: chat.js sends the files on their own -->
                    <input type="file" class="chat-visually-hidden" tabindex="-1" aria-hidden="true" multiple data-attach-input>
                    <!-- Attach: a small popup above the button (chat.js). Your attachments (on top, with a line under it) opens
                         the tickets popup, Files picks some to send. Files is greyed out while new attachments can't be sent.
                         Voice message is coming soon. -->
                    <div class="chat-attach">
                        <button type="button" class="chat-icon-btn" aria-label="Attach" title="Attach"
                                aria-haspopup="menu" aria-expanded="false" aria-controls="chat-attach-menu" data-attach-open>
                            <img src="/assets/images/icons/addFile.svg" alt="">
                        </button>
                        <div class="chat-menu chat-attach-menu" id="chat-attach-menu" role="menu" aria-label="Attach" hidden data-attach-menu>
                            <button type="button" role="menuitem" data-attach-tickets>Your attachments</button>
                            <hr class="chat-menu-divider">
                            <?php if ($chatAttachBlocked === 'storageFull'): ?>
                                <button type="button" role="menuitem" aria-disabled="true" title="HB Hub's storage is full. Attachments can be sent again once some expire or are deleted.">
                                    Files <span class="chat-menu-soon">Full</span>
                                </button>
                            <?php elseif ($chatAttachBlocked !== null): ?>
                                <button type="button" role="menuitem" aria-disabled="true" title="Attachments are turned off for now">
                                    Files <span class="chat-menu-soon">Off</span>
                                </button>
                            <?php else: ?>
                                <button type="button" role="menuitem" data-attach-file>Files</button>
                            <?php endif; ?>
                            <button type="button" role="menuitem" aria-disabled="true" title="Coming soon">
                                Voice message <span class="chat-menu-soon">Soon</span>
                            </button>
                        </div>
                    </div>
                    <label class="chat-visually-hidden" for="chat-message">Message</label>
                    <textarea id="chat-message" name="message" rows="1" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required data-format-target
                              data-mentions="<?= htmlspecialchars(json_encode($openChatMentionChoices, JSON_INVALID_UTF8_SUBSTITUTE)) ?>"
                              placeholder="Message <?= htmlspecialchars($openChat['chatTitle']) ?>" autofocus></textarea>
                    <!-- Emoji picker: a popup above the button, filled by chat.js. Hidden on phones, which have emoji on their keyboard. -->
                    <div class="chat-emoji">
                        <button type="button" class="chat-icon-btn" aria-label="Emoji" title="Emoji"
                                aria-haspopup="dialog" aria-expanded="false" aria-controls="chat-emoji-picker" data-emoji-open>
                            <img src="/assets/images/icons/smiley.svg" alt="">
                        </button>
                        <div class="chat-emoji-picker" id="chat-emoji-picker" role="dialog" aria-label="Emoji" hidden data-emoji-picker>
                            <div class="chat-emoji-tabs" role="toolbar" aria-label="Emoji categories" data-emoji-tabs></div>
                            <div class="chat-emoji-list" data-emoji-list></div>
                        </div>
                    </div>
                    <?php if ($chatGifsEnabled): ?>
                        <!-- Set by the GIF picker (chat.js), which then sends the form -->
                        <input type="hidden" name="gif" value="" autocomplete="off" data-gif-input>
                        <button type="button" class="chat-icon-btn" aria-label="Send a GIF" title="GIF" aria-haspopup="dialog" data-gif-open>
                            <img src="/assets/images/icons/gif.png" alt="">
                        </button>
                    <?php endif; ?>
                    <button type="submit" class="chat-send-btn">Send</button>
                </form>
            <?php endif; ?>
        </section>

        <!-- New chat popup (chat.js): one user picked makes a DM, more make a group DM -->
        <dialog class="chat-new" aria-labelledby="chat-new-title" data-new-chat data-members-max="<?= HBHUB_CHAT_MEMBERS_MAX ?>">
            <form class="chat-new-form" action="/chat/" method="post">
                <input type="hidden" name="action" value="create">

                <header class="chat-new-header">
                    <h2 id="chat-new-title">New chat</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-new-chat-close>&times;</button>
                </header>

                <label class="chat-visually-hidden" for="chat-new-search">Search users</label>
                <input type="search" id="chat-new-search" placeholder="Search users" autocomplete="off">

                <ul class="chat-new-picked" aria-label="Picked users" data-picked></ul>

                <ul class="chat-new-users" aria-label="Users" data-users></ul>
                <p class="chat-new-status" role="status" data-users-status></p>

                <div class="chat-new-group" hidden data-group-fields>
                    <label for="chat-new-name">Group name <span class="chat-new-optional">(optional)</span></label>
                    <input type="text" id="chat-new-name" name="name" maxlength="<?= HBHUB_CHAT_NAME_MAX ?>" autocomplete="off">
                </div>

                <footer class="chat-new-footer">
                    <p class="chat-new-count" data-picked-count></p>
                    <button type="button" class="chat-btn" data-new-chat-close>Cancel</button>
                    <button type="submit" class="chat-new-btn" disabled data-new-chat-submit>Start DM</button>
                </footer>
            </form>
        </dialog>

        <!-- Chat menu (chat.js): opened by right-clicking a chat in the list. Each form below sends "open",
             so the server can go back to the chat that's open now. -->
        <div class="chat-menu" role="menu" aria-label="Chat options" hidden data-chat-menu>
            <?php if ($chatIsSiteAdmin): ?>
                <!-- Site admins: on a DM the other person's admin panel, on a group the group's own (chat.js sets the address) -->
                <a role="menuitem" href="/admin/user" data-menu-site-admin>Admin panel</a>
                <hr class="chat-menu-divider" data-menu-site-admin>
            <?php endif; ?>
            <!-- On a DM: the other person's profile (chat.js sets the address) -->
            <a role="menuitem" href="/profile" data-menu-profile data-menu-dm>Show profile</a>
            <button type="button" role="menuitem" data-menu-action="nickname" data-menu-dm>Give nickname</button>
            <!-- Mute chat opens its own small menu with how long for, a muted chat gets Unmute chat instead -->
            <button type="button" role="menuitem" aria-haspopup="menu" aria-expanded="false" aria-controls="chat-mute-menu" data-menu-mute>
                Mute chat <span class="chat-menu-arrow" aria-hidden="true">&rsaquo;</span>
            </button>
            <div class="chat-menu chat-submenu" id="chat-mute-menu" role="menu" aria-label="Mute chat" hidden data-mute-menu>
                <?php foreach (HBHUB_CHAT_MUTE_OPTIONS as $minutes => $label): ?>
                    <button type="button" role="menuitem" data-mute-minutes="<?= $minutes ?>"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
            <button type="button" role="menuitem" data-menu-action="unmute">Unmute chat</button>
            <button type="button" role="menuitem" data-menu-action="hide">Hide chat</button>
            <button type="button" role="menuitem" data-menu-action="rename" data-menu-admin>Change name</button>
            <button type="button" role="menuitem" data-menu-action="icon" data-menu-admin>Change group icon</button>
            <!-- Group admins, or either person in a DM -->
            <button type="button" role="menuitem" data-menu-action="background">Change background</button>
            <hr class="chat-menu-divider">
            <button type="button" role="menuitem" class="is-danger" data-menu-action="leave">Leave chat</button>
            <button type="button" role="menuitem" class="is-danger" data-menu-action="delete" data-menu-admin>Delete group</button>
        </div>

        <?php if ($openChat !== null): ?>
            <!-- Message menu (chat.js): opened by right-clicking a message in the open chat.
                 Along the top, the emoji the user reacted or typed with most recently to react with (chat.js fills them in),
                 and + for all of them. Edit is only for your own messages, Delete for your own (and everyone's for group
                 admins), and a deleted message can only be marked unread. -->
            <div class="chat-menu" role="menu" aria-label="Message options" hidden data-message-menu>
                <div class="chat-menu-reactions" role="group" aria-label="React" data-menu-row data-menu-reactions></div>
                <hr class="chat-menu-divider" data-menu-reactions>
                <button type="button" role="menuitem" data-menu-action="reply">Reply</button>
                <button type="button" role="menuitem" data-menu-action="edit">Edit message</button>
                <button type="button" role="menuitem" data-menu-action="unread">Mark unread</button>
                <hr class="chat-menu-divider" data-menu-delete>
                <button type="button" role="menuitem" class="is-danger" data-menu-action="delete" data-menu-delete>Delete message</button>
                <?php if ($chatIsSiteAdmin): ?>
                    <!-- Site admins, on someone else's message: the sender's admin panel (chat.js sets the address) -->
                    <hr class="chat-menu-divider" data-menu-site-admin>
                    <a role="menuitem" href="/admin/user" data-menu-site-admin>Admin panel</a>
                <?php endif; ?>
            </div>

            <!-- Formatting menu: right-click the message box (or Edit's) to put formatting around the selected text (chat.js).
                 Each item shows what it does, and data-format is the formatting chatFormatApply() puts in. -->
            <div class="chat-menu chat-format-menu" role="menu" aria-label="Formatting" hidden data-format-menu>
                <button type="button" role="menuitem" data-format="bold"><strong>Bold</strong><kbd class="chat-menu-shortcut">Ctrl+B</kbd></button>
                <button type="button" role="menuitem" data-format="italic"><em>Italic</em><kbd class="chat-menu-shortcut">Ctrl+I</kbd></button>
                <button type="button" role="menuitem" data-format="underline"><u>Underline</u><kbd class="chat-menu-shortcut">Ctrl+U</kbd></button>
                <button type="button" role="menuitem" data-format="strike"><s>Strikethrough</s></button>
                <button type="button" role="menuitem" data-format="code"><code class="chat-message-code">Code</code></button>
                <button type="button" role="menuitem" data-format="codeBlock">Code block</button>
                <hr class="chat-menu-divider">
                <button type="button" role="menuitem" data-format="heading">Heading</button>
                <button type="button" role="menuitem" data-format="list">List</button>
                <button type="button" role="menuitem" data-format="numberedList">Numbered list</button>
                <button type="button" role="menuitem" data-format="link">Link</button>
                <hr class="chat-menu-divider">
                <!-- The browser's own menu is replaced, so these are here instead (Shift+right-click still opens it) -->
                <button type="button" role="menuitem" data-format="cut">Cut</button>
                <button type="button" role="menuitem" data-format="copy">Copy</button>
                <button type="button" role="menuitem" data-format="paste">Paste</button>
            </div>

            <!-- Add reaction (chat.js): + in the message menu opens it where the menu was, with every emoji like the emoji
                 picker. Picking one reacts with it. Not inside .chat-emoji, so it's there on phones too. -->
            <div class="chat-emoji-picker chat-reaction-picker" role="dialog" aria-label="Add reaction" hidden data-reaction-picker>
                <div class="chat-emoji-tabs" role="toolbar" aria-label="Emoji categories" data-emoji-tabs></div>
                <div class="chat-emoji-list" data-emoji-list></div>
            </div>

            <form action="/chat/" method="post" hidden data-message-unread>
                <input type="hidden" name="action" value="unread">
                <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                <input type="hidden" name="message">
            </form>

            <!-- Edit message: chat.js fills in the message as it is now -->
            <dialog class="chat-dialog chat-message-edit" aria-labelledby="chat-edit-title">
                <form class="chat-requests-body" action="/chat/" method="post">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                    <input type="hidden" name="message">

                    <header class="chat-new-header">
                        <h2 id="chat-edit-title">Edit message</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                    </header>

                    <label class="chat-visually-hidden" for="chat-edit-content">Message</label>
                    <textarea id="chat-edit-content" name="content" rows="4" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required data-format-target
                              data-mentions="<?= htmlspecialchars(json_encode($openChatMentionChoices, JSON_INVALID_UTF8_SUBSTITUTE)) ?>"></textarea>
                    <p class="chat-dialog-hint">Enter to save, Shift+Enter for a new line. Right-click the text for formatting.</p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                        <button type="submit" class="chat-new-btn" data-edit-save>Save</button>
                    </footer>
                </form>
            </dialog>

            <!-- Link popup: the formatting menu's Link opens it (on the message box or Edit's), and Done puts
                 [text](address) in where the selection was (chat.js). A selected address starts in Link, other text in Text. -->
            <dialog class="chat-dialog chat-link-edit" aria-labelledby="chat-link-title">
                <form class="chat-requests-body" action="/chat/" method="post" novalidate>
                    <header class="chat-new-header">
                        <h2 id="chat-link-title">Add link</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                    </header>

                    <div class="chat-new-group">
                        <label for="chat-link-text">Text</label>
                        <input type="text" id="chat-link-text" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" autocomplete="off"
                               placeholder="What the link says" data-link-text>
                    </div>
                    <div class="chat-new-group">
                        <label for="chat-link-url">Link</label>
                        <input type="text" id="chat-link-url" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" autocomplete="off"
                               inputmode="url" spellcheck="false" placeholder="https://" data-link-url>
                    </div>
                    <p class="chat-dialog-hint is-error" role="alert" hidden data-link-error></p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                        <button type="submit" class="chat-new-btn">Done</button>
                    </footer>
                </form>
            </dialog>

            <!-- Delete message asks first, showing the message it's about -->
            <dialog class="chat-dialog chat-message-delete" aria-labelledby="chat-delete-title">
                <form class="chat-requests-body" action="/chat/" method="post">
                    <input type="hidden" name="action" value="deleteMessage">
                    <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                    <input type="hidden" name="message">

                    <header class="chat-new-header">
                        <h2 id="chat-delete-title">Delete message?</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                    </header>

                    <p class="chat-dialog-text" data-delete-text>Everyone in the chat will see it as deleted. This can't be undone.</p>
                    <p class="chat-dialog-quote" data-delete-preview></p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                        <button type="submit" class="chat-new-btn is-danger">Delete message</button>
                    </footer>
                </form>
            </dialog>

            <?php if ($openChatMembers !== []): ?>
                <!-- Members popup (chat.js): everyone in the open group, each going to their profile.
                     Members who haven't accepted the group yet show as invited.
                     Group admins also get Make admin (for members who accepted) and Kick next to normal members.
                     The group owner also gets Remove admin and Kick next to admins.
                     Site admins can right-click anyone else for their admin panel (data-member-user-id). -->
                <dialog class="chat-dialog chat-members" aria-labelledby="chat-members-title">
                    <div class="chat-requests-body">
                        <header class="chat-new-header">
                            <h2 id="chat-members-title">Members <span class="chat-members-count"><?= count($openChatMembers) ?></span></h2>
                            <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                        </header>

                        <ul class="chat-members-list">
                            <?php foreach ($openChatMembers as $member): ?>
                                <?php
                                $memberUserId = (int) $member['userId'];
                                $memberInner = chatUserAvatarHtml($memberUserId, $member['memberName'], $member['memberNoProfile'] ? null : $member['userAvatarAttachmentId'])
                                    . '<span class="chat-member-name">' . htmlspecialchars($member['memberName'])
                                    . ($memberUserId === $userId ? ' <span class="chat-member-you">(you)</span>' : '') . '</span>'
                                    // One admin badge: Site Admin wins over Group Owner, which wins over Group Admin
                                    . ($member['memberIsSiteAdmin'] ? '<span class="chat-member-badge is-site-admin">Site Admin</span>'
                                        : match ((int) $member['memberRole']) {
                                            HBHUB_CHAT_ROLE_OWNER => '<span class="chat-member-badge is-owner">Group Owner</span>',
                                            HBHUB_CHAT_ROLE_ADMIN => '<span class="chat-member-badge">Group Admin</span>',
                                            default => '',
                                        })
                                    . (!$member['userAcknowledgedJoin'] ? '<span class="chat-member-badge is-invited">Invited</span>' : '');
                                // Admins can act on normal members, the owner on admins too
                                $memberRole = (int) $member['memberRole'];
                                $memberCanKick = $openChat['chatIsGroupAdmin'] && $memberUserId !== $userId
                                    && ($memberRole === HBHUB_CHAT_ROLE_MEMBER || $openChat['chatIsGroupOwner']);
                                ?>
                                <li<?= $chatIsSiteAdmin && $memberUserId !== $userId ? ' data-member-user-id="' . $memberUserId . '"' : '' ?>>
                                    <?php if ($member['memberNoProfile']): ?>
                                        <span class="chat-member"><?= $memberInner ?></span>
                                    <?php else: ?>
                                        <a class="chat-member" href="<?= htmlspecialchars(hbHubProfileUrl($memberUserId)) ?>" title="View profile"><?= $memberInner ?></a>
                                    <?php endif; ?>
                                    <?php if ($memberCanKick): ?>
                                        <span class="chat-member-actions">
                                            <?php if ($memberRole === HBHUB_CHAT_ROLE_MEMBER && $member['userAcknowledgedJoin']): ?>
                                                <button type="button" class="chat-btn" data-member-action="makeAdmin"
                                                        data-member-id="<?= $memberUserId ?>" data-member-name="<?= htmlspecialchars($member['memberName']) ?>">Make admin</button>
                                            <?php elseif ($memberRole === HBHUB_CHAT_ROLE_ADMIN): ?>
                                                <button type="button" class="chat-btn" data-member-action="removeAdmin"
                                                        data-member-id="<?= $memberUserId ?>" data-member-name="<?= htmlspecialchars($member['memberName']) ?>">Remove admin</button>
                                            <?php endif; ?>
                                            <button type="button" class="chat-btn is-danger" data-member-action="kick"
                                                    data-member-id="<?= $memberUserId ?>" data-member-name="<?= htmlspecialchars($member['memberName']) ?>"
                                                    <?= !$member['userAcknowledgedJoin'] ? 'data-member-invited' : '' ?>>Kick</button>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($chatIsSiteAdmin): ?>
                            <!-- Member menu (chat.js), for site admins: opened by right-clicking someone in the list.
                                 Inside the popup, as everything outside it can't be clicked while it's open. -->
                            <div class="chat-menu" role="menu" aria-label="Member options" hidden data-member-menu>
                                <a role="menuitem" href="/admin/user" data-menu-site-admin>Admin panel</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </dialog>

                <!-- Add to chat popup (chat.js), for every member: search users and pick who to add, like New chat.
                     Who's in the group already (invited too) shows, but can't be picked. Not in a class chat. -->
                <?php if ($openChat['chatClassKey'] === null): ?>
                <dialog class="chat-new chat-add" aria-labelledby="chat-add-title" data-members-max="<?= HBHUB_CHAT_MEMBERS_MAX ?>"
                        data-member-ids="<?= implode(',', array_map('intval', array_column($openChatMembers, 'userId'))) ?>">
                    <form class="chat-new-form" action="/chat/" method="post">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">

                        <header class="chat-new-header">
                            <h2 id="chat-add-title">Add to <?= htmlspecialchars($openChat['chatTitle']) ?></h2>
                            <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                        </header>

                        <label class="chat-visually-hidden" for="chat-add-search">Search users</label>
                        <input type="search" id="chat-add-search" placeholder="Search users" autocomplete="off">

                        <ul class="chat-new-picked" aria-label="Picked users" data-picked></ul>

                        <ul class="chat-new-users" aria-label="Users" data-users></ul>
                        <p class="chat-new-status" role="status" data-users-status></p>

                        <footer class="chat-new-footer">
                            <p class="chat-new-count" data-picked-count></p>
                            <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                            <button type="submit" class="chat-new-btn" disabled data-add-submit>Add</button>
                        </footer>
                    </form>
                </dialog>
                <?php endif; ?>

                <?php if ($openChat['chatIsGroupAdmin']): ?>
                    <!-- Make admin, Remove admin and Kick ask first (chat.js fills in which, and who it's about) -->
                    <dialog class="chat-dialog chat-member-confirm" aria-labelledby="chat-member-confirm-title">
                        <form class="chat-requests-body" action="/chat/" method="post">
                            <input type="hidden" name="action" data-member-confirm-action>
                            <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                            <input type="hidden" name="member">

                            <header class="chat-new-header">
                                <h2 id="chat-member-confirm-title" data-member-confirm-title></h2>
                                <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                            </header>

                            <p class="chat-dialog-text" data-member-confirm-text></p>

                            <footer class="chat-new-footer chat-dialog-footer">
                                <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                                <button type="submit" class="chat-new-btn" data-member-confirm-submit></button>
                            </footer>
                        </form>
                    </dialog>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Your attachments (chat.js): the attachments the user sent that still take tickets, from /chat/attachments,
                 each with a Delete button. Opened from the Attach menu, and by itself when the files waiting to be sent need
                 more tickets than are free - then it says how many, and Send now sends them once enough are free. -->
            <dialog class="chat-dialog chat-tickets" aria-labelledby="chat-tickets-title">
                <div class="chat-requests-body">
                    <header class="chat-new-header">
                        <h2 id="chat-tickets-title" data-tickets-title>Your attachments</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                    </header>

                    <p class="chat-dialog-text chat-tickets-needed" hidden data-tickets-needed></p>
                    <div class="chat-tickets-summary">
                        <p class="chat-tickets-count" data-tickets-count></p>
                        <div class="chat-tickets-meter" aria-hidden="true" data-tickets-meter></div>
                    </div>

                    <ul class="chat-tickets-list" aria-label="Your attachments" data-tickets-list></ul>
                    <p class="chat-new-status" role="status" data-tickets-status></p>

                    <!-- Folded away under Read more until it's clicked (chat.js folds it up again each time the popup opens) -->
                    <details class="chat-dialog-hint chat-tickets-rules" data-tickets-rules>
                        <summary>
                            <strong>How tickets works?</strong>
                            <span class="chat-tickets-rules-more">Read more</span>
                            <span class="chat-tickets-rules-less">Show less</span>
                        </summary>
                        <?php $chatTicketsTotal = hbHubChatAttachmentTicketsTotal($db, $userId); ?>
                        <?php $chatTicketMb = HBHUB_CHAT_ATTACHMENT_TICKET_BYTES / 1024 / 1024; ?>
                        <p>
                        You've got <?= $chatTicketsTotal ?> attachment <?= $chatTicketsTotal === 1 ? 'ticket' : 'tickets' ?> to
                        spend. Every <?= $chatTicketMb ?> MB costs one ticket, and we round up, so a file that's a hair over
                        <?= $chatTicketMb ?> MB pays for two. No haggling. Attachments hang around for
                        <?= HBHUB_CHAT_ATTACHMENT_DAYS ?> days, but anything greedy enough to eat more than
                        <?= HBHUB_CHAT_ATTACHMENT_BIG_TICKETS ?> tickets gets just <?= HBHUB_CHAT_ATTACHMENT_BIG_DAYS ?> days
                        before it's shown the door. When an attachment expires, it's gone for good and its tickets come
                        home to you. Can't wait that long? Delete the attachment or its message and you get them back on
                        the spot.
                        </p>
                    </details>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Close</button>
                        <button type="button" class="chat-new-btn" hidden disabled data-tickets-retry>Send now</button>
                    </footer>
                </div>
            </dialog>

            <?php if ($chatGifsEnabled): ?>
                <!-- GIF picker (chat.js): searches Giphy through /chat/gifs, trending GIFs before anything is typed.
                     Picking one sends it straight away. -->
                <dialog class="chat-dialog chat-gif" aria-labelledby="chat-gif-title">
                    <div class="chat-requests-body">
                        <header class="chat-new-header">
                            <h2 id="chat-gif-title">Send a GIF</h2>
                            <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                        </header>

                        <label class="chat-visually-hidden" for="chat-gif-search">Search GIFs</label>
                        <input type="search" id="chat-gif-search" placeholder="Search GIPHY" autocomplete="off">

                        <ul class="chat-gif-results" aria-label="GIFs" data-gif-results></ul>
                        <p class="chat-new-status" role="status" data-gif-status></p>

                        <!-- Giphy asks for this wherever its GIFs are shown -->
                        <p class="chat-gif-attribution">Powered by GIPHY</p>
                    </div>
                </dialog>
            <?php endif; ?>
        <?php endif; ?>

        <form action="/chat/" method="post" hidden data-chat-hide>
            <input type="hidden" name="action" value="hide">
            <input type="hidden" name="chat">
            <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">
        </form>

        <!-- Mute chat and Unmute chat: chat.js sets which, and for how long -->
        <form action="/chat/" method="post" hidden data-chat-mute>
            <input type="hidden" name="action" value="mute">
            <input type="hidden" name="chat">
            <input type="hidden" name="minutes">
            <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">
        </form>

        <!-- Leave chat and delete group ask first (chat.js fills in which) -->
        <dialog class="chat-dialog chat-confirm" aria-labelledby="chat-confirm-title">
            <form class="chat-requests-body" action="/chat/" method="post">
                <input type="hidden" name="action" data-confirm-action>
                <input type="hidden" name="chat">
                <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">

                <header class="chat-new-header">
                    <h2 id="chat-confirm-title" data-confirm-title></h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <p class="chat-dialog-text" data-confirm-text></p>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="chat-new-btn is-danger" data-confirm-submit></button>
                </footer>
            </form>
        </dialog>

        <!-- Give nickname (DMs): chat.js fills in who it's for -->
        <dialog class="chat-dialog chat-nickname" aria-labelledby="chat-nickname-title">
            <form class="chat-requests-body" action="/chat/" method="post">
                <input type="hidden" name="action" value="nickname">
                <input type="hidden" name="chat">
                <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">

                <header class="chat-new-header">
                    <h2 id="chat-nickname-title">Give nickname</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <div class="chat-new-group">
                    <label for="chat-nickname-name" data-nickname-label>Nickname</label>
                    <input type="text" id="chat-nickname-name" name="nickname" maxlength="<?= HBHUB_CHAT_NICKNAME_MAX ?>" autocomplete="off">
                </div>
                <p class="chat-dialog-hint" data-nickname-hint></p>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="chat-new-btn">Save</button>
                </footer>
            </form>
        </dialog>

        <!-- Change name (group admins) -->
        <dialog class="chat-dialog chat-rename" aria-labelledby="chat-rename-title">
            <form class="chat-requests-body" action="/chat/" method="post">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="chat">
                <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">

                <header class="chat-new-header">
                    <h2 id="chat-rename-title">Change name</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <div class="chat-new-group">
                    <label for="chat-rename-name">Group name</label>
                    <input type="text" id="chat-rename-name" name="name" maxlength="<?= HBHUB_CHAT_NAME_MAX ?>" autocomplete="off">
                </div>
                <p class="chat-dialog-hint">Leave it empty to name the group after its members.</p>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="chat-new-btn">Save</button>
                </footer>
            </form>
        </dialog>

        <!-- Change group icon (group admins) -->
        <dialog class="chat-dialog chat-icon-edit" aria-labelledby="chat-icon-title"
                data-max-bytes="<?= HBHUB_CHAT_ICON_MAX_BYTES ?>" data-types="<?= implode(',', HBHUB_CHAT_ICON_TYPES) ?>">
            <form class="chat-requests-body" action="/chat/" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="icon">
                <input type="hidden" name="chat">
                <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">

                <header class="chat-new-header">
                    <h2 id="chat-icon-title">Change group icon</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <div class="chat-icon-pick">
                    <span class="chat-icon-preview" data-icon-preview></span>
                    <input type="file" id="chat-icon-file" class="chat-visually-hidden" name="icon"
                           accept="<?= implode(',', HBHUB_CHAT_ICON_TYPES) ?>" data-icon-file>
                    <label class="chat-btn" for="chat-icon-file">Choose image</label>
                </div>
                <p class="chat-dialog-hint" role="status" data-icon-status></p>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="submit" class="chat-btn is-danger chat-icon-remove" name="remove" value="1" data-icon-remove>Remove icon</button>
                    <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="chat-new-btn" disabled data-icon-save>Save</button>
                </footer>
            </form>
        </dialog>

        <!-- Change background (group admins, or either person in a DM): a gradient between two colors, going the way the
             picked arrow points. chat.js fills in the chat's background as it is now and shows the picked one in the preview. -->
        <dialog class="chat-dialog chat-background-edit" aria-labelledby="chat-background-title">
            <form class="chat-requests-body" action="/chat/" method="post">
                <input type="hidden" name="action" value="background">
                <input type="hidden" name="chat">
                <input type="hidden" name="open" value="<?= $openChat['chatId'] ?? '' ?>">

                <header class="chat-new-header">
                    <h2 id="chat-background-title">Change background</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <div class="chat-background-preview" aria-hidden="true" data-background-preview>
                    <span class="chat-message-bubble">Hey, new background?</span>
                    <span class="chat-message-bubble is-own">Looks good!</span>
                </div>

                <div class="chat-background-colors">
                    <div class="chat-new-group">
                        <label for="chat-background-color1">From</label>
                        <input type="color" id="chat-background-color1" name="color1">
                    </div>
                    <div class="chat-new-group">
                        <label for="chat-background-color2">To</label>
                        <input type="color" id="chat-background-color2" name="color2">
                    </div>
                </div>

                <fieldset class="chat-background-directions">
                    <legend>Direction</legend>
                    <?php foreach (HBHUB_CHAT_BACKGROUND_DIRECTIONS as $angle => [$arrow, $label]): ?>
                        <label title="<?= $label ?>">
                            <input type="radio" class="chat-visually-hidden" name="angle" value="<?= $angle ?>">
                            <span class="chat-background-arrow" aria-hidden="true"><?= $arrow ?></span>
                            <span class="chat-visually-hidden"><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="submit" class="chat-btn is-danger chat-icon-remove" name="remove" value="1" data-background-remove>Reset</button>
                    <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="chat-new-btn">Save</button>
                </footer>
            </form>
        </dialog>

        <!-- Link warning popup (chat.js): shown before a link in a message is opened -->
        <dialog class="chat-link-warning" aria-labelledby="chat-link-warning-title">
            <div class="chat-requests-body">
                <header class="chat-new-header">
                    <h2 id="chat-link-warning-title">Leaving HB Hub</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-link-warning-close>&times;</button>
                </header>

                <p class="chat-link-warning-text">
                    Hey, do you really want to go there? Links from people you don't know can be dangerous -
                    only open it if you trust where it goes.
                </p>
                <p class="chat-link-warning-url" data-link-warning-url></p>

                <footer class="chat-new-footer">
                    <button type="button" class="chat-btn" data-link-warning-close>Stay here</button>
                    <a class="chat-new-btn" href="#" target="_blank" rel="noopener noreferrer nofollow" data-link-warning-open>Open link</a>
                </footer>
            </div>
        </dialog>

        <!-- Picture viewer (chat.js): clicking a picture in the open chat shows it big here. Along the top its name, zoom,
             Info (its type, size and who sent it, in the panel below), Download and ×. Clicking anywhere that isn't
             the picture, a button or the Info panel closes it too. chat.js puts the picture in data-viewer-stage.
             A message with several pictures gets the arrows on each side (and the arrow keys) to go through them,
             and which one of how many it is next to the name. -->
        <dialog class="chat-viewer" aria-labelledby="chat-viewer-name">
            <header class="chat-viewer-bar">
                <h2 class="chat-viewer-name" id="chat-viewer-name"><span data-viewer-name></span>
                    <span class="chat-viewer-count" hidden data-viewer-count></span></h2>
                <div class="chat-viewer-zoom" role="group" aria-label="Zoom">
                    <button type="button" class="chat-viewer-btn is-icon" aria-label="Zoom out" title="Zoom out (-)" data-viewer-zoom-out>&minus;</button>
                    <span class="chat-viewer-zoom-level" title="How big it's shown, next to its real size" data-viewer-zoom-level></span>
                    <button type="button" class="chat-viewer-btn is-icon" aria-label="Zoom in" title="Zoom in (+)" data-viewer-zoom-in>+</button>
                </div>
                <button type="button" class="chat-viewer-btn" aria-expanded="false" aria-controls="chat-viewer-info" data-viewer-info-open>Info</button>
                <a class="chat-viewer-btn" href="#" download data-viewer-download>Download</a>
                <button type="button" class="chat-viewer-btn is-icon" aria-label="Close" title="Close (Esc)" autofocus data-viewer-close>&times;</button>
            </header>
            <div class="chat-viewer-stage" data-viewer-stage></div>
            <button type="button" class="chat-viewer-nav is-prev" aria-label="Previous picture" title="Previous (←)" hidden data-viewer-prev>&lsaquo;</button>
            <button type="button" class="chat-viewer-nav is-next" aria-label="Next picture" title="Next (→)" hidden data-viewer-next>&rsaquo;</button>
            <dl class="chat-viewer-info" id="chat-viewer-info" hidden data-viewer-info></dl>
        </dialog>

        <!-- Blocked link popup (chat.js): shown instead of sending a message with a blacklisted link in it
             (assets/php/urlBlacklist.php), whose list chat.js checks against (data-url-blacklist).
             Opens by itself when the server stopped one (?linkBlocked=1, data-link-blocked-open). -->
        <dialog class="chat-dialog chat-link-blocked" aria-labelledby="chat-link-blocked-title"
                data-url-blacklist="<?= htmlspecialchars(json_encode(hbHubUrlBlacklist())) ?>"
                <?= ($_GET['linkBlocked'] ?? null) === '1' ? 'data-link-blocked-open' : '' ?>>
            <div class="chat-requests-body">
                <header class="chat-new-header">
                    <h2 id="chat-link-blocked-title">Link blocked</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                </header>

                <p class="chat-dialog-text"><?= htmlspecialchars(HBHUB_URL_BLACKLIST_MESSAGE) ?></p>
                <p class="chat-dialog-text">It hasn't gone through. Take the link out and try again.</p>

                <footer class="chat-new-footer chat-dialog-footer">
                    <button type="button" class="chat-new-btn" data-dialog-close>Okay</button>
                </footer>
            </div>
        </dialog>

        <!-- Slow down popup (chat.js), when a message wasn't sent because the user sent too many too fast
             (chatSendingTooFast(), ?tooFast=1). What was typed is still in the box. -->
        <?php if (($_GET['tooFast'] ?? null) === '1'): ?>
            <dialog class="chat-dialog chat-too-fast" aria-labelledby="chat-too-fast-title">
                <div class="chat-requests-body">
                    <header class="chat-new-header">
                        <h2 id="chat-too-fast-title">Woah! Slow down!</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-dialog-close>&times;</button>
                    </header>

                    <p class="chat-dialog-text">
                        Boddy, your sending messeges really quickly. And i think i didn't get the last one. 
                        Take a moment and try again.
                    </p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-new-btn" data-dialog-close>Okay</button>
                    </footer>
                </div>
            </dialog>
        <?php endif; ?>

        <!-- Message requests popup (chat.js): chats the user was added to, to accept, decline (leave) or block.
             Always on the page, so chat.js polling can fill it when a request comes in. -->
        <dialog class="chat-requests" aria-labelledby="chat-requests-title">
            <div class="chat-requests-body">
                <header class="chat-new-header">
                    <h2 id="chat-requests-title">Message requests</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-requests-close>&times;</button>
                </header>
                <p class="chat-dialog-hint">Block declines a request and stops whoever sent it sending you new ones, until you start a chat with them yourself.</p>

                <ul class="chat-requests-list" data-requests-list>
                    <?php foreach ($chatRequests as $request): ?>
                        <?php chatRequestItem($request); ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
