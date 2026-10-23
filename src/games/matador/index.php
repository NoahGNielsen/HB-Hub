<?php
// The Matador lobby: the user's game, their saved game (continue or delete it), join a game by its code, open a game,
// the user's invites and the public games (both kept up to date by matadorLobby.js), and the rules. A user is in one
// game at a time.
// The forms post here (action): joinCode (code), join (room - from the lists), create (name, type), continue,
// deleteSave and leave. Joining, opening or continuing a game goes straight to it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

const LOBBY_CREATE_TYPES = [ // what the "Who can join" choices make
    'public' => HBHUB_MATADOR_ROOM_PUBLIC,
    'code' => HBHUB_MATADOR_ROOM_CODE,
    'invite' => HBHUB_MATADOR_ROOM_INVITE,
];

function lobbyGoToGame(int $roomId): never
{
    header('Location: /games/matador/game?room=' . $roomId, true, 303);
    exit;
}

/**
 * "just now", "5 minutes ago", "3 hours ago" or "2 days ago".
 */
function lobbyAgo(int $minutes): string
{
    [$amount, $unit] = match (true) {
        $minutes < 1 => [0, ''],
        $minutes < 60 => [$minutes, 'minute'],
        $minutes < 60 * 24 => [intdiv($minutes, 60), 'hour'],
        default => [intdiv($minutes, 60 * 24), 'day'],
    };
    return $amount === 0 ? 'just now' : $amount . ' ' . $unit . ($amount === 1 ? '' : 's') . ' ago';
}

$lobbyErrors = [];
$lobbyValues = ['name' => '', 'type' => 'public', 'code' => ''];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : null; // only from the site itself (session.php)

if (in_array($action, ['create', 'continue'], true)) {
    $seat = hbHubMatadorUserSeat($db, $userId);
    if ($seat !== null) {
        lobbyGoToGame($seat['roomId']);
    }
}

if ($action === 'create') {
    $lobbyValues['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $lobbyValues['type'] = $_POST['type'] ?? null;

    if (!is_string($lobbyValues['type']) || !isset(LOBBY_CREATE_TYPES[$lobbyValues['type']])) {
        $lobbyErrors['create'] = 'Choose who can join the game.';
    } else {
        try {
            lobbyGoToGame(hbHubMatadorCreateRoom($db, $userId, $hbHubUser['userName'],
                hbHubMatadorRoomName($lobbyValues['name'], $hbHubUser['userName']), LOBBY_CREATE_TYPES[$lobbyValues['type']]));
        } catch (PDOException $e) {
            $seat = hbHubMatadorUserSeat($db, $userId); // joined a game in another tab meanwhile
            if ($seat !== null) {
                lobbyGoToGame($seat['roomId']);
            }
            error_log('Matador: could not open a game: ' . $e->getMessage());
            $lobbyErrors['create'] = 'Something went wrong opening a game. Please try again.';
        }
    }
}

if ($action === 'continue') {
    try {
        $result = hbHubMatadorContinue($db, $userId, $hbHubUser['userName']);
    } catch (PDOException $e) {
        error_log('Matador: could not continue a saved game: ' . $e->getMessage());
        $result = 'Something went wrong opening your saved game. Please try again.';
    }
    if (is_int($result)) {
        lobbyGoToGame($result);
    }
    $lobbyErrors['save'] = $result;
}

if ($action === 'deleteSave') {
    $lobbyErrors['save'] = hbHubMatadorDeleteSave($db, $userId);
    if ($lobbyErrors['save'] === null) {
        header('Location: /games/matador/', true, 303);
        exit;
    }
}

if ($action === 'join') {
    $roomId = $_POST['room'] ?? null;
    $error = is_string($roomId) && ctype_digit($roomId) && strlen($roomId) <= 10
        ? hbHubMatadorJoin($db, $userId, $hbHubUser['userName'], (int) $roomId, false)
        : 'That game isn\'t there anymore.';
    if ($error === null) {
        lobbyGoToGame((int) $roomId);
    }
    $lobbyErrors['list'] = $error;
}

if ($action === 'joinCode') {
    $lobbyValues['code'] = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 16) : '';
    $code = hbHubMatadorCleanCode($lobbyValues['code']);
    $roomId = $code !== null ? hbHubMatadorRoomIdByCode($db, $code) : null;
    if ($code === null) {
        $lobbyErrors['code'] = 'A code is 6 letters and numbers, like K7PX3M.';
    } elseif ($roomId === null) {
        $lobbyErrors['code'] = 'There\'s no game with that code. It may have ended.';
    } else {
        $error = hbHubMatadorJoin($db, $userId, $hbHubUser['userName'], $roomId, true);
        if ($error === null) {
            lobbyGoToGame($roomId);
        }
        $lobbyErrors['code'] = $error;
    }
}

