<?php
// Help docs: Sudoku - the rules (assets/php/games/sudoku/rules.php, included as the game shows them), the levels, a
// few tips and where the game is kept. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/sudoku';
$docsIntro = 'The number puzzle: fill in the board so every row, column and box has 1 to 9 once each. Play it on the
    <a href="/games/sudoku/">Sudoku</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">The rules</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/sudoku/rules.php'; ?>
                    </section>

                    <section id="levels" aria-labelledby="docs-levels">
                        <h2 id="docs-levels">The levels</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Level</th><th scope="col">Numbers to start with</th><th scope="col">What it takes</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Easy</td><td>About 38</td><td>There's always a square with only one number it can be.</td></tr>
                                    <tr><td>Medium</td><td>About 32</td><td>Also needs looking for the only square in a row, column or box where a number can go.</td></tr>
                                    <tr><td>Hard</td><td>About 28</td><td>Can need more than that, like notes and ruling numbers out.</td></tr>
                                    <tr><td>Expert</td><td>As few as it can, usually 22 to 25</td><td>Always needs more than the two tricks above.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Every puzzle is made new when you start it, and every one has exactly one answer.</p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Tips</h2>
                        <ul>
                            <li>
                                Click a number on the board to see every other square with the same number lit up. That makes
                                it easy to see which rows, columns and boxes still need it.
                            </li>
                            <li>
                                Under each number on the pad is how many of it are still to go. A greyed-out number has all
                                nine placed.
                            </li>
                            <li>
                                On Hard and Expert, write notes. When you put a number in a square, it's taken out of the
                                notes around it for you, so a square left with one note is the next one to fill in.
                            </li>
                        </ul>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Saving and best times</h2>
                        <p>
                            Your game, and your best time and number of wins on each level, are kept in your browser, not on
                            HB Hub's server. So you can close the page and carry on later, in the same browser. In another
                            browser or on another device, you start over. Clearing your browser's site data deletes them.
                        </p>
                        <p>Sudoku has no leaderboard yet.</p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
