<?php
// The guest Blockfall (/play/blockfall): the members' page (games/blockfall/index.php), played without an account and
// only in the browser (no server game, no leaderboard; the bests in localStorage). See
// assets/php/guestGames/guestGames.php. Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('blockfall', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/games/blockfall/index.php';
