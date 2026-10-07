<?php
// Giphy GIFs in the chat, set up by giphy_gif_integration and giphy_api_key in hbHubSiteConfig.php.
// The key stays on the server: the GIF picker searches through /chat/gifs, which asks Giphy for it.

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
