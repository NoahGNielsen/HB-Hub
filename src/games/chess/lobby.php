<?php
// The chess lobby's lists, for chessLobby.js, which asks every few seconds: /games/chess/lobby is
// {"rooms": the public games waiting for a player, "invites": the games the user can join from an invite, "seat": the
// room id of the user's own game or null}. Every visit also tidies up a few games nobody has open anymore
// (hbHubChessSweep() in room.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubChessSweep($db);

echo json_encode([
    'rooms' => hbHubChessPublicRooms($db),
    'invites' => hbHubChessInvitesFor($db, $userId),
    'seat' => hbHubChessUserSeat($db, $userId)['roomId'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE);
