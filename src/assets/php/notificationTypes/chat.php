<?php
// Chat notifications (types "chat.<kind>"), sent from chat/index.php and the admin panel (admin/chat.php).
// See assets/php/notifications.php.
//
// Each kind's text is what comes after the name of who did it, like "<strong>Alice</strong> kicked you from Gamers".
// A notification without anyone who did it shows the text on its own, so then it has to be the whole sentence.
// Their data is the chat's id, and its name when it was sent (chatName, null for a group without one).

// A group without a name is named after its members in the chat, that's too long here
$group = static fn (array $data): string => ($data['chatName'] ?? '') !== '' ? $data['chatName'] : 'a group';
$openChat = static fn (array $data): string => '/chat/?chat=' . (int) ($data['chatId'] ?? 0);

return [
    'icon' => '/assets/images/icons/chat.png',
    'kinds' => [
        // A new DM, or added to a group, waiting to be accepted. isGroup says which.
        'messageRequest' => [
            'text' => static fn (array $data): string => !empty($data['isGroup'])
                ? 'added you to ' . $group($data)
                : 'sent you a message request',
            'link' => static fn (array $data): string => '/chat/?requests=1', // chat.js opens the Message requests popup
        ],
        // No link, the user isn't in the group anymore
        'kicked' => [
            'text' => static fn (array $data): string => 'kicked you from ' . $group($data),
        ],
        // Kicked from the admin panel, which doesn't say who did it, so this is the whole sentence
        'kickedBySiteAdmin' => [
            'text' => static fn (array $data): string => 'A site admin kicked you from ' . $group($data),
        ],
        // The other person left the DM
        'leftDm' => [
            'text' => static fn (array $data): string => 'left your DM',
            'link' => $openChat,
        ],
        'madeAdmin' => [
            'text' => static fn (array $data): string => 'made you an admin in ' . $group($data),
            'link' => $openChat,
        ],
        // Someone @mentioned the user in a message (chat/index.php's chatNotifyMentions()). Its data has messageId too,
        // which the link scrolls to (chat.js), and which takes the notification back when the message is deleted.
        'mention' => [
            'text' => static fn (array $data): string => 'mentioned you in ' . (!empty($data['isGroup']) ? $group($data) : 'your DM'),
            'link' => static fn (array $data): string => $openChat($data) . '#message-' . (int) ($data['messageId'] ?? 0),
        ],
        'removedAdmin' => [
            'text' => static fn (array $data): string => 'removed you as admin in ' . $group($data),
            'link' => $openChat,
        ],
    ],
];
