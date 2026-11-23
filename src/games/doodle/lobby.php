<?php
// The Doodle Chain lobby's list, for doodleLobby.js, which asks every few seconds: /games/doodle/lobby is
// {"rooms": the public games that can be joined, "seat": the room id of the user's own game or null, "watch": the public
// games being played that can be watched, for lobbyWatch.js (hbHubDoodleWatchRooms())}. Every visit also
// tidies up a few games nobody has open anymore (hbHubDoodleSweep() in room.php).
// About every 15 seconds navUpdates.js sends the nav bar's request along (?nav=), answered in "nav" too (hbHubNavRide()
// in assets/php/navUpdates.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubDoodleSweep($db);

echo json_encode([
    'rooms' => hbHubDoodlePublicRooms($db),
    'watch' => hbHubDoodleWatchRooms($db),
    'seat' => hbHubDoodleUserSeat($db, $userId)['roomId'] ?? null,
] + array_filter(['nav' => hbHubNavRide($db, $hbHubUser)]), JSON_INVALID_UTF8_SUBSTITUTE);
