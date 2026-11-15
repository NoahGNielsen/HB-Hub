<?php
// Help docs: profile and settings - the profile card, password, logged in devices, logging out and deleting the
// account. Follows the settings page's four groups of rows, Profile, Preferences, Security and Account (otherPages/settings.php). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/account';
$docsIntro = 'Alt om din konto er på <a href="/settings">indstillingssiden</a>: åbn din profil (dit billede i
    topbjælken), og tryk på tandhjulet. Den er en liste, ligesom indstillingerne på en telefon, i fire grupper: Profile,
    Preferences, Security og Account. Tryk på en linje for at åbne den. Det, du ændrer, bliver gemt med det samme, så
    der er ingen gem-knap.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="profile" aria-labelledby="docs-profile">
                        <h2 id="docs-profile">Din profil</h2>
                        <p>Din profil er sådan, alle andre ser dig. Gruppen <strong>Profile</strong> under indstillinger ændrer den, i <strong>Picture and banner</strong>, <strong>Name, class and bio</strong> og <strong>Status</strong>:</p>
                        <ul>
                            <li><strong>Picture</strong>: en PNG, JPEG, GIF eller WebP. Den bliver vist ved dine beskeder, i chatlisten og på ranglisterne.</li>
                            <li><strong>Name</strong>: det, alle ser dig som, og det, du logger ind med.</li>
                            <li><strong>Year og field of study</strong>: 1., 2. eller 3. år og X, U, T, Y eller Z, eller Not HTX, så folk ved, hvem du er. Din årgang stiger af sig selv hvert år den 1. juli, og efter 3. år bliver du vist som Graduate. Skifter du, flytter du over i den anden <a href="/docs/chat/groups#class">klassechat</a>.</li>
                            <li><strong>Banner</strong>: den brede stribe øverst på din profil. Vælg to farver, og hvilken vej de går, ligesom en <a href="/docs/chat/groups#look">chatbaggrund</a>, eller et billede. Et billede bliver gjort mindre og lavet om til JPEG XL på din enhed, og tager en af dine <a href="/docs/chat/attachments#tickets">billetter til vedhæftede filer</a>, så længe det er dit banner (det udløber aldrig). Går du tilbage til farver, får du billetten tilbage.</li>
                            <li><strong>Education</strong>: et mærke for den slags uddannelse, du tager, som 💻 IT and media eller 🍳 Food and service.</li>
                            <li><strong>Status</strong>: en emoji og op til 45 tegn på op til to linjer, vist ved dit profilbillede. Ligesom bioen tager den kun det latinske alfabet, tal, tegnsætning og emoji. Vælg, hvornår den rydder sig selv: efter 30 minutter, 1 time, 8 timer, 24 timer (standard) eller 7 dage, eller aldrig. Du kan også klikke på din status på din egen profil for at ændre eller rydde den dér, eller klikke på "Click here to add a status!" (kun du ser det), når du ikke har en.</li>
                            <li><strong>Bio</strong>: et par ord om dig selv.</li>
                        </ul>
                        <p>
                            Alle, der er logget ind, kan åbne din profil, fra chatten, en rangliste eller et spil, og skrive til dig
                            derfra. Klikker man på et profilbillede eller bannerbillede dér, bliver det vist stort. Ved din klasse og
                            uddannelse viser den et Site admin-mærke for side-admins, og om du er online. Billeder skal følge
                            <a href="/legal/code-of-conduct">adfærdskodekset</a> ligesom alt andet: et profilbillede eller
                            bannerbillede, der får en konto udelukket, bliver også bandlyst, på alle konti, både som profilbillede og
                            som banner. Side-admins kan også fjerne en status eller et bannerbillede, der bryder reglerne.
                        </p>
                    </section>

                    <section id="password" aria-labelledby="docs-password">
                        <h2 id="docs-password">At skifte din adgangskode</h2>
                        <p>
                            Skriv din nuværende adgangskode, og så den nye to gange, under <strong>Password</strong> i gruppen <strong>Security</strong>. At skifte
                            den logger dig ud på alle andre enheder, så det er også det, du skal gøre, hvis du tror, at en anden
                            kender den. Reglerne for en ny adgangskode står i <a href="/docs/two-factor#passwords">Totrinslogin og sikkerhed</a>.
                        </p>
                    </section>

                    <section id="devices" aria-labelledby="docs-devices">
                        <h2 id="docs-devices">Enheder, du er logget ind på</h2>
                        <p>
                            <strong>Logged in devices</strong>, i gruppen <strong>Security</strong>, viser hver browser, du er logget ind i, med dens browser og system,
                            hvornår du loggede ind, og hvornår den sidst blev brugt. Log ud af hver af dem med dens knap. Er der en,
                            du ikke kan genkende, så log den ud, og skift din adgangskode.
                        </p>
                        <p><strong>Log out everywhere</strong>, i gruppen <strong>Account</strong>, logger dig ud på alle enheder, også den, du er på.</p>
                    </section>

                    <section id="log-out" aria-labelledby="docs-log-out">
                        <h2 id="docs-log-out">At logge ud</h2>
                        <p>
                            <strong>Log out</strong>, i gruppen <strong>Account</strong> nederst på indstillingssiden, logger dig ud på den enhed, du er på. Du
                            forbliver logget ind alle andre steder. På en delt computer skal du altid logge ud, når du er færdig.
                        </p>
                    </section>

                    <section id="delete" aria-labelledby="docs-delete">
                        <h2 id="docs-delete">At slette din konto</h2>
                        <p>
                            <strong>Delete account</strong>, helt nederst, beder om din adgangskode og sletter så din konto med det
                            samme. Det kan ikke fortrydes.
                        </p>
                        <ul>
                            <li>Dit navn, billede, banner, din bio, status, årgang, klasse, dine indstillinger, logins og de filer, du har vedhæftet i chats, bliver slettet, og du bliver logget ud overalt.</li>
                            <li>Beskeder, du har sendt, bliver i deres chats, fra "Deleted User".</li>
                            <li>Dit gemte spil i Ore Mining Inc. bliver også slettet, og ingen kan logge ind på kontoen eller finde den igen.</li>
                        </ul>
                        <p class="legal-note">
                            Vil du have det hele væk, også beskeder, eller en kopi af dine data først?
                            <a href="/legal/privacy">Privatlivspolitikken</a> forklarer, hvordan du beder om det.
                        </p>
                    </section>

                    <section id="more" aria-labelledby="docs-more">
                        <h2 id="docs-more">De andre indstillinger</h2>
                        <p>Gruppen <strong>Preferences</strong> har:</p>
                        <ul>
                            <li><a href="/docs/notifications">Notifikationer</a>: en browsernotifikation eller en lyd ved nye beskeder.</li>
                            <li><a href="/docs/self-blocking">Bloker Casino og Spil</a>: hold dig selv væk fra dem i skoletiden eller hele tiden.</li>
                            <li>
                                <strong>Help improve HB Hub</strong>: om siden må samle statistik om, hvordan den virker for dig, fx
                                de fejl, der sker i din browser på siden, og når serveren har for travlt til at svare. Den er slået til, indtil du slår den fra. Ore Mining Inc.s
                                egne nedbrudsrapporter spørger altid først; har du slået den fra, står der i spørgsmålet, at det går
                                imod indstillingen at sende en. Hvad der bliver samlet, står i
                                <a href="/legal/privacy#watchdog">privatlivspolitikken</a>.
                            </li>
                        </ul>
                        <p>
                            Gruppen <strong>Security</strong> har, ud over din adgangskode og dine enheder,
                            <a href="/docs/two-factor">totrinslogin</a> (en kode fra din telefon oven i din adgangskode) og Discord-login.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
