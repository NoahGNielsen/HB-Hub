<?php
// The Matador game's JSON, for matadorGame.js (the page around it is game.php). Only for the user's own game:
// - GET ?room=id&v=version is the game as the players may see it (hbHubMatadorView() in game.php), with the game's
//   version (hbHubMatadorVersion() in room.php). When the version hasn't changed it's just {"v": version, "same": true}.
//   The game asks every second. About every 15 seconds navUpdates.js sends the nav bar's request along (?nav=), and
//   then either answer has "nav" too (hbHubNavRide() in assets/php/navUpdates.php).
// - POST room=id&action=... does something in the game, and answers with the whole game the same way, plus "error"
//   (a message to show) when it couldn't be done. The actions:
//     In your turn (with turn: the turn id the game showed when it was clicked, so a double click only counts once):
//       roll, buy, decline, tax (choice: fixed or percent), payDebt, bankrupt, payBail, useCard and endTurn
//     In your turn, but as often as you like: build, sell, mortgage and unmortgage (field)
//     Any time: offer (to, giveFields, giveCash, giveCards, getFields, getCash, getCards), answer (trade: its id,
//       answer: accept, decline or cancel), minimize (trade: its id - the player it was offered to minimized its
//       pop-up, so it runs out after 30 seconds unless they answer, game.php's hbHubMatadorMinimizeTrade()) and leave
//     For the host: start, invite (user), giveSeat (seat, user), saveStop, pause and resume (room.php's
//       hbHubMatadorPause()), and settings (dice: 1 or 2, jailRent: yes, no or bank, parkingPot: on or off,
//       building: group, uneven or any - before the game starts)
//     chat (text) writes in the game's chat (chat.php), for anyone in the game
//   Anything the player whose turn it is does (not the chat) starts their 30 seconds again (hbHubMatadorPlayerActed()).
//   While the host has paused the game, the moves, building and trades are turned down. The chat, leaving, minimizing
//   an offer and the host's actions still work.
//   The game has the chat's newest messages in "chat", each with the seat of who wrote it (null when they have none) and
//   the text cut up into its @mentions in "parts" (hbHubMentionParts()), and who can be mentioned in "chatMentions"
//   (for mentions.js).
// Answers 404 with {"error": "notSeated"} when the user isn't in that game (anymore), or "stopped" when it's gone.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navUpdates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/room.php';
hbHubGamesJson($hbHubUser); // blocked in settings, or not open yet

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;

$seat = hbHubMatadorUserSeat($db, $userId);
if ($seat === null || ($input['room'] ?? null) !== (string) $seat['roomId']) {
    // A game that's gone while the user was in it was stopped by its host (their seat went with it). One the user left
    // or was taken out of is still there, or was only deleted after they'd gone.
    $askedFor = $input['room'] ?? null;
    $isGone = $seat === null && is_string($askedFor) && ctype_digit($askedFor) && strlen($askedFor) <= 10
        && hbHubMatadorRoom($db, (int) $askedFor) === null;
    http_response_code(404);
    echo json_encode(['error' => $isGone ? 'stopped' : 'notSeated']);
    exit;
}
$roomId = $seat['roomId'];
hbHubMatadorTouchSeat($db, $userId);

$action = $isPost ? ($input['action'] ?? '') : null;
if ($action === 'leave') {
    hbHubMatadorLeave($db, $userId);
    echo json_encode(['left' => true]);
    exit;
}

// Writing in the chat doesn't change the game, so it's answered like just looking at it
$chatError = null;
if ($action === 'chat') {
    $chatError = hbHubMatadorSay($db, $roomId, $userId, $input['text'] ?? null);
    $action = null;
}

/**
 * A move in the user's turn: only when the turn id they sent is still the turn's (otherwise it was already done - a
 * double click - and nothing happens). The turn id goes up once it's done.
 */
$turn = static fn (callable $move): callable => static function (array &$room) use ($move, $input, $userId): ?string {
    $state = &$room['state'];
    $seat = hbHubMatadorSeatOfUser($state, $userId);
    if ($seat === null || $state['phase'] !== 'playing' || ($input['turn'] ?? null) !== (string) $state['turn']['id']) {
        return null;
    }
    if (hbHubMatadorIsPaused($state)) {
        return 'The game is paused.';
    }
    $error = $move($state, $seat);
    if ($error === null && $state['phase'] === 'playing') {
        $state['turn']['id']++;
    }
    hbHubMatadorPlayerActed($state, $seat);
    return $error;
};

