<?php
// The rules of Tetris as played here, shown on the Tetris page (games/tetris/) and in its help docs
// (docs/games/tetris.php). In Danish, like the docs (a public page), also on the game. The game is
// assets/js/blockfall.js and the server's side assets/php/games/blockfall/blockfall.php (GRAVITY, LINES_PER_LEVEL, the
// lock delay, LINE_POINTS, SPIN_POINTS, FULL_CLEAR_POINTS, the back-to-back and the pauses are in both), so change them
// together.
?>
<ul class="casino-rule-list" lang="da">
    <li>Brikkerne falder ned i en brønd, der er 10 felter bred og 20 høj. Flyt dem med <kbd>&larr;</kbd> <kbd>&rarr;</kbd> (eller <kbd>A</kbd> <kbd>D</kbd>), drej dem med <kbd>&uarr;</kbd> eller <kbd>X</kbd> (med uret) og <kbd>Z</kbd> (mod uret), og lad dem falde hurtigere med <kbd>&darr;</kbd> eller med det samme med <kbd>Mellemrum</kbd>. På en telefon bruger du knapperne under brønden.</li>
    <li>Fyld en hel vandret linje, så forsvinder den. 1, 2, 3 eller 4 linjer på én gang giver 100, 300, 500 eller 800 point gange dit level, og hver række, du selv taber en brik, giver 1 point (2 med <kbd>Mellemrum</kbd>).</li>
    <li><strong>Spins:</strong> Drejer du en brik (alle undtagen den firkantede, så også Z, I, J og L) ind på en plads, hvor den hverken kan flyttes til siden eller op, og den sidder fast uden at være faldet eller flyttet siden, er det et spin. Et spin giver 100 point uden linjer og 800, 1.200, 1.600 eller 2.600 for 1-4 linjer, gange dit level.</li>
    <li><strong>Back-to-back:</strong> Fire linjer på én gang og spins med linjer er svære. Laver du to svære i træk (uden almindelige linjer imellem), giver den næste halvanden gang så mange point.</li>
    <li><strong>Full clear:</strong> Tømmer du hele brønden, får du 800, 1.200, 1.800 eller 2.000 point ekstra (for 1-4 linjer) gange dit level.</li>
    <li>Tryk <kbd>C</kbd> eller <kbd>Shift</kbd> for at lægge brikken til side under <strong>Hold</strong> og tage den frem igen senere (én gang pr. brik). Under <strong>Next</strong> ser du de næste tre brikker, og alle syv slags kommer én gang i hver runde af syv.</li>
    <li>En brik, der lander, ligger et halvt sekund, før den sidder fast, så du kan nå at skubbe eller dreje den på plads. Når der ikke er plads til den næste brik i toppen, er spillet slut.</li>
    <li>Spil, til brønden er fuld. Det går hurtigere for hver 10 linjer, hele vejen op til level 20. Fra level 21 falder brikkerne helt ned med det samme, og for hvert level derefter sidder de lidt hurtigere fast. Din bedste score kommer på ranglisten.</li>
    <li>Tryk <kbd>Mellemrum</kbd>, <kbd>Enter</kbd> eller <strong>Play</strong> for at starte, og <kbd>P</kbd> eller <kbd>Esc</kbd> for pause. Spillet går også selv på pause, når du skifter væk fra siden. Du kan holde pause op til 10 gange i et spil; holder du flere, tæller spillet ikke på ranglisten.</li>
</ul>
