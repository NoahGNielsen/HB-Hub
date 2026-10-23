<?php
// Help docs: Matador - what's on the board, a turn, the game's own rules (assets/php/games/matador/rules.php,
// included as the game shows them, with room.php's money and constants), the Danish words, the room settings and
// saving a game (save.php). Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';

$docsPath = '/docs/games/matador';
$docsIntro = 'The Danish Monopoly. Roll the die, buy streets, ferries and breweries, build houses and hotels, and be the
    last one standing. Open a game on the <a href="/games/matador/">Matador</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Players</dt><dd><?= HBHUB_MATADOR_MIN_PLAYERS ?>-<?= HBHUB_MATADOR_MAX_PLAYERS ?></dd></div>
                        <div><dt>Start money</dt><dd><?= hbHubMatadorKr(HBHUB_MATADOR_START_CASH) ?></dd></div>
                        <div><dt>Passing START</dt><dd>+<?= hbHubMatadorKr(HBHUB_MATADOR_START_PAY) ?></dd></div>
                        <div><dt>Time per turn</dt><dd><?= HBHUB_MATADOR_TURN_SECONDS ?> s</dd></div>
                    </dl>

                    <section id="turn" aria-labelledby="docs-turn">
                        <h2 id="docs-turn">A turn</h2>
                        <ol class="docs-steps">
                            <li><strong>Roll.</strong> Move as many fields as the die shows. A 6 gives you another roll.</li>
                            <li><strong>Do what the field says.</strong> Buy a property nobody owns, pay rent on someone else's, draw a Prøv lykken card, pay a tax, or go to jail.</li>
                            <li><strong>Build, mortgage or trade</strong> if you like, before you end your turn.</li>
                            <li><strong>End turn</strong>, and it's the next player's.</li>
                        </ol>
                        <p>Everything you do starts your <?= HBHUB_MATADOR_TURN_SECONDS ?> seconds over, so take your time, just don't walk away.</p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/rules.php'; ?>
                    </section>

                    <section id="words" aria-labelledby="docs-words">
                        <h2 id="docs-words">The Danish words</h2>
                        <p>Matador keeps its Danish names. If you know Monopoly, this is what they mean:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">On the board</th><th scope="col">In Monopoly</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Prøv lykken</td><td>Chance: "try your luck"</td></tr>
                                    <tr><td>Parkering</td><td>Free Parking</td></tr>
                                    <tr><td>De fængsles</td><td>Go to Jail: "you are imprisoned"</td></tr>
                                    <tr><td>Fængsel</td><td>Jail</td></tr>
                                    <tr><td>"Benådet" card</td><td>Get Out of Jail Free: "pardoned"</td></tr>
                                    <tr><td>Indkomstskat</td><td>Income Tax</td></tr>
                                    <tr><td>Ekstraordinær statsskat</td><td>Super Tax: "extraordinary state tax"</td></tr>
                                    <tr><td>The ferries</td><td>The railroads</td></tr>
                                    <tr><td>The breweries</td><td>The utilities</td></tr>
                                    <tr><td>kr.</td><td>Danish kroner, the game's money</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="settings" aria-labelledby="docs-settings">
                        <h2 id="docs-settings">Room settings</h2>
                        <p>Before the game starts, the host can change a few house rules in the room's settings:</p>
                        <ul>
                            <li><strong>Dice</strong>: one die (a 6 rolls again), or two (doubles roll again).</li>
                            <li><strong>Parkering's account</strong>: taxes and Prøv lykken payments go to whoever lands on Parkering (on by default), or to the bank.</li>
                            <li><strong>Rent in jail</strong>: whether you still get rent while you're in jail, don't, or it goes to the bank.</li>
                            <li><strong>Building houses and hotels</strong>: only once you own every street in a colour and evenly (the default), with the whole colour but not evenly, or on any street you own.</li>
                        </ul>
                    </section>

                    <section id="saves" aria-labelledby="docs-saves">
                        <h2 id="docs-saves">Saving and continuing</h2>
                        <p>
                            A game is saved after every move, in the save slot of whoever opened it. Everyone has one slot, and
                            starting a new game of your own uses it. Leave, and your seat waits for you while your turns are skipped.
                            The host can give an empty seat to someone else, or press <strong>Save &amp; stop</strong>, and
                            continue it from the Matador page another day. The others get a notification when it goes on. A game
                            that's been won is gone from the slot, as there's nothing left to play.
                        </p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">A few tips</h2>
                        <ul>
                            <li>A whole colour doubles its rent even before you build, and lets you build. Trade to finish one.</li>
                            <li>Three houses on a street is often the sweet spot: the rent jumps a lot from two to three.</li>
                            <li>Keep some money back. Landing on a hotel with nothing in the bank means selling or mortgaging in a hurry.</li>
                            <li>Trade offers can be made at any time, also in other players' turns, with streets, money and "benådet" cards.</li>
                        </ul>
                        <p>Every game counts towards the <a href="/games/matador/leaderboard">Matador leaderboard</a>, with your wins and fastest win.</p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
