<?php
// A Baccarat table's hands: betting, dealing (the cards are turned over one at a time), the payouts and the shoe.
// All of it happens here on the server. The table's script only sends the bets, and only ever gets
// hbHubBaccaratTableView() of the table - never the shoe, or a card before it's turned over.
//
// It's punto banco: nobody decides anything once the bets are in. The whole hand is dealt by the rules in cards.php as
// soon as betting closes, and the cards are then shown one by one, every HBHUB_BACCARAT_DEAL_MS.
//
// A table's state (roomState, loaded and saved by room.php with the room locked):
//   phase        waiting, betting, dealing or results (HBHUB_BACCARAT_PHASES)
//   deadline     when the running timer runs out (Unix time in ms), null when nothing is timed. timer is how long it was set for.
//   shoe         the cards left in the shoe, the next one last
//   shuffled     true from when the shoe was shuffled (75% of it dealt) until the first hand from the new one is dealt,
//                so the table can tell the players before they bet
//   bets         the bets on the next hand, [{userId, seat, spots: {spot: chips}}], only the spots they bet on
//                (HBHUB_BACCARAT_SPOTS). Their chips are already taken.
//   betsRoundId  the HBHub-BaccaratRounds row for those bets, made with the first one
//   round        the hand being dealt, or the last one (shown until the next deal), or null before the first:
//                id, player and banker (their cards), order (which hand each card went to, in the order they were
//                dealt), shown (how many of those are face up), newShoe (the first hand from a new shoe), settled (paid
//                out), winner (player, banker or tie), playerPair and bankerPair (null until it's paid out) and
//                players: [{userId, seat, spots, wagered, payouts ({spot: chips paid back}), payout, net}] in seat order
//
// Nothing runs in the background. Every request for the table first calls hbHubBaccaratAdvance(), which moves it on
// for every timer that ran out since. room.php does that with the room locked, so it only happens once.
//
// $seats, passed to most of these, is who sits at the table now: [{userId, seat, away, sittingOut}] (room.php).
// A player who leaves during a hand stays in the round without a seat. What they win still goes to their chips.
//
// Every player's part of a round is stored when it's paid out (hbHubBaccaratRecordPlayer()), for the leaderboard.
require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/../wallet.php';

const HBHUB_BACCARAT_GAME = 'baccarat'; // transactionGame in HBHub-CasinoTransactions
const HBHUB_BACCARAT_PHASES = ['waiting' => 1, 'betting' => 2, 'dealing' => 3, 'results' => 4]; // roomPhase
const HBHUB_BACCARAT_SPOTS = ['player', 'banker', 'tie', 'playerPair', 'bankerPair']; // what can be bet on
const HBHUB_BACCARAT_PAYS = ['player' => 1, 'banker' => 1, 'tie' => 8, 'playerPair' => 11, 'bankerPair' => 11]; // to 1
// What's taken off a winning Banker bet's win, in thousandths (2.7%). The Banker wins a little more often than the
// Player (45.9% to 44.6% of hands), so at a full 1:1 always betting Banker would win chips. 2.7% evens that out.
const HBHUB_BACCARAT_BANKER_COMMISSION = 27;
const HBHUB_BACCARAT_MIN_PLAYERS = 1; // at the table (and not sitting out or away) before bets can be placed
const HBHUB_BACCARAT_BET_STEP = 10;
const HBHUB_BACCARAT_BET_MAX_DIGITS = 9;
const HBHUB_BACCARAT_BETTING_MS = 15000; // from the first bet, unless everyone has bet before
const HBHUB_BACCARAT_DEAL_MS = 800; // between the cards being turned over
const HBHUB_BACCARAT_DRAW_MS = 1400; // before a third card, so everyone can see the totals first
const HBHUB_BACCARAT_RESULTS_MS = 6000;
const HBHUB_BACCARAT_STEPS_MAX = 50; // hbHubBaccaratAdvance() stops after this many, in case of a bug

