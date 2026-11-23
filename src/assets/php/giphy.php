<?php
// Giphy GIFs in the chat, set up by giphy_gif_integration and giphy_api_key in hbHubSiteConfig.php.
// The key stays on the server: the GIF picker searches through /chat/gifs, which asks Giphy for it.
// To save on Giphy's hourly API limit, every answer is kept in `HBHub-GifCache` for HBHUB_GIPHY_CACHE_SECONDS, keyed
// by a hash of the search, and the same search is answered from there until it runs out (shared by everyone).
// Every request that does go to Giphy is counted per hour in `HBHub-GiphyCalls` (only counts, never who or what was
// searched), for the "Giphy API calls" chart on the admin panel's Overview; hours older than
// HBHUB_GIPHY_CALLS_KEEP_DAYS are deleted as new calls come in.

const HBHUB_GIPHY_CACHE_SECONDS = 15 * 60;
const HBHUB_GIPHY_CALLS_KEEP_DAYS = 8; // the Overview shows 7 days; one more so the oldest hour is always there
const HBHUB_GIPHY_HOURLY_LIMIT_DEFAULT = 100; // Giphy's limit for a beta key; giphy_hourly_limit in the config

/**
 * The Giphy API key, or null when GIFs are turned off or no key is set (the example config's placeholder counts as none).
 */
function hbHubGiphyApiKey(array $siteConfig): ?string
{
    $key = is_string($siteConfig['giphy_api_key'] ?? null) ? trim($siteConfig['giphy_api_key']) : '';
    if (!filter_var($siteConfig['giphy_gif_integration'] ?? true, FILTER_VALIDATE_BOOLEAN)
        || $key === '' || $key === 'your_giphy_api_key') {
        return null;
    }
    return $key;
}

/**
 * How many API calls Giphy allows the key each hour (giphy_hourly_limit in hbHubSiteConfig.php), for the Overview's
 * chart to show which hours hit it.
 */
function hbHubGiphyHourlyLimit(array $siteConfig): int
{
    return max(1, (int) ($siteConfig['giphy_hourly_limit'] ?? HBHUB_GIPHY_HOURLY_LIMIT_DEFAULT));
}

/**
 * Counts one request to Giphy in this hour's row of `HBHub-GiphyCalls` (by the database's own clock), and whether
 * Giphy turned it away for too many calls (HTTP 429). Clears out the hours older than HBHUB_GIPHY_CALLS_KEEP_DAYS. A
 * count isn't worth failing a GIF search over, so errors (like the table not being there yet) are ignored.
 */
function hbHubGiphyCallCount(PDO $db, bool $limited): void
{
    try {
        $db->prepare('INSERT INTO `HBHub-GiphyCalls` (giphyCallHour, giphyCallCount, giphyCallLimitedCount)
            VALUES (DATE_FORMAT(NOW(), \'%Y-%m-%d %H:00:00\'), 1, ?)
            ON DUPLICATE KEY UPDATE giphyCallCount = giphyCallCount + 1, giphyCallLimitedCount = giphyCallLimitedCount + VALUES(giphyCallLimitedCount)')
            ->execute([$limited ? 1 : 0]);
        $db->exec('DELETE FROM `HBHub-GiphyCalls` WHERE giphyCallHour < NOW() - INTERVAL ' . HBHUB_GIPHY_CALLS_KEEP_DAYS . ' DAY');
    } catch (Throwable) {
        // Only a statistic
    }
}

/**
 * Whether the string can be a Giphy GIF id (letters and digits only, so it's safe to put in a URL).
 */
function hbHubGiphyIsId(mixed $id): bool
{
    return is_string($id) && preg_match('~^[A-Za-z0-9]{1,64}$~', $id) === 1;
}

/**
 * The search as it's sent to Giphy and cached: lowercase, with runs of spaces made one, so "Cats" and "cats " share
 * a cache row. Empty means the trending GIFs.
 */
function hbHubGiphyCleanQuery(string $query): string
{
    return mb_strtolower(trim(preg_replace('~\s+~u', ' ', $query) ?? ''));
}

/**
 * The cached GIFs for a (cleaned) search, or null when it hasn't been asked in the last HBHUB_GIPHY_CACHE_SECONDS.
 * Each GIF is ['id', 'title', 'preview', 'width', 'height'], as /chat/gifs sends them.
 */
function hbHubGiphyCacheGet(PDO $db, string $query): ?array
{
    $statement = $db->prepare('SELECT gifCacheGifs FROM `HBHub-GifCache`
        WHERE gifCacheKey = ? AND gifCacheExpiresTimestamp > UTC_TIMESTAMP()');
    $statement->execute([hash('sha256', $query)]);
    $gifs = $statement->fetchColumn();
    $gifs = is_string($gifs) ? json_decode($gifs, true) : null;
    return is_array($gifs) ? $gifs : null;
}

/**
 * Keeps Giphy's answer to a (cleaned) search for HBHUB_GIPHY_CACHE_SECONDS, and clears out the rows that ran out
 * so the table only ever holds the last 15 minutes of searches.
 */
function hbHubGiphyCacheSave(PDO $db, string $query, array $gifs): void
{
    $db->exec('DELETE FROM `HBHub-GifCache` WHERE gifCacheExpiresTimestamp <= UTC_TIMESTAMP()');
    $db->prepare('INSERT INTO `HBHub-GifCache` (gifCacheKey, gifCacheGifs, gifCacheExpiresTimestamp)
        VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)
        ON DUPLICATE KEY UPDATE gifCacheGifs = VALUES(gifCacheGifs), gifCacheExpiresTimestamp = VALUES(gifCacheExpiresTimestamp)')
        ->execute([hash('sha256', $query), json_encode($gifs), HBHUB_GIPHY_CACHE_SECONDS]);
}
