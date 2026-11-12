<?php
// Minesweeper's games, kept on the server so the leaderboard can trust them: the browser never knows where the mines
// are, like Wordle never knows the word. The game in the frame (assets/minesweeper/js/main.js) sends each square it
// opens to games/minesweeper/move, and gets back the squares that opened (with their numbers). Flags stay in the
// browser, they don't change anything here.
// The mines are placed on the first click, away from the square clicked and the 8 around it, so the first click always
// opens an area. Each game is one row in HBHub-MinesweeperGames, made by that first click: gameCells has one character
// per square, row by row ('0' hidden, '1' opened, '2' mine), and is emptied once the game is over, when only the
// result and the time are needed. The time is the server's own, from the first click to the one that won.
// Starting a game ends the user's game that's still being played (as left), so there's only ever one, and a game you
// walk away from counts as played but not won.
// Used by games/minesweeper/move (the JSON) and games/minesweeper/leaderboard.
require_once __DIR__ . '/../../database.php';

const HBHUB_MINESWEEPER_SIZES = [ // gameSize in HBHub-MinesweeperGames (squares on each side) => its difficulty
    9 => ['key' => 'easy', 'name' => 'Easy', 'mines' => 10],
    16 => ['key' => 'medium', 'name' => 'Medium', 'mines' => 40],
    30 => ['key' => 'hard', 'name' => 'Hard', 'mines' => 160],
];

const HBHUB_MINESWEEPER_PLAYING = 0; // gameResult in HBHub-MinesweeperGames
const HBHUB_MINESWEEPER_WON = 1;
const HBHUB_MINESWEEPER_LOST = 2;
const HBHUB_MINESWEEPER_LEFT = 3;

const HBHUB_MINESWEEPER_HIDDEN = '0'; // a square in gameCells
const HBHUB_MINESWEEPER_OPENED = '1';
const HBHUB_MINESWEEPER_MINE = '2';

/**
 * The indexes (row * size + column) of the up to 8 squares around a square.
 *
 * @return int[]
 */
function hbHubMinesweeperNeighbours(int $size, int $index): array
{
    $row = intdiv($index, $size);
    $col = $index % $size;
    $neighbours = [];
    for ($r = max(0, $row - 1); $r <= min($size - 1, $row + 1); $r++) {
        for ($c = max(0, $col - 1); $c <= min($size - 1, $col + 1); $c++) {
            if ($r !== $row || $c !== $col) {
                $neighbours[] = $r * $size + $c;
            }
        }
    }
    return $neighbours;
}

/**
 * How many mines are around a square, or -1 when it's a mine itself.
 */
function hbHubMinesweeperNumber(string $cells, int $size, int $index): int
{
    if ($cells[$index] === HBHUB_MINESWEEPER_MINE) {
        return -1;
    }
    $mines = 0;
    foreach (hbHubMinesweeperNeighbours($size, $index) as $neighbour) {
        $mines += $cells[$neighbour] === HBHUB_MINESWEEPER_MINE ? 1 : 0;
    }
    return $mines;
}

/**
 * A new board with every square hidden and the mines placed at random, but not on $first or around it.
 */
function hbHubMinesweeperNewBoard(int $size, int $first): string
{
    $safe = array_flip([$first, ...hbHubMinesweeperNeighbours($size, $first)]);
    $spots = array_values(array_filter(range(0, $size * $size - 1), static fn (int $index): bool => !isset($safe[$index])));
    $spots = (new Random\Randomizer())->shuffleArray($spots); // the default engine is the secure one
    $cells = str_repeat(HBHUB_MINESWEEPER_HIDDEN, $size * $size);
    foreach (array_slice($spots, 0, HBHUB_MINESWEEPER_SIZES[$size]['mines']) as $index) {
        $cells[$index] = HBHUB_MINESWEEPER_MINE;
    }
    return $cells;
}

/**
 * Opens a square in $cells, and when no mine is around it, the squares around it too (and so on). Opening a mine
 * leaves $cells as it is. Returns the squares that opened, or null when it was a mine.
 *
 * @return int[]|null
 */
function hbHubMinesweeperOpenSquare(string &$cells, int $size, int $index): ?array
{
    if ($cells[$index] === HBHUB_MINESWEEPER_MINE) {
        return null;
    }
    $opened = [];
    $todo = [$index];
    while ($todo !== []) {
        $square = array_pop($todo);
        if ($cells[$square] !== HBHUB_MINESWEEPER_HIDDEN) {
            continue; // opened already (or a mine, which an empty square never has next to it)
        }
        $cells[$square] = HBHUB_MINESWEEPER_OPENED;
        $opened[] = $square;
        if (hbHubMinesweeperNumber($cells, $size, $square) === 0) {
            array_push($todo, ...hbHubMinesweeperNeighbours($size, $square));
        }
    }
    return $opened;
}

/**
 * Whether every square without a mine is open.
 */
function hbHubMinesweeperIsWon(string $cells): bool
{
    return !str_contains($cells, HBHUB_MINESWEEPER_HIDDEN);
}

/**
 * The user's fastest win on a size, in ms, or null before their first.
 */
function hbHubMinesweeperBest(PDO $db, int $userId, int $size): ?int
{
    $statement = $db->prepare('SELECT MIN(gameMs) FROM `HBHub-MinesweeperGames` WHERE userId = ? AND gameSize = ? AND gameResult = ?');
    $statement->execute([$userId, $size, HBHUB_MINESWEEPER_WON]);
    $best = $statement->fetchColumn();
    return $best !== null && $best !== false ? (int) $best : null;
}

