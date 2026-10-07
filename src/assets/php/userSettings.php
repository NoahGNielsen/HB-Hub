<?php
// The userSettings column of HBHub-Users: same shape as database/defaultUserSettings.json.
// Accounts made before a setting existed don't have it saved, so always read with a default.

/**
 * The saved settings as an array, or [] when there are none (or they can't be read).
 */
function hbHubUserSettings(?string $json): array
{
    $settings = $json !== null ? json_decode($json, true) : null;
    return is_array($settings) ? $settings : [];
}

/**
 * Changes the user's settings: $change gets them to change, then they're saved.
 * Locked while that happens, so two changes at once can't undo each other.
 *
 * @param callable(array): array $change gets the settings and returns the changed ones
 */
function hbHubChangeUserSettings(PDO $db, int $userId, callable $change): void
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT userSettings FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $settings = $change(hbHubUserSettings($statement->fetchColumn() ?: null));

        $db->prepare('UPDATE `HBHub-Users` SET userSettings = ? WHERE userId = ?')
            ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $userId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
