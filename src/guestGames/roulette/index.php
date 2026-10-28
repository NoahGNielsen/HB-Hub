<?php
// The guest Roulette (/play/roulette): the members' page (gambling/roulette/index.php), played with play chips without
// an account. See assets/php/guestGames/guestGames.php. Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('roulette', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/gambling/roulette/index.php';
