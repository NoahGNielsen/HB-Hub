<?php
// The "Spectator mode" choice in a multiplayer game's settings dialog (chess, UNO, Matador and Doodle Chain), included
// inside the dialog's form. Unlike the game's other settings it isn't saved with the form's Save button: the game's
// script sends it as the "spectators" action as soon as the host picks one (spectators.php), also during a game, and
// fills it in from the game's "spectators". Everyone but the host only sees it (the script disables it for them).
// The script finds it by data-spectators, and the choices by their name, "spectators".
?>
<fieldset class="casino-choices" data-spectators>
    <legend class="casino-label">Spectator mode</legend>
    <?php foreach ([
        'off' => ['Off (default)', 'Only the players can see the game.'],
        'on' => ['On', 'Anyone with the code can watch, without playing or reading the chat. A public game is also listed for watching in the lobby while it\'s played. Changes straight away, also during a game.'],
    ] as $value => [$label, $hint]): ?>
        <label class="casino-choice">
            <input type="radio" name="spectators" value="<?= $value ?>">
            <span><strong><?= $label ?></strong> <span class="casino-hint"><?= $hint ?></span></span>
        </label>
    <?php endforeach; ?>
</fieldset>
