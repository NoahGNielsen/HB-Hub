<?php
// The rules of Doodle Chain, shown on the game's page and in the game, and in the help docs. In Danish, like the docs (a
// public page), also in the game; buttons keep the English names the game shows them by. game.php and room.php play
// by them, so change them together.
?>
<ul class="casino-rule-list" lang="da">
    <li>
        For <?= HBHUB_DOODLE_MIN_PLAYERS ?> til <?= HBHUB_DOODLE_MAX_PLAYERS ?> spillere. Værten starter spillet, når der er
        nok med, og et nyt, når det er slut.
    </li>
    <li>
        Først skriver alle en sætning, som en anden skal tegne (<?= HBHUB_DOODLE_WRITE_SECONDS ?> sekunder). Så går
        sætningerne videre til den næste, som tegner den, de får. Tegningen går videre til den næste igen, som skriver, hvad
        de tror, den forestiller, og sådan bliver det ved: skriv, tegn, skriv, tegn.
    </li>
    <li>
        Hvor mange tegninger der er i hver kæde, afhænger af, hvor mange I er: 2 tegninger med 3 til 6 spillere, 3 med 7
        eller 8 og 4 med 9 eller 10. En kæde slutter altid med et gæt, så med 3 til 6 spillere er der 5 runder: skriv,
        tegn, gæt, tegn, gæt.
    </li>
    <li>
        Hver kæde kommer forbi alle de andre, før den kommer tilbage til nogen. Kun med 3 eller 4 spillere får du en
        kæde igen, du har haft før. Du ser kun det, du lige har fået, aldrig det, der kom før.
    </li>
    <li>
        Tryk på <strong>Done</strong>, når du er færdig. Runden slutter, når alle er færdige, eller tiden er gået. Når tiden
        løber ud, bliver det, du har skrevet eller tegnet, sendt videre, som det er.
    </li>
    <li>
        Til sidst viser værten kæderne for alle, én del ad gangen, så I kan se, hvordan det startede, og hvor galt det gik.
    </li>
    <li>
        Går du midt i et spil, spiller de andre videre uden dig. Den, der skulle have haft din del, får i stedet den seneste
        tekst eller tegning fra længere tilbage i kæden.
    </li>
    <li>
        Tegninger og tekster følger de samme regler som alt andet på HB Hub (se
        <a href="/legal/code-of-conduct">retningslinjerne</a>). De slettes, når værten starter et nyt spil, eller alle har
        forladt spillet.
    </li>
</ul>
