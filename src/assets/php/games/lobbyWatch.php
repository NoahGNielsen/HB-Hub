<?php
// "Games to watch" in a multiplayer game's lobby (chess, UNO, Matador and Doodle Chain): the public games being played
// whose host has spectator mode on (spectators.php). Included in the lobby's list of rooms; the page sets $lobbyWatchUrl
// to its game page (like "/games/chess/game") first. lobbyWatch.js fills it in, from the rooms in the lobby's JSON
// ("watch") that the lobby's own script hands it with the lobby:watch event on every update.
?>
<section class="lobby-panel lobby-list" aria-labelledby="lobby-watch-title" data-lobby-watch="<?= htmlspecialchars($lobbyWatchUrl) ?>">
    <div class="lobby-panel-head">
        <h2 id="lobby-watch-title">Games to watch</h2>
        <span class="lobby-count" data-lobby-watch-count></span>
    </div>
    <ul class="lobby-rooms" data-lobby-watch-rooms></ul>
    <p class="lobby-empty" data-lobby-watch-empty>Loading…</p>
</section>
