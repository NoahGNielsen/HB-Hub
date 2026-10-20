<?php
// The chess lobby: the user's game, join a game by its code, open a game, the user's invites and the public games
// (both kept up to date by chessLobby.js), and the rules. A user is in one chess game at a time.
// The forms post here (action): joinCode (code), join (room - from the lists), create (name, type) and leave. Joining
// or opening a game goes straight to it, where the host picks the clock and colours in the game's settings.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

const LOBBY_CREATE_TYPES = [ // what the "Who can join" choices make
    'public' => HBHUB_CHESS_ROOM_PUBLIC,
    'code' => HBHUB_CHESS_ROOM_CODE,
    'invite' => HBHUB_CHESS_ROOM_INVITE,
];

function lobbyGoToGame(int $roomId): never
{
    header('Location: /games/chess/game?room=' . $roomId, true, 303);
    exit;
}

$lobbyErrors = [];
$lobbyValues = ['name' => '', 'type' => 'public', 'code' => ''];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : null; // only from the site itself (session.php)

if ($action === 'create') {
    $seat = hbHubChessUserSeat($db, $userId);
    if ($seat !== null) {
        lobbyGoToGame($seat['roomId']);
    }

    $lobbyValues['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $lobbyValues['type'] = $_POST['type'] ?? null;

    if (!is_string($lobbyValues['type']) || !isset(LOBBY_CREATE_TYPES[$lobbyValues['type']])) {
        $lobbyErrors['create'] = 'Choose who can join the game.';
    } else {
        try {
            lobbyGoToGame(hbHubChessCreateRoom($db, $userId, $hbHubUser['userName'],
                hbHubChessRoomName($lobbyValues['name'], $hbHubUser['userName']), LOBBY_CREATE_TYPES[$lobbyValues['type']]));
        } catch (PDOException $e) {
            $seat = hbHubChessUserSeat($db, $userId); // joined a game in another tab meanwhile
            if ($seat !== null) {
                lobbyGoToGame($seat['roomId']);
            }
            error_log('Chess: could not open a game: ' . $e->getMessage());
            $lobbyErrors['create'] = 'Something went wrong opening a game. Please try again.';
        }
    }
}

if ($action === 'join') {
    $roomId = $_POST['room'] ?? null;
    $error = is_string($roomId) && ctype_digit($roomId) && strlen($roomId) <= 10
        ? hbHubChessJoin($db, $userId, $hbHubUser['userName'], (int) $roomId, false)
        : 'That game isn\'t there anymore.';
    if ($error === null) {
        lobbyGoToGame((int) $roomId);
    }
    $lobbyErrors['list'] = $error;
}

if ($action === 'joinCode') {
    $lobbyValues['code'] = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 16) : '';
    $code = hbHubChessCleanCode($lobbyValues['code']);
    $roomId = $code !== null ? hbHubChessRoomIdByCode($db, $code) : null;
    if ($code === null) {
        $lobbyErrors['code'] = 'A code is 6 letters and numbers, like K7PX3M.';
    } elseif ($roomId === null) {
        $lobbyErrors['code'] = 'There\'s no game with that code. It may have ended.';
    } else {
        $error = hbHubChessJoin($db, $userId, $hbHubUser['userName'], $roomId, true);
        if ($error === null) {
            lobbyGoToGame($roomId);
        }
        $lobbyErrors['code'] = $error;
    }
}

if ($action === 'leave') {
    hbHubChessLeave($db, $userId);
    header('Location: /games/chess/', true, 303);
    exit;
}

