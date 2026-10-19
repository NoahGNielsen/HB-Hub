<?php
// A Matador game (?room=id). The board's fields are drawn here, with what they cost and take in rent (data-* for the
// property cards); matadorGame.js puts the pieces, owners and houses on them and plays the game through
// /games/matador/play. Only for the game the user is in - anyone else is sent to the Matador page, where games are joined.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$seat = hbHubMatadorUserSeat($db, $userId);
$room = $seat !== null && ($_GET['room'] ?? null) === (string) $seat['roomId'] ? hbHubMatadorRoom($db, $seat['roomId']) : null;
if ($room === null) {
    header('Location: /games/matador/', true, 302);
    exit;
}

/**
 * Where the field goes on the 11 x 11 board, START in the bottom right corner and on clockwise:
 * [row, column, the side it's on].
 *
 * @return array{0: int, 1: int, 2: string}
 */
function gameFieldPlace(int $field): array
{
    return match (true) {
        $field <= 10 => [11, 11 - $field, 'bottom'],
        $field < 20 => [11 - ($field - 10), 1, 'left'],
        $field <= 30 => [1, 1 + ($field - 20), 'top'],
        default => [1 + ($field - 30), 11, 'right'],
    };
}

$gameTypeName = HBHUB_MATADOR_ROOM_TYPE_NAMES[$room['roomType']] ?? 'Game';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/matador.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/matador.css') ?>">
    <title><?= htmlspecialchars($room['roomName']) ?> - Matador - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="A game of Matador on the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="mt" data-matador data-room="<?= $room['roomId'] ?>" data-turn-seconds="<?= HBHUB_MATADOR_TURN_SECONDS ?>">
        <!-- The board on the left; the game's name and buttons, then the panels and the rules, on the right -->
        <div class="mt-layout">
            <div class="mt-header">
                <div class="mt-heading">
                    <h1 class="mt-title"><?= htmlspecialchars($room['roomName']) ?></h1>
                    <p class="mt-meta">
                        <?= $gameTypeName ?> &middot; <span data-mt-player-count>1</span>/<?= HBHUB_MATADOR_MAX_PLAYERS ?> players
                        <span data-mt-round></span>
                        <span data-mt-saved></span>
                    </p>
                </div>
                <div class="mt-header-actions">
                    <button type="button" class="mt-code" data-mt-code="<?= htmlspecialchars($room['roomCode']) ?>"
                            aria-label="Join code <?= htmlspecialchars(implode(' ', str_split($room['roomCode']))) ?>, copy it">
                        <span class="mt-code-label">Code</span>
                        <strong class="mt-code-value"><?= htmlspecialchars($room['roomCode']) ?></strong>
                        <span class="mt-code-copied" data-mt-code-copied aria-live="polite"></span>
                    </button>
                    <!-- Icons drawn in currentColor, so they follow the theme. matadorGame.js sets the sound button's
                         aria-pressed, which picks the icon shown, and its title. -->
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Invite people" title="Invite people"
                            aria-haspopup="dialog" data-mt-invite hidden>
                        <svg class="mt-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Room settings" title="Room settings"
                            aria-haspopup="dialog" data-mt-settings>
                        <svg class="mt-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/>
                            <path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Sound" title="Sound" aria-pressed="true" data-mt-sound>
                        <svg class="mt-icon mt-icon-sound-on" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/>
                        </svg>
                        <svg class="mt-icon mt-icon-sound-off" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/>
                        </svg>
                    </button>
                    <!-- matadorGame.js sets aria-pressed (which picks the icon shown) and the title -->
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Pause the game" title="Pause the game" aria-pressed="false"
                            data-mt-pause hidden>
                        <svg class="mt-icon mt-icon-pause" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 5v14"/><path d="M15 5v14"/>
                        </svg>
                        <svg class="mt-icon mt-icon-resume" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 4.5v15l12-7.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Save &amp; stop" title="Save &amp; stop" data-mt-save-stop hidden>
                        <svg class="mt-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>
                        </svg>
                    </button>
                    <button type="button" class="casino-btn mt-icon-btn" aria-label="Leave game" title="Leave game" data-mt-leave>
                        <svg class="mt-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- The wrapper is the board's size container, so the board's text and pieces scale with it (cqi in matador.css).
                 It's also the frame the board is zoomed in (boardZoom.js): it keeps its size and scrolls. -->
            <div class="mt-board-area">
                <div class="mt-board-wrap" data-board-zoom="hbHubMatadorZoom">
                    <section class="mt-board" aria-label="The board" data-mt-board>
                        <?php foreach (HBHUB_MATADOR_FIELDS as $field => $info):
                            [$row, $column, $side] = gameFieldPlace($field);
                            $isCorner = in_array($field, [0, 10, 20, 30], true);
                            $classes = 'mt-field mt-r-' . $row . ' mt-c-' . $column . ' mt-side-' . $side . ' mt-type-' . $info['type']
                                . (isset($info['group']) ? ' mt-group-' . $info['group'] : '') . ($isCorner ? ' is-corner' : '');
                            $isProperty = isset($info['price']); ?>
                            <button type="button" class="<?= $classes ?>" data-field="<?= $field ?>"
                                    data-name="<?= htmlspecialchars($info['name']) ?>" data-type="<?= $info['type'] ?>"
                                    <?php if ($isProperty): ?>
                                        data-group="<?= $info['group'] ?>" data-price="<?= $info['price'] ?>"
                                        <?php if ($info['type'] === 'street'): ?>data-house="<?= $info['house'] ?>" data-rent="<?= implode(',', $info['rent']) ?>"<?php endif; ?>
                                    <?php endif; ?>
                                    aria-label="<?= htmlspecialchars($info['name']) ?>">
                                <?php if (isset($info['group']) && is_int($info['group'])): ?>
                                    <span class="mt-field-colour" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="mt-field-name"><?= htmlspecialchars($info['name']) ?></span>
                                <?php if ($isProperty): ?>
                                    <span class="mt-field-price"><?= number_format($info['price']) ?> kr</span>
                                <?php elseif ($info['type'] === 'incomeTax'): ?>
                                    <span class="mt-field-price">10 % or <?= number_format(HBHUB_MATADOR_TAX_FIXED) ?> kr</span>
                                <?php elseif ($info['type'] === 'extraTax'): ?>
                                    <span class="mt-field-price"><?= number_format($info['amount']) ?> kr</span>
                                <?php endif; ?>
                                <span class="mt-field-houses" data-mt-houses></span>
                                <span class="mt-field-tokens" data-mt-tokens></span>
                            </button>
                        <?php endforeach; ?>
    
                        <div class="mt-center">
                            <div class="mt-brand" aria-hidden="true">
                                <p class="mt-logo">MATADOR</p>
                                <p class="mt-edition">HTX Edition</p>
                            </div>
                            <div class="mt-die" role="img" aria-label="The dice" data-mt-die>⚀</div>
                            <p class="mt-status" role="status" data-mt-status>Loading the game…</p>
                            <div class="mt-timer" data-mt-timer hidden>
                                <span class="mt-timer-bar" aria-hidden="true"><span class="mt-timer-fill" data-mt-timer-fill></span></span>
                                <span class="mt-timer-text" data-mt-timer-text></span>
                            </div>
                            <div class="mt-card" data-mt-card hidden>
                                <p class="mt-card-title">Prøv lykken</p>
                                <p class="mt-card-text" data-mt-card-text></p>
                                <p class="mt-card-who" data-mt-card-who></p>
                            </div>
                        </div>
                    </section>
                </div>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/boardZoom.php'; ?>
            </div>

            <aside class="mt-side">
                <section class="mt-panel" aria-label="Your moves">
                    <p class="mt-message" role="alert" data-mt-message></p>
                    <div class="mt-actions" data-mt-actions></div>
                </section>

                <section class="mt-panel" aria-labelledby="mt-players-title">
                    <h2 class="mt-panel-title" id="mt-players-title">Players</h2>
                    <ol class="mt-players" data-mt-players></ol>
                    <p class="mt-hint" data-mt-bank></p>
                </section>

                <section class="mt-panel" aria-labelledby="mt-trades-title" data-mt-trades-panel hidden>
                    <div class="mt-panel-row">
                        <h2 class="mt-panel-title" id="mt-trades-title">Trades</h2>
                        <button type="button" class="casino-btn mt-small-btn" data-mt-trade-open>Offer a trade</button>
                    </div>
                    <ul class="mt-trades" data-mt-trades></ul>
                    <p class="mt-hint" data-mt-trades-empty>No offers right now.</p>
                </section>

                <section class="mt-panel" aria-labelledby="mt-chat-title">
                    <h2 class="mt-panel-title" id="mt-chat-title">Chat</h2>
                    <ol class="mt-chat" aria-live="polite" data-mt-chat></ol>
                    <p class="mt-hint" data-mt-chat-empty>Nothing written yet. Say hi!</p>
                    <p class="mt-message" role="alert" data-mt-chat-message></p>
                    <form class="mt-chat-form casino-inline" data-mt-chat-form>
                        <label class="games-visually-hidden" for="mt-chat-text">Message</label>
                        <input type="text" id="mt-chat-text" class="casino-input" maxlength="<?= HBHUB_MATADOR_CHAT_MAX ?>"
                               placeholder="Write a message" autocomplete="off" required data-mt-chat-text>
                        <button type="submit" class="casino-btn casino-btn-primary">Send</button>
                    </form>
                </section>

                <section class="mt-panel" aria-labelledby="mt-log-title">
                    <h2 class="mt-panel-title" id="mt-log-title">What happened</h2>
                    <ol class="mt-log" data-mt-log></ol>
                </section>

                <details class="mt-panel mt-rules">
                    <summary class="mt-panel-title">Rules</summary>
                    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/rules.php'; ?>
                </details>
            </aside>
        </div>

        <dialog class="games-dialog mt-deed" aria-labelledby="mt-deed-title" data-mt-deed>
            <div class="mt-deed-head" data-mt-deed-head>
                <p class="mt-deed-kind" data-mt-deed-kind></p>
                <h2 id="mt-deed-title" data-mt-deed-title></h2>
            </div>
            <table class="mt-deed-table" data-mt-deed-table></table>
            <p class="mt-deed-owner" data-mt-deed-owner></p>
            <p class="mt-message" role="alert" data-mt-deed-message></p>
            <div class="games-dialog-actions mt-deed-actions" data-mt-deed-actions></div>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-mt-deed-close>Close</button>
            </div>
        </dialog>

        <dialog class="games-dialog mt-trade" aria-labelledby="mt-trade-title" data-mt-trade-dialog>
            <h2 id="mt-trade-title">Offer a trade</h2>
            <label class="casino-label" for="mt-trade-with">Trade with</label>
            <select id="mt-trade-with" class="casino-input" data-mt-trade-with></select>
            <div class="mt-trade-sides">
                <fieldset class="mt-trade-side">
                    <legend class="casino-label">You give</legend>
                    <div class="mt-trade-fields" data-mt-trade-give></div>
                    <label class="mt-trade-amount">Money <input type="number" class="casino-input" min="0" step="50" value="0" inputmode="numeric" data-mt-trade-give-cash></label>
                    <label class="mt-trade-amount" data-mt-trade-give-cards-row>"Benådet" cards <input type="number" class="casino-input" min="0" max="2" value="0" data-mt-trade-give-cards></label>
                </fieldset>
                <fieldset class="mt-trade-side">
                    <legend class="casino-label">You get</legend>
                    <div class="mt-trade-fields" data-mt-trade-get></div>
                    <label class="mt-trade-amount">Money <input type="number" class="casino-input" min="0" step="50" value="0" inputmode="numeric" data-mt-trade-get-cash></label>
                    <label class="mt-trade-amount" data-mt-trade-get-cards-row>"Benådet" cards <input type="number" class="casino-input" min="0" max="2" value="0" data-mt-trade-get-cards></label>
                </fieldset>
            </div>
            <p class="mt-hint">Properties with houses or hotels in their colour can't be traded until those are sold.</p>
            <p class="mt-message" role="alert" data-mt-trade-message></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-mt-trade-cancel>Cancel</button>
                <button type="button" class="casino-btn casino-btn-primary" data-mt-trade-send>Send offer</button>
            </div>
        </dialog>

        <dialog class="games-dialog mt-invite" aria-labelledby="mt-invite-title" data-mt-invite-dialog>
            <h2 id="mt-invite-title" data-mt-invite-title>Invite people</h2>
            <p data-mt-invite-text></p>
            <label class="casino-label" for="mt-invite-search">Find people</label>
            <input type="search" id="mt-invite-search" class="casino-input" placeholder="Search by name" autocomplete="off" data-mt-invite-search>
            <ul class="mt-invite-list" data-mt-invite-list></ul>
            <p class="mt-hint" role="status" data-mt-invite-status></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-mt-invite-close>Done</button>
            </div>
        </dialog>

        <!-- The room's settings: the host changes them before the game starts, everyone else (and the host once it has
             started) only sees them. matadorGame.js fills in the choices from the game and sends them as "settings". -->
        <dialog class="games-dialog mt-settings" aria-labelledby="mt-settings-title" data-mt-settings-dialog>
            <form method="dialog" data-mt-settings-form>
                <h2 id="mt-settings-title">Room settings</h2>
                <p class="mt-hint" data-mt-settings-text></p>
                <fieldset class="casino-choices" data-mt-settings-fields>
                    <legend class="casino-label">Dice</legend>
                    <?php foreach ([
                        1 => ['1 die (default)', 'A 6 gives another roll, and gets you out of jail.'],
                        2 => ['2 dice', 'Doubles give another roll, and get you out of jail.'],
                    ] as $value => [$label, $hint]): ?>
                        <label class="casino-choice">
                            <input type="radio" name="dice" value="<?= $value ?>">
                            <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="casino-choices" data-mt-settings-fields>
                    <legend class="casino-label">Collect rent while in jail</legend>
                    <?php foreach (HBHUB_MATADOR_JAIL_RENT_CHOICES as $value => $label):
                        $hint = match ($value) {
                            'yes' => 'Players in jail still get the rent for their properties.',
                            'no' => 'Nobody pays rent to a player who\'s in jail.',
                            'bank' => 'The rent is still paid, but to the bank, not to the player in jail.',
                        }; ?>
                        <label class="casino-choice">
                            <input type="radio" name="jailRent" value="<?= $value ?>">
                            <span>
                                <strong><?= htmlspecialchars($label) ?><?= $value === HBHUB_MATADOR_DEFAULT_SETTINGS['jailRent'] ? ' (default)' : '' ?></strong>
                                <span class="casino-hint"><?= htmlspecialchars($hint) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="mt-message" role="alert" data-mt-settings-message></p>
                <div class="games-dialog-actions">
                    <button type="button" class="casino-btn" data-mt-settings-close>Close</button>
                    <button type="submit" class="casino-btn casino-btn-primary" value="save" data-mt-settings-save>Save</button>
                </div>
            </form>
        </dialog>

        <dialog class="games-dialog" aria-labelledby="mt-confirm-title" data-mt-confirm>
            <h2 id="mt-confirm-title" data-mt-confirm-title></h2>
            <p data-mt-confirm-text></p>
            <div class="games-dialog-actions">
                <button type="button" class="casino-btn" data-mt-confirm-cancel>Cancel</button>
                <button type="button" class="casino-btn casino-btn-primary" data-mt-confirm-ok></button>
            </div>
        </dialog>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/matadorGame.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/matadorGame.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/boardZoom.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/boardZoom.js') ?>" defer></script>
</body>
</html>
