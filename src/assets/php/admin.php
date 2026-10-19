<?php
// The admin panel's pages: the lists of users and chats (admin/index.php), the banned pictures (admin/banned.php), the
// feedback (admin/feedback.php), and one user (admin/user.php) or chat (admin/chat.php). Only site admins (userRole 2) get in, everyone else gets a 404 so
// it doesn't show they're there. What the pages share is here: who gets in, the tabs at the top of the lists, and how
// dates, statuses, pictures, messages and chat names are shown.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/chat.php';

const ADMIN_ROLE_ADMIN = 2; // userRole in HBHub-Users
const ADMIN_CHAT_ROLES = [1 => 'Member', 2 => 'Group admin', 3 => 'Group owner']; // memberRole in HBHub-ChatMembers
// messageDeletedByAdmin in HBHub-Messages: 0 by the sender, 1 by a group admin, 2 by a site admin (the admin panel)
const ADMIN_MESSAGE_DELETED_LABELS = [0 => 'Deleted by the user', 1 => 'Deleted by a group admin', 2 => 'Deleted by a site admin'];
const ADMIN_MESSAGE_DELETED_BY_SITE_ADMIN = 2;
const ADMIN_MESSAGES_SHOWN = 50; // per page

/**
 * Shows the 404 page and stops.
 */
function adminNotFound(): never
{
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}

/**
 * Lets only site admins go on, everyone else gets the 404 page. Every admin page starts with this.
 */
function adminOnly(array $user): void
{
    if ((int) $user['userRole'] !== ADMIN_ROLE_ADMIN) {
        adminNotFound();
    }
}

/**
 * The tabs at the top of the admin panel's start page: Users, Chats (both admin/index.php), Banned pictures
 * (admin/banned.php) and Feedback (admin/feedback.php), each with how many there are in all, or for Feedback how many
 * are new. $active is 'users', 'chats', 'banned' or 'feedback'.
 */
function adminTabsHtml(PDO $db, string $active): string
{
    $totals = $db->query('SELECT (SELECT COUNT(*) FROM `HBHub-Users`) AS users, (SELECT COUNT(*) FROM `HBHub-Chats`) AS chats,
        (SELECT COUNT(*) FROM `HBHub-BannedAvatars`) AS banned,
        (SELECT COUNT(*) FROM `HBHub-Feedback` WHERE feedbackDoneTimestamp IS NULL) AS feedback')->fetch();
    $tabs = [
        'users' => ['Users', '/admin/?list=users'],
        'chats' => ['Chats', '/admin/?list=chats'],
        'banned' => ['Banned pictures', '/admin/banned'],
        'feedback' => ['Feedback', '/admin/feedback'],
    ];

    $html = '<nav class="admin-tabs" aria-label="Lists">';
    foreach ($tabs as $tab => [$label, $href]) {
        $isActive = $tab === $active;
        $html .= '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" href="' . $href . '"' . ($isActive ? ' aria-current="page"' : '') . '>'
            . $label . ' <span class="admin-tab-count">' . number_format((int) $totals[$tab]) . '</span></a>';
    }
    return $html . '</nav>';
}

/**
 * A database timestamp as the rest of the site shows dates and times, or a dash when there isn't one.
 */
function adminTime(?string $timestamp): string
{
    return $timestamp !== null ? date('d/m/Y H:i', strtotime($timestamp)) : '–';
}

/**
 * A user's status: Deleted, Banned, Locked (too many wrong passwords) or Active.
 *
 * @param array{userStatus: mixed, isLocked: mixed} $user
 */
function adminUserStatus(array $user): string
{
    return match (true) {
        $user['userStatus'] === null => 'Deleted',
        (int) $user['userStatus'] === HBHUB_SESSION_STATUS_BANNED => 'Banned',
        (bool) $user['isLocked'] => 'Locked',
        default => 'Active',
    };
}

/**
 * A chat's status: Active, Deleted (everyone left, or a group admin deleted it) or Deleted by a site admin.
 */
function adminChatStatus(int $chatStatus): string
{
    return match ($chatStatus) {
        HBHUB_CHAT_STATUS_ACTIVE => 'Active',
        HBHUB_CHAT_STATUS_DELETED_BY_SITE_ADMIN => 'Deleted by a site admin',
        default => 'Deleted',
    };
}

/**
 * A user's or chat's status as a badge: green while active, yellow when locked, red when banned or deleted.
 */
function adminStatusHtml(string $status): string
{
    $class = match ($status) {
        'Active' => ' is-on',
        'Locked' => ' admin-status-locked',
        default => ' admin-status-deleted',
    };
    return '<span class="settings-status' . $class . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * A small round picture for the lists, a user's or a chat's, or the first letter of the name when there isn't one.
 */
function adminAvatarHtml(?string $src, string $name): string
{
    return '<span class="admin-avatar" aria-hidden="true">' . ($src !== null
        ? '<img src="' . htmlspecialchars($src) . '" alt="" loading="lazy">'
        : htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1)))) . '</span>';
}

/**
 * The address of a chat's icon, which site admins can see for every chat (chat/icon.php), or null when it has none.
 */
function adminChatIconUrl(int $chatId, mixed $iconId): ?string
{
    return $iconId !== null ? '/chat/icon?chat=' . $chatId . '&v=' . (int) $iconId : null;
}

/**
 * The SQL for a chat's name as the admin panel shows it (the chat is c): its own name, otherwise its members' names.
 * With $exceptColumn, the member in that column is left out, for a list of that member's chats.
 */
function adminChatTitleSql(?string $exceptColumn = null): string
{
    return 'COALESCE(NULLIF(c.chatName, \'\'), (
            SELECT GROUP_CONCAT(ou.userName ORDER BY ou.userName SEPARATOR \', \')
            FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId' . ($exceptColumn !== null ? ' AND om.userId <> ' . $exceptColumn : '') . '
        ), ' . ($exceptColumn !== null ? '\'Nobody else\'' : '\'Nobody left\'') . ')';
}

/**
 * A message's text for a list of messages, as HTML: GIFs and attachments are named, not shown.
 */
function adminMessageHtml(array $message): string
{
    $content = $message['messageContent'];
    if ($content === null) {
        return '<span class="admin-muted">' . ($message['attachmentId'] !== null ? 'Attachment' : 'Empty') . '</span>';
    }
    if (preg_match('~^https://media\.giphy\.com/media/[A-Za-z0-9]{1,64}/giphy\.gif$~', $content) === 1) {
        return '<span class="admin-muted">GIF</span> <span class="admin-small">' . htmlspecialchars($content) . '</span>';
    }
    return nl2br(htmlspecialchars($content));
}
