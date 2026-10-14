<?php
// Blackjack: the rules, and a table to play at. "Play" (POST action=solo) opens a table of the user's own against
// the dealer, or takes them back to the table they already sit at (one table at a time).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/blackjack/room.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

function blackjackGoToTable(int $roomId): never
{
    header('Location: /gambling/blackjack/table?room=' . $roomId, true, 303);
    exit;
}

$blackjackError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'solo' && hbHubCasinoIsSameSite()) {
    $seat = hbHubBlackjackUserSeat($db, $userId);
    if ($seat !== null) {
        blackjackGoToTable($seat['roomId']);
    }

    // "Alice's table", with the name cut short so it fits the column
    $suffix = '\'s table';
    $name = mb_substr($hbHubUser['userName'], 0, HBHUB_BLACKJACK_ROOM_NAME_MAX - mb_strlen($suffix)) . $suffix;
    try {
        blackjackGoToTable(hbHubBlackjackCreateRoom($db, $userId, $name, HBHUB_BLACKJACK_ROOM_SOLO, HBHUB_BLACKJACK_SOLO_MIN_BET));
    } catch (PDOException $e) {
        // Sat down at a table in another tab meanwhile
        $seat = hbHubBlackjackUserSeat($db, $userId);
        if ($seat !== null) {
            blackjackGoToTable($seat['roomId']);
        }
        error_log('Blackjack: could not make a table: ' . $e->getMessage());
        $blackjackError = 'Something went wrong opening a table. Please try again.';
    }
}

$blackjackSeat = hbHubBlackjackUserSeat($db, $userId);
$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Blackjack - HB Hub</title>
    <meta name="description" content="Blackjack in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <h1>Blackjack</h1>
        <p class="casino-intro">Get closer to 21 than the dealer without going over. Played with your casino chips.</p>

        <section class="casino-card casino-play" aria-labelledby="blackjack-play-title">
            <h2 id="blackjack-play-title">Play</h2>
            <?php if ($blackjackSeat !== null): ?>
                <p class="casino-hint">You're sitting at a table.</p>
                <a class="casino-btn casino-btn-primary" href="/gambling/blackjack/table?room=<?= $blackjackSeat['roomId'] ?>">Back to your table</a>
            <?php else: ?>
                <p class="casino-hint">Play against the dealer at a table of your own. The minimum bet is <?= HBHUB_BLACKJACK_SOLO_MIN_BET ?> chips.</p>
                <form method="post">
                    <input type="hidden" name="action" value="solo">
                    <button type="submit" class="casino-btn casino-btn-primary">Play</button>
                </form>
            <?php endif; ?>
            <?php if ($blackjackError !== null): ?>
                <p class="casino-error" role="alert"><?= htmlspecialchars($blackjackError) ?></p>
            <?php endif; ?>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="blackjack-rules-title">
            <h2 id="blackjack-rules-title">Rules</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/blackjack/rules.php'; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
