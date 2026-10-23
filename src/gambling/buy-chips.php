<?php
// Buying extra chips: the page behind the faint card button in the top right of the casino home (gambling/index.php).
// Nothing is bought here. It only explains that chips are normally free, that extra ones can be bought from the owner
// at HBHUB_CASINO_CHIPS_PER_KRONE per krone by messaging them, and that the money is never refunded. The owner then
// adds the chips by hand. The same rules are in the terms (legal/terms.php#chips).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const BUY_CHIPS_SELLER_NAME = 'Noha Gayy'; // the owner's userName on HB Hub, the one to message

$casinoWallet = hbHubCasinoStatus(hbHubDatabase(), (int) $hbHubUser['userId']);
$navCasinoChips = $casinoWallet['chips']; // the chip count in the top bar (navPanel.php)

// Link the name to the owner's profile when the account exists (and isn't banned), plain text otherwise
$statement = hbHubDatabase()->prepare('SELECT userId FROM `HBHub-Users` WHERE userNameLower = ? AND userStatus <> ? LIMIT 1');
$statement->execute([mb_strtolower(BUY_CHIPS_SELLER_NAME), HBHUB_SESSION_STATUS_BANNED]);
$buyChipsSellerId = $statement->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Buy chips - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="How to buy extra casino chips on HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <h1>Buy chips</h1>
        <p class="casino-intro">
            Normally I don't take money for chips. Everyone gets at least <?= number_format(HBHUB_CASINO_DAILY_CALENDAR[0]) ?> free
            chips every day, and that's how the casino is meant to be played.
        </p>

        <section class="casino-card casino-rules" aria-labelledby="buy-chips-how">
            <h2 id="buy-chips-how">Want more?</h2>
            <p class="casino-buy-price">
                <img class="casino-balance-icon" src="/assets/images/icons/chip.svg" alt="">
                <?= number_format(HBHUB_CASINO_CHIPS_PER_KRONE) ?> chips for 1 kr
            </p>
            <p class="casino-hint">
                If you really want more chips, you can buy them. Write to
                <?php if ($buyChipsSellerId !== false): ?>
                    <a href="/profile?user=<?= (int) $buyChipsSellerId ?>"><strong><?= htmlspecialchars(BUY_CHIPS_SELLER_NAME) ?></strong></a>
                <?php else: ?>
                    <strong><?= htmlspecialchars(BUY_CHIPS_SELLER_NAME) ?></strong>
                <?php endif; ?>
                in the chat, or hit me up somewhere else, and we'll make the deal. The chips are added to your account
                by hand once you've paid. NOTE! The price is the price and not negotiable!
            </p>
        </section>

        <section class="casino-card casino-alert-card" aria-labelledby="buy-chips-disclaimer">
            <h2 id="buy-chips-disclaimer">Disclaimer</h2>
            <ul class="casino-rule-list">
                <li>
                    <strong>No refunds, for any reason.</strong> If you're banned, delete your account or block the
                    casino in your settings, you will not get your money back.
                </li>
                <li>Bought chips work like any other chips. They can be lost in the games and can't be swapped back into money.</li>
                <li>
                    Bought chips still follow the <a href="/legal/terms#chips">terms</a>: they can be corrected or
                    reset after a bug or cheating, without a refund.
                </li>
            </ul>
        </section>

        <p class="casino-hint">
            If gambling, also with real money, is starting to feel like a problem,
            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a> gives free and
            anonymous help.
        </p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
