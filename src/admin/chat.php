<?php
// Admin panel for one chat: /admin/chat?chat=id. Site admins open it from the list of chats on the admin panel's start
// page (admin/index.php), from a user's chats and messages in their admin panel (admin/user.php), and from "Admin panel"
// in the chat menu on a group (chat.js). Everyone else gets a 404, so it doesn't show that the page is here.
//
// Shows the chat, its members, and its messages with the notices about changes to it, newest first. The actions are one
// form per card, like admin/user.php: change a group's name, remove its icon, change or reset the background, delete
// the chat, kick members and delete messages. Each change shows up in the chat as made by "A site admin" (chat.php),
// and someone kicked gets a notification that says the same. Like in admin/user.php, site admins can't be kicked and
// their messages can't be deleted here. A deleted chat can only be looked at.
// The side menu (adminPageMenuHtml()) links to the cards in the order they're on the page: Information, then Manage
// (name and icon, background), Activity (members, messages) and the Danger zone (delete) last.
// Opening the page and every change are logged in the admin log (assets/php/adminLog.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

adminOnly($hbHubUser);

$chatId = $_GET['chat'] ?? null;
if (!is_string($chatId) || !ctype_digit($chatId) || strlen($chatId) > 10) {
    adminNotFound();
}
$chatId = (int) $chatId;
$adminId = (int) $hbHubUser['userId'];

