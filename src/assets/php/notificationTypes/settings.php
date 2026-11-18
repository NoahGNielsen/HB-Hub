<?php
// Settings notifications (types "settings.<kind>"), for when the site changed one of the user's settings for them.
// See assets/php/notifications.php.
//
// They have nobody who did it (the site did it on its own), so the text is the whole sentence.

return [
    'icon' => '/assets/images/icons/settings.png',
    'kinds' => [
        // The user signed up with a browser that sends Global Privacy Control or Do Not Track (Sec-GPC: 1 / DNT: 1), so the new account was made
        // with Error reports and Anonymous usage statistics turned off (onboardingSaveToDB.php's hbHubSignupDoNotTrack()). No data.
        'doNotTrack' => [
            'text' => static fn (array $data): string => 'Your browser asks websites not to track you (Global Privacy Control), so we\'ve '
                . 'turned off Error reports and Anonymous usage statistics for you: your page views aren\'t counted and no error reports are sent. '
                . 'You can turn them back on in your settings.',
            'link' => static fn (array $data): ?string => '/settings#improve',
        ],
    ],
];
