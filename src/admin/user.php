<?php
// Admin panel for one user: /admin/user?user=id. Site admins (userRole 2) open it from the list of users on the admin
// panel's start page (admin/index.php), from "Admin panel" in the chat's right-click menus (on a DM, on someone's
// message, on a group member), from a chat's members in its admin panel (admin/chat.php) and from the user's profile.
// Everyone else gets a 404, so it doesn't show that the page is here.
//
// Shows what there is about the user: their account, sessions (their logins), messages in every chat, chats and casino
// chips, and ?export=1 downloads all of it as JSON. The actions are one form per card, like the settings page:
// ban or unban, change the name or bio, remove the picture, set the gambling block (straight away, without the wait),
// reset the password, remove two-factor login, log out every session, add or remove chips, delete messages, delete
// attachments (one or all, which frees their tickets), add or take away attachment tickets and delete the account.
// Chips change through hbHubCasinoChange() like in the games, logged with the reason "admin" and the admin's userId,
// so the chip history shows who did it. Attachments are only listed (name, size, chat, tickets), never opened here.
// Before any form is sent, admin.js asks for a reason, which goes in the admin log with the action (adminLog.php).
// Banning puts their picture on the banned pictures list (admin/banned.php) too, unless that's unticked, and
// unbanning takes it off again.
// Opening the page, downloading the data and every change are logged in the admin log (assets/php/adminLog.php).
// Site admins can't be changed here (yourself included), and a deleted account only has its messages left to delete.
// The side menu (adminPageMenuHtml()) links to the cards in the order they're on the page: Information, then Manage
// (profile, block, login), Activity (sessions, messages, attachments, chats, casino) and the Danger zone (ban, delete) last.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/bannedAvatars.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/account.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/password.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/notifications.php'; // taking back the @mentions in deleted messages
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/urlBlacklist.php'; // IP loggers and other links that can't be in a bio
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const ADMIN_STATUS_OFFLINE = 2; // userStatus in HBHub-Users (HBHUB_SESSION_STATUS_BANNED is 4)
const ADMIN_STATUS_LOCKED = 3;
const ADMIN_TRANSACTIONS_SHOWN = 25;
const ADMIN_CHIPS_MAX = 1_000_000_000; // the most chips that can be added or removed at once, to catch a typo with too many zeros
const ADMIN_TICKETS_MAX = 1000; // the most attachment tickets one user can have in all (userAttachmentTicketsExtra is a smallint)

adminOnly($hbHubUser);

$targetId = $_GET['user'] ?? null;
if (!is_string($targetId) || !ctype_digit($targetId) || strlen($targetId) > 10) {
    adminNotFound();
}
$targetId = (int) $targetId;
$adminId = (int) $hbHubUser['userId'];

$db = hbHubDatabase();
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);
$adminNameMax = max(1, min(35, (int) $siteConfig['user_name_maxSize'])); // names are capped at 35
$adminDescriptionMax = max(1, min(255, (int) $siteConfig['user_description_maxSize']));
$adminPasswordPolicy = hbHubPasswordPolicy($siteConfig);

