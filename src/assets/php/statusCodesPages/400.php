<?php
// 400 Bad Request
if (!headers_sent()) {
    http_response_code(400);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Ugyldig forespørgsel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">400</p>
        <h1>Ugyldig forespørgsel</h1>
        <p class="status-message">Serveren kunne ikke forstå forespørgslen. Tjek adressen, og prøv igen.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
