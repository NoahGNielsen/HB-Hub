<?php
// Admin panel list of people: /admin/people. Site admins open it from People in the admin panel's side menu
// (adminListsMenuHtml()). Everyone else gets a 404, so it doesn't show that the page is here.
//
// Lists everyone with more than one account: accounts that have logged in from the same browser are taken to be one
// person (admin.php, adminPersonGroups()). Each row shows their accounts with their status, how many browsers they
// share and when one of them was last seen, newest account first, and opens the person page (admin/person.php),
// where they can all be dealt with at once. A shared computer (like at school) links people too, so the person page
// shows which devices link which accounts. The pictures load two at a time from pictureQueue.js, like in the other lists.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

adminOnly($hbHubUser);

$db = hbHubDatabase();
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);
$adminPeople = adminPersonGroups($db);

// Every account in the list in one go, by id
$peopleUsers = [];
$peopleIds = array_merge(...array_column($adminPeople, 'userIds'));
if ($peopleIds !== []) {
    $statement = $db->prepare('SELECT userId, userName, userAvatarAttachmentId, userStatus, userRole, userLastSeenTimestamp,
            userGhostBannedTimestamp, COALESCE(userAccountLockedTimestamp + INTERVAL ? MINUTE > NOW(), 0) AS isLocked,
            ' . adminUserOnlineSql() . ' AS isOnline
        FROM `HBHub-Users` WHERE userId IN (' . implode(',', array_fill(0, count($peopleIds), '?')) . ')');
    $statement->execute([$adminLockoutMinutes, ...$peopleIds]);
    foreach ($statement->fetchAll() as $user) {
        $peopleUsers[(int) $user['userId']] = $user;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <title>People - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'people') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" aria-labelledby="admin-people-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-people-heading">People</h2>
                            <p class="settings-intro">
                                Everyone with more than one account: accounts that have been logged in to from the same browser
                                count as one person, the newest account first. Open one to see all their accounts together, and
                                ban, block or log them out all at once. A shared computer, like one at school, links different
                                people too, so check the devices on their page first.
                            </p>
                        </div>
                        <span class="settings-status"><?= count($adminPeople) ?></span>
                    </div>

                    <?php if ($adminPeople === []): ?>
                        <p class="settings-hint">Nobody has more than one account (that has been used in the same browser).</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">Accounts</th><th scope="col">Devices</th><th scope="col">Last seen</th><th scope="col"><span class="settings-visually-hidden">Open</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminPeople as $person): ?>
                                        <?php
                                        $personUsers = array_values(array_filter(array_map(fn (int $id): ?array => $peopleUsers[$id] ?? null, $person['userIds'])));
                                        $personLastSeen = max(array_column($personUsers, 'userLastSeenTimestamp') ?: [null]);
                                        ?>
                                        <tr>
                                            <td>
                                                <ul class="admin-person-accounts">
                                                    <?php foreach ($personUsers as $user): ?>
                                                        <?php $listUserId = (int) $user['userId']; ?>
                                                        <li>
                                                            <a class="admin-list-link" href="/admin/user?user=<?= $listUserId ?>">
                                                                <?= adminAvatarHtml(hbHubAvatarUrl($listUserId, $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null), $user['userName'], true) ?>
                                                                <span class="admin-list-name"><?= htmlspecialchars($user['userName']) ?></span>
                                                            </a>
                                                            <?= adminStatusHtml(adminUserStatus($user)) ?>
                                                            <?php if ((int) $user['userRole'] === ADMIN_ROLE_ADMIN): ?>
                                                                <span class="admin-small">Site admin</span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </td>
                                            <td><?= (int) $person['devices'] ?></td>
                                            <td class="admin-nowrap"><?= adminTime($personLastSeen) ?></td>
                                            <td><a class="settings-btn settings-btn-small" href="/admin/person?user=<?= (int) $person['userIds'][0] ?>">Open</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
