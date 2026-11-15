<?php
// The part under a guest game (see guestGames.php), in Danish like every public page, included by the game's page
// right after its </main>: a few
// paragraphs about the game for visitors and search engines, links to the rules and the other free games, and the
// popup that asks the visitor to sign up or log in after a while (guestPrompt.js). Its scripts too: guestPrompt.js,
// and for the casino games casino.js, which keeps the play chips in the bar (top.php) up to date.
// Needs $guestGame.
require_once __DIR__ . '/../casino/wallet.php'; // HBHUB_CASINO_START_CHIPS, for the casino games' popup

$guestLinks = hbHubGuestAccountLinks($guestGame);
$guestPrompt = str_replace('{startChips}', number_format(HBHUB_CASINO_START_CHIPS, 0, ',', '.'), $guestGame['prompt']);
?>
<section class="guest-about" aria-labelledby="guest-about-title" lang="da">
    <h2 id="guest-about-title">Om <?= htmlspecialchars($guestGame['name']) ?></h2>
    <?php foreach ($guestGame['about'] as $guestParagraph): ?>
        <p><?= htmlspecialchars($guestParagraph) ?></p>
    <?php endforeach; ?>
    <p><a href="<?= htmlspecialchars($guestGame['docs']) ?>">Sådan spiller du <?= htmlspecialchars($guestGame['name']) ?></a></p>

    <h3 class="guest-about-more">Flere gratis spil</h3>
    <ul class="guest-about-games">
        <?php foreach (HBHUB_GUEST_GAMES as $guestOtherKey => $guestOther): ?>
            <?php if ($guestOtherKey !== $guestGame['key'] && hbHubGuestGameIsOpen($guestOther)): ?>
                <li><a href="<?= htmlspecialchars($guestOther['path']) ?>"><?= htmlspecialchars($guestOther['name']) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li><a href="/play">Alle gratis spil</a></li>
    </ul>
</section>

<!-- Shown by guestPrompt.js after a while of playing. "Spil videre" (or Escape) closes it until the next time. -->
<dialog class="guest-prompt" aria-labelledby="guest-prompt-title" data-guest-prompt lang="da">
    <!-- The dialog itself has no padding, so a click on it (not on this) is a click on the backdrop -->
    <div class="guest-prompt-body">
        <h2 id="guest-prompt-title">Kan du lide <?= htmlspecialchars($guestGame['name']) ?>?</h2>
        <p><?= htmlspecialchars($guestPrompt) ?></p>
        <p class="guest-prompt-hint">En konto er gratis og tager kun et minut.</p>
        <div class="guest-prompt-actions">
            <button type="button" class="casino-btn" data-guest-prompt-later>Spil videre</button>
            <a class="casino-btn" href="<?= htmlspecialchars($guestLinks['login']) ?>">Log ind</a>
            <a class="casino-btn casino-btn-primary" href="<?= htmlspecialchars($guestLinks['signup']) ?>">Opret gratis konto</a>
        </div>
    </div>
</dialog>
<script src="/assets/js/guestPrompt.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/guestPrompt.js') ?>" defer></script>
<?php if ($guestGame['section'] === 'casino'): ?>
<script src="/assets/js/casino.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/casino.js') ?>" defer></script>
<?php endif; ?>
