<?php
// Spectator mode for the multiplayer games (chess, UNO, Matador and Doodle Chain): watching a game without playing in it.
//
// It's a room setting, "spectators" in the room's state (roomState), true or false. It's off in every new room, and in
// rooms from before there was such a setting (hence hbHubGamesSpectatorsOn() reads it with a default). The host turns it
// on and off in the game's settings, whenever they want, with the "spectators" action of the game's play.php
// (hbHubGamesSetSpectators()).
//
// While it's on, anyone who has the room's code can watch it at /games/<game>/game?watch=CODE: the game's page in
// watching mode, which asks the game's play.php with ?watch=CODE instead of ?room=id. Watchers get the game's own view
// built for nobody in it (HBHUB_GAMES_SPECTATOR_ID), so they see what any player sees of the others and nothing
// hidden (no hands, prompts or card order), and they don't get the game's chat. They can't do anything in the game.
// Public games being played with it on are listed under "Games to watch" in the game's lobby, which is where the codes
// come from for those. A user who sits in a game of the same kind can't watch one, as being away from their own game
// for long enough loses it or their seat.
//
// Nothing about watchers is stored: the players aren't told who watches, only that the game can be watched.

const HBHUB_GAMES_SPECTATOR_ID = 0; // the userId a watcher's view is built for: no user has it, so nothing is "theirs"

/**
 * Whether people can watch the room (its state's "spectators", off when it's missing).
 */
function hbHubGamesSpectatorsOn(array $state): bool
{
    return ($state['spectators'] ?? false) === true;
}

/**
 * The host turns watching on or off ($on as sent: "on" or "off"). Returns null when it's done, otherwise why not.
 */
function hbHubGamesSetSpectators(array &$state, bool $isHost, mixed $on): ?string
{
    if (!$isHost) {
        return 'Only the host can change who can watch.';
    }
    if ($on !== 'on' && $on !== 'off') {
        return 'Choose whether people can watch.';
    }
    $state['spectators'] = $on === 'on';
    return null;
}

/**
 * The message the lobby shows when the user was sent back from watching (its ?watch=): "seated" when they sit in a game
 * of their own, "gone" when the game isn't there or can't be watched (anymore). Null for anything else.
 */
function hbHubGamesWatchNotice(mixed $why): ?string
{
    return match ($why) {
        'seated' => 'You can\'t watch a game while you\'re in one yourself. Leave yours first.',
        'gone' => 'That game can\'t be watched. It may have ended, or its host has turned spectators off.',
        default => null,
    };
}

/**
 * Sends the user from the game page back to the lobby at $lobbyUrl, with why (hbHubGamesWatchNotice()).
 */
function hbHubGamesWatchRefused(string $lobbyUrl, string $why): never
{
    header('Location: ' . $lobbyUrl . '?watch=' . $why, true, 302);
    exit;
}

/**
 * The play.php answer for a watcher whose game can't be watched (anymore): the page then goes back to the lobby.
 */
function hbHubGamesNotWatchable(): never
{
    http_response_code(404);
    echo json_encode(['error' => 'notWatchable']);
    exit;
}
