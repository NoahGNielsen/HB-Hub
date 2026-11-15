<?php
// Creates the account once the onboarding form is valid, then logs the new user in.
// When the picture is on the banned list (bannedAvatars.php), the account is made banned instead, and it's on to the
// login page with the browser marked, like a banned account's (session.php).
// When a ghost-banned account is known in the browser (loginLimit.php), the new account is quietly ghost banned too.
// A new account at HTX is put in its class chat (schoolClass.php).
// Signing up with a browser that sends "Do Not Track" makes the account with "Help improve HB Hub" off, and tells them
// so with a notification (settings.doNotTrack in notificationTypes/settings.php).
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/loginLimit.php';
require_once __DIR__ . '/bannedAvatars.php';
require_once __DIR__ . '/schoolClass.php'; // how the class is saved, and the class chat they're put in
require_once __DIR__ . '/notifications.php';

/**
 * Whether the browser signing up asks sites not to track it: the "Do Not Track" setting, sent as the header DNT: 1
 * (hbHubSessionDoNotTrack() in session.php).
 */
function hbHubSignupDoNotTrack(): bool
{
    return hbHubSessionDoNotTrack();
}

/**
 * Returns [] when the account was created, otherwise step name => message to show on the form.
 * No more than $accountsPerHour accounts are made from the same address in an hour.
 * A banned picture doesn't return: the banned account is made and it redirects to the login page.
 * $discord is the Discord account they signed up with (hbHubDiscordSignupGet() in discord.php), made linked to it.
 */
function onboardingSaveToDB(array $values, string $password, ?string $avatarBlob, ?string $avatarType, int $accountsPerHour, ?array $discord = null): array
{
    [$ipv4, $ipv6] = hbHubClientIp();

    // Same shape as database/defaultUserSettings.json. Do Not Track turns "Help improve HB Hub" off from the start
    // (hbHubImproveSiteOn() in userSettings.php), so not even the first page after signing up is counted.
    $doNotTrack = hbHubSignupDoNotTrack();
    $settings = hbHubClassSetSettings([], $values['year'], $values['class']);
    if ($doNotTrack) {
        $settings['improveSite'] = false;
    }
    $settings = json_encode($settings);

    $db = null;
    $bannedAvatarId = null;
    try {
        $db = hbHubDatabase();

        // Deleted accounts don't count, their addresses are gone
        $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users` WHERE userCreatedTimestamp > NOW() - INTERVAL 1 HOUR
            AND (userIpv4AdresseOnAccountCreate = ? OR userIpv6AdresseOnAccountCreate = ?)');
        $statement->execute([$ipv4, $ipv6]);
        if ((int) $statement->fetchColumn() >= $accountsPerHour) {
            return ['password' => 'Der er oprettet for mange konti fra dette netværk den sidste time. Prøv igen senere.'];
        }

        $bannedAvatarId = $avatarBlob !== null ? hbHubBannedAvatarFind($db, $avatarBlob) : null;

        $db->beginTransaction();

        $avatarId = null;
        if ($avatarBlob !== null) {
            $statement = $db->prepare('INSERT INTO `HBHub-Attachments` (attachmentType, attachmentFile) VALUES (?, ?)');
            $statement->bindValue(1, $avatarType);
            $statement->bindValue(2, $avatarBlob, PDO::PARAM_LOB);
            $statement->execute();
            $avatarId = (int) $db->lastInsertId();
        }

        $db->prepare('INSERT INTO `HBHub-Users` (userName, userNameLower, userDescription, userSettings, userAvatarAttachmentId,
                userIpv4AdresseOnAccountCreate, userIpv6AdresseOnAccountCreate, userIpv4AdresseLastAccessed, userIpv6AdresseLastAccessed, userPasswordHash,
                userDiscordId, userDiscordName)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $values['name'],
                mb_strtolower($values['name']),
                $values['description'] === '' ? null : $values['description'],
                $settings,
                $avatarId,
                $ipv4,
                $ipv6,
                $ipv4,
                $ipv6,
                password_hash($password, PASSWORD_DEFAULT),
                $discord['id'] ?? null,
                $discord['name'] ?? null,
            ]);
        $userId = (int) $db->lastInsertId();

        if ($bannedAvatarId !== null) {
            // Kept with the picture, so admins can see what got it banned. No login and no known device for it.
            hbHubBannedAvatarAutoban($db, $userId, $bannedAvatarId, true);
        } else {
            hbHubLoginTrustDevice($db, $userId); // made here, so it's one of the account's own devices
            hbHubGhostBanFromDevice($db); // made where a ghost-banned account is known, so it's ghost banned too (loginLimit.php)
            $sessionId = hbHubCreateSession($db, $userId);
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userNameLower and userDiscordId are unique
            // The message names the key, and a Discord account only gets this far when it was linked in the meantime
            return str_contains($e->getMessage(), 'userDiscordId')
                ? ['name' => 'Den Discord-konto er lige blevet koblet til en anden HB Hub-konto. Vælg "Kobl den ikke til" for at oprette dig uden den.']
                : ['name' => 'Det navn er allerede taget. Vælg et andet.'];
        }
        error_log('Onboarding: could not create the account: ' . $e->getMessage());
        return ['password' => 'Noget gik galt, da din konto skulle oprettes. Prøv igen.'];
    }

    if ($bannedAvatarId !== null) {
        hbHubSetBannedCookie($userId);
        header('Location: /login', true, 303);
        exit;
    }

    hbHubSetSessionCookie($sessionId);

    // Into their class chat, already accepted. The account is made either way, and the chat page tries again if this fails.
    try {
        hbHubClassChatSync($db, $userId);
    } catch (PDOException $e) {
        error_log('Onboarding: could not put the account in its class chat: ' . $e->getMessage());
    }

    // Tell them we turned "Help improve HB Hub" off and why. The setting is already saved, so it's only the note if this fails.
    if ($doNotTrack) {
        try {
            hbHubNotify($db, $userId, 'settings.doNotTrack');
        } catch (PDOException $e) {
            error_log('Onboarding: could not send the Do Not Track notification: ' . $e->getMessage());
        }
    }

    return [];
}
