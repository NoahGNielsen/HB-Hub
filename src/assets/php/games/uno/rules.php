<?php
// The rules of UNO as played here, shown on the UNO page and in the game. game.php and room.php play by them, so change
// them together.
?>
<ul class="casino-rule-list">
    <li>
        For <?= HBHUB_UNO_MIN_PLAYERS ?> to <?= HBHUB_UNO_MAX_PLAYERS ?> players. Everyone gets <?= HBHUB_UNO_HAND ?>
        cards, and the first one to get rid of all their cards wins. The host starts the game once someone has joined,
        and a new one when it's over.
    </li>
    <li>
        On your turn, play a card with the same colour, number or symbol as the top card, or a wild card. A Wild lets you
        pick the colour to play on. A Wild Draw Four does too, and the next player draws 4 cards and misses their turn.
        It can be played at any time.
    </li>
    <li>
        Skip: the next player misses their turn. Reverse: play goes the other way round the table (with two players it
        works like a Skip). Draw Two: the next player draws 2 cards and misses their turn.
    </li>
    <li>
        Can't or don't want to play? Draw: you keep drawing until you get a card that can be played (unless the host
        has set it to one card), then you can play it straight away or keep it.
    </li>
    <li>
        With stacking on, a Draw Two can be answered with another Draw Two, and a Wild Draw Four with another Wild Draw
        Four. The cards add up, and whoever can't (or won't) stack draws them all and misses their turn.
    </li>
    <li>
        Press UNO! when you play your second-to-last card. If you forget, anyone can catch you before the next player
        makes their move, and you draw <?= HBHUB_UNO_CATCH_CARDS ?> cards. You can still press it yourself until then.
    </li>
    <li>
        Every turn has a timer (the host picks 15, 30 or 60 seconds in the game's settings). Run out of time and you
        draw a card (or the cards waiting for you), and your turn is over. The host can pause the game.
    </li>
    <li>
        The winner scores the cards left in everyone else's hands: number cards their number, Skip, Reverse and Draw Two
        20, and wild cards 50.
    </li>
    <li>
        Leaving a game that's being played counts as a loss (unless the host has paused it), and your cards are
        shuffled back into the deck. Close the game for <?= HBHUB_UNO_GRACE_SECONDS ?> seconds and you leave it too. If
        everyone else leaves, the last player left wins.
    </li>
</ul>
