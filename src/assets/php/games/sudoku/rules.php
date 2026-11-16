<?php
// The rules of Sudoku as played here, shown on the Sudoku page (games/sudoku/) and in its help docs
// (docs/games/sudoku.php). The levels (and how many numbers each starts with) are HBHUB_SUDOKU_LEVELS in
// assets/php/games/sudoku/sudoku.php, and the anticheat's floor is HBHUB_SUDOKU_MIN_MS_PER_SQUARE there, so change
// them together. Not for the free Sudoku at /play/sudoku, which has no leaderboard and its own rules.
?>
<ul class="casino-rule-list">
    <li>Fill in the board so every row, every column and every 3&times;3 box has each of the numbers 1 to 9 exactly once.</li>
    <li>Pick a level: Easy starts with about 38 numbers filled in, Medium about 32, Hard about 28 and Expert as few as it can (usually 22 to 25). Every puzzle has exactly one answer, and Easy and Medium can always be solved without guessing.</li>
    <li>Click a square, then a number under the board (or type it). <kbd>Backspace</kbd> or Erase takes it out again, and the arrow keys move around.</li>
    <li>Turn on Notes (or press <kbd>N</kbd>) to write small numbers in a square, for the ones it could be. Putting a number in a square takes it out of the notes in its row, column and box.</li>
    <li>Two of the same number in a row, column or box turn red. The board doesn't tell you when a number is wrong in any other way.</li>
    <li>Stuck? Hint fills in the square you picked (or a random one) with the right number. Undo (<kbd>Ctrl</kbd> + <kbd>Z</kbd>) takes back your last move.</li>
    <li>The clock is HB Hub's own, and only runs while the page is on screen and the game isn't paused. Your numbers and notes are saved in this browser, so you can close the page and pick it up later.</li>
    <li>A puzzle solved without a hint goes on the <a href="/games/sudoku/leaderboard">leaderboard</a> for its level. One with a hint counts as solved, but not there, and so does one filled in faster than a person can (less than a second a square: no solvers or scripts). Starting a new game ends the one you were playing.</li>
</ul>
