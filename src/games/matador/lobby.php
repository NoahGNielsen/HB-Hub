<?php
// The Matador lobby's lists, for matadorLobby.js, which asks every few seconds: /games/matador/lobby is
// {"rooms": the public games waiting for players, "invites": the games the user can join from an invite, "seat": the
// room id of the user's own game or null}. Every visit also tidies up a few games nobody has open anymore
// (hbHubMatadorSweep() in room.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubMatadorSweep($db);

echo json_encode([
    'rooms' => hbHubMatadorPublicRooms($db),
    'invites' => hbHubMatadorInvitesFor($db, $userId),
    'seat' => hbHubMatadorUserSeat($db, $userId)['roomId'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE);
