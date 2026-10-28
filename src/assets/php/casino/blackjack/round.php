<?php
// A Blackjack table's hands: betting, dealing, insurance, the players' turns, the dealer and the payouts.
// All of it happens here on the server. The table's script only sends what the player does, and only ever gets
// hbHubBlackjackTableView() of the table - never the shoe, or the dealer's hole card before it's turned over.
//
// A table's state (roomState, loaded and saved by room.php with the room locked):
//   phase        waiting, betting, insurance, playing, dealer or results (HBHUB_BLACKJACK_PHASES)
//   deadline     when the running timer runs out (Unix time in ms), null when nothing is timed. timer is how long it was set for.
//   shoe         the cards left in the shoe, the next one last
//   bets         the bets on the next hand, [{userId, seat, amount}]. Their chips are already taken.
//   betsRoundId  the HBHub-BlackjackRounds row for those bets, made with the first one
//   round        the hand being played, or the last one (shown until the next deal), or null before the first:
//                id, dealer (cards, the hole card second), holeShown, insurance (whether it was offered), reshuffled,
//                settled (paid out), turn ({player, hand} indexes, null when it's nobody's turn) and
//                players: [{userId, seat, bet, insurance (null not decided yet, 0 said no, or what it cost), net (once
//                paid out), hands: [{cards, bet, doubled, split, done, result, payout}],
//                stats: {hits, stands, doubles, splits, autoStands}}], in seat order, the order they take their turns in
//   turnId       goes up with every turn and every card, so a double click on Hit only counts once
//
// Nothing runs in the background. Every request for the table first calls hbHubBlackjackAdvance(), which moves it on
// for every timer that ran out since. room.php does that with the room locked, so it only happens once.
//
// $seats, passed to most of these, is who sits at the table now: [{userId, seat, away, sittingOut}] (room.php).
// A player who leaves during a hand stays in the round without a seat, and plays like an away player: their hands
// stand when their turn comes. What they win still goes to their chips.
//
// Every player's part of a round is stored when it's paid out (hbHubBlackjackRecordPlayer()), for the leaderboard.
require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/../wallet.php';

const HBHUB_BLACKJACK_GAME = 'blackjack'; // transactionGame in HBHub-CasinoTransactions
const HBHUB_BLACKJACK_PHASES = ['waiting' => 1, 'betting' => 2, 'insurance' => 3, 'playing' => 4, 'dealer' => 5, 'results' => 6]; // roomPhase
const HBHUB_BLACKJACK_BET_STEP = 10; // so 3:2 and half for insurance are always whole chips
const HBHUB_BLACKJACK_BET_MAX_DIGITS = 9;
const HBHUB_BLACKJACK_MAX_HANDS = 3; // splitting into up to 3 hands
const HBHUB_BLACKJACK_BETTING_MS = 15000; // from the first bet, unless everyone has bet before
const HBHUB_BLACKJACK_INSURANCE_MS = 10000;
const HBHUB_BLACKJACK_TURN_MS = 20000; // for every decision, then the hand stands by itself
const HBHUB_BLACKJACK_DEALER_MS = 900; // between the dealer's cards
const HBHUB_BLACKJACK_RESULTS_MS = 5000;
const HBHUB_BLACKJACK_STEPS_MAX = 200; // hbHubBlackjackAdvance() stops after this many, in case of a bug

function hbHubBlackjackNewState(): array
{
    return [
        'phase' => 'waiting',
        'deadline' => null,
        'timer' => null,
        'shoe' => hbHubBlackjackNewShoe(),
        'bets' => [],
        'betsRoundId' => null,
        'round' => null,
        'turnId' => 0,
    ];
}

/**
 * Moves the table on for every timer that ran out by $now, and everything that's ready before its timer runs out
 * (everyone has bet, everyone has decided on insurance, an away player's turn). With $force every timer counts as
 * run out, which plays a hand out to the end straight away - for a table nobody is left at.
 *
 * @param array $room the room from room.php, with its state
 */
function hbHubBlackjackAdvance(PDO $db, array &$room, array $seats, int $now, bool $force = false): void
{
    $steps = 0;
    while ($steps++ < HBHUB_BLACKJACK_STEPS_MAX && hbHubBlackjackStep($db, $room, $seats, $now, $force)) {
        // every step moves it on once, until there's nothing left that's ready
    }
}

/**
 * Moves the table on once, if anything is ready. Returns whether it did.
 */
