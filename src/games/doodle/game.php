<?php
// A Doodle Chain game (?room=id). The page is only the frame: doodleGame.js shows the waiting room, the step being
// played (a box to write in, or the canvas to draw on) and the chains at the end, and plays the game through
// /games/doodle/play. Only for the game the user is in - anyone else is sent to the Doodle Chain page, where games are
// joined. The drawing tools' colours and sizes come from game.php's constants (data-config), so the page and the server
// agree on them.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubDoodleUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubDoodleRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /games/doodle/', true, 302);
    exit;
}

$gameConfig = [
    'width' => HBHUB_DOODLE_WIDTH,
    'height' => HBHUB_DOODLE_HEIGHT,
    'colours' => HBHUB_DOODLE_COLOURS,
    'sizes' => HBHUB_DOODLE_SIZES,
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
          data-config="<?= htmlspecialchars(json_encode($gameConfig, JSON_THROW_ON_ERROR)) ?>">
        <div class="doodle-header">
            <div class="doodle-heading">
                <h1 class="doodle-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                <p class="doodle-meta"><?= HBHUB_DOODLE_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Game' ?> &middot; <span data-doodle-settings-name></span></p>
            </div>
            <div class="doodle-header-actions">
                <button type="button" class="doodle-code" data-doodle-code="<?= htmlspecialchars($room['roomCode']) ?>"
                        aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                    <span class="doodle-code-label">Code</span>
                    <strong class="doodle-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                    <span class="doodle-code-copied" data-doodle-code-copied aria-live="polite"></span>
                </button>
                <button type="button" class="casino-btn" data-doodle-leave>Leave game</button>
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
                        <label class="casino-label" for="doodle-draw-seconds">Time to draw</label>
                        <select id="doodle-draw-seconds" class="casino-input doodle-select" data-doodle-draw-seconds>
                            <?php foreach (HBHUB_DOODLE_DRAW_CHOICES as $seconds): ?>
                                <option value="<?= $seconds ?>"><?= $seconds ?> seconds<?= $seconds === HBHUB_DOODLE_DEFAULT_SETTINGS['drawSeconds'] ? ' (default)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="casino-btn casino-btn-primary" data-doodle-start>Start game</button>
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
                        <canvas class="doodle-canvas is-pad" width="<?= HBHUB_DOODLE_WIDTH ?>" height="<?= HBHUB_DOODLE_HEIGHT ?>"
                                aria-label="Drawing area" data-doodle-pad></canvas>
                        <div class="doodle-tools">
                            <div class="doodle-colours" role="radiogroup" aria-label="Colour" data-doodle-colours></div>
                            <div class="doodle-sizes" role="radiogroup" aria-label="Pen size" data-doodle-sizes></div>
                            <div class="doodle-tool-actions">
                                <button type="button" class="casino-btn" data-doodle-undo>Undo</button>
                                <button type="button" class="casino-btn" data-doodle-clear>Clear</button>
                                <button type="button" class="casino-btn casino-btn-primary" data-doodle-draw-done>Done</button>
                            </div>
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
