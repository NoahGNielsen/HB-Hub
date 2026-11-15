<?php
// 401 Unauthorized
if (!headers_sent()) {
    http_response_code(401);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Ikke logget ind - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">401</p>
        <h1>Ikke logget ind</h1>
        <p class="status-message">Du skal logge ind for at se denne side.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
