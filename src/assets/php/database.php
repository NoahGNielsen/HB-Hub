<?php
// Database connection, using database_credentials from hbHubSiteConfig.php (one level above the web root).
// Include it with require_once and call hbHubDatabase() wherever the database is needed.

function hbHubDatabase(): PDO
{
    static $db = null; // one connection per request

    if ($db === null) {
        $siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
        $credentials = $siteConfig['database_credentials'];

        $db = new PDO(
            'mysql:host=' . $credentials['host'] . ';dbname=' . $credentials['name'] . ';charset=utf8mb4',
            $credentials['user'],
            $credentials['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $db;
}
