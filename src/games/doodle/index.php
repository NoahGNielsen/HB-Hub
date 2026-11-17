<?php
// The Doodle Chain lobby: the user's game, join a game by its code, open a game, the public games (kept up to date by
// doodleLobby.js), and the rules. A user is in one Doodle Chain game at a time.
// The forms post here (action): joinCode (code), join (room - from the list), create (name, type) and leave. Joining
// or opening a game goes straight to it, where the host picks the time to draw.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

const LOBBY_CREATE_TYPES = [ // what the "Who can join" choices make
    'public' => HBHUB_DOODLE_ROOM_PUBLIC,
    'code' => HBHUB_DOODLE_ROOM_CODE,
];

function lobbyGoToGame(int $roomId): never
{
    header('Location: /games/doodle/game?room=' . $roomId, true, 303);
    exit;
}

$lobbyErrors = [];
$lobbyValues = ['name' => '', 'type' => 'public', 'code' => ''];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : null; // only from the site itself (session.php)

if ($action === 'create') {
    $seat = hbHubDoodleUserSeat($db, $userId);
    if ($seat !== null) {
        lobbyGoToGame($seat['roomId']);
    }

    $lobbyValues['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $lobbyValues['type'] = $_POST['type'] ?? null;

    if (!is_string($lobbyValues['type']) || !isset(LOBBY_CREATE_TYPES[$lobbyValues['type']])) {
        $lobbyErrors['create'] = 'Choose who can join the game.';
    } else {
        try {
            lobbyGoToGame(hbHubDoodleCreateRoom($db, $userId, $hbHubUser['userName'],
                hbHubDoodleRoomName($lobbyValues['name'], $hbHubUser['userName']), LOBBY_CREATE_TYPES[$lobbyValues['type']]));
        } catch (PDOException $e) {
            $seat = hbHubDoodleUserSeat($db, $userId); // joined a game in another tab meanwhile
            if ($seat !== null) {
                lobbyGoToGame($seat['roomId']);
            }
            error_log('Doodle Chain: could not open a game: ' . $e->getMessage());
            $lobbyErrors['create'] = 'Something went wrong opening a game. Please try again.';
        }
    }
}

if ($action === 'join') {
    $roomId = $_POST['room'] ?? null;
    $error = is_string($roomId) && ctype_digit($roomId) && strlen($roomId) <= 10
        ? hbHubDoodleJoin($db, $userId, $hbHubUser['userName'], (int) $roomId, false)
        : 'That game isn\'t there anymore.';
    if ($error === null) {
        lobbyGoToGame((int) $roomId);
    }
    $lobbyErrors['list'] = $error;
}

if ($action === 'joinCode') {
    $lobbyValues['code'] = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 16) : '';
    $code = hbHubDoodleCleanCode($lobbyValues['code']);
    $roomId = $code !== null ? hbHubDoodleRoomIdByCode($db, $code) : null;
    if ($code === null) {
        $lobbyErrors['code'] = 'A code is 6 letters and numbers, like K7PX3M.';
    } elseif ($roomId === null) {
        $lobbyErrors['code'] = 'There\'s no game with that code. It may have ended.';
    } else {
        $error = hbHubDoodleJoin($db, $userId, $hbHubUser['userName'], $roomId, true);
        if ($error === null) {
            lobbyGoToGame($roomId);
        }
        $lobbyErrors['code'] = $error;
    }
}

if ($action === 'leave') {
    hbHubDoodleLeave($db, $userId);
    header('Location: /games/doodle/', true, 303);
    exit;
}

