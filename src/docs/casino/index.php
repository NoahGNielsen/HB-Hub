<?php
// Help docs: how the casino works - the chip wallet (assets/php/casino/wallet.php: the start and daily chips and when a
// casino day starts), the tables (public, code, invite-only and solo, like casino/blackjack/room.php), the
// leaderboards, and what chips are (the terms' "The casino and chips"). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';

$docsPath = '/docs/casino/';
$docsIntro = 'Blackjack, Baccarat and Roulette, played with chips you get for free. No real money is involved anywhere,
    and nothing you win can be paid out.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>To start</dt><dd><?= number_format(HBHUB_CASINO_START_CHIPS) ?> chips</dd></div>
                        <div><dt>Every day</dt><dd>+<?= number_format(HBHUB_CASINO_DAILY_CHIPS) ?> chips</dd></div>
                        <div><dt>New day at</dt><dd><?= HBHUB_CASINO_DAY_START ?> AM</dd></div>
                        <div><dt>Games</dt><dd>3</dd></div>
                    </dl>

                    <section id="chips" aria-labelledby="docs-chips">
                        <h2 id="docs-chips">Your chips</h2>
                        <p>
                            Every account starts with <strong><?= number_format(HBHUB_CASINO_START_CHIPS) ?> chips</strong>. All
                            the casino games share them, so what you win at Blackjack you can bet at Roulette. Your chips are on the
                            <a href="/gambling/">casino's home page</a> and in the top bar on every casino page.
                        </p>
                    </section>

                    <section id="daily" aria-labelledby="docs-daily">
                        <h2 id="docs-daily">Daily chips</h2>
                        <p>
                            Press <strong>Collect daily chips</strong> on the casino's home page for
                            <strong><?= number_format(HBHUB_CASINO_DAILY_CHIPS) ?> free chips</strong>, once a day. A new casino day
                            starts at <?= HBHUB_CASINO_DAY_START ?> AM (Danish time), and until then the button shows how long is left.
                            Missed a day? They don't pile up, so come back tomorrow.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="docs-games">
                        <h2 id="docs-games">The games</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Game</th><th scope="col">How it's played</th><th scope="col">Players</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><a href="/docs/casino/blackjack">Blackjack</a></td><td>Get closer to 21 than the dealer without going over.</td><td>Solo, or up to 5 at a table</td></tr>
                                    <tr><td><a href="/docs/casino/baccarat">Baccarat</a></td><td>Bet on which hand ends closest to 9.</td><td>Up to 6 at a table</td></tr>
                                    <tr><td><a href="/docs/casino/roulette">Roulette</a></td><td>Bet on where the ball lands.</td><td>On your own</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Tables, codes and invites</h2>
                        <p>Blackjack and Baccarat are played at tables. Open one from the game's page, or join one that's already there:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Kind of table</th><th scope="col">Who can join</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Public</td><td>Anyone. It's listed in the game's lobby.</td></tr>
                                    <tr><td>Code</td><td>Anyone with its 6-character code. Type it under <strong>Join a table</strong> on the game's page.</td></tr>
                                    <tr><td>Invite-only</td><td>Only the people the host invites. They get a <a href="/docs/notifications">notification</a>, and it shows under <strong>Your invites</strong> on the game's page.</td></tr>
                                    <tr><td>Solo (Blackjack)</td><td>Just you and the dealer.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            The host picks the table's name and its minimum bet (10, 50, 100 or 500 chips). Every player plays
                            against the house, never against each other. You can join a table in the middle of a hand, and you're
                            dealt in from the next one. <strong>Sit out</strong> skips hands without giving up your seat.
                        </p>
                        <p>
                            Close the table, and your seat is kept for a minute in case you come back. After that you've left it.
                            Bets close by themselves after a while, and so do turns, so nobody can keep a table waiting.
                        </p>
                    </section>

                    <section id="leaderboards" aria-labelledby="docs-leaderboards">
                        <h2 id="docs-leaderboards">Leaderboards</h2>
                        <p>
                            Each game has its own leaderboard, with hands (or spins), wins, losses and your chips, plus the biggest
                            win and how much you've won or lost in Roulette. Click a column's heading to sort by it. Win % only counts
                            once you've played 20 hands or spins, so one lucky round can't top it.
                        </p>
                    </section>

                    <section id="not-money" aria-labelledby="docs-not-money">
                        <h2 id="docs-not-money">Chips aren't money</h2>
                        <ul>
                            <li>Chips can't be swapped for real money, gift cards or anything else, and nothing you win can be paid out.</li>
                            <li>Don't sell, buy or bet chips, accounts or results for real money or real things with each other.</li>
                            <li>Site admins can add or take away chips, for example to fix a bug, or when chips were won by cheating.</li>
                        </ul>
                        <p>
                            All of it is in <a href="/legal/terms#chips">The casino and chips</a> in the Terms. If you feel like
                            you're playing too much, you can <a href="/docs/self-blocking">block the casino for yourself</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
