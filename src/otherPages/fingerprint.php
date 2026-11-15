<?php
// Where assets/js/fingerprint.js sends the browser's fingerprint (assets/php/fingerprint.php says what it is and
// what's kept). Only for logged in users: header.php only loads the script for them, when their login hasn't sent
// one in the last day.
//
// POST /fingerprint (form-encoded): action=save, fingerprint (64 lowercase hex characters, a SHA-256).
// Answers {"ok": true, "saved": bool} (saved is false when this login already sent one today). Errors:
// - 405 {"error": "methodNotAllowed"}: not a POST.
// - 401 {"error": "notLoggedIn"}: no valid session.
// - 400 {"error": "badRequest"}: no action=save, or the fingerprint isn't one.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/fingerprint.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}
if ($hbHubUser === null) {
    http_response_code(401);
    echo json_encode(['error' => 'notLoggedIn']);
    exit;
}

$fingerprint = $_POST['fingerprint'] ?? null;
if (($_POST['action'] ?? null) !== 'save' || !hbHubFingerprintIsValid($fingerprint)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

// session.php only lets a valid session id through, so the cookie is that one
$saved = hbHubFingerprintSave(hbHubDatabase(), (int) $hbHubUser['userId'], (string) $_COOKIE[HBHUB_SESSION_COOKIE], $fingerprint);
echo json_encode(['ok' => true, 'saved' => $saved]);
