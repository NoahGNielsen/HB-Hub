<?php
// Help docs: Baccarat - the bets and what they pay, the table's own rules (assets/php/casino/baccarat/rules.php,
// included as the game shows them, with the third-card rules), an example hand and a few tips. The numbers are
// room.php's, round.php's and cards.php's constants. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';

$docsCommission = HBHUB_BACCARAT_BANKER_COMMISSION / 10; // per thousand in round.php, a percentage here

$docsPath = '/docs/casino/baccarat';
$docsIntro = 'Two hands are dealt, the Player and the Banker. Bet on the one you think ends closest to 9. Nobody makes
    any choices once the cards are out, so <a href="/gambling/baccarat/">Baccarat</a> is quick to learn.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Players</dt><dd><?= HBHUB_BACCARAT_MIN_PLAYERS ?>-<?= HBHUB_BACCARAT_SEATS ?></dd></div>
                        <div><dt>Decks</dt><dd><?= HBHUB_BACCARAT_DECKS ?></dd></div>
                        <div><dt>Bets</dt><dd>5</dd></div>
                        <div><dt>Betting time</dt><dd><?= HBHUB_BACCARAT_BETTING_MS / 1000 ?> s</dd></div>
                    </dl>

                    <section id="bets" aria-labelledby="docs-bets">
                        <h2 id="docs-bets">The bets</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Bet</th><th scope="col">Wins when</th><th scope="col">Pays</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><span class="casino-bac-player">Player</span></td><td>The Player's hand is closer to 9</td><td>1:1</td></tr>
                                    <tr><td><span class="casino-bac-banker">Banker</span></td><td>The Banker's hand is closer to 9</td><td>1:1, less <?= $docsCommission ?>% of the win</td></tr>
                                    <tr><td>Tie</td><td>Both hands end on the same number</td><td>8:1</td></tr>
                                    <tr><td>Player Pair</td><td>The Player's first two cards are the same rank</td><td>11:1</td></tr>
                                    <tr><td>Banker Pair</td><td>The Banker's first two cards are the same rank</td><td>11:1</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            The Banker wins a little more often than the Player, which is why a Banker win pays a bit less: with the
                            <?= $docsCommission ?>% taken off, neither side has an edge. On a tie, Player and Banker bets are given back.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules at HB Hub's tables</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/rules.php'; ?>
                        <p>
                            The third-card rules look complicated, but you never need to remember them: the table follows them for
                            you. All you choose is the bet.
                        </p>
                    </section>

                    <section id="example" aria-labelledby="docs-example">
                        <h2 id="docs-example">An example hand</h2>
                        <ol class="docs-steps">
                            <li>The <span class="casino-bac-player">Player</span> gets a 4 and a king: 4 + 0 = <strong>4</strong>. The <span class="casino-bac-banker">Banker</span> gets a 7 and a 9: 7 + 9 = 16, so <strong>6</strong>.</li>
                            <li>Nobody has 8 or 9, so the Player, on 0-5, takes a third card: a 3. Now the Player has <strong>7</strong>.</li>
                            <li>The Banker has 6 and the Player's third card was a 3, so the Banker stands on <strong>6</strong>.</li>
                            <li>7 beats 6: Player bets win 1:1.</li>
                        </ol>
                    </section>

                    <section id="playing" aria-labelledby="docs-playing">
                        <h2 id="docs-playing">Placing bets</h2>
                        <p>
                            Pick a chip, then tap a spot to put it there (right-click a spot to take one off), and press
                            <strong>Place bets</strong>. &times;2 doubles everything you've put down. You can bet
                            on Player or Banker (not both), and on Tie and the pairs as well. Your bets together have to be at least
                            the table's minimum, in steps of <?= HBHUB_BACCARAT_BET_STEP ?>.
                        </p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">A few tips</h2>
                        <ul>
                            <li>Player and Banker are as close to a fair bet as the casino gets.</li>
                            <li>Tie and the pairs pay a lot, but hit rarely: in the long run they lose much faster.</li>
                            <li>Earlier hands don't change the next one. A "streak" of Banker wins says nothing about what comes next.</li>
                        </ul>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Tables and leaderboard</h2>
                        <p>
                            Open a table (public, with a code or invite-only) or join one from the lobby. More in
                            <a href="/docs/casino/#tables">How the casino works</a>. Every hand counts towards the
                            <a href="/gambling/baccarat/leaderboard">Baccarat leaderboard</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
