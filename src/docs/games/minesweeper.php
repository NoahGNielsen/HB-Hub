<?php
// Help docs: Minesweeper - the rules (assets/php/games/minesweeper/rules.php, included as the game shows them), what the
// numbers mean, and a few tips. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
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
                                The mines are placed before your first click, so that one can be a mine too. Bad luck: click the
                                face and go again.
                            </li>
                            <li>
                                Hard is 30 squares wide. On a phone, the board scrolls sideways.
                            </li>
                        </ul>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Saving and scores</h2>
                        <p>
                            Minesweeper doesn't save anything: there are no stats or leaderboard, and a game is gone when you
                            close the page. It's a copy of Nick Arocho's Minesweeper (see the <a href="/legal/credits#code">credits</a>).
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
