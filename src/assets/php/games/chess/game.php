<?php
// A chess game between two players, without the database: the room's state (roomState in HBHub-ChessRooms), playing a
// move with the clocks, draw offers, resigning, aborting, claiming the win when the other player has gone, rematches,
// and what the players are sent (hbHubChessView()). The rules of the board are in board.php, and room.php loads and
// saves the state around all of this.
//
// The state:
//   phase: "lobby" (waiting for a second player), "playing" or "ended" (both still there, maybe for a rematch)
//   clock: the time control, a key of HBHUB_CHESS_CLOCKS
//   colour: the colour the host plays: "random", "white" or "black"
//   players: who sits in the room, [{seat: 1 or 2, userId, name}]
//   games: how many games have been started in the room
//   note: something to tell the player left waiting (like "Alice left."), or null
//   game: the game being played, or the last one (also while waiting for a new player after the other left), or null:
//     white, black: {userId, name}
//     position: board.php's position
//     moves: [{from, to, promotion, san}], every move played (squares as numbers)
//     repetitions: how many times each position has come up (hbHubChessPositionKey()), for threefold repetition
//     clocks: {w, b} the time each player has left in ms, or null without a clock
//     clockSince: Unix ms when the clock of the player to move started, null before White's first move and once it ends
//     drawOffer: the colour offering a draw, or null. drawOffered: {w, b} the move count of their last offer
//     result: null while it's played, then {winner: "w", "b" or null for a draw, reason}
//     rematch: the colours that want another game
//     recorded: it's in HBHub-ChessResults (results.php)
// Nothing in it is hidden from the players, but they still get hbHubChessView(), which adds the legal moves and the
// clocks as they are right now.
require_once __DIR__ . '/board.php';

const HBHUB_CHESS_CLOCKS = [ // key => [minutes each, seconds added per move], null for no clock
    'none' => null,
    '3+2' => [3, 2],
    '5+0' => [5, 0],
    '10+0' => [10, 0],
    '15+10' => [15, 10],
    '30+0' => [30, 0],
];
const HBHUB_CHESS_CLOCK_NAMES = [
    'none' => 'No clock',
    '3+2' => '3 min + 2 sec',
    '5+0' => '5 min',
    '10+0' => '10 min',
    '15+10' => '15 min + 10 sec',
    '30+0' => '30 min',
];
const HBHUB_CHESS_DEFAULT_CLOCK = '10+0';
const HBHUB_CHESS_COLOURS = ['random', 'white', 'black'];
const HBHUB_CHESS_ABORT_MOVES = 2; // a game can be aborted (no result) until both players have moved
const HBHUB_CHESS_CLAIM_SECONDS = 60; // a player gone this long can be beaten by the one still there (claim)
const HBHUB_CHESS_PIECE_COUNTS = ['p' => 8, 'n' => 2, 'b' => 2, 'r' => 2, 'q' => 1]; // to work out what's been taken
// Games that ended like this (result.reason) don't count for anyone on the leaderboard (results.php). A game is
// stopped when both players have been gone so long that their seats went (room.php)
const HBHUB_CHESS_UNCOUNTED = ['aborted', 'stopped'];

function hbHubChessNowMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

function hbHubChessNewState(string $clock, string $colour): array
{
    return [
        'phase' => 'lobby',
        'clock' => array_key_exists($clock, HBHUB_CHESS_CLOCKS) ? $clock : HBHUB_CHESS_DEFAULT_CLOCK,
        'colour' => in_array($colour, HBHUB_CHESS_COLOURS, true) ? $colour : 'random',
        'players' => [],
        'games' => 0,
        'note' => null,
        'game' => null,
    ];
}

function hbHubChessNewPlayer(int $seat, int $userId, string $name): array
{
    return ['seat' => $seat, 'userId' => $userId, 'name' => $name];
}

/**
 * The seat the user sits in, or null.
 */
function hbHubChessSeatOfUser(array $state, int $userId): ?int
{
    foreach ($state['players'] as $player) {
        if ($player['userId'] === $userId) {
            return $player['seat'];
        }
    }
    return null;
}

/**
 * The colour the user plays in the game (or played in the last one), or null when they aren't one of its players.
 */
function hbHubChessColourOfUser(array $state, int $userId): ?string
{
    $game = $state['game'];
    return match (true) {
        $game === null => null,
        $game['white']['userId'] === $userId => 'w',
        $game['black']['userId'] === $userId => 'b',
        default => null,
    };
}

/**
 * Starts a game between the two players in the room. Two who just played each other swap colours (a rematch);
 * otherwise the host gets the colour they chose when they opened the room.
 */
