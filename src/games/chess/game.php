<?php
// A chess game (?room=id). The page is only the frame: chessGame.js draws the board, the players and their clocks, and
// plays the game through /games/chess/play. Only for the game the user is in - anyone else is sent to the chess page,
// where games are joined.
// The pieces are letters from the Noto Sans Symbols 2 font (only those 12 are loaded), drawn by chess.css.
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
$pieceFontUrl = 'https://fonts.googleapis.com/css2?family=Noto+Sans+Symbols+2&display=block&text=' . rawurlencode('♔♕♖♗♘♙♚♛♜♝♞♟');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link href="<?= htmlspecialchars($pieceFontUrl) ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/chess.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/chess.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Chess - HB Hub</title>
    <meta name="description" content="A game of chess on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="ch" data-chess data-room="<?= $room['roomId'] ?>">
        <div class="ch-header">
            <div class="ch-heading">
                <h1 class="ch-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                <p class="ch-meta"><?= $gameTypeName ?> &middot; <?= $gameClockName ?></p>
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

        <div class="ch-layout">
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
                <div class="ch-board" data-ch-board></div>
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
                               placeholder="Write a message" autocomplete="off" required data-ch-chat-text>
                        <button type="submit" class="casino-btn casino-btn-primary">Send</button>
                    </form>
                </section>
            </aside>
        </div>

        <details class="ch-rules casino-card">
            <summary>Rules</summary>
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/rules.php'; ?>
        </details>

        <dialog class="games-dialog ch-promotion" aria-labelledby="ch-promotion-title" data-ch-promotion>
            <h2 id="ch-promotion-title">Promote your pawn to</h2>
            <div class="ch-promotion-choices" data-ch-promotion-choices></div>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-ch-promotion-cancel>Cancel</button>
            </div>
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
</body>
</html>
