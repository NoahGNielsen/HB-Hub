<?php
// Tetris's games, kept on the server so the leaderboard can trust them. Tetris is HB Hub's falling-block puzzle
// (the genre, with its own name and look), built on the ideas in larprad's tetris on GitHub (credited on the credits
// page): a 10x20 well, the seven four-square pieces and a hold slot. You go on until you top out, faster every 10 lines.
//
// Like Snake, the game runs in the browser (assets/js/blockfall.js, it has to answer a key press straight away), and
// the server plays every game along with it on its own copy of the well. The score, lines and time on the leaderboard
// are the server's own, never the numbers the page sends. So THE RULES ARE IN BOTH FILES (the "Rules" part below and
// in blockfall.js) and have to give exactly the same game: a rule change goes in both, or every game ends as "bad
// moves". There are no floats anywhere in them, only whole numbers, so both sides always agree.
//
// The rules, as both sides play them:
// - The game moves on in ticks of HBHUB_BLOCKFALL_TICK_MS (10 ms). On each tick, the inputs pressed on it are made
//   first, in order, and then the tick itself (advance()): gravity, then the lock delay, then the clock.
// - The well is 10 wide and 22 high, the top 2 rows hidden (pieces come in up there). A piece comes in at column 3,
//   row 2 (the top of the visible well), turned the way HBHUB_BLOCKFALL_SHAPES has it, and the game is over when it doesn't fit there, or when a
//   piece locks without a single square below the hidden rows (and no lines cleared).
// - The pieces come in bags of seven: each bag has every piece once, shuffled with the game's secret (hbHubBlockfallBag()).
// - Gravity drops the piece a row every HBHUB_BLOCKFALL_GRAVITY[speed - 1] ticks (the speeds from larprad's game, in
//   ticks). The speed is HBHUB_BLOCKFALL_START_SPEED plus the level - 1, at most 15, and the level goes up every
//   HBHUB_BLOCKFALL_LINES_PER_LEVEL lines.
// - A piece that can't fall any further locks after HBHUB_BLOCKFALL_LOCK_TICKS ticks there. Moving or turning it while
//   it rests starts that over again, up to HBHUB_BLOCKFALL_MAX_RESETS times, until it reaches a row lower than it has
//   been before (which gives it all its resets back).
// - Turning tries the piece where it is first, then each of HBHUB_BLOCKFALL_KICKS in turn (one or two squares to the
//   side, or one up), and takes the first that fits. The O piece doesn't turn.
// - Inputs (one letter each): L / R move a square, D soft drops a row (1 point), F hard drops (2 points a row, and locks
//   at once), + turns clockwise, - anticlockwise, H holds (swaps with the hold slot, once per piece).
// - Lines: 100, 300, 500 or 800 points for 1-4 at once, times the level the piece locked on.
//
// How a game goes (games/tetris/move, the JSON):
// - The Tetris page makes the next game when it's opened (hbHubBlockfallNew()), and the answer to each finished game
//   makes the one after it: a row in HBHub-BlockfallGames with a secret (gameSecret) that decides the pieces. The page
//   gets the first HBHUB_BLOCKFALL_PIECES_AHEAD of them.
// - When the first piece comes in, the page says so (hbHubBlockfallStart()), which starts the server's
//   clock and puts the first piece in its well.
// - While it's played, the page reports (hbHubBlockfallReport()): how many ticks it has played, and the inputs since the
//   last report ("120L,120+,131F": on tick 120 it moved left and turned, on 131 it hard dropped). It does that every
//   few seconds, sooner when it's running low on pieces, when it's paused or carries on, and once more when it's over.
//   The server plays the same ticks on its copy (gameState), and its answer brings the next pieces.
//
// Anticheat: what the page could fake, and what stops it (the same as Snake's, see snake.php).
// - The score, lines and time: the server only counts what its own copy does, and a game its copy can't end the same
//   way (or that it ends earlier) ends as "bad moves".
// - The pieces: the page only ever knows the next HBHUB_BLOCKFALL_PIECES_AHEAD, so a game can't be played through at
//   its own pace before the clock starts.
// - The speed: every report checks the ticks against the server's clock without the pauses, with the same leeway as
//   Snake. A slowed down game (more time to think) or a sped up one ends as "out of time". It's checked
//   before the ticks are played, so a report of a huge number of ticks can't keep the server busy.
// - Pauses: timed by the server, at most HBHUB_BLOCKFALL_MAX_PAUSES of them.
// - Bots: a key press or a tap on the buttons made by code isn't "trusted" in the browser, so the page ignores it and
//   ends the game as "scripted" (hbHubBlockfallScripted()).
// As with Snake, nothing can stop a rewritten page that plays in real time, as the server can't see who presses the keys.
//
// Only started games count as played; making the next one deletes a game never started and ends one still going (as
// left). gameState and gameSecret are emptied once a game is over.
// Used by games/tetris/move, games/tetris/index (the next game, and the bests) and games/tetris/leaderboard.
require_once __DIR__ . '/../../database.php';