function hbHubBlackjackStep(PDO $db, array &$room, array $seats, int $now, bool $force): bool
{
    $state = &$room['state'];
    $isDue = $state['deadline'] !== null && ($force || $state['deadline'] <= $now);

    switch ($state['phase']) {
        case 'betting':
            if (!$isDue && !hbHubBlackjackEveryoneBet($state, $seats)) {
                return false;
            }
            if ($state['bets'] === []) {
                hbHubBlackjackSetPhase($state, 'waiting', $now, null);
            } else {
                hbHubBlackjackDeal($db, $room, $now);
            }
            return true;

        case 'insurance':
            if (!$isDue && !hbHubBlackjackEveryoneInsured($state['round'], $seats)) {
                return false;
            }
            foreach ($state['round']['players'] as &$player) {
                $player['insurance'] ??= 0; // not decided in time is no
            }
            unset($player);
            hbHubBlackjackPeek($db, $room, $now);
            return true;

        case 'playing':
            ['player' => $p] = $state['round']['turn'];
            if (!$isDue && hbHubBlackjackIsHere($seats, $state['round']['players'][$p]['userId'])) {
                return false;
            }
            hbHubBlackjackEndHand($room, $now, 'autoStands'); // out of time, away or gone
            return true;

        case 'dealer':
            if (!$isDue) {
                return false;
            }
            hbHubBlackjackDealerStep($db, $room, $now);
            return true;

        case 'results':
            // Bets on the next hand can be placed while the results are shown. Once everyone has, it's dealt straight away.
            if (!$isDue && ($state['bets'] === [] || !hbHubBlackjackEveryoneBet($state, $seats))) {
                return false;
            }
            if ($state['bets'] === []) {
                hbHubBlackjackSetPhase($state, 'waiting', $now, null);
            } else {
                hbHubBlackjackSetPhase($state, 'betting', $now, HBHUB_BLACKJACK_BETTING_MS);
            }
            return true;

        default:
            return false; // waiting: nothing happens until someone bets
    }
}

/**
 * Bets $amount (as sent, a string of digits) on the next hand. Returns null when it's placed, otherwise why it wasn't.
 */
function hbHubBlackjackBet(PDO $db, array &$room, array $seats, int $userId, mixed $amount, int $now): ?string
{
    $state = &$room['state'];
    $seat = hbHubBlackjackSeatOf($seats, $userId);
    $minBet = (int) $room['roomMinBet'];

    if ($seat === null) {
        return 'You aren\'t at this table.';
    }
    if (!in_array($state['phase'], ['waiting', 'betting', 'results'], true)) {
        return 'Wait for this hand to finish before betting on the next one.';
    }
    if ($seat['sittingOut']) {
        return 'You\'re sitting out. Sit back in to bet.';
    }
    if (hbHubBlackjackBetIndex($state, $userId) !== null) {
        return 'You\'ve already bet on the next hand.';
    }
    if (!is_string($amount) || !ctype_digit($amount) || strlen($amount) > HBHUB_BLACKJACK_BET_MAX_DIGITS
        || (int) $amount < $minBet || (int) $amount % HBHUB_BLACKJACK_BET_STEP !== 0) {
        return 'Bets go in steps of ' . HBHUB_BLACKJACK_BET_STEP . ', from ' . number_format($minBet) . ' chips at this table.';
    }
    $amount = (int) $amount;
    if (hbHubCasinoWallet($db, $userId)['chips'] < $amount) {
        return 'You don\'t have enough chips for that bet.';
    }

    if ($state['betsRoundId'] === null) {
        $db->prepare('INSERT INTO `HBHub-BlackjackRounds` (roomId, roundStartedTimestamp) VALUES (?, UTC_TIMESTAMP())')
            ->execute([$room['roomId']]);
        $state['betsRoundId'] = (int) $db->lastInsertId();
    }
    // The wallet decides, in case the chips were spent somewhere else since they were looked at above
    if (hbHubCasinoChange($db, $userId, -$amount, 'bet', HBHUB_BLACKJACK_GAME, $state['betsRoundId']) === null) {
        return 'You don\'t have enough chips for that bet.';
    }

    $state['bets'][] = ['userId' => $userId, 'seat' => $seat['seat'], 'amount' => $amount];
    if ($state['phase'] === 'waiting') {
        hbHubBlackjackSetPhase($state, 'betting', $now, HBHUB_BLACKJACK_BETTING_MS);
    }
    return null;
}

