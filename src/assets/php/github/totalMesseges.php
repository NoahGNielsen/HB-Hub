<?php
// Message count for the README shield: https://img.shields.io/endpoint?url=https://hbhub.noahgajnielsen.dk/assets/php/.github/totalMesseges
// Returns shields.io endpoint JSON (https://shields.io/badges/endpoint-badge). Counts the messages people have sent,
// leaving out deleted ones and notices about a change in the chat (messageEvent, like a new group name).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/database.php';

const HBHUB_SHIELD_CACHE_SECONDS = 3600; // shields.io caches the badge this long before asking again

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=' . HBHUB_SHIELD_CACHE_SECONDS);

try {
    $total = (int) hbHubDatabase()->query('SELECT COUNT(*) FROM `HBHub-Messages`
        WHERE messageEvent = 0 AND messageDeletedTimestamp IS NULL')->fetchColumn();
    $badge = ['message' => number_format($total), 'color' => 'brightgreen'];
} catch (Throwable $exception) {
    $badge = ['message' => 'unavailable', 'color' => 'lightgrey', 'isError' => true];
}

echo json_encode(['schemaVersion' => 1, 'label' => 'messages', 'cacheSeconds' => HBHUB_SHIELD_CACHE_SECONDS] + $badge);
