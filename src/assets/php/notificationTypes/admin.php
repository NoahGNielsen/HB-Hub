<?php
// Admin notifications (types "admin.<kind>"), sent from the admin panel's Announcements page (admin/announcements.php).
// See assets/php/notifications.php.
//
// They have nobody who did it (they don't say which admin), so the text is the whole sentence.

return [
    'icon' => '/assets/images/icons/admin.svg',
    'kinds' => [
        // A site admin sent an announcement to every user (announcements.php's hbHubAnnouncementSend()).
        // Data: announcementId, text (what the admin wrote), link (a path on the site, or null for none).
        'announcement' => [
            'text' => static fn (array $data): string => 'Announcement from the admins: ' . ($data['text'] ?? ''),
            'link' => static fn (array $data): ?string => is_string($data['link'] ?? null) ? $data['link'] : null,
        ],
    ],
];
