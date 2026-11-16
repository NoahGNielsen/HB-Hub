<?php
// Help docs: Sudoku - the rules (assets/php/games/sudoku/rules.php, included as the game shows them), the levels, a
// few tips and where the game is kept. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/sudoku';
$docsIntro = 'Talgåden: udfyld brættet, så hver række, kolonne og boks har 1 til 9 én gang hver. Spil det på
    <a href="/games/sudoku/">Sudoku</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/rules.php'; ?>
                    </section>

                    <section id="levels" aria-labelledby="docs-levels">
                        <h2 id="docs-levels">Niveauerne</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Niveau</th><th scope="col">Tal fra start</th><th scope="col">Hvad det kræver</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Easy</td><td>Cirka 38</td><td>Der er altid et felt, som kun kan være ét tal.</td></tr>
                                    <tr><td>Medium</td><td>Cirka 32</td><td>Kræver også, at man leder efter det eneste felt i en række, kolonne eller boks, hvor et tal kan stå.</td></tr>
                                    <tr><td>Hard</td><td>Cirka 28</td><td>Kan kræve mere end det, som noter og at udelukke tal.</td></tr>
                                    <tr><td>Expert</td><td>Så få som muligt, som regel 22 til 25</td><td>Kræver altid mere end de to tricks ovenfor.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Hver opgave bliver lavet ny, når du starter den, og hver eneste har præcis én løsning.</p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                Klik på et tal på brættet for at se alle de andre felter med samme tal lyse op. Så er det nemt at se,
                                hvilke rækker, kolonner og bokse der stadig mangler det.
                            </li>
                            <li>
                                Under hvert tal på tastaturet står, hvor mange af det der mangler. Et gråt tal har alle ni placeret.
                            </li>
                            <li>
                                På Hard og Expert skal du skrive noter. Når du sætter et tal i et felt, bliver det fjernet fra noterne
                                rundt om det for dig, så et felt, der er tilbage med én note, er det næste, du skal udfylde.
                            </li>
                        </ul>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">At gemme og ranglisten</h2>
                        <p>
                            Opgaven bliver lavet af HB Hubs server, som holder dens løsning og tager tid på spillet, så din browser
                            aldrig kender løsningen. De tal og noter, du skriver, bliver gemt i din browser, så du kan lukke siden og
                            fortsætte senere. I en anden browser eller på en anden enhed får du den samme opgave tilbage, men de
                            felter, du har udfyldt, starter tomme igen.
                        </p>
                        <p>
                            Der er en <a href="/games/sudoku/leaderboard">Sudoku-rangliste</a> for hvert niveau, med alles bedste og
                            gennemsnitlige tid, og hvor mange de har løst. Kun opgaver løst uden et hint tæller. Uret stopper, mens
                            spillet er sat på pause, eller du er væk fra siden, så den tid tæller ikke. En opgave, der er udfyldt
                            hurtigere, end et menneske kan (mindre end et sekund for hvert tomt felt), tæller heller ikke.
                        </p>
                        <p>
                            Den <a href="/play/sudoku">gratis Sudoku</a>, til at spille uden en konto, bliver kun spillet i din browser
                            og har ingen rangliste.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
