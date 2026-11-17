<?php
// Help docs: Snake - the rules (assets/php/games/snake/rules.php, included as the game shows them), a few tips, and
// where the best scores are kept. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
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

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Bedste score</h2>
                        <p>
                            Snake spilles helt i din browser, og intet om dine spil bliver sendt til HB Hub. Din bedste score
                            for hver fart og den fart, du sidst valgte, gemmes kun i din browser, så de er der ikke på en
                            anden computer eller telefon, og der er ingen rangliste.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
