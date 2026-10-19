<?php
// The rules of chess as played here, shown on the chess page and in the game. board.php, game.php and room.php play by
// them, so change them together.
?>
<ul class="casino-rule-list">
    <li>
        The normal rules of chess: castling, en passant, and a pawn on the last rank becomes a queen, rook, bishop or
        knight of your choice. The game starts as soon as a second player joins.
    </li>
    <li>
        Checkmate wins. Stalemate, the same position three times, 50 moves each without a capture or a pawn move, and
        too few pieces left for anyone to mate are all draws, straight away.
    </li>
    <li>
        With a clock, the time you take for a move comes off your clock (White's first move is free), and the extra
        seconds are added after every move. Run out of time and you lose, unless all your opponent has left is their
        king, or a king and one bishop or knight. Then it's a draw.
    </li>
    <li>
        You can resign or offer a draw at any time, and abort the game until you've both made a move (an aborted game
        doesn't count). A draw offer is taken back when your opponent plays a move instead of answering it.
    </li>
    <li>
        Leaving the game loses it. If your opponent's game has been closed for <?= HBHUB_CHESS_CLAIM_SECONDS ?> seconds,
        you can claim the win.
    </li>
    <li>
        The host can pause the game. The clocks stop and nobody can move or offer a draw until the host goes on, and
        the host can't claim the win while it's paused (their opponent still can, if the host's game is closed).
    </li>
    <li>When the game is over, you can both ask for a rematch. You swap colours.</li>
    <li>
        With a mouse, drag with the right button to draw an arrow, or right-click a square to colour it in (hold Shift,
        Ctrl or Alt for other colours). Only you see them, and a left click on the board or the next move clears them.
    </li>
</ul>
