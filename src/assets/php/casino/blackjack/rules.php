<?php
// The rules of Blackjack as played here, shown on the Blackjack page and at the table, and in the help docs. In Danish,
// like the docs (a public page), also at the table. round.php and room.php play by them, so change both together.
?>
<ul class="casino-rule-list" lang="da">
    <li>Kort fra 2 til 10 tæller deres tal, knægte, damer og konger tæller 10, og esser tæller 1 eller 11.</li>
    <li>Spilles med 6 kortspil, som blandes igen, når cirka 75 % af dem er givet.</li>
    <li>En blackjack (et es og et kort til 10 som dine første to kort) betaler 3:2. En sejr betaler 1:1, og uafgjort (push) giver dig din indsats tilbage.</li>
    <li>Dealeren tager kort, til den har 17, og står på alle 17, også blød 17.</li>
    <li>Viser dealeren et es eller en 10'er, tjekker den først for blackjack. Har dealeren blackjack, slutter hånden med det samme.</li>
    <li>Double down på dine første to kort: fordobl din indsats, og få præcis ét kort mere. Du kan også doble efter en split.</li>
    <li>Split to kort med samme værdi (som en konge og en dame) i to hænder, op til 3 hænder. Splittede esser får ét kort hver, og 21 efter en split betaler 1:1.</li>
    <li>Forsikring (insurance): viser dealeren et es, kan du satse halvdelen af din indsats på, at dealeren har blackjack. Den betaler 2:1 og bliver gjort op for sig, adskilt fra din hånd.</li>
    <li>
        Indsatser går i trin af 10. Medlemmer med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips kan i stedet gå
        <strong>all in</strong>: satse alle deres chips, ved ethvert bord. En blackjack på en ulige indsats betaler 3:2,
        rundet ned.
    </li>
    <li>Du har <?= HBHUB_BLACKJACK_TURN_MS / 1000 ?> sekunder til hvert træk. Løber tiden ud, står din hånd.</li>
    <li>
        Op til <?= HBHUB_BLACKJACK_SEATS ?> spillere ved et bord, som hver spiller mod dealeren, ikke mod hinanden. Spillerne
        tager tur fra plads 1. Indsatserne lukker <?= HBHUB_BLACKJACK_BETTING_MS / 1000 ?> sekunder efter den første indsats,
        eller så snart alle har satset.
    </li>
    <li>
        Kommer du med midt i en hånd, får du kort fra den næste. Sit out for at springe hænder over uden at gå.
    </li>
</ul>
