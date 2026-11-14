<?php
// The guest Minesweeper (/play/minesweeper): the members' page (games/minesweeper/index.php), played without an
// account, with the game in a frame of its own (/play/minesweeper/frame). See assets/php/guestGames/guestGames.php.
// Someone who's logged in is sent to the members' page instead.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
$guestGame = hbHubGuestGamePage('minesweeper', $hbHubUser);

require $_SERVER['DOCUMENT_ROOT'] . '/games/minesweeper/index.php';
