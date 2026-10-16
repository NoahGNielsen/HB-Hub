<?php
// How chess games ended (HBHub-ChessResults), for the leaderboard (games/chess/leaderboard.php): one row per player per
// game, stored once the game is over (from room.php, in hbHubChessPlay()'s transaction). Aborted games, and games
// stopped because both players had gone, aren't stored (HBHUB_CHESS_UNCOUNTED).
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/game.php';

const HBHUB_CHESS_RESULT_LOSS = 0; // playerResult
const HBHUB_CHESS_RESULT_DRAW = 1;
const HBHUB_CHESS_RESULT_WIN = 2;

/**
 * Stores how the room's game ended for both players, once (it marks the game as recorded). Players whose account is
 * gone are left out.
 */
function hbHubChessRecordResults(PDO $db, array &$room): void
{
    $game = &$room['state']['game'];
    if ($game === null || $game['result'] === null || $game['recorded']) {
        return;
    }
    $game['recorded'] = true;
    if (in_array($game['result']['reason'], HBHUB_CHESS_UNCOUNTED, true)) {
        return;
    }

    $statement = $db->prepare('INSERT INTO `HBHub-ChessResults`
            (roomId, roomType, userId, opponentUserId, playerColour, playerResult, resultReason, gameMoves, gameClock, resultTimestamp)
        SELECT ?, ?, userId, (SELECT o.userId FROM `HBHub-Users` o WHERE o.userId = ?), ?, ?, ?, ?, ?, UTC_TIMESTAMP()
        FROM `HBHub-Users` WHERE userId = ?');
    foreach (['w' => 'white', 'b' => 'black'] as $colour => $side) {
        $winner = $game['result']['winner'];
        $statement->execute([
            $room['roomId'],
            $room['roomType'],
            $game[$side === 'white' ? 'black' : 'white']['userId'],
            $colour,
            match ($winner) {
                null => HBHUB_CHESS_RESULT_DRAW,
                $colour => HBHUB_CHESS_RESULT_WIN,
                default => HBHUB_CHESS_RESULT_LOSS,
            },
            $game['result']['reason'],
            intdiv(count($game['moves']) + 1, 2), // full moves, White's last one counting
            $room['state']['clock'] !== 'none' ? $room['state']['clock'] : null,
            $game[$side]['userId'],
        ]);
    }
}
