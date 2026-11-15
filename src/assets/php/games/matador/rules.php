<?php
// The rules of Matador as played here, shown on the Matador page and in the game, and in the help docs. In Danish, like
// the docs (a public page) and Matador's own game terms, also in the game. game.php and room.php play by them, so
// change both together. The amounts that differ between editions (board.php) are the game's own edition's in a game
// ($room), and Classic's everywhere else.
$rulesFields = hbHubMatadorFields(is_array($room['state'] ?? null) ? $room['state'] : []);
$rulesExtraTax = array_values(array_filter($rulesFields, static fn (array $info): bool => $info['type'] === 'extraTax'))[0]['amount'] ?? 0;
?>
<ul class="casino-rule-list" lang="da">
    <li>
        Værten vælger udgaven i rummets indstillinger, før spillet starter: Classic, det originale danske bræt, HTX, skolens
        eget (indtil videre det samme som Classic), eller Kaos.
    </li>
    <li>
        Kaos er det klassiske bræt, men alle felter undtagen de fire hjørner bliver lagt et tilfældigt sted, når spillet
        starter. Lander du på Prøv lykken, er der <?= HBHUB_MATADOR_KAOS_PERCENT ?> % chance for Kaos-kortet i stedet for det
        øverste kort i bunken, og det blander alle felterne igen. Grunde beholder deres huse og pantsætning, når de flytter,
        brikkerne bliver stående, hvor de står, og et kort, der sender dig til en gade eller færge, sender dig derhen, hvor
        den er nu. Kaos-kortet bytter også rundt på spillerne: alles grunde går til en tilfældig spiller, og alles penge går
        også til en tilfældig spiller, trukket for sig. Du kan være heldig at beholde dine egne. Åbne handelstilbud bliver
        trukket tilbage.
    </li>
    <li>
        Alle starter med <?= hbHubMatadorKr(HBHUB_MATADOR_START_CASH) ?> på START. Rækkefølgen bliver trukket, når spillet
        starter. Du får <?= hbHubMatadorKr(HBHUB_MATADOR_START_PAY) ?>, hver gang du passerer eller lander på START.
    </li>
    <li>
        Du slår med én terning. En 6'er giver dig et slag til, så mange gange du slår den. Værten kan vælge 2 terninger i
        rummets indstillinger, før spillet starter: så giver to ens dig et slag til i stedet.
    </li>
    <li>
        Lander du på en gade, en færge eller et bryggeri, som ingen ejer, kan du købe den. Gør du ikke det, bliver den i
        banken, og den næste spiller, der lander dér, kan købe den.
    </li>
    <li>
        Lander du på en andens grund, betaler du dem leje. Ejer man alle gader i en farve, fordobles lejen, så længe der ikke
        er bygget. Færger tager mere, jo flere af dem ejeren har, og bryggerier tager 100 eller 200 gange dit slag.
    </li>
    <li>
        Ejer du alle gader i en farve, kan du bygge huse på dem i din tur, jævnt, og et hotel efter 4 huse. Banken har
        <?= HBHUB_MATADOR_BANK_HOUSES ?> huse og <?= HBHUB_MATADOR_BANK_HOTELS ?> hoteller. Sælger du dem tilbage, får du
        halvdelen af prisen. Værten kan ændre det i rummets indstillinger: lade dig bygge på alle gader, du ejer (så kommer de
        op jævnt på tværs af de gader i farven, du har), eller stadig kræve hele farven, men bygge på dens gader i vilkårlig
        rækkefølge.
    </li>
    <li>
        Pantsæt en grund i din tur for halvdelen af dens pris (sælg først husene i dens farve). Der bliver ikke betalt leje
        for den, mens den er pantsat, og at indfri den koster <?= HBHUB_MATADOR_INTEREST_PERCENT ?> % oveni.
    </li>
    <li>
        Indkomstskat: betal <?= hbHubMatadorKr(HBHUB_MATADOR_TAX_FIXED) ?> eller <?= HBHUB_MATADOR_TAX_PERCENT ?> % af alt,
        hvad du er værd, valgt før det bliver regnet ud. Ekstraordinær statsskat er <?= hbHubMatadorKr($rulesExtraTax) ?>.
        Disse skatter, og det, Prøv lykken-kortene får dig til at betale, går ind på Parkerings konto: lander du på
        Parkering, får du alt, hvad der er på den. Værten kan slå det fra i rummets indstillinger, og så går pengene til
        banken, og Parkering er et gratis hvil.
    </li>
    <li>
        De fængsles sender dig i fængsel. For at komme ud betaler du <?= hbHubMatadorKr(HBHUB_MATADOR_BAIL) ?>, før du slår,
        bruger et "benådet"-kort eller slår en 6'er (to ens med 2 terninger). Efter <?= HBHUB_MATADOR_JAIL_TRIES ?> forsøg
        betaler du og rykker det, du slog. Om du stadig får leje, mens du sidder i fængsel, er en indstilling i rummet: ja
        (standard), nej, eller den bliver betalt til banken i stedet.
    </li>
    <li>Prøv lykken: træk et kort, og gør, hvad det siger. "Benådet"-kortene bliver gemt, til de bliver brugt eller byttet væk.</li>
    <li>
        Handl med de andre når som helst, også i deres ture: grunde (uden noget bygget på dine gader i deres farve), penge
        og "benådet"-kort. Den, der får en pantsat grund, betaler de <?= HBHUB_MATADOR_INTEREST_PERCENT ?> % i rente på den
        med det samme. Et tilbud til dig dukker op. Du kan minimere det for at kigge på brættet først, men så udløber det,
        hvis du ikke har svaret inden for <?= HBHUB_MATADOR_TRADE_SECONDS ?> sekunder.
    </li>
    <li>
        Kan du ikke betale? Sælg huse, pantsæt eller handl for at skaffe pengene, eller gå fallit: alt går til den, du
        skylder (eller tilbage til banken). Den sidste spiller tilbage vinder.
    </li>
    <li>
        Du har <?= HBHUB_MATADOR_TURN_SECONDS ?> sekunder til at gøre noget i din tur, og tiden starter forfra med alt, hvad
        du gør. Løber tiden ud, går turen videre: en grund, du landede på, bliver i banken. Mens du skylder penge, er uret
        sat på pause, og spillet venter på, at du betaler eller går fallit.
    </li>
    <li>
        Spillet bliver gemt efter hvert træk, i den gemmeplads, der tilhører den, der åbnede det. Går du, bliver din plads
        holdt til dig, mens dine ture bliver sprunget over. Værten kan give en tom plads til en anden eller gemme spillet og
        stoppe det.
    </li>
    <li>
        At gå, mens spillet bliver spillet (eller lukke det i <?= HBHUB_MATADOR_GRACE_SECONDS ?> sekunder), tæller som et
        nederlag på ranglisten, også hvis du kommer tilbage, medmindre værten har sat spillet på pause.
    </li>
    <li>
        Værten kan sætte spillet på pause. Ingen kan slå, bygge eller handle, og ingen tur løber ud eller bliver sprunget
        over, før værten fortsætter. Så får den spiller, hvis tur det er, sine fulde <?= HBHUB_MATADOR_TURN_SECONDS ?>
        sekunder igen.
    </li>
</ul>
