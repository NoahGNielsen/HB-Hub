<?php
// The guest version of a game's <head> bits (see guestGames.php), included by the game's page in place of its own
// <title>, robots and description, after header.php (which the page includes with $headerHomeNav = true, for the
// frontpage's top bar): the top bar's and the guest bits' stylesheets, the title and description for search engines,
// and the game's structured data. Unlike the normal games, it's indexed. Needs $guestGame.
?>
<link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
<link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/guestGames.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/guestGames.css') ?>">
<title><?= htmlspecialchars($guestGame['title']) ?></title>
<meta name="description" content="<?= htmlspecialchars($guestGame['description']) ?>">
<script type="application/ld+json"><?= hbHubGuestStructuredData($guestGame) ?></script>
