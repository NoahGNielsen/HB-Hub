<?php
// Page views: how many times each page was opened each day, for "Page views" and "Most visited pages" on the admin
// panel's Overview (admin/overview.php). header.php calls hbHubPageViewCount() on every page it's in, but not for a
// logged-in user who has turned "Help improve HB Hub" off in the settings (hbHubImproveSiteOn()).
//
// Only counts are kept, one row per page and day in HBHub-PageViews (viewDate, viewPath, viewCount, viewGuestCount):
// never who it was, their IP or their browser. viewGuestCount is the part of viewCount that wasn't logged in. The path
// is the page's clean address without the query, the same as the canonical URL in header.php (so /chat/?chat=5 is
// /chat/, and /index is /). Rows older than HBHUB_PAGE_VIEWS_KEEP_DAYS are deleted by the nightly clean-up
// (cron/nightly.php, hbHubPageViewsSweep()).
//
// What counts as a view:
// - a GET that ends in a page (a 200, so error pages and their made-up addresses never get a row)
// - the browser opening it itself (Sec-Fetch-Mode: navigate, or no Sec-Fetch-Mode from an old browser), or the chat
//   swapping in another chat page (chat.js sends X-HBHub-Page-View: 1, but not for the reload after sending a message
//   or when the open chat is gone, as nobody opened anything then)
// - not a prefetch or prerender (Sec-Purpose / Purpose), and not a user agent that says it's a bot or a script

const HBHUB_PAGE_VIEWS_KEEP_DAYS = 90;
// User agents of search engines, link previews and scripts, which would drown out the people
const HBHUB_PAGE_VIEWS_BOT_PATTERN = '~bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|headless|lighthouse~i';

/**
 * Adds one to today's count for $path (the page's clean path, from header.php) when this request counts as a view
 * (see the top of the file). Never stops the page: if the database fails, the view just isn't counted.
 */
function hbHubPageViewCount(string $path, bool $loggedIn): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || http_response_code() !== 200 || strlen($path) > 255) {
        return;
    }
    $mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null;
    if ($mode !== null && $mode !== 'navigate' && ($_SERVER['HTTP_X_HBHUB_PAGE_VIEW'] ?? '') !== '1') {
        return;
    }
    $purpose = ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? '');
    if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
        return;
    }
    $agent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ($agent === '' || preg_match(HBHUB_PAGE_VIEWS_BOT_PATTERN, $agent)) {
        return;
    }

    try {
        require_once __DIR__ . '/database.php';
        hbHubDatabase()->prepare('INSERT INTO `HBHub-PageViews` (viewDate, viewPath, viewCount, viewGuestCount) VALUES (CURDATE(), ?, 1, ?)
            ON DUPLICATE KEY UPDATE viewCount = viewCount + 1, viewGuestCount = viewGuestCount + ?')
            ->execute([$path, $loggedIn ? 0 : 1, $loggedIn ? 0 : 1]);
    } catch (Throwable) {
        // A page view isn't worth an error page
    }
}

/**
 * Deletes the days older than HBHUB_PAGE_VIEWS_KEEP_DAYS, for the nightly clean-up. Returns how many rows went.
 */
function hbHubPageViewsSweep(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-PageViews` WHERE viewDate < CURDATE() - INTERVAL ' . HBHUB_PAGE_VIEWS_KEEP_DAYS . ' DAY');
}
