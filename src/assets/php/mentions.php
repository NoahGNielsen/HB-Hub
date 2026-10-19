<?php
// @mentions: "@Alice" in a message points at Alice, lights up, and tells her about it. Used by the chat
// (chatFormat.php shows them, chat/index.php sends the notifications) and the games' chats (games/chess/chat.php and
// games/matador/chat.php).
//
// A mention is an @ at the start of a word (so an email address isn't one) followed by the name of someone who's in
// that chat. Names can have spaces, punctuation and emoji in them, so there's no telling where a name ends by looking
// at the text: instead what comes after the @ is checked against the names of the people who are there, the longest
// first ("@Anna Sofie" is Anna Sofie, even when there's an Anna too). Upper and lower case don't matter.
// The text is stored just as it was typed, so mentions are worked out every time it's shown: someone who has left the
// chat or changed their name since is just text.
//
// A chat builds its lookup once with hbHubMentionLookup() from the people in it, and hands that to the functions below.

/**
 * The lookup for everyone who can be mentioned in a chat, from the people in it.
 *
 * @param array<int, array{name: string, names: list<string>, isYou?: bool}> $people by userId: the name to show for them,
 *     every name they can be mentioned by (their username, and a nickname in the chat), and whether it's the user
 * @return list<array{typed: string, userId: int, name: string, isYou: bool}> one per name, the longest names first
 */
function hbHubMentionLookup(array $people): array
{
    $lookup = [];
    foreach ($people as $userId => $person) {
        foreach (array_unique($person['names']) as $typed) {
            if ($typed !== '') {
                $lookup[] = ['typed' => $typed, 'userId' => (int) $userId, 'name' => $person['name'], 'isYou' => !empty($person['isYou'])];
            }
        }
    }
    usort($lookup, static fn (array $a, array $b): int => mb_strlen($b['typed']) <=> mb_strlen($a['typed']));
    return $lookup;
}

/**
 * Who the text mentions right after an @, with $offset (in bytes) just after the @, or null when it's nobody in the chat.
 * The name has to end there too: "@Annabel" isn't a mention of Anna, but "@Anna's" and "@Anna!" are.
 *
 * @return ?array{length: int, userId: int, name: string, isYou: bool} length is how much of the text the name took, in bytes
 */
function hbHubMentionAt(string $text, int $offset, array $lookup): ?array
{
    foreach ($lookup as $person) {
        // Not a letter or number right after the name, when the name ends with one itself
        $pattern = '/\G' . preg_quote($person['typed'], '/') . '(?!(?<=[\p{L}\p{N}_])[\p{L}\p{N}_])/iu';
        if (preg_match($pattern, $text, $match, 0, $offset) === 1) {
            return ['length' => strlen($match[0]), 'userId' => $person['userId'], 'name' => $person['name'], 'isYou' => $person['isYou']];
        }
    }
    return null;
}

/**
 * A message with no other formatting (the games' chats) cut up into its text and its mentions, for the game's script to
 * draw: [{text: "Good move "}, {text: "@Alice", mention: true, userId: 5, isYou: false}, {text: "!"}]. A mention shows
 * the name the chat knows them by, however it was typed.
 *
 * @return list<array{text: string, mention?: true, userId?: int, isYou?: bool}>
 */
function hbHubMentionParts(string $text, array $lookup): array
{
    $parts = [];
    $plain = '';
    $offset = 0;
    while ($lookup !== [] && preg_match('/(?<![\p{L}\p{N}_])@/u', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $at = $match[0][1];
        $person = hbHubMentionAt($text, $at + 1, $lookup);
        $plain .= substr($text, $offset, $at - $offset + 1);
        $offset = $at + 1;
        if ($person === null) {
            continue; // just an @, it stays in the text
        }

        $plain = substr($plain, 0, -1); // the @ goes with the mention
        if ($plain !== '') {
            $parts[] = ['text' => $plain];
        }
        $parts[] = ['text' => '@' . $person['name'], 'mention' => true, 'userId' => $person['userId'], 'isYou' => $person['isYou']];
        $plain = '';
        $offset += $person['length'];
    }

    $plain .= substr($text, $offset);
    if ($plain !== '') {
        $parts[] = ['text' => $plain];
    }
    return $parts;
}

/**
 * The userIds of everyone the text mentions (from hbHubMentionParts()), once each, leaving out the user themselves.
 *
 * @return list<int>
 */
function hbHubMentionedUserIds(string $text, array $lookup): array
{
    $userIds = [];
    foreach (hbHubMentionParts($text, $lookup) as $part) {
        if (!empty($part['mention']) && !$part['isYou']) {
            $userIds[$part['userId']] = $part['userId'];
        }
    }
    return array_values($userIds);
}
