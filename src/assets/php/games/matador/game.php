<?php
// Matador's rules, played on a game's state (roomState in HBHub-MatadorRooms, saved as it is in HBHub-MatadorSaves).
// Nothing here touches the database: room.php loads the state, runs one of these on it and saves it again.
//
// The house rules (decided with the site's owner, they differ from the real game): one die, and a 6 gives another
// roll (as many as come, never jail for it). A property that isn't bought stays with the bank - there are no auctions.
// Getting out of jail is paying 1,000 kr, a "benådet" card, or rolling a 6 (3 tries, then the bail has to be paid).
//
// The state:
//   phase       'lobby' (waiting for players), 'resume' (a saved game waiting for its players), 'playing' or 'ended'
//   players     in turn order: seat (1-4, the seat in HBHub-MatadorSeats), userId (who plays it, null while it's
//               empty), originalUserId (who it's kept for when they leave), reservedFor (who the host gave it to),
//               name, cash, position (0-39), inJail, jailTries, cards (the "benådet" cards held), bankrupt
//   fields      by field number, the properties: owner (a seat or null for the bank), houses (5 is a hotel), mortgaged
//   deck        the Prøv lykken cards (cards.php) in the order they'll be drawn
//   turn        seat, id (goes up with every move, so a double click only counts once), step ('roll', 'wait' while
//               something has to be answered, 'end'), next (the step after that: 'roll' after a 6) and deadline (in
//               Unix milliseconds: when the player hasn't done anything by then, their turn is passed on for them)
//   pending     what the player whose turn it is has to answer: buy (field), tax, or debt (amount, to: a seat or null
//               for the bank, reason, move: steps to move once it's paid - after the third try in jail)
//   trades      offers between players: id, from, to, give and get ({fields, cash, cards})
//   lastRoll, lastCard  for matadorGame.js to show (n counts them, so it knows a new one from the last one). lastCard
//               gets over: true once the turn it was drawn in has ended, and isn't shown anymore
//   log         what happened, the newest last
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/cards.php';

const HBHUB_MATADOR_MAX_PLAYERS = 4;
const HBHUB_MATADOR_MIN_PLAYERS = 2;
const HBHUB_MATADOR_LOG_KEPT = 40;
const HBHUB_MATADOR_TURN_SECONDS = 30; // the time a player has to do something in their turn, before it's passed on

function hbHubMatadorNewState(string $roomName, int $roomType): array
{
    return [
        'phase' => 'lobby',
        'roomName' => $roomName, // to open the game again with, from a save
        'roomType' => $roomType,
        'players' => [],
        'fields' => [],
        'deck' => [],
        'turn' => null,
        'pending' => null,
        'trades' => [],
        'nextTradeId' => 1,
        'lastRoll' => null,
        'lastCard' => null,
        'log' => [],
        'logCount' => 0,
        'round' => 0,
        'winner' => null,
        'unsaved' => false, // the owner's save slot was taken by another game, so this one isn't saved anymore
    ];
}

function hbHubMatadorNewPlayer(int $seat, int $userId, string $name): array
{
    return [
        'seat' => $seat,
        'userId' => $userId,
        'originalUserId' => $userId,
        'reservedFor' => null,
        'name' => $name,
        'cash' => HBHUB_MATADOR_START_CASH,
        'position' => 0,
        'inJail' => false,
        'jailTries' => 0,
        'cards' => [],
        'bankrupt' => false,
    ];
}

/**
 * The list in a random order (random_int(), not shuffle(), as it decides who goes first and the cards).
 */
function hbHubMatadorShuffle(array $list): array
{
    $list = array_values($list);
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
    }
    return $list;
}

/**
 * Starts a new game with the players in the lobby: the turn order is drawn, the deck shuffled, and the first player rolls.
 */
function hbHubMatadorBegin(array &$state): void
{
    $state['players'] = hbHubMatadorShuffle($state['players']);
    $state['fields'] = [];
    foreach (array_keys(HBHUB_MATADOR_FIELDS) as $field) {
        if (hbHubMatadorIsProperty($field)) {
            $state['fields'][$field] = ['owner' => null, 'houses' => 0, 'mortgaged' => false];
        }
    }
    $state['deck'] = hbHubMatadorShuffle(array_keys(HBHUB_MATADOR_CARDS));
    $state['phase'] = 'playing';
    $state['round'] = 1;
    $first = $state['players'][0]['seat'];
    $state['turn'] = ['seat' => $first, 'id' => 1, 'step' => 'roll', 'next' => 'end'];
    hbHubMatadorResetTimer($state);
    hbHubMatadorLog($state, 'The game has started. ' . hbHubMatadorName($state, $first) . ' goes first.');
}

function hbHubMatadorLog(array &$state, string $text): void
{
    $state['logCount']++;
    $state['log'][] = ['n' => $state['logCount'], 'text' => $text];
    if (count($state['log']) > HBHUB_MATADOR_LOG_KEPT) {
        $state['log'] = array_slice($state['log'], -HBHUB_MATADOR_LOG_KEPT);
    }
}

/**
 * Where the seat's player is in $state['players'], or null when there's no such player.
 */
function hbHubMatadorIndex(array $state, int $seat): ?int
{
    foreach ($state['players'] as $i => $player) {
        if ($player['seat'] === $seat) {
            return $i;
        }
    }
    return null;
}

function hbHubMatadorPlayerOf(array $state, int $seat): ?array
{
    $i = hbHubMatadorIndex($state, $seat);
    return $i !== null ? $state['players'][$i] : null;
}

/**
 * The seat the user plays, or null when they don't play one.
 */
function hbHubMatadorSeatOfUser(array $state, int $userId): ?int
{
    foreach ($state['players'] as $player) {
        if ($player['userId'] === $userId) {
            return $player['seat'];
        }
    }
    return null;
}

function hbHubMatadorName(array $state, int $seat): string
{
    return hbHubMatadorPlayerOf($state, $seat)['name'] ?? 'Someone';
}

function hbHubMatadorFieldName(int $field): string
{
    return HBHUB_MATADOR_FIELDS[$field]['name'];
}

/**
 * The players still in the game (not bankrupt).
 *
 * @return list<array>
 */
function hbHubMatadorActivePlayers(array $state): array
{
    return array_values(array_filter($state['players'], static fn (array $player): bool => !$player['bankrupt']));
}

/**
 * How many houses and hotels the bank has left.
 *
 * @return array{houses: int, hotels: int}
 */
function hbHubMatadorBank(array $state): array
{
    $houses = HBHUB_MATADOR_BANK_HOUSES;
    $hotels = HBHUB_MATADOR_BANK_HOTELS;
    foreach ($state['fields'] as $property) {
        if ($property['houses'] === HBHUB_MATADOR_HOTEL) {
            $hotels--;
        } else {
            $houses -= $property['houses'];
        }
    }
    return ['houses' => $houses, 'hotels' => $hotels];
}

