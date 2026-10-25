<?php
// Settings page for the logged in user: a side menu (settings.css, settingsMenu.js marks the card on screen) next to
// one form per card, in the menu's three groups:
// Personal
// - Profile (#profile): picture, name, class and bio, with the same rules as onboarding (userMgmt/onboarding.php).
//   A new class moves them to its class chat (assets/php/schoolClass.php).
//   A picture on the banned list bans the account (assets/php/bannedAvatars.php).
// - Notifications (#notifications): a browser notification and/or a sound for new messages while the site is open
//   (assets/js/notifications.js, assets/js/messageSound.js)
// - Gambling and Games (#block): blocking them, during school hours or all the time (assets/php/block.php)
// Security
// - Password (#password): a new one, after entering the current one. Logs out every other session.
// - Two-factor login (#totp): a code from an authenticator app when logging in (assets/php/totp.php)
// - Logged in devices (#devices): every session (HBHub-Sessions) with its browser and system and when it was last used,
//   a button to log out each of the others, and one to log out everywhere (this device too)
// Account
// - Log out (#account): only this device
// - Delete account (#delete): after entering the password
// The password and 2FA codes typed in to confirm a change count towards the login lock (assets/php/loginLimit.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/account.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/password.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/totp.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/bannedAvatars.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/urlBlacklist.php'; // IP loggers and other links that can't be in a bio
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // the same year and field choices as onboarding, and the class chat
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const SETTINGS_ROLE_ADMIN = 2; // userRole in HBHub-Users
const SETTINGS_AVATAR_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']; // no SVG, it can carry scripts

// Requirement modes used by the onboarding_*_requirements settings
const SETTINGS_REQUIRED = 0;
const SETTINGS_OPTIONAL = 1;
const SETTINGS_DISABLED = 2;

// Limits are capped to what the database columns can hold
$settingsNameMax = max(1, min(35, (int) $siteConfig['user_name_maxSize'])); // names are capped at 35
$settingsDescriptionMax = max(1, min(255, (int) $siteConfig['user_description_maxSize']));
$settingsDescriptionMin = 10; // only when the description is required
$settingsAvatarMaxBytes = max(1, (int) $siteConfig['user_avatar_maxSize']) * 1000; // setting is in KB
$settingsAvatarMaxLabel = rtrim(rtrim(number_format($settingsAvatarMaxBytes / 1000000, 1, '.', ''), '0'), '.') . ' MB';
$settingsPasswordPolicy = hbHubPasswordPolicy($siteConfig);

$settingsAvatarMode = (int) $siteConfig['onboarding_avatar_requirements'];
if ((int) $siteConfig['site_save_diskspace'] === 2) {
    $settingsAvatarMode = SETTINGS_DISABLED; // user uploads are turned off
}
$settingsDescriptionMode = (int) $siteConfig['onboarding_description_requirements'];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$statement = $db->prepare('SELECT userName, userDescription, userSettings, userAvatarAttachmentId, userPasswordHash, userTotpSecret, userTotpLastStep
    FROM `HBHub-Users` WHERE userId = ?');
$statement->execute([$userId]);
$settingsUser = $statement->fetch();
$savedSettings = hbHubUserSettings($settingsUser['userSettings']);

$settingsClass = hbHubClassFormValues($savedSettings);
// A graduate gets "Student" in the year picker, so they can keep it (it can't be picked otherwise)
$settingsIsStudent = $settingsClass['year'] === HBHUB_CLASS_STUDENT;
$settingsValues = [
    'name' => $settingsUser['userName'],
    'year' => $settingsClass['year'],
    'class' => $settingsClass['field'],
    'description' => $settingsUser['userDescription'] ?? '',
];
$settingsErrors = []; // field name => message

$settingsAvatarId = $settingsUser['userAvatarAttachmentId'] !== null ? (int) $settingsUser['userAvatarAttachmentId'] : null;
$settingsCanUploadAvatar = $settingsAvatarMode !== SETTINGS_DISABLED;
$settingsCanRemoveAvatar = $settingsAvatarId !== null && $settingsAvatarMode !== SETTINGS_REQUIRED;

$settingsTotpIsOn = $settingsUser['userTotpSecret'] !== null;
// Setting up 2FA: a new secret from ?totp=setup, or the one sent back when the code was wrong
$settingsTotpSetupSecret = !$settingsTotpIsOn && ($_GET['totp'] ?? null) === 'setup' ? hbHubTotpNewSecret() : null;

/**
 * Saves the changed profile, and the new picture when there is one. Locked while that happens,
 * so settings saved somewhere else at the same time aren't lost.
 * Returns [] when it was saved, otherwise field name => message to show on the form.
 *
 * @param ?array{0: string, 1: string} $avatar [type, file], or null to keep the current picture
 */
function settingsSave(PDO $db, int $userId, array $values, bool $saveDescription, ?array $avatar): array
{
    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT userSettings, userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $current = $statement->fetch();

        // Anything else saved in the settings is kept. The class is only saved again when it was changed.
        $settings = hbHubUserSettings($current['userSettings']);
        $isClassChanged = hbHubClassFormValues($settings) !== ['year' => $values['year'], 'field' => $values['year'] === HBHUB_CLASS_NOT_HTX ? '' : $values['class']];
        if ($isClassChanged) {
            $settings = hbHubClassSetSettings($settings, $values['year'], $values['class']);
        }

        $oldAvatarId = $current['userAvatarAttachmentId'];
        $avatarId = $oldAvatarId;
        if ($avatar !== null) {
            $statement = $db->prepare('INSERT INTO `HBHub-Attachments` (attachmentType, attachmentFile) VALUES (?, ?)');
            $statement->bindValue(1, $avatar[0]);
            $statement->bindValue(2, $avatar[1], PDO::PARAM_LOB);
            $statement->execute();
            $avatarId = (int) $db->lastInsertId();
        }

        $db->prepare('UPDATE `HBHub-Users` SET userName = ?, userNameLower = ?, userSettings = ?, userAvatarAttachmentId = ?'
                . ($saveDescription ? ', userDescription = ?' : '') . ' WHERE userId = ?')
            ->execute([
                $values['name'],
                mb_strtolower($values['name']),
                json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $avatarId,
                ...($saveDescription ? [$values['description'] === '' ? null : $values['description']] : []),
                $userId,
            ]);

        if ($avatar !== null && $oldAvatarId !== null) {
            $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$oldAvatarId]);
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userNameLower is unique
            return ['name' => 'That name is already taken. Please choose another one.'];
        }
        error_log('Settings: could not save the profile: ' . $e->getMessage());
        return ['profile' => 'Something went wrong saving your profile. Please try again.'];
    }

    // A new class is a new class chat, and the old one is left (assets/php/schoolClass.php).
    // The profile is saved either way, and the chat page tries again if this fails.
    if ($isClassChanged) {
        try {
            hbHubClassChatSync($db, $userId);
        } catch (PDOException $e) {
            error_log('Settings: could not move the user to their new class chat: ' . $e->getMessage());
        }
    }

    return [];
}

