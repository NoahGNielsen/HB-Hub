<?php
// Help docs: Tetris - the rules (assets/php/games/blockfall/rules.php, included as the game shows them), a few tips,
// and the leaderboard and what keeps it fair (assets/php/games/blockfall/blockfall.php). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/tetris';
$docsIntro = 'Puslespillet med faldende brikker: stabl dem, ryd hele linjer, og lad ikke brønden blive fuld. Spil det på
    <a href="/games/tetris/">Tetris</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/blockfall/rules.php'; ?>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                Hold en piletast nede, så glider brikken hele vejen til siden. Det er hurtigere end at trykke
                                mange gange.
                            </li>
                            <li>
                                Den svage omrids nederst i brønden viser, hvor brikken lander, hvis du taber den med
                                <kbd>Mellemrum</kbd>.
                            </li>
                            <li>
                                Hold én kolonne i siden fri, og gem den lange brik til den. Fire linjer på én gang giver 800 point
                                gange dit level, mere end fire enkelte linjer.
                            </li>
                            <li>
                                Lav svære linjer i træk (fire på én gang eller spins), så får du back-to-back-bonus. En
                                almindelig linje imellem bryder rækken, men en brik uden linjer gør ikke.
                            </li>
                            <li>
                                Drejer du en brik ind mod væggen eller en anden brik, skubber spillet den et felt til siden eller
                                op, hvis der er plads, så den kan nå at komme på plads.
                            </li>
                        </ul>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Ranglisten</h2>
                        <p>
                            Ranglisten (<strong>Leaderboard</strong> på Tetris-siden) viser din bedste score, dit
                            gennemsnit, alle de linjer, du har ryddet, og hvor mange spil du har spillet.
                        </p>
                        <p>
                            Spillet kører i din browser, men HB Hub's server spiller med på sin egen kopi af brønden: siden
                            sender dine tastetryk og hvor langt spillet er nået, mens du spiller, og serveren spiller det samme
                            spil og tæller selv point, linjer og tid. Brikkerne bestemmes også af serveren, og siden kender kun
                            de næste par stykker. Et spil tæller kun som spillet, ikke på ranglisten, hvis
                        </p>
                        <ul>
                            <li>et tastetryk eller tryk på en knap kom fra kode i stedet for fra dig (det bliver ignoreret),</li>
                            <li>spillet ikke passer med serverens ur (det er sat op eller ned i fart), eller serveren ikke kan spille det samme spil,</li>
                            <li>du holder pause mere end 10 gange,</li>
                            <li>forbindelsen til HB Hub forsvinder, eller du starter et nyt spil i en anden fane.</li>
                        </ul>
                        <p>
                            Står der en rød linje over brønden, tæller spillet ikke, og linjen siger hvorfor. Du kan stadig
                            spille det færdigt.
                        </p>
                        <p>
                            Den <a href="/play/tetris">gratis Tetris</a>, til at spille uden en konto, bliver kun spillet
                            i din browser og har ingen rangliste. Din bedste score bliver gemt i din browser.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