function hbHubBaccaratNewState(): array
{
    return [
        'phase' => 'waiting',
        'deadline' => null,
        'timer' => null,
        'shoe' => hbHubBaccaratNewShoe(),
        'shuffled' => false, // a new table obviously has a new shoe, so it isn't announced
        'bets' => [],
        'betsRoundId' => null,
        'round' => null,
    ];
}

/**
 * Moves the table on for every timer that ran out by $now, and everything that's ready before its timer runs out
 * (everyone has bet). With $force every timer counts as run out, which deals a hand out to the end straight away -
 * for a table nobody is left at.
 *
 * @param array $room the room from room.php, with its state
 */
function hbHubBaccaratAdvance(PDO $db, array &$room, array $seats, int $now, bool $force = false): void
{
    $steps = 0;
    while ($steps++ < HBHUB_BACCARAT_STEPS_MAX && hbHubBaccaratStep($db, $room, $seats, $now, $force)) {
        // every step moves it on once, until there's nothing left that's ready
    }
}

/**
 * Moves the table on once, if anything is ready. Returns whether it did.
 */
function hbHubBaccaratStep(PDO $db, array &$room, array $seats, int $now, bool $force): bool
{
    $state = &$room['state'];
    $isDue = $state['deadline'] !== null && ($force || $state['deadline'] <= $now);

    switch ($state['phase']) {
        case 'betting':
            if (!$isDue && !hbHubBaccaratEveryoneBet($state, $seats)) {
                return false;
            }
            if ($state['bets'] === []) {
                hbHubBaccaratSetPhase($state, 'waiting', $now, null);
            } else {
                hbHubBaccaratDeal($db, $room, $now);
            }
            return true;

        case 'dealing':
            if (!$isDue) {
                return false;
            }
            hbHubBaccaratTurnCard($db, $room, $now);
            return true;

        case 'results':
            // Bets on the next hand can be placed while the results are shown. Once everyone has, it's dealt straight away.
            if (!$isDue && ($state['bets'] === [] || !hbHubBaccaratEveryoneBet($state, $seats))) {
                return false;
            }
            if ($state['bets'] === []) {
                hbHubBaccaratSetPhase($state, 'waiting', $now, null);
            } else {
                hbHubBaccaratSetPhase($state, 'betting', $now, HBHUB_BACCARAT_BETTING_MS);
            }
            return true;

        default:
            return false; // waiting: nothing happens until someone bets
    }
}

/**
 * Places the user's bets on the next hand, all at once: $bets is what was sent, {spot: chips as a string of digits}.
 * Returns null when they're placed, otherwise why they weren't.
 */
function hbHubBaccaratBet(PDO $db, array &$room, array $seats, int $userId, mixed $bets, int $now): ?string
{
    $state = &$room['state'];
    $seat = hbHubBaccaratSeatOf($seats, $userId);
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
    if (hbHubBaccaratBetIndex($state, $userId) !== null) {
        return 'You\'ve already bet on the next hand.';
    }
    if (hbHubBaccaratPlayersHere($seats) < HBHUB_BACCARAT_MIN_PLAYERS) {
        return 'Baccarat needs at least ' . HBHUB_BACCARAT_MIN_PLAYERS . ' players at the table. Share the code or invite someone.';
    }

    $spots = hbHubBaccaratCleanBets($bets);
    if ($spots === null) {
        return 'Bets go in steps of ' . HBHUB_BACCARAT_BET_STEP . '.';
    }
    if (isset($spots['player'], $spots['banker'])) {
        return 'Bet on Player or Banker, not both.';
    }
    $total = array_sum($spots);
    if ($total < $minBet) {
        return 'Your bets together have to be at least ' . number_format($minBet) . ' chips at this table.';
    }
    if (hbHubCasinoWallet($db, $userId)['chips'] < $total) {
        return 'You don\'t have enough chips for those bets.';
    }

    if ($state['betsRoundId'] === null) {
        $db->prepare('INSERT INTO `HBHub-BaccaratRounds` (roomId, roundStartedTimestamp) VALUES (?, UTC_TIMESTAMP())')
            ->execute([$room['roomId']]);
        $state['betsRoundId'] = (int) $db->lastInsertId();
    }
    // The wallet decides, in case the chips were spent somewhere else since they were looked at above
    if (hbHubCasinoChange($db, $userId, -$total, 'bet', HBHUB_BACCARAT_GAME, $state['betsRoundId']) === null) {
        return 'You don\'t have enough chips for those bets.';
    }

    $state['bets'][] = ['userId' => $userId, 'seat' => $seat['seat'], 'spots' => $spots];
    if ($state['phase'] === 'waiting') {
        hbHubBaccaratSetPhase($state, 'betting', $now, HBHUB_BACCARAT_BETTING_MS);
    }
    return null;
}

