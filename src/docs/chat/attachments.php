<?php
// Help docs: files and pictures in the chat - sending them, the picture viewer, and attachment tickets. The numbers are
// chatAttachments.php's own constants, so they stay right when they change. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatAttachments.php';

$docsTicketMb = HBHUB_CHAT_ATTACHMENT_TICKET_BYTES / 1024 / 1024;
$docsMaxMb = HBHUB_CHAT_ATTACHMENT_MAX_BYTES / 1024 / 1024;

$docsPath = '/docs/chat/attachments';
$docsIntro = 'Send billeder, dokumenter og alt andet i enhver chat, op til ' . HBHUB_CHAT_ATTACHMENT_FILES_MAX . ' filer
    ad gangen.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Filer per besked</dt><dd>Op til <?= HBHUB_CHAT_ATTACHMENT_FILES_MAX ?></dd></div>
                        <div><dt>Størrelse</dt><dd><?= $docsMaxMb ?> MB tilsammen</dd></div>
                        <div><dt>Billetter</dt><dd><?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> &times; <?= $docsTicketMb ?> MB</dd></div>
                        <div><dt>Gemmes i</dt><dd><?= HBHUB_CHAT_ATTACHMENT_DAYS ?> dage</dd></div>
                    </dl>

                    <section id="send" aria-labelledby="docs-send">
                        <h2 id="docs-send">At sende filer og billeder</h2>
                        <p>Der er tre måder at vedhæfte noget på:</p>
                        <ul>
                            <li>Tryk på vedhæft-knappen ved beskedfeltet, og vælg filerne.</li>
                            <li>Indsæt et billede eller en fil med <kbd>Ctrl</kbd> + <kbd>V</kbd>.</li>
                            <li>Træk filer ind over chatten, og slip dem.</li>
                        </ul>
                        <p>
                            De venter som små forhåndsvisninger over beskedfeltet, så du kan tage en ud igen eller skrive en besked
                            til dem. Send så som normalt. Billeder og videoer bliver vist for sig selv i chatten, ligesom en GIF, og
                            flere billeder i én besked bliver vist som et galleri.
                        </p>
                        <p>
                            Videoer (MP4, MOV, WebM og MKV) får en afspiller direkte i chatten, så de kan ses uden at blive
                            downloadet. Kan din browser ikke afspille en, viser den i stedet et kort med videoens navn, som
                            downloader den.
                        </p>
                        <p>
                            For at se en fils navn, type, størrelse, hvem der sendte den, og hvornår den udløber, højreklikker du
                            på den og vælger <strong>File info</strong>.
                        </p>
                    </section>

                    <section id="smaller" aria-labelledby="docs-smaller">
                        <h2 id="docs-smaller">Billeder og videoer bliver gjort mindre</h2>
                        <p>
                            Før et billede bliver sendt, laver din browser det om til JPEG XL, en nyere slags billedfil, der fylder
                            meget mindre. Det mister ingen detaljer, bortset fra JPEG-fotos, som får en, der ser ens ud. Sæt flueben
                            i <strong>Keep original pictures</strong> for i stedet at sende dem præcis, som de er. Andre filer bliver
                            zippet på serveren og pakket ud igen, når nogen downloader dem, så du får den samme fil tilbage.
                        </p>
                        <p>
                            Du behøver ikke vente på, at de bliver lavet om: trykker du på <strong>Send</strong>, ryger beskeden op i
                            chatten med "Sending…" og bliver sendt, så snart filerne er klar. Imens kan du skrive og sende andre
                            beskeder, som så bliver sendt først.
                        </p>
                        <p>
                            Videoer bliver lavet om til WebM med VP9, før de bliver sendt, så alle videoer kan afspilles i chatten
                            og fylder mindre (en video fra en telefon tager tit langt færre billetter bagefter). Dem, der er større
                            end 1080p, bliver gjort til 1080p. Det sker i din browser, og forhåndsvisningen viser, hvor langt den er
                            nået; en lang video kan tage et stykke tid, og du kan allerede sende, den går af sted, når den er færdig.
                            Sæt flueben i <strong>Keep original videos</strong> for i stedet at sende dem præcis, som de er (en
                            MKV-video bliver så sendt som en fil til download, uden afspiller). Kan din browser ikke lave
                            WebM-videoer, bliver videoen sendt, som den er.
                        </p>
                    </section>

                    <section id="viewer" aria-labelledby="docs-viewer">
                        <h2 id="docs-viewer">Billedfremviseren</h2>
                        <p>Klik på et billede for at åbne det stort. I fremviseren:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Tast</th><th scope="col">Gør</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>&larr;</kbd> <kbd>&rarr;</kbd></td><td>Det forrige eller næste billede i beskeden</td></tr>
                                    <tr><td><kbd>+</kbd> <kbd>-</kbd></td><td>Zoom ind og ud (eller <kbd>Ctrl</kbd> + musehjulet)</td></tr>
                                    <tr><td><kbd>0</kbd></td><td>Tilbage til normal størrelse</td></tr>
                                    <tr><td><kbd>Esc</kbd></td><td>Luk den</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Download-knappen gemmer filen på din enhed.</p>
                    </section>

                    <section id="tickets" aria-labelledby="docs-tickets">
                        <h2 id="docs-tickets">Sådan virker billetter</h2>
                        <p>
                            Serveren har kun så meget plads, så vedhæftede filer bliver betalt med billetter. Alle har
                            <strong><?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> billetter</strong>, og side-admins har
                            <?= HBHUB_CHAT_ATTACHMENT_TICKETS_ADMIN ?>.
                        </p>
                        <ul>
                            <li>Hver påbegyndt <?= $docsTicketMb ?> MB af en fil tager én billet. Et foto på 1 MB tager 1, en video på 12 MB tager 3. Hver fil betaler for sig selv, så tre små filer tager tre billetter.</li>
                            <li>En fil beholder sine billetter i <?= HBHUB_CHAT_ATTACHMENT_DAYS ?> dage. Så bliver den slettet, og billetterne er dine igen. En stor fil, der tager mere end <?= HBHUB_CHAT_ATTACHMENT_BIG_TICKETS ?> billetter, bliver kun i <?= HBHUB_CHAT_ATTACHMENT_BIG_DAYS ?> dage.</li>
                            <li>Sletter du beskeden, eller bare den vedhæftede fil, får du billetterne tilbage med det samme.</li>
                            <li>Et billede til et <a href="/docs/account#profile">profilbanner</a> tager også en billet, men udløber aldrig: det beholder den, til du skifter banner eller går tilbage til farver.</li>
                            <li>Side-admins kan give nogen flere billetter eller tage nogle væk.</li>
                        </ul>
                        <p>
                            <strong>Your attachments</strong>, i vedhæft-knappens menu, viser, hvad der tager dine billetter, og
                            hvornår hver af dem udløber, og sletter dem, du ikke har brug for længere. Prøver du at sende mere, end du
                            har ledige billetter til, siger den, hvor mange der mangler, og <strong>Send now</strong> sender dem, når
                            der er nok ledige.
                        </p>
                        <p class="legal-note">
                            HB Hub er ikke en backup: vedhæftede filer forsvinder altid til sidst, så gem din egen kopi af alt, der
                            betyder noget. Og send kun billeder, du har lov til at dele. Læg ikke billeder af andre op uden at spørge.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
