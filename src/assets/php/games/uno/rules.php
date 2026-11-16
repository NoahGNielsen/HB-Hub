<?php
// The rules of UNO as played here, shown on the UNO page and in the game, and in the help docs. In Danish, like the docs
// (a public page), also in the game; the cards keep the names the game shows them by. game.php and room.php play by
// them, so change them together.
?>
<ul class="casino-rule-list" lang="da">
    <li>
        For <?= HBHUB_UNO_MIN_PLAYERS ?> til <?= HBHUB_UNO_MAX_PLAYERS ?> spillere. Alle får <?= HBHUB_UNO_HAND ?> kort, og
        den første, der bliver af med alle sine kort, vinder. Værten starter spillet, når nogen er kommet med, og et nyt, når
        det er slut.
    </li>
    <li>
        Har nogen vundet? De andre spiller videre om 2.-, 3.-pladsen og så videre, til kun én af dem har kort tilbage
        (medmindre værten har sat spillet til at slutte ved første sejr). Når du er løbet tør for kort, ser du med, og at gå
        tæller ikke som et nederlag.
    </li>
    <li>
        I din tur lægger du et kort med samme farve, tal eller symbol som det øverste kort, eller et wild-kort. Et Wild lader
        dig vælge den farve, der skal spilles på. Det gør et Wild Draw Four også, og den næste spiller trækker 4 kort og
        mister sin tur. Det kan lægges når som helst.
    </li>
    <li>
        Skip: den næste spiller mister sin tur. Reverse: spillet går den anden vej rundt om bordet (med to spillere virker det
        som et Skip). Draw Two: den næste spiller trækker 2 kort og mister sin tur.
    </li>
    <li>
        Har du mere end ét kort med samme tal eller symbol? Læg dem sammen, uanset farve: klik på det, der passer på det
        øverste kort, og så de andre i den rækkefølge, du vil, og tryk på Play. Det sidste bestemmer, hvilken farve der skal
        spilles på. Hvert kort tæller, så to Skips springer to spillere over, og to Draw Twos giver 4 kort at trække.
        Wild-kort, og et kort, du lige har trukket, bliver lagt ét ad gangen.
    </li>
    <li>
        Kan du ikke, eller vil du ikke, lægge? Træk: du bliver ved med at trække, til du får et kort, der kan lægges
        (medmindre værten har sat det til ét kort), og så kan du lægge det med det samme eller beholde det.
    </li>
    <li>
        Med stabling slået til kan et Draw Two eller et Wild Draw Four besvares med et til, så det går videre til den næste
        spiller. Medmindre værten har sat stabling til kun samme kort, kan et Draw Two lægges på et Wild Draw Four og omvendt;
        ellers kan kun samme slags lægges. Kortene lægges sammen, og den, der ikke kan (eller vil) stable, trækker dem alle og
        mister sin tur.
    </li>
    <li>
        Tryk på UNO!, når du lægger dit næstsidste kort. Glemmer du det, kan alle fange dig, før den næste spiller laver sit
        træk, og så trækker du <?= HBHUB_UNO_CATCH_CARDS ?> kort. Du kan stadig selv trykke på den indtil da.
    </li>
    <li>
        Hver tur har en tidsgrænse (værten vælger 15, 30 eller 60 sekunder i spillets indstillinger). Løber tiden ud,
        trækker du et kort (eller de kort, der venter på dig), og din tur er slut. Værten kan sætte spillet på pause.
    </li>
    <li>
        Vinderen får point for de kort, der er tilbage på alle de andres hænder: talkort deres tal, Skip, Reverse og Draw Two
        20, og wild-kort 50.
    </li>
    <li>
        At forlade et spil, der bliver spillet, tæller som et nederlag (medmindre værten har sat det på pause), og dine kort
        bliver blandet tilbage i bunken. Lukker du spillet i <?= HBHUB_UNO_GRACE_SECONDS ?> sekunder, forlader du det også.
        Går alle de andre, vinder den sidste spiller tilbage.
    </li>
</ul>
