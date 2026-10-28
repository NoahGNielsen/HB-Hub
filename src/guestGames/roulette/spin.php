<?php
// The guest Roulette's spins (/play/roulette/spin), for roulette.js on /play/roulette - the same as
// gambling/roulette/spin for members, but with play chips that come and go in a sealed token, {chips}, kept only in
// the page (see assets/php/guestGames/guestGames.php). Nothing is stored. POST only:
//   bets={"17": 10, "red": 50} and token (none on the page's first spin: a new stack of play chips).
// Answers like the members' version (where the ball landed, what each winning bet paid and the chips after), plus
// "token" for the next spin, and "notice" when the guest ran out and got a new stack (hbHubGuestCasinoRefill()).
// Errors: 400 {"error": "badBets"}, and {"error": "notEnoughChips", "chips": 1234} (with "token").
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/guestGames/guestGames.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/roulette/roulette.php';
hbHubGuestGameJson('roulette'); // only while the casino is open to everyone, and only POST

$bets = hbHubRouletteCleanBets($_POST['bets'] ?? null);
if ($bets === null) {
    http_response_code(400);
    echo json_encode(['error' => 'badBets']);
    exit;
}

$state = ['chips' => HBHUB_GUEST_CASINO_CHIPS];
$saved = hbHubGuestOpen('roulette', $_POST['token'] ?? null);
if (is_int($saved['chips'] ?? null)) {
    $state['chips'] = $saved['chips'];
}

$wagered = array_sum($bets);
if ($state['chips'] < $wagered) {
    echo json_encode(['error' => 'notEnoughChips', 'chips' => $state['chips'], 'token' => hbHubGuestSeal('roulette', $state)]);
    exit;
}

$spin = hbHubRouletteResolve($bets);
$state['chips'] += $spin['net'];
$spin['chips'] = $state['chips'];
$spin['wins'] = (object) $spin['wins']; // always {}, like the members' version
$notice = hbHubGuestCasinoRefill($state, HBHUB_ROULETTE_BET_STEP);
echo json_encode($spin + ['token' => hbHubGuestSeal('roulette', $state)] + ($notice !== null ? ['notice' => $notice, 'refilledChips' => $state['chips']] : []));
