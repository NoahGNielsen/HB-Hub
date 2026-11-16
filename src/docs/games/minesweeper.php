<?php
// Help docs: Minesweeper - the rules (assets/php/games/minesweeper/rules.php, included as the game shows them), what the
// numbers mean, a few tips and the leaderboard. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/minesweeper';
$docsIntro = 'Klassikeren, i sit Windows 95-udseende: åbn alle felter uden en mine. Spil det på
    <a href="/games/minesweeper/">Minesweeper</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/rules.php'; ?>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                Et tal, der allerede har så mange flag rundt om sig, er færdigt: alle de andre felter ved siden af
                                det er sikre at åbne.
                            </li>
                            <li>
                                Et tal med præcis så mange lukkede felter rundt om sig betyder, at de alle er miner, som et 1-tal
                                med kun ét lukket felt ved siden af. Start fra de tal, der har færrest lukkede felter rundt om sig.
                            </li>
                            <li>
                                Hard er 30 felter bred. På en telefon scroller brættet sidelæns.
                            </li>
                        </ul>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Rangliste</h2>
                        <p>
                            Der er en <a href="/games/minesweeper/leaderboard">Minesweeper-rangliste</a> for hver størrelse, over alle,
                            der har vundet dér: deres bedste og gennemsnitlige tid, sejre, spillede spil og sejrsprocent (vist fra 5
                            spil). Efter en sejr viser siden over brættet din tid og din bedste.
                        </p>
                        <p>
                            Minerne bliver holdt på HB Hubs server, ikke i din browser, og din tid bliver taget dér, fra dit første
                            klik til det, der vinder. Så tiden tæller med, hvor lang tid hvert klik tager om at nå serveren, og at
                            kigge i sidens kode afslører ikke minerne. Et spil kan ikke tages op igen, når du har lukket siden: det
                            tæller som spillet, men ikke vundet.
                        </p>
                        <p>
                            Der er også et simpelt anticheat mod bots, der løser brættet for dig. Når du vinder, regner serveren ud,
                            hvor få klik brættet kunne vindes på, og har du lavet dem hurtigere end 10 i sekundet (langt over verdens
                            bedste spillere), tæller sejren ikke: den bliver vist som spillet, men ikke på ranglisten. Normalt hurtigt
                            spil er slet ikke i nærheden. Spillet stopper også sig selv, når et klik på brættet kommer fra kode i
                            stedet for fra dig (som et skrevet i browserens konsol, eller en autoklikker-udvidelse), og det spil
                            tæller som spillet, men ikke vundet.
                        </p>
                        <p>Det er en kopi af Nick Arochos Minesweeper (se <a href="/legal/credits#code">krediteringerne</a>).</p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
