<?php
// The Matador board: the 40 fields in order from START, with what the properties cost and what they take in rent
// (the classic Danish edition), and the game's amounts. The names are Danish, as on the real board.
//
// Fields are numbered 0-39 the way the pieces move. A property's 'group' is 1-8 for the streets (the colours on the
// board, see matador.css), 'ferry' or 'brewery'. A street's 'rent' is unbuilt, 1-4 houses and a hotel.

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

const HBHUB_MATADOR_FIELDS = [
    0 => ['type' => 'start', 'name' => 'Start'],
    1 => ['type' => 'street', 'name' => 'Rødovrevej', 'group' => 1, 'price' => 1200, 'house' => 1000, 'rent' => [50, 250, 750, 2250, 4000, 6000]],
    2 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    3 => ['type' => 'street', 'name' => 'Hvidovrevej', 'group' => 1, 'price' => 1200, 'house' => 1000, 'rent' => [50, 250, 750, 2250, 4000, 6000]],
    4 => ['type' => 'incomeTax', 'name' => 'Indkomstskat'],
    5 => ['type' => 'ferry', 'name' => 'Helsingør–Helsingborg', 'group' => 'ferry', 'price' => 4000],
    6 => ['type' => 'street', 'name' => 'Roskildevej', 'group' => 2, 'price' => 2000, 'house' => 1000, 'rent' => [100, 600, 1800, 5400, 8000, 11000]],
    7 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    8 => ['type' => 'street', 'name' => 'Valby Langgade', 'group' => 2, 'price' => 2000, 'house' => 1000, 'rent' => [100, 600, 1800, 5400, 8000, 11000]],
    9 => ['type' => 'street', 'name' => 'Allégade', 'group' => 2, 'price' => 2400, 'house' => 1000, 'rent' => [150, 800, 2000, 6000, 9000, 12000]],
    10 => ['type' => 'jail', 'name' => 'Fængsel'],
    11 => ['type' => 'street', 'name' => 'Frederiksberg Allé', 'group' => 3, 'price' => 2800, 'house' => 2000, 'rent' => [200, 1000, 3000, 9000, 12500, 15000]],
    12 => ['type' => 'brewery', 'name' => 'Tuborg', 'group' => 'brewery', 'price' => 3000],
    13 => ['type' => 'street', 'name' => 'Bülowsvej', 'group' => 3, 'price' => 2800, 'house' => 2000, 'rent' => [200, 1000, 3000, 9000, 12500, 15000]],
    14 => ['type' => 'street', 'name' => 'Gl. Kongevej', 'group' => 3, 'price' => 3200, 'house' => 2000, 'rent' => [250, 1250, 3750, 10000, 14000, 18000]],
    15 => ['type' => 'ferry', 'name' => 'Mols-Linien', 'group' => 'ferry', 'price' => 4000],
    16 => ['type' => 'street', 'name' => 'Bernstorffsvej', 'group' => 4, 'price' => 3600, 'house' => 2000, 'rent' => [300, 1400, 4000, 11000, 15000, 19000]],
    17 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    18 => ['type' => 'street', 'name' => 'Hellerupvej', 'group' => 4, 'price' => 3600, 'house' => 2000, 'rent' => [300, 1400, 4000, 11000, 15000, 19000]],
    19 => ['type' => 'street', 'name' => 'Strandvejen', 'group' => 4, 'price' => 4000, 'house' => 2000, 'rent' => [350, 1600, 4400, 12000, 16000, 20000]],
    20 => ['type' => 'parking', 'name' => 'Parkering'],
    21 => ['type' => 'street', 'name' => 'Trianglen', 'group' => 5, 'price' => 4400, 'house' => 3000, 'rent' => [350, 1800, 5000, 14000, 17500, 21000]],
    22 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    23 => ['type' => 'street', 'name' => 'Østerbrogade', 'group' => 5, 'price' => 4400, 'house' => 3000, 'rent' => [350, 1800, 5000, 14000, 17500, 21000]],
    24 => ['type' => 'street', 'name' => 'Grønningen', 'group' => 5, 'price' => 4800, 'house' => 3000, 'rent' => [400, 2000, 6000, 15000, 18500, 22000]],
    25 => ['type' => 'ferry', 'name' => 'Gedser–Rostock', 'group' => 'ferry', 'price' => 4000],
    26 => ['type' => 'street', 'name' => 'Bredgade', 'group' => 6, 'price' => 5200, 'house' => 3000, 'rent' => [450, 2200, 6600, 16000, 19500, 23000]],
    27 => ['type' => 'street', 'name' => 'Kgs. Nytorv', 'group' => 6, 'price' => 5200, 'house' => 3000, 'rent' => [450, 2200, 6600, 16000, 19500, 23000]],
    28 => ['type' => 'brewery', 'name' => 'Coca-Cola', 'group' => 'brewery', 'price' => 3000],
    29 => ['type' => 'street', 'name' => 'Østergade', 'group' => 6, 'price' => 5600, 'house' => 3000, 'rent' => [500, 2400, 7200, 17000, 20500, 24000]],
    30 => ['type' => 'goToJail', 'name' => 'De fængsles'],
    31 => ['type' => 'street', 'name' => 'Amagertorv', 'group' => 7, 'price' => 6000, 'house' => 4000, 'rent' => [550, 2600, 7800, 18000, 22000, 25000]],
    32 => ['type' => 'street', 'name' => 'Vimmelskaftet', 'group' => 7, 'price' => 6000, 'house' => 4000, 'rent' => [550, 2600, 7800, 18000, 22000, 25000]],
    33 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    34 => ['type' => 'street', 'name' => 'Nygade', 'group' => 7, 'price' => 6400, 'house' => 4000, 'rent' => [600, 3000, 9000, 20000, 24000, 28000]],
    35 => ['type' => 'ferry', 'name' => 'Rødby–Puttgarden', 'group' => 'ferry', 'price' => 4000],
    36 => ['type' => 'chance', 'name' => 'Prøv lykken'],
    37 => ['type' => 'street', 'name' => 'Frederiksberggade', 'group' => 8, 'price' => 7000, 'house' => 4000, 'rent' => [700, 3500, 10000, 22000, 26000, 30000]],
    38 => ['type' => 'extraTax', 'name' => 'Ekstraordinær statsskat', 'amount' => 2000],
    39 => ['type' => 'street', 'name' => 'Rådhuspladsen', 'group' => 8, 'price' => 8000, 'house' => 4000, 'rent' => [1000, 4000, 12000, 28000, 34000, 40000]],
];

/**
 * Whether the field can be bought: a street, a ferry or a brewery.
 */
function hbHubMatadorIsProperty(int $field): bool
{
    return isset(HBHUB_MATADOR_FIELDS[$field]['price']);
}

/**
 * The fields of a group (a colour, 'ferry' or 'brewery').
 *
 * @return list<int>
 */
function hbHubMatadorGroupFields(int|string $group): array
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        foreach (HBHUB_MATADOR_FIELDS as $field => $info) {
            if (isset($info['group'])) {
                $groups[$info['group']][] = $field;
            }
        }
    }
    return $groups[$group] ?? [];
}

/**
 * What the field is mortgaged for, and paid back for (with the interest).
 */
function hbHubMatadorMortgageValue(int $field): int
{
    return intdiv(HBHUB_MATADOR_FIELDS[$field]['price'], 2);
}

function hbHubMatadorInterest(int $field): int
{
    return intdiv(hbHubMatadorMortgageValue($field) * HBHUB_MATADOR_INTEREST_PERCENT, 100);
}

/**
 * An amount the way the game's messages write it, like "4,000 kr" (matadorGame.js does the same).
 */
function hbHubMatadorKr(int $amount): string
{
    return number_format($amount) . ' kr';
}
