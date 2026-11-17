<?php
// Snake's games, kept on the server so the leaderboard can trust them. The game itself still runs in the browser
// (assets/js/snake.js, it has to answer a key press straight away), but the server plays every game along with it, one
// step behind, and the score on the leaderboard is the server's own, never the number the page sends.
//
// How a game goes (games/snake/move, the JSON):
// - The Snake page makes the next game when it's opened (hbHubSnakeNew()), and the answer to each finished game makes
//   the one after it. A game is one row in HBHub-SnakeGames: the snake lying where it starts (gameSnake, gameDir,
//   gameApple, as the browser has it) and a secret (gameSecret) that decides where the apples go.
// - When the snake starts moving, the page says so with the speed (hbHubSnakeStart()), which starts the server's clock.
// - While it moves, the page reports (hbHubSnakeReport()): how many steps the snake has made, and the turns since the
//   last report ("12u,15l": on step 12 it went up, on 15 left). It does that every few seconds, as soon as it eats an
//   apple, when it's paused or carries on, and once more when it crashes (or fills the board). The server makes the
//   same steps on its copy of the board.
//
// Anticheat: what the page could fake, and what stops it.
// - The score: the server only counts the apples its own copy of the snake eats, and a step its copy can't make (into
//   a wall, into itself, or straight back) ends the game as "bad moves". So the score can't be typed in.
// - The apples: where the next one goes is a "roll" (a number from the secret, hbHubSnakeRoll()): the free square it
//   lands on is free[roll % free.length], free being the empty squares in order. The page only ever has the rolls of
//   the next HBHUB_SNAKE_ROLLS_AHEAD apples; the next one comes in each report's answer. So nobody can play a game
//   through at their own pace before the clock starts (or in a second window) and then send it in.
// - The speed: every report checks the steps against the server's clock, without the pauses (steps x the speed's ms).
//   More than HBHUB_SNAKE_AHEAD_MS ahead (a sped up game, or moves sent before they were played) or more than
//   HBHUB_SNAKE_BEHIND_MS + HBHUB_SNAKE_BEHIND_SHARE behind (a slowed down game, to have more time to think) ends it as
//   "out of time". The leeway is for the time a request takes and the odd slow frame, which the game makes up by
//   running a little behind.
// - Pauses: they're timed by the server too (the clock stops while paused, like Sudoku's), and a game paused more than
//   HBHUB_SNAKE_MAX_PAUSES times ends as "too many pauses", as a pause on every step would turn it into a slow game.
// - Bots: a key press or swipe made by code (one typed into the console) isn't "trusted" in the browser, so the page
//   ignores it and ends the game as "scripted" (hbHubSnakeScripted()). A game that ends any of these ways counts as
//   played, but its score doesn't go on the leaderboard.
// Nothing can stop someone who rewrites the page into a bot that plays in real time, as the server can't see who
// presses the keys. That's the same for Minesweeper.
//
// Only started games count as played: a game made when the page was opened but never started (gameStartTimestamp
// NULL) isn't, and making the next one deletes it. Making one also ends the user's game that's still going (as left),
// so there's only ever one. gameSnake and gameSecret are emptied once a game is over, when only the result is needed.
// Used by games/snake/move, games/snake/index (the next game, and the best scores) and games/snake/leaderboard.
require_once __DIR__ . '/../../database.php';

const HBHUB_SNAKE_COLS = 20; // the board, as COLS, ROWS and START_LENGTH in assets/js/snake.js
const HBHUB_SNAKE_ROWS = 12;
const HBHUB_SNAKE_START_LENGTH = 4;

// gameSpeed in HBHub-SnakeGames => the speed; ms a step, as SPEEDS in snake.js. 'leaderboard' is whether it has a tab
// on the leaderboard: Slow doesn't (its games are still saved, and still your best at the speed, just not ranked)
const HBHUB_SNAKE_SPEEDS = [
    1 => ['key' => 'slow', 'name' => 'Slow', 'ms' => 160, 'leaderboard' => false],
    2 => ['key' => 'normal', 'name' => 'Normal', 'ms' => 120, 'leaderboard' => true],
    3 => ['key' => 'fast', 'name' => 'Fast', 'ms' => 85, 'leaderboard' => true],
];