$db = hbHubDatabase();
$statement = $db->prepare('SELECT c.chatId, c.chatName, c.chatType, c.chatStatus, c.chatCreatedBy, cu.userName AS creatorName,
        c.chatSettings, c.chatIconAttachmentId, c.chatCreatedTimestamp, c.lastMessageTimestamp, c.chatBannedTimestamp,
        ' . adminChatTitleSql() . ' AS chatTitle
    FROM `HBHub-Chats` c LEFT JOIN `HBHub-Users` cu ON cu.userId = c.chatCreatedBy
    WHERE c.chatId = ?');
$statement->execute([$chatId]);
$chat = $statement->fetch();
if ($chat === false) {
    adminNotFound();
}

$adminIsActive = (int) $chat['chatStatus'] === HBHUB_CHAT_STATUS_ACTIVE;
$adminIsGroup = (int) $chat['chatType'] === HBHUB_CHAT_TYPE_GROUP;

/**
 * Everyone in the chat, invited too: the owner and admins first, then the ones who accepted, then by name.
 */
function adminChatMembers(PDO $db, int $chatId): array
{
    $statement = $db->prepare('SELECT cm.userId, u.userName, u.userRole, u.userStatus, u.userAvatarAttachmentId,
            cm.memberNickname, cm.memberRole, cm.userAcknowledgedJoin, cm.memberJoinedTimestamp
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Users` u ON u.userId = cm.userId
        WHERE cm.chatId = ?
        ORDER BY cm.memberRole DESC, cm.userAcknowledgedJoin DESC, u.userName');
    $statement->execute([$chatId]);
    return $statement->fetchAll();
}

/**
 * The chat's messages and notices, newest first. With $beforeId only the ones older than that message.
 * The names are called what chatNoticeHtml() looks for (senderName and eventUserName), the real names here.
 */
function adminChatMessages(PDO $db, int $chatId, ?int $beforeId, int $limit): array
{
    $statement = $db->prepare('SELECT m.messageId, m.userId, u.userName AS senderName, u.userRole AS senderRole,
            m.messageContent, m.attachmentId, m.messageSentTimestamp, m.messageDeletedTimestamp, m.messageDeletedByAdmin,
            m.messageIsEdited, m.messageEvent, m.messageEventType, m.messageEventUserId, eu.userName AS eventUserName
        FROM `HBHub-Messages` m
        JOIN `HBHub-Users` u ON u.userId = m.userId
        LEFT JOIN `HBHub-Users` eu ON eu.userId = m.messageEventUserId
        WHERE m.chatId = ?' . ($beforeId !== null ? ' AND m.messageId < ?' : '') . '
        ORDER BY m.messageId DESC LIMIT ' . $limit);
    $statement->execute($beforeId !== null ? [$chatId, $beforeId] : [$chatId]);
    return $statement->fetchAll();
}

/**
 * Back to the chat's admin panel (at the card that was used) with a toast, so reloading doesn't send the form again.
 * $query is anything else to keep in the address, like the page of messages.
 */
function adminChatRedirect(int $chatId, ?string $toast, string $card, string $query = ''): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    if ($toast !== null) {
        hbHubSetToast($toast);
    }
    header('Location: /admin/chat?chat=' . $chatId . $query . '#' . $card, true, 303);
    exit;
}

// Which page of messages is shown: ?before= is the oldest message on the page before
$adminMessagesBefore = is_string($_GET['before'] ?? null) && ctype_digit($_GET['before']) && strlen($_GET['before']) <= 19
    ? (int) $_GET['before'] : null;

$adminErrors = []; // card => message

$adminAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;
if ($adminAction !== null && !$adminIsActive) {
    adminChatRedirect($chatId, null, 'overview'); // the forms aren't on the page, so nothing to say
}

switch ($adminAction) {
    case 'rename':
        // Only groups have a name of their own. Left empty, it's named after its members again.
        if (!$adminIsGroup) {
            adminChatRedirect($chatId, null, 'overview');
        }
        if (chatRename($db, $adminId, $chatId, $_POST['name'] ?? null, true)) {
            adminLog($db, $adminId, 'chat.rename', null, $chatId);
        }
        adminChatRedirect($chatId, 'adminSaved', 'group');

    case 'removeIcon':
        if (chatSetIcon($db, $chatId, null)) {
            chatNotice($db, $adminId, $chatId, HBHUB_CHAT_EVENT_ICON_REMOVE, null, null, true);
            adminLog($db, $adminId, 'chat.icon', null, $chatId);
        }
        adminChatRedirect($chatId, 'adminSaved', 'group');

    case 'background':
        // Reset goes back to the default background
        $isResetting = ($_POST['remove'] ?? null) === '1';
        $background = $isResetting ? null : chatBackgroundOf($_POST['color1'] ?? null, $_POST['color2'] ?? null, $_POST['angle'] ?? null);
        if (!$isResetting && $background === null) {
            $adminErrors['background'] = 'Please pick two colors and a direction.';
            break;
        }
        if (chatSetBackground($db, $chatId, $background)) {
            chatNotice($db, $adminId, $chatId, $isResetting ? HBHUB_CHAT_EVENT_BACKGROUND_RESET : HBHUB_CHAT_EVENT_BACKGROUND, null, null, true);
            adminLog($db, $adminId, 'chat.background', null, $chatId, ['reset' => $isResetting]);
        }
        adminChatRedirect($chatId, 'adminSaved', 'background');

    case 'kick':
        // Like Kick in the group's Members popup: someone who hasn't accepted yet has their invite taken back (and its
        // notification), and a group without its owner gets a new one (chatLeave()). Only from groups, and not site admins.
        $memberId = $_POST['member'] ?? null;
        $member = false;
        if ($adminIsGroup && is_string($memberId) && ctype_digit($memberId) && strlen($memberId) <= 10) {
            $statement = $db->prepare('SELECT cm.userId, cm.userAcknowledgedJoin FROM `HBHub-ChatMembers` cm
                JOIN `HBHub-Users` u ON u.userId = cm.userId
                WHERE cm.chatId = ? AND cm.userId = ? AND NOT (u.userRole <=> ?)');
            $statement->execute([$chatId, (int) $memberId, ADMIN_ROLE_ADMIN]);
            $member = $statement->fetch();
        }
        if ($member === false) {
            adminChatRedirect($chatId, null, 'members');
        }

        chatLeave($db, (int) $member['userId'], $chat, $adminId, true); // posts the notice itself
        if ($member['userAcknowledgedJoin']) {
            hbHubNotify($db, (int) $member['userId'], 'chat.kickedBySiteAdmin', null, chatNotificationData($db, $chatId));
        } else {
            hbHubRemoveNotifications($db, (int) $member['userId'], 'chat.messageRequest', ['chatId' => $chatId]);
        }
        adminLog($db, $adminId, 'chat.kick', (int) $member['userId'], $chatId, ['invite' => !$member['userAcknowledgedJoin']]);
        adminChatRedirect($chatId, 'adminMemberKicked', 'members');

    case 'deleteMessage':
        // Shows as deleted by a site admin in the chat. The page of messages it was on stays open.
        $messageId = $_POST['message'] ?? null;
        if (is_string($messageId) && ctype_digit($messageId) && strlen($messageId) <= 19) {
            $statement = $db->prepare('UPDATE `HBHub-Messages` m JOIN `HBHub-Users` u ON u.userId = m.userId
                SET m.messageDeletedTimestamp = NOW(), m.messageDeletedByAdmin = ?
                WHERE m.messageId = ? AND m.chatId = ? AND m.messageEvent = 0 AND m.messageDeletedTimestamp IS NULL
                    AND NOT (u.userRole <=> ?)');
            $statement->execute([ADMIN_MESSAGE_DELETED_BY_SITE_ADMIN, (int) $messageId, $chatId, ADMIN_ROLE_ADMIN]);
            if ($statement->rowCount() > 0) {
                hbHubRemoveNotifications($db, null, 'chat.mention', ['messageId' => (int) $messageId]); // whoever it @mentioned
                $statement = $db->prepare('SELECT userId FROM `HBHub-Messages` WHERE messageId = ?');
                $statement->execute([(int) $messageId]);
                adminLog($db, $adminId, 'message.delete', (int) $statement->fetchColumn(), $chatId, ['messageId' => (int) $messageId]);
            }
            // Its attachment goes too (assets/php/chatAttachments.php), giving the sender back its tickets
            hbHubChatAttachmentsRemove($db, 'm.messageId = ? AND m.chatId = ? AND m.messageDeletedTimestamp IS NOT NULL', [(int) $messageId, $chatId]);
        }
        adminChatRedirect($chatId, 'adminMessageDeleted', 'messages', $adminMessagesBefore !== null ? '&before=' . $adminMessagesBefore : '');

    case 'deleteChat':
        // Gone for everyone in it, like when a group admin deletes a group, but marked as done by a site admin
        $db->prepare('UPDATE `HBHub-Chats` SET chatStatus = ?, chatBannedTimestamp = NOW() WHERE chatId = ? AND chatStatus = ?')
            ->execute([HBHUB_CHAT_STATUS_DELETED_BY_SITE_ADMIN, $chatId, HBHUB_CHAT_STATUS_ACTIVE]);
        adminLog($db, $adminId, 'chat.delete', null, $chatId);
        hbHubChatAttachmentsRemove($db, 'a.chatId = ?', [$chatId]); // and its attachments with it
        adminChatRedirect($chatId, 'adminChatDeleted', 'overview');
}

// What the page shows, which is logged: it has all the chat's messages
adminLogView($db, $adminId, 'chat.view', null, $chatId);
$adminBackground = chatBackground(chatSettings($chat['chatSettings']));
// The default background starts the picker at the site's orange going right into purple, like in the chat (chat.js)
[$adminColor1, $adminColor2, $adminAngle] = $adminBackground ?? ['#f2711c', '#6a4bd6', 90];
$adminIconSrc = adminChatIconUrl($chatId, $chat['chatIconAttachmentId']);

$adminMembers = adminChatMembers($db, $chatId);
$adminInvited = count(array_filter($adminMembers, fn (array $member): bool => !$member['userAcknowledgedJoin']));

// One more than is shown, to know whether there are older ones
$adminMessages = adminChatMessages($db, $chatId, $adminMessagesBefore, ADMIN_MESSAGES_SHOWN + 1);
$adminMessagesHasOlder = count($adminMessages) > ADMIN_MESSAGES_SHOWN;
$adminMessages = array_slice($adminMessages, 0, ADMIN_MESSAGES_SHOWN);

$statement = $db->prepare('SELECT COUNT(*) AS total, COUNT(messageDeletedTimestamp) AS deleted
    FROM `HBHub-Messages` WHERE chatId = ? AND messageEvent = 0');
$statement->execute([$chatId]);
$adminMessageCounts = $statement->fetch();

// chatNoticeHtml() shows names like the chat page does for someone outside the chat: their real names, nobody is "you"
$adminNoticeChat = ['chatType' => (int) $chat['chatType'], 'chatNickname' => null];
$adminFormAction = '/admin/chat?chat=' . $chatId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/settingsMenu.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settingsMenu.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title><?= htmlspecialchars($chat['chatTitle']) ?> - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Links to the cards, in the order they're on the page -->
            <?= adminPageMenuHtml('/admin/?list=chats', 'All chats', $adminIconSrc, $chat['chatTitle'], adminStatusHtml(adminChatStatus((int) $chat['chatStatus'])), [
                'Overview' => ['overview' => 'Information'],
                'Manage' => $adminIsActive
                    ? ($adminIsGroup || $adminIconSrc !== null ? ['group' => $adminIsGroup ? 'Name and icon' : 'Icon'] : []) + ['background' => 'Background']
                    : [],
                'Activity' => ['members' => 'Members', 'messages' => 'Messages'],
                ADMIN_MENU_DANGER => $adminIsActive ? ['delete' => 'Delete chat'] : [],
            ]) ?>

            <div class="settings-content">
                <!-- Which chat it is, and what there is to know about it -->
                <section class="settings-card" id="overview" aria-labelledby="admin-name-heading">
                    <div class="admin-header">
                        <?php if ($adminIconSrc !== null): ?>
                            <img class="settings-avatar-preview" src="<?= htmlspecialchars($adminIconSrc) ?>" alt="Icon of <?= htmlspecialchars($chat['chatTitle']) ?>">
                        <?php else: ?>
                            <span class="settings-avatar-preview settings-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($chat['chatTitle'], 0, 1))) ?></span>
                        <?php endif; ?>
                        <div class="admin-header-text">
                            <h1 id="admin-name-heading">
                                <?= htmlspecialchars($chat['chatTitle']) ?>
                                <?= adminStatusHtml(adminChatStatus((int) $chat['chatStatus'])) ?>
                                <span class="settings-status"><?= $adminIsGroup ? 'Group' : 'DM' ?></span>
                            </h1>
                            <div class="admin-header-links">
                                <a class="settings-btn settings-btn-small" href="/admin/log?chat=<?= $chatId ?>">Admin log</a>
                            </div>
                        </div>
                    </div>

                    <hr class="settings-divider">

                    <h2 class="settings-visually-hidden">Information</h2>
                    <dl class="admin-details">
                        <div><dt>Chat ID</dt><dd><?= $chatId ?></dd></div>
                        <div><dt>Type</dt><dd><?= $adminIsGroup ? 'Group' : 'DM' ?></dd></div>
                        <?php if ($adminIsGroup): ?>
                            <div><dt>Name</dt><dd><?= $chat['chatName'] !== null && $chat['chatName'] !== ''
                                ? htmlspecialchars($chat['chatName']) : '<span class="admin-muted">Named after its members</span>' ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Made by</dt><dd><?= $chat['creatorName'] !== null
                            ? '<a class="admin-link" href="/admin/user?user=' . (int) $chat['chatCreatedBy'] . '">' . htmlspecialchars($chat['creatorName']) . '</a>' : '–' ?></dd></div>
                        <div><dt>Made</dt><dd><?= adminTime($chat['chatCreatedTimestamp']) ?></dd></div>
                        <div><dt>Last message</dt><dd><?= $chat['lastMessageTimestamp'] !== null ? adminTime($chat['lastMessageTimestamp']) : 'No messages yet' ?></dd></div>
                        <?php if ((int) $chat['chatStatus'] === HBHUB_CHAT_STATUS_DELETED_BY_SITE_ADMIN): ?>
                            <div><dt>Deleted by a site admin</dt><dd><?= adminTime($chat['chatBannedTimestamp']) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Members</dt><dd><?= count($adminMembers) ?><?= $adminInvited > 0 ? ' <span class="admin-small">(' . $adminInvited . ' not accepted yet)</span>' : '' ?></dd></div>
                        <div><dt>Messages sent</dt><dd><?= number_format((int) $adminMessageCounts['total']) ?> <span class="admin-small">(<?= number_format((int) $adminMessageCounts['deleted']) ?> deleted)</span></dd></div>
                        <div><dt>Background</dt><dd><?= $adminBackground !== null
                            ? htmlspecialchars($adminColor1 . ' to ' . $adminColor2 . ', ' . HBHUB_CHAT_BACKGROUND_DIRECTIONS[$adminAngle][1])
                            : 'Default' ?></dd></div>
                    </dl>

                    <?php if (!$adminIsActive): ?>
                        <p class="settings-notice admin-overview-notice">
                            <?= (int) $chat['chatStatus'] === HBHUB_CHAT_STATUS_DELETED_BY_SITE_ADMIN
                                ? 'A site admin deleted this chat.'
                                : 'This chat has been deleted: everyone left it, or a group admin deleted it.' ?>
                            There's nothing left to change, but its members and messages are still here to look at.
                        </p>
                    <?php endif; ?>
                </section>

                <?php if ($adminIsActive): ?>
                    <?php if ($adminIsGroup || $adminIconSrc !== null): ?>
                        <!-- A group's name and icon -->
                        <section class="settings-card" id="group" aria-labelledby="admin-group-heading">
                            <div class="settings-card-head">
                                <div>
                                    <h2 id="admin-group-heading"><?= $adminIsGroup ? 'Name and icon' : 'Icon' ?></h2>
                                    <p class="settings-intro">The chat gets a notice that a site admin changed it.</p>
                                </div>
                            </div>

                            <?php if ($adminIsGroup): ?>
                                <form action="<?= $adminFormAction ?>#group" method="post">
                                    <input type="hidden" name="action" value="rename">
                                    <div class="settings-field">
                                        <label class="settings-label" for="admin-chat-name">Name</label>
                                        <input type="text" id="admin-chat-name" name="name" maxlength="<?= HBHUB_CHAT_NAME_MAX ?>" autocomplete="off"
                                               value="<?= htmlspecialchars($chat['chatName'] ?? '') ?>" placeholder="Named after its members"
                                               aria-describedby="admin-chat-name-hint">
                                        <p class="settings-hint" id="admin-chat-name-hint">Leave it empty to name the group after its members.</p>
                                    </div>
                                    <div class="settings-actions">
                                        <button type="submit" class="settings-btn settings-btn-primary">Save name</button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if ($adminIconSrc !== null): ?>
                                <?php if ($adminIsGroup): ?>
                                    <hr class="settings-divider">
                                <?php endif; ?>
                                <form class="settings-item" action="<?= $adminFormAction ?>#group" method="post" data-confirm="Remove the icon of <?= htmlspecialchars($chat['chatTitle']) ?>?">
                                    <input type="hidden" name="action" value="removeIcon">
                                    <div class="settings-item-text">
                                        <span class="settings-label">Icon</span>
                                        <p class="settings-hint">The icon (at the top) is deleted, and the first letter of the name is shown instead.</p>
                                    </div>
                                    <button type="submit" class="settings-btn settings-btn-danger">Remove icon</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <!-- Background: a gradient between two colors going the way the picked arrow points, like Change background in the chat -->
                    <section class="settings-card" id="background" aria-labelledby="admin-background-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-background-heading">Background</h2>
                                <p class="settings-intro">Everyone in the chat gets a notice that a site admin changed it.</p>
                            </div>
                            <span class="settings-status"><?= $adminBackground !== null ? 'Custom' : 'Default' ?></span>
                        </div>
                        <form action="<?= $adminFormAction ?>#background" method="post">
                            <input type="hidden" name="action" value="background">
                            <!-- admin.js shows the picked gradient here, as the content security policy doesn't allow style attributes -->
                            <div class="admin-background-preview" aria-hidden="true" data-background-preview>
                                <span class="admin-bubble">Hey, new background?</span>
                                <span class="admin-bubble is-own">Looks good!</span>
                            </div>
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="settings-label" for="admin-background-color1">From</label>
                                    <input type="color" class="admin-color" id="admin-background-color1" name="color1" value="<?= htmlspecialchars($adminColor1) ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="settings-label" for="admin-background-color2">To</label>
                                    <input type="color" class="admin-color" id="admin-background-color2" name="color2" value="<?= htmlspecialchars($adminColor2) ?>">
                                </div>
                            </div>
                            <fieldset class="admin-directions">
                                <legend class="settings-label">Direction</legend>
                                <?php foreach (HBHUB_CHAT_BACKGROUND_DIRECTIONS as $angle => [$arrow, $label]): ?>
                                    <label title="<?= $label ?>">
                                        <input type="radio" class="settings-visually-hidden" name="angle" value="<?= $angle ?>"<?= $angle === $adminAngle ? ' checked' : '' ?>>
                                        <span class="admin-direction" aria-hidden="true"><?= $arrow ?></span>
                                        <span class="settings-visually-hidden"><?= $label ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <?php if (isset($adminErrors['background'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['background']) ?></p>
                            <?php endif; ?>
                            <div class="settings-actions">
                                <?php if ($adminBackground !== null): ?>
                                    <button type="submit" class="settings-btn settings-btn-danger" name="remove" value="1">Reset to default</button>
                                <?php endif; ?>
                                <button type="submit" class="settings-btn settings-btn-primary">Save background</button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <!-- Members, invited ones too -->
                <section class="settings-card" id="members" aria-labelledby="admin-members-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-members-heading">Members</h2>
                            <?php if ($adminIsActive && $adminIsGroup): ?>
                                <p class="settings-intro">
                                    Kicking someone takes them out of the group, with a notice in it that a site admin did it, and they get a notification.
                                    Kicking someone who hasn't accepted yet takes back their invite. When the owner is kicked, the admin who has been in the
                                    group the longest takes over (or the longest-standing member). Site admins can't be kicked.
                                </p>
                            <?php elseif ($adminIsActive): ?>
                                <p class="settings-intro">Nobody can be kicked from a DM. Delete the chat instead.</p>
                            <?php endif; ?>
                        </div>
                        <span class="settings-status"><?= count($adminMembers) ?></span>
                    </div>
                    <?php if ($adminMembers === []): ?>
                        <p class="settings-hint">Nobody is in this chat anymore.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col">Joined</th><th scope="col"><span class="settings-visually-hidden">Actions</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminMembers as $member): ?>
                                        <?php
                                        $memberUserId = (int) $member['userId'];
                                        $memberIsSiteAdmin = (int) $member['userRole'] === ADMIN_ROLE_ADMIN;
                                        $memberStatus = adminUserStatus($member + ['isLocked' => false, 'isOnline' => false]); // Offline, Banned or Deleted
                                        ?>
                                        <tr>
                                            <td>
                                                <a class="admin-list-link" href="/admin/user?user=<?= $memberUserId ?>">
                                                    <?= adminAvatarHtml(hbHubAvatarUrl($memberUserId, $member['userAvatarAttachmentId'] !== null ? (int) $member['userAvatarAttachmentId'] : null), $member['userName']) ?>
                                                    <span class="admin-list-name"><?= htmlspecialchars($member['userName']) ?></span>
                                                </a>
                                                <?php if ($memberIsSiteAdmin): ?>
                                                    <span class="admin-small">Site admin</span>
                                                <?php endif; ?>
                                                <?php if ($memberStatus !== 'Offline'): ?>
                                                    <?= adminStatusHtml($memberStatus) ?>
                                                <?php endif; ?>
                                                <?php if ($member['memberNickname'] !== null): ?>
                                                    <span class="admin-small">Goes by "<?= htmlspecialchars($member['memberNickname']) ?>" here</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $member['userAcknowledgedJoin'] ? htmlspecialchars(ADMIN_CHAT_ROLES[(int) $member['memberRole']] ?? 'Member') : 'Not accepted yet' ?></td>
                                            <td class="admin-nowrap"><?= adminTime($member['memberJoinedTimestamp']) ?></td>
                                            <td>
                                                <?php if ($adminIsActive && $adminIsGroup && !$memberIsSiteAdmin): ?>
                                                    <form action="<?= $adminFormAction ?>#members" method="post"
                                                          data-confirm="<?= htmlspecialchars($member['userAcknowledgedJoin']
                                                              ? 'Kick ' . $member['userName'] . ' from ' . $chat['chatTitle'] . '?'
                                                              : 'Take back ' . $member['userName'] . '\'s invite to ' . $chat['chatTitle'] . '?') ?>">
                                                        <input type="hidden" name="action" value="kick">
                                                        <input type="hidden" name="member" value="<?= $memberUserId ?>">
                                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Kick</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Messages, with the notices about changes to the chat -->
                <section class="settings-card" id="messages" aria-labelledby="admin-messages-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-messages-heading">Messages</h2>
                            <p class="settings-intro">
                                Everything sent in the chat, newest first, with the notices about changes to it. Deleted messages are still here,
                                but only shown as deleted in the chat.<?= $adminIsActive ? ' Deleting one here shows it as deleted by a site admin.' : '' ?>
                            </p>
                        </div>
                        <span class="settings-status"><?= number_format((int) $adminMessageCounts['total']) ?></span>
                    </div>
                    <?php if ($adminMessages === []): ?>
                        <p class="settings-hint"><?= $adminMessagesBefore !== null ? 'No older messages.' : 'No messages.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-messages">
                                <thead><tr><th scope="col">Sent</th><th scope="col">From</th><th scope="col">Message</th><th scope="col"><span class="settings-visually-hidden">Actions</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminMessages as $message): ?>
                                        <?php
                                        $isNotice = (bool) $message['messageEvent'];
                                        $isDeleted = $message['messageDeletedTimestamp'] !== null;
                                        ?>
                                        <tr<?= $isNotice ? ' class="is-notice"' : ($isDeleted ? ' class="is-deleted"' : '') ?>>
                                            <td class="admin-nowrap"><?= adminTime($message['messageSentTimestamp']) ?></td>
                                            <td><a class="admin-link" href="/admin/user?user=<?= (int) $message['userId'] ?>"><?= htmlspecialchars($message['senderName']) ?></a></td>
                                            <td class="admin-message-text">
                                                <?php if ($isNotice): ?>
                                                    <span class="admin-notice"><?= chatNoticeHtml($adminNoticeChat, 0, $message) ?></span>
                                                <?php else: ?>
                                                    <?= adminMessageHtml($message) ?>
                                                    <?php if ($message['messageIsEdited'] || $isDeleted): ?>
                                                        <span class="admin-small"><?= $isDeleted
                                                            ? htmlspecialchars(ADMIN_MESSAGE_DELETED_LABELS[(int) $message['messageDeletedByAdmin']] ?? 'Deleted') . ' ' . adminTime($message['messageDeletedTimestamp'])
                                                            : 'Edited' ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($adminIsActive && !$isNotice && !$isDeleted && (int) $message['senderRole'] !== ADMIN_ROLE_ADMIN): ?>
                                                    <form action="<?= $adminFormAction ?><?= $adminMessagesBefore !== null ? '&amp;before=' . $adminMessagesBefore : '' ?>#messages"
                                                          method="post" data-confirm="Delete this message?">
                                                        <input type="hidden" name="action" value="deleteMessage">
                                                        <input type="hidden" name="message" value="<?= (int) $message['messageId'] ?>">
                                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($adminMessagesBefore !== null || $adminMessagesHasOlder): ?>
                        <div class="settings-actions admin-messages-actions">
                            <?php if ($adminMessagesBefore !== null): ?>
                                <a class="settings-btn" href="<?= $adminFormAction ?>#messages">Back to the newest</a>
                            <?php endif; ?>
                            <?php if ($adminMessagesHasOlder): ?>
                                <a class="settings-btn" href="<?= $adminFormAction ?>&amp;before=<?= (int) end($adminMessages)['messageId'] ?>#messages">Older messages</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($adminIsActive): ?>
                    <!-- Delete chat -->
                    <section class="settings-card is-danger" id="delete" aria-labelledby="admin-delete-heading">
                        <form class="settings-item" action="<?= $adminFormAction ?>#overview" method="post"
                              data-confirm="Delete <?= htmlspecialchars($chat['chatTitle']) ?> for everyone in it? This can't be undone.">
                            <input type="hidden" name="action" value="deleteChat">
                            <div class="settings-item-text">
                                <h2 class="settings-item-title" id="admin-delete-heading">Delete chat</h2>
                                <p class="settings-hint">
                                    The chat is gone for everyone in it, and nothing more can be sent in it. It stays here in the admin panel
                                    with its members and messages, marked as deleted by a site admin. This can't be undone.
                                </p>
                            </div>
                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid">Delete chat</button>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
