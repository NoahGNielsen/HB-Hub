<?php
// The Baccarat lobby: join a table by its code, open a table, the user's invites and the public tables (both kept up
// to date by baccaratLobby.js), and the rules. A user sits at one Baccarat table at a time. There's no solo play, as
// Baccarat is for 2-6 players.
// The forms post here (action): joinCode (code), join (room - from the lists), create (name, type, minBet) and leave.
// Joining or opening a table goes straight to it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

const LOBBY_CREATE_TYPES = [ // what the "Who can join" choices make
    'public' => HBHUB_BACCARAT_ROOM_PUBLIC,
    'code' => HBHUB_BACCARAT_ROOM_CODE,
    'invite' => HBHUB_BACCARAT_ROOM_INVITE,
];

function lobbyGoToTable(int $roomId): never
{
    header('Location: /gambling/baccarat/table?room=' . $roomId, true, 303);
    exit;
}

/**
 * Opens a table and goes to it. Returns a message when it couldn't, or goes to the table the user already sits at.
 */
function lobbyCreate(PDO $db, array $user, string $name, int $type, int $minBet): string
{
    try {
        lobbyGoToTable(hbHubBaccaratCreateRoom($db, (int) $user['userId'], $name, $type, $minBet));
    } catch (PDOException $e) {
        $seat = hbHubBaccaratUserSeat($db, (int) $user['userId']); // sat down at a table in another tab meanwhile
        if ($seat !== null) {
            lobbyGoToTable($seat['roomId']);
        }
        error_log('Baccarat: could not open a table: ' . $e->getMessage());
        return 'Something went wrong opening a table. Please try again.';
    }
}

$lobbyErrors = [];
$lobbyValues = ['name' => '', 'type' => 'public', 'minBet' => HBHUB_BACCARAT_MIN_BETS[0], 'code' => ''];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : null; // only from the site itself (session.php)

if ($action === 'create') {
    $seat = hbHubBaccaratUserSeat($db, $userId);
    if ($seat !== null) {
        lobbyGoToTable($seat['roomId']);
    }

    $lobbyValues['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $lobbyValues['type'] = $_POST['type'] ?? null;
    $lobbyValues['minBet'] = (int) ($_POST['minBet'] ?? 0);

    if (!is_string($lobbyValues['type']) || !isset(LOBBY_CREATE_TYPES[$lobbyValues['type']])) {
        $lobbyErrors['create'] = 'Choose who can join the table.';
    } elseif (!in_array($lobbyValues['minBet'], HBHUB_BACCARAT_MIN_BETS, true)) {
        $lobbyErrors['create'] = 'Choose a minimum bet.';
    } else {
        $lobbyErrors['create'] = lobbyCreate($db, $hbHubUser, hbHubBaccaratRoomName($lobbyValues['name'], $hbHubUser['userName']),
            LOBBY_CREATE_TYPES[$lobbyValues['type']], $lobbyValues['minBet']);
    }
}

if ($action === 'join') {
    $roomId = $_POST['room'] ?? null;
    $error = is_string($roomId) && ctype_digit($roomId) && strlen($roomId) <= 10
        ? hbHubBaccaratJoin($db, $userId, (int) $roomId, false)
        : 'That table isn\'t there anymore.';
    if ($error === null) {
        lobbyGoToTable((int) $roomId);
    }
    $lobbyErrors['list'] = $error;
}

if ($action === 'joinCode') {
    $lobbyValues['code'] = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 16) : '';
    $code = hbHubBaccaratCleanCode($lobbyValues['code']);
    $roomId = $code !== null ? hbHubBaccaratRoomIdByCode($db, $code) : null;
    if ($code === null) {
        $lobbyErrors['code'] = 'A code is 6 letters and numbers, like K7PX3M.';
    } elseif ($roomId === null) {
        $lobbyErrors['code'] = 'There\'s no table with that code. It may have closed.';
    } else {
        $error = hbHubBaccaratJoin($db, $userId, $roomId, true);
        if ($error === null) {
            lobbyGoToTable($roomId);
        }
        $lobbyErrors['code'] = $error;
    }
}

if ($action === 'leave') {
    hbHubBaccaratLeave($db, $userId);
    header('Location: /gambling/baccarat/', true, 303);
    exit;
}

