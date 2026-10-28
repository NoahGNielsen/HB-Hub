<?php
// Blackjack for guests (/play/blackjack, see assets/php/guestGames/guestGames.php): a solo table with play chips and no
// database. The whole table - the shoe, the hand and the play chips - is the guest's sealed token, which every request
// brings along and gets back, so the shoe and the hole card stay hidden from the browser all the same.
//
// The state is round.php's (phase, deadline, timer, shoe, bets, betsRoundId, round and turnId, with the guest as the
// only player), plus chips (their play chips) and hands (how many hands have been dealt, for the round ids). That way
// hbHubBlackjackTableView() shows it and blackjackTable.js draws it just like a members' solo table, and round.php's
// functions are used wherever they don't touch the database. The ones that do (betting, insurance, the moves, the
// dealer and the payouts, which take and pay chips) have guest versions here, doing the same with the chips in the
// state. They play by the same rules (rules.php), so change them together with round.php's.
require_once __DIR__ . '/room.php';
require_once __DIR__ . '/../../guestGames/guestGames.php';

const HBHUB_BLACKJACK_GUEST_ID = 0; // the guest's userId in the state: they're the only one at the table
const HBHUB_BLACKJACK_GUEST_SEATS = [['userId' => HBHUB_BLACKJACK_GUEST_ID, 'seat' => 1, 'away' => false, 'sittingOut' => false]];

/**
 * The guest's table from their token, or a new one with a new stack of play chips when there's none (the page was
 * just opened) or it can't be read.
 */
function hbHubBlackjackGuestState(mixed $token): array
{
    $state = hbHubGuestOpen('blackjack', $token);
    if (!is_array($state['shoe'] ?? null) || !is_int($state['chips'] ?? null) || !isset(HBHUB_BLACKJACK_PHASES[$state['phase'] ?? ''])) {
        $state = hbHubBlackjackNewState() + ['chips' => HBHUB_GUEST_CASINO_CHIPS, 'hands' => 0];
    }
    return $state;
}

/**
 * Moves the guest's table on for every timer that ran out by $now, like hbHubBlackjackAdvance(): insurance not decided
 * in time is no, a hand not played in time stands, the dealer's cards come one at a time, and the results go.
 */
function hbHubBlackjackGuestAdvance(array &$state, int $now): void
{
    for ($steps = 0; $steps < HBHUB_BLACKJACK_STEPS_MAX && $state['deadline'] !== null && $state['deadline'] <= $now; $steps++) {
        switch ($state['phase']) {
            case 'insurance':
                $state['round']['players'][0]['insurance'] ??= 0;
                hbHubBlackjackGuestPeek($state, $now);
                break;
            case 'playing':
                $room = ['state' => $state];
                hbHubBlackjackEndHand($room, $now, 'autoStands');
                $state = $room['state'];
                break;
            case 'dealer':
                hbHubBlackjackGuestDealerStep($state, $now);
                break;
            default: // results: the hand stays on the table, faded, until the next one
                hbHubBlackjackSetPhase($state, 'waiting', $now, null);
        }
    }
}

/**
 * Bets $amount (as sent, a string of digits) and deals straight away, as the guest is the only one who has to bet.
 * Returns null when it's dealt, otherwise why not. Like hbHubBlackjackBet().
 */
function hbHubBlackjackGuestBet(array &$state, mixed $amount, int $now): ?string
{
    if (!in_array($state['phase'], ['waiting', 'results'], true)) {
        return 'Wait for this hand to finish before betting on the next one.';
    }
    if (!is_string($amount) || !ctype_digit($amount) || strlen($amount) > HBHUB_BLACKJACK_BET_MAX_DIGITS
        || (int) $amount < HBHUB_BLACKJACK_SOLO_MIN_BET || (int) $amount % HBHUB_BLACKJACK_BET_STEP !== 0) {
        return 'Bets go in steps of ' . HBHUB_BLACKJACK_BET_STEP . ', from ' . number_format(HBHUB_BLACKJACK_SOLO_MIN_BET) . ' chips at this table.';
    }
    $amount = (int) $amount;
    if ($state['chips'] < $amount) {
        return 'You don\'t have enough chips for that bet.';
    }

    $state['chips'] -= $amount;
    $state['hands']++;
    $state['bets'] = [['userId' => HBHUB_BLACKJACK_GUEST_ID, 'seat' => 1, 'amount' => $amount]];
    $state['betsRoundId'] = $state['hands'];
    hbHubBlackjackGuestDeal($state, $now);
    return null;
}

/**
 * Deals the hand, like hbHubBlackjackDeal(): a card each and one for the dealer, twice. Then insurance when the dealer
 * shows an ace (unless the guest can't afford it), otherwise the dealer checks for blackjack.
 */
