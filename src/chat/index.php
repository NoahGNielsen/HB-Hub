<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/giphy.php';

const HBHUB_CHAT_TYPE_DM = 1; // chatType in HBHub-Chats
const HBHUB_CHAT_TYPE_GROUP = 2;
const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_CHAT_STATUS_DELETED = 2;
const HBHUB_CHAT_ROLE_MEMBER = 1; // memberRole in HBHub-ChatMembers
const HBHUB_CHAT_ROLE_ADMIN = 2;
const HBHUB_CHAT_MEMBERS_MAX = 200; // per group DM, the creator included
const HBHUB_CHAT_NAME_MAX = 64; // chatName column
const HBHUB_CHAT_NICKNAME_MAX = 64; // same as userName
const HBHUB_CHAT_MESSAGE_MAX = 2500; // messageContent column
const HBHUB_CHAT_MESSAGES_SHOWN = 100;
const HBHUB_CHAT_ICON_MAX_BYTES = 2 * 1024 * 1024;
const HBHUB_CHAT_ICON_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']; // no SVG, it can carry scripts

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
$chatGifsEnabled = hbHubGiphyApiKey($siteConfig) !== null; // the GIF button and picker

/**
 * The chat with the id, or null when it doesn't exist, isn't active or the user isn't a member of it.
 * A chat the user hasn't accepted yet (a message request) only counts when $isRequest is true,
 * and then it's the only kind that counts.
 *
 * @return ?array{chatId: int, chatType: int, memberRole: int, memberMutedUntil: ?string, lastReadMessageId: ?int}
 */
