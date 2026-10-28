<?php
// The free games (/play): every game visitors can play without an account (HBHUB_GUEST_GAMES in
// assets/php/guestGames/guestGames.php), for search engines and anyone who wants to try HB Hub first. Open to
// everyone, logged in or not (a member who opens a game from here is sent to the normal version of it), and built
// like the docs and legal pages: the frontpage's top bar and a dark hero (legal.css), then a card per game.
// A game whose section (games_access / casino_access) isn't open to everyone isn't listed.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';

$playGames = array_filter(HBHUB_GUEST_GAMES, 'hbHubGuestGameIsOpen');
$playKinds = ['games' => 'Game', 'casino' => 'Casino, play chips'];

// The list as structured data for search engines: an ItemList of the games' pages
$playStructuredData = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => 'Free online games on HB Hub',
    'itemListElement' => array_map(static fn (int $index, array $game): array => [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'url' => HBHUB_GUEST_SITE . $game['path'],
        'name' => $game['name'],
    ], array_keys(array_values($playGames)), array_values($playGames)),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/guestGames.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/guestGames.css') ?>">
    <title>Free Online Games - No Sign-Up Needed | HB Hub</title>
    <meta name="description" content="Play Wordle in English and Danish, the Ore Mining Inc. idle clicker, and Blackjack, Baccarat and Roulette with play chips. Free in your browser: no account, no download, no real money.">
    <script type="application/ld+json"><?= $playStructuredData ?></script>
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <header class="legal-hero">
            <div class="legal-hero-inner">
                <p class="home-kicker legal-kicker">Play free</p>
                <h1>Free online games, no sign-up</h1>
                <p class="legal-intro">
                    Try HB Hub's games right in your browser, on a phone, tablet or computer. No account, no download, and
                    the casino games use play chips, never real money.
                </p>
            </div>
        </header>

        <div class="guest-hub">
            <?php if ($playGames === []): ?>
                <p class="guest-hub-text">There are no free games to play right now. Please come back later.</p>
            <?php else: ?>
                <ul class="guest-hub-games">
                    <?php foreach ($playGames as $playGame): ?>
                        <li class="guest-hub-game">
                            <p class="guest-hub-kind"><?= $playKinds[$playGame['section']] ?></p>
                            <h2><?= htmlspecialchars($playGame['name']) ?></h2>
                            <p><?= htmlspecialchars($playGame['card']) ?></p>
                            <a class="home-btn home-btn-primary" href="<?= htmlspecialchars($playGame['path']) ?>">Play <?= htmlspecialchars($playGame['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <section class="guest-hub-text" aria-labelledby="guest-hub-account">
                <h2 id="guest-hub-account">Want to keep your progress?</h2>
                <p>
                    As a guest, your Wordle and Ore Mining Inc. games are kept in your browser, and the casino's play chips
                    start over every time. Make a free HB Hub account to keep your Wordle streak and stats, save your
                    mining company on HB Hub, get chips of your own every day, play at tables with friends, and get on the
                    leaderboards. What you played as a guest in Wordle and Ore Mining Inc. comes with you.
                </p>
                <p>
                    HB Hub is also a chat for the school, with more games to play with friends, like Matador, chess and
                    UNO. <a href="/onboarding">Sign up</a>, <a href="/login">log in</a> or <a href="/">read more about HB Hub</a>.
                </p>
            </section>
        </div>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