// The rules (as the constants at the top of assets/js/blockfall.js) -------------------------------------------------

const HBHUB_BLOCKFALL_COLS = 10;
const HBHUB_BLOCKFALL_ROWS = 22; // the top HBHUB_BLOCKFALL_HIDDEN of them hidden, where the pieces come in
const HBHUB_BLOCKFALL_HIDDEN = 2;
const HBHUB_BLOCKFALL_TICK_MS = 10;
const HBHUB_BLOCKFALL_SPAWN_X = 3;
const HBHUB_BLOCKFALL_SPAWN_Y = 2; // the top of the visible well, so a new piece is always all in view
const HBHUB_BLOCKFALL_LOCK_TICKS = 50; // half a second
const HBHUB_BLOCKFALL_MAX_RESETS = 15;
const HBHUB_BLOCKFALL_GRAVITY = [236, 146, 92, 59, 39, 26, 18, 12, 9, 6, 5, 4, 3, 2, 2]; // ticks a row at speed 1-15
const HBHUB_BLOCKFALL_LINE_POINTS = [0, 100, 300, 500, 800];
const HBHUB_BLOCKFALL_KICKS = [[0, 0], [-1, 0], [1, 0], [0, -1], [-1, -1], [1, -1], [-2, 0], [2, 0]]; // [x, y], y down

// Each piece's squares the way it comes in, [x, y] in its box (4x4 for I and O, 3x3 for the rest). Turned clockwise
// by (x, y) => (size - 1 - y, x), see hbHubBlockfallCells()
const HBHUB_BLOCKFALL_SHAPES = [
    'I' => [[0, 1], [1, 1], [2, 1], [3, 1]],
    'O' => [[1, 0], [2, 0], [1, 1], [2, 1]],
    'T' => [[1, 0], [0, 1], [1, 1], [2, 1]],
    'S' => [[1, 0], [2, 0], [0, 1], [1, 1]],
    'Z' => [[0, 0], [1, 0], [1, 1], [2, 1]],
    'J' => [[0, 0], [0, 1], [1, 1], [2, 1]],
    'L' => [[2, 0], [0, 1], [1, 1], [2, 1]],
];
const HBHUB_BLOCKFALL_BAG = 'IOTSZJL'; // the order a bag is shuffled from

const HBHUB_BLOCKFALL_START_SPEED = 3; // the speed a game starts at
const HBHUB_BLOCKFALL_LINES_PER_LEVEL = 10;

// The server's side ---------------------------------------------------------------------------------------------------

const HBHUB_BLOCKFALL_PLAYING = 0; // gameResult in HBHub-BlockfallGames (ready to start too, while gameStartTimestamp is NULL)
const HBHUB_BLOCKFALL_FINISHED = 1; // topped out: counts
// gameMode in HBHub-BlockfallGames. There's only the one mode now: the games of the old Rush (2) and Sprint (3) modes
// are kept, but left out of the leaderboard and the bests
const HBHUB_BLOCKFALL_MODE_ENDURO = 1;
const HBHUB_BLOCKFALL_LEFT = 2; // another game was started first
const HBHUB_BLOCKFALL_SCRIPTED = 3; // a key press or tap came from code
const HBHUB_BLOCKFALL_OUT_OF_TIME = 4; // the ticks didn't keep time with the server's clock
const HBHUB_BLOCKFALL_BAD_MOVES = 5; // the server's copy couldn't play the same game
const HBHUB_BLOCKFALL_TOO_MANY_PAUSES = 6; // paused more than HBHUB_BLOCKFALL_MAX_PAUSES times

