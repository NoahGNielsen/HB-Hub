<?php
// 403 Forbidden
if (!headers_sent()) {
    http_response_code(403);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/statusCodePage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/statusCodePage.css') ?>">
    <title>Adgang nægtet - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main class="status-page">
        <p class="status-code">403</p>
        <h1>Adgang nægtet</h1>
        <p class="status-message">Du har ikke tilladelse til at se denne side.<br>Enten er du ikke logget ind, eller din konto har ikke de nødvendige rettigheder.</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