const HBHUB_SNAKE_PLAYING = 0; // gameResult in HBHub-SnakeGames (ready to start too, while gameStartTimestamp is NULL)
const HBHUB_SNAKE_FINISHED = 1; // crashed or filled the board: on the leaderboard
const HBHUB_SNAKE_LEFT = 2; // another game was started first
const HBHUB_SNAKE_SCRIPTED = 3; // a key press or swipe came from code
const HBHUB_SNAKE_OUT_OF_TIME = 4; // the steps didn't keep time with the server's clock
const HBHUB_SNAKE_BAD_MOVES = 5; // a step the server's copy couldn't make
const HBHUB_SNAKE_TOO_MANY_PAUSES = 6; // paused more than HBHUB_SNAKE_MAX_PAUSES times

const HBHUB_SNAKE_ROLLS_AHEAD = 4; // how many apples ahead the page knows where they'll go (as it can't wait for one)
const HBHUB_SNAKE_MAX_PAUSES = 10; // as MAX_PAUSES in snake.js
const HBHUB_SNAKE_AHEAD_MS = 2000; // the anticheat's leeway (see above)
const HBHUB_SNAKE_BEHIND_MS = 3000;
const HBHUB_SNAKE_BEHIND_SHARE = 0.1; // of the time played so far
const HBHUB_SNAKE_MAX_STEPS = 2000000; // more than anyone plays (Slow, for three days), so a number can't overflow
const HBHUB_SNAKE_MAX_TURNS = 5000; // turns in one report

const HBHUB_SNAKE_DIRECTIONS = ['u' => [0, -1], 'd' => [0, 1], 'l' => [-1, 0], 'r' => [1, 0]]; // gameDir => [x, y]
const HBHUB_SNAKE_OPPOSITE = ['u' => 'd', 'd' => 'u', 'l' => 'r', 'r' => 'l'];
const HBHUB_SNAKE_NO_APPLE = 255; // gameApple once the snake fills the whole board

// The ms from the start to now, or to the start of the pause it's in (TIMESTAMPDIFF in microseconds, as datetime(3)
// has ms). gamePausedMs is taken off in PHP, as it's unsigned.
const HBHUB_SNAKE_ELAPSED_SQL = 'TIMESTAMPDIFF(MICROSECOND, gameStartTimestamp, COALESCE(gamePausedTimestamp, UTC_TIMESTAMP(3))) DIV 1000 AS elapsedMs';

/**
 * The speed's key ('slow', ...) => its gameSpeed number.
 *
 * @return array<string, int>
 */
function hbHubSnakeSpeedKeys(): array
{
    $keys = [];
    foreach (HBHUB_SNAKE_SPEEDS as $number => $speed) {
        $keys[$speed['key']] = $number;
    }
    return $keys;
}

/**
 * Where apple number $index + 2 goes (apple 1 is always the one ahead of the snake at the start): a number from 0 to
 * 2^28 - 1, from the game's secret. The page picks free[roll % free.length].
 */
function hbHubSnakeRoll(string $secret, int $index): int
{
    return hexdec(substr(hash('sha256', $secret . ':' . $index), 0, 7));
}

/**
 * The rolls from $from up to (not including) $to, as the JSON sends them: {"rollsFrom": $from, "rolls": [...]}.
 */
function hbHubSnakeRolls(string $secret, int $from, int $to): array
{
    $rolls = [];
    for ($index = $from; $index < $to; $index++) {
        $rolls[] = hbHubSnakeRoll($secret, $index);
    }
    return ['rollsFrom' => $from, 'rolls' => $rolls];
}

/**
 * The squares of a snake (index = row * HBHUB_SNAKE_COLS + column, head first) as gameSnake has them: two hex digits
 * each. And back again.
 */
function hbHubSnakePack(array $cells): string
{
    return bin2hex(pack('C*', ...$cells));
}

function hbHubSnakeUnpack(string $snake): array
{
    return array_values(unpack('C*', hex2bin($snake)));
}