const HBHUB_BLOCKFALL_PIECES_AHEAD = 35; // five bags: how far ahead the page knows the pieces (it can't wait for one)
const HBHUB_BLOCKFALL_MAX_PAUSES = 10; // as MAX_PAUSES in blockfall.js
const HBHUB_BLOCKFALL_AHEAD_MS = 2000; // the anticheat's leeway, as Snake's
const HBHUB_BLOCKFALL_BEHIND_MS = 3000;
const HBHUB_BLOCKFALL_BEHIND_SHARE = 0.1; // of the time played so far
const HBHUB_BLOCKFALL_MAX_TICKS = 100000000; // more than anyone plays (11 days), so a number can't overflow
const HBHUB_BLOCKFALL_MAX_INPUTS = 5000; // inputs in one report

// The ms from the start to now, or to the start of the pause it's in (as Snake's)
const HBHUB_BLOCKFALL_ELAPSED_SQL = 'TIMESTAMPDIFF(MICROSECOND, gameStartTimestamp, COALESCE(gamePausedTimestamp, UTC_TIMESTAMP(3))) DIV 1000 AS elapsedMs';

// The pieces ------------------------------------------------------------------------------------------------------

/**
 * A number from 0 to 2^28 - 1 from the game's secret, for shuffling the bags.
 */
function hbHubBlockfallRoll(string $secret, int $index): int
{
    return hexdec(substr(hash('sha256', $secret . ':' . $index), 0, 7));
}

/**
 * Bag number $bag (0 first): the seven pieces, shuffled (Fisher-Yates from the back, with the secret's rolls).
 */
function hbHubBlockfallBag(string $secret, int $bag): string
{
    $order = HBHUB_BLOCKFALL_BAG;
    for ($i = 6; $i > 0; $i--) {
        $j = hbHubBlockfallRoll($secret, $bag * 7 + $i) % ($i + 1);
        $swap = $order[$i];
        $order[$i] = $order[$j];
        $order[$j] = $swap;
    }
    return $order;
}

/**
 * The pieces from number $from up to (not including) $to, as the JSON sends them: {"piecesFrom": $from, "pieces": "TIS..."}.
 */
function hbHubBlockfallPieces(string $secret, int $from, int $to): array
{
    $pieces = '';
    for ($bag = intdiv($from, 7); $bag * 7 < $to; $bag++) {
        $pieces .= hbHubBlockfallBag($secret, $bag);
    }
    return ['piecesFrom' => $from, 'pieces' => substr($pieces, $from % 7, max(0, $to - $from))];
}

/**
 * Piece number $index of the game (0 is the first), one bag at a time.
 */
function hbHubBlockfallPiece(string $secret, int $index): string
{
    static $bags = [];
    $key = $secret . ':' . intdiv($index, 7);
    $bags[$key] ??= hbHubBlockfallBag($secret, intdiv($index, 7));
    return $bags[$key][$index % 7];
}

// The rules (the same as the "Rules" part of blockfall.js, function by function) -------------------------------------

/**
 * The squares of $piece turned $rot times clockwise, [x, y] in its box.
 *
 * @return array<int, array{int, int}>
 */
function hbHubBlockfallCells(string $piece, int $rot): array
{
    $cells = HBHUB_BLOCKFALL_SHAPES[$piece];
    if ($piece === 'O') {
        return $cells;
    }
    $size = $piece === 'I' ? 4 : 3;
    for ($turn = 0; $turn < $rot; $turn++) {
        $cells = array_map(static fn(array $cell): array => [$size - 1 - $cell[1], $cell[0]], $cells);
    }
    return $cells;
}

/**
 * Whether $piece, turned $rot times, fits in the well at ($x, $y): inside it, and only on empty squares.
 */
function hbHubBlockfallFits(array $state, string $piece, int $rot, int $x, int $y): bool
{
    foreach (hbHubBlockfallCells($piece, $rot) as [$cx, $cy]) {
        $col = $x + $cx;
        $row = $y + $cy;
        if ($col < 0 || $col >= HBHUB_BLOCKFALL_COLS || $row < 0 || $row >= HBHUB_BLOCKFALL_ROWS
            || $state['board'][$row * HBHUB_BLOCKFALL_COLS + $col] !== '.') {
            return false;
        }
    }
    return true;
}

