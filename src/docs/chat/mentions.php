<?php
// Help docs: @mentions - typing them, how a name is found (assets/php/mentions.php), who gets a notification, and the
// game chats they work in. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/mentions';
$docsIntro = 'Vil du have, at nogen ser din besked? Omtal dem med et @, så får de en notifikation.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="how" aria-labelledby="docs-how">
                        <h2 id="docs-how">At omtale nogen</h2>
                        <ol class="docs-steps">
                            <li>Skriv <code>@</code> i beskedfeltet. En liste over dem i chatten dukker op.</li>
                            <li>Bliv ved med at skrive for at indsnævre den, og vælg nogen med musen eller med <kbd>&uarr;</kbd> <kbd>&darr;</kbd> og <kbd>Enter</kbd> eller <kbd>Tab</kbd>. <kbd>Esc</kbd> lukker listen.</li>
                            <li>Send beskeden. Navnet lyser op, som <span class="docs-mention">@Navn</span>, og linker til deres profil.</li>
                        </ol>
                        <p>
                            Du kan også bare skrive navnet efter <code>@</code>. Det virker også med mellemrum i navne, og store eller
                            små bogstaver betyder ikke noget. Et kælenavn virker også: i en privat chat det, du har givet nogen, i en
                            gruppe det, de har dér. Et @ midt i et ord (som i en e-mailadresse) er ikke en omtale.
                        </p>
                    </section>

                    <section id="told" aria-labelledby="docs-told">
                        <h2 id="docs-told">Hvem der får besked</h2>
                        <p>Den, du omtaler, får en notifikation under <a href="/docs/notifications">klokken</a>, og beskeder, der omtaler dig, lyser op i chatten. De får ikke besked, når:</p>
                        <ul>
                            <li>de allerede kigger på chatten,</li>
                            <li>de har <a href="/docs/notifications#mute">gjort chatten lydløs</a>,</li>
                            <li>eller de har <a href="/docs/chat/safety#block">blokeret</a> dig.</li>
                        </ul>
                        <p>Kun folk i chatten kan omtales. En, der har forladt den siden, eller har skiftet navn, bliver bare vist som tekst.</p>
                    </section>

                    <section id="games" aria-labelledby="docs-games">
                        <h2 id="docs-games">I spilchats</h2>
                        <p>
                            Omtaler virker på samme måde i chatten i spil som <a href="/docs/games/matador">Matador</a>,
                            <a href="/docs/games/chess">skak</a> og <a href="/docs/games/uno">UNO</a>, med spillerne i det spil.
                            Notifikationen fører dem direkte hen til spillet.
                        </p>
                    </section>

                    <section id="nice" aria-labelledby="docs-nice">
                        <h2 id="docs-nice">Vær sød med det</h2>
                        <p>
                            At omtale nogen igen og igen for at irritere dem er spam og imod
                            <a href="/legal/code-of-conduct">adfærdskodekset</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
