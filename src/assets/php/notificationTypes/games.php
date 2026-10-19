<?php
// Games notifications (types "games.<kind>"). See assets/php/notifications.php.
//
// Each kind's text is what comes after the name of who did it, like "<strong>Alice</strong> invited you to Matador
// at Friday night". The invites link to the invites on the game's page, as joining a game is a form post there, and
// the mentions straight to the game.

return [
    'icon' => '/assets/images/icons/controler.png',
    'kinds' => [
        // The host of a Matador game invited the user before it started (assets/php/games/matador/room.php).
        // Data: roomId, roomName.
        'matadorInvite' => [
            'text' => static fn (array $data): string => 'invited you to Matador at ' . ($data['roomName'] ?? 'their game'),
            'link' => static fn (array $data): string => '/games/matador/#invites',
        ],
        // The host gave the user an empty seat in a game being played, to take over. Data: roomId, roomName, player
        // (the name of who had the seat).
        'matadorSeat' => [
            'text' => static fn (array $data): string => 'gave you ' . ($data['player'] ?? 'a player') . '\'s seat in Matador at '
                . ($data['roomName'] ?? 'their game'),
            'link' => static fn (array $data): string => '/games/matador/#invites',
        ],
        // The owner of a saved game the user played in is continuing it (assets/php/games/matador/save.php).
        // Data: roomId, roomName.
        'matadorContinue' => [
            'text' => static fn (array $data): string => 'is continuing your Matador game ' . ($data['roomName'] ?? '') . '. Your seat is waiting',
            'link' => static fn (array $data): string => '/games/matador/#invites',
        ],
        // Another player @mentioned the user in the game's chat while their game wasn't open
        // (assets/php/games/matador/chat.php). Data: roomId, roomName.
        'matadorMention' => [
            'text' => static fn (array $data): string => 'mentioned you in the chat of your Matador game at ' . ($data['roomName'] ?? 'their game'),
            'link' => static fn (array $data): string => '/games/matador/game?room=' . (int) ($data['roomId'] ?? 0),
        ],
        // The host of a chess game invited the user while it waits for a player (assets/php/games/chess/room.php).
        // Data: roomId, roomName.
        'chessInvite' => [
            'text' => static fn (array $data): string => 'invited you to a game of chess at ' . ($data['roomName'] ?? 'their game'),
            'link' => static fn (array $data): string => '/games/chess/#invites',
        ],
        // The other player @mentioned the user in the game's chat while their game wasn't open
        // (assets/php/games/chess/chat.php). Data: roomId, roomName.
        'chessMention' => [
            'text' => static fn (array $data): string => 'mentioned you in the chat of your chess game at ' . ($data['roomName'] ?? 'their game'),
            'link' => static fn (array $data): string => '/games/chess/game?room=' . (int) ($data['roomId'] ?? 0),
        ],
    ],
];
