<?php
// An UNO game for 2-6 players, without the database: the room's state (roomState in HBHub-UnoRooms), dealing, playing
// and drawing cards, the turn timer, calling UNO and catching someone who didn't, the host pausing the game, and what
// each player is sent (hbHubUnoView()). room.php loads the state, runs one of these on it and saves it again.
//
// The cards are short strings: a colour (r, y, g or b) and what's on it (0-9, S Skip, R Reverse, D Draw Two), like
// "r7" or "gS", and the two wild cards "W" (Wild) and "F" (Wild Draw Four). The deck has 108 of them, so the same card
// can be in a hand twice - it doesn't matter which of the two is played.
//
// The house rules (see rules.php): a Wild Draw Four can be played at any time (there's no challenging it), the first
// card turned up is always a number card, and someone who leaves a game being played is out of it - the last player
// left in wins. The host can change some of it in the room's settings while no game is being played
// (hbHubUnoSettings()): the time for a turn, stacking (a Draw Two can be answered with a Draw Two and a Wild Draw Four
// with a Wild Draw Four, passing them all on to the next player), mixing them while stacking (a Wild Draw Four on a
// Draw Two and a Draw Two on a Wild Draw Four) and drawing until there's a card that can be played.
//
// The state:
//   phase: "lobby" (waiting for players, or for the host to start, hbHubUnoStart()), "playing" or "ended" (the players
//     are still there, new ones can join, and the host can start a new game)
//   settings: the room's settings, {turnSeconds, stacking, stackMixing, drawUntil}. Always read through hbHubUnoSettings()
//   players: who sits in the room, [{seat: 1-6, userId, name}], in seat order
//   games: how many games have been started in the room
//   note: something to tell the players left waiting (like "Alice left."), or null
//   game: the game being played, or the last one, or null:
//     players: everyone dealt in, in seat order (the order of play): {seat, userId, name, hand: [cards], out: they left,
//       excused: they left while the host had paused the game, so it doesn't count for them (missing until they leave)}
//     deck: the draw pile, its top card last. Never sent to anyone
//     discard: the cards played, the top card last
//     colour: the colour to play on (for a wild card, the one its player chose)
//     direction: 1 round the table in seat order, -1 the other way (after a Reverse)
//     turn: seat, id (goes up with every move, so a double click only counts once), drawn (the card just drawn, which
//       the player can still play, or null) and deadline (Unix ms: when nothing has been done by then, it's done for
//       them, hbHubUnoAdvance())
//     penalty: the cards the player whose turn it is has to draw unless they stack another one on (only with stacking)
//     uno: the seat of the player who's down to one card without having called UNO, who the others can catch, or null.
//       It goes when they call it, are caught, or the next player does something
//     pausedSince: Unix ms when the host paused the game, null while it goes on. Nobody can do anything in the game and
//       the turn timer stands still until the host lets it go on (hbHubUnoPause())
//     result: null while it's played, then {winner: a seat or null, reason: "out" (played their last card), "left"
//       (everyone else left) or "stopped" (everyone had gone), points: the value of the cards left in everyone else's
//       hands, which the winner scores}
//     recorded: it's in HBHub-UnoResults (results.php)
//     turns: how many cards have been played (several played together count one each)
//     log: what happened, the newest last ({n, text}), and logCount (n of the newest)
//     last: what happened last, for unoGame.js to show: {n (counts up, so a new one can be told from the last one), type
//       ("play", "draw", "uno" or "catch"), seat, card (for "play": the last one played), count (for "draw" and
//       "catch", and how many were played together for "play")}, or null

const HBHUB_UNO_MIN_PLAYERS = 2;
const HBHUB_UNO_MAX_PLAYERS = 6;
const HBHUB_UNO_HAND = 7; // cards dealt to each player
const HBHUB_UNO_CATCH_CARDS = 2; // drawn by a player caught not saying UNO
const HBHUB_UNO_DRAWN_SECONDS = 10; // at least this long left to play or keep a card just drawn
const HBHUB_UNO_LOG_KEPT = 30;
const HBHUB_UNO_COLOURS = ['r', 'y', 'g', 'b'];
const HBHUB_UNO_COLOUR_NAMES = ['r' => 'red', 'y' => 'yellow', 'g' => 'green', 'b' => 'blue'];
const HBHUB_UNO_TURN_CHOICES = [15, 30, 60]; // the turnSeconds setting
const HBHUB_UNO_DEFAULT_SETTINGS = ['turnSeconds' => 30, 'stacking' => true, 'stackMixing' => true, 'drawUntil' => true];

function hbHubUnoNowMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

/**
 * A new room, with the default settings (the host changes them in the game, hbHubUnoChangeSettings()).
 */
