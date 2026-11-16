<?php
// Sudoku's games, kept on the server so the leaderboard can trust them: the server makes the puzzle and keeps its
// answer, and the browser only ever gets the puzzle (like Minesweeper never sends the mines). The page
// (games/sudoku/, assets/js/sudoku.js) asks games/sudoku/move for a new game, a hint, a pause, and to check the board
// once it's full; the numbers and notes you put in stay in the browser until then.
//
// A board is 81 squares, row by row (index = row * 9 + column), each 0 (empty) or 1-9, kept as a string of 81 digits.
// A new puzzle starts from a random full board (hbHubSudokuSearch() on an empty one, trying the numbers in a random
// order), then takes out squares in mirrored pairs, putting a pair back whenever the puzzle would get a second answer
// or, on Easy and Medium, would need more than singles to solve (hbHubSudokuSolvesWithSingles()). HBHUB_SUDOKU_LEVELS
// has how far each level goes. Making one takes the server a few ms (Expert, which makes 12 and keeps the hardest,
// about 0.2 s).
//
// Each game is one row in HBHub-SudokuGames, made when it starts. Starting a game ends the user's game that's still
// being played (as left), so there's only ever one. gamePuzzle and gameSolution are emptied once it's over, when only
// the result and the time are needed.
//
// The time is the server's own: from the start to the board being checked, minus the time it was paused. The page
// pauses it (action=pause) when you press Pause or leave the page (another tab, the phone's lock screen, closing it),
// and carries on (action=resume) when you come back, so it matches the clock on the page. A pause that never arrives
// (no connection) just means the clock kept running.
//
// What counts on the leaderboard (games/sudoku/leaderboard) is a solve without a hint (HBHUB_SUDOKU_SOLVED). A solve
// with hints is HBHUB_SUDOKU_HINTED: played and solved, but not ranked. Anticheat: the puzzle's answer never leaves
// the server, so what's left is a solver (another site, or code) typing the answer in, faster than any person. So a
// solve faster than HBHUB_SUDOKU_MIN_MS_PER_SQUARE for each square that was empty ends as "too fast" instead, which
// doesn't count either. Someone who solves the puzzle elsewhere and types it in slowly can't be told apart from a fast
// player, which is why it's a floor and not more.
//
// Used by games/sudoku/move (the JSON), games/sudoku/index (the game being played, and the best times) and
// games/sudoku/leaderboard.
require_once __DIR__ . '/../../database.php';

const HBHUB_SUDOKU_LEVELS = [ // gameLevel in HBHub-SudokuGames => the level, as DIFFICULTY_NAMES in sudoku.js
    // clues: the fewest numbers a puzzle may start with (fewer is harder). It often ends a few above that, as squares
    // only come out while the answer stays unique (Hard usually ends at 28-30, Expert at 22-25).
    // singles: Easy and Medium have to be solvable with only naked singles ('naked') or naked and hidden singles
    // ('hidden'); Expert must not be ('none': it makes HBHUB_SUDOKU_EXPERT_TRIES boards and keeps the one with the
    // fewest numbers); Hard can be either (null)
    1 => ['key' => 'easy', 'name' => 'Easy', 'clues' => 38, 'singles' => 'naked'],
    2 => ['key' => 'medium', 'name' => 'Medium', 'clues' => 32, 'singles' => 'hidden'],
    3 => ['key' => 'hard', 'name' => 'Hard', 'clues' => 27, 'singles' => null],
    4 => ['key' => 'expert', 'name' => 'Expert', 'clues' => 22, 'singles' => 'none'],
];
const HBHUB_SUDOKU_EXPERT_TRIES = 12;

const HBHUB_SUDOKU_PLAYING = 0; // gameResult in HBHub-SudokuGames
const HBHUB_SUDOKU_SOLVED = 1;
const HBHUB_SUDOKU_HINTED = 2; // solved, but with a hint, so it isn't ranked
const HBHUB_SUDOKU_LEFT = 3;
const HBHUB_SUDOKU_TOO_FAST = 4; // solved faster than a person can type it in (see the anticheat above)

// The anticheat's floor: ms for each square that started empty. The fastest people fill in an Easy puzzle (about 43
// empty squares) in a bit over a minute, so 1.5-2 s a square, and Expert takes everyone far longer. 1 s a square, over
// the whole board, is past anyone who's working it out as they go.
const HBHUB_SUDOKU_MIN_MS_PER_SQUARE = 1000;

