<?php
// Help docs: chat basics - starting a DM, message requests, sending, replies, reactions, editing and deleting, pinned messages, unread
// messages, the chat list's right-click menu and nicknames. Follows chat/index.php and chat.js. Open to everyone.
// Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/';
$docsIntro = 'Chatten er hjertet i HB Hub: private chats med én person, gruppechats med mange, og alt det, du kan gøre
    med en besked.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">At starte en chat</h2>
                        <ol class="docs-steps">
                            <li>Åbn <a href="/chat/">Chat</a> i sidebjælken, og tryk på <strong>New chat</strong> over din liste af chats.</li>
                            <li>Søg efter dem, du vil snakke med, og vælg dem.</li>
                            <li>
                                Vælg én person, og tryk på <strong>Start DM</strong> for en privat besked. Vælger du flere, bliver det
                                en <a href="/docs/chat/groups">gruppechat</a>, som du kan give et navn.
                            </li>
                        </ol>
                        <p>Har du allerede en privat chat med nogen, åbner den den i stedet for at lave en ny.</p>
                    </section>

                    <section id="requests" aria-labelledby="docs-requests">
                        <h2 id="docs-requests">Beskedanmodninger</h2>
                        <p>
                            Når nogen starter en chat med dig eller tilføjer dig til en gruppe, dukker den op under
                            <strong>Message requests</strong> øverst i din chatliste og i <a href="/docs/notifications">klokken</a>.
                            Du kan læse den, før du svarer:
                        </p>
                        <ul>
                            <li><strong>Accept</strong> flytter den over til dine chats, og du kan skrive tilbage.</li>
                            <li><strong>Decline</strong> siger nej tak. De kan spørge igen senere.</li>
                            <li><strong>Block</strong> afviser den og forhindrer personen i at sende dig flere anmodninger. Se <a href="/docs/chat/safety#block">At blokere</a>.</li>
                        </ul>
                    </section>

                    <section id="send" aria-labelledby="docs-send">
                        <h2 id="docs-send">At skrive beskeder</h2>
                        <ul>
                            <li><kbd>Enter</kbd> sender, <kbd>Shift</kbd> + <kbd>Enter</kbd> laver en ny linje.</li>
                            <li>En besked kan være op til 2.500 tegn lang og <a href="/docs/chat/formatting">formateres</a> med fed, kursiv, kode, lister og mere.</li>
                            <li>Knapperne ved beskedfeltet sender <a href="/docs/chat/attachments">filer og billeder</a>, <a href="/docs/chat/gifs">GIFs og smileys</a>.</li>
                            <li>Skriv <code>@</code> for at <a href="/docs/chat/mentions">omtale</a> nogen i chatten.</li>
                            <li>Mens du skriver, ser de andre i chatten <strong>... is typing</strong> over deres beskedfelt. Det forsvinder, når du sender, tømmer feltet eller holder op med at skrive i et par sekunder.</li>
                            <li>Beskeder, der er sendt i træk af den samme person (mindre end 10 minutter imellem), bliver vist samlet. Peg på en for at se, hvornår den blev sendt.</li>
                        </ul>
                    </section>

                    <section id="message-menu" aria-labelledby="docs-message-menu">
                        <h2 id="docs-message-menu">Svar, reaktioner, rettelser og sletning</h2>
                        <p>Højreklik på en besked (eller hold fingeren på den på en telefon) for at få dens menu:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Valg</th><th scope="col">Hvad det gør</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Reactions</td><td>De emoji øverst er dem, du har brugt mest for nylig. Tryk på en for at reagere med den, eller <strong>+</strong> for alle de andre. Tryk på en reaktion under en besked for at tilføje din eller tage den væk, og peg på den for at se, hvem der reagerede.</td></tr>
                                    <tr><td>File info</td><td>På en fil, et billede eller en video: dens navn, type, størrelse, hvem der sendte den, og hvornår den udløber. Se <a href="/docs/chat/attachments">Filer og billeder</a>.</td></tr>
                                    <tr><td>Reply</td><td>Citerer beskeden over din. Klik på et citat for at hoppe tilbage til den besked, det svarer på. <kbd>Esc</kbd> annullerer svaret.</td></tr>
                                    <tr><td>Edit message</td><td>Kun dine egne beskeder. Beskeden viser, at den er rettet.</td></tr>
                                    <tr><td>Pin message</td><td>Fastgør den i chatten for alle i den, se <a href="#pins">Fastgjorte beskeder</a>. En fastgjort besked får <strong>Unpin message</strong> i stedet, hvis du kan løsne den.</td></tr>
                                    <tr><td>Mark unread</td><td>Markerer chatten som ulæst fra den besked, så du husker at komme tilbage til den.</td></tr>
                                    <tr><td>Delete message</td><td>Dine egne beskeder, eller alles, hvis du er admin i gruppen. Den bliver vist som "Message deleted" for alle.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="pins" aria-labelledby="docs-pins">
                        <h2 id="docs-pins">Fastgjorte beskeder</h2>
                        <p>
                            Fastgør en besked, så den er nem at finde, som planer, lektier eller et link, alle skal bruge. Alle i en
                            chat kan fastgøre en besked fra <a href="#message-menu">beskedmenuen</a>, og den får en lille nål i
                            hjørnet. Alle i chatten ser en besked om, at du har fastgjort den.
                        </p>
                        <ul>
                            <li><strong>Nålen</strong> øverst til højre i chatten viser alt, der er fastgjort i den, det nyeste først. <strong>Jump</strong> scroller op til beskeden.</li>
                            <li>Du kan løsne de beskeder, du selv har fastgjort. Gruppeadmins kan løsne alt i deres gruppe, og i en privat chat kan I begge løsne alt.</li>
                            <li>En chat kan have op til 50 fastgjorte beskeder. Sletter man en fastgjort besked, bliver den løsnet.</li>
                        </ul>
                    </section>

                    <section id="unread" aria-labelledby="docs-unread">
                        <h2 id="docs-unread">Ulæste beskeder</h2>
                        <p>
                            Chats med nye beskeder er markeret i din liste, og Chat i sidebjælken viser, hvor mange der har noget
                            nyt. Åbner du en chat, du har været væk fra, kommer der en <strong>New messages</strong>-linje over den
                            første, du ikke har set. Scroll op for at hente ældre beskeder.
                        </p>
                    </section>

                    <section id="chat-menu" aria-labelledby="docs-chat-menu">
                        <h2 id="docs-chat-menu">Chatmenuen</h2>
                        <p>Højreklik på en chat i din liste (eller hold fingeren på den) for mere:</p>
                        <ul>
                            <li><strong>Show profile</strong> og <strong>Give nickname</strong> (private chats): åbn deres profil, eller giv dem et navn, kun du ser. I en gruppe bliver kælenavne i stedet givet fra <strong>Members</strong>, og alle ser dem (se <a href="/docs/chat/groups#nicknames">Kælenavne</a>).</li>
                            <li><strong>Chat info</strong>: hvilken slags chat det er, hvornår den blev lavet og af hvem, hvor mange personer og beskeder der er i den, og hvornår du kom med (og din rolle, i en gruppe).</li>
                            <li><strong>Mute chat</strong>: ingen notifikationer eller lyde fra den i et stykke tid eller for altid. Se <a href="/docs/notifications#mute">At gøre en chat lydløs</a>.</li>
                            <li><strong>Hide chat</strong>: tager den ud af din liste, indtil der kommer en ny besked, eller du søger efter den og åbner den.</li>
                            <li><strong>Change background</strong>: giv chatten en farve eller en overgang, for alle i den. I kan begge gøre det i en privat chat, kun admins i en gruppe.</li>
                            <li><strong>Leave chat</strong>: forlad den for altid. I en gruppe skal nogen tilføje dig igen, for at du kan komme ind igen.</li>
                        </ul>
                        <p>Grupper har et par flere til deres admins, forklaret i <a href="/docs/chat/groups">Gruppechats</a>.</p>
                    </section>

                    <section id="search" aria-labelledby="docs-search">
                        <h2 id="docs-search">At finde en chat</h2>
                        <p>
                            Skriv i <strong>Search chats</strong> over din liste for at finde en chat ud fra dens navn (en privat chat
                            ud fra den anden persons navn eller det kælenavn, du har givet dem). Søgningen finder også skjulte chats,
                            og åbner du en, kommer den tilbage i din liste.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