/**
 * One step of the game on the server's copy ($state: 'cells', 'dir', 'apple', 'score', 'steps'), going $state['dir'],
 * the same as step() in snake.js: into the next square, eating the apple there (and placing the next one with the
 * secret's roll) or moving the tail along. Returns false when the step can't be made: into a wall or the snake itself
 * (its tail is fine, as it moves out of the way, unless the snake grows), or after the board is full.
 */
function hbHubSnakeStep(array &$state, string $secret): bool
{
    if ($state['apple'] === HBHUB_SNAKE_NO_APPLE) {
        return false;
    }
    [$dx, $dy] = HBHUB_SNAKE_DIRECTIONS[$state['dir']];
    $x = $state['cells'][0] % HBHUB_SNAKE_COLS + $dx;
    $y = intdiv($state['cells'][0], HBHUB_SNAKE_COLS) + $dy;
    if ($x < 0 || $y < 0 || $x >= HBHUB_SNAKE_COLS || $y >= HBHUB_SNAKE_ROWS) {
        return false;
    }
    $next = $y * HBHUB_SNAKE_COLS + $x;
    $eating = $next === $state['apple'];
    $body = $eating ? $state['cells'] : array_slice($state['cells'], 0, -1);
    if (in_array($next, $body, true)) {
        return false;
    }

    array_unshift($state['cells'], $next);
    $state['steps']++;
    if (!$eating) {
        array_pop($state['cells']);
        return true;
    }
    $state['score']++;
    $taken = array_flip($state['cells']);
    $free = [];
    for ($square = 0; $square < HBHUB_SNAKE_COLS * HBHUB_SNAKE_ROWS; $square++) {
        if (!isset($taken[$square])) {
            $free[] = $square;
        }
    }
    $state['apple'] = $free === []
        ? HBHUB_SNAKE_NO_APPLE // the board is full: won
        : $free[hbHubSnakeRoll($secret, $state['score'] - 1) % count($free)];
    return true;
}

/**
 * The turns a report sends ("12u,15l", or empty) as [step => direction], or null when they aren't turns: not in that
 * shape, not in order, too many, or from before $fromStep (the steps the server has made already).
 *
 * @return array<int, string>|null
 */
function hbHubSnakeTurns(mixed $turns, int $fromStep): ?array
{
    if (!is_string($turns)) {
        return null;
    }
    if ($turns === '') {
        return [];
    }
    $parts = explode(',', $turns);
    if (count($parts) > HBHUB_SNAKE_MAX_TURNS) {
        return null;
    }
    $list = [];
    $last = $fromStep - 1;
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,7})([udlr])$/', $part, $match) || (int) $match[1] <= $last) {
            return null;
        }
        $last = (int) $match[1];
        $list[$last] = $match[2];
    }
    return $list;
}

/**
 * Whether $steps of the game's speed fit the time it has been played (without its pauses), within the anticheat's
 * leeway.
 */
function hbHubSnakeOnTime(int $steps, int $stepMs, int $playedMs): bool
{
    $expected = $steps * $stepMs;
    return $expected - $playedMs <= HBHUB_SNAKE_AHEAD_MS
        && $playedMs - $expected <= HBHUB_SNAKE_BEHIND_MS + HBHUB_SNAKE_BEHIND_SHARE * $playedMs;
}

/**
 * Makes the user's next game, ready to start, and ends the one they're still playing (as left; one never started is
 * deleted instead, it doesn't count). Returns ['game' => id, 'rollsFrom' => 0, 'rolls' => the first rolls].
 */
