<?php
// The guest Baccarat (/play/baccarat): a solo table with play chips, on the members' table page
// (gambling/baccarat/table.php), without an account. See assets/php/guestGames/guestGames.php. Someone who's logged
// in is sent to the members' Baccarat page instead, where tables are made and joined.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('baccarat', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/gambling/baccarat/table.php';
