<?php
// Chat info in the chat menu (chat.js): /chat/info?chat=12 returns some basic info about a chat the user is in, as JSON:
// {type: "dm"|"group"|"class", created: "d/m/Y", createdBy: name or null, members: count, messages: count,
//  joined: "d/m/Y", role: "owner"|"admin"|"member"|null}
// createdBy is null for a class chat (the HB Hub System account made it), role is null in a DM.
// Like the chat list, ghost invites and other people's ghost messages aren't counted (see the top of assets/php/chat.php),
// except for a ghost-banned user, to whom their own look like everyone else's.
// 404 when the chat doesn't exist, isn't active or the user isn't in it (or hasn't accepted it yet).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$chatId = $_GET['chat'] ?? null;
if (!is_string($chatId) || !ctype_digit($chatId) || strlen($chatId) > 10) {
    http_response_code(404);
    echo json_encode(['error' => 'chatNotFound']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isGhost = (bool) $hbHubUser['userGhostBanned'];

// The creator's name as the user sees it (their nickname in a group), also when they've left since
$statement = $db->prepare('SELECT c.chatType, c.chatClassKey, c.chatCreatedTimestamp, cm.memberRole, cm.memberJoinedTimestamp,
        ' . chatMemberNameSql('creatorMember', 'creator', $userId) . ' AS creatorName,
        (
            SELECT COUNT(*) FROM `HBHub-ChatMembers` om
            WHERE om.chatId = c.chatId' . ($isGhost ? '' : ' AND om.memberIsGhost = 0') . '
        ) AS memberCount,
        (
            SELECT COUNT(*) FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageDeletedTimestamp IS NULL AND m.messageEvent = 0
                AND ' . chatGhostVisibleSql('m', 'messageIsGhost', $userId) . '
        ) AS messageCount
    FROM `HBHub-ChatMembers` cm
    JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
    LEFT JOIN `HBHub-Users` creator ON creator.userId = c.chatCreatedBy
    LEFT JOIN `HBHub-ChatMembers` creatorMember ON creatorMember.chatId = c.chatId AND creatorMember.userId = c.chatCreatedBy
    WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND cm.userAcknowledgedJoin = 1 AND cm.memberIsGhost = 0');
$statement->execute([(int) $chatId, $userId, HBHUB_CHAT_STATUS_ACTIVE]);
$chat = $statement->fetch();
if (!$chat) {
    http_response_code(404);
    echo json_encode(['error' => 'chatNotFound']);
    exit;
}

$isDm = (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM;
$isClass = $chat['chatClassKey'] !== null;
echo json_encode([
    'type' => $isDm ? 'dm' : ($isClass ? 'class' : 'group'),
    'created' => date('d/m/Y', strtotime($chat['chatCreatedTimestamp'])),
    'createdBy' => $isClass ? null : ($chat['creatorName'] ?? 'Deleted User'),
    'members' => (int) $chat['memberCount'],
    'messages' => (int) $chat['messageCount'],
    'joined' => date('d/m/Y', strtotime($chat['memberJoinedTimestamp'])),
    'role' => $isDm ? null : match ((int) $chat['memberRole']) {
        HBHUB_CHAT_ROLE_OWNER => 'owner',
        HBHUB_CHAT_ROLE_ADMIN => 'admin',
        default => 'member',
    },
], JSON_INVALID_UTF8_SUBSTITUTE);