function hbHubChessBegin(array &$state, ?int $hostUserId): void
{
    [$first, $second] = $state['players'];
    $last = $state['game'];
    $userIds = [$first['userId'], $second['userId']];
    sort($userIds);
    $lastIds = $last !== null ? [$last['white']['userId'], $last['black']['userId']] : [];
    sort($lastIds);

    if ($userIds === $lastIds) {
        $whiteId = $last['black']['userId'];
    } else {
        $host = $hostUserId === $second['userId'] ? $second : $first;
        $guest = $host === $first ? $second : $first;
        $hostIsWhite = match ($state['colour']) {
            'white' => true,
            'black' => false,
            default => random_int(0, 1) === 1,
        };
        $whiteId = $hostIsWhite ? $host['userId'] : $guest['userId'];
    }
    [$white, $black] = $first['userId'] === $whiteId ? [$first, $second] : [$second, $first];

    $clock = HBHUB_CHESS_CLOCKS[$state['clock']] ?? null;
    $state['game'] = [
        'white' => ['userId' => $white['userId'], 'name' => $white['name']],
        'black' => ['userId' => $black['userId'], 'name' => $black['name']],
        'position' => hbHubChessStartPosition(),
        'moves' => [],
        'repetitions' => [],
        'clocks' => $clock !== null ? ['w' => $clock[0] * 60000, 'b' => $clock[0] * 60000] : null,
        'clockSince' => null,
        'drawOffer' => null,
        'drawOffered' => ['w' => -1, 'b' => -1],
        'result' => null,
        'rematch' => [],
        'recorded' => false,
    ];
    $state['game']['repetitions'][hbHubChessPositionKey($state['game']['position'], [])] = 1;
    $state['phase'] = 'playing';
    $state['games']++;
    $state['note'] = null;
}

/**
 * The time the colour has left in ms right now, counting down the clock of the player to move. Null without a clock.
 */
function hbHubChessTimeLeft(array $game, string $colour, int $now): ?int
{
    if ($game['clocks'] === null) {
        return null;
    }
    $left = $game['clocks'][$colour];
    if ($game['result'] === null && $game['clockSince'] !== null && $game['position']['turn'] === $colour) {
        $left -= $now - $game['clockSince'];
    }
    return max(0, $left);
}

/**
 * Whether the player to move has run out of time, which hbHubChessAdvance() ends the game for.
 */
function hbHubChessTimeUp(array $state, int $now): bool
{
    return $state['phase'] === 'playing' && hbHubChessTimeLeft($state['game'], $state['game']['position']['turn'], $now) === 0;
}

/**
 * Ends the game on time when the player to move has run out: the other player wins, unless all they have left is
 * their king (and maybe one bishop or knight) - then it's a draw. Called on every request that touches the room.
 */
function hbHubChessAdvance(array &$state, int $now): void
{
    if (!hbHubChessTimeUp($state, $now)) {
        return;
    }
    $other = hbHubChessOpponent($state['game']['position']['turn']);
    hbHubChessEnd($state, hbHubChessCanMate($state['game']['position']['board'], $other) ? $other : null, 'time', $now);
}

/**
 * Ends the game: $winner is "w", "b" or null for a draw. The clock of the player to move stops where it is.
 */
function hbHubChessEnd(array &$state, ?string $winner, string $reason, int $now): void
{
    $game = &$state['game'];
    if ($game['clocks'] !== null) {
        $turn = $game['position']['turn'];
        $game['clocks'][$turn] = hbHubChessTimeLeft($game, $turn, $now);
    }
    $game['clockSince'] = null;
    $game['drawOffer'] = null;
    $game['result'] = ['winner' => $winner, 'reason' => $reason];
    $state['phase'] = 'ended';
}

/**
 * The colour plays a move (squares as sent, like "e2" and "e4", and the piece a pawn becomes on the last rank: "q",
 * "r", "b" or "n" - a queen when it's left out). Returns null when it's played, otherwise why not.
 */