/**
 * What the player is worth: cash, their properties (half when mortgaged) and what their houses and hotels cost.
 * The 10 % income tax and the Matador-legat go by this.
 */
function hbHubMatadorWorth(array $state, int $seat): int
{
    $worth = hbHubMatadorPlayerOf($state, $seat)['cash'] ?? 0;
    foreach ($state['fields'] as $field => $property) {
        if ($property['owner'] !== $seat) {
            continue;
        }
        $info = HBHUB_MATADOR_FIELDS[$field];
        $worth += $property['mortgaged'] ? hbHubMatadorMortgageValue($field) : $info['price'];
        $worth += $property['houses'] * ($info['house'] ?? 0);
    }
    return $worth;
}

/**
 * Whether one player owns every field in the group.
 */
function hbHubMatadorOwnsGroup(array $state, int $seat, int|string $group): bool
{
    foreach (hbHubMatadorGroupFields($group) as $field) {
        if ($state['fields'][$field]['owner'] !== $seat) {
            return false;
        }
    }
    return true;
}

function hbHubMatadorGroupHasBuildings(array $state, int|string $group): bool
{
    foreach (hbHubMatadorGroupFields($group) as $field) {
        if ($state['fields'][$field]['houses'] > 0) {
            return true;
        }
    }
    return false;
}

/**
 * The rent for landing on the property (owned and not mortgaged). $roll is what was rolled, for the breweries,
 * and $multiplier doubles it for the "nearest ferry" card.
 */
function hbHubMatadorRent(array $state, int $field, int $roll, int $multiplier = 1): int
{
    $info = HBHUB_MATADOR_FIELDS[$field];
    $property = $state['fields'][$field];
    $owner = $property['owner'];

    if ($info['type'] === 'street') {
        if ($property['houses'] > 0) {
            return $info['rent'][$property['houses']] * $multiplier;
        }
        $double = hbHubMatadorOwnsGroup($state, $owner, $info['group']) ? 2 : 1; // the whole group, unbuilt
        return $info['rent'][0] * $double * $multiplier;
    }

    $owned = count(array_filter(hbHubMatadorGroupFields($info['group']),
        static fn (int $other): bool => $state['fields'][$other]['owner'] === $owner));
    return match ($info['type']) {
        'ferry' => HBHUB_MATADOR_FERRY_RENT[$owned - 1] * $multiplier,
        'brewery' => HBHUB_MATADOR_BREWERY_RENT[$owned - 1] * $roll * $multiplier,
    };
}

/**
 * The player whose turn it is pays $amount to the seat $to (or the bank), when they have it. When they don't, it's
 * owed (pending debt) until they raise the money or go bankrupt, and $move is how far they move once it's paid.
 */
function hbHubMatadorOwe(array &$state, int $seat, int $amount, ?int $to, string $reason, ?int $move = null): void
{
    $i = hbHubMatadorIndex($state, $seat);
    if ($state['players'][$i]['cash'] >= $amount) {
        hbHubMatadorTransfer($state, $seat, $to, $amount);
        if ($move !== null) {
            hbHubMatadorMoveBy($state, $seat, $move);
        }
        return;
    }

    $state['pending'] = ['type' => 'debt', 'amount' => $amount, 'to' => $to, 'reason' => $reason, 'move' => $move];
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' owes ' . hbHubMatadorKr($amount) . ' for ' . $reason
        . ' and has to raise the money.');
}

/**
 * Moves money from a player to another player, or to the bank ($to null). The payer has to have it.
 */
function hbHubMatadorTransfer(array &$state, int $from, ?int $to, int $amount): void
{
    $state['players'][hbHubMatadorIndex($state, $from)]['cash'] -= $amount;
    if ($to !== null) {
        $state['players'][hbHubMatadorIndex($state, $to)]['cash'] += $amount;
    }
}

/**
 * Moves the player forward $steps fields and lands them there.
 */
function hbHubMatadorMoveBy(array &$state, int $seat, int $steps): void
{
    $position = $state['players'][hbHubMatadorIndex($state, $seat)]['position'];
    hbHubMatadorMoveTo($state, $seat, ($position + $steps) % HBHUB_MATADOR_FIELD_COUNT);
}

/**
 * Moves the player forward to the field (4,000 kr when they pass or land on START) and lands them there.
 */
function hbHubMatadorMoveTo(array &$state, int $seat, int $field, int $multiplier = 1): void
{
    $i = hbHubMatadorIndex($state, $seat);
    if ($field <= $state['players'][$i]['position']) {
        $state['players'][$i]['cash'] += HBHUB_MATADOR_START_PAY;
        hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ($field === 0 ? ' landed on' : ' passed')
            . ' START and got ' . hbHubMatadorKr(HBHUB_MATADOR_START_PAY) . '.');
    }
    $state['players'][$i]['position'] = $field;
    hbHubMatadorLand($state, $seat, $multiplier);
}

/**
 * What happens on the field the player has just moved to.
 */
function hbHubMatadorLand(array &$state, int $seat, int $multiplier = 1): void
{
    $field = $state['players'][hbHubMatadorIndex($state, $seat)]['position'];
    $info = HBHUB_MATADOR_FIELDS[$field];
    $name = hbHubMatadorName($state, $seat);

    if (hbHubMatadorIsProperty($field)) {
        $property = $state['fields'][$field];
        if ($property['owner'] === null) {
            $state['pending'] = ['type' => 'buy', 'field' => $field];
        } elseif ($property['owner'] !== $seat) {
            if ($property['mortgaged']) {
                hbHubMatadorLog($state, $info['name'] . ' is mortgaged, so ' . $name . ' pays no rent.');
                return;
            }
            $rent = hbHubMatadorRent($state, $field, $state['lastRoll']['value'] ?? 1, $multiplier);
            $owner = hbHubMatadorName($state, $property['owner']);
            hbHubMatadorLog($state, $name . ' pays ' . $owner . ' ' . hbHubMatadorKr($rent) . ' in rent for ' . $info['name'] . '.');
            hbHubMatadorOwe($state, $seat, $rent, $property['owner'], 'rent to ' . $owner);
        }
        return;
    }

    match ($info['type']) {
        'chance' => hbHubMatadorDrawCard($state, $seat),
        'incomeTax' => $state['pending'] = ['type' => 'tax'],
        'extraTax' => hbHubMatadorPayBank($state, $seat, $info['amount'], 'Ekstraordinær statsskat'),
        'goToJail' => hbHubMatadorJail($state, $seat),
        default => null, // START, jail (just visiting) and Parkering: nothing happens
    };
}