// Whether the piece in play rests on something (can't fall any further)
function hbHubBlockfallResting(array $state): bool
{
    return !hbHubBlockfallFits($state, $state['piece'], $state['rot'], $state['x'], $state['y'] + 1);
}

function hbHubBlockfallLevel(array $state): int
{
    return 1 + intdiv($state['lines'], HBHUB_BLOCKFALL_LINES_PER_LEVEL);
}

// The ticks a row at the level the game's on
function hbHubBlockfallGravity(array $state): int
{
    return HBHUB_BLOCKFALL_GRAVITY[min(HBHUB_BLOCKFALL_START_SPEED + hbHubBlockfallLevel($state) - 1, count(HBHUB_BLOCKFALL_GRAVITY)) - 1];
}

/**
 * $piece comes in at the top. The game is over when it doesn't fit there.
 */
function hbHubBlockfallSpawn(array &$state, string $piece): void
{
    $state['piece'] = $piece;
    $state['rot'] = 0;
    $state['x'] = HBHUB_BLOCKFALL_SPAWN_X;
    $state['y'] = HBHUB_BLOCKFALL_SPAWN_Y;
    $state['gravity'] = 0;
    $state['lock'] = 0;
    $state['resets'] = 0;
    $state['lowest'] = HBHUB_BLOCKFALL_SPAWN_Y;
    $state['canHold'] = true;
    if (!hbHubBlockfallFits($state, $piece, 0, $state['x'], $state['y'])) {
        $state['over'] = 'top';
    }
}

// The next piece from the bags comes in
function hbHubBlockfallDraw(array &$state, string $secret): void
{
    $piece = hbHubBlockfallPiece($secret, $state['drawn']);
    $state['drawn']++;
    hbHubBlockfallSpawn($state, $piece);
}

/**
 * Moves the piece down a row if it can. A row lower than it has been gives it all its lock resets back.
 */
function hbHubBlockfallDown(array &$state): bool
{
    if (hbHubBlockfallResting($state)) {
        return false;
    }
    $state['y']++;
    if ($state['y'] > $state['lowest']) {
        $state['lowest'] = $state['y'];
        $state['resets'] = 0;
    }
    return true;
}

// After the piece has moved or turned: when it's resting, the lock delay starts over (while it has resets left)
function hbHubBlockfallMoved(array &$state): void
{
    if (hbHubBlockfallResting($state) && $state['resets'] < HBHUB_BLOCKFALL_MAX_RESETS) {
        $state['lock'] = 0;
        $state['resets']++;
    }
}

/**
 * The piece locks where it is: full lines are cleared and scored, and the next piece comes in (unless that ends the game).
 */
function hbHubBlockfallLock(array &$state, string $secret): void
{
    $hiddenOnly = true;
    foreach (hbHubBlockfallCells($state['piece'], $state['rot']) as [$cx, $cy]) {
        $row = $state['y'] + $cy;
        $state['board'][$row * HBHUB_BLOCKFALL_COLS + $state['x'] + $cx] = $state['piece'];
        $hiddenOnly = $hiddenOnly && $row < HBHUB_BLOCKFALL_HIDDEN;
    }

    $kept = [];
    $cleared = 0;
    foreach (str_split($state['board'], HBHUB_BLOCKFALL_COLS) as $row) {
        if (str_contains($row, '.')) {
            $kept[] = $row;
        } else {
            $cleared++;
        }
    }
    if ($cleared > 0) {
        $state['board'] = str_repeat('.', HBHUB_BLOCKFALL_COLS * $cleared) . implode('', $kept);
        $state['score'] += HBHUB_BLOCKFALL_LINE_POINTS[$cleared] * hbHubBlockfallLevel($state);
        $state['lines'] += $cleared;
    }

    if ($cleared === 0 && $hiddenOnly) {
        $state['over'] = 'top';
    } else {
        hbHubBlockfallDraw($state, $secret);
    }
}

/**
 * One input (see the top), on the piece in play. Nothing happens once the game is over.
 */
