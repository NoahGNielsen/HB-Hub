<?php
// Help docs: group chats - making one, adding people, the owner/admin/member roles (HBHUB_CHAT_ROLE_* in
// assets/php/chat.php), nicknames (memberNickname, chat/index.php's chatSetMemberNickname()), changing the name, icon and background, kicking, leaving and deleting, and the notices
// a group shows, and class chats (assets/php/schoolClass.php). Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/groups';
$docsIntro = 'En gruppechat er en chat med tre eller flere personer: din klasse, dit hold eller bare dine venner.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="make" aria-labelledby="docs-make">
                        <h2 id="docs-make">At lave en gruppe</h2>
                        <ol class="docs-steps">
                            <li>Tryk på <strong>New chat</strong> i <a href="/chat/">chatten</a>.</li>
                            <li>Vælg to eller flere personer.</li>
                            <li>Giv gruppen et navn, hvis du vil. Uden et bliver den opkaldt efter dem, der er i den.</li>
                        </ol>
                        <p>
                            Alle, du har valgt, får en <a href="/docs/chat/#requests">beskedanmodning</a> og kommer med, når de
                            accepterer den. Indtil da er de markeret <strong>Invited</strong> i medlemslisten. En gruppe kan have op
                            til 200 personer.
                        </p>
                    </section>

                    <section id="add" aria-labelledby="docs-add">
                        <h2 id="docs-add">At tilføje folk</h2>
                        <p>
                            Alle i en gruppe kan tilføje flere med knappen <strong>Add to chat</strong> (personen med et plus)
                            øverst i chatten. De får en anmodning om, hvem der tilføjede dem. Folk, der har
                            <a href="/docs/chat/safety#block">blokeret</a> dig, kan du ikke tilføje.
                        </p>
                    </section>

                    <section id="roles" aria-labelledby="docs-roles">
                        <h2 id="docs-roles">Ejer, admins og medlemmer</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Rolle</th><th scope="col">Kan</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Group Owner</td><td>Alt det, en admin kan, og også smide admins ud eller fjerne dem som admin. Den, der lavede gruppen, er dens ejer, og der er kun én.</td></tr>
                                    <tr><td>Group Admin</td><td>Omdøbe gruppen, ændre eller fjerne dens ikon, ændre dens baggrund, gøre medlemmer til admin, smide medlemmer ud, slette alles beskeder, <a href="/docs/chat/#pins">løsne</a> alles fastgjorte beskeder og slette hele gruppen.</td></tr>
                                    <tr><td>Member</td><td>Skrive, reagere, tilføje folk, <a href="#nicknames">give kælenavne</a> og gå ud.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Knappen <strong>Members</strong> (personen) øverst i chatten viser alle, med deres mærker. Admins gør
                            nogen til admin, fjerner dem som admin eller smider dem ud med knapperne ved deres navn. Når ejeren går,
                            overtager den admin, der har været i gruppen længst, eller det medlem, der har været med længst, når
                            der ikke er nogen admins.
                        </p>
                        <p>
                            Du kan også se <strong>Site Admin</strong> på nogen. De driver selve HB Hub, ikke gruppen, se
                            <a href="/docs/feedback#admins">Hvem er admins?</a>
                        </p>
                    </section>

                    <section id="nicknames" aria-labelledby="docs-nicknames">
                        <h2 id="docs-nicknames">Kælenavne</h2>
                        <p>
                            Alle i en gruppe kan give alle i den et kælenavn, også sig selv: åbn <strong>Members</strong>, og tryk
                            på <strong>Nickname</strong> ved dem. Alle i gruppen ser dem så under det navn i stedet for deres
                            brugernavn, på deres beskeder, reaktioner, i medlemslisten og så videre, og de kan blive
                            <a href="/docs/chat/mentions">@omtalt</a> med det. Medlemslisten viser stadig deres brugernavn ved siden af.
                        </p>
                        <p>
                            Kælenavne er op til 35 tegn, med de samme regler som navne og bioer: kun bogstaver fra det latinske
                            alfabet (som a-z, æ, ø og å), tal, tegnsætning og emoji, og ingen blokerede links. Lad det være tomt for
                            at fjerne kælenavnet. Hver ændring dukker op som en <a href="#notices">besked</a> i chatten om, hvem der
                            gav det, og den, der får et nyt kælenavn af en anden, får en notifikation om det (ikke fra en gruppe, de
                            har gjort lydløs, eller fra en, de har blokeret). Du kan ikke give kælenavne, mens du er gjort lydløs i
                            gruppen. Kælenavne gælder kun i den gruppe, og i en privat chat er et kælenavn noget andet: kun du ser
                            det (se <a href="/docs/chat/#chat-menu">chatmenuen</a>).
                        </p>
                    </section>

                    <section id="look" aria-labelledby="docs-look">
                        <h2 id="docs-look">Navn, ikon og baggrund</h2>
                        <p>Gruppeadmins finder dem i <a href="/docs/chat/#chat-menu">chatmenuen</a> (højreklik på gruppen i din liste):</p>
                        <ul>
                            <li><strong>Change name</strong>: op til 64 tegn. Lad det være tomt for igen at opkalde gruppen efter dens medlemmer.</li>
                            <li><strong>Change group icon</strong>: et billede i PNG, JPEG, GIF eller WebP, op til 2 MB. Det kan også fjernes igen.</li>
                            <li><strong>Change background</strong>: én farve, eller en overgang mellem to farver i den retning, du vælger. Reset sætter den normale tilbage.</li>
                        </ul>
                        <p>I en privat chat kan I begge ændre baggrunden.</p>
                    </section>

                    <section id="notices" aria-labelledby="docs-notices">
                        <h2 id="docs-notices">Beskeder om ændringer</h2>
                        <p>
                            Ændringer i en gruppe dukker op i chatten som små beskeder, så alle ved, hvad der skete: et nyt navn,
                            ikon eller en ny baggrund, nogen der kommer med, går, bliver tilføjet eller smidt ud, nogen der bliver
                            admin eller ejer, et nyt kælenavn, og beskeder, der bliver fastgjort eller løsnet.
                        </p>
                    </section>

                    <section id="leave" aria-labelledby="docs-leave">
                        <h2 id="docs-leave">At smide ud, gå og slette</h2>
                        <ul>
                            <li><strong>Kick</strong> tager nogen ud af gruppen. De får en notifikation og kan kun komme tilbage, hvis nogen tilføjer dem igen.</li>
                            <li><strong>Leave chat</strong> i chatmenuen tager dig ud. Dine beskeder bliver.</li>
                            <li><strong>Delete group</strong> (admins) lukker gruppen for alle og sletter de filer, der er sendt i den. En gruppe, som alle har forladt, bliver også slettet.</li>
                        </ul>
                    </section>

                    <section id="class" aria-labelledby="docs-class">
                        <h2 id="docs-class">Din klassechat</h2>
                        <p>
                            Går du på HTX, kommer du med i din klasses gruppechat, så snart du opretter dig, uden en
                            beskedanmodning at acceptere. Din klasse er alle, der startede i 1. år samme år som dig på samme
                            studieretning, så chatten hedder noget i stil med <strong>1.X</strong>.
                        </p>
                        <ul>
                            <li>Den følger din klasse: den 1. juli bliver den omdøbt med den nye årgang (<strong>1.X</strong> bliver til <strong>2.X</strong>), og beskederne bliver. Efter 3. år hedder den noget i stil med <strong>Graduate (X) 2029</strong>.</li>
                            <li>Ingen er ejer eller admin, så ingen kan omdøbe den, smide folk ud eller slette den. Det kan kun side-admins.</li>
                            <li>Du kan gøre den lydløs eller skjule den, men ikke forlade den, og du kan ikke tilføje folk til den.</li>
                            <li>Skifter du klasse under dine <a href="/docs/account#profile">indstillinger</a>, flytter du over i den anden klasses chat.</li>
                        </ul>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