function hbHubMatadorPayBank(array &$state, int $seat, int $amount, string $reason): void
{
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' pays ' . hbHubMatadorKr($amount) . ' (' . $reason . ').');
    hbHubMatadorOwe($state, $seat, $amount, null, $reason);
}

/**
 * Straight to jail, without passing START. A 6 rolled on the way doesn't give another roll.
 */
function hbHubMatadorJail(array &$state, int $seat): void
{
    $i = hbHubMatadorIndex($state, $seat);
    $state['players'][$i]['position'] = HBHUB_MATADOR_JAIL_FIELD;
    $state['players'][$i]['inJail'] = true;
    $state['players'][$i]['jailTries'] = 0;
    if (($state['turn']['seat'] ?? null) === $seat) {
        $state['turn']['next'] = 'end';
    }
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' goes to jail.');
}

/**
 * Draws the top Prøv lykken card and does what it says.
 */
function hbHubMatadorDrawCard(array &$state, int $seat): void
{
    $card = array_shift($state['deck']);
    $state['lastCard'] = ['seat' => $seat, 'card' => $card, 'n' => ($state['lastCard']['n'] ?? 0) + 1];
    [$do, $value, $perHotel] = array_pad(HBHUB_MATADOR_CARDS[$card]['do'], 3, 0); // $value: a field, steps or an amount
    $i = hbHubMatadorIndex($state, $seat);
    $name = hbHubMatadorName($state, $seat);
    hbHubMatadorLog($state, $name . ' drew Prøv lykken: "' . HBHUB_MATADOR_CARDS[$card]['text'] . '"');

    if ($do === 'jailCard') {
        $state['players'][$i]['cards'][] = $card; // kept until it's used
    } else {
        $state['deck'][] = $card;
    }

    switch ($do) {
        case 'move':
            hbHubMatadorMoveTo($state, $seat, $value);
            break;
        case 'back':
            $state['players'][$i]['position'] = ($state['players'][$i]['position'] - $value + HBHUB_MATADOR_FIELD_COUNT) % HBHUB_MATADOR_FIELD_COUNT;
            hbHubMatadorLand($state, $seat);
            break;
        case 'nearestFerry':
            $position = $state['players'][$i]['position'];
            $ferries = hbHubMatadorGroupFields('ferry');
            $next = array_values(array_filter($ferries, static fn (int $ferry): bool => $ferry > $position))[0] ?? $ferries[0];
            hbHubMatadorMoveTo($state, $seat, $next, 2);
            break;
        case 'jail':
            hbHubMatadorJail($state, $seat);
            break;
        case 'pay':
            hbHubMatadorOwe($state, $seat, $value, null, 'Prøv lykken');
            break;
        case 'receive':
            $state['players'][$i]['cash'] += $value;
            break;
        case 'repairs':
            $cost = 0;
            foreach ($state['fields'] as $property) {
                if ($property['owner'] === $seat) {
                    $cost += $property['houses'] === HBHUB_MATADOR_HOTEL ? $perHotel : $property['houses'] * $value;
                }
            }
            if ($cost > 0) {
                hbHubMatadorLog($state, $name . ' pays ' . hbHubMatadorKr($cost) . ' for their houses and hotels.');
                hbHubMatadorOwe($state, $seat, $cost, null, 'Prøv lykken');
            }
            break;
        case 'fromEach':
            foreach ($state['players'] as $other) {
                if ($other['seat'] !== $seat && !$other['bankrupt']) {
                    hbHubMatadorCollect($state, $other['seat'], $seat, $value);
                }
            }
            break;
        case 'legat':
            if (hbHubMatadorWorth($state, $seat) <= HBHUB_MATADOR_LEGAT_LIMIT) {
                $state['players'][$i]['cash'] += HBHUB_MATADOR_LEGAT;
                hbHubMatadorLog($state, $name . ' gets the Matador-legat: ' . hbHubMatadorKr(HBHUB_MATADOR_LEGAT) . '!');
            } else {
                hbHubMatadorLog($state, $name . ' is worth more than ' . hbHubMatadorKr(HBHUB_MATADOR_LEGAT_LIMIT) . ', so no legat.');
            }
            break;
    }
}

/**
 * Another player than the one whose turn it is pays $amount to $to (a Prøv lykken card). They can't be asked to raise
 * the money in the middle of someone else's turn, so it's raised for them (hbHubMatadorRaise()), and when even that
 * isn't enough, they go bankrupt to $to.
 */
function hbHubMatadorCollect(array &$state, int $from, int $to, int $amount): void
{
    hbHubMatadorRaise($state, $from, $amount);
    if ($state['players'][hbHubMatadorIndex($state, $from)]['cash'] >= $amount) {
        hbHubMatadorTransfer($state, $from, $to, $amount);
    } else {
        hbHubMatadorBankrupt($state, $from, $to);
    }
}

/**
 * Raises money for a player who isn't there to do it themselves: sells houses and hotels (from the most built street
 * first, so they stay even), then mortgages properties (the cheapest first), until they have $amount or nothing is left.
 */
function hbHubMatadorRaise(array &$state, int $seat, int $amount): void
{
    $i = hbHubMatadorIndex($state, $seat);
    while ($state['players'][$i]['cash'] < $amount) {
        $built = null;
        $mortgage = null;
        foreach ($state['fields'] as $field => $property) {
            if ($property['owner'] !== $seat) {
                continue;
            }
            if ($property['houses'] > 0 && ($built === null || $property['houses'] > $state['fields'][$built]['houses'])) {
                $built = $field;
            }
            $canMortgage = !$property['mortgaged'] && !hbHubMatadorGroupHasBuildings($state, HBHUB_MATADOR_FIELDS[$field]['group']);
            if ($canMortgage && ($mortgage === null || HBHUB_MATADOR_FIELDS[$field]['price'] < HBHUB_MATADOR_FIELDS[$mortgage]['price'])) {
                $mortgage = $field;
            }
        }

        $problem = match (true) {
            $built !== null => hbHubMatadorSell($state, $seat, $built),
            $mortgage !== null => hbHubMatadorMortgage($state, $seat, $mortgage),
            default => 'Nothing left.',
        };
        if ($problem !== null) {
            return;
        }
    }
}

/**
 * The player goes bankrupt to the seat $to (who gets their cash, properties and cards, with their buildings sold back
 * to the bank first), or to the bank ($to null: their properties go back to the bank, unbuilt and unmortgaged).
 */