const HBHUB_SUDOKU_ALL = 0x3fe; // bits 1-9: every number

/**
 * Which row, column and box each square is in, and every square's 20 neighbours (same row, column or box), worked
 * out once: ['row' => [81], 'col' => [81], 'box' => [81], 'peers' => [81 => int[]], 'units' => 27 lists of 9 squares,
 * 'bits' => [1024 => how many bits are set]].
 */
function hbHubSudokuShape(): array
{
    static $shape = null;
    if ($shape !== null) {
        return $shape;
    }
    $shape = ['row' => [], 'col' => [], 'box' => [], 'peers' => [], 'units' => [], 'bits' => []];
    for ($i = 0; $i < 81; $i++) {
        $shape['row'][$i] = intdiv($i, 9);
        $shape['col'][$i] = $i % 9;
        $shape['box'][$i] = intdiv($shape['row'][$i], 3) * 3 + intdiv($shape['col'][$i], 3);
    }
    for ($i = 0; $i < 81; $i++) {
        $shape['peers'][$i] = [];
        for ($j = 0; $j < 81; $j++) {
            if ($j !== $i && ($shape['row'][$j] === $shape['row'][$i] || $shape['col'][$j] === $shape['col'][$i]
                    || $shape['box'][$j] === $shape['box'][$i])) {
                $shape['peers'][$i][] = $j;
            }
        }
    }
    for ($n = 0; $n < 9; $n++) {
        $row = $col = $box = [];
        for ($k = 0; $k < 9; $k++) {
            $row[] = $n * 9 + $k;
            $col[] = $k * 9 + $n;
            $box[] = (intdiv($n, 3) * 3 + intdiv($k, 3)) * 9 + ($n % 3) * 3 + ($k % 3);
        }
        array_push($shape['units'], $row, $col, $box);
    }
    for ($mask = 0; $mask < 1024; $mask++) {
        $shape['bits'][$mask] = substr_count(decbin($mask), '1');
    }
    return $shape;
}

/**
 * Backtracking search on a board (an array of 81 ints). Always fills the square with the fewest options next. Counts
 * the answers up to $limit, and puts the first in $first. With $random, the numbers are tried in a random order (for
 * making a new full board).
 */
function hbHubSudokuSearch(array $grid, int $limit, ?array &$first = null, bool $random = false): int
{
    $shape = hbHubSudokuShape();
    ['row' => $rowOf, 'col' => $colOf, 'box' => $boxOf, 'bits' => $bits] = $shape;
    $rows = $cols = $boxes = array_fill(0, 9, 0);
    $empty = [];
    for ($i = 0; $i < 81; $i++) {
        if (!$grid[$i]) {
            $empty[] = $i;
            continue;
        }
        $bit = 1 << $grid[$i];
        if (($rows[$rowOf[$i]] | $cols[$colOf[$i]] | $boxes[$boxOf[$i]]) & $bit) {
            return 0; // breaks the rules already
        }
        $rows[$rowOf[$i]] |= $bit;
        $cols[$colOf[$i]] |= $bit;
        $boxes[$boxOf[$i]] |= $bit;
    }

    $count = 0;
    $emptyCount = count($empty);
    // $empty[0..$depth-1] are filled in; each step swaps the square it picks to $depth
    $step = function (int $depth) use (&$step, &$grid, &$rows, &$cols, &$boxes, &$empty, &$count, &$first,
            $emptyCount, $limit, $random, $rowOf, $colOf, $boxOf, $bits): void {
        if ($depth === $emptyCount) { // full
            $count++;
            if ($count === 1) {
                $first = $grid;
            }
            return;
        }
        $bestK = -1;
        $bestMask = 0;
        $bestCount = 10;
        for ($k = $depth; $k < $emptyCount; $k++) {
            $i = $empty[$k];
            $mask = HBHUB_SUDOKU_ALL & ~($rows[$rowOf[$i]] | $cols[$colOf[$i]] | $boxes[$boxOf[$i]]);
            if ($bits[$mask] < $bestCount) {
                $bestK = $k;
                $bestMask = $mask;
                $bestCount = $bits[$mask];
                if ($bestCount <= 1) {
                    break;
                }
            }
        }
        if ($bestCount === 0) {
            return; // a square with no number left: a dead end
        }
        $best = $empty[$bestK];
        $empty[$bestK] = $empty[$depth];
        $empty[$depth] = $best;
        $digits = [];
        for ($d = 1; $d <= 9; $d++) {
            if ($bestMask & (1 << $d)) {
                $digits[] = $d;
            }
        }
        if ($random) {
            shuffle($digits);
        }
        $r = $rowOf[$best];
        $c = $colOf[$best];
        $b = $boxOf[$best];
        foreach ($digits as $d) {
            $bit = 1 << $d;
            $grid[$best] = $d;
            $rows[$r] |= $bit;
            $cols[$c] |= $bit;
            $boxes[$b] |= $bit;
            $step($depth + 1);
            $rows[$r] &= ~$bit;
            $cols[$c] &= ~$bit;
            $boxes[$b] &= ~$bit;
            $grid[$best] = 0;
            if ($count >= $limit) {
                break;
            }
        }
        $empty[$depth] = $empty[$bestK];
        $empty[$bestK] = $best;
    };
    $step(0);
    return $count;
}

