<?php
// The rules of chess themselves, with no database and no players: the position, which moves are legal, playing one,
// writing it down the usual way (SAN, like "Nxe5+") and whether the pieces left can still give mate. game.php plays a
// game with it, and is the only one that calls this.
//
// A position is an array:
//   board: 64 characters, a1 first, then b1 ... h1, a2 ... h8 (square = rank * 8 + file, both from 0). "." is an empty
//          square, PNBRQK are White's pieces and pnbrqk Black's
//   turn: "w" or "b", who moves
//   castling: the castling still allowed, out of "KQkq" ("" when none): K White short, Q White long, k and q Black's
//   ep: the square a pawn can be taken en passant on (the one it just jumped over), or null
//   halfmove: moves since the last capture or pawn move, for the 50-move rule
//   fullmove: the move number, going up after Black's move
// A move is [from, to, promotion]: squares, and "q", "r", "b" or "n" for a pawn reaching the last rank (null otherwise).
//
// A pawn can only become a piece its player has lost (hbHubChessPromotions()): with their queen still on the board it
// can't become a queen. While the other player hasn't taken any of their pieces (pawns don't count), a pawn still goes
// to the last rank, but stays a pawn there, stuck (it can't move or take anything). Once one of their pieces is taken,
// becoming it is a move of its own: [square, the same square, promotion].

const HBHUB_CHESS_START_BOARD = 'RNBQKBNR' . 'PPPPPPPP' . '................................' . 'pppppppp' . 'rnbqkbnr';
const HBHUB_CHESS_PROMOTIONS = ['q', 'r', 'b', 'n'];
const HBHUB_CHESS_START_PIECES = ['q' => 1, 'r' => 2, 'b' => 2, 'n' => 2]; // how many of each a player starts with
const HBHUB_CHESS_KNIGHT_STEPS = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]]; // [file, rank]
const HBHUB_CHESS_KING_STEPS = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
const HBHUB_CHESS_ROOK_LINES = [[1, 0], [0, 1], [-1, 0], [0, -1]];
const HBHUB_CHESS_BISHOP_LINES = [[1, 1], [-1, 1], [-1, -1], [1, -1]];
// Castling: the king's move, the rook's move, the squares between that must be empty, and the squares the king stands
// on and passes over, which mustn't be attacked
const HBHUB_CHESS_CASTLES = [
    'K' => ['king' => [4, 6], 'rook' => [7, 5], 'empty' => [5, 6], 'safe' => [4, 5, 6]],
    'Q' => ['king' => [4, 2], 'rook' => [0, 3], 'empty' => [1, 2, 3], 'safe' => [4, 3, 2]],
    'k' => ['king' => [60, 62], 'rook' => [63, 61], 'empty' => [61, 62], 'safe' => [60, 61, 62]],
    'q' => ['king' => [60, 58], 'rook' => [56, 59], 'empty' => [57, 58, 59], 'safe' => [60, 59, 58]],
];
// The castling a king or rook takes away when it moves from (or is taken on) its square
const HBHUB_CHESS_CASTLING_SQUARES = [4 => 'KQ', 60 => 'kq', 0 => 'Q', 7 => 'K', 56 => 'q', 63 => 'k'];

function hbHubChessStartPosition(): array
{
    return ['board' => HBHUB_CHESS_START_BOARD, 'turn' => 'w', 'castling' => 'KQkq', 'ep' => null, 'halfmove' => 0, 'fullmove' => 1];
}

function hbHubChessOpponent(string $colour): string
{
    return $colour === 'w' ? 'b' : 'w';
}

/**
 * "e4" for the square, as the moves are sent and shown.
 */
function hbHubChessSquareName(int $square): string
{
    return chr(97 + $square % 8) . (intdiv($square, 8) + 1);
}

/**
 * The square named (as sent, like "e4"), or null when it isn't one.
 */
function hbHubChessSquare(mixed $name): ?int
{
    if (!is_string($name) || strlen($name) !== 2) {
        return null;
    }
    $file = ord($name[0]) - 97;
    $rank = ord($name[1]) - 49;
    return $file >= 0 && $file < 8 && $rank >= 0 && $rank < 8 ? $rank * 8 + $file : null;
}

/**
 * Whose the piece is: "w", "b", or null for an empty square.
 */
function hbHubChessColourOf(string $piece): ?string
{
    return $piece === '.' ? null : (ctype_upper($piece) ? 'w' : 'b');
}

/**
 * The square $file files and $rank ranks away from $square, or null when that's off the board.
 */
