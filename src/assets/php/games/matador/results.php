<?php
// How Matador games ended (HBHub-MatadorResults), for the leaderboard (games/matador/leaderboard.php): one row per
// player, stored once a game has been won (from room.php's hbHubMatadorPlay(), in its transaction). A seat counts for
// whoever played it last - the player who took it over when the host gave it away.
require_once __DIR__ . '/../../database.php';

/**
 * Stores how the game in the room ended for every player in it. Players whose account is gone are left out.
 */
function hbHubMatadorRecordResults(PDO $db, array $room): void
{
    $state = $room['state'];
    if ($state['winner'] === null) {
        return;
    }

    $statement = $db->prepare('INSERT INTO `HBHub-MatadorResults`
            (roomId, roomType, userId, seatNumber, playersInGame, playerWon, gameRounds, resultTimestamp)
        SELECT ?, ?, userId, ?, ?, ?, ?, UTC_TIMESTAMP() FROM `HBHub-Users` WHERE userId = ?');
    $counted = [];
    foreach ($state['players'] as $player) {
        $userId = $player['originalUserId'];
        if ($userId === null || isset($counted[$userId])) {
            continue; // one row per user, should they somehow have had two seats
        }
        $counted[$userId] = true;
        $statement->execute([
            $room['roomId'],
            $room['roomType'],
            $player['seat'],
            count($state['players']),
            $player['seat'] === $state['winner'] ? 1 : 0,
            $state['round'],
            $userId,
        ]);
    }
}