if ($action === 'leave') {
    hbHubMatadorLeave($db, $userId);
    header('Location: /games/matador/', true, 303);
    exit;
}

hbHubMatadorSweep($db); // games everyone has closed, so they don't show (and are saved)
$lobbySeat = hbHubMatadorUserSeat($db, $userId);
$lobbyRoom = $lobbySeat !== null ? hbHubMatadorRoom($db, $lobbySeat['roomId']) : null;
$lobbySave = hbHubMatadorSaveOf($db, $userId);
$lobbyStopped = ($_GET['stopped'] ?? null) === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/lobby.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/lobby.css') ?>">
    <title>Matador - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Matador, the Danish board game, on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="lobby lobby-matador" data-mt-lobby data-seated="<?= $lobbyRoom !== null ? '1' : '0' ?>">
        <a class="lobby-back" href="/games/">&larr; Games</a>
        <header class="lobby-hero">
            <div class="lobby-hero-icon">
                <!-- A house and a hotel -->
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <path d="M6 33 20 21l14 12v20H6z" fill="#fff"/>
                    <rect x="16" y="41" width="8" height="12" rx="1" fill="#c8102e"/>
                    <path d="M32 27h26v26H32z" fill="#fff" opacity="0.6"/>
                    <path d="M30 27 45 13l15 14z" fill="#fff" opacity="0.6"/>
                    <path d="M37 33h5v5h-5zM48 33h5v5h-5zM37 43h5v5h-5zM48 43h5v5h-5z" fill="#c8102e"/>
                </svg>
            </div>
            <div class="lobby-hero-text">
                <h1>Matador</h1>
                <p class="lobby-tagline">Buy streets in Copenhagen, build houses and hotels, and make the others go bankrupt.</p>
                <ul class="lobby-facts">
                    <li><?= HBHUB_MATADOR_MIN_PLAYERS ?>-<?= HBHUB_MATADOR_MAX_PLAYERS ?> players</li>
                    <li><?= HBHUB_MATADOR_TURN_SECONDS ?> seconds a turn</li>
                    <li>Saved when you stop</li>
                </ul>
            </div>
            <div class="lobby-hero-actions">
                <a class="lobby-hero-btn" href="/games/matador/leaderboard">Leaderboard</a>
                <a class="lobby-hero-btn" href="#rules">Rules</a>
            </div>
        </header>

        <div class="lobby-notes">
            <?php if ($lobbyStopped): ?>
                <p class="casino-alert games-notice" role="status">The game was saved and stopped. Whoever opened it can continue it from their saved game.</p>
            <?php endif; ?>
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
                        <p class="lobby-hint">You're in <strong class="lobby-current-name"><?= htmlspecialchars($lobbyRoom['roomName']) ?></strong>. You can be in one game at a time.</p>
                    </div>
                </div>
                <div class="lobby-actions">
                    <a class="casino-btn casino-btn-primary" href="/games/matador/game?room=<?= $lobbyRoom['roomId'] ?>">Back to your game</a>
                    <form method="post">
                        <input type="hidden" name="action" value="leave">
                        <button type="submit" class="casino-btn">Leave it</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($lobbySave !== null || isset($lobbyErrors['save'])): ?>
            <section class="lobby-panel lobby-current lobby-current-saved" aria-labelledby="lobby-save-title">
                <div class="lobby-current-text">
                    <span class="lobby-pulse" aria-hidden="true"></span>
                    <div>
                        <h2 id="lobby-save-title">Your saved game<?php if ($lobbySave !== null): ?>: <span class="lobby-current-name"><?= htmlspecialchars($lobbySave['name']) ?></span><?php endif; ?></h2>
                        <?php if ($lobbySave !== null): ?>
                            <p class="lobby-hint">
                                <?= implode(', ', array_map(static fn (array $player): string => htmlspecialchars($player['name']) . ($player['bankrupt'] ? ' (bankrupt)' : ''), $lobbySave['players'])) ?>
                                &middot; Round <?= $lobbySave['round'] ?> &middot; Saved <?= lobbyAgo($lobbySave['minutesAgo']) ?>
                            </p>
                            <?php if ($lobbySave['isPlaying']): ?>
                                <p class="lobby-hint">It's being played right now, so it can't be continued or deleted until everyone has left it.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($lobbySave !== null): ?>
                    <div class="lobby-actions">
                        <form method="post">
                            <input type="hidden" name="action" value="continue">
                            <button type="submit" class="casino-btn casino-btn-primary"<?= $lobbySave['isPlaying'] || $lobbyRoom !== null ? ' disabled' : '' ?>>Continue it</button>
                        </form>
                        <form method="post" data-mt-delete-save>
                            <input type="hidden" name="action" value="deleteSave">
                            <button type="submit" class="casino-btn"<?= $lobbySave['isPlaying'] ? ' disabled' : '' ?>>Delete it</button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if (isset($lobbyErrors['save'])): ?>
                    <p class="casino-error" role="alert"><?= htmlspecialchars($lobbyErrors['save']) ?></p>
                <?php endif; ?>
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
                        <p class="lobby-hint lobby-form-note">Or pick a public game or one of your invites.</p>
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
                            <input type="text" id="lobby-name" name="name" class="casino-input" maxlength="<?= HBHUB_MATADOR_ROOM_NAME_MAX ?>"
                                   placeholder="<?= htmlspecialchars(hbHubMatadorRoomName(null, $hbHubUser['userName'])) ?>"
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

                            <?php if ($lobbySave !== null): ?>
                                <p class="casino-hint games-warning">
                                    You have a saved game. A new game is saved in its place once it starts, so
                                    <strong><?= htmlspecialchars($lobbySave['name']) ?></strong> will be gone.
                                </p>
                            <?php endif; ?>

                            <button type="submit" class="casino-btn casino-btn-primary lobby-submit">Open game</button>
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
                        <span class="lobby-count" data-mt-invites-count></span>
                    </div>
                    <ul class="lobby-rooms" data-mt-invites></ul>
                    <p class="lobby-empty" data-mt-invites-empty>Loading…</p>
                </section>

                <section class="lobby-panel lobby-list" aria-labelledby="lobby-rooms-title">
                    <div class="lobby-panel-head">
                        <h2 id="lobby-rooms-title">Public games</h2>
                        <span class="lobby-count" data-mt-rooms-count></span>
                        <span class="lobby-live" title="This list keeps itself up to date"><span class="lobby-pulse" aria-hidden="true"></span>Live</span>
                    </div>
                    <ul class="lobby-rooms" data-mt-rooms></ul>
                    <p class="lobby-empty" data-mt-rooms-empty>Loading…</p>
                </section>
            </div>
        </div>

        <section class="lobby-panel lobby-rules" id="rules" aria-labelledby="lobby-rules-title">
            <div class="lobby-panel-head">
                <h2 id="lobby-rules-title">Rules</h2>
            </div>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/rules.php'; ?>
        </section>

        <dialog class="games-dialog" aria-labelledby="lobby-delete-title" data-mt-delete-dialog>
            <h2 id="lobby-delete-title">Delete your saved game?</h2>
            <p>It can't be continued after this.</p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-mt-delete-cancel>Keep it</button>
                <button type="button" class="casino-btn casino-btn-primary" data-mt-delete-confirm>Delete</button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/matadorLobby.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/matadorLobby.js') ?>" defer></script>
</body>
</html>
