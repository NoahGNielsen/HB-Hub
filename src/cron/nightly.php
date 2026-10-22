<?php
// Nightly clean-up, run by the server's crontab at 2:30 every night (see docs/setup.md), never by a browser.
// The site does all of this lazily on the next request anyway (chat page, lobbies, GIF searches), but only a batch
// at a time and only when someone comes by, so this catches up on whatever was left overnight:
//   - every expired chat attachment: its file deleted from disk and its row marked as expired
//   - casino tables and game rooms nobody has open anymore (a batch of each, like a lobby visit)
//   - GIF searches cached longer than 15 minutes
//   - admin log rows older than a year (assets/php/adminLog.php)
// Each job runs on its own, so one failing doesn't stop the rest. It prints one line per job, and errors go to
// stderr with exit code 1, so the crontab's mail (if MAILTO is set) only has something to say when it matters.
//
// Run by hand with: php /var/www/noahgajnielsen.dk/hbhub/cron/nightly.php

// Only from the command line: over the web it's just a page that isn't there (cron/.htaccess denies it too)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// The shared PHP finds the config and its own files from the web root, which a command line run doesn't have
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
$hbHubSessionOptional = true; // session.php (pulled in by chatAttachments.php) then just finds no one logged in

// Maintenance mode keeps the database untouched, and maintenance.php would print the 503 page here
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
if (filter_var($siteConfig['site_maintenance'], FILTER_VALIDATE_BOOLEAN)) {
    echo "Maintenance mode is on, so nothing was cleaned up.\n";
    exit;
}

require_once __DIR__ . '/../assets/php/chatAttachments.php';
require_once __DIR__ . '/../assets/php/casino/blackjack/room.php';
require_once __DIR__ . '/../assets/php/casino/baccarat/room.php';
require_once __DIR__ . '/../assets/php/games/chess/room.php';
require_once __DIR__ . '/../assets/php/games/matador/room.php';
require_once __DIR__ . '/../assets/php/games/uno/room.php';
require_once __DIR__ . '/../assets/php/adminLog.php';

const HBHUB_CRON_ATTACHMENT_ROUNDS = 1000; // the most sweeps of 50 attachments in one night, in case something keeps finding more

try {
    $db = hbHubDatabase();
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not connect to the database: ' . $e->getMessage() . "\n");
    exit(1);
}
$failed = false;

/**
 * Runs one job and prints what it did, or the error. $job returns the line to print.
 */
function hbHubCronRun(string $name, callable $job): void
{
    global $failed;
    try {
        echo $name . ': ' . $job() . "\n";
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, $name . ' failed: ' . $e->getMessage() . "\n");
    }
}

hbHubCronRun('Attachments', static function () use ($db): string {
    $removed = 0;
    for ($round = 0; $round < HBHUB_CRON_ATTACHMENT_ROUNDS; $round++) {
        $swept = hbHubChatAttachmentsSweep($db);
        $removed += $swept;
        if ($swept === 0) {
            break;
        }
    }
    return $removed . ' expired removed';
});

hbHubCronRun('Game rooms', static function () use ($db): string {
    hbHubBlackjackSweep($db);
    hbHubBaccaratSweep($db);
    hbHubChessSweep($db);
    hbHubMatadorSweep($db);
    hbHubUnoSweep($db);
    return 'tidied';
});

hbHubCronRun('GIF cache', static function () use ($db): string {
    return $db->exec('DELETE FROM `HBHub-GifCache` WHERE gifCacheExpiresTimestamp <= UTC_TIMESTAMP()') . ' expired removed';
});

hbHubCronRun('Admin log', static function () use ($db): string {
    return adminLogSweep($db) . ' old rows removed';
});

exit($failed ? 1 : 0);