function hbHubBlockfallInput(array &$state, string $input, string $secret): void
{
    if ($state['over'] !== '') {
        return;
    }
    switch ($input) {
        case 'L':
        case 'R':
            $dx = $input === 'L' ? -1 : 1;
            if (hbHubBlockfallFits($state, $state['piece'], $state['rot'], $state['x'] + $dx, $state['y'])) {
                $state['x'] += $dx;
                hbHubBlockfallMoved($state);
            }
            break;
        case '+':
        case '-':
            if ($state['piece'] === 'O') {
                break;
            }
            $rot = ($state['rot'] + ($input === '+' ? 1 : 3)) % 4;
            foreach (HBHUB_BLOCKFALL_KICKS as [$dx, $dy]) {
                if (hbHubBlockfallFits($state, $state['piece'], $rot, $state['x'] + $dx, $state['y'] + $dy)) {
                    $state['rot'] = $rot;
                    $state['x'] += $dx;
                    $state['y'] += $dy;
                    if ($state['y'] > $state['lowest']) {
                        $state['lowest'] = $state['y'];
                        $state['resets'] = 0;
                    }
                    hbHubBlockfallMoved($state);
                    break;
                }
            }
            break;
        case 'D':
            if (hbHubBlockfallDown($state)) {
                $state['score'] += 1;
                $state['gravity'] = 0;
            }
            break;
        case 'F':
            $rows = 0;
            while (hbHubBlockfallDown($state)) {
                $rows++;
            }
            $state['score'] += 2 * $rows;
            hbHubBlockfallLock($state, $secret);
            break;
        case 'H':
            if (!$state['canHold']) {
                break;
            }
            if ($state['hold'] === '') {
                $state['hold'] = $state['piece'];
                hbHubBlockfallDraw($state, $secret);
            } else {
                $held = $state['hold'];
                $state['hold'] = $state['piece'];
                hbHubBlockfallSpawn($state, $held);
            }
            $state['canHold'] = false;
            break;
    }
}

/**
 * One tick: gravity, the lock delay, then the clock.
 */
function hbHubBlockfallAdvance(array &$state, string $secret): void
{
    if ($state['over'] !== '') {
        return;
    }
    $state['gravity']++;
    if ($state['gravity'] >= hbHubBlockfallGravity($state)) {
        $state['gravity'] = 0;
        hbHubBlockfallDown($state);
    }
    if (hbHubBlockfallResting($state)) {
        $state['lock']++;
        if ($state['lock'] >= HBHUB_BLOCKFALL_LOCK_TICKS) {
            hbHubBlockfallLock($state, $secret);
        }
    } else {
        $state['lock'] = 0;
    }
    $state['ticks']++;
}

/**
 * A new game's well, empty, with the first piece in it.
 */
function hbHubBlockfallFresh(string $secret): array
{
    $state = [
        'board' => str_repeat('.', HBHUB_BLOCKFALL_COLS * HBHUB_BLOCKFALL_ROWS),
        'piece' => '', 'rot' => 0, 'x' => 0, 'y' => 0, 'hold' => '', 'canHold' => true, 'drawn' => 0,
        'gravity' => 0, 'lock' => 0, 'resets' => 0, 'lowest' => 0, 'ticks' => 0, 'score' => 0, 'lines' => 0, 'over' => '',
    ];
    hbHubBlockfallDraw($state, $secret);
    return $state;
}

// The games ---------------------------------------------------------------------------------------------------------

/**
 * The inputs a report sends ("120L,120+,131F", or empty) as [tick => [inputs in order]], or null when they aren't
 * inputs: not in that shape, ticks going back, too many, or outside $fromTick..$toTick.
 *
 * @return array<int, array<int, string>>|null
 */
function hbHubBlockfallInputs(mixed $inputs, int $fromTick, int $toTick): ?array
{
    if (!is_string($inputs)) {
        return null;
    }
    if ($inputs === '') {
        return [];
    }
    $parts = explode(',', $inputs);
    if (count($parts) > HBHUB_BLOCKFALL_MAX_INPUTS) {
        return null;
    }
    $list = [];
    $last = $fromTick;
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,9})([LRDF+\-H])$/', $part, $match)) {
            return null;
        }
        $tick = (int) $match[1];
        if ($tick < $last || $tick > $toTick) {
            return null;
        }
        $last = $tick;
        $list[$tick][] = $match[2];
    }
    return $list;
}

