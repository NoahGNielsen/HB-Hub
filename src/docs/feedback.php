<?php
// Help docs: feedback and getting help - the Feedback button (otherPages/feedback.php) and what happens to what's sent,
// who the site admins are, and where to report people and security problems. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/feedback';
$docsIntro = 'Har du fundet en fejl, fået en idé eller brug for en hånd? Her er, hvem du kan spørge, og hvordan.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="feedback" aria-labelledby="docs-feedback">
                        <h2 id="docs-feedback">At sende feedback</h2>
                        <ol class="docs-steps">
                            <li>Tryk på <strong>Feedback</strong> nederst i sidebjælken (for enden af bundbjælken på en telefon), på den side, du vil fortælle om.</li>
                            <li>Skriv, hvad der er galt, hvad du gerne vil have, eller hvad du synes. Ved en fejl, så skriv, hvad du gjorde, og hvad der skete.</li>
                            <li>Send den. Du bliver sendt tilbage til den side, du var på.</li>
                        </ol>
                        <p>
                            Kun side-admins kan læse feedback, og de kan se, hvilken side du sendte den fra, så du behøver ikke
                            forklare, hvor du var. Når en admin markerer din som færdig, får du en
                            <a href="/docs/notifications">notifikation</a> med deres svar.
                        </p>
                    </section>

                    <section id="admins" aria-labelledby="docs-admins">
                        <h2 id="docs-admins">Hvem er admins?</h2>
                        <p>
                            Side-admins driver HB Hub. De har et <strong>Site Admin</strong>-mærke på deres profiler. De hjælper
                            folk, der sidder fast, undersøger anmeldelser og kan udelukke konti eller slette beskeder, der bryder
                            <a href="/legal/code-of-conduct">adfærdskodekset</a>. Alt, hvad de gør i adminpanelet, bliver logget.
                        </p>
                        <p>
                            Gruppeejere og gruppeadmins er noget andet: de styrer kun deres egen <a href="/docs/chat/groups">gruppechat</a>
                            og kan ikke gøre noget uden for den.
                        </p>
                    </section>

                    <section id="help" aria-labelledby="docs-help">
                        <h2 id="docs-help">Hvor du skal gå hen med hvad</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Du vil</th><th scope="col">Gør sådan her</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Rapportere en fejl eller sende en idé</td><td>Feedback-knappen.</td></tr>
                                    <tr><td>Anmelde nogen</td><td>Feedback-knappen, en side-admin eller <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. Se <a href="/docs/chat/safety#report">At anmelde nogen</a>.</td></tr>
                                    <tr><td>Komme ind på din konto igen</td><td>Skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> uden for HB Hub, eller spørg en side-admin på skolen.</td></tr>
                                    <tr><td>Spørge om dine data</td><td>Se <a href="/legal/privacy">privatlivspolitikken</a>.</td></tr>
                                    <tr><td>Rapportere et sikkerhedsproblem</td><td>Privat, med <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">Rapportér et sikkerhedsproblem</a>. Fortæl det ikke til andre, før det er rettet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            HB Hub er open source: koden ligger på <a href="https://github.com/NoahGNielsen/HB-Hub" target="_blank" rel="noopener noreferrer">GitHub</a>,
                            hvis du er nysgerrig efter, hvordan noget virker.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
