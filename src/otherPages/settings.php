<?php
// Settings page for the logged in user, one form per card:
// - Profile: picture, name, class and bio, with the same rules as onboarding (userMgmt/onboarding.php)
// - Password: a new one, after entering the current one. Logs out every other session.
// - Two-factor login: a code from an authenticator app when logging in (assets/php/totp.php)
// - Notifications: a browser notification for new messages while the site is open (assets/js/notifications.js)
// - Gambling and Games: blocking them, during school hours or all the time (assets/php/block.php)
// - Account: logging out, and deleting the account after entering the password
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/password.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/totp.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const SETTINGS_YEARS = ['1', '2', '3']; // the same choices as onboarding
const SETTINGS_CLASSES = ['T', 'Y', 'X', 'U'];
const SETTINGS_AVATAR_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']; // no SVG, it can carry scripts

// Requirement modes used by the onboarding_*_requirements settings
const SETTINGS_REQUIRED = 0;
const SETTINGS_OPTIONAL = 1;
const SETTINGS_DISABLED = 2;

// Limits are capped to what the database columns can hold
$settingsNameMax = max(1, min(64, (int) $siteConfig['user_name_maxSize']));
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

$settingsValues = [
    'name' => $settingsUser['userName'],
    'year' => is_scalar($savedSettings['year'] ?? null) ? (string) $savedSettings['year'] : '',
    'class' => is_scalar($savedSettings['class'] ?? null) ? (string) $savedSettings['class'] : '',
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

        // Anything else saved in the settings is kept
        $settings = hbHubUserSettings($current['userSettings']);
        $settings['year'] = (int) $values['year'];
        $settings['class'] = $values['class'];

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

    return [];
}

/**
 * Takes away the user's profile picture.
 */
function settingsRemoveAvatar(PDO $db, int $userId): void
{
    $db->beginTransaction();
    $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
    $statement->execute([$userId]);
    $avatarId = $statement->fetchColumn() ?: null;

    $db->prepare('UPDATE `HBHub-Users` SET userAvatarAttachmentId = NULL WHERE userId = ?')->execute([$userId]);
    if ($avatarId !== null) {
        $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$avatarId]);
    }
    $db->commit();
}

/**
 * Deletes the account. Everything in the user's row is cleared except userId, userCreatedTimestamp and
 * userLastSeenTimestamp, and the name becomes "Deleted User", so their messages stay but nothing about them does.
 * Their profile picture and every one of their sessions are deleted too.
 * userNameLower and userPasswordHash end up NULL, so nobody can log in to it or find it in the user search.
 */
