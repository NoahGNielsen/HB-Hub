<?php
// Chats: what the chat page (chat/index.php) and the admin panel's chat page (admin/chat.php) both use.
// The kinds of chat, member roles and notices, a chat's settings and background, and the changes both make to a chat:
// renaming a group, its icon, its background and taking someone out of it, each with a notice in the chat about it.
// From the admin panel the notice says a site admin made the change, not who (HBHUB_CHAT_EVENT_BY_SITE_ADMIN).
require_once __DIR__ . '/notifications.php';

const HBHUB_CHAT_TYPE_DM = 1; // chatType in HBHub-Chats
const HBHUB_CHAT_TYPE_GROUP = 2;
const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_CHAT_STATUS_DELETED = 2; // everyone left, or a group admin deleted it
const HBHUB_CHAT_STATUS_DELETED_BY_SITE_ADMIN = 3; // from the admin panel, at chatBannedTimestamp
const HBHUB_CHAT_ROLE_MEMBER = 1; // memberRole in HBHub-ChatMembers
const HBHUB_CHAT_ROLE_ADMIN = 2;
const HBHUB_CHAT_ROLE_OWNER = 3; // a group admin who can also remove admins, one per group
const HBHUB_CHAT_NAME_MAX = 64; // chatName column
// Change background: which way the gradient goes, as the angle linear-gradient() takes => [arrow, label]
const HBHUB_CHAT_BACKGROUND_DIRECTIONS = [
    0 => ['&uarr;', 'Up'],
    45 => ['&nearr;', 'Up right'],
    90 => ['&rarr;', 'Right'],
    135 => ['&searr;', 'Down right'],
    180 => ['&darr;', 'Down'],
    225 => ['&swarr;', 'Down left'],
    270 => ['&larr;', 'Left'],
    315 => ['&nwarr;', 'Up left'],
];
// chatBackgroundGradientColor in the chat's settings while it has the default background (database/defaultChatSettings.json)
const HBHUB_CHAT_BACKGROUND_DEFAULT = ['color1' => 'default', 'color2' => 'default', 'gradientAngle' => 90];
// messageEventType in HBHub-Messages: which change a notice in the middle of the chat is about (messageEvent is 1),
// made by the message's userId
const HBHUB_CHAT_EVENT_RENAME = 1; // messageContent is the new name, NULL when it was taken away
const HBHUB_CHAT_EVENT_ICON = 2;
const HBHUB_CHAT_EVENT_ICON_REMOVE = 3;
const HBHUB_CHAT_EVENT_BACKGROUND = 4;
const HBHUB_CHAT_EVENT_BACKGROUND_RESET = 5;
const HBHUB_CHAT_EVENT_MAKE_ADMIN = 6; // messageEventUserId is who it's about, for these three and ADD and OWNER
const HBHUB_CHAT_EVENT_REMOVE_ADMIN = 7;
const HBHUB_CHAT_EVENT_KICK = 8;
const HBHUB_CHAT_EVENT_JOIN = 9;
const HBHUB_CHAT_EVENT_LEAVE = 10;
const HBHUB_CHAT_EVENT_ADD = 11;
const HBHUB_CHAT_EVENT_OWNER = 12; // took over as owner when the owner left, so they're both userId and messageEventUserId
// Added to the event when a site admin made the change from the admin panel (admin/chat.php), so 101 is a group renamed
// by a site admin. The notice says "A site admin" then, and userId is still the admin who did it.
const HBHUB_CHAT_EVENT_BY_SITE_ADMIN = 100;

/**
 * Puts a notice in the middle of the chat about a change the user made, like a new group name or a member kicked.
 * It's saved as a message, so it shows up in the right place and polling brings it to everyone in the chat.
 *
 * @param int $event one of the HBHUB_CHAT_EVENT_* constants
 * @param ?int $aboutUserId who it's about: the member made or removed as admin, kicked or added, or the new owner
 * @param ?string $content the group's new name, for HBHUB_CHAT_EVENT_RENAME
 * @param bool $bySiteAdmin made from the admin panel, so the notice says a site admin did it
 */