function hbHubBlackjackGuestDeal(array &$state, int $now): void
{
    $isReshuffled = count($state['shoe']) <= HBHUB_BLACKJACK_RESHUFFLE_LEFT;
    if ($isReshuffled) {
        $state['shoe'] = hbHubBlackjackNewShoe();
    }

    $bet = $state['bets'][0]['amount'];
    $player = [
        'userId' => HBHUB_BLACKJACK_GUEST_ID,
        'seat' => 1,
        'bet' => $bet,
        'insurance' => null,
        'net' => null,
        'hands' => [hbHubBlackjackNewHand([], $bet, false)],
        'stats' => ['hits' => 0, 'stands' => 0, 'doubles' => 0, 'splits' => 0, 'autoStands' => 0],
    ];
    $dealer = [];
    for ($i = 0; $i < 2; $i++) {
        $player['hands'][0]['cards'][] = hbHubBlackjackDraw($state);
        $dealer[] = hbHubBlackjackDraw($state);
    }
    $player['hands'][0]['done'] = hbHubBlackjackIsNatural($player['hands'][0]['cards']); // a blackjack has nothing to decide

    $state['round'] = [
        'id' => $state['betsRoundId'],
        'dealer' => $dealer,
        'holeShown' => false,
        'insurance' => false,
        'reshuffled' => $isReshuffled,
        'settled' => false,
        'turn' => null,
        'players' => [$player],
    ];
    $state['bets'] = [];
    $state['betsRoundId'] = null;
    $state['turnId']++;

    if ($dealer[0][0] === 'A') {
        $state['round']['insurance'] = true;
        if ($state['chips'] >= intdiv($bet, 2)) {
            hbHubBlackjackSetPhase($state, 'insurance', $now, HBHUB_BLACKJACK_INSURANCE_MS);
            return;
        }
        $state['round']['players'][0]['insurance'] = 0; // can't afford it, so that's a no
    }
    hbHubBlackjackGuestPeek($state, $now);
}

/**
 * The dealer checks for blackjack, like hbHubBlackjackPeek(): a dealer blackjack ends the hand, otherwise it's the
 * guest's turn.
 */
function hbHubBlackjackGuestPeek(array &$state, int $now): void
{
    if (hbHubBlackjackIsNatural($state['round']['dealer'])) {
        hbHubBlackjackGuestSettle($state, $now);
        return;
    }
    $room = ['state' => $state];
    hbHubBlackjackNextTurn($room, $now);
    $state = $room['state'];
}

/**
 * Takes insurance (half the bet) or says no to it, like hbHubBlackjackInsurance(). As the guest is the only one
 * deciding, the hand goes on straight away. Returns null when it's done, otherwise why not.
 */
function hbHubBlackjackGuestInsurance(array &$state, bool $take, int $now): ?string
{
    if ($state['phase'] !== 'insurance') {
        return 'Insurance isn\'t offered now.';
    }
    if ($state['round']['players'][0]['insurance'] !== null) {
        return 'You\'ve already decided.';
    }

    $cost = $take ? intdiv($state['round']['players'][0]['bet'], 2) : 0;
    if ($state['chips'] < $cost) {
        return 'You don\'t have enough chips for insurance.';
    }
    $state['chips'] -= $cost;
    $state['round']['players'][0]['insurance'] = $cost;
    hbHubBlackjackGuestPeek($state, $now);
    return null;
}

/**
 * Hit, stand, double or split, like hbHubBlackjackMove(), with the chips in the state. $turnId is the turnId the guest
 * clicked on: when the table has moved on since, it's ignored. Returns null when it's done (or ignored), otherwise why not.
 */
