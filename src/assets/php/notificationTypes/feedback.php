<?php
// Feedback notifications (types "feedback.<kind>"), sent from the admin panel's Feedback tab (admin/feedback.php).
// See assets/php/notifications.php.
//
// They have nobody who did it (they don't say which admin), so the text is the whole sentence.

return [
    'icon' => '/assets/images/icons/feedback.svg',
    'kinds' => [
        // A site admin marked the user's feedback as done with Reply, and mark completed (feedback.php's hbHubFeedbackReply()).
        // No link, as users can't see their feedback anywhere. Data: feedbackId, reply (what the admin wrote).
        'reply' => [
            'text' => static fn (array $data): string => 'An admin has marked your feedback as completed with the message: ' . ($data['reply'] ?? ''),
        ],
    ],
];
