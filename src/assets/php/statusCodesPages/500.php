<?php
// 500 Internal Server Error
if (!headers_sent()) {
    http_response_code(500);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Noget gik galt - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">500</p>
        <h1>Noget gik galt</h1>
        <p class="status-message">Noget gik galt hos os. Prøv igen senere.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
