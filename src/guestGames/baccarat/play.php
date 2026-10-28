<?php
// The guest Baccarat table's JSON (/play/baccarat/play), for baccaratTable.js on /play/baccarat - the same as
// gambling/baccarat/play for a members' solo table, but the table and the play chips come and go as a sealed token,
// kept only in the page (assets/php/casino/baccarat/guest.php). Nothing is stored. POST only, as each request carries
// the token (none on the page's first: a new table with a new stack of play chips):
//   action=poll (what's new: the table moves on for the timers that ran out, like the next card being turned over),
//   or bet (bets[spot]=chips for each spot bet on, dealt straight away).
// Answers with the whole table every time, like the members' version ("v", "chips" and "table"), plus "token" for the
// next request, "error" (a message to show) when the bets couldn't be placed, and "notice" when the guest ran out of
// chips and got a new stack. 400 {"error": "unknownAction"} for anything else.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/guest.php';
hbHubGuestGameJson('baccarat'); // only while the casino is open to everyone, and only POST

$now = hbHubBaccaratNow();
$state = hbHubBaccaratGuestState($_POST['token'] ?? null);
hbHubBaccaratGuestAdvance($state, $now);

$action = $_POST['action'] ?? 'poll';
$error = match ($action) {
    'poll' => null,
    'bet' => hbHubBaccaratGuestBet($state, $_POST['bets'] ?? null, $now),
    default => false,
};
if ($error === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Between hands, a guest who can't afford the smallest bet any more gets a new stack
$notice = in_array($state['phase'], ['waiting', 'results'], true) ? hbHubGuestCasinoRefill($state, HBHUB_BACCARAT_SOLO_MIN_BET) : null;

// The version is the time: the page sends one request at a time, so a newer answer always has a later one
$response = ['v' => (string) $now] + hbHubBaccaratGuestView($state, $now) + ['token' => hbHubGuestSeal('baccarat', $state)];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($notice !== null) {
    $response['notice'] = $notice;
}
echo json_encode($response);
