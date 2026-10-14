<?php
// Casino notifications (types "gambling.<kind>"). See assets/php/notifications.php.
//
// Each kind's text is what comes after the name of who did it, like "<strong>Alice</strong> invited you to Blackjack
// at Friday night". A notification without anyone who did it shows the text on its own, so then it has to be the whole sentence.

return [
    'icon' => '/assets/images/icons/dice.png',
    'kinds' => [
        // The host of a Blackjack table invited the user (assets/php/blackjack/room.php). Data: roomId, roomName.
        // The lobby lists their invites with a Join button, as joining is a form post.
        'blackjackInvite' => [
            'text' => static fn (array $data): string => 'invited you to Blackjack at ' . ($data['roomName'] ?? 'their table'),
            'link' => static fn (array $data): string => '/gambling/blackjack/#invites',
        ],
    ],
];
