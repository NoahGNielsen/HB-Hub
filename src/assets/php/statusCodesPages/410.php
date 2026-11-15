<?php
// 410 Gone
if (!headers_sent()) {
    http_response_code(410);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Siden er fjernet - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">410</p>
        <h1>Siden er fjernet</h1>
        <p class="status-message">Denne side er fjernet for altid og kommer ikke tilbage.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
