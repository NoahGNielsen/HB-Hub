<?php
// Games home (the Games section): the games to play. Games use their own money inside each game, not the casino's chips.
// The pages share the casino's look (casino.css) for cards, buttons and forms, with games.css on top. Each game's card
// has a coloured cover with its name and a drawing of it (casino-game-<name> in casino.css).
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
        <div class="casino-hero">
            <p class="casino-kicker">Play with your friends</p>
            <h1>Games</h1>
        </div>
        <p class="casino-intro">Board games, card games and a few to play on your own, all on the HB Hub.</p>

        <section class="casino-games" aria-labelledby="games-list-title">
            <h2 id="games-list-title" class="games-visually-hidden">Games</h2>
            <ul class="casino-game-list">
                <li>
                    <a class="casino-game casino-game-matador" href="/games/matador/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">2 to 4 players</span>
                            <span class="casino-game-name">Matador</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <path d="M8 30L24 16l16 14v24H8z"/>
                                <rect class="casino-game-art-mark" x="19" y="40" width="10" height="14"/>
                                <rect x="34" y="26" width="24" height="24" rx="5" transform="rotate(12 46 38)"/>
                                <circle class="casino-game-art-mark" cx="41" cy="33" r="2.6"/>
                                <circle class="casino-game-art-mark" cx="47" cy="39" r="2.6"/>
                                <circle class="casino-game-art-mark" cx="52" cy="45" r="2.6"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">The classic Danish board game. Buy streets in Copenhagen, build houses and hotels, and don't go bankrupt.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-chess" href="/games/chess/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">2 players</span>
                            <span class="casino-game-name">Chess</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <path d="M22 54h28v-6l-4-3c-1-6-1-12 2-17l-6-12c-4-2-9-2-12 2l-8 13 4 4 7-4c1 5-2 9-6 14l-5 3z"/>
                                <circle class="casino-game-art-mark" cx="35" cy="20" r="2.2"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Play chess against your friends, with or without a clock. Draw offers, rematches and a leaderboard.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-uno" href="/games/uno/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">2 to 6 players</span>
                            <span class="casino-game-name">UNO <span class="casino-game-badge">Beta</span></span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect x="14" y="8" width="36" height="50" rx="5" transform="rotate(14 32 33)"/>
                                <ellipse class="casino-game-art-mark" cx="32" cy="33" rx="12" ry="19" transform="rotate(44 32 33)"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Match the colour or the number, hit your friends with Draw Fours, and don't forget to call UNO!</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-wordle" href="/games/wordle/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">A new word daily</span>
                            <span class="casino-game-name">Wordle</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <rect class="casino-game-art-mark" x="6" y="12" width="15" height="15" rx="3"/>
                                <rect x="24.5" y="12" width="15" height="15" rx="3"/>
                                <rect class="casino-game-art-mark" x="43" y="12" width="15" height="15" rx="3"/>
                                <rect x="6" y="31" width="15" height="15" rx="3"/>
                                <rect class="casino-game-art-mark" x="24.5" y="31" width="15" height="15" rx="3"/>
                                <rect x="43" y="31" width="15" height="15" rx="3"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Guess the daily five-letter word in six tries, in English and in Danish. Keep your streak going and climb the leaderboards.</span>
                    </a>
                </li>
                <li>
                    <a class="casino-game casino-game-miner" href="/games/ore-mining-inc/">
                        <span class="casino-game-cover">
                            <span class="casino-game-kicker">Clicker game</span>
                            <span class="casino-game-name">Ore Mining Inc. <span class="casino-game-badge">Beta</span></span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <path d="M30 8l16 6 10 16-4 18-16 10-18-4L8 38l4-18z"/>
                                <circle class="casino-game-art-mark" cx="24" cy="24" r="5"/>
                                <circle class="casino-game-art-mark" cx="40" cy="40" r="7"/>
                                <circle class="casino-game-art-mark" cx="42" cy="22" r="3"/>
                            </svg>
                        </span>
                        <span class="casino-game-text">Click an asteroid for ore, buy machines that mine for you, catch Golden Comets and Warp Jump to new galaxies.</span>
                    </a>
                </li>
            </ul>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
