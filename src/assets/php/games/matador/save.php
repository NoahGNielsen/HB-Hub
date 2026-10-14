<?php
// Saved Matador games (HBHub-MatadorSaves): one per user, in the slot of whoever opened the game (its owner,
// roomOwnerUserId), so the host can stop a game halfway and continue it later.
//
// A game takes over its owner's slot when it starts (hbHubMatadorClaimSave(), overwriting what was saved there), and from
// then on it's saved after every change (hbHubMatadorAutosave(), from room.php's hbHubMatadorPlay()). saveRoomId says
// which room the slot belongs to, so when the owner starts another game, the first one stops being saved instead of the
// two writing over each other. When the room is deleted (everyone left, or the host chose Save & stop) saveRoomId goes
// back to NULL, and the owner can continue the game from the Matador page (hbHubMatadorContinue()). A game that has
// been won is deleted from the slot, as there's nothing left to continue.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../notifications.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/room.php';

/**
 * The user's saved game, or null when they have none.
 *
 * @return ?array{name: string, players: list<array{name: string, bankrupt: bool}>, round: int, minutesAgo: int, isPlaying: bool}
 */
function hbHubMatadorSaveOf(PDO $db, int $userId): ?array
{
    $statement = $db->prepare('SELECT saveName, saveSummary, saveRoomId, TIMESTAMPDIFF(MINUTE, saveTimestamp, UTC_TIMESTAMP()) AS minutesAgo
        FROM `HBHub-MatadorSaves` WHERE userId = ?');
    $statement->execute([$userId]);
    $save = $statement->fetch();
    if ($save === false) {
        return null;
    }

    $summary = json_decode($save['saveSummary'], true) ?: [];
    return [
        'name' => $save['saveName'],
        'players' => $summary['players'] ?? [],
        'round' => (int) ($summary['round'] ?? 0),
        'minutesAgo' => max(0, (int) $save['minutesAgo']),
        'isPlaying' => $save['saveRoomId'] !== null,
    ];
}

/**
 * What the Matador page shows about a saved game, so it doesn't have to read the whole game.
 */
function hbHubMatadorSummary(array $state): array
{
    return [
        'players' => array_map(static fn (array $player): array => ['name' => $player['name'], 'bankrupt' => $player['bankrupt']], $state['players']),
        'round' => $state['round'],
    ];
}

/**
 * The game as it's kept in a save, and the summary of it.
 */
function hbHubMatadorSaveRow(array $state): array
{
    return [
        json_encode($state, JSON_THROW_ON_ERROR),
        json_encode(hbHubMatadorSummary($state), JSON_THROW_ON_ERROR),
    ];
}

/**
 * A new game takes over its owner's save slot, overwriting the game saved there.
 */
function hbHubMatadorClaimSave(PDO $db, array $room): void
{
    if ($room['roomOwnerUserId'] === null) {
        return;
    }
    [$state, $summary] = hbHubMatadorSaveRow($room['state']);
    $db->prepare('INSERT INTO `HBHub-MatadorSaves` (userId, saveName, saveState, saveSummary, saveRoomId, saveTimestamp)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE saveName = VALUES(saveName), saveState = VALUES(saveState), saveSummary = VALUES(saveSummary),
            saveRoomId = VALUES(saveRoomId), saveTimestamp = VALUES(saveTimestamp)')
        ->execute([$room['roomOwnerUserId'], $room['roomName'], $state, $summary, $room['roomId']]);
}

/**
 * Whether the owner's save slot belongs to this room.
 */
function hbHubMatadorHoldsSave(PDO $db, array $room, bool $lock = false): bool
{
    if ($room['roomOwnerUserId'] === null) {
        return false;
    }
    $statement = $db->prepare('SELECT saveRoomId FROM `HBHub-MatadorSaves` WHERE userId = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$room['roomOwnerUserId']]);
    $roomId = $statement->fetchColumn();
    return $roomId !== false && $roomId !== null && (int) $roomId === $room['roomId'];
}

/**
 * Saves the game being played in its owner's slot (in hbHubMatadorPlay()'s transaction), or deletes it from there once
 * it has been won. When the slot has another game in it now, the game is marked as not saved anymore instead.
 */
function hbHubMatadorAutosave(PDO $db, array &$room): void
{
    $holds = hbHubMatadorHoldsSave($db, $room, true);

    if ($room['state']['phase'] === 'ended') {
        if ($holds) {
            $db->prepare('DELETE FROM `HBHub-MatadorSaves` WHERE userId = ?')->execute([$room['roomOwnerUserId']]);
        }
        return;
    }

    if (!$holds) {
        if (!$room['state']['unsaved']) {
            $room['state']['unsaved'] = true;
            hbHubMatadorLog($room['state'], 'This game isn\'t being saved anymore: its save slot has another game in it now.');
        }
        return;
    }

    [$state, $summary] = hbHubMatadorSaveRow($room['state']);
    $db->prepare('UPDATE `HBHub-MatadorSaves` SET saveName = ?, saveState = ?, saveSummary = ?, saveTimestamp = UTC_TIMESTAMP() WHERE userId = ?')
        ->execute([$room['roomName'], $state, $summary, $room['roomOwnerUserId']]);
}

/**
 * Opens a new room from the user's saved game, with the user (its owner) as the host in their old seat. The others'
 * seats are kept for them, and they get an invite back. Returns the room's id, or why it can't be continued.
 */
function hbHubMatadorContinue(PDO $db, int $userId, string $userName): int|string
{
    if (hbHubMatadorUserSeat($db, $userId) !== null) {
        return 'You\'re already in a game. Leave it first.';
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT saveState, saveRoomId FROM `HBHub-MatadorSaves` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $save = $statement->fetch();
        $error = match (true) {
            $save === false => 'You don\'t have a saved game.',
            $save['saveRoomId'] !== null => 'Your saved game is being played right now.',
            default => null,
        };
        if ($error !== null) {
            $db->rollBack();
            return $error;
        }

        $state = json_decode($save['saveState'], true, 512, JSON_THROW_ON_ERROR);
        $seat = null;
        foreach ($state['players'] as &$player) {
            $player['userId'] = null;
            $player['reservedFor'] = null;
            if ($player['originalUserId'] === $userId) {
                $player['userId'] = $userId;
                $player['name'] = $userName;
                $seat = $player['seat'];
            }
        }
        unset($player);
        if ($seat === null) {
            $db->rollBack();
            return 'You don\'t have a seat in your saved game anymore (it was given to someone else), so it can\'t be continued.';
        }
        $state['phase'] = 'resume';
        $state['trades'] = [];
        $state['unsaved'] = false;

        $roomId = hbHubMatadorInsertRoom($db, $state, $userId);
        hbHubMatadorInsertSeat($db, $roomId, $userId, $seat);
        $db->prepare('UPDATE `HBHub-MatadorSaves` SET saveRoomId = ? WHERE userId = ?')->execute([$roomId, $userId]);

        // The others are invited back to their seats (accounts that are gone or banned just have an empty seat)
        $others = [];
        foreach ($state['players'] as $player) {
            if ($player['originalUserId'] !== $userId && !$player['bankrupt']) {
                $others[$player['originalUserId']] = $player['seat'];
            }
        }
        if ($others !== []) {
            $statement = $db->prepare('SELECT userId FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ?
                AND userId IN (' . implode(', ', array_fill(0, count($others), '?')) . ')');
            $statement->execute([HBHUB_MATADOR_STATUS_BANNED, ...array_keys($others)]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $otherId) {
                hbHubMatadorAddInvite($db, $roomId, (int) $otherId, $userId, $others[(int) $otherId]);
                hbHubNotify($db, (int) $otherId, 'games.matadorContinue', $userId, ['roomId' => $roomId, 'roomName' => $state['roomName']]);
            }
        }

        $db->commit();
        return $roomId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Deletes the user's saved game. Returns null when it's gone, otherwise why not.
 */
function hbHubMatadorDeleteSave(PDO $db, int $userId): ?string
{
    $statement = $db->prepare('DELETE FROM `HBHub-MatadorSaves` WHERE userId = ? AND saveRoomId IS NULL');
    $statement->execute([$userId]);
    return $statement->rowCount() > 0 || hbHubMatadorSaveOf($db, $userId) === null
        ? null
        : 'Your saved game is being played right now. It can be deleted once nobody is playing it.';
}
