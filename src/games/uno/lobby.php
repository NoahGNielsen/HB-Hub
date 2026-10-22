<?php
// The UNO lobby's lists, for unoLobby.js, which asks every few seconds: /games/uno/lobby is
// {"rooms": the public games that can be joined, "invites": the games the user can join from an invite, "seat": the
// room id of the user's own game or null}. Every visit also tidies up a few games nobody has open anymore
// (hbHubUnoSweep() in room.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubUnoSweep($db);

echo json_encode([
    'rooms' => hbHubUnoPublicRooms($db),
    'invites' => hbHubUnoInvitesFor($db, $userId),
    'seat' => hbHubUnoUserSeat($db, $userId)['roomId'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE);
