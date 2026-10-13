<?php
// The notifications behind the bell in the nav bar (navPanel.php, notificationBell.js and otherPages/notifications.php),
// for every part of the site. Each one is a row in HBHub-Notifications for the user who gets it, with its type,
// who did it (if anyone) and whatever the type needs to say what happened (notificationData, as JSON).
//
// A type is "section.kind", like "chat.kicked". Every section has a file in notificationTypes/ named after it
// (notificationTypes/chat.php), which gives the section's icon and its kinds: what each one says and where it links to.
// To add notifications to a new part of the site, like gambling:
//   1. Make notificationTypes/gambling.php, the same shape as chat.php
//   2. Call hbHubNotify($db, $userId, 'gambling.bigWin', null, ['amount' => 500]) where it happens
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/userAvatar.php';

const HBHUB_NOTIFICATION_SHOWN = 30; // the newest ones, in the list under the bell
const HBHUB_NOTIFICATION_TYPE_MAX = 64; // notificationType column
const HBHUB_NOTIFICATION_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * Every section's icon and kinds, from notificationTypes/, by section name.
 *
 * @return array<string, array{icon: string, kinds: array<string, array{text: callable(array): string, link?: callable(array): ?string}>}>
 */
function hbHubNotificationSections(): array
{
    static $sections = null;
    if ($sections === null) {
        $sections = [];
        $load = static function (string $file): array {
            return require $file; // on its own, so the file's variables can't clash with these
        };
        foreach (glob(__DIR__ . '/notificationTypes/*.php') ?: [] as $file) {
            $sections[basename($file, '.php')] = $load($file);
        }
    }
    return $sections;
}

/**
 * The type's kind (its text and link) with its section's icon, or null when there's no such type.
 *
 * @return ?array{icon: string, text: callable(array): string, link?: callable(array): ?string}
 */
function hbHubNotificationType(string $type): ?array
{
    [$section, $kind] = array_pad(explode('.', $type, 2), 2, '');
    $sections = hbHubNotificationSections();
    $found = $sections[$section]['kinds'][$kind] ?? null;
    return $found !== null ? ['icon' => $sections[$section]['icon']] + $found : null;
}

/**
 * Gives the user a notification.
 *
 * @param string $type "section.kind", one of the kinds in notificationTypes/
 * @param ?int $byUserId who did it, or null when nobody did (the section's icon is shown instead of their picture)
 * @param array $data what the kind needs to say what happened, and to find it again with hbHubRemoveNotifications()
 */
