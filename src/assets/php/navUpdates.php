<?php
// What the nav bar keeps up to date (navUpdates.js): the numbers on the bell and on Chat, the casino chips and the new
// messages for browser notifications. /nav-updates (otherPages/navUpdates.php) answers with it on its own, and the pages
// that ask their own server every few seconds anyway take it along with theirs (hbHubNavRide()), so the nav bar doesn't
// cost one of the hosting's ~10 PHP requests of its own there: the chat's polling, the game tables and the lobbies.
// Needs session.php (included first by every page anyway).
require_once __DIR__ . '/casino/access.php';
require_once __DIR__ . '/casino/wallet.php';
require_once __DIR__ . '/chatUnread.php';
require_once __DIR__ . '/messageNotifications.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/userSettings.php';

const HBHUB_NAV_RIDE_MAX = 200; // characters in ?nav=, which only ever holds a few short fields

/**
 * The nav bar's answer, from the fields navUpdates.js collected on nav:poll:
 * - unread: unread notifications, the number on the bell (notificationBell.js)
 * - unreadChats: chats with unread messages, the number on Chat in the side bar (chatUnread.js)
 * - with wallet=1 (casino pages, casino.js): wallet, the chips and daily chips like /gambling/chips sends them.
 *   Left out while the user can't use the casino (blocked in settings, or not open to them).
 * - with messages=1 (when browser notifications or the message sound are on, notifications.js): messages, the new
 *   messages to notify about, after the message id in after= (see messageNotifications.php)
 *
 * @param array $user $hbHubUser from session.php
 * @param array $params the fields, like $_GET
 * @return array{unread: int, unreadChats: int, wallet?: array, messages?: array}
 */
function hbHubNavUpdates(PDO $db, array $user, array $params): array
{
    $userId = (int) $user['userId'];
    $userSettings = hbHubUserSettings($user['userSettings']);

    $response = [
        'unread' => hbHubUnreadNotificationCount($db, $userId),
        'unreadChats' => hbHubUnreadChatCount($db, $userId, $userSettings),
    ];
    if (($params['wallet'] ?? null) === '1' && hbHubCasinoDenied($user) === null) {
        $response['wallet'] = hbHubCasinoStatus($db, $userId);
    }
    if (($params['messages'] ?? null) === '1') {
        $response['messages'] = hbHubMessageNotifications($db, $userId, $userSettings, $params['after'] ?? null);
    }
    return $response;
}

/**
 * The nav bar's answer for a page's own polling, when navUpdates.js sent it along (?nav=, its fields as a query
 * string: "wallet=1&messages=1&after=123", or empty for just the numbers). Null when it didn't, which is most of the
 * time: it comes along once every 15 seconds. Only on GET, the polling - never on a POST that does something.
 * Add the answer to the JSON as "nav", and the page's script passes it on as nav:update.
 */
function hbHubNavRide(PDO $db, array $user): ?array
{
    $query = $_GET['nav'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !is_string($query) || strlen($query) > HBHUB_NAV_RIDE_MAX) {
        return null;
    }
    parse_str($query, $params);
    return hbHubNavUpdates($db, $user, $params);
}
