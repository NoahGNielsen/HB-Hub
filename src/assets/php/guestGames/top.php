<?php
// The bar at the top of a guest game (see guestGames.php), in Danish like every public page (the game under it is the
// members' own, in English): that they're playing as a guest, what that means for their
// game, and Log in / Sign up, which go to the normal version of the game afterwards. The casino games show the guest's
// play chips in it too (data-casino-chips, kept up to date by casino.js like the top bar's count for members).
// Included by the game's page inside its <main>. Needs $guestGame.
$guestLinks = hbHubGuestAccountLinks($guestGame);
$guestIsCasino = $guestGame['section'] === 'casino';
?>
<aside class="guest-bar" aria-label="Du spiller som gæst" lang="da">
    <p class="guest-bar-text">
        <strong>Du spiller som gæst.</strong>
        <?php if ($guestIsCasino): ?>
            Det her er legechips: de starter forfra, når du forlader siden.
        <?php else: ?>
            Dit spil bliver gemt i denne browser. Opret en konto for at gemme det på din konto.
        <?php endif; ?>
    </p>
    <?php if ($guestIsCasino): ?>
        <p class="guest-bar-chips">
            <img class="guest-bar-chips-icon" src="/assets/images/icons/chip.svg" alt="Chips" aria-hidden="true">
            <span data-casino-chips="<?= HBHUB_GUEST_CASINO_CHIPS ?>"><?= number_format(HBHUB_GUEST_CASINO_CHIPS, 0, ',', '.') ?></span>
            <span class="guest-visually-hidden">legechips</span>
        </p>
    <?php endif; ?>
    <div class="guest-bar-actions">
        <a class="casino-btn" href="<?= htmlspecialchars($guestLinks['login']) ?>">Log ind</a>
        <a class="casino-btn casino-btn-primary" href="<?= htmlspecialchars($guestLinks['signup']) ?>">Opret gratis konto</a>
    </div>
</aside>
