<?php
// Admin panel start page: /admin/. Site admins open it from Admin in the side bar (navPanel.php), and get back to it
// from the top of a user's or a chat's admin panel. Everyone else gets a 404, so it doesn't show that the page is here.
//
// Lists every user (?list=users, the default) or every chat (?list=chats), to pick one and open its admin panel:
// admin/user.php for a user, admin/chat.php for a chat. Deleted accounts and chats are in the lists too, at the end.
// ?q= searches the list by name (a chat by its name or its members' names), or by id when it's a number,
// and ?page= goes through it ADMIN_LIST_SHOWN at a time. The side menu (adminListsMenuHtml()) switches between the two,
// and goes to the other lists, Banned pictures and Feedback, which are their own pages (admin/banned.php, admin/feedback.php).
// The pictures in the lists are loaded by admin.js two at a time (data-queued-picture), as each one is a PHP request.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const ADMIN_LIST_SHOWN = 50; // per page
const ADMIN_SEARCH_MAX = 35; // as long as a name can be

adminOnly($hbHubUser);

$db = hbHubDatabase();
[, $adminLockoutMinutes] = hbHubLoginLimits($siteConfig);

$adminList = ($_GET['list'] ?? null) === 'chats' ? 'chats' : 'users';
$adminSearch = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, ADMIN_SEARCH_MAX) : '';
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;

/**
 * The WHERE for the search and its parameters: a name containing it in any case (% and _ in it only match themselves),
 * or the id when it's a number. Nothing when there's no search.
 *
 * @param string[] $nameConditions the names to search, any one of them, as SQL with one "LIKE ?" each
 * @return array{0: string, 1: list<mixed>} [sql, parameters]
 */
function adminSearchWhere(string $search, array $nameConditions, string $idColumn): array
{
    if ($search === '') {
        return ['', []];
    }

    $like = '%' . addcslashes($search, '%_\\') . '%';
    $conditions = $nameConditions;
    $parameters = array_fill(0, count($nameConditions), $like);
    if (ctype_digit($search) && strlen($search) <= 10) {
        $conditions[] = $idColumn . ' = ?';
        $parameters[] = (int) $search;
    }
    return [' WHERE (' . implode(' OR ', $conditions) . ')', $parameters];
}

/**
 * The page to show of a list with $total in it: $page, or the last one when $page is past the end.
 */
function adminPageOf(int $page, int $total): int
{
    return min($page, max(1, intdiv($total + ADMIN_LIST_SHOWN - 1, ADMIN_LIST_SHOWN)));
}

/**
 * A page of the users the search finds (all of them without one), by name with deleted accounts last,
 * with how many it found and the page shown.
 *
 * @return array{0: list<array>, 1: int, 2: int} [users, total, page]
 */
