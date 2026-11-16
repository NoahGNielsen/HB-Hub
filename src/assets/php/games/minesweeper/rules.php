<?php
// The rules of Minesweeper as played here, shown on the Minesweeper page (games/minesweeper/) and in its help docs
// (docs/games/minesweeper.php). In Danish, like the docs (a public page), also on the game. The game is
// assets/minesweeper/js/main.js and cell.js, with the mines and the timing on the server
// (assets/php/games/minesweeper/minesweeper.php, HBHUB_MINESWEEPER_SIZES has the boards and their mines), so change
// them together.
?>
<ul class="casino-rule-list" lang="da">
    <li>Vælg en størrelse i bjælken under brættet: Easy er 9&times;9 med 10 miner, Medium 16&times;16 med 40 og Hard 30&times;30 med 160.</li>
    <li>Klik på et felt for at åbne det. Dit første klik er aldrig en mine og åbner altid et område: minerne bliver lagt ud efter det. Et tal er, hvor mange af de 8 felter rundt om det der er miner, og et felt uden miner rundt om sig åbner også sine naboer.</li>
    <li>Tror du, et felt er en mine? <kbd>Shift</kbd> + klik eller højreklik på det for at sætte et flag på det (på de fleste telefoner: hold fingeren på det), og gør det igen for at fjerne det. Tælleren til venstre er, hvor mange flag du har tilbage.</li>
    <li>Åbner du en mine, er spillet slut. Åbner du alle felter uden en, vinder du.</li>
    <li>Uret starter med dit første klik og stopper med det klik, der vinder. Din tid kommer på <a href="/games/minesweeper/leaderboard">ranglisten</a> for den størrelse, du spillede, medmindre den er hurtigere, end et menneske kan klikke (ingen bots).</li>
    <li>Klik på ansigtet for et nyt spil. Et spil, du forlader eller starter forfra, tæller som spillet, men ikke vundet.</li>
</ul>
