<?php
// Casino home (the Gambling section): the user's chips, the daily chips and the games.
// casino.js (loaded by navPanel.php along with the chip count in the top bar) keeps the chips and the countdown up to date.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$casinoWallet = hbHubCasinoStatus(hbHubDatabase(), (int) $hbHubUser['userId']);
$casinoDaily = $casinoWallet['daily'];
$navCasinoChips = $casinoWallet['chips']; // the chip count in the top bar (navPanel.php)

/**
 * Seconds as "H:MM:SS", the same as casino.js shows the countdown.
 */
function casinoCountdown(int $seconds): string
{
    return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Casino - HB Hub</title>
    <meta name="description" content="Casino page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino">
        <h1>Casino</h1>

        <div class="casino-overview">
            <section class="casino-card" aria-labelledby="casino-chips-title">
                <h2 id="casino-chips-title">Your chips</h2>
                <p class="casino-balance">
                    <img class="casino-balance-icon" src="/assets/images/icons/chip.svg" alt="">
                    <span data-casino-chips="<?= $casinoWallet['chips'] ?>"><?= number_format($casinoWallet['chips']) ?></span>
                </p>
                <p class="casino-hint">Every game uses the same chips.</p>
            </section>

            <section class="casino-card casino-daily" aria-labelledby="casino-daily-title"
                     data-casino-daily data-seconds="<?= $casinoDaily['seconds'] ?>" data-amount="<?= $casinoDaily['amount'] ?>">
                <h2 id="casino-daily-title">Daily chips</h2>
                <p class="casino-hint">
                    Collect <?= number_format($casinoDaily['amount']) ?> free chips once a day.
                </p>
                <button type="button" class="casino-btn casino-btn-primary" data-casino-claim<?= $casinoDaily['ready'] ? '' : ' disabled' ?>>Collect daily chips</button>
                <p class="casino-daily-timer" role="timer" data-casino-daily-timer>
                    <?= $casinoDaily['ready'] ? 'Your daily chips are ready.' : 'Next daily chips in ' . casinoCountdown($casinoDaily['seconds']) ?>
                </p>
                <p class="casino-daily-message" role="status" data-casino-daily-message></p>
            </section>
        </div>

        <section class="casino-games" aria-labelledby="casino-games-title">
            <h2 id="casino-games-title">Games</h2>
            <ul class="casino-game-list">
                <li>
                    <a class="casino-game" href="/gambling/blackjack/">
                        <span class="casino-game-name">Blackjack</span>
                        <span class="casino-game-text">Get closer to 21 than the dealer without going over, alone or with up to 5 players.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game" href="/gambling/roulette/">
                        <span class="casino-game-name">Roulette</span>
                        <span class="casino-game-text">Bet on a number, a color or anything in between, and see where the ball lands.</span>
                    </a>
                </li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