/**
 * Anything else a player does in the game, with their seat. In their turn it also starts their time again, like a move.
 */
$player = static fn (callable $do): callable => static function (array &$room) use ($do, $userId): ?string {
    $seat = hbHubMatadorSeatOfUser($room['state'], $userId);
    if ($seat === null) {
        return 'You aren\'t playing in this game.';
    }
    if (hbHubMatadorIsPaused($room['state'])) {
        return 'The game is paused.';
    }
    $error = $do($room['state'], $seat);
    hbHubMatadorPlayerActed($room['state'], $seat);
    return $error;
};

/**
 * Building, selling and mortgaging: like $player, but only in the player's own turn.
 */
$ownTurn = static fn (callable $do): callable => $player(
    static fn (array &$state, int $seat): ?string => hbHubMatadorOwnTurnProblem($state, $seat) ?? $do($state, $seat));

$field = isset($input['field']) && is_string($input['field']) && ctype_digit($input['field']) && strlen($input['field']) <= 2
    ? (int) $input['field']
    : null;

$do = match ($action) {
    null => null,
    'roll' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorRoll($state, $seat)),
    'buy' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorBuy($state, $seat)),
    'decline' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorDecline($state, $seat)),
    'tax' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorTax($state, $seat, $input['choice'] ?? null)),
    'payDebt' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorPayDebt($state, $seat)),
    'bankrupt' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorDeclareBankruptcy($state, $seat)),
    'payBail' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorPayBail($state, $seat)),
    'useCard' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorUseCard($state, $seat)),
    'endTurn' => $turn(static fn (array &$state, int $seat): ?string => hbHubMatadorEndTurn($state, $seat)),
    'build' => $ownTurn(static fn (array &$state, int $seat): ?string => hbHubMatadorBuild($state, $seat, $field)),
    'sell' => $ownTurn(static fn (array &$state, int $seat): ?string => hbHubMatadorSell($state, $seat, $field)),
    'mortgage' => $ownTurn(static fn (array &$state, int $seat): ?string => hbHubMatadorMortgage($state, $seat, $field)),
    'unmortgage' => $ownTurn(static fn (array &$state, int $seat): ?string => hbHubMatadorUnmortgage($state, $seat, $field)),
    'offer' => $player(static fn (array &$state, int $seat): ?string => hbHubMatadorOffer($state, $seat, $input['to'] ?? null,
        hbHubMatadorTradeSide($input['giveFields'] ?? '', $input['giveCash'] ?? '', $input['giveCards'] ?? ''),
        hbHubMatadorTradeSide($input['getFields'] ?? '', $input['getCash'] ?? '', $input['getCards'] ?? ''))),
    'answer' => $player(static fn (array &$state, int $seat): ?string => in_array($input['answer'] ?? null, ['accept', 'decline', 'cancel'], true)
        ? hbHubMatadorAnswerTrade($state, $seat, $input['trade'] ?? null, $input['answer'])
        : 'Choose an answer.'),
    // Not a move, so it doesn't start the turn's time again, and it works while the game is paused
    'minimize' => static function (array &$room) use ($input, $userId): ?string {
        $seat = hbHubMatadorSeatOfUser($room['state'], $userId);
        return $seat !== null ? hbHubMatadorMinimizeTrade($room['state'], $seat, $input['trade'] ?? null) : 'You aren\'t playing in this game.';
    },
    'start' => static fn (array &$room): ?string => hbHubMatadorStart($db, $room, $userId),
    'invite' => static fn (array &$room): ?string => hbHubMatadorInvite($db, $room, $userId, $input['user'] ?? null),
    'giveSeat' => static fn (array &$room): ?string => hbHubMatadorGiveSeat($db, $room, $userId, $input['seat'] ?? null, $input['user'] ?? null),
    'saveStop' => static fn (array &$room): ?string => hbHubMatadorSaveAndStop($db, $room, $userId),
    'pause', 'resume' => static fn (array &$room): ?string => hbHubMatadorPause($room, $userId, $action === 'pause'),
    'settings' => static fn (array &$room): ?string => hbHubMatadorChangeSettings($room, $userId, $input['dice'] ?? null,
        $input['jailRent'] ?? null, $input['parkingPot'] ?? null, $input['building'] ?? null),
    default => false,
};
if ($do === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknownAction']);
    exit;
}

