<?php
// 502 Bad Gateway
if (!headers_sent()) {
    http_response_code(502);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Ugyldigt svar fra serveren - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">502</p>
        <h1>Ugyldigt svar fra serveren</h1>
        <p class="status-message">Serveren fik et ugyldigt svar, mens den behandlede din forespørgsel. Prøv igen.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
