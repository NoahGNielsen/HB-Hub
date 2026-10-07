<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_CHAT_TYPE_DM = 1; // chatType in HBHub-Chats
const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_CHAT_MESSAGE_MAX = 2500; // messageContent column
const HBHUB_CHAT_MESSAGES_SHOWN = 100;

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

/**
 * The chat with the id, or null when it doesn't exist, isn't active or the user isn't a member of it.
 *
 * @return ?array{chatId: int, memberMutedUntil: ?string}
 */
function chatMembership(PDO $db, int $userId, mixed $chatId): ?array
{
    if (!is_scalar($chatId) || !ctype_digit((string) $chatId)) {
        return null;
    }

    $statement = $db->prepare('SELECT c.chatId, cm.memberMutedUntil
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ?');
    $statement->execute([(int) $chatId, $userId, HBHUB_CHAT_STATUS_ACTIVE]);
    return $statement->fetch() ?: null;
}

// Sending a message, then back to the chat so a refresh doesn't send it again
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null);
    $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
    $isMuted = $chat !== null && $chat['memberMutedUntil'] !== null && strtotime($chat['memberMutedUntil']) > time();

    if ($chat !== null && !$isMuted && $message !== '' && mb_strlen($message) <= HBHUB_CHAT_MESSAGE_MAX) {
        $db->beginTransaction();
        $db->prepare('INSERT INTO `HBHub-Messages` (userId, chatId, messageContent) VALUES (?, ?, ?)')
            ->execute([$userId, $chat['chatId'], $message]);
        $db->prepare('UPDATE `HBHub-Chats` SET lastMessageTimestamp = NOW() WHERE chatId = ?')
            ->execute([$chat['chatId']]);
        $db->commit();
    }

    header('Location: /chat/' . ($chat !== null ? '?chat=' . $chat['chatId'] : ''), true, 303);
    exit;
}

// Every chat the user is in, newest activity first.
// DMs (and groups without a name) are named after the other members.
$statement = $db->prepare('SELECT c.chatId, c.chatType,
        COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp) AS chatActivity,
        COALESCE(NULLIF(c.chatName, \'\'), (
            SELECT GROUP_CONCAT(COALESCE(om.memberNickname, ou.userName) ORDER BY ou.userName SEPARATOR \', \')
            FROM `HBHub-ChatMembers` om JOIN `HBHub-Users` ou ON ou.userId = om.userId
            WHERE om.chatId = c.chatId AND om.userId <> ?
        )) AS chatTitle,
        (
            SELECT m.messageContent FROM `HBHub-Messages` m
            WHERE m.chatId = c.chatId AND m.messageDeletedTimestamp IS NULL
            ORDER BY m.messageId DESC LIMIT 1
        ) AS chatLastMessage
    FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
    WHERE cm.userId = ? AND c.chatStatus = ?
    ORDER BY chatActivity DESC');
$statement->execute([$userId, $userId, HBHUB_CHAT_STATUS_ACTIVE]);
$chats = $statement->fetchAll();

foreach ($chats as &$chat) {
    $chat['chatTitle'] ??= 'Just you';
}
unset($chat);

// Without JS the search bar submits ?q= and the list is filtered here instead
$chatSearch = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
foreach ($chats as &$chat) {
    $chat['chatHidden'] = $chatSearch !== '' && mb_stripos($chat['chatTitle'], $chatSearch) === false;
}
unset($chat);
$chatSearchHasMatches = in_array(false, array_column($chats, 'chatHidden'), true);

// The chat shown on the right, if any
$openChat = null;
$messages = [];
$openChatId = $_GET['chat'] ?? null;
foreach ($chats as $chat) {
    if (is_string($openChatId) && (string) $chat['chatId'] === $openChatId) {
        $openChat = $chat;
        break;
    }
}

if ($openChat !== null) {
    // The newest messages, oldest at the top
    $statement = $db->prepare('SELECT * FROM (
            SELECT m.messageId, m.userId, m.messageContent, m.attachmentId, m.messageSentTimestamp,
                m.messageDeletedTimestamp, m.messageIsEdited, COALESCE(cm.memberNickname, u.userName) AS senderName
            FROM `HBHub-Messages` m
            JOIN `HBHub-Users` u ON u.userId = m.userId
            LEFT JOIN `HBHub-ChatMembers` cm ON cm.chatId = m.chatId AND cm.userId = m.userId
            WHERE m.chatId = ?
            ORDER BY m.messageId DESC LIMIT ' . HBHUB_CHAT_MESSAGES_SHOWN . '
        ) newest ORDER BY messageId ASC');
    $statement->execute([$openChat['chatId']]);
    $messages = $statement->fetchAll();
}

/**
 * Short time for the chat list and messages: just the time for today, otherwise the date.
 */
function chatTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    return date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : date('d/m/Y', $time);
}

