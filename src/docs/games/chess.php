<?php
// Help docs: Chess - starting a game, the clocks (HBHUB_CHESS_CLOCKS in games/chess/game.php), the game's own rules
// (assets/php/games/chess/rules.php, included as the game shows them, with HB Hub's promotion rule), moving pieces,
// and the leaderboard's score. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';

$docsPath = '/docs/games/chess';
$docsIntro = 'Two players, every rule of chess, and one twist on promotion. Challenge a friend on the
    <a href="/games/chess/">Chess</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Players</dt><dd>2</dd></div>
                        <div><dt>Clocks</dt><dd><?= count(HBHUB_CHESS_CLOCKS) - 1 ?> + none</dd></div>
                        <div><dt>Default clock</dt><dd><?= htmlspecialchars(HBHUB_CHESS_CLOCK_NAMES[HBHUB_CHESS_DEFAULT_CLOCK]) ?></dd></div>
                    </dl>

                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">Starting a game</h2>
                        <ol class="docs-steps">
                            <li>Open a game on the Chess page: public, with a code, or invite-only (see <a href="/docs/games/#rooms">Opening a game</a>).</li>
                            <li>Pick the clock and who plays White (you, them, or random) in the game's settings.</li>
                            <li>Once someone has joined, press start. White moves first.</li>
                        </ol>
                    </section>

                    <section id="clocks" aria-labelledby="docs-clocks">
                        <h2 id="docs-clocks">The clocks</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Clock</th><th scope="col">Each player gets</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_CHESS_CLOCKS as $docsClock => $docsClockTime): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(HBHUB_CHESS_CLOCK_NAMES[$docsClock]) ?></td>
                                            <td>
                                                <?php if ($docsClockTime === null): ?>
                                                    All the time in the world
                                                <?php else: ?>
                                                    <?= $docsClockTime[0] ?> minutes<?= $docsClockTime[1] > 0 ? ', plus ' . $docsClockTime[1] . ' seconds for every move' : '' ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="moving" aria-labelledby="docs-moving">
                        <h2 id="docs-moving">Moving pieces</h2>
                        <p>
                            Click a piece to see where it can go, then click where you want it, or drag it there. Only moves that
                            are allowed can be played, so you can't move into check by accident.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/rules.php'; ?>
                    </section>

                    <section id="promotion" aria-labelledby="docs-promotion">
                        <h2 id="docs-promotion">The promotion twist, explained</h2>
                        <p>
                            In normal chess, a pawn that reaches the last rank can become any piece, so you could have three queens.
                            Here it can only become a piece you've lost. Say your opponent has taken one of your knights and nothing
                            else: a pawn that gets to the end can only become a knight. If they haven't taken any of your pieces yet,
                            the pawn just waits there, and becomes one the moment they do.
                        </p>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Leaderboard</h2>
                        <p>
                            The <a href="/games/chess/leaderboard">Chess leaderboard</a> counts your games, wins, draws and losses,
                            and your fastest checkmate. The score is a point for every win and half a point for every draw, out of all
                            your games, and only counts from 5 games. Aborted games don't count. Leaving a game counts as a loss,
                            unless the host had paused it.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