/**
 * Whether a puzzle can be solved by singles alone: a square with only one number left (naked single), and with
 * $hidden, a number with only one square left in a row, column or box (hidden single).
 */
function hbHubSudokuSolvesWithSingles(array $grid, bool $hidden): bool
{
    $shape = hbHubSudokuShape();
    $options = static function (int $i) use (&$grid, $shape): int {
        $used = 0;
        foreach ($shape['peers'][$i] as $p) {
            $used |= 1 << $grid[$p];
        }
        return HBHUB_SUDOKU_ALL & ~$used;
    };
    $progress = true;
    while ($progress) {
        $progress = false;
        for ($i = 0; $i < 81; $i++) {
            if ($grid[$i]) {
                continue;
            }
            $mask = $options($i);
            if (!$mask) {
                return false;
            }
            if ($shape['bits'][$mask] === 1) {
                $grid[$i] = strlen(decbin($mask)) - 1; // the one number left (its bit)
                $progress = true;
            }
        }
        if ($progress || !$hidden) {
            continue;
        }
        foreach ($shape['units'] as $unit) {
            for ($d = 1; $d <= 9; $d++) {
                $spots = [];
                foreach ($unit as $i) {
                    if ($grid[$i] === $d) {
                        continue 2;
                    }
                    if (!$grid[$i] && $options($i) & (1 << $d)) {
                        $spots[] = $i;
                    }
                }
                if (count($spots) === 1) {
                    $grid[$spots[0]] = $d;
                    $progress = true;
                }
            }
        }
    }
    return !in_array(0, $grid, true);
}

/**
 * A new puzzle for a level (a key of HBHUB_SUDOKU_LEVELS): ['puzzle' => '81 digits', 'solution' => '81 digits'].
 */
function hbHubSudokuMakePuzzle(int $level): array
{
    ['clues' => $clues, 'singles' => $singles] = HBHUB_SUDOKU_LEVELS[$level];
    $isExpert = $singles === 'none';
    $best = null; // Expert keeps the board with the fewest numbers that singles alone can't solve
    for ($attempt = 0; $attempt < ($isExpert ? HBHUB_SUDOKU_EXPERT_TRIES : 1); $attempt++) {
        $solution = [];
        hbHubSudokuSearch(array_fill(0, 81, 0), 1, $solution, true);
        $puzzle = $solution;
        $left = 81;
        // Try taking a group out; it stays out if the puzzle still has one answer (and is still easy enough)
        $tryRemove = static function (array $group) use (&$puzzle, &$left, $solution, $clues, $singles): void {
            if ($left - count($group) < $clues) {
                return;
            }
            foreach ($group as $j) {
                if (!$puzzle[$j]) {
                    return;
                }
            }
            foreach ($group as $j) {
                $puzzle[$j] = 0;
            }
            $ok = hbHubSudokuSearch($puzzle, 2) === 1
                && (($singles !== 'naked' && $singles !== 'hidden') || hbHubSudokuSolvesWithSingles($puzzle, $singles === 'hidden'));
            if ($ok) {
                $left -= count($group);
            } else {
                foreach ($group as $j) {
                    $puzzle[$j] = $solution[$j];
                }
            }
        };
        // Mirrored pairs first, for the classic look (0-40, each with 80 - i; 40 is the middle)...
        $order = range(0, 40);
        shuffle($order);
        foreach ($order as $i) {
            $tryRemove($i === 40 ? [40] : [$i, 80 - $i]);
        }
        // ...then, on Expert, single squares, to get as low as it can
        if ($isExpert) {
            $order = range(0, 80);
            shuffle($order);
            foreach ($order as $i) {
                $tryRemove([$i]);
            }
        }

        $hard = !$isExpert || !hbHubSudokuSolvesWithSingles($puzzle, true);
        if ($best === null || ($hard && (!$best['hard'] || $left < $best['left']))) {
            $best = ['puzzle' => $puzzle, 'solution' => $solution, 'left' => $left, 'hard' => $hard];
        }
    }
    return ['puzzle' => implode('', $best['puzzle']), 'solution' => implode('', $best['solution'])];
}

