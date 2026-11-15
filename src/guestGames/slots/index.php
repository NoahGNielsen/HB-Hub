<?php
// The guest Slots (/play/slots): the members' page (casino/slots/index.php), played with play chips without an
// account. See assets/php/guestGames/guestGames.php. Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('slots', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/casino/slots/index.php';
