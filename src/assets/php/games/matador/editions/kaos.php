<?php
// The Kaos edition of Matador: the classic board and cards (classic.php), but 'shuffle' puts the fields in a random
// order when the game starts (board.php's layout; the four corners stay put). On top of the classic cards there's the
// Kaos card (number HBHUB_MATADOR_KAOS_CARD), which isn't in the deck: a Prøv lykken is it HBHUB_MATADOR_KAOS_PERCENT
// of the time instead of the top card. It mixes the fields up again, and the players swap their properties and, with a
// draw of its own, their money (game.php's hbHubMatadorShuffleBoard() and hbHubMatadorSwapPlayers()).
// board.php loads it through hbHubMatadorEdition(). The card text is Danish, like the others.
// (This file runs inside hbHubMatadorEdition(), so its variable has a name that one doesn't use.)
$kaosEdition = require __DIR__ . '/classic.php';
$kaosEdition['shuffle'] = true;
$kaosEdition['cards'][HBHUB_MATADOR_KAOS_CARD] = [
    'text' => 'KAOS! Alle felterne på brættet bytter plads. Og så bytter spillerne grunde - og hver for sig også penge.',
    'do' => ['kaos'],
];
return $kaosEdition;
