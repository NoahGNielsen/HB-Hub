<?php
// The top bar of the frontpage (index.php) and the pages made to look like it (legal/credits.php), instead of the
// app's nav panel (navPanel.php). Styled by homepage.css, so the page has to load that and give body "home-body".
// In Danish, like every public page. Visitors get Log ind / Opret konto, logged in users a way back into the app and their profile picture.
// The section links point at the frontpage's sections: set $homeNavSectionBase = '/' first on any other page,
// so they go to /#chat instead of #chat. Docs, after them, always goes to the help docs (docs/). Needs session.php (with $hbHubSessionOptional = true) included first.
require_once __DIR__ . '/userAvatar.php';

$homeNavUser = $hbHubUser ?? null;
$homeNavAvatarSrc = $homeNavUser !== null
    ? hbHubAvatarUrl((int) $homeNavUser['userId'], $homeNavUser['userAvatarAttachmentId'] !== null ? (int) $homeNavUser['userAvatarAttachmentId'] : null)
    : null;
$homeNavSectionBase ??= '';
if ($homeNavUser !== null && $homeNavSectionBase === '/') {
    $homeNavSectionBase = '/?home'; // or the frontpage would send them on to the chat
}
?>
<header class="home-nav">
    <?php // The logo goes to the frontpage for visitors and to the chat for logged in users, who'd be sent there from the frontpage anyway ?>
    <a class="home-brand" href="<?= $homeNavUser !== null ? '/chat/' : '/' ?>" aria-label="<?= $homeNavUser !== null ? 'HB Hub, chat' : 'HB Hub, forside' ?>">
        <span class="home-brand-mark" aria-hidden="true">HB</span>
        <span class="home-brand-name">Hub</span>
    </a>
    <nav class="home-nav-links" aria-label="Siden">
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#chat">Chat</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#casino">Casino</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#games">Spil</a>
        <a href="<?= htmlspecialchars($homeNavSectionBase) ?>#safety">Sikkerhed</a>
        <a href="/docs/">Hjælp</a>
    </nav>
    <div class="home-nav-actions">
        <?php if ($homeNavUser === null): ?>
            <a class="home-btn home-btn-ghost" href="/login">Log ind</a>
            <a class="home-btn home-btn-primary" href="/onboarding">Opret konto</a>
        <?php else: ?>
            <a class="home-btn home-btn-primary" href="/chat/">Åbn HB Hub</a>
            <a class="home-avatar" href="/profile" aria-label="Din profil" title="Din profil">
                <?php if ($homeNavAvatarSrc !== null): ?>
                    <img src="<?= htmlspecialchars($homeNavAvatarSrc) ?>" alt="">
                <?php else: ?>
                    <span aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($homeNavUser['userName'], 0, 1))) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</header>