hbHubChessSweep($db); // games everyone has closed, so they don't show
$lobbySeat = hbHubChessUserSeat($db, $userId);
$lobbyRoom = $lobbySeat !== null ? hbHubChessRoom($db, $lobbySeat['roomId']) : null;
$lobbyIsPlaying = $lobbyRoom !== null && $lobbyRoom['state']['phase'] === 'playing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Chess - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Play chess against your friends on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games" data-ch-lobby data-seated="<?= $lobbyRoom !== null ? '1' : '0' ?>">
        <a class="casino-back" href="/games/">&larr; Games</a>
        <div class="casino-title-row">
            <h1>Chess</h1>
            <a class="casino-btn" href="/games/chess/leaderboard">Leaderboard</a>
        </div>
        <p class="casino-intro">Play chess against someone else on the HB Hub, with or without a clock.</p>
        <?php if (isset($lobbyErrors['list'])): ?>
            <p class="casino-alert" role="alert"><?= htmlspecialchars($lobbyErrors['list']) ?></p>
        <?php endif; ?>

        <?php if ($lobbyRoom !== null): ?>
            <section class="casino-card casino-your-table" aria-labelledby="lobby-yours-title">
                <h2 id="lobby-yours-title">Your game</h2>
                <p class="casino-hint">
                    You're in <strong><?= htmlspecialchars($lobbyRoom['roomName']) ?></strong>. You can be in one chess game at a time.
                    <?php if ($lobbyIsPlaying): ?>Leaving it while it's being played loses it.<?php endif; ?>
                </p>
                <div class="casino-actions">
                    <a class="casino-btn casino-btn-primary" href="/games/chess/game?room=<?= $lobbyRoom['roomId'] ?>">Back to your game</a>
                    <form method="post"<?= $lobbyIsPlaying ? ' data-ch-leave-form' : '' ?>>
                        <input type="hidden" name="action" value="leave">
                        <button type="submit" class="casino-btn">Leave it</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <div class="casino-overview">
            <section class="casino-card" aria-labelledby="lobby-join-title">
                <h2 id="lobby-join-title">Join a game</h2>
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
                    <p class="casino-hint">Or pick a public game or one of your invites below.</p>
                </fieldset>
            </section>

            <section class="casino-card" aria-labelledby="lobby-create-title">
                <h2 id="lobby-create-title">Open a game</h2>
                <fieldset class="casino-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                    <form method="post" class="casino-form">
                        <input type="hidden" name="action" value="create">
                        <label class="casino-label" for="lobby-name">Name <span class="casino-optional">(optional)</span></label>
                        <input type="text" id="lobby-name" name="name" class="casino-input" maxlength="<?= HBHUB_CHESS_ROOM_NAME_MAX ?>"
                               placeholder="<?= htmlspecialchars(hbHubChessRoomName(null, $hbHubUser['userName'])) ?>"
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

                        <p class="casino-hint">You pick the clock and the colours in the game's settings, and start the game once someone has joined.</p>
                        <button type="submit" class="casino-btn casino-btn-primary">Open game</button>
                        <?php if (isset($lobbyErrors['create'])): ?>
                            <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['create']) ?></p>
                        <?php endif; ?>
                    </form>
                </fieldset>
            </section>
        </div>

        <section class="casino-card casino-list-card" id="invites" aria-labelledby="lobby-invites-title">
            <h2 id="lobby-invites-title">Your invites</h2>
            <ul class="casino-rooms" data-ch-invites></ul>
            <p class="casino-hint" data-ch-invites-empty>Loading…</p>
        </section>

        <section class="casino-card casino-list-card" aria-labelledby="lobby-rooms-title">
            <h2 id="lobby-rooms-title">Public games</h2>
            <ul class="casino-rooms" data-ch-rooms></ul>
            <p class="casino-hint" data-ch-rooms-empty>Loading…</p>
        </section>

        <section class="casino-card casino-rules" aria-labelledby="lobby-rules-title">
            <h2 id="lobby-rules-title">Rules</h2>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/rules.php'; ?>
        </section>

        <dialog class="games-dialog" aria-labelledby="lobby-leave-title" data-ch-leave-dialog>
            <h2 id="lobby-leave-title">Leave your game?</h2>
            <p>It's being played, so leaving loses it (or aborts it, if you haven't both moved yet).</p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-ch-leave-cancel>Stay</button>
                <button type="button" class="casino-btn casino-btn-primary" data-ch-leave-confirm>Leave</button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chessLobby.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chessLobby.js') ?>" defer></script>
</body>
</html>
