<?php
// A Doodle Chain game (?room=id). The page is only the frame: doodleGame.js shows the waiting room, the step being
// played (a box to write in, or the canvas to draw on) and the chains at the end, and plays the game through
// /games/doodle/play. Only for the game the user is in - anyone else is sent to the Doodle Chain page, where games are
// joined. The drawing tools' colours and sizes come from game.php's constants (data-config), so the page and the server
// agree on them.
// With ?watch=code instead it's the game in spectator mode (assets/php/games/spectators.php): the same page for someone
// watching, with data-watch on <main> (the code). Only while its host lets people watch, and the user isn't in a
// Doodle Chain game themselves (they're sent back to the Doodle Chain page, which says why); someone watching their
// own game is sent to it.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubDoodleUserSeat($db, $userId);
$isWatching = isset($_GET['watch']);
if ($isWatching) {
    $room = hbHubDoodleWatchedRoom($db, $_GET['watch']);
    if ($room !== null && $seat !== null && $seat['roomId'] === $room['roomId']) {
        header('Location: /games/doodle/game?room=' . $room['roomId'], true, 302);
        exit;
    }
    if ($room === null || $seat !== null) {
        hbHubGamesWatchRefused('/games/doodle/', $room !== null ? 'seated' : 'gone');
    }
} else {
    $room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubDoodleRoom($db, $seat['roomId']) : null;
    if ($room === null) {
        header('Location: /games/doodle/', true, 302);
        exit;
    }
}

