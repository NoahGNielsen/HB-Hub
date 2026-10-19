<?php
// A Blackjack table (?room=id). This is just the page around it: blackjackTable.js draws the table and plays it
// through /gambling/blackjack/play. Only for the table the user sits at - anyone else is sent to the Blackjack page,
// where tables are joined. At a table for more players there's also its join code, sitting out, and for the host,
// inviting people (searched for with the chat's /chat/users).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubBlackjackUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubBlackjackRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /gambling/blackjack/', true, 302);
    exit;
}

$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
$tableChips = [10, 50, 100, 500, 1000]; // the chips to make up a bet with
$tableIsSolo = $room['roomType'] === HBHUB_BLACKJACK_ROOM_SOLO;
$tableSeats = hbHubBlackjackMaxSeats($room['roomType']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/blackjack.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/blackjack.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Blackjack - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="A Blackjack table in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="bj" data-blackjack data-room="<?= $room['roomId'] ?>" data-min-bet="<?= $room['roomMinBet'] ?>">
        <div class="bj-header">
            <div class="bj-heading">
                <h1 class="bj-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                <p class="bj-meta">
                    <?= HBHUB_BLACKJACK_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Table' ?>
                    <?php if (!$tableIsSolo): ?>
                        &middot; <span data-bj-players>1</span>/<?= $tableSeats ?> players
                    <?php endif; ?>
                    &middot; Minimum bet <?= number_format($room['roomMinBet']) ?> &middot; Blackjack pays 3:2 &middot; Dealer stands on 17
                </p>
            </div>
            <div class="bj-header-actions">
                <?php if (!$tableIsSolo): ?>
                    <button type="button" class="bj-code" data-bj-code="<?= htmlspecialchars($room['roomCode']) ?>"
                            aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                        <span class="bj-code-label">Code</span>
                        <strong class="bj-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                        <span class="bj-code-copied" data-bj-code-copied aria-live="polite"></span>
                    </button>
                    <button type="button" class="casino-btn" data-bj-invite hidden>Invite</button>
                    <button type="button" class="casino-btn" data-bj-sit-out hidden>Sit out</button>
                <?php endif; ?>
                <button type="button" class="casino-btn" data-bj-leave>Leave table</button>
            </div>
        </div>

        <section class="bj-table" aria-label="The table" data-bj-table>
            <div class="bj-dealer">
                <p class="bj-label">Dealer <span class="bj-total" data-bj-dealer-total></span></p>
                <div class="bj-cards" data-bj-dealer-cards></div>
                <p class="bj-note" data-bj-shuffled hidden>A new shoe was shuffled for this hand.</p>
            </div>

            <div class="bj-status">
                <p class="bj-status-text" role="status" data-bj-status>Loading the table…</p>
                <div class="bj-timer" data-bj-timer hidden>
                    <div class="bj-timer-track"><div class="bj-timer-bar" data-bj-timer-bar></div></div>
                    <span class="bj-timer-text" data-bj-timer-text></span>
                </div>
            </div>

            <div class="bj-shoe" data-bj-shoe hidden>
                <div class="bj-shoe-track" aria-hidden="true">
                    <div class="bj-shoe-bar" data-bj-shoe-bar></div>
                    <div class="bj-shoe-cut" data-bj-shoe-cut></div>
                </div>
                <span class="bj-shoe-text" data-bj-shoe-text></span>
            </div>

            <ol class="bj-seats" data-bj-seats></ol>
        </section>

        <section class="bj-controls" aria-label="Your moves">
            <p class="bj-message" role="alert" data-bj-message></p>

            <div class="bj-panel" data-bj-panel="bet" hidden>
                <p class="bj-bet-line">
                    Your bet: <strong class="bj-bet-amount" data-bj-pending>0</strong>
                    <span class="bj-hint">from <?= number_format($room['roomMinBet']) ?>, in steps of <?= HBHUB_BLACKJACK_BET_STEP ?></span>
                </p>
                <div class="bj-chip-row" role="group" aria-label="Add chips to your bet">
                    <?php foreach ($tableChips as $chip): ?>
                        <button type="button" class="bj-chip bj-chip-<?= $chip ?>" data-bj-chip="<?= $chip ?>" aria-label="Add <?= $chip ?>"><?= $chip >= 1000 ? ($chip / 1000) . 'K' : $chip ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="bj-panel-actions">
                    <button type="button" class="casino-btn" data-bj-bet-clear>Clear</button>
                    <button type="button" class="casino-btn" data-bj-bet-half>&frac12;</button>
                    <button type="button" class="casino-btn" data-bj-bet-double>&times;2</button>
                    <button type="button" class="casino-btn casino-btn-primary" data-bj-bet-place>Place bet</button>
                </div>
                <p class="bj-hint" data-bj-broke hidden>
                    You don't have enough chips for this table. <a href="/gambling/">Collect your daily chips</a>
                </p>
            </div>

            <div class="bj-panel" data-bj-panel="placed" hidden>
                <p class="bj-bet-line">Your bet: <strong class="bj-bet-amount" data-bj-placed>0</strong></p>
                <p class="bj-hint" data-bj-placed-hint></p>
                <div class="bj-panel-actions">
                    <button type="button" class="casino-btn" data-bj-bet-take-back>Take back</button>
                </div>
            </div>

            <div class="bj-panel" data-bj-panel="insurance" hidden>
                <p class="bj-bet-line">The dealer shows an ace. Insurance?</p>
                <p class="bj-hint">
                    Costs <strong data-bj-insurance-cost>0</strong> chips (half your bet), and pays 2:1 if the dealer has blackjack.
                    Your hand is played the same either way.
                </p>
                <div class="bj-panel-actions">
                    <button type="button" class="casino-btn" data-bj-insurance="0">No insurance</button>
                    <button type="button" class="casino-btn casino-btn-primary" data-bj-insurance="1">Take insurance</button>
                </div>
            </div>

            <div class="bj-panel" data-bj-panel="moves" hidden>
                <div class="bj-moves">
                    <button type="button" class="bj-move" data-bj-move="hit"><span>Hit</span><kbd>H</kbd></button>
                    <button type="button" class="bj-move" data-bj-move="stand"><span>Stand</span><kbd>S</kbd></button>
                    <button type="button" class="bj-move" data-bj-move="double"><span>Double</span><kbd>D</kbd></button>
                    <button type="button" class="bj-move" data-bj-move="split"><span>Split</span><kbd>P</kbd></button>
                </div>
            </div>

            <div class="bj-panel" data-bj-panel="wait" hidden>
                <p class="bj-hint" data-bj-wait></p>
            </div>
        </section>

        <details class="bj-rules casino-card">
            <summary>Rules</summary>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/rules.php'; ?>
        </details>

        <?php if (!$tableIsSolo): ?>
            <dialog class="bj-dialog bj-invite" aria-labelledby="bj-invite-title" data-bj-invite-dialog>
                <h2 id="bj-invite-title">Invite people</h2>
                <p>
                    <?php if ($room['roomType'] === HBHUB_BLACKJACK_ROOM_INVITE): ?>
                        Only people you invite can join this table.
                    <?php endif; ?>
                    They get a notification, and join from the Blackjack page.
                </p>
                <label class="casino-label" for="bj-invite-search">Find people</label>
                <input type="search" id="bj-invite-search" class="casino-input" placeholder="Search by name" autocomplete="off" data-bj-invite-search>
                <ul class="bj-invite-list" data-bj-invite-list></ul>
                <p class="bj-hint" role="status" data-bj-invite-status></p>
                <div class="bj-panel-actions">
                    <button type="button" class="casino-btn" data-bj-invite-close>Done</button>
                </div>
            </dialog>
        <?php endif; ?>

        <dialog class="bj-dialog" aria-labelledby="bj-leave-title" data-bj-leave-dialog>
            <h2 id="bj-leave-title">Leave the table?</h2>
            <p data-bj-leave-text></p>
            <div class="bj-panel-actions">
                <button type="button" class="casino-btn" data-bj-leave-cancel>Stay</button>
                <button type="button" class="casino-btn casino-btn-primary" data-bj-leave-confirm>Leave</button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/blackjackTable.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/blackjackTable.js') ?>" defer></script>
</body>
</html>