function settingsDeleteAccount(PDO $db, int $userId): void
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $avatarId = $statement->fetchColumn() ?: null;

        $db->prepare('UPDATE `HBHub-Users` SET userName = ?, userNameLower = NULL, userDescription = NULL, userSettings = NULL,
                userAvatarAttachmentId = NULL, userIpv4AdresseOnAccountCreate = NULL, userIpv6AdresseOnAccountCreate = NULL,
                userIpv4AdresseLastAccessed = NULL, userIpv6AdresseLastAccessed = NULL, userPasswordHash = NULL,
                userTotpSecret = NULL, userTotpLastStep = NULL, userFailedLoginCount = DEFAULT, userStatus = DEFAULT,
                userRole = DEFAULT, userAccountLockedTimestamp = NULL, userBannedTimestamp = NULL
            WHERE userId = ?')
            ->execute([HBHUB_DELETED_USER_NAME, $userId]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
        if ($avatarId !== null) {
            $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$avatarId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Back to the settings page (at the card that was saved) with a toast, so reloading doesn't send the form again.
 */
function settingsRedirect(string $toast, string $card): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast($toast);
    header('Location: /otherPages/settings#' . $card, true, 303);
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
    header('Location: /userMgmt/login', true, 303);
    exit;
}

$settingsAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? 'profile') : null;

if ($settingsAction === 'removeAvatar') {
    if ($settingsCanRemoveAvatar) {
        settingsRemoveAvatar($db, $userId);
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
        if ($settingsValues['name'] === '') {
            $settingsErrors['name'] = 'Please tell us what you should be known as.';
        } elseif (preg_match('/^[^\p{Cc}]+$/u', $settingsValues['name']) !== 1) {
            $settingsErrors['name'] = 'That name contains characters that can\'t be used.';
        } elseif (mb_strlen($settingsValues['name']) > $settingsNameMax) {
            $settingsErrors['name'] = "Please keep it to $settingsNameMax characters or fewer.";
        } elseif (hbHubIsDeletedUserName($settingsValues['name'])) {
            $settingsErrors['name'] = 'That name can\'t be used. Please choose another one.';
        }

        // Class
        if (!in_array($settingsValues['year'], SETTINGS_YEARS, true)) {
            $settingsErrors['class'] = 'Please choose your year.';
        } elseif (!in_array($settingsValues['class'], SETTINGS_CLASSES, true)) {
            $settingsErrors['class'] = 'Please choose your class.';
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

        // Bio
        if ($settingsDescriptionMode !== SETTINGS_DISABLED) {
            $descriptionLength = mb_strlen($settingsValues['description']);
            if (preg_match('/^[^\p{Cc}]*$/u', str_replace("\n", '', $settingsValues['description'])) !== 1) {
                $settingsErrors['description'] = 'Your bio contains characters that can\'t be used.';
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

    if ($currentPassword === '' || strlen($currentPassword) > 72 || !password_verify($currentPassword, $settingsUser['userPasswordHash'])) {
        $settingsErrors['currentPassword'] = 'That isn\'t your current password.';
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
    if (hbHubTotpVerify($settingsUser['userTotpSecret'], $_POST['code'] ?? null, $lastStep) === null) {
        $settingsErrors['totp'] = 'That code isn\'t right. Please try the newest code from your app.';
    } else {
        $db->prepare('UPDATE `HBHub-Users` SET userTotpSecret = NULL, userTotpLastStep = NULL WHERE userId = ?')->execute([$userId]);
        settingsRedirect('totpOff', 'totp');
    }
}

if ($settingsAction === 'notifications') {
    $notificationsOn = ($_POST['notifications'] ?? null) === '1';
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($notificationsOn): array {
        $settings['notifications'] = $notificationsOn;
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

if ($settingsAction === 'deleteAccount') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if ($password === '' || strlen($password) > 72 || !password_verify($password, $settingsUser['userPasswordHash'])) {
        $settingsErrors['deleteAccount'] = 'That isn\'t your password.';
    } else {
        settingsDeleteAccount($db, $userId);
        settingsRedirectToLogin('accountDeleted');
    }
}

$settingsAvatarSrc = hbHubAvatarUrl($userId, $settingsAvatarId);
$settingsNotificationsOn = ($savedSettings['notifications'] ?? false) === true;
$settingsBlock = hbHubBlockSettings($savedSettings);
$settingsBlockIsActive = hbHubBlockIsActive($settingsBlock);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <?php if ($settingsTotpSetupSecret !== null): ?>
        <script src="https://hbhub.noahgajnielsen.dk/assets/js/vendor/qrcode.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/vendor/qrcode.js') ?>" defer></script>
    <?php endif; ?>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/settings.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settings.js') ?>" defer></script>
    <title>Settings - HB Hub</title>
    <meta name="description" content="Settings page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings">
        <h1>Settings</h1>

        <!-- Profile -->
        <section class="settings-card" id="profile" aria-labelledby="settings-profile-heading">
            <h2 id="settings-profile-heading">Profile</h2>
            <?php if (isset($settingsErrors['profile'])): ?>
                <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['profile']) ?></p>
            <?php endif; ?>

            <form action="/otherPages/settings#profile" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="profile">

                <?php if ($settingsCanUploadAvatar || $settingsCanRemoveAvatar): ?>
                <div class="settings-field">
                    <span class="settings-label">Profile picture</span>
                    <div class="settings-avatar">
                        <?php if ($settingsAvatarSrc !== null): ?>
                            <img class="settings-avatar-preview" src="<?= htmlspecialchars($settingsAvatarSrc) ?>" alt="Your profile picture">
                        <?php else: ?>
                            <img class="settings-avatar-preview is-placeholder" src="/assets/images/icons/user.png" alt="">
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
                            <select id="settings-year" name="year" required>
                                <option value="" disabled<?= !in_array($settingsValues['year'], SETTINGS_YEARS, true) ? ' selected' : '' ?>>Choose your year</option>
                                <?php foreach (SETTINGS_YEARS as $year): ?>
                                    <option value="<?= $year ?>"<?= $settingsValues['year'] === $year ? ' selected' : '' ?>><?= $year ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label" for="settings-class">Class</label>
                            <select id="settings-class" name="class" required>
                                <option value="" disabled<?= !in_array($settingsValues['class'], SETTINGS_CLASSES, true) ? ' selected' : '' ?>>Choose your class</option>
                                <?php foreach (SETTINGS_CLASSES as $class): ?>
                                    <option value="<?= $class ?>"<?= $settingsValues['class'] === $class ? ' selected' : '' ?>><?= $class ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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
                    <a class="settings-btn" href="/otherPages/profile">View your profile</a>
                    <button type="submit" class="settings-btn settings-btn-primary">Save profile</button>
                </div>
            </form>

            <?php if ($settingsCanRemoveAvatar): ?>
                <form id="settings-remove-avatar" action="/otherPages/settings" method="post" hidden>
                    <input type="hidden" name="action" value="removeAvatar">
                </form>
            <?php endif; ?>
        </section>

        <!-- Password -->
        <section class="settings-card" id="password" aria-labelledby="settings-password-heading">
            <h2 id="settings-password-heading">Password</h2>
            <p class="settings-intro">Changing your password logs you out on every other device.</p>

            <form action="/otherPages/settings#password" method="post">
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
                    <label class="settings-label" for="settings-new-password">New password</label>
                    <input type="password" id="settings-new-password" name="newPassword" minlength="<?= $settingsPasswordPolicy['minLength'] ?>" maxlength="72"
                           required autocomplete="new-password" aria-describedby="settings-new-password-hint"
                           data-min-length="<?= $settingsPasswordPolicy['minLength'] ?>" data-char-types="<?= $settingsPasswordPolicy['charTypes'] ?>"
                           data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
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
                </div>

                <div class="settings-field">
                    <label class="settings-label" for="settings-new-password-confirm">Repeat new password</label>
                    <input type="password" id="settings-new-password-confirm" name="newPasswordConfirm" maxlength="72" required autocomplete="new-password">
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
            <h2 id="settings-totp-heading">
                Two-factor login
                <span class="settings-status<?= $settingsTotpIsOn ? ' is-on' : '' ?>"><?= $settingsTotpIsOn ? 'On' : 'Off' ?></span>
            </h2>

            <?php if ($settingsTotpIsOn): ?>
                <p class="settings-intro">When you log in, you're asked for a code from your authenticator app after your password.</p>
                <form action="/otherPages/settings#totp" method="post">
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
                <form action="/otherPages/settings#totp" method="post">
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
                        <a class="settings-btn" href="/otherPages/settings#totp">Cancel</a>
                        <button type="submit" class="settings-btn settings-btn-primary">Turn on two-factor login</button>
                    </div>
                </form>

            <?php else: ?>
                <p class="settings-intro">
                    Adds a second step when you log in: a 6 digit code from an authenticator app on your phone.
                    Then your password alone isn't enough to get into your account.
                </p>
                <div class="settings-actions">
                    <a class="settings-btn settings-btn-primary" href="/otherPages/settings?totp=setup#totp">Set up two-factor login</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- Notifications -->
        <section class="settings-card" id="notifications" aria-labelledby="settings-notifications-heading">
            <h2 id="settings-notifications-heading">Notifications</h2>
            <form action="/otherPages/settings#notifications" method="post">
                <input type="hidden" name="action" value="notifications">
                <label class="settings-checkbox">
                    <input type="checkbox" name="notifications" value="1"<?= $settingsNotificationsOn ? ' checked' : '' ?> data-notifications-toggle>
                    <span>Notify me when I get a new message</span>
                </label>
                <p class="settings-hint">
                    Works while HB Hub is open in a tab, also in the background. Your browser asks for permission the first time.
                </p>
                <!-- Filled in by settings.js when the browser blocks or doesn't support notifications -->
                <p class="settings-error" data-notifications-status hidden></p>
                <button type="button" class="settings-btn settings-btn-small" data-notifications-allow hidden>Allow notifications in this browser</button>
                <div class="settings-actions">
                    <button type="submit" class="settings-btn settings-btn-primary">Save</button>
                </div>
            </form>
        </section>

        <!-- Gambling and Games -->
        <section class="settings-card" id="block" aria-labelledby="settings-block-heading">
            <h2 id="settings-block-heading">
                Block Gambling and Games
                <?php if ($settingsBlock['mode'] !== HBHUB_BLOCK_NONE): ?>
                    <span class="settings-status<?= $settingsBlockIsActive ? ' is-on' : '' ?>"><?= $settingsBlockIsActive ? 'Blocked now' : 'Not blocked now' ?></span>
                <?php endif; ?>
            </h2>
            <form action="/otherPages/settings#block" method="post">
                <input type="hidden" name="action" value="block">
                <div class="settings-field">
                    <label class="settings-label" for="settings-block">Block them</label>
                    <select id="settings-block" name="block" aria-describedby="settings-block-hint">
                        <?php foreach (HBHUB_BLOCK_MODES as $mode => $label): ?>
                            <option value="<?= $mode ?>"<?= ($settingsBlock['pendingMode'] ?? $settingsBlock['mode']) === $mode ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="settings-hint" id="settings-block-hint">
                        School hours are 8 AM to 3 PM, Monday to Friday. A stricter block starts straight away.
                        Turning it down waits: during school hours until 3 PM, and from "All the time" for 6 hours.
                    </p>
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

        <!-- Account -->
        <section class="settings-card" id="account" aria-labelledby="settings-account-heading">
            <h2 id="settings-account-heading">Account</h2>

            <form action="/otherPages/settings" method="post">
                <input type="hidden" name="action" value="logout">
                <div class="settings-field">
                    <span class="settings-label">Log out</span>
                    <p class="settings-hint">Logs you out on this device. You stay logged in on your other devices.</p>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Log out</button>
                </div>
            </form>

            <hr class="settings-divider">

            <form action="/otherPages/settings#account" method="post" data-confirm="Delete your account? This can't be undone.">
                <input type="hidden" name="action" value="deleteAccount">
                <!-- For password managers, so they know which account the password is for -->
                <input type="text" name="username" value="<?= htmlspecialchars($settingsUser['userName']) ?>" autocomplete="username" hidden>
                <div class="settings-field">
                    <label class="settings-label" for="settings-delete-password">Delete account</label>
                    <p class="settings-hint" id="settings-delete-hint">
                        Your name, picture, bio and settings are deleted and you're logged out everywhere.
                        Messages you've sent stay in their chats, from "<?= HBHUB_DELETED_USER_NAME ?>". This can't be undone.
                    </p>
                    <input type="password" id="settings-delete-password" name="password" maxlength="72" required autocomplete="current-password"
                           placeholder="Your password" aria-describedby="settings-delete-hint">
                    <?php if (isset($settingsErrors['deleteAccount'])): ?>
                        <p class="settings-error" role="alert"><?= htmlspecialchars($settingsErrors['deleteAccount']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="settings-btn settings-btn-danger">Delete my account</button>
                </div>
            </form>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
