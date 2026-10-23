<?php
// Games home (the Games section): the games to play. Games use their own money inside each game, not the casino's chips.
// The pages share the casino's look (casino.css) for cards, buttons and forms, with games.css on top.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
    <title>Games - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Games page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino games">
        <h1>Games</h1>
        <p class="casino-intro">Play with your friends on the HB Hub.</p>

        <section class="casino-games" aria-labelledby="games-list-title">
            <h2 id="games-list-title" class="games-visually-hidden">Games</h2>
            <ul class="casino-game-list">
                <li>
                    <a class="casino-game" href="/games/matador/">
                        <span class="casino-game-name">Matador</span>
                        <span class="casino-game-text">The classic Danish board game. Buy streets in Copenhagen, build houses and hotels, and don't go bankrupt. 2 to 4 players.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game" href="/games/chess/">
                        <span class="casino-game-name">Chess</span>
                        <span class="casino-game-text">Play chess against your friends, with or without a clock. Draw offers, rematches and a leaderboard. 2 players.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game" href="/games/uno/">
                        <span class="casino-game-name">UNO</span>
                        <span class="casino-game-text">The card game: match the colour or the number, hit your friends with Draw Fours, and don't forget to call UNO! 2 to 6 players.</span>
                        <span class="casino-game-badge">Beta</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game" href="/games/wordle/">
                        <span class="casino-game-name">Wordle</span>
                        <span class="casino-game-text">Guess the daily five-letter word in six tries, in English and in Danish. Keep your streak going and climb the leaderboards.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game" href="/games/space-ore-miner/">
                        <span class="casino-game-name">Space Ore Miner</span>
                        <span class="casino-game-text">Click an asteroid for ore, buy machines that mine for you, catch Golden Comets and Warp Jump to new galaxies. A clicker game with a leaderboard.</span>
                        <span class="casino-game-badge">Beta</span>
                    </a>
                </li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
