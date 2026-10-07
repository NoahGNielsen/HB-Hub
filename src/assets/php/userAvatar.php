<?php
// Addresses for users' profile pages and profile pictures (otherPages/profile.php and otherPages/avatar.php).

function hbHubProfileUrl(int $userId): string
{
    return '/otherPages/profile?user=' . $userId;
}

/**
 * The address of the user's profile picture, or null when they don't have one.
 * The attachment id in the address changes with every new picture, so an old one is never shown from the cache.
 */
function hbHubAvatarUrl(int $userId, ?int $attachmentId): ?string
{
    return $attachmentId !== null ? '/otherPages/avatar?user=' . $userId . '&v=' . $attachmentId : null;
}
