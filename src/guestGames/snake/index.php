<?php
// The guest Snake (/play/snake): the members' page (games/snake/index.php), played without an account and only in the
// browser (no server game, no leaderboard; the best scores in localStorage). See assets/php/guestGames/guestGames.php.
// Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('snake', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/games/snake/index.php';
