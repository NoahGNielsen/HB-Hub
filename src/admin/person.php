<?php
// Admin panel for one person: /admin/person?user=id, every account used in the same browsers as that account
// (admin.php, adminPersonGroups()), as a "lite" version of a user's admin panel (admin/user.php) across all of them.
// Site admins open it from Person at the top of a user's admin panel, or from the list of people (admin/people.php).
// Everyone else gets a 404, so it doesn't show that the page is here.
//
// Shows what there is about them together: the accounts with their status, chips and messages, the devices (browsers)
// that link the accounts, and every account's sessions. The actions are one form per card, like on a user's page:
// the gambling block and the admin block, logging out everywhere, ghost ban or lift it, ban or unban (with or without
// their pictures) and delete. Each form has a tick box for every account it can change, all ticked to begin with,
// since a shared computer (like at school) can link someone who isn't the same person: untick them. Site admins and
// deleted accounts are never in them. admin.js greys out a button while none of the ticked accounts would be changed
// by it (nothing ticked, or Unban with only accounts that aren't banned, and so on). Each account is changed like on its own page (adminAccounts.php), and logged in
// the admin log with {"personOf": id}, the account in the address. Before any form is sent, admin.js asks for a reason.
// Opening the page is logged too ('person.view'), as it shows every account's personal info.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/adminAccounts.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const PERSON_DELETE_CONFIRM = 'delete'; // typed in to delete the accounts

adminOnly($hbHubUser);

$targetId = $_GET['user'] ?? null;
if (!is_string($targetId) || !ctype_digit($targetId) || strlen($targetId) > 10) {
    adminNotFound();
}
$targetId = (int) $targetId;
$adminId = (int) $hbHubUser['userId'];

$db = hbHubDatabase();
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);
$personIds = adminPersonUserIds($db, $targetId);
$personPlaceholders = implode(',', array_fill(0, count($personIds), '?'));

// Never the password hash or the 2FA secret, just whether there is one
$statement = $db->prepare('SELECT u.userId, u.userName, u.userAvatarAttachmentId, u.userSettings, u.userStatus, u.userRole,
        u.userIpv4AdresseLastAccessed, u.userIpv6AdresseLastAccessed, u.userTotpSecret IS NOT NULL AS totpIsOn,
        u.userCreatedTimestamp, u.userLastSeenTimestamp, u.userBannedTimestamp, u.userGhostBannedTimestamp,
        COALESCE(u.userAccountLockedTimestamp + INTERVAL ? MINUTE > NOW(), 0) AS isLocked, ' . adminUserOnlineSql() . ' AS isOnline,
        w.walletChips,
        (SELECT COUNT(*) FROM `HBHub-Messages` m WHERE m.userId = u.userId AND m.messageEvent = 0) AS messageCount,
        (SELECT COUNT(*) FROM `HBHub-Sessions` s WHERE s.userId = u.userId AND s.sessionTotpPending = 0 AND s.sessionValidUntil > NOW()) AS activeSessions
    FROM `HBHub-Users` u LEFT JOIN `HBHub-CasinoWallets` w ON w.userId = u.userId
    WHERE u.userId IN (' . $personPlaceholders . ')
    ORDER BY u.userId');
$statement->execute([$adminLockoutMinutes, ...$personIds]);
$personAccounts = [];
foreach ($statement->fetchAll() as $account) {
    $personAccounts[(int) $account['userId']] = $account;
}
if (!isset($personAccounts[$targetId])) {
    adminNotFound();
}
$target = $personAccounts[$targetId];

/**
 * Whether the account can be changed from here: not deleted and not a site admin, like on a user's page.
 */
function personCanChange(array $account): bool
{
    return $account['userStatus'] !== null && (int) $account['userRole'] !== ADMIN_ROLE_ADMIN;
}

$personChangeable = array_filter($personAccounts, 'personCanChange');

/**
 * Back to the person page (at the card that was used) with a toast, so reloading doesn't send the form again.
 */
function personRedirect(int $userId, ?string $toast, string $card): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    if ($toast !== null) {
        hbHubSetToast($toast);
    }
    header('Location: /admin/person?user=' . $userId . '#' . $card, true, 303);
    exit;
}

