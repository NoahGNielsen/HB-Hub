<?php
// The admin panel's pages: the lists of users and chats (admin/index.php), the banned pictures (admin/banned.php), the
// feedback (admin/feedback.php), the admin log (admin/log.php), and one user (admin/user.php) or chat (admin/chat.php).
// Only site admins (userRole 2) get in, everyone else gets a 404 so it doesn't show they're there. What the pages share
// is here: who gets in, the side menus (the settings page's, settings.css), and how dates, statuses, pictures, messages
// and chat names are shown. Everything they do is logged with adminLog.php.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/adminLog.php';

const ADMIN_ROLE_ADMIN = 2; // userRole in HBHub-Users
const ADMIN_CHAT_ROLES = [1 => 'Member', 2 => 'Group admin', 3 => 'Group owner']; // memberRole in HBHub-ChatMembers
// messageDeletedByAdmin in HBHub-Messages: 0 by the sender, 1 by a group admin, 2 by a site admin (the admin panel)
const ADMIN_MESSAGE_DELETED_LABELS = [0 => 'Deleted by the user', 1 => 'Deleted by a group admin', 2 => 'Deleted by a site admin'];
const ADMIN_MESSAGE_DELETED_BY_SITE_ADMIN = 2;
const ADMIN_MESSAGES_SHOWN = 50; // per page
const ADMIN_MENU_DANGER = 'Danger zone'; // the group of a side menu whose links are red (ban, delete)

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
 * The side menu of the admin panel's lists: Users, Chats (both admin/index.php), Banned pictures (admin/banned.php),
 * Feedback (admin/feedback.php) and Admin log (admin/log.php), each with how many there are in all, or for Feedback
 * how many are new. $active is 'users', 'chats', 'banned', 'feedback' or 'log'.
 */
function adminListsMenuHtml(PDO $db, string $active): string
{
    $totals = $db->query('SELECT (SELECT COUNT(*) FROM `HBHub-Users`) AS users, (SELECT COUNT(*) FROM `HBHub-Chats`) AS chats,
        (SELECT COUNT(*) FROM `HBHub-BannedAvatars`) AS banned,
        (SELECT COUNT(*) FROM `HBHub-Feedback` WHERE feedbackDoneTimestamp IS NULL) AS feedback,
        (SELECT COUNT(*) FROM `HBHub-AdminLog`) AS log')->fetch();
    $lists = [
        'users' => ['Users', '/admin/?list=users'],
        'chats' => ['Chats', '/admin/?list=chats'],
        'banned' => ['Banned pictures', '/admin/banned'],
        'feedback' => ['Feedback', '/admin/feedback'],
        'log' => ['Admin log', '/admin/log'],
    ];

    $html = '<nav class="settings-menu" aria-label="Admin panel"><div class="settings-menu-links"><div class="settings-menu-group">'
        . '<p class="settings-menu-heading">Lists</p><ul class="settings-menu-list">';
    foreach ($lists as $list => [$label, $href]) {
        $count = (int) $totals[$list];
        $html .= '<li><a class="settings-menu-link" href="' . $href . '"' . ($list === $active ? ' aria-current="page"' : '') . '>'
            . $label . ' <span class="settings-menu-count' . ($list === 'feedback' && $count > 0 ? ' is-new' : '') . '">'
            . number_format($count) . '</span></a></li>';
    }
    return $html . '</ul></div></div></nav>';
}

/**
 * The side menu of one user's or chat's admin panel: back to the list, its picture, name and status, then links to
 * the cards on the page, which settingsMenu.js marks while scrolling. $groups is heading => [card id => label], in the
 * order the cards are on the page; the links in the ADMIN_MENU_DANGER group are red. An empty group is left out.
 *
 * @param array<string, array<string, string>> $groups
 */
function adminPageMenuHtml(string $backHref, string $backLabel, ?string $avatarSrc, string $name, string $statusHtml, array $groups): string
{
    $html = '<nav class="settings-menu" aria-label="Sections" data-settings-menu>'
        . '<a class="settings-menu-back" href="' . htmlspecialchars($backHref) . '">&larr; ' . htmlspecialchars($backLabel) . '</a>'
        . '<div class="settings-menu-who"><span class="settings-menu-avatar" aria-hidden="true">'
        . ($avatarSrc !== null ? '<img src="' . htmlspecialchars($avatarSrc) . '" alt="">' : htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))))
        . '</span><div class="settings-menu-who-text"><span class="settings-menu-who-name">' . htmlspecialchars($name) . '</span>'
        . $statusHtml . '</div></div><div class="settings-menu-links">';
    foreach ($groups as $heading => $links) {
        if ($links === []) {
            continue;
        }
        $html .= '<div class="settings-menu-group"><p class="settings-menu-heading">' . htmlspecialchars($heading) . '</p><ul class="settings-menu-list">';
        foreach ($links as $card => $label) {
            $html .= '<li><a class="settings-menu-link' . ($heading === ADMIN_MENU_DANGER ? ' is-danger' : '') . '" href="#' . $card . '">'
                . htmlspecialchars($label) . '</a></li>';
        }
        $html .= '</ul></div>';
    }
    return $html . '</div></nav>';
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
 * With $queued, pictureQueue.js loads the picture instead, a couple at a time (data-queued-picture, see there), for the long
 * lists where every picture would otherwise be its own PHP request all at once. Without JS the <noscript> one loads.
 */
function adminAvatarHtml(?string $src, string $name, bool $queued = false): string
{
    if ($src === null) {
        return '<span class="admin-avatar" aria-hidden="true">' . htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
    }
    $img = '<img src="' . htmlspecialchars($src) . '" alt="" loading="lazy">';
    return '<span class="admin-avatar" aria-hidden="true">' . ($queued
        ? '<img data-queued-picture="' . htmlspecialchars($src) . '" alt=""><noscript>' . $img . '</noscript>'
        : $img) . '</span>';
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
