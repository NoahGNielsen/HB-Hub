<?php
// The user's own attachments, for the Your attachments popup in the chat (chat.js). JSON only.
//   GET                              -> { ticketsTotal, ticketsFree, attachments: [{ id, name, size, tickets, expires, chat }] }
//   POST action=delete&attachment=id -> the same, after deleting that one: its file goes, its tickets are free again,
//                                       and its message stays, showing "Attachment deleted"
// Only attachments whose file is still there are listed (and take tickets), newest first.
// size is for people ("4.3 MB"), expires is the date the tickets run out, and chat is the chat it was sent in
// ("Group name", or "your chat with Name" for a DM).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chat.php'; // the kinds of chat (and chatAttachments.php)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attachmentId = $_POST['attachment'] ?? null;
    if (($_POST['action'] ?? null) !== 'delete' || !is_string($attachmentId) || !ctype_digit($attachmentId) || strlen($attachmentId) > 10) {
        http_response_code(400);
        echo json_encode(['error' => 'badRequest']);
        exit;
    }
    hbHubChatAttachmentsRemove($db, 'a.attachmentId = ? AND a.userId = ?', [(int) $attachmentId, $userId]);
}

hbHubChatAttachmentsSweep($db);

// The chat's name, or for one without a name (DMs), the other members' names - like the chat list, minus nicknames
$statement = $db->prepare('SELECT a.attachmentId, a.attachmentName, a.attachmentSize, a.attachmentTickets, a.attachmentExpiresTimestamp,
        c.chatType, COALESCE(NULLIF(c.chatName, \'\'), (
            SELECT GROUP_CONCAT(ou.userName ORDER BY ou.userName SEPARATOR \', \')
            FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ?
        ), \'Just you\') AS chatTitle
    FROM `HBHub-ChatAttachments` a JOIN `HBHub-Chats` c ON c.chatId = a.chatId
    WHERE a.userId = ? AND a.attachmentStatus = ? AND a.attachmentExpiresTimestamp > NOW()
    ORDER BY a.attachmentId DESC');
$statement->execute([$userId, $userId, HBHUB_CHAT_ATTACHMENT_KEPT]);

echo json_encode([
    'ticketsTotal' => hbHubChatAttachmentTicketsTotal($db, $userId),
    'ticketsFree' => hbHubChatAttachmentTicketsFree($db, $userId),
    'attachments' => array_map(fn (array $attachment): array => [
        'id' => (int) $attachment['attachmentId'],
        'name' => $attachment['attachmentName'],
        'size' => hbHubChatAttachmentSizeLabel((int) $attachment['attachmentSize']),
        'tickets' => (int) $attachment['attachmentTickets'],
        'expires' => date('d/m/Y', strtotime($attachment['attachmentExpiresTimestamp'])),
        'chat' => ((int) $attachment['chatType'] === HBHUB_CHAT_TYPE_DM ? 'your chat with ' : '') . $attachment['chatTitle'],
    ], $statement->fetchAll()),
], JSON_INVALID_UTF8_SUBSTITUTE);