function hbHubMatadorBankrupt(array &$state, int $seat, ?int $to): void
{
    $i = hbHubMatadorIndex($state, $seat);
    $creditor = $to !== null ? hbHubMatadorIndex($state, $to) : null;
    if ($creditor !== null && $state['players'][$creditor]['bankrupt']) {
        $creditor = null;
    }

    foreach ($state['fields'] as $field => &$property) {
        if ($property['owner'] !== $seat) {
            continue;
        }
        $state['players'][$i]['cash'] += $property['houses'] * intdiv(HBHUB_MATADOR_FIELDS[$field]['house'] ?? 0, 2);
        $property['houses'] = 0;
        if ($creditor !== null) {
            $property['owner'] = $to;
        } else {
            $property['owner'] = null;
            $property['mortgaged'] = false;
        }
    }
    unset($property);

    $name = hbHubMatadorName($state, $seat);
    if ($creditor !== null) {
        $state['players'][$creditor]['cash'] += $state['players'][$i]['cash'];
        array_push($state['players'][$creditor]['cards'], ...$state['players'][$i]['cards']);
        hbHubMatadorLog($state, $name . ' is bankrupt. Everything they had goes to ' . hbHubMatadorName($state, $to) . '.');
    } else {
        array_push($state['deck'], ...$state['players'][$i]['cards']);
        hbHubMatadorLog($state, $name . ' is bankrupt. Their properties go back to the bank.');
    }

    $state['players'][$i]['cash'] = 0;
    $state['players'][$i]['cards'] = [];
    $state['players'][$i]['inJail'] = false;
    $state['players'][$i]['bankrupt'] = true;
    $state['trades'] = array_values(array_filter($state['trades'],
        static fn (array $trade): bool => $trade['from'] !== $seat && $trade['to'] !== $seat));

    $isTheirTurn = ($state['turn']['seat'] ?? null) === $seat;
    if ($isTheirTurn) {
        $state['pending'] = null;
    }
    hbHubMatadorCheckWinner($state);
    if ($isTheirTurn && $state['phase'] === 'playing') {
        hbHubMatadorNextTurn($state);
    }
}

/**
 * Ends the game once only one player is left.
 */
function hbHubMatadorCheckWinner(array &$state): void
{
    $left = hbHubMatadorActivePlayers($state);
    if ($state['phase'] !== 'playing' || count($left) > 1) {
        return;
    }
    $state['phase'] = 'ended';
    $state['winner'] = $left[0]['seat'] ?? null;
    $state['pending'] = null;
    $state['trades'] = [];
    if ($state['winner'] !== null) {
        hbHubMatadorLog($state, $left[0]['name'] . ' has won the game!');
    }
}

/**
 * The next player still in the game rolls.
 */
function hbHubMatadorNextTurn(array &$state): void
{
    $count = count($state['players']);
    $current = hbHubMatadorIndex($state, $state['turn']['seat']) ?? 0;
    for ($step = 1; $step <= $count; $step++) {
        $i = ($current + $step) % $count;
        if (!$state['players'][$i]['bankrupt']) {
            if ($i <= $current) {
                $state['round']++;
            }
            $state['turn'] = ['seat' => $state['players'][$i]['seat'], 'id' => $state['turn']['id'] + 1, 'step' => 'roll', 'next' => 'end'];
            hbHubMatadorResetTimer($state);
            $state['pending'] = null;
            if ($state['lastCard'] !== null) {
                $state['lastCard']['over'] = true; // only shown in the turn it was drawn in (its n still counts on)
            }
            return;
        }
    }
}

/**
 * Once nothing is left to answer, the turn goes on: another roll after a 6, otherwise the player can end their turn.
 */
function hbHubMatadorSettle(array &$state): void
{
    if ($state['phase'] === 'playing' && $state['pending'] === null && $state['turn']['step'] === 'wait') {
        $state['turn']['step'] = $state['turn']['next'];
    }
}

function hbHubMatadorNowMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

/**
 * Gives the player whose turn it is HBHUB_MATADOR_TURN_SECONDS again, from now: when their turn starts, and whenever
 * they do something in the game (play.php).
 */
function hbHubMatadorResetTimer(array &$state): void
{
    $state['turn']['deadline'] = hbHubMatadorNowMs() + HBHUB_MATADOR_TURN_SECONDS * 1000;
}

/**
 * The seat's player did something in the game (play.php): when it's their turn, they aren't AFK, so their time starts again.
 */
function hbHubMatadorPlayerActed(array &$state, int $seat): void
{
    if ($state['phase'] === 'playing' && $state['turn']['seat'] === $seat) {
        hbHubMatadorResetTimer($state);
    }
}

/**
 * Whether the timer is paused: the player whose turn it is owes money, and the game waits for them to raise it or go
 * bankrupt. Paying it is a move, so they get their full time again after it.
 */
function hbHubMatadorTimerPaused(array $state): bool
{
    return ($state['pending']['type'] ?? null) === 'debt';
}

/**
 * Whether the player whose turn it is has run out of time. Also true for a game from before the timer, which has no
 * deadline yet, so hbHubMatadorAdvance() gives it one.
 */
function hbHubMatadorTimeUp(array $state): bool
{
    return $state['phase'] === 'playing' && !hbHubMatadorTimerPaused($state)
        && ($state['turn']['deadline'] ?? 0) <= hbHubMatadorNowMs();
}

/**
 * Moves the game on past players who aren't there or are AFK: when the player whose turn it is has an empty seat (they
 * left or have been gone too long), or hasn't done anything for HBHUB_MATADOR_TURN_SECONDS, what they had to answer is
 * answered for them - they don't buy, pay the cheaper tax, and raise what they owe (hbHubMatadorRaise()) - and their
 * turn is passed on. An empty seat waits when nobody still in the game is there. A player who's there is never made to
 * raise money or go bankrupt: when they owe money (also from the tax paid for them), the game waits for them.
 */
