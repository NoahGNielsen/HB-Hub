<?php
// Announcements: a notification a site admin sends to every user at once, from the admin panel's Announcements page
// (admin/announcements.php). Everyone gets it under the bell as "admin.announcement" (notificationTypes/admin.php),
// with the text and, if the admin gave one, a link to a page on the site.
//
// Each one is a row in HBHub-Announcements (who sent it, what it says, the link, how many got it and when), plus one
// row in HBHub-Notifications per user, whose data is {"announcementId": 3, "text": "...", "link": "/games/" or null}.
// Taking one back (hbHubAnnouncementTakeBack()) deletes both, so it's gone from everyone's bell, read or not.
// Who gets it: every account that isn't deleted (userStatus NULL) or banned. Ghost-banned accounts get it too, as
// nobody else sees what they get.
require_once __DIR__ . '/notifications.php';

const HBHUB_ANNOUNCEMENT_TEXT_MAX = 500; // characters, announcementText
const HBHUB_ANNOUNCEMENT_LINK_MAX = 255; // characters, announcementLink
const HBHUB_ANNOUNCEMENT_TYPE = 'admin.announcement';
const HBHUB_ANNOUNCEMENT_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * The text as typed in the admin panel, tidied up: on one line (the bell shows it on one anyway), with the spaces
 * squashed, or null when it's empty or longer than HBHUB_ANNOUNCEMENT_TEXT_MAX.
 */
function hbHubAnnouncementText(mixed $text): ?string
{
    $text = is_string($text) ? trim(preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')) ?? '') : '';
    return $text !== '' && mb_strlen($text) <= HBHUB_ANNOUNCEMENT_TEXT_MAX ? $text : null;
}

/**
 * The link as typed in the admin panel: '' for none, the path when it's a path on the site (starts with one /, no
 * spaces or backslashes, so it can't be //other.site or javascript:), or null when it's neither.
 */
function hbHubAnnouncementLink(mixed $link): ?string
{
    $link = is_string($link) ? trim($link) : '';
    if ($link === '') {
        return '';
    }
    return strlen($link) <= HBHUB_ANNOUNCEMENT_LINK_MAX && preg_match('#^/(?![/\\\\])[\x21-\x7E]*$#', $link) === 1
        && !str_contains($link, '\\') ? $link : null;
}

/**
 * How many users an announcement sent now would reach.
 */
function hbHubAnnouncementAudience(PDO $db): int
{
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([HBHUB_ANNOUNCEMENT_STATUS_BANNED]);
    return (int) $statement->fetchColumn();
}

/**
 * Sends an announcement to every user ($text from hbHubAnnouncementText(), $link from hbHubAnnouncementLink(), '' for
 * none). The notifications go in with one INSERT ... SELECT, so it's one query however many users there are, and all
 * in one transaction, so either everyone gets it or nobody does. Returns the new announcement's id.
 */
function hbHubAnnouncementSend(PDO $db, int $adminId, string $text, string $link): int
{
    $link = $link !== '' ? $link : null;

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO `HBHub-Announcements` (announcementByUserId, announcementText, announcementLink) VALUES (?, ?, ?)')
            ->execute([$adminId, $text, $link]);
        $announcementId = (int) $db->lastInsertId();

        // Nobody who did it, so it shows the admin icon and not which admin, like a feedback reply
        $statement = $db->prepare('INSERT INTO `HBHub-Notifications` (userId, notificationType, notificationByUserId, notificationData)
            SELECT userId, ?, NULL, ? FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ?');
        $statement->execute([HBHUB_ANNOUNCEMENT_TYPE, json_encode(['announcementId' => $announcementId, 'text' => $text, 'link' => $link]),
            HBHUB_ANNOUNCEMENT_STATUS_BANNED]);

        $db->prepare('UPDATE `HBHub-Announcements` SET announcementRecipients = ? WHERE announcementId = ?')
            ->execute([$statement->rowCount(), $announcementId]);
        $db->commit();
        return $announcementId;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

/**
 * Takes an announcement back: deletes it and everyone's notification of it. Returns whether there was one to delete.
 */
function hbHubAnnouncementTakeBack(PDO $db, int $announcementId): bool
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('DELETE FROM `HBHub-Announcements` WHERE announcementId = ?');
        $statement->execute([$announcementId]);
        $isDeleted = $statement->rowCount() > 0;
        if ($isDeleted) {
            hbHubRemoveNotifications($db, null, HBHUB_ANNOUNCEMENT_TYPE, ['announcementId' => $announcementId]);
        }
        $db->commit();
        return $isDeleted;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

/**
 * How many users have seen each of the announcements (opened the bell since they got it), by announcement id.
 * One look through the announcement notifications for the whole page, not one per announcement.
 *
 * @param list<int> $announcementIds
 * @return array<int, int>
 */
function hbHubAnnouncementSeenCounts(PDO $db, array $announcementIds): array
{
    if ($announcementIds === []) {
        return [];
    }

    $statement = $db->prepare('SELECT CAST(JSON_EXTRACT(notificationData, \'$.announcementId\') AS UNSIGNED) AS announcementId, COUNT(*) AS seen
        FROM `HBHub-Notifications`
        WHERE notificationType = ? AND notificationReadTimestamp IS NOT NULL
        GROUP BY announcementId');
    $statement->execute([HBHUB_ANNOUNCEMENT_TYPE]);

    $wanted = array_flip($announcementIds);
    $counts = [];
    foreach ($statement->fetchAll() as $row) {
        if (isset($wanted[(int) $row['announcementId']])) {
            $counts[(int) $row['announcementId']] = (int) $row['seen'];
        }
    }
    return $counts;
}