hbHubBaccaratSweep($db); // tables everyone has closed, so they don't show (the user's own too, once they've been gone too long)
$lobbySeat = hbHubBaccaratUserSeat($db, $userId);
$lobbyRoom = $lobbySeat !== null ? hbHubBaccaratRoom($db, $lobbySeat['roomId']) : null;
$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Baccarat - HB Hub</title>
    <meta name="description" content="Baccarat in the HB Hub casino">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino" data-bac-lobby data-seated="<?= $lobbyRoom !== null ? '1' : '0' ?>">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <div class="casino-title-row">
            <h1>Baccarat</h1>
            <a class="casino-btn" href="/gambling/baccarat/leaderboard">Leaderboard</a>
        </div>
        <p class="casino-intro">
            Bet on the <strong class="casino-bac-player">Player</strong> or the <strong class="casino-bac-banker">Banker</strong>,
            whichever ends closest to 9. For <?= HBHUB_BACCARAT_MIN_PLAYERS ?>-<?= HBHUB_BACCARAT_SEATS ?> players at a table.
        </p>
        <?php if (isset($lobbyErrors['list'])): ?>
            <p class="casino-alert" role="alert"><?= htmlspecialchars($lobbyErrors['list']) ?></p>
        <?php endif; ?>

        <?php if ($lobbyRoom !== null): ?>
            <section class="casino-card casino-your-table" aria-labelledby="lobby-yours-title">
                <h2 id="lobby-yours-title">Your table</h2>
                <p class="casino-hint">You're sitting at <strong><?= htmlspecialchars($lobbyRoom['roomName']) ?></strong>. You can be at one table at a time.</p>
                <div class="casino-actions">
                    <a class="casino-btn casino-btn-primary" href="/gambling/baccarat/table?room=<?= $lobbyRoom['roomId'] ?>">Back to your table</a>
                    <form method="post">
                        <input type="hidden" name="action" value="leave">
                        <button type="submit" class="casino-btn">Leave it</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <div class="casino-overview">
            <section class="casino-card" aria-labelledby="lobby-join-title">
                <h2 id="lobby-join-title">Join a table</h2>
                <fieldset class="casino-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                    <form method="post" class="casino-form">
                        <input type="hidden" name="action" value="joinCode">
                        <label class="casino-label" for="lobby-code">Join code</label>
                        <div class="casino-inline">
                            <input type="text" id="lobby-code" name="code" class="casino-input casino-code-input" maxlength="16" required
                                   autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7PX3M"
                                   value="<?= htmlspecialchars($lobbyValues['code']) ?>"<?= isset($lobbyErrors['code']) ? ' aria-describedby="lobby-code-error"' : '' ?>>
                            <button type="submit" class="casino-btn casino-btn-primary">Join</button>
                        </div>
                        <?php if (isset($lobbyErrors['code'])): ?>
                            <p class="casino-error" id="lobby-code-error" role="alert"><?= htmlspecialchars($lobbyErrors['code']) ?></p>
                        <?php endif; ?>
                    </form>
                    <p class="casino-hint">
                        Or join one of the public tables below. Baccarat needs at least <?= HBHUB_BACCARAT_MIN_PLAYERS ?>
                        players, so open a table and bring a friend if there are none.
                    </p>
                </fieldset>
            </section>

            <section class="casino-card" aria-labelledby="lobby-create-title">
                <h2 id="lobby-create-title">Open a table</h2>
                <fieldset class="casino-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                    <form method="post" class="casino-form">
                        <input type="hidden" name="action" value="create">
                        <label class="casino-label" for="lobby-name">Name <span class="casino-optional">(optional)</span></label>
                        <input type="text" id="lobby-name" name="name" class="casino-input" maxlength="<?= HBHUB_BACCARAT_ROOM_NAME_MAX ?>"
                               placeholder="<?= htmlspecialchars(hbHubBaccaratRoomName(null, $hbHubUser['userName'])) ?>"
                               value="<?= htmlspecialchars($lobbyValues['name']) ?>">

                        <fieldset class="casino-choices">
                            <legend class="casino-label">Who can join</legend>
                            <?php foreach ([
                                'public' => ['Public', 'Listed below, so anyone can join.'],
                                'code' => ['With the code', 'Not listed. Anyone you give the code to can join.'],
                                'invite' => ['Invite-only', 'Not listed. Only people you invite can join.'],
                            ] as $value => [$label, $hint]): ?>
                                <label class="casino-choice">
                                    <input type="radio" name="type" value="<?= $value ?>"<?= $lobbyValues['type'] === $value ? ' checked' : '' ?>>
                                    <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>

                        <label class="casino-label" for="lobby-min-bet">Minimum bet</label>
                        <select id="lobby-min-bet" name="minBet" class="casino-input">
                            <?php foreach (HBHUB_BACCARAT_MIN_BETS as $minBet): ?>
                                <option value="<?= $minBet ?>"<?= $lobbyValues['minBet'] === $minBet ? ' selected' : '' ?>><?= number_format($minBet) ?> chips</option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="casino-btn casino-btn-primary">Open table</button>
                        <?php if (isset($lobbyErrors['create'])): ?>
                            <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['create']) ?></p>
                        <?php endif; ?>
                    </form>
                </fieldset>
            </section>
        </div>

        <section class="casino-card casino-list-card" id="invites" aria-labelledby="lobby-invites-title">
            <h2 id="lobby-invites-title">Your invites</h2>
            <ul class="casino-rooms" data-bac-invites></ul>
            <p class="casino-hint" data-bac-invites-empty>Loading…</p>
        </section>

        <section class="casino-card casino-list-card" aria-labelledby="lobby-rooms-title">
            <h2 id="lobby-rooms-title">Public tables</h2>
            <ul class="casino-rooms" data-bac-rooms></ul>
            <p class="casino-hint" data-bac-rooms-empty>Loading…</p>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="lobby-rules-title">
            <h2 id="lobby-rules-title">Rules</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/rules.php'; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/baccaratLobby.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/baccaratLobby.js') ?>" defer></script>
</body>
</html>
