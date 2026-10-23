<?php
// The Blackjack lobby: join a table by its code, play solo, open a table, the user's invites and the public tables
// (both kept up to date by blackjackLobby.js), and the rules. A user sits at one table at a time.
// The forms post here (action): joinCode (code), join (room - from the lists), solo, create (name, type, minBet) and
// leave. Joining or opening a table goes straight to it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

const LOBBY_CREATE_TYPES = [ // what the "Who can join" choices make
    'public' => HBHUB_BLACKJACK_ROOM_PUBLIC,
    'code' => HBHUB_BLACKJACK_ROOM_CODE,
    'invite' => HBHUB_BLACKJACK_ROOM_INVITE,
];

function lobbyGoToTable(int $roomId): never
{
    header('Location: /gambling/blackjack/table?room=' . $roomId, true, 303);
    exit;
}

/**
 * Opens a table and goes to it. Returns a message when it couldn't, or goes to the table the user already sits at.
 */
function lobbyCreate(PDO $db, array $user, string $name, int $type, int $minBet): string
{
    try {
        lobbyGoToTable(hbHubBlackjackCreateRoom($db, (int) $user['userId'], $name, $type, $minBet));
    } catch (PDOException $e) {
        $seat = hbHubBlackjackUserSeat($db, (int) $user['userId']); // sat down at a table in another tab meanwhile
        if ($seat !== null) {
            lobbyGoToTable($seat['roomId']);
        }
        error_log('Blackjack: could not open a table: ' . $e->getMessage());
        return 'Something went wrong opening a table. Please try again.';
    }
}

$lobbyErrors = [];
$lobbyValues = ['name' => '', 'type' => 'public', 'minBet' => HBHUB_BLACKJACK_MIN_BETS[0], 'code' => ''];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : null; // only from the site itself (session.php)

if (in_array($action, ['solo', 'create'], true)) {
    $seat = hbHubBlackjackUserSeat($db, $userId);
    if ($seat !== null) {
        lobbyGoToTable($seat['roomId']);
    }
}

if ($action === 'solo') {
    $lobbyErrors['solo'] = lobbyCreate($db, $hbHubUser, hbHubBlackjackRoomName(null, $hbHubUser['userName']),
        HBHUB_BLACKJACK_ROOM_SOLO, HBHUB_BLACKJACK_SOLO_MIN_BET);
}

if ($action === 'create') {
    $lobbyValues['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $lobbyValues['type'] = $_POST['type'] ?? null;
    $lobbyValues['minBet'] = (int) ($_POST['minBet'] ?? 0);

    if (!is_string($lobbyValues['type']) || !isset(LOBBY_CREATE_TYPES[$lobbyValues['type']])) {
        $lobbyErrors['create'] = 'Choose who can join the table.';
    } elseif (!in_array($lobbyValues['minBet'], HBHUB_BLACKJACK_MIN_BETS, true)) {
        $lobbyErrors['create'] = 'Choose a minimum bet.';
    } else {
        $lobbyErrors['create'] = lobbyCreate($db, $hbHubUser, hbHubBlackjackRoomName($lobbyValues['name'], $hbHubUser['userName']),
            LOBBY_CREATE_TYPES[$lobbyValues['type']], $lobbyValues['minBet']);
    }
}

if ($action === 'join') {
    $roomId = $_POST['room'] ?? null;
    $error = is_string($roomId) && ctype_digit($roomId) && strlen($roomId) <= 10
        ? hbHubBlackjackJoin($db, $userId, (int) $roomId, false)
        : 'That table isn\'t there anymore.';
    if ($error === null) {
        lobbyGoToTable((int) $roomId);
    }
    $lobbyErrors['list'] = $error;
}

if ($action === 'joinCode') {
    $lobbyValues['code'] = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 16) : '';
    $code = hbHubBlackjackCleanCode($lobbyValues['code']);
    $roomId = $code !== null ? hbHubBlackjackRoomIdByCode($db, $code) : null;
    if ($code === null) {
        $lobbyErrors['code'] = 'A code is 6 letters and numbers, like K7PX3M.';
    } elseif ($roomId === null) {
        $lobbyErrors['code'] = 'There\'s no table with that code. It may have closed.';
    } else {
        $error = hbHubBlackjackJoin($db, $userId, $roomId, true);
        if ($error === null) {
            lobbyGoToTable($roomId);
        }
        $lobbyErrors['code'] = $error;
    }
}

