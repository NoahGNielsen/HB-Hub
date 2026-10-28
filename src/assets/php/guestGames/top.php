<?php
// The bar at the top of a guest game (see guestGames.php): that they're playing as a guest, what that means for their
// game, and Log in / Sign up, which go to the normal version of the game afterwards. The casino games show the guest's
// play chips in it too (data-casino-chips, kept up to date by casino.js like the top bar's count for members).
// Included by the game's page inside its <main>. Needs $guestGame.
$guestLinks = hbHubGuestAccountLinks($guestGame);
$guestIsCasino = $guestGame['section'] === 'casino';
?>
<aside class="guest-bar" aria-label="Playing as a guest">
    <p class="guest-bar-text">
        <strong>You're playing as a guest.</strong>
        <?php if ($guestIsCasino): ?>
            These are play chips: they start over when you leave the page.
        <?php else: ?>
            Your game is kept in this browser. Sign up to keep it on your account.
        <?php endif; ?>
    </p>
    <?php if ($guestIsCasino): ?>
        <p class="guest-bar-chips">
            <img class="guest-bar-chips-icon" src="/assets/images/icons/chip.svg" alt="">
            <span data-casino-chips="<?= HBHUB_GUEST_CASINO_CHIPS ?>"><?= number_format(HBHUB_GUEST_CASINO_CHIPS) ?></span>
            <span class="guest-visually-hidden">play chips</span>
        </p>
    <?php endif; ?>
    <div class="guest-bar-actions">
        <a class="casino-btn" href="<?= htmlspecialchars($guestLinks['login']) ?>">Log in</a>
        <a class="casino-btn casino-btn-primary" href="<?= htmlspecialchars($guestLinks['signup']) ?>">Sign up free</a>
    </div>
</aside>
