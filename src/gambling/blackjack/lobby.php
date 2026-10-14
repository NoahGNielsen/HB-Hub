<?php
// The Blackjack lobby's lists, for blackjackLobby.js, which asks every few seconds: /gambling/blackjack/lobby is
// {"rooms": the public tables, "invites": the tables the user is invited to, "seat": the room id of the user's own
// table or null}. Every visit also tidies up a few tables nobody has open anymore (hbHubBlackjackSweep() in room.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';
hbHubCasinoJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

hbHubBlackjackSweep($db);

echo json_encode([
    'rooms' => hbHubBlackjackPublicRooms($db),
    'invites' => hbHubBlackjackInvitesFor($db, $userId),
    'seat' => hbHubBlackjackUserSeat($db, $userId)['roomId'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE);
