<?php
// Help docs: Blackjack - a round step by step, the table's own rules (assets/php/casino/blackjack/rules.php, included
// as the game shows them, so they can't drift apart), the payouts and a few tips. The numbers are room.php's and
// round.php's constants. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';

$docsPath = '/docs/casino/blackjack';
$docsIntro = 'Kom tættere på 21 end dealeren uden at komme over. Spil <a href="/casino/blackjack/">Blackjack</a>
    alene mod dealeren eller ved et bord med dine venner.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd>1-<?= HBHUB_BLACKJACK_SEATS ?></dd></div>
                        <div><dt>Kortspil</dt><dd>6</dd></div>
                        <div><dt>Blackjack betaler</dt><dd>3:2</dd></div>
                        <div><dt>Tid per træk</dt><dd><?= HBHUB_BLACKJACK_TURN_MS / 1000 ?> s</dd></div>
                    </dl>

                    <section id="round" aria-labelledby="docs-round">
                        <h2 id="docs-round">En runde, trin for trin</h2>
                        <ol class="docs-steps">
                            <li><strong>Sats.</strong> Klik på chipsene for at bygge din indsats (&frac12; og &times;2 halverer eller fordobler den), mindst bordets mindsteindsats, og tryk på <strong>Place bet</strong>. Med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips tilbage satser <strong>All in</strong>-chippen i stedet dem alle. <strong>Take back</strong> fortryder den før udlægningen. Indsatserne lukker <?= HBHUB_BLACKJACK_BETTING_MS / 1000 ?> sekunder efter den første indsats, eller så snart alle har satset.</li>
                            <li><strong>Udlægningen.</strong> Alle får to kort med billedsiden op. Dealeren får ét med billedsiden op og ét med billedsiden ned (hulkortet).</li>
                            <li><strong>Din tur.</strong> Spillerne går i pladsrækkefølge. <strong>Hit</strong> for et kort mere, <strong>Stand</strong> for at stoppe, <strong>Double</strong> for at fordoble din indsats for præcis ét kort mere, eller <strong>Split</strong> for at dele et par i to hænder. Tasterne <kbd>H</kbd>, <kbd>S</kbd>, <kbd>D</kbd> og <kbd>P</kbd> gør det samme. Kommer du over 21, er du bust: indsatsen er tabt.</li>
                            <li><strong>Dealeren.</strong> Vender hulkortet og trækker, indtil den har 17 eller mere.</li>
                            <li><strong>Udbetaling.</strong> Tættere på 21 end dealeren vinder, samme total er push (du får din indsats tilbage), og går dealeren bust, vinder alle, der stadig er med.</li>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne ved HB Hubs borde</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/rules.php'; ?>
                    </section>

                    <section id="payouts" aria-labelledby="docs-payouts">
                        <h2 id="docs-payouts">Udbetalinger</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Resultat</th><th scope="col">Betaler</th><th scope="col">En indsats på 100 chips giver tilbage</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Blackjack</td><td>3:2</td><td>250</td></tr>
                                    <tr><td>Sejr</td><td>1:1</td><td>200</td></tr>
                                    <tr><td>Push</td><td>Indsatsen tilbage</td><td>100</td></tr>
                                    <tr><td>Forsikring, når dealeren har blackjack</td><td>2:1</td><td>3 &times; forsikringsindsatsen</td></tr>
                                    <tr><td>Nederlag eller bust</td><td>&ndash;</td><td>0</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Et par tips</h2>
                        <ul>
                            <li>Stå altid på hård 17 eller mere, og hit altid på 11 eller mindre: du kan ikke gå bust.</li>
                            <li>Mod en dealer, der viser 7 eller højere, så hit, til du har 17. Mod 2-6 går dealeren tit bust, så stå fra 12 eller 13.</li>
                            <li>Double på 11, og på 10, medmindre dealeren viser en 10'er eller et es.</li>
                            <li>Split altid esser og 8'ere. Split aldrig 10'ere eller 5'ere.</li>
                            <li>Forsikring betaler sig kun, hvis dealeren har blackjack mere end en tredjedel af gangene, hvilket de ikke har. Spring den som regel over.</li>
                        </ul>
                        <p>Huset har stadig en lille fordel i det lange løb. Sådan virker casinoer, også dem uden rigtige penge.</p>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Borde og rangliste</h2>
                        <p>
                            Tryk på <strong>Play solo</strong> for et bord for dig selv, eller åbn et for andre: offentligt, med en kode
                            eller kun for inviterede, med en mindsteindsats på <?= implode(', ', array_slice(HBHUB_BLACKJACK_MIN_BETS, 0, -1)) ?> eller
                            <?= HBHUB_BLACKJACK_MIN_BETS[count(HBHUB_BLACKJACK_MIN_BETS) - 1] ?> chips. Mere i
                            <a href="/docs/casino/#tables">Sådan virker casinoet</a>. Hver hånd tæller med på
                            <a href="/casino/blackjack/leaderboard">Blackjack-ranglisten</a>.
                        </p>
                    </section>

                    <section id="table-fun" aria-labelledby="docs-table-fun">
                        <h2 id="docs-table-fun">Emotes og drikkepenge</h2>
                        <p>
                            Under knapperne ved bordet kan du reagere med
                            <?= implode(' ', array_column(HBHUB_BLACKJACK_EMOTES, 'symbol')) ?>, som svæver op fra din plads,
                            så alle ved bordet ser dem (ikke ved et solobord, hvor der ikke er andre).
                            Med <strong>Tip the dealer</strong> kan du give dealeren
                            <?= implode(', ', array_slice(HBHUB_BLACKJACK_TIPS, 0, -1)) ?> eller
                            <?= HBHUB_BLACKJACK_TIPS[count(HBHUB_BLACKJACK_TIPS) - 1] ?> chips. Chippen flyver hen til dealeren,
                            som siger tak, og bordet tæller, hvor meget dealeren har fået. Det er kun for sjov: chipsene er væk,
                            giver ingen fordel og tæller ikke på ranglisten.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
