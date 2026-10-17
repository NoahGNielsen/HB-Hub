<?php
// The Baccarat lobby's lists, for baccaratLobby.js, which asks every few seconds: /gambling/baccarat/lobby is
// {"rooms": the public tables, "invites": the tables the user is invited to, "seat": the room id of the user's own
// table or null}. Every visit also tidies up a few tables nobody has open anymore (hbHubBaccaratSweep() in room.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubBaccaratSweep($db);

echo json_encode([
    'rooms' => hbHubBaccaratPublicRooms($db),
    'invites' => hbHubBaccaratInvitesFor($db, $userId),
    'seat' => hbHubBaccaratUserSeat($db, $userId)['roomId'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE);