$personErrors = []; // card => message
$personAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;
$personCards = [
    'block' => 'block', 'blockAdmin' => 'block', 'blockAdminLift' => 'block', 'logoutAll' => 'sessions',
    'ghostBan' => 'ghost', 'ghostUnban' => 'ghost', 'ban' => 'ban', 'unban' => 'ban', 'deleteAccounts' => 'delete',
];
if ($personAction !== null) {
    if (!isset($personCards[$personAction])) {
        personRedirect($targetId, null, 'overview');
    }
    $personCard = $personCards[$personAction];

    // The ticked accounts that can be changed, and of those the ones the action changes anything for
    $picked = is_array($_POST['accounts'] ?? null) ? $_POST['accounts'] : [];
    $picked = array_values(array_filter($personChangeable, fn (array $account): bool
        => in_array((string) $account['userId'], $picked, true) && match ($personAction) {
            'ban' => (int) $account['userStatus'] !== HBHUB_SESSION_STATUS_BANNED,
            'unban' => (int) $account['userStatus'] === HBHUB_SESSION_STATUS_BANNED,
            'ghostBan' => $account['userGhostBannedTimestamp'] === null,
            'ghostUnban' => $account['userGhostBannedTimestamp'] !== null,
            default => true,
        }));
    $pickedIds = array_map(fn (array $account): int => (int) $account['userId'], $picked);
    if ($pickedIds === []) {
        personRedirect($targetId, 'adminNoAccounts', $personCard);
    }
    $logData = ['personOf' => $targetId];

    switch ($personAction) {
        case 'block':
            $blockMode = $_POST['block'] ?? null;
            if (!hbHubBlockIsMode($blockMode)) {
                $personErrors['block'] = 'Please choose one of the options.';
                break;
            }
            foreach ($pickedIds as $userId) {
                adminAccountBlock($db, $adminId, $userId, $blockMode, $logData);
            }
            personRedirect($targetId, 'adminSaved', 'block');

        case 'blockAdmin':
        case 'blockAdminLift':
            $blockAdminOn = $personAction === 'blockAdmin';
            foreach ($pickedIds as $userId) {
                adminAccountBlockAdmin($db, $adminId, $userId, $blockAdminOn, $logData);
            }
            personRedirect($targetId, $blockAdminOn ? 'adminBlockedAll' : 'adminBlockLiftedAll', 'block');

        case 'logoutAll':
            foreach ($pickedIds as $userId) {
                adminAccountLogout($db, $adminId, $userId, $logData);
            }
            personRedirect($targetId, 'adminLoggedOutAll', 'sessions');

        case 'ghostBan':
        case 'ghostUnban':
            $ghostOn = $personAction === 'ghostBan';
            foreach ($pickedIds as $userId) {
                adminAccountGhostBan($db, $adminId, $userId, $ghostOn, $logData);
            }
            personRedirect($targetId, $ghostOn ? 'adminGhostBannedAll' : 'adminGhostUnbannedAll', 'ghost');

        case 'ban':
            $banAvatar = ($_POST['banAvatar'] ?? null) === '1';
            foreach ($pickedIds as $userId) {
                adminAccountBan($db, $adminId, $userId, $banAvatar, $logData);
            }
            hbHubBannedAvatarFillFingerprints($db); // after the commits, so the bans don't wait on it
            personRedirect($targetId, 'adminBannedAll', 'ban');

        case 'unban':
            foreach ($pickedIds as $userId) {
                adminAccountUnban($db, $adminId, $userId, $logData);
            }
            personRedirect($targetId, 'adminUnbannedAll', 'ban');

        case 'deleteAccounts':
            $confirm = is_string($_POST['confirm'] ?? null) ? mb_strtolower(trim($_POST['confirm'])) : '';
            if ($confirm !== PERSON_DELETE_CONFIRM) {
                $personErrors['delete'] = 'Type "' . PERSON_DELETE_CONFIRM . '" to delete the accounts.';
                break;
            }
            foreach ($pickedIds as $userId) {
                adminAccountDelete($db, $adminId, $userId, $logData);
            }
            // A deleted account has no known devices left, so the rest of the person may not be linked to it anymore
            personRedirect($targetId, 'adminAccountsDeleted', 'overview');
    }
}