/**
 * Whether $ticks fit the time it has been played (without its pauses), within the anticheat's leeway.
 */
function hbHubBlockfallOnTime(int $ticks, int $playedMs): bool
{
    $expected = $ticks * HBHUB_BLOCKFALL_TICK_MS;
    return $expected - $playedMs <= HBHUB_BLOCKFALL_AHEAD_MS
        && $playedMs - $expected <= HBHUB_BLOCKFALL_BEHIND_MS + HBHUB_BLOCKFALL_BEHIND_SHARE * $playedMs;
}

/**
 * Makes the user's next game, ready to start, and ends the one they're still playing (as left; one never started is
 * deleted instead). Returns ['game' => id, 'piecesFrom' => 0, 'pieces' => the first pieces].
 */
function hbHubBlockfallNew(PDO $db, int $userId): array
{
    $secret = bin2hex(random_bytes(16));
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM `HBHub-BlockfallGames` WHERE userId = ? AND gameResult = ? AND gameStartTimestamp IS NULL')
            ->execute([$userId, HBHUB_BLOCKFALL_PLAYING]);
        $db->prepare('UPDATE `HBHub-BlockfallGames` SET gameResult = ?, gameState = \'\', gameSecret = \'\', gamePausedTimestamp = NULL,
                gameEndTimestamp = UTC_TIMESTAMP(3)
            WHERE userId = ? AND gameResult = ?')
            ->execute([HBHUB_BLOCKFALL_LEFT, $userId, HBHUB_BLOCKFALL_PLAYING]);
        $db->prepare('INSERT INTO `HBHub-BlockfallGames` (userId, gameSecret, gameState, gameResult, gameCreatedTimestamp)
            VALUES (?, ?, \'\', ?, UTC_TIMESTAMP(3))')
            ->execute([$userId, $secret, HBHUB_BLOCKFALL_PLAYING]);
        $gameId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId] + hbHubBlockfallPieces($secret, 0, HBHUB_BLOCKFALL_PIECES_AHEAD);
}

/**
 * Starts the clock on the user's game $gameId, with the first piece in its well. Returns ['game' => id], or 'notFound'
 * (not the user's game) or 'gameOver' (started already, or over, like when another tab made a new one).
 */
function hbHubBlockfallStart(PDO $db, int $userId, int $gameId): array|string
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT gameSecret, gameResult, gameStartTimestamp IS NOT NULL AS isStarted
            FROM `HBHub-BlockfallGames` WHERE gameId = ? AND userId = ? FOR UPDATE');
        $statement->execute([$gameId, $userId]);
        $game = $statement->fetch();
        if ($game === false || (int) $game['gameResult'] !== HBHUB_BLOCKFALL_PLAYING || $game['isStarted']) {
            $db->rollBack();
            return $game === false ? 'notFound' : 'gameOver';
        }
        $state = hbHubBlockfallFresh($game['gameSecret']);
        $db->prepare('UPDATE `HBHub-BlockfallGames` SET gameMode = ?, gameState = ?, gamePieces = ?, gameStartTimestamp = UTC_TIMESTAMP(3)
            WHERE gameId = ?')
            ->execute([HBHUB_BLOCKFALL_MODE_ENDURO, json_encode($state), $state['drawn'], $gameId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId];
}

/**
 * The page's report on the user's game $gameId: it has played $ticks ticks in all, with $inputs since the last report
 * (see hbHubBlockfallInputs()). The server plays the same ticks and checks them against its clock (the anticheat at
 * the top), then does what $action says:
 * - 'tick': nothing more, it's keeping up (and getting the next pieces).
 * - 'pause' / 'resume': stops or starts the clock. One pause too many ends the game.
 * - 'finish': the game is over on the page, so it has to be over on the server's copy too, at the same tick.
 * Returns ['game' => id, 'result' => HBHUB_BLOCKFALL_*, 'score', 'lines', 'ticks' (the server's)] and, while it's still
 * being played, the pieces the page doesn't have yet ('piecesFrom', 'pieces'). A finished game also has 'best' (the
 * user's best score, this one included) and 'isBest'.
 * Errors: 'badRequest', 'notFound' and 'gameOver', as Snake's.
 */
function hbHubBlockfallReport(PDO $db, int $userId, int $gameId, mixed $action, mixed $ticks, mixed $inputs): array|string
{
    $ticks = filter_var($ticks, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => HBHUB_BLOCKFALL_MAX_TICKS]]);
    if (!in_array($action, ['tick', 'pause', 'resume', 'finish'], true) || $ticks === false) {
        return 'badRequest';
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT gameSecret, gameState, gamePauses, gamePausedMs,
                gamePausedTimestamp IS NOT NULL AS isPaused, gameResult, gameStartTimestamp IS NOT NULL AS isStarted,
                ' . HBHUB_BLOCKFALL_ELAPSED_SQL . '
            FROM `HBHub-BlockfallGames` WHERE gameId = ? AND userId = ? FOR UPDATE');
        $statement->execute([$gameId, $userId]);
        $game = $statement->fetch();
        if ($game === false || (int) $game['gameResult'] !== HBHUB_BLOCKFALL_PLAYING || !$game['isStarted']) {
            $db->rollBack();
            return $game === false ? 'notFound' : 'gameOver';
        }

        $secret = $game['gameSecret'];
        $state = json_decode($game['gameState'], true);
        $drawnBefore = $state['drawn'];
        $playedMs = max(0, (int) $game['elapsedMs'] - (int) $game['gamePausedMs']);

        // The same ticks on the server's copy, with the inputs where the page made them. The clock is checked first, so
        // a made-up number of ticks is never played.
        $result = HBHUB_BLOCKFALL_PLAYING;
        $inputList = hbHubBlockfallInputs($inputs, $state['ticks'], $ticks);
        if ($inputList === null || $ticks < $state['ticks']) {
            $result = HBHUB_BLOCKFALL_BAD_MOVES;
        } elseif (!hbHubBlockfallOnTime($ticks, $playedMs)) {
            $result = HBHUB_BLOCKFALL_OUT_OF_TIME;
        } else {
            while ($state['over'] === '' && $state['ticks'] <= $ticks) {
                foreach ($inputList[$state['ticks']] ?? [] as $input) {
                    hbHubBlockfallInput($state, $input, $secret);
                }
                if ($state['ticks'] === $ticks) {
                    break; // the inputs on the tick the page is on are made, the tick itself isn't yet
                }
                hbHubBlockfallAdvance($state, $secret);
            }
            // Over on the server's copy: it has to be at the tick the page got to, and the page has to say it's over
            // (a 'tick' sent just as it ended is fine, the 'finish' is on its way). Over on the page but not here: bad
            if ($state['over'] !== '') {
                $result = $state['ticks'] === $ticks ? HBHUB_BLOCKFALL_FINISHED : HBHUB_BLOCKFALL_BAD_MOVES;
            } elseif ($action === 'finish') {
                $result = HBHUB_BLOCKFALL_BAD_MOVES;
            }
        }

        $pauses = (int) $game['gamePauses'];
        if ($result === HBHUB_BLOCKFALL_PLAYING && $action === 'pause' && !$game['isPaused']) {
            $pauses++;
            if ($pauses > HBHUB_BLOCKFALL_MAX_PAUSES) {
                $result = HBHUB_BLOCKFALL_TOO_MANY_PAUSES;
            }
        }

        if ($result === HBHUB_BLOCKFALL_PLAYING) {
            $db->prepare('UPDATE `HBHub-BlockfallGames` SET gameState = ?, gameTicks = ?, gameScore = ?, gameLines = ?, gamePieces = ?,
                    gamePauses = ?,
                    gamePausedMs = IF(? = \'resume\' AND gamePausedTimestamp IS NOT NULL,
                        gamePausedMs + GREATEST(0, TIMESTAMPDIFF(MICROSECOND, gamePausedTimestamp, UTC_TIMESTAMP(3)) DIV 1000), gamePausedMs),
                    gamePausedTimestamp = CASE ? WHEN \'pause\' THEN COALESCE(gamePausedTimestamp, UTC_TIMESTAMP(3))
                        WHEN \'resume\' THEN NULL ELSE gamePausedTimestamp END
                WHERE gameId = ?')
                ->execute([json_encode($state), $state['ticks'], $state['score'], $state['lines'], $state['drawn'], $pauses,
                    $action, $action, $gameId]);
        } else {
            // Over: only the result, the numbers and the time are kept. A game that doesn't count keeps what the server
            // got to, for the admins, but the leaderboard leaves it out.
            $db->prepare('UPDATE `HBHub-BlockfallGames` SET gameResult = ?, gameState = \'\', gameSecret = \'\', gameTicks = ?, gameScore = ?,
                    gameLines = ?, gamePieces = ?, gamePauses = ?, gameMs = ?, gamePausedTimestamp = NULL, gameEndTimestamp = UTC_TIMESTAMP(3)
                WHERE gameId = ?')
                ->execute([$result, $state['ticks'], $state['score'], $state['lines'], $state['drawn'], $pauses, $playedMs, $gameId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $view = ['game' => $gameId, 'result' => $result, 'score' => $state['score'], 'lines' => $state['lines'], 'ticks' => $state['ticks']];
    if ($result === HBHUB_BLOCKFALL_PLAYING) {
        $view += hbHubBlockfallPieces($secret, $drawnBefore + HBHUB_BLOCKFALL_PIECES_AHEAD, $state['drawn'] + HBHUB_BLOCKFALL_PIECES_AHEAD);
    }
    if ($result === HBHUB_BLOCKFALL_FINISHED) {
        $view['best'] = hbHubBlockfallBest($db, $userId);
        $view['isBest'] = hbHubBlockfallIsBest($db, $userId, $gameId, $state, $view['best']);
    }
    return $view;
}

/**
 * Whether the finished game $gameId is the user's new best: it's their best now, and no game before it was as good
 * (so it beats it, rather than tying it). A score of 0 never is.
 */
function hbHubBlockfallIsBest(PDO $db, int $userId, int $gameId, array $state, int $best): bool
{
    if ($state['score'] === 0 || $best !== $state['score']) {
        return false;
    }
    $statement = $db->prepare('SELECT 1 FROM `HBHub-BlockfallGames`
        WHERE userId = ? AND gameMode = ? AND gameResult = ? AND gameScore >= ? AND gameId <> ? LIMIT 1');
    $statement->execute([$userId, HBHUB_BLOCKFALL_MODE_ENDURO, HBHUB_BLOCKFALL_FINISHED, $state['score'], $gameId]);
    return $statement->fetchColumn() === false;
}

/**
 * Ends the user's game $gameId as scripted, when the page saw a key press or tap made by code. Returns
 * ['game' => id, 'result' => HBHUB_BLOCKFALL_SCRIPTED], or 'notFound' / 'gameOver'.
 */
function hbHubBlockfallScripted(PDO $db, int $userId, int $gameId): array|string
{
    $statement = $db->prepare('UPDATE `HBHub-BlockfallGames` SET gameResult = ?, gameState = \'\', gameSecret = \'\', gamePausedTimestamp = NULL,
            gameEndTimestamp = UTC_TIMESTAMP(3)
        WHERE gameId = ? AND userId = ? AND gameResult = ?');
    $statement->execute([HBHUB_BLOCKFALL_SCRIPTED, $gameId, $userId, HBHUB_BLOCKFALL_PLAYING]);
    if ($statement->rowCount() === 0) {
        $statement = $db->prepare('SELECT 1 FROM `HBHub-BlockfallGames` WHERE gameId = ? AND userId = ?');
        $statement->execute([$gameId, $userId]);
        return $statement->fetchColumn() === false ? 'notFound' : 'gameOver';
    }
    return ['game' => $gameId, 'result' => HBHUB_BLOCKFALL_SCRIPTED];
}

/**
 * The user's best score, from the games that count. 0 before their first.
 */
function hbHubBlockfallBest(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT COALESCE(MAX(gameScore), 0) FROM `HBHub-BlockfallGames`
        WHERE userId = ? AND gameMode = ? AND gameResult = ?');
    $statement->execute([$userId, HBHUB_BLOCKFALL_MODE_ENDURO, HBHUB_BLOCKFALL_FINISHED]);
    return (int) $statement->fetchColumn();
}