/**
 * Takes back the user's bet on the next hand, before it's dealt. Returns null when it's taken back, otherwise why not.
 */
function hbHubBlackjackClearBet(PDO $db, array &$room, int $userId, int $now): ?string
{
    if (!in_array($room['state']['phase'], ['betting', 'results'], true)) {
        return 'Your bet can\'t be taken back now.';
    }
    if (hbHubBlackjackBetIndex($room['state'], $userId) === null) {
        return 'You haven\'t bet on the next hand.';
    }
    hbHubBlackjackReturnBet($db, $room, $userId, $now);
    return null;
}

/**
 * Gives the user back their bet on the next hand, if they have one - when they take it back, or leave the table.
 */
function hbHubBlackjackReturnBet(PDO $db, array &$room, int $userId, int $now): void
{
    $state = &$room['state'];
    $index = hbHubBlackjackBetIndex($state, $userId);
    if ($index === null) {
        return;
    }

    hbHubCasinoChange($db, $userId, $state['bets'][$index]['amount'], 'betReturned', HBHUB_BLACKJACK_GAME, $state['betsRoundId']);
    array_splice($state['bets'], $index, 1);

    if ($state['phase'] === 'betting' && $state['bets'] === []) {
        hbHubBlackjackSetPhase($state, 'waiting', $now, null); // the round row is kept for the next first bet
    }
}

/**
 * Takes insurance (half the bet) or says no to it, while the dealer shows an ace. Returns null when it's done,
 * otherwise why not.
 */
function hbHubBlackjackInsurance(PDO $db, array &$room, int $userId, bool $take): ?string
{
    $state = &$room['state'];
    if ($state['phase'] !== 'insurance') {
        return 'Insurance isn\'t offered now.';
    }
    $p = hbHubBlackjackPlayerIndex($state['round'], $userId);
    if ($p === null) {
        return 'You aren\'t in this hand.';
    }
    $player = &$state['round']['players'][$p];
    if ($player['insurance'] !== null) {
        return 'You\'ve already decided.';
    }

    if (!$take) {
        $player['insurance'] = 0;
        return null;
    }
    $cost = intdiv($player['bet'], 2);
    if (hbHubCasinoChange($db, $userId, -$cost, 'insurance', HBHUB_BLACKJACK_GAME, $state['round']['id']) === null) {
        return 'You don\'t have enough chips for insurance.';
    }
    $player['insurance'] = $cost;
    return null;
}

/**
 * Hit, stand, double or split, on the hand whose turn it is. $turnId is the turnId the player clicked on: when the
 * table has moved on since (a double click, or the timer ran out) it's ignored. Returns null when it's done (or
 * ignored), otherwise why not.
 */
function hbHubBlackjackMove(PDO $db, array &$room, int $userId, string $move, mixed $turnId, int $now): ?string
{
    $state = &$room['state'];
    $round = &$state['round'];
    if ($state['phase'] !== 'playing' || $round['players'][$round['turn']['player']]['userId'] !== $userId) {
        return 'It isn\'t your turn.';
    }
    if (!is_string($turnId) || $turnId !== (string) $state['turnId']) {
        return null;
    }

    ['player' => $p, 'hand' => $h] = $round['turn'];
    if (!in_array($move, hbHubBlackjackMoves($round['players'][$p], $h), true)) {
        return 'You can\'t do that with this hand.';
    }
    $hands = &$round['players'][$p]['hands'];
    $stats = &$round['players'][$p]['stats'];

    switch ($move) {
        case 'hit':
            $stats['hits']++;
            $hands[$h]['cards'][] = hbHubBlackjackDraw($state);
            if (hbHubBlackjackTotal($hands[$h]['cards'])[0] >= 21) {
                $hands[$h]['done'] = true; // bust, or 21 with nothing left to decide
                hbHubBlackjackNextTurn($room, $now);
            } else {
                hbHubBlackjackSameTurn($state, $now);
            }
            return null;

        case 'stand':
            hbHubBlackjackEndHand($room, $now, 'stands');
            return null;

        case 'double':
            if (hbHubCasinoChange($db, $userId, -$hands[$h]['bet'], 'double', HBHUB_BLACKJACK_GAME, $round['id']) === null) {
                return 'You don\'t have enough chips to double.';
            }
            $stats['doubles']++;
            $hands[$h]['bet'] *= 2;
            $hands[$h]['doubled'] = true;
            $hands[$h]['cards'][] = hbHubBlackjackDraw($state); // just the one card
            $hands[$h]['done'] = true;
            hbHubBlackjackNextTurn($room, $now);
            return null;

        case 'split':
            $bet = $hands[$h]['bet'];
            if (hbHubCasinoChange($db, $userId, -$bet, 'split', HBHUB_BLACKJACK_GAME, $round['id']) === null) {
                return 'You don\'t have enough chips to split.';
            }
            $stats['splits']++;
            [$card1, $card2] = $hands[$h]['cards'];
            $first = hbHubBlackjackNewHand([$card1, hbHubBlackjackDraw($state)], $bet, true);
            $second = hbHubBlackjackNewHand([$card2, hbHubBlackjackDraw($state)], $bet, true);
            // Split aces get one card each and that's it. Any other hand is done at 21.
            $isAces = $card1[0] === 'A';
            $first['done'] = $isAces || hbHubBlackjackTotal($first['cards'])[0] === 21;
            $second['done'] = $isAces || hbHubBlackjackTotal($second['cards'])[0] === 21;
            array_splice($hands, $h, 1, [$first, $second]);

            if ($hands[$h]['done']) {
                hbHubBlackjackNextTurn($room, $now);
            } else {
                hbHubBlackjackSameTurn($state, $now);
            }
            return null;
    }
    return null;
}

