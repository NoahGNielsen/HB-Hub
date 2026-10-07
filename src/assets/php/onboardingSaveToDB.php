<?php
// Creates the account once the onboarding form is valid, then logs the new user in.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

/**
 * Returns [] when the account was created, otherwise step name => message to show on the form.
 */
function onboardingSaveToDB(array $values, string $password, ?string $avatarBlob, ?string $avatarType): array
{
    [$ipv4, $ipv6] = hbHubClientIp();

    // Same shape as database/defaultUserSettings.json
    $settings = json_encode(['year' => (int) $values['year'], 'class' => $values['class']]);

    $db = null;
    try {
        $db = hbHubDatabase();
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

        $sessionId = hbHubCreateSession($db, $userId);

        $db->commit();
    } catch (PDOException $e) {
        if ($db !== null && $db->inTransaction()) {
            $db->rollBack();
        }
        if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userName and userNameLower are unique
            return ['name' => 'That name is already taken. Please choose another one.'];
        }
        error_log('Onboarding: could not create the account: ' . $e->getMessage());
        return ['password' => 'Something went wrong creating your account. Please try again.'];
    }

    hbHubSetSessionCookie($sessionId);

    return [];
}