function hbHubChessStep(int $square, int $file, int $rank): ?int
{
    $file += $square % 8;
    $rank += intdiv($square, 8);
    return $file >= 0 && $file < 8 && $rank >= 0 && $rank < 8 ? $rank * 8 + $file : null;
}

/**
 * Whether the colour $by attacks $square on the board, so a king couldn't stand there.
 */
function hbHubChessAttacked(string $board, int $square, string $by): bool
{
    $piece = static fn (string $type): string => $by === 'w' ? strtoupper($type) : $type;

    // A white pawn takes upwards, so one that attacks the square stands a rank below it
    foreach ([-1, 1] as $file) {
        $from = hbHubChessStep($square, $file, $by === 'w' ? -1 : 1);
        if ($from !== null && $board[$from] === $piece('p')) {
            return true;
        }
    }
    foreach ([[HBHUB_CHESS_KNIGHT_STEPS, 'n'], [HBHUB_CHESS_KING_STEPS, 'k']] as [$steps, $type]) {
        foreach ($steps as [$file, $rank]) {
            $from = hbHubChessStep($square, $file, $rank);
            if ($from !== null && $board[$from] === $piece($type)) {
                return true;
            }
        }
    }
    foreach ([[HBHUB_CHESS_ROOK_LINES, 'r'], [HBHUB_CHESS_BISHOP_LINES, 'b']] as [$lines, $type]) {
        foreach ($lines as [$file, $rank]) {
            $from = hbHubChessStep($square, $file, $rank);
            while ($from !== null && $board[$from] === '.') {
                $from = hbHubChessStep($from, $file, $rank);
            }
            if ($from !== null && ($board[$from] === $piece($type) || $board[$from] === $piece('q'))) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Whether the colour's king is attacked on the board.
 */
function hbHubChessKingAttacked(string $board, string $colour): bool
{
    $king = strpos($board, $colour === 'w' ? 'K' : 'k');
    return $king !== false && hbHubChessAttacked($board, $king, hbHubChessOpponent($colour));
}

function hbHubChessInCheck(array $position): bool
{
    return hbHubChessKingAttacked($position['board'], $position['turn']);
}

/**
 * What a pawn of the colour can become: only the kinds of piece the other player has taken from them, as there are
 * fewer of them on the board than they started with. A piece a pawn became counts too, so a second queen needs the
 * first one taken as well. An empty list (nothing taken yet) means a pawn on the last rank stays a pawn for now.
 *
 * @return list<string>
 */
function hbHubChessPromotions(string $board, string $colour): array
{
    return array_values(array_filter(HBHUB_CHESS_PROMOTIONS, static fn (string $type): bool =>
        substr_count($board, $colour === 'w' ? strtoupper($type) : $type) < HBHUB_CHESS_START_PIECES[$type]));
}

/**
 * Every move the pieces of the player to move can make, before checking that it doesn't leave their own king attacked
 * (hbHubChessLegalMoves() does that). Castling is only added when it's allowed all the way.
 *
 * @return list<array{0: int, 1: int, 2: ?string}>
 */
function hbHubChessPseudoMoves(array $position): array
{
    $board = $position['board'];
    $turn = $position['turn'];
    $promotions = hbHubChessPromotions($board, $turn);
    $moves = [];

    for ($from = 0; $from < 64; $from++) {
        if (hbHubChessColourOf($board[$from]) !== $turn) {
            continue;
        }
        $type = strtolower($board[$from]);

        if ($type === 'p') {
            $forward = $turn === 'w' ? 1 : -1;
            $lastRank = $turn === 'w' ? 7 : 0;
            // A pawn waiting on the last rank: becoming each piece it can is a move, where it stands
            if (intdiv($from, 8) === $lastRank) {
                foreach ($promotions as $promotion) {
                    $moves[] = [$from, $from, $promotion];
                }
                continue;
            }
            // On the last rank once for each piece it can become, or as a pawn that waits there when there's none
            $addPawn = static function (int $to) use (&$moves, $from, $lastRank, $promotions): void {
                if (intdiv($to, 8) === $lastRank && $promotions !== []) {
                    foreach ($promotions as $promotion) {
                        $moves[] = [$from, $to, $promotion];
                    }
                } else {
                    $moves[] = [$from, $to, null];
                }
            };

            $one = hbHubChessStep($from, 0, $forward);
            if ($one !== null && $board[$one] === '.') {
                $addPawn($one);
                $two = hbHubChessStep($from, 0, 2 * $forward);
                if (intdiv($from, 8) === ($turn === 'w' ? 1 : 6) && $board[$two] === '.') {
                    $moves[] = [$from, $two, null];
                }
            }
            foreach ([-1, 1] as $file) {
                $to = hbHubChessStep($from, $file, $forward);
                if ($to !== null && (hbHubChessColourOf($board[$to]) === hbHubChessOpponent($turn) || $to === $position['ep'])) {
                    $addPawn($to);
                }
            }
            continue;
        }

        if ($type === 'n' || $type === 'k') {
            foreach ($type === 'n' ? HBHUB_CHESS_KNIGHT_STEPS : HBHUB_CHESS_KING_STEPS as [$file, $rank]) {
                $to = hbHubChessStep($from, $file, $rank);
                if ($to !== null && hbHubChessColourOf($board[$to]) !== $turn) {
                    $moves[] = [$from, $to, null];
                }
            }
            continue;
        }

        $lines = match ($type) {
            'r' => HBHUB_CHESS_ROOK_LINES,
            'b' => HBHUB_CHESS_BISHOP_LINES,
            default => [...HBHUB_CHESS_ROOK_LINES, ...HBHUB_CHESS_BISHOP_LINES], // the queen
        };
        foreach ($lines as [$file, $rank]) {
            $to = hbHubChessStep($from, $file, $rank);
            while ($to !== null) {
                $owner = hbHubChessColourOf($board[$to]);
                if ($owner === $turn) {
                    break;
                }
                $moves[] = [$from, $to, null];
                if ($owner !== null) {
                    break; // took a piece, can't go through it
                }
                $to = hbHubChessStep($to, $file, $rank);
            }
        }
    }

    // Castling: still allowed, nothing in between, and the king isn't in check and doesn't pass over an attacked square
    foreach (HBHUB_CHESS_CASTLES as $right => $castle) {
        if (!str_contains($position['castling'], $right) || ($right === strtoupper($right)) !== ($turn === 'w')) {
            continue;
        }
        $isFree = array_reduce($castle['empty'], static fn (bool $free, int $square): bool => $free && $board[$square] === '.', true);
        $isSafe = $isFree && array_reduce($castle['safe'],
            static fn (bool $safe, int $square): bool => $safe && !hbHubChessAttacked($board, $square, hbHubChessOpponent($turn)), true);
        $rook = $turn === 'w' ? 'R' : 'r';
        if ($isSafe && $board[$castle['rook'][0]] === $rook) {
            $moves[] = [$castle['king'][0], $castle['king'][1], null];
        }
    }

    return $moves;
}

/**
 * The position after the move. The move isn't checked: it has to be one of hbHubChessLegalMoves().
 */
function hbHubChessApply(array $position, array $move): array
{
    [$from, $to, $promotion] = $move;
    $board = $position['board'];
    $turn = $position['turn'];
    $piece = $board[$from];
    $type = strtolower($piece);
    $isCapture = $board[$to] !== '.';

    // En passant: the pawn goes diagonally to an empty square, and takes the pawn that jumped past it
    if ($type === 'p' && $to === $position['ep'] && $from % 8 !== $to % 8) {
        $board[$to + ($turn === 'w' ? -8 : 8)] = '.';
        $isCapture = true;
    }
    // Castling: the king goes two squares, and the rook jumps over it
    if ($type === 'k' && abs($to - $from) === 2) {
        foreach (HBHUB_CHESS_CASTLES as $castle) {
            if ($castle['king'] === [$from, $to]) {
                [$rookFrom, $rookTo] = $castle['rook'];
                $board[$rookTo] = $board[$rookFrom];
                $board[$rookFrom] = '.';
            }
        }
    }
    $board[$from] = '.'; // first, as a pawn waiting on the last rank becomes a piece where it stands
    $board[$to] = $promotion !== null ? ($turn === 'w' ? strtoupper($promotion) : $promotion) : $piece;

    $castling = $position['castling'];
    foreach ([$from, $to] as $square) {
        if (isset(HBHUB_CHESS_CASTLING_SQUARES[$square])) {
            $castling = str_replace(str_split(HBHUB_CHESS_CASTLING_SQUARES[$square]), '', $castling);
        }
    }

    return [
        'board' => $board,
        'turn' => hbHubChessOpponent($turn),
        'castling' => $castling,
        'ep' => $type === 'p' && abs($to - $from) === 16 ? intdiv($from + $to, 2) : null,
        'halfmove' => $type === 'p' || $isCapture ? 0 : $position['halfmove'] + 1,
        'fullmove' => $position['fullmove'] + ($turn === 'b' ? 1 : 0),
    ];
}

/**
 * Every move the player to move may make: the ones that don't leave their own king attacked.
 *
 * @return list<array{0: int, 1: int, 2: ?string}>
 */
function hbHubChessLegalMoves(array $position): array
{
    return array_values(array_filter(hbHubChessPseudoMoves($position),
        static fn (array $move): bool => !hbHubChessKingAttacked(hbHubChessApply($position, $move)['board'], $position['turn'])));
}

/**
 * The move written down the usual way (SAN): "e4", "Nbd7", "exd5", "O-O", "e8=Q", with "+" for check and "#" for mate.
 * $legal is hbHubChessLegalMoves() of the position, to tell which of two knights (say) moved.
 */
function hbHubChessSan(array $position, array $move, array $legal): string
{
    [$from, $to, $promotion] = $move;
    $board = $position['board'];
    $type = strtolower($board[$from]);

    if ($type === 'k' && abs($to - $from) === 2) {
        $san = $to > $from ? 'O-O' : 'O-O-O';
    } elseif ($type === 'p') {
        $isCapture = $from % 8 !== $to % 8;
        $san = ($isCapture ? hbHubChessSquareName($from)[0] . 'x' : '') . hbHubChessSquareName($to)
            . ($promotion !== null ? '=' . strtoupper($promotion) : '');
    } else {
        // Another piece of the same kind that can go to the same square: say which by its file, its rank, or both
        $others = array_filter($legal, static fn (array $other): bool => $other[1] === $to && $other[0] !== $from && $board[$other[0]] === $board[$from]);
        $which = '';
        if ($others !== []) {
            $sameFile = array_filter($others, static fn (array $other): bool => $other[0] % 8 === $from % 8) !== [];
            $sameRank = array_filter($others, static fn (array $other): bool => intdiv($other[0], 8) === intdiv($from, 8)) !== [];
            $name = hbHubChessSquareName($from);
            $which = !$sameFile ? $name[0] : (!$sameRank ? $name[1] : $name);
        }
        $san = strtoupper($type) . $which . ($board[$to] !== '.' ? 'x' : '') . hbHubChessSquareName($to);
    }

    $after = hbHubChessApply($position, $move);
    if (hbHubChessInCheck($after)) {
        $san .= hbHubChessLegalMoves($after) === [] ? '#' : '+';
    }
    return $san;
}

/**
 * What makes positions the same for threefold repetition: the pieces, who moves, the castling, and en passant only
 * when a pawn can really take that way ($legal is hbHubChessLegalMoves() of the position).
 */
function hbHubChessPositionKey(array $position, array $legal): string
{
    $ep = '-';
    foreach ($legal as [$from, $to]) {
        if ($to === $position['ep'] && strtolower($position['board'][$from]) === 'p') {
            $ep = (string) $to;
        }
    }
    return 'p' . hash('xxh64', $position['board'] . $position['turn'] . $position['castling'] . $ep);
}

/**
 * Whether neither player can ever give mate, so the game is a draw: only kings left, a king and one bishop or knight
 * against a king, or only bishops that all stand on the same colour of square.
 */
function hbHubChessInsufficientMaterial(string $board): bool
{
    $pieces = str_replace(['.', 'K', 'k'], '', $board);
    if (strpbrk($pieces, 'PpRrQq') !== false) {
        return false;
    }
    if (strlen($pieces) <= 1) {
        return true;
    }
    if (trim($pieces, 'Bb') !== '') {
        return false; // a knight with other pieces
    }

    $squareColours = [];
    for ($square = 0; $square < 64; $square++) {
        if ($board[$square] === 'B' || $board[$square] === 'b') {
            $squareColours[($square % 8 + intdiv($square, 8)) % 2] = true;
        }
    }
    return count($squareColours) === 1;
}

/**
 * Whether the colour has enough left to give mate at all: more than a lone king, or a king and one bishop or knight.
 * When the other player runs out of time and this one can't, it's a draw instead of a win.
 */
function hbHubChessCanMate(string $board, string $colour): bool
{
    $pieces = '';
    foreach (str_split(str_replace('.', '', $board)) as $piece) {
        if (hbHubChessColourOf($piece) === $colour && strtolower($piece) !== 'k') {
            $pieces .= strtolower($piece);
        }
    }
    return strpbrk($pieces, 'prq') !== false || strlen($pieces) >= 2;
}
