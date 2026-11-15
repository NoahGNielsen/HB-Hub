<?php
// The free games (/play): every game visitors can play without an account (HBHUB_GUEST_GAMES in
// assets/php/guestGames/guestGames.php), for search engines and anyone who wants to try HB Hub first. Open to
// everyone, logged in or not (a member who opens a game from here is sent to the normal version of it), and built
// like the docs and legal pages: the frontpage's top bar and a dark hero (legal.css), then a card per game.
// A game whose section (games_access / casino_access) isn't open to everyone isn't listed. In Danish, like every public
// page.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';

$playGames = array_filter(HBHUB_GUEST_GAMES, 'hbHubGuestGameIsOpen');
$playKinds = ['games' => 'Spil', 'casino' => 'Casino, legechips'];

// The list as structured data for search engines: an ItemList of the games' pages
$playStructuredData = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => 'Gratis onlinespil på HB Hub',
    'itemListElement' => array_map(static fn (int $index, array $game): array => [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'url' => HBHUB_GUEST_SITE . $game['path'],
        'name' => $game['name'],
    ], array_keys(array_values($playGames)), array_values($playGames)),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php
    $headerHomeNav = true;
    $headerLocale = 'da_DK';
    $headerKeywords = ['gratis spil', 'gratis onlinespil', 'browserspil', 'uden login', 'free online games', ...array_column($playGames, 'name')];
    include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php';
    ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <link rel="stylesheet" href="/assets/css/guestGames.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/guestGames.css') ?>">
    <title>Gratis onlinespil - uden login | HB Hub</title>
    <meta name="description" content="Gratis browserspil fra HB Hub på HANSENBERG: Wordle på dansk og engelsk, et idle clicker-spil, Minesweeper, Sudoku, og Blackjack, Baccarat, Roulette og Slots med legechips. Uden login.">
    <script type="application/ld+json"><?= $playStructuredData ?></script>
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <header class="legal-hero">
            <div class="legal-hero-inner">
                <p class="home-kicker legal-kicker">Spil gratis</p>
                <h1>Gratis onlinespil, uden login</h1>
                <p class="legal-intro">
                    Prøv HB Hubs spil direkte i din browser, på en telefon, tablet eller computer. Ingen konto, ingen download,
                    og casinospillene bruger legechips, aldrig rigtige penge.
                </p>
            </div>
        </header>

        <div class="guest-hub">
            <?php if ($playGames === []): ?>
                <p class="guest-hub-text">Der er ingen gratis spil at spille lige nu. Kom tilbage senere.</p>
            <?php else: ?>
                <ul class="guest-hub-games">
                    <?php foreach ($playGames as $playGame): ?>
                        <li class="guest-hub-game">
                            <p class="guest-hub-kind"><?= $playKinds[$playGame['section']] ?></p>
                            <h2><?= htmlspecialchars($playGame['name']) ?></h2>
                            <p><?= htmlspecialchars($playGame['card']) ?></p>
                            <a class="home-btn home-btn-primary" href="<?= htmlspecialchars($playGame['path']) ?>">Spil <?= htmlspecialchars($playGame['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <section class="guest-hub-text" aria-labelledby="guest-hub-account">
                <h2 id="guest-hub-account">Vil du beholde dine fremskridt?</h2>
                <p>
                    Som gæst bliver dine spil af Wordle, Ore Mining Inc. og Sudoku gemt i din browser, og Minesweeper og
                    casinoets legechips starter forfra hver gang. Opret en gratis HB Hub-konto for at beholde din Wordle-stime og
                    statistik, gemme dit mineselskab på HB Hub, få dine tider i Minesweeper og Sudoku på ranglisten, få dine egne
                    chips hver dag, spille ved borde med venner og komme på ranglisterne. Det, du har spillet som gæst i
                    Wordle og Ore Mining Inc., kommer med dig.
                </p>
                <p>
                    HB Hub er også en chat for skolen, med flere spil at spille med venner, som Matador, skak og
                    UNO. <a href="/onboarding">Opret en konto</a>, <a href="/login">log ind</a> eller <a href="/">læs mere om HB Hub</a>.
                </p>
            </section>
        </div>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
