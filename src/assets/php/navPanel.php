<?php
// The logged in user's profile picture links to their profile. Without a picture it's the first letter of their name,
// and pages open to visitors (session.php's $hbHubUser is null) get a "Log in" link instead.
// With notifications turned on in settings, every page also checks for new messages (notifications.js).
require_once __DIR__ . '/userAvatar.php';
require_once __DIR__ . '/userSettings.php';
$navUser = $hbHubUser ?? null;
$navNotifications = $navUser !== null && (hbHubUserSettings($navUser['userSettings'] ?? null)['notifications'] ?? false) === true;
$navAvatarSrc = $navUser !== null
    ? hbHubAvatarUrl((int) $navUser['userId'], $navUser['userAvatarAttachmentId'] !== null ? (int) $navUser['userAvatarAttachmentId'] : null)
    : null;

$navSection = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
$navItems = [
    'chat'     => ['label' => 'Chat',     'icon' => 'chat.png'],
    'gambling' => ['label' => 'Gambling', 'icon' => 'dice.png'],
    'games'    => ['label' => 'Games',    'icon' => 'controler.png'],
];
?>
<header class="nav-top">
    <a class="nav-brand" href="/">HB Hub</a>
    <div class="nav-top-actions">
        <button type="button" class="nav-icon-btn" aria-label="Notifications">
            <img class="nav-icon" src="/assets/images/icons/no-notification.png" alt="">
        </button>
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
        <a class="nav-side-link<?= $navSection === $slug ? ' active' : '' ?>" href="/<?= $slug ?>/"<?= $navSection === $slug ? ' aria-current="page"' : '' ?>>
            <img class="nav-icon" src="/assets/images/icons/<?= $item['icon'] ?>" alt="">
            <span><?= $item['label'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php if ($navNotifications): ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../js/notifications.js') ?>" defer></script>
<?php endif; ?>
