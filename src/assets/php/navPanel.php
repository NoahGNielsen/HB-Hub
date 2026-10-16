<?php
// The logged in user's profile picture links to their profile. Without a picture it's the first letter of their name,
// and pages open to visitors (session.php's $hbHubUser is null) get a "Log in" link instead.
// With notifications turned on in settings, every page also checks for new messages (notifications.js).
// The bell lists the logged in user's notifications (notificationBell.js), with the number of unread ones on it.
// Chat in the side bar has the number of chats with unread messages on it, kept up to date by chatUnread.js.
// Casino pages set $navCasinoChips (the user's chips) before including this, to show them in the top bar too,
// kept up to date by casino.js.
// Site admins also get Admin in the side bar, the admin panel's lists of users and chats (admin/index.php).
require_once __DIR__ . '/chatUnread.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/userAvatar.php';
require_once __DIR__ . '/userSettings.php';
$navUser = $hbHubUser ?? null;
$navSettings = hbHubUserSettings($navUser['userSettings'] ?? null);
$navNotifications = $navUser !== null && ($navSettings['notifications'] ?? false) === true;
$navAvatarSrc = $navUser !== null
    ? hbHubAvatarUrl((int) $navUser['userId'], $navUser['userAvatarAttachmentId'] !== null ? (int) $navUser['userAvatarAttachmentId'] : null)
    : null;

$navUnread = 0;
$navUnreadChats = 0;
if ($navUser !== null) {
    try {
        $navUnread = hbHubUnreadNotificationCount(hbHubDatabase(), (int) $navUser['userId']);
        $navUnreadChats = hbHubUnreadChatCount(hbHubDatabase(), (int) $navUser['userId'], $navSettings);
    } catch (PDOException $e) {
        error_log('Nav panel: could not count notifications or unread chats: ' . $e->getMessage()); // the page still works without the numbers
    }
}

$navCasinoChips ??= null;

// A count of unread things (null for none) is shown as a red number on the item's icon, 9+ above 9, and hidden while
// it's 0. Visitors, who aren't logged in, have no chats to count.
$navSection = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
$navItems = [
    'chat'     => ['label' => 'Chat',     'icon' => 'chat.png', 'count' => $navUser !== null ? $navUnreadChats : null],
    'gambling' => ['label' => 'Gambling', 'icon' => 'dice.png'],
    'games'    => ['label' => 'Games',    'icon' => 'controler.png'],
];
if ($navUser !== null && (int) $navUser['userRole'] === 2) { // userRole in HBHub-Users, a site admin
    $navItems['admin'] = ['label' => 'Admin', 'icon' => 'admin.svg'];
}
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
            <a class="nav-login" href="/userMgmt/login?return=<?= htmlspecialchars(rawurlencode($_SERVER['REQUEST_URI'] ?? '/')) ?>">Log in</a>
        <?php else: ?>
            <a class="nav-icon-btn nav-avatar-btn" href="/otherPages/profile" aria-label="Your profile" title="Your profile">
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
            $navCount > 0 ? ' aria-label="' . $item['label'] . ' (' . $navCount . ' unread)"' : '' ?>>
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
</nav>
<?php if ($navUser !== null): ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/notificationBell.js?v=<?= filemtime(__DIR__ . '/../js/notificationBell.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chatUnread.js?v=<?= filemtime(__DIR__ . '/../js/chatUnread.js') ?>" defer></script>
<?php endif; ?>
<?php if ($navNotifications): ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../js/notifications.js') ?>" defer></script>
<?php endif; ?>
<?php if ($navCasinoChips !== null): ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/casino.js?v=<?= filemtime(__DIR__ . '/../js/casino.js') ?>" defer></script>
<?php endif; ?>
