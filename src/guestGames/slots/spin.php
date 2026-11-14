<?php
// The guest Slots' spins (/play/slots/spin), for slots.js on /play/slots: the same machine and rules as the members'
// gambling/slots/spin (hbHubSlotsResolve() in assets/php/casino/slots/slots.php), but with play chips that come and go
// in a sealed token, {chips}, kept only in the page (see assets/php/guestGames/guestGames.php). Nothing is stored or
// logged. POST only:
//   action=spin, bet, request (slots.js's id for the spin, only checked for its shape here) and token (none on the
//   page's first spin: a new stack of play chips).
// Answers like the members' version, {chips, spin: {id: 0, stops, window, bet, payout, net, lines}}, plus "token" for
// the next spin, and "notice" with "refilledChips" when the guest ran out and got a new stack (hbHubGuestCasinoRefill()).
// A spin sent again with the same token (slots.js does when an answer got lost) is just spun again: they're play chips.
// Errors: 400 {"error": "badRequest"} or {"error": "badBet"}, and {"error": "notEnoughChips", "chips": 1234} (with "token").
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';
hbHubGuestGameJson('slots'); // only while the casino is open to everyone, and only POST

$requestId = $_POST['request'] ?? null;
if (($_POST['action'] ?? null) !== 'spin' || !is_string($requestId) || preg_match(HBHUB_SLOTS_REQUEST_PATTERN, $requestId) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'badRequest']);
    exit;
}

$state = ['chips' => HBHUB_GUEST_CASINO_CHIPS];
$saved = hbHubGuestOpen('slots', $_POST['token'] ?? null);
if (is_int($saved['chips'] ?? null)) {
    $state['chips'] = $saved['chips'];
}

$betText = $_POST['bet'] ?? null;
$bet = is_string($betText) && preg_match('~^[1-9][0-9]{0,11}$~', $betText) === 1 ? (int) $betText : null;
if (!hbHubSlotsIsBet($bet, $state['chips'])) {
    http_response_code(400);
    echo json_encode(['error' => 'badBet']);
    exit;
}
if ($state['chips'] < $bet) {
    echo json_encode(['error' => 'notEnoughChips', 'chips' => $state['chips'], 'token' => hbHubGuestSeal('slots', $state)]);
    exit;
}

$spin = hbHubSlotsResolve($bet);
$state['chips'] += $spin['net'];
$chips = $state['chips'];
$notice = hbHubGuestCasinoRefill($state, HBHUB_SLOTS_MIN_BET);
echo json_encode(['chips' => $chips, 'spin' => ['id' => 0] + $spin, 'token' => hbHubGuestSeal('slots', $state)]
    + ($notice !== null ? ['notice' => $notice, 'refilledChips' => $state['chips']] : []));
