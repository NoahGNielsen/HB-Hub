<?php
// The zoom buttons under a game's board, included by the chess and Matador game pages right after the board's frame
// ([data-board-zoom]). boardZoom.js makes them work and writes the zoom level between them; styled in games.css.
?>
<div class="games-zoom" role="group" aria-label="Zoom the board" data-board-zoom-controls>
    <button type="button" class="casino-btn games-zoom-btn" aria-label="Zoom out" title="Zoom out" data-zoom-out>
        <svg class="games-zoom-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
    </button>
    <span class="games-zoom-level" aria-live="polite" data-zoom-level>100%</span>
    <button type="button" class="casino-btn games-zoom-btn" aria-label="Zoom in" title="Zoom in" data-zoom-in>
        <svg class="games-zoom-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    </button>
</div>