// Just looking only locks the room when there's something to move on (someone has gone, or the host isn't there)
$played = null;
if ($do === null) {
    $room = hbHubMatadorRoom($db, $roomId);
    $seats = $room !== null ? hbHubMatadorSeats($db, $roomId) : [];
    if ($room !== null && !hbHubMatadorNeedsPlay($room, $seats)) {
        $played = [$room, $seats, null];
    }
}
$played ??= hbHubMatadorPlay($db, $roomId, $do);
if ($played === null || !empty($played[0]['isDeleted'])) {
    http_response_code(404);
    echo json_encode(['error' => 'stopped']); // the host saved and stopped it
    exit;
}
if (!hbHubMatadorSeated($played[1], $userId)) {
    http_response_code(404);
    echo json_encode(['error' => 'notSeated']); // taken out of the game (gone too long)
    exit;
}
[$room, $seats, $error] = $played;
$error ??= $chatError;

$version = hbHubMatadorVersion($room, $seats, hbHubMatadorLastChatId($db, $roomId));
$nav = hbHubNavRide($db, $hbHubUser); // the nav bar's numbers, when navUpdates.js sent its request along (?nav=)
if (!$isPost && ($input['v'] ?? null) === $version) {
    echo json_encode(['v' => $version, 'same' => true] + ($nav !== null ? ['nav' => $nav] : []), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$state = $room['state'];
$people = hbHubMatadorPeople($db, array_filter([
    ...array_column($state['players'], 'userId'),
    ...array_column($state['players'], 'reservedFor'),
    $room['roomOwnerUserId'],
], static fn (?int $id): bool => $id !== null));
$away = array_column(array_map(static fn (array $seat): array => [$seat['userId'], $seat['away']], $seats), 1, 0);

// Who wrote what in the chat, by their seat: the one they play, or the one kept for them after they left
$chatSeats = [];
foreach ($state['players'] as $player) {
    foreach ([$player['originalUserId'], $player['userId']] as $playerUserId) {
        if ($playerUserId !== null) {
            $chatSeats[$playerUserId] = $player['seat'];
        }
    }
}
// The chat's @mentions (assets/php/mentions.php) are of the players in the room. Being back in the game means the user
// has seen any they were sent a notification about.
$chatPeople = hbHubMatadorChatPeople($db, $roomId, $userId);
$chatMentions = hbHubMentionLookup($chatPeople);
hbHubMarkNotificationsReadAbout($db, $userId, 'games.matadorMention', ['roomId' => $roomId]);
$chat = array_map(static fn (array $message): array => [
    'id' => $message['id'],
    'seat' => $chatSeats[$message['userId']] ?? null,
    'name' => $message['name'],
    'text' => $message['text'],
    'parts' => hbHubMentionParts($message['text'], $chatMentions),
    'time' => $message['time'],
    'isYou' => $message['userId'] === $userId,
], hbHubMatadorChat($db, $roomId));
$chatNames = [];
foreach ($chatPeople as $person) {
    if (!$person['isYou']) {
        $chatNames[] = ['name' => $person['name'], 'userName' => $person['name']];
    }
}

$response = [
    'v' => $version,
    'game' => [
        'room' => [
            'id' => $room['roomId'],
            'name' => $room['roomName'],
            'code' => $room['roomCode'],
            'type' => HBHUB_MATADOR_ROOM_TYPES[$room['roomType']] ?? 'public',
            'players' => count($seats),
            'owner' => $room['roomOwnerUserId'] !== null ? ($people[$room['roomOwnerUserId']]['name'] ?? null) : null,
            'isOwner' => $room['roomOwnerUserId'] === $userId,
        ],
    ] + hbHubMatadorView($state, $people, $away, $userId, $room['roomHostUserId'])
        + ['chat' => $chat, 'chatMentions' => $chatNames],
];
if (is_string($error)) {
    $response['error'] = $error;
}
if ($nav !== null) {
    $response['nav'] = $nav;
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
