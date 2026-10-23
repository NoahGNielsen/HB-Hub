<?php
// Help docs: Blackjack - a round step by step, the table's own rules (assets/php/casino/blackjack/rules.php, included
// as the game shows them, so they can't drift apart), the payouts and a few tips. The numbers are room.php's and
// round.php's constants. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/room.php';

$docsPath = '/docs/casino/blackjack';
$docsIntro = 'Get closer to 21 than the dealer without going over. Play <a href="/gambling/blackjack/">Blackjack</a>
    on your own against the dealer, or at a table with your friends.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Players</dt><dd>1-<?= HBHUB_BLACKJACK_SEATS ?></dd></div>
                        <div><dt>Decks</dt><dd>6</dd></div>
                        <div><dt>Blackjack pays</dt><dd>3:2</dd></div>
                        <div><dt>Time per move</dt><dd><?= HBHUB_BLACKJACK_TURN_MS / 1000 ?> s</dd></div>
                    </dl>

                    <section id="round" aria-labelledby="docs-round">
                        <h2 id="docs-round">A round, step by step</h2>
                        <ol class="docs-steps">
                            <li><strong>Bet.</strong> Click the chips to build your bet (&frac12; and &times;2 halve or double it), at least the table's minimum, and press <strong>Place bet</strong>. <strong>Take back</strong> undoes it before the deal. Betting closes <?= HBHUB_BLACKJACK_BETTING_MS / 1000 ?> seconds after the first bet, or as soon as everyone has bet.</li>
                            <li><strong>The deal.</strong> Everyone gets two cards face up. The dealer gets one face up and one face down (the hole card).</li>
                            <li><strong>Your turn.</strong> Players go in seat order. <strong>Hit</strong> for another card, <strong>Stand</strong> to stop, <strong>Double</strong> to double your bet for exactly one more card, or <strong>Split</strong> a pair into two hands. The keys <kbd>H</kbd>, <kbd>S</kbd>, <kbd>D</kbd> and <kbd>P</kbd> do the same. Go over 21 and you bust: the bet is lost.</li>
                            <li><strong>The dealer.</strong> Turns over the hole card and draws until 17 or more.</li>
                            <li><strong>Pay out.</strong> Closer to 21 than the dealer wins, the same total is a push (you get your bet back), and if the dealer busts everyone still in wins.</li>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules at HB Hub's tables</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/rules.php'; ?>
                    </section>

                    <section id="payouts" aria-labelledby="docs-payouts">
                        <h2 id="docs-payouts">Payouts</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Result</th><th scope="col">Pays</th><th scope="col">A 100 chip bet gives back</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Blackjack</td><td>3:2</td><td>250</td></tr>
                                    <tr><td>Win</td><td>1:1</td><td>200</td></tr>
                                    <tr><td>Push</td><td>Bet back</td><td>100</td></tr>
                                    <tr><td>Insurance, when the dealer has blackjack</td><td>2:1</td><td>3 &times; the insurance bet</td></tr>
                                    <tr><td>Loss or bust</td><td>&ndash;</td><td>0</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">A few tips</h2>
                        <ul>
                            <li>Always stand on hard 17 or more, and always hit on 11 or less: you can't bust.</li>
                            <li>Against a dealer showing 7 or higher, hit until you have 17. Against 2-6, the dealer busts often, so stand from 12 or 13.</li>
                            <li>Double on 11, and on 10 unless the dealer shows a 10 or an ace.</li>
                            <li>Always split aces and 8s. Never split 10s or 5s.</li>
                            <li>Insurance only pays off if the dealer has blackjack more than a third of the time, which they don't. Usually skip it.</li>
                        </ul>
                        <p>The house still has a small edge in the long run. That's how casinos work, even ones without real money.</p>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Tables and leaderboard</h2>
                        <p>
                            Press <strong>Play solo</strong> for a table of your own, or open one for others: public, with a code or
                            invite-only, with a minimum bet of <?= implode(', ', array_slice(HBHUB_BLACKJACK_MIN_BETS, 0, -1)) ?> or
                            <?= HBHUB_BLACKJACK_MIN_BETS[count(HBHUB_BLACKJACK_MIN_BETS) - 1] ?> chips. More in
                            <a href="/docs/casino/#tables">How the casino works</a>. Every hand counts towards the
                            <a href="/gambling/blackjack/leaderboard">Blackjack leaderboard</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
