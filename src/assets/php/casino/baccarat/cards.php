<?php
// Baccarat's cards: the shoe, what the cards and hands are worth, and the drawing rules (punto banco, so nobody decides
// anything once the bets are in). No database in here - round.php plays the hands with it.
//
// A card is its rank and suit, like "AS" (ace of spades), "TH" (ten of hearts) or "9D", the same as at Blackjack.
// Ranks are A, 2-9, T, J, Q and K, suits S, H, D and C.

const HBHUB_BACCARAT_DECKS = 8;
const HBHUB_BACCARAT_RANKS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K'];
const HBHUB_BACCARAT_SUITS = ['S', 'H', 'D', 'C'];
const HBHUB_BACCARAT_SHOE_SIZE = HBHUB_BACCARAT_DECKS * 52;
const HBHUB_BACCARAT_RESHUFFLE_LEFT = HBHUB_BACCARAT_SHOE_SIZE / 4; // a new shoe once 75% of it has been dealt

/**
 * A new shoe of 8 shuffled decks. The last card is the next one dealt.
 *
 * @return list<string>
 */
function hbHubBaccaratNewShoe(): array
{
    $shoe = [];
    for ($deck = 0; $deck < HBHUB_BACCARAT_DECKS; $deck++) {
        foreach (HBHUB_BACCARAT_SUITS as $suit) {
            foreach (HBHUB_BACCARAT_RANKS as $rank) {
                $shoe[] = $rank . $suit;
            }
        }
    }
    // Shuffled with the operating system's secure random numbers, so the order can't be worked out from earlier cards
    return (new Random\Randomizer(new Random\Engine\Secure()))->shuffleArray($shoe);
}

/**
 * What a card counts as: an ace 1, 2-9 their number, and tens and picture cards 0.
 */
function hbHubBaccaratCardValue(string $card): int
{
    return match ($card[0]) {
        'A' => 1,
        'T', 'J', 'Q', 'K' => 0,
        default => (int) $card[0],
    };
}

/**
 * What the cards are worth together: only the last digit counts, so a 7 and an 8 (15) are 5.
 *
 * @param list<string> $cards
 */
function hbHubBaccaratTotal(array $cards): int
{
    return array_sum(array_map('hbHubBaccaratCardValue', $cards)) % 10;
}

/**
 * Whether the first two cards are a natural: 8 or 9, and then neither hand takes a third card.
 *
 * @param list<string> $cards
 */
function hbHubBaccaratIsNatural(array $cards): bool
{
    return count($cards) >= 2 && hbHubBaccaratTotal(array_slice($cards, 0, 2)) >= 8;
}

/**
 * Whether the first two cards are a pair: the same rank, so two kings are, but a king and a queen aren't.
 *
 * @param list<string> $cards
 */
function hbHubBaccaratIsPair(array $cards): bool
{
    return count($cards) >= 2 && $cards[0][0] === $cards[1][0];
}

/**
 * Whether the Banker takes a third card, by its total from two cards and what the Player's third card was worth
 * (null when the Player stood on two). Standing, the Player leaves the Banker to draw on 0-5 like the Player does.
 */
function hbHubBaccaratBankerDraws(int $bankerTotal, ?int $playerThird): bool
{
    if ($playerThird === null) {
        return $bankerTotal <= 5;
    }
    return match ($bankerTotal) {
        0, 1, 2 => true,
        3 => $playerThird !== 8,
        4 => $playerThird >= 2 && $playerThird <= 7,
        5 => $playerThird >= 4 && $playerThird <= 7,
        6 => $playerThird === 6 || $playerThird === 7,
        default => false, // 7 stands
    };
}

/**
 * Deals one hand (a coup) from the shoe: Player, Banker, Player, Banker, then the third cards by the rules.
 * Returns both hands, and which hand each card went to in the order they were dealt (so the table can turn them
 * over one at a time).
 *
 * @param list<string> $shoe the shoe, the next card last. Never runs out: the shoe is changed long before.
 * @return array{player: list<string>, banker: list<string>, order: list<string>}
 */
function hbHubBaccaratCoup(array &$shoe): array
{
    $hands = ['player' => [], 'banker' => []];
    $order = [];
    $draw = static function (string $side) use (&$shoe, &$hands, &$order): string {
        if ($shoe === []) {
            $shoe = hbHubBaccaratNewShoe();
        }
        $card = array_pop($shoe);
        $hands[$side][] = $card;
        $order[] = $side;
        return $card;
    };

    for ($i = 0; $i < 2; $i++) {
        $draw('player');
        $draw('banker');
    }

    if (!hbHubBaccaratIsNatural($hands['player']) && !hbHubBaccaratIsNatural($hands['banker'])) {
        $playerThird = null;
        if (hbHubBaccaratTotal($hands['player']) <= 5) {
            $playerThird = hbHubBaccaratCardValue($draw('player'));
        }
        if (hbHubBaccaratBankerDraws(hbHubBaccaratTotal($hands['banker']), $playerThird)) {
            $draw('banker');
        }
    }

    return ['player' => $hands['player'], 'banker' => $hands['banker'], 'order' => $order];
}

/**
 * Which hand won: "player", "banker" or "tie".
 *
 * @param list<string> $player
 * @param list<string> $banker
 */
function hbHubBaccaratWinner(array $player, array $banker): string
{
    return match (hbHubBaccaratTotal($player) <=> hbHubBaccaratTotal($banker)) {
        1 => 'player',
        -1 => 'banker',
        default => 'tie',
    };
}
