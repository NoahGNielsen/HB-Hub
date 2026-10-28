<?php
// The guest Blackjack table's JSON (/play/blackjack/play), for blackjackTable.js on /play/blackjack - the same as
// gambling/blackjack/play for a members' solo table, but the table and the play chips come and go as a sealed token,
// kept only in the page (assets/php/casino/blackjack/guest.php). Nothing is stored. POST only, as each request
// carries the token (none on the page's first: a new table with a new stack of play chips):
//   action=poll (what's new: the table moves on for the timers that ran out, like the dealer's cards), bet (amount),
//   insurance (take: 1 or 0), and hit, stand, double and split (turn: the turnId the table showed when it was clicked).
// Answers with the whole table every time, like the members' version ("v", "chips" and "table"), plus "token" for the
// next request, "error" (a message to show) when the action couldn't be done, and "notice" when the guest ran out of
// chips and got a new stack. 400 {"error": "unknownAction"} for anything else.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/blackjack/guest.php';
hbHubGuestGameJson('blackjack'); // only while the casino is open to everyone, and only POST

$now = hbHubBlackjackNow();
$state = hbHubBlackjackGuestState($_POST['token'] ?? null);
hbHubBlackjackGuestAdvance($state, $now);

$action = $_POST['action'] ?? 'poll';
$error = match ($action) {
    'poll' => null,
    'bet' => hbHubBlackjackGuestBet($state, $_POST['amount'] ?? null, $now),
    'insurance' => hbHubBlackjackGuestInsurance($state, ($_POST['take'] ?? null) === '1', $now),
    'hit', 'stand', 'double', 'split' => hbHubBlackjackGuestMove($state, $action, $_POST['turn'] ?? null, $now),
    default => false,
};
if ($error === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Between hands, a guest who can't afford the smallest bet any more gets a new stack
$notice = in_array($state['phase'], ['waiting', 'results'], true) ? hbHubGuestCasinoRefill($state, HBHUB_BLACKJACK_SOLO_MIN_BET) : null;

// The version is the time: the page sends one request at a time, so a newer answer always has a later one
$response = ['v' => (string) $now] + hbHubBlackjackGuestView($state, $now) + ['token' => hbHubGuestSeal('blackjack', $state)];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($notice !== null) {
    $response['notice'] = $notice;
}
echo json_encode($response);
