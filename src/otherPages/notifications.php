<?php
// The list under the bell in the nav bar, for notificationBell.js. Answers as JSON (the number on the bell is kept up
// to date by otherPages/navUpdates.php):
// - /notifications is the newest notifications, from every part of the site, how many are unread and the
//   newest one's id
// - POST action=read&upTo=id marks them read up to that one, once the list has been opened. Only up to the newest one
//   the user was shown, so one that came in meanwhile still counts as new.
// - POST action=act&notification=id is clicking the button on a notification (like Undo kick), answered with where to
//   go next ({"link": "/chat/?chat=5"}), or 409 and why it couldn't be done ({"error": "chatGone"})
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'act') {
    $notificationId = $_POST['notification'] ?? null;
    $result = is_string($notificationId) && ctype_digit($notificationId) && strlen($notificationId) <= 19
        ? hbHubNotificationAct($db, $userId, (int) $notificationId)
        : ['error' => 'notFound'];
    if (isset($result['error'])) {
        http_response_code($result['error'] === 'notFound' ? 404 : 409);
    }
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upTo = $_POST['upTo'] ?? null;
    if (($_POST['action'] ?? null) === 'read' && is_string($upTo) && ctype_digit($upTo) && strlen($upTo) <= 19) {
        hbHubMarkNotificationsRead($db, $userId, (int) $upTo);
    }
    echo json_encode(['unread' => hbHubUnreadNotificationCount($db, $userId)]);
    exit;
}

echo json_encode(['unread' => hbHubUnreadNotificationCount($db, $userId)] + hbHubNotifications($db, $userId), JSON_INVALID_UTF8_SUBSTITUTE);
