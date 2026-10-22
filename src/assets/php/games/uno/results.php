<?php
// How UNO games ended (HBHub-UnoResults), for the leaderboard (games/uno/leaderboard.php): one row per player per game,
// stored once the game is over (from room.php, in hbHubUnoPlay()'s transaction). Everyone dealt in gets one: the winner,
// everyone who lost, and everyone who left the game before it was over. Games stopped because everyone had gone aren't
// stored.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/game.php';

const HBHUB_UNO_RESULT_LOSS = 0; // playerResult
const HBHUB_UNO_RESULT_WIN = 1;
const HBHUB_UNO_RESULT_LEFT = 2; // left the game before it was over: a loss too

/**
 * Stores how the room's game ended for everyone who played it, once (it marks the game as recorded). Players whose
 * account is gone are left out.
 */
function hbHubUnoRecordResults(PDO $db, array &$room): void
{
    $game = &$room['state']['game'];
    if ($game === null || $game['result'] === null || $game['recorded']) {
        return;
    }
    $game['recorded'] = true;
    if ($game['result']['reason'] === 'stopped') {
        return;
    }

    $statement = $db->prepare('INSERT INTO `HBHub-UnoResults`
            (roomId, roomType, userId, playerResult, playerPoints, playerCardsLeft, gamePlayers, gameTurns, resultTimestamp)
        SELECT ?, ?, userId, ?, ?, ?, ?, ?, UTC_TIMESTAMP()
        FROM `HBHub-Users` WHERE userId = ?');
    $winner = $game['result']['winner'];
    foreach ($game['players'] as $player) {
        $isWinner = $player['seat'] === $winner;
        $statement->execute([
            $room['roomId'],
            $room['roomType'],
            match (true) {
                $isWinner => HBHUB_UNO_RESULT_WIN,
                $player['out'] => HBHUB_UNO_RESULT_LEFT,
                default => HBHUB_UNO_RESULT_LOSS,
            },
            $isWinner ? $game['result']['points'] : 0,
            count($player['hand']),
            count($game['players']),
            $game['turns'],
            $player['userId'],
        ]);
    }
}
