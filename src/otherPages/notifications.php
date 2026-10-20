<?php
// The list under the bell in the nav bar, for notificationBell.js. Answers as JSON (the number on the bell is kept up
// to date by otherPages/navUpdates.php):
// - /otherPages/notifications is the newest notifications, from every part of the site, how many are unread and the
//   newest one's id
// - POST action=read&upTo=id marks them read up to that one, once the list has been opened. Only up to the newest one
//   the user was shown, so one that came in meanwhile still counts as new.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upTo = $_POST['upTo'] ?? null;
    if (($_POST['action'] ?? null) === 'read' && is_string($upTo) && ctype_digit($upTo) && strlen($upTo) <= 19) {
        hbHubMarkNotificationsRead($db, $userId, (int) $upTo);
    }
    echo json_encode(['unread' => hbHubUnreadNotificationCount($db, $userId)]);
    exit;
}

echo json_encode(['unread' => hbHubUnreadNotificationCount($db, $userId)] + hbHubNotifications($db, $userId), JSON_INVALID_UTF8_SUBSTITUTE);