function hbHubMatadorAdvance(array &$state): void
{
    if ($state['phase'] === 'playing' && !isset($state['turn']['deadline'])) {
        hbHubMatadorResetTimer($state); // a game from before the timer
    }
    for ($guard = 0; $guard < 50 && $state['phase'] === 'playing'; $guard++) {
        $player = hbHubMatadorPlayerOf($state, $state['turn']['seat']);
        $isHere = $player !== null && $player['userId'] !== null && !$player['bankrupt'];
        if ($isHere && !hbHubMatadorTimeUp($state)) {
            return;
        }
        $anyoneHere = array_filter(hbHubMatadorActivePlayers($state), static fn (array $other): bool => $other['userId'] !== null);
        if ($anyoneHere === []) {
            return;
        }

        $seat = $state['turn']['seat'];
        for ($answered = 0; $answered < 20 && $state['phase'] === 'playing' && $state['pending'] !== null && $state['turn']['seat'] === $seat; $answered++) {
            $pending = $state['pending'];
            if ($isHere && $pending['type'] === 'debt') {
                return; // the timer is paused until they've paid (hbHubMatadorTimerPaused())
            }
            $state['pending'] = null;
            match ($pending['type']) {
                'buy' => hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' didn\'t buy ' . hbHubMatadorFieldName($pending['field'])
                    . '. It stays with the bank.'),
                'tax' => hbHubMatadorPayBank($state, $seat, min(HBHUB_MATADOR_TAX_FIXED, hbHubMatadorPercentTax($state, $seat)), 'Indkomstskat'),
                'debt' => hbHubMatadorRaiseAndPay($state, $seat, $pending),
            };
        }
        if ($state['phase'] === 'playing' && $state['turn']['seat'] === $seat) {
            if ($isHere) {
                hbHubMatadorLog($state, $player['name'] . ' didn\'t do anything for ' . HBHUB_MATADOR_TURN_SECONDS
                    . ' seconds, so their turn is passed on.');
            } elseif ($player !== null && !$player['bankrupt']) {
                hbHubMatadorLog($state, $player['name'] . ' isn\'t here, so their turn is skipped.');
            }
            hbHubMatadorNextTurn($state);
        }
    }
}

function hbHubMatadorRaiseAndPay(array &$state, int $seat, array $debt): void
{
    hbHubMatadorRaise($state, $seat, $debt['amount']);
    if ($state['players'][hbHubMatadorIndex($state, $seat)]['cash'] < $debt['amount']) {
        hbHubMatadorBankrupt($state, $seat, $debt['to']);
        return;
    }
    hbHubMatadorTransfer($state, $seat, $debt['to'], $debt['amount']);
    if ($debt['move'] !== null) {
        hbHubMatadorMoveBy($state, $seat, $debt['move']);
    }
}

function hbHubMatadorPercentTax(array $state, int $seat): int
{
    return intdiv(hbHubMatadorWorth($state, $seat) * HBHUB_MATADOR_TAX_PERCENT, 100);
}

// ----- What the players do. Each returns null when it was done, otherwise why not (to show them).

/**
 * Why the seat can't do a move in their turn right now (or null when they can): it has to be their turn, in $step.
 */
function hbHubMatadorTurnProblem(array $state, int $seat, ?string $step = null, ?string $pending = null): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    if ($state['turn']['seat'] !== $seat) {
        return 'It isn\'t your turn.';
    }
    if ($pending !== null) {
        return ($state['pending']['type'] ?? null) === $pending ? null : 'That can\'t be done right now.';
    }
    if ($state['pending'] !== null) {
        return 'Answer what\'s asked first.';
    }
    return $step === null || $state['turn']['step'] === $step ? null : 'That can\'t be done right now.';
}

function hbHubMatadorRoll(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, 'roll');
    if ($problem !== null) {
        return $problem;
    }

    $value = random_int(1, 6);
    $state['lastRoll'] = ['seat' => $seat, 'value' => $value, 'n' => ($state['lastRoll']['n'] ?? 0) + 1];
    $state['turn']['step'] = 'wait';
    $i = hbHubMatadorIndex($state, $seat);
    $name = $state['players'][$i]['name'];

    if ($state['players'][$i]['inJail']) {
        if ($value === 6) {
            $state['players'][$i]['inJail'] = false;
            $state['players'][$i]['jailTries'] = 0;
            $state['turn']['next'] = 'roll';
            hbHubMatadorLog($state, $name . ' rolled a 6 and is out of jail.');
            hbHubMatadorMoveBy($state, $seat, $value);
        } elseif (++$state['players'][$i]['jailTries'] < HBHUB_MATADOR_JAIL_TRIES) {
            $state['turn']['next'] = 'end';
            hbHubMatadorLog($state, $name . ' rolled a ' . $value . ' and stays in jail.');
        } else {
            $state['players'][$i]['inJail'] = false;
            $state['players'][$i]['jailTries'] = 0;
            $state['turn']['next'] = 'end';
            hbHubMatadorLog($state, $name . ' rolled a ' . $value . ' on their last try, so they pay '
                . hbHubMatadorKr(HBHUB_MATADOR_BAIL) . ' to get out of jail.');
            hbHubMatadorOwe($state, $seat, HBHUB_MATADOR_BAIL, null, 'getting out of jail', $value);
        }
    } else {
        $state['turn']['next'] = $value === 6 ? 'roll' : 'end';
        $target = ($state['players'][$i]['position'] + $value) % HBHUB_MATADOR_FIELD_COUNT;
        hbHubMatadorLog($state, $name . ' rolled a ' . $value . ' and moves to ' . hbHubMatadorFieldName($target) . '.');
        hbHubMatadorMoveBy($state, $seat, $value);
    }

    hbHubMatadorSettle($state);
    return null;
}

function hbHubMatadorBuy(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, null, 'buy');
    if ($problem !== null) {
        return $problem;
    }
    $field = $state['pending']['field'];
    $price = HBHUB_MATADOR_FIELDS[$field]['price'];
    if ($state['players'][hbHubMatadorIndex($state, $seat)]['cash'] < $price) {
        return 'You don\'t have ' . hbHubMatadorKr($price) . '. Mortgage or sell something first, or leave it with the bank.';
    }

    hbHubMatadorTransfer($state, $seat, null, $price);
    $state['fields'][$field]['owner'] = $seat;
    $state['pending'] = null;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' bought ' . hbHubMatadorFieldName($field) . ' for ' . hbHubMatadorKr($price) . '.');
    hbHubMatadorSettle($state);
    return null;
}

function hbHubMatadorDecline(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, null, 'buy');
    if ($problem !== null) {
        return $problem;
    }
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' didn\'t buy ' . hbHubMatadorFieldName($state['pending']['field'])
        . '. It stays with the bank.');
    $state['pending'] = null;
    hbHubMatadorSettle($state);
    return null;
}

/**
 * Income tax: 4,000 kr ('fixed') or 10 % of what the player is worth ('percent'), chosen before it's worked out.
 */
function hbHubMatadorTax(array &$state, int $seat, mixed $choice): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, null, 'tax');
    if ($problem !== null) {
        return $problem;
    }
    if (!in_array($choice, ['fixed', 'percent'], true)) {
        return 'Choose how to pay the tax.';
    }

    $state['pending'] = null;
    if ($choice === 'percent') {
        hbHubMatadorPayBank($state, $seat, hbHubMatadorPercentTax($state, $seat), 'Indkomstskat, ' . HBHUB_MATADOR_TAX_PERCENT . ' %');
    } else {
        hbHubMatadorPayBank($state, $seat, HBHUB_MATADOR_TAX_FIXED, 'Indkomstskat');
    }
    hbHubMatadorSettle($state);
    return null;
}

