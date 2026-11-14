<?php
// The Matador board: the editions to choose from, the game's amounts, and the helpers that look up a game's fields.
// The fields themselves are in editions/<edition>.php, one file per edition (the classic Danish one, HTX's own, Kaos),
// each with its 40 fields and its Prøv lykken cards (cards.php). The host picks the edition in the room's settings
// before the game starts, so it's in the game's state (settings.edition, game.php) - always look a field up through the
// state (hbHubMatadorFields($state)), never from one edition directly.
//
// An edition with 'shuffle' (Kaos) has its fields put in a random order when the game starts, and again by its Kaos
// card (game.php's hbHubMatadorShuffleBoard()). That order is the state's layout: by field number, the number the
// field has in its edition's file. The four corners never move. Everything in the state goes by where a field is now
// (hbHubMatadorFields($state) is already in the layout's order), and a card that moves you to a field follows it.
//
// A field: type ('start', 'street', 'chance', 'incomeTax', 'ferry', 'jail', 'brewery', 'parking', 'goToJail' or
// 'extraTax' with its 'amount') and name. The properties also have a group and a price: the group is 1-8 for the
// streets (the colours on the board, see matador.css), 'ferry' or 'brewery'. A street also has 'house' (what a house
// costs) and 'rent': unbuilt, 1-4 houses and a hotel. Fields are numbered 0-39 the way the pieces move.

const HBHUB_MATADOR_FIELD_COUNT = 40;
const HBHUB_MATADOR_START_CASH = 30000;
const HBHUB_MATADOR_START_PAY = 4000; // for passing or landing on START
const HBHUB_MATADOR_JAIL_FIELD = 10;
const HBHUB_MATADOR_BAIL = 1000;
const HBHUB_MATADOR_JAIL_TRIES = 3; // rolls to get a 6, then the bail has to be paid
const HBHUB_MATADOR_TAX_FIXED = 4000;
const HBHUB_MATADOR_TAX_PERCENT = 10;
const HBHUB_MATADOR_INTEREST_PERCENT = 10; // on top of the mortgage when it's paid back or a mortgaged property changes hands
const HBHUB_MATADOR_HOTEL = 5; // a street's 'houses' when it has a hotel
const HBHUB_MATADOR_BANK_HOUSES = 32;
const HBHUB_MATADOR_BANK_HOTELS = 12;
const HBHUB_MATADOR_FERRY_RENT = [500, 1000, 2000, 4000]; // owning 1-4 of them
const HBHUB_MATADOR_BREWERY_RENT = [100, 200]; // times the roll, owning 1 or 2 of them
const HBHUB_MATADOR_LEGAT = 40000; // the Matador-legat (a Prøv lykken card)
const HBHUB_MATADOR_LEGAT_LIMIT = 15000; // only for players worth at most this

// The editions, by the name of their file in editions/ (which is also what's kept in the state's settings.edition):
// what the room's settings call it, and a line about it
const HBHUB_MATADOR_EDITIONS = [
    'classic' => ['name' => 'Classic', 'about' => 'The original Danish board, with the streets of Copenhagen.'],
    'htx' => ['name' => 'HTX', 'about' => 'HTX\'s own board and cards - BETA!'],
    'kaos' => ['name' => 'Kaos', 'about' => 'The classic board with every field in a random place, and a Prøv lykken card that mixes them up again.'],
];
const HBHUB_MATADOR_CORNERS = [0, 10, 20, 30]; // START, jail, Parkering and De fængsles: they stay put in Kaos
const HBHUB_MATADOR_KAOS_CARD = 0; // the Kaos card's number in editions/kaos.php (it isn't in the deck)
const HBHUB_MATADOR_KAOS_PERCENT = 25; // the chance a Prøv lykken in Kaos is the Kaos card instead of the top of the deck
const HBHUB_MATADOR_DEFAULT_EDITION = 'classic';

/**
 * An edition's fields and cards (editions/<edition>.php), read once per request. An edition that isn't there anymore
 * gives the default one, so an old game never breaks.
 *
 * @return array{fields: array<int, array>, cards: array<int, array>, groups: array<int|string, list<int>>}
 */
