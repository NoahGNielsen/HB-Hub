<?php
// Help docs: Roulette - every bet with what it covers, pays and how often it hits, the game's own rules
// (assets/php/casino/roulette/rules.php, included as the game shows them) and how to play. The red numbers come from
// roulette.php's HBHUB_ROULETTE_RED. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';

// Every bet: [name, what it covers, how many numbers, what it pays]. The chance is worked out from the numbers, out of 37.
const DOCS_ROULETTE_BETS = [
    ['Straight', 'One number, 0 too', 1, '35:1'],
    ['Split', 'Two numbers next to each other', 2, '17:1'],
    ['Street', 'A row of three numbers, or 0-1-2 and 0-2-3', 3, '11:1'],
    ['Corner', 'Four numbers that meet at a corner, or 0-1-2-3', 4, '8:1'],
    ['Line', 'Two rows next to each other: six numbers', 6, '5:1'],
    ['Dozen', '1-12, 13-24 or 25-36', 12, '2:1'],
    ['Column', 'One of the three long columns', 12, '2:1'],
    ['Red or black', 'All 18 numbers of that colour', 18, '1:1'],
    ['Odd or even', 'All 18 odd or even numbers (not 0)', 18, '1:1'],
    ['1-18 or 19-36', 'The low or the high half', 18, '1:1'],
];

$docsPath = '/docs/casino/roulette';
$docsIntro = 'European roulette with a single green zero. Put your chips anywhere on the table, spin, and see where
    the ball lands. Play it on the <a href="/gambling/roulette/">Roulette</a> page, on your own and at your own pace.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Numbers</dt><dd>0-36</dd></div>
                        <div><dt>Zeros</dt><dd>1</dd></div>
                        <div><dt>Bets in steps of</dt><dd><?= HBHUB_ROULETTE_BET_STEP ?></dd></div>
                        <div><dt>Best payout</dt><dd>35:1</dd></div>
                    </dl>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">How to play</h2>
                        <ol class="docs-steps">
                            <li>Pick a chip and click the table where you want to bet. Click again to add more, and right-click a bet to take a chip off. <strong>Undo</strong> takes back the last chip, <strong>Clear</strong> all of them.</li>
                            <li>Press <strong>Spin</strong>. The wheel turns and the ball lands on a number.</li>
                            <li>Winning bets are paid, losing ones are taken. Your bets stay on the table, so you can spin again straight away, or change them first.</li>
                        </ol>
                        <p>Your last spins are listed above the table, and <kbd>Space</kbd> spins again.</p>
                    </section>

                    <section id="bets" aria-labelledby="docs-bets">
                        <h2 id="docs-bets">Every bet</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Bet</th><th scope="col">Covers</th><th scope="col">Pays</th><th scope="col">Hits</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (DOCS_ROULETTE_BETS as [$docsBetName, $docsBetCovers, $docsBetNumbers, $docsBetPays]): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsBetName) ?></td>
                                            <td><?= htmlspecialchars($docsBetCovers) ?></td>
                                            <td><?= $docsBetPays ?></td>
                                            <td><?= number_format($docsBetNumbers / 37 * 100, 1) ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            The red numbers are <?= implode(', ', HBHUB_ROULETTE_RED) ?>. The other numbers from 1 to 36 are black,
                            and 0 is green.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/rules.php'; ?>
                    </section>

                    <section id="zero" aria-labelledby="docs-zero">
                        <h2 id="docs-zero">Why the zero matters</h2>
                        <p>
                            Every bet pays as if there were only 36 numbers, but there are 37. That single green 0 is the house's edge:
                            about 2.7% of everything you bet, whichever bet you pick. No system of bets changes that, and no number is
                            "due": every spin is as random as the one before.
                        </p>
                        <p>
                            Every spin counts towards the <a href="/gambling/roulette/leaderboard">Roulette leaderboard</a>, with your
                            biggest win and how much you've won or lost in total.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
