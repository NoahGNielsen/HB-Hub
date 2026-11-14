<?php
// The rules of Sudoku as played here, shown on the Sudoku page (games/sudoku/) and in its help docs
// (docs/games/sudoku.php). The game itself is all in assets/js/sudoku.js (DIFFICULTIES there has the levels and how
// many numbers each starts with), so change them together.
?>
<ul class="casino-rule-list">
    <li>Fill in the board so every row, every column and every 3&times;3 box has each of the numbers 1 to 9 exactly once.</li>
    <li>Pick a level: Easy starts with about 38 numbers filled in, Medium about 32, Hard about 28 and Expert as few as it can (usually 22 to 25). Every puzzle has exactly one answer, and Easy and Medium can always be solved without guessing.</li>
    <li>Click a square, then a number under the board (or type it). <kbd>Backspace</kbd> or Erase takes it out again, and the arrow keys move around.</li>
    <li>Turn on Notes (or press <kbd>N</kbd>) to write small numbers in a square, for the ones it could be. Putting a number in a square takes it out of the notes in its row, column and box.</li>
    <li>Two of the same number in a row, column or box turn red. The board doesn't tell you when a number is wrong in any other way.</li>
    <li>Stuck? Hint fills in the square you picked (or a random one) with the right number. Undo (<kbd>Ctrl</kbd> + <kbd>Z</kbd>) takes back your last move.</li>
    <li>The clock only runs while the page is on screen and the game isn't paused. Your game is saved in this browser, so you can close the page and pick it up later. Your best time for each level is kept there too.</li>
</ul>
