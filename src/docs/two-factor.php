<?php
// Help docs: two-factor login and security - setting up TOTP, logging in with Discord, the password rules, the login lock after wrong guesses
// (per known device, assets/php/loginLimit.php) and what to do when an account might be someone else's hands.
// The numbers come from the site config, which header.php (through top.php) has loaded. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/two-factor';
$docsIntro = 'En god adgangskode og totrinslogin sørger for, at du er den eneste, der kan komme ind på din konto.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';

$docsLoginAttempts = (int) ($siteConfig['login_attempts_max'] ?? 5);
$docsLockoutMinutes = (int) ($siteConfig['login_attempts_lockout_time'] ?? 15);
?>
                    <section id="two-factor" aria-labelledby="docs-two-factor">
                        <h2 id="docs-two-factor">At slå totrinslogin til</h2>
                        <p>
                            Med totrinslogin skal du bruge din adgangskode <em>og</em> en 6-cifret kode fra en godkendelsesapp på
                            din telefon for at logge ind. Koden skifter hvert 30. sekund, så en, der finder ud af din adgangskode,
                            stadig ikke kan komme ind.
                        </p>
                        <ol class="docs-steps">
                            <li>Installer en godkendelsesapp på din telefon, som <a href="https://proton.me/authenticator" target="_blank" rel="noopener">Proton Authenticator</a>, <a href="https://support.google.com/accounts/answer/1066447" target="_blank" rel="noopener">Google Authenticator</a>, <a href="https://2fas.com/" target="_blank" rel="noopener">2FAS</a> eller den adgangskodeapp, der er indbygget i din telefon.</li>
                            <li>Åbn <strong>Two-factor login</strong> i gruppen <strong>Security</strong> under dine <a href="/settings#totp">indstillinger</a>, og tryk på <strong>Set up</strong>.</li>
                            <li>Scan QR-koden med appen. På selve telefonen kan du bruge <strong>Open it in your app</strong> i stedet, eller skrive nøglen under koden.</li>
                            <li>Skriv den 6-cifrede kode, appen viser, for at slå det til. Fra nu af beder login om en kode efter din adgangskode.</li>
                        </ol>
                        <p>
                            For at slå det fra igen trykker du på <strong>Turn off</strong> samme sted og skriver en kode fra appen. Behold appen: mister du din
                            telefon, skal du have hjælp fra en admin for at komme ind igen.
                        </p>
                    </section>

                    <section id="discord" aria-labelledby="docs-discord">
                        <h2 id="docs-discord">At logge ind med Discord</h2>
                        <p>
                            Du kan koble din Discord-konto til HB Hub og så logge ind med <strong>Log ind med Discord</strong> på
                            login-siden i stedet for at skrive din adgangskode. Din adgangskode virker også stadig, og med
                            totrinslogin slået til bliver du stadig bedt om koden. HB Hub ser kun dit Discord-brugernavn,
                            visningsnavn og profilbillede (og din bio, hvis Discord deler den), intet andet fra Discord.
                        </p>
                        <ul>
                            <li>Opret dig med <strong>Opret dig med Discord</strong> på første trin af oprettelsen. Dit navn, din bio og dit billede starter som dem fra Discord, og du kan ændre dem, før din konto bliver oprettet. Du vælger stadig din klasse og en adgangskode, og kontoen bliver koblet til Discord med det samme. <strong>Kobl den ikke til</strong> går tilbage til en normal oprettelse.</li>
                            <li>Eller kobl den til senere med <strong>Link Discord</strong> under <strong>Discord login</strong> i gruppen <strong>Security</strong> under dine <a href="/settings#discord">indstillinger</a>.</li>
                            <li>Dit Discord-brugernavn bliver vist på din profil, mens den er koblet til. Slå <strong>Show my Discord username on my profile</strong> fra samme sted for at skjule det. Skifter du dit brugernavn på Discord, opdager HB Hub det nye i løbet af natten (eller næste gang du logger ind med Discord).</li>
                            <li><strong>Unlink</strong> samme sted fjerner koblingen igen. Én Discord-konto kan kun kobles til én HB Hub-konto.</li>
                        </ul>
                    </section>

                    <section id="passwords" aria-labelledby="docs-passwords">
                        <h2 id="docs-passwords">Reglerne for adgangskoder</h2>
                        <p>
                            En ny adgangskode skal være lang nok og blande nok slags tegn (små bogstaver, store bogstaver, tal og
                            symboler). Formularen fortæller dig, hvad der mangler, mens du skriver. Adgangskoder, der er på listen
                            over de mest brugte, som <code>123456</code> eller <code>password</code>, kan slet ikke bruges.
                        </p>
                        <ul>
                            <li>Brug ikke den samme adgangskode som et andet sted.</li>
                            <li>Fortæl aldrig nogen din adgangskode, heller ikke en admin. Admins har aldrig brug for den.</li>
                            <li>En adgangskodeadministrator (de fleste browsere og telefoner har en) kan lave og huske en stærk en for dig.</li>
                        </ul>
                    </section>

                    <section id="lockout" aria-labelledby="docs-lockout">
                        <h2 id="docs-lockout">Forkerte adgangskoder og login-spærringen</h2>
                        <p>
                            Efter <?= $docsLoginAttempts ?> forkerte adgangskoder (eller totrinskoder) i træk bliver login låst et
                            stykke tid. Det samme gælder for den adgangskode og de koder, indstillingssiden beder om før en stor
                            ændring.
                        </p>
                    </section>

                    <section id="hacked" aria-labelledby="docs-hacked">
                        <h2 id="docs-hacked">Hvis du tror, at nogen er kommet ind på din konto</h2>
                        <ol class="docs-steps">
                            <li>Log ind, og <a href="/docs/account#password">skift din adgangskode</a>. Det logger alle andre enheder ud.</li>
                            <li>Tjek <a href="/docs/account#devices">Logged in devices</a> for noget, du ikke kan genkende.</li>
                            <li>Slå totrinslogin til, hvis det ikke allerede er, og fjern koblingen til en Discord-konto, du ikke kan genkende, under <a href="/settings#discord">Discord login</a>.</li>
                            <li>Sig det til en admin, send en besked til <a href="https://hbhub.noahgajnielsen.dk/profile?user=1">Noha Gayy</a> eller en e-mail til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, især hvis du ikke kan logge ind længere.</li>
                        </ol>
                        <p>
                            Har du fundet et sikkerhedsproblem i selve HB Hub? Rapportér det privat med
                            <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">Rapportér et sikkerhedsproblem</a>
                            i stedet for at fortælle alle om det.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