if ($action === 'leave') {
    hbHubBlackjackLeave($db, $userId);
    header('Location: /gambling/blackjack/', true, 303);
    exit;
}

hbHubBlackjackSweep($db); // tables everyone has closed, so they don't show (the user's own too, once they've been gone too long)
$lobbySeat = hbHubBlackjackUserSeat($db, $userId);
$lobbyRoom = $lobbySeat !== null ? hbHubBlackjackRoom($db, $lobbySeat['roomId']) : null;
$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/lobby.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/lobby.css') ?>">
    <title>Blackjack - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Blackjack in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="lobby lobby-blackjack" data-bj-lobby data-seated="<?= $lobbyRoom !== null ? '1' : '0' ?>">
        <a class="lobby-back" href="/gambling/">&larr; Casino</a>
        <header class="lobby-hero">
            <div class="lobby-hero-icon">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <rect x="8" y="10" width="30" height="42" rx="5" fill="#fff" opacity="0.55" transform="rotate(-12 23 31)"/>
                    <rect x="24" y="10" width="30" height="42" rx="5" fill="#fff" transform="rotate(8 39 31)"/>
                    <path d="M39 20c-4.5 5.2-9 8.4-9 12.6a4.6 4.6 0 0 0 7.6 3.5L36.2 41h5.6l-1.4-4.9A4.6 4.6 0 0 0 48 32.6c0-4.2-4.5-7.4-9-12.6z"
                          fill="#14231b" transform="rotate(8 39 31)"/>
                </svg>
            </div>
            <div class="lobby-hero-text">
                <h1>Blackjack</h1>
                <p class="lobby-tagline">Get closer to 21 than the dealer without going over. Play on your own, or with friends at the same table.</p>
                <ul class="lobby-facts">
                    <li>1-<?= HBHUB_BLACKJACK_SEATS ?> players</li>
                    <li>Min bet <?= number_format(min(HBHUB_BLACKJACK_MIN_BETS)) ?>-<?= number_format(max(HBHUB_BLACKJACK_MIN_BETS)) ?> chips</li>
                    <li>Blackjack pays 3:2</li>
                </ul>
            </div>
            <div class="lobby-hero-actions">
                <a class="lobby-hero-btn" href="/gambling/blackjack/leaderboard">Leaderboard</a>
                <a class="lobby-hero-btn" href="#rules">Rules</a>
            </div>
        </header>

        <div class="lobby-notes">
            <?php if (isset($lobbyErrors['list'])): ?>
                <p class="casino-alert" role="alert"><?= htmlspecialchars($lobbyErrors['list']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($lobbyRoom !== null): ?>
            <section class="lobby-panel lobby-current" aria-labelledby="lobby-yours-title">
                <div class="lobby-current-text">
                    <span class="lobby-pulse" aria-hidden="true"></span>
                    <div>
                        <h2 id="lobby-yours-title">Your table</h2>
                        <p class="lobby-hint">You're sitting at <strong class="lobby-current-name"><?= htmlspecialchars($lobbyRoom['roomName']) ?></strong>. You can be at one table at a time.</p>
                    </div>
                </div>
                <div class="lobby-actions">
                    <a class="casino-btn casino-btn-primary" href="/gambling/blackjack/table?room=<?= $lobbyRoom['roomId'] ?>">Back to your table</a>
                    <form method="post">
                        <input type="hidden" name="action" value="leave">
                        <button type="submit" class="casino-btn">Leave it</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <div class="lobby-body">
            <div class="lobby-side">
                <section class="lobby-panel" aria-labelledby="lobby-join-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-join-title">Join a table</h2>
                    </div>
                    <fieldset class="lobby-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                        <form method="post" class="lobby-form">
                            <input type="hidden" name="action" value="joinCode">
                            <label class="casino-label" for="lobby-code">Join code</label>
                            <div class="lobby-code">
                                <input type="text" id="lobby-code" name="code" class="casino-input" maxlength="16" required
                                       autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7PX3M"
                                       value="<?= htmlspecialchars($lobbyValues['code']) ?>"<?= isset($lobbyErrors['code']) ? ' aria-describedby="lobby-code-error"' : '' ?>>
                                <button type="submit" class="casino-btn casino-btn-primary">Join</button>
                            </div>
                            <?php if (isset($lobbyErrors['code'])): ?>
                                <p class="casino-error" id="lobby-code-error" role="alert"><?= htmlspecialchars($lobbyErrors['code']) ?></p>
                            <?php endif; ?>
                        </form>

                        <p class="lobby-or">or</p>
                        <form method="post" class="lobby-form">
                            <input type="hidden" name="action" value="solo">
                            <button type="submit" class="casino-btn lobby-submit">Play solo against the dealer</button>
                            <?php if (isset($lobbyErrors['solo'])): ?>
                                <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['solo']) ?></p>
                            <?php endif; ?>
                        </form>
                    </fieldset>
                </section>

                <section class="lobby-panel" id="open" aria-labelledby="lobby-create-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-create-title">Open a table</h2>
                    </div>
                    <fieldset class="lobby-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                        <form method="post" class="lobby-form">
                            <input type="hidden" name="action" value="create">
                            <label class="casino-label" for="lobby-name">Name <span class="casino-optional">(optional)</span></label>
                            <input type="text" id="lobby-name" name="name" class="casino-input" maxlength="<?= HBHUB_BLACKJACK_ROOM_NAME_MAX ?>"
                                   placeholder="<?= htmlspecialchars(hbHubBlackjackRoomName(null, $hbHubUser['userName'])) ?>"
                                   value="<?= htmlspecialchars($lobbyValues['name']) ?>">

                            <fieldset class="lobby-choices">
                                <legend class="casino-label">Who can join</legend>
                                <?php foreach ([
                                    'public' => ['Public', 'Listed here, so anyone can join.'],
                                    'code' => ['With the code', 'Not listed. Anyone you give the code to can join.'],
                                    'invite' => ['Invite-only', 'Not listed. Only people you invite can join.'],
                                ] as $value => [$label, $hint]): ?>
                                    <label class="lobby-option">
                                        <input type="radio" name="type" value="<?= $value ?>"<?= $lobbyValues['type'] === $value ? ' checked' : '' ?>>
                                        <span class="lobby-option-text"><strong><?= $label ?></strong> <span class="lobby-hint"><?= $hint ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>

                            <fieldset class="lobby-bets">
                                <legend class="casino-label">Minimum bet in chips</legend>
                                <?php foreach (HBHUB_BLACKJACK_MIN_BETS as $minBet): ?>
                                    <label class="lobby-bet">
                                        <input type="radio" name="minBet" value="<?= $minBet ?>"<?= $lobbyValues['minBet'] === $minBet ? ' checked' : '' ?>>
                                        <img src="/assets/images/icons/chip.svg" alt=""><?= number_format($minBet) ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>

                            <button type="submit" class="casino-btn casino-btn-primary lobby-submit">Open table</button>
                            <?php if (isset($lobbyErrors['create'])): ?>
                                <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['create']) ?></p>
                            <?php endif; ?>
                        </form>
                    </fieldset>
                </section>
            </div>

            <div class="lobby-lists">
                <section class="lobby-panel lobby-list lobby-invites is-empty" id="invites" aria-labelledby="lobby-invites-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-invites-title">Your invites</h2>
                        <span class="lobby-count" data-bj-invites-count></span>
                    </div>
                    <ul class="lobby-rooms" data-bj-invites></ul>
                    <p class="lobby-empty" data-bj-invites-empty>Loading…</p>
                </section>

                <section class="lobby-panel lobby-list" aria-labelledby="lobby-rooms-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-rooms-title">Public tables</h2>
                        <span class="lobby-count" data-bj-rooms-count></span>
                        <span class="lobby-live" title="This list keeps itself up to date"><span class="lobby-pulse" aria-hidden="true"></span>Live</span>
                    </div>
                    <ul class="lobby-rooms" data-bj-rooms></ul>
                    <p class="lobby-empty" data-bj-rooms-empty>Loading…</p>
                </section>
            </div>
        </div>

        <section class="lobby-panel lobby-rules" id="rules" aria-labelledby="lobby-rules-title">
            <div class="lobby-panel-head">
                <h2 id="lobby-rules-title">Rules</h2>
            </div>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/rules.php'; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/blackjackLobby.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/blackjackLobby.js') ?>" defer></script>
</body>
</html>
