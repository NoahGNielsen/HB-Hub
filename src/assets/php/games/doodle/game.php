<?php
// Doodle Chain's rules: a game of "telephone" with drawings. Pure functions on the room's state (roomState in
// HBHub-DoodleRooms), no database - room.php loads the state, calls these under the room's row lock, and saves it.
// The texts and drawings themselves are rows in HBHub-DoodleEntries (room.php), not in the state.
//
// How a game goes, for N players (the order they sat in when it started, game.order):
// - There are N chains, each with D = floor((N - 1) / 2) drawings, so 2D + 1 steps: write, then draw and guess D times.
//   That's N steps for an odd N and N - 1 for an even one, so a chain always ends with a guess (hbHubDoodleSteps()).
//   In step s, the player at place i in the order works on chain (i + s) % N, so chain c is started by the player at
//   place c and passes every player at most once.
// - Even steps are written, odd steps are drawn: step 0 everyone writes something to draw, step 1 they draw the text
//   they're handed, step 2 they describe the drawing they're handed, and so on.
// - A step ends when everyone still in the room is done, or when its time is up (plus HBHUB_DOODLE_LATE_MS for what the
//   page sends when its timer hits zero). Whoever didn't hand anything in just has nothing in that step: the next
//   player is handed the newest text (to draw) or drawing (to describe) further back in the chain instead.
// - After the last step everyone watches the chains together ('reveal'): the host shows them one entry at a time.
//   Then the host starts a new game, which also deletes the old texts and drawings.
//
// The state:
//   phase: 'lobby' (waiting for players) | 'playing' | 'reveal'
//   players: [{seat, userId, name}] who sits in the room, in seat order
//   settings: {drawSeconds}
//   gameCount: games started in the room (the game's number, gameNumber in HBHub-DoodleEntries)
//   note: a line about the last thing that happened between games ("Alice left."), or null
//   game: null before the first game, otherwise {number, order: [userId], names: [name] (same places as order),
//         step, stepEndsMs, done: [userId] (handed in this step), reveal: null | {chain, shown, finished}}
//
// Drawings are sent and kept as JSON: a list of strokes, each [colour, size, x, y, x, y, ...], with colour and size
// places in HBHUB_DOODLE_COLOURS and HBHUB_DOODLE_SIZES and the points on a HBHUB_DOODLE_WIDTH x HBHUB_DOODLE_HEIGHT
// canvas. A stroke with one point is a dot. doodleGame.js draws and sends them the same way.

const HBHUB_DOODLE_MIN_PLAYERS = 3;
const HBHUB_DOODLE_MAX_PLAYERS = 10; // seatNumber in HBHub-DoodleSeats
const HBHUB_DOODLE_WRITE_SECONDS = 45;
const HBHUB_DOODLE_DRAW_CHOICES = [60, 90, 120]; // the host's choices for the time to draw
const HBHUB_DOODLE_DEFAULT_SETTINGS = ['drawSeconds' => 90];
const HBHUB_DOODLE_LATE_MS = 4000; // a step waits this much past its time for what the pages send at zero
const HBHUB_DOODLE_TEXT_MAX = 100; // entryText in HBHub-DoodleEntries
const HBHUB_DOODLE_KIND_TEXT = 1; // entryKind in HBHub-DoodleEntries
const HBHUB_DOODLE_KIND_DRAWING = 2;
const HBHUB_DOODLE_WIDTH = 800;
const HBHUB_DOODLE_HEIGHT = 600;
const HBHUB_DOODLE_COLOURS = [
    '#000000', '#7f7f7f', '#c3c3c3', '#ffffff', '#88001b', '#ec1c24', '#ff7f27', '#ffca18',
    '#fff200', '#0ed145', '#1c7c3c', '#00a8f3', '#3f48cc', '#8c3fbe', '#ffaec8', '#b97a56',
];
const HBHUB_DOODLE_SIZES = [3, 8, 16, 32]; // line widths, in canvas pixels
const HBHUB_DOODLE_MAX_STROKES = 3000;
const HBHUB_DOODLE_MAX_NUMBERS = 120000; // all the numbers in a drawing together
const HBHUB_DOODLE_MAX_BYTES = 500000; // a drawing's JSON as sent

function hbHubDoodleNowMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

function hbHubDoodleNewState(): array
{
    return [
        'phase' => 'lobby',
        'players' => [],
        'settings' => HBHUB_DOODLE_DEFAULT_SETTINGS,
        'gameCount' => 0,
        'note' => null,
        'game' => null,
    ];
}

function hbHubDoodleNewPlayer(int $seat, int $userId, string $name): array
{
    return ['seat' => $seat, 'userId' => $userId, 'name' => $name];
}

/**
 * The room's settings, with the default for anything missing.
 *
 * @return array{drawSeconds: int}
 */