function hbHubChessMove(array &$state, string $colour, mixed $from, mixed $to, mixed $promotion, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    $game = &$state['game'];
    $position = $game['position'];
    if ($position['turn'] !== $colour) {
        return 'It\'s not your move.';
    }

    $from = hbHubChessSquare($from);
    $to = hbHubChessSquare($to);
    $promotion = in_array($promotion, HBHUB_CHESS_PROMOTIONS, true) ? $promotion : 'q';
    $legal = hbHubChessLegalMoves($position);
    $move = null;
    foreach ($legal as $candidate) {
        if ($candidate[0] === $from && $candidate[1] === $to && ($candidate[2] === null || $candidate[2] === $promotion)) {
            $move = $candidate;
            break;
        }
    }
    if ($move === null) {
        return 'That move isn\'t allowed.';
    }

    // The clock: the time the move took comes off (White's first move is free, the clocks start after it), and the
    // increment goes on
    if ($game['clocks'] !== null) {
        if ($game['clockSince'] !== null) {
            $game['clocks'][$colour] = hbHubChessTimeLeft($game, $colour, $now) + (HBHUB_CHESS_CLOCKS[$state['clock']][1] ?? 0) * 1000;
        }
        $game['clockSince'] = $now;
    }

    $san = hbHubChessSan($position, $move, $legal);
    $game['position'] = hbHubChessApply($position, $move);
    $game['moves'][] = ['from' => $move[0], 'to' => $move[1], 'promotion' => $move[2], 'san' => $san];
    if ($game['drawOffer'] === hbHubChessOpponent($colour)) {
        $game['drawOffer'] = null; // playing on instead of answering says no
    }

    $next = $game['position'];
    $nextLegal = hbHubChessLegalMoves($next);
    $key = hbHubChessPositionKey($next, $nextLegal);
    $game['repetitions'][$key] = ($game['repetitions'][$key] ?? 0) + 1;
    unset($game);

    if ($nextLegal === [] && hbHubChessInCheck($next)) {
        hbHubChessEnd($state, $colour, 'checkmate', $now);
    } elseif ($nextLegal === []) {
        hbHubChessEnd($state, null, 'stalemate', $now);
    } elseif (hbHubChessInsufficientMaterial($next['board'])) {
        hbHubChessEnd($state, null, 'material', $now);
    } elseif ($state['game']['repetitions'][$key] >= 3) {
        hbHubChessEnd($state, null, 'repetition', $now);
    } elseif ($next['halfmove'] >= 100) {
        hbHubChessEnd($state, null, 'fiftyMoves', $now);
    }
    return null;
}

function hbHubChessResign(array &$state, string $colour, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    if (count($state['game']['moves']) < HBHUB_CHESS_ABORT_MOVES) {
        return 'Nobody has moved yet, so abort the game instead.';
    }
    hbHubChessEnd($state, hbHubChessOpponent($colour), 'resign', $now);
    return null;
}

/**
 * Ends the game without a result, until both players have made a move.
 */
function hbHubChessAbort(array &$state, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    if (count($state['game']['moves']) >= HBHUB_CHESS_ABORT_MOVES) {
        return 'The game can only be aborted until both players have moved. Resign or offer a draw instead.';
    }
    hbHubChessEnd($state, null, 'aborted', $now);
    return null;
}

/**
 * The colour offers a draw: once per move of theirs. When the other player has offered one already, it's agreed.
 */
function hbHubChessOfferDraw(array &$state, string $colour, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    $game = &$state['game'];
    if ($game['drawOffer'] === hbHubChessOpponent($colour)) {
        unset($game);
        hbHubChessEnd($state, null, 'agreement', $now);
        return null;
    }
    if (count($game['moves']) < HBHUB_CHESS_ABORT_MOVES) {
        return 'Nobody has moved yet, so abort the game instead.';
    }
    if ($game['drawOffer'] === $colour) {
        return 'You\'ve offered a draw already.';
    }
    if ($game['drawOffered'][$colour] === count($game['moves'])) {
        return 'Make a move before you offer a draw again.';
    }
    $game['drawOffer'] = $colour;
    $game['drawOffered'][$colour] = count($game['moves']);
    return null;
}

/**
 * The colour answers the other player's draw offer: $answer (as sent) "accept" or "decline".
 */
function hbHubChessAnswerDraw(array &$state, string $colour, mixed $answer, int $now): ?string
{
    if ($state['phase'] !== 'playing' || $state['game']['drawOffer'] !== hbHubChessOpponent($colour)) {
        return 'There\'s no draw offer to answer.';
    }
    if ($answer === 'accept') {
        hbHubChessEnd($state, null, 'agreement', $now);
    } elseif ($answer === 'decline') {
        $state['game']['drawOffer'] = null;
    } else {
        return 'Choose an answer.';
    }
    return null;
}

/**
 * The colour wins because the other player has been gone for HBHUB_CHESS_CLAIM_SECONDS ($otherIdleSeconds is how long
 * since their game last checked in).
 */
function hbHubChessClaim(array &$state, string $colour, int $otherIdleSeconds, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game is over.';
    }
    if ($otherIdleSeconds < HBHUB_CHESS_CLAIM_SECONDS) {
        return 'They haven\'t been gone long enough for that.';
    }
    hbHubChessEnd($state, $colour, 'abandoned', $now);
    return null;
}