function hbHubBlackjackGuestMove(array &$state, string $move, mixed $turnId, int $now): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'It isn\'t your turn.';
    }
    if (!is_string($turnId) || $turnId !== (string) $state['turnId']) {
        return null;
    }

    ['player' => $p, 'hand' => $h] = $state['round']['turn'];
    if (!in_array($move, hbHubBlackjackMoves($state['round']['players'][$p], $h), true)) {
        return 'You can\'t do that with this hand.';
    }
    $bet = $state['round']['players'][$p]['hands'][$h]['bet'];
    if (in_array($move, ['double', 'split'], true) && $state['chips'] < $bet) {
        return 'You don\'t have enough chips to ' . $move . '.';
    }

    // round.php's turn functions take the room, so the state goes in one
    $room = ['state' => $state];
    $hands = &$room['state']['round']['players'][$p]['hands'];
    $stats = &$room['state']['round']['players'][$p]['stats'];
    switch ($move) {
        case 'hit':
            $stats['hits']++;
            $hands[$h]['cards'][] = hbHubBlackjackDraw($room['state']);
            if (hbHubBlackjackTotal($hands[$h]['cards'])[0] >= 21) {
                $hands[$h]['done'] = true; // bust, or 21 with nothing left to decide
                hbHubBlackjackNextTurn($room, $now);
            } else {
                hbHubBlackjackSameTurn($room['state'], $now);
            }
            break;

        case 'stand':
            hbHubBlackjackEndHand($room, $now, 'stands');
            break;

        case 'double':
            $room['state']['chips'] -= $bet;
            $stats['doubles']++;
            $hands[$h]['bet'] *= 2;
            $hands[$h]['doubled'] = true;
            $hands[$h]['cards'][] = hbHubBlackjackDraw($room['state']); // just the one card
            $hands[$h]['done'] = true;
            hbHubBlackjackNextTurn($room, $now);
            break;

        case 'split':
            $room['state']['chips'] -= $bet;
            $stats['splits']++;
            [$card1, $card2] = $hands[$h]['cards'];
            $first = hbHubBlackjackNewHand([$card1, hbHubBlackjackDraw($room['state'])], $bet, true);
            $second = hbHubBlackjackNewHand([$card2, hbHubBlackjackDraw($room['state'])], $bet, true);
            // Split aces get one card each and that's it. Any other hand is done at 21.
            $isAces = $card1[0] === 'A';
            $first['done'] = $isAces || hbHubBlackjackTotal($first['cards'])[0] === 21;
            $second['done'] = $isAces || hbHubBlackjackTotal($second['cards'])[0] === 21;
            array_splice($hands, $h, 1, [$first, $second]);

            if ($hands[$h]['done']) {
                hbHubBlackjackNextTurn($room, $now);
            } else {
                hbHubBlackjackSameTurn($room['state'], $now);
            }
            break;
    }
    unset($hands, $stats);
    $state = $room['state'];
    return null;
}

/**
 * The dealer's turn, one step at a time, like hbHubBlackjackDealerStep(): turn over the hole card, then take cards
 * below 17, then pay out.
 */
function hbHubBlackjackGuestDealerStep(array &$state, int $now): void
{
    if (!$state['round']['holeShown']) {
        $state['round']['holeShown'] = true;
    } elseif (hbHubBlackjackHasLiveHands($state['round']) && hbHubBlackjackDealerHits($state['round']['dealer'])) {
        $state['round']['dealer'][] = hbHubBlackjackDraw($state);
    } else {
        hbHubBlackjackGuestSettle($state, $now);
        return;
    }
    hbHubBlackjackSetPhase($state, 'dealer', $now, HBHUB_BLACKJACK_DEALER_MS);
}

/**
 * Pays out the hand into the guest's play chips, like hbHubBlackjackSettle(): the hands by hbHubBlackjackHandPayout(),
 * and insurance 2:1 when the dealer has blackjack.
 */
function hbHubBlackjackGuestSettle(array &$state, int $now): void
{
    $round = &$state['round'];
    $round['holeShown'] = true;
    $round['turn'] = null;

    [$dealerTotal] = hbHubBlackjackTotal($round['dealer']);
    $dealerNatural = hbHubBlackjackIsNatural($round['dealer']);
    $player = &$round['players'][0];
    $staked = 0;
    $handsPayout = 0;
    foreach ($player['hands'] as &$hand) {
        [$hand['result'], $hand['payout']] = hbHubBlackjackHandPayout($hand, $dealerTotal, $dealerNatural);
        $staked += $hand['bet'];
        $handsPayout += $hand['payout'];
    }
    unset($hand);
    $insurancePayout = $player['insurance'] > 0 && $dealerNatural ? $player['insurance'] * 3 : 0;
    $staked += (int) $player['insurance'];
    $player['net'] = $handsPayout + $insurancePayout - $staked;
    $round['settled'] = true;
    unset($player, $round);

    $state['chips'] += $handsPayout + $insurancePayout;
    $state['turnId']++;
    hbHubBlackjackSetPhase($state, 'results', $now, HBHUB_BLACKJACK_RESULTS_MS);
}

/**
 * The answer for blackjackTable.js, in the shape of gambling/blackjack/play's for a solo table: the play chips, and the
 * table as the guest may see it (without the shoe, and with the hole card hidden until it's turned over).
 */
function hbHubBlackjackGuestView(array $state, int $now): array
{
    return [
        'chips' => $state['chips'],
        'table' => [
            'room' => ['id' => 0, 'name' => 'Blackjack', 'code' => '', 'type' => 'solo', 'minBet' => HBHUB_BLACKJACK_SOLO_MIN_BET, 'seats' => 1, 'players' => 1],
        ] + hbHubBlackjackTableView($state, HBHUB_BLACKJACK_GUEST_SEATS, [HBHUB_BLACKJACK_GUEST_ID => ['name' => 'You', 'avatar' => null]],
            HBHUB_BLACKJACK_GUEST_ID, $state['chips'], null, $now),
    ];
}
