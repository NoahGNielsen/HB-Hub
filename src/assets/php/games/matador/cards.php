<?php
// The Prøv lykken cards: one deck, shuffled when the game starts. A card that's been read goes to the bottom of the deck,
// except the two "benådet" cards, which the player keeps until they use them to get out of jail (or trade them).
// Each edition has its own cards, in editions/<edition>.php next to its fields (board.php), as
// number => ['text' => what it says, 'do' => [what it does, and its numbers]]. The texts are in Danish, as on the real
// cards. What each one does ('do', played by hbHubMatadorDrawCard() in game.php):
//   move (field)         move forward to the field, 4,000 kr for passing START
//   back (steps)         move back, without passing START
//   nearestFerry         move forward to the nearest ferry and pay its owner double rent (or buy it from the bank)
//   jail                 go straight to jail
//   jailCard             keep the card, to get out of jail with
//   pay (amount)         pay the bank
//   receive (amount)     the bank pays you
//   repairs (house, hotel)  pay for every house and hotel you own
//   fromEach (amount)    every other player pays you
//   legat                the Matador-legat, when you're worth at most 15,000 kr

/**
 * The game's Prøv lykken cards (its edition's), by number.
 *
 * @return array<int, array{text: string, do: list<int|string>}>
 */
function hbHubMatadorCards(array $state): array
{
    return hbHubMatadorEditionOf($state)['cards'];
}
