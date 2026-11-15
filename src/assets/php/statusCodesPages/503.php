<?php
// 503 Service Unavailable - also shown by maintenance.php while the site is in maintenance mode
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
$siteMaintenance = filter_var($siteConfig['site_maintenance'], FILTER_VALIDATE_BOOLEAN);

if (!headers_sent()) {
    http_response_code(503);
    header('Retry-After: 3600');
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title><?= $siteMaintenance ? 'Lukket for vedligeholdelse' : 'Tjenesten er utilgængelig' ?> - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">503</p>
        <h1><?= $siteMaintenance ? 'Lukket for vedligeholdelse' : 'Tjenesten er utilgængelig' ?></h1>
        <p class="status-message"><?= htmlspecialchars($siteMaintenance ? $siteConfig['site_maintenance_message'] : 'Siden er midlertidigt utilgængelig. Prøv igen senere.') ?></p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
