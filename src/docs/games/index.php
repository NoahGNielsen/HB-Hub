<?php
// Help docs: how games work - the multiplayer games' rooms (public and code, plus invites, like
// games/matador/room.php), what the host can do, watching a game (spectator mode, assets/php/games/spectators.php),
// game chats, leaving, and the leaderboards; and the two you play on
// your own. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/';
$docsIntro = 'Otte spil, ingen chips nødvendige: tre at spille med venner og fem at spille alene. De ligger alle under
    <a href="/games/">Games</a> i sidebjælken.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="list" aria-labelledby="docs-list">
                        <h2 id="docs-list">Spillene</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Spil</th><th scope="col">Hvad det er</th><th scope="col">Spillere</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><a href="/docs/games/matador">Matador</a></td><td>Det danske brætspil: køb gader, byg hoteller, og gør dine venner fallit.</td><td>2-4</td></tr>
                                    <tr><td><a href="/docs/games/chess">Skak</a></td><td>Skak, med eller uden ur.</td><td>2</td></tr>
                                    <tr><td><a href="/docs/games/uno">UNO</a></td><td>Kortspillet. Bliv af med dine kort først, og glem ikke at sige UNO!</td><td>2-6</td></tr>
                                    <tr><td><a href="/docs/games/doodle">Doodle Chain</a></td><td>Skriv, tegn og gæt i en kæde, og se, hvor galt det gik.</td><td>3-10</td></tr>
                                    <tr><td><a href="/docs/games/wordle">Wordle</a></td><td>Gæt dagens ord på fem bogstaver, på engelsk og på dansk.</td><td>Kun dig</td></tr>
                                    <tr><td><a href="/docs/games/minesweeper">Minesweeper</a></td><td>Klassikeren: ryd brættet uden at åbne en mine.</td><td>Kun dig</td></tr>
                                    <tr><td><a href="/docs/games/sudoku">Sudoku</a></td><td>Fyld brættet med 1 til 9 i hver række, kolonne og boks, fra Easy til Expert.</td><td>Kun dig</td></tr>
                                    <tr><td><a href="/docs/games/snake">Snake</a></td><td>Klassikeren: spis æblerne, og bliv længere uden at ramme kanten eller dig selv.</td><td>Kun dig</td></tr>
                                    <tr><td><a href="/docs/games/tetris">Tetris</a></td><td>Puslespillet med faldende brikker: ryd hele linjer, før brønden bliver fuld.</td><td>Kun dig</td></tr>
                                    <tr><td><a href="/docs/games/ore-mining-inc">Ore Mining Inc.</a></td><td>Et clickerspil om at mine asteroider, og siden hele multiverset.</td><td>Kun dig</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="rooms" aria-labelledby="docs-rooms">
                        <h2 id="docs-rooms">At åbne et spil</h2>
                        <p>Matador, skak, UNO og Doodle Chain spilles i rum. Åbn et, eller gå med i et, på spillets side:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Slags spil</th><th scope="col">Hvem kan være med</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Offentligt</td><td>Alle. Det står på spillets side.</td></tr>
                                    <tr><td>Kode</td><td>Alle med dets kode på 6 tegn, skrevet ind på spillets side.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Værten kan også invitere folk. De får en <a href="/docs/notifications">notifikation</a>, og invitationen venter på spillets side (ikke i Doodle Chain endnu).</p>
                        <p>Hvert spil har en kode, uanset hvilken slags det er, så du altid kan sende den til en ven. Koder springer bogstaver over, der ligner hinanden, som 0 og O.</p>
                    </section>

                    <section id="host" aria-labelledby="docs-host">
                        <h2 id="docs-host">Værten</h2>
                        <p>Den, der åbner et spil, er dets vært. Værten:</p>
                        <ul>
                            <li>vælger spillets indstillinger (som uret i skak, eller tidsgrænsen og stabling i UNO),</li>
                            <li>inviterer folk og starter spillet, når nok er kommet med,</li>
                            <li>kan sætte spillet på pause, hvilket stopper urene, til de fortsætter,</li>
                            <li>kan slå tilskuere til og fra (se <a href="#watch">At se med</a>),</li>
                            <li>og kan i Matador give en tom plads til en anden, eller gemme spillet for at spille det færdigt en anden dag.</li>
                        </ul>
                    </section>

                    <section id="watch" aria-labelledby="docs-watch">
                        <h2 id="docs-watch">At se med</h2>
                        <p>
                            I Matador, skak, UNO og Doodle Chain kan værten slå <strong>Spectator mode</strong> til i spillets
                            indstillinger, når som helst, også midt i et spil. Den er slået fra, indtil værten slår den til, og
                            der står <strong>Spectators can watch</strong> under spillets navn, mens den er slået til.
                        </p>
                        <ul>
                            <li>Skriv spillets kode på spillets side, og tryk <strong>Watch</strong> i stedet for <strong>Join</strong>.</li>
                            <li>Offentlige spil, der er i gang, står også under <strong>Games to watch</strong> på spillets side.</li>
                            <li>
                                Du ser det samme som spillerne ser af hinanden: brættet, trækkene, hvor mange kort hver har, og i
                                Doodle Chain kæderne, når værten viser dem. Du ser ikke nogens kort på hånden, spillets chat eller
                                det, der bliver tegnet og skrevet i Doodle Chain, før det bliver vist.
                            </li>
                            <li>Du kan ikke spille med, mens du ser med, og du kan ikke se med, mens du selv er med i et spil af samme slags.</li>
                            <li>Slår værten tilskuere fra, eller slutter spillet, kommer du tilbage til spillets side. Tryk <strong>Stop watching</strong> for at gå selv.</li>
                        </ul>
                    </section>

                    <section id="playing" aria-labelledby="docs-playing">
                        <h2 id="docs-playing">Mens du spiller</h2>
                        <ul>
                            <li><strong>Spilchat:</strong> hvert spil i Matador, skak og UNO har sin egen chat, med <a href="/docs/chat/mentions">@omtaler</a>. Den følger de samme regler som den normale chat.</li>
                            <li><strong>Din tur:</strong> i Matador og UNO spiller der en lyd, når det er din tur, så du kan kigge på noget andet, mens du venter.</li>
                            <li><strong>Tidsgrænser:</strong> ture har en tidsgrænse, så ingen kan få spillet til at vente. Hvad der sker, når den løber ud, er forskelligt i hvert spil.</li>
                            <li><strong>At gå:</strong> i skak og UNO tæller det som et nederlag at forlade et spil, der bliver spillet. I Matador bliver din plads holdt til dig, og i Doodle Chain spiller de andre bare videre uden dig. At holde spillet lukket et stykke tid tæller som at forlade det.</li>
                        </ul>
                    </section>

                    <section id="leaderboards" aria-labelledby="docs-leaderboards">
                        <h2 id="docs-leaderboards">Ranglister</h2>
                        <p>
                            Alle spillene har en rangliste, som der linkes til fra deres side. Klik på en kolonnes
                            overskrift for at sortere efter den. Sejrsprocenten (eller scoren, i skak) tæller først, når du har spillet
                            5 spil, så ét heldigt spil ikke kan komme øverst.
                        </p>
                    </section>

                    <section id="fair" aria-labelledby="docs-fair">
                        <h2 id="docs-fair">At spille fair</h2>
                        <p>
                            Ingen snyd, ingen bots eller autoklikkere, ingen ekstra konti for at vinde over dig selv, og ingen fejl
                            brugt til en fordel. Har du fundet en fejl? <a href="/docs/feedback">Sig det til admins</a> i stedet. Og vind
                            og tab pænt. Detaljerne står i <a href="/legal/code-of-conduct">adfærdskodekset</a>.
                        </p>
                        <p>
                            Spillene kan <a href="/docs/self-blocking">blokeres for dig selv</a> i skoletiden eller hele tiden.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
