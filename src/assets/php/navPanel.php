<?php
// Profile picture: set $navAvatarBlob (raw BLOB from the db) before including this file.
// Falls back to the default user icon when no BLOB is given.
$navAvatarSrc = '/assets/images/icons/user.png';
if (!empty($navAvatarBlob)) {
    $navAvatarMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($navAvatarBlob) ?: 'image/png';
    $navAvatarSrc = 'data:' . $navAvatarMime . ';base64,' . base64_encode($navAvatarBlob);
}

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
        <button type="button" class="nav-icon-btn nav-avatar-btn" aria-label="Account">
            <img class="nav-avatar<?= empty($navAvatarBlob) ? ' nav-icon' : '' ?>" src="<?= htmlspecialchars($navAvatarSrc) ?>" alt="">
        </button>
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
