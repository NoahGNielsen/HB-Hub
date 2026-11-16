<?php
// Help docs: UNO - the 108 cards, a turn, the game's own rules (assets/php/games/uno/rules.php, included as the game
// shows them), the host's settings (HBHUB_UNO_TURN_CHOICES and the rest in game.php) and scoring. Open to everyone.
// Built by assets/php/docs/top.php and bottom.php. The cards keep the names the game shows them by.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/room.php';

$docsPath = '/docs/games/uno';
$docsIntro = 'Læg samme farve eller tal, få de andre til at trække, og vær den første uden kort tilbage. Bare glem ikke at
    trykke UNO! Spil det på <a href="/games/uno/">UNO</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd><?= HBHUB_UNO_MIN_PLAYERS ?>-<?= HBHUB_UNO_MAX_PLAYERS ?></dd></div>
                        <div><dt>Kort hver</dt><dd><?= HBHUB_UNO_HAND ?></dd></div>
                        <div><dt>Bunke</dt><dd>108 kort</dd></div>
                        <div><dt>Glemt UNO</dt><dd>+<?= HBHUB_UNO_CATCH_CARDS ?> kort</dd></div>
                    </dl>

                    <section id="cards" aria-labelledby="docs-cards">
                        <h2 id="docs-cards">Kortene</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Kort</th><th scope="col">Hvad det gør</th><th scope="col">I bunken</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>0-9</td><td>Intet særligt. Læg det på samme farve eller tal.</td><td>76 (ét 0 og to af 1-9 i hver farve)</td></tr>
                                    <tr><td>Skip</td><td>Den næste spiller mister sin tur.</td><td>8</td></tr>
                                    <tr><td>Reverse</td><td>Spillet går den anden vej rundt. Med to spillere er det et Skip.</td><td>8</td></tr>
                                    <tr><td>Draw Two</td><td>Den næste spiller trækker 2 og mister sin tur.</td><td>8</td></tr>
                                    <tr><td>Wild</td><td>Vælg den farve, der skal spilles på. Kan lægges på alt.</td><td>4</td></tr>
                                    <tr><td>Wild Draw Four</td><td>Vælg farven, og den næste spiller trækker 4 og mister sin tur. Kan lægges på alt, når som helst.</td><td>4</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="turn" aria-labelledby="docs-turn">
                        <h2 id="docs-turn">En tur</h2>
                        <ol class="docs-steps">
                            <li>Læg et kort, der passer til det øverste korts farve, tal eller symbol, eller et wild-kort. Har du flere med samme tal eller symbol? Klik også på dem, i en hvilken som helst farve, og tryk på <strong>Play</strong>: det sidste vælger farven.</li>
                            <li>Kan du ikke, eller vil du ikke? Træk et kort. Passer det, kan du lægge det med det samme eller beholde det.</li>
                            <li>Nede på dit sidste kort? Tryk på <strong>UNO!</strong>, når du lægger dit næstsidste.</li>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/rules.php'; ?>
                    </section>

                    <section id="settings" aria-labelledby="docs-settings">
                        <h2 id="docs-settings">Værtens indstillinger</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Indstilling</th><th scope="col">Valg</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Tid til en tur</td><td><?= implode(', ', array_slice(HBHUB_UNO_TURN_CHOICES, 0, -1)) ?> eller <?= HBHUB_UNO_TURN_CHOICES[count(HBHUB_UNO_TURN_CHOICES) - 1] ?> sekunder</td></tr>
                                    <tr><td>Stabling</td><td>Fra, kun samme kort (svar på et Draw Two med et Draw Two, eller et Wild Draw Four med et Wild Draw Four, for at sende dem alle videre), eller ethvert træk-kort (et Wild Draw Four kan også lægges på et Draw Two, og omvendt; standard)</td></tr>
                                    <tr><td>Træk, til du kan lægge</td><td>Bliv ved med at trække, til du får et kort, der passer, i stedet for bare ét (fra som standard)</td></tr>
                                    <tr><td>Når nogen vinder</td><td>Spil videre (de andre fortsætter om 2.-, 3.-pladsen og så videre, til én er tilbage; standard), eller afslut spillet ved første sejr</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>De kan ændres før et spil og mellem spil. Nye spillere kan også komme med mellem spil.</p>
                    </section>

                    <section id="scoring" aria-labelledby="docs-scoring">
                        <h2 id="docs-scoring">Point</h2>
                        <p>
                            Vinderen får point for de kort, der er tilbage på alle de andres hænder: et talkort sit tal, Skip, Reverse
                            og Draw Two 20 hver, og wild-kort 50 hver. Bliv af med dine store kort først, hvis nogen er ved at gå ud.
                        </p>
                        <p>
                            <a href="/games/uno/leaderboard">UNO-ranglisten</a> har dine sejre, din sejrsprocent, alle dine point og
                            din bedste sejr.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
