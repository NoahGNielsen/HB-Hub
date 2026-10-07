<?php
// Shown by hbHubBlockPage() (block.php) instead of Gambling or Games while the user has them blocked.
// Expects $blockSection (the page's name) and $blockIsSchool (blocked for school hours, not all the time).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <title><?= htmlspecialchars($blockSection) ?> - HB Hub</title>
    <meta name="description" content="<?= htmlspecialchars($blockSection) ?> page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="blocked">
        <h1>Gambling and Games are blocked</h1>
        <p>
            <?php if ($blockIsSchool): ?>
                You've blocked Gambling and Games during school hours, 8 AM to 3 PM on weekdays. It opens again at 3 PM.
            <?php else: ?>
                You've blocked Gambling and Games all the time.
            <?php endif; ?>
        </p>
        <a class="blocked-link" href="/otherPages/settings#block">Change this in settings</a>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