/**
 * A time as the page shows it: 4:05, or 1:02:05 from an hour.
 */
function hbHubSudokuTime(int $ms): string
{
    $seconds = intdiv($ms, 1000);
    return $seconds >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60)
        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

/**
 * The user's fastest ranked solve on each level, in ms: [level => ms], only the levels they've solved.
 *
 * @return array<int, int>
 */
function hbHubSudokuBest(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT gameLevel, MIN(gameMs) FROM `HBHub-SudokuGames` WHERE userId = ? AND gameResult = ? GROUP BY gameLevel');
    $statement->execute([$userId, HBHUB_SUDOKU_SOLVED]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * How long a game has been played so far, in ms: from its start to now (or to the pause it's in), minus its pauses.
 * $game is its row, with 'elapsedMs' (see HBHUB_SUDOKU_ELAPSED_SQL) and 'gamePausedMs'.
 */
function hbHubSudokuPlayedMs(array $game): int
{
    return max(0, (int) $game['elapsedMs'] - (int) $game['gamePausedMs']);
}

// For hbHubSudokuPlayedMs(): the ms from the start to now, or to the start of the pause it's in. TIMESTAMPDIFF in
// microseconds, as datetime(3) has ms. (Taking gamePausedMs off happens in PHP: it's unsigned, so taking it off in
// MySQL would fail for a negative.)
const HBHUB_SUDOKU_ELAPSED_SQL = 'TIMESTAMPDIFF(MICROSECOND, gameStartTimestamp, COALESCE(gamePausedTimestamp, UTC_TIMESTAMP(3))) DIV 1000 AS elapsedMs';

/**
 * The game the user is playing now, for the page: ['game' => id, 'level' => key, 'puzzle' => '81 digits',
 * 'hints' => n, 'ms' => played so far], or null when there isn't one.
 */
function hbHubSudokuCurrent(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT gameId, gameLevel, gamePuzzle, gameHints, gamePausedMs, ' . HBHUB_SUDOKU_ELAPSED_SQL . '
        FROM `HBHub-SudokuGames` WHERE userId = ? AND gameResult = ? ORDER BY gameId DESC LIMIT 1');
    $statement->execute([$userId, HBHUB_SUDOKU_PLAYING]);
    $game = $statement->fetch();
    if ($game === false || !isset(HBHUB_SUDOKU_LEVELS[(int) $game['gameLevel']])) {
        return null;
    }
    return [
        'game' => (int) $game['gameId'],
        'level' => HBHUB_SUDOKU_LEVELS[(int) $game['gameLevel']]['key'],
        'puzzle' => $game['gamePuzzle'],
        'hints' => (int) $game['gameHints'],
        'ms' => hbHubSudokuPlayedMs($game),
    ];
}

/**
 * Starts a new game on a level (its key, like 'medium'), ending the one the user was playing (as left). Returns the
 * same shape as hbHubSudokuCurrent(), or 'badRequest' for no such level.
 */
function hbHubSudokuNew(PDO $db, int $userId, mixed $levelKey): array|string
{
    $level = null;
    foreach (HBHUB_SUDOKU_LEVELS as $number => $info) {
        if ($info['key'] === $levelKey) {
            $level = $number;
        }
    }
    if ($level === null) {
        return 'badRequest';
    }
    $made = hbHubSudokuMakePuzzle($level); // before the transaction, so nothing waits on it

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE `HBHub-SudokuGames` SET gameResult = ?, gamePuzzle = \'\', gameSolution = \'\', gamePausedTimestamp = NULL,
                gameEndTimestamp = UTC_TIMESTAMP(3)
            WHERE userId = ? AND gameResult = ?')
            ->execute([HBHUB_SUDOKU_LEFT, $userId, HBHUB_SUDOKU_PLAYING]);
        $db->prepare('INSERT INTO `HBHub-SudokuGames` (userId, gameLevel, gamePuzzle, gameSolution, gameResult, gameStartTimestamp)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3))')
            ->execute([$userId, $level, $made['puzzle'], $made['solution'], HBHUB_SUDOKU_PLAYING]);
        $gameId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId, 'level' => $levelKey, 'puzzle' => $made['puzzle'], 'hints' => 0, 'ms' => 0];
}

/**
 * The user's game $gameId while it's being played, locked (FOR UPDATE, so call it in a transaction), or an error code:
 * 'notFound' (not the user's game) or 'gameOver' (solved or left already, like when another tab started a new one).
 */
function hbHubSudokuLock(PDO $db, int $userId, int $gameId): array|string
{
    $statement = $db->prepare('SELECT gameLevel, gamePuzzle, gameSolution, gameHints, gameResult, gamePausedMs, ' . HBHUB_SUDOKU_ELAPSED_SQL . '
        FROM `HBHub-SudokuGames` WHERE gameId = ? AND userId = ? FOR UPDATE');
    $statement->execute([$gameId, $userId]);
    $game = $statement->fetch();
    if ($game === false) {
        return 'notFound';
    }
    return (int) $game['gameResult'] === HBHUB_SUDOKU_PLAYING ? $game : 'gameOver';
}

/**
 * Pauses ($pause true) or carries on with the user's game $gameId. Doing it twice is fine: a pause while paused does
 * nothing. Returns ['game' => id, 'ms' => played so far], or 'notFound' / 'gameOver' like hbHubSudokuLock().
 */
function hbHubSudokuPause(PDO $db, int $userId, int $gameId, bool $pause): array|string
{
    $db->beginTransaction();
    try {
        $game = hbHubSudokuLock($db, $userId, $gameId);
        if (is_string($game)) {
            $db->rollBack();
            return $game;
        }
        if ($pause) {
            $db->prepare('UPDATE `HBHub-SudokuGames` SET gamePausedTimestamp = UTC_TIMESTAMP(3) WHERE gameId = ? AND gamePausedTimestamp IS NULL')
                ->execute([$gameId]);
        } else {
            $db->prepare('UPDATE `HBHub-SudokuGames`
                SET gamePausedMs = gamePausedMs + GREATEST(0, TIMESTAMPDIFF(MICROSECOND, gamePausedTimestamp, UTC_TIMESTAMP(3)) DIV 1000),
                    gamePausedTimestamp = NULL
                WHERE gameId = ? AND gamePausedTimestamp IS NOT NULL')
                ->execute([$gameId]);
        }
        $game = hbHubSudokuLock($db, $userId, $gameId); // again, for the time after it
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId, 'ms' => hbHubSudokuPlayedMs($game)];
}

/**
 * Checks that $values (the board as the page has it, 81 digits) is a board of the game $puzzle, or null when it isn't
 * (wrong length, not digits, or a given number changed).
 */
function hbHubSudokuValues(mixed $values, string $puzzle): ?string
{
    if (!is_string($values) || !preg_match('/^[0-9]{81}$/', $values)) {
        return null;
    }
    for ($i = 0; $i < 81; $i++) {
        if ($puzzle[$i] !== '0' && $values[$i] !== $puzzle[$i]) {
            return null;
        }
    }
    return $values;
}

/**
 * A hint in the user's game $gameId: the right number for $square (0-80) if it's empty or wrong on $values (the board
 * as the page has it), otherwise for a random square that is. Counts as a hint, so the game won't be ranked.
 * Returns ['game' => id, 'square' => 0-80, 'digit' => 1-9, 'hints' => used so far], or 'badRequest' (no such square or
 * board, or nothing left to fill in), 'notFound' or 'gameOver'.
 */
function hbHubSudokuHint(PDO $db, int $userId, int $gameId, mixed $square, mixed $values): array|string
{
    $db->beginTransaction();
    try {
        $game = hbHubSudokuLock($db, $userId, $gameId);
        if (is_string($game)) {
            $db->rollBack();
            return $game;
        }
        $solution = $game['gameSolution'];
        $values = hbHubSudokuValues($values, $game['gamePuzzle']);
        $square = filter_var($square, FILTER_VALIDATE_INT, ['options' => ['min_range' => -1, 'max_range' => 80]]);
        if ($values === null || $square === false) {
            $db->rollBack();
            return 'badRequest';
        }
        if ($square < 0 || $values[$square] === $solution[$square]) {
            $open = [];
            for ($i = 0; $i < 81; $i++) {
                if ($values[$i] !== $solution[$i]) {
                    $open[] = $i;
                }
            }
            if ($open === []) {
                $db->rollBack();
                return 'badRequest';
            }
            $square = $open[random_int(0, count($open) - 1)];
        }
        $db->prepare('UPDATE `HBHub-SudokuGames` SET gameHints = LEAST(gameHints + 1, 65535) WHERE gameId = ?')->execute([$gameId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['game' => $gameId, 'square' => $square, 'digit' => (int) $solution[$square], 'hints' => (int) $game['gameHints'] + 1];
}

/**
 * Checks the full board $values (81 digits) of the user's game $gameId, and ends it when it's the answer: solved,
 * solved with hints, or too fast (the anticheat). Returns ['game' => id, 'result' => HBHUB_SUDOKU_*, 'ms' => its time,
 * 'hints' => n, 'best' => the user's fastest ranked solve on the level (this one included) or null, 'isBest',
 * 'solved' => their ranked solves on the level], or 'notSolved' (the board isn't the answer), 'badRequest',
 * 'notFound' or 'gameOver'.
 */
function hbHubSudokuSolve(PDO $db, int $userId, int $gameId, mixed $values): array|string
{
    $db->beginTransaction();
    try {
        $game = hbHubSudokuLock($db, $userId, $gameId);
        if (is_string($game)) {
            $db->rollBack();
            return $game;
        }
        $values = hbHubSudokuValues($values, $game['gamePuzzle']);
        if ($values === null) {
            $db->rollBack();
            return 'badRequest';
        }
        if ($values !== $game['gameSolution']) { // every puzzle has one answer, so any other full board breaks a rule
            $db->rollBack();
            return 'notSolved';
        }

        $ms = hbHubSudokuPlayedMs($game); // a board checked while paused ends at the start of the pause
        $hints = (int) $game['gameHints'];
        $emptySquares = substr_count($game['gamePuzzle'], '0');
        $result = match (true) {
            $hints > 0 => HBHUB_SUDOKU_HINTED,
            $ms < $emptySquares * HBHUB_SUDOKU_MIN_MS_PER_SQUARE => HBHUB_SUDOKU_TOO_FAST,
            default => HBHUB_SUDOKU_SOLVED,
        };
        // Over: only the result and the time are kept
        $db->prepare('UPDATE `HBHub-SudokuGames` SET gameResult = ?, gameMs = ?, gamePuzzle = \'\', gameSolution = \'\',
                gamePausedTimestamp = NULL, gameEndTimestamp = UTC_TIMESTAMP(3)
            WHERE gameId = ?')
            ->execute([$result, $ms, $gameId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $level = (int) $game['gameLevel'];
    $statement = $db->prepare('SELECT MIN(gameMs) AS best, COUNT(*) AS solved FROM `HBHub-SudokuGames`
        WHERE userId = ? AND gameLevel = ? AND gameResult = ?');
    $statement->execute([$userId, $level, HBHUB_SUDOKU_SOLVED]);
    $totals = $statement->fetch();
    $best = $totals['best'] !== null ? (int) $totals['best'] : null;
    return [
        'game' => $gameId,
        'result' => $result,
        'ms' => $ms,
        'hints' => $hints,
        'best' => $best,
        'isBest' => $result === HBHUB_SUDOKU_SOLVED && $best === $ms,
        'solved' => (int) $totals['solved'],
    ];
}
