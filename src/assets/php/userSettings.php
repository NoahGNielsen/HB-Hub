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
 * The "Help improve HB Hub" card in the settings has two switches: Error reports (errorReports,
 * hbHubErrorReportsOn()) and Anonymous usage statistics (usageStats, hbHubUsageStatsOn()). Both are on unless they've
 * been turned off, so accounts from before the settings have them on, like visitors who aren't logged in. An account
 * made with a browser that sends Global Privacy Control or Do Not Track starts with both off (onboardingSaveToDB.php).
 *
 * They used to be one switch, improveSite. An account that turned that off and hasn't touched the new switches since
 * still has both off: each one falls back to improveSite when it isn't saved yet.
 *
 * Anonymous usage statistics depends on Error reports: it only counts as on while Error reports is on too
 * (hbHubUsageStatsOn()), and the settings page greys it out while Error reports is off.
 */
function hbHubImproveSettingOn(array $settings, string $key): bool
{
    $value = $settings[$key] ?? $settings['improveSite'] ?? true;
    return $value !== false;
}

/**
 * Whether the user lets the browser send error reports (errorReports). While it's off:
 * - The Watchdog's browser error reports (assets/js/watchdog.js) aren't sent: header.php leaves the script out, and
 *   /watchdog (otherPages/watchdog.php) throws away any that were still waiting in the tab from before.
 * - Ore Mining Inc. doesn't ask Chrome to tell us when its tab crashes (the Reporting-Endpoints header in
 *   games/spaceOreMiner/index.php, see games/spaceOreMiner/browserCrash.php).
 * - Ore Mining Inc.'s own crash reports still ask after a crash, since they're only ever sent when the player says
 *   yes, but the popup warns that sending one goes against this setting (for that report only; the setting stays off).
 * Anything new that reports errors from the user's browser has to check this too.
 */
function hbHubErrorReportsOn(array $settings): bool
{
    return hbHubImproveSettingOn($settings, 'errorReports');
}

/**
 * Whether the user's visits count towards the anonymous usage statistics (usageStats): while it's off, header.php
 * doesn't count their page views (pageViews.php). Anything new that counts how people use the site has to check this too.
 * Always off while Error reports is off, whatever usageStats says: it depends on that switch.
 */
function hbHubUsageStatsOn(array $settings): bool
{
    return hbHubErrorReportsOn($settings) && hbHubImproveSettingOn($settings, 'usageStats');
}

/**
 * Both "Help improve HB Hub" switches as one word, for the admin panel (the users list, a user's page and the
 * Overview's pie chart): 'all' (both on), 'errors' (only Error reports) or 'none' (both off). 'stats' (only Anonymous
 * usage statistics) can't happen any more, as that switch depends on Error reports, but the admin panel still knows it.
 */
function hbHubImproveLevel(array $settings): string
{
    $errors = hbHubErrorReportsOn($settings);
    $stats = hbHubUsageStatsOn($settings);
    return $errors ? ($stats ? 'all' : 'errors') : ($stats ? 'stats' : 'none');
}

/**
 * Whether the chat's attachment tray shows the "Keep original pictures" and "Keep original videos" boxes for the user
 * (chatKeepOriginal, the Attachments card in the settings). Off unless they've turned it on, so pictures and videos
 * are always converted to JPEG XL and WebM for everyone else.
 */
function hbHubChatKeepOriginalOn(array $settings): bool
{
    return ($settings['chatKeepOriginal'] ?? false) === true;
}

/**
 * Whether the user muted the chat right now, so it doesn't send them notifications (chatMuted in their settings,
 * keyed by chatId). A mute with a Unix time in chatMutedUntil ends then, one without lasts until they unmute it.
 */
function hbHubChatIsMuted(array $settings, int $chatId): bool
{
    if (($settings['chatMuted'][$chatId] ?? false) !== true) {
        return false;
    }
    $until = $settings['chatMutedUntil'][$chatId] ?? null;
    return $until === null || (is_int($until) && $until > time());
}

/**
 * The ids of every chat the user has muted right now.
 *
 * @return list<int>
 */
function hbHubMutedChatIds(array $settings): array
{
    $chatIds = is_array($settings['chatMuted'] ?? null) ? array_filter(array_keys($settings['chatMuted']), 'is_int') : [];
    return array_values(array_filter($chatIds, fn (int $chatId): bool => hbHubChatIsMuted($settings, $chatId)));
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
