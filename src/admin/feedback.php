<?php
// Admin panel's feedback: /admin/feedback, the fourth list in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// The feedback users have sent from the Feedback button in the side bar (otherPages/feedback.php,
// assets/php/feedback.php): new ones first, then the ones marked as done, each newest first, ADMIN_FEEDBACK_SHOWN at a
// time (?page=). Each shows who sent it, when, the page they were on and what they wrote.
// Forms (action=, feedback=id): done marks it as done, new marks it as new again, delete removes it for good.
// Each one is logged in the admin log (assets/php/adminLog.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const ADMIN_FEEDBACK_SHOWN = 30; // per page
const ADMIN_FEEDBACK_TOASTS = ['done' => 'adminFeedbackDone', 'new' => 'adminFeedbackNew', 'delete' => 'adminFeedbackDeleted'];

adminOnly($hbHubUser);

$db = hbHubDatabase();
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;

$adminAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;
if ($adminAction !== null && isset(ADMIN_FEEDBACK_TOASTS[$adminAction])) {
    $feedbackId = $_POST['feedback'] ?? null;
    if (is_string($feedbackId) && ctype_digit($feedbackId) && strlen($feedbackId) <= 10) {
        $isChanged = $adminAction === 'delete'
            ? hbHubFeedbackDelete($db, (int) $feedbackId)
            : hbHubFeedbackSetDone($db, (int) $feedbackId, $adminAction === 'done', (int) $hbHubUser['userId']);
        if ($isChanged) {
            adminLog($db, (int) $hbHubUser['userId'], 'feedback.' . $adminAction, null, null, ['feedbackId' => (int) $feedbackId]);
        }
    }

    // Back to the same page with a toast, so reloading doesn't send the form again
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast(ADMIN_FEEDBACK_TOASTS[$adminAction]);
    header('Location: /admin/feedback' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#list', true, 303);
    exit;
}

$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-Feedback`')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_FEEDBACK_SHOWN - 1, ADMIN_FEEDBACK_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminFeedback = $db->query('SELECT f.feedbackId, f.feedbackUserId, u.userName, u.userAvatarAttachmentId, f.feedbackContent,
        f.feedbackPage, f.feedbackCreatedTimestamp, f.feedbackDoneTimestamp, f.feedbackDoneByUserId, du.userName AS doneByName
    FROM `HBHub-Feedback` f
    LEFT JOIN `HBHub-Users` u ON u.userId = f.feedbackUserId
    LEFT JOIN `HBHub-Users` du ON du.userId = f.feedbackDoneByUserId
    ORDER BY f.feedbackDoneTimestamp IS NOT NULL, f.feedbackId DESC
    LIMIT ' . ADMIN_FEEDBACK_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_FEEDBACK_SHOWN))->fetchAll();
$adminFormAction = '/admin/feedback' . ($adminPage > 1 ? '?page=' . $adminPage : '') . '#list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title>Feedback - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Users, chats, banned pictures or feedback, each with how many there are in all (new feedback only) -->
            <?= adminListsMenuHtml($db, 'feedback') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-list-heading">Feedback</h2>
                            <p class="settings-intro">What users have sent with the Feedback button in the side bar. Mark it as done once it's dealt with.</p>
                        </div>
                    </div>

                    <?php if ($adminFeedback === []): ?>
                        <p class="settings-hint">Nobody has sent any feedback yet.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_FEEDBACK_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_FEEDBACK_SHOWN + count($adminFeedback)) ?>
                            of <?= number_format($adminTotal) ?>, new ones first
                        </p>
                        <ul class="admin-feedback-list">
                            <?php foreach ($adminFeedback as $feedback): ?>
                                <?php
                                $feedbackId = (int) $feedback['feedbackId'];
                                $feedbackDone = $feedback['feedbackDoneTimestamp'] !== null;
                                $feedbackName = $feedback['userName'] ?? 'Deleted User';
                                ?>
                                <li class="admin-feedback-item<?= $feedbackDone ? ' is-done' : '' ?>">
                                    <div class="admin-feedback-head">
                                        <span class="admin-feedback-from">
                                            <?php if ($feedback['feedbackUserId'] !== null): ?>
                                                <?= adminAvatarHtml(hbHubAvatarUrl((int) $feedback['feedbackUserId'], $feedback['userAvatarAttachmentId'] !== null ? (int) $feedback['userAvatarAttachmentId'] : null), $feedbackName) ?>
                                                <a class="admin-link" href="/admin/user?user=<?= (int) $feedback['feedbackUserId'] ?>"><?= htmlspecialchars($feedbackName) ?></a>
                                            <?php else: ?>
                                                <?= adminAvatarHtml(null, $feedbackName) ?>
                                                <span class="admin-muted"><?= htmlspecialchars($feedbackName) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="admin-small"><?= adminTime($feedback['feedbackCreatedTimestamp']) ?></span>
                                        <?php if ($feedback['feedbackPage'] !== null): ?>
                                            <span class="admin-small">from <a class="admin-link admin-mono" href="<?= htmlspecialchars($feedback['feedbackPage']) ?>"><?= htmlspecialchars($feedback['feedbackPage']) ?></a></span>
                                        <?php endif; ?>
                                        <span class="settings-status<?= $feedbackDone ? '' : ' is-on' ?>"><?= $feedbackDone ? 'Done' : 'New' ?></span>
                                    </div>
                                    <p class="admin-feedback-text"><?= nl2br(htmlspecialchars($feedback['feedbackContent'])) ?></p>
                                    <div class="admin-feedback-foot">
                                        <?php if ($feedbackDone): ?>
                                            <span class="admin-small">Done <?= adminTime($feedback['feedbackDoneTimestamp']) ?><?php if ($feedback['feedbackDoneByUserId'] !== null): ?>
                                                by <a class="admin-link" href="/admin/user?user=<?= (int) $feedback['feedbackDoneByUserId'] ?>"><?= htmlspecialchars($feedback['doneByName']) ?></a><?php endif; ?></span>
                                        <?php endif; ?>
                                        <form action="<?= $adminFormAction ?>" method="post">
                                            <input type="hidden" name="action" value="<?= $feedbackDone ? 'new' : 'done' ?>">
                                            <input type="hidden" name="feedback" value="<?= $feedbackId ?>">
                                            <button type="submit" class="settings-btn settings-btn-small<?= $feedbackDone ? '' : ' settings-btn-primary' ?>"><?= $feedbackDone ? 'Mark as new' : 'Mark as done' ?></button>
                                        </form>
                                        <form action="<?= $adminFormAction ?>" method="post" data-confirm="Delete this feedback? It can't be brought back.">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="feedback" value="<?= $feedbackId ?>">
                                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Delete</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="/admin/feedback<?= $adminPage > 2 ? '?page=' . ($adminPage - 1) : '' ?>#list" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="/admin/feedback?page=<?= $adminPage + 1 ?>#list" rel="next">Next</a>
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
