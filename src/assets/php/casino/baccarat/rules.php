<?php
// The rules of Baccarat as played here, shown on the Baccarat page and at the table, and in the help docs. In Danish,
// like the docs (a public page), also at the table; Player and Banker keep the names the table shows them by.
// cards.php, round.php and room.php play by them, so change them together.
?>
<ul class="casino-rule-list" lang="da">
    <li>
        Sats på, hvilken hånd der ender tættest på 9: <strong class="casino-bac-player">Player</strong> eller
        <strong class="casino-bac-banker">Banker</strong>, eller at de ender uafgjort. Ingen spiller hænderne, kortene bliver
        givet efter faste regler.
    </li>
    <li>Esser tæller 1, 2-9 deres tal, og 10'ere, knægte, damer og konger 0. Kun det sidste ciffer i en total tæller, så 7 + 8 er 5.</li>
    <li>
        Begge hænder får to kort. Har en af dem 8 eller 9 (en natural), står begge. Ellers tager Player et tredje kort på 0-5
        og står på 6-7. Så Banker: står på 7, og når Player stod, tager den et kort på 0-5. Når Player tog et tredje kort,
        tager Banker et på 0-2, på 3 medmindre det kort var en 8'er, på 4 når det var 2-7, på 5 når det var 4-7 og på 6 når
        det var en 6'er eller 7'er.
    </li>
    <li>
        Player betaler 1:1. Banker betaler 1:1 minus <?= HBHUB_BACCARAT_BANKER_COMMISSION / 10 ?> % af gevinsten (rundet til en
        hel chip), da Banker vinder lidt oftere. Du får din indsats på dem tilbage, når de ender uafgjort. Tie betaler 8:1.
        Player Pair og Banker Pair betaler 11:1, når den hånds første to kort har samme værdi, som to konger.
    </li>
    <li>
        Du kan satse på Player eller Banker, ikke begge, og på Tie og parrene også. Indsatser går i trin af
        <?= HBHUB_BACCARAT_BET_STEP ?> og skal tilsammen være mindst bordets mindsteindsats.
    </li>
    <li>
        Medlemmer med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips kan i stedet gå <strong>all in</strong>: alle deres
        chips på ét felt, ved ethvert bord.
    </li>
    <li>
        Spilles med <?= HBHUB_BACCARAT_DECKS ?> kortspil. Når 75 % af skoen er givet, bliver de alle blandet ind i en ny sko
        før næste hånd, og bordet fortæller det til alle.
    </li>
    <li>
        Spil alene mod dealeren, eller med op til <?= HBHUB_BACCARAT_SEATS ?> spillere ved et bord, hvor alle satser på de
        samme hænder mod huset, ikke mod hinanden. Indsatserne lukker <?= HBHUB_BACCARAT_BETTING_MS / 1000 ?> sekunder efter
        den første indsats, eller så snart alle har satset.
    </li>
    <li>Kommer du med midt i en hånd, er du med fra den næste. Ved et bord for flere spillere kan du bruge Sit out for at springe hænder over uden at gå.</li>
</ul>
