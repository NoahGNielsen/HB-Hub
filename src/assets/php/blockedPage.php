<?php
// Shown by hbHubBlockPage() (block.php) instead of Gambling or Games while they're blocked for the user.
// Expects $blockSection (the page's name), $blockIsAdmin (blocked by a site admin, which settings can't change) and
// $blockIsSchool (blocked for school hours, not all the time).
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
            <?php if ($blockIsAdmin): ?>
                A site admin has blocked Gambling and Games for your account. Only a site admin can lift it.
            <?php elseif ($blockIsSchool): ?>
                You've blocked Gambling and Games during school hours, 8 AM to 3 PM on weekdays. It opens again at 3 PM.
            <?php else: ?>
                You've blocked Gambling and Games all the time.
            <?php endif; ?>
        </p>
        <?php if (!$blockIsAdmin): ?>
            <a class="blocked-link" href="/otherPages/settings#block">Change this in settings</a>
        <?php endif; ?>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