function hbHubMatadorEdition(string $edition): array
{
    static $editions = [];
    if (!isset(HBHUB_MATADOR_EDITIONS[$edition])) {
        $edition = HBHUB_MATADOR_DEFAULT_EDITION;
    }
    if (!isset($editions[$edition])) {
        $data = require __DIR__ . '/editions/' . $edition . '.php';
        $data['groups'] = []; // the fields of each group, worked out once
        foreach ($data['fields'] as $field => $info) {
            if (isset($info['group'])) {
                $data['groups'][$info['group']][] = $field;
            }
        }
        $editions[$edition] = $data;
    }
    return $editions[$edition];
}

/**
 * The edition the game is played with (its settings.edition, missing in games from before editions: those are Classic).
 */
function hbHubMatadorEditionOf(array $state): array
{
    static $laidOut = [];
    $name = $state['settings']['edition'] ?? HBHUB_MATADOR_DEFAULT_EDITION;
    $edition = hbHubMatadorEdition($name);
    $layout = $state['layout'] ?? null;
    if ($layout === null || empty($edition['shuffle'])) {
        return $edition;
    }
    return $laidOut[$name . ':' . implode(',', $layout)] ??= hbHubMatadorLaidOut($edition, $layout);
}

/**
 * The edition with its fields in the layout's order (by field number, the number in the edition's file): the fields,
 * the groups worked out again, and the cards that move you to a field sent to where that field is now.
 *
 * @param list<int> $layout
 */
function hbHubMatadorLaidOut(array $edition, array $layout): array
{
    $where = array_flip($layout); // where each of the edition's fields is now
    $data = $edition;
    $data['fields'] = [];
    $data['groups'] = [];
    foreach ($layout as $field => $original) {
        $info = $edition['fields'][$original];
        $data['fields'][$field] = $info;
        if (isset($info['group'])) {
            $data['groups'][$info['group']][] = $field; // in board order, as the "nearest ferry" card needs
        }
    }
    foreach ($data['cards'] as &$card) {
        if ($card['do'][0] === 'move') {
            $card['do'][1] = $where[$card['do'][1]];
        }
    }
    unset($card);
    return $data;
}

/**
 * Whether the game's fields are put in a random order (its edition has 'shuffle': Kaos).
 */
function hbHubMatadorShuffles(array $state): bool
{
    return !empty(hbHubMatadorEdition($state['settings']['edition'] ?? HBHUB_MATADOR_DEFAULT_EDITION)['shuffle']);
}

/**
 * A new random order for the fields (the state's layout), with the corners where they are.
 *
 * @return list<int>
 */
function hbHubMatadorNewLayout(): array
{
    $layout = range(0, HBHUB_MATADOR_FIELD_COUNT - 1);
    $free = array_values(array_diff($layout, HBHUB_MATADOR_CORNERS));
    $mixed = $free;
    for ($i = count($mixed) - 1; $i > 0; $i--) { // random_int(), not shuffle(), like hbHubMatadorShuffle() in game.php
        $j = random_int(0, $i);
        [$mixed[$i], $mixed[$j]] = [$mixed[$j], $mixed[$i]];
    }
    foreach ($free as $i => $field) {
        $layout[$field] = $mixed[$i];
    }
    return $layout;
}

/**
 * The game's 40 fields, by number.
 *
 * @return array<int, array>
 */
function hbHubMatadorFields(array $state): array
{
    return hbHubMatadorEditionOf($state)['fields'];
}

/**
 * Whether the field can be bought: a street, a ferry or a brewery.
 */
function hbHubMatadorIsProperty(array $state, int $field): bool
{
    return isset(hbHubMatadorFields($state)[$field]['price']);
}

/**
 * The fields of a group (a colour, 'ferry' or 'brewery').
 *
 * @return list<int>
 */
function hbHubMatadorGroupFields(array $state, int|string $group): array
{
    return hbHubMatadorEditionOf($state)['groups'][$group] ?? [];
}

/**
 * What the field is mortgaged for, and paid back for (with the interest).
 */
function hbHubMatadorMortgageValue(array $state, int $field): int
{
    return intdiv(hbHubMatadorFields($state)[$field]['price'], 2);
}

function hbHubMatadorInterest(array $state, int $field): int
{
    return intdiv(hbHubMatadorMortgageValue($state, $field) * HBHUB_MATADOR_INTEREST_PERCENT, 100);
}

/**
 * An amount the way the game's messages write it, like "4,000 kr" (matadorGame.js does the same).
 */
function hbHubMatadorKr(int $amount): string
{
    return number_format($amount) . ' kr';
}
