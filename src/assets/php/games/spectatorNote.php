<?php
// The bit at the end of a multiplayer game's line under its name that says it's watched (spectators.php): "Watching"
// for someone watching ($isWatching, set by the page), and "Spectators can watch" for the players while the host has
// spectator mode on ($room['state']). The game's script keeps it up to date (data-spectators-note), as the host can
// turn it on and off at any time.
$spectatorNoteShown = $isWatching || hbHubGamesSpectatorsOn($room['state']);
?>
<span data-spectators-note<?= $spectatorNoteShown ? '' : ' hidden' ?>> &middot; <?= $isWatching ? 'Watching' : 'Spectators can watch' ?></span>
