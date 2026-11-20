<?php
// Which Doodle Chain games were played (HBHub-DoodleResults), for the admin panel's overview (admin/overview.php) and a
// user's data export (admin/user.php): one row per player per game, stored once the last step is over and the chains
// are shown (from room.php, in hbHubDoodlePlay()'s transaction). Doodle Chain has no winners, so a row only says
// whether the player was still in the room at the end, how much they handed in, and how many played. The texts and
// drawings themselves aren't in it, so the rows stay when the room's next game deletes those.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/game.php';

const HBHUB_DOODLE_RESULT_FINISHED = 0; // playerLeft in HBHub-DoodleResults: still in the room when the chains were shown
const HBHUB_DOODLE_RESULT_LEFT = 1; // left the room before the game was over

/**
 * Stores the room's game for everyone who was dealt in. Call it once, as the game goes from 'playing' to 'reveal'.
 * Players whose account is gone are left out.
 *
 * @param list<int> $seatedIds who still sits in the room
 */
function hbHubDoodleRecordResults(PDO $db, array $room, array $seatedIds): void
{
    $game = $room['state']['game'];
    if ($game === null) {
        return;
    }

    // What each of them handed in in this game
    $statement = $db->prepare('SELECT userId, COUNT(*) FROM `HBHub-DoodleEntries` WHERE roomId = ? AND gameNumber = ? GROUP BY userId');
    $statement->execute([$room['roomId'], $game['number']]);
    $entries = array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));

    $statement = $db->prepare('INSERT INTO `HBHub-DoodleResults`
            (roomId, roomType, userId, playerLeft, playerEntries, gamePlayers, resultTimestamp)
        SELECT ?, ?, userId, ?, ?, ?, UTC_TIMESTAMP()
        FROM `HBHub-Users` WHERE userId = ?');
    foreach ($game['order'] as $userId) {
        $statement->execute([
            $room['roomId'],
            $room['roomType'],
            in_array($userId, $seatedIds, true) ? HBHUB_DOODLE_RESULT_FINISHED : HBHUB_DOODLE_RESULT_LEFT,
            $entries[$userId] ?? 0,
            count($game['order']),
            $userId,
        ]);
    }
}
