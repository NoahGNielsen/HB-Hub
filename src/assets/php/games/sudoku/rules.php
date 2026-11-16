<?php
// The rules of Sudoku as played here, shown on the Sudoku page (games/sudoku/) and in its help docs
// (docs/games/sudoku.php). In Danish, like the docs (a public page), also on the game. The levels (and how many numbers
// each starts with) are HBHUB_SUDOKU_LEVELS in assets/php/games/sudoku/sudoku.php, and the anticheat's floor is
// HBHUB_SUDOKU_MIN_MS_PER_SQUARE there, so change them together. Not for the free Sudoku at /play/sudoku, which has no
// leaderboard and its own rules.
?>
<ul class="casino-rule-list" lang="da">
    <li>Udfyld brættet, så hver række, hver kolonne og hver 3&times;3-boks har hvert af tallene 1 til 9 præcis én gang.</li>
    <li>Vælg et niveau: Easy starter med cirka 38 tal udfyldt, Medium cirka 32, Hard cirka 28 og Expert så få som muligt (som regel 22 til 25). Hver opgave har præcis én løsning, og Easy og Medium kan altid løses uden at gætte.</li>
    <li>Klik på et felt, og så på et tal under brættet (eller skriv det). <kbd>Backspace</kbd> eller Erase tager det ud igen, og piletasterne flytter rundt.</li>
    <li>Slå Notes til (eller tryk <kbd>N</kbd>) for at skrive små tal i et felt, for dem det kunne være. Sætter du et tal i et felt, bliver det fjernet fra noterne i dets række, kolonne og boks.</li>
    <li>To af det samme tal i en række, kolonne eller boks bliver røde. Brættet fortæller dig ikke på andre måder, når et tal er forkert.</li>
    <li>Sidder du fast? Hint udfylder det felt, du har valgt (eller et tilfældigt), med det rigtige tal. Undo (<kbd>Ctrl</kbd> + <kbd>Z</kbd>) tager dit sidste træk tilbage.</li>
    <li>Uret er HB Hubs eget og kører kun, mens siden er på skærmen, og spillet ikke er sat på pause. Dine tal og noter bliver gemt i denne browser, så du kan lukke siden og fortsætte senere.</li>
    <li>En opgave løst uden et hint kommer på <a href="/games/sudoku/leaderboard">ranglisten</a> for sit niveau. En med et hint tæller som løst, men ikke dér, og det samme gør en, der er udfyldt hurtigere, end et menneske kan (mindre end et sekund per felt: ingen løsere eller scripts). At starte et nyt spil afslutter det, du var i gang med.</li>
</ul>