function hbHubDoodleSettings(array $state): array
{
    $settings = ($state['settings'] ?? []) + HBHUB_DOODLE_DEFAULT_SETTINGS;
    if (!in_array($settings['drawSeconds'], HBHUB_DOODLE_DRAW_CHOICES, true)) {
        $settings['drawSeconds'] = HBHUB_DOODLE_DEFAULT_SETTINGS['drawSeconds'];
    }
    return $settings;
}

/**
 * "90 s to draw", for the lobby list and under the game's name.
 */
function hbHubDoodleSettingsText(array $settings): string
{
    return $settings['drawSeconds'] . ' s to draw';
}

/**
 * The host changes the time to draw, while no game is being played. Returns null when it's changed, otherwise why not.
 */
function hbHubDoodleChangeSettings(array &$state, bool $isHost, mixed $drawSeconds): ?string
{
    if (!$isHost) {
        return 'Only the host can change the settings.';
    }
    if ($state['phase'] === 'playing') {
        return 'The settings can\'t be changed while a game is being played.';
    }
    $seconds = is_string($drawSeconds) && ctype_digit($drawSeconds) ? (int) $drawSeconds : 0;
    if (!in_array($seconds, HBHUB_DOODLE_DRAW_CHOICES, true)) {
        return 'Pick one of the times.';
    }
    $state['settings'] = ['drawSeconds' => $seconds] + hbHubDoodleSettings($state);
    return null;
}

/**
 * The host starts a game with everyone in the room, before the first game or after the last one. Returns null when it
 * started, otherwise why not. room.php deletes the last game's texts and drawings when it has.
 */
function hbHubDoodleStart(array &$state, bool $isHost, int $nowMs): ?string
{
    if (!$isHost) {
        return 'Only the host can start the game.';
    }
    if ($state['phase'] === 'playing') {
        return 'The game has already started.';
    }
    if (count($state['players']) < HBHUB_DOODLE_MIN_PLAYERS) {
        return 'You need at least ' . HBHUB_DOODLE_MIN_PLAYERS . ' players to start.';
    }

    $state['gameCount']++;
    $state['phase'] = 'playing';
    $state['note'] = null;
    $state['game'] = [
        'number' => $state['gameCount'],
        'order' => array_column($state['players'], 'userId'),
        'names' => array_column($state['players'], 'name'),
        'step' => 0,
        'stepEndsMs' => $nowMs + hbHubDoodleStepSeconds($state, 0) * 1000,
        'done' => [],
        'reveal' => null,
    ];
    return null;
}

/**
 * 'write' or 'draw': what's done in the step.
 */
function hbHubDoodleStepKind(int $step): string
{
    return $step % 2 === 0 ? 'write' : 'draw';
}

/**
 * How many drawings each chain has for that many players: floor((players - 1) / 2), so 1 for 3 or 4 players, 2 for 5
 * or 6, 3 for 7 or 8 and so on.
 */
function hbHubDoodleDrawings(int $players): int
{
    return intdiv($players - 1, 2);
}

/**
 * How many steps the game has, so how long each chain is: the first text, then a drawing and a guess per drawing.
 */
function hbHubDoodleSteps(array $game): int
{
    return 2 * hbHubDoodleDrawings(count($game['order'])) + 1;
}

function hbHubDoodleStepSeconds(array $state, int $step): int
{
    return hbHubDoodleStepKind($step) === 'write' ? HBHUB_DOODLE_WRITE_SECONDS : hbHubDoodleSettings($state)['drawSeconds'];
}

/**
 * The chain the user works on in the step being played, or null when they aren't in the game.
 */
function hbHubDoodleChainOf(array $game, int $userId): ?int
{
    $place = array_search($userId, $game['order'], true);
    return $place === false ? null : ($place + $game['step']) % count($game['order']);
}

/**
 * The players in the game who still sit in the room.
 *
 * @param list<int> $seatedIds
 * @return list<int>
 */
function hbHubDoodleActive(array $game, array $seatedIds): array
{
    return array_values(array_intersect($game['order'], $seatedIds));
}

/**
 * Moves the game on: the step being played ends once everyone still in the room has handed something in, or its time
 * (and HBHUB_DOODLE_LATE_MS) is up. After the last step the chains are shown.
 *
 * @param list<int> $seatedIds who sits in the room
 */
function hbHubDoodleAdvance(array &$state, array $seatedIds, int $nowMs): void
{
    if ($state['phase'] !== 'playing') {
        return;
    }
    $game = &$state['game'];
    $active = hbHubDoodleActive($game, $seatedIds);
    $isEveryoneDone = array_diff($active, $game['done']) === [];
    if (!$isEveryoneDone && $nowMs < $game['stepEndsMs'] + HBHUB_DOODLE_LATE_MS) {
        return;
    }

    $game['step']++;
    $game['done'] = [];
    if ($game['step'] >= hbHubDoodleSteps($game)) {
        $state['phase'] = 'reveal';
        $game['reveal'] = ['chain' => 0, 'shown' => 1, 'finished' => false];
        return;
    }
    $game['stepEndsMs'] = $nowMs + hbHubDoodleStepSeconds($state, $game['step']) * 1000;
}

