<?php
// Help docs: notifications - the bell (assets/php/notificationTypes/ lists what lands there), browser notifications
// and the message sound from the settings page, and muting chats. Open to everyone. Built by assets/php/docs/top.php
// and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/notifications';
$docsIntro = 'HB Hub fortæller dig på tre måder, når der sker noget: klokken i topbjælken, en notifikation fra din
    browser og en lyd. Kun klokken er slået til fra starten.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="bell" aria-labelledby="docs-bell">
                        <h2 id="docs-bell">Klokken</h2>
                        <p>
                            Klokken i topbjælken viser et rødt tal, når der er noget nyt. Åbn den for at se dine nyeste
                            notifikationer, og tryk på en for at komme hen til det, den handler om.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Fra</th><th scope="col">Hvad du får besked om</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Chat</td><td>Beskedanmodninger, <a href="/docs/chat/mentions">@omtaler</a>, at blive smidt ud af en gruppe, at blive gjort til admin eller ikke længere være admin, og at nogen forlader din private chat.</td></tr>
                                    <tr><td>Casino</td><td>Invitationer til et Blackjack- eller Baccarat-bord.</td></tr>
                                    <tr><td>Spil</td><td>Invitationer til Matador, skak og UNO, @omtaler i deres spilchats, en plads, du har fået i et spil Matador, og et gemt spil Matador, der bliver fortsat.</td></tr>
                                    <tr><td>Feedback</td><td>At en admin markerer din <a href="/docs/feedback">feedback</a> som færdig, med deres svar.</td></tr>
                                    <tr><td>Admins</td><td>Meddelelser, som side-admins sender til alle, som nyheder om siden. Nogle linker til den side, de handler om.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Selve de nye beskeder kommer ikke under klokken: de bliver i stedet vist som et rødt tal på Chat i sidebjælken.</p>
                    </section>

                    <section id="browser" aria-labelledby="docs-browser">
                        <h2 id="docs-browser">Browsernotifikationer og beskedlyden</h2>
                        <p>Begge er i kortet <strong>Notifications</strong> under dine <a href="/settings#notifications">indstillinger</a>:</p>
                        <ul>
                            <li>
                                <strong>Notify me when I get a new message</strong> viser en popup fra din browser. Første gang spørger
                                din browser, om HB Hub må vise notifikationer: tryk Tillad. Trykkede du Bloker ved en fejl, så tillad
                                det igen i din browsers indstillinger for siden (ikonet til venstre for adressen).
                            </li>
                            <li>
                                <strong>Play a sound when I get a new message</strong> spiller en kort lyd, men ikke for den chat, du
                                kigger på. Browsere lader kun en side spille lyd, efter du har klikket eller skrevet på den.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Begge virker kun, mens HB Hub er åben i en fane et sted; en fane i baggrunden er fint. Lukker du alle
                            HB Hub-faner, holder de op, til du åbner den igen.
                        </p>
                    </section>

                    <section id="mute" aria-labelledby="docs-mute">
                        <h2 id="docs-mute">At gøre en chat lydløs</h2>
                        <p>
                            Sker der for meget i en gruppe? Højreklik på den i chatlisten (eller hold fingeren på den på en
                            telefon), og vælg <strong>Mute chat</strong>, i 15 minutter, 1, 3, 8 eller 24 timer, eller for altid. En
                            lydløs chat sender ingen browsernotifikationer, lyde eller notifikationer om @omtaler, men dens beskeder
                            kommer stadig ind. Slå lyden til igen på samme måde.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