hbHubDoodleSweep($db); // games everyone has closed, so they don't show
$lobbySeat = hbHubDoodleUserSeat($db, $userId);
$lobbyRoom = $lobbySeat !== null ? hbHubDoodleRoom($db, $lobbySeat['roomId']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/lobby.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/lobby.css') ?>">
    <title>Doodle Chain - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Write, draw and guess with your friends in Doodle Chain on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="lobby lobby-doodle" data-doodle-lobby data-seated="<?= $lobbyRoom !== null ? '1' : '0' ?>">
        <a class="lobby-back" href="/games/">&larr; Games</a>
        <header class="lobby-hero">
            <div class="lobby-hero-icon">
                <!-- A speech bubble passing a squiggle on to a pencil -->
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <path d="M8 12h28a4 4 0 0 1 4 4v14a4 4 0 0 1-4 4H20l-8 7v-7H8a4 4 0 0 1-4-4V16a4 4 0 0 1 4-4z" fill="#fff"/>
                    <path d="M11 24c3-5 6 5 9 0s6 5 9 0" fill="none" stroke="#ff5d8f" stroke-width="3" stroke-linecap="round"/>
                    <g transform="rotate(40 46 42)">
                        <rect x="41" y="22" width="10" height="30" rx="2" fill="#ffd23f"/>
                        <path d="M41 52h10l-5 9z" fill="#f4d9b0"/>
                        <path d="M44.5 58.5h3L46 61z" fill="#1d1f24"/>
                        <rect x="41" y="18" width="10" height="5" rx="2" fill="#ff5d8f"/>
                    </g>
                </svg>
            </div>
            <div class="lobby-hero-text">
                <h1>Doodle Chain</h1>
                <p class="lobby-tagline">Write something, draw what you're given, guess what the drawing is, and watch it all go wrong.</p>
                <ul class="lobby-facts">
                    <li><?= HBHUB_DOODLE_MIN_PLAYERS ?>-<?= HBHUB_DOODLE_MAX_PLAYERS ?> players</li>
                    <li><?= implode(', ', array_slice(HBHUB_DOODLE_DRAW_CHOICES, 0, -1)) ?> or <?= HBHUB_DOODLE_DRAW_CHOICES[array_key_last(HBHUB_DOODLE_DRAW_CHOICES)] ?> seconds to draw</li>
                    <li>Beta</li>
                </ul>
            </div>
            <div class="lobby-hero-actions">
                <a class="lobby-hero-btn" href="#rules">Rules</a>
            </div>
        </header>

        <div class="lobby-notes">
            <p class="casino-alert games-notice games-beta">Doodle Chain is new and in beta, so things might break or change. If something goes wrong, tell us with the Feedback button in the side bar.</p>
            <?php if (isset($lobbyErrors['list'])): ?>
                <p class="casino-alert" role="alert"><?= htmlspecialchars($lobbyErrors['list']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($lobbyRoom !== null): ?>
            <section class="lobby-panel lobby-current" aria-labelledby="lobby-yours-title">
                <div class="lobby-current-text">
                    <span class="lobby-pulse" aria-hidden="true"></span>
                    <div>
                        <h2 id="lobby-yours-title">Your game</h2>
                        <p class="lobby-hint">
                            You're in <strong class="lobby-current-name"><?= htmlspecialchars($lobbyRoom['roomName']) ?></strong>. You can be in one Doodle Chain game at a time.
                        </p>
                    </div>
                </div>
                <div class="lobby-actions">
                    <a class="casino-btn casino-btn-primary" href="/games/doodle/game?room=<?= $lobbyRoom['roomId'] ?>">Back to your game</a>
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
                        <h2 id="lobby-join-title">Join a game</h2>
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
                        <p class="lobby-hint lobby-form-note">Or pick one of the public games.</p>
                    </fieldset>
                </section>

                <section class="lobby-panel" id="open" aria-labelledby="lobby-create-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-create-title">Open a game</h2>
                    </div>
                    <fieldset class="lobby-fieldset"<?= $lobbyRoom !== null ? ' disabled' : '' ?>>
                        <form method="post" class="lobby-form">
                            <input type="hidden" name="action" value="create">
                            <label class="casino-label" for="lobby-name">Name <span class="casino-optional">(optional)</span></label>
                            <input type="text" id="lobby-name" name="name" class="casino-input" maxlength="<?= HBHUB_DOODLE_ROOM_NAME_MAX ?>"
                                   placeholder="<?= htmlspecialchars(hbHubDoodleRoomName(null, $hbHubUser['userName'])) ?>"
                                   value="<?= htmlspecialchars($lobbyValues['name']) ?>">

                            <fieldset class="lobby-choices">
                                <legend class="casino-label">Who can join</legend>
                                <?php foreach ([
                                    'public' => ['Public', 'Listed here, so anyone can join.'],
                                    'code' => ['With the code', 'Not listed. Anyone you give the code to can join.'],
                                ] as $value => [$label, $hint]): ?>
                                    <label class="lobby-option">
                                        <input type="radio" name="type" value="<?= $value ?>"<?= $lobbyValues['type'] === $value ? ' checked' : '' ?>>
                                        <span class="lobby-option-text"><strong><?= $label ?></strong> <span class="lobby-hint"><?= $hint ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>

                            <p class="lobby-hint lobby-form-note">You pick the time to draw in the game, and start it once at least <?= HBHUB_DOODLE_MIN_PLAYERS ?> players are in.</p>
                            <button type="submit" class="casino-btn casino-btn-primary lobby-submit">Open game</button>
                            <?php if (isset($lobbyErrors['create'])): ?>
                                <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['create']) ?></p>
                            <?php endif; ?>
                        </form>
                    </fieldset>
                </section>
            </div>

            <div class="lobby-lists">
                <section class="lobby-panel lobby-list" aria-labelledby="lobby-rooms-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-rooms-title">Public games</h2>
                        <span class="lobby-count" data-doodle-rooms-count></span>
                        <span class="lobby-live" title="This list keeps itself up to date"><span class="lobby-pulse" aria-hidden="true"></span>Live</span>
                    </div>
                    <ul class="lobby-rooms" data-doodle-rooms></ul>
                    <p class="lobby-empty" data-doodle-rooms-empty>Loading…</p>
                </section>
            </div>
        </div>

        <section class="lobby-panel lobby-rules" id="rules" aria-labelledby="lobby-rules-title">
            <div class="lobby-panel-head">
                <h2 id="lobby-rules-title">Rules</h2>
            </div>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/rules.php'; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/doodleLobby.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/doodleLobby.js') ?>" defer></script>
</body>
</html>
