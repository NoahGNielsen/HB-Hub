<?php
// Help docs: Slots - how to play, the paylines, the game's own rules with its pay table
// (assets/php/casino/slots/rules.php, included as the game shows them) and what the machine pays back. Every number
// comes from assets/php/casino/slots/slots.php. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';

const DOCS_SLOTS_LINE_NAMES = ['The middle row', 'The top row', 'The bottom row', 'Top left to bottom right', 'Bottom left to top right'];

$docsPath = '/docs/casino/slots';
$docsIntro = 'A three-reel slot machine with ' . count(HBHUB_SLOTS_LINES) . ' paylines. Pick a bet, spin, and see what
    lines up. Every spin is your own, so you can play at your own pace at the <a href="/gambling/slots/">slot machine</a>.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Reels</dt><dd>3</dd></div>
                        <div><dt>Paylines</dt><dd><?= count(HBHUB_SLOTS_LINES) ?></dd></div>
                        <div><dt>Bets in steps of</dt><dd><?= HBHUB_SLOTS_BET_STEP ?></dd></div>
                        <div><dt>Best payout</dt><dd><?= HBHUB_SLOTS_PAYS['seven'] ?>&times; a line</dd></div>
                    </dl>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">How to play</h2>
                        <ol class="docs-steps">
                            <li>Pick a bet with the chips on the machine, under the reels, or move it up and down with <strong>&minus;</strong> and <strong>+</strong>. With fewer than <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips left, the <strong>All in</strong> chip bets every chip you have.</li>
                            <li>Press <strong>Spin</strong> (or <kbd>Space</kbd>). The bet is taken, and the reels roll and stop one after another from the left.</li>
                            <li>Every winning line lights up and is paid. Your bet stays the same, so you can spin again straight away.</li>
                        </ol>
                        <p>Your last spins are listed next to the machine, with what each one won or lost.</p>
                    </section>

                    <section id="lines" aria-labelledby="docs-lines">
                        <h2 id="docs-lines">The paylines</h2>
                        <p>
                            Every spin plays all <?= count(HBHUB_SLOTS_LINES) ?> lines, and your bet is split evenly across
                            them: a bet of 50 is 10 on each line. The lines are numbered down both sides of the machine.
                        </p>
                        <ol>
                            <?php foreach (DOCS_SLOTS_LINE_NAMES as $docsLineName): ?>
                                <li><?= $docsLineName ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules and the pay table</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/rules.php'; ?>
                        <p>
                            "Pays" is how many times a line's share of the bet it pays back, the share included, so on a bet of
                            50 (10 a line) three 7s pay <?= number_format(HBHUB_SLOTS_PAYS['seven'] * 10) ?>.
                        </p>
                    </section>

                    <section id="odds" aria-labelledby="docs-odds">
                        <h2 id="docs-odds">What the machine pays back</h2>
                        <p>
                            Over a long time the slot machine pays back about <?= number_format(hbHubSlotsReturn() * 100, 1) ?>% of
                            everything bet on it, so the house keeps about <?= number_format((1 - hbHubSlotsReturn()) * 100, 1) ?>%.
                            Small wins come often, the big ones rarely: three 7s on a line is 1 in
                            <?= number_format(hbHubSlotsOdds('seven')) ?>. No spin depends on the ones before it, so a machine is
                            never "due" a win.
                        </p>
                        <p>
                            Every spin counts towards the <a href="/gambling/slots/leaderboard">Slots leaderboard</a>, with your
                            biggest win and how much you've won or lost in total.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