function hbHubSnakeNew(PDO $db, int $userId): array
{
    $secret = bin2hex(random_bytes(16));
    $row = intdiv(HBHUB_SNAKE_ROWS, 2);
    $cells = [];
    for ($i = 0; $i < HBHUB_SNAKE_START_LENGTH; $i++) {
        $cells[] = $row * HBHUB_SNAKE_COLS + HBHUB_SNAKE_START_LENGTH + 1 - $i; // as reset() in snake.js
    }
    $apple = $row * HBHUB_SNAKE_COLS + HBHUB_SNAKE_COLS - 5;

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM `HBHub-SnakeGames` WHERE userId = ? AND gameResult = ? AND gameStartTimestamp IS NULL')
            ->execute([$userId, HBHUB_SNAKE_PLAYING]);
        $db->prepare('UPDATE `HBHub-SnakeGames` SET gameResult = ?, gameSnake = \'\', gameSecret = \'\', gamePausedTimestamp = NULL,
                gameEndTimestamp = UTC_TIMESTAMP(3)
            WHERE userId = ? AND gameResult = ?')
            ->execute([HBHUB_SNAKE_LEFT, $userId, HBHUB_SNAKE_PLAYING]);
        $db->prepare('INSERT INTO `HBHub-SnakeGames` (userId, gameSecret, gameSnake, gameDir, gameApple, gameResult, gameCreatedTimestamp)
            VALUES (?, ?, ?, \'r\', ?, ?, UTC_TIMESTAMP(3))')
            ->execute([$userId, $secret, hbHubSnakePack($cells), $apple, HBHUB_SNAKE_PLAYING]);
        $gameId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId] + hbHubSnakeRolls($secret, 0, HBHUB_SNAKE_ROLLS_AHEAD);
}

/**
 * Starts the clock on the user's game $gameId, at $speedKey ('slow', 'normal' or 'fast'). Returns ['game' => id], or
 * 'badRequest' (no such speed), 'notFound' (not the user's game) or 'gameOver' (started already, or over, like when
 * another tab made a new one).
 */
function hbHubSnakeStart(PDO $db, int $userId, int $gameId, mixed $speedKey): array|string
{
    $speed = is_string($speedKey) ? (hbHubSnakeSpeedKeys()[$speedKey] ?? null) : null;
    if ($speed === null) {
        return 'badRequest';
    }
    $statement = $db->prepare('UPDATE `HBHub-SnakeGames` SET gameSpeed = ?, gameStartTimestamp = UTC_TIMESTAMP(3)
        WHERE gameId = ? AND userId = ? AND gameResult = ? AND gameStartTimestamp IS NULL');
    $statement->execute([$speed, $gameId, $userId, HBHUB_SNAKE_PLAYING]);
    if ($statement->rowCount() === 0) {
        $statement = $db->prepare('SELECT 1 FROM `HBHub-SnakeGames` WHERE gameId = ? AND userId = ?');
        $statement->execute([$gameId, $userId]);
        return $statement->fetchColumn() === false ? 'notFound' : 'gameOver';
    }
    return ['game' => $gameId];
}

/**
 * The page's report on the user's game $gameId: the snake has made $steps steps in all, with $turns since the last
 * report (see hbHubSnakeTurns()). The server makes the same steps and checks them against its clock (the anticheat at
 * the top), then does what $action says:
 * - 'tick': nothing more, it's just keeping up.
 * - 'pause' / 'resume': stops or starts the clock. Doing it twice is fine. One pause too many ends the game.
 * - 'finish': the snake crashed (or filled the board), so the game is over and its score counts.
 * Returns ['game' => id, 'result' => HBHUB_SNAKE_*, 'score' => apples eaten, as the server counted them] and, while
 * it's still being played, the rolls the page doesn't have yet ('rollsFrom', 'rolls'). A finished game also has 'best'
 * (the user's best at the speed, this one included) and 'isBest'.
 * Errors: 'badRequest' (no such action, or steps that aren't a number), 'notFound' (not the user's game) and
 * 'gameOver' (not started, or over already, like when another tab made a new one).
 */
function hbHubSnakeReport(PDO $db, int $userId, int $gameId, mixed $action, mixed $steps, mixed $turns): array|string
{
    $steps = filter_var($steps, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => HBHUB_SNAKE_MAX_STEPS]]);
    if (!in_array($action, ['tick', 'pause', 'resume', 'finish'], true) || $steps === false) {
        return 'badRequest';
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT gameSpeed, gameSecret, gameSnake, gameDir, gameApple, gameSteps, gameScore, gamePauses,
                gamePausedMs, gamePausedTimestamp IS NOT NULL AS isPaused, gameResult, gameStartTimestamp IS NOT NULL AS isStarted,
                ' . HBHUB_SNAKE_ELAPSED_SQL . '
            FROM `HBHub-SnakeGames` WHERE gameId = ? AND userId = ? FOR UPDATE');
        $statement->execute([$gameId, $userId]);
        $game = $statement->fetch();
        if ($game === false || (int) $game['gameResult'] !== HBHUB_SNAKE_PLAYING || !$game['isStarted']) {
            $db->rollBack();
            return $game === false ? 'notFound' : 'gameOver';
        }

        $secret = $game['gameSecret'];
        $state = [
            'cells' => hbHubSnakeUnpack($game['gameSnake']),
            'dir' => $game['gameDir'],
            'apple' => (int) $game['gameApple'],
            'score' => (int) $game['gameScore'],
            'steps' => (int) $game['gameSteps'],
        ];
        $scoreBefore = $state['score'];
        $playedMs = max(0, (int) $game['elapsedMs'] - (int) $game['gamePausedMs']);
        $stepMs = HBHUB_SNAKE_SPEEDS[(int) $game['gameSpeed']]['ms'];

        // The same steps on the server's copy, turning where the page did
        $result = HBHUB_SNAKE_PLAYING;
        $turnList = hbHubSnakeTurns($turns, $state['steps']);
        if ($turnList === null || $steps < $state['steps'] || ($turnList !== [] && array_key_last($turnList) >= $steps)) {
            $result = HBHUB_SNAKE_BAD_MOVES;
        } else {
            while ($state['steps'] < $steps) {
                $turn = $turnList[$state['steps']] ?? null;
                if ($turn !== null) {
                    if ($turn === HBHUB_SNAKE_OPPOSITE[$state['dir']]) {
                        $result = HBHUB_SNAKE_BAD_MOVES; // straight back into itself
                        break;
                    }
                    $state['dir'] = $turn;
                }
                if (!hbHubSnakeStep($state, $secret)) {
                    $result = HBHUB_SNAKE_BAD_MOVES;
                    break;
                }
            }
        }
        if ($result === HBHUB_SNAKE_PLAYING && !hbHubSnakeOnTime($state['steps'], $stepMs, $playedMs)) {
            $result = HBHUB_SNAKE_OUT_OF_TIME;
        }

        $pauses = (int) $game['gamePauses'];
        if ($result === HBHUB_SNAKE_PLAYING && $action === 'pause' && !$game['isPaused']) {
            $pauses++;
            if ($pauses > HBHUB_SNAKE_MAX_PAUSES) {
                $result = HBHUB_SNAKE_TOO_MANY_PAUSES;
            }
        }
        if ($result === HBHUB_SNAKE_PLAYING && $action === 'finish') {
            $result = HBHUB_SNAKE_FINISHED;
        }

        if ($result === HBHUB_SNAKE_PLAYING) {
            $db->prepare('UPDATE `HBHub-SnakeGames` SET gameSnake = ?, gameDir = ?, gameApple = ?, gameSteps = ?, gameScore = ?, gamePauses = ?,
                    gamePausedMs = IF(? = \'resume\' AND gamePausedTimestamp IS NOT NULL,
                        gamePausedMs + GREATEST(0, TIMESTAMPDIFF(MICROSECOND, gamePausedTimestamp, UTC_TIMESTAMP(3)) DIV 1000), gamePausedMs),
                    gamePausedTimestamp = CASE ? WHEN \'pause\' THEN COALESCE(gamePausedTimestamp, UTC_TIMESTAMP(3))
                        WHEN \'resume\' THEN NULL ELSE gamePausedTimestamp END
                WHERE gameId = ?')
                ->execute([hbHubSnakePack($state['cells']), $state['dir'], $state['apple'], $state['steps'], $state['score'],
                    $pauses, $action, $action, $gameId]);
        } else {
            // Over: only the result, the score and the time are kept. A game that doesn't count keeps the score the
            // server got to, for the admins, but the leaderboard leaves it out.
            $db->prepare('UPDATE `HBHub-SnakeGames` SET gameResult = ?, gameSnake = \'\', gameSecret = \'\', gameSteps = ?, gameScore = ?,
                    gamePauses = ?, gameMs = ?, gamePausedTimestamp = NULL, gameEndTimestamp = UTC_TIMESTAMP(3)
                WHERE gameId = ?')
                ->execute([$result, $state['steps'], $state['score'], $pauses, $playedMs, $gameId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $view = ['game' => $gameId, 'result' => $result, 'score' => $state['score']];
    if ($result === HBHUB_SNAKE_PLAYING) {
        $view += hbHubSnakeRolls($secret, $scoreBefore + HBHUB_SNAKE_ROLLS_AHEAD, $state['score'] + HBHUB_SNAKE_ROLLS_AHEAD);
    }
    if ($result === HBHUB_SNAKE_FINISHED) {
        $view['best'] = hbHubSnakeBests($db, $userId)[HBHUB_SNAKE_SPEEDS[(int) $game['gameSpeed']]['key']];
        $view['isBest'] = $state['score'] > 0 && $view['best'] === $state['score'] && !hbHubSnakeTied($db, $userId, $gameId,
            (int) $game['gameSpeed'], $state['score']);
    }
    return $view;
}

/**
 * Whether the user had already scored $score at the speed before the game $gameId (so this one ties their best,
 * rather than beating it).
 */
function hbHubSnakeTied(PDO $db, int $userId, int $gameId, int $speed, int $score): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-SnakeGames`
        WHERE userId = ? AND gameSpeed = ? AND gameResult = ? AND gameScore >= ? AND gameId <> ? LIMIT 1');
    $statement->execute([$userId, $speed, HBHUB_SNAKE_FINISHED, $score, $gameId]);
    return $statement->fetchColumn() !== false;
}

/**
 * Ends the user's game $gameId as scripted, when the page saw a key press or swipe made by code. Returns
 * ['game' => id, 'result' => HBHUB_SNAKE_SCRIPTED], or 'notFound' / 'gameOver'.
 */
function hbHubSnakeScripted(PDO $db, int $userId, int $gameId): array|string
{
    $statement = $db->prepare('UPDATE `HBHub-SnakeGames` SET gameResult = ?, gameSnake = \'\', gameSecret = \'\', gamePausedTimestamp = NULL,
            gameEndTimestamp = UTC_TIMESTAMP(3)
        WHERE gameId = ? AND userId = ? AND gameResult = ?');
    $statement->execute([HBHUB_SNAKE_SCRIPTED, $gameId, $userId, HBHUB_SNAKE_PLAYING]);
    if ($statement->rowCount() === 0) {
        $statement = $db->prepare('SELECT 1 FROM `HBHub-SnakeGames` WHERE gameId = ? AND userId = ?');
        $statement->execute([$gameId, $userId]);
        return $statement->fetchColumn() === false ? 'notFound' : 'gameOver';
    }
    return ['game' => $gameId, 'result' => HBHUB_SNAKE_SCRIPTED];
}

/**
 * The user's best score at each speed, from the games that count: ['slow' => apples, 'normal' => ..., 'fast' => ...],
 * 0 before their first.
 *
 * @return array<string, int>
 */
function hbHubSnakeBests(PDO $db, int $userId): array
{
    $bests = [];
    foreach (HBHUB_SNAKE_SPEEDS as $speed) {
        $bests[$speed['key']] = 0;
    }
    $statement = $db->prepare('SELECT gameSpeed, MAX(gameScore) FROM `HBHub-SnakeGames` WHERE userId = ? AND gameResult = ? GROUP BY gameSpeed');
    $statement->execute([$userId, HBHUB_SNAKE_FINISHED]);
    foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$speed, $best]) {
        if (isset(HBHUB_SNAKE_SPEEDS[(int) $speed])) {
            $bests[HBHUB_SNAKE_SPEEDS[(int) $speed]['key']] = (int) $best;
        }
    }
    return $bests;
}