/**
 * Whether the step being played is over by the clock, so just looking at the game has to move it on.
 */
function hbHubDoodleTimeUp(array $state, int $nowMs): bool
{
    return $state['phase'] === 'playing' && $nowMs >= $state['game']['stepEndsMs'] + HBHUB_DOODLE_LATE_MS;
}

/**
 * What the user hands in now: [chain, step, kind], or why they can't (a string).
 *
 * @return array{0: int, 1: int, 2: string}|string
 */
function hbHubDoodleTurnOf(array $state, int $userId): array|string
{
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    $game = $state['game'];
    $chain = hbHubDoodleChainOf($game, $userId);
    if ($chain === null) {
        return 'You aren\'t in this game. You can play in the next one.';
    }
    if (in_array($userId, $game['done'], true)) {
        return 'You\'ve already handed this one in.';
    }
    return [$chain, $game['step'], hbHubDoodleStepKind($game['step'])];
}

/**
 * A text as typed in: on one line, trimmed and cut to fit. '' when there's nothing (handing in nothing is allowed - it's
 * what the page sends when the time runs out on an empty box).
 */
function hbHubDoodleCleanText(mixed $text): string
{
    if (!is_string($text)) {
        return '';
    }
    $text = trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $text) ?? '');
    return mb_substr($text, 0, HBHUB_DOODLE_TEXT_MAX);
}

/**
 * A drawing as sent by doodleGame.js (see the top of this file), checked and written again the same way, so only plain
 * numbers in range are ever stored. '' for an empty drawing, null when it isn't a drawing at all (or too big).
 */
function hbHubDoodleCleanDrawing(mixed $drawing): ?string
{
    if (!is_string($drawing) || strlen($drawing) > HBHUB_DOODLE_MAX_BYTES) {
        return null;
    }
    try {
        $strokes = json_decode($drawing, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($strokes) || !array_is_list($strokes) || count($strokes) > HBHUB_DOODLE_MAX_STROKES) {
        return null;
    }

    $numbers = 0;
    $clean = [];
    foreach ($strokes as $stroke) {
        if (!is_array($stroke) || !array_is_list($stroke) || count($stroke) < 4 || count($stroke) % 2 !== 0) {
            return null;
        }
        $numbers += count($stroke);
        if ($numbers > HBHUB_DOODLE_MAX_NUMBERS) {
            return null;
        }
        foreach ($stroke as $index => $number) {
            $max = match (true) {
                $index === 0 => count(HBHUB_DOODLE_COLOURS) - 1,
                $index === 1 => count(HBHUB_DOODLE_SIZES) - 1,
                $index % 2 === 0 => HBHUB_DOODLE_WIDTH,
                default => HBHUB_DOODLE_HEIGHT,
            };
            if (!is_int($number) || $number < 0 || $number > $max) {
                return null;
            }
        }
        $clean[] = $stroke;
    }
    return $clean === [] ? '' : json_encode($clean, JSON_THROW_ON_ERROR);
}

/**
 * Notes that the user has handed in their part of the step.
 */
function hbHubDoodleMarkDone(array &$state, int $userId): void
{
    $state['game']['done'][] = $userId;
}

/**
 * The host shows the next entry of the chain being shown, or the start of the next chain. After the last one the
 * reveal is finished, and any chain can be shown whole (hbHubDoodleShowChain()).
 */
function hbHubDoodleRevealNext(array &$state, bool $isHost): ?string
{
    if (!$isHost) {
        return 'Only the host can show the next one.';
    }
    if ($state['phase'] !== 'reveal') {
        return 'There\'s nothing to show right now.';
    }
    $reveal = &$state['game']['reveal'];
    $steps = hbHubDoodleSteps($state['game']);
    if ($reveal['finished']) {
        return null;
    }
    if ($reveal['shown'] < $steps) {
        $reveal['shown']++;
    } elseif ($reveal['chain'] < count($state['game']['order']) - 1) {
        $reveal['chain']++;
        $reveal['shown'] = 1;
    } else {
        $reveal['finished'] = true;
    }
    return null;
}

/**
 * Once every chain has been shown, the host can show any of them again, whole.
 */
function hbHubDoodleShowChain(array &$state, bool $isHost, mixed $chain): ?string
{
    if (!$isHost) {
        return 'Only the host can pick what\'s shown.';
    }
    if ($state['phase'] !== 'reveal' || !$state['game']['reveal']['finished']) {
        return 'Wait until every chain has been shown.';
    }
    $chain = is_string($chain) && ctype_digit($chain) ? (int) $chain : -1;
    if ($chain < 0 || $chain >= count($state['game']['order'])) {
        return 'That chain isn\'t there.';
    }
    $state['game']['reveal']['chain'] = $chain;
    $state['game']['reveal']['shown'] = hbHubDoodleSteps($state['game']);
    return null;
}
