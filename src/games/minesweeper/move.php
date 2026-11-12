<?php
// Minesweeper's JSON, for the game in the frame (assets/minesweeper/js/main.js, on frame.php). POST only:
//   action=open, row and col (the square, from 0 at the top left), and either game (the id the first answer gave) or,
//   for the first click of a new game, size (9, 16 or 30), which starts it and places the mines.
// Answers with what opened (hbHubMinesweeperOpen() in assets/php/games/minesweeper/minesweeper.php):
//   {"game": id, "result": 0-3, "cells": [[row, col, number], ...]} (number -1 for a mine), and for a win also "ms",
//   "best" and "isBest". Errors:
// - 400 {"error": "badRequest"}: no such action, size or square.
// - 404 {"error": "notFound"}: not one of the user's games.
// - 409 {"error": "gameOver"}: the game is over already, like when another tab started a new one.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/minesweeper.php';
hbHubGamesJson($hbHubUser); // the JSON headers, and blocked in settings or not open yet

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodNotAllowed']);
    exit;
}

$gameId = null;
if (isset($_POST['game'])) {
    $gameId = filter_var($_POST['game'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}
if (($_POST['action'] ?? null) !== 'open' || $gameId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$view = hbHubMinesweeperOpen(hbHubDatabase(), (int) $hbHubUser['userId'], $gameId, $_POST['size'] ?? null,
    $_POST['row'] ?? null, $_POST['col'] ?? null);
if (is_string($view)) {
    http_response_code(match ($view) {
        'notFound' => 404,
        'gameOver' => 409,
        default => 400,
    });
    echo json_encode(['error' => $view]);
    exit;
}
echo json_encode($view);
