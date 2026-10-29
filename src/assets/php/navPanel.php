<?php
// The logged in user's profile picture links to their profile. Without a picture it's the first letter of their name,
// and pages open to visitors (session.php's $hbHubUser is null) get a "Log in" link instead.
// With browser notifications or the message sound turned on in settings, every page also checks for new messages
// (notifications.js, with messageSound.js for the sound). Pages that set $navMessageSound to true before including this
// (the settings page, to play it when the box is ticked) get messageSound.js either way.
// The bell lists the logged in user's notifications (notificationBell.js), with the number of unread ones on it.
// Chat in the side bar has the number of chats with unread messages on it, kept up to date by chatUnread.js.
// Casino pages set $navCasinoChips (the user's chips) before including this, to show them in the top bar too,
// kept up to date by casino.js.
// Those four share one request (navUpdates.js, otherPages/navUpdates.php), so a page only sends one every 15 seconds.
// Site admins also get Admin in the side bar, the admin panel's lists of users and chats (admin/index.php), with the
// number of new feedback on it.
// Feedback, at the bottom of the side bar (at the end of the bottom bar on a phone), opens otherPages/feedback.php with
// the path of the page it was pressed on, so the feedback says where it came from and the user is sent back there.
require_once __DIR__ . '/chatUnread.php';
require_once __DIR__ . '/feedback.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/userAvatar.php';
require_once __DIR__ . '/userSettings.php';
$navUser = $hbHubUser ?? null;
$navSettings = hbHubUserSettings($navUser['userSettings'] ?? null);
$navNotify = ($navSettings['notifications'] ?? false) === true;
$navSound = ($navSettings['notificationSound'] ?? false) === true;
$navNotifications = $navUser !== null && ($navNotify || $navSound);
$navMessageSound = $navNotifications || ($navUser !== null && ($navMessageSound ?? false) === true);
$navAvatarSrc = $navUser !== null
    ? hbHubAvatarUrl((int) $navUser['userId'], $navUser['userAvatarAttachmentId'] !== null ? (int) $navUser['userAvatarAttachmentId'] : null)
    : null;

