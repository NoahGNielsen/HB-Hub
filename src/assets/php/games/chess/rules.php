<?php
// The rules of chess as played here, shown on the chess page and in the game, and in the help docs. In Danish, like the
// docs (a public page), also in the game. board.php, game.php and room.php play by them, so change them together.
?>
<ul class="casino-rule-list" lang="da">
    <li>
        Skakkens normale regler, med rokade og en passant. Når en anden spiller er kommet med, starter værten spillet.
    </li>
    <li>
        En bonde på den sidste række kan kun blive til en brik, din modstander har taget fra dig: en dronning, når de har
        taget din dronning, et tårn, når de har taget et tårn, og så videre. Indtil de har taget en af dine brikker (bønder
        tæller ikke), forbliver en bonde, der kommer derhen, en bonde og kan ikke flytte. I det øjeblik en af dine brikker
        bliver taget, kan du gøre den til den brik: det er dit træk.
    </li>
    <li>
        Værten vælger uret, og hvem der spiller hvid, i spillets indstillinger, indtil de starter spillet, eller mellem
        spil.
    </li>
    <li>
        Skakmat vinder. Pat, den samme stilling tre gange, 50 træk hver uden et slag eller et bondetræk, og for få brikker
        tilbage til, at nogen kan sætte mat, er alle remis, med det samme.
    </li>
    <li>
        Med et ur bliver den tid, du bruger på et træk, trukket fra dit ur (hvids første træk er gratis), og de ekstra
        sekunder bliver lagt til efter hvert træk. Løber din tid ud, taber du, medmindre din modstander kun har sin konge
        tilbage, eller en konge og én løber eller springer. Så er det remis.
    </li>
    <li>
        Du kan opgive eller tilbyde remis når som helst og afbryde spillet, indtil I begge har lavet et træk (et afbrudt spil
        tæller ikke). Et remistilbud bliver trukket tilbage, når din modstander spiller et træk i stedet for at svare på det.
    </li>
    <li>
        At forlade spillet taber det, medmindre værten har sat det på pause: så slutter det bare og tæller ikke for nogen af
        jer. Har din modstanders spil været lukket i <?= HBHUB_CHESS_CLAIM_SECONDS ?> sekunder, kan du kræve sejren, og har I
        begge været væk så længe, at I går, er det et nederlag for begge.
    </li>
    <li>
        Værten kan sætte spillet på pause. Urene stopper, og ingen kan trække eller tilbyde remis, før værten fortsætter, og
        værten kan ikke kræve sejren, mens det er sat på pause (det kan modstanderen stadig, hvis værtens spil er lukket).
    </li>
    <li>Når spillet er slut, kan I begge bede om en revanche. I bytter farver.</li>
    <li>
        Med en mus kan du trække med højre knap for at tegne en pil eller højreklikke på et felt for at farve det (hold Shift,
        Ctrl eller Alt nede for andre farver). Kun du ser dem, og et venstreklik på brættet eller det næste træk rydder dem.
    </li>
</ul>
