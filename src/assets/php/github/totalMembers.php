<?php
// Member count for the README shield: https://img.shields.io/endpoint?url=https://hbhub.noahgajnielsen.dk/assets/php/.github/totalMembers
// Returns shields.io endpoint JSON (https://shields.io/badges/endpoint-badge). Counts every account that isn't deleted
// or banned (a deleted account has userStatus set to NULL, a banned one has it at 4).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/database.php';

const HBHUB_SHIELD_CACHE_SECONDS = 3600; // shields.io caches the badge this long before asking again
const HBHUB_SHIELD_STATUS_BANNED = 4; // userStatus in HBHub-Users

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=' . HBHUB_SHIELD_CACHE_SECONDS);

try {
    $statement = hbHubDatabase()->prepare('SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ?');
    $statement->execute([HBHUB_SHIELD_STATUS_BANNED]);
    $total = (int) $statement->fetchColumn();
    $badge = ['message' => number_format($total), 'color' => 'blue'];
} catch (Throwable $exception) {
    $badge = ['message' => 'unavailable', 'color' => 'lightgrey', 'isError' => true];
}

echo json_encode(['schemaVersion' => 1, 'label' => 'members', 'cacheSeconds' => HBHUB_SHIELD_CACHE_SECONDS] + $badge);