$navIsAdmin = $navUser !== null && (int) $navUser['userRole'] === 2; // userRole in HBHub-Users, a site admin
$navUnread = 0;
$navUnreadChats = 0;
$navNewFeedback = 0;
if ($navUser !== null) {
    try {
        $navUnread = hbHubUnreadNotificationCount(hbHubDatabase(), (int) $navUser['userId']);
        $navUnreadChats = hbHubUnreadChatCount(hbHubDatabase(), (int) $navUser['userId'], $navSettings);
        if ($navIsAdmin) {
            $navNewFeedback = (int) hbHubDatabase()->query('SELECT COUNT(*) FROM `HBHub-Feedback` WHERE feedbackDoneTimestamp IS NULL')->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('Nav panel: could not count notifications, unread chats or new feedback: ' . $e->getMessage()); // the page still works without the numbers
    }
}

$navCasinoChips ??= null;

// A count of unread things (null for none) is shown as a red number on the item's icon, 9+ above 9, and hidden while
// it's 0. Visitors, who aren't logged in, have no chats to count.
$navPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$navSection = explode('/', trim($navPath, '/'))[0];
$navItems = [
    'chat'     => ['label' => 'Chat',     'icon' => 'chat.png', 'count' => $navUser !== null ? $navUnreadChats : null],
    'gambling' => ['label' => 'Gambling', 'icon' => 'dice.png'],
    'games'    => ['label' => 'Games',    'icon' => 'controler.png'],
];
if ($navIsAdmin) {
    $navItems['admin'] = ['label' => 'Admin', 'icon' => 'admin.svg', 'count' => $navNewFeedback, 'countWord' => 'new feedback'];
}

// On the feedback page itself the link keeps the page it came from
$navOnFeedback = rtrim($navPath, '/') === HBHUB_FEEDBACK_PAGE;
$navFeedbackFrom = hbHubFeedbackReturn($_SERVER['REQUEST_URI']);
$navFeedbackHref = $navOnFeedback
    ? $_SERVER['REQUEST_URI']
    : HBHUB_FEEDBACK_PAGE . ($navFeedbackFrom !== null ? '?from=' . rawurlencode($navFeedbackFrom) : '');
?>
<header class="nav-top">
    <a class="nav-brand" href="/" aria-label="HB Hub, frontpage">
        <span class="nav-brand-mark" aria-hidden="true">HB</span>
        <span class="nav-brand-name">Hub</span>
    </a>
    <div class="nav-top-actions">
        <?php if ($navCasinoChips !== null): ?>
            <a class="nav-chips" href="/gambling/" title="Your chips">
                <img class="nav-chips-icon" src="/assets/images/icons/chip.svg" alt="">
                <span data-casino-chips="<?= (int) $navCasinoChips ?>"><?= number_format($navCasinoChips) ?></span>
                <span class="nav-visually-hidden">chips</span>
            </a>
        <?php endif; ?>
        <?php if ($navUser !== null): ?>
            <div class="nav-notifications" data-notifications>
                <button type="button" class="nav-icon-btn nav-bell" aria-expanded="false" aria-controls="nav-notifications-panel"
                        aria-label="Notifications<?= $navUnread > 0 ? ' (' . $navUnread . ' new)' : '' ?>" title="Notifications" data-notifications-bell>
                    <img class="nav-icon" src="/assets/images/icons/<?= $navUnread > 0 ? 'notification.png' : 'no-notification.png' ?>" alt="" data-notifications-icon>
                    <span class="nav-bell-count" aria-hidden="true" data-notifications-count<?= $navUnread === 0 ? ' hidden' : '' ?>><?= $navUnread > 9 ? '9+' : $navUnread ?></span>
                </button>
                <div class="nav-notifications-panel" id="nav-notifications-panel" role="region" aria-label="Notifications" hidden data-notifications-panel>
                    <h2 class="nav-notifications-title">Notifications</h2>
                    <ul class="nav-notifications-list" data-notifications-list></ul>
                    <p class="nav-notifications-empty" data-notifications-empty>Loading…</p>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($navUser === null): ?>
            <a class="nav-login" href="/login?return=<?= htmlspecialchars(rawurlencode($_SERVER['REQUEST_URI'] ?? '/')) ?>">Log in</a>
        <?php else: ?>
            <a class="nav-icon-btn nav-avatar-btn" href="/profile" aria-label="Your profile" title="Your profile">
                <?php if ($navAvatarSrc !== null): ?>
                    <img class="nav-avatar" src="<?= htmlspecialchars($navAvatarSrc) ?>" alt="">
                <?php else: ?>
                    <span class="nav-avatar nav-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($navUser['userName'], 0, 1))) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</header>
<nav class="nav-side" aria-label="Main">
    <?php foreach ($navItems as $slug => $item): ?>
        <?php $navCount = $item['count'] ?? null; ?>
        <a class="nav-side-link<?= $navSection === $slug ? ' active' : '' ?>" href="/<?= $slug ?>/"<?= $navSection === $slug ? ' aria-current="page"' : '' ?><?=
            $navCount > 0 ? ' aria-label="' . $item['label'] . ' (' . $navCount . ' ' . ($item['countWord'] ?? 'unread') . ')"' : '' ?>>
            <span class="nav-side-icon">
                <img class="nav-icon" src="/assets/images/icons/<?= $item['icon'] ?>" alt="">
                <?php if ($navCount !== null): ?>
                    <span class="nav-side-count" aria-hidden="true" data-nav-count="<?= $slug ?>"<?=
                        $navCount === 0 ? ' hidden' : '' ?>><?= $navCount > 9 ? '9+' : $navCount ?></span>
                <?php endif; ?>
            </span>
            <span class="nav-side-label"><?= $item['label'] ?></span>
        </a>
    <?php endforeach; ?>
    <?php if ($navUser !== null): ?>
        <a class="nav-side-link nav-side-feedback<?= $navOnFeedback ? ' active' : '' ?>" href="<?= htmlspecialchars($navFeedbackHref) ?>"<?=
            $navOnFeedback ? ' aria-current="page"' : '' ?> title="Send feedback to the admins">
            <span class="nav-side-icon">
                <img class="nav-icon" src="/assets/images/icons/feedback.svg" alt="">
            </span>
            <span class="nav-side-label">Feedback</span>
        </a>
    <?php endif; ?>
</nav>
<?php if ($navUser !== null): ?>
    <script src="/assets/js/notificationBell.js?v=<?= filemtime(__DIR__ . '/../js/notificationBell.js') ?>" defer></script>
    <script src="/assets/js/chatUnread.js?v=<?= filemtime(__DIR__ . '/../js/chatUnread.js') ?>" defer></script>
<?php endif; ?>
<?php if ($navMessageSound): ?>
    <script src="/assets/js/messageSound.js?v=<?= filemtime(__DIR__ . '/../js/messageSound.js') ?>" defer></script>
<?php endif; ?>
<?php if ($navNotifications): ?>
    <script src="/assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../js/notifications.js') ?>" defer
            data-notify="<?= $navNotify ? '1' : '0' ?>" data-sound="<?= $navSound ? '1' : '0' ?>"></script>
<?php endif; ?>
<?php if ($navCasinoChips !== null): ?>
    <script src="/assets/js/casino.js?v=<?= filemtime(__DIR__ . '/../js/casino.js') ?>" defer></script>
<?php endif; ?>
<?php if ($navUser !== null): ?>
    <script src="/assets/js/navUpdates.js?v=<?= filemtime(__DIR__ . '/../js/navUpdates.js') ?>" defer></script>
<?php endif; ?>
