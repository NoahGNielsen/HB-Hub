<?php
// The rules of Blackjack as played here, shown on the Blackjack page and at the table. round.php and room.php play by them,
// so change both together.
?>
<ul class="casino-rule-list">
    <li>Cards 2-10 count as their number, jacks, queens and kings as 10, and aces as 1 or 11.</li>
    <li>Played with 6 decks, shuffled again once about 75% of them have been dealt.</li>
    <li>A blackjack (an ace and a 10-value card as your first two cards) pays 3:2. A win pays 1:1, and a tie (push) gives your bet back.</li>
    <li>The dealer takes cards until 17, and stands on every 17, soft 17 too.</li>
    <li>With an ace or a 10 showing, the dealer checks for blackjack first. With a dealer blackjack the hand ends straight away.</li>
    <li>Double down on your first two cards: double your bet and get exactly one more card. You can double after splitting too.</li>
    <li>Split two cards of the same value (like a king and a queen) into two hands, up to 3 hands. Split aces get one card each, and 21 after a split pays 1:1.</li>
    <li>Insurance: when the dealer shows an ace you can bet half your bet that the dealer has blackjack. It pays 2:1, and is settled separately from your hand.</li>
    <li>Bets go in steps of 10. You have <?= HBHUB_BLACKJACK_TURN_MS / 1000 ?> seconds for each move. When the time runs out, your hand stands.</li>
    <li>
        Up to <?= HBHUB_BLACKJACK_SEATS ?> players at a table, each playing against the dealer, not each other. Players take turns from seat 1.
        Betting closes <?= HBHUB_BLACKJACK_BETTING_MS / 1000 ?> seconds after the first bet, or as soon as everyone has bet.
    </li>
    <li>
        Joining during a hand, you're dealt in from the next one. Sit out to skip hands without leaving.
        If you're away for <?= HBHUB_BLACKJACK_AWAY_SECONDS ?> seconds your hand stands, and after <?= HBHUB_BLACKJACK_GRACE_SECONDS ?> seconds you leave the table.
    </li>
</ul>