function hbHubUnoNewState(): array
{
    return [
        'phase' => 'lobby',
        'settings' => HBHUB_UNO_DEFAULT_SETTINGS,
        'players' => [],
        'games' => 0,
        'note' => null,
        'game' => null,
    ];
}

function hbHubUnoNewPlayer(int $seat, int $userId, string $name): array
{
    return ['seat' => $seat, 'userId' => $userId, 'name' => $name];
}

/**
 * The room's settings, with the defaults for anything that's missing.
 *
 * @return array{turnSeconds: int, stacking: bool, stackMixing: bool, drawUntil: bool}
 */
function hbHubUnoSettings(array $state): array
{
    return ($state['settings'] ?? []) + HBHUB_UNO_DEFAULT_SETTINGS;
}

/**
 * The settings as sent from the game's settings (turnSeconds: one of HBHUB_UNO_TURN_CHOICES, stacking: "off", "same"
 * (only the same kind of draw card) or "mixed" (any draw card on any), drawUntil: "on" or "off"), or null when they
 * can't be. Stacking is one choice in the settings but kept as two: stacking and stackMixing.
 *
 * @return ?array{turnSeconds: int, stacking: bool, stackMixing: bool, drawUntil: bool}
 */
function hbHubUnoCleanSettings(mixed $turnSeconds, mixed $stacking, mixed $drawUntil): ?array
{
    $turnSeconds = is_string($turnSeconds) && ctype_digit($turnSeconds) && strlen($turnSeconds) <= 3 ? (int) $turnSeconds : null;
    if (!in_array($turnSeconds, HBHUB_UNO_TURN_CHOICES, true) || !in_array($stacking, ['off', 'same', 'mixed'], true)
        || !in_array($drawUntil, ['on', 'off'], true)) {
        return null;
    }
    return ['turnSeconds' => $turnSeconds, 'stacking' => $stacking !== 'off', 'stackMixing' => $stacking === 'mixed',
        'drawUntil' => $drawUntil === 'on'];
}

/**
 * The settings in a few words, for the lobby and the game: "30 seconds a turn, stacking, draw until you can play"
 * ("stacking the same card" when Draw Twos and Wild Draw Fours can't be mixed).
 */
function hbHubUnoSettingsText(array $settings): string
{
    return $settings['turnSeconds'] . ' seconds a turn, '
        . ($settings['stacking'] ? ($settings['stackMixing'] ? 'stacking' : 'stacking the same card') : 'no stacking')
        . ', ' . ($settings['drawUntil'] ? 'draw until you can play' : 'draw one card');
}

/**
 * The list in a random order (random_int(), not shuffle(), as it decides who gets which cards).
 */
function hbHubUnoShuffle(array $list): array
{
    $list = array_values($list);
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
    }
    return $list;
}

/**
 * All 108 cards: in each colour one 0, and two each of 1-9, Skip, Reverse and Draw Two. Then four Wilds and four Wild
 * Draw Fours.
 *
 * @return list<string>
 */
function hbHubUnoNewDeck(): array
{
    $deck = [];
    foreach (HBHUB_UNO_COLOURS as $colour) {
        $deck[] = $colour . '0';
        foreach ([...range(1, 9), 'S', 'R', 'D'] as $value) {
            $deck[] = $colour . $value;
            $deck[] = $colour . $value;
        }
    }
    return [...$deck, ...array_fill(0, 4, 'W'), ...array_fill(0, 4, 'F')];
}

function hbHubUnoIsWild(string $card): bool
{
    return $card === 'W' || $card === 'F';
}

/**
 * What's on the card, without its colour: 0-9, S, R, D, or W and F for the wild cards.
 */
function hbHubUnoValue(string $card): string
{
    return hbHubUnoIsWild($card) ? $card : substr($card, 1);
}

/**
 * The card's name, for the log: "red 7", "green Skip", "Wild Draw Four".
 */
function hbHubUnoCardName(string $card): string
{
    return match (hbHubUnoValue($card)) {
        'W' => 'Wild',
        'F' => 'Wild Draw Four',
        'S' => HBHUB_UNO_COLOUR_NAMES[$card[0]] . ' Skip',
        'R' => HBHUB_UNO_COLOUR_NAMES[$card[0]] . ' Reverse',
        'D' => HBHUB_UNO_COLOUR_NAMES[$card[0]] . ' Draw Two',
        default => HBHUB_UNO_COLOUR_NAMES[$card[0]] . ' ' . $card[1],
    };
}

/**
 * What the card counts for the winner when it's left in someone's hand: a number card its number, Skip, Reverse and
 * Draw Two 20, and the wild cards 50.
 */
