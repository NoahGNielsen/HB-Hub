<?php
// Casino home (the Gambling section): the user's chips, the daily chips with their 30-day calendar, the games and a
// link to the casino leaderboard. casino.js (loaded by navPanel.php along with the chip count in the top bar) keeps the
// chips, the calendar and the countdown up to date.
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

/**
 * A calendar day's chips, short enough for its square on a phone: 2500 is "2.5k", 15000 "15k".
 */
function casinoShortChips(int $chips): string
{
    return rtrim(rtrim(number_format($chips / 1000, 2), '0'), '.') . 'k';
}

/**
 * The streak over the daily chips, the same as casino.js writes it.
 */
function casinoStreakText(int $collected): string
{
    return match ($collected) {
        0 => 'No streak yet',
        1 => '1 day in a row',
        default => $collected . ' days in a row',
    };
}

const CASINO_BIG_DAYS = [7, 14, 21]; // the end of each week on the calendar, gold like day 30
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Casino - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Casino page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino">
        <div class="casino-title-row">
            <div class="casino-hero">
                <p class="casino-kicker">Chips, not cash</p>
                <h1>Casino</h1>
            </div>
            <!-- Kept faint on purpose: buying chips is there for whoever looks, not something to push -->
            <a class="casino-pay-link" href="/gambling/buy-chips" aria-label="Buy chips" title="Buy chips">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <path d="M2 10h20M6 15h4"/>
                </svg>
            </a>
        </div>

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
                     data-casino-daily data-seconds="<?= $casinoDaily['seconds'] ?>" data-amount="<?= $casinoDaily['amount'] ?>"
                     data-day="<?= $casinoDaily['day'] ?>" data-collected="<?= $casinoDaily['collected'] ?>">
                <div class="casino-daily-head">
                    <h2 id="casino-daily-title">Daily chips</h2>
                    <p class="casino-streak" data-casino-streak><?= casinoStreakText($casinoDaily['collected']) ?></p>
                </div>
                <p class="casino-daily-next">
                    <span data-casino-daily-when><?= $casinoDaily['ready'] ? 'Today' : 'Tomorrow' ?></span>:
                    day <span data-casino-daily-day><?= $casinoDaily['day'] ?></span> of <?= HBHUB_CASINO_DAILY_DAYS ?>
                </p>
                <p class="casino-daily-amount">
                    <img class="casino-daily-icon" src="/assets/images/icons/chip.svg" alt="">
                    <span>+<span data-casino-daily-amount><?= number_format($casinoDaily['amount']) ?></span></span>
                </p>
                <button type="button" class="casino-btn casino-btn-primary" data-casino-claim<?= $casinoDaily['ready'] ? '' : ' disabled' ?>>Collect daily chips</button>
                <p class="casino-daily-timer" role="timer" data-casino-daily-timer>
                    <?= $casinoDaily['ready'] ? 'Your daily chips are ready.' : 'Next daily chips in ' . casinoCountdown($casinoDaily['seconds']) ?>
                </p>
                <p class="casino-daily-message" role="status" data-casino-daily-message></p>
            </section>
        </div>

        <section class="casino-card casino-calendar-card" aria-labelledby="casino-calendar-title">
            <div class="casino-calendar-head">
                <h2 id="casino-calendar-title">Daily calendar</h2>
                <p class="casino-hint">
                    Collect every day to move along. The further you get, the more you get, with a bonus at the end of
                    every week. Miss a day and you start over at day 1.
                </p>
            </div>
            <!-- A week to a row. casino.js moves the ticks and the highlighted day along (data-casino-calendar, data-day). -->
            <ol class="casino-calendar" data-casino-calendar>
                <?php foreach (HBHUB_CASINO_DAILY_CALENDAR as $casinoIndex => $casinoChips):
                    $casinoDay = $casinoIndex + 1;
                    $casinoClasses = ['casino-calendar-day'];
                    if (in_array($casinoDay, CASINO_BIG_DAYS, true)) {
                        $casinoClasses[] = 'is-big';
                    }
                    if ($casinoDay === HBHUB_CASINO_DAILY_DAYS) {
                        $casinoClasses[] = 'is-last';
                    }
                    if ($casinoIndex > 0 && $casinoChips < HBHUB_CASINO_DAILY_CALENDAR[$casinoIndex - 1]) {
                        $casinoClasses[] = 'is-lean'; // less than the day before
                    }
                    if ($casinoDay <= $casinoDaily['collected']) {
                        $casinoClasses[] = 'is-collected';
                    } elseif ($casinoDay === $casinoDaily['day']) {
                        $casinoClasses[] = $casinoDaily['ready'] ? 'is-next is-ready' : 'is-next';
                    }
                    ?>
                    <li class="<?= implode(' ', $casinoClasses) ?>" data-day="<?= $casinoDay ?>"
                        title="Day <?= $casinoDay ?>: <?= number_format($casinoChips) ?> chips">
                        <span class="casino-calendar-number"><?= $casinoDay === HBHUB_CASINO_DAILY_DAYS ? 'Day ' . $casinoDay : $casinoDay ?></span>
                        <span class="casino-calendar-short" aria-hidden="true"><?= casinoShortChips($casinoChips) ?></span>
                        <span class="casino-calendar-full"><?= number_format($casinoChips) ?> chips</span>
                        <svg class="casino-calendar-tick" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M5 12.5l4.5 4.5L19 7.5"/>
                        </svg>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="casino-games" aria-labelledby="casino-games-title">
            <div class="casino-games-head">
                <h2 id="casino-games-title">Games</h2>
                <a class="casino-btn" href="/gambling/leaderboard">Leaderboard</a>
            </div>
            <ul class="casino-game-list">
                <li>
                    <a class="casino-game casino-game-blackjack" href="/gambling/blackjack/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">1 to 5 players</span>
                            <span class="casino-game-name">Blackjack</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect x="8" y="14" width="26" height="38" rx="4" transform="rotate(-12 21 33)"/>
                                <rect x="28" y="10" width="26" height="38" rx="4" transform="rotate(10 41 29)"/>
                                <path class="casino-game-art-mark" d="M41 19c-3.5 4.5-7.5 6.5-7.5 10.5a3.8 3.8 0 0 0 6.5 2.5l-1 5h4l-1-5a3.8 3.8 0 0 0 6.5-2.5c0-4-4-6-7.5-10.5z" transform="rotate(10 41 29)"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Get closer to 21 than the dealer without going over, alone or with up to 5 players.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-baccarat" href="/gambling/baccarat/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">1 to 6 players</span>
                            <span class="casino-game-name">Baccarat</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect x="6" y="14" width="26" height="38" rx="4" transform="rotate(-8 19 33)"/>
                                <rect x="32" y="14" width="26" height="38" rx="4" transform="rotate(8 45 33)"/>
                                <path class="casino-game-art-mark" d="M19 25l6 8-6 8-6-8z" transform="rotate(-8 19 33)"/>
                                <path class="casino-game-art-mark" d="M45 41s-8-5-8-10a4 4 0 0 1 8-1.5 4 4 0 0 1 8 1.5c0 5-8 10-8 10z" transform="rotate(8 45 33)"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Bet on the Player or the Banker, whichever ends closest to 9, solo or at a table of up to 6.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-roulette" href="/gambling/roulette/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">Single zero</span>
                            <span class="casino-game-name">Roulette</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <circle cx="32" cy="32" r="26"/>
                                <circle class="casino-game-art-line" cx="32" cy="32" r="17"/>
                                <path class="casino-game-art-line" d="M32 6v52M6 32h52M13.6 13.6l36.8 36.8M50.4 13.6L13.6 50.4"/>
                                <circle class="casino-game-art-mark" cx="32" cy="32" r="6"/>
                                <circle class="casino-game-art-mark" cx="47" cy="19" r="3.5"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Bet on a number, a color or anything in between, and see where the ball lands.</span>
                    </a>
                </li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