// Never the password hash or the 2FA secret, just whether there is one
$statement = $db->prepare('SELECT userId, userName, userDescription, userSettings, userAvatarAttachmentId,
        userIpv4AdresseOnAccountCreate, userIpv6AdresseOnAccountCreate, userIpv4AdresseLastAccessed, userIpv6AdresseLastAccessed,
        userTotpSecret IS NOT NULL AS totpIsOn, userFailedLoginCount, userStatus, userRole, userCreatedTimestamp, userLastSeenTimestamp,
        userAccountLockedTimestamp, userBannedTimestamp, userBannedReason, userAttachmentTicketsExtra,
        COALESCE(userAccountLockedTimestamp + INTERVAL ? MINUTE > NOW(), 0) AS isLocked, ' . adminUserOnlineSql() . ' AS isOnline
    FROM `HBHub-Users` WHERE userId = ?');
$statement->execute([$adminLockoutMinutes, $targetId]);
$target = $statement->fetch();
if ($target === false) {
    adminNotFound();
}

$targetIsDeleted = $target['userStatus'] === null;
$targetIsBanned = (int) $target['userStatus'] === HBHUB_SESSION_STATUS_BANNED;
$targetIsAdmin = (int) $target['userRole'] === ADMIN_ROLE_ADMIN;
$targetIsSelf = $targetId === (int) $hbHubUser['userId'];
$adminCanChange = !$targetIsDeleted && !$targetIsAdmin;
$adminCanDeleteMessages = !$targetIsAdmin;

/**
 * Every chat the user is in (message requests too), newest first. Named after the other members when it has no name
 * (for a DM, just the other person).
 */
function adminChats(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT c.chatId, c.chatType, ' . adminChatTitleSql('cm.userId') . ' AS chatTitle,
            cm.memberRole, cm.userAcknowledgedJoin, cm.memberJoinedTimestamp,
            (SELECT COUNT(*) FROM `HBHub-ChatMembers` x WHERE x.chatId = c.chatId) AS memberCount
        FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.userId = ? AND c.chatStatus = ?
        ORDER BY cm.memberJoinedTimestamp DESC');
    $statement->execute([$userId, HBHUB_CHAT_STATUS_ACTIVE]);
    return $statement->fetchAll();
}

/**
 * The user's messages in every chat (not the notices about changes to a chat), newest first.
 * With $beforeId only the ones older than that message, with $limit at most that many.
 */
function adminMessages(PDO $db, int $userId, ?int $beforeId = null, ?int $limit = null): array
{
    $statement = $db->prepare('SELECT m.messageId, m.chatId, c.chatType, c.chatStatus, ' . adminChatTitleSql('m.userId') . ' AS chatTitle,
            m.messageContent, m.attachmentId, m.messageSentTimestamp, m.messageDeletedTimestamp, m.messageDeletedByAdmin,
            m.messageIsEdited, m.messageReplyToId
        FROM `HBHub-Messages` m JOIN `HBHub-Chats` c ON c.chatId = m.chatId
        WHERE m.userId = ? AND m.messageEvent = 0' . ($beforeId !== null ? ' AND m.messageId < ?' : '') . '
        ORDER BY m.messageId DESC' . ($limit !== null ? ' LIMIT ' . $limit : ''));
    $statement->execute($beforeId !== null ? [$userId, $beforeId] : [$userId]);
    return $statement->fetchAll();
}

/**
 * The user's sessions (one per login, kept for 30 days), newest first. The ids are left out: they'd log you in as the user.
 */
function adminSessions(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT sessionDevice, sessionGenerated, sessionLastSeen, sessionValidUntil, sessionTotpPending, sessionValidUntil > NOW() AS isValid
        FROM `HBHub-Sessions` WHERE userId = ? ORDER BY sessionGenerated DESC');
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

/**
 * The user's casino wallet, or null when they've never opened the casino.
 */
function adminWallet(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT walletChips, walletDailyDay, walletCreatedTimestamp FROM `HBHub-CasinoWallets` WHERE userId = ?');
    $statement->execute([$userId]);
    return $statement->fetch() ?: null;
}

/**
 * Every change to the user's chips, newest first, at most $limit of them when it's given.
 * The ones a site admin made have the admin's name as adminName (null for every other change).
 */
function adminTransactions(PDO $db, int $userId, ?int $limit = null): array
{
    $statement = $db->prepare('SELECT t.transactionAmount, t.transactionBalance, t.transactionReason, t.transactionGame, t.transactionReference,
            t.transactionTimestamp, a.userName AS adminName
        FROM `HBHub-CasinoTransactions` t
        LEFT JOIN `HBHub-Users` a ON t.transactionReason = ? AND t.transactionGame IS NULL AND a.userId = t.transactionReference
        WHERE t.userId = ? ORDER BY t.transactionId DESC' . ($limit !== null ? ' LIMIT ' . $limit : ''));
    $statement->execute([HBHUB_CASINO_REASON_ADMIN, $userId]);
    return $statement->fetchAll();
}

/**
 * The user's attachments that still take tickets (their file is there and hasn't expired), newest first, with the
 * chat they were sent in. Never the file itself or its key on disk.
 */
function adminAttachments(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT a.attachmentId, a.chatId, c.chatType, c.chatStatus, ' . adminChatTitleSql('a.userId') . ' AS chatTitle,
            a.attachmentName, a.attachmentKind, a.attachmentSize, a.attachmentStoredSize, a.attachmentTickets,
            a.attachmentUploadedTimestamp, a.attachmentExpiresTimestamp
        FROM `HBHub-ChatAttachments` a JOIN `HBHub-Chats` c ON c.chatId = a.chatId
        WHERE a.userId = ? AND a.attachmentStatus = ? AND a.attachmentExpiresTimestamp > NOW()
        ORDER BY a.attachmentId DESC');
    $statement->execute([$userId, HBHUB_CHAT_ATTACHMENT_KEPT]);
    return $statement->fetchAll();
}

/**
 * Back to the admin panel (at the card that was used) with a toast, so reloading doesn't send the form again.
 * $query is anything else to keep in the address, like the page of messages.
 */
function adminRedirect(int $userId, ?string $toast, string $card, string $query = ''): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    if ($toast !== null) {
        hbHubSetToast($toast);
    }
    header('Location: /admin/user?user=' . $userId . $query . '#' . $card, true, 303);
    exit;
}

// ?export=1: everything there is about the user as a JSON file
if (($_GET['export'] ?? null) === '1') {
    $exportUser = $target;
    unset($exportUser['isLocked'], $exportUser['isOnline']);
    $exportUser['totpIsOn'] = (bool) $exportUser['totpIsOn'];
    $exportUser['userSettings'] = hbHubUserSettings($exportUser['userSettings']);

    $statement = $db->prepare('SELECT notificationType, notificationByUserId, notificationData, notificationCreatedTimestamp, notificationReadTimestamp
        FROM `HBHub-Notifications` WHERE userId = ? ORDER BY notificationId DESC');
    $statement->execute([$targetId]);
    $notifications = array_map(function (array $row): array {
        $row['notificationData'] = $row['notificationData'] !== null ? json_decode($row['notificationData'], true) : null;
        return $row;
    }, $statement->fetchAll());

    adminLog($db, $adminId, 'user.export', $targetId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="hbhub-user-' . $targetId . '.json"');
    header('Cache-Control: no-store');
    echo json_encode([
        'exportedTimestamp' => date('c'),
        'user' => $exportUser,
        'sessions' => adminSessions($db, $targetId),
        'chats' => adminChats($db, $targetId),
        'messages' => adminMessages($db, $targetId),
        'casinoWallet' => adminWallet($db, $targetId),
        'casinoTransactions' => adminTransactions($db, $targetId),
        'attachmentTicketsTotal' => hbHubChatAttachmentTicketsTotal($db, $targetId),
        'attachments' => adminAttachments($db, $targetId),
        'notifications' => $notifications,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Which page of messages is shown: ?before= is the oldest message on the page before
$adminMessagesBefore = is_string($_GET['before'] ?? null) && ctype_digit($_GET['before']) && strlen($_GET['before']) <= 19
    ? (int) $_GET['before'] : null;

$adminValues = [
    'name' => $target['userName'],
    'description' => $target['userDescription'] ?? '',
    'newPassword' => '',
    'chips' => '',
    'tickets' => '',
];
$adminErrors = []; // card => message

$adminAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;
// Deleting what they've sent works for a deleted account too, the rest only while it can be changed
$adminIsMessageAction = in_array($adminAction, ['deleteMessage', 'deleteAllMessages', 'deleteAttachment', 'deleteAllAttachments'], true);
if ($adminAction !== null && !($adminIsMessageAction ? $adminCanDeleteMessages : $adminCanChange)) {
    adminRedirect($targetId, null, 'overview'); // the forms aren't on the page, so nothing to say
}

switch ($adminAction) {
    case 'ban':
        // The sessions stay until the user's next visit: session.php then marks their browser, so they can't just make a new account.
        // Their picture goes on the banned list, so a new account can't use it either.
        $db->beginTransaction();
        hbHubBanUser($db, $targetId, HBHUB_BAN_REASON_ADMIN);
        $banAvatar = ($_POST['banAvatar'] ?? null) === '1';
        if ($banAvatar) {
            hbHubBannedAvatarAddFromUser($db, $targetId, $adminId);
        }
        adminLog($db, $adminId, 'user.ban', $targetId, null, ['avatar' => $banAvatar]);
        $db->commit();
        hbHubBannedAvatarFillFingerprints($db); // after the commit, so the ban doesn't wait on it
        adminRedirect($targetId, 'adminBanned', 'ban');

    case 'unban':
        // Sessions from before the ban don't come back, they log in again. The pictures their ban put on the banned
        // list come off it, so uploading their own picture again doesn't ban them.
        $db->beginTransaction();
        $db->prepare('UPDATE `HBHub-Users` SET userStatus = ?, userBannedTimestamp = NULL, userBannedReason = NULL WHERE userId = ? AND userStatus = ?')
            ->execute([ADMIN_STATUS_OFFLINE, $targetId, HBHUB_SESSION_STATUS_BANNED]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$targetId]);
        hbHubBannedAvatarRemoveFromUser($db, $targetId);
        adminLog($db, $adminId, 'user.unban', $targetId);
        $db->commit();
        adminRedirect($targetId, 'adminUnbanned', 'ban');

    case 'name':
        $adminValues['name'] = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $nameError = hbHubNameError($adminValues['name'], $adminNameMax, $target['userName']);
        if ($nameError === null) {
            try {
                $db->prepare('UPDATE `HBHub-Users` SET userName = ?, userNameLower = ? WHERE userId = ?')
                    ->execute([$adminValues['name'], mb_strtolower($adminValues['name']), $targetId]);
                adminLog($db, $adminId, 'user.name', $targetId);
                adminRedirect($targetId, 'adminSaved', 'profile');
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) { // duplicate key - userNameLower is unique
                    throw $e;
                }
                $nameError = 'That name is already taken. Please choose another one.';
            }
        }
        $adminErrors['name'] = $nameError;
        break;

    case 'bio':
        // Remove bio, or Save with the box emptied, takes it away
        $description = ($_POST['remove'] ?? null) === '1' || !is_string($_POST['description'] ?? null)
            ? '' : trim(str_replace("\r\n", "\n", $_POST['description']));
        $adminValues['description'] = $description;
        if ($description !== ($target['userDescription'] ?? '') && !hbHubIsLatinText($description, true)) {
            $adminErrors['bio'] = 'The bio can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji.';
        } elseif (hbHubUrlBlacklisted($description)) {
            $adminErrors['bio'] = 'Woah, hold up! The bio has a dangerous link or a link that compromises people\'s privacy in it.';
        } elseif (mb_strlen($description) > $adminDescriptionMax) {
            $adminErrors['bio'] = "Please keep it to $adminDescriptionMax characters or fewer.";
        } else {
            $db->prepare('UPDATE `HBHub-Users` SET userDescription = ? WHERE userId = ?')
                ->execute([$description === '' ? null : $description, $targetId]);
            adminLog($db, $adminId, 'user.bio', $targetId, null, ['removed' => $description === '']);
            adminRedirect($targetId, 'adminSaved', 'profile');
        }
        break;

    case 'removeAvatar':
        hbHubRemoveAvatar($db, $targetId);
        adminLog($db, $adminId, 'user.avatar', $targetId);
        adminRedirect($targetId, 'adminSaved', 'profile');

    case 'block':
        // Straight away either way: the wait is only there to stop the user turning it off in the moment
        $blockMode = $_POST['block'] ?? null;
        if (!hbHubBlockIsMode($blockMode)) {
            $adminErrors['block'] = 'Please choose one of the options.';
            break;
        }
        hbHubChangeUserSettings($db, $targetId, function (array $settings) use ($blockMode): array {
            $settings['block'] = ['mode' => $blockMode, 'pendingMode' => null, 'pendingFrom' => null];
            return $settings;
        });
        adminLog($db, $adminId, 'user.block', $targetId, null, ['mode' => $blockMode]);
        adminRedirect($targetId, 'adminSaved', 'block');

    case 'password':
        // Shown to the admin as it's typed (or generated), to pass on to the user. Their locks are lifted too, their devices' as well.
        $newPassword = is_string($_POST['newPassword'] ?? null) ? $_POST['newPassword'] : '';
        $adminValues['newPassword'] = $newPassword;
        $passwordError = hbHubPasswordError($newPassword, $newPassword, $adminPasswordPolicy, 'The new password');
        if ($passwordError !== null) {
            $adminErrors['password'] = $passwordError;
            break;
        }
        $db->beginTransaction();
        $db->prepare('UPDATE `HBHub-Users` SET userPasswordHash = ?, userFailedLoginCount = 0, userAccountLockedTimestamp = NULL,
                userStatus = IF(userStatus = ?, ?, userStatus)
            WHERE userId = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), ADMIN_STATUS_LOCKED, ADMIN_STATUS_OFFLINE, $targetId]);
        hbHubLoginUnlockAll($db, $targetId);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$targetId]);
        adminLog($db, $adminId, 'user.password', $targetId); // never the password itself
        $db->commit();
        adminRedirect($targetId, 'adminPassword', 'security');

    case 'removeTotp':
        // A login waiting for a code goes too, the password alone logs in now
        $db->beginTransaction();
        $db->prepare('UPDATE `HBHub-Users` SET userTotpSecret = NULL, userTotpLastStep = NULL WHERE userId = ?')->execute([$targetId]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ? AND sessionTotpPending = 1')->execute([$targetId]);
        adminLog($db, $adminId, 'user.totp', $targetId);
        $db->commit();
        adminRedirect($targetId, 'adminTotpOff', 'security');

    case 'logoutAll':
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$targetId]);
        adminLog($db, $adminId, 'user.logout', $targetId);
        adminRedirect($targetId, 'adminLoggedOut', 'security');

    case 'chips':
        // Add chips (like ones they've bought) or Remove chips (like ones got from a bug). Someone who's never opened
        // the casino gets their wallet with the starting chips first, like when they open it themselves.
        // Thousands can be split up like 10.000, 10,000 or 10 000, but 1.5 isn't read as 15
        $chipsInput = is_string($_POST['chips'] ?? null) ? trim($_POST['chips']) : '';
        $adminValues['chips'] = $chipsInput;
        if (preg_match('~^\d{1,3}([., ]\d{3})+$~', $chipsInput) === 1) {
            $chipsInput = str_replace(['.', ',', ' '], '', $chipsInput);
        }
        $chipsDirection = $_POST['direction'] ?? null;
        if (!in_array($chipsDirection, ['add', 'remove'], true)) {
            $adminErrors['chips'] = 'Please choose Add chips or Remove chips.';
            break;
        }
        if (!ctype_digit($chipsInput) || strlen($chipsInput) > 10 || (int) $chipsInput < 1 || (int) $chipsInput > ADMIN_CHIPS_MAX) {
            $adminErrors['chips'] = 'Please type a whole number of chips from 1 to ' . number_format(ADMIN_CHIPS_MAX) . '.';
            break;
        }
        $chipsAmount = $chipsDirection === 'add' ? (int) $chipsInput : -(int) $chipsInput;
        if (hbHubCasinoChange($db, $targetId, $chipsAmount, HBHUB_CASINO_REASON_ADMIN, null, $adminId) === null) {
            $adminErrors['chips'] = 'They only have ' . number_format(hbHubCasinoWallet($db, $targetId)['chips']) . ' chips, so that many can\'t be removed.';
            break;
        }
        adminLog($db, $adminId, 'user.chips', $targetId, null, ['amount' => $chipsAmount]);
        adminRedirect($targetId, $chipsAmount > 0 ? 'adminChipsAdded' : 'adminChipsRemoved', 'casino');

    case 'deleteMessage':
        // Shows as deleted by a site admin in the chat. The page of messages it was on stays open.
        $messageId = $_POST['message'] ?? null;
        if (is_string($messageId) && ctype_digit($messageId) && strlen($messageId) <= 19) {
            $statement = $db->prepare('UPDATE `HBHub-Messages` SET messageDeletedTimestamp = NOW(), messageDeletedByAdmin = ?
                WHERE messageId = ? AND userId = ? AND messageEvent = 0 AND messageDeletedTimestamp IS NULL');
            $statement->execute([ADMIN_MESSAGE_DELETED_BY_SITE_ADMIN, (int) $messageId, $targetId]);
            if ($statement->rowCount() > 0) {
                hbHubRemoveNotifications($db, null, 'chat.mention', ['messageId' => (int) $messageId]); // whoever it @mentioned
                $statement = $db->prepare('SELECT chatId FROM `HBHub-Messages` WHERE messageId = ?');
                $statement->execute([(int) $messageId]);
                adminLog($db, $adminId, 'message.delete', $targetId, (int) $statement->fetchColumn(), ['messageId' => (int) $messageId]);
            }
            // Its attachment goes too (assets/php/chatAttachments.php), giving the user back its tickets
            hbHubChatAttachmentsRemove($db, 'm.messageId = ? AND m.userId = ? AND m.messageDeletedTimestamp IS NOT NULL', [(int) $messageId, $targetId]);
        }
        adminRedirect($targetId, 'adminMessageDeleted', 'messages', $adminMessagesBefore !== null ? '&before=' . $adminMessagesBefore : '');

    case 'deleteAllMessages':
        $statement = $db->prepare('UPDATE `HBHub-Messages` SET messageDeletedTimestamp = NOW(), messageDeletedByAdmin = ?
            WHERE userId = ? AND messageEvent = 0 AND messageDeletedTimestamp IS NULL');
        $statement->execute([ADMIN_MESSAGE_DELETED_BY_SITE_ADMIN, $targetId]);
        adminLog($db, $adminId, 'user.messages', $targetId, null, ['count' => $statement->rowCount()]);
        hbHubChatAttachmentsRemove($db, 'a.userId = ?', [$targetId]); // every attachment was in one of them
        // and every @mention in them (chat.mention notifications), which only ever come from the one who wrote them
        $db->prepare('DELETE FROM `HBHub-Notifications` WHERE notificationType = ? AND notificationByUserId = ?')
            ->execute(['chat.mention', $targetId]);
        adminRedirect($targetId, 'adminMessagesDeleted', 'messages');

    case 'deleteAttachment':
        // Like Delete in their own attachments popup: the file goes for good and its tickets are free again, and the
        // message it was in stays, showing "Attachment deleted"
        $attachmentId = $_POST['attachment'] ?? null;
        if (is_string($attachmentId) && ctype_digit($attachmentId) && strlen($attachmentId) <= 10) {
            $statement = $db->prepare('SELECT attachmentTickets FROM `HBHub-ChatAttachments` WHERE attachmentId = ? AND userId = ?');
            $statement->execute([(int) $attachmentId, $targetId]);
            $attachmentTickets = (int) $statement->fetchColumn();
            if (hbHubChatAttachmentsRemove($db, 'a.attachmentId = ? AND a.userId = ?', [(int) $attachmentId, $targetId]) > 0) {
                adminLog($db, $adminId, 'user.attachment', $targetId, null, ['attachmentId' => (int) $attachmentId, 'tickets' => $attachmentTickets]);
            }
        }
        adminRedirect($targetId, 'adminAttachmentDeleted', 'attachments');

    case 'deleteAllAttachments':
        $removed = hbHubChatAttachmentsRemove($db, 'a.userId = ?', [$targetId]);
        if ($removed > 0) {
            adminLog($db, $adminId, 'user.attachments', $targetId, null, ['count' => $removed]);
        }
        adminRedirect($targetId, 'adminAttachmentsDeleted', 'attachments');

    case 'tickets':
        // Add tickets (for someone who needs to send more) or Revoke tickets. Their total can go from 0 to ADMIN_TICKETS_MAX.
        // Taking some away doesn't delete what they've sent: they just can't send more until they're under it.
        $ticketsInput = is_string($_POST['tickets'] ?? null) ? trim($_POST['tickets']) : '';
        $adminValues['tickets'] = $ticketsInput;
        $ticketsDirection = $_POST['direction'] ?? null;
        if (!in_array($ticketsDirection, ['add', 'remove'], true)) {
            $adminErrors['tickets'] = 'Please choose Add tickets or Revoke tickets.';
            break;
        }
        if (!ctype_digit($ticketsInput) || strlen($ticketsInput) > 4 || (int) $ticketsInput < 1 || (int) $ticketsInput > ADMIN_TICKETS_MAX) {
            $adminErrors['tickets'] = 'Please type a whole number of tickets from 1 to ' . number_format(ADMIN_TICKETS_MAX) . '.';
            break;
        }
        $ticketsAmount = $ticketsDirection === 'add' ? (int) $ticketsInput : -(int) $ticketsInput;
        // Checked and changed in one go, so two admins at once can't push it past 0 or ADMIN_TICKETS_MAX
        $statement = $db->prepare('UPDATE `HBHub-Users` SET userAttachmentTicketsExtra = userAttachmentTicketsExtra + ?
            WHERE userId = ? AND ? + userAttachmentTicketsExtra + ? BETWEEN 0 AND ?');
        $statement->execute([$ticketsAmount, $targetId, HBHUB_CHAT_ATTACHMENT_TICKETS, $ticketsAmount, ADMIN_TICKETS_MAX]);
        $ticketsTotal = hbHubChatAttachmentTicketsTotal($db, $targetId);
        if ($statement->rowCount() === 0) {
            $adminErrors['tickets'] = $ticketsAmount < 0
                ? 'They only have ' . $ticketsTotal . ' ' . ($ticketsTotal === 1 ? 'ticket' : 'tickets') . ', so that many can\'t be taken away.'
                : 'They have ' . $ticketsTotal . ' tickets, and nobody can have more than ' . number_format(ADMIN_TICKETS_MAX) . '.';
            break;
        }
        adminLog($db, $adminId, 'user.tickets', $targetId, null, ['amount' => $ticketsAmount, 'total' => $ticketsTotal]);
        adminRedirect($targetId, $ticketsAmount > 0 ? 'adminTicketsAdded' : 'adminTicketsRevoked', 'attachments');

    case 'deleteAccount':
        // The name is typed in to make sure it's the right account
        $confirmName = is_string($_POST['confirmName'] ?? null) ? trim($_POST['confirmName']) : '';
        if (mb_strtolower($confirmName) !== mb_strtolower($target['userName'])) {
            $adminErrors['delete'] = 'Type the user\'s name exactly to delete the account.';
            break;
        }
        hbHubDeleteAccount($db, $targetId);
        adminLog($db, $adminId, 'user.delete', $targetId);
        adminRedirect($targetId, 'adminAccountDeleted', 'overview');
}

// What the page shows, which is logged: it has all their messages and personal info
adminLogView($db, $adminId, 'user.view', $targetId, null);
$adminSettings = hbHubUserSettings($target['userSettings']);
$adminYear = is_scalar($adminSettings['year'] ?? null) ? (string) $adminSettings['year'] : '';
$adminClass = is_scalar($adminSettings['class'] ?? null) ? (string) $adminSettings['class'] : '';
$adminBlock = hbHubBlockSettings($adminSettings);
$adminAvatarSrc = hbHubAvatarUrl($targetId, $target['userAvatarAttachmentId'] !== null ? (int) $target['userAvatarAttachmentId'] : null);

$adminSessions = adminSessions($db, $targetId);
$adminActiveSessions = count(array_filter($adminSessions, fn (array $session): bool => $session['isValid'] && !$session['sessionTotpPending']));
$adminChats = adminChats($db, $targetId);
$adminWallet = adminWallet($db, $targetId);
$adminTransactions = adminTransactions($db, $targetId, ADMIN_TRANSACTIONS_SHOWN);
$adminAttachments = adminAttachments($db, $targetId);
$adminTicketsUsed = array_sum(array_map(fn (array $attachment): int => (int) $attachment['attachmentTickets'], $adminAttachments));
$adminTicketsExtra = (int) $target['userAttachmentTicketsExtra'];
$adminTicketsTotal = max(0, HBHUB_CHAT_ATTACHMENT_TICKETS + $adminTicketsExtra); // like hbHubChatAttachmentTicketsTotal()

// One more than is shown, to know whether there are older ones
$adminMessages = adminMessages($db, $targetId, $adminMessagesBefore, ADMIN_MESSAGES_SHOWN + 1);
$adminMessagesHasOlder = count($adminMessages) > ADMIN_MESSAGES_SHOWN;
$adminMessages = array_slice($adminMessages, 0, ADMIN_MESSAGES_SHOWN);

$statement = $db->prepare('SELECT COUNT(*) AS total, COUNT(messageDeletedTimestamp) AS deleted
    FROM `HBHub-Messages` WHERE userId = ? AND messageEvent = 0');
$statement->execute([$targetId]);
$adminMessageCounts = $statement->fetch();

/**
 * The addresses, IPv4 and/or IPv6, or a dash when neither was saved.
 */
function adminIps(?string $ipv4, ?string $ipv6): string
{
    $ips = array_filter([$ipv4, $ipv6], fn (?string $ip): bool => $ip !== null && $ip !== '');
    return $ips !== [] ? implode(' / ', $ips) : '–';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/settingsMenu.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settingsMenu.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title><?= htmlspecialchars($target['userName']) ?> - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Links to the cards, in the order they're on the page -->
            <?= adminPageMenuHtml('/admin/', 'All users', $adminAvatarSrc, $target['userName'], adminStatusHtml(adminUserStatus($target)), [
                'Overview' => ['overview' => 'Information'],
                'Manage' => $adminCanChange ? ['profile' => 'Profile', 'block' => 'Gambling block', 'security' => 'Login'] : [],
                'Activity' => ['sessions' => 'Sessions', 'messages' => 'Messages', 'attachments' => 'Attachments', 'chats' => 'Chats', 'casino' => 'Casino'],
                ADMIN_MENU_DANGER => $adminCanChange ? ['ban' => $targetIsBanned ? 'Unban' : 'Ban', 'delete' => 'Delete account'] : [],
            ]) ?>

            <div class="settings-content">
                <!-- Who it is, and what there is to know about them -->
                <section class="settings-card" id="overview" aria-labelledby="admin-name-heading">
                    <div class="admin-header">
                        <?php if ($adminAvatarSrc !== null): ?>
                            <img class="settings-avatar-preview" src="<?= htmlspecialchars($adminAvatarSrc) ?>" alt="Profile picture of <?= htmlspecialchars($target['userName']) ?>">
                        <?php else: ?>
                            <span class="settings-avatar-preview settings-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($target['userName'], 0, 1))) ?></span>
                        <?php endif; ?>
                        <div class="admin-header-text">
                            <h1 id="admin-name-heading">
                                <?= htmlspecialchars($target['userName']) ?>
                                <?= adminStatusHtml(adminUserStatus($target)) ?>
                                <?php if ($targetIsAdmin): ?>
                                    <span class="settings-status">Site admin</span>
                                <?php endif; ?>
                            </h1>
                            <div class="admin-header-links">
                                <?php if (!$targetIsDeleted && !$targetIsBanned): ?>
                                    <a class="settings-btn settings-btn-small" href="<?= htmlspecialchars(hbHubProfileUrl($targetId)) ?>">View profile</a>
                                <?php endif; ?>
                                <a class="settings-btn settings-btn-small" href="/admin/user?user=<?= $targetId ?>&amp;export=1" download>Download all data (JSON)</a>
                                <a class="settings-btn settings-btn-small" href="/admin/log?user=<?= $targetId ?>">Admin log</a>
                            </div>
                        </div>
                    </div>

                    <hr class="settings-divider">

                    <h2 class="settings-visually-hidden">Information</h2>
                    <dl class="admin-details">
                        <div><dt>User ID</dt><dd><?= $targetId ?></dd></div>
                        <div><dt>Role</dt><dd><?= $targetIsAdmin ? 'Site admin' : ($targetIsDeleted ? '–' : 'User') ?></dd></div>
                        <div><dt>Joined</dt><dd><?= adminTime($target['userCreatedTimestamp']) ?></dd></div>
                        <div><dt>Last seen</dt><dd><?= adminTime($target['userLastSeenTimestamp']) ?></dd></div>
                        <?php if ($targetIsBanned): ?>
                            <div><dt>Banned</dt><dd><?= adminTime($target['userBannedTimestamp']) ?><?php if ((int) $target['userBannedReason'] === HBHUB_BAN_REASON_AVATAR): ?>
                                <span class="admin-small">(automatically, for uploading a <a class="admin-link" href="/admin/banned">banned picture</a>)</span><?php endif; ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Class</dt><dd><?= $adminYear !== '' && $adminClass !== '' ? htmlspecialchars($adminYear . '.' . $adminClass) : '–' ?></dd></div>
                        <div><dt>Two-factor login</dt><dd><?= $target['totpIsOn'] ? 'On' : 'Off' ?></dd></div>
                        <div><dt>Wrong passwords in a row</dt><dd><?= (int) $target['userFailedLoginCount'] ?></dd></div>
                        <div><dt>Last locked</dt><dd><?= adminTime($target['userAccountLockedTimestamp']) ?><?= $target['isLocked'] ? ' (still locked)' : '' ?></dd></div>
                        <div><dt>IP when signing up</dt><dd class="admin-mono"><?= htmlspecialchars(adminIps($target['userIpv4AdresseOnAccountCreate'], $target['userIpv6AdresseOnAccountCreate'])) ?></dd></div>
                        <div><dt>Last IP</dt><dd class="admin-mono"><?= htmlspecialchars(adminIps($target['userIpv4AdresseLastAccessed'], $target['userIpv6AdresseLastAccessed'])) ?></dd></div>
                        <div><dt>Gambling block</dt><dd><?= htmlspecialchars(HBHUB_BLOCK_MODES[$adminBlock['mode']]) ?><?php if ($adminBlock['pendingMode'] !== null): ?>
                            <span class="admin-small">(changing to "<?= htmlspecialchars(HBHUB_BLOCK_MODES[$adminBlock['pendingMode']]) ?>" at <?= htmlspecialchars(hbHubBlockFormatTime($adminBlock['pendingFrom'])) ?>)</span><?php endif; ?></dd></div>
                        <div><dt>Chips</dt><dd><?= $adminWallet !== null ? number_format((int) $adminWallet['walletChips']) : 'Never opened the casino' ?></dd></div>
                        <div><dt>Messages sent</dt><dd><?= number_format((int) $adminMessageCounts['total']) ?> <span class="admin-small">(<?= number_format((int) $adminMessageCounts['deleted']) ?> deleted)</span></dd></div>
                        <div><dt>Attachment tickets</dt><dd><?= $adminTicketsUsed ?> of <?= $adminTicketsTotal ?> used<?php if ($adminTicketsExtra !== 0): ?>
                            <span class="admin-small">(<?= $adminTicketsExtra > 0 ? $adminTicketsExtra . ' added' : -$adminTicketsExtra . ' taken away' ?> by site admins)</span><?php endif; ?></dd></div>
                        <div><dt>Chats</dt><dd><?= count($adminChats) ?></dd></div>
                        <div><dt>Logged in sessions</dt><dd><?= $adminActiveSessions ?></dd></div>
                    </dl>
                    <?php if ($target['userDescription'] !== null && trim($target['userDescription']) !== ''): ?>
                        <p class="settings-label">Bio</p>
                        <p class="admin-bio"><?= nl2br(htmlspecialchars($target['userDescription'])) ?></p>
                    <?php endif; ?>

                    <?php if ($targetIsDeleted): ?>
                        <p class="settings-notice admin-overview-notice">This account has been deleted, so there's nothing left to change. Its messages can still be deleted below.</p>
                    <?php elseif ($targetIsAdmin): ?>
                        <p class="settings-notice admin-overview-notice">
                            <?= $targetIsSelf ? 'This is you.' : htmlspecialchars($target['userName']) . ' is a site admin.' ?>
                            Site admins can't be changed from the admin panel.
                        </p>
                    <?php endif; ?>
                </section>

                <?php if ($adminCanChange): ?>
                    <!-- Profile: name, bio and picture -->
                    <section class="settings-card" id="profile" aria-labelledby="admin-profile-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-profile-heading">Profile</h2>
                                <p class="settings-intro">Their name, bio and picture, for when something on their profile breaks the rules.</p>
                            </div>
                        </div>

                        <form action="/admin/user?user=<?= $targetId ?>#profile" method="post">
                            <input type="hidden" name="action" value="name">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-name">Name</label>
                                <input type="text" id="admin-name" name="name" maxlength="<?= $adminNameMax ?>" required autocomplete="off"
                                       value="<?= htmlspecialchars($adminValues['name']) ?>" aria-describedby="admin-name-hint">
                                <p class="settings-hint" id="admin-name-hint">They log in with it too, so tell them when it changes.</p>
                                <?php if (isset($adminErrors['name'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['name']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary">Save name</button>
                            </div>
                        </form>

                        <hr class="settings-divider">

                        <form action="/admin/user?user=<?= $targetId ?>#profile" method="post">
                            <input type="hidden" name="action" value="bio">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-bio">Bio</label>
                                <textarea id="admin-bio" name="description" maxlength="<?= $adminDescriptionMax ?>" rows="3"><?= htmlspecialchars($adminValues['description']) ?></textarea>
                                <?php if (isset($adminErrors['bio'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['bio']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <?php if ($target['userDescription'] !== null): ?>
                                    <button type="submit" class="settings-btn settings-btn-danger" name="remove" value="1" formnovalidate>Remove bio</button>
                                <?php endif; ?>
                                <button type="submit" class="settings-btn settings-btn-primary">Save bio</button>
                            </div>
                        </form>

                        <?php if ($adminAvatarSrc !== null): ?>
                            <hr class="settings-divider">
                            <form class="settings-item" action="/admin/user?user=<?= $targetId ?>#profile" method="post" data-confirm="Remove <?= htmlspecialchars($target['userName']) ?>'s profile picture?">
                                <input type="hidden" name="action" value="removeAvatar">
                                <div class="settings-item-text">
                                    <span class="settings-label">Profile picture</span>
                                    <p class="settings-hint">Their picture is deleted, and the first letter of their name is shown instead.</p>
                                </div>
                                <button type="submit" class="settings-btn settings-btn-danger">Remove picture</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <!-- Gambling block, straight away -->
                    <section class="settings-card" id="block" aria-labelledby="admin-block-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-block-heading">Gambling and Games block</h2>
                                <p class="settings-intro">
                                    Changes straight away, also when it's turned down: there's no wait like when they change it themselves.
                                    A change they were waiting for is cancelled.
                                </p>
                            </div>
                        </div>
                        <form action="/admin/user?user=<?= $targetId ?>#block" method="post">
                            <input type="hidden" name="action" value="block">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-block">Block</label>
                                <select id="admin-block" name="block">
                                    <?php foreach (HBHUB_BLOCK_MODES as $mode => $label): ?>
                                        <option value="<?= $mode ?>"<?= $adminBlock['mode'] === $mode ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($adminErrors['block'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['block']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary">Save</button>
                            </div>
                        </form>
                    </section>

                    <!-- Password, 2FA and sessions -->
                    <section class="settings-card" id="security" aria-labelledby="admin-security-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-security-heading">Login</h2>
                                <p class="settings-intro">For when they can't get in, or someone else has.</p>
                            </div>
                            <span class="settings-status<?= $target['totpIsOn'] ? ' is-on' : '' ?>">2FA <?= $target['totpIsOn'] ? 'on' : 'off' ?></span>
                        </div>

                        <form action="/admin/user?user=<?= $targetId ?>#security" method="post">
                            <input type="hidden" name="action" value="password">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-new-password">Reset password</label>
                                <div class="admin-password-row">
                                    <!-- Plain text, so it can be passed on to the user -->
                                    <input type="text" id="admin-new-password" name="newPassword" minlength="<?= $adminPasswordPolicy['minLength'] ?>" maxlength="72"
                                           required autocomplete="off" spellcheck="false" class="admin-mono"
                                           value="<?= htmlspecialchars($adminValues['newPassword']) ?>" aria-describedby="admin-password-hint">
                                    <button type="button" class="settings-btn" data-password-generate hidden>Generate</button>
                                </div>
                                <p class="settings-hint" id="admin-password-hint">
                                    Type a new password or generate one, then give it to them. It has to follow the same rules as any other password.
                                    They're logged out everywhere, and a lock from wrong passwords is lifted.
                                </p>
                                <?php if (isset($adminErrors['password'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['password']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary">Reset password</button>
                            </div>
                        </form>

                        <hr class="settings-divider">

                        <div class="settings-items">
                            <?php if ($target['totpIsOn']): ?>
                                <form class="settings-item" action="/admin/user?user=<?= $targetId ?>#security" method="post" data-confirm="Remove two-factor login from <?= htmlspecialchars($target['userName']) ?>'s account?">
                                    <input type="hidden" name="action" value="removeTotp">
                                    <div class="settings-item-text">
                                        <span class="settings-label">Two-factor login</span>
                                        <p class="settings-hint">For when they've lost their authenticator app. They log in with just their password, and can set it up again in settings.</p>
                                    </div>
                                    <button type="submit" class="settings-btn settings-btn-danger">Remove two-factor login</button>
                                </form>
                            <?php endif; ?>
                            <form class="settings-item" action="/admin/user?user=<?= $targetId ?>#security" method="post" data-confirm="Log <?= htmlspecialchars($target['userName']) ?> out everywhere?">
                                <input type="hidden" name="action" value="logoutAll">
                                <div class="settings-item-text">
                                    <span class="settings-label">Log out of every session</span>
                                    <p class="settings-hint">
                                        Logged in on <?= $adminActiveSessions ?> <?= $adminActiveSessions === 1 ? 'device' : 'devices' ?> right now (see <a class="admin-link" href="#sessions">Sessions</a>).
                                        They can log in again with their password.
                                    </p>
                                </div>
                                <button type="submit" class="settings-btn settings-btn-danger">Log out everywhere</button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Sessions: one per login, so this is their login history for the last 30 days -->
                <section class="settings-card" id="sessions" aria-labelledby="admin-sessions-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-sessions-heading">Sessions</h2>
                            <p class="settings-intro">One for every time they logged in. A session lasts 30 days, unless they log out or are logged out.</p>
                        </div>
                        <span class="settings-status"><?= count($adminSessions) ?></span>
                    </div>
                    <?php if ($adminSessions === []): ?>
                        <p class="settings-hint">No sessions.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Device</th><th scope="col">Logged in</th><th scope="col">Last active</th><th scope="col">Lasts until</th><th scope="col">State</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminSessions as $session): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($session['sessionDevice'] ?? '–') ?></td>
                                            <td><?= adminTime($session['sessionGenerated']) ?></td>
                                            <td><?= adminTime($session['sessionLastSeen']) ?></td>
                                            <td><?= adminTime($session['sessionValidUntil']) ?></td>
                                            <td><?= !$session['isValid'] ? '<span class="admin-muted">Ran out</span>'
                                                : ($session['sessionTotpPending'] ? 'Waiting for the 2FA code' : 'Logged in') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Messages in every chat -->
                <section class="settings-card" id="messages" aria-labelledby="admin-messages-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-messages-heading">Messages</h2>
                            <p class="settings-intro">
                                Everything they've sent, in every chat, newest first. Deleted messages are still here, but only shown as deleted in the chat.
                                Deleting one here shows it as deleted by a site admin.
                            </p>
                        </div>
                        <span class="settings-status"><?= number_format((int) $adminMessageCounts['total']) ?></span>
                    </div>
                    <?php if ($adminMessages === []): ?>
                        <p class="settings-hint"><?= $adminMessagesBefore !== null ? 'No older messages.' : 'No messages.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-messages">
                                <thead><tr><th scope="col">Sent</th><th scope="col">Chat</th><th scope="col">Message</th><th scope="col"><span class="settings-visually-hidden">Actions</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminMessages as $message): ?>
                                        <?php $isDeleted = $message['messageDeletedTimestamp'] !== null; ?>
                                        <tr<?= $isDeleted ? ' class="is-deleted"' : '' ?>>
                                            <td class="admin-nowrap"><?= adminTime($message['messageSentTimestamp']) ?></td>
                                            <td>
                                                <a class="admin-link" href="/admin/chat?chat=<?= (int) $message['chatId'] ?>"><?= htmlspecialchars($message['chatTitle']) ?></a>
                                                <span class="admin-small">
                                                    <?= (int) $message['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group' ?><?=
                                                    (int) $message['chatStatus'] !== HBHUB_CHAT_STATUS_ACTIVE ? ', chat deleted' : '' ?>
                                                </span>
                                            </td>
                                            <td class="admin-message-text">
                                                <?= adminMessageHtml($message) ?>
                                                <?php if ($message['messageIsEdited'] || $isDeleted): ?>
                                                    <span class="admin-small"><?= $isDeleted
                                                        ? htmlspecialchars(ADMIN_MESSAGE_DELETED_LABELS[(int) $message['messageDeletedByAdmin']] ?? 'Deleted') . ' ' . adminTime($message['messageDeletedTimestamp'])
                                                        : 'Edited' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$isDeleted && $adminCanDeleteMessages): ?>
                                                    <form action="/admin/user?user=<?= $targetId ?><?= $adminMessagesBefore !== null ? '&amp;before=' . $adminMessagesBefore : '' ?>#messages"
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
                    <div class="settings-actions admin-messages-actions">
                        <?php if ($adminMessagesBefore !== null): ?>
                            <a class="settings-btn" href="/admin/user?user=<?= $targetId ?>#messages">Back to the newest</a>
                        <?php endif; ?>
                        <?php if ($adminMessagesHasOlder): ?>
                            <a class="settings-btn" href="/admin/user?user=<?= $targetId ?>&amp;before=<?= (int) end($adminMessages)['messageId'] ?>#messages">Older messages</a>
                        <?php endif; ?>
                        <?php if ($adminCanDeleteMessages && (int) $adminMessageCounts['total'] > (int) $adminMessageCounts['deleted']): ?>
                            <form action="/admin/user?user=<?= $targetId ?>#messages" method="post"
                                  data-confirm="Delete all <?= number_format((int) $adminMessageCounts['total'] - (int) $adminMessageCounts['deleted']) ?> of <?= htmlspecialchars($target['userName']) ?>'s messages? This can't be undone.">
                                <input type="hidden" name="action" value="deleteAllMessages">
                                <button type="submit" class="settings-btn settings-btn-danger">Delete all their messages</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Attachments that still take tickets, and how many tickets they have -->
                <section class="settings-card" id="attachments" aria-labelledby="admin-attachments-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-attachments-heading">Attachments</h2>
                            <p class="settings-intro">
                                The files and pictures they've sent that are still kept, newest first. Each takes one ticket per started
                                <?= HBHUB_CHAT_ATTACHMENT_TICKET_BYTES / 1024 / 1024 ?> MB (after compressing) until it runs out.
                                Deleting one deletes its file for good and gives them its tickets back straight away. The message it was in stays,
                                showing "Attachment deleted".
                            </p>
                        </div>
                        <span class="settings-status<?= $adminTicketsUsed < $adminTicketsTotal ? ' is-on' : '' ?>"><?= $adminTicketsTotal - min($adminTicketsUsed, $adminTicketsTotal) ?> of <?= $adminTicketsTotal ?> free</span>
                    </div>
                    <?php if ($adminAttachments === []): ?>
                        <p class="settings-hint">No attachments that take tickets.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Sent</th><th scope="col">Chat</th><th scope="col">File</th><th scope="col">Size</th><th scope="col">Tickets</th><th scope="col">Runs out</th><th scope="col"><span class="settings-visually-hidden">Actions</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminAttachments as $attachment): ?>
                                        <tr>
                                            <td class="admin-nowrap"><?= adminTime($attachment['attachmentUploadedTimestamp']) ?></td>
                                            <td>
                                                <a class="admin-link" href="/admin/chat?chat=<?= (int) $attachment['chatId'] ?>"><?= htmlspecialchars($attachment['chatTitle']) ?></a>
                                                <span class="admin-small">
                                                    <?= (int) $attachment['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group' ?><?=
                                                    (int) $attachment['chatStatus'] !== HBHUB_CHAT_STATUS_ACTIVE ? ', chat deleted' : '' ?>
                                                </span>
                                            </td>
                                            <td class="admin-message-text">
                                                <?= htmlspecialchars($attachment['attachmentName'] ?? 'file') ?>
                                                <span class="admin-small"><?= in_array((int) $attachment['attachmentKind'], [HBHUB_CHAT_ATTACHMENT_KIND_IMAGE, HBHUB_CHAT_ATTACHMENT_KIND_JXL], true) ? 'Picture' : 'File' ?></span>
                                            </td>
                                            <td class="admin-nowrap">
                                                <?= hbHubChatAttachmentSizeLabel((int) $attachment['attachmentSize']) ?>
                                                <?php if ((int) $attachment['attachmentStoredSize'] !== (int) $attachment['attachmentSize']): ?>
                                                    <span class="admin-small"><?= hbHubChatAttachmentSizeLabel((int) $attachment['attachmentStoredSize']) ?> stored</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int) $attachment['attachmentTickets'] ?></td>
                                            <td class="admin-nowrap"><?= adminTime($attachment['attachmentExpiresTimestamp']) ?></td>
                                            <td>
                                                <?php if ($adminCanDeleteMessages): ?>
                                                    <form action="/admin/user?user=<?= $targetId ?>#attachments" method="post"
                                                          data-confirm="Delete this attachment? Its file is deleted for good, and <?= htmlspecialchars($target['userName']) ?> gets <?= (int) $attachment['attachmentTickets'] === 1 ? 'its ticket' : 'its ' . (int) $attachment['attachmentTickets'] . ' tickets' ?> back.">
                                                        <input type="hidden" name="action" value="deleteAttachment">
                                                        <input type="hidden" name="attachment" value="<?= (int) $attachment['attachmentId'] ?>">
                                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($adminCanDeleteMessages): ?>
                            <div class="settings-actions admin-messages-actions">
                                <form action="/admin/user?user=<?= $targetId ?>#attachments" method="post"
                                      data-confirm="Delete all <?= count($adminAttachments) ?> of <?= htmlspecialchars($target['userName']) ?>'s attachments? Their files are deleted for good.">
                                    <input type="hidden" name="action" value="deleteAllAttachments">
                                    <button type="submit" class="settings-btn settings-btn-danger">Delete all their attachments</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($adminCanChange): ?>
                        <hr class="settings-divider">
                        <form action="/admin/user?user=<?= $targetId ?>#attachments" method="post" data-confirm="Change <?= htmlspecialchars($target['userName']) ?>'s attachment tickets?">
                            <input type="hidden" name="action" value="tickets">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-tickets">Add or revoke tickets</label>
                                <input type="text" id="admin-tickets" name="tickets" inputmode="numeric" maxlength="4" required autocomplete="off"
                                       value="<?= htmlspecialchars($adminValues['tickets']) ?>" aria-describedby="admin-tickets-hint">
                                <p class="settings-hint" id="admin-tickets-hint">
                                    They have <?= $adminTicketsTotal ?> <?= $adminTicketsTotal === 1 ? 'ticket' : 'tickets' ?> in all: the <?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> everyone has<?=
                                    $adminTicketsExtra > 0 ? ', plus ' . $adminTicketsExtra . ' added by site admins' : ($adminTicketsExtra < 0 ? ', minus ' . -$adminTicketsExtra . ' taken away by site admins' : '') ?>.
                                    Revoking tickets doesn't delete anything they've sent, they just can't send more until they're under it.
                                    One attachment can still take at most <?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> tickets. Nobody can have more than <?= number_format(ADMIN_TICKETS_MAX) ?>.
                                </p>
                                <?php if (isset($adminErrors['tickets'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['tickets']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger" name="direction" value="remove">Revoke tickets</button>
                                <button type="submit" class="settings-btn settings-btn-primary" name="direction" value="add">Add tickets</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <!-- Chats they're in -->
                <section class="settings-card" id="chats" aria-labelledby="admin-chats-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-chats-heading">Chats</h2>
                            <p class="settings-intro">Every chat they're in, message requests too, the newest first.</p>
                        </div>
                        <span class="settings-status"><?= count($adminChats) ?></span>
                    </div>
                    <?php if ($adminChats === []): ?>
                        <p class="settings-hint">Not in any chats.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Chat</th><th scope="col">Type</th><th scope="col">Members</th><th scope="col">Role</th><th scope="col">Joined</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminChats as $chat): ?>
                                        <tr>
                                            <td><a class="admin-link" href="/admin/chat?chat=<?= (int) $chat['chatId'] ?>"><?= htmlspecialchars($chat['chatTitle']) ?></a></td>
                                            <td><?= (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group' ?></td>
                                            <td><?= (int) $chat['memberCount'] ?></td>
                                            <td><?= $chat['userAcknowledgedJoin'] ? htmlspecialchars(ADMIN_CHAT_ROLES[(int) $chat['memberRole']] ?? 'Member') : 'Not accepted yet' ?></td>
                                            <td class="admin-nowrap"><?= adminTime($chat['memberJoinedTimestamp']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Casino chips -->
                <section class="settings-card" id="casino" aria-labelledby="admin-casino-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-casino-heading">Casino</h2>
                            <p class="settings-intro">
                                <?php if ($adminWallet === null): ?>
                                    They've never opened the casino.
                                <?php else: ?>
                                    The newest <?= ADMIN_TRANSACTIONS_SHOWN ?> changes to their chips are below, the download at the top has all of them.
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($adminWallet !== null): ?>
                            <span class="settings-status is-on"><?= number_format((int) $adminWallet['walletChips']) ?> chips</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($adminCanChange): ?>
                        <form action="/admin/user?user=<?= $targetId ?>#casino" method="post" data-confirm="Change <?= htmlspecialchars($target['userName']) ?>'s chips?">
                            <input type="hidden" name="action" value="chips">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-chips">Add or remove chips</label>
                                <input type="text" id="admin-chips" name="chips" inputmode="numeric" maxlength="13" required autocomplete="off"
                                       value="<?= htmlspecialchars($adminValues['chips']) ?>" aria-describedby="admin-chips-hint">
                                <p class="settings-hint" id="admin-chips-hint">
                                    For chips they've bought, or to take away chips got from a bug or cheating. Up to <?= number_format(ADMIN_CHIPS_MAX) ?> at once.
                                    It shows in their chip history as changed by you.<?= $adminWallet === null
                                        ? ' They\'ve never opened the casino, so they get the ' . number_format(HBHUB_CASINO_START_CHIPS) . ' starting chips first.' : '' ?>
                                </p>
                                <?php if (isset($adminErrors['chips'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['chips']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger" name="direction" value="remove">Remove chips</button>
                                <button type="submit" class="settings-btn settings-btn-primary" name="direction" value="add">Add chips</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <?php if ($adminTransactions !== []): ?>
                        <?php if ($adminCanChange): ?>
                            <hr class="settings-divider">
                        <?php endif; ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">When</th><th scope="col">Change</th><th scope="col">Chips after</th><th scope="col">Why</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminTransactions as $transaction): ?>
                                        <tr>
                                            <td class="admin-nowrap"><?= adminTime($transaction['transactionTimestamp']) ?></td>
                                            <td class="admin-nowrap"><?= (int) $transaction['transactionAmount'] > 0 ? '+' : '' ?><?= number_format((int) $transaction['transactionAmount']) ?></td>
                                            <td><?= number_format((int) $transaction['transactionBalance']) ?></td>
                                            <td><?= htmlspecialchars($transaction['adminName'] !== null
                                                ? 'By ' . $transaction['adminName'] . ' (admin)'
                                                : ($transaction['transactionGame'] !== null ? $transaction['transactionGame'] . ': ' : '') . $transaction['transactionReason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($adminCanChange): ?>
                    <!-- Ban -->
                    <section class="settings-card<?= $targetIsBanned ? '' : ' is-danger' ?>" id="ban" aria-labelledby="admin-ban-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-ban-heading"><?= $targetIsBanned ? 'Unban' : 'Ban' ?></h2>
                                <p class="settings-intro">
                                    <?php if ($targetIsBanned): ?>
                                        Banned <?= adminTime($target['userBannedTimestamp']) ?><?= (int) $target['userBannedReason'] === HBHUB_BAN_REASON_AVATAR
                                            ? ' automatically, as the profile picture they uploaded is on the <a class="admin-link" href="/admin/banned">banned pictures</a> list' : '' ?>.
                                        Unbanning lets them log in again, and their profile and picture come back.
                                        A browser marked by the ban stops being marked once they log in there.
                                        A picture their ban put on the banned pictures list comes off it.
                                    <?php else: ?>
                                        They're logged out and can't log in again. Their profile and picture are hidden, they can't be added to chats,
                                        and their messages stay. The next time they open the site, or try to log in, their browser is marked so it can't make a new account.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($targetIsBanned): ?>
                                <span class="settings-status admin-status-banned">Banned</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($targetIsBanned): ?>
                            <form action="/admin/user?user=<?= $targetId ?>#ban" method="post" data-confirm="Unban <?= htmlspecialchars($target['userName']) ?>?">
                                <input type="hidden" name="action" value="unban">
                                <div class="settings-actions">
                                    <button type="submit" class="settings-btn settings-btn-primary">Unban <?= htmlspecialchars($target['userName']) ?></button>
                                </div>
                            </form>
                        <?php else: ?>
                            <form action="/admin/user?user=<?= $targetId ?>#ban" method="post" data-confirm="Ban <?= htmlspecialchars($target['userName']) ?>?">
                                <input type="hidden" name="action" value="ban">
                                <?php if ($adminAvatarSrc !== null): ?>
                                    <div class="settings-field">
                                        <label class="settings-checkbox">
                                            <input type="checkbox" name="banAvatar" value="1" checked aria-describedby="admin-ban-avatar-hint">
                                            <span>Ban their profile picture too</span>
                                        </label>
                                        <p class="settings-hint" id="admin-ban-avatar-hint">
                                            It goes on the <a class="admin-link" href="/admin/banned">banned pictures</a> list: any account that uploads
                                            it, or a resized or slightly cropped copy, or signs up with it, is banned straight away.
                                        </p>
                                    </div>
                                <?php endif; ?>
                                <div class="settings-actions">
                                    <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid">Ban <?= htmlspecialchars($target['userName']) ?></button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <!-- Delete account -->
                    <section class="settings-card is-danger" id="delete" aria-labelledby="admin-delete-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="admin-delete-heading">Delete account</h2>
                                <p class="settings-intro" id="admin-delete-hint">
                                    Their name, picture, bio and settings are deleted and they're logged out everywhere.
                                    Their messages stay in their chats, from "<?= HBHUB_DELETED_USER_NAME ?>". This can't be undone.
                                </p>
                            </div>
                        </div>
                        <form action="/admin/user?user=<?= $targetId ?>#delete" method="post" data-confirm="Delete <?= htmlspecialchars($target['userName']) ?>'s account? This can't be undone.">
                            <input type="hidden" name="action" value="deleteAccount">
                            <div class="settings-field">
                                <label class="settings-label" for="admin-delete-name">Type their name to confirm</label>
                                <input type="text" id="admin-delete-name" name="confirmName" maxlength="35" required autocomplete="off"
                                       placeholder="<?= htmlspecialchars($target['userName']) ?>" aria-describedby="admin-delete-hint">
                                <?php if (isset($adminErrors['delete'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($adminErrors['delete']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid">Delete account</button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
