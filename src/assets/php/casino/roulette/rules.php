<?php
// The rules of Roulette as played here, shown on the Roulette page and in the help docs. In Danish, like the docs (a
// public page), also on the game. roulette.php and table.php play by them, so change them together. They're the same
// for the free table at /play/roulette, which is the guest's own, so the shared table's part says it's for members.
require_once __DIR__ . '/table.php';
?>
<ul class="casino-rule-list" lang="da">
    <li>Ét hjul med tallene 1-36 (halvdelen røde, halvdelen sorte) og ét grønt 0. Alle tal er lige sandsynlige.</li>
    <li>Klik på et tal for at satse på det. Klik på stregen mellem to tal for en split, hjørnet mellem fire for et hjørne (corner), og den yderste kant af en række for rækken (street) eller to rækker (line). Højreklik på en indsats for at tage en chip af den.</li>
    <li>
        Udbetalinger: et tal 35:1, en split 17:1, en street eller 0-1-2 / 0-2-3 11:1, et hjørne eller 0-1-2-3 8:1, en line
        5:1, et dusin eller en kolonne 2:1, og rød, sort, ulige, lige, 1-18 og 19-36 1:1.
    </li>
    <li>Lander kuglen på 0, taber alle indsatser uden for tallene (dusiner, kolonner, rød, sort og så videre).</li>
    <li>
        Indsatser går i trin af <?= HBHUB_ROULETTE_BET_STEP ?>. Medlemmer med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips
        kan i stedet gå <strong>all in</strong>: alle deres chips på én indsats.
    </li>
    <li>
        Medlemmer spiller alle ved ét bord: alle ser alles indsatser, og der bliver drejet én kugle for dem alle. Den første
        indsats på bordet starter et ur på <?= intdiv(HBHUB_ROULETTE_BET_MS, 1000) ?> sekunder, og alle kan lægge indsatser på
        eller tage dem af, indtil det løber ud. Tryk på Ready (eller Mellemrum), når du er færdig: så kan dine indsatser ikke
        ændres, og når alle med indsatser er klar, drejer hjulet med det samme.
    </li>
    <li>
        Dine chips bliver først taget, når hjulet drejer. Har du ikke chips nok til dine indsatser til den tid, fx fordi du
        har brugt dem ved et andet bord, bliver dine indsatser taget af, og intet bliver taget eller betalt.
    </li>
    <li>Efter et spin lægger Same again (eller Mellemrum) dine sidste indsatser tilbage på bordet. På det gratis bord bliver dine indsatser bare liggende, og Mellemrum drejer igen.</li>
</ul>
