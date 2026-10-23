<?php
// Help docs: Wordle - the colours (with an example guess), the two languages, streaks and the leaderboard. The numbers
// are assets/php/games/wordle/wordle.php's constants, the same rules the game's own "How to play" shows.
// Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/wordle/wordle.php';

// An example guess, "crane" when the word is "cards": [letter, how it did ('correct', 'present' or '' for not in it)]
const DOCS_WORDLE_EXAMPLE = [['c', 'correct'], ['r', 'present'], ['a', 'present'], ['n', ''], ['e', '']];

$docsPath = '/docs/games/wordle';
$docsIntro = 'One word a day, ' . HBHUB_WORDLE_TRIES . ' tries to guess it. There\'s an English one and a Danish one,
    the same for everyone. Play it on the <a href="/games/wordle/">Wordle</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Letters</dt><dd><?= HBHUB_WORDLE_LENGTH ?></dd></div>
                        <div><dt>Tries</dt><dd><?= HBHUB_WORDLE_TRIES ?></dd></div>
                        <div><dt>Words a day</dt><dd><?= count(HBHUB_WORDLE_LANGUAGES) ?></dd></div>
                        <div><dt>New words at</dt><dd>Midnight</dd></div>
                    </dl>

                    <section id="play" aria-labelledby="docs-play">
                        <h2 id="docs-play">How to play</h2>
                        <p>
                            Type a <?= HBHUB_WORDLE_LENGTH ?>-letter word on your keyboard or with the letters on the screen, and
                            press <kbd>Enter</kbd>. It has to be a real word. Then the letters change colour:
                        </p>
                        <div class="docs-tiles" role="img" aria-label="The guess CRANE: C green, R and A yellow, N and E grey">
                            <?php foreach (DOCS_WORDLE_EXAMPLE as [$docsLetter, $docsHow]): ?>
                                <span class="docs-tile<?= $docsHow !== '' ? ' is-' . $docsHow : '' ?>" aria-hidden="true"><?= $docsLetter ?></span>
                            <?php endforeach; ?>
                        </div>
                        <ul>
                            <li><span class="docs-tile-word is-correct">Green</span>: the right letter in the right place. The word starts with C.</li>
                            <li><span class="docs-tile-word is-present">Yellow</span>: in the word, but somewhere else. There's an R and an A, just not there.</li>
                            <li><span class="docs-tile-word">Grey</span>: not in the word, or not that many times. No N and no E.</li>
                        </ul>
                        <p>Use what you've learned for the next guess. The keyboard colours its letters too, so you can see which ones are left.</p>
                    </section>

                    <section id="languages" aria-labelledby="docs-languages">
                        <h2 id="docs-languages">English and Danish</h2>
                        <p>
                            Switch between them with the tabs at the top. The Danish keyboard has æ, ø and å, and any form of a Danish
                            word counts as a guess, like "huset" or "spist". You get one go at each word, and a word isn't used again
                            until every other one has been.
                        </p>
                    </section>

                    <section id="streaks" aria-labelledby="docs-streaks">
                        <h2 id="docs-streaks">Streaks and stats</h2>
                        <p>
                            New words come at midnight, Danish time. Your streak is the days in a row you've guessed the word.
                            Missing a day, or not getting it, starts it over, and so does a word you've started but not finished by
                            midnight, so giving up halfway doesn't save it.
                        </p>
                        <p>
                            When you're done, your stats show your wins, streaks and how many guesses your wins took.
                            <strong>Share</strong> copies your result as coloured squares, without giving the word away.
                        </p>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Leaderboard</h2>
                        <p>
                            There's a <a href="/games/wordle/leaderboard">Wordle leaderboard</a> for each language, with how many
                            you've played and won, your win % (from 5 games), your average guesses, and your current and longest
                            streak.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
