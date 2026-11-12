<?php
// Minesweeper itself, on its own page without HB Hub's side bar: games/minesweeper/ (index.php) shows it in a frame.
// It's the index.html of Nick Arocho's Minesweeper (https://github.com/nickarocho/minesweeper), whose code is in
// assets/minesweeper/ (main.js builds the board into #board, cell.js has the cells). Changed for HB Hub:
// - it needs a login and open Games, like every Games page, so the game can't be opened around them
// - the unused jQuery from code.jquery.com is left out (the CSP wouldn't load it anyway), and the bar at the bottom has
//   no Start button: its picture was the Windows logo
// - the hint also says a right click flags a cell (main.js)
// Nothing is saved: a game lives in the frame and is gone when it's closed.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
hbHubGamesPage($hbHubUser); // blocked in settings, or not open yet

$minesweeperAsset = static fn (string $path): string => '/assets/minesweeper/' . $path . '?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/minesweeper/' . $path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Minesweeper - HB Hub</title>
    <link rel="stylesheet" href="<?= $minesweeperAsset('css/main.css') ?>">
</head>
<body>
    <div class="flex-container">
        <div class="game-board">
            <br>
                <table id="board"></table>
        </div>
    </div>
        <section id="footer">
            <div id="size-btns">
                <div id="size-btns"><button id="size-9"><img src="/assets/minesweeper/images/easy.png" alt=""> Easy</button></div>
                <div id="size-btns"><button id="size-16"><img src="/assets/minesweeper/images/medium.png" alt=""> Medium</button></div>
                <div id="size-btns"><button id="size-30"><img src="/assets/minesweeper/images/hard.png" alt=""> Hard</button></div>
            </div>
            <div id="clock"><em>"Shift + Click"</em> or right click to toggle <img src="/assets/minesweeper/images/flag.png" alt="a flag"></div>
        </section>
    <script src="<?= $minesweeperAsset('js/cell.js') ?>"></script>
    <script src="<?= $minesweeperAsset('js/main.js') ?>"></script>
</body>
</html>
