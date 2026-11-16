<?php
// The guest Minesweeper's JSON (/play/minesweeper/move), for the game in the frame on /play/minesweeper
// (assets/minesweeper/js/main.js): the same as games/minesweeper/move for members, but the game comes and goes as a
// sealed token instead of being stored (see assets/php/guestGames/guestGames.php and hbHubMinesweeperGuestOpen() in
// assets/php/games/minesweeper/minesweeper.php). The token is what the members' answers have as the game's id
// ("game"), so main.js plays both the same way and only keeps it in the page. POST only:
//   action=open, row and col, and either game (the token from the answer before) or, for the first click of a new
//   game, size (9, 16 or 30). Answers {"game": token, "result": 0-2, "cells": [[row, col, number], ...]}, and for a
//   win also "ms", with "best" null and "isBest" false (guests have no best times).
//   action=scripted and game: the game saw a click made by code. Nothing is stored for guests, so this only answers
//   {"game": token, "result": 5}, like the members' version.
// Errors like the members' version: 400 {"error": "badRequest"}, 404 {"error": "notFound"} (a token that can't be
// opened) and 409 {"error": "gameOver"}.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/minesweeper/minesweeper.php';
hbHubGuestGameJson('minesweeper'); // only while the games are open to everyone, and only POST

$action = $_POST['action'] ?? null;
$token = $_POST['game'] ?? null;
if (!in_array($action, ['open', 'scripted'], true) || ($action === 'scripted' && $token === null)) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$game = null;
if ($token !== null) {
    $game = hbHubGuestOpen('minesweeper', $token);
    if ($game === null) {
        http_response_code(404);
        echo json_encode(['error' => 'notFound']);
        exit;
    }
}

if ($action === 'scripted') {
    echo json_encode(['game' => $token, 'result' => HBHUB_MINESWEEPER_SCRIPTED]);
    exit;
}

$answer = hbHubMinesweeperGuestOpen($game, $_POST['size'] ?? null, $_POST['row'] ?? null, $_POST['col'] ?? null);
if (is_string($answer)) {
    http_response_code($answer === 'gameOver' ? 409 : 400);
    echo json_encode(['error' => $answer]);
    exit;
}
[$game, $view] = $answer;
echo json_encode(['game' => hbHubGuestSeal('minesweeper', $game)] + $view);
