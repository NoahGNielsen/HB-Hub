<?php
// The list under the bell in the nav bar, for notificationBell.js. Answers as JSON:
// - /otherPages/notifications?count=1 is just how many are unread (polled for the number on the bell)
// - /otherPages/notifications is the newest notifications too, from every part of the site, and the newest one's id
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

$response = ['unread' => hbHubUnreadNotificationCount($db, $userId)];
if (($_GET['count'] ?? null) !== '1') {
    $response += hbHubNotifications($db, $userId);
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