function hbHubMatadorPayDebt(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, null, 'debt');
    if ($problem !== null) {
        return $problem;
    }
    $debt = $state['pending'];
    if ($state['players'][hbHubMatadorIndex($state, $seat)]['cash'] < $debt['amount']) {
        return 'You don\'t have ' . hbHubMatadorKr($debt['amount']) . ' yet. Sell houses, mortgage or trade to raise it.';
    }

    $state['pending'] = null;
    hbHubMatadorTransfer($state, $seat, $debt['to'], $debt['amount']);
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' paid the ' . hbHubMatadorKr($debt['amount']) . ' they owed.');
    if ($debt['move'] !== null) {
        hbHubMatadorMoveBy($state, $seat, $debt['move']);
    }
    hbHubMatadorSettle($state);
    return null;
}

/**
 * Gives up when the player can't pay what they owe (hbHubMatadorBankrupt()).
 */
function hbHubMatadorDeclareBankruptcy(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, null, 'debt');
    if ($problem !== null) {
        return $problem;
    }
    hbHubMatadorBankrupt($state, $seat, $state['pending']['to']);
    return null;
}

function hbHubMatadorPayBail(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, 'roll');
    $i = hbHubMatadorIndex($state, $seat);
    if ($problem === null && !$state['players'][$i]['inJail']) {
        $problem = 'You aren\'t in jail.';
    }
    if ($problem !== null) {
        return $problem;
    }
    if ($state['players'][$i]['cash'] < HBHUB_MATADOR_BAIL) {
        return 'You don\'t have ' . hbHubMatadorKr(HBHUB_MATADOR_BAIL) . '.';
    }

    hbHubMatadorTransfer($state, $seat, null, HBHUB_MATADOR_BAIL);
    $state['players'][$i]['inJail'] = false;
    $state['players'][$i]['jailTries'] = 0;
    hbHubMatadorLog($state, $state['players'][$i]['name'] . ' paid ' . hbHubMatadorKr(HBHUB_MATADOR_BAIL) . ' to get out of jail.');
    return null;
}

function hbHubMatadorUseCard(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, 'roll');
    $i = hbHubMatadorIndex($state, $seat);
    if ($problem === null && !$state['players'][$i]['inJail']) {
        $problem = 'You aren\'t in jail.';
    }
    if ($problem === null && $state['players'][$i]['cards'] === []) {
        $problem = 'You don\'t have a card to get out of jail with.';
    }
    if ($problem !== null) {
        return $problem;
    }

    $state['deck'][] = array_pop($state['players'][$i]['cards']);
    $state['players'][$i]['inJail'] = false;
    $state['players'][$i]['jailTries'] = 0;
    hbHubMatadorLog($state, $state['players'][$i]['name'] . ' used their card and is out of jail.');
    return null;
}

function hbHubMatadorEndTurn(array &$state, int $seat): ?string
{
    $problem = hbHubMatadorTurnProblem($state, $seat, 'end');
    if ($problem !== null) {
        return $problem;
    }
    hbHubMatadorNextTurn($state);
    return null;
}

// ----- Building and mortgaging: any time during the game, also in other players' turns.

/**
 * Why the seat can't do anything with the property (or null when they can): it has to be theirs, during the game.
 */
function hbHubMatadorPropertyProblem(array $state, int $seat, mixed $field): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    if (!is_int($field) || !isset($state['fields'][$field])) {
        return 'That isn\'t a property.';
    }
    if ($state['fields'][$field]['owner'] !== $seat) {
        return hbHubMatadorFieldName($field) . ' isn\'t yours.';
    }
    return null;
}

function hbHubMatadorBuild(array &$state, int $seat, mixed $field): ?string
{
    $problem = hbHubMatadorPropertyProblem($state, $seat, $field);
    if ($problem !== null) {
        return $problem;
    }
    $info = HBHUB_MATADOR_FIELDS[$field];
    if ($info['type'] !== 'street') {
        return 'You can only build on streets.';
    }
    if (($state['pending']['type'] ?? null) === 'debt' && $state['turn']['seat'] === $seat) {
        return 'Pay what you owe first.';
    }
    if (!hbHubMatadorOwnsGroup($state, $seat, $info['group'])) {
        return 'You need every street in the group to build on it.';
    }

    $levels = [];
    foreach (hbHubMatadorGroupFields($info['group']) as $other) {
        if ($state['fields'][$other]['mortgaged']) {
            return 'Pay back the mortgages in the group first.';
        }
        $levels[] = $state['fields'][$other]['houses'];
    }
    $houses = $state['fields'][$field]['houses'];
    if ($houses === HBHUB_MATADOR_HOTEL) {
        return $info['name'] . ' already has a hotel.';
    }
    if ($houses > min($levels)) {
        return 'Build on the other streets in the group first. Houses go up evenly.';
    }

    $bank = hbHubMatadorBank($state);
    $isHotel = $houses === HBHUB_MATADOR_HOTEL - 1;
    if ($isHotel ? $bank['hotels'] < 1 : $bank['houses'] < 1) {
        return 'The bank has no ' . ($isHotel ? 'hotels' : 'houses') . ' left.';
    }
    if ($state['players'][hbHubMatadorIndex($state, $seat)]['cash'] < $info['house']) {
        return 'You don\'t have ' . hbHubMatadorKr($info['house']) . '.';
    }

    hbHubMatadorTransfer($state, $seat, null, $info['house']);
    $state['fields'][$field]['houses']++;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' built ' . ($isHotel ? 'a hotel' : 'a house') . ' on ' . $info['name'] . '.');
    return null;
}

/**
 * Sells a house (or the hotel) back to the bank at half price. A hotel goes back to 4 houses, or to none when the bank
 * doesn't have 4 houses to give back for it.
 */
function hbHubMatadorSell(array &$state, int $seat, mixed $field): ?string
{
    $problem = hbHubMatadorPropertyProblem($state, $seat, $field);
    if ($problem !== null) {
        return $problem;
    }
    $info = HBHUB_MATADOR_FIELDS[$field];
    $houses = $state['fields'][$field]['houses'];
    if ($houses === 0) {
        return 'There\'s nothing built on ' . $info['name'] . '.';
    }
    $most = max(array_map(static fn (int $other): int => $state['fields'][$other]['houses'], hbHubMatadorGroupFields($info['group'])));
    if ($houses < $most) {
        return 'Sell from the other streets in the group first. Houses come down evenly.';
    }

    $left = $houses - 1;
    if ($houses === HBHUB_MATADOR_HOTEL && hbHubMatadorBank($state)['houses'] < HBHUB_MATADOR_HOTEL - 1) {
        $left = 0;
    }
    $refund = ($houses - $left) * intdiv($info['house'], 2);
    $state['fields'][$field]['houses'] = $left;
    $state['players'][hbHubMatadorIndex($state, $seat)]['cash'] += $refund;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' sold ' . ($houses === HBHUB_MATADOR_HOTEL ? 'the hotel' : 'a house')
        . ' on ' . $info['name'] . ' for ' . hbHubMatadorKr($refund) . '.');
    return null;
}