function chatMembership(PDO $db, int $userId, mixed $chatId, bool $isRequest = false): ?array
{
    if (!is_scalar($chatId) || !ctype_digit((string) $chatId)) {
        return null;
    }

    $statement = $db->prepare('SELECT c.chatId, c.chatType, cm.memberRole, cm.memberMutedUntil, cm.lastReadMessageId
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND cm.userAcknowledgedJoin = ?');
    $statement->execute([(int) $chatId, $userId, HBHUB_CHAT_STATUS_ACTIVE, $isRequest ? 0 : 1]);
    return $statement->fetch() ?: null;
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
 * The message with the id, or null when the chat has no such message.
 *
 * @return ?array{messageId: int, userId: int, messageContent: ?string, messageDeletedTimestamp: ?string}
 */
function chatMessage(PDO $db, int $chatId, mixed $messageId): ?array
{
    if (!is_scalar($messageId) || !ctype_digit((string) $messageId)) {
        return null;
    }

    $statement = $db->prepare('SELECT messageId, userId, messageContent, messageDeletedTimestamp
        FROM `HBHub-Messages` WHERE messageId = ? AND chatId = ?');
    $statement->execute([(int) $messageId, $chatId]);
    return $statement->fetch() ?: null;
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
 * Takes the user out of the chat. A group is never left without an admin: when the last one leaves,
 * the member who has been in it the longest takes over. A chat with nobody left in it is deleted.
 *
 * @param array{chatId: int, chatType: int} $chat
 */
function chatLeave(PDO $db, int $userId, array $chat): void
{
    $db->beginTransaction();
    $db->prepare('DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?')
        ->execute([$chat['chatId'], $userId]);

    $statement = $db->prepare('SELECT userId, memberRole FROM `HBHub-ChatMembers` WHERE chatId = ?
        ORDER BY userAcknowledgedJoin DESC, memberJoinedTimestamp, userId FOR UPDATE');
    $statement->execute([$chat['chatId']]);
    $members = $statement->fetchAll();

    if ($members === []) {
        $db->prepare('UPDATE `HBHub-Chats` SET chatStatus = ? WHERE chatId = ?')
            ->execute([HBHUB_CHAT_STATUS_DELETED, $chat['chatId']]);
    } elseif ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP
        && !in_array(HBHUB_CHAT_ROLE_ADMIN, array_map('intval', array_column($members, 'memberRole')), true)) {
        $db->prepare('UPDATE `HBHub-ChatMembers` SET memberRole = ? WHERE chatId = ? AND userId = ?')
            ->execute([HBHUB_CHAT_ROLE_ADMIN, $chat['chatId'], $members[0]['userId']]);
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
 * Gives the group a new icon, or none when $icon is null. The old icon is deleted.
 *
 * @param ?array{0: string, 1: string} $icon [type, data] from chatIconUpload()
 */
function chatSetIcon(PDO $db, int $chatId, ?array $icon): void
{
    $db->beginTransaction();
    $statement = $db->prepare('SELECT chatIconAttachmentId FROM `HBHub-Chats` WHERE chatId = ? FOR UPDATE');
    $statement->execute([$chatId]);
    $oldIconId = $statement->fetchColumn() ?: null;

    $newIconId = null;
    if ($icon !== null) {
        $statement = $db->prepare('INSERT INTO `HBHub-Attachments` (attachmentType, attachmentFile) VALUES (?, ?)');
        $statement->bindValue(1, $icon[0]);
        $statement->bindValue(2, $icon[1], PDO::PARAM_LOB);
        $statement->execute();
        $newIconId = (int) $db->lastInsertId();
    }

    $db->prepare('UPDATE `HBHub-Chats` SET chatIconAttachmentId = ? WHERE chatId = ?')->execute([$newIconId, $chatId]);
    if ($oldIconId !== null) {
        $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$oldIconId]);
    }
    $db->commit();
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
 * The chat's settings from the chatSettings column, with the defaults filled in for anything not saved yet.
 */
function chatSettings(?string $json): stdClass
{
    // Same shape as database/defaultChatSettings.json. The per-user settings are keyed by userId, so they start out empty.
    $settings = (object) [
        'chatHidden' => new stdClass(),
        'chatNicknames' => new stdClass(),
        'chatMuted' => false,
        'chatMutedUntil' => null,
        'chatBackgroundGradientColor' => (object) ['color1' => 'default', 'color2' => 'default', 'gradientAngle' => 90],
    ];

    // Decoded as objects, not arrays, so the userId keys are saved as an object again
    $saved = $json !== null ? json_decode($json) : null;
    foreach ($saved instanceof stdClass ? get_object_vars($saved) : [] as $key => $value) {
        $settings->$key = $value;
    }
    return $settings;
}

/**
 * Changes the chat's settings: $change gets them (defaults filled in) to change, then they're saved.
 * Locked while that happens, so two changes at once can't undo each other.
 *
 * @param callable(stdClass): void $change
 */
function chatChangeSettings(PDO $db, int $chatId, callable $change): void
{
    $db->beginTransaction();
    $statement = $db->prepare('SELECT chatSettings FROM `HBHub-Chats` WHERE chatId = ? FOR UPDATE');
    $statement->execute([$chatId]);
    $settings = chatSettings($statement->fetchColumn() ?: null);

    $change($settings);

    $db->prepare('UPDATE `HBHub-Chats` SET chatSettings = ? WHERE chatId = ?')
        ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $chatId]);
    $db->commit();
}

/**
 * Hides the chat from the user's chat list until a new message arrives (chatHidden in the chat's settings),
 * or shows it again.
 */
function chatSetHidden(PDO $db, int $userId, int $chatId, bool $isHidden): void
{
    chatChangeSettings($db, $chatId, function (stdClass $settings) use ($userId, $isHidden): void {
        $settings->chatHidden->{$userId} = $isHidden;
    });
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
 * Returns the chat id, or null when the picked users aren't valid (none, too many, unknown or banned).
 */
function chatCreate(PDO $db, int $userId, mixed $pickedIds, mixed $name): ?int
{
    if (!is_array($pickedIds)) {
        return null;
    }

    $memberIds = [];
    foreach ($pickedIds as $pickedId) {
        if (is_string($pickedId) && ctype_digit($pickedId) && (int) $pickedId !== $userId) {
            $memberIds[(int) $pickedId] = true;
        }
    }
    $memberIds = array_keys($memberIds);

    if ($memberIds === [] || count($memberIds) + 1 > HBHUB_CHAT_MEMBERS_MAX) {
        return null;
    }

    // Every picked user has to exist and not be banned
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users`
        WHERE userStatus <> ? AND userId IN (' . implode(', ', array_fill(0, count($memberIds), '?')) . ')');
    $statement->execute([HBHUB_SESSION_STATUS_BANNED, ...$memberIds]);
    if ((int) $statement->fetchColumn() !== count($memberIds)) {
        return null;
    }

    $isGroup = count($memberIds) > 1;

    if (!$isGroup) {
        $statement = $db->prepare('SELECT c.chatId FROM `HBHub-Chats` c
            JOIN `HBHub-ChatMembers` me ON me.chatId = c.chatId AND me.userId = ?
            JOIN `HBHub-ChatMembers` them ON them.chatId = c.chatId AND them.userId = ?
            WHERE c.chatType = ? AND c.chatStatus = ? LIMIT 1');
        $statement->execute([$userId, $memberIds[0], HBHUB_CHAT_TYPE_DM, HBHUB_CHAT_STATUS_ACTIVE]);
        $existingChatId = $statement->fetchColumn();
        if ($existingChatId !== false) {
            // Starting a DM with someone who already sent you a request accepts it, and a hidden one comes back
            $db->prepare('UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1 WHERE chatId = ? AND userId = ?')
                ->execute([$existingChatId, $userId]);
            chatSetHidden($db, $userId, (int) $existingChatId, false);
            return (int) $existingChatId;
        }
    }

    // Only groups get a name - left empty, they're named after the members
    $chatName = $isGroup && is_string($name) ? trim($name) : '';
    $chatName = $chatName === '' ? null : mb_substr($chatName, 0, HBHUB_CHAT_NAME_MAX);

    $db->beginTransaction();
    $db->prepare('INSERT INTO `HBHub-Chats` (chatName, chatType, chatCreatedBy) VALUES (?, ?, ?)')
        ->execute([$chatName, $isGroup ? HBHUB_CHAT_TYPE_GROUP : HBHUB_CHAT_TYPE_DM, $userId]);
    $chatId = (int) $db->lastInsertId();

    // The creator is the group's admin. In a DM both are normal members.
    // Everyone but the creator gets the chat as a message request they have to accept first.
    $memberRows = [$chatId, $userId, $isGroup ? HBHUB_CHAT_ROLE_ADMIN : HBHUB_CHAT_ROLE_MEMBER, 1];
    foreach ($memberIds as $memberId) {
        array_push($memberRows, $chatId, $memberId, HBHUB_CHAT_ROLE_MEMBER, 0);
    }
    $db->prepare('INSERT INTO `HBHub-ChatMembers` (chatId, userId, memberRole, userAcknowledgedJoin)
        VALUES ' . implode(', ', array_fill(0, count($memberIds) + 1, '(?, ?, ?, ?)')))
        ->execute($memberRows);
    $db->commit();

    return $chatId;
}

// Starting a new chat, then straight into it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'create') {
    chatRedirect(chatCreate($db, $userId, $_POST['users'] ?? null, $_POST['name'] ?? null));
}

// The right-click menu in the chat list: hide or leave a chat, give the other person in a DM a nickname,
// and for group admins rename it, change its icon or delete it.
// Then back to the chat that was open (sent along as "open"), unless that's the one that was just hidden, left or deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['hide', 'leave', 'nickname', 'rename', 'icon', 'delete'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $openChatId = is_string($_POST['open'] ?? null) && ctype_digit($_POST['open']) ? (int) $_POST['open'] : null;
    $isGroupAdmin = $chat !== null && (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP
        && (int) $chat['memberRole'] === HBHUB_CHAT_ROLE_ADMIN;

    switch ($chat !== null ? $_POST['action'] : null) {
        case 'nickname':
            if ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM) {
                chatSetNickname($db, $userId, (int) $chat['chatId'], $_POST['nickname'] ?? null);
            }
            break;
        case 'hide':
            chatSetHidden($db, $userId, (int) $chat['chatId'], true);
            break;
        case 'leave':
            chatLeave($db, $userId, $chat);
            break;
        case 'rename':
            // Left empty, the group is named after its members again
            $chatName = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
            if ($isGroupAdmin) {
                $db->prepare('UPDATE `HBHub-Chats` SET chatName = ? WHERE chatId = ?')
                    ->execute([$chatName === '' ? null : mb_substr($chatName, 0, HBHUB_CHAT_NAME_MAX), $chat['chatId']]);
            }
            break;
        case 'icon':
            $isRemoving = ($_POST['remove'] ?? null) === '1';
            $icon = $isRemoving ? null : chatIconUpload($_FILES['icon'] ?? null);
            if ($isGroupAdmin && ($isRemoving || $icon !== null)) {
                chatSetIcon($db, (int) $chat['chatId'], $icon);
            }
            break;
        case 'delete':
            if ($isGroupAdmin) {
                $db->prepare('UPDATE `HBHub-Chats` SET chatStatus = ? WHERE chatId = ?')
                    ->execute([HBHUB_CHAT_STATUS_DELETED, $chat['chatId']]);
            }
            break;
    }

    $isGone = $chat !== null && in_array($_POST['action'], ['hide', 'leave', 'delete'], true);
    chatRedirect($isGone && (int) $chat['chatId'] === $openChatId ? null : $openChatId);
}

// Answering a message request: accepting opens the chat, declining leaves it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['accept', 'decline'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null, true);
    $isAccepted = $_POST['action'] === 'accept';

    if ($chat !== null) {
        $db->prepare($isAccepted
            ? 'UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1 WHERE chatId = ? AND userId = ?'
            : 'DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?')
            ->execute([$chat['chatId'], $userId]);
    }

    chatRedirect($chat !== null && $isAccepted ? $chat['chatId'] : null);
}

// The right-click menu on a message: edit or delete your own message, or mark the chat unread from that message on
// (the message before it becomes the last one read). Deleted messages stay in the database, but only show as deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['edit', 'deleteMessage', 'unread'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = $chat !== null ? chatMessage($db, (int) $chat['chatId'], $_POST['message'] ?? null) : null;
    $isOwnMessage = $message !== null && (int) $message['userId'] === $userId && $message['messageDeletedTimestamp'] === null;

    switch ($message !== null ? $_POST['action'] : null) {
        case 'edit':
            $content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
            if ($isOwnMessage && !chatIsMuted($chat) && $message['messageContent'] !== null && $content !== ''
                && mb_strlen($content) <= HBHUB_CHAT_MESSAGE_MAX && $content !== $message['messageContent']) {
                $db->prepare('UPDATE `HBHub-Messages` SET messageContent = ?, messageIsEdited = 1
                    WHERE messageId = ? AND userId = ? AND messageDeletedTimestamp IS NULL')
                    ->execute([$content, $message['messageId'], $userId]);
            }
            break;
        case 'deleteMessage':
            if ($isOwnMessage) {
                $db->prepare('UPDATE `HBHub-Messages` SET messageDeletedTimestamp = NOW()
                    WHERE messageId = ? AND userId = ? AND messageDeletedTimestamp IS NULL')
                    ->execute([$message['messageId'], $userId]);
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

// Sending a message, maybe as a reply to another one, then back to the chat so a refresh doesn't send it again.
// A GIF picked in the GIF picker comes as "gif" (its Giphy id), with whatever was typed in the box sent just before it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
    $gifId = $chatGifsEnabled && hbHubGiphyIsId($_POST['gif'] ?? null) ? $_POST['gif'] : null;

    $contents = $message !== '' ? [$message] : [];
    if ($gifId !== null) {
        $contents[] = chatGifUrl($gifId);
    }

    if ($chat !== null && !chatIsMuted($chat) && $contents !== [] && mb_strlen($message) <= HBHUB_CHAT_MESSAGE_MAX) {
        // Only a message in this chat that isn't deleted can be replied to, otherwise it's sent as a normal message
        $replyTo = chatMessage($db, (int) $chat['chatId'], $_POST['reply'] ?? null);
        $replyToId = $replyTo !== null && $replyTo['messageDeletedTimestamp'] === null ? (int) $replyTo['messageId'] : null;

        $db->beginTransaction();
        $statement = $db->prepare('INSERT INTO `HBHub-Messages` (userId, chatId, messageContent, messageReplyToId) VALUES (?, ?, ?, ?)');
        foreach ($contents as $i => $content) {
            $statement->execute([$userId, $chat['chatId'], $content, $i === 0 ? $replyToId : null]); // the reply goes on the first
        }
        $db->prepare('UPDATE `HBHub-Chats` SET lastMessageTimestamp = NOW() WHERE chatId = ?')
            ->execute([$chat['chatId']]);
        $db->commit();

        // A new message brings the chat back for everyone who hid it
        chatChangeSettings($db, (int) $chat['chatId'], function (stdClass $settings): void {
            $settings->chatHidden = new stdClass();
        });
    }

    chatRedirect($chat !== null ? $chat['chatId'] : null);
}

// Opening a chat marks all its messages as read, unless it was just marked unread (?unread=1).
// Where the user had read up to before is kept for the "New messages" line.
$openChatId = $_GET['chat'] ?? null;
$openMembership = chatMembership($db, $userId, $openChatId);
$lastReadMessageId = $openMembership['lastReadMessageId'] ?? null;
if ($openMembership !== null && ($_GET['unread'] ?? null) !== '1') {
    $db->prepare('UPDATE `HBHub-ChatMembers` SET lastReadMessageId = (
            SELECT MAX(messageId) FROM `HBHub-Messages` WHERE chatId = ?
        ) WHERE chatId = ? AND userId = ?')
        ->execute([$openMembership['chatId'], $openMembership['chatId'], $userId]);
}

// Every chat the user is in, newest activity first.
// DMs (and groups without a name) are named after the other members, or a DM after the nickname the other person was given.
// Unread messages are the ones from others, not deleted, that came after the last one the user read.
$statement = $db->prepare('SELECT c.chatId, c.chatType, c.chatName, c.chatIconAttachmentId, c.chatSettings,
        cm.userAcknowledgedJoin, cm.memberRole,
        COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp) AS chatActivity,
        (SELECT cu.userName FROM `HBHub-Users` cu WHERE cu.userId = c.chatCreatedBy) AS chatCreatorName,
        (
            SELECT om.userId FROM `HBHub-ChatMembers` om
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserId,
        (
            SELECT ou.userName FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ? AND c.chatType = ' . HBHUB_CHAT_TYPE_DM . ' LIMIT 1
        ) AS dmUserName,
        COALESCE(NULLIF(c.chatName, \'\'), (
            SELECT GROUP_CONCAT(COALESCE(om.memberNickname, ou.userName) ORDER BY ou.userName SEPARATOR \', \')
            FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ?
        )) AS chatTitle,
        (
            SELECT m.messageContent FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageDeletedTimestamp IS NULL
            ORDER BY m.messageId DESC LIMIT 1
        ) AS chatLastMessage,
        (
            SELECT COUNT(*) FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageId > COALESCE(cm.lastReadMessageId, 0)
                AND m.userId <> ? AND m.messageDeletedTimestamp IS NULL
        ) AS chatUnreadCount
    FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
    WHERE cm.userId = ? AND c.chatStatus = ?
    ORDER BY chatActivity DESC');
$statement->execute([$userId, $userId, $userId, $userId, $userId, HBHUB_CHAT_STATUS_ACTIVE]);
$chats = [];
$chatRequests = []; // chats the user was added to but hasn't accepted yet
foreach ($statement->fetchAll() as $chat) {
    $chat['chatTitle'] ??= 'Just you';
    $settings = chatSettings($chat['chatSettings']);
    $chat['chatHiddenSetting'] = ($settings->chatHidden->{$userId} ?? false) === true;
    $nickname = $chat['dmUserId'] !== null ? $settings->chatNicknames->{$chat['dmUserId']} ?? null : null;
    $chat['chatNickname'] = is_string($nickname) ? $nickname : null;
    $chat['chatTitle'] = $chat['chatNickname'] ?? $chat['chatTitle'];
    if (chatGifId($chat['chatLastMessage']) !== null) {
        $chat['chatLastMessage'] = 'GIF';
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
    $chat['chatIsGroupAdmin'] = (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP && (int) $chat['memberRole'] === HBHUB_CHAT_ROLE_ADMIN;

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

$shownMessageIds = []; // messageId => true, for the replies that can jump to the message they answer
$firstUnreadMessageId = null; // the "New messages" line goes above this one
if ($openChat !== null) {
    // The newest messages, oldest at the top, each with the message it replies to (r) if any
    $statement = $db->prepare('SELECT * FROM (
            SELECT m.messageId, m.userId, m.messageContent, m.attachmentId, m.messageSentTimestamp,
                m.messageDeletedTimestamp, m.messageIsEdited, m.messageReplyToId,
                COALESCE(cm.memberNickname, u.userName) AS senderName,
                r.messageId AS replyId, r.userId AS replyUserId, r.messageContent AS replyContent,
                r.messageDeletedTimestamp AS replyDeletedTimestamp, COALESCE(rcm.memberNickname, ru.userName) AS replySenderName
            FROM `HBHub-Messages` m
            JOIN `HBHub-Users` u ON u.userId = m.userId
            LEFT JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = m.userId
            LEFT JOIN `HBHub-Messages` r ON r.messageId = m.messageReplyToId AND r.chatId = m.chatId
            LEFT JOIN `HBHub-Users` ru ON ru.userId = r.userId
            LEFT JOIN `HBHub-ChatMembers` rcm ON rcm.chatId = r.chatId AND rcm.userId = r.userId
            WHERE m.chatId = ?
            ORDER BY m.messageId DESC LIMIT ' . HBHUB_CHAT_MESSAGES_SHOWN . '
        ) newest ORDER BY messageId ASC');
    $statement->execute([$openChat['chatId']]);
    $messages = $statement->fetchAll();

    foreach ($messages as $message) {
        $shownMessageIds[(int) $message['messageId']] = true;
        if ($firstUnreadMessageId === null && (int) $message['messageId'] > (int) $lastReadMessageId
            && (int) $message['userId'] !== $userId && $message['messageDeletedTimestamp'] === null) {
            $firstUnreadMessageId = (int) $message['messageId'];
        }
    }
}

/**
 * Short time for the chat list and messages: just the time for today, otherwise the date.
 */
function chatTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    return date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : date('d/m/Y', $time);
}

function chatInitial(string $name): string
{
    return mb_strtoupper(mb_substr($name, 0, 1));
}

/**
 * The round picture for a chat: the group's icon if it has one, otherwise the first letter of its name.
 * The attachment id in the icon's address changes with every new icon, so an old one is never shown from the cache.
 */
function chatAvatarHtml(array $chat): string
{
    if ($chat['chatIconAttachmentId'] !== null) {
        return '<span class="chat-avatar" aria-hidden="true"><img src="/chat/icon?chat=' . (int) $chat['chatId']
            . '&amp;v=' . (int) $chat['chatIconAttachmentId'] . '" alt=""></span>';
    }
    return '<span class="chat-avatar" aria-hidden="true">' . htmlspecialchars(chatInitial($chat['chatTitle'])) . '</span>';
}

/**
 * A message as HTML: escaped, with line breaks, and every http:// or https:// address made a link.
 * The links open in a new tab, after chat.js has warned about following links from strangers.
 */
function chatMessageHtml(string $content): string
{
    $html = '';
    $parts = preg_split('~(?<![\w/])(https?://[^\s<>"]+)~iu', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return nl2br(htmlspecialchars($content)); // not valid UTF-8, so no links
    }

    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $html .= htmlspecialchars($part);
            continue;
        }

        // Punctuation at the end belongs to the sentence, not the address ("see https://x.dk.")
        $url = preg_replace('~[.,!?;:\'")\]]+$~u', '', $part);
        $trailing = substr($part, strlen($url));
        if (preg_match('~^https?://[^/?#]~i', $url)) {
            $html .= '<a class="chat-message-link" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer nofollow ugc" data-chat-link>'
                . htmlspecialchars($url) . '</a>';
        } else {
            $html .= htmlspecialchars($url); // just "https://" with no address after it
        }
        $html .= htmlspecialchars($trailing);
    }

    return nl2br($html);
}

/**
 * Who sent a message, as the open chat shows it: "You" for the user's own,
 * and in a DM the name the chat goes by, so the nickname the other person was given is used.
 */
function chatSenderName(array $openChat, int $userId, int $senderId, ?string $senderName): string
{
    if ($senderId === $userId) {
        return 'You';
    }
    if ((int) $openChat['chatType'] === HBHUB_CHAT_TYPE_DM && $openChat['chatNickname'] !== null) {
        return $openChat['chatNickname'];
    }
    return $senderName ?? 'Someone';
}

/**
 * The quote above a reply: who and what it answers, on one line.
 * A link to the original when it's on the page, so it can be scrolled to.
 */
function chatReplyHtml(array $openChat, int $userId, array $message, bool $isShown): string
{
    if ($message['replyId'] === null) {
        $sender = '';
        $text = '<span class="chat-message-reply-text is-deleted">Original message is gone</span>';
    } else {
        $sender = '<span class="chat-message-reply-sender">'
            . htmlspecialchars(chatSenderName($openChat, $userId, (int) $message['replyUserId'], $message['replySenderName'])) . '</span>';
        if ($message['replyDeletedTimestamp'] !== null) {
            $text = '<span class="chat-message-reply-text is-deleted">Message deleted</span>';
        } elseif ($message['replyContent'] === null) {
            $text = '<span class="chat-message-reply-text is-deleted">Attachment</span>';
        } elseif (chatGifId($message['replyContent']) !== null) {
            $text = '<span class="chat-message-reply-text is-deleted">GIF</span>';
        } else {
            // Shortened here too, so a long message isn't sent twice
            $content = preg_replace('~\s+~u', ' ', $message['replyContent']) ?? $message['replyContent'];
            $text = '<span class="chat-message-reply-text">' . htmlspecialchars(mb_substr($content, 0, 150)) . '</span>';
        }
    }

    $label = '<span class="chat-visually-hidden">Reply to </span>';
    if ($isShown) {
        return '<a class="chat-message-reply" href="#message-' . (int) $message['replyId'] . '" data-reply-link>' . $label . $sender . $text . '</a>';
    }
    return '<span class="chat-message-reply">' . $label . $sender . $text . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chat.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chat.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chat.js') ?>" defer></script>
    <title><?= $openChat !== null ? htmlspecialchars($openChat['chatTitle']) . ' - ' : '' ?>Chat - HB Hub</title>
    <meta name="description" content="Chat page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="chat<?= $openChat !== null ? ' has-open-chat' : '' ?>">
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
                <?php if ($chatRequests !== []): ?>
                    <button type="button" class="chat-requests-btn" data-requests-open
                            aria-label="Message requests (<?= count($chatRequests) ?>)" title="Message requests">
                        <img src="/assets/images/icons/notification.png" alt="">
                        <span class="chat-requests-count" aria-hidden="true"><?= count($chatRequests) > 9 ? '9+' : count($chatRequests) ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <h2 class="chat-list-heading">Chats</h2>
            <!-- Right-clicking a chat opens the chat menu (chat.js), the data-chat-* attributes say what it offers -->
            <ul class="chat-list">
                <?php foreach ($chats as $chat): ?>
                    <li data-chat-title="<?= htmlspecialchars($chat['chatTitle']) ?>"
                        data-chat-id="<?= $chat['chatId'] ?>"
                        data-chat-type="<?= (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'dm' : 'group' ?>"
                        data-chat-name="<?= htmlspecialchars($chat['chatName'] ?? '') ?>"<?=
                        $chat['dmUserName'] !== null ? ' data-chat-username="' . htmlspecialchars($chat['dmUserName']) . '"' : '' ?><?=
                        $chat['chatNickname'] !== null ? ' data-chat-nickname="' . htmlspecialchars($chat['chatNickname']) . '"' : '' ?><?=
                        $chat['chatIsGroupAdmin'] ? ' data-chat-admin' : '' ?><?=
                        $chat['chatIconAttachmentId'] !== null ? ' data-chat-icon' : '' ?><?=
                        $chat['chatHiddenByUser'] ? ' data-chat-hidden-by-user' : '' ?><?=
                        $chat['chatHidden'] ? ' hidden' : '' ?>>
                        <a class="chat-list-item<?= $chat['chatIsOpen'] ? ' active' : '' ?><?= $chat['chatUnreadCount'] > 0 ? ' is-unread' : '' ?>" href="/chat/?chat=<?= $chat['chatId'] ?>"<?= $chat['chatIsOpen'] ? ' aria-current="page"' : '' ?>>
                            <?= chatAvatarHtml($chat) ?>
                            <span class="chat-list-text">
                                <span class="chat-list-top">
                                    <span class="chat-list-title"><?= htmlspecialchars($chat['chatTitle']) ?></span>
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
                <?php endforeach; ?>
            </ul>
            <p class="chat-list-empty"<?= $chats !== [] ? ' hidden' : '' ?> data-empty-all>You don't have any chats yet.</p>
            <p class="chat-list-empty"<?= $chats === [] || $chatSearch !== '' || $chatListHasShown ? ' hidden' : '' ?> data-empty-hidden>All your chats are hidden. Search to find them again.</p>
            <p class="chat-list-empty"<?= $chats === [] || $chatSearch === '' || $chatListHasShown ? ' hidden' : '' ?> data-empty-search>No chats match your search.</p>
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
                    <?= chatAvatarHtml($openChat) ?>
                    <h2 class="chat-window-title"><?= htmlspecialchars($openChat['chatTitle']) ?></h2>
                </header>

                <!-- Right-clicking a message opens the message menu (chat.js), the data-message-* attributes say what it offers.
                     The arrow keys move between messages (tabindex is set by chat.js). -->
                <ol class="chat-messages" data-chat-messages>
                    <?php if ($messages === []): ?>
                        <li class="chat-messages-empty">No messages yet - say hi!</li>
                    <?php endif; ?>
                    <?php foreach ($messages as $message): ?>
                        <?php
                        $isOwn = (int) $message['userId'] === $userId;
                        $isDeleted = $message['messageDeletedTimestamp'] !== null;
                        $gifId = $isDeleted ? null : chatGifId($message['messageContent']);
                        ?>
                        <?php if ((int) $message['messageId'] === $firstUnreadMessageId): ?>
                            <li class="chat-messages-unread" data-unread-divider><span>New messages</span></li>
                        <?php endif; ?>
                        <li class="chat-message<?= $isOwn ? ' is-own' : '' ?>" id="message-<?= (int) $message['messageId'] ?>" tabindex="-1"
                            data-message-id="<?= (int) $message['messageId'] ?>"
                            data-message-sender="<?= htmlspecialchars(chatSenderName($openChat, $userId, (int) $message['userId'], $message['senderName'])) ?>"<?=
                            $isOwn ? ' data-message-own' : '' ?><?= $isDeleted ? ' data-message-deleted' : '' ?><?= $gifId !== null ? ' data-message-gif' : '' ?>>
                            <?php if (!$isOwn && (int) $openChat['chatType'] !== HBHUB_CHAT_TYPE_DM): ?>
                                <span class="chat-message-sender"><?= htmlspecialchars($message['senderName']) ?></span>
                            <?php endif; ?>
                            <?php if ($message['messageReplyToId'] !== null && !$isDeleted): ?>
                                <?= chatReplyHtml($openChat, $userId, $message, isset($shownMessageIds[(int) $message['replyId']])) ?>
                            <?php endif; ?>
                            <div class="chat-message-bubble<?= $gifId !== null ? ' is-gif' : '' ?>">
                                <?php if ($isDeleted): ?>
                                    <p class="chat-message-deleted">Message deleted</p>
                                <?php else: ?>
                                    <?php if ($gifId !== null): ?>
                                        <!-- Giphy's 200px tall version, so a chat full of GIFs doesn't load every full-size one -->
                                        <img class="chat-message-gif" src="https://media.giphy.com/media/<?= $gifId ?>/200.webp" alt="GIF"
                                             height="200" loading="lazy" referrerpolicy="no-referrer">
                                    <?php elseif ($message['messageContent'] !== null): ?>
                                        <p data-message-text><?= chatMessageHtml($message['messageContent']) ?></p>
                                    <?php elseif ($message['attachmentId'] !== null): ?>
                                        <p class="chat-message-deleted">Attachment</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <span class="chat-message-meta">
                                <time datetime="<?= htmlspecialchars($message['messageSentTimestamp']) ?>"><?= chatTime($message['messageSentTimestamp']) ?></time>
                                <?= $message['messageIsEdited'] && !$isDeleted ? ' &middot; edited' : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <form class="chat-composer" action="/chat/" method="post">
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
                    <!-- Attach: a small popup above the button (chat.js). File and Voice message are coming soon. -->
                    <div class="chat-attach">
                        <button type="button" class="chat-icon-btn" aria-label="Attach" title="Attach"
                                aria-haspopup="menu" aria-expanded="false" aria-controls="chat-attach-menu" data-attach-open>
                            <img src="/assets/images/icons/addFile.svg" alt="">
                        </button>
                        <div class="chat-menu chat-attach-menu" id="chat-attach-menu" role="menu" aria-label="Attach" hidden data-attach-menu>
                            <button type="button" role="menuitem" aria-disabled="true" title="Coming soon">
                                File <span class="chat-menu-soon">Soon</span>
                            </button>
                            <button type="button" role="menuitem" aria-disabled="true" title="Coming soon">
                                Voice message <span class="chat-menu-soon">Soon</span>
                            </button>
                        </div>
                    </div>
                    <label class="chat-visually-hidden" for="chat-message">Message</label>
                    <textarea id="chat-message" name="message" rows="1" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required
                              placeholder="Message <?= htmlspecialchars($openChat['chatTitle']) ?>" autofocus></textarea>
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
        <dialog class="chat-new" aria-labelledby="chat-new-title" data-members-max="<?= HBHUB_CHAT_MEMBERS_MAX ?>">
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
            <button type="button" role="menuitem" aria-disabled="true" title="Coming soon" data-menu-action="profile" data-menu-dm>
                Show profile <span class="chat-menu-soon">Soon</span>
            </button>
            <button type="button" role="menuitem" data-menu-action="nickname" data-menu-dm>Give nickname</button>
            <button type="button" role="menuitem" data-menu-action="hide">Hide chat</button>
            <button type="button" role="menuitem" data-menu-action="rename" data-menu-admin>Change name</button>
            <button type="button" role="menuitem" data-menu-action="icon" data-menu-admin>Change group icon</button>
            <button type="button" role="menuitem" aria-disabled="true" title="Coming soon" data-menu-action="background" data-menu-admin>
                Change background <span class="chat-menu-soon">Soon</span>
            </button>
            <hr class="chat-menu-divider">
            <button type="button" role="menuitem" class="is-danger" data-menu-action="leave">Leave chat</button>
            <button type="button" role="menuitem" class="is-danger" data-menu-action="delete" data-menu-admin>Delete group</button>
        </div>

        <?php if ($openChat !== null): ?>
            <!-- Message menu (chat.js): opened by right-clicking a message in the open chat.
                 Edit and Delete are only for your own messages, and a deleted message can only be marked unread. -->
            <div class="chat-menu" role="menu" aria-label="Message options" hidden data-message-menu>
                <button type="button" role="menuitem" data-menu-action="reply">Reply</button>
                <button type="button" role="menuitem" data-menu-action="edit">Edit message</button>
                <button type="button" role="menuitem" data-menu-action="unread">Mark unread</button>
                <hr class="chat-menu-divider" data-menu-delete>
                <button type="button" role="menuitem" class="is-danger" data-menu-action="delete" data-menu-delete>Delete message</button>
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
                    <textarea id="chat-edit-content" name="content" rows="4" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required></textarea>
                    <p class="chat-dialog-hint">Enter to save, Shift+Enter for a new line.</p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                        <button type="submit" class="chat-new-btn" data-edit-save>Save</button>
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

                    <p class="chat-dialog-text">Everyone in the chat will see it as deleted. This can't be undone.</p>
                    <p class="chat-dialog-quote" data-delete-preview></p>

                    <footer class="chat-new-footer chat-dialog-footer">
                        <button type="button" class="chat-btn" data-dialog-close>Cancel</button>
                        <button type="submit" class="chat-new-btn is-danger">Delete message</button>
                    </footer>
                </form>
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

        <!-- Message requests popup (chat.js): chats the user was added to, to accept or decline (leave) -->
        <?php if ($chatRequests !== []): ?>
            <dialog class="chat-requests" aria-labelledby="chat-requests-title">
                <div class="chat-requests-body">
                    <header class="chat-new-header">
                        <h2 id="chat-requests-title">Message requests</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-requests-close>&times;</button>
                    </header>

                    <ul class="chat-requests-list">
                        <?php foreach ($chatRequests as $request): ?>
                            <?php $isGroup = (int) $request['chatType'] !== HBHUB_CHAT_TYPE_DM; ?>
                            <li class="chat-request">
                                <?= chatAvatarHtml($request) ?>
                                <span class="chat-list-text">
                                    <span class="chat-list-top">
                                        <span class="chat-list-title"><?= htmlspecialchars($request['chatTitle']) ?></span>
                                        <span class="chat-list-time"><?= chatTime($request['chatActivity']) ?></span>
                                    </span>
                                    <span class="chat-list-preview">
                                        <?= $isGroup ? 'Group, added by ' . htmlspecialchars($request['chatCreatorName'] ?? 'someone') : 'Wants to send you messages' ?>
                                    </span>
                                    <?php if ($request['chatLastMessage'] !== null): ?>
                                        <span class="chat-list-preview chat-request-message"><?= htmlspecialchars($request['chatLastMessage']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <form class="chat-request-answer" action="/chat/" method="post">
                                    <input type="hidden" name="chat" value="<?= $request['chatId'] ?>">
                                    <button type="submit" class="chat-btn" name="action" value="decline">Decline</button>
                                    <button type="submit" class="chat-new-btn" name="action" value="accept">Accept</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </dialog>
        <?php endif; ?>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