/**
 * Back to the settings page (at the card that was saved) with a toast, so reloading doesn't send the form again.
 */
function settingsRedirect(string $toast, string $card): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast($toast);
    header('Location: /settings#' . $card, true, 303);
    exit;
}

/**
 * Logged out or the account is gone: the session cookie is removed, and on to the login page with a toast.
 */
function settingsRedirectToLogin(string $toast): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubClearSessionCookie();
    hbHubSetToast($toast);
    header('Location: /login', true, 303);
    exit;
}

/**
 * Checks the password or 2FA code typed in to confirm a change. A wrong one counts like a wrong one at the login,
 * so a session left open on someone else's computer can't be used to guess the password or turn off two-factor login.
 * Returns null when $isRight() says it's right, otherwise the message to show. The wrong one that locks the browser
 * out logs it out too, and it's on to the login page.
 *
 * @param callable(): bool $isRight
 */
function settingsConfirm(PDO $db, int $userId, array $siteConfig, callable $isRight, string $wrongMessage): ?string
{
    [$attemptsMax, $lockoutMinutes] = hbHubLoginLimits($siteConfig);

    // Checked first, so guessing can't carry on while locked
    $lockMinutesLeft = hbHubLoginLockMinutesLeft($db, $userId, $lockoutMinutes);
    if ($lockMinutesLeft > 0) {
        return hbHubLoginLockedMessage($lockMinutesLeft);
    }
    if ($isRight()) {
        hbHubLoginForgetFailures($db, $userId);
        return null;
    }
    if (hbHubLoginRecordFailure($db, $userId, $attemptsMax)) {
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$_COOKIE[HBHUB_SESSION_COOKIE]]);
        settingsRedirectToLogin('lockedOut');
    }
    return $wrongMessage;
}

/**
 * The user's logged in sessions, the one that was used last first. Logins still waiting for their 2FA code aren't
 * logged in yet, so they're left out. The ids are never put on the page (they'd log you in), so each one is pointed at
 * by deviceKey, a hash of its id, and isCurrent is this browser's own session.
 */
function settingsDevices(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT SHA2(sessionId, 256) AS deviceKey, sessionId = ? AS isCurrent,
            sessionDevice, sessionGenerated, sessionLastSeen
        FROM `HBHub-Sessions` WHERE userId = ? AND sessionTotpPending = 0 AND sessionValidUntil > NOW()
        ORDER BY isCurrent DESC, COALESCE(sessionLastSeen, sessionGenerated) DESC');
    $statement->execute([$_COOKIE[HBHUB_SESSION_COOKIE], $userId]);
    return $statement->fetchAll();
}

$settingsAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? 'profile') : null;

if ($settingsAction === 'removeAvatar') {
    if ($settingsCanRemoveAvatar) {
        hbHubRemoveAvatar($db, $userId);
    }
    settingsRedirect('settings', 'profile');
}

