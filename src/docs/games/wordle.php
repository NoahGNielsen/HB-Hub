<?php
// Help docs: Wordle - the colours (with an example guess), the two languages, random words, streaks and the leaderboard. The numbers
// are assets/php/games/wordle/wordle.php's constants, the same rules the game's own "How to play" shows.
// Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';

// An example guess, "huset" when the word is "hilse": [letter, how it did ('correct', 'present' or '' for not in it)]
const DOCS_WORDLE_EXAMPLE = [['h', 'correct'], ['u', ''], ['s', 'present'], ['e', 'present'], ['t', '']];

$docsPath = '/docs/games/wordle';
$docsIntro = 'Ét ord om dagen, ' . HBHUB_WORDLE_TRIES . ' forsøg til at gætte det. Der er et engelsk og et dansk, det
    samme for alle. Spil det på <a href="/games/wordle/">Wordle</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">Sådan spiller du</h2>
                        <p>
                            Skriv et ord på <?= HBHUB_WORDLE_LENGTH ?> bogstaver på dit tastatur eller med bogstaverne på skærmen, og
                            tryk på <kbd>Enter</kbd>. Det skal være et rigtigt ord. Så skifter bogstaverne farve:
                        </p>
                        <div class="docs-tiles" role="img" aria-label="Gættet HUSET: H grøn, S og E gule, U og T grå">
                            <?php foreach (DOCS_WORDLE_EXAMPLE as [$docsLetter, $docsHow]): ?>
                                <span class="docs-tile<?= $docsHow !== '' ? ' is-' . $docsHow : '' ?>" aria-hidden="true"><?= $docsLetter ?></span>
                            <?php endforeach; ?>
                        </div>
                        <ul>
                            <li><span class="docs-tile-word is-correct">Grøn</span>: det rigtige bogstav på det rigtige sted. Ordet starter med H.</li>
                            <li><span class="docs-tile-word is-present">Gul</span>: i ordet, men et andet sted. Der er et S og et E, bare ikke dér.</li>
                            <li><span class="docs-tile-word">Grå</span>: ikke i ordet, eller ikke så mange gange. Intet U og intet T.</li>
                        </ul>
                        <p>Brug det, du har lært, til næste gæt. Tastaturet farver også sine bogstaver, så du kan se, hvilke der er tilbage.</p>
                    </section>

                    <section id="languages" aria-labelledby="docs-languages">
                        <h2 id="docs-languages">Engelsk og dansk</h2>
                        <p>
                            Skift mellem dem med fanerne øverst. Det danske tastatur har æ, ø og å, og alle bøjninger af et dansk
                            ord tæller som et gæt, som "huset" eller "spist". Du får ét forsøg på hvert ord, og et ord bliver ikke
                            brugt igen, før alle de andre har været brugt.
                        </p>
                    </section>

                    <section id="random" aria-labelledby="docs-random">
                        <h2 id="docs-random">Tilfældige ord</h2>
                        <p>
                            Vil du have mere end ét om dagen? Vælg <strong>Random words</strong> øverst på
                            <a href="/games/wordle/?mode=random">Wordle</a>-siden. Hvert ord bliver valgt tilfældigt kun til dig
                            (aldrig dagens ord), så ingen andre spiller det samme, og når det er slut, giver
                            <strong>New word</strong> dig et nyt. Spil så mange, du vil, på begge sprog. De tæller ikke med i din
                            statistik, dine stimer eller på ranglisten. Tilfældige ord på et sprog åbner, når du har spillet dagens
                            ord på det, uanset om du fik det eller ej: en popup tilbyder dem, så snart du er færdig med det.
                        </p>
                    </section>

                    <section id="streaks" aria-labelledby="docs-streaks">
                        <h2 id="docs-streaks">Stimer og statistik</h2>
                        <p>
                            Nye ord kommer ved midnat, dansk tid. Din stime er de dage i træk, du har gættet ordet. Springer du en
                            dag over, eller får du det ikke, starter den forfra, og det gør den også ved et ord, du har startet på,
                            men ikke er færdig med ved midnat, så at give op halvvejs redder den ikke.
                        </p>
                        <p>
                            Når du er færdig, viser din statistik dine sejre, stimer, og hvor mange gæt dine sejre tog.
                            <strong>Share</strong> kopierer dit resultat som farvede felter uden at afsløre ordet.
                        </p>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Rangliste</h2>
                        <p>
                            Der er en <a href="/games/wordle/leaderboard">Wordle-rangliste</a> for hvert sprog, med hvor mange du har
                            spillet og vundet, din sejrsprocent (fra 5 spil), dine gennemsnitlige gæt, og din nuværende og længste
                            stime.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
