<?php
// The fun at a Blackjack table that isn't the game itself: quick emotes (👏 😬 💀 🤑) and tipping the dealer.
// Both are kept in the table's state (roomState, see round.php), so they reach everyone at the table with the check
// blackjackTable.js already sends every second - there's no request of their own to fetch them. play.php's emote and
// tip actions call these with the room locked (hbHubBlackjackPlay() in room.php).
//
// In the state:
//   feed    the newest emotes and tips, oldest first, at most HBHUB_BLACKJACK_FEED_KEEP:
//           [{id, userId, seat, kind: emote|tip, emote (its key, for an emote), amount (for a tip), at (Unix ms)}]
//   feedId  the last id given out, so the table only shows each one once
//   tips    what the dealer has been tipped at this table: {chips, count}
// Rooms made before these existed don't have them, so they're always read with a default. They go with the room when
// it's deleted. A tip is just chips given away (the house keeps them): it's logged as "tip" in the chip log
// (transactionReference is the room), and doesn't count on the leaderboards, which only count hands.
require_once __DIR__ . '/../wallet.php';

// The emotes, by the key sent from the table (data-bj-emote) and kept in the state
const HBHUB_BLACKJACK_EMOTES = [
    'clap' => ['symbol' => '👏', 'label' => 'Clap'],
    'yikes' => ['symbol' => '😬', 'label' => 'Yikes'],
    'dead' => ['symbol' => '💀', 'label' => 'Dead'],
    'rich' => ['symbol' => '🤑', 'label' => 'Money'],
];
const HBHUB_BLACKJACK_TIPS = [10, 50, 100]; // what the dealer can be tipped, one chip at a time
const HBHUB_BLACKJACK_FEED_KEEP = 12;
const HBHUB_BLACKJACK_FEED_SHOW_MS = 6000; // a table only gets emotes and tips this new (the ones it missed while hidden are old news)
const HBHUB_BLACKJACK_EMOTE_GAP_MS = 1200; // a player's emotes are at least this far apart, so nobody can flood the table
const HBHUB_BLACKJACK_TIP_GAP_MS = 500;

/**
 * The player sends an emote (its key, as sent). One too soon after their last is let go without a word, as the
 * buttons wait too. Returns null when it's sent (or let go), otherwise why not.
 */
function hbHubBlackjackEmote(array &$room, array $seats, int $userId, mixed $emote, int $now): ?string
{
    $seat = hbHubBlackjackSeatOf($seats, $userId);
    if ($seat === null) {
        return 'You aren\'t at this table.';
    }
    if ($room['roomType'] === HBHUB_BLACKJACK_ROOM_SOLO) {
        return 'There\'s nobody to see that at a solo table.';
    }
    if (!is_string($emote) || !isset(HBHUB_BLACKJACK_EMOTES[$emote])) {
        return 'That emote doesn\'t exist.';
    }
    if (hbHubBlackjackFeedIsTooSoon($room['state'], $userId, 'emote', HBHUB_BLACKJACK_EMOTE_GAP_MS, $now)) {
        return null;
    }

    hbHubBlackjackFeedAdd($room['state'], ['userId' => $userId, 'seat' => $seat['seat'], 'kind' => 'emote', 'emote' => $emote], $now);
    return null;
}

/**
 * The player tips the dealer $amount chips (as sent, one of HBHUB_BLACKJACK_TIPS). Returns null when it's given,
 * otherwise why not.
 */
function hbHubBlackjackTip(PDO $db, array &$room, array $seats, int $userId, mixed $amount, int $now): ?string
{
    $seat = hbHubBlackjackSeatOf($seats, $userId);
    if ($seat === null) {
        return 'You aren\'t at this table.';
    }
    $amount = is_string($amount) && ctype_digit($amount) && strlen($amount) <= 4 ? (int) $amount : 0;
    if (!in_array($amount, HBHUB_BLACKJACK_TIPS, true)) {
        return 'The dealer can be tipped ' . implode(', ', HBHUB_BLACKJACK_TIPS) . ' chips at a time.';
    }
    if (hbHubBlackjackFeedIsTooSoon($room['state'], $userId, 'tip', HBHUB_BLACKJACK_TIP_GAP_MS, $now)) {
        return null; // a double click
    }
    if (hbHubCasinoChange($db, $userId, -$amount, 'tip', HBHUB_BLACKJACK_GAME, $room['roomId']) === null) {
        return 'You don\'t have enough chips for that tip.';
    }

    $state = &$room['state'];
    $tips = $state['tips'] ?? ['chips' => 0, 'count' => 0];
    $state['tips'] = ['chips' => $tips['chips'] + $amount, 'count' => $tips['count'] + 1];
    hbHubBlackjackFeedAdd($state, ['userId' => $userId, 'seat' => $seat['seat'], 'kind' => 'tip', 'amount' => $amount], $now);
    return null;
}

/**
 * Whether the user's last emote or tip ($kind) was less than $gapMs ago.
 */
function hbHubBlackjackFeedIsTooSoon(array $state, int $userId, string $kind, int $gapMs, int $now): bool
{
    foreach (array_reverse($state['feed'] ?? []) as $entry) {
        if ($entry['userId'] === $userId && $entry['kind'] === $kind) {
            return $now - $entry['at'] < $gapMs;
        }
    }
    return false;
}

/**
 * Adds an emote or a tip to the feed with the next id, and drops the oldest past HBHUB_BLACKJACK_FEED_KEEP.
 */
function hbHubBlackjackFeedAdd(array &$state, array $entry, int $now): void
{
    $state['feedId'] = ($state['feedId'] ?? 0) + 1;
    $state['feed'] = array_slice([...($state['feed'] ?? []), ['id' => $state['feedId']] + $entry + ['at' => $now]], -HBHUB_BLACKJACK_FEED_KEEP);
}

/**
 * The emotes and tips as the table shows them (part of hbHubBlackjackTableView()): the ones from the last
 * HBHUB_BLACKJACK_FEED_SHOW_MS, with how old they are and the name of who sent them, and the dealer's tips.
 *
 * @param array<int, array{name: string, avatar: ?string}> $people
 * @return array{feed: list<array{id: int, seat: int, name: string, isYou: bool, kind: string, emote: ?string, amount: ?int, ageMs: int}>,
 *     tips: array{chips: int, count: int}}
 */
function hbHubBlackjackSocialView(array $state, array $people, int $viewerId, int $now): array
{
    $feed = [];
    foreach ($state['feed'] ?? [] as $entry) {
        $age = max(0, $now - $entry['at']);
        if ($age > HBHUB_BLACKJACK_FEED_SHOW_MS) {
            continue;
        }
        $feed[] = [
            'id' => $entry['id'],
            'seat' => $entry['seat'],
            'name' => $people[$entry['userId']]['name'] ?? '',
            'isYou' => $entry['userId'] === $viewerId,
            'kind' => $entry['kind'],
            'emote' => isset($entry['emote']) ? (HBHUB_BLACKJACK_EMOTES[$entry['emote']]['symbol'] ?? null) : null,
            'amount' => $entry['amount'] ?? null,
            'ageMs' => $age,
        ];
    }
    return ['feed' => $feed, 'tips' => $state['tips'] ?? ['chips' => 0, 'count' => 0]];
}
