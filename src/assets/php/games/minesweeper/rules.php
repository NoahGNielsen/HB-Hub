<?php
// The rules of Minesweeper as played here, shown on the Minesweeper page (games/minesweeper/) and in its help docs
// (docs/games/minesweeper.php). The game is assets/minesweeper/js/main.js and cell.js (sizeLookup has the boards and
// their mines), so change them together.
?>
<ul class="casino-rule-list">
    <li>Pick a size in the bar under the board: Easy is 9&times;9 with 10 mines, Medium 16&times;16 with 40, and Hard 30&times;30 with 160.</li>
    <li>Click a square to open it. The clock starts with your first click. A number is how many of the 8 squares around it are mines, and a square with no mines around it opens its neighbours too.</li>
    <li>Think a square is a mine? <kbd>Shift</kbd> + click or right click it to put a flag on it (on most phones, press and hold), and do it again to take it off. The counter on the left is how many flags you have left.</li>
    <li>Open a mine and it's game over. Open every square without one and you win.</li>
    <li>Click the face for a new game. Nothing is saved, so closing the page ends the game.</li>
</ul>