/**
 * The bets as sent ({spot: chips}), with only the spots there are and the ones with chips on them, in the order of
 * HBHUB_BACCARAT_SPOTS. Null when anything in it isn't a bet (an unknown spot, or chips not in steps of 10).
 *
 * @return ?array<string, int>
 */
function hbHubBaccaratCleanBets(mixed $bets): ?array
{
    if (!is_array($bets) || array_diff(array_map('strval', array_keys($bets)), HBHUB_BACCARAT_SPOTS) !== []) {
        return null;
    }

    $spots = [];
    foreach (HBHUB_BACCARAT_SPOTS as $spot) {
        $amount = $bets[$spot] ?? '0';
        if (!is_string($amount) || !ctype_digit($amount) || strlen($amount) > HBHUB_BACCARAT_BET_MAX_DIGITS
            || (int) $amount % HBHUB_BACCARAT_BET_STEP !== 0) {
            return null;
        }
        if ((int) $amount > 0) {
            $spots[$spot] = (int) $amount;
        }
    }
    return $spots;
}

/**
 * Takes back the user's bets on the next hand, before it's dealt. Returns null when they're taken back, otherwise why not.
 */
function hbHubBaccaratClearBet(PDO $db, array &$room, int $userId, int $now): ?string
{
    if (!in_array($room['state']['phase'], ['betting', 'results'], true)) {
        return 'Your bets can\'t be taken back now.';
    }
    if (hbHubBaccaratBetIndex($room['state'], $userId) === null) {
        return 'You haven\'t bet on the next hand.';
    }
    hbHubBaccaratReturnBet($db, $room, $userId, $now);
    return null;
}

/**
 * Gives the user back their bets on the next hand, if they have any - when they take them back, sit out or leave.
 */
function hbHubBaccaratReturnBet(PDO $db, array &$room, int $userId, int $now): void
{
    $state = &$room['state'];
    $index = hbHubBaccaratBetIndex($state, $userId);
    if ($index === null) {
        return;
    }

    hbHubCasinoChange($db, $userId, array_sum($state['bets'][$index]['spots']), 'betReturned', HBHUB_BACCARAT_GAME, $state['betsRoundId']);
    array_splice($state['bets'], $index, 1);

    if ($state['phase'] === 'betting' && $state['bets'] === []) {
        hbHubBaccaratSetPhase($state, 'waiting', $now, null); // the round row is kept for the next first bet
    }
}

/**
 * Deals the next hand for everyone who bet. The whole hand is dealt now, by the rules, and turned over a card at a time.
 */
function hbHubBaccaratDeal(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];

    $bets = $state['bets'];
    usort($bets, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
    $players = array_map(static fn (array $bet): array => [
        'userId' => $bet['userId'],
        'seat' => $bet['seat'],
        'spots' => $bet['spots'],
        'wagered' => array_sum($bet['spots']),
        'payouts' => null,
        'payout' => null,
        'net' => null,
    ], $bets);

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
        'players' => $players,
    ];
    $state['shuffled'] = false;
    $state['bets'] = [];
    $state['betsRoundId'] = null;
    $db->prepare('UPDATE `HBHub-BaccaratRounds` SET roundDealtTimestamp = UTC_TIMESTAMP() WHERE roundId = ?')
        ->execute([$state['round']['id']]);

    hbHubBaccaratSetPhase($state, 'dealing', $now, HBHUB_BACCARAT_DEAL_MS);
}

