<?php
// The guest Minesweeper's frame (/play/minesweeper/frame), shown in /play/minesweeper: the members' frame
// (games/minesweeper/frame.php), with its clicks sent to /play/minesweeper/move. The page around it already sends
// members and browsers that have logged in before to the members' game, so here someone who's logged in just gets the
// members' frame, and while the games aren't open to everyone there's nothing.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
if ($hbHubUser !== null) {
    header('Location: /games/minesweeper/frame', true, 302);
    exit;
}
if (!hbHubGuestGameIsOpen(HBHUB_GUEST_GAMES['minesweeper'])) {
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}
$guestGame = HBHUB_GUEST_GAMES['minesweeper'] + ['key' => 'minesweeper'];

require $_SERVER['DOCUMENT_ROOT'] . '/games/minesweeper/frame.php';
