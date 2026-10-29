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
    <link rel="stylesheet" href="/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <link rel="stylesheet" href="/assets/css/games.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/games.css') ?>">
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
                                <!-- A queen with a jewel in her crown, and a pawn beside her -->
                                <path d="M8 56H36V51H33L29 32H32V28H28L33 14L27 20L22 9L17 20L11 14L16 28H12V32H15L11 51H8Z"/>
                                <circle cx="11" cy="13" r="2.4"/>
                                <circle cx="22" cy="8" r="2.4"/>
                                <circle cx="33" cy="13" r="2.4"/>
                                <circle class="casino-game-art-mark" cx="22" cy="23" r="2"/>
                                <path d="M38 56H58V51H55C54 45 52 41 52 38H55V35H41V38H44C44 41 42 45 41 51H38Z"/>
                                <circle cx="48" cy="28" r="6.5"/>
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
                                <!-- A fanned-out hand of three UNO cards: a green 3, a yellow 5 and a red 7 in front. Each has the
                                     white border and its number in the corner; the front one also has the tilted white oval with
                                     the big number in it, and the upside-down number in the bottom corner -->
                                <g transform="rotate(-20 32 60)">
                                    <rect x="19" y="6" width="26" height="40" rx="4"/>
                                    <rect class="casino-game-art-green" x="21.5" y="8.5" width="21" height="35" rx="2.5"/>
                                    <text class="casino-game-art-corner" x="23.5" y="15.5">3</text>
                                </g>
                                <g>
                                    <rect x="19" y="6" width="26" height="40" rx="4"/>
                                    <rect class="casino-game-art-mark" x="21.5" y="8.5" width="21" height="35" rx="2.5"/>
                                    <text class="casino-game-art-corner" x="23.5" y="15.5">5</text>
                                </g>
                                <g transform="rotate(20 32 60)">
                                    <rect x="19" y="6" width="26" height="40" rx="4"/>
                                    <rect class="casino-game-art-red" x="21.5" y="8.5" width="21" height="35" rx="2.5"/>
                                    <ellipse cx="32" cy="26" rx="7.5" ry="13" transform="rotate(35 32 26)"/>
                                    <text class="casino-game-art-number" x="32" y="31.5" text-anchor="middle">7</text>
                                    <text class="casino-game-art-corner" x="23.5" y="15.5">7</text>
                                    <text class="casino-game-art-corner" transform="translate(40.5 36.5) rotate(180)">7</text>
                                </g>
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
                            <span class="casino-game-name">Ore Mining Inc.</span>
                            <svg class="casino-game-art" viewBox="0 0 64 64" aria-hidden="true">
                                <!-- A cratered asteroid with gold ore in it, a pickaxe biting into it and a Golden Comet flying past -->
                                <path class="casino-game-art-tail" d="M10.5 15.5L2 2L15.5 10.5Z"/>
                                <circle class="casino-game-art-mark" cx="13" cy="13" r="3.5"/>
                                <path d="M18 30C20 20 32 16 40 19C48 21 56 26 55 37C55 47 47 56 36 56C26 57 15 51 14 41C13 36 16 33 18 30Z"/>
                                <circle class="casino-game-art-crater" cx="25" cy="46" r="4.5"/>
                                <circle class="casino-game-art-crater" cx="48" cy="40" r="3"/>
                                <circle class="casino-game-art-crater" cx="23" cy="33" r="2.6"/>
                                <path class="casino-game-art-mark" d="M33 30L38 27L42 31L40 36L34 37Z"/>
                                <path class="casino-game-art-mark" d="M36 44L40 42L42 46L38 49L35 47Z"/>
                                <g transform="translate(32 16) rotate(-110)">
                                    <rect class="casino-game-art-handle" x="-1.6" y="0" width="3.2" height="26" rx="1.6"/>
                                    <path class="casino-game-art-metal" d="M-13 6Q0 -8 13 6L11 7Q0 -2 -11 7Z"/>
                                </g>
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
