<?php
// The top bar of the frontpage (index.php) and the pages made to look like it (legal/credits.php), instead of the
// app's nav panel (navPanel.php). Styled by homepage.css, so the page has to load that and give body "home-body".
// Visitors get Log in / Sign up, logged in users a way back into the app and their profile picture.
// The section links point at the frontpage's sections: set $homeNavSectionBase = '/' first on any other page,
// so they go to /#chat instead of #chat. Needs session.php (with $hbHubSessionOptional = true) included first.
require_once __DIR__ . '/userAvatar.php';

$homeNavUser = $hbHubUser ?? null;
$homeNavAvatarSrc = $homeNavUser !== null
    ? hbHubAvatarUrl((int) $homeNavUser['userId'], $homeNavUser['userAvatarAttachmentId'] !== null ? (int) $homeNavUser['userAvatarAttachmentId'] : null)
    : null;
$homeNavSectionBase ??= '';
?>
<header class="home-nav">
    <a class="home-brand" href="/" aria-label="HB Hub, frontpage">
        <span class="home-brand-mark" aria-hidden="true">HB</span>
        <span class="home-brand-name">Hub</span>
    </a>
    <nav class="home-nav-links" aria-label="Frontpage sections">
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#chat">Chat</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#casino">Casino</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#games">Games</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#safety">Safety</a>
    </nav>
    <div class="home-nav-actions">
        <?php if ($homeNavUser === null): ?>
            <a class="home-btn home-btn-ghost" href="/userMgmt/login">Log in</a>
            <a class="home-btn home-btn-primary" href="/userMgmt/onboarding">Sign up</a>
        <?php else: ?>
            <a class="home-btn home-btn-primary" href="/chat/">Open HB Hub</a>
            <a class="home-avatar" href="/otherPages/profile" aria-label="Your profile" title="Your profile">
                <?php if ($homeNavAvatarSrc !== null): ?>
                    <img src="<?= htmlspecialchars($homeNavAvatarSrc) ?>" alt="">
                <?php else: ?>
                    <span aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($homeNavUser['userName'], 0, 1))) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</header>
