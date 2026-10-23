<?php
// Everything the nav bar keeps up to date, in one request, for navUpdates.js: the hosting only runs about 10 PHP
// requests at once for the whole site, so every open page asking once instead of once per number matters.
// GET /nav-updates answers with hbHubNavUpdates() (assets/php/navUpdates.php says what's in it), from the fields:
// &wallet=1 (casino pages), &messages=1 and &after= (browser notifications and the message sound).
// Pages that poll their own server anyway (the chat, the game tables and lobbies) take this along with theirs instead
// (hbHubNavRide()), so this is only asked on the other pages, and while the page is hidden.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

echo json_encode(hbHubNavUpdates(hbHubDatabase(), $hbHubUser, $_GET), JSON_INVALID_UTF8_SUBSTITUTE);
