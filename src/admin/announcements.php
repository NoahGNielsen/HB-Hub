<?php
// Admin panel's announcements: /admin/announcements, in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// Sends a notification to every user at once (assets/php/announcements.php): a text, and a page on the site it links
// to if wanted. Under it are the ones sent so far, newest first, ADMIN_ANNOUNCEMENTS_SHOWN at a time (?page=), each with
// who sent it, when, how many got it and how many of them have seen it.
// Forms (action=): send (with text= and link=) sends one, takeBack (announcement=id) deletes one and takes it out of
// everyone's notifications. admin.js asks "are you sure?" and for a reason before either, and both are logged in the
// admin log (assets/php/adminLog.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/announcements.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_ANNOUNCEMENTS_SHOWN = 30; // per page

adminOnly($hbHubUser);

$db = hbHubDatabase();
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;

$adminAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;
if ($adminAction === 'send' || $adminAction === 'takeBack') {
    if ($adminAction === 'send') {
        $text = hbHubAnnouncementText($_POST['text'] ?? null);
        $link = hbHubAnnouncementLink($_POST['link'] ?? null);
        $toast = $text === null ? 'adminAnnouncementTextMissing' : ($link === null ? 'adminAnnouncementBadLink' : 'adminAnnouncementSent');
        if ($text !== null && $link !== null) {
            $announcementId = hbHubAnnouncementSend($db, (int) $hbHubUser['userId'], $text, $link);
            adminLog($db, (int) $hbHubUser['userId'], 'announcement.send', null, null, ['announcementId' => $announcementId]);
        }
        $adminPage = 1; // the new one is at the top
    } else {
        $announcementId = $_POST['announcement'] ?? null;
        $toast = 'adminAnnouncementTakenBack';
        if (is_string($announcementId) && ctype_digit($announcementId) && strlen($announcementId) <= 10
            && hbHubAnnouncementTakeBack($db, (int) $announcementId)) {
            adminLog($db, (int) $hbHubUser['userId'], 'announcement.takeBack', null, null, ['announcementId' => (int) $announcementId]);
        }
    }

    // Back to the page with a toast, so reloading doesn't send the form again
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast($toast);
    header('Location: /admin/announcements' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#sent', true, 303);
    exit;
}

$adminAudience = hbHubAnnouncementAudience($db);
$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-Announcements`')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_ANNOUNCEMENTS_SHOWN - 1, ADMIN_ANNOUNCEMENTS_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminAnnouncements = $db->query('SELECT a.announcementId, a.announcementByUserId, u.userName, u.userAvatarAttachmentId, a.announcementText,
        a.announcementLink, a.announcementRecipients, a.announcementCreatedTimestamp
    FROM `HBHub-Announcements` a
    LEFT JOIN `HBHub-Users` u ON u.userId = a.announcementByUserId
    ORDER BY a.announcementId DESC
    LIMIT ' . ADMIN_ANNOUNCEMENTS_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_ANNOUNCEMENTS_SHOWN))->fetchAll();
$adminSeen = hbHubAnnouncementSeenCounts($db, array_map(static fn (array $row): int => (int) $row['announcementId'], $adminAnnouncements));
$adminFormAction = '/admin/announcements' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#sent';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title>Announcements - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'announcements') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="send" aria-labelledby="admin-send-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-send-heading">Send an announcement</h2>
                            <p class="settings-intro">
                                Every user gets it as a notification under the bell, right now: <?= number_format($adminAudience) ?>
                                accounts (everyone but banned and deleted ones). It says "Announcement from the admins", not which admin.
                            </p>
                        </div>
                    </div>

                    <form action="/admin/announcements#sent" method="post" data-confirm="Send this to all <?= number_format($adminAudience) ?> users?">
                        <input type="hidden" name="action" value="send">
                        <div class="settings-field">
                            <label class="settings-label" for="admin-announcement-text">Message</label>
                            <textarea id="admin-announcement-text" name="text" maxlength="<?= HBHUB_ANNOUNCEMENT_TEXT_MAX ?>" rows="3" required
                                      aria-describedby="admin-announcement-text-hint"></textarea>
                            <p class="settings-hint" id="admin-announcement-text-hint">At most <?= HBHUB_ANNOUNCEMENT_TEXT_MAX ?> characters. It's shown on one line, so line breaks become spaces.</p>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label" for="admin-announcement-link">Link <span class="settings-optional">(optional)</span></label>
                            <input type="text" id="admin-announcement-link" name="link" maxlength="<?= HBHUB_ANNOUNCEMENT_LINK_MAX ?>" autocomplete="off"
                                   pattern="/(?![\/\\]).*" placeholder="/games/" aria-describedby="admin-announcement-link-hint">
                            <p class="settings-hint" id="admin-announcement-link-hint">A page on HB Hub the notification opens when it's clicked, starting with /, like <code>/games/ore-mining-inc/</code>.</p>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Send to everyone</button>
                        </div>
                    </form>
                </section>

                <section class="settings-card" id="sent" aria-labelledby="admin-sent-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-sent-heading">Sent</h2>
                            <p class="settings-intro">Taking one back removes it from everyone's notifications, whether they've seen it or not.</p>
                        </div>
                    </div>

                    <?php if ($adminAnnouncements === []): ?>
                        <p class="settings-hint">No announcements have been sent yet.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_ANNOUNCEMENTS_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_ANNOUNCEMENTS_SHOWN + count($adminAnnouncements)) ?>
                            of <?= number_format($adminTotal) ?>, newest first
                        </p>
                        <ul class="admin-feedback-list">
                            <?php foreach ($adminAnnouncements as $announcement): ?>
                                <?php
                                $announcementId = (int) $announcement['announcementId'];
                                $announcementName = $announcement['userName'] ?? 'Deleted User';
                                ?>
                                <li class="admin-feedback-item">
                                    <div class="admin-feedback-head">
                                        <span class="admin-feedback-from">
                                            <?php if ($announcement['announcementByUserId'] !== null): ?>
                                                <?= adminAvatarHtml(hbHubAvatarUrl((int) $announcement['announcementByUserId'], $announcement['userAvatarAttachmentId'] !== null ? (int) $announcement['userAvatarAttachmentId'] : null), $announcementName) ?>
                                                <a class="admin-link" href="/admin/user?user=<?= (int) $announcement['announcementByUserId'] ?>"><?= htmlspecialchars($announcementName) ?></a>
                                            <?php else: ?>
                                                <?= adminAvatarHtml(null, $announcementName) ?>
                                                <span class="admin-muted"><?= htmlspecialchars($announcementName) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="admin-small"><?= adminTime($announcement['announcementCreatedTimestamp']) ?></span>
                                        <?php if ($announcement['announcementLink'] !== null): ?>
                                            <span class="admin-small">links to <a class="admin-link admin-mono" href="<?= htmlspecialchars($announcement['announcementLink']) ?>"><?= htmlspecialchars($announcement['announcementLink']) ?></a></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="admin-feedback-text"><?= htmlspecialchars($announcement['announcementText']) ?></p>
                                    <div class="admin-feedback-foot">
                                        <span class="admin-small">
                                            Seen by <?= number_format($adminSeen[$announcementId] ?? 0) ?> of <?= number_format((int) $announcement['announcementRecipients']) ?>
                                        </span>
                                        <form action="<?= $adminFormAction ?>" method="post" data-confirm="Take this announcement back from everyone?">
                                            <input type="hidden" name="action" value="takeBack">
                                            <input type="hidden" name="announcement" value="<?= $announcementId ?>">
                                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Take back</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="/admin/announcements<?= $adminPage > 2 ? '?page=' . ($adminPage - 1) : '' ?>#sent" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="/admin/announcements?page=<?= $adminPage + 1 ?>#sent" rel="next">Next</a>
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