function hbHubUnoCardPoints(string $card): int
{
    $value = hbHubUnoValue($card);
    return match (true) {
        ctype_digit($value) => (int) $value,
        hbHubUnoIsWild($card) => 50,
        default => 20,
    };
}

/**
 * The seat the user sits in, in the room, or null.
 */
function hbHubUnoSeatOfUser(array $state, int $userId): ?int
{
    foreach ($state['players'] as $player) {
        if ($player['userId'] === $userId) {
            return $player['seat'];
        }
    }
    return null;
}

/**
 * Where the seat's player is in the game's players, or null when they weren't dealt in.
 */
function hbHubUnoIndex(array $game, int $seat): ?int
{
    foreach ($game['players'] as $i => $player) {
        if ($player['seat'] === $seat) {
            return $i;
        }
    }
    return null;
}

/**
 * The seat the user plays in the game (or played in the last one) while they're still in it, or null.
 */
function hbHubUnoGameSeatOfUser(array $state, int $userId): ?int
{
    foreach ($state['game']['players'] ?? [] as $player) {
        if ($player['userId'] === $userId && !$player['out']) {
            return $player['seat'];
        }
    }
    return null;
}

function hbHubUnoName(array $game, int $seat): string
{
    $i = hbHubUnoIndex($game, $seat);
    return $i !== null ? $game['players'][$i]['name'] : 'Someone';
}

/**
 * The players still in the game (who haven't left it), in seat order.
 */
function hbHubUnoActive(array $game): array
{
    return array_values(array_filter($game['players'], static fn (array $player): bool => !$player['out']));
}

function hbHubUnoLog(array &$game, string $text): void
{
    $game['logCount']++;
    $game['log'][] = ['n' => $game['logCount'], 'text' => $text];
    if (count($game['log']) > HBHUB_UNO_LOG_KEPT) {
        $game['log'] = array_slice($game['log'], -HBHUB_UNO_LOG_KEPT);
    }
}

/**
 * Notes what just happened for unoGame.js to show (the game's "last").
 */
function hbHubUnoLast(array &$game, string $type, int $seat, ?string $card = null, int $count = 0): void
{
    $game['last'] = ['n' => ($game['last']['n'] ?? 0) + 1, 'type' => $type, 'seat' => $seat, 'card' => $card, 'count' => $count];
}

/**
 * Starts a new game with everyone in the room: each gets 7 cards, the first card is turned up (a number card: anything
 * else goes back into the deck), and a random player goes first.
 */
function hbHubUnoBegin(array &$state, int $now): void
{
    $deck = hbHubUnoShuffle(hbHubUnoNewDeck());
    $players = [];
    foreach ($state['players'] as $player) {
        $players[] = [
            'seat' => $player['seat'],
            'userId' => $player['userId'],
            'name' => $player['name'],
            'hand' => array_splice($deck, -HBHUB_UNO_HAND),
            'out' => false,
        ];
    }

    $first = array_pop($deck);
    for ($guard = 0; !ctype_digit(hbHubUnoValue($first)) && $guard < 100; $guard++) {
        $deck = hbHubUnoShuffle([...$deck, $first]);
        $first = array_pop($deck);
    }

    $state['game'] = [
        'players' => $players,
        'deck' => $deck,
        'discard' => [$first],
        'colour' => $first[0],
        'direction' => 1,
        'turn' => [
            'seat' => $players[random_int(0, count($players) - 1)]['seat'],
            'id' => 1,
            'drawn' => null,
            'deadline' => $now + hbHubUnoSettings($state)['turnSeconds'] * 1000,
        ],
        'penalty' => 0,
        'uno' => null,
        'pausedSince' => null,
        'result' => null,
        'recorded' => false,
        'turns' => 0,
        'log' => [],
        'logCount' => 0,
        'last' => null,
    ];
    $state['phase'] = 'playing';
    $state['games']++;
    $state['note'] = null;
    hbHubUnoLog($state['game'], 'The game has started. ' . hbHubUnoName($state['game'], $state['game']['turn']['seat']) . ' goes first.');
}

/**
 * The host starts a game once at least 2 players are in the room: the first one, or a new one after the last one ended.
 * Returns null when it's started, otherwise why not.
 */
function hbHubUnoStart(array &$state, bool $isHost, int $now): ?string
{
    if (!$isHost) {
        return 'Only the host can start the game.';
    }
    if ($state['phase'] === 'playing') {
        return null; // started already (a double click, or another tab)
    }
    if (count($state['players']) < HBHUB_UNO_MIN_PLAYERS) {
        return 'Wait for someone to join first.';
    }
    hbHubUnoBegin($state, $now);
    return null;
}

