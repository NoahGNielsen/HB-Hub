<?php
// A Baccarat table (?room=id). This is just the page around it: baccaratTable.js draws the table and plays it through
// /gambling/baccarat/play. Only for the table the user sits at - anyone else is sent to the Baccarat page, where tables
// are joined. There's also the table's join code, sitting out, and for the host, inviting people (searched for with
// the chat's /chat/users).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubBaccaratUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubBaccaratRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /gambling/baccarat/', true, 302);
    exit;
}

$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
$tableChips = [10, 50, 100, 500, 1000]; // the chips to make up the bets with
$tableCommission = (HBHUB_BACCARAT_BANKER_COMMISSION / 10) . '%'; // taken off a winning Banker bet's win, like "2.7%"
$tableSpots = [ // the betting board, in the order it's laid out: [name, what it pays]
    'playerPair' => ['Player pair', HBHUB_BACCARAT_PAYS['playerPair'] . ':1'],
    'tie' => ['Tie', HBHUB_BACCARAT_PAYS['tie'] . ':1'],
    'bankerPair' => ['Banker pair', HBHUB_BACCARAT_PAYS['bankerPair'] . ':1'],
    'player' => ['Player', HBHUB_BACCARAT_PAYS['player'] . ':1'],
    'banker' => ['Banker', HBHUB_BACCARAT_PAYS['banker'] . ':1 less ' . $tableCommission],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/baccarat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/baccarat.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Baccarat - HB Hub</title>
    <meta name="description" content="A Baccarat table in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="bac" data-baccarat data-room="<?= $room['roomId'] ?>" data-min-bet="<?= $room['roomMinBet'] ?>">
        <div class="bac-header">
            <div class="bac-heading">
                <h1 class="bac-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                <p class="bac-meta">
                    <?= HBHUB_BACCARAT_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Table' ?>
                    &middot; <span data-bac-players>1</span>/<?= HBHUB_BACCARAT_SEATS ?> players
                    &middot; Minimum bet <?= number_format($room['roomMinBet']) ?>
                    &middot; Player 1:1 &middot; Banker 1:1 with <?= $tableCommission ?> &middot cut; Tie 8:1 &middot; Pairs 11:1
                </p>
            </div>
            <div class="bac-header-actions">
                <button type="button" class="bac-code" data-bac-code="<?= htmlspecialchars($room['roomCode']) ?>"
                        aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                    <span class="bac-code-label">Code</span>
                    <strong class="bac-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                    <span class="bac-code-copied" data-bac-code-copied aria-live="polite"></span>
                </button>
                <button type="button" class="casino-btn" data-bac-invite hidden>Invite</button>
                <button type="button" class="casino-btn" data-bac-sit-out hidden>Sit out</button>
                <button type="button" class="casino-btn" data-bac-leave>Leave table</button>
            </div>
        </div>

        <!-- When the shoe has been shuffled (75% of it dealt), so everyone knows before they bet -->
        <p class="bac-shoe-notice" role="status" data-bac-shoe-notice hidden></p>

        <section class="bac-table" aria-label="The table" data-bac-table>
            <div class="bac-status">
                <p class="bac-status-text" role="status" data-bac-status>Loading the table…</p>
                <div class="bac-timer" data-bac-timer hidden>
                    <div class="bac-timer-track"><div class="bac-timer-bar" data-bac-timer-bar></div></div>
                    <span class="bac-timer-text" data-bac-timer-text></span>
                </div>
            </div>

            <div class="bac-coup">
                <?php foreach (['player' => 'Player', 'banker' => 'Banker'] as $side => $sideName): ?>
                    <div class="bac-hand is-<?= $side ?>" data-bac-hand="<?= $side ?>">
                        <p class="bac-hand-label"><?= $sideName ?> <span class="bac-total" data-bac-total></span></p>
                        <div class="bac-cards" data-bac-cards></div>
                        <p class="bac-hand-note" data-bac-note></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="bac-result" data-bac-result hidden></p>

            <div class="bac-shoe" data-bac-shoe hidden>
                <div class="bac-shoe-track" aria-hidden="true">
                    <div class="bac-shoe-bar" data-bac-shoe-bar></div>
                    <div class="bac-shoe-cut" data-bac-shoe-cut></div>
                </div>
                <span class="bac-shoe-text" data-bac-shoe-text></span>
            </div>

            <ol class="bac-seats" data-bac-seats></ol>
        </section>

        <section class="bac-controls" aria-label="Your bets">
            <p class="bac-message" role="alert" data-bac-message></p>

            <div class="bac-panel" data-bac-panel="bet" hidden>
                <p class="bac-bet-line">
                    Your bets: <strong class="bac-bet-amount" data-bac-pending>0</strong>
                    <span class="bac-hint">at least <?= number_format($room['roomMinBet']) ?> together, in steps of <?= HBHUB_BACCARAT_BET_STEP ?>. Tap a spot to put a chip on it, right-click it to take one off.</span>
                </p>
                <div class="bac-board" role="group" aria-label="Betting spots">
                    <?php foreach ($tableSpots as $spot => [$spotName, $pays]): ?>
                        <button type="button" class="bac-spot is-<?= $spot ?>" data-bac-spot="<?= $spot ?>" data-bac-pays="<?= $pays ?>">
                            <span class="bac-spot-name"><?= $spotName ?></span>
                            <span class="bac-spot-pays">pays <?= $pays ?></span>
                            <span class="bac-spot-chips" data-bac-spot-chips></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="bac-chip-row" role="group" aria-label="Chip to put down">
                    <?php foreach ($tableChips as $chip): ?>
                        <button type="button" class="bac-chip bac-chip-<?= $chip ?>" data-bac-chip="<?= $chip ?>" aria-pressed="false"
                                aria-label="<?= $chip ?> chip"><?= $chip >= 1000 ? ($chip / 1000) . 'K' : $chip ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="bac-panel-actions">
                    <button type="button" class="casino-btn" data-bac-bet-clear>Clear</button>
                    <button type="button" class="casino-btn" data-bac-bet-double>&times;2</button>
                    <button type="button" class="casino-btn casino-btn-primary" data-bac-bet-place>Place bets</button>
                </div>
                <p class="bac-hint" data-bac-broke hidden>
                    You don't have enough chips for this table. <a href="/gambling/">Collect your daily chips</a>
                </p>
            </div>

            <div class="bac-panel" data-bac-panel="placed" hidden>
                <p class="bac-bet-line">Your bets: <strong class="bac-bet-amount" data-bac-placed>0</strong></p>
                <ul class="bac-bet-list" data-bac-placed-list></ul>
                <p class="bac-hint" data-bac-placed-hint></p>
                <div class="bac-panel-actions">
                    <button type="button" class="casino-btn" data-bac-bet-take-back>Take back</button>
                </div>
            </div>

            <div class="bac-panel" data-bac-panel="wait" hidden>
                <p class="bac-hint" data-bac-wait></p>
            </div>
        </section>

        <details class="bac-rules casino-card">
            <summary>Rules</summary>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/rules.php'; ?>
        </details>

        <dialog class="bac-dialog bac-invite" aria-labelledby="bac-invite-title" data-bac-invite-dialog>
            <h2 id="bac-invite-title">Invite people</h2>
            <p>
                <?php if ($room['roomType'] === HBHUB_BACCARAT_ROOM_INVITE): ?>
                    Only people you invite can join this table.
                <?php endif; ?>
                They get a notification, and join from the Baccarat page.
            </p>
            <label class="casino-label" for="bac-invite-search">Find people</label>
            <input type="search" id="bac-invite-search" class="casino-input" placeholder="Search by name" autocomplete="off" data-bac-invite-search>
            <ul class="bac-invite-list" data-bac-invite-list></ul>
            <p class="bac-hint" role="status" data-bac-invite-status></p>
            <div class="bac-panel-actions">
                <button type="button" class="casino-btn" data-bac-invite-close>Done</button>
            </div>
        </dialog>

        <dialog class="bac-dialog" aria-labelledby="bac-leave-title" data-bac-leave-dialog>
            <h2 id="bac-leave-title">Leave the table?</h2>
            <p data-bac-leave-text></p>
            <div class="bac-panel-actions">
                <button type="button" class="casino-btn" data-bac-leave-cancel>Stay</button>
                <button type="button" class="casino-btn casino-btn-primary" data-bac-leave-confirm>Leave</button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/baccaratTable.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/baccaratTable.js') ?>" defer></script>
</body>
</html>
