<?php
// Help docs: how the casino works - the chip wallet (assets/php/casino/wallet.php: the start and daily chips, the 3-hour
// bonus and when a casino day starts), the tables (public, code and solo, plus invites, like casino/blackjack/room.php), the
// leaderboards, and what chips are (the terms' "The casino and chips"). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';

$docsPath = '/docs/casino/';
$docsIntro = 'Blackjack, Baccarat, Roulette og Slots, spillet med chips, du får gratis. Der er ingen rigtige penge med
    nogen steder, og intet, du vinder, kan udbetales.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Til at starte</dt><dd><?= number_format(HBHUB_CASINO_START_CHIPS, 0, ',', '.') ?> chips</dd></div>
                        <div><dt>Hver dag</dt><dd>+<?= number_format(HBHUB_CASINO_DAILY_CALENDAR[0], 0, ',', '.') ?> til <?= number_format(max(HBHUB_CASINO_DAILY_CALENDAR), 0, ',', '.') ?> chips</dd></div>
                        <div><dt>Hver 3. time</dt><dd>+<?= HBHUB_CASINO_BONUS_PERCENT ?> % af dagens chips</dd></div>
                        <div><dt>Ny dag</dt><dd>kl. <?= HBHUB_CASINO_DAY_START ?></dd></div>
                        <div><dt>Spil</dt><dd>4</dd></div>
                    </dl>

                    <section id="chips" aria-labelledby="docs-chips">
                        <h2 id="docs-chips">Dine chips</h2>
                        <p>
                            Alle konti starter med <strong><?= number_format(HBHUB_CASINO_START_CHIPS, 0, ',', '.') ?> chips</strong>.
                            Alle casinospillene deler dem, så det, du vinder i Blackjack, kan du satse i Roulette. Dine chips står på
                            <a href="/casino/">casinoets forside</a> og i topbjælken på alle casinosider.
                        </p>
                    </section>

                    <section id="daily" aria-labelledby="docs-daily">
                        <h2 id="docs-daily">Daglige chips</h2>
                        <p>
                            Tryk på <strong>Collect daily chips</strong> på casinoets forside for gratis chips, én gang om dagen. En
                            ny casinodag starter kl. <?= HBHUB_CASINO_DAY_START ?> (dansk tid), og indtil da viser knappen, hvor lang
                            tid der er tilbage.
                        </p>
                        <p>
                            Hvor mange du får, følger en <strong>kalender på <?= HBHUB_CASINO_DAILY_DAYS ?> dage</strong>: dag 1 er
                            <strong><?= number_format(HBHUB_CASINO_DAILY_CALENDAR[0], 0, ',', '.') ?> chips</strong>, og hver dag i
                            træk flytter dig én dag videre. De fleste dage giver mere end dagen før, og nogle få giver lidt mindre,
                            slutningen af hver uge er en bonus, og dag <?= HBHUB_CASINO_DAILY_DAYS ?> er
                            <strong><?= number_format(max(HBHUB_CASINO_DAILY_CALENDAR), 0, ',', '.') ?> chips</strong>. Derefter
                            starter kalenderen forfra. Har du sprunget en dag over? Så er du tilbage på dag 1, og missede dage hober
                            sig ikke op.
                        </p>
                        <p>
                            De daglige chips er <strong>én gang per person</strong>, ikke per konto. Når én af dine konti (dem, der
                            har været brugt i den samme browser) har hentet dem, får dine andre konti kun
                            <strong><?= HBHUB_CASINO_DAILY_ALT_PERCENT ?> %</strong> af deres dags chips den dag. Deres kalender
                            går stadig videre som normalt.
                        </p>
                    </section>

                    <section id="bonus" aria-labelledby="docs-bonus">
                        <h2 id="docs-bonus">3-timersbonus</h2>
                        <p>
                            Når du har hentet dine daglige chips, kan du hente en bonus på
                            <strong><?= HBHUB_CASINO_BONUS_PERCENT ?> %</strong> af dem hver 3. time med
                            <strong>Collect</strong> i boksen, der dukker op nederst til højre på casinoets sider, når den er klar. Den første er klar 3 timer efter de daglige
                            chips, og den næste 3 timer efter, at du har hentet den forrige, så bonusser, du ikke henter, hober
                            sig ikke op. Fik du kun <?= HBHUB_CASINO_DAILY_ALT_PERCENT ?> % på en af dine andre konti, er bonussen
                            <?= HBHUB_CASINO_BONUS_PERCENT ?> % af det.
                        </p>
                        <p>
                            Bonussen gælder kun den casinodag, du hentede de daglige chips. Ville den næste først være klar efter
                            kl. <?= HBHUB_CASINO_DAY_START ?>, er der ikke flere den dag, og næste dags daglige chips starter den forfra.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="docs-games">
                        <h2 id="docs-games">Spillene</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Spil</th><th scope="col">Sådan spilles det</th><th scope="col">Spillere</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><a href="/docs/casino/blackjack">Blackjack</a></td><td>Kom tættere på 21 end dealeren uden at komme over.</td><td>Alene, eller op til 5 ved et bord</td></tr>
                                    <tr><td><a href="/docs/casino/baccarat">Baccarat</a></td><td>Sats på, hvilken hånd der ender tættest på 9.</td><td>Alene, eller op til 6 ved et bord</td></tr>
                                    <tr><td><a href="/docs/casino/roulette">Roulette</a></td><td>Sats på, hvor kuglen lander.</td><td>Ét bord for alle</td></tr>
                                    <tr><td><a href="/docs/casino/slots">Slots</a></td><td>Drej tre hjul, og få symbolerne på linje.</td><td>Alene</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Borde, koder og invitationer</h2>
                        <p>Blackjack og Baccarat spilles ved borde. Åbn et fra spillets side, eller gå med ved et, der allerede er der:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Slags bord</th><th scope="col">Hvem kan være med</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Offentligt</td><td>Alle. Det står i spillets lobby.</td></tr>
                                    <tr><td>Kode</td><td>Alle med dets kode på 6 tegn. Skriv den under <strong>Join a table</strong> på spillets side.</td></tr>
                                    <tr><td>Alene</td><td>Kun dig og dealeren.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Værten kan også invitere folk til bordet. De får en <a href="/docs/notifications">notifikation</a>, og
                            bordet bliver vist under <strong>Your invites</strong> på spillets side.
                        </p>
                        <p>
                            Værten vælger bordets navn og dets mindsteindsats (10, 50, 100 eller 500 chips). Alle spillere spiller mod
                            huset, aldrig mod hinanden. Du kan gå med ved et bord midt i en hånd, og du får kort fra den næste.
                            <strong>Sit out</strong> springer hænder over uden at opgive din plads.
                        </p>
                        <p>
                            Lukker du bordet, bliver din plads holdt i et minut, hvis du kommer tilbage. Derefter har du forladt det.
                            Indsatser lukker af sig selv efter et stykke tid, og det gør ture også, så ingen kan få et bord til at vente.
                        </p>
                    </section>

                    <section id="leaderboards" aria-labelledby="docs-leaderboards">
                        <h2 id="docs-leaderboards">Ranglister</h2>
                        <p>
                            <a href="/casino/leaderboard">Casinoets rangliste</a> viser, hvor mange chips alle har vundet eller tabt i
                            hvert spil og i dem alle tilsammen, og deres chips lige nu. De daglige chips tæller ikke som vundet. Hvert
                            spil har også sin egen rangliste, med hænder (eller spins), sejre, nederlag og sejrsprocent, plus den
                            største gevinst, og hvor meget du har vundet eller tabt i Roulette og Slots. Klik på en kolonnes overskrift
                            for at sortere efter den. Sejrsprocenten tæller først, når du har spillet 20 hænder eller spins (50 i
                            Slots), så én heldig runde ikke kan komme øverst.
                        </p>
                        <p>
                            <a href="/casino/">Casinoets forside</a> viser top 5 på casinoets rangliste og din egen plads på den, og
                            under hvert spil, hvor mange borde der er åbne, og hvor mange der spiller lige nu.
                        </p>
                    </section>

                    <section id="not-money" aria-labelledby="docs-not-money">
                        <h2 id="docs-not-money">Chips er ikke penge</h2>
                        <ul>
                            <li>Chips kan ikke byttes til rigtige penge, gavekort eller noget andet, og intet, du vinder, kan udbetales.</li>
                            <li>Sælg, køb eller sats ikke chips, konti eller resultater for rigtige penge eller rigtige ting med hinanden.</li>
                            <li>Side-admins kan tilføje eller tage chips væk, fx for at rette en fejl, eller når chips er vundet ved snyd.</li>
                        </ul>
                        <p>
                            Det hele står i <a href="/legal/terms#chips">Casinoet og chips</a> i vilkårene. Føler du, at du spiller for
                            meget, kan du <a href="/docs/self-blocking">blokere casinoet for dig selv</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