/**
 * The card on top of the discard pile.
 */
function hbHubUnoTop(array $game): string
{
    return $game['discard'][count($game['discard']) - 1];
}

/**
 * Whether the card can be played now. With cards to draw waiting (stacking), only a draw card can: any Draw Two or
 * Wild Draw Four with $stackMixing (the stackMixing setting), otherwise only the same kind - a Draw Two on a Draw Two,
 * a Wild Draw Four on a Wild Draw Four. Otherwise a wild card always can, and any other card when it has the colour to
 * play on or what's on the top card (a number, Skip, Reverse or Draw Two).
 */
function hbHubUnoCanPlay(array $game, string $card, bool $stackMixing): bool
{
    $top = hbHubUnoTop($game);
    if ($game['penalty'] > 0) {
        return $stackMixing
            ? in_array(hbHubUnoValue($card), ['D', 'F'], true)
            : hbHubUnoValue($card) === hbHubUnoValue($top);
    }
    return hbHubUnoIsWild($card)
        || $card[0] === $game['colour']
        || (!hbHubUnoIsWild($top) && hbHubUnoValue($card) === hbHubUnoValue($top));
}

/**
 * Takes cards off the draw pile. When it runs out, the discard pile (all but its top card) is shuffled into a new one.
 * Fewer come when every other card is in someone's hand.
 *
 * @return list<string>
 */
function hbHubUnoTake(array &$game, int $count): array
{
    $cards = [];
    for ($i = 0; $i < $count; $i++) {
        if ($game['deck'] === []) {
            if (count($game['discard']) <= 1) {
                break;
            }
            $top = array_pop($game['discard']);
            $game['deck'] = hbHubUnoShuffle($game['discard']);
            $game['discard'] = [$top];
        }
        $cards[] = array_pop($game['deck']);
    }
    return $cards;
}

/**
 * The seat's player draws $count cards. Returns the cards.
 *
 * @return list<string>
 */
function hbHubUnoGive(array &$game, int $seat, int $count): array
{
    $cards = hbHubUnoTake($game, $count);
    $i = hbHubUnoIndex($game, $seat);
    array_push($game['players'][$i]['hand'], ...$cards);
    return $cards;
}

/**
 * The next player still in the game after the seat, the way play goes round the table.
 */
function hbHubUnoNextSeat(array $game, int $seat): int
{
    $count = count($game['players']);
    $i = hbHubUnoIndex($game, $seat) ?? 0;
    for ($step = 1; $step <= $count; $step++) {
        $next = $game['players'][(($i + $step * $game['direction']) % $count + $count) % $count];
        if (!$next['out']) {
            return $next['seat'];
        }
    }
    return $seat;
}

/**
 * The turn goes on to the next player, past $skip players (a Skip, or a draw card without stacking), with a new
 * deadline.
 */
function hbHubUnoPassTurn(array &$state, int $skip, int $now): void
{
    $game = &$state['game'];
    $seat = $game['turn']['seat'];
    for ($i = 0; $i <= $skip; $i++) {
        $seat = hbHubUnoNextSeat($game, $seat);
    }
    $game['turn'] = [
        'seat' => $seat,
        'id' => $game['turn']['id'] + 1,
        'drawn' => null,
        'deadline' => $now + hbHubUnoSettings($state)['turnSeconds'] * 1000,
    ];
}

/**
 * Why the seat's player can't do something in the game right now, or null when they can (it's their turn, unless
 * $anyTurn).
 */
function hbHubUnoCantAct(array $state, int $seat, bool $anyTurn = false): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    if ($state['game']['pausedSince'] !== null) {
        return 'The game is paused.';
    }
    if (!$anyTurn && $state['game']['turn']['seat'] !== $seat) {
        return 'It\'s not your turn.';
    }
    return null;
}

/**
 * The seat's player plays $cards (as sent: one card like "r7", or several with the same number or symbol in the order
 * they were clicked, like "r7,r7,b7"), with $colour (as sent: r, y, g or b) for a wild card. Only the first card has to
 * go on the top card; the others just have to match it, whatever their colour, and the last one decides the colour to
 * play on. Each card does what it does: two Skips skip two players, two Draw Twos make 4 cards to draw. The wild cards
 * and a card just drawn only go one at a time. $saidUno: they called UNO with it, which counts when it leaves them with
 * one card. Returns null when it's played, otherwise why not.
 */