function hbHubMatadorMortgage(array &$state, int $seat, mixed $field): ?string
{
    $problem = hbHubMatadorPropertyProblem($state, $seat, $field);
    if ($problem !== null) {
        return $problem;
    }
    if ($state['fields'][$field]['mortgaged']) {
        return hbHubMatadorFieldName($field) . ' is already mortgaged.';
    }
    if (hbHubMatadorGroupHasBuildings($state, HBHUB_MATADOR_FIELDS[$field]['group'])) {
        return 'Sell the houses in the group first.';
    }

    $value = hbHubMatadorMortgageValue($field);
    $state['fields'][$field]['mortgaged'] = true;
    $state['players'][hbHubMatadorIndex($state, $seat)]['cash'] += $value;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' mortgaged ' . hbHubMatadorFieldName($field) . ' for ' . hbHubMatadorKr($value) . '.');
    return null;
}

function hbHubMatadorUnmortgage(array &$state, int $seat, mixed $field): ?string
{
    $problem = hbHubMatadorPropertyProblem($state, $seat, $field);
    if ($problem !== null) {
        return $problem;
    }
    if (!$state['fields'][$field]['mortgaged']) {
        return hbHubMatadorFieldName($field) . ' isn\'t mortgaged.';
    }
    $cost = hbHubMatadorMortgageValue($field) + hbHubMatadorInterest($field);
    if ($state['players'][hbHubMatadorIndex($state, $seat)]['cash'] < $cost) {
        return 'You don\'t have ' . hbHubMatadorKr($cost) . '.';
    }

    hbHubMatadorTransfer($state, $seat, null, $cost);
    $state['fields'][$field]['mortgaged'] = false;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' paid back the mortgage on ' . hbHubMatadorFieldName($field)
        . ' (' . hbHubMatadorKr($cost) . ').');
    return null;
}

// ----- Trading: properties (with nothing built in their group), cash and "benådet" cards, between two players.

/**
 * What one side of a trade gives, as sent: fields as "1,3,5", cash and cards as numbers. Null when it can't be one.
 *
 * @return ?array{fields: list<int>, cash: int, cards: int}
 */
function hbHubMatadorTradeSide(mixed $fields, mixed $cash, mixed $cards): ?array
{
    $fields = is_string($fields) && $fields !== '' ? explode(',', $fields) : [];
    $list = [];
    foreach ($fields as $field) {
        if (!ctype_digit($field) || !hbHubMatadorIsProperty((int) $field)) {
            return null;
        }
        $list[] = (int) $field;
    }
    foreach ([$cash, $cards] as $number) {
        if (!is_string($number) || ($number !== '' && (!ctype_digit($number) || strlen($number) > 7))) {
            return null;
        }
    }
    return ['fields' => array_values(array_unique($list)), 'cash' => (int) $cash, 'cards' => (int) $cards];
}

/**
 * Why the trade can't happen as it stands (or null when it can). With $accepting, also whether both can pay the
 * interest on the mortgaged properties they'd get.
 */
function hbHubMatadorTradeProblem(array $state, array $trade, bool $accepting = false): ?string
{
    $from = hbHubMatadorPlayerOf($state, $trade['from']);
    $to = hbHubMatadorPlayerOf($state, $trade['to']);
    if ($from === null || $to === null || $from['seat'] === $to['seat'] || $from['bankrupt'] || $to['bankrupt']) {
        return 'Choose another player to trade with.';
    }
    if ($trade['give']['fields'] === [] && $trade['give']['cash'] === 0 && $trade['give']['cards'] === 0
        && $trade['get']['fields'] === [] && $trade['get']['cash'] === 0 && $trade['get']['cards'] === 0) {
        return 'Choose something to trade.';
    }

    $interest = [$from['seat'] => 0, $to['seat'] => 0];
    foreach ([[$from, $trade['give'], $to], [$to, $trade['get'], $from]] as [$player, $side, $other]) {
        foreach ($side['fields'] as $field) {
            if (($state['fields'][$field]['owner'] ?? null) !== $player['seat']) {
                return $player['name'] . ' doesn\'t own ' . hbHubMatadorFieldName($field) . ' (anymore).';
            }
            if (hbHubMatadorGroupHasBuildings($state, HBHUB_MATADOR_FIELDS[$field]['group'])) {
                return 'Houses and hotels in the group of ' . hbHubMatadorFieldName($field) . ' have to be sold before it can be traded.';
            }
            if ($state['fields'][$field]['mortgaged']) {
                $interest[$other['seat']] += hbHubMatadorInterest($field);
            }
        }
        if ($side['cash'] > $player['cash']) {
            return $player['name'] . ' doesn\'t have ' . hbHubMatadorKr($side['cash']) . '.';
        }
        if ($side['cards'] > count($player['cards'])) {
            return $player['name'] . ' doesn\'t have that many cards.';
        }
    }

    if ($accepting) {
        foreach ([[$from, $trade['give'], $trade['get']], [$to, $trade['get'], $trade['give']]] as [$player, $gives, $gets]) {
            if ($player['cash'] - $gives['cash'] + $gets['cash'] < $interest[$player['seat']]) {
                return $player['name'] . ' can\'t pay the ' . hbHubMatadorKr($interest[$player['seat']])
                    . ' interest on the mortgaged properties they\'d get.';
            }
        }
    }
    return null;
}

function hbHubMatadorOffer(array &$state, int $seat, mixed $to, ?array $give, ?array $get): ?string
{
    if ($state['phase'] !== 'playing') {
        return 'The game isn\'t being played right now.';
    }
    if ($give === null || $get === null || !is_string($to) || !ctype_digit($to)) {
        return 'Choose what to trade.';
    }
    foreach ($state['trades'] as $trade) {
        if ($trade['from'] === $seat) {
            return 'You already have an offer out. Take it back first.';
        }
    }
    $other = hbHubMatadorPlayerOf($state, (int) $to);
    if ($other !== null && $other['userId'] === null) {
        return $other['name'] . ' isn\'t here right now.';
    }

    $trade = ['id' => $state['nextTradeId'], 'from' => $seat, 'to' => (int) $to, 'give' => $give, 'get' => $get];
    $problem = hbHubMatadorTradeProblem($state, $trade);
    if ($problem !== null) {
        return $problem;
    }
    $state['nextTradeId']++;
    $state['trades'][] = $trade;
    hbHubMatadorLog($state, hbHubMatadorName($state, $seat) . ' offered ' . hbHubMatadorName($state, (int) $to) . ' a trade.');
    return null;
}

