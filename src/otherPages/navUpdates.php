<?php
// Everything the nav bar keeps up to date, in one request, for navUpdates.js: the hosting only runs about 10 PHP
// requests at once for the whole site, so every open page asking once instead of once per number matters.
// GET /nav-updates answers, as JSON:
// - unread: unread notifications, the number on the bell (notificationBell.js)
// - unreadChats: chats with unread messages, the number on Chat in the side bar (chatUnread.js)
// - with &wallet=1 (casino pages, casino.js): wallet, the chips and daily chips like /gambling/chips sends them.
//   Left out while the user can't use the casino (blocked in settings, or not open to them).
// - with &messages=1 (when browser notifications or the message sound are on, notifications.js): messages, the new
//   messages to notify about, after the message id in &after= (see assets/php/messageNotifications.php)
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatUnread.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/messageNotifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$userSettings = hbHubUserSettings($hbHubUser['userSettings']);

$response = [
    'unread' => hbHubUnreadNotificationCount($db, $userId),
    'unreadChats' => hbHubUnreadChatCount($db, $userId, $userSettings),
];
if (($_GET['wallet'] ?? null) === '1' && hbHubCasinoDenied($hbHubUser) === null) {
    $response['wallet'] = hbHubCasinoStatus($db, $userId);
}
if (($_GET['messages'] ?? null) === '1') {
    $response['messages'] = hbHubMessageNotifications($db, $userId, $userSettings, $_GET['after'] ?? null);
}

echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