function adminUserList(PDO $db, string $search, int $page, int $lockoutMinutes): array
{
    [$where, $parameters] = adminSearchWhere($search, ['userName LIKE ?'], 'userId');

    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users`' . $where);
    $statement->execute($parameters);
    $total = (int) $statement->fetchColumn();
    $page = adminPageOf($page, $total);

    $statement = $db->prepare('SELECT userId, userName, userAvatarAttachmentId, userStatus, userRole, userCreatedTimestamp,
            userLastSeenTimestamp, COALESCE(userAccountLockedTimestamp + INTERVAL ? MINUTE > NOW(), 0) AS isLocked
        FROM `HBHub-Users`' . $where . '
        ORDER BY userStatus IS NULL, userName, userId
        LIMIT ' . ADMIN_LIST_SHOWN . ' OFFSET ' . (($page - 1) * ADMIN_LIST_SHOWN));
    $statement->execute([$lockoutMinutes, ...$parameters]);
    return [$statement->fetchAll(), $total, $page];
}

/**
 * A page of the chats the search finds (all of them without one), the newest activity first with deleted chats last,
 * with how many it found and the page shown.
 *
 * @return array{0: list<array>, 1: int, 2: int} [chats, total, page]
 */
function adminChatList(PDO $db, string $search, int $page): array
{
    [$where, $parameters] = adminSearchWhere($search, [
        'c.chatName LIKE ?',
        'EXISTS (SELECT 1 FROM `HBHub-ChatMembers` sm JOIN `HBHub-Users` su ON su.userId = sm.userId
            WHERE sm.chatId = c.chatId AND su.userName LIKE ?)',
    ], 'c.chatId');

    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Chats` c' . $where);
    $statement->execute($parameters);
    $total = (int) $statement->fetchColumn();
    $page = adminPageOf($page, $total);

    $statement = $db->prepare('SELECT c.chatId, c.chatType, c.chatStatus, c.chatIconAttachmentId, ' . adminChatTitleSql() . ' AS chatTitle,
            COALESCE(c.lastMessageTimestamp, c.chatCreatedTimestamp) AS chatActivity,
            (SELECT COUNT(*) FROM `HBHub-ChatMembers` x WHERE x.chatId = c.chatId) AS memberCount
        FROM `HBHub-Chats` c' . $where . '
        ORDER BY c.chatStatus <> ' . HBHUB_CHAT_STATUS_ACTIVE . ', chatActivity DESC, c.chatId DESC
        LIMIT ' . ADMIN_LIST_SHOWN . ' OFFSET ' . (($page - 1) * ADMIN_LIST_SHOWN));
    $statement->execute($parameters);
    return [$statement->fetchAll(), $total, $page];
}

/**
 * The address of a page of the list, with the search kept, ready for an href.
 */
function adminListUrl(string $list, string $search = '', int $page = 1): string
{
    $query = ['list' => $list] + ($search !== '' ? ['q' => $search] : []) + ($page > 1 ? ['page' => $page] : []);
    return htmlspecialchars('/admin/?' . http_build_query($query));
}

[$adminRows, $adminFound, $adminPage] = $adminList === 'users'
    ? adminUserList($db, $adminSearch, $adminPage, $adminLockoutMinutes)
    : adminChatList($db, $adminSearch, $adminPage);
$adminPages = max(1, intdiv($adminFound + ADMIN_LIST_SHOWN - 1, ADMIN_LIST_SHOWN));
$adminNoun = $adminList === 'users' ? 'users' : 'chats';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title><?= $adminList === 'users' ? 'Users' : 'Chats' ?> - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Users, chats, banned pictures or feedback, each with how many there are in all (new feedback only) -->
            <?= adminListsMenuHtml($db, $adminList) ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading"><?= $adminList === 'users' ? 'Users' : 'Chats' ?></h2>
                            <p class="settings-intro"><?= $adminList === 'users'
                                ? 'Everyone with an account, deleted accounts last. Open one to see and change everything about it.'
                                : 'Every DM and group, the newest activity first and deleted chats last. Open one to see its members and messages.' ?></p>
                        </div>
                    </div>

                    <form class="admin-search" action="/admin/" method="get" role="search">
                        <input type="hidden" name="list" value="<?= $adminList ?>">
                        <label class="settings-visually-hidden" for="admin-search">Search <?= $adminNoun ?></label>
                        <input type="search" id="admin-search" name="q" maxlength="<?= ADMIN_SEARCH_MAX ?>" autocomplete="off"
                               value="<?= htmlspecialchars($adminSearch) ?>"
                               placeholder="<?= $adminList === 'users' ? 'Search by name or ID' : 'Search by name, member or ID' ?>">
                        <button type="submit" class="settings-btn settings-btn-primary">Search</button>
                        <?php if ($adminSearch !== ''): ?>
                            <a class="settings-btn" href="<?= adminListUrl($adminList) ?>">Clear</a>
                        <?php endif; ?>
                    </form>

                    <?php if ($adminRows === []): ?>
                        <p class="settings-hint"><?= $adminSearch !== ''
                            ? 'No ' . $adminNoun . ' match "' . htmlspecialchars($adminSearch) . '".'
                            : 'There are no ' . $adminNoun . ' yet.' ?></p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_LIST_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_LIST_SHOWN + count($adminRows)) ?>
                            of <?= number_format($adminFound) ?><?= $adminSearch !== '' ? ' matching "' . htmlspecialchars($adminSearch) . '"' : '' ?>
                        </p>
                        <div class="admin-table-wrap">
                            <?php if ($adminList === 'users'): ?>
                                <table class="admin-table admin-list">
                                    <thead><tr><th scope="col">User</th><th scope="col">Status</th><th scope="col">Joined</th><th scope="col">Last seen</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($adminRows as $user): ?>
                                            <?php $listUserId = (int) $user['userId']; ?>
                                            <tr>
                                                <td>
                                                    <a class="admin-list-link" href="/admin/user?user=<?= $listUserId ?>">
                                                        <?= adminAvatarHtml(hbHubAvatarUrl($listUserId, $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null), $user['userName'], true) ?>
                                                        <span class="admin-list-name"><?= htmlspecialchars($user['userName']) ?></span>
                                                    </a>
                                                    <?php if ((int) $user['userRole'] === ADMIN_ROLE_ADMIN): ?>
                                                        <span class="admin-small">Site admin</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= adminStatusHtml(adminUserStatus($user)) ?></td>
                                                <td class="admin-nowrap"><?= adminTime($user['userCreatedTimestamp']) ?></td>
                                                <td class="admin-nowrap"><?= adminTime($user['userLastSeenTimestamp']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <table class="admin-table admin-list">
                                    <thead><tr><th scope="col">Chat</th><th scope="col">Type</th><th scope="col">Members</th><th scope="col">Last activity</th><th scope="col">Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($adminRows as $chat): ?>
                                            <?php $listChatId = (int) $chat['chatId']; ?>
                                            <tr>
                                                <td>
                                                    <a class="admin-list-link" href="/admin/chat?chat=<?= $listChatId ?>">
                                                        <?= adminAvatarHtml(adminChatIconUrl($listChatId, $chat['chatIconAttachmentId']), $chat['chatTitle'], true) ?>
                                                        <span class="admin-list-name"><?= htmlspecialchars($chat['chatTitle']) ?></span>
                                                    </a>
                                                </td>
                                                <td><?= (int) $chat['chatType'] === HBHUB_CHAT_TYPE_DM ? 'DM' : 'Group' ?></td>
                                                <td><?= (int) $chat['memberCount'] ?></td>
                                                <td class="admin-nowrap"><?= adminTime($chat['chatActivity']) ?></td>
                                                <td><?= adminStatusHtml(adminChatStatus((int) $chat['chatStatus'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="<?= adminListUrl($adminList, $adminSearch, $adminPage - 1) ?>" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="<?= adminListUrl($adminList, $adminSearch, $adminPage + 1) ?>" rel="next">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
