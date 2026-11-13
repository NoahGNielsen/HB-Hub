<?php
// The guest Slots' spins (/play/slots/spin), for slots.js on /play/slots - the same as gambling/slots/spin for
// members, by the same rules (hbHubSlotsResolve() in assets/php/casino/slots/slots.php), but the play chips come and
// go in a sealed token, {chips}, kept only in the page (see assets/php/guestGames/guestGames.php). Nothing is stored
// or logged. POST only:
//   action=spin, bet and token (none on the page's first spin: a new stack of play chips). slots.js sends its request
//   id too, which isn't needed here: a spin sent again comes with the token from before it, so it's just that spin.
// Answers like the members' version, {chips, spin: {stops, window, bet, payout, net, lines}}, plus "token" for the next
// spin, and "notice" and "refilledChips" when the guest ran out and got a new stack (hbHubGuestCasinoRefill()).
// Errors: 400 {"error": "unknownAction"} and {"error": "badBet"}, and 409 {"error": "notEnoughChips", "chips": 1234}
// (with "token").
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/slots/slots.php';
hbHubGuestGameJson('slots'); // only while the casino is open to everyone, and only POST

if (($_POST['action'] ?? null) !== 'spin') {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

$state = ['chips' => HBHUB_GUEST_CASINO_CHIPS];
$saved = hbHubGuestOpen('slots', $_POST['token'] ?? null);
if (is_int($saved['chips'] ?? null) && $saved['chips'] >= 0) {
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
    http_response_code(409);
    echo json_encode(['error' => 'notEnoughChips', 'chips' => $state['chips'], 'token' => hbHubGuestSeal('slots', $state)]);
    exit;
}

$spin = hbHubSlotsResolve($bet);
$state['chips'] += $spin['net'];
$chipsAfter = $state['chips'];
$notice = hbHubGuestCasinoRefill($state, HBHUB_SLOTS_MIN_BET);
echo json_encode(['chips' => $chipsAfter, 'spin' => $spin, 'token' => hbHubGuestSeal('slots', $state)]
    + ($notice !== null ? ['notice' => $notice, 'refilledChips' => $state['chips']] : []));
