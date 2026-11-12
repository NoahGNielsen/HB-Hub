<?php
// The rules of Minesweeper as played here, shown on the Minesweeper page (games/minesweeper/) and in its help docs
// (docs/games/minesweeper.php). The game is assets/minesweeper/js/main.js and cell.js, with the mines and the timing on
// the server (assets/php/games/minesweeper/minesweeper.php, HBHUB_MINESWEEPER_SIZES has the boards and their mines),
// so change them together.
?>
<ul class="casino-rule-list">
    <li>Pick a size in the bar under the board: Easy is 9&times;9 with 10 mines, Medium 16&times;16 with 40, and Hard 30&times;30 with 160.</li>
    <li>Click a square to open it. Your first click is never a mine, and always opens an area: the mines are placed after it. A number is how many of the 8 squares around it are mines, and a square with no mines around it opens its neighbours too.</li>
    <li>Think a square is a mine? <kbd>Shift</kbd> + click or right click it to put a flag on it (on most phones, press and hold), and do it again to take it off. The counter on the left is how many flags you have left.</li>
    <li>Open a mine and it's game over. Open every square without one and you win.</li>
    <li>The clock starts with your first click and stops with the click that wins. Your time goes on the <a href="/games/minesweeper/leaderboard">leaderboard</a> for the size you played.</li>
    <li>Click the face for a new game. A game you leave or start over counts as played, but not won.</li>
</ul>