if ($settingsAction === 'profile') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Body was larger than post_max_size, so PHP dropped all of it - the picture is the only thing that big
        $settingsErrors['avatar'] = 'That picture is too big. Please choose a smaller one.';
    } else {
        foreach (array_keys($settingsValues) as $key) {
            $value = $_POST[$key] ?? '';
            $settingsValues[$key] = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
        }
        if ($settingsDescriptionMode === SETTINGS_DISABLED) {
            $settingsValues['description'] = $settingsUser['userDescription'] ?? ''; // not on the form, so left as it is
        }

        // Name
        if (($nameError = hbHubNameError($settingsValues['name'], $settingsNameMax, $settingsUser['userName'])) !== null) {
            $settingsErrors['name'] = $nameError;
        }

        // Class - the field of study only for 1. to 3. year (and Student), not for Not HTX
        if (($classError = hbHubClassError($settingsValues['year'], $settingsValues['class'], $settingsIsStudent)) !== null) {
            $settingsErrors['class'] = $classError;
        }

        // Picture - only when a new one was chosen
        $avatarFile = $settingsCanUploadAvatar ? ($_FILES['avatar'] ?? null) : null;
        $avatar = null;
        if (is_array($avatarFile) && is_int($avatarFile['error'] ?? null) && $avatarFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($avatarFile['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                || ($avatarFile['error'] === UPLOAD_ERR_OK && $avatarFile['size'] > $settingsAvatarMaxBytes)) {
                $settingsErrors['avatar'] = "That picture is too big. Please choose one under $settingsAvatarMaxLabel.";
            } elseif ($avatarFile['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($avatarFile['tmp_name'])) {
                $settingsErrors['avatar'] = 'Something went wrong uploading your picture. Please try again.';
            } elseif (!in_array($avatarType = (new finfo(FILEINFO_MIME_TYPE))->file($avatarFile['tmp_name']), SETTINGS_AVATAR_TYPES, true)
                || getimagesize($avatarFile['tmp_name']) === false) {
                $settingsErrors['avatar'] = 'Please upload a PNG, JPEG, GIF or WebP image.';
            } else {
                $avatar = [$avatarType, file_get_contents($avatarFile['tmp_name'])];
            }
        }

        // A picture on the banned list (bannedAvatars.php) bans the account, whatever else is on the form.
        // Site admins can't be banned, so it doesn't happen to them.
        if ($avatar !== null && (int) $hbHubUser['userRole'] !== SETTINGS_ROLE_ADMIN
            && ($bannedAvatarId = hbHubBannedAvatarFind($db, $avatar[1])) !== null) {
            hbHubBannedAvatarBanUploader($db, $userId, $avatar, $bannedAvatarId);
            // What session.php does on a banned account's next visit, straight away here: the browser is marked and
            // this session is gone. Their other browsers get the same on their next visit.
            hbHubSetBannedCookie($userId);
            $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$_COOKIE[HBHUB_SESSION_COOKIE]]);
            hbHubClearSessionCookie();
            header('Location: /login', true, 303);
            exit;
        }

        // Bio - one saved before the allow list (account.php) can be kept as it is, but a blacklisted link
        // (urlBlacklist.php) has to come out, even from one saved before the blacklist
        if ($settingsDescriptionMode !== SETTINGS_DISABLED) {
            $descriptionLength = mb_strlen($settingsValues['description']);
            if ($settingsValues['description'] !== ($settingsUser['userDescription'] ?? '')
                && !hbHubIsLatinText($settingsValues['description'], true)) {
                $settingsErrors['description'] = 'Your bio can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji.';
            } elseif (hbHubUrlBlacklisted($settingsValues['description'])) {
                $settingsErrors['description'] = 'Woah, hold up! Your bio has a dangerous link or a link that compromises people\'s privacy in it.';
            } elseif ($descriptionLength > $settingsDescriptionMax) {
                $settingsErrors['description'] = "Please keep it to $settingsDescriptionMax characters or fewer.";
            } elseif ($settingsDescriptionMode === SETTINGS_REQUIRED && $descriptionLength < $settingsDescriptionMin) {
                $settingsErrors['description'] = "Please write at least $settingsDescriptionMin characters.";
            }
        }

        if (!$settingsErrors) {
            $settingsErrors = settingsSave($db, $userId, $settingsValues, $settingsDescriptionMode !== SETTINGS_DISABLED, $avatar);
            if (!$settingsErrors) {
                settingsRedirect('settings', 'profile');
            }
        }
    }
}

if ($settingsAction === 'password') {
    $currentPassword = is_string($_POST['currentPassword'] ?? null) ? $_POST['currentPassword'] : '';
    $newPassword = is_string($_POST['newPassword'] ?? null) ? $_POST['newPassword'] : '';
    $newPasswordConfirm = is_string($_POST['newPasswordConfirm'] ?? null) ? $_POST['newPasswordConfirm'] : '';

    $currentPasswordError = settingsConfirm($db, $userId, $siteConfig,
        fn (): bool => strlen($currentPassword) <= 72 && password_verify($currentPassword, $settingsUser['userPasswordHash']),
        'That isn\'t your current password.');

    if ($currentPasswordError !== null) {
        $settingsErrors['currentPassword'] = $currentPasswordError;
    } elseif (($passwordError = hbHubPasswordError($newPassword, $newPasswordConfirm, $settingsPasswordPolicy, 'Your new password')) !== null) {
        $settingsErrors['newPassword'] = $passwordError;
    } elseif ($newPassword === $currentPassword) {
        $settingsErrors['newPassword'] = 'That\'s the password you have now. Please choose a new one.';
    } else {
        // Every other session is logged out, in case the old password is why it's being changed
        $db->beginTransaction();
        $db->prepare('UPDATE `HBHub-Users` SET userPasswordHash = ? WHERE userId = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ? AND sessionId <> ?')
            ->execute([$userId, $_COOKIE[HBHUB_SESSION_COOKIE]]);
        $db->commit();
        settingsRedirect('password', 'password');
    }
}

