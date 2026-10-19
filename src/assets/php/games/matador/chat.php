<?php
// A Matador game's chat (HBHub-MatadorChat): the players in the game write to each other. It's kept with the room and
// goes when the room does - a saved game that's continued starts with an empty chat. The newest message's id is part of
// the game's version (room.php's hbHubMatadorVersion()), so matadorGame.js gets the chat again when there's something new.
// @mentions (assets/php/mentions.php) work for the players in the room: they light up, and one whose game isn't open
// gets a notification (games.matadorMention) - just the one until they're back, however often they're mentioned.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../mentions.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/../../urlBlacklist.php'; // IP loggers and other links that can't be sent

const HBHUB_MATADOR_CHAT_MAX = 300; // chatText column
const HBHUB_MATADOR_CHAT_SHOWN = 50; // the newest messages sent to the game
const HBHUB_MATADOR_CHAT_BURST = 5; // the most messages a player can send in HBHUB_MATADOR_CHAT_BURST_SECONDS
const HBHUB_MATADOR_CHAT_BURST_SECONDS = 10;

/**
 * The user writes $text (as sent) in the room's chat. Returns null when it's sent, otherwise why not.
 */
function hbHubMatadorSay(PDO $db, int $roomId, int $userId, mixed $text): ?string
{
    $text = is_string($text) ? trim(preg_replace('~[\p{Cc}\s]+~u', ' ', $text) ?? '') : '';
    if ($text === '') {
        return 'Write a message first.';
    }
    if (mb_strlen($text) > HBHUB_MATADOR_CHAT_MAX) {
        return 'A message can be up to ' . HBHUB_MATADOR_CHAT_MAX . ' characters.';
    }
    if (hbHubUrlBlacklisted($text)) {
        return HBHUB_URL_BLACKLIST_MESSAGE;
    }

    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-MatadorChat`
        WHERE userId = ? AND chatTimestamp > UTC_TIMESTAMP() - INTERVAL ' . HBHUB_MATADOR_CHAT_BURST_SECONDS . ' SECOND');
    $statement->execute([$userId]);
    if ((int) $statement->fetchColumn() >= HBHUB_MATADOR_CHAT_BURST) {
        return 'You\'re writing too fast. Wait a few seconds.';
    }

    $db->prepare('INSERT INTO `HBHub-MatadorChat` (roomId, userId, chatText, chatTimestamp) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$roomId, $userId, $text]);

    // The players it @mentions who aren't looking at the game hear about it, once until they're back
    $people = hbHubMatadorChatPeople($db, $roomId, $userId);
    foreach (hbHubMentionedUserIds($text, hbHubMentionLookup($people)) as $mentionedId) {
        if ($people[$mentionedId]['isAway']
            && !hbHubHasUnreadNotificationAbout($db, $mentionedId, 'games.matadorMention', ['roomId' => $roomId])) {
            $statement = $db->prepare('SELECT roomName FROM `HBHub-MatadorRooms` WHERE roomId = ?');
            $statement->execute([$roomId]);
            hbHubNotify($db, $mentionedId, 'games.matadorMention', $userId, ['roomId' => $roomId, 'roomName' => $statement->fetchColumn() ?: null]);
        }
    }
    return null;
}

/**
 * Everyone in the room's seats, who can be @mentioned in its chat: by userId, their name (for hbHubMentionLookup()),
 * whether it's the user, and whether they're away (their game hasn't checked in for HBHUB_MATADOR_AWAY_SECONDS).
 *
 * @return array<int, array{name: string, names: list<string>, isYou: bool, isAway: bool}>
 */
function hbHubMatadorChatPeople(PDO $db, int $roomId, int $userId): array
{
    // The time is worked out by the database, so PHP's time zone doesn't matter
    $statement = $db->prepare('SELECT s.userId, u.userName,
            TIMESTAMPDIFF(SECOND, s.seatLastSeenTimestamp, UTC_TIMESTAMP()) >= ' . HBHUB_MATADOR_AWAY_SECONDS . ' AS isAway
        FROM `HBHub-MatadorSeats` s JOIN `HBHub-Users` u ON u.userId = s.userId
        WHERE s.roomId = ? AND u.userStatus IS NOT NULL');
    $statement->execute([$roomId]);

    $people = [];
    foreach ($statement->fetchAll() as $seat) {
        $people[(int) $seat['userId']] = [
            'name' => $seat['userName'],
            'names' => [$seat['userName']],
            'isYou' => (int) $seat['userId'] === $userId,
            'isAway' => (bool) $seat['isAway'],
        ];
    }
    return $people;
}

/**
 * The id of the room's newest message, 0 when nothing has been written.
 */
function hbHubMatadorLastChatId(PDO $db, int $roomId): int
{
    $statement = $db->prepare('SELECT MAX(chatMessageId) FROM `HBHub-MatadorChat` WHERE roomId = ?');
    $statement->execute([$roomId]);
    return (int) $statement->fetchColumn();
}

/**
 * The room's newest messages, the oldest first, with who wrote them and when (UTC, ISO 8601).
 *
 * @return list<array{id: int, userId: int, name: string, text: string, time: string}>
 */
function hbHubMatadorChat(PDO $db, int $roomId): array
{
    $statement = $db->prepare('SELECT c.chatMessageId, c.userId, u.userName, c.chatText, c.chatTimestamp
        FROM `HBHub-MatadorChat` c
        JOIN `HBHub-Users` u ON u.userId = c.userId
        WHERE c.roomId = ?
        ORDER BY c.chatMessageId DESC
        LIMIT ' . HBHUB_MATADOR_CHAT_SHOWN);
    $statement->execute([$roomId]);

    return array_reverse(array_map(static fn (array $message): array => [
        'id' => (int) $message['chatMessageId'],
        'userId' => (int) $message['userId'],
        'name' => $message['userName'],
        'text' => $message['chatText'],
        'time' => str_replace(' ', 'T', $message['chatTimestamp']) . 'Z',
    ], $statement->fetchAll()));
}
