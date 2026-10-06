<?php
// Maintenance mode: while site_maintenance is on, every page that includes header.php shows the 503 page instead.
// Include it with require_once, so the 503 page (which also includes header.php) doesn't run the check again.
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
if (!filter_var($siteConfig['site_maintenance'], FILTER_VALIDATE_BOOLEAN)) {
    return;
}

// header.php is included inside <head>, so throw away what the page has written so far.
// That only works while it's still buffered (PHP's default output_buffering of 4096 bytes covers it).
while (ob_get_level() > 0) {
    ob_end_clean();
}
require __DIR__ . '/statusCodesPages/503.php';
exit;
