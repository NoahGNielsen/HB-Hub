<?php
// Help docs: staying safe in the chat - blocking (Block on a message request), the link warning and the URL blacklist
// (assets/php/urlBlacklist.php), the spam limit (HBHUB_CHAT_SEND_MAX in chat/index.php) and reporting someone, the
// same way the Code of Conduct explains it. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/safety';
$docsIntro = 'HB Hub skal føles som et venligt hjørne af skolen. Her er det, der holder det sådan, og hvad du kan gøre,
    når det ikke er.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="block" aria-labelledby="docs-block">
                        <h2 id="docs-block">At blokere nogen</h2>
                        <p>
                            Tryk på <strong>Block</strong> på en <a href="/docs/chat/#requests">beskedanmodning</a> for at afvise den
                            og forhindre personen i at sende dig nye anmodninger: de kan ikke starte en chat med dig eller tilføje dig
                            til grupper, og deres @omtaler giver dig ingen notifikation. De får ikke at vide, at du har blokeret dem.
                        </p>
                        <p>
                            Starter du selv en chat med dem, er de ikke blokeret længere. For en, du allerede har en chat med, kan du
                            gøre den lydløs, skjule den eller forlade den fra <a href="/docs/chat/#chat-menu">chatmenuen</a>.
                        </p>
                    </section>

                    <section id="links" aria-labelledby="docs-links">
                        <h2 id="docs-links">Sikre links</h2>
                        <ul>
                            <li><strong>Hvert link spørger først.</strong> Klikker du på et link i en besked, viser den, hvor det fører hen, før du forlader HB Hub, så du kan afgøre, om du stoler på det.</li>
                            <li>
                                <strong>Nogle links kan slet ikke sendes</strong>, hverken i chats, spilchats eller bioer: IP-loggere
                                (som finder ud af, hvor du er, i det øjeblik du klikker), linkforkortere (som kan skjule, hvor et link i
                                virkeligheden fører hen) og kendte kryptosvindel- og phishingsider. Prøver du, viser den
                                <strong>Link blocked</strong>.
                            </li>
                            <li><strong>Maskerede links kan ikke lyve</strong>: et, hvis tekst ligner en adresse, viser i stedet den rigtige adresse. Se <a href="/docs/chat/formatting#links">Links</a>.</li>
                        </ul>
                        <p>At ændre et link for at snige et blokeret igennem er imod <a href="/legal/code-of-conduct">adfærdskodekset</a>, ligesom at sende det.</p>
                    </section>

                    <section id="spam" aria-labelledby="docs-spam">
                        <h2 id="docs-spam">Spamgrænsen</h2>
                        <p>
                            Alle kan sende op til <strong>10 beskeder hvert 5. sekund</strong>, i alle deres chats tilsammen (filer
                            og GIFs tæller også). Sender du én til, får du <strong>Woah! Slow down!</strong>: beskeden bliver ikke
                            sendt, men den bliver i beskedfeltet, så du kan sende den et øjeblik senere.
                        </p>
                    </section>

                    <section id="report" aria-labelledby="docs-report">
                        <h2 id="docs-report">At anmelde nogen</h2>
                        <p>Hvis nogen er onde, får dig til at føle dig utryg eller bryder reglerne:</p>
                        <ol class="docs-steps">
                            <li>Tryk på <strong>Feedback</strong> nederst i sidebjælken (kun side-admins kan læse den), sig det til en side-admin, eller skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.</li>
                            <li>Sig, hvem det var, hvilken chat eller hvilket spil det skete i, og cirka hvornår.</li>
                            <li>Gør chatten lydløs, skjul den eller forlad den imens, hvis du vil. Du behøver ikke blive ved med at læse den.</li>
                        </ol>
                        <p>Anmeldelser bliver holdt private, og den, du anmelder, får ikke at vide, hvem der anmeldte dem. Du kommer ikke i problemer for at anmelde.</p>
                        <p class="legal-note">
                            Er det alvorligt, som trusler, eller at nogen er i fare, så snak også med en lærer, en forælder eller en
                            anden voksen, du stoler på. Er nogen i akut fare, så ring 112.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
