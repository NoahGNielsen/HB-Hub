<?php
// Giphy GIFs in the chat, set up by giphy_gif_integration and giphy_api_key in hbHubSiteConfig.php.
// The key stays on the server: the GIF picker searches through /chat/gifs, which asks Giphy for it.
// To save on Giphy's hourly API limit, every answer is kept in `HBHub-GifCache` for HBHUB_GIPHY_CACHE_SECONDS, keyed
// by a hash of the search, and the same search is answered from there until it runs out (shared by everyone).

const HBHUB_GIPHY_CACHE_SECONDS = 15 * 60;

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