function chatInitial(string $name): string
{
    return mb_strtoupper(mb_substr($name, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chat.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chat.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chat.js') ?>" defer></script>
    <title><?= $openChat !== null ? htmlspecialchars($openChat['chatTitle']) . ' - ' : '' ?>Chat - HB Hub</title>
    <meta name="description" content="Chat page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="chat<?= $openChat !== null ? ' has-open-chat' : '' ?>">
        <h1 class="chat-visually-hidden">Chat</h1>

        <!-- Left: search, new chat and every chat the user is in -->
        <aside class="chat-sidebar" aria-label="Your chats">
            <form class="chat-search" action="/chat/" method="get" role="search">
                <label class="chat-visually-hidden" for="chat-search">Search chats</label>
                <input type="search" id="chat-search" name="q" placeholder="Search chats" autocomplete="off"
                       value="<?= htmlspecialchars($chatSearch) ?>">
            </form>

            <button type="button" class="chat-new-btn">+ New chat</button>

            <h2 class="chat-list-heading">Open chats</h2>
            <ul class="chat-list">
                <?php foreach ($chats as $chat): ?>
                    <li data-chat-title="<?= htmlspecialchars($chat['chatTitle']) ?>"<?= $chat['chatHidden'] ? ' hidden' : '' ?>>
                        <a class="chat-list-item<?= $chat === $openChat ? ' active' : '' ?>" href="/chat/?chat=<?= $chat['chatId'] ?>"<?= $chat === $openChat ? ' aria-current="page"' : '' ?>>
                            <span class="chat-avatar" aria-hidden="true"><?= htmlspecialchars(chatInitial($chat['chatTitle'])) ?></span>
                            <span class="chat-list-text">
                                <span class="chat-list-top">
                                    <span class="chat-list-title"><?= htmlspecialchars($chat['chatTitle']) ?></span>
                                    <span class="chat-list-time"><?= chatTime($chat['chatActivity']) ?></span>
                                </span>
                                <span class="chat-list-preview"><?= htmlspecialchars($chat['chatLastMessage'] ?? 'No messages yet') ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="chat-list-empty"<?= $chats !== [] ? ' hidden' : '' ?> data-empty-all>You don't have any chats yet.</p>
            <p class="chat-list-empty"<?= $chats === [] || $chatSearchHasMatches ? ' hidden' : '' ?> data-empty-search>No chats match your search.</p>
        </aside>

        <!-- Right: the open chat -->
        <section class="chat-window" aria-label="<?= $openChat !== null ? htmlspecialchars($openChat['chatTitle']) : 'No chat open' ?>">
            <?php if ($openChat === null): ?>
                <div class="chat-placeholder">
                    <img class="chat-placeholder-icon" src="/assets/images/icons/chat.png" alt="">
                    <p>Pick a chat on the left, or start a new one.</p>
                </div>
            <?php else: ?>
                <header class="chat-window-header">
                    <a class="chat-back" href="/chat/" aria-label="Back to chats">&larr;</a>
                    <span class="chat-avatar" aria-hidden="true"><?= htmlspecialchars(chatInitial($openChat['chatTitle'])) ?></span>
                    <h2 class="chat-window-title"><?= htmlspecialchars($openChat['chatTitle']) ?></h2>
                </header>

                <ol class="chat-messages" data-chat-messages>
                    <?php if ($messages === []): ?>
                        <li class="chat-messages-empty">No messages yet - say hi!</li>
                    <?php endif; ?>
                    <?php foreach ($messages as $message): ?>
                        <?php $isOwn = (int) $message['userId'] === $userId; ?>
                        <li class="chat-message<?= $isOwn ? ' is-own' : '' ?>">
                            <?php if (!$isOwn && (int) $openChat['chatType'] !== HBHUB_CHAT_TYPE_DM): ?>
                                <span class="chat-message-sender"><?= htmlspecialchars($message['senderName']) ?></span>
                            <?php endif; ?>
                            <div class="chat-message-bubble">
                                <?php if ($message['messageDeletedTimestamp'] !== null): ?>
                                    <p class="chat-message-deleted">Message deleted</p>
                                <?php else: ?>
                                    <?php if ($message['messageContent'] !== null): ?>
                                        <p><?= nl2br(htmlspecialchars($message['messageContent'])) ?></p>
                                    <?php elseif ($message['attachmentId'] !== null): ?>
                                        <p class="chat-message-deleted">Attachment</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <span class="chat-message-meta">
                                <time datetime="<?= htmlspecialchars($message['messageSentTimestamp']) ?>"><?= chatTime($message['messageSentTimestamp']) ?></time>
                                <?= $message['messageIsEdited'] && $message['messageDeletedTimestamp'] === null ? ' &middot; edited' : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <form class="chat-composer" action="/chat/" method="post">
                    <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                    <label class="chat-visually-hidden" for="chat-message">Message</label>
                    <textarea id="chat-message" name="message" rows="1" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required
                              placeholder="Message <?= htmlspecialchars($openChat['chatTitle']) ?>" autofocus></textarea>
                    <button type="submit" class="chat-send-btn">Send</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