/**
 * Opens a square: in the game $gameId, or, when it's null, in a new game on a board of $size (the first click, which
 * places the mines). Returns what the page needs, or an error code:
 * ['game' => id, 'result' => HBHUB_MINESWEEPER_*, 'cells' => [[row, col, number], ...]]. 'cells' has the squares
 * that opened, and once the game is over, every square on the board (number -1 for a mine). A won game also has
 * 'ms' (its time), 'best' (the user's fastest on the size, this one included) and 'isBest'.
 * Errors: 'badRequest' (no such size or square), 'notFound' (not the user's game) and 'gameOver' (the game is won,
 * lost or left already, like when another tab started a new one). Opening a square that's open already does nothing,
 * so a double click is fine.
 */
function hbHubMinesweeperOpen(PDO $db, int $userId, ?int $gameId, mixed $size, mixed $row, mixed $col): array|string
{
    $db->beginTransaction();
    try {
        if ($gameId === null) {
            $size = filter_var($size, FILTER_VALIDATE_INT);
            if (!isset(HBHUB_MINESWEEPER_SIZES[$size])) {
                $db->rollBack();
                return 'badRequest';
            }
        } else {
            $statement = $db->prepare('SELECT gameSize, gameCells, gameResult FROM `HBHub-MinesweeperGames` WHERE gameId = ? AND userId = ? FOR UPDATE');
            $statement->execute([$gameId, $userId]);
            $game = $statement->fetch();
            if ($game === false) {
                $db->rollBack();
                return 'notFound';
            }
            if ((int) $game['gameResult'] !== HBHUB_MINESWEEPER_PLAYING) {
                $db->rollBack();
                return 'gameOver';
            }
            $size = (int) $game['gameSize'];
            $cells = $game['gameCells'];
        }

        $row = filter_var($row, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $size - 1]]);
        $col = filter_var($col, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $size - 1]]);
        if ($row === false || $col === false) {
            $db->rollBack();
            return 'badRequest';
        }
        $index = $row * $size + $col;

        if ($gameId === null) {
            // The game still being played (another tab, or one the user walked away from) ends as left
            $db->prepare('UPDATE `HBHub-MinesweeperGames` SET gameResult = ?, gameCells = \'\', gameEndTimestamp = UTC_TIMESTAMP(3)
                WHERE userId = ? AND gameResult = ?')
                ->execute([HBHUB_MINESWEEPER_LEFT, $userId, HBHUB_MINESWEEPER_PLAYING]);
            $cells = hbHubMinesweeperNewBoard($size, $index);
            $db->prepare('INSERT INTO `HBHub-MinesweeperGames` (userId, gameSize, gameCells, gameResult, gameStartTimestamp)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))')
                ->execute([$userId, $size, $cells, HBHUB_MINESWEEPER_PLAYING]);
            $gameId = (int) $db->lastInsertId();
        }

        $opened = hbHubMinesweeperOpenSquare($cells, $size, $index);
        $result = match (true) {
            $opened === null => HBHUB_MINESWEEPER_LOST,
            hbHubMinesweeperIsWon($cells) => HBHUB_MINESWEEPER_WON,
            default => HBHUB_MINESWEEPER_PLAYING,
        };

        if ($result === HBHUB_MINESWEEPER_PLAYING) {
            $db->prepare('UPDATE `HBHub-MinesweeperGames` SET gameCells = ? WHERE gameId = ?')->execute([$cells, $gameId]);
        } else {
            // Over: only the result (and a win's time) is kept. TIMESTAMPDIFF in microseconds, as datetime(3) has ms.
            $db->prepare('UPDATE `HBHub-MinesweeperGames` SET gameCells = \'\', gameResult = ?, gameEndTimestamp = UTC_TIMESTAMP(3),
                    gameMs = IF(? = ?, TIMESTAMPDIFF(MICROSECOND, gameStartTimestamp, UTC_TIMESTAMP(3)) DIV 1000, NULL)
                WHERE gameId = ?')
                ->execute([$result, $result, HBHUB_MINESWEEPER_WON, $gameId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $squares = $result === HBHUB_MINESWEEPER_PLAYING ? $opened : range(0, $size * $size - 1);
    $view = [
        'game' => $gameId,
        'result' => $result,
        'cells' => array_map(static fn (int $square): array => [intdiv($square, $size), $square % $size,
            hbHubMinesweeperNumber($cells, $size, $square)], $squares),
    ];
    if ($result === HBHUB_MINESWEEPER_WON) {
        $statement = $db->prepare('SELECT gameMs FROM `HBHub-MinesweeperGames` WHERE gameId = ?');
        $statement->execute([$gameId]);
        $view['ms'] = (int) $statement->fetchColumn();
        $view['best'] = hbHubMinesweeperBest($db, $userId, $size);
        $view['isBest'] = $view['best'] === $view['ms'];
    }
    return $view;
}

/**
 * A time in ms as the leaderboard and the page show it, like "12.34 s" or "1:02.50".
 */
function hbHubMinesweeperTime(int $ms): string
{
    $seconds = intdiv($ms, 1000);
    $hundredths = intdiv($ms % 1000, 10);
    return $seconds >= 60
        ? sprintf('%d:%02d.%02d', intdiv($seconds, 60), $seconds % 60, $hundredths)
        : sprintf('%d.%02d s', $seconds, $hundredths);
}