/**
 * The colour asks for another game ($wants), or takes that back. Once both want it, it starts, with the colours swapped.
 */
function hbHubChessRematch(array &$state, string $colour, bool $wants, ?int $hostUserId): ?string
{
    if ($state['phase'] !== 'ended' || count($state['players']) < 2) {
        return 'There\'s nobody to play again with.';
    }
    $rematch = array_values(array_diff($state['game']['rematch'], [$colour]));
    if ($wants) {
        $rematch[] = $colour;
    }
    $state['game']['rematch'] = $rematch;
    if (count($rematch) === 2) {
        hbHubChessBegin($state, $hostUserId);
    }
    return null;
}

/**
 * The pieces each colour has lost, the most valuable first: {w: "qrp", b: "nppp"}. A pawn that was promoted counts
 * as a lost pawn (and its new piece as one too many, which is left out).
 *
 * @return array{w: string, b: string}
 */
function hbHubChessCaptured(string $board): array
{
    $captured = ['w' => '', 'b' => ''];
    foreach (array_reverse(HBHUB_CHESS_PIECE_COUNTS) as $type => $count) {
        $captured['w'] .= str_repeat($type, max(0, $count - substr_count($board, strtoupper($type))));
        $captured['b'] .= str_repeat($type, max(0, $count - substr_count($board, $type)));
    }
    return $captured;
}

/**
 * The game as the user sees it, for chessGame.js. $people are the names and pictures by userId (room.php's
 * hbHubChessPeople()), $seats who sits in the room right now (room.php's hbHubChessSeats(), with how long since each
 * one's game checked in).
 */
function hbHubChessView(array $state, array $people, array $seats, int $userId, ?int $hostUserId, int $now): array
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
            // Seconds until the other player can claim the win, while this one is away
            'claimIn' => $seat !== null && $seat['away'] ? max(0, HBHUB_CHESS_CLAIM_SECONDS - $seat['idleSeconds']) : null,
        ];
    };

    $view = [
        'phase' => $state['phase'],
        'colour' => $state['colour'],
        'isHost' => $hostUserId === $userId,
        'note' => $state['note'],
        'seated' => array_map(static fn (array $player): array => $person($player['userId'], $player['name']), $state['players']),
        'you' => hbHubChessColourOfUser($state, $userId),
        'game' => null,
    ];

    $game = $state['game'];
    if ($game === null) {
        return $view;
    }

    $position = $game['position'];
    $isPlaying = $state['phase'] === 'playing';
    $legal = [];
    if ($isPlaying && $view['you'] === $position['turn']) {
        foreach (hbHubChessLegalMoves($position) as [$from, $to]) {
            $legal[hbHubChessSquareName($from)][] = hbHubChessSquareName($to); // a promotion is there 4 times
        }
        $legal = array_map(static fn (array $targets): array => array_values(array_unique($targets)), $legal);
    }
    $lastMove = $game['moves'] !== [] ? $game['moves'][count($game['moves']) - 1] : null;
    $inCheck = hbHubChessInCheck($position);

    $view['game'] = [
        'white' => $person($game['white']['userId'], $game['white']['name']) + ['wantsRematch' => in_array('w', $game['rematch'], true)],
        'black' => $person($game['black']['userId'], $game['black']['name']) + ['wantsRematch' => in_array('b', $game['rematch'], true)],
        'board' => $position['board'],
        'turn' => $position['turn'],
        'moves' => array_column($game['moves'], 'san'),
        'lastMove' => $lastMove !== null ? [hbHubChessSquareName($lastMove['from']), hbHubChessSquareName($lastMove['to'])] : null,
        'check' => $inCheck ? hbHubChessSquareName((int) strpos($position['board'], $position['turn'] === 'w' ? 'K' : 'k')) : null,
        'legal' => (object) $legal,
        'clocks' => $game['clocks'] !== null
            ? ['w' => hbHubChessTimeLeft($game, 'w', $now), 'b' => hbHubChessTimeLeft($game, 'b', $now)]
            : null,
        'running' => $isPlaying && $game['clockSince'] !== null ? $position['turn'] : null,
        'result' => $game['result'],
        'drawOffer' => $game['drawOffer'],
        'canAbort' => $isPlaying && count($game['moves']) < HBHUB_CHESS_ABORT_MOVES,
        'canOfferDraw' => $isPlaying && $view['you'] !== null && count($game['moves']) >= HBHUB_CHESS_ABORT_MOVES
            && $game['drawOffer'] === null && $game['drawOffered'][$view['you']] !== count($game['moves']),
        'ply' => count($game['moves']),
        'captured' => hbHubChessCaptured($position['board']),
    ];
    return $view;
}
