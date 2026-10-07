<?php
// GIF search for the GIF picker: /chat/gifs?q=cats returns matching GIFs from Giphy as JSON, an empty q the trending ones.
// Asked through here, so the API key never reaches the browser. 404 when GIFs are turned off in hbHubSiteConfig.php.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/giphy.php';

const HBHUB_GIF_SEARCH_LIMIT = 30;
const HBHUB_GIF_RATING = 'pg-13'; // Giphy's content rating: g, pg, pg-13 or r

$apiKey = hbHubGiphyApiKey(require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php');
if ($apiKey === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 50) : ''; // Giphy allows 50 characters

$parameters = ['api_key' => $apiKey, 'limit' => HBHUB_GIF_SEARCH_LIMIT, 'rating' => HBHUB_GIF_RATING];
if ($query !== '') {
    $parameters['q'] = $query;
}
$curl = curl_init('https://api.giphy.com/v1/gifs/' . ($query === '' ? 'trending' : 'search') . '?' . http_build_query($parameters));
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$response = is_string($body) ? json_decode($body, true) : null;

// A wrong key (401/403) or too many searches this hour (429) end up here too
if ($status !== 200 || !is_array($response['data'] ?? null)) {
    error_log('GIF search: Giphy answered ' . $status . ($body === false ? ' (' . curl_error($curl) . ')' : ''));
    http_response_code(502);
    echo json_encode(['gifs' => []]);
    exit;
}

// Only what the picker needs: the id to send, and a 200px wide preview from Giphy's own servers
$gifs = [];
foreach ($response['data'] as $gif) {
    $preview = $gif['images']['fixed_width'] ?? null;
    $previewUrl = $preview['webp'] ?? $preview['url'] ?? null;
    if (!hbHubGiphyIsId($gif['id'] ?? null) || !is_string($previewUrl)
        || !preg_match('~^https://[a-z0-9-]+\.giphy\.com/~', $previewUrl)) {
        continue;
    }
    $gifs[] = [
        'id' => $gif['id'],
        'title' => is_string($gif['title'] ?? null) ? $gif['title'] : '',
        'preview' => $previewUrl,
        'width' => (int) ($preview['width'] ?? 0),
        'height' => (int) ($preview['height'] ?? 0),
    ];
}

echo json_encode(['gifs' => $gifs]);