adminLogView($db, $adminId, 'person.view', $targetId, null);

// The browsers that link the accounts: deviceTokenHash => [the accounts known in it, first and last login there]
$statement = $db->prepare('SELECT deviceTokenHash, userId, deviceCreatedTimestamp, deviceLastUsedTimestamp
    FROM `HBHub-LoginDevices` WHERE userId IN (' . $personPlaceholders . ') ORDER BY deviceLastUsedTimestamp DESC');
$statement->execute($personIds);
$personDevices = [];
foreach ($statement->fetchAll() as $row) {
    $device = &$personDevices[$row['deviceTokenHash']];
    $device ??= ['userIds' => [], 'first' => $row['deviceCreatedTimestamp'], 'last' => $row['deviceLastUsedTimestamp']];
    $device['userIds'][] = (int) $row['userId'];
    $device['first'] = min($device['first'], $row['deviceCreatedTimestamp']);
    $device['last'] = max($device['last'], $row['deviceLastUsedTimestamp']);
    unset($device);
}

// Every account's sessions, newest first. The ids are left out: they'd log you in as the user.
$statement = $db->prepare('SELECT userId, sessionDevice, sessionGenerated, sessionLastSeen, sessionValidUntil, sessionTotpPending, sessionValidUntil > NOW() AS isValid
    FROM `HBHub-Sessions` WHERE userId IN (' . $personPlaceholders . ') ORDER BY sessionGenerated DESC');
$statement->execute($personIds);
$personSessions = $statement->fetchAll();

$personCount = count($personAccounts);
$personChips = array_sum(array_map(fn (array $account): int => (int) $account['walletChips'], $personAccounts));
$personMessages = array_sum(array_column($personAccounts, 'messageCount'));
$personActiveSessions = array_sum(array_column($personAccounts, 'activeSessions'));
$personBanned = count(array_filter($personAccounts, fn (array $account): bool => (int) $account['userStatus'] === HBHUB_SESSION_STATUS_BANNED));
$personGhosts = count(array_filter($personAccounts, fn (array $account): bool => $account['userGhostBannedTimestamp'] !== null));
$personOnline = count(array_filter($personAccounts, fn (array $account): bool => $account['userStatus'] !== null && (bool) $account['isOnline']));
$personAvatarSrc = hbHubAvatarUrl($targetId, $target['userAvatarAttachmentId'] !== null ? (int) $target['userAvatarAttachmentId'] : null);
$personHasChangeable = $personChangeable !== [];
$personHasAvatars = array_filter($personChangeable, fn (array $account): bool => $account['userAvatarAttachmentId'] !== null) !== [];

/**
 * A tick box for every account a form on the page can change, all ticked to begin with, with its status so it's clear
 * which ones the action does anything to. $card keeps the ids apart between the forms. data-person-state lists what
 * the account is now ("banned ghost adminBlock"), so admin.js can grey out the buttons that wouldn't change any of the
 * ticked ones (data-person-needs on the button).
 */
function personAccountsFieldHtml(array $accounts, string $card): string
{
    $html = '<fieldset class="settings-field admin-person-pick"><legend class="settings-label">Accounts</legend>';
    foreach ($accounts as $account) {
        $id = 'person-' . $card . '-' . (int) $account['userId'];
        $state = array_keys(array_filter([
            'banned' => (int) $account['userStatus'] === HBHUB_SESSION_STATUS_BANNED,
            'ghost' => $account['userGhostBannedTimestamp'] !== null,
            'adminBlock' => hbHubBlockIsAdmin(hbHubUserSettings($account['userSettings'])),
        ]));
        $html .= '<label class="settings-checkbox" for="' . $id . '">'
            . '<input type="checkbox" id="' . $id . '" name="accounts[]" value="' . (int) $account['userId'] . '" checked'
            . ' data-person-state="' . implode(' ', $state) . '">'
            . '<span>' . htmlspecialchars($account['userName']) . '</span> ' . adminStatusHtml(adminUserStatus($account)) . '</label>';
    }
    return $html . '<p class="settings-hint">Untick an account that isn\'t theirs, like one only linked by a shared computer.</p></fieldset>';
}

/**
 * The names of the accounts, linked to their admin panels, for the Devices table.
 */
function personNamesHtml(array $userIds, array $accounts): string
{
    return implode(', ', array_map(fn (int $userId): string => '<a class="admin-link" href="/admin/user?user=' . $userId . '">'
        . htmlspecialchars($accounts[$userId]['userName'] ?? '#' . $userId) . '</a>', $userIds));
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
    <title><?= htmlspecialchars($target['userName']) ?> (person) - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Links to the cards, in the order they're on the page -->
            <?= adminPageMenuHtml('/admin/people', 'All people', $personAvatarSrc, $target['userName'],
                '<span class="settings-status">' . $personCount . ' ' . ($personCount === 1 ? 'account' : 'accounts') . '</span>', [
                'Overview' => ['overview' => 'Information', 'accounts' => 'Accounts', 'devices' => 'Devices'],
                'Manage' => $personHasChangeable ? ['block' => 'Gambling block'] : [],
                'Activity' => ['sessions' => 'Sessions'],
                ADMIN_MENU_DANGER => $personHasChangeable ? ['ghost' => 'Ghost ban', 'ban' => 'Ban', 'delete' => 'Delete accounts'] : [],
            ]) ?>

            <div class="settings-content">
                <!-- Who it is, and what there is to know about them across their accounts -->
                <section class="settings-card" id="overview" aria-labelledby="person-heading">
                    <div class="admin-header">
                        <?php if ($personAvatarSrc !== null): ?>
                            <img class="settings-avatar-preview" src="<?= htmlspecialchars($personAvatarSrc) ?>" alt="Profile picture of <?= htmlspecialchars($target['userName']) ?>">
                        <?php else: ?>
                            <span class="settings-avatar-preview settings-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($target['userName'], 0, 1))) ?></span>
                        <?php endif; ?>
                        <div class="admin-header-text">
                            <h1 id="person-heading">
                                <?= htmlspecialchars($target['userName']) ?>
                                <span class="settings-status"><?= $personCount === 1 ? 'Only account' : 'And ' . ($personCount - 1) . ' other ' . ($personCount === 2 ? 'account' : 'accounts') ?></span>
                            </h1>
                            <div class="admin-header-links">
                                <a class="settings-btn settings-btn-small" href="/admin/user?user=<?= $targetId ?>">Open <?= htmlspecialchars($target['userName']) ?>'s admin panel</a>
                                <a class="settings-btn settings-btn-small" href="/admin/log?user=<?= $targetId ?>">Admin log</a>
                            </div>
                        </div>
                    </div>

                    <hr class="settings-divider">

                    <h2 class="settings-visually-hidden">Information</h2>
                    <dl class="admin-details">
                        <div><dt>Accounts</dt><dd><?= $personCount ?></dd></div>
                        <div><dt>Devices</dt><dd><?= count($personDevices) ?></dd></div>
                        <div><dt>Online now</dt><dd><?= $personOnline ?></dd></div>
                        <div><dt>Banned</dt><dd><?= $personBanned ?> of <?= $personCount ?></dd></div>
                        <div><dt>Ghost banned</dt><dd><?= $personGhosts ?> of <?= $personCount ?></dd></div>
                        <div><dt>First account made</dt><dd><?= adminTime(min(array_column($personAccounts, 'userCreatedTimestamp'))) ?></dd></div>
                        <div><dt>Last seen</dt><dd><?= adminTime(max(array_column($personAccounts, 'userLastSeenTimestamp'))) ?></dd></div>
                        <div><dt>Chips in all</dt><dd><?= number_format($personChips) ?></dd></div>
                        <div><dt>Messages sent in all</dt><dd><?= number_format($personMessages) ?></dd></div>
                        <div><dt>Logged in sessions</dt><dd><?= $personActiveSessions ?></dd></div>
                    </dl>

                    <?php if ($personCount === 1): ?>
                        <p class="settings-notice admin-overview-notice">
                            No other account has been used in the same browser as this one, so as far as the site can tell, it's their only account.
                        </p>
                    <?php elseif (!$personHasChangeable): ?>
                        <p class="settings-notice admin-overview-notice">These accounts are all deleted or site admins, so there's nothing to change here.</p>
                    <?php endif; ?>
                </section>

                <!-- Every account, side by side -->
                <section class="settings-card" id="accounts" aria-labelledby="person-accounts-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="person-accounts-heading">Accounts</h2>
                            <p class="settings-intro">
                                Every account that's been used in the same browser as another one of them, the oldest first. Open one for
                                everything about it, like its messages, chats, attachments and chip history.
                            </p>
                        </div>
                        <span class="settings-status"><?= $personCount ?></span>
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th scope="col">User</th><th scope="col">Status</th><th scope="col">Joined</th><th scope="col">Last seen</th><th scope="col">Last IP</th>
                                <th scope="col">Chips</th><th scope="col">Messages</th><th scope="col">Gambling block</th><th scope="col">2FA</th></tr></thead>
                            <tbody>
                                <?php foreach ($personAccounts as $userId => $account): ?>
                                    <?php
                                    $accountSettings = hbHubUserSettings($account['userSettings']);
                                    $accountBlock = hbHubBlockSettings($accountSettings);
                                    ?>
                                    <tr>
                                        <td>
                                            <a class="admin-link" href="/admin/user?user=<?= $userId ?>"><?= htmlspecialchars($account['userName']) ?></a>
                                            <?php if ((int) $account['userRole'] === ADMIN_ROLE_ADMIN): ?>
                                                <span class="admin-small">Site admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= adminStatusHtml(adminUserStatus($account)) ?></td>
                                        <td class="admin-nowrap"><?= adminTime($account['userCreatedTimestamp']) ?></td>
                                        <td class="admin-nowrap"><?= adminTime($account['userLastSeenTimestamp']) ?></td>
                                        <td class="admin-mono"><?= htmlspecialchars(adminIps($account['userIpv4AdresseLastAccessed'], $account['userIpv6AdresseLastAccessed'])) ?></td>
                                        <td><?= $account['walletChips'] !== null ? number_format((int) $account['walletChips']) : '–' ?></td>
                                        <td><?= number_format((int) $account['messageCount']) ?></td>
                                        <td>
                                            <?= htmlspecialchars(HBHUB_BLOCK_MODES[$accountBlock['mode']]) ?>
                                            <?php if (hbHubBlockIsAdmin($accountSettings)): ?>
                                                <span class="admin-small">and blocked by a site admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $account['totpIsOn'] ? 'On' : 'Off' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- The browsers that link the accounts -->
                <section class="settings-card" id="devices" aria-labelledby="person-devices-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="person-devices-heading">Devices</h2>
                            <p class="settings-intro">
                                The browsers the accounts have been logged in to from, known by a cookie (not by IP address, which
                                everyone at school shares), the most recently used first. Accounts in the same row were used in the
                                same browser. A computer lots of people use, like one at school, links accounts that aren't the
                                same person, so leave those out when changing them below.
                            </p>
                        </div>
                        <span class="settings-status"><?= count($personDevices) ?></span>
                    </div>
                    <?php if ($personDevices === []): ?>
                        <p class="settings-hint">No known devices. The account hasn't been used since devices started being recorded.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Device</th><th scope="col">Accounts used in it</th><th scope="col">First login</th><th scope="col">Last login</th></tr></thead>
                                <tbody>
                                    <?php foreach ($personDevices as $hash => $device): ?>
                                        <tr>
                                            <!-- The start of the cookie's hash, just to tell them apart -->
                                            <td class="admin-mono"><?= htmlspecialchars(substr((string) $hash, 0, 8)) ?></td>
                                            <td><?= personNamesHtml($device['userIds'], $personAccounts) ?><?= count($device['userIds']) > 1
                                                ? ' <span class="admin-small">(' . count($device['userIds']) . ' accounts)</span>' : '' ?></td>
                                            <td class="admin-nowrap"><?= adminTime($device['first']) ?></td>
                                            <td class="admin-nowrap"><?= adminTime($device['last']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($personHasChangeable): ?>
                    <!-- Gambling block and admin block, straight away -->
                    <section class="settings-card" id="block" aria-labelledby="person-block-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="person-block-heading">Gambling and Games block</h2>
                                <p class="settings-intro">
                                    Changes straight away for the ticked accounts, also when it's turned down: there's no wait like when
                                    they change it themselves. Each account's block now is under <a class="admin-link" href="#accounts">Accounts</a>.
                                </p>
                            </div>
                        </div>
                        <form action="/admin/person?user=<?= $targetId ?>#block" method="post" data-confirm="Change the gambling block of the ticked accounts?">
                            <input type="hidden" name="action" value="block">
                            <div class="settings-field">
                                <label class="settings-label" for="person-block">Block</label>
                                <select id="person-block" name="block">
                                    <?php foreach (HBHUB_BLOCK_MODES as $mode => $label): ?>
                                        <option value="<?= $mode ?>"<?= hbHubBlockSettings(hbHubUserSettings($target['userSettings']))['mode'] === $mode ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($personErrors['block'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($personErrors['block']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?= personAccountsFieldHtml($personChangeable, 'block') ?>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary" data-person-needs="">Save</button>
                            </div>
                        </form>

                        <hr class="settings-divider">
                        <!-- Admin block: on top of the one above, and they can't lift it themselves -->
                        <form action="/admin/person?user=<?= $targetId ?>#block" method="post">
                            <div class="settings-field">
                                <span class="settings-label">Admin block</span>
                                <p class="settings-hint">
                                    Blocks Gambling and Games for the ticked accounts all the time, straight away, also games and tables they
                                    have open. Unlike the block above, they can't turn it off in their settings: only a site admin can lift it.
                                </p>
                            </div>
                            <?= personAccountsFieldHtml($personChangeable, 'block-admin') ?>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary" name="action" value="blockAdminLift" data-person-needs="adminBlock"
                                        data-confirm="Lift the admin block on Gambling and Games for the ticked accounts?">Lift admin block</button>
                                <button type="submit" class="settings-btn settings-btn-danger" name="action" value="blockAdmin" data-person-needs="!adminBlock"
                                        data-confirm="Block Gambling and Games for the ticked accounts?">Block Gambling and Games</button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <!-- Sessions of every account -->
                <section class="settings-card" id="sessions" aria-labelledby="person-sessions-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="person-sessions-heading">Sessions</h2>
                            <p class="settings-intro">One for every time one of the accounts logged in, newest first. A session lasts 30 days, unless they log out or are logged out.</p>
                        </div>
                        <span class="settings-status"><?= count($personSessions) ?></span>
                    </div>
                    <?php if ($personSessions === []): ?>
                        <p class="settings-hint">No sessions.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Account</th><th scope="col">Device</th><th scope="col">Logged in</th><th scope="col">Last active</th><th scope="col">Lasts until</th><th scope="col">State</th></tr></thead>
                                <tbody>
                                    <?php foreach ($personSessions as $session): ?>
                                        <tr>
                                            <td><?= personNamesHtml([(int) $session['userId']], $personAccounts) ?></td>
                                            <td><?= htmlspecialchars($session['sessionDevice'] ?? '–') ?></td>
                                            <td class="admin-nowrap"><?= adminTime($session['sessionGenerated']) ?></td>
                                            <td class="admin-nowrap"><?= adminTime($session['sessionLastSeen']) ?></td>
                                            <td class="admin-nowrap"><?= adminTime($session['sessionValidUntil']) ?></td>
                                            <td><?= !$session['isValid'] ? '<span class="admin-muted">Ran out</span>'
                                                : ($session['sessionTotpPending'] ? 'Waiting for the 2FA code' : 'Logged in') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($personHasChangeable): ?>
                        <hr class="settings-divider">
                        <form action="/admin/person?user=<?= $targetId ?>#sessions" method="post" data-confirm="Log the ticked accounts out everywhere?">
                            <input type="hidden" name="action" value="logoutAll">
                            <div class="settings-field">
                                <span class="settings-label">Log out of every session</span>
                                <p class="settings-hint">The ticked accounts are logged out on every device. They can log in again with their passwords.</p>
                            </div>
                            <?= personAccountsFieldHtml($personChangeable, 'logout') ?>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger" data-person-needs="">Log out everywhere</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <?php if ($personHasChangeable): ?>
                    <!-- Ghost ban: what they do in chats only shows to themselves (see the top of assets/php/chat.php) -->
                    <section class="settings-card is-danger" id="ghost" aria-labelledby="person-ghost-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="person-ghost-heading">Ghost ban</h2>
                                <p class="settings-intro">
                                    Like on a user's page, for every ticked account: they aren't told, and the chat works like always for them,
                                    but what they do in it only shows to themselves. Lifting it makes what they do from now on show again.
                                    Accounts that already are (or aren't) ghost banned are left as they are.
                                </p>
                            </div>
                            <?php if ($personGhosts > 0): ?>
                                <span class="settings-status admin-status-ghost"><?= $personGhosts ?> ghost banned</span>
                            <?php endif; ?>
                        </div>
                        <form action="/admin/person?user=<?= $targetId ?>#ghost" method="post">
                            <?= personAccountsFieldHtml($personChangeable, 'ghost') ?>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary" name="action" value="ghostUnban" data-person-needs="ghost"
                                        data-confirm="Lift the ghost ban of the ticked accounts?">Lift ghost ban</button>
                                <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid" name="action" value="ghostBan" data-person-needs="!ghost"
                                        data-confirm="Ghost ban the ticked accounts?">Ghost ban</button>
                            </div>
                        </form>
                    </section>

                    <!-- Ban -->
                    <section class="settings-card is-danger" id="ban" aria-labelledby="person-ban-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="person-ban-heading">Ban</h2>
                                <p class="settings-intro">
                                    Like on a user's page, for every ticked account: they're logged out and can't log in again, their profiles
                                    and pictures are hidden, and the next time they open the site, their browser is marked so it can't make a
                                    new account. Unbanning lets them log in again. Accounts that already are (or aren't) banned are left as they are.
                                </p>
                            </div>
                            <?php if ($personBanned > 0): ?>
                                <span class="settings-status admin-status-banned"><?= $personBanned ?> banned</span>
                            <?php endif; ?>
                        </div>
                        <form action="/admin/person?user=<?= $targetId ?>#ban" method="post">
                            <?php if ($personHasAvatars): ?>
                                <div class="settings-field">
                                    <label class="settings-checkbox">
                                        <input type="checkbox" name="banAvatar" value="1" aria-describedby="person-ban-avatar-hint">
                                        <span>Ban their profile pictures too</span>
                                    </label>
                                    <p class="settings-hint" id="person-ban-avatar-hint">
                                        When banning: they go on the <a class="admin-link" href="/admin/banned">banned pictures</a> list, so any
                                        account that uploads one, or a resized or slightly cropped copy, or signs up with it, is banned straight away.
                                    </p>
                                </div>
                            <?php endif; ?>
                            <?= personAccountsFieldHtml($personChangeable, 'ban') ?>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-primary" name="action" value="unban" data-person-needs="banned"
                                        data-confirm="Unban the ticked accounts?">Unban</button>
                                <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid" name="action" value="ban" data-person-needs="!banned"
                                        data-confirm="Ban the ticked accounts?">Ban</button>
                            </div>
                        </form>
                    </section>

                    <!-- Delete accounts -->
                    <section class="settings-card is-danger" id="delete" aria-labelledby="person-delete-heading">
                        <div class="settings-card-head">
                            <div>
                                <h2 id="person-delete-heading">Delete accounts</h2>
                                <p class="settings-intro" id="person-delete-hint">
                                    The ticked accounts' names, pictures, bios and settings are deleted and they're logged out everywhere.
                                    Their messages stay in their chats, from "<?= HBHUB_DELETED_USER_NAME ?>". This can't be undone.
                                </p>
                            </div>
                        </div>
                        <form action="/admin/person?user=<?= $targetId ?>#delete" method="post" data-confirm="Delete the ticked accounts? This can't be undone.">
                            <input type="hidden" name="action" value="deleteAccounts">
                            <?= personAccountsFieldHtml($personChangeable, 'delete') ?>
                            <div class="settings-field">
                                <label class="settings-label" for="person-delete-confirm">Type "<?= PERSON_DELETE_CONFIRM ?>" to confirm</label>
                                <input type="text" id="person-delete-confirm" name="confirm" maxlength="20" required autocomplete="off"
                                       placeholder="<?= PERSON_DELETE_CONFIRM ?>" aria-describedby="person-delete-hint">
                                <?php if (isset($personErrors['delete'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($personErrors['delete']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid" data-person-needs="">Delete accounts</button>
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
