<?php
// Help docs: Minesweeper - the rules (assets/php/games/minesweeper/rules.php, included as the game shows them), what the
// numbers mean, a few tips and the leaderboard. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/minesweeper';
$docsIntro = 'The classic, in its Windows 95 look: open every square without a mine. Play it on the
    <a href="/games/minesweeper/">Minesweeper</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/rules.php'; ?>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                A number that already has that many flags around it is done: every other square next to it is
                                safe to open.
                            </li>
                            <li>
                                A number with exactly that many closed squares around it means they're all mines, like a 1
                                with only one closed square next to it. Start from the numbers with the fewest closed squares
                                around them.
                            </li>
                            <li>
                                Hard is 30 squares wide. On a phone, the board scrolls sideways.
                            </li>
                        </ul>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Leaderboard</h2>
                        <p>
                            There's a <a href="/games/minesweeper/leaderboard">Minesweeper leaderboard</a> for each size, of
                            everyone who has won there: their best and average time, wins, games played and win % (shown
                            from 5 games). After a win, the page above the board shows your time and your best.
                        </p>
                        <p>
                            The mines are kept on HB Hub's server, not in your browser, and your time is timed there, from
                            your first click to the one that wins. So the time includes how long each click takes to reach
                            the server, and peeking at the page's code doesn't give away the mines. A game can't be picked up
                            again after you close the page: it counts as played, but not won.
                        </p>
                        <p>It's a copy of Nick Arocho's Minesweeper (see the <a href="/legal/credits#code">credits</a>).</p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