function hbHubUnoPlayCard(array &$state, int $seat, mixed $cards, mixed $colour, bool $saidUno, int $now): ?string
{
    $error = hbHubUnoCantAct($state, $seat);
    if ($error !== null) {
        return $error;
    }
    $game = &$state['game'];
    $i = hbHubUnoIndex($game, $seat);
    $cards = is_string($cards) && strlen($cards) <= 100 ? explode(',', $cards) : [];
    $hand = $game['players'][$i]['hand'];
    foreach ($cards as $card) {
        $at = array_search($card, $hand, true); // taken out as it goes, so two of the same card need two in the hand
        if ($at === false) {
            return 'You don\'t have that card.';
        }
        array_splice($hand, $at, 1);
    }
    if ($cards === []) {
        return 'You don\'t have that card.';
    }
    $settings = hbHubUnoSettings($state);
    $card = $cards[0];
    $count = count($cards);
    if ($game['turn']['drawn'] !== null && ($card !== $game['turn']['drawn'] || $count > 1)) {
        return 'You can only play the card you just drew, or keep it.';
    }
    if (!hbHubUnoCanPlay($game, $card, $settings['stackMixing'])) {
        return $game['penalty'] > 0
            ? 'Stack a ' . ($settings['stackMixing'] ? 'Draw Two or Wild Draw Four' : (hbHubUnoTop($game) === 'F' ? 'Wild Draw Four' : 'Draw Two'))
                . ' on it, or draw the ' . $game['penalty'] . ' cards.'
            : 'That card can\'t go on the ' . hbHubUnoCardName(hbHubUnoTop($game)) . '.';
    }
    $isWild = hbHubUnoIsWild($card);
    if ($count > 1 && ($isWild || array_filter($cards, static fn (string $other): bool => hbHubUnoValue($other) !== hbHubUnoValue($card)) !== [])) {
        return $isWild ? 'Wild cards are played one at a time.' : 'Cards played together need the same number or symbol.';
    }
    if ($isWild && !in_array($colour, HBHUB_UNO_COLOURS, true)) {
        return 'Choose a colour for the wild card.';
    }

    $name = $game['players'][$i]['name'];
    $game['uno'] = null; // the next player has done something: too late to catch whoever it was
    $game['players'][$i]['hand'] = $hand;
    $left = count($hand);
    $card = $cards[$count - 1]; // the last one is on top
    array_push($game['discard'], ...$cards);
    $game['colour'] = $isWild ? $colour : $card[0];
    $game['turns'] += $count;
    hbHubUnoLast($game, 'play', $seat, $card, $count);
    hbHubUnoLog($game, $name . ' played ' . implode(', ', array_map('hbHubUnoCardName', array_slice($cards, 0, -1)))
        . ($count > 1 ? ' and ' : '') . hbHubUnoCardName($card) . ($isWild ? ' and chose ' . HBHUB_UNO_COLOUR_NAMES[$colour] : '') . '.');
    if ($left === 1) {
        if ($saidUno) {
            hbHubUnoLog($game, $name . ': UNO!');
        } else {
            $game['uno'] = $seat;
        }
    }

    // Each Skip skips one more player, but never round the table back past whoever played them
    $skip = 0;
    $value = hbHubUnoValue($card);
    $mostSkipped = count(hbHubUnoActive($game)) - 1;
    if ($value === 'R') {
        if ($count % 2 === 1) {
            $game['direction'] = -$game['direction'];
        }
        if ($mostSkipped === 1) {
            $skip = 1; // with two players a Reverse works like a Skip
        }
    } elseif ($value === 'S') {
        $skip = min($count, $mostSkipped);
    } elseif ($value === 'D' || $value === 'F') {
        $cards = ($value === 'D' ? 2 : 4) * $count;
        if ($settings['stacking'] && $left > 0) {
            $game['penalty'] += $cards; // the next player stacks another one on, or draws them all
        } else {
            $count = $game['penalty'] + $cards;
            $game['penalty'] = 0;
            $next = hbHubUnoNextSeat($game, $seat);
            hbHubUnoGive($game, $next, $count);
            hbHubUnoLog($game, hbHubUnoName($game, $next) . ' draws ' . $count . ' cards and misses their turn.');
            $skip = 1;
        }
    }

    unset($game);
    if ($left === 0) {
        hbHubUnoEnd($state, $seat, 'out');
    } else {
        hbHubUnoPassTurn($state, $skip, $now);
    }
    return null;
}

/**
 * The seat's player draws instead of playing. With cards to draw waiting (stacking), they draw them all and their turn
 * is over. Otherwise they draw one card (or until they get one that can be played, with the drawUntil setting): when it
 * can be played they choose to play it or keep it, otherwise their turn is over. Returns null when it's done, otherwise
 * why not.
 */