function hbHubNotify(PDO $db, int $userId, string $type, ?int $byUserId = null, array $data = []): void
{
    if (strlen($type) > HBHUB_NOTIFICATION_TYPE_MAX || hbHubNotificationType($type) === null) {
        throw new InvalidArgumentException('Unknown notification type: ' . $type); // a typo, so it's caught straight away
    }

    $db->prepare('INSERT INTO `HBHub-Notifications` (userId, notificationType, notificationByUserId, notificationData) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $type, $byUserId, $data !== [] ? json_encode($data) : null]);
}

/**
 * The WHERE for the user's notifications of the type whose data has the values in $about, like ['chatId' => 5].
 *
 * @return array{0: string, 1: list<mixed>} [sql, parameters]
 */
function hbHubNotificationsAbout(int $userId, string $type, array $about): array
{
    $sql = 'userId = ? AND notificationType = ?';
    $parameters = [$userId, $type];
    foreach ($about as $key => $value) {
        $sql .= ' AND JSON_UNQUOTE(JSON_EXTRACT(notificationData, ?)) = ?';
        array_push($parameters, '$.' . $key, (string) $value);
    }
    return [$sql, $parameters];
}

/**
 * Takes back the user's notifications of the type about $about, like a message request when the invite is taken back.
 */
function hbHubRemoveNotifications(PDO $db, int $userId, string $type, array $about = []): void
{
    [$where, $parameters] = hbHubNotificationsAbout($userId, $type, $about);
    $db->prepare('DELETE FROM `HBHub-Notifications` WHERE ' . $where)->execute($parameters);
}

/**
 * Marks the user's notifications of the type about $about read, like a message request once it's been answered.
 */
function hbHubMarkNotificationsReadAbout(PDO $db, int $userId, string $type, array $about = []): void
{
    [$where, $parameters] = hbHubNotificationsAbout($userId, $type, $about);
    $db->prepare('UPDATE `HBHub-Notifications` SET notificationReadTimestamp = NOW()
        WHERE notificationReadTimestamp IS NULL AND ' . $where)->execute($parameters);
}

/**
 * Marks the user's notifications read, up to and with $upToId (the newest one they were shown).
 */
function hbHubMarkNotificationsRead(PDO $db, int $userId, int $upToId): void
{
    $db->prepare('UPDATE `HBHub-Notifications` SET notificationReadTimestamp = NOW()
        WHERE userId = ? AND notificationReadTimestamp IS NULL AND notificationId <= ?')
        ->execute([$userId, $upToId]);
}

/**
 * How many notifications the user hasn't seen yet, for the number on the bell.
 */
function hbHubUnreadNotificationCount(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Notifications` WHERE userId = ? AND notificationReadTimestamp IS NULL');
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

/**
 * The user's newest notifications, newest first, ready to be sent to notificationBell.js, and the id of the newest one
 * (to mark them read up to). A type that isn't in notificationTypes/ anymore is left out.
 *
 * @return array{newestId: ?int, notifications: list<array{id: int, by: ?string, byInitial: ?string, byAvatar: ?string,
 *     icon: string, text: string, link: ?string, time: string, isUnread: bool}>}
 */
function hbHubNotifications(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT n.notificationId, n.notificationType, n.notificationByUserId, n.notificationData,
            n.notificationCreatedTimestamp, n.notificationReadTimestamp IS NULL AS isUnread,
            u.userName, u.userAvatarAttachmentId, u.userStatus = ? AS byBanned
        FROM `HBHub-Notifications` n
        LEFT JOIN `HBHub-Users` u ON u.userId = n.notificationByUserId
        WHERE n.userId = ?
        ORDER BY n.notificationId DESC LIMIT ' . HBHUB_NOTIFICATION_SHOWN);
    $statement->execute([HBHUB_NOTIFICATION_STATUS_BANNED, $userId]);
    $rows = $statement->fetchAll();

    $notifications = [];
    foreach ($rows as $row) {
        $type = hbHubNotificationType($row['notificationType']);
        if ($type === null) {
            continue;
        }

        $data = $row['notificationData'] !== null ? (json_decode($row['notificationData'], true) ?? []) : [];
        $byUserId = $row['notificationByUserId'] !== null ? (int) $row['notificationByUserId'] : null;
        $by = $byUserId !== null ? $row['userName'] : null;
        // Banned users don't have a profile picture anymore (otherPages/avatar.php)
        $avatarId = $row['userAvatarAttachmentId'] !== null && !$row['byBanned'] ? (int) $row['userAvatarAttachmentId'] : null;
        $time = strtotime($row['notificationCreatedTimestamp']);

        $notifications[] = [
            'id' => (int) $row['notificationId'],
            'by' => $by,
            'byInitial' => $by !== null ? mb_strtoupper(mb_substr($by, 0, 1)) : null,
            'byAvatar' => $byUserId !== null ? hbHubAvatarUrl($byUserId, $avatarId) : null,
            'icon' => $type['icon'],
            'text' => ($type['text'])($data),
            'link' => isset($type['link']) ? ($type['link'])($data) : null,
            // Like the notices in a chat: just the time for today, otherwise the date and the time
            'time' => date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : date('d/m/Y • H.i', $time),
            'isUnread' => (bool) $row['isUnread'],
        ];
    }

    return ['newestId' => $rows !== [] ? (int) $rows[0]['notificationId'] : null, 'notifications' => $notifications];
}