/**
 * Turns the next card over. After the last one the hand is paid out. The pause before a third card is a little longer.
 */
function hbHubBaccaratTurnCard(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];
    $round = &$state['round'];
    $round['shown']++;

    if ($round['shown'] >= count($round['order'])) {
        hbHubBaccaratSettle($db, $room, $now);
        return;
    }
    hbHubBaccaratSetPhase($state, 'dealing', $now, $round['shown'] >= 4 ? HBHUB_BACCARAT_DRAW_MS : HBHUB_BACCARAT_DEAL_MS);
}

/**
 * Pays out the hand: Player 1:1, Banker 1:1 less 2.7% (both given back on a tie), Tie 8:1, and the pairs 11:1. Then, once 75% of the
 * shoe has been dealt, it's shuffled into a new one before anyone bets on the next hand.
 */
function hbHubBaccaratSettle(PDO $db, array &$room, int $now): void
{
    $state = &$room['state'];
    $round = &$state['round'];
    $round['shown'] = count($round['order']);
    $round['winner'] = hbHubBaccaratWinner($round['player'], $round['banker']);
    $round['playerPair'] = hbHubBaccaratIsPair($round['player']);
    $round['bankerPair'] = hbHubBaccaratIsPair($round['banker']);

    foreach ($round['players'] as &$player) {
        $player['payouts'] = [];
        foreach ($player['spots'] as $spot => $amount) {
            $player['payouts'][$spot] = hbHubBaccaratSpotPayout($spot, $amount, $round);
        }
        $player['payout'] = array_sum($player['payouts']);
        $player['net'] = $player['payout'] - $player['wagered'];

        if ($player['payout'] > 0) {
            hbHubCasinoChange($db, $player['userId'], $player['payout'], 'payout', HBHUB_BACCARAT_GAME, $round['id']);
        }
        hbHubBaccaratRecordPlayer($db, $room, $player, count($round['players']));
    }
    unset($player);

    $round['settled'] = true;
    $db->prepare('UPDATE `HBHub-BaccaratRounds` SET roundEndedTimestamp = UTC_TIMESTAMP(), roundPlayerCards = ?, roundBankerCards = ?,
            roundPlayerTotal = ?, roundBankerTotal = ?, roundWinner = ?, roundPlayerPair = ?, roundBankerPair = ?
        WHERE roundId = ?')
        ->execute([
            implode(' ', $round['player']),
            implode(' ', $round['banker']),
            hbHubBaccaratTotal($round['player']),
            hbHubBaccaratTotal($round['banker']),
            $round['winner'],
            $round['playerPair'] ? 1 : 0,
            $round['bankerPair'] ? 1 : 0,
            $round['id'],
        ]);

    if (count($state['shoe']) <= HBHUB_BACCARAT_RESHUFFLE_LEFT) {
        $state['shoe'] = hbHubBaccaratNewShoe();
        $state['shuffled'] = true;
    }

    hbHubBaccaratSetPhase($state, 'results', $now, HBHUB_BACCARAT_RESULTS_MS);
}

/**
 * What a bet on the spot pays back, the bet included: 0 when it lost, the bet itself when Player or Banker tie.
 */
function hbHubBaccaratSpotPayout(string $spot, int $amount, array $round): int
{
    $isWin = match ($spot) {
        'player', 'banker', 'tie' => $round['winner'] === $spot,
        'playerPair' => $round['playerPair'],
        'bankerPair' => $round['bankerPair'],
        default => false,
    };
    if ($isWin) {
        return $amount * (1 + HBHUB_BACCARAT_PAYS[$spot]) - ($spot === 'banker' ? hbHubBaccaratCommission($amount) : 0);
    }
    return $round['winner'] === 'tie' && in_array($spot, ['player', 'banker'], true) ? $amount : 0;
}

/**
 * The commission on a winning Banker bet of $amount: 2.7% of it, rounded to the nearest whole chip (so a bet of 10
 * pays 10, 20 pays 19 and 100 pays 97).
 */
function hbHubBaccaratCommission(int $amount): int
{
    return intdiv($amount * HBHUB_BACCARAT_BANKER_COMMISSION + 500, 1000);
}

/**
 * Stores how the round went for the player (HBHub-BaccaratPlayerRounds), for the leaderboard and any stats later.
 * The result is whether their bets won or lost chips together, so a winning tie bet with a lost pair bet is a win.
 */
function hbHubBaccaratRecordPlayer(PDO $db, array $room, array $player, int $playerCount): void
{
    $result = match (true) {
        $player['net'] > 0 => 'win',
        $player['net'] < 0 => 'loss',
        default => 'push',
    };

    $db->prepare('INSERT INTO `HBHub-BaccaratPlayerRounds` (roundId, roomId, roomType, userId, seatNumber, playersInRound,
            playerBetPlayer, playerBetBanker, playerBetTie, playerBetPlayerPair, playerBetBankerPair,
            playerWagered, playerPayout, playerNet, playerResult, playerTimestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([
            $room['state']['round']['id'],
            $room['roomId'],
            $room['roomType'],
            $player['userId'],
            $player['seat'],
            $playerCount,
            $player['spots']['player'] ?? 0,
            $player['spots']['banker'] ?? 0,
            $player['spots']['tie'] ?? 0,
            $player['spots']['playerPair'] ?? 0,
            $player['spots']['bankerPair'] ?? 0,
            $player['wagered'],
            $player['payout'],
            $player['net'],
            $result,
        ]);
}

/**
 * The table as $viewerId may see it, for baccaratTable.js: without the shoe, and only the cards that are turned over.
 * Everyone sees everyone's bets, as at a real table.
 *
 * @param array<int, array{name: string, avatar: ?string}> $people everyone at the table or in the hand, by userId
 * @param ?int $hostUserId the room's host, who can invite people
 */
function hbHubBaccaratTableView(array $state, array $seats, array $people, int $viewerId, ?int $hostUserId, int $now): array
{
    $round = $state['round'];
    $playersHere = hbHubBaccaratPlayersHere($seats);

    $coup = null;
    $hiddenCards = 0;
    if ($round !== null) {
        $hands = ['player' => [], 'banker' => []];
        foreach (array_slice($round['order'], 0, $round['shown']) as $side) {
            $next = count($hands[$side]);
            $hands[$side][] = $round[$side][$next];
        }
        $hiddenCards = count($round['order']) - $round['shown'];
        $coup = [
            'player' => ['cards' => $hands['player'], 'total' => hbHubBaccaratTotal($hands['player'])],
            'banker' => ['cards' => $hands['banker'], 'total' => hbHubBaccaratTotal($hands['banker'])],
            // Both first cards are face up from the 4th card on, and a natural only depends on those
            'natural' => $round['shown'] >= 4 && (hbHubBaccaratIsNatural($round['player']) || hbHubBaccaratIsNatural($round['banker'])),
            'winner' => $round['winner'],
            'playerPair' => $round['playerPair'],
            'bankerPair' => $round['bankerPair'],
        ];
    }

    // Everyone sitting at the table, and anyone who left during the hand (their bets are still on the table)
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
        $p = $round !== null ? hbHubBaccaratPlayerIndex($round, $userId) : null;
        $betIndex = hbHubBaccaratBetIndex($state, $userId);
        $player = $p !== null ? $round['players'][$p] : null;
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
            'nextBets' => $betIndex !== null ? $state['bets'][$betIndex]['spots'] : null,
            'player' => $player !== null ? [
                'spots' => $player['spots'],
                'wagered' => $player['wagered'],
                'payouts' => $player['payouts'],
                'net' => $player['net'],
            ] : null,
        ];
    }

    $you = null;
    $yourSeat = hbHubBaccaratSeatOf($seats, $viewerId);
    if ($yourSeat !== null) {
        $betIndex = hbHubBaccaratBetIndex($state, $viewerId);
        $isBetting = in_array($state['phase'], ['waiting', 'betting', 'results'], true);
        $you = [
            'seat' => $yourSeat['seat'],
            'isHost' => $viewerId === $hostUserId,
            'sittingOut' => $yourSeat['sittingOut'],
            'nextBets' => $betIndex !== null ? $state['bets'][$betIndex]['spots'] : null,
            'canBet' => $isBetting && !$yourSeat['sittingOut'] && $betIndex === null && $playersHere >= HBHUB_BACCARAT_MIN_PLAYERS,
            'canClearBet' => in_array($state['phase'], ['betting', 'results'], true) && $betIndex !== null,
        ];
    }

    return [
        'phase' => $state['phase'],
        // The cards are timed too, so the table asks again as soon as the next one is turned over
        'timer' => $state['deadline'] !== null ? ['left' => max(0, $state['deadline'] - $now), 'total' => $state['timer']] : null,
        'roundId' => $round['id'] ?? null,
        'settled' => $round['settled'] ?? false,
        'coup' => $coup,
        // The cards still to be turned over count as in the shoe, so how many went doesn't give away a third card early
        'shoe' => ['left' => count($state['shoe']) + $hiddenCards, 'size' => HBHUB_BACCARAT_SHOE_SIZE, 'reshuffleAt' => HBHUB_BACCARAT_RESHUFFLE_LEFT],
        // "shuffled" until the next hand is dealt, then "firstHand" while that hand is dealt
        'shuffle' => match (true) {
            $state['shuffled'] => 'shuffled',
            $round !== null && $round['newShoe'] && !$round['settled'] => 'firstHand',
            default => null,
        },
        'playersHere' => $playersHere,
        'minPlayers' => HBHUB_BACCARAT_MIN_PLAYERS,
        'seats' => $seatViews,
        'you' => $you,
    ];
}

