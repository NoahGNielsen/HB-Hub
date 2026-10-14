<?php
// Creates the account once the onboarding form is valid, then logs the new user in.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/loginLimit.php';

/**
 * Returns [] when the account was created, otherwise step name => message to show on the form.
 * No more than $accountsPerHour accounts are made from the same address in an hour.
 */
function onboardingSaveToDB(array $values, string $password, ?string $avatarBlob, ?string $avatarType, int $accountsPerHour): array
{
    [$ipv4, $ipv6] = hbHubClientIp();

    // Same shape as database/defaultUserSettings.json
    $settings = json_encode(['year' => (int) $values['year'], 'class' => $values['class']]);

    $db = null;
    try {
        $db = hbHubDatabase();

        // Deleted accounts don't count, their addresses are gone
        $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Users` WHERE userCreatedTimestamp > NOW() - INTERVAL 1 HOUR
            AND (userIpv4AdresseOnAccountCreate = ? OR userIpv6AdresseOnAccountCreate = ?)');
        $statement->execute([$ipv4, $ipv6]);
        if ((int) $statement->fetchColumn() >= $accountsPerHour) {
            return ['password' => 'Too many accounts have been made from this network in the last hour. Please try again later.'];
        }

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
                userIpv4AdresseOnAccountCreate, userIpv6AdresseOnAccountCreate, userIpv4AdresseLastAccessed, userIpv6AdresseLastAccessed, userPasswordHash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
            ]);
        $userId = (int) $db->lastInsertId();

        hbHubLoginTrustDevice($db, $userId); // made here, so it's one of the account's own devices
        $sessionId = hbHubCreateSession($db, $userId);

        $db->commit();
    } catch (PDOException $e) {
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userNameLower is unique
            return ['name' => 'That name is already taken. Please choose another one.'];
        }
        error_log('Onboarding: could not create the account: ' . $e->getMessage());
        return ['password' => 'Something went wrong creating your account. Please try again.'];
    }

    hbHubSetSessionCookie($sessionId);

    return [];
}
