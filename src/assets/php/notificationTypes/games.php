<?php
// Games notifications (types "games.<kind>"). See assets/php/notifications.php.
//
// Each kind's text is what comes after the name of who did it, like "<strong>Alice</strong> invited you to Matador
// at Friday night". They all link to the Matador page's invites, as joining a game is a form post there.

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
    ],
];