function hbHubUnoDraw(array &$state, int $seat, int $now): ?string
{
    $error = hbHubUnoCantAct($state, $seat);
    if ($error !== null) {
        return $error;
    }
    $game = &$state['game'];
    if ($game['turn']['drawn'] !== null) {
        return 'You\'ve drawn a card already. Play it or keep it.';
    }
    $settings = hbHubUnoSettings($state);
    $name = hbHubUnoName($game, $seat);
    $game['uno'] = null;

    if ($game['penalty'] > 0) {
        $drawn = hbHubUnoGive($game, $seat, $game['penalty']);
        $game['penalty'] = 0;
        hbHubUnoLast($game, 'draw', $seat, null, count($drawn));
        hbHubUnoLog($game, $name . ' drew ' . count($drawn) . ' cards and misses their turn.');
        unset($game);
        hbHubUnoPassTurn($state, 0, $now);
        return null;
    }

    $drawn = [];
    do {
        $cards = hbHubUnoGive($game, $seat, 1);
        array_push($drawn, ...$cards);
    } while ($cards !== [] && $settings['drawUntil'] && !hbHubUnoCanPlay($game, $cards[0], $settings['stackMixing']));
    hbHubUnoLast($game, 'draw', $seat, null, count($drawn));
    hbHubUnoLog($game, $name . match (count($drawn)) {
        0 => ' couldn\'t draw, as there are no cards left.',
        1 => ' drew a card.',
        default => ' drew ' . count($drawn) . ' cards.',
    });

    $card = $drawn !== [] ? $drawn[count($drawn) - 1] : null;
    if ($card !== null && hbHubUnoCanPlay($game, $card, $settings['stackMixing'])) {
        $game['turn']['drawn'] = $card;
        $game['turn']['id']++;
        $game['turn']['deadline'] = max($game['turn']['deadline'], $now + HBHUB_UNO_DRAWN_SECONDS * 1000); // time to choose
        return null;
    }
    unset($game);
    hbHubUnoPassTurn($state, 0, $now);
    return null;
}

/**
 * The seat's player keeps the card they just drew, and their turn is over.
 */
function hbHubUnoKeep(array &$state, int $seat, int $now): ?string
{
    $error = hbHubUnoCantAct($state, $seat);
    if ($error !== null) {
        return $error;
    }
    if ($state['game']['turn']['drawn'] === null) {
        return 'Draw a card first.';
    }
    hbHubUnoLog($state['game'], hbHubUnoName($state['game'], $state['game']['turn']['seat']) . ' kept the card.');
    hbHubUnoPassTurn($state, 0, $now);
    return null;
}

/**
 * The seat's player calls UNO after playing their second-to-last card, before anyone catches them.
 */
function hbHubUnoCallUno(array &$state, int $seat): ?string
{
    $error = hbHubUnoCantAct($state, $seat, true);
    if ($error !== null) {
        return $error;
    }
    $game = &$state['game'];
    if ($game['uno'] !== $seat) {
        return null; // called already (a double click), or it's too late
    }
    $game['uno'] = null;
    hbHubUnoLast($game, 'uno', $seat);
    hbHubUnoLog($game, hbHubUnoName($game, $seat) . ': UNO!');
    return null;
}

/**
 * The seat's player catches the one who's down to one card without calling UNO: they draw HBHUB_UNO_CATCH_CARDS.
 */
function hbHubUnoCatch(array &$state, int $seat): ?string
{
    $error = hbHubUnoCantAct($state, $seat, true);
    if ($error !== null) {
        return $error;
    }
    $game = &$state['game'];
    $caught = $game['uno'];
    if ($caught === null) {
        return 'There\'s nobody to catch right now. Someone may have been quicker.';
    }
    if ($caught === $seat) {
        return 'Call UNO instead!';
    }
    $game['uno'] = null;
    $drawn = hbHubUnoGive($game, $caught, HBHUB_UNO_CATCH_CARDS);
    hbHubUnoLast($game, 'catch', $caught, null, count($drawn));
    hbHubUnoLog($game, hbHubUnoName($game, $seat) . ' caught ' . hbHubUnoName($game, $caught) . ' not saying UNO! '
        . hbHubUnoName($game, $caught) . ' draws ' . count($drawn) . ' cards.');
    return null;
}

/**
 * Ends the game. $winner is the seat that won (or null), and scores the cards left in everyone else's hands.
 */
