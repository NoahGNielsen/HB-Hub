<?php
// Baccarat for guests (/play/baccarat, see assets/php/guestGames/guestGames.php): a solo table with play chips and no
// database. The whole table - the shoe, the hand being dealt and the play chips - is the guest's sealed token, which
// every request brings along and gets back, so the cards still to be turned over stay hidden from the browser.
//
// The state is round.php's (phase, deadline, timer, shoe, shuffled, bets, betsRoundId and round, with the guest as the
// only player), plus chips (their play chips) and hands (how many hands have been dealt, for the round ids). That way
// hbHubBaccaratTableView() shows it and baccaratTable.js draws it just like a members' solo table, and round.php's
// functions are used wherever they don't touch the database. The ones that do (betting, dealing and the payouts, which
// take and pay chips) have guest versions here, doing the same with the chips in the state. They play by the same
// rules (rules.php, cards.php), so change them together with round.php's.
require_once __DIR__ . '/room.php';
require_once __DIR__ . '/../../guestGames/guestGames.php';

const HBHUB_BACCARAT_GUEST_ID = 0; // the guest's userId in the state: they're the only one at the table
const HBHUB_BACCARAT_GUEST_SEATS = [['userId' => HBHUB_BACCARAT_GUEST_ID, 'seat' => 1, 'away' => false, 'sittingOut' => false]];

/**
 * The guest's table from their token, or a new one with a new stack of play chips when there's none (the page was
 * just opened) or it can't be read.
 */
function hbHubBaccaratGuestState(mixed $token): array
{
    $state = hbHubGuestOpen('baccarat', $token);
    if (!is_array($state['shoe'] ?? null) || !is_int($state['chips'] ?? null) || !isset(HBHUB_BACCARAT_PHASES[$state['phase'] ?? ''])) {
        $state = hbHubBaccaratNewState() + ['chips' => HBHUB_GUEST_CASINO_CHIPS, 'hands' => 0];
    }
    return $state;
}

/**
 * Moves the guest's table on for every timer that ran out by $now, like hbHubBaccaratAdvance(): the cards are turned
 * over one at a time, and the results go.
 */
function hbHubBaccaratGuestAdvance(array &$state, int $now): void
{
    for ($steps = 0; $steps < HBHUB_BACCARAT_STEPS_MAX && $state['deadline'] !== null && $state['deadline'] <= $now; $steps++) {
        if ($state['phase'] === 'dealing') {
            hbHubBaccaratGuestTurnCard($state, $now);
        } else { // results: the hand stays on the table until the next one
            hbHubBaccaratSetPhase($state, 'waiting', $now, null);
        }
    }
}

/**
 * Places the guest's bets ($bets as sent, {spot: chips as a string of digits}) and deals straight away, as they're
 * the only one who has to bet. Returns null when it's dealt, otherwise why not. Like hbHubBaccaratBet().
 */
function hbHubBaccaratGuestBet(array &$state, mixed $bets, int $now): ?string
{
    if (!in_array($state['phase'], ['waiting', 'results'], true)) {
        return 'Wait for this hand to finish before betting on the next one.';
    }
    $spots = hbHubBaccaratCleanBets($bets);
    if ($spots === null) {
        return 'Bets go in steps of ' . HBHUB_BACCARAT_BET_STEP . '.';
    }
    if (isset($spots['player'], $spots['banker'])) {
        return 'Bet on Player or Banker, not both.';
    }
    $total = array_sum($spots);
    if ($total < HBHUB_BACCARAT_SOLO_MIN_BET) {
        return 'Your bets together have to be at least ' . number_format(HBHUB_BACCARAT_SOLO_MIN_BET) . ' chips at this table.';
    }
    if ($state['chips'] < $total) {
        return 'You don\'t have enough chips for those bets.';
    }

    $state['chips'] -= $total;
    $state['hands']++;
    $state['bets'] = [['userId' => HBHUB_BACCARAT_GUEST_ID, 'seat' => 1, 'spots' => $spots]];
    $state['betsRoundId'] = $state['hands'];
    hbHubBaccaratGuestDeal($state, $now);
    return null;
}

