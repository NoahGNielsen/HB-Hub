<?php
// Addresses for users' profile pages and profile pictures (otherPages/profile.php and otherPages/avatar.php).
// A profile opened from a leaderboard gets &from=<the leaderboard's address>, and the profile shows a "Back to ..." link
// to it, as some people don't use the browser's own back button.

// The pages a profile can link back to: address (without .php) => what the link says. Only these are ever linked to,
// so ?from= can't be used to send someone off to another site.
const HBHUB_PROFILE_BACK_PAGES = [
    '/gambling/' => 'Casino',
    '/gambling/leaderboard' => 'Casino leaderboard',
    '/gambling/blackjack/leaderboard' => 'Blackjack leaderboard',
    '/gambling/baccarat/leaderboard' => 'Baccarat leaderboard',
    '/gambling/roulette/leaderboard' => 'Roulette leaderboard',
    '/games/matador/leaderboard' => 'Matador leaderboard',
    '/games/chess/leaderboard' => 'Chess leaderboard',
    '/games/uno/leaderboard' => 'UNO leaderboard',
    '/games/wordle/leaderboard' => 'Wordle leaderboard',
    '/games/ore-mining-inc/leaderboard' => 'Ore Mining Inc. leaderboard',
];

/**
 * The address of a user's profile. $from is the address of the page linking to it (a leaderboard passes
 * $_SERVER['REQUEST_URI']), for the profile's back link.
 */
function hbHubProfileUrl(int $userId, ?string $from = null): string
{
    return '/profile?user=' . $userId . ($from !== null ? '&from=' . rawurlencode($from) : '');
}

/**
 * The profile's back link from its ?from= as [address, text], or null when there's none or it isn't one of
 * HBHUB_PROFILE_BACK_PAGES. Of the query only the leaderboards' own sort, dir and lang are kept, so it goes back to
 * the same sorting (and Wordle language).
 */
function hbHubProfileBackLink(mixed $from): ?array
{
    if (!is_string($from) || !str_starts_with($from, '/') || str_starts_with($from, '//')) {
        return null;
    }
    $path = parse_url($from, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }
    // The same page under the other addresses .htaccess answers on
    $path = preg_replace('/(?:index)?(?:\.php)?$/', '', $path);
    if (!isset(HBHUB_PROFILE_BACK_PAGES[$path])) {
        return null;
    }
    parse_str((string) parse_url($from, PHP_URL_QUERY), $query);
    $keep = [];
    foreach (['lang', 'sort', 'dir'] as $key) {
        if (is_string($query[$key] ?? null) && preg_match('/^[A-Za-z]{1,32}$/', $query[$key])) {
            $keep[$key] = $query[$key];
        }
    }
    return [$path . ($keep !== [] ? '?' . http_build_query($keep) : ''), HBHUB_PROFILE_BACK_PAGES[$path]];
}

/**
 * The address of the user's profile picture, or null when they don't have one.
 * The attachment id in the address changes with every new picture, so an old one is never shown from the cache.
 */
function hbHubAvatarUrl(int $userId, ?int $attachmentId): ?string
{
    return $attachmentId !== null ? '/avatar?user=' . $userId . '&v=' . $attachmentId : null;
}
