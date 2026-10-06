<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://forum.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <title>Homepage - HB Hub</title>
    <description>Homepage for the HB Hub</description>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main>
        <h1>HB Hub</h1>
        <p>Welcome to the HB Hub!</p>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>