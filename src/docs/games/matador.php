<?php
// Help docs: Matador - what's on the board, a turn, the game's own rules (assets/php/games/matador/rules.php,
// included as the game shows them, with room.php's money and constants), the Danish words, the room settings (with the edition) and
// saving a game (save.php). Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';

$docsPath = '/docs/games/matador';
$docsIntro = 'Det danske Matador. Slå med terningen, køb gader, færger og bryggerier, byg huse og hoteller, og vær den
    sidste tilbage. Åbn et spil på <a href="/games/matador/">Matador</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd><?= HBHUB_MATADOR_MIN_PLAYERS ?>-<?= HBHUB_MATADOR_MAX_PLAYERS ?></dd></div>
                        <div><dt>Startpenge</dt><dd><?= hbHubMatadorKr(HBHUB_MATADOR_START_CASH) ?></dd></div>
                        <div><dt>Forbi START</dt><dd>+<?= hbHubMatadorKr(HBHUB_MATADOR_START_PAY) ?></dd></div>
                        <div><dt>Tid per tur</dt><dd><?= HBHUB_MATADOR_TURN_SECONDS ?> s</dd></div>
                    </dl>

                    <section id="turn" aria-labelledby="docs-turn">
                        <h2 id="docs-turn">En tur</h2>
                        <ol class="docs-steps">
                            <li><strong>Slå.</strong> Ryk så mange felter, som terningen viser. En 6'er giver dig et slag til.</li>
                            <li><strong>Gør det, feltet siger.</strong> Køb en grund, ingen ejer, betal leje på en andens, træk et Prøv lykken-kort, betal skat eller gå i fængsel.</li>
                            <li><strong>Byg, pantsæt eller handl</strong>, hvis du vil, før du afslutter din tur.</li>
                            <li><strong>Afslut turen</strong>, og det er den næste spillers tur.</li>
                        </ol>
                        <p>Alt, hvad du gør, starter dine <?= HBHUB_MATADOR_TURN_SECONDS ?> sekunder forfra, så tag dig god tid, bare gå ikke din vej.</p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/rules.php'; ?>
                    </section>

                    <section id="words" aria-labelledby="docs-words">
                        <h2 id="docs-words">Ordene på brættet</h2>
                        <p>Kender du Monopoly, er det her, hvad Matadors felter svarer til:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">På brættet</th><th scope="col">I Monopoly</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Prøv lykken</td><td>Chance</td></tr>
                                    <tr><td>Parkering</td><td>Free Parking</td></tr>
                                    <tr><td>De fængsles</td><td>Go to Jail</td></tr>
                                    <tr><td>Fængsel</td><td>Jail</td></tr>
                                    <tr><td>"Benådet"-kort</td><td>Get Out of Jail Free</td></tr>
                                    <tr><td>Indkomstskat</td><td>Income Tax</td></tr>
                                    <tr><td>Ekstraordinær statsskat</td><td>Super Tax</td></tr>
                                    <tr><td>Færgerne</td><td>Jernbanerne</td></tr>
                                    <tr><td>Bryggerierne</td><td>Værkerne (utilities)</td></tr>
                                    <tr><td>kr.</td><td>Danske kroner, spillets penge</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="settings" aria-labelledby="docs-settings">
                        <h2 id="docs-settings">Rummets indstillinger</h2>
                        <p>Før spillet starter, kan værten ændre et par husregler i rummets indstillinger:</p>
                        <ul>
                            <li><strong>Edition</strong> (udgave): hvilket bræt og hvilke Prøv lykken-kort spillet bliver spillet med. Classic er det originale danske bræt (standard), HTX er skolens eget, som indtil videre er det samme som Classic, og Kaos er det klassiske bræt med alle felter undtagen hjørnerne et tilfældigt sted. I Kaos har et Prøv lykken <?= HBHUB_MATADOR_KAOS_PERCENT ?> % chance for at være Kaos-kortet, som blander felterne igen (grunde beholder deres huse og pantsætning), og derefter giver alles grunde til en tilfældig spiller og, trukket for sig, alles penge til en tilfældig spiller (du kan beholde dine egne).</li>
                            <li><strong>Terninger</strong>: én terning (en 6'er slår igen), eller to (to ens slår igen).</li>
                            <li><strong>Parkerings konto</strong>: skatter og betalinger fra Prøv lykken går til den, der lander på Parkering (slået til som standard), eller til banken.</li>
                            <li><strong>Leje i fængsel</strong>: om du stadig får leje, mens du sidder i fængsel, ikke gør, eller den går til banken.</li>
                            <li><strong>At bygge huse og hoteller</strong>: kun når du ejer alle gader i en farve, og jævnt (standard), med hele farven, men ikke jævnt, eller på alle gader, du ejer.</li>
                        </ul>
                    </section>

                    <section id="saves" aria-labelledby="docs-saves">
                        <h2 id="docs-saves">At gemme og fortsætte</h2>
                        <p>
                            Et spil bliver gemt efter hvert træk, i den gemmeplads, der tilhører den, der åbnede det. Alle har én
                            plads, og starter du et nyt spil selv, bruger det den. Går du, venter din plads på dig, mens dine ture
                            bliver sprunget over. Værten kan give en tom plads til en anden eller trykke på <strong>Save &amp; stop</strong> og
                            fortsætte spillet fra Matador-siden en anden dag. De andre får en notifikation, når det fortsætter. Et
                            spil, der er vundet, er væk fra pladsen, da der ikke er mere at spille.
                        </p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Et par tips</h2>
                        <ul>
                            <li>En hel farve fordobler sin leje, allerede før du bygger, og lader dig bygge. Handl for at gøre én færdig.</li>
                            <li>Tre huse på en gade er tit det søde punkt: lejen stiger meget fra to til tre.</li>
                            <li>Hold nogle penge tilbage. Lander du på et hotel uden noget i banken, skal du sælge eller pantsætte i en fart.</li>
                            <li>Handelstilbud kan laves når som helst, også i andre spilleres ture, med gader, penge og "benådet"-kort.</li>
                        </ul>
                        <p>
                            Hvert spil tæller med på <a href="/games/matador/leaderboard">Matador-ranglisten</a>, med dine sejre og
                            din hurtigste sejr. At forlade et spil, mens det bliver spillet, tæller som et nederlag med det samme, også
                            hvis du kommer tilbage, medmindre værten havde sat det på pause.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