function chatNotice(PDO $db, int $userId, int $chatId, int $event, ?int $aboutUserId = null, ?string $content = null,
    bool $bySiteAdmin = false): void
{
    $db->prepare('INSERT INTO `HBHub-Messages` (userId, chatId, messageContent, messageEvent, messageEventType, messageEventUserId)
        VALUES (?, ?, ?, 1, ?, ?)')
        ->execute([$userId, $chatId, $content, $bySiteAdmin ? HBHUB_CHAT_EVENT_BY_SITE_ADMIN + $event : $event, $aboutUserId]);
}

/**
 * What a chat notification needs to know about the chat (assets/php/notificationTypes/chat.php):
 * its id, its name right now and whether it's a group.
 *
 * @return array{chatId: int, chatName: ?string, isGroup: bool}
 */
function chatNotificationData(PDO $db, int $chatId): array
{
    $statement = $db->prepare('SELECT chatName, chatType FROM `HBHub-Chats` WHERE chatId = ?');
    $statement->execute([$chatId]);
    $chat = $statement->fetch() ?: ['chatName' => null, 'chatType' => HBHUB_CHAT_TYPE_DM];
    return ['chatId' => $chatId, 'chatName' => $chat['chatName'], 'isGroup' => (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP];
}

/**
 * Takes the user out of the chat, with a notice that they left, or that $kickedBy kicked them.
 * When they leave a DM, the other person gets a notification about it (once they've accepted it).
 * A group is never left without an owner: when the owner leaves, the admin who has been in it the longest takes over,
 * or the longest-standing member when there's no admin left, and a notice says who.
 * A chat with nobody left in it is deleted.
 *
 * @param array{chatId: int, chatType: int} $chat
 * @param bool $kickedBySiteAdmin kicked from the admin panel, so the notice says a site admin did it
 */
function chatLeave(PDO $db, int $userId, array $chat, ?int $kickedBy = null, bool $kickedBySiteAdmin = false): void
{
    $chatId = (int) $chat['chatId'];
    $db->beginTransaction();
    $db->prepare('DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?')
        ->execute([$chatId, $userId]);

    $statement = $db->prepare('SELECT userId, memberRole, userAcknowledgedJoin FROM `HBHub-ChatMembers` WHERE chatId = ?
        ORDER BY userAcknowledgedJoin DESC, memberJoinedTimestamp, userId FOR UPDATE');
    $statement->execute([$chatId]);
    $members = $statement->fetchAll();

    if ($members === []) {
        $db->prepare('UPDATE `HBHub-Chats` SET chatStatus = ? WHERE chatId = ?')
            ->execute([HBHUB_CHAT_STATUS_DELETED, $chatId]);
    } else {
        if ($kickedBy === null) {
            chatNotice($db, $userId, $chatId, HBHUB_CHAT_EVENT_LEAVE);
            if ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM) {
                foreach ($members as $member) {
                    if ($member['userAcknowledgedJoin']) {
                        hbHubNotify($db, (int) $member['userId'], 'chat.leftDm', $userId, chatNotificationData($db, $chatId));
                    }
                }
            }
        } else {
            chatNotice($db, $kickedBy, $chatId, HBHUB_CHAT_EVENT_KICK, $userId, null, $kickedBySiteAdmin);
        }

        $roles = array_map('intval', array_column($members, 'memberRole'));
        if ((int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP && !in_array(HBHUB_CHAT_ROLE_OWNER, $roles, true)) {
            $adminIndex = array_search(HBHUB_CHAT_ROLE_ADMIN, $roles, true);
            $newOwnerId = (int) $members[$adminIndex === false ? 0 : $adminIndex]['userId'];
            $db->prepare('UPDATE `HBHub-ChatMembers` SET memberRole = ? WHERE chatId = ? AND userId = ?')
                ->execute([HBHUB_CHAT_ROLE_OWNER, $chatId, $newOwnerId]);
            chatNotice($db, $newOwnerId, $chatId, HBHUB_CHAT_EVENT_OWNER, $newOwnerId);
        }
    }
    $db->commit();
}

/**
 * Renames the group, with a notice about it. Left empty, the group is named after its members again.
 * Saving the same name again isn't a change, so there's no notice then.
 *
 * @param bool $bySiteAdmin renamed from the admin panel, so the notice says a site admin did it
 */
function chatRename(PDO $db, int $userId, int $chatId, mixed $name, bool $bySiteAdmin = false): void
{
    $chatName = is_string($name) ? trim($name) : '';
    $chatName = $chatName === '' ? null : mb_substr($chatName, 0, HBHUB_CHAT_NAME_MAX);

    $statement = $db->prepare('UPDATE `HBHub-Chats` SET chatName = ? WHERE chatId = ? AND NOT (chatName <=> ?)');
    $statement->execute([$chatName, $chatId, $chatName]);
    if ($statement->rowCount() > 0) {
        chatNotice($db, $userId, $chatId, HBHUB_CHAT_EVENT_RENAME, null, $chatName, $bySiteAdmin);
    }
}

/**
 * Gives the group a new icon, or none when $icon is null. The old icon is deleted.
 * Returns false when nothing changed (removing the icon of a group that has none).
 *
 * @param ?array{0: string, 1: string} $icon [type, data] from chatIconUpload()
 */
function chatSetIcon(PDO $db, int $chatId, ?array $icon): bool
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

    return $icon !== null || $oldIconId !== null;
}

/**
 * The chat's settings from the chatSettings column, with the defaults filled in for anything not saved yet.
 */
function chatSettings(?string $json): stdClass
{
    // Same shape as database/defaultChatSettings.json. The nicknames are keyed by userId, so they start out empty.
    $settings = (object) [
        'chatNicknames' => new stdClass(),
        'chatBackgroundGradientColor' => (object) HBHUB_CHAT_BACKGROUND_DEFAULT,
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
 * A background gradient as [color1, color2, angle], or null when it isn't one Change background could have made:
 * two colors like #a1b2c3 and one of its directions. Only those are let through, as they end up in the page's CSS.
 *
 * @return ?array{0: string, 1: string, 2: int}
 */
function chatBackgroundOf(mixed $color1, mixed $color2, mixed $angle): ?array
{
    $isColor = fn (mixed $color): bool => is_string($color) && preg_match('~^#[0-9a-f]{6}$~i', $color) === 1;
    // An int from the saved settings, a string from the form
    $angle = is_string($angle) && ctype_digit($angle) && strlen($angle) <= 3 ? (int) $angle : $angle;

    if (!$isColor($color1) || !$isColor($color2) || !is_int($angle) || !array_key_exists($angle, HBHUB_CHAT_BACKGROUND_DIRECTIONS)) {
        return null;
    }
    return [strtolower($color1), strtolower($color2), $angle];
}

/**
 * The chat's background gradient from its settings as [color1, color2, angle], or null for the default background.
 *
 * @return ?array{0: string, 1: string, 2: int}
 */
function chatBackground(stdClass $settings): ?array
{
    $gradient = $settings->chatBackgroundGradientColor;
    return $gradient instanceof stdClass
        ? chatBackgroundOf($gradient->color1 ?? null, $gradient->color2 ?? null, $gradient->gradientAngle ?? null)
        : null;
}

/**
 * Gives the chat a new background gradient, or the default background when $background is null.
 * Returns false when it already had that background.
 *
 * @param ?array{0: string, 1: string, 2: int} $background from chatBackgroundOf()
 */
function chatSetBackground(PDO $db, int $chatId, ?array $background): bool
{
    $isChanged = false;
    chatChangeSettings($db, $chatId, function (stdClass $settings) use ($background, &$isChanged): void {
        $isChanged = chatBackground($settings) !== $background;
        $settings->chatBackgroundGradientColor = (object) ($background === null ? HBHUB_CHAT_BACKGROUND_DEFAULT
            : ['color1' => $background[0], 'color2' => $background[1], 'gradientAngle' => $background[2]]);
    });
    return $isChanged;
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
 * What a notice says, as HTML: "<strong>Alice</strong> made <strong>you</strong> a group admin".
 * The names are the ones the chat shows, so "You" for the user and the nickname someone was given.
 * A change made from the admin panel says "A site admin" instead of who made it.
 * The admin panel passes 0 as $userId, as nobody is "you" there.
 */
function chatNoticeHtml(array $openChat, int $userId, array $message): string
{
    $name = fn (string $name): string => '<strong>' . htmlspecialchars($name) . '</strong>';
    $event = (int) $message['messageEventType'];
    $isBySiteAdmin = $event > HBHUB_CHAT_EVENT_BY_SITE_ADMIN;
    $by = $name($isBySiteAdmin ? 'A site admin' : chatSenderName($openChat, $userId, (int) $message['userId'], $message['senderName']));
    $aboutUserId = $message['messageEventUserId'] !== null ? (int) $message['messageEventUserId'] : null;
    $about = $name(match ($aboutUserId) {
        null => 'someone', // their account is gone
        $userId => 'you',
        default => chatSenderName($openChat, $userId, $aboutUserId, $message['eventUserName']),
    });
    $chatWord = (int) $openChat['chatType'] === HBHUB_CHAT_TYPE_GROUP ? 'group' : 'chat';

    return match ($isBySiteAdmin ? $event - HBHUB_CHAT_EVENT_BY_SITE_ADMIN : $event) {
        HBHUB_CHAT_EVENT_RENAME => $message['messageContent'] === null
            ? $by . ' removed the group name'
            : $by . ' changed the group name to ' . $name($message['messageContent']),
        HBHUB_CHAT_EVENT_ICON => $by . ' changed the group icon',
        HBHUB_CHAT_EVENT_ICON_REMOVE => $by . ' removed the group icon',
        HBHUB_CHAT_EVENT_BACKGROUND => $by . ' changed the chat background',
        HBHUB_CHAT_EVENT_BACKGROUND_RESET => $by . ' reset the chat background',
        HBHUB_CHAT_EVENT_MAKE_ADMIN => $by . ' made ' . $about . ' a group admin',
        HBHUB_CHAT_EVENT_REMOVE_ADMIN => $by . ' removed ' . $about . ' as group admin',
        HBHUB_CHAT_EVENT_KICK => $by . ' kicked ' . $about . ' from the group',
        HBHUB_CHAT_EVENT_JOIN => $by . ' joined the group',
        HBHUB_CHAT_EVENT_LEAVE => $by . ' left the ' . $chatWord,
        HBHUB_CHAT_EVENT_ADD => $by . ' added ' . $about . ' to the group',
        HBHUB_CHAT_EVENT_OWNER => $by . ((int) $message['userId'] === $userId ? ' are' : ' is') . ' now the group owner',
        default => $by . ' changed the ' . $chatWord,
    };
}
