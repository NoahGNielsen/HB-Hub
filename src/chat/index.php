<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_CHAT_TYPE_DM = 1; // chatType in HBHub-Chats
const HBHUB_CHAT_TYPE_GROUP = 2;
const HBHUB_CHAT_STATUS_ACTIVE = 1; // chatStatus in HBHub-Chats
const HBHUB_CHAT_ROLE_MEMBER = 1; // memberRole in HBHub-ChatMembers
const HBHUB_CHAT_ROLE_ADMIN = 2;
const HBHUB_CHAT_MEMBERS_MAX = 200; // per group DM, the creator included
const HBHUB_CHAT_NAME_MAX = 64; // chatName column
const HBHUB_CHAT_MESSAGE_MAX = 2500; // messageContent column
const HBHUB_CHAT_MESSAGES_SHOWN = 100;

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

/**
 * The chat with the id, or null when it doesn't exist, isn't active or the user isn't a member of it.
 * A chat the user hasn't accepted yet (a message request) only counts when $isRequest is true,
 * and then it's the only kind that counts.
 *
 * @return ?array{chatId: int, memberMutedUntil: ?string}
 */
function chatMembership(PDO $db, int $userId, mixed $chatId, bool $isRequest = false): ?array
{
    if (!is_scalar($chatId) || !ctype_digit((string) $chatId)) {
        return null;
    }

    $statement = $db->prepare('SELECT c.chatId, cm.memberMutedUntil
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.chatId = ? AND cm.userId = ? AND c.chatStatus = ? AND cm.userAcknowledgedJoin = ?');
    $statement->execute([(int) $chatId, $userId, HBHUB_CHAT_STATUS_ACTIVE, $isRequest ? 0 : 1]);
    return $statement->fetch() ?: null;
}

/**
 * Starts a chat with the picked users: a DM for one, a group DM for more.
 * A DM that already exists with that user is reused instead of making a second one.
 * Returns the chat id, or null when the picked users aren't valid (none, too many, unknown or banned).
 */
function chatCreate(PDO $db, int $userId, mixed $pickedIds, mixed $name): ?int
{
    if (!is_array($pickedIds)) {
        return null;
    }

    $memberIds = [];
    foreach ($pickedIds as $pickedId) {
        if (is_string($pickedId) && ctype_digit($pickedId) && (int) $pickedId !== $userId) {
            $memberIds[(int) $pickedId] = true;
        }
    }
    $memberIds = array_keys($memberIds);

    if ($memberIds === [] || count($memberIds) + 1 > HBHUB_CHAT_MEMBERS_MAX) {
        return null;
    }

    // Every picked user has to exist and not be banned
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users`
        WHERE userStatus <> ? AND userId IN (' . implode(', ', array_fill(0, count($memberIds), '?')) . ')');
    $statement->execute([HBHUB_SESSION_STATUS_BANNED, ...$memberIds]);
    if ((int) $statement->fetchColumn() !== count($memberIds)) {
        return null;
    }

    $isGroup = count($memberIds) > 1;

    if (!$isGroup) {
        $statement = $db->prepare('SELECT c.chatId FROM `HBHub-Chats` c
            JOIN `HBHub-ChatMembers` me ON me.chatId = c.chatId AND me.userId = ?
            JOIN `HBHub-ChatMembers` them ON them.chatId = c.chatId AND them.userId = ?
            WHERE c.chatType = ? AND c.chatStatus = ? LIMIT 1');
        $statement->execute([$userId, $memberIds[0], HBHUB_CHAT_TYPE_DM, HBHUB_CHAT_STATUS_ACTIVE]);
        $existingChatId = $statement->fetchColumn();
        if ($existingChatId !== false) {
            // Starting a DM with someone who already sent you a request accepts it
            $db->prepare('UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1 WHERE chatId = ? AND userId = ?')
                ->execute([$existingChatId, $userId]);
            return (int) $existingChatId;
        }
    }

    // Only groups get a name - left empty, they're named after the members
    $chatName = $isGroup && is_string($name) ? trim($name) : '';
    $chatName = $chatName === '' ? null : mb_substr($chatName, 0, HBHUB_CHAT_NAME_MAX);

    $db->beginTransaction();
    $db->prepare('INSERT INTO `HBHub-Chats` (chatName, chatType, chatCreatedBy) VALUES (?, ?, ?)')
        ->execute([$chatName, $isGroup ? HBHUB_CHAT_TYPE_GROUP : HBHUB_CHAT_TYPE_DM, $userId]);
    $chatId = (int) $db->lastInsertId();

    // The creator is the group's admin. In a DM both are normal members.
    // Everyone but the creator gets the chat as a message request they have to accept first.
    $memberRows = [$chatId, $userId, $isGroup ? HBHUB_CHAT_ROLE_ADMIN : HBHUB_CHAT_ROLE_MEMBER, 1];
    foreach ($memberIds as $memberId) {
        array_push($memberRows, $chatId, $memberId, HBHUB_CHAT_ROLE_MEMBER, 0);
    }
    $db->prepare('INSERT INTO `HBHub-ChatMembers` (chatId, userId, memberRole, userAcknowledgedJoin)
        VALUES ' . implode(', ', array_fill(0, count($memberIds) + 1, '(?, ?, ?, ?)')))
        ->execute($memberRows);
    $db->commit();

    return $chatId;
}

// Starting a new chat, then straight into it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'create') {
    $chatId = chatCreate($db, $userId, $_POST['users'] ?? null, $_POST['name'] ?? null);
    header('Location: /chat/' . ($chatId !== null ? '?chat=' . $chatId : ''), true, 303);
    exit;
}

// Answering a message request: accepting opens the chat, declining leaves it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? null, ['accept', 'decline'], true)) {
    $chat = chatMembership($db, $userId, $_POST['chat'] ?? null, true);
    $isAccepted = $_POST['action'] === 'accept';

    if ($chat !== null) {
        $db->prepare($isAccepted
            ? 'UPDATE `HBHub-ChatMembers` SET userAcknowledgedJoin = 1 WHERE chatId = ? AND userId = ?'
            : 'DELETE FROM `HBHub-ChatMembers` WHERE chatId = ? AND userId = ?')
            ->execute([$chat['chatId'], $userId]);
    }

    header('Location: /chat/' . ($chat !== null && $isAccepted ? '?chat=' . $chat['chatId'] : ''), true, 303);
    exit;
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
$statement = $db->prepare('SELECT c.chatId, c.chatType, cm.userAcknowledgedJoin,
        COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp) AS chatActivity,
        (SELECT cu.userName FROM `HBHub-Users` cu WHERE cu.userId = c.chatCreatedBy) AS chatCreatorName,
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
$chats = [];
$chatRequests = []; // chats the user was added to but hasn't accepted yet
foreach ($statement->fetchAll() as $chat) {
    $chat['chatTitle'] ??= 'Just you';
    if ($chat['userAcknowledgedJoin']) {
        $chats[] = $chat;
    } else {
        $chatRequests[] = $chat;
    }
}

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

            <div class="chat-actions">
                <button type="button" class="chat-new-btn" data-new-chat-open>+ New chat</button>
                <?php if ($chatRequests !== []): ?>
                    <button type="button" class="chat-requests-btn" data-requests-open
                            aria-label="Message requests (<?= count($chatRequests) ?>)" title="Message requests">
                        <img src="/assets/images/icons/notification.png" alt="">
                        <span class="chat-requests-count" aria-hidden="true"><?= count($chatRequests) > 9 ? '9+' : count($chatRequests) ?></span>
                    </button>
                <?php endif; ?>
            </div>

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
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="chat" value="<?= $openChat['chatId'] ?>">
                    <label class="chat-visually-hidden" for="chat-message">Message</label>
                    <textarea id="chat-message" name="message" rows="1" maxlength="<?= HBHUB_CHAT_MESSAGE_MAX ?>" required
                              placeholder="Message <?= htmlspecialchars($openChat['chatTitle']) ?>" autofocus></textarea>
                    <button type="submit" class="chat-send-btn">Send</button>
                </form>
            <?php endif; ?>
        </section>

        <!-- New chat popup (chat.js): one user picked makes a DM, more make a group DM -->
        <dialog class="chat-new" aria-labelledby="chat-new-title" data-members-max="<?= HBHUB_CHAT_MEMBERS_MAX ?>">
            <form class="chat-new-form" action="/chat/" method="post">
                <input type="hidden" name="action" value="create">

                <header class="chat-new-header">
                    <h2 id="chat-new-title">New chat</h2>
                    <button type="button" class="chat-new-close" aria-label="Close" data-new-chat-close>&times;</button>
                </header>

                <label class="chat-visually-hidden" for="chat-new-search">Search users</label>
                <input type="search" id="chat-new-search" placeholder="Search users" autocomplete="off">

                <ul class="chat-new-picked" aria-label="Picked users" data-picked></ul>

                <ul class="chat-new-users" aria-label="Users" data-users></ul>
                <p class="chat-new-status" role="status" data-users-status></p>

                <div class="chat-new-group" hidden data-group-fields>
                    <label for="chat-new-name">Group name <span class="chat-new-optional">(optional)</span></label>
                    <input type="text" id="chat-new-name" name="name" maxlength="<?= HBHUB_CHAT_NAME_MAX ?>" autocomplete="off">
                </div>

                <footer class="chat-new-footer">
                    <p class="chat-new-count" data-picked-count></p>
                    <button type="button" class="chat-btn" data-new-chat-close>Cancel</button>
                    <button type="submit" class="chat-new-btn" disabled data-new-chat-submit>Start DM</button>
                </footer>
            </form>
        </dialog>

        <!-- Message requests popup (chat.js): chats the user was added to, to accept or decline (leave) -->
        <?php if ($chatRequests !== []): ?>
            <dialog class="chat-requests" aria-labelledby="chat-requests-title">
                <div class="chat-requests-body">
                    <header class="chat-new-header">
                        <h2 id="chat-requests-title">Message requests</h2>
                        <button type="button" class="chat-new-close" aria-label="Close" data-requests-close>&times;</button>
                    </header>

                    <ul class="chat-requests-list">
                        <?php foreach ($chatRequests as $request): ?>
                            <?php $isGroup = (int) $request['chatType'] !== HBHUB_CHAT_TYPE_DM; ?>
                            <li class="chat-request">
                                <span class="chat-avatar" aria-hidden="true"><?= htmlspecialchars(chatInitial($request['chatTitle'])) ?></span>
                                <span class="chat-list-text">
                                    <span class="chat-list-top">
                                        <span class="chat-list-title"><?= htmlspecialchars($request['chatTitle']) ?></span>
                                        <span class="chat-list-time"><?= chatTime($request['chatActivity']) ?></span>
                                    </span>
                                    <span class="chat-list-preview">
                                        <?= $isGroup ? 'Group, added by ' . htmlspecialchars($request['chatCreatorName'] ?? 'someone') : 'Wants to send you messages' ?>
                                    </span>
                                    <?php if ($request['chatLastMessage'] !== null): ?>
                                        <span class="chat-list-preview chat-request-message"><?= htmlspecialchars($request['chatLastMessage']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <form class="chat-request-answer" action="/chat/" method="post">
                                    <input type="hidden" name="chat" value="<?= $request['chatId'] ?>">
                                    <button type="submit" class="chat-btn" name="action" value="decline">Decline</button>
                                    <button type="submit" class="chat-new-btn" name="action" value="accept">Accept</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </dialog>
        <?php endif; ?>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
