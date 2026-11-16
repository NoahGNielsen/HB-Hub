<?php
// The game in the guest Minesweeper's frame (/play/minesweeper/frame): the members' frame (games/minesweeper/frame.php),
// sending its squares to /play/minesweeper/move instead. It's only ever opened by /play/minesweeper, which has already
// sent members and browsers that have logged in before to the normal game, so this only checks the minimum: someone
// who's logged in (say, in another tab since) gets the members' frame, and while the Games aren't open to everyone it
// isn't there.
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
$minesweeperGuest = true;

require $_SERVER['DOCUMENT_ROOT'] . '/games/minesweeper/frame.php';
