<?php
// Help docs: Roulette - every bet with what it covers, pays and how often it hits, the game's own rules
// (assets/php/casino/roulette/rules.php, included as the game shows them) and how to play. The red numbers come from
// roulette.php's HBHUB_ROULETTE_RED. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';

// Every bet: [name, what it covers, how many numbers, what it pays]. The chance is worked out from the numbers, out of 37.
const DOCS_ROULETTE_BETS = [
    ['Straight (ét tal)', 'Ét tal, også 0', 1, '35:1'],
    ['Split (to tal)', 'To tal ved siden af hinanden', 2, '17:1'],
    ['Street (række)', 'En række på tre tal, eller 0-1-2 og 0-2-3', 3, '11:1'],
    ['Corner (hjørne)', 'Fire tal, der mødes i et hjørne, eller 0-1-2-3', 4, '8:1'],
    ['Line (to rækker)', 'To rækker ved siden af hinanden: seks tal', 6, '5:1'],
    ['Dozen (dusin)', '1-12, 13-24 eller 25-36', 12, '2:1'],
    ['Column (kolonne)', 'En af de tre lange kolonner', 12, '2:1'],
    ['Rød eller sort', 'Alle 18 tal i den farve', 18, '1:1'],
    ['Ulige eller lige', 'Alle 18 ulige eller lige tal (ikke 0)', 18, '1:1'],
    ['1-18 eller 19-36', 'Den lave eller den høje halvdel', 18, '1:1'],
];

$docsPath = '/docs/casino/roulette';
$docsIntro = 'Europæisk roulette med ét grønt nul. Læg dine chips hvor som helst på bordet, og se, hvor kuglen lander.
    Alle på HB Hub spiller ved det samme <a href="/casino/roulette/">Roulette</a>-bord, så I ser hinandens indsatser, og
    der bliver drejet én kugle for jer alle.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Tal</dt><dd>0-36</dd></div>
                        <div><dt>Nuller</dt><dd>1</dd></div>
                        <div><dt>Indsatser i trin af</dt><dd><?= HBHUB_ROULETTE_BET_STEP ?></dd></div>
                        <div><dt>Bedste udbetaling</dt><dd>35:1</dd></div>
                    </dl>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">Sådan spiller du</h2>
                        <ol class="docs-steps">
                            <li>Vælg en chip, og klik på bordet, hvor du vil satse. Klik igen for at lægge mere, og højreklik på en indsats for at tage en chip af. <strong>Undo</strong> tager den sidste chip tilbage, <strong>Clear</strong> dem alle. Med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips tilbage lægger <strong>All in</strong>-chippen alle dine chips på den indsats, du klikker på.</li>
                            <li>Tryk på <strong>Spin</strong>. Hjulet drejer, og kuglen lander på et tal.</li>
                            <li>Vindende indsatser bliver betalt, tabende bliver taget. Dine indsatser bliver liggende på bordet, så du kan dreje igen med det samme eller ændre dem først.</li>
                        </ol>
                        <p>Dine seneste spins står over bordet, og <kbd>Mellemrum</kbd> drejer igen.</p>
                    </section>

                    <section id="bets" aria-labelledby="docs-bets">
                        <h2 id="docs-bets">Alle indsatser</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Indsats</th><th scope="col">Dækker</th><th scope="col">Betaler</th><th scope="col">Rammer</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (DOCS_ROULETTE_BETS as [$docsBetName, $docsBetCovers, $docsBetNumbers, $docsBetPays]): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsBetName) ?></td>
                                            <td><?= htmlspecialchars($docsBetCovers) ?></td>
                                            <td><?= $docsBetPays ?></td>
                                            <td><?= number_format($docsBetNumbers / 37 * 100, 1, ',', '.') ?> %</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            De røde tal er <?= implode(', ', HBHUB_ROULETTE_RED) ?>. De andre tal fra 1 til 36 er sorte, og 0 er grønt.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/rules.php'; ?>
                    </section>

                    <section id="zero" aria-labelledby="docs-zero">
                        <h2 id="docs-zero">Hvorfor nullet betyder noget</h2>
                        <p>
                            Hver indsats betaler, som om der kun var 36 tal, men der er 37. Det ene grønne 0 er husets fordel: cirka
                            2,7 % af alt, hvad du satser, uanset hvilken indsats du vælger. Intet system af indsatser ændrer det, og
                            intet tal "skal snart komme": hvert spin er lige så tilfældigt som det før.
                        </p>
                        <p>
                            Hvert spin tæller med på <a href="/casino/roulette/leaderboard">Roulette-ranglisten</a>, med din største
                            gevinst, og hvor meget du har vundet eller tabt i alt.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