$gameConfig = [
    'width' => HBHUB_DOODLE_WIDTH,
    'height' => HBHUB_DOODLE_HEIGHT,
    'colours' => HBHUB_DOODLE_COLOURS,
    'customColour' => HBHUB_DOODLE_CUSTOM_COLOUR,
    'sizes' => HBHUB_DOODLE_SIZES,
    'sizeFill' => HBHUB_DOODLE_SIZE_FILL,
    'textMax' => HBHUB_DOODLE_TEXT_MAX,
    'maxStrokes' => HBHUB_DOODLE_MAX_STROKES,
    'maxNumbers' => HBHUB_DOODLE_MAX_NUMBERS,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="/assets/css/doodle.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/doodle.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Doodle Chain - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="A game of Doodle Chain on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="doodle" data-doodle data-room="<?= $room['roomId'] ?>"
          data-config="<?= htmlspecialchars(json_encode($gameConfig, JSON_THROW_ON_ERROR)) ?>"<?= $isWatching ? ' data-watch="' . htmlspecialchars($room['roomCode']) . '"' : '' ?>>
        <div class="doodle-header">
            <div class="doodle-heading">
                <h1 class="doodle-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                <p class="doodle-meta"><?= HBHUB_DOODLE_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Game' ?> &middot; <span data-doodle-settings-name></span><?php
                    include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spectatorNote.php'; ?></p>
            </div>
            <div class="doodle-header-actions">
                <button type="button" class="doodle-code" data-doodle-code="<?= htmlspecialchars($room['roomCode']) ?>"
                        aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                    <span class="doodle-code-label">Code</span>
                    <strong class="doodle-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                    <span class="doodle-code-copied" data-doodle-code-copied aria-live="polite"></span>
                </button>
                <!-- Drawn in currentColor, so it follows the theme -->
                <button type="button" class="casino-btn doodle-icon-btn" aria-label="Game settings" title="Game settings"
                        aria-haspopup="dialog" data-doodle-settings>
                    <svg class="doodle-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/>
                        <path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>
                    </svg>
                </button>
                <button type="button" class="casino-btn" data-doodle-leave><?= $isWatching ? 'Stop watching' : 'Leave game' ?></button>
            </div>
        </div>

        <div class="doodle-layout">
            <section class="doodle-stage" aria-label="The game">
                <p class="doodle-message" role="alert" data-doodle-message></p>

                <!-- Waiting for players (and between games, under the chains) -->
                <div class="doodle-panel doodle-waiting" data-doodle-waiting hidden>
                    <h2 class="doodle-panel-title">Waiting for players</h2>
                    <p class="doodle-hint" data-doodle-waiting-text></p>
                    <div class="doodle-host-controls" data-doodle-host-controls hidden>
                        <button type="button" class="casino-btn casino-btn-primary" data-doodle-start>Start game</button>
                        <button type="button" class="casino-btn" aria-haspopup="dialog" data-doodle-settings>Game settings</button>
                    </div>
                </div>

                <!-- The step being played -->
                <div class="doodle-panel doodle-turn" data-doodle-turn hidden>
                    <div class="doodle-turn-head">
                        <h2 class="doodle-panel-title" data-doodle-turn-title></h2>
                        <span class="doodle-step" data-doodle-step></span>
                    </div>
                    <div class="doodle-timer" role="timer" aria-label="Time left" data-doodle-timer>
                        <span class="doodle-timer-bar" data-doodle-timer-bar></span>
                    </div>

                    <!-- What the player is handed: a text to draw, or a drawing to describe -->
                    <div class="doodle-prompt" data-doodle-prompt hidden>
                        <p class="doodle-prompt-label" data-doodle-prompt-label></p>
                        <p class="doodle-prompt-text" data-doodle-prompt-text hidden></p>
                        <canvas class="doodle-canvas is-view" width="<?= HBHUB_DOODLE_WIDTH ?>" height="<?= HBHUB_DOODLE_HEIGHT ?>"
                                data-doodle-prompt-canvas hidden></canvas>
                    </div>

                    <form class="doodle-write" data-doodle-write hidden>
                        <label class="games-visually-hidden" for="doodle-text">Your text</label>
                        <input type="text" id="doodle-text" class="casino-input" maxlength="<?= HBHUB_DOODLE_TEXT_MAX ?>"
                               autocomplete="off" data-doodle-text>
                        <button type="submit" class="casino-btn casino-btn-primary" data-doodle-write-done>Done</button>
                    </form>

                    <div class="doodle-draw" data-doodle-draw hidden>
                        <div class="doodle-pad-wrap">
                            <canvas class="doodle-canvas is-pad" width="<?= HBHUB_DOODLE_WIDTH ?>" height="<?= HBHUB_DOODLE_HEIGHT ?>"
                                    aria-label="Drawing area. Arrow keys move, hold Space to draw" data-doodle-pad></canvas>
                            <!-- The keyboard's cursor: moved with the arrow keys, Space draws (doodleGame.js) -->
                            <span class="doodle-cursor" aria-hidden="true" data-doodle-cursor hidden></span>
                        </div>
                        <div class="doodle-tools">
                            <!-- The top row: the tools (doodleGame.js's TOOL_KEYS, drawn in currentColor, so they
                                 follow the theme) and the pen sizes -->
                            <div class="doodle-tool-row">
                                <div class="doodle-tool-list" role="radiogroup" aria-label="Tool" data-doodle-tools>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="true" aria-label="Pen" title="Pen (P)" data-doodle-tool="pen">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1-5L16 4l4 4L9 19z M14 6l4 4"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Line" title="Line (L) - hold Shift for straight angles" data-doodle-tool="line">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19L19 5"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Circle" title="Circle (C) - hold Shift for a round one" data-doodle-tool="circle">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Rectangle" title="Rectangle (R) - hold Shift for a square" data-doodle-tool="rectangle">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="6" width="16" height="12"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Triangle" title="Triangle (T)" data-doodle-tool="triangle">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4L21 20H3z"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Star" title="Star (S)" data-doodle-tool="star">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.6 5.9 6.4.6-4.8 4.3 1.4 6.3L12 16.8l-5.6 3.3 1.4-6.3L3 9.5l6.4-.6z"/></svg>
                                    </button>
                                    <button type="button" class="doodle-tool" role="radio" aria-checked="false" aria-label="Fill" title="Fill (F)" data-doodle-tool="fill">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 3l8 8-7 7-8-8z M4 10h14 M20 15c0 0 2 3 2 4a2 2 0 0 1-4 0c0-1 2-4 2-4z"/></svg>
                                    </button>
                                </div>
                                <div class="doodle-sizes" role="radiogroup" aria-label="Pen size" data-doodle-sizes></div>
                            </div>
                            <div class="doodle-colours" role="radiogroup" aria-label="Colour" data-doodle-colours></div>
                            <input type="color" class="doodle-colour-input" value="#2e86de" tabindex="-1" aria-hidden="true" data-doodle-colour-input>
                            <div class="doodle-tool-actions">
                                <button type="button" class="casino-btn" data-doodle-undo>Undo</button>
                                <button type="button" class="casino-btn" data-doodle-clear>Clear</button>
                                <button type="button" class="casino-btn casino-btn-primary" data-doodle-draw-done>Done</button>
                            </div>
                            <p class="doodle-keys-hint">Keyboard: the arrow keys move, hold Space to draw. Shift keeps shapes even.</p>
                        </div>
                    </div>

                    <p class="doodle-done" role="status" data-doodle-done hidden></p>
                </div>

                <!-- The chains at the end -->
                <div class="doodle-panel doodle-reveal" data-doodle-reveal hidden>
                    <div class="doodle-turn-head">
                        <h2 class="doodle-panel-title" data-doodle-reveal-title></h2>
                        <span class="doodle-step" data-doodle-reveal-count></span>
                    </div>
                    <ol class="doodle-chain" data-doodle-chain></ol>
                    <div class="doodle-reveal-actions" data-doodle-reveal-actions></div>
                </div>
            </section>

            <aside class="doodle-side">
                <section class="doodle-panel" aria-labelledby="doodle-players-title">
                    <h2 class="doodle-panel-title" id="doodle-players-title">Players</h2>
                    <ol class="doodle-players" data-doodle-players></ol>
                </section>

                <details class="doodle-panel doodle-rules">
                    <summary class="doodle-panel-title">Rules</summary>
                    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/rules.php'; ?>
                </details>
            </aside>
        </div>

        <!-- The game's settings: the host changes them while no game is being played, everyone else (and the host
             during a game) only sees them. doodleGame.js fills in the choices from the game and sends them as "settings". -->
        <dialog class="games-dialog doodle-settings" aria-labelledby="doodle-settings-title" data-doodle-settings-dialog>
            <form method="dialog" data-doodle-settings-form>
                <h2 id="doodle-settings-title">Game settings</h2>
                <p class="doodle-hint" data-doodle-settings-text></p>
                <fieldset class="casino-choices" data-doodle-settings-fields>
                    <legend class="casino-label">Time to draw</legend>
                    <?php foreach (HBHUB_DOODLE_DRAW_CHOICES as $seconds): ?>
                        <label class="casino-choice">
                            <input type="radio" name="drawSeconds" value="<?= $seconds ?>">
                            <span><strong><?= $seconds ?> seconds<?= $seconds === HBHUB_DOODLE_DEFAULT_SETTINGS['drawSeconds'] ? ' (default)' : '' ?></strong></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spectatorSetting.php'; ?>
                <p class="doodle-message" role="alert" data-doodle-settings-message></p>
                <div class="games-dialog-actions">
                    <button type="button" class="casino-btn" data-doodle-settings-close>Close</button>
                    <button type="submit" class="casino-btn casino-btn-primary" value="save" data-doodle-settings-save>Save</button>
                </div>
            </form>
        </dialog>

        <dialog class="games-dialog" aria-labelledby="doodle-confirm-title" data-doodle-confirm>
            <h2 id="doodle-confirm-title" data-doodle-confirm-title></h2>
            <p data-doodle-confirm-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-doodle-confirm-cancel>Cancel</button>
                <button type="button" class="casino-btn casino-btn-primary" data-doodle-confirm-ok></button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="/assets/js/doodleGame.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/doodleGame.js') ?>" defer></script>
</body>
</html>
