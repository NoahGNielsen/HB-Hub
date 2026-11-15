<?php
// Help docs: Slots - how to play, the paylines, the game's own rules with its pay table
// (assets/php/casino/slots/rules.php, included as the game shows them) and what the machine pays back. Every number
// comes from assets/php/casino/slots/slots.php. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';

const DOCS_SLOTS_LINE_NAMES = ['Den midterste række', 'Den øverste række', 'Den nederste række', 'Øverst til venstre til nederst til højre', 'Nederst til venstre til øverst til højre'];

$docsPath = '/docs/casino/slots';
$docsIntro = 'En spilleautomat med tre hjul og ' . count(HBHUB_SLOTS_LINES) . ' gevinstlinjer. Vælg en indsats, drej, og
    se, hvad der kommer på linje. Hvert spin er dit eget, så du kan spille i dit eget tempo ved
    <a href="/casino/slots/">spilleautomaten</a>.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Hjul</dt><dd>3</dd></div>
                        <div><dt>Gevinstlinjer</dt><dd><?= count(HBHUB_SLOTS_LINES) ?></dd></div>
                        <div><dt>Indsatser i trin af</dt><dd><?= HBHUB_SLOTS_BET_STEP ?></dd></div>
                        <div><dt>Bedste udbetaling</dt><dd><?= HBHUB_SLOTS_PAYS['seven'] ?>&times; en linje</dd></div>
                    </dl>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">Sådan spiller du</h2>
                        <ol class="docs-steps">
                            <li>Vælg en indsats med chipsene på automaten under hjulene, eller flyt den op og ned med <strong>&minus;</strong> og <strong>+</strong>. Med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips tilbage satser <strong>All in</strong>-chippen alle dine chips.</li>
                            <li>Tryk på <strong>Spin</strong> (eller <kbd>Mellemrum</kbd>, eller træk i håndtaget ved siden af automaten). Indsatsen bliver taget, og hjulene ruller og stopper ét efter ét fra venstre. Når de første to hjul har to 7'ere, diamanter eller stjerner på linje, ruller det sidste lidt længere og lyser, da det kan give en stor gevinst. Det er kun for showets skyld: hvor det stopper, var allerede valgt.</li>
                            <li>Hver vindende linje tegner sig selv og bliver betalt, og er der mere end én, får hver linje sin tur med det, den betalte. Jo større gevinst, jo større show: mønter, et Big win- eller Mega win-banner, og fyrværkeri og faldende 7'ere for tre 7'ere. Din indsats forbliver den samme, så du kan dreje igen med det samme (hvilket også rydder showet).</li>
                        </ol>
                        <p>Dine seneste spins står ved siden af automaten, med hvad hvert af dem vandt eller tabte.</p>
                        <p>Automaten har lydeffekter. Knappen <strong>Sound</strong> ved linket til ranglisten slår dem fra og til, og din browser husker, hvad du valgte.</p>
                    </section>

                    <section id="lines" aria-labelledby="docs-lines">
                        <h2 id="docs-lines">Gevinstlinjerne</h2>
                        <p>
                            Hvert spin spiller alle <?= count(HBHUB_SLOTS_LINES) ?> linjer, og din indsats bliver delt ligeligt ud
                            på dem: en indsats på 50 er 10 på hver linje. Linjerne er nummereret ned langs begge sider af automaten.
                        </p>
                        <ol>
                            <?php foreach (DOCS_SLOTS_LINE_NAMES as $docsLineName): ?>
                                <li><?= $docsLineName ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne og gevinsttabellen</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/rules.php'; ?>
                        <p>
                            "Betaler" er, hvor mange gange en linjes andel af indsatsen den betaler tilbage, andelen medregnet, så
                            med en indsats på 50 (10 per linje) betaler tre 7'ere <?= number_format(HBHUB_SLOTS_PAYS['seven'] * 10, 0, ',', '.') ?>.
                        </p>
                    </section>

                    <section id="odds" aria-labelledby="docs-odds">
                        <h2 id="docs-odds">Hvad automaten betaler tilbage</h2>
                        <p>
                            Over lang tid betaler spilleautomaten cirka <?= number_format(hbHubSlotsReturn() * 100, 1, ',', '.') ?> %
                            af alt, der er satset på den, tilbage, så huset beholder cirka
                            <?= number_format((1 - hbHubSlotsReturn()) * 100, 1, ',', '.') ?> %. Små gevinster kommer tit, de store
                            sjældent: tre 7'ere på en linje er 1 ud af <?= number_format(hbHubSlotsOdds('seven'), 0, ',', '.') ?>. Intet
                            spin afhænger af dem før, så en automat "skal" aldrig give en gevinst.
                        </p>
                        <p>
                            Hvert spin tæller med på <a href="/casino/slots/leaderboard">Slots-ranglisten</a>, med din største
                            gevinst, og hvor meget du har vundet eller tabt i alt.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