/**
 * What the player can do with their hand: hit and stand, and with its first two cards double, and split a pair
 * (up to HBHUB_BLACKJACK_MAX_HANDS hands). With $chips, double and split only when they can afford them.
 *
 * @return list<string>
 */
function hbHubBlackjackMoves(array $player, int $handIndex, ?int $chips = null): array
{
    $hand = $player['hands'][$handIndex];
    if ($hand['done']) {
        return [];
    }

    $moves = ['hit', 'stand'];
    if (count($hand['cards']) === 2 && ($chips === null || $chips >= $hand['bet'])) {
        $moves[] = 'double';
        if (count($player['hands']) < HBHUB_BLACKJACK_MAX_HANDS && hbHubBlackjackIsPair($hand['cards'][0], $hand['cards'][1])) {
            $moves[] = 'split';
        }
    }
    return $moves;
}

/**
 * Deals the next hand to everyone who bet: one card each and one for the dealer, twice (the dealer's second is the
 * hole card). Then insurance when the dealer shows an ace, otherwise the dealer checks for blackjack.
 */
function hbHubBlackjackDeal(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];
    $isReshuffled = count($state['shoe']) <= HBHUB_BLACKJACK_RESHUFFLE_LEFT;
    if ($isReshuffled) {
        $state['shoe'] = hbHubBlackjackNewShoe();
    }

    $bets = $state['bets'];
    usort($bets, fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
    $players = [];
    foreach ($bets as $bet) {
        $players[] = [
            'userId' => $bet['userId'],
            'seat' => $bet['seat'],
            'bet' => $bet['amount'],
            'insurance' => null,
            'net' => null,
            'hands' => [hbHubBlackjackNewHand([], $bet['amount'], false)],
            'stats' => ['hits' => 0, 'stands' => 0, 'doubles' => 0, 'splits' => 0, 'autoStands' => 0],
        ];
    }

    $dealer = [];
    for ($i = 0; $i < 2; $i++) {
        foreach ($players as &$player) {
            $player['hands'][0]['cards'][] = hbHubBlackjackDraw($state);
        }
        unset($player);
        $dealer[] = hbHubBlackjackDraw($state);
    }
    foreach ($players as &$player) {
        $player['hands'][0]['done'] = hbHubBlackjackIsNatural($player['hands'][0]['cards']); // a blackjack has nothing to decide
    }
    unset($player);

    $state['round'] = [
        'id' => $state['betsRoundId'],
        'dealer' => $dealer,
        'holeShown' => false,
        'insurance' => false,
        'reshuffled' => $isReshuffled,
        'settled' => false,
        'turn' => null,
        'players' => $players,
    ];
    $state['bets'] = [];
    $state['betsRoundId'] = null;
    $state['turnId']++;
    $db->prepare('UPDATE `HBHub-BlackjackRounds` SET roundDealtTimestamp = UTC_TIMESTAMP() WHERE roundId = ?')
        ->execute([$state['round']['id']]);

    if ($dealer[0][0] === 'A') {
        // Insurance, for everyone who can afford half their bet. Anyone who can't has said no already.
        $state['round']['insurance'] = true;
        foreach ($state['round']['players'] as &$player) {
            if (hbHubCasinoWallet($db, $player['userId'])['chips'] < intdiv($player['bet'], 2)) {
                $player['insurance'] = 0;
            }
        }
        unset($player);
        hbHubBlackjackSetPhase($state, 'insurance', $now, HBHUB_BLACKJACK_INSURANCE_MS);
        return;
    }
    hbHubBlackjackPeek($db, $room, $now);
}

