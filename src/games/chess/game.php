<?php
// A chess game (?room=id). The page is only the frame: chessGame.js draws the board, the players and their clocks, and
// plays the game through /games/chess/play. Only for the game the user is in - anyone else is sent to the chess page,
// where games are joined.
// The pieces are letters from the Noto Sans Symbols 2 font (assets/fonts/, only those 12), drawn by chess.css.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubChessUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubChessRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /games/chess/', true, 302);
    exit;
}

$gameTypeName = HBHUB_CHESS_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Game';
$gameClockName = HBHUB_CHESS_CLOCK_NAMES[$room['state']['clock']] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chess.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chess.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/mentions.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/mentions.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Chess - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="A game of chess on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="ch" data-chess data-room="<?= $room['roomId'] ?>">
        <div class="ch-layout">
            <div class="ch-header">
                <div class="ch-heading">
                    <h1 class="ch-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                    <p class="ch-meta"><?= $gameTypeName ?> &middot; <span data-ch-clock-name><?= $gameClockName ?></span></p>
                </div>
                <div class="ch-header-actions">
                    <button type="button" class="ch-code" data-ch-code="<?= htmlspecialchars($room['roomCode']) ?>"
                            aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                        <span class="ch-code-label">Code</span>
                        <strong class="ch-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                        <span class="ch-code-copied" data-ch-code-copied aria-live="polite"></span>
                    </button>
                    <!-- Icons drawn in currentColor, so they follow the theme. chessGame.js sets the sound and pause
                         buttons' aria-pressed, which picks the icon shown, and their titles. -->
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Invite people" title="Invite people"
                            aria-haspopup="dialog" data-ch-invite hidden>
                        <svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Game settings" title="Game settings"
                            aria-haspopup="dialog" data-ch-settings>
                        <svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/>
                            <path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Pause the game" title="Pause the game" aria-pressed="false"
                            data-ch-pause hidden>
                        <svg class="ch-icon ch-icon-pause" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 5v14"/><path d="M15 5v14"/>
                        </svg>
                        <svg class="ch-icon ch-icon-resume" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 4.5v15l12-7.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Turn the board around" title="Turn the board around" data-ch-flip>
                        <svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 4v16"/><path d="m3 8 4-4 4 4"/><path d="M17 20V4"/><path d="m21 16-4 4-4-4"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Sound" title="Sound" aria-pressed="true" data-ch-sound>
                        <svg class="ch-icon ch-icon-sound-on" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/>
                        </svg>
                        <svg class="ch-icon ch-icon-sound-off" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn ch-icon-btn" aria-label="Leave game" title="Leave game" data-ch-leave>
                        <svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                        </svg>
                    </button>
                </div>
            </div>

            <section class="ch-play" aria-label="The board">
                <?php foreach (['top', 'bottom'] as $place): ?>
                    <div class="ch-bar ch-bar-<?= $place ?>" data-ch-bar="<?= $place ?>">
                        <span class="ch-avatar" aria-hidden="true" data-ch-avatar></span>
                        <div class="ch-bar-text">
                            <span class="ch-bar-name" data-ch-name></span>
                            <span class="ch-bar-line">
                                <span class="ch-bar-status" data-ch-state></span>
                                <span class="ch-captured" data-ch-captured></span>
                            </span>
                        </div>
                        <span class="ch-clock" role="timer" data-ch-clock hidden></span>
                    </div>
                <?php endforeach; ?>
                <!-- The frame keeps the board's size; zoomed in, the board grows inside it and it scrolls (boardZoom.js) -->
                <div class="ch-board-view" data-board-zoom="hbHubChessZoom" data-ch-board-view>
                    <div class="ch-board" data-ch-board></div>
                </div>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/boardZoom.php'; ?>
            </section>

            <aside class="ch-side">
                <section class="ch-panel" aria-label="The game">
                    <p class="ch-status" role="status" data-ch-status>Loading the game…</p>
                    <p class="ch-message" role="alert" data-ch-message></p>
                    <div class="ch-actions" data-ch-actions></div>
                </section>

                <section class="ch-panel" aria-labelledby="ch-moves-title">
                    <h2 class="ch-panel-title" id="ch-moves-title">Moves</h2>
                    <ol class="ch-moves" data-ch-moves></ol>
                    <p class="ch-hint" data-ch-moves-empty>No moves yet.</p>
                </section>

                <section class="ch-panel" aria-labelledby="ch-chat-title">
                    <h2 class="ch-panel-title" id="ch-chat-title">Chat</h2>
                    <ol class="ch-chat" aria-live="polite" data-ch-chat></ol>
                    <p class="ch-hint" data-ch-chat-empty>Nothing written yet. Say hi!</p>
                    <p class="ch-message" role="alert" data-ch-chat-message></p>
                    <form class="ch-chat-form casino-inline" data-ch-chat-form>
                        <label class="games-visually-hidden" for="ch-chat-text">Message</label>
                        <input type="text" id="ch-chat-text" class="casino-input" maxlength="<?= HBHUB_CHESS_CHAT_MAX ?>"
                               placeholder="Write a message" autocomplete="off" required data-ch-chat-text data-mentions="[]">
                        <button type="submit" class="casino-btn casino-btn-primary">Send</button>
                    </form>
                </section>

                <details class="ch-panel ch-rules">
                    <summary class="ch-panel-title">Rules</summary>
                    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/rules.php'; ?>
                </details>
            </aside>
        </div>

        <dialog class="games-dialog ch-promotion" aria-labelledby="ch-promotion-title" data-ch-promotion>
            <h2 id="ch-promotion-title">Promote your pawn to</h2>
            <div class="ch-promotion-choices" data-ch-promotion-choices></div>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-ch-promotion-cancel>Cancel</button>
            </div>
        </dialog>

        <!-- The game's settings: the host changes them while no game is being played (waiting for a player, or between
             games), everyone else (and the host during a game) only sees them. chessGame.js fills in the choices from
             the game and sends them as "settings". -->
        <dialog class="games-dialog ch-settings" aria-labelledby="ch-settings-title" data-ch-settings-dialog>
            <form method="dialog" data-ch-settings-form>
                <h2 id="ch-settings-title">Game settings</h2>
                <p class="ch-hint" data-ch-settings-text></p>
                <fieldset class="casino-choices" data-ch-settings-fields>
                    <legend class="casino-label">Clock</legend>
                    <?php foreach (HBHUB_CHESS_CLOCK_NAMES as $value => $label): ?>
                        <label class="casino-choice">
                            <input type="radio" name="clock" value="<?= $value ?>">
                            <span><strong><?= $label ?><?= $value === HBHUB_CHESS_DEFAULT_CLOCK ? ' (default)' : '' ?></strong></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="casino-choices" data-ch-settings-fields>
                    <legend class="casino-label">The host plays</legend>
                    <?php foreach ([
                        'random' => ['Random (default)', 'Who plays White is picked when the game starts.'],
                        'white' => ['White', 'The host moves first.'],
                        'black' => ['Black', 'The one who joins moves first.'],
                    ] as $value => [$label, $hint]): ?>
                        <label class="casino-choice">
                            <input type="radio" name="colour" value="<?= $value ?>">
                            <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="ch-message" role="alert" data-ch-settings-message></p>
                <div class="games-dialog-actions">
                    <button type="button" class="casino-btn" data-ch-settings-close>Close</button>
                    <button type="submit" class="casino-btn casino-btn-primary" value="save" data-ch-settings-save>Save</button>
                </div>
            </form>
        </dialog>

        <dialog class="games-dialog ch-invite" aria-labelledby="ch-invite-title" data-ch-invite-dialog>
            <h2 id="ch-invite-title">Invite someone</h2>
            <p data-ch-invite-text></p>
            <label class="casino-label" for="ch-invite-search">Find people</label>
            <input type="search" id="ch-invite-search" class="casino-input" placeholder="Search by name" autocomplete="off" data-ch-invite-search>
            <ul class="ch-invite-list" data-ch-invite-list></ul>
            <p class="ch-hint" role="status" data-ch-invite-status></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-ch-invite-close>Done</button>
            </div>
        </dialog>

        <dialog class="games-dialog" aria-labelledby="ch-confirm-title" data-ch-confirm>
            <h2 id="ch-confirm-title" data-ch-confirm-title></h2>
            <p data-ch-confirm-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-ch-confirm-cancel>Cancel</button>
                <button type="button" class="casino-btn casino-btn-primary" data-ch-confirm-ok></button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/chessGame.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chessGame.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/boardZoom.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/boardZoom.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/mentions.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/mentions.js') ?>" defer></script>
</body>
</html>