if ($settingsAction === 'totpEnable' && !$settingsTotpIsOn) {
    $secret = $_POST['secret'] ?? null;
    $step = hbHubTotpIsSecret($secret) ? hbHubTotpVerify($secret, $_POST['code'] ?? null) : null;
    if ($step === null) {
        $settingsErrors['totp'] = 'That code isn\'t right. Check that the time on your phone is correct, then try the newest code.';
        $settingsTotpSetupSecret = hbHubTotpIsSecret($secret) ? $secret : hbHubTotpNewSecret(); // same QR code, so it doesn't have to be scanned again
    } else {
        $db->prepare('UPDATE `HBHub-Users` SET userTotpSecret = ?, userTotpLastStep = ? WHERE userId = ? AND userTotpSecret IS NULL')
            ->execute([$secret, $step, $userId]);
        settingsRedirect('totpOn', 'totp');
    }
}

if ($settingsAction === 'totpDisable' && $settingsTotpIsOn) {
    $lastStep = $settingsUser['userTotpLastStep'] !== null ? (int) $settingsUser['userTotpLastStep'] : null;
    $totpError = settingsConfirm($db, $userId, $siteConfig,
        fn (): bool => hbHubTotpVerify($settingsUser['userTotpSecret'], $_POST['code'] ?? null, $lastStep) !== null,
        'That code isn\'t right. Please try the newest code from your app.');
    if ($totpError !== null) {
        $settingsErrors['totp'] = $totpError;
    } else {
        $db->prepare('UPDATE `HBHub-Users` SET userTotpSecret = NULL, userTotpLastStep = NULL WHERE userId = ?')->execute([$userId]);
        settingsRedirect('totpOff', 'totp');
    }
}

if ($settingsAction === 'notifications') {
    $notificationsOn = ($_POST['notifications'] ?? null) === '1';
    $notificationSoundOn = ($_POST['notificationSound'] ?? null) === '1';
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($notificationsOn, $notificationSoundOn): array {
        $settings['notifications'] = $notificationsOn;
        $settings['notificationSound'] = $notificationSoundOn;
        return $settings;
    });
    settingsRedirect('settings', 'notifications');
}

if ($settingsAction === 'block') {
    $blockMode = $_POST['block'] ?? null;
    if (!hbHubBlockIsMode($blockMode)) {
        $settingsErrors['block'] = 'Please choose one of the options.';
    } else {
        $blockIsPending = false;
        hbHubChangeUserSettings($db, $userId, function (array $settings) use ($blockMode, &$blockIsPending): array {
            $settings['block'] = hbHubBlockChange(hbHubBlockSettings($settings), $blockMode);
            $blockIsPending = $settings['block']['pendingMode'] !== null;
            return $settings;
        });
        settingsRedirect($blockIsPending ? 'blockPending' : 'settings', 'block');
    }
}

if ($settingsAction === 'logout') {
    // Only this device - the user's other sessions stay logged in
    $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$_COOKIE[HBHUB_SESSION_COOKIE]]);
    settingsRedirectToLogin('logout');
}

if ($settingsAction === 'logoutDevice') {
    // One of the others, by its deviceKey from settingsDevices(). This device has the Log out button for itself.
    $deviceKey = $_POST['device'] ?? null;
    if (is_string($deviceKey) && strlen($deviceKey) === 64 && ctype_xdigit($deviceKey)) {
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ? AND sessionId <> ? AND SHA2(sessionId, 256) = ?')
            ->execute([$userId, $_COOKIE[HBHUB_SESSION_COOKIE], strtolower($deviceKey)]);
    }
    settingsRedirect('deviceLoggedOut', 'devices');
}

if ($settingsAction === 'logoutEverywhere') {
    // Every session, this one included, and logins waiting for their 2FA code too
    $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
    settingsRedirectToLogin('logoutEverywhere');
}

if ($settingsAction === 'deleteAccount') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $deleteError = settingsConfirm($db, $userId, $siteConfig,
        fn (): bool => strlen($password) <= 72 && password_verify($password, $settingsUser['userPasswordHash']),
        'That isn\'t your password.');
    if ($deleteError !== null) {
        $settingsErrors['deleteAccount'] = $deleteError;
    } else {
        hbHubDeleteAccount($db, $userId);
        settingsRedirectToLogin('accountDeleted');
    }
}

$settingsAvatarSrc = hbHubAvatarUrl($userId, $settingsAvatarId);
$settingsNotificationsOn = ($savedSettings['notifications'] ?? false) === true;
$settingsNotificationSoundOn = ($savedSettings['notificationSound'] ?? false) === true;
$navMessageSound = true; // messageSound.js, so ticking the sound box plays it (navPanel.php)
$settingsBlock = hbHubBlockSettings($savedSettings);
$settingsBlockIsActive = hbHubBlockIsActive($settingsBlock);
$settingsBlockIsAdmin = hbHubBlockIsAdmin($savedSettings); // set in the admin panel, which is the only place to lift it
$settingsDevices = settingsDevices($db, $userId);

/**
 * A time from the database for the list of devices, like 19/10/2026 14:05 (the same as the admin panel).
 */