/**
 * The dealer checks the hole card for blackjack (only possible with an ace or a 10 showing). A dealer blackjack
 * ends the hand straight away, before anyone doubles or splits. Otherwise the players take their turns.
 */
function hbHubBlackjackPeek(PDO $db, array &$room, int $now): void
{
    if (hbHubBlackjackIsNatural($room['state']['round']['dealer'])) {
        hbHubBlackjackSettle($db, $room, $now);
        return;
    }
    hbHubBlackjackNextTurn($room, $now);
}

/**
 * Ends the hand whose turn it is (stand, or stood for the player), counting it in their stats, and moves on.
 */
function hbHubBlackjackEndHand(array &$room, int $now, string $stat): void
{
    $round = &$room['state']['round'];
    ['player' => $p, 'hand' => $h] = $round['turn'];
    $round['players'][$p]['hands'][$h]['done'] = true;
    $round['players'][$p]['stats'][$stat]++;
    hbHubBlackjackNextTurn($room, $now);
}

/**
 * Gives the turn to the first hand that still has something to decide (the hands before it are all done),
 * or to the dealer when there's none left.
 */
function hbHubBlackjackNextTurn(array &$room, int $now): void
{
    $state = &$room['state'];
    $round = &$state['round'];
    $state['turnId']++;

    foreach ($round['players'] as $p => $player) {
        foreach ($player['hands'] as $h => $hand) {
            if (!$hand['done']) {
                $round['turn'] = ['player' => $p, 'hand' => $h];
                hbHubBlackjackSetPhase($state, 'playing', $now, HBHUB_BLACKJACK_TURN_MS);
                return;
            }
        }
    }

    $round['turn'] = null;
    hbHubBlackjackSetPhase($state, 'dealer', $now, HBHUB_BLACKJACK_DEALER_MS);
}

/**
 * The same hand goes on (after a hit or a split): a new turnId, and the timer starts over.
 */
function hbHubBlackjackSameTurn(array &$state, int $now): void
{
    $state['turnId']++;
    hbHubBlackjackSetPhase($state, 'playing', $now, HBHUB_BLACKJACK_TURN_MS);
}

/**
 * The dealer's turn, one step at a time so the cards come one by one: turn over the hole card, then take cards
 * below 17, then pay out. No cards are taken when every hand has bust or is a blackjack.
 */
function hbHubBlackjackDealerStep(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];
    $round = &$state['round'];

    if (!$round['holeShown']) {
        $round['holeShown'] = true;
    } elseif (hbHubBlackjackHasLiveHands($round) && hbHubBlackjackDealerHits($round['dealer'])) {
        $round['dealer'][] = hbHubBlackjackDraw($state);
    } else {
        hbHubBlackjackSettle($db, $room, $now);
        return;
    }
    hbHubBlackjackSetPhase($state, 'dealer', $now, HBHUB_BLACKJACK_DEALER_MS);
}

/**
 * Whether any hand can still lose or win against the dealer's cards: not bust, and not a blackjack (already paid 3:2).
 */
