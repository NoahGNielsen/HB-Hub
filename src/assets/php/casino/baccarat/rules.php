<?php
// The rules of Baccarat as played here, shown on the Baccarat page and at the table. cards.php, round.php and room.php
// play by them, so change them together.
?>
<ul class="casino-rule-list">
    <li>
        Bet on which hand ends closest to 9: <strong class="casino-bac-player">Player</strong> or
        <strong class="casino-bac-banker">Banker</strong>, or that they tie. Nobody plays the hands, the cards are dealt by
        fixed rules.
    </li>
    <li>Aces count as 1, 2-9 as their number, and 10s, jacks, queens and kings as 0. Only the last digit of a total counts, so 7 + 8 is 5.</li>
    <li>
        Both hands get two cards. With an 8 or 9 (a natural) in either, both stand. Otherwise the Player takes a third card on
        0-5 and stands on 6-7. Then the Banker: standing on 7, and when the Player stood, taking a card on 0-5. When the
        Player took a third card, the Banker takes one on 0-2, on 3 unless that card was an 8, on 4 when it was 2-7, on 5
        when it was 4-7 and on 6 when it was a 6 or 7.
    </li>
    <li>
        Player pays 1:1. Banker pays 1:1 less <?= HBHUB_BACCARAT_BANKER_COMMISSION / 10 ?>% of the win (rounded to a whole
        chip), as the Banker wins a little more often. You get your bet back on either when they tie. Tie pays 8:1. Player
        Pair and Banker Pair pay 11:1 when that hand's first two cards are the same rank, like two kings.
    </li>
    <li>
        You can bet on Player or Banker, not both, and on Tie and the pairs as well. Bets go in steps of
        <?= HBHUB_BACCARAT_BET_STEP ?>, and together have to be at least the table's minimum bet.
    </li>
    <li>
        Played with <?= HBHUB_BACCARAT_DECKS ?> decks. Once 75% of the shoe has been dealt, all of them are shuffled into a
        new shoe before the next hand, and the table tells everyone.
    </li>
    <li>
        Play solo against the dealer, or with up to <?= HBHUB_BACCARAT_SEATS ?> players at a table, everyone betting on
        the same hands against the house, not each other. Betting closes <?= HBHUB_BACCARAT_BETTING_MS / 1000 ?> seconds
        after the first bet, or as soon as everyone has bet.
    </li>
    <li>Joining during a hand, you're in from the next one. At a table for more players, sit out to skip hands without leaving.</li>
</ul>
