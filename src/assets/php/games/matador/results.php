<?php
// How Matador games ended (HBHub-MatadorResults), for the leaderboard (games/matador/leaderboard.php): one row per
// player, stored once a game has been won (from room.php's hbHubMatadorPlay(), in its transaction). A seat counts for
// whoever played it last - the player who took it over when the host gave it away.
// Someone who leaves a game while it's being played, and the host hasn't paused it, gets a lost row straight away
// (hbHubMatadorRecordLeft(), from room.php's hbHubMatadorRemoveSeat()), and none when the game ends.
require_once __DIR__ . '/../../database.php';

/**
 * Stores how the game in the room ended for every player in it. Players whose account is gone, and players who already
 * lost it by leaving (forfeited in the state), are left out.
 */
function hbHubMatadorRecordResults(PDO $db, array $room): void
{
    $state = $room['state'];
    if ($state['winner'] === null) {
        return;
    }

    $counted = array_fill_keys($state['forfeited'] ?? [], true);
    foreach ($state['players'] as $player) {
        $userId = $player['originalUserId'];
        if ($userId === null || isset($counted[$userId])) {
            continue; // one row per user, should they somehow have had two seats
        }
        $counted[$userId] = true;
        hbHubMatadorInsertResult($db, $room, $player['seat'], $userId, $player['seat'] === $state['winner'], false);
    }
}

/**
 * The user leaves the game in the room while it's being played: stores it as a loss for them, once per game, and
 * notes it in the state (forfeited) so the game's end doesn't count them again. Not while the host has paused it, and
 * not for a player who's already bankrupt (the game's end counts their loss).
 */
function hbHubMatadorRecordLeft(PDO $db, array &$room, int $seat, int $userId): void
{
    $state = &$room['state'];
    $player = hbHubMatadorPlayerOf($state, $seat);
    if ($state['phase'] !== 'playing' || hbHubMatadorIsPaused($state) || $player === null || $player['bankrupt']
        || in_array($userId, $state['forfeited'] ?? [], true)) {
        return;
    }
    $state['forfeited'][] = $userId;
    hbHubMatadorInsertResult($db, $room, $seat, $userId, false, true);
}

function hbHubMatadorInsertResult(PDO $db, array $room, int $seat, int $userId, bool $won, bool $left): void
{
    $db->prepare('INSERT INTO `HBHub-MatadorResults`
            (roomId, roomType, userId, seatNumber, playersInGame, playerWon, playerLeft, gameRounds, resultTimestamp)
        SELECT ?, ?, userId, ?, ?, ?, ?, ?, UTC_TIMESTAMP() FROM `HBHub-Users` WHERE userId = ?')
        ->execute([
            $room['roomId'],
            $room['roomType'],
            $seat,
            count($room['state']['players']),
            $won ? 1 : 0,
            $left ? 1 : 0,
            $room['state']['round'],
            $userId,
        ]);
}
