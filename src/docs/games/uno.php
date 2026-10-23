<?php
// Help docs: UNO - the 108 cards, a turn, the game's own rules (assets/php/games/uno/rules.php, included as the game
// shows them), the host's settings (HBHUB_UNO_TURN_CHOICES and the rest in game.php) and scoring. Open to everyone.
// Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/room.php';

$docsPath = '/docs/games/uno';
$docsIntro = 'Match the colour or the number, make the others draw, and be the first with no cards left. Just don\'t
    forget to press UNO! Play it on the <a href="/games/uno/">UNO</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Players</dt><dd><?= HBHUB_UNO_MIN_PLAYERS ?>-<?= HBHUB_UNO_MAX_PLAYERS ?></dd></div>
                        <div><dt>Cards each</dt><dd><?= HBHUB_UNO_HAND ?></dd></div>
                        <div><dt>Deck</dt><dd>108 cards</dd></div>
                        <div><dt>Forgot UNO</dt><dd>+<?= HBHUB_UNO_CATCH_CARDS ?> cards</dd></div>
                    </dl>

                    <section id="cards" aria-labelledby="docs-cards">
                        <h2 id="docs-cards">The cards</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Card</th><th scope="col">What it does</th><th scope="col">In the deck</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>0-9</td><td>Nothing special. Play it on the same colour or number.</td><td>76 (one 0 and two of 1-9 in each colour)</td></tr>
                                    <tr><td>Skip</td><td>The next player misses their turn.</td><td>8</td></tr>
                                    <tr><td>Reverse</td><td>Play goes the other way round. With two players, it's a Skip.</td><td>8</td></tr>
                                    <tr><td>Draw Two</td><td>The next player draws 2 and misses their turn.</td><td>8</td></tr>
                                    <tr><td>Wild</td><td>Pick the colour to play on. Goes on anything.</td><td>4</td></tr>
                                    <tr><td>Wild Draw Four</td><td>Pick the colour, and the next player draws 4 and misses their turn. Goes on anything, any time.</td><td>4</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="turn" aria-labelledby="docs-turn">
                        <h2 id="docs-turn">A turn</h2>
                        <ol class="docs-steps">
                            <li>Play a card that matches the top card's colour, number or symbol, or a wild card.</li>
                            <li>Can't, or don't want to? Draw a card. If it fits, play it straight away or keep it.</li>
                            <li>Down to your last card? Press <strong>UNO!</strong> as you play your second-to-last one.</li>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/uno/rules.php'; ?>
                    </section>

                    <section id="settings" aria-labelledby="docs-settings">
                        <h2 id="docs-settings">The host's settings</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Setting</th><th scope="col">Choices</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Time for a turn</td><td><?= implode(', ', array_slice(HBHUB_UNO_TURN_CHOICES, 0, -1)) ?> or <?= HBHUB_UNO_TURN_CHOICES[count(HBHUB_UNO_TURN_CHOICES) - 1] ?> seconds</td></tr>
                                    <tr><td>Stacking</td><td>Answer a Draw Two with a Draw Two, or a Wild Draw Four with a Wild Draw Four, to pass them all on (on by default)</td></tr>
                                    <tr><td>Draw until you can play</td><td>Keep drawing until you get a card that fits, instead of just one (on by default)</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>They can be changed before a game, and between games. New players can join between games too.</p>
                    </section>

                    <section id="scoring" aria-labelledby="docs-scoring">
                        <h2 id="docs-scoring">Scoring</h2>
                        <p>
                            The winner scores the cards left in everyone else's hands: a number card its number, Skip, Reverse and
                            Draw Two 20 each, and wild cards 50 each. Get rid of your big cards first if someone's about to go out.
                        </p>
                        <p>
                            The <a href="/games/uno/leaderboard">UNO leaderboard</a> has your wins, win %, all your points and your
                            best win.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
