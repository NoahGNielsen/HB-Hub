<?php
// The guest Minesweeper's JSON (/play/minesweeper/move), for the game in /play/minesweeper/frame
// (assets/minesweeper/js/main.js) - the same as games/minesweeper/move for members, but the board comes and goes as a
// sealed token, kept only in the frame's page, instead of being stored (hbHubMinesweeperGuestOpen() in
// assets/php/games/minesweeper/minesweeper.php). Nothing is stored. POST only:
//   action=open, row and col (the square, from 0 at the top left), and either token (from the answer before) or, for
//   the first click of a new game, size (9, 16 or 30), which starts it and places the mines.
// Answers {"result": 0-3, "cells": [[row, col, number], ...]} (number -1 for a mine), plus "token" for the next click
// while the game goes on, and "ms" (the time) for a win. Errors: 400 {"error": "badRequest"} (no such action, size or
// square) and 409 {"error": "expired"} (a token that can't be opened: the page starts a new game).
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/minesweeper.php';
hbHubGuestGameJson('minesweeper'); // only while the games are open to everyone, and only POST

if (($_POST['action'] ?? null) !== 'open') {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$view = hbHubMinesweeperGuestOpen($_POST['token'] ?? null, $_POST['size'] ?? null, $_POST['row'] ?? null, $_POST['col'] ?? null);
if (is_string($view)) {
    http_response_code($view === 'expired' ? 409 : 400);
    echo json_encode(['error' => $view]);
    exit;
}
echo json_encode($view);