/**
 * Deals the hand, like hbHubBaccaratDeal(): all of it now, by the rules, to be turned over a card at a time.
 */
function hbHubBaccaratGuestDeal(array &$state, int $now): void
{
    $spots = $state['bets'][0]['spots'];
    $coup = hbHubBaccaratCoup($state['shoe']);
    $state['round'] = [
        'id' => $state['betsRoundId'],
        'player' => $coup['player'],
        'banker' => $coup['banker'],
        'order' => $coup['order'],
        'shown' => 0,
        'newShoe' => $state['shuffled'],
        'settled' => false,
        'winner' => null,
        'playerPair' => null,
        'bankerPair' => null,
        'players' => [[
            'userId' => HBHUB_BACCARAT_GUEST_ID,
            'seat' => 1,
            'spots' => $spots,
            'wagered' => array_sum($spots),
            'payouts' => null,
            'payout' => null,
            'net' => null,
        ]],
    ];
    $state['shuffled'] = false;
    $state['bets'] = [];
    $state['betsRoundId'] = null;
    hbHubBaccaratSetPhase($state, 'dealing', $now, HBHUB_BACCARAT_DEAL_MS);
}

/**
 * Turns the next card over, like hbHubBaccaratTurnCard(). After the last one the hand is paid out.
 */
function hbHubBaccaratGuestTurnCard(array &$state, int $now): void
{
    $state['round']['shown']++;
    if ($state['round']['shown'] >= count($state['round']['order'])) {
        hbHubBaccaratGuestSettle($state, $now);
        return;
    }
    hbHubBaccaratSetPhase($state, 'dealing', $now, $state['round']['shown'] >= 4 ? HBHUB_BACCARAT_DRAW_MS : HBHUB_BACCARAT_DEAL_MS);
}

/**
 * Pays out the hand into the guest's play chips with hbHubBaccaratSpotPayout(), like hbHubBaccaratSettle(), and
 * shuffles a new shoe once 75% of it has been dealt.
 */
function hbHubBaccaratGuestSettle(array &$state, int $now): void
{
    $round = &$state['round'];
    $round['shown'] = count($round['order']);
    $round['winner'] = hbHubBaccaratWinner($round['player'], $round['banker']);
    $round['playerPair'] = hbHubBaccaratIsPair($round['player']);
    $round['bankerPair'] = hbHubBaccaratIsPair($round['banker']);

    $player = &$round['players'][0];
    $player['payouts'] = [];
    foreach ($player['spots'] as $spot => $amount) {
        $player['payouts'][$spot] = hbHubBaccaratSpotPayout($spot, $amount, $round);
    }
    $player['payout'] = array_sum($player['payouts']);
    $player['net'] = $player['payout'] - $player['wagered'];
    $state['chips'] += $player['payout'];
    $round['settled'] = true;
    unset($player, $round);

    if (count($state['shoe']) <= HBHUB_BACCARAT_RESHUFFLE_LEFT) {
        $state['shoe'] = hbHubBaccaratNewShoe();
        $state['shuffled'] = true;
    }
    hbHubBaccaratSetPhase($state, 'results', $now, HBHUB_BACCARAT_RESULTS_MS);
}

/**
 * The answer for baccaratTable.js, in the shape of gambling/baccarat/play's for a solo table: the play chips, and the
 * table as the guest may see it (without the shoe, and only the cards that are turned over).
 */
function hbHubBaccaratGuestView(array $state, int $now): array
{
    return [
        'chips' => $state['chips'],
        'table' => [
            'room' => ['id' => 0, 'name' => 'Baccarat', 'code' => '', 'type' => 'solo', 'minBet' => HBHUB_BACCARAT_SOLO_MIN_BET, 'seats' => 1, 'players' => 1],
        ] + hbHubBaccaratTableView($state, HBHUB_BACCARAT_GUEST_SEATS, [HBHUB_BACCARAT_GUEST_ID => ['name' => 'You', 'avatar' => null]],
            HBHUB_BACCARAT_GUEST_ID, null, $now),
    ];
}
