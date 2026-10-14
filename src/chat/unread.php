<?php
// The number on Chat in the side bar, for chatUnread.js: /chat/unread returns, as JSON, how many chats have unread
// messages (see assets/php/chatUnread.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatUnread.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'unreadChats' => hbHubUnreadChatCount(hbHubDatabase(), (int) $hbHubUser['userId'], hbHubUserSettings($hbHubUser['userSettings'])),
]);