function hbHubBlackjackHasLiveHands(array $round): bool
{
    foreach ($round['players'] as $player) {
        foreach ($player['hands'] as $hand) {
            if (hbHubBlackjackTotal($hand['cards'])[0] <= 21 && !hbHubBlackjackIsHandNatural($hand)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Pays out the hand: blackjack 3:2, a win 1:1, a push gets the bet back, and insurance 2:1 when the dealer has
 * blackjack. Hands and insurance are paid separately (payout and insurancePayout in the chip log).
 */
function hbHubBlackjackSettle(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];
    $round = &$state['round'];
    $round['holeShown'] = true;
    $round['turn'] = null;

    [$dealerTotal] = hbHubBlackjackTotal($round['dealer']);
    $dealerNatural = hbHubBlackjackIsNatural($round['dealer']);

    foreach ($round['players'] as &$player) {
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

        if ($handsPayout > 0) {
            hbHubCasinoChange($db, $player['userId'], $handsPayout, 'payout', HBHUB_BLACKJACK_GAME, $round['id']);
        }
        if ($insurancePayout > 0) {
            hbHubCasinoChange($db, $player['userId'], $insurancePayout, 'insurancePayout', HBHUB_BLACKJACK_GAME, $round['id']);
        }
        hbHubBlackjackRecordPlayer($db, $room, $player, count($round['players']), $handsPayout, $insurancePayout);
    }
    unset($player);

    $round['settled'] = true;
    $state['turnId']++;
    $db->prepare('UPDATE `HBHub-BlackjackRounds` SET roundEndedTimestamp = UTC_TIMESTAMP(), roundDealerCards = ?, roundDealerTotal = ?
        WHERE roundId = ?')
        ->execute([implode(' ', $round['dealer']), $dealerTotal, $round['id']]);

    hbHubBlackjackSetPhase($state, 'results', $now, HBHUB_BLACKJACK_RESULTS_MS);
}

/**
 * How a hand did against the dealer's cards, and what it pays back, the bet included: blackjack 3:2, a win 1:1, a
 * push the bet back. Also used by the guests' table (guest.php), so both pay the same.
 *
 * @return array{0: string, 1: int} [blackjack, win, push, bust or lose, payout]
 */
function hbHubBlackjackHandPayout(array $hand, int $dealerTotal, bool $dealerNatural): array
{
    [$total] = hbHubBlackjackTotal($hand['cards']);
    $isNatural = hbHubBlackjackIsHandNatural($hand);
    $result = match (true) {
        $isNatural && $dealerNatural => 'push',
        $isNatural => 'blackjack',
        $total > 21 => 'bust',
        $dealerNatural => 'lose',
        $dealerTotal > 21, $total > $dealerTotal => 'win',
        $total === $dealerTotal => 'push',
        default => 'lose',
    };
    $payout = match ($result) {
        'blackjack' => $hand['bet'] + intdiv($hand['bet'] * 3, 2),
        'win' => $hand['bet'] * 2,
        'push' => $hand['bet'],
        default => 0,
    };
    return [$result, $payout];
}

/**
 * Stores how the round went for the player (HBHub-BlackjackPlayerRounds), for the leaderboard and any stats later.
 * The result is from the hands, not insurance: blackjack for a blackjack, otherwise whether the hands won or lost
 * chips together - so a split with one hand won and one lost (the same bets) is a push.
 */
function hbHubBlackjackRecordPlayer(PDO $db, array $room, array $player, int $playerCount, int $handsPayout, int $insurancePayout): void
{
    $wagered = array_sum(array_column($player['hands'], 'bet'));
    $handsNet = $handsPayout - $wagered;
    $result = match (true) {
        count($player['hands']) === 1 && $player['hands'][0]['result'] === 'blackjack' => 'blackjack',
        $handsNet > 0 => 'win',
        $handsNet < 0 => 'loss',
        default => 'push',
    };
    $hands = array_map(static fn (array $hand): array => [
        'cards' => $hand['cards'],
        'bet' => $hand['bet'],
        'doubled' => $hand['doubled'],
        'result' => $hand['result'],
        'payout' => $hand['payout'],
    ], $player['hands']);
    $stats = $player['stats'];

    $db->prepare('INSERT INTO `HBHub-BlackjackPlayerRounds` (roundId, roomId, roomType, userId, seatNumber, playersInRound,
            playerBet, playerWagered, playerInsurance, playerPayout, playerInsurancePayout, playerNet, playerResult, playerHands,
            playerHits, playerStands, playerDoubles, playerSplits, playerAutoStands, playerInsuranceTaken, playerHandsDetail, playerTimestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([
            $room['state']['round']['id'],
            $room['roomId'],
            $room['roomType'],
            $player['userId'],
            $player['seat'],
            $playerCount,
            $player['bet'],
            $wagered,
            (int) $player['insurance'],
            $handsPayout,
            $insurancePayout,
            $player['net'],
            $result,
            count($player['hands']),
            $stats['hits'],
            $stats['stands'],
            $stats['doubles'],
            $stats['splits'],
            $stats['autoStands'],
            $player['insurance'] > 0 ? 1 : 0,
            json_encode($hands, JSON_THROW_ON_ERROR),
        ]);
}

/**
 * The table as $viewerId may see it, for blackjackTable.js: without the shoe, and with the hole card as null until
 * it's turned over. Everyone sees everyone's cards and bets, as at a real table.
 *
 * @param array<int, array{name: string, avatar: ?string}> $people everyone at the table or in the hand, by userId
 * @param int $chips the viewer's chips, so double and split are only offered when they can afford them
 * @param ?int $hostUserId the room's host, who can invite people
 */
function hbHubBlackjackTableView(array $state, array $seats, array $people, int $viewerId, int $chips, ?int $hostUserId, int $now): array
{
    $round = $state['round'];
    $turn = $state['phase'] === 'playing' ? $round['turn'] : null;

    $dealer = null;
    if ($round !== null) {
        $cards = $round['holeShown'] ? $round['dealer'] : [$round['dealer'][0], null];
        [$total, $isSoft] = hbHubBlackjackTotal(array_values(array_filter($cards)));
        $dealer = [
            'cards' => $cards,
            'total' => $total,
            'soft' => $isSoft,
            'blackjack' => $round['holeShown'] && hbHubBlackjackIsNatural($round['dealer']),
        ];
    }

    // Everyone sitting at the table, and anyone who left during the hand (their cards are still on the table)
    $bySeat = [];
    foreach ($seats as $seat) {
        $bySeat[$seat['seat']] = ['userId' => $seat['userId'], 'away' => $seat['away'], 'sittingOut' => $seat['sittingOut'], 'left' => false];
    }
    foreach ($round['players'] ?? [] as $player) {
        $bySeat[$player['seat']] ??= ['userId' => $player['userId'], 'away' => true, 'sittingOut' => false, 'left' => true];
    }
    ksort($bySeat);

    $seatViews = [];
    foreach ($bySeat as $seatNumber => $seat) {
        $userId = $seat['userId'];
        $p = $round !== null ? hbHubBlackjackPlayerIndex($round, $userId) : null;
        $betIndex = hbHubBlackjackBetIndex($state, $userId);
        $seatViews[] = [
            'seat' => $seatNumber,
            'userId' => $userId,
            'name' => $people[$userId]['name'] ?? '',
            'avatar' => $people[$userId]['avatar'] ?? null,
            'isYou' => $userId === $viewerId,
            'isHost' => $userId === $hostUserId && !$seat['left'],
            'away' => $seat['away'],
            'sittingOut' => $seat['sittingOut'],
            'left' => $seat['left'],
            'nextBet' => $betIndex !== null ? $state['bets'][$betIndex]['amount'] : null,
            'player' => $p !== null ? hbHubBlackjackPlayerView($round['players'][$p], $turn !== null && $turn['player'] === $p ? $turn['hand'] : null) : null,
        ];
    }

    $you = null;
    $yourSeat = hbHubBlackjackSeatOf($seats, $viewerId);
    if ($yourSeat !== null) {
        $p = $round !== null ? hbHubBlackjackPlayerIndex($round, $viewerId) : null;
        $betIndex = hbHubBlackjackBetIndex($state, $viewerId);
        $you = [
            'seat' => $yourSeat['seat'],
            'isHost' => $viewerId === $hostUserId,
            'sittingOut' => $yourSeat['sittingOut'],
            'nextBet' => $betIndex !== null ? $state['bets'][$betIndex]['amount'] : null,
            'canBet' => in_array($state['phase'], ['waiting', 'betting', 'results'], true) && !$yourSeat['sittingOut'] && $betIndex === null,
            'canClearBet' => in_array($state['phase'], ['betting', 'results'], true) && $betIndex !== null,
            'insurance' => $state['phase'] === 'insurance' && $p !== null && $round['players'][$p]['insurance'] === null
                ? ['cost' => intdiv($round['players'][$p]['bet'], 2), 'canAfford' => $chips >= intdiv($round['players'][$p]['bet'], 2)]
                : null,
            'moves' => $turn !== null && $turn['player'] === $p ? hbHubBlackjackMoves($round['players'][$p], $turn['hand'], $chips) : [],
        ];
    }

    return [
        'phase' => $state['phase'],
        // The dealer's steps are timed too, but only to space out the cards
        'timer' => $state['deadline'] !== null && $state['phase'] !== 'dealer'
            ? ['left' => max(0, $state['deadline'] - $now), 'total' => $state['timer']]
            : null,
        'roundId' => $round['id'] ?? null,
        'settled' => $round['settled'] ?? false,
        'reshuffled' => $round['reshuffled'] ?? false,
        // Only how many cards are left. The hole card is already out of the shoe, as it is at a real table.
        'shoe' => ['left' => count($state['shoe']), 'size' => HBHUB_BLACKJACK_SHOE_SIZE, 'reshuffleAt' => HBHUB_BLACKJACK_RESHUFFLE_LEFT],
        'insuranceOffered' => $round['insurance'] ?? false,
        'dealer' => $dealer,
        'seats' => $seatViews,
        'turn' => $turn !== null ? ['seat' => $round['players'][$turn['player']]['seat'], 'hand' => $turn['hand']] : null,
        'turnId' => $state['turnId'],
        'you' => $you,
    ];
}

/**
 * A player's part of the hand as everyone at the table sees it. $turnHand is the index of their hand whose turn it
 * is, or null.
 */
function hbHubBlackjackPlayerView(array $player, ?int $turnHand): array
{
    $hands = [];
    foreach ($player['hands'] as $h => $hand) {
        [$total, $isSoft] = hbHubBlackjackTotal($hand['cards']);
        $hands[] = [
            'cards' => $hand['cards'],
            'total' => $total,
            'soft' => $isSoft,
            'bet' => $hand['bet'],
            'doubled' => $hand['doubled'],
            'blackjack' => hbHubBlackjackIsHandNatural($hand),
            'result' => $hand['result'],
            'payout' => $hand['payout'],
            'isTurn' => $turnHand === $h,
        ];
    }
    return ['bet' => $player['bet'], 'insurance' => $player['insurance'], 'net' => $player['net'], 'hands' => $hands];
}

function hbHubBlackjackNewHand(array $cards, int $bet, bool $isSplit): array
{
    return ['cards' => $cards, 'bet' => $bet, 'doubled' => false, 'split' => $isSplit, 'done' => false, 'result' => null, 'payout' => 0];
}

/**
 * Whether the hand is a blackjack: 21 with its first two cards, and not after a split (that's just 21).
 */
function hbHubBlackjackIsHandNatural(array $hand): bool
{
    return !$hand['split'] && hbHubBlackjackIsNatural($hand['cards']);
}

/**
 * The next card from the shoe. A shoe never runs out during a hand (a new one starts before the deal once 75% of it
 * has been dealt), but should it ever, a new one is opened.
 */
function hbHubBlackjackDraw(array &$state): string
{
    if ($state['shoe'] === []) {
        $state['shoe'] = hbHubBlackjackNewShoe();
    }
    return array_pop($state['shoe']);
}

function hbHubBlackjackSetPhase(array &$state, string $phase, int $now, ?int $ms): void
{
    $state['phase'] = $phase;
    $state['deadline'] = $ms !== null ? $now + $ms : null;
    $state['timer'] = $ms;
}

/**
 * Whether everyone at the table who's playing (not sitting out, not away) has bet on the next hand.
 * False when there's nobody like that, so the betting timer decides.
 */
function hbHubBlackjackEveryoneBet(array $state, array $seats): bool
{
    $playing = 0;
    foreach ($seats as $seat) {
        if ($seat['away'] || $seat['sittingOut']) {
            continue;
        }
        if (hbHubBlackjackBetIndex($state, $seat['userId']) === null) {
            return false;
        }
        $playing++;
    }
    return $playing > 0;
}

/**
 * Whether every player in the hand who's still here has decided on insurance (anyone away or gone counts as no).
 */
function hbHubBlackjackEveryoneInsured(array $round, array $seats): bool
{
    foreach ($round['players'] as $player) {
        if ($player['insurance'] === null && hbHubBlackjackIsHere($seats, $player['userId'])) {
            return false;
        }
    }
    return true;
}

/**
 * Whether the user sits at the table and isn't away.
 */
function hbHubBlackjackIsHere(array $seats, int $userId): bool
{
    $seat = hbHubBlackjackSeatOf($seats, $userId);
    return $seat !== null && !$seat['away'];
}

function hbHubBlackjackSeatOf(array $seats, int $userId): ?array
{
    foreach ($seats as $seat) {
        if ($seat['userId'] === $userId) {
            return $seat;
        }
    }
    return null;
}

function hbHubBlackjackBetIndex(array $state, int $userId): ?int
{
    foreach ($state['bets'] as $index => $bet) {
        if ($bet['userId'] === $userId) {
            return $index;
        }
    }
    return null;
}

function hbHubBlackjackPlayerIndex(array $round, int $userId): ?int
{
    foreach ($round['players'] as $index => $player) {
        if ($player['userId'] === $userId) {
            return $index;
        }
    }
    return null;
}
