<?php
// Admin panel's log: /admin/log, the last list in the admin panel's side menu (adminListsMenuHtml()), under Feedback.
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// Everything site admins have done in the admin panel, newest first, ADMIN_LOG_SHOWN at a time (?page=): opening a
// user's or chat's admin panel (once per ADMIN_LOG_VIEW_MINUTES), downloading a user's data, and every change, plus
// the automatic bans for a banned profile picture (no admin, shown as "Automatic"), each change with the reason the
// admin gave in admin.js's popup under it (when they gave one). The rows are written by the admin
// pages with adminLog() (assets/php/adminLog.php), the automatic bans by bannedAvatars.php. Nothing here changes them: the log can't be
// edited or deleted from the site, and rows older than ADMIN_LOG_KEEP_DAYS are removed (here and by cron/nightly.php).
// ?user=id shows only what was done by or to that user, ?chat=id only what was done to that chat. The "Admin log"
// buttons at the top of a user's and a chat's admin panel link here with those.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_LOG_SHOWN = 50; // per page

adminOnly($hbHubUser);

$db = hbHubDatabase();
adminLogSweep($db); // in case the nightly clean-up hasn't run

/**
 * A whole number from the address, or null when it isn't one.
 */
function adminLogQueryId(string $name, int $maxLength = 10): ?int
{
    $value = $_GET[$name] ?? null;
    return is_string($value) && ctype_digit($value) && strlen($value) <= $maxLength ? (int) $value : null;
}

$adminFilterUser = adminLogQueryId('user');
$adminFilterChat = $adminFilterUser === null ? adminLogQueryId('chat') : null;
$adminPage = max(1, adminLogQueryId('page', 6) ?? 1);

// The filter, as SQL and as what the page says about it
[$adminWhere, $adminParameters, $adminFilterHtml] = ['', [], null];
if ($adminFilterUser !== null) {
    $statement = $db->prepare('SELECT userName FROM `HBHub-Users` WHERE userId = ?');
    $statement->execute([$adminFilterUser]);
    $filterName = $statement->fetchColumn();
    $adminWhere = ' WHERE l.adminLogUserId = ? OR l.adminLogByUserId = ?';
    $adminParameters = [$adminFilterUser, $adminFilterUser];
    $adminFilterHtml = 'Only what was done by or to '
        . adminLogNameLink('/admin/user?user=' . $adminFilterUser, $filterName !== false ? $filterName : 'user ' . $adminFilterUser) . '.';
} elseif ($adminFilterChat !== null) {
    $statement = $db->prepare('SELECT ' . adminChatTitleSql() . ' FROM `HBHub-Chats` c WHERE c.chatId = ?');
    $statement->execute([$adminFilterChat]);
    $filterTitle = $statement->fetchColumn();
    $adminWhere = ' WHERE l.adminLogChatId = ?';
    $adminParameters = [$adminFilterChat];
    $adminFilterHtml = 'Only what was done to '
        . adminLogNameLink('/admin/chat?chat=' . $adminFilterChat, $filterTitle !== false ? $filterTitle : 'chat ' . $adminFilterChat) . '.';
}

$statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-AdminLog` l' . $adminWhere);
$statement->execute($adminParameters);
$adminTotal = (int) $statement->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_LOG_SHOWN - 1, ADMIN_LOG_SHOWN));
$adminPage = min($adminPage, $adminPages);

$statement = $db->prepare('SELECT l.adminLogAction, l.adminLogData, l.adminLogReason, l.adminLogTimestamp,
        l.adminLogByUserId, bu.userName AS byName, bu.userAvatarAttachmentId AS byAvatarId,
        l.adminLogUserId, u.userName, l.adminLogChatId, IF(c.chatId IS NULL, NULL, ' . adminChatTitleSql() . ') AS chatTitle
    FROM `HBHub-AdminLog` l
    LEFT JOIN `HBHub-Users` bu ON bu.userId = l.adminLogByUserId
    LEFT JOIN `HBHub-Users` u ON u.userId = l.adminLogUserId
    LEFT JOIN `HBHub-Chats` c ON c.chatId = l.adminLogChatId' . $adminWhere . '
    ORDER BY l.adminLogId DESC
    LIMIT ' . ADMIN_LOG_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_LOG_SHOWN));
$statement->execute($adminParameters);
$adminEntries = $statement->fetchAll();

/**
 * What a row of the log says was done, as HTML, with the user and chat it was about linked to their admin panels.
 * Starts from ADMIN_LOG_ACTIONS, with the details from adminLogData where there are any.
 */
function adminLogText(array $entry): string
{
    $data = $entry['adminLogData'] !== null ? json_decode($entry['adminLogData'], true) : null;
    $data = is_array($data) ? $data : [];
    $amount = (int) ($data['amount'] ?? 0);

    $text = match ($entry['adminLogAction']) {
        'user.ban' => !empty($data['avatar']) ? 'Banned {user} and their profile picture' : null,
        'user.autoban' => isset($data['pictureId'])
            ? '{user} was banned automatically for ' . (!empty($data['signup']) ? 'signing up with' : 'uploading')
                . ' banned picture #' . (int) $data['pictureId'] : null,
        'user.avatarBan' => isset($data['pictureId'])
            ? 'Removed {user}\'s profile picture and banned it as picture #' . (int) $data['pictureId'] : null,
        'user.bio' => !empty($data['removed']) ? 'Removed {user}\'s bio' : null,
        'user.block' => is_string($data['mode'] ?? null) && isset(HBHUB_BLOCK_MODES[$data['mode']])
            ? 'Set {user}\'s gambling block to "' . HBHUB_BLOCK_MODES[$data['mode']] . '"' : null,
        'user.chips' => $amount > 0 ? 'Gave {user} ' . number_format($amount) . ' chips'
            : ($amount < 0 ? 'Took ' . number_format(-$amount) . ' chips from {user}' : null),
        'user.messages' => isset($data['count']) ? 'Deleted all of {user}\'s messages (' . number_format((int) $data['count']) . ')' : null,
        'user.attachment' => isset($data['tickets']) && (int) $data['tickets'] > 0
            ? 'Deleted an attachment from {user}, freeing ' . ((int) $data['tickets'] === 1 ? '1 ticket' : (int) $data['tickets'] . ' tickets') : null,
        'user.attachments' => isset($data['count']) ? 'Deleted all of {user}\'s attachments (' . number_format((int) $data['count']) . ')' : null,
        'user.tickets' => $amount === 0 ? null
            : ($amount > 0 ? 'Gave {user} ' . $amount . ' more attachment ' . ($amount === 1 ? 'ticket' : 'tickets')
                : 'Took ' . -$amount . ' attachment ' . ($amount === -1 ? 'ticket' : 'tickets') . ' from {user}')
            . (isset($data['total']) ? ' (' . (int) $data['total'] . ' in all now)' : ''),
        'chat.background' => !empty($data['reset']) ? 'Reset the background of {chat}' : null,
        'chat.kick' => !empty($data['invite']) ? 'Took back {user}\'s ' . (!empty($data['ghost']) ? 'ghost ' : '') . 'invite to {chat}' : null,
        'feedback.done', 'feedback.new', 'feedback.delete' => isset($data['feedbackId'])
            ? ADMIN_LOG_ACTIONS[$entry['adminLogAction']] . ' #' . (int) $data['feedbackId'] : null,
        'banned.add', 'banned.remove' => isset($data['pictureId'])
            ? ADMIN_LOG_ACTIONS[$entry['adminLogAction']] . ' #' . (int) $data['pictureId'] : null,
        default => null,
    } ?? ADMIN_LOG_ACTIONS[$entry['adminLogAction']] ?? $entry['adminLogAction'];

    $user = $entry['adminLogUserId'] !== null
        ? adminLogNameLink('/admin/user?user=' . (int) $entry['adminLogUserId'], $entry['userName'])
        : '<span class="admin-muted">someone</span>';
    $chat = $entry['adminLogChatId'] !== null
        ? adminLogNameLink('/admin/chat?chat=' . (int) $entry['adminLogChatId'], $entry['chatTitle'])
        : '<span class="admin-muted">a chat</span>';
    return strtr(htmlspecialchars($text), ['{user}' => $user, '{chat}' => $chat]);
}

/**
 * A user's name or chat's title linked to its admin panel, cut off with "..." when it's too long for one line (the
 * whole name is in the tooltip). dir="auto" keeps right-to-left names from turning the sentence around them.
 */
function adminLogNameLink(string $href, string $name): string
{
    return '<a class="admin-link admin-log-name" href="' . $href . '" dir="auto" title="' . htmlspecialchars($name) . '">'
        . htmlspecialchars($name) . '</a>';
}

/**
 * The address of a page of the log, with the filter kept, ready for an href.
 */
function adminLogUrl(?int $user, ?int $chat, int $page = 1): string
{
    $query = ($user !== null ? ['user' => $user] : []) + ($chat !== null ? ['chat' => $chat] : []) + ($page > 1 ? ['page' => $page] : []);
    return htmlspecialchars('/admin/log' . ($query !== [] ? '?' . http_build_query($query) : ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <title>Admin log - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Users, chats, banned pictures, feedback or the admin log, each with how many there are in all (new feedback only) -->
            <?= adminListsMenuHtml($db, 'log') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading">Admin log</h2>
                            <p class="settings-intro">
                                Everything site admins have done in the admin panel, newest first. Opening a user or a chat counts too,
                                as it shows all their messages and personal info (once every <?= ADMIN_LOG_VIEW_MINUTES ?> minutes).
                                Accounts banned automatically for uploading a banned profile picture are here too.
                                The reason an admin typed before a change is shown under it.
                                The log can't be changed or deleted, and is kept for <?= ADMIN_LOG_KEEP_DAYS === 365 ? 'a year' : ADMIN_LOG_KEEP_DAYS . ' days' ?>.
                            </p>
                        </div>
                    </div>

                    <?php if ($adminFilterHtml !== null): ?>
                        <p class="settings-hint">
                            <?= $adminFilterHtml ?>
                            <a class="settings-btn settings-btn-small" href="/admin/log">Show everything</a>
                        </p>
                    <?php endif; ?>

                    <?php if ($adminEntries === []): ?>
                        <p class="settings-hint">Nothing has been logged<?= $adminFilterHtml !== null ? ' about this' : '' ?> yet.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_LOG_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_LOG_SHOWN + count($adminEntries)) ?>
                            of <?= number_format($adminTotal) ?>
                        </p>
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">When</th><th scope="col">Admin</th><th scope="col">What</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminEntries as $entry): ?>
                                        <tr>
                                            <td class="admin-nowrap"><?= adminTime($entry['adminLogTimestamp']) ?></td>
                                            <td class="admin-log-by">
                                                <?php if ($entry['adminLogByUserId'] !== null): ?>
                                                    <?php $byId = (int) $entry['adminLogByUserId']; ?>
                                                    <a class="admin-list-link" href="/admin/user?user=<?= $byId ?>" title="<?= htmlspecialchars($entry['byName']) ?>">
                                                        <?= adminAvatarHtml(hbHubAvatarUrl($byId, $entry['byAvatarId'] !== null ? (int) $entry['byAvatarId'] : null), $entry['byName']) ?>
                                                        <span class="admin-list-name"><?= htmlspecialchars($entry['byName']) ?></span>
                                                    </a>
                                                <?php elseif ($entry['adminLogAction'] === 'user.autoban'): ?>
                                                    <span class="admin-muted">Automatic</span>
                                                <?php else: ?>
                                                    <span class="admin-muted">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-message-text">
                                                <?= adminLogText($entry) ?>
                                                <?php if ($entry['adminLogReason'] !== null): ?>
                                                    <span class="admin-log-reason"><span class="settings-visually-hidden">Reason: </span><?= nl2br(htmlspecialchars($entry['adminLogReason'])) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="<?= adminLogUrl($adminFilterUser, $adminFilterChat, $adminPage - 1) ?>#list" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="<?= adminLogUrl($adminFilterUser, $adminFilterChat, $adminPage + 1) ?>#list" rel="next">Next</a>
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
