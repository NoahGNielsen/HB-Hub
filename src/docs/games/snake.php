<?php
// Help docs: Snake - the rules (assets/php/games/snake/rules.php, included as the game shows them), a few tips, and
// the leaderboard and what keeps it fair (assets/php/games/snake/snake.php). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/snake';
$docsIntro = 'Klassikeren: styr slangen hen til æblerne, og bliv længere uden at ramme kanten eller dig selv. Spil det på
    <a href="/games/snake/">Snake</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/snake/rules.php'; ?>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                Du kan trykke to retninger hurtigt efter hinanden (som op og så venstre), og slangen tager dem
                                én ad gangen. Det gør det nemmere at vende rundt i en snæver plads.
                            </li>
                            <li>
                                Når slangen bliver lang, så kør i baner frem og tilbage hen over brættet i stedet for direkte mod
                                æblet. Så har halen altid plads til at følge efter.
                            </li>
                            <li>
                                Du må gerne køre ind på det felt, hvor halen er lige nu: den flytter sig samtidig, så længe du
                                ikke spiser et æble i samme træk.
                            </li>
                        </ul>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Rangliste</h2>
                        <p>
                            Normal og Fast har hver sin rangliste (Slow har ingen) (<strong>Leaderboard</strong> på Snake-siden) med din
                            bedste score, dit gennemsnit, alle de æbler, du har spist, og hvor mange spil du har spillet.
                        </p>
                        <p>
                            Spillet kører i din browser, men HB Hub's server spiller med på sin egen kopi af brættet: siden
                            sender dine sving og antallet af skridt, mens du spiller, og serveren laver de samme træk og tæller
                            selv æblerne. Den holder også øje med uret, så et spil, der er sat op eller ned i fart, ikke tæller.
                            Et spil tæller kun som spillet, ikke på ranglisten, hvis
                        </p>
                        <ul>
                            <li>et tastetryk eller swipe kom fra kode i stedet for fra dig (det bliver ignoreret),</li>
                            <li>skridtene ikke passer med serverens ur, eller serveren ikke kan lave de samme træk,</li>
                            <li>du holder pause mere end 10 gange,</li>
                            <li>forbindelsen til HB Hub forsvinder, eller du starter et nyt spil i en anden fane.</li>
                        </ul>
                        <p>
                            Står der en rød linje over brættet, tæller spillet ikke, og linjen siger hvorfor. Du kan stadig
                            spille det færdigt. Den fart, du sidst valgte, huskes i din browser.
                        </p>
                        <p>
                            Den <a href="/play/snake">gratis Snake</a>, til at spille uden en konto, bliver kun spillet i din browser
                            og har ingen rangliste. Din bedste score ved hver fart bliver gemt i din browser.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