function hbHubUnoEnd(array &$state, ?int $winner, string $reason): void
{
    $game = &$state['game'];
    $points = 0;
    if ($winner !== null) {
        foreach ($game['players'] as $player) {
            foreach ($player['hand'] as $card) {
                $points += hbHubUnoCardPoints($card);
            }
        }
    }
    $game['result'] = ['winner' => $winner, 'reason' => $reason, 'points' => $points];
    $game['turn']['drawn'] = null;
    $game['penalty'] = 0;
    $game['uno'] = null;
    $game['pausedSince'] = null;
    $state['phase'] = 'ended';
    hbHubUnoLog($game, match ($reason) {
        'out' => hbHubUnoName($game, (int) $winner) . ' played their last card and won, with ' . $points . ' points!',
        'left' => hbHubUnoName($game, (int) $winner) . ' won, as everyone else left.',
        default => 'The game was stopped, as everyone had gone.',
    });
}

/**
 * Whether the player whose turn it is has run out of time (never while the game is paused).
 */
function hbHubUnoTimeUp(array $state, int $now): bool
{
    return $state['phase'] === 'playing' && $state['game']['pausedSince'] === null && $state['game']['turn']['deadline'] <= $now;
}

/**
 * When the player whose turn it is has run out of time, their turn is played for them: they draw the cards waiting
 * for them (stacking), keep the card they drew, or draw one card - and their turn is over. Called on every request that
 * touches the room.
 */
function hbHubUnoAdvance(array &$state, int $now): void
{
    if (!hbHubUnoTimeUp($state, $now)) {
        return;
    }
    $game = &$state['game'];
    $seat = $game['turn']['seat'];
    $name = hbHubUnoName($game, $seat);
    $game['uno'] = null;
    if ($game['turn']['drawn'] !== null) {
        hbHubUnoLog($game, $name . ' ran out of time and kept the card.');
    } else {
        $drawn = hbHubUnoGive($game, $seat, max(1, $game['penalty']));
        $game['penalty'] = 0;
        hbHubUnoLast($game, 'draw', $seat, null, count($drawn));
        hbHubUnoLog($game, $name . ' ran out of time and drew ' . (count($drawn) === 1 ? 'a card.' : count($drawn) . ' cards.'));
    }
    unset($game);
    hbHubUnoPassTurn($state, 0, $now);
}

/**
 * Takes the user out of the game being played (room.php, when they leave the room or have been gone too long): their
 * cards are shuffled back into the draw pile, and the turn goes on when it was theirs. The last player left in wins.
 */
function hbHubUnoDropPlayer(array &$state, int $userId, bool $isGone, int $now): void
{
    $seat = $state['phase'] === 'playing' ? hbHubUnoGameSeatOfUser($state, $userId) : null;
    if ($seat === null) {
        return;
    }
    $game = &$state['game'];
    $i = hbHubUnoIndex($game, $seat);
    $game['deck'] = hbHubUnoShuffle([...$game['deck'], ...$game['players'][$i]['hand']]);
    $game['players'][$i]['hand'] = [];
    $game['players'][$i]['out'] = true;
    $game['players'][$i]['excused'] = $game['pausedSince'] !== null; // leaving while it's paused isn't a loss (results.php)
    if ($game['uno'] === $seat) {
        $game['uno'] = null;
    }
    hbHubUnoLog($game, $game['players'][$i]['name'] . ($isGone ? ' was gone too long, and is out of the game.' : ' left the game.'));

    $active = hbHubUnoActive($game);
    $isTheirTurn = $game['turn']['seat'] === $seat;
    unset($game);
    if (count($active) <= 1) {
        hbHubUnoEnd($state, $active[0]['seat'] ?? null, $active === [] ? 'stopped' : 'left');
    } elseif ($isTheirTurn) {
        $state['game']['penalty'] = 0;
        hbHubUnoPassTurn($state, 0, $now);
    }
}

/**
 * Stops the game being played, as everyone has gone (room.php): they've all left it, so it's a loss for each of them,
 * unless the host had paused it.
 */
function hbHubUnoStop(array &$state): void
{
    $game = &$state['game'];
    foreach ($game['players'] as &$player) {
        if (!$player['out']) {
            $player['out'] = true;
            $player['excused'] = $game['pausedSince'] !== null;
        }
    }
    unset($player, $game);
    hbHubUnoEnd($state, null, 'stopped');
}

/**
 * The host pauses the game ($pause) or lets it go on. While it's paused nobody can do anything in it and the turn timer
 * stands still: going on moves the deadline on by how long it was paused.
 */
function hbHubUnoPause(array &$state, bool $isHost, bool $pause, int $now): ?string
{
    if (!$isHost) {
        return 'Only the host can pause the game.';
    }
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    $game = &$state['game'];
    $since = $game['pausedSince'];
    if ($pause === ($since !== null)) {
        return null; // it already is (a double click, or another tab)
    }
    if ($pause) {
        $game['pausedSince'] = $now;
        hbHubUnoLog($game, 'The host paused the game.');
    } else {
        $game['turn']['deadline'] += $now - $since;
        $game['pausedSince'] = null;
        hbHubUnoLog($game, 'The host let the game go on.');
    }
    return null;
}