/**
 * The player the trade was offered to accepts it (or declines it), or the one who offered it takes it back.
 */
function hbHubMatadorAnswerTrade(array &$state, int $seat, mixed $id, string $answer): ?string
{
    $found = null;
    foreach ($state['trades'] as $i => $trade) {
        if (is_string($id) && $id === (string) $trade['id']) {
            $found = $i;
        }
    }
    if ($found === null) {
        return 'That offer isn\'t there anymore.';
    }
    $trade = $state['trades'][$found];
    $isTheirs = $answer === 'cancel' ? $trade['from'] === $seat : $trade['to'] === $seat;
    if (!$isTheirs) {
        return 'That offer isn\'t yours to answer.';
    }

    if ($answer === 'accept') {
        $problem = hbHubMatadorTradeProblem($state, $trade, true);
        if ($problem !== null) {
            return $problem;
        }
        hbHubMatadorTrade($state, $trade);
    } else {
        hbHubMatadorLog($state, $answer === 'cancel'
            ? hbHubMatadorName($state, $seat) . ' took back their offer.'
            : hbHubMatadorName($state, $seat) . ' turned down ' . hbHubMatadorName($state, $trade['from']) . '\'s offer.');
    }
    array_splice($state['trades'], $found, 1);
    return null;
}

/**
 * Swaps what the trade says. Whoever gets a mortgaged property pays the bank the interest on it straight away.
 */
function hbHubMatadorTrade(array &$state, array $trade): void
{
    foreach ([[$trade['from'], $trade['to'], $trade['give']], [$trade['to'], $trade['from'], $trade['get']]] as [$giver, $taker, $side]) {
        foreach ($side['fields'] as $field) {
            $state['fields'][$field]['owner'] = $taker;
            if ($state['fields'][$field]['mortgaged']) {
                hbHubMatadorTransfer($state, $taker, null, hbHubMatadorInterest($field));
            }
        }
        hbHubMatadorTransfer($state, $giver, $taker, $side['cash']);
        $giverIndex = hbHubMatadorIndex($state, $giver);
        for ($n = 0; $n < $side['cards']; $n++) {
            $state['players'][hbHubMatadorIndex($state, $taker)]['cards'][] = array_pop($state['players'][$giverIndex]['cards']);
        }
    }
    hbHubMatadorLog($state, hbHubMatadorName($state, $trade['to']) . ' accepted ' . hbHubMatadorName($state, $trade['from']) . '\'s offer: '
        . hbHubMatadorTradeText($state, $trade['give']) . ' for ' . hbHubMatadorTradeText($state, $trade['get']) . '.');
}

function hbHubMatadorTradeText(array $state, array $side): string
{
    $parts = array_map('hbHubMatadorFieldName', $side['fields']);
    if ($side['cash'] > 0) {
        $parts[] = hbHubMatadorKr($side['cash']);
    }
    if ($side['cards'] > 0) {
        $parts[] = $side['cards'] === 1 ? 'a "benådet" card' : $side['cards'] . ' "benådet" cards';
    }
    return $parts === [] ? 'nothing' : implode(', ', $parts);
}

/**
 * Takes back the offers from and to the seat, when its player leaves.
 */
function hbHubMatadorCancelTrades(array &$state, int $seat): void
{
    $state['trades'] = array_values(array_filter($state['trades'],
        static fn (array $trade): bool => $trade['from'] !== $seat && $trade['to'] !== $seat));
}

// ----- What the players see.

/**
 * The game as the players may see it (everything but the order of the cards), for matadorGame.js.
 *
 * @param array<int, array{name: string, avatar: ?string}> $people names and pictures by userId (room.php's hbHubMatadorPeople())
 * @param array<int, bool> $away by userId, whose game hasn't checked in for a while
 */
function hbHubMatadorView(array $state, array $people, array $away, int $userId, ?int $hostId): array
{
    $you = hbHubMatadorSeatOfUser($state, $userId);
    $fields = array_fill(0, HBHUB_MATADOR_FIELD_COUNT, null);
    foreach ($state['fields'] as $field => $property) {
        $fields[$field] = $property;
    }

    $card = $state['lastCard'];
    $pending = $state['pending'];
    if (($pending['type'] ?? null) === 'tax') {
        $pending['fixed'] = HBHUB_MATADOR_TAX_FIXED;
    }

    return [
        'phase' => $state['phase'],
        'round' => $state['round'],
        'you' => $you,
        'isHost' => $hostId === $userId,
        'players' => array_map(static function (array $player) use ($people, $away, $userId, $hostId, $state): array {
            $person = $player['userId'] !== null ? ($people[$player['userId']] ?? null) : null;
            return [
                'seat' => $player['seat'],
                'name' => $person['name'] ?? $player['name'],
                'avatar' => $person['avatar'] ?? null,
                'cash' => $player['cash'],
                'worth' => $state['phase'] === 'lobby' ? null : hbHubMatadorWorth($state, $player['seat']),
                'position' => $player['position'],
                'inJail' => $player['inJail'],
                'jailTries' => $player['jailTries'],
                'cards' => count($player['cards']),
                'bankrupt' => $player['bankrupt'],
                'here' => $player['userId'] !== null,
                'away' => $player['userId'] !== null && ($away[$player['userId']] ?? false),
                'isYou' => $player['userId'] === $userId,
                'isHost' => $player['userId'] !== null && $player['userId'] === $hostId,
                'reservedFor' => $player['reservedFor'] !== null ? ($people[$player['reservedFor']]['name'] ?? 'someone') : null,
            ];
        }, $state['players']),
        'fields' => $fields,
        'bank' => $state['phase'] === 'lobby' ? null : hbHubMatadorBank($state),
        'turn' => $state['turn'],
        // In milliseconds, from when this was worked out: the server's clock and the player's don't have to agree
        // Null while it isn't counting: the game isn't being played, or the timer is paused
        'timeLeft' => $state['phase'] === 'playing' && !hbHubMatadorTimerPaused($state)
            ? max(0, ($state['turn']['deadline'] ?? 0) - hbHubMatadorNowMs())
            : null,
        'pending' => $pending,
        'trades' => $state['trades'],
        'lastRoll' => $state['lastRoll'],
        'lastCard' => $card !== null && empty($card['over']) ? ['seat' => $card['seat'], 'text' => HBHUB_MATADOR_CARDS[$card['card']]['text'], 'n' => $card['n']] : null,
        'log' => $state['log'],
        'winner' => $state['winner'],
        'unsaved' => $state['unsaved'],
    ];
}
