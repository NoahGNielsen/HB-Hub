<?php
// Blackjack's cards: the shoe, and what a hand is worth. No database in here - round.php plays the rounds with it.
//
// A card is its rank and suit, like "AS" (ace of spades), "TH" (ten of hearts) or "9D". Ranks are A, 2-9, T, J, Q and K,
// suits S, H, D and C.

const HBHUB_BLACKJACK_DECKS = 6;
const HBHUB_BLACKJACK_RANKS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K'];
const HBHUB_BLACKJACK_SUITS = ['S', 'H', 'D', 'C'];
const HBHUB_BLACKJACK_SHOE_SIZE = HBHUB_BLACKJACK_DECKS * 52;
const HBHUB_BLACKJACK_RESHUFFLE_LEFT = HBHUB_BLACKJACK_SHOE_SIZE / 4; // a new shoe before the deal once 75% of it has been dealt

/**
 * A new shoe of 6 shuffled decks. The last card is the next one dealt.
 *
 * @return list<string>
 */
function hbHubBlackjackNewShoe(): array
{
    $shoe = [];
    for ($deck = 0; $deck < HBHUB_BLACKJACK_DECKS; $deck++) {
        foreach (HBHUB_BLACKJACK_SUITS as $suit) {
            foreach (HBHUB_BLACKJACK_RANKS as $rank) {
                $shoe[] = $rank . $suit;
            }
        }
    }
    // Shuffled with the operating system's secure random numbers, so the order can't be worked out from earlier cards
    return (new Random\Randomizer(new Random\Engine\Secure()))->shuffleArray($shoe);
}

/**
 * What a card counts as, with an ace as 11 (hbHubBlackjackTotal() counts it as 1 when 11 would bust).
 */
function hbHubBlackjackCardValue(string $card): int
{
    return match ($card[0]) {
        'A' => 11,
        'T', 'J', 'Q', 'K' => 10,
        default => (int) $card[0],
    };
}

/**
 * The best total of the cards, and whether it's soft (an ace in it still counts as 11).
 *
 * @param list<string> $cards
 * @return array{0: int, 1: bool} [total, isSoft]
 */
function hbHubBlackjackTotal(array $cards): array
{
    $total = 0;
    $aces = 0; // counted as 11 so far
    foreach ($cards as $card) {
        $total += hbHubBlackjackCardValue($card);
        if ($card[0] === 'A') {
            $aces++;
        }
    }
    while ($total > 21 && $aces > 0) {
        $total -= 10;
        $aces--;
    }
    return [$total, $aces > 0];
}

/**
 * Whether the cards are a blackjack: 21 with the first two cards. A split hand never is, round.php checks that.
 *
 * @param list<string> $cards
 */
function hbHubBlackjackIsNatural(array $cards): bool
{
    return count($cards) === 2 && hbHubBlackjackTotal($cards)[0] === 21;
}

/**
 * Whether the dealer takes another card: below 17. The dealer stands on every 17, soft 17 too.
 *
 * @param list<string> $cards
 */
function hbHubBlackjackDealerHits(array $cards): bool
{
    return hbHubBlackjackTotal($cards)[0] < 17;
}

/**
 * Whether two cards can be split: the same value, so a king and a queen can too.
 */
function hbHubBlackjackIsPair(string $card1, string $card2): bool
{
    return hbHubBlackjackCardValue($card1) === hbHubBlackjackCardValue($card2);
}