/**
 * The host changes the room's settings (as sent, hbHubUnoCleanSettings()) while no game is being played. Returns null
 * when they're changed, otherwise why not.
 */
function hbHubUnoChangeSettings(array &$state, bool $isHost, mixed $turnSeconds, mixed $stacking, mixed $drawUntil): ?string
{
    if (!$isHost) {
        return 'Only the host can change the settings.';
    }
    if ($state['phase'] === 'playing') {
        return 'The settings can\'t be changed while a game is being played.';
    }
    $settings = hbHubUnoCleanSettings($turnSeconds, $stacking, $drawUntil);
    if ($settings === null) {
        return 'Choose the settings.';
    }
    $state['settings'] = $settings;
    return null;
}

/**
 * The game as the user sees it, for unoGame.js: only their own hand, and how many cards everyone else has. $people are
 * the names and pictures by userId (room.php's hbHubUnoPeople()), $seats who sits in the room right now (room.php's
 * hbHubUnoSeats()). Once the game is over, everyone's hand is shown.
 */
function hbHubUnoView(array $state, array $people, array $seats, int $userId, ?int $hostUserId, int $now): array
{
    $seatOf = array_column($seats, null, 'userId');
    $person = static function (int $playerId, string $name) use ($people, $seatOf, $userId, $hostUserId): array {
        $seat = $seatOf[$playerId] ?? null;
        return [
            'name' => $people[$playerId]['name'] ?? $name,
            'avatar' => $people[$playerId]['avatar'] ?? null,
            'isYou' => $playerId === $userId,
            'isHost' => $playerId === $hostUserId,
            'here' => $seat !== null,
            'away' => $seat !== null && $seat['away'],
        ];
    };
    $settings = hbHubUnoSettings($state);

    $view = [
        'phase' => $state['phase'],
        'settings' => $settings,
        'settingsText' => hbHubUnoSettingsText($settings),
        'isHost' => $hostUserId === $userId,
        'note' => $state['note'],
        'seated' => array_map(static fn (array $player): array => ['seat' => $player['seat']] + $person($player['userId'], $player['name']),
            $state['players']),
        'minPlayers' => HBHUB_UNO_MIN_PLAYERS,
        'maxPlayers' => HBHUB_UNO_MAX_PLAYERS,
        'game' => null,
    ];

    $game = $state['game'];
    if ($game === null) {
        return $view;
    }

    $isPlaying = $state['phase'] === 'playing';
    $isPaused = $isPlaying && $game['pausedSince'] !== null;
    $you = hbHubUnoGameSeatOfUser($state, $userId);
    $hand = $you !== null ? $game['players'][hbHubUnoIndex($game, $you)]['hand'] : [];
    $isYourTurn = $isPlaying && !$isPaused && $you !== null && $game['turn']['seat'] === $you;
    $drawn = $game['turn']['drawn'];
    $playable = $isYourTurn
        ? array_values(array_unique(array_filter($hand, static fn (string $card): bool => hbHubUnoCanPlay($game, $card, $settings['stackMixing'])
            && ($drawn === null || $card === $drawn))))
        : [];

    $view['game'] = [
        'players' => array_map(static fn (array $player): array => ['seat' => $player['seat']] + $person($player['userId'], $player['name']) + [
            'cards' => count($player['hand']),
            'out' => $player['out'],
            'saidUno' => count($player['hand']) === 1 && $game['uno'] !== $player['seat'],
            'hand' => $state['phase'] === 'ended' ? $player['hand'] : null,
        ], $game['players']),
        'you' => $you,
        'hand' => $hand,
        'playable' => $playable,
        'top' => hbHubUnoTop($game),
        'colour' => $game['colour'],
        'direction' => $game['direction'],
        'deck' => count($game['deck']),
        'turn' => [
            'seat' => $game['turn']['seat'],
            'id' => $game['turn']['id'],
            'drawn' => $isYourTurn ? $drawn : null, // which card it was is only for the one who drew it
            'hasDrawn' => $drawn !== null,
            // The time left, counted down by unoGame.js (only up to when the game was paused, while it is)
            'timeLeft' => $isPlaying ? max(0, $game['turn']['deadline'] - ($game['pausedSince'] ?? $now)) : null,
            'seconds' => $settings['turnSeconds'],
        ],
        'penalty' => $game['penalty'],
        'uno' => $game['uno'],
        'paused' => $isPaused,
        'result' => $game['result'],
        'log' => $game['log'],
        'last' => $game['last'],
    ];
    return $view;
}
