<?php
// Browser fingerprints: the second way (next to the hbHubDevice cookie, loginLimit.php) the admin panel ties one
// person's accounts together (admin.php, adminPersonGroups()). The cookie is gone as soon as someone clears their
// cookies, uses a private window or another browser profile, but what their browser and computer are like stays the
// same, so the fingerprint still links the new account to the old one.
//
// How it gets here: session.php works out whether this login has sent one in the last HBHUB_SESSION_FINGERPRINT_HOURS
// ($hbHubFingerprintDue), header.php only then loads assets/js/fingerprint.js, which works it out (a SHA-256 of
// the canvas, graphics card, audio, fonts, screen, time zone, languages and so on, see the top of that file) and posts
// it to /fingerprint (otherPages/fingerprint.php), which calls hbHubFingerprintSave(). Only the hash is ever sent or
// kept, never what went into it.
//
// HBHub-DeviceFingerprints holds one row per fingerprint and account (fingerprintHash, userId), with the short browser
// name (like "Chrome on Windows", hbHubSessionDevice()) and when it was first and last seen. A row not seen for
// HBHUB_DEVICE_DAYS goes the next time the account sends one, like the known devices, and they all go when the
// account is deleted (account.php).
//
// Lots of school computers are the same model with the same setup, so they can give the same fingerprint for different
// people. A fingerprint seen with more than HBHUB_FINGERPRINT_SHARED_MAX accounts is taken to be one of those and
// doesn't link anyone (the person page still lists it, marked as too common).
//
// A browser that sends Do Not Track (the DNT: 1 header, hbHubSessionDoNotTrack()) is never fingerprinted: the script
// isn't loaded and /fingerprint throws away anything it gets. Its accounts are still tied together by the device
// cookie. Fingerprints it sent before Do Not Track was turned on go after HBHUB_DEVICE_DAYS, like any other.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/loginLimit.php'; // HBHUB_DEVICE_DAYS

const HBHUB_FINGERPRINT_SHARED_MAX = 4; // more accounts than this with the same fingerprint, it doesn't link them

/**
 * Whether $value can be a fingerprint from fingerprint.js: 64 lowercase hex characters (a SHA-256).
 */
function hbHubFingerprintIsValid(mixed $value): bool
{
    return is_string($value) && strlen($value) === 64 && ctype_xdigit($value) && strtolower($value) === $value;
}

/**
 * Stores the fingerprint for the account (or that it was seen again), unless this login already sent one in the
 * last HBHUB_SESSION_FINGERPRINT_HOURS: claiming the session's turn with one UPDATE first means a script posting
 * made-up ones can't fill the table, and two tabs loading at once only store it once. Returns whether it was stored.
 */
function hbHubFingerprintSave(PDO $db, int $userId, string $sessionId, string $fingerprint): bool
{
    $claim = $db->prepare('UPDATE `HBHub-Sessions` SET sessionFingerprintTimestamp = NOW() WHERE sessionId = ? AND userId = ?
        AND (sessionFingerprintTimestamp IS NULL OR sessionFingerprintTimestamp < NOW() - INTERVAL ' . HBHUB_SESSION_FINGERPRINT_HOURS . ' HOUR)');
    $claim->execute([$sessionId, $userId]);
    if ($claim->rowCount() === 0) {
        return false;
    }

    $device = hbHubSessionDevice();
    $db->prepare('INSERT INTO `HBHub-DeviceFingerprints` (fingerprintHash, userId, fingerprintDevice) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE fingerprintLastSeenTimestamp = NOW(), fingerprintDevice = VALUES(fingerprintDevice)')
        ->execute([$fingerprint, $userId, $device]);
    // The account's fingerprints that haven't been seen for too long go, like its known devices
    $db->prepare('DELETE FROM `HBHub-DeviceFingerprints` WHERE userId = ? AND fingerprintLastSeenTimestamp <= NOW() - INTERVAL '
        . HBHUB_DEVICE_DAYS . ' DAY')
        ->execute([$userId]);
    return true;
}