function hbHubBaccaratSetPhase(array &$state, string $phase, int $now, ?int $ms): void
{
    $state['phase'] = $phase;
    $state['deadline'] = $ms !== null ? $now + $ms : null;
    $state['timer'] = $ms;
}

/**
 * How many players are at the table and playing: not sitting out, and not away.
 */
function hbHubBaccaratPlayersHere(array $seats): int
{
    return count(array_filter($seats, static fn (array $seat): bool => !$seat['away'] && !$seat['sittingOut']));
}

/**
 * Whether everyone at the table who's playing (not sitting out, not away) has bet on the next hand.
 * False when there's nobody like that, so the betting timer decides.
 */
function hbHubBaccaratEveryoneBet(array $state, array $seats): bool
{
    $playing = 0;
    foreach ($seats as $seat) {
        if ($seat['away'] || $seat['sittingOut']) {
            continue;
        }
        if (hbHubBaccaratBetIndex($state, $seat['userId']) === null) {
            return false;
        }
        $playing++;
    }
    return $playing > 0;
}

function hbHubBaccaratSeatOf(array $seats, int $userId): ?array
{
    foreach ($seats as $seat) {
        if ($seat['userId'] === $userId) {
            return $seat;
        }
    }
    return null;
}

function hbHubBaccaratBetIndex(array $state, int $userId): ?int
{
    foreach ($state['bets'] as $index => $bet) {
        if ($bet['userId'] === $userId) {
            return $index;
        }
    }
    return null;
}

function hbHubBaccaratPlayerIndex(array $round, int $userId): ?int
{
    foreach ($round['players'] as $index => $player) {
        if ($player['userId'] === $userId) {
            return $index;
        }
    }
    return null;
}
