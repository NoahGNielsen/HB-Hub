<?php
// The guest Wordle (/play/wordle): the members' page (games/wordle/index.php), played without an account. See
// assets/php/guestGames/guestGames.php. Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('wordle', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/games/wordle/index.php';
