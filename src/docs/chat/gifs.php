<?php
// Help docs: GIFs and smileys - the GIF picker (Giphy, through chat/gifs.php so the key stays on the server) and the
// smiley menu, and reacting with emoji. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/gifs';
$docsIntro = 'Nogle gange siger en GIF eller en smiley det bedre end ord.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="gifs" aria-labelledby="docs-gifs">
                        <h2 id="docs-gifs">At sende en GIF</h2>
                        <ol class="docs-steps">
                            <li>Tryk på knappen <strong>GIF</strong> ved beskedfeltet. Den åbner med det, der trender.</li>
                            <li>Skriv i <strong>Search GIFs</strong> for at finde noget andet.</li>
                            <li>Klik på en GIF for at sende den med det samme.</li>
                        </ol>
                        <p>
                            GIF'erne kommer fra <a href="https://giphy.com" target="_blank" rel="noopener noreferrer">Giphy</a>. Din
                            søgning går gennem HB Hubs server, men selve GIF'erne bliver hentet fra Giphy, hvilket betyder, at Giphy
                            kan se din IP-adresse. Mere om det i <a href="/legal/privacy">privatlivspolitikken</a>. GIFs tæller med i
                            <a href="/docs/chat/safety#spam">spamgrænsen</a> ligesom alle andre beskeder og skal også følge
                            <a href="/legal/code-of-conduct">adfærdskodekset</a>.
                        </p>
                    </section>

                    <section id="smileys" aria-labelledby="docs-smileys">
                        <h2 id="docs-smileys">Smileys</h2>
                        <p>
                            Smiley-knappen åbner alle emoji, sorteret i faner (ansigter, mennesker, dyr, mad og så videre). Klik på
                            en for at sætte den ind i din besked, hvor markøren er. Du kan selvfølgelig også skrive emoji direkte fra
                            din telefons tastatur, eller med <kbd>Win</kbd> + <kbd>.</kbd> på Windows og <kbd>Ctrl</kbd> +
                            <kbd>&#8984;</kbd> + <kbd>Mellemrum</kbd> på en Mac.
                        </p>
                        <p>
                            En besked, der kun er emoji (op til 75 af dem), bliver vist stort, uden boblen rundt om.
                        </p>
                    </section>

                    <section id="reactions" aria-labelledby="docs-reactions">
                        <h2 id="docs-reactions">At reagere med emoji</h2>
                        <p>
                            Højreklik på en besked for at reagere på den. Menuen har de emoji, du har brugt mest for nylig, øverst,
                            og <strong>+</strong> for resten. En besked kan have op til 20 forskellige reaktioner. Peg på en for at se,
                            hvem der reagerede, og klik på den for at være med eller tage din tilbage. Mere i
                            <a href="/docs/chat/#message-menu">Grundlæggende chat</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