function settingsTime(?string $timestamp): string
{
    return $timestamp !== null ? date('d/m/Y H:i', strtotime($timestamp)) : '–';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <?php if ($settingsTotpSetupSecret !== null): ?>
        <script src="https://hbhub.noahgajnielsen.dk/assets/js/vendor/qrcode.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/vendor/qrcode.js') ?>" defer></script>
    <?php endif; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/settingsMenu.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settingsMenu.js') ?>" defer></script>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/settings.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settings.js') ?>" defer></script>
    <title>Settings - HB Hub</title>
    <meta name="description" content="Settings page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page">
        <div class="settings-layout">
            <!-- Side menu: links to the cards next to it, settingsMenu.js marks the one on screen -->
            <nav class="settings-menu" aria-label="Settings sections" data-settings-menu>
                <div class="settings-menu-who">
                    <span class="settings-menu-avatar" aria-hidden="true"><?php if ($settingsAvatarSrc !== null): ?><img src="<?= htmlspecialchars($settingsAvatarSrc) ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($settingsUser['userName'], 0, 1))) ?><?php endif; ?></span>
                    <div class="settings-menu-who-text">
                        <span class="settings-menu-who-name"><?= htmlspecialchars($settingsUser['userName']) ?></span>
                        <a href="/profile">View your profile</a>
                    </div>
                </div>
                <div class="settings-menu-links">
                    <div class="settings-menu-group">
                        <p class="settings-menu-heading">Personal</p>
                        <ul class="settings-menu-list">
                            <li><a class="settings-menu-link" href="#profile">Profile</a></li>
                            <li><a class="settings-menu-link" href="#notifications">Notifications</a></li>
                            <li><a class="settings-menu-link" href="#block">Gambling and Games</a></li>
                        </ul>
                    </div>
                    <div class="settings-menu-group">
                        <p class="settings-menu-heading">Security</p>
                        <ul class="settings-menu-list">
                            <li><a class="settings-menu-link" href="#password">Password</a></li>
                            <li><a class="settings-menu-link" href="#totp">Two-factor login</a></li>
                            <li><a class="settings-menu-link" href="#devices">Logged in devices</a></li>
                        </ul>
                    </div>
                    <div class="settings-menu-group">
                        <p class="settings-menu-heading">Account</p>
                        <ul class="settings-menu-list">
                            <li><a class="settings-menu-link" href="#account">Log out</a></li>
                            <li><a class="settings-menu-link is-danger" href="#delete">Delete account</a></li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="settings-content">
                <h1>Settings</h1>

                <!-- Profile -->
                <section class="settings-card" id="profile" aria-labelledby="settings-profile-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-profile-heading">Profile</h2>
                            <p class="settings-intro">How everyone else sees you on HB Hub.</p>
                        </div>
                    </div>
                    <?php if (isset($settingsErrors['profile'])): ?>
                        <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['profile']) ?></p>
                    <?php endif; ?>

                    <form action="/settings#profile" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="profile">

                        <?php if ($settingsCanUploadAvatar || $settingsCanRemoveAvatar): ?>
                        <div class="settings-field">
                            <span class="settings-label">Profile picture</span>
                            <div class="settings-avatar">
                                <?php if ($settingsAvatarSrc !== null): ?>
                                    <img class="settings-avatar-preview" src="<?= htmlspecialchars($settingsAvatarSrc) ?>" alt="Your profile picture">
                                <?php else: ?>
                                    <span class="settings-avatar-preview settings-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($settingsUser['userName'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <div class="settings-avatar-actions">
                                    <?php if ($settingsCanUploadAvatar): ?>
                                        <label class="settings-visually-hidden" for="settings-avatar"><?= $settingsAvatarSrc !== null ? 'Change picture' : 'Upload a picture' ?></label>
                                        <input type="file" id="settings-avatar" name="avatar" accept="<?= implode(',', SETTINGS_AVATAR_TYPES) ?>" aria-describedby="settings-avatar-hint">
                                        <p class="settings-hint" id="settings-avatar-hint">PNG, JPEG, GIF or WebP, up to <?= $settingsAvatarMaxLabel ?>. Saved with the button below.</p>
                                    <?php endif; ?>
                                    <?php if ($settingsCanRemoveAvatar): ?>
                                        <!-- Belongs to a form of its own, so pressing Enter in a field never removes the picture -->
                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small" form="settings-remove-avatar">Remove picture</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (isset($settingsErrors['avatar'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['avatar']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="settings-field">
                            <label class="settings-label" for="settings-name">Name</label>
                            <input type="text" id="settings-name" name="name" maxlength="<?= $settingsNameMax ?>" required autocomplete="username"
                                   value="<?= htmlspecialchars($settingsValues['name']) ?>" aria-describedby="settings-name-hint">
                            <p class="settings-hint" id="settings-name-hint">What everyone else sees you as, and what you log in with. Up to <?= $settingsNameMax ?> characters.</p>
                            <?php if (isset($settingsErrors['name'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="settings-field">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="settings-label" for="settings-year">Year</label>
                                    <select id="settings-year" name="year" required data-class-year>
                                        <option value="" disabled<?= $settingsValues['year'] === '' ? ' selected' : '' ?>>Choose your year</option>
                                        <?php foreach (HBHUB_CLASS_YEARS as $year): ?>
                                            <option value="<?= $year ?>"<?= $settingsValues['year'] === $year ? ' selected' : '' ?>><?= $year ?>.</option>
                                        <?php endforeach; ?>
                                        <?php if ($settingsIsStudent): ?>
                                            <option value="<?= HBHUB_CLASS_STUDENT ?>"<?= $settingsValues['year'] === HBHUB_CLASS_STUDENT ? ' selected' : '' ?>>Student</option>
                                        <?php endif; ?>
                                        <option value="<?= HBHUB_CLASS_NOT_HTX ?>"<?= $settingsValues['year'] === HBHUB_CLASS_NOT_HTX ? ' selected' : '' ?>>Not HTX</option>
                                    </select>
                                </div>
                                <!-- Not for Not HTX: settings.js hides it then. Not required in the markup, so Not HTX can be
                                     saved without JS; the server checks it. -->
                                <div class="settings-field" data-class-field>
                                    <label class="settings-label" for="settings-class">Field of study</label>
                                    <select id="settings-class" name="class">
                                        <option value="" disabled<?= !in_array($settingsValues['class'], HBHUB_CLASS_FIELDS, true) ? ' selected' : '' ?>>Choose your field</option>
                                        <?php foreach (HBHUB_CLASS_FIELDS as $field): ?>
                                            <option value="<?= $field ?>"<?= $settingsValues['class'] === $field ? ' selected' : '' ?>><?= $field ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <p class="settings-hint">Your year goes up by itself every July 1st, and after 3. you're a Student. You're in your class's group chat, and changing class moves you to the other one.</p>
                            <?php if (isset($settingsErrors['class'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['class']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($settingsDescriptionMode !== SETTINGS_DISABLED): ?>
                        <div class="settings-field">
                            <label class="settings-label" for="settings-description">Bio<?php if ($settingsDescriptionMode === SETTINGS_OPTIONAL): ?> <span class="settings-optional">(Optional)</span><?php endif; ?></label>
                            <textarea id="settings-description" name="description" maxlength="<?= $settingsDescriptionMax ?>" rows="4"
                                      <?= $settingsDescriptionMode === SETTINGS_REQUIRED ? 'required minlength="' . $settingsDescriptionMin . '"' : '' ?>
                                      aria-describedby="settings-description-hint"><?= htmlspecialchars($settingsValues['description']) ?></textarea>
                            <p class="settings-hint" id="settings-description-hint">
                                Shown on your profile. Up to <?= $settingsDescriptionMax ?> characters<?= $settingsDescriptionMode === SETTINGS_REQUIRED ? " (at least $settingsDescriptionMin)" : '' ?>.
                            </p>
                            <?php if (isset($settingsErrors['description'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="settings-actions">
                            <a class="settings-btn" href="/profile">View your profile</a>
                            <button type="submit" class="settings-btn settings-btn-primary">Save profile</button>
                        </div>
                    </form>

                    <?php if ($settingsCanRemoveAvatar): ?>
                        <form id="settings-remove-avatar" action="/settings" method="post" hidden>
                            <input type="hidden" name="action" value="removeAvatar">
                        </form>
                    <?php endif; ?>
                </section>

                <!-- Notifications -->
                <section class="settings-card" id="notifications" aria-labelledby="settings-notifications-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-notifications-heading">Notifications</h2>
                            <p class="settings-intro">A notification from your browser, a sound, or both when a new message comes in.</p>
                        </div>
                        <?php $settingsAnyNotificationOn = $settingsNotificationsOn || $settingsNotificationSoundOn; ?>
                        <span class="settings-status<?= $settingsAnyNotificationOn ? ' is-on' : '' ?>"><?= $settingsAnyNotificationOn ? 'On' : 'Off' ?></span>
                    </div>
                    <form action="/settings#notifications" method="post">
                        <input type="hidden" name="action" value="notifications">
                        <div class="settings-field">
                            <label class="settings-checkbox">
                                <input type="checkbox" class="settings-switch" name="notifications" value="1"<?= $settingsNotificationsOn ? ' checked' : '' ?>
                                       aria-describedby="settings-notifications-hint" data-notifications-toggle>
                                <span>Notify me when I get a new message</span>
                            </label>
                            <p class="settings-hint" id="settings-notifications-hint">
                                Works while HB Hub is open in a tab, also in the background. Your browser asks for permission the first time.
                            </p>
                            <!-- Filled in by settings.js when the browser blocks or doesn't support notifications -->
                            <p class="settings-error" data-notifications-status hidden></p>
                            <button type="button" class="settings-btn settings-btn-small" data-notifications-allow hidden>Allow notifications in this browser</button>
                        </div>
                        <div class="settings-field">
                            <label class="settings-checkbox">
                                <input type="checkbox" class="settings-switch" name="notificationSound" value="1"<?= $settingsNotificationSoundOn ? ' checked' : '' ?>
                                       aria-describedby="settings-notification-sound-hint" data-notification-sound-toggle>
                                <span>Play a sound when I get a new message</span>
                            </label>
                            <p class="settings-hint" id="settings-notification-sound-hint">
                                Plays while HB Hub is open in a tab, also in the background, but not for the chat you're looking at
                                or chats you've muted. Your browser only lets a page play sound after you've clicked or typed on it.
                            </p>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Save</button>
                        </div>
                    </form>
                </section>

                <!-- Gambling and Games -->
                <section class="settings-card" id="block" aria-labelledby="settings-block-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-block-heading">Block Gambling and Games</h2>
                            <p class="settings-intro">Keep yourself away from the casino and the games, during school hours or all the time.</p>
                        </div>
                        <?php if ($settingsBlockIsAdmin): ?>
                            <span class="settings-status is-on">Blocked by an admin</span>
                        <?php elseif ($settingsBlock['mode'] !== HBHUB_BLOCK_NONE): ?>
                            <span class="settings-status<?= $settingsBlockIsActive ? ' is-on' : '' ?>"><?= $settingsBlockIsActive ? 'Blocked now' : 'Not blocked now' ?></span>
                        <?php endif; ?>
                    </div>
                    <form action="/settings#block" method="post">
                        <input type="hidden" name="action" value="block">
                        <div class="settings-field">
                            <label class="settings-label" for="settings-block">Block</label>
                            <select id="settings-block" name="block" aria-describedby="settings-block-hint">
                                <?php foreach (HBHUB_BLOCK_MODES as $mode => $label): ?>
                                    <option value="<?= $mode ?>"<?= ($settingsBlock['pendingMode'] ?? $settingsBlock['mode']) === $mode ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="settings-hint" id="settings-block-hint">
                                School hours are 8 AM to 3 PM, Monday to Friday. A stricter block starts straight away.
                                Turning it down waits: during school hours until 3 PM, and from "All the time" for 6 hours.
                            </p>
                            <?php if ($settingsBlockIsAdmin): ?>
                                <p class="settings-notice">
                                    A site admin has blocked Gambling and Games for your account, all the time. Only a site admin can lift it,
                                    so it stays blocked whatever you choose here.
                                </p>
                            <?php endif; ?>
                            <?php if ($settingsBlock['pendingMode'] !== null): ?>
                                <p class="settings-notice">
                                    Changes to "<?= htmlspecialchars(HBHUB_BLOCK_MODES[$settingsBlock['pendingMode']]) ?>"
                                    at <?= htmlspecialchars(hbHubBlockFormatTime($settingsBlock['pendingFrom'])) ?>.
                                    Until then it stays "<?= htmlspecialchars(HBHUB_BLOCK_MODES[$settingsBlock['mode']]) ?>".
                                    To cancel, choose that again and save.
                                </p>
                            <?php endif; ?>
                            <?php if (isset($settingsErrors['block'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['block']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Save</button>
                        </div>
                    </form>
                </section>

                <!-- Password -->
                <section class="settings-card" id="password" aria-labelledby="settings-password-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-password-heading">Password</h2>
                            <p class="settings-intro">Changing your password logs you out on every other device.</p>
                        </div>
                    </div>

                    <form action="/settings#password" method="post">
                        <input type="hidden" name="action" value="password">
                        <!-- For password managers, so they know which account the new password is for -->
                        <input type="text" name="username" value="<?= htmlspecialchars($settingsUser['userName']) ?>" autocomplete="username" hidden>

                        <div class="settings-field">
                            <label class="settings-label" for="settings-current-password">Current password</label>
                            <input type="password" id="settings-current-password" name="currentPassword" maxlength="72" required autocomplete="current-password">
                            <?php if (isset($settingsErrors['currentPassword'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['currentPassword']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="settings-field">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="settings-label" for="settings-new-password">New password</label>
                                    <input type="password" id="settings-new-password" name="newPassword" minlength="<?= $settingsPasswordPolicy['minLength'] ?>" maxlength="72"
                                           required autocomplete="new-password" aria-describedby="settings-new-password-hint"
                                           data-min-length="<?= $settingsPasswordPolicy['minLength'] ?>" data-char-types="<?= $settingsPasswordPolicy['charTypes'] ?>"
                                           data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="settings-label" for="settings-new-password-confirm">Repeat new password</label>
                                    <input type="password" id="settings-new-password-confirm" name="newPasswordConfirm" maxlength="72" required autocomplete="new-password">
                                </div>
                            </div>
                            <!-- Filled in by settings.js while typing, the same meter as onboarding -->
                            <div class="settings-strength" data-level="none" hidden>
                                <div class="settings-strength-track"><div class="settings-strength-bar"></div></div>
                                <p class="settings-strength-label" aria-live="polite"></p>
                            </div>
                            <p class="settings-hint" id="settings-new-password-hint">
                                <?php if ($settingsPasswordPolicy['minLength'] > 1): ?>At least <?= $settingsPasswordPolicy['minLength'] ?> characters.<?php endif; ?>
                                <?php if ($settingsPasswordPolicy['charTypes'] === 4): ?>
                                    Needs lowercase letters, uppercase letters, numbers and special characters.
                                <?php elseif ($settingsPasswordPolicy['charTypes'] > 0): ?>
                                    Needs at least <?= $settingsPasswordPolicy['charTypes'] ?> of: lowercase letters, uppercase letters, numbers and special characters.
                                <?php endif; ?>
                                Common passwords can't be used.
                            </p>
                            <?php if (isset($settingsErrors['newPassword'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['newPassword']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Change password</button>
                        </div>
                    </form>
                </section>

                <!-- Two-factor login -->
                <section class="settings-card" id="totp" aria-labelledby="settings-totp-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-totp-heading">Two-factor login</h2>
                            <p class="settings-intro">
                                <?= $settingsTotpIsOn
                                    ? 'When you log in, you\'re asked for a code from your authenticator app after your password.'
                                    : 'Adds a second step when you log in: a 6 digit code from an authenticator app on your phone. Then your password alone isn\'t enough to get into your account.' ?>
                            </p>
                        </div>
                        <span class="settings-status<?= $settingsTotpIsOn ? ' is-on' : '' ?>"><?= $settingsTotpIsOn ? 'On' : 'Off' ?></span>
                    </div>

                    <?php if ($settingsTotpIsOn): ?>
                        <form action="/settings#totp" method="post">
                            <input type="hidden" name="action" value="totpDisable">
                            <div class="settings-field">
                                <label class="settings-label" for="settings-totp-code">To turn it off, enter a code from your app</label>
                                <input type="text" id="settings-totp-code" class="settings-code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                                       required autocomplete="one-time-code">
                                <?php if (isset($settingsErrors['totp'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['totp']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="settings-btn settings-btn-danger">Turn off two-factor login</button>
                            </div>
                        </form>

                    <?php elseif ($settingsTotpSetupSecret !== null): ?>
                        <?php $settingsTotpUri = hbHubTotpUri($settingsTotpSetupSecret, $settingsUser['userName']); ?>
                        <ol class="settings-steps">
                            <li>
                                Open your authenticator app (like Google Authenticator, Microsoft Authenticator or Authy) and scan this QR code.
                                <img class="settings-qr" data-qr="<?= htmlspecialchars($settingsTotpUri) ?>" alt="QR code for adding HB Hub to your authenticator app" hidden>
                                <span class="settings-hint">
                                    On your phone? <a href="<?= htmlspecialchars($settingsTotpUri) ?>">Open it in your app</a> instead.
                                    Or type in this key: <code class="settings-secret"><?= hbHubTotpFormatSecret($settingsTotpSetupSecret) ?></code>
                                </span>
                            </li>
                            <li>Enter the 6 digit code the app shows for HB Hub.</li>
                        </ol>
                        <form action="/settings#totp" method="post">
                            <input type="hidden" name="action" value="totpEnable">
                            <input type="hidden" name="secret" value="<?= $settingsTotpSetupSecret ?>">
                            <div class="settings-field">
                                <label class="settings-label" for="settings-totp-code">Code from the app</label>
                                <input type="text" id="settings-totp-code" class="settings-code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                                       required autocomplete="one-time-code">
                                <?php if (isset($settingsErrors['totp'])): ?>
                                    <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['totp']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="settings-actions">
                                <a class="settings-btn" href="/settings#totp">Cancel</a>
                                <button type="submit" class="settings-btn settings-btn-primary">Turn on two-factor login</button>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="settings-actions">
                            <a class="settings-btn settings-btn-primary" href="/settings?totp=setup#totp">Set up two-factor login</a>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Logged in devices -->
                <section class="settings-card" id="devices" aria-labelledby="settings-devices-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-devices-heading">Logged in devices</h2>
                            <p class="settings-intro">
                                Everywhere you're logged in right now. If there's one you don't recognise, log it out and change your password.
                            </p>
                        </div>
                    </div>

                    <ul class="settings-devices">
                        <?php foreach ($settingsDevices as $device): ?>
                            <li class="settings-device<?= $device['isCurrent'] ? ' is-current' : '' ?>">
                                <div class="settings-device-text">
                                    <span class="settings-label">
                                        <?= htmlspecialchars($device['sessionDevice'] ?? 'Unknown device') ?>
                                        <?php if ($device['isCurrent']): ?>
                                            <span class="settings-status is-on">This device</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="settings-hint">
                                        Logged in <?= settingsTime($device['sessionGenerated']) ?>
                                        · <?= $device['isCurrent'] ? 'Active now' : 'Last active ' . settingsTime($device['sessionLastSeen'] ?? $device['sessionGenerated']) ?>
                                    </span>
                                </div>
                                <?php if (!$device['isCurrent']): ?>
                                    <form action="/settings#devices" method="post">
                                        <input type="hidden" name="action" value="logoutDevice">
                                        <input type="hidden" name="device" value="<?= htmlspecialchars($device['deviceKey']) ?>">
                                        <button type="submit" class="settings-btn settings-btn-small">Log out</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <form class="settings-item" action="/settings" method="post" data-confirm="Log out on every device, this one too?">
                        <input type="hidden" name="action" value="logoutEverywhere">
                        <div class="settings-item-text">
                            <span class="settings-label">Log out everywhere</span>
                            <p class="settings-hint">Logs you out on every device, this one too. You can log in again with your password.</p>
                        </div>
                        <button type="submit" class="settings-btn settings-btn-danger">Log out everywhere</button>
                    </form>
                </section>

                <!-- Log out -->
                <section class="settings-card" id="account" aria-labelledby="settings-account-heading">
                    <form class="settings-item" action="/settings" method="post">
                        <input type="hidden" name="action" value="logout">
                        <div class="settings-item-text">
                            <h2 class="settings-item-title" id="settings-account-heading">Log out</h2>
                            <p class="settings-hint">Logs you out on this device. You stay logged in on your other devices.</p>
                        </div>
                        <button type="submit" class="settings-btn">Log out</button>
                    </form>
                </section>

                <!-- Delete account -->
                <section class="settings-card is-danger" id="delete" aria-labelledby="settings-delete-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="settings-delete-heading">Delete account</h2>
                            <p class="settings-intro" id="settings-delete-hint">
                                Your name, picture, bio and settings are deleted and you're logged out everywhere.
                                Messages you've sent stay in their chats, from "<?= HBHUB_DELETED_USER_NAME ?>". This can't be undone.
                            </p>
                        </div>
                    </div>
                    <form action="/settings#delete" method="post" data-confirm="Delete your account? This can't be undone.">
                        <input type="hidden" name="action" value="deleteAccount">
                        <!-- For password managers, so they know which account the password is for -->
                        <input type="text" name="username" value="<?= htmlspecialchars($settingsUser['userName']) ?>" autocomplete="username" hidden>
                        <div class="settings-field">
                            <label class="settings-label" for="settings-delete-password">Enter your password to confirm</label>
                            <input type="password" id="settings-delete-password" name="password" maxlength="72" required autocomplete="current-password"
                                   aria-describedby="settings-delete-hint">
                            <?php if (isset($settingsErrors['deleteAccount'])): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['deleteAccount']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid">Delete my account</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
