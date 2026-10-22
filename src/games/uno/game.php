<?php
// An UNO game (?room=id). The page is only the frame: unoGame.js draws the table, the players and the user's cards, and
// plays the game through /games/uno/play. Only for the game the user is in - anyone else is sent to the UNO page, where
// games are joined.
// The cards are drawn by uno.css from the card's colour and what's on it (no pictures).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubUnoUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubUnoRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /games/uno/', true, 302);
    exit;
}

$gameTypeName = HBHUB_UNO_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Game';
$gameSettingsText = hbHubUnoSettingsText(hbHubUnoSettings($room['state']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/uno.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/uno.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/mentions.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/mentions.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - UNO - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="A game of UNO on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="uno" data-uno data-room="<?= $room['roomId'] ?>">
        <div class="uno-layout">
            <div class="uno-header">
                <div class="uno-heading">
                    <h1 class="uno-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                    <p class="uno-meta"><?= $gameTypeName ?> &middot; <span data-uno-settings-name><?= $gameSettingsText ?></span></p>
                </div>
                <div class="uno-header-actions">
                    <button type="button" class="uno-code" data-uno-code="<?= htmlspecialchars($room['roomCode']) ?>"
                            aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                        <span class="uno-code-label">Code</span>
                        <strong class="uno-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                        <span class="uno-code-copied" data-uno-code-copied aria-live="polite"></span>
                    </button>
                    <!-- Icons drawn in currentColor, so they follow the theme. unoGame.js sets the sound and pause
                         buttons' aria-pressed, which picks the icon shown, and their titles. -->
                    <button type="button" class="casino-btn uno-icon-btn" aria-label="Invite people" title="Invite people"
                            aria-haspopup="dialog" data-uno-invite hidden>
                        <svg class="uno-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn uno-icon-btn" aria-label="Game settings" title="Game settings"
                            aria-haspopup="dialog" data-uno-settings>
                        <svg class="uno-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/>
                            <path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn uno-icon-btn" aria-label="Pause the game" title="Pause the game" aria-pressed="false"
                            data-uno-pause hidden>
                        <svg class="uno-icon uno-icon-pause" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 5v14"/><path d="M15 5v14"/>
                        </svg>
                        <svg class="uno-icon uno-icon-resume" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 4.5v15l12-7.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn uno-icon-btn" aria-label="Sound" title="Sound" aria-pressed="true" data-uno-sound>
                        <svg class="uno-icon uno-icon-sound-on" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/>
                        </svg>
                        <svg class="uno-icon uno-icon-sound-off" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn uno-icon-btn" aria-label="Leave game" title="Leave game" data-uno-leave>
                        <svg class="uno-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                        </svg>
                    </button>
                </div>
            </div>

            <section class="uno-play" aria-label="The table">
                <!-- Everyone at the table, in the order of play -->
                <ol class="uno-players" data-uno-players></ol>

                <div class="uno-table" data-uno-table hidden>
                    <span class="uno-direction" aria-hidden="true" data-uno-direction>
                        <svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
                    </span>
                    <button type="button" class="uno-deck" data-uno-deck disabled>
                        <span class="uno-card is-back" aria-hidden="true"></span>
                        <span class="uno-deck-count" data-uno-deck-count></span>
                    </button>
                    <div class="uno-discard" data-uno-discard></div>
                    <p class="uno-table-text" role="status" data-uno-table-text></p>
                </div>

                <div class="uno-timer" role="timer" aria-label="Time left in the turn" data-uno-timer hidden>
                    <span class="uno-timer-bar" data-uno-timer-bar></span>
                </div>

                <div class="uno-hand-bar" data-uno-hand-bar hidden>
                    <h2 class="uno-hand-title" data-uno-hand-title>Your cards</h2>
                    <div class="uno-hand-actions">
                        <button type="button" class="casino-btn uno-keep" data-uno-keep hidden>Keep it</button>
                        <button type="button" class="uno-call" aria-pressed="false" data-uno-call hidden>UNO!</button>
                    </div>
                </div>
                <div class="uno-hand" data-uno-hand hidden></div>
            </section>

            <aside class="uno-side">
                <section class="uno-panel" aria-label="The game">
                    <p class="uno-status" role="status" data-uno-status>Loading the game…</p>
                    <p class="uno-message" role="alert" data-uno-message></p>
                    <div class="uno-actions" data-uno-actions></div>
                </section>

                <section class="uno-panel" aria-labelledby="uno-log-title">
                    <h2 class="uno-panel-title" id="uno-log-title">What happened</h2>
                    <ol class="uno-log" data-uno-log></ol>
                    <p class="uno-hint" data-uno-log-empty>Nothing yet.</p>
                </section>

                <section class="uno-panel" aria-labelledby="uno-chat-title">
                    <h2 class="uno-panel-title" id="uno-chat-title">Chat</h2>
                    <ol class="uno-chat" aria-live="polite" data-uno-chat></ol>
                    <p class="uno-hint" data-uno-chat-empty>Nothing written yet. Say hi!</p>
                    <p class="uno-message" role="alert" data-uno-chat-message></p>
                    <form class="uno-chat-form casino-inline" data-uno-chat-form>
                        <label class="games-visually-hidden" for="uno-chat-text">Message</label>
                        <input type="text" id="uno-chat-text" class="casino-input" maxlength="<?= HBHUB_UNO_CHAT_MAX ?>"
                               placeholder="Write a message" autocomplete="off" required data-uno-chat-text data-mentions="[]">
                        <button type="submit" class="casino-btn casino-btn-primary">Send</button>
                    </form>
                </section>

                <details class="uno-panel uno-rules">
                    <summary class="uno-panel-title">Rules</summary>
                    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/rules.php'; ?>
                </details>
            </aside>
        </div>

        <!-- Picking the colour for a wild card -->
        <dialog class="games-dialog uno-colours" aria-labelledby="uno-colours-title" data-uno-colours>
            <h2 id="uno-colours-title">Choose a colour</h2>
            <div class="uno-colour-choices">
                <?php foreach (HBHUB_UNO_COLOUR_NAMES as $colour => $name): ?>
                    <button type="button" class="uno-colour-choice is-<?= $colour ?>" data-uno-colour-choice="<?= $colour ?>"><?= ucfirst($name) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-uno-colours-cancel>Cancel</button>
            </div>
        </dialog>

        <!-- The game's settings: the host changes them while no game is being played, everyone else (and the host
             during a game) only sees them. unoGame.js fills in the choices from the game and sends them as "settings". -->
        <dialog class="games-dialog uno-settings" aria-labelledby="uno-settings-title" data-uno-settings-dialog>
            <form method="dialog" data-uno-settings-form>
                <h2 id="uno-settings-title">Game settings</h2>
                <p class="uno-hint" data-uno-settings-text></p>
                <fieldset class="casino-choices" data-uno-settings-fields>
                    <legend class="casino-label">Time for a turn</legend>
                    <?php foreach (HBHUB_UNO_TURN_CHOICES as $seconds): ?>
                        <label class="casino-choice">
                            <input type="radio" name="turnSeconds" value="<?= $seconds ?>">
                            <span><strong><?= $seconds ?> seconds<?= $seconds === HBHUB_UNO_DEFAULT_SETTINGS['turnSeconds'] ? ' (default)' : '' ?></strong></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="casino-choices" data-uno-settings-fields>
                    <legend class="casino-label">Stacking</legend>
                    <?php foreach ([
                        'off' => ['Off (default)', 'A Draw Two or Wild Draw Four is drawn straight away.'],
                        'on' => ['On', 'Answer a Draw Two with a Draw Two, or a Wild Draw Four with a Wild Draw Four, to pass them all on.'],
                    ] as $value => [$label, $hint]): ?>
                        <label class="casino-choice">
                            <input type="radio" name="stacking" value="<?= $value ?>">
                            <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="casino-choices" data-uno-settings-fields>
                    <legend class="casino-label">Drawing</legend>
                    <?php foreach ([
                        'off' => ['Draw one card (default)', 'Then play it if you can, or keep it.'],
                        'on' => ['Draw until you can play', 'Keep drawing until you get a card that can be played.'],
                    ] as $value => [$label, $hint]): ?>
                        <label class="casino-choice">
                            <input type="radio" name="drawUntil" value="<?= $value ?>">
                            <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="uno-message" role="alert" data-uno-settings-message></p>
                <div class="games-dialog-actions">
                    <button type="button" class="casino-btn" data-uno-settings-close>Close</button>
                    <button type="submit" class="casino-btn casino-btn-primary" value="save" data-uno-settings-save>Save</button>
                </div>
            </form>
        </dialog>

        <dialog class="games-dialog uno-invite" aria-labelledby="uno-invite-title" data-uno-invite-dialog>
            <h2 id="uno-invite-title">Invite someone</h2>
            <p data-uno-invite-text></p>
            <label class="casino-label" for="uno-invite-search">Find people</label>
            <input type="search" id="uno-invite-search" class="casino-input" placeholder="Search by name" autocomplete="off" data-uno-invite-search>
            <ul class="uno-invite-list" data-uno-invite-list></ul>
            <p class="uno-hint" role="status" data-uno-invite-status></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-uno-invite-close>Done</button>
            </div>
        </dialog>

        <dialog class="games-dialog" aria-labelledby="uno-confirm-title" data-uno-confirm>
            <h2 id="uno-confirm-title" data-uno-confirm-title></h2>
            <p data-uno-confirm-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-uno-confirm-cancel>Cancel</button>
                <button type="button" class="casino-btn casino-btn-primary" data-uno-confirm-ok></button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/unoGame.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/unoGame.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/mentions.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/mentions.js') ?>" defer></script>
</body>
</html>
