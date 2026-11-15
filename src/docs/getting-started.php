<?php
// Help docs: getting started - making an account, logging in, the side bar and top bar, and adding HB Hub to a phone's
// home screen. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/getting-started';
$docsIntro = 'HB Hub er skolens eget sted at chatte, spille spil og prøve lykken i et casino med chips, der ikke er
    rigtige penge. Sådan kommer du ind og finder rundt.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="sign-up" aria-labelledby="docs-sign-up">
                        <h2 id="docs-sign-up">At oprette en konto</h2>
                        <p>Tryk på <strong>Opret konto</strong> på <a href="/">forsiden</a>. Den stiller dig et par spørgsmål, ét ad gangen:</p>
                        <ol class="docs-steps">
                            <li><strong>Vælg et brugernavn.</strong> Det navn, folk ser i chatten og på ranglisterne, og det, du logger ind med. Det kan være dit navn, et kælenavn eller hvad som helst andet.</li>
                            <li><strong>Hvilken klasse går du i?</strong> 1., 2. eller 3. år på HTX, og så din studieretning (X, U, T, Y eller Z), eller Ikke HTX. Det bliver vist på din profil, og på HTX kommer du med det samme med i din <a href="/docs/chat/groups#class">klassechat</a>.</li>
                            <li><strong>Et billede af dig selv.</strong> Dit profilbillede. Du kan springe det over og tilføje et senere.</li>
                            <li><strong>Beskriv dig selv.</strong> En kort bio til din profil.</li>
                            <li><strong>Din adgangskode.</strong> Formularen fortæller dig, mens du skriver, hvis den er for svag, eller hvis den bliver brugt så tit, at den er nem at gætte.</li>
                        </ol>
                        <p>
                            Navne og bioer kan bruge bogstaverne i det latinske alfabet (med æ, ø og å), tal, tegnsætning og emoji.
                            Ved at oprette en konto accepterer du <a href="/legal/terms">vilkårene</a> og
                            <a href="/legal/code-of-conduct">adfærdskodekset</a>, så læs dem igennem. Én konto per person.
                        </p>
                    </section>

                    <section id="log-in" aria-labelledby="docs-log-in">
                        <h2 id="docs-log-in">At logge ind</h2>
                        <p>
                            Tryk på <strong>Log ind</strong> på forsiden, og skriv dit brugernavn og din adgangskode. Har du slået
                            <a href="/docs/two-factor">totrinslogin</a> til, beder den også om den 6-cifrede kode fra din
                            godkendelsesapp. Du forbliver logget ind i den browser, til du logger ud, og du kan se og logge ud af
                            alle de enheder, du er logget ind på, under dine <a href="/docs/account#devices">indstillinger</a>.
                        </p>
                        <p>
                            Mens du er logget ind, fører forsiden og HB Hub-logoet i topbjælken dig direkte til chatten. Vil du se
                            forsiden alligevel, så dobbeltklik på logoet, mens du er i chatten.
                        </p>
                        <p>
                            Et par forkerte adgangskoder i træk låser login et stykke tid. Det er forklaret i
                            <a href="/docs/two-factor#lockout">Totrinslogin og sikkerhed</a>.
                        </p>
                    </section>

                    <section id="around" aria-labelledby="docs-around">
                        <h2 id="docs-around">At finde rundt</h2>
                        <p>Når du er inde, har hver side de samme to bjælker:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Hvor</th><th scope="col">Hvad er der</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Sidebjælken</td><td><strong>Chat</strong>, <strong>Casino</strong> og <strong>Games</strong>, med et rødt tal på Chat for chats med ulæste beskeder. <strong>Feedback</strong> er nederst. På en telefon er det en bjælke langs bunden af skærmen.</td></tr>
                                    <tr><td>Topbjælken</td><td><a href="/docs/notifications">Notifikationsklokken</a>, dine chips på casinosiderne og dit profilbillede, som åbner din profil.</td></tr>
                                    <tr><td>Din profil</td><td>Dit billede, navn, din klasse og bio, som andre ser dem, med tandhjulet, der åbner dine <a href="/docs/account">indstillinger</a>.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Tallene på sidebjælken og klokken opdaterer sig selv hvert 15. sekund, mens siden er åben.</p>
                    </section>

                    <section id="app" aria-labelledby="docs-app">
                        <h2 id="docs-app">HB Hub på din telefon</h2>
                        <p>
                            HB Hub kan lægges på din telefons hjemmeskærm som en app, og så åbner den uden browserens bjælker. Hold
                            fingeren på ikonet for genveje direkte til Chat, Casino og Games.
                        </p>
                        <ul>
                            <li><strong>iPhone (Safari):</strong> tryk på Del-knappen, og så <strong>Føj til hjemmeskærm</strong>.</li>
                            <li><strong>Android (Chrome):</strong> åbn browserens menu (&vellip;), og så <strong>Føj til startskærm</strong> eller <strong>Installer app</strong>.</li>
                        </ul>
                        <p>
                            Første gang du logger ind på en telefon, spørger HB Hub dig om det én gang med en popup (på Android kan
                            den gøre det for dig med ét tryk). Derefter spørger den ikke igen i den browser.
                        </p>
                    </section>

                    <section id="next" aria-labelledby="docs-next">
                        <h2 id="docs-next">Hvor du kan gå hen nu</h2>
                        <ul>
                            <li><a href="/docs/chat/">Grundlæggende chat</a>: start en privat chat eller en gruppe, og sig hej.</li>
                            <li><a href="/docs/casino/">Sådan virker casinoet</a>: hent dine gratis chips, og vælg et bord.</li>
                            <li><a href="/docs/games/">Sådan virker spillene</a>: åbn et spil, og inviter dine venner.</li>
                            <li><a href="/docs/account">Profil og indstillinger</a>: tilføj et profilbillede, og gør din konto sikker.</li>
                        </ul>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
