<?php
// Settings page for the logged in user, laid out like a phone's settings app: a list of rows in four groups, each row
// showing what it's set to now and opening its own panel. The panel is the one the address points at (/settings#block),
// shown by the CSS with :target (settings.css), so the rows are plain links and it works without JS. On a small screen
// the list and the panel take turns (the panel has a "‹ Settings" link back); on a wide one the list stays on the left
// and Picture and banner shows when no panel is picked. The panels, by their #id:
// Profile
// - Picture and banner (#look), Name, class and bio (#profile), Status (#status): one form over three panels, with the
//   same rules as onboarding (userMgmt/onboarding.php). A new class moves them to its class chat
//   (assets/php/schoolClass.php). A picture on the banned list bans the account (assets/php/bannedAvatars.php).
//   The banner (colors, or a picture that takes tickets), the status and the education badge are assets/php/profile.php.
//   settings.js turns a banner picture into JPEG XL before the form is sent, as only that is taken, and adds a small PNG
//   check copy of it (bannerCheck), so a banner on the banned list (or a similar copy) bans the account too.
// Preferences
// - Notifications (#notifications): a browser notification and/or a sound for new messages while the site is open
//   (assets/js/notifications.js, assets/js/messageSound.js)
// - Gambling and Games (#block): blocking them, during school hours or all the time (assets/php/block.php)
// - Attachments (#attachments): whether the chat's attachment tray has the "Keep original pictures/videos" boxes
//   (chatKeepOriginal, off by default, so pictures and videos are always converted unless it's turned on)
// Security
// - Password (#password): a new one, after entering the current one. Logs out every other session.
// - Two-factor login (#totp): a code from an authenticator app when logging in (assets/php/totp.php)
// - Discord login (#discord): linking a Discord account to log in with (assets/php/discord.php), or unlinking it,
//   and whether its username is shown on the profile (profileShowDiscord). Only there when Discord login is set up.
// - Logged in devices (#devices): every session (HBHub-Sessions) with its browser and system and when it was last used,
//   and a button to log out each of the others
// Account
// - Help improve HB Hub (#improve): two switches, Error reports (errorReports) and Anonymous usage statistics
//   (usageStats), both on by default; hbHubErrorReportsOn() and hbHubUsageStatsOn() in assets/php/userSettings.php say
//   what each turns off. Usage statistics depends on Error reports: it's greyed out and saved off while that's off
// - Log out: only this device. Log out everywhere: every device, this one too. Both straight from their rows.
// - Delete account (#delete): after entering the password
// The password and 2FA codes typed in to confirm a change count towards the login lock (assets/php/loginLimit.php).
//
// Saving: the forms that are plain settings (profile, notifications, block, improve, keepOriginal, discordProfile) have
// data-autosave, and settings.js sends them by themselves as they're changed, with Accept: application/json. Those get
// a JSON answer instead of a redirect: {"ok": true, ...} (with what the page needs to show the new state, see
// settingsRedirect()), 422 {"error": "invalid", "errors": {field: message}} when something on the form isn't right
// (shown in the [data-error-for] boxes), or {"redirect": url} when the browser has to go somewhere else (banned).
// Without JS each of them has a Save button in <noscript>, and gets the redirect and a toast as before.
// Password, two-factor, Discord linking, logging out and deleting are actions, so they keep their buttons.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/loginLimit.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/account.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/password.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/totp.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/discord.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/bannedAvatars.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/urlBlacklist.php'; // IP loggers and other links that can't be in a bio
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // the same year and field choices as onboarding, and the class chat
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/profile.php'; // banner, status and education badge
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

$statement = $db->prepare('SELECT u.userName, u.userDescription, u.userSettings, u.userAvatarAttachmentId, u.userPasswordHash,
        u.userTotpSecret, u.userTotpLastStep, u.userDiscordName, ' . hbHubProfileBannerIdSql('u') . ' AS bannerId
    FROM `HBHub-Users` u WHERE u.userId = ?');
$statement->execute([$userId]);
$settingsUser = $statement->fetch();
$savedSettings = hbHubUserSettings($settingsUser['userSettings']);

$settingsClass = hbHubClassFormValues($savedSettings);
// A graduate gets "Graduate" in the year picker, so they can keep it (it can't be picked otherwise)
$settingsIsGraduate = $settingsClass['year'] === HBHUB_CLASS_GRADUATE;
$settingsStatus = hbHubProfileStatus($savedSettings);
$settingsBannerId = $settingsUser['bannerId'] !== null ? (int) $settingsUser['bannerId'] : null;
// A banner without colors of its own starts the pickers at the site's orange going right into purple, like the chat's
$settingsBannerGradient = hbHubProfileBannerGradient($savedSettings) ?? ['#f2711c', '#6a4bd6', 90];
$settingsBannerGif = hbHubProfileBannerGif($savedSettings); // a Giphy id, or null
$settingsValues = [
    'name' => $settingsUser['userName'],
    'year' => $settingsClass['year'],
    'class' => $settingsClass['field'],
    'description' => $settingsUser['userDescription'] ?? '',
    'statusEmoji' => $settingsStatus['emoji'],
    'statusText' => $settingsStatus['text'],
    'statusClearAfter' => '', // the picker's default (hbHubProfileStatusClearOptions())
    'education' => hbHubProfileEducation($savedSettings)[0] ?? '',
    'bannerType' => $settingsBannerId !== null ? 'picture' : ($settingsBannerGif !== null ? 'gif' : 'colors'), // colors | picture | gif
    'bannerGif' => $settingsBannerGif ?? '', // the GIF banner's Giphy id
    'bannerColor1' => $settingsBannerGradient[0],
    'bannerColor2' => $settingsBannerGradient[1],
    'bannerAngle' => (string) $settingsBannerGradient[2],
];
$settingsErrors = []; // field name => message

$settingsAvatarId = $settingsUser['userAvatarAttachmentId'] !== null ? (int) $settingsUser['userAvatarAttachmentId'] : null;
$settingsCanUploadAvatar = $settingsAvatarMode !== SETTINGS_DISABLED;
$settingsCanRemoveAvatar = $settingsAvatarId !== null && $settingsAvatarMode !== SETTINGS_REQUIRED;
// Banner pictures are attachments, so they're off whenever those are (site_save_diskspace 2)
$settingsCanUploadBanner = (int) $siteConfig['site_save_diskspace'] !== 2;
// GIF banners search Giphy through the chat's /chat/gifs, so they're off whenever the chat's GIFs are (assets/php/giphy.php)
$settingsCanUseGifs = hbHubGiphyApiKey($siteConfig) !== null;
$settingsTicketsFree = hbHubChatAttachmentTicketsFree($db, $userId);

$settingsTotpIsOn = $settingsUser['userTotpSecret'] !== null;
// Setting up 2FA: a new secret from ?totp=setup, or the one sent back when the code was wrong
$settingsTotpSetupSecret = !$settingsTotpIsOn && ($_GET['totp'] ?? null) === 'setup' ? hbHubTotpNewSecret() : null;

// Log in with Discord (assets/php/discord.php): the card is only there when it's set up
$settingsDiscord = hbHubDiscordClient($siteConfig);
$settingsDiscordName = $settingsUser['userDiscordName']; // the linked account's Discord username, NULL when none is
$settingsDiscordOnProfile = hbHubDiscordShowsOnProfile($savedSettings);

/**
 * Saves the changed profile, and the new picture when there is one. Locked while that happens,
 * so settings saved somewhere else at the same time aren't lost.
 * Returns [] when it was saved, otherwise field name => message to show on the form.
 *
 * $profile is what goes in the settings for the banner, status and education badge (assets/php/profile.php).
 *
 * @param ?array{0: string, 1: string} $avatar [type, file], or null to keep the current picture
 * @param array{profileStatus: array, profileEducation: ?string, profileBanner: array} $profile
 */
function settingsSave(PDO $db, int $userId, array $values, bool $saveDescription, ?array $avatar, array $profile): array
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
        $settings = $profile + $settings;

        $oldAvatarId = $current['userAvatarAttachmentId'];
        $avatarId = $oldAvatarId;
        if ($avatar !== null) {
            // The file on disk first, then its row (assets/php/pictureFiles.php). It goes again if this isn't saved after all.
            $avatarId = hbHubPictureAdd($db, $avatar[0], $avatar[1]);
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
            hbHubPictureDelete($db, (int) $oldAvatarId); // its file is deleted once this is committed
        }
        $db->commit();
    } catch (PDOException | RuntimeException $e) { // a RuntimeException is the picture's file that couldn't be written
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) { // duplicate key - userNameLower is unique
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
 * Whether the form was sent by settings.js's autosave, which wants a JSON answer rather than a page.
 */
function settingsWantsJson(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/**
 * The JSON answer to an autosave (see the top of this file), and stops.
 */
function settingsAnswer(array $answer, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Saved: back to the settings page (at the card that was saved) with a toast, so reloading doesn't send the form
 * again. An autosave gets {"ok": true} and $answer instead (what the page needs to show the new state).
 */
function settingsRedirect(string $toast, string $card, array $answer = []): never
{
    if (settingsWantsJson()) {
        settingsAnswer(['ok' => true] + $answer);
    }
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast($toast);
    header('Location: /settings#' . $card, true, 303);
    exit;
}

/**
 * Logged out or the account is gone: the session cookie is removed, and on to the login page with a toast. A deleted
 * account has nothing left to log in to, so that one goes to the frontpage instead ($to = '/').
 */
function settingsRedirectToLogin(string $toast, string $to = '/login'): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubClearSessionCookie();
    hbHubSetToast($toast);
    header('Location: ' . $to, true, 303);
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

        // Class - the field of study only for 1. to 3. year (and Graduate), not for Not HTX
        if (($classError = hbHubClassError($settingsValues['year'], $settingsValues['class'], $settingsIsGraduate)) !== null) {
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

        // Banner picture - only a new one, which settings.js has turned into JPEG XL and sent with a small PNG check
        // copy of (bannedAvatars.php). Read here for the banned list below, saved further down.
        $bannerFile = $settingsCanUploadBanner ? ($_FILES['banner'] ?? null) : null;
        $hasBannerFile = is_array($bannerFile) && is_int($bannerFile['error'] ?? null) && $bannerFile['error'] !== UPLOAD_ERR_NO_FILE;
        $bannerUpload = $hasBannerFile && $settingsValues['bannerType'] === 'picture' && $bannerFile['error'] === UPLOAD_ERR_OK && is_uploaded_file($bannerFile['tmp_name'])
            && $bannerFile['size'] <= HBHUB_PROFILE_BANNER_MAX_BYTES ? file_get_contents($bannerFile['tmp_name']) : null;
        $bannerCheckCopy = hbHubBannedAvatarCheckCopy('bannerCheck');

        // A picture on the banned list (bannedAvatars.php) bans the account, whatever else is on the form, as a profile
        // picture or as a banner. Site admins can't be banned, so it doesn't happen to them.
        $isSettingsAdmin = (int) $hbHubUser['userRole'] === SETTINGS_ROLE_ADMIN;
        $bannedAvatarId = null;
        if ($avatar !== null && !$isSettingsAdmin && ($bannedAvatarId = hbHubBannedAvatarFind($db, $avatar[1])) !== null) {
            hbHubBannedAvatarBanUploader($db, $userId, $avatar, $bannedAvatarId);
        } elseif (is_string($bannerUpload) && !$isSettingsAdmin
            && ($bannedAvatarId = hbHubBannedAvatarFindBanner($db, $bannerUpload, $bannerCheckCopy)) !== null) {
            // Still made their banner when it can be (it can be turned down for tickets or space), so admins can see it
            hbHubProfileSetBanner($db, $userId, $bannerFile);
            $db->beginTransaction();
            hbHubBannedAvatarAutoban($db, $userId, $bannedAvatarId, false, true);
            $db->commit();
        }
        if ($bannedAvatarId !== null) {
            // What session.php does on a banned account's next visit, straight away here: the browser is marked and
            // this session is gone. Their other browsers get the same on their next visit.
            hbHubSetBannedCookie($userId);
            $db->prepare('DELETE FROM `HBHub-Sessions` WHERE sessionId = ?')->execute([$_COOKIE[HBHUB_SESSION_COOKIE]]);
            hbHubClearSessionCookie();
            if (settingsWantsJson()) {
                settingsAnswer(['redirect' => '/login']);
            }
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

        // Status - one emoji and a short text, with the bio's rules, and when it clears (assets/php/profile.php)
        $statusToSave = hbHubProfileStatusFromForm($settingsValues['statusEmoji'], $settingsValues['statusText'],
            $settingsValues['statusClearAfter'], $settingsStatus);
        if (is_string($statusToSave)) {
            $settingsErrors['status'] = $statusToSave;
        }

        // Education badge - one from the list, or none
        if ($settingsValues['education'] !== '' && !isset(HBHUB_PROFILE_EDUCATIONS[$settingsValues['education']])) {
            $settingsErrors['education'] = 'Please choose one of the options.';
        }

        // Banner - the colors are saved either way, so going back from a picture or GIF finds them as they were.
        // A new picture has to have been turned into JPEG XL by settings.js, and have its check copy with it.
        // A GIF is a Giphy id; a new one only while GIFs are on (the one they have stays when they're turned off).
        $bannerGradient = chatBackgroundOf($settingsValues['bannerColor1'], $settingsValues['bannerColor2'], $settingsValues['bannerAngle']);
        if (!in_array($settingsValues['bannerType'], ['colors', 'picture', 'gif'], true) || $bannerGradient === null) {
            $settingsErrors['banner'] = 'Please choose colors and a direction, a picture or a GIF.';
        } elseif ($settingsValues['bannerType'] === 'gif' && !hbHubGiphyIsId($settingsValues['bannerGif'])) {
            $settingsErrors['banner'] = 'Please choose a GIF for your banner.';
        } elseif ($settingsValues['bannerType'] === 'gif' && !$settingsCanUseGifs && $settingsValues['bannerGif'] !== $settingsBannerGif) {
            $settingsErrors['banner'] = 'GIF banners are turned off for now.';
        } elseif ($settingsValues['bannerType'] === 'picture' && !$hasBannerFile && $settingsBannerId === null) {
            $settingsErrors['banner'] = $settingsCanUploadBanner ? 'Please choose a picture for your banner.' : 'Banner pictures are turned off for now.';
        } elseif ($settingsValues['bannerType'] === 'picture' && is_string($bannerUpload) && $bannerCheckCopy === null) {
            $settingsErrors['banner'] = 'Your browser couldn\'t get the picture ready. Please try another picture, or another browser.';
        }

        // The picture is saved first, as it can still fail on tickets. Then nothing else is saved, so it can be fixed.
        if (!$settingsErrors && $settingsValues['bannerType'] === 'picture' && $hasBannerFile) {
            $bannerError = hbHubProfileSetBanner($db, $userId, $bannerFile);
            if ($bannerError !== null) {
                $settingsErrors['banner'] = match ($bannerError) {
                    'notJxl' => 'Your browser couldn\'t get the picture ready. Please try another picture, or another browser.',
                    'tooBig' => 'That picture is too big. Please choose a smaller one.',
                    'notEnoughTickets' => 'You don\'t have enough free tickets for this banner. Delete some of your attachments in the chat (Attach, then Your attachments) to make room.',
                    'disabled' => 'Banner pictures are turned off for now.',
                    'storageFull' => 'HB Hub\'s storage is full right now. Try again once some attachments have expired, or use colors.',
                    'busy' => 'Lots of pictures are being sent right now. Try again in a moment.',
                    default => 'Something went wrong uploading your banner. Please try again.',
                };
            }
        }

        if (!$settingsErrors) {
            $settingsErrors = settingsSave($db, $userId, $settingsValues, $settingsDescriptionMode !== SETTINGS_DISABLED, $avatar, [
                'profileStatus' => $statusToSave,
                'profileEducation' => $settingsValues['education'] !== '' ? $settingsValues['education'] : null,
                'profileBanner' => ['color1' => $bannerGradient[0], 'color2' => $bannerGradient[1], 'gradientAngle' => $bannerGradient[2],
                    'gifId' => $settingsValues['bannerType'] === 'gif' ? $settingsValues['bannerGif'] : null],
            ]);
            if (!$settingsErrors) {
                // Colors or a GIF: the picture goes, and its tickets are free again
                if ($settingsValues['bannerType'] !== 'picture' && $settingsBannerId !== null) {
                    hbHubProfileRemoveBanner($db, $userId);
                }
                // An autosave is told what the picture and banner are now, so the page can show them, and gets the
                // status's "Clear after" picker again, now with "Leave as it is" picked, so the next autosave doesn't
                // start the status's time over
                $profileAnswer = [];
                if (settingsWantsJson()) {
                    $statement = $db->prepare('SELECT u.userAvatarAttachmentId, ' . hbHubProfileBannerIdSql('u') . ' AS bannerId
                        FROM `HBHub-Users` u WHERE u.userId = ?');
                    $statement->execute([$userId]);
                    $saved = $statement->fetch();
                    $profileAnswer = [
                        'name' => $settingsValues['name'],
                        'avatarUrl' => hbHubAvatarUrl($userId, $saved['userAvatarAttachmentId'] !== null ? (int) $saved['userAvatarAttachmentId'] : null),
                        'bannerUrl' => $saved['bannerId'] !== null ? hbHubProfileBannerUrl($userId, (int) $saved['bannerId']) : null,
                        'bannerGif' => $settingsValues['bannerType'] === 'gif' ? $settingsValues['bannerGif'] : null,
                        'ticketsFree' => hbHubChatAttachmentTicketsFree($db, $userId),
                        'statusClearOptions' => hbHubProfileStatusClearOptions($statusToSave),
                    ];
                }
                settingsRedirect('settings', 'profile', $profileAnswer);
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

// On to Discord, and /discord links it once it's allowed there
if ($settingsAction === 'discordLink' && $settingsDiscord !== null) {
    hbHubDiscordStart($settingsDiscord, 'link', HBHUB_RETURN_DEFAULT);
}

if ($settingsAction === 'discordUnlink') {
    $db->prepare('UPDATE `HBHub-Users` SET userDiscordId = NULL, userDiscordName = NULL WHERE userId = ?')->execute([$userId]);
    settingsRedirect('discordUnlinked', 'discord');
}

if ($settingsAction === 'discordProfile') {
    $discordOnProfile = ($_POST['profileShowDiscord'] ?? null) === '1';
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($discordOnProfile): array {
        $settings['profileShowDiscord'] = $discordOnProfile;
        return $settings;
    });
    settingsRedirect('settings', 'discord');
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

if ($settingsAction === 'improve') {
    $errorReportsOn = ($_POST['errorReports'] ?? null) === '1';
    // Anonymous usage statistics depends on Error reports (hbHubUsageStatsOn()), so it's saved off along with it
    $usageStatsOn = $errorReportsOn && ($_POST['usageStats'] ?? null) === '1';
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($errorReportsOn, $usageStatsOn): array {
        $settings['errorReports'] = $errorReportsOn;
        $settings['usageStats'] = $usageStatsOn;
        unset($settings['improveSite']); // the old single switch, only read while these two aren't saved
        return $settings;
    });
    settingsRedirect('settings', 'improve');
}

if ($settingsAction === 'keepOriginal') {
    $keepOriginalOn = ($_POST['chatKeepOriginal'] ?? null) === '1';
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($keepOriginalOn): array {
        $settings['chatKeepOriginal'] = $keepOriginalOn;
        return $settings;
    });
    settingsRedirect('settings', 'attachments');
}

if ($settingsAction === 'block') {
    $blockMode = $_POST['block'] ?? null;
    if (!hbHubBlockIsMode($blockMode)) {
        $settingsErrors['block'] = 'Please choose one of the options.';
    } else {
        $newBlock = null;
        hbHubChangeUserSettings($db, $userId, function (array $settings) use ($blockMode, &$newBlock): array {
            $settings['block'] = $newBlock = hbHubBlockChange(hbHubBlockSettings($settings), $blockMode);
            return $settings;
        });
        settingsRedirect($newBlock['pendingMode'] !== null ? 'blockPending' : 'settings', 'block', ['notice' => settingsBlockPending($newBlock)]);
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
        settingsRedirectToLogin('accountDeleted', '/');
    }
}

// An autosave that wasn't saved: what's wrong, for the boxes next to the fields (anything that was saved has
// redirected, or answered, above)
if ($settingsAction !== null && settingsWantsJson()) {
    settingsAnswer(['error' => 'invalid', 'errors' => $settingsErrors], 422);
}

$settingsAvatarSrc = hbHubAvatarUrl($userId, $settingsAvatarId);
$settingsNotificationsOn = ($savedSettings['notifications'] ?? false) === true;
$settingsNotificationSoundOn = ($savedSettings['notificationSound'] ?? false) === true;
$settingsErrorReportsOn = hbHubErrorReportsOn($savedSettings);
$settingsUsageStatsOn = hbHubUsageStatsOn($savedSettings);
$settingsKeepOriginalOn = hbHubChatKeepOriginalOn($savedSettings);
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

/**
 * What a block change that's still waiting says (hbHubBlockChange() in assets/php/block.php), null when none is.
 */
function settingsBlockPending(array $block): ?string
{
    if ($block['pendingMode'] === null) {
        return null;
    }
    return 'Changes to "' . HBHUB_BLOCK_MODES[$block['pendingMode']] . '" at ' . hbHubBlockFormatTime($block['pendingFrom'])
        . '. Until then it stays "' . HBHUB_BLOCK_MODES[$block['mode']] . '". To cancel, choose that again.';
}

/**
 * The box for a field's error, with the one from the last send when there is one. settings.js fills it in after an
 * autosave too, so it's always there, hidden while empty.
 */
function settingsErrorSlot(array $errors, string $field): string
{
    $message = $errors[$field] ?? null;
    return '<p class="settings-error" role="alert" data-error-for="' . $field . '"' . ($message === null ? ' hidden' : '') . '>'
        . htmlspecialchars($message ?? '') . '</p>';
}

/**
 * The top of a panel: back to the list (only on a small screen, where the list and the panel take turns), its title
 * with Saving… / Saved next to it when it saves by itself ($saves), and a line about it.
 */
function settingsPanelHead(string $id, string $title, ?string $intro = null, bool $saves = false): string
{
    return '<header class="settings-panel-head">'
        . '<a class="settings-back" href="#" data-settings-back>‹ Settings</a>'
        . '<div class="settings-panel-title">'
        . '<h2 id="settings-' . $id . '-heading">' . htmlspecialchars($title) . '</h2>'
        . ($saves ? '<span class="settings-save-state" aria-live="polite" data-save-state></span>' : '')
        . '</div>'
        . ($intro !== null ? '<p class="settings-intro">' . htmlspecialchars($intro) . '</p>' : '')
        . '</header>';
}

/**
 * A tab's icon: a small line drawing in the text color (drawn for HB Hub, like the nav bar's icons rather than emoji),
 * or Discord's logo. $name is one of the keys below.
 */
function settingsIcon(string $name): string
{
    if ($name === 'discord') {
        return hbHubDiscordLogo();
    }
    $paths = [
        'look' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5L5 20"/>',
        'profile' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.8-1.4 1.8-2 3-2s2.2.6 3 2M14.5 10H18M14.5 14H17"/>',
        'status' => '<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 20 12z"/>',
        'notifications' => '<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'block' => '<rect x="4" y="4" width="16" height="16" rx="3"/><circle cx="9" cy="9" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="15" r="1" fill="currentColor"/>',
        'improve' => '<path d="M4 20h16M7 16v-5M12 16V6M17 16v-8"/>',
        'attachments' => '<path d="M20 11.5l-8 8a5 5 0 0 1-7-7l8.5-8.5a3.5 3.5 0 0 1 5 5L10 17.5a2 2 0 0 1-3-3l7.5-7.5"/>',
        'password' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M14.5 8.5l2 2"/>',
        'totp' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'devices' => '<rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M3 19h18"/>',
        'logout' => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M15 16l4-4-4-4M19 12H9"/>',
        'everywhere' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.7 3.5 5.7 3.5 9s-1.1 6.3-3.5 9c-2.4-2.7-3.5-5.7-3.5-9S9.6 5.7 12 3z"/>',
        'delete' => '<path d="M4 7h16M10 3h4M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * One tab in the list: its icon (settingsIcon()), its name, what it's set to now ($value, which settings.js keeps up
 * to date through [data-summary="$summary"]), and an arrow. Goes to its panel (#$id).
 */
function settingsRow(string $id, string $icon, string $title, string $value = '', ?string $summary = null, string $class = ''): string
{
    return '<li><a class="settings-tab' . ($class !== '' ? ' ' . $class : '') . '" href="#' . $id . '" data-settings-row>'
        . '<span class="settings-tab-icon" aria-hidden="true">' . settingsIcon($icon) . '</span>'
        . '<span class="settings-tab-text">'
        . '<span class="settings-tab-title">' . htmlspecialchars($title) . '</span>'
        . '<span class="settings-tab-value"' . ($summary !== null ? ' data-summary="' . $summary . '"' : '') . ($value === '' ? ' hidden' : '') . '>'
        . htmlspecialchars($value) . '</span>'
        . '</span>'
        . '<span class="settings-tab-arrow" aria-hidden="true">›</span>'
        . '</a></li>';
}

// What each row in the list says it's set to now. settings.js works out the same from the forms after an autosave.
$settingsClassSummary = match ($settingsValues['year']) {
    '' => 'No class yet',
    HBHUB_CLASS_NOT_HTX => 'Not HTX',
    HBHUB_CLASS_GRADUATE => 'Graduate',
    default => $settingsValues['year'] . '. year',
} . ($settingsValues['year'] !== HBHUB_CLASS_NOT_HTX && in_array($settingsValues['class'], HBHUB_CLASS_FIELDS, true) ? ' · ' . $settingsValues['class'] : '');
$settingsStatusSummary = trim($settingsValues['statusEmoji'] . ' ' . str_replace("\n", ' ', $settingsValues['statusText']));
$settingsNotificationsSummary = match (true) {
    $settingsNotificationsOn && $settingsNotificationSoundOn => 'Notifications and sound',
    $settingsNotificationsOn => 'Notifications',
    $settingsNotificationSoundOn => 'Sound',
    default => 'Off',
};
$settingsImproveSummary = match (true) {
    $settingsErrorReportsOn && $settingsUsageStatsOn => 'Error reports and statistics',
    $settingsErrorReportsOn => 'Error reports',
    default => 'Off', // usage statistics is never on by itself (hbHubUsageStatsOn())
};
$settingsBlockSummary = $settingsBlockIsAdmin ? 'Blocked by an admin' : HBHUB_BLOCK_MODES[$settingsBlock['pendingMode'] ?? $settingsBlock['mode']];
$settingsDeviceCount = count($settingsDevices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <?php if ($settingsTotpSetupSecret !== null): ?>
        <script src="/assets/js/vendor/qrcode.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/vendor/qrcode.js') ?>" defer></script>
    <?php endif; ?>
    <script src="/assets/js/settings.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/settings.js') ?>" defer></script>
    <script src="/assets/js/statusForm.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/statusForm.js') ?>" defer></script>
    <script src="/assets/js/jxlImages.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/jxlImages.js') ?>" defer></script>
    <title>Settings - HB Hub</title>
    <meta name="description" content="Settings page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <?php
    $settingsInitial = htmlspecialchars(mb_strtoupper(mb_substr($settingsUser['userName'], 0, 1)));
    $settingsAvatarRemovable = $settingsAvatarMode !== SETTINGS_REQUIRED; // the Remove button is there, hidden while there's no picture
    $settingsJxlWorker = '/assets/js/chatJxlWorker.js?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chatJxlWorker.js');
    ?>
    <main class="settings settings-page settings-app" data-settings-app>
        <!-- The list: you at the top, then a row for each panel, in groups. The rows are plain #links, and the CSS shows
             the panel the address points at (:target), so it works without JS too. -->
        <div class="settings-home" data-settings-home>
            <div class="settings-title">
                <h1>Settings</h1>
                <p class="settings-hint">Changes are saved as you make them.</p>
            </div>

            <a class="settings-me" href="/profile">
                <span class="settings-me-avatar" aria-hidden="true">
                    <img src="<?= htmlspecialchars($settingsAvatarSrc ?? '') ?>" alt="" data-avatar-picture<?= $settingsAvatarSrc === null ? ' hidden' : '' ?>>
                    <span data-avatar-initial<?= $settingsAvatarSrc !== null ? ' hidden' : '' ?>><?= $settingsInitial ?></span>
                </span>
                <span class="settings-me-text">
                    <span class="settings-me-name" data-settings-name><?= htmlspecialchars($settingsUser['userName']) ?></span>
                    <span class="settings-me-status" data-summary="status"<?= $settingsStatusSummary === '' ? ' hidden' : '' ?>><?= htmlspecialchars($settingsStatusSummary) ?></span>
                    <span class="settings-me-link">View your profile ›</span>
                </span>
            </a>

            <nav class="settings-lists" aria-label="Settings">
                <h2 class="settings-list-heading">Profile</h2>
                <ul class="settings-list">
                    <?= settingsRow('look', 'look', 'Picture and banner', ['picture' => 'Picture banner', 'gif' => 'GIF banner'][$settingsValues['bannerType']] ?? 'Color banner', 'look') ?>
                    <?= settingsRow('profile', 'profile', 'Name, class and bio', $settingsClassSummary, 'profile') ?>
                    <?= settingsRow('status', 'status', 'Status', $settingsStatusSummary !== '' ? $settingsStatusSummary : 'None', 'statusRow') ?>
                </ul>

                <h2 class="settings-list-heading">Preferences</h2>
                <ul class="settings-list">
                    <?= settingsRow('notifications', 'notifications', 'Notifications', $settingsNotificationsSummary, 'notifications') ?>
                    <?= settingsRow('block', 'block', 'Gambling and Games', $settingsBlockSummary, 'block') ?>
                    <?= settingsRow('attachments', 'attachments', 'Attachments', $settingsKeepOriginalOn ? 'Can keep originals' : 'Always converted', 'attachments') ?>
                </ul>

                <h2 class="settings-list-heading">Security</h2>
                <ul class="settings-list">
                    <?= settingsRow('password', 'password', 'Password', 'Change your password') ?>
                    <?= settingsRow('totp', 'totp', 'Two-factor login', $settingsTotpIsOn ? 'On' : 'Off') ?>
                    <?php if ($settingsDiscord !== null): ?>
                        <?= settingsRow('discord', 'discord', 'Discord login', $settingsDiscordName !== null ? 'Linked to ' . $settingsDiscordName : 'Not linked') ?>
                    <?php endif; ?>
                    <?= settingsRow('devices', 'devices', 'Logged in devices', $settingsDeviceCount === 1 ? 'Only this one' : "$settingsDeviceCount devices") ?>
                </ul>

                <h2 class="settings-list-heading">Account</h2>
                <ul class="settings-list">
                    <?= settingsRow('improve', 'improve', 'Help improve HB Hub', $settingsImproveSummary, 'improve') ?>
                    <li>
                        <form action="/settings" method="post">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="settings-tab">
                                <span class="settings-tab-icon" aria-hidden="true"><?= settingsIcon('logout') ?></span>
                                <span class="settings-tab-text">
                                    <span class="settings-tab-title">Log out</span>
                                    <span class="settings-tab-value">On this device</span>
                                </span>
                            </button>
                        </form>
                    </li>
                    <li>
                        <form action="/settings" method="post" data-confirm="Log out on every device, this one too?">
                            <input type="hidden" name="action" value="logoutEverywhere">
                            <button type="submit" class="settings-tab">
                                <span class="settings-tab-icon" aria-hidden="true"><?= settingsIcon('everywhere') ?></span>
                                <span class="settings-tab-text">
                                    <span class="settings-tab-title">Log out everywhere</span>
                                    <span class="settings-tab-value">On every device, this one too</span>
                                </span>
                            </button>
                        </form>
                    </li>
                    <?= settingsRow('delete', 'delete', 'Delete account', '', null, 'is-danger') ?>
                </ul>
            </nav>
        </div>

        <!-- The panels: one shows at a time, the one the address points at (or holds what it points at, like
             #settings-status-text). On a wide screen the list stays next to it, and Picture and banner shows when the
             address points at none. -->
        <div class="settings-panels">
            <!-- Profile: one form over three panels, sent by itself as it's changed (settings.js). All of it is sent
                 every time, the panels that aren't showing too. -->
            <form action="/settings#profile" method="post" enctype="multipart/form-data" data-autosave data-profile-form>
                <input type="hidden" name="action" value="profile">

                <!-- Picture and banner: a preview of the top of the profile (the banner with the profile picture over
                     it, and the name and status next to it), then a row for each. Banner (assets/php/profile.php): two
                     colors and a direction like a chat's background, a picture, or a GIF from Giphy. "Change banner"
                     opens a popup to pick which (settings.js), which shows the one picked in the preview, and turns a
                     new picture into JPEG XL before it's sent. Without JS the banner can't be changed. -->
                <section class="settings-card settings-panel" id="look" aria-labelledby="settings-look-heading" data-settings-panel data-settings-default>
                    <?= settingsPanelHead('look', 'Picture and banner', 'The top of your profile.', true) ?>
                    <?= settingsErrorSlot($settingsErrors, 'profile') ?>

                    <div class="settings-look" data-banner data-jxl-worker="<?= $settingsJxlWorker ?>"
                         data-banner-max-side="<?= HBHUB_PROFILE_BANNER_MAX_SIDE ?>" data-banner-max-bytes="<?= HBHUB_PROFILE_BANNER_MAX_BYTES ?>"
                         data-ticket-bytes="<?= HBHUB_CHAT_ATTACHMENT_TICKET_BYTES ?>">
                        <div class="settings-look-preview">
                            <div class="settings-banner-preview" aria-hidden="true" data-banner-preview>
                                <?php if ($settingsBannerId !== null): ?>
                                    <img class="settings-banner-picture" src="<?= htmlspecialchars(hbHubProfileBannerUrl($userId, $settingsBannerId)) ?>" alt=""
                                         data-banner-current data-jxl data-jxl-worker="<?= $settingsJxlWorker ?>">
                                <?php endif; ?>
                                <img class="settings-banner-picture" src="<?= $settingsBannerGif !== null ? htmlspecialchars(hbHubProfileBannerGifUrl($settingsBannerGif)) : '' ?>" alt=""
                                     data-banner-gif-picture<?= $settingsValues['bannerType'] !== 'gif' ? ' hidden' : '' ?>>
                            </div>
                            <button type="button" class="settings-banner-edit" data-banner-open hidden>
                                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Change banner
                            </button>
                            <div class="settings-look-who" aria-hidden="true">
                                <div class="settings-look-avatar">
                                    <img class="settings-avatar-preview" src="<?= htmlspecialchars($settingsAvatarSrc ?? '') ?>" alt="" data-avatar-picture<?= $settingsAvatarSrc === null ? ' hidden' : '' ?>>
                                    <span class="settings-avatar-preview settings-avatar-initial" data-avatar-initial<?= $settingsAvatarSrc !== null ? ' hidden' : '' ?>><?= $settingsInitial ?></span>
                                </div>
                                <div class="settings-look-text">
                                    <span class="settings-look-name" data-settings-name><?= htmlspecialchars($settingsUser['userName']) ?></span>
                                    <span class="settings-look-status" data-summary="status"<?= $settingsStatusSummary === '' ? ' hidden' : '' ?>><?= htmlspecialchars($settingsStatusSummary) ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($settingsCanUploadAvatar || $settingsCanRemoveAvatar): ?>
                        <!-- Profile picture: the file input is hidden behind a button (its label); the preview above shows what was picked -->
                        <div class="settings-look-part">
                            <div class="settings-look-part-head">
                                <div class="settings-look-part-text">
                                    <span class="settings-label">Profile picture</span>
                                    <?php if ($settingsCanUploadAvatar): ?>
                                        <p class="settings-hint" id="settings-avatar-hint">PNG, JPEG, GIF or WebP, up to <?= $settingsAvatarMaxLabel ?>.</p>
                                    <?php endif; ?>
                                </div>
                                <div class="settings-look-part-actions">
                                    <?php if ($settingsCanUploadAvatar): ?>
                                        <input type="file" class="settings-visually-hidden settings-file-input" id="settings-avatar" name="avatar" accept="<?= implode(',', SETTINGS_AVATAR_TYPES) ?>"
                                               aria-describedby="settings-avatar-hint" data-avatar-input>
                                        <label class="settings-btn settings-btn-small settings-file-btn" for="settings-avatar">Upload picture</label>
                                    <?php endif; ?>
                                    <?php if ($settingsAvatarRemovable): ?>
                                        <!-- Belongs to a form of its own, so pressing Enter in a field never removes the picture -->
                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small" form="settings-remove-avatar"
                                                data-avatar-remove<?= $settingsAvatarId === null ? ' hidden' : '' ?>>Remove</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?= settingsErrorSlot($settingsErrors, 'avatar') ?>
                        </div>
                        <?php endif; ?>

                        <!-- Banner: what it is now, and "Change banner", which opens the popup below -->
                        <div class="settings-look-part">
                            <div class="settings-look-part-head">
                                <div class="settings-look-part-text">
                                    <span class="settings-label">Banner</span>
                                    <p class="settings-hint" data-banner-kind><?= ['picture' => 'A picture of your own', 'gif' => 'A GIF from GIPHY'][$settingsValues['bannerType']] ?? 'A gradient of two colors' ?></p>
                                </div>
                                <div class="settings-look-part-actions">
                                    <button type="button" class="settings-btn settings-btn-small" data-banner-open hidden>Change banner</button>
                                </div>
                            </div>
                            <noscript><p class="settings-hint">Changing your banner needs JavaScript.</p></noscript>
                            <!-- settings.js: how getting a new picture ready is going -->
                            <p class="settings-hint" aria-live="polite" data-banner-status></p>
                            <?= settingsErrorSlot($settingsErrors, 'banner') ?>

                            <!-- What the banner is (colors | picture | gif), set by the popup -->
                            <?php foreach (['colors', 'picture', 'gif'] as $bannerType): ?>
                                <input type="radio" name="bannerType" value="<?= $bannerType ?>"<?= $settingsValues['bannerType'] === $bannerType ? ' checked' : '' ?> hidden>
                            <?php endforeach; ?>
                            <input type="hidden" name="bannerGif" value="<?= htmlspecialchars($settingsValues['bannerGif']) ?>">
                            <?php if ($settingsCanUploadBanner): ?>
                                <input type="file" class="settings-visually-hidden" id="settings-banner" name="banner" tabindex="-1" aria-hidden="true"
                                       accept="image/png,image/jpeg,image/gif,image/webp,image/avif,image/bmp,image/jxl" data-banner-input>
                                <!-- settings.js puts the banner's check copy for the banned pictures list here (assets/php/bannedAvatars.php) -->
                                <input type="file" name="bannerCheck" hidden tabindex="-1" aria-hidden="true"
                                       data-banner-check="<?= HBHUB_BANNED_AVATAR_CHECK_SIDE ?>">
                            <?php endif; ?>
                        </div>

                        <!-- The Change banner popup: Upload image, Choose GIF or Choose gradient, each its own view
                             ([data-banner-view]). Upload image opens the file picker straight away. Inside the form, so
                             the colors and direction are sent with it. -->
                        <dialog class="settings-banner-dialog" aria-labelledby="settings-banner-dialog-title" data-banner-dialog>
                            <div class="settings-banner-dialog-head">
                                <button type="button" class="settings-banner-dialog-icon" aria-label="Back" data-banner-back hidden>
                                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <h3 id="settings-banner-dialog-title" data-banner-dialog-title>Change banner</h3>
                                <button type="button" class="settings-banner-dialog-icon" aria-label="Close" data-banner-close>
                                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                                </button>
                            </div>

                            <!-- The three choices -->
                            <div class="settings-banner-view" data-banner-view="choose">
                                <div class="settings-banner-options">
                                    <button type="button" class="settings-banner-option" data-banner-choose="upload"<?= $settingsCanUploadBanner ? '' : ' disabled' ?>>
                                        <span class="settings-banner-option-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 16V4M7 9l5-5 5 5M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <span class="settings-banner-option-text">
                                            <span class="settings-banner-option-title">Upload image</span>
                                            <span class="settings-banner-option-hint">
                                                <?php if ($settingsCanUploadBanner): ?>
                                                    Takes a ticket from your chat attachments (<span data-tickets-free><?= $settingsTicketsFree ?></span> free) while it's your banner
                                                <?php else: ?>
                                                    Turned off for now
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </button>
                                    <button type="button" class="settings-banner-option" data-banner-choose="gif"<?= $settingsCanUseGifs ? '' : ' disabled' ?>>
                                        <span class="settings-banner-option-icon" aria-hidden="true"><span class="settings-banner-option-gif">GIF</span></span>
                                        <span class="settings-banner-option-text">
                                            <span class="settings-banner-option-title">Choose GIF</span>
                                            <span class="settings-banner-option-hint"><?= $settingsCanUseGifs ? 'Search GIPHY for one that moves' : 'Turned off for now' ?></span>
                                        </span>
                                    </button>
                                    <button type="button" class="settings-banner-option" data-banner-choose="gradient">
                                        <span class="settings-banner-option-icon settings-banner-option-swirl" aria-hidden="true"></span>
                                        <span class="settings-banner-option-text">
                                            <span class="settings-banner-option-title">Choose gradient</span>
                                            <span class="settings-banner-option-hint">Two colors fading into each other</span>
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- GIPHY search, through the chat's /chat/gifs (trending before anything is typed) -->
                            <div class="settings-banner-view" data-banner-view="gif" hidden>
                                <label class="settings-visually-hidden" for="settings-banner-gif-search">Search GIPHY</label>
                                <input type="search" id="settings-banner-gif-search" placeholder="Search GIPHY" maxlength="50" autocomplete="off" data-banner-gif-search>
                                <div class="settings-banner-gifs" data-banner-gifs></div>
                                <p class="settings-hint settings-banner-gif-status" aria-live="polite" data-banner-gif-status></p>
                                <p class="settings-banner-giphy">Powered by GIPHY</p>
                            </div>

                            <!-- The gradient: a preview, the two colors with their hex codes (kept up to date by
                                 settings.js) and the direction as a compass -->
                            <div class="settings-banner-view" data-banner-view="gradient" hidden>
                                <div class="settings-banner-gradient-preview" aria-hidden="true" data-banner-mini></div>
                                <div class="settings-banner-colors">
                                    <div class="settings-banner-swatches">
                                        <?php foreach (['bannerColor1' => ['settings-banner-color1', 'From'], 'bannerColor2' => ['settings-banner-color2', 'To']] as $colorName => [$colorId, $colorLabel]): ?>
                                            <label class="settings-swatch" for="<?= $colorId ?>">
                                                <input type="color" id="<?= $colorId ?>" name="<?= $colorName ?>" value="<?= htmlspecialchars($settingsValues[$colorName]) ?>">
                                                <span class="settings-swatch-text">
                                                    <span class="settings-swatch-name"><?= $colorLabel ?></span>
                                                    <span class="settings-swatch-value" data-color-value="<?= $colorName ?>"><?= htmlspecialchars(strtoupper($settingsValues[$colorName])) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <fieldset class="settings-directions">
                                        <legend class="settings-visually-hidden">Direction</legend>
                                        <?php foreach (HBHUB_CHAT_BACKGROUND_DIRECTIONS as $angle => [, $label]): ?>
                                            <label class="settings-direction settings-direction-a<?= $angle ?>" title="<?= $label ?>">
                                                <input type="radio" class="settings-visually-hidden" name="bannerAngle" value="<?= $angle ?>"<?= $settingsValues['bannerAngle'] === (string) $angle ? ' checked' : '' ?>>
                                                <span class="settings-direction-arrow" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </span>
                                                <span class="settings-visually-hidden"><?= $label ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                        <span class="settings-direction-dot" aria-hidden="true"></span>
                                    </fieldset>
                                </div>
                                <div class="settings-actions">
                                    <button type="button" class="settings-btn settings-btn-primary" data-banner-use-gradient>Use gradient</button>
                                </div>
                            </div>
                        </dialog>
                    </div>

                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save profile</button></div></noscript>
                </section>

                <!-- Name, class and bio -->
                <section class="settings-card settings-panel" id="profile" aria-labelledby="settings-profile-heading" data-settings-panel>
                    <?= settingsPanelHead('profile', 'Name, class and bio', 'How everyone else sees you on HB Hub.', true) ?>
                    <?= settingsErrorSlot($settingsErrors, 'profile') ?>

                    <div class="settings-group">
                        <div class="settings-field">
                            <label class="settings-label" for="settings-name">Name</label>
                            <!-- Saved once you leave the field, not while typing, as it's what you log in with -->
                            <input type="text" id="settings-name" name="name" minlength="<?= HBHUB_USER_NAME_MIN ?>" maxlength="<?= $settingsNameMax ?>" required autocomplete="username"
                                   value="<?= htmlspecialchars($settingsValues['name']) ?>" aria-describedby="settings-name-hint" data-save-on-change>
                            <p class="settings-hint" id="settings-name-hint">What everyone else sees you as, and what you log in with. <?= HBHUB_USER_NAME_MIN ?> to <?= $settingsNameMax ?> characters.</p>
                            <?= settingsErrorSlot($settingsErrors, 'name') ?>
                        </div>
                    </div>

                    <div class="settings-group">
                        <div class="settings-field">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="settings-label" for="settings-year">Year</label>
                                    <select id="settings-year" name="year" required data-class-year>
                                        <option value="" disabled<?= $settingsValues['year'] === '' ? ' selected' : '' ?>>Choose your year</option>
                                        <?php foreach (HBHUB_CLASS_YEARS as $year): ?>
                                            <option value="<?= $year ?>"<?= $settingsValues['year'] === $year ? ' selected' : '' ?>><?= $year ?>.</option>
                                        <?php endforeach; ?>
                                        <?php if ($settingsIsGraduate): ?>
                                            <option value="<?= HBHUB_CLASS_GRADUATE ?>"<?= $settingsValues['year'] === HBHUB_CLASS_GRADUATE ? ' selected' : '' ?>>Graduate</option>
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
                            <p class="settings-hint">Goes up by itself every July 1st, and after 3. you're a Graduate. Changing class moves you to that class's group chat.</p>
                            <?= settingsErrorSlot($settingsErrors, 'class') ?>
                        </div>

                        <div class="settings-field">
                            <label class="settings-label" for="settings-education">Education <span class="settings-optional">(Optional)</span></label>
                            <select id="settings-education" name="education" aria-describedby="settings-education-hint">
                                <option value="">None</option>
                                <?php foreach (HBHUB_PROFILE_EDUCATIONS as $key => [$emoji, $name, $examples]): ?>
                                    <option value="<?= $key ?>" title="<?= htmlspecialchars($examples) ?>"<?= $settingsValues['education'] === $key ? ' selected' : '' ?>><?= $emoji ?> <?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="settings-hint" id="settings-education-hint">A badge on your profile for the kind of education you're taking.</p>
                            <?= settingsErrorSlot($settingsErrors, 'education') ?>
                        </div>
                    </div>

                    <?php if ($settingsDescriptionMode !== SETTINGS_DISABLED): ?>
                    <div class="settings-group">
                        <div class="settings-field">
                            <label class="settings-label" for="settings-description">Bio<?php if ($settingsDescriptionMode === SETTINGS_OPTIONAL): ?> <span class="settings-optional">(Optional)</span><?php endif; ?></label>
                            <textarea id="settings-description" name="description" maxlength="<?= $settingsDescriptionMax ?>" rows="4"
                                      <?= $settingsDescriptionMode === SETTINGS_REQUIRED ? 'required minlength="' . $settingsDescriptionMin . '"' : '' ?>
                                      aria-describedby="settings-description-hint"><?= htmlspecialchars($settingsValues['description']) ?></textarea>
                            <p class="settings-hint" id="settings-description-hint">
                                Shown on your profile. Up to <?= $settingsDescriptionMax ?> characters<?= $settingsDescriptionMode === SETTINGS_REQUIRED ? " (at least $settingsDescriptionMin)" : '' ?>.
                            </p>
                            <?= settingsErrorSlot($settingsErrors, 'description') ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save profile</button></div></noscript>
                </section>

                <!-- Status (assets/php/profile.php): an emoji and a short text under the name, and when it clears.
                     statusForm.js adds a row of emoji to pick from, and stops the text at two lines. -->
                <section class="settings-card settings-panel" id="status" aria-labelledby="settings-status-heading" data-settings-panel>
                    <?= settingsPanelHead('status', 'Status', 'Shown next to your profile picture.', true) ?>
                    <?= settingsErrorSlot($settingsErrors, 'profile') ?>

                    <div class="settings-field">
                        <label class="settings-label" for="settings-status-text">Status <span class="settings-optional">(Optional)</span></label>
                        <div class="settings-status-row">
                            <label class="settings-visually-hidden" for="settings-status-emoji">Status emoji</label>
                            <input type="text" id="settings-status-emoji" class="settings-status-emoji" name="statusEmoji" maxlength="16"
                                   placeholder="😀" autocomplete="off" value="<?= htmlspecialchars($settingsValues['statusEmoji']) ?>" data-status-emoji>
                            <textarea id="settings-status-text" name="statusText" maxlength="<?= HBHUB_PROFILE_STATUS_TEXT_MAX ?>" rows="2"
                                      placeholder="What are you up to?" aria-describedby="settings-status-hint" data-status-text
                                      data-max-lines="<?= HBHUB_PROFILE_STATUS_LINES_MAX ?>"><?= htmlspecialchars($settingsValues['statusText']) ?></textarea>
                        </div>
                        <div class="settings-status-picks" data-status-picks hidden></div>
                        <div class="settings-status-clear">
                            <label for="settings-status-clear">Clear after</label>
                            <select id="settings-status-clear" name="statusClearAfter" data-status-clear>
                                <?= hbHubProfileStatusClearOptions($settingsStatus, $settingsValues['statusClearAfter']) ?>
                            </select>
                        </div>
                        <p class="settings-hint" id="settings-status-hint">
                            One emoji and up to <?= HBHUB_PROFILE_STATUS_TEXT_MAX ?> characters on up to <?= HBHUB_PROFILE_STATUS_LINES_MAX ?> lines.
                        </p>
                        <?= settingsErrorSlot($settingsErrors, 'status') ?>
                    </div>

                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save profile</button></div></noscript>
                </section>
            </form>

            <?php if ($settingsAvatarRemovable): ?>
                <form id="settings-remove-avatar" action="/settings" method="post" hidden>
                    <input type="hidden" name="action" value="removeAvatar">
                </form>
            <?php endif; ?>

            <!-- Notifications -->
            <section class="settings-card settings-panel" id="notifications" aria-labelledby="settings-notifications-heading" data-settings-panel>
                <?= settingsPanelHead('notifications', 'Notifications', 'For new messages while HB Hub is open.', true) ?>
                <form action="/settings#notifications" method="post" data-autosave>
                    <input type="hidden" name="action" value="notifications">
                    <div class="settings-group">
                        <label class="settings-checkbox settings-line">
                            <span class="settings-item-text">
                                <span class="settings-label">Message notifications</span>
                                <span class="settings-hint" id="settings-notifications-hint">
                                    A notification from your browser for new messages while HB Hub is open in a tab, also in the
                                    background. Your browser asks for permission the first time.
                                </span>
                            </span>
                            <input type="checkbox" class="settings-switch" name="notifications" value="1"<?= $settingsNotificationsOn ? ' checked' : '' ?>
                                   aria-describedby="settings-notifications-hint" data-notifications-toggle>
                        </label>
                        <!-- Filled in by settings.js when the browser blocks or doesn't support notifications -->
                        <p class="settings-error" data-notifications-status hidden></p>
                        <button type="button" class="settings-btn settings-btn-small" data-notifications-allow hidden>Allow notifications in this browser</button>
                    </div>
                    <div class="settings-group">
                        <label class="settings-checkbox settings-line">
                            <span class="settings-item-text">
                                <span class="settings-label">Message sound</span>
                                <span class="settings-hint" id="settings-notification-sound-hint">
                                    A short sound for new messages while HB Hub is open, but not for the chat you're looking at or
                                    chats you've muted.
                                </span>
                            </span>
                            <input type="checkbox" class="settings-switch" name="notificationSound" value="1"<?= $settingsNotificationSoundOn ? ' checked' : '' ?>
                                   aria-describedby="settings-notification-sound-hint" data-notification-sound-toggle>
                        </label>
                    </div>
                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save</button></div></noscript>
                </form>
            </section>

            <!-- Gambling and Games -->
            <section class="settings-card settings-panel" id="block" aria-labelledby="settings-block-heading" data-settings-panel>
                <?= settingsPanelHead('block', 'Gambling and Games', 'Keeps you away from the casino and the games.', true) ?>
                <form action="/settings#block" method="post" data-autosave>
                    <input type="hidden" name="action" value="block">
                    <fieldset class="settings-choices">
                        <legend class="settings-label">Block them</legend>
                        <?php foreach (HBHUB_BLOCK_MODES as $mode => $label): ?>
                            <label class="settings-choice">
                                <input type="radio" name="block" value="<?= $mode ?>"<?= ($settingsBlock['pendingMode'] ?? $settingsBlock['mode']) === $mode ? ' checked' : '' ?>>
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="settings-hint">
                        School hours are 8 AM to 3 PM, Monday to Friday. A stricter block starts straight away. A looser one
                        waits: until 3 PM during school hours, and 6 hours from "All the time".
                    </p>
                    <?php if ($settingsBlockIsAdmin): ?>
                        <p class="settings-notice">
                            <strong>Blocked by an admin.</strong> A site admin has blocked Gambling and Games for your account,
                            all the time. Only a site admin can lift it, so it stays blocked whatever you choose here.
                        </p>
                    <?php endif; ?>
                    <?php $settingsBlockPending = settingsBlockPending($settingsBlock); ?>
                    <p class="settings-notice" data-block-pending<?= $settingsBlockPending === null ? ' hidden' : '' ?>><?= htmlspecialchars($settingsBlockPending ?? '') ?></p>
                    <?= settingsErrorSlot($settingsErrors, 'block') ?>
                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save</button></div></noscript>
                </form>
            </section>

            <!-- Help improve HB Hub (hbHubErrorReportsOn() and hbHubUsageStatsOn() in assets/php/userSettings.php) -->
            <section class="settings-card settings-panel" id="improve" aria-labelledby="settings-improve-heading" data-settings-panel>
                <?= settingsPanelHead('improve', 'Help improve HB Hub', null, true) ?>
                <form action="/settings#improve" method="post" data-autosave>
                    <input type="hidden" name="action" value="improve">
                    <div class="settings-group">
                        <label class="settings-checkbox settings-line">
                            <span class="settings-item-text">
                                <span class="settings-label">Error reports</span>
                                <span class="settings-hint" id="settings-error-reports-hint">
                                    Sends a short report to site admins when something on a page goes wrong, like an error.
                                    This helps us make a site that runs without errors.
                                </span>
                            </span>
                            <input type="checkbox" class="settings-switch" name="errorReports" value="1"<?= $settingsErrorReportsOn ? ' checked' : '' ?>
                                   aria-describedby="settings-error-reports-hint" data-error-reports>
                        </label>
                    </div>
                    <div class="settings-group">
                        <label class="settings-checkbox settings-line">
                            <span class="settings-item-text">
                                <span class="settings-label">Anonymous usage statistics</span>
                                <span class="settings-hint" id="settings-usage-stats-hint">
                                    Share anonymous statistics about how you use the site, you are never identified and stay anonymous. This helps us make a better site!
                                    Needs Error reports to be on.
                                </span>
                            </span>
                            <?php // Greyed out while Error reports is off (settings.js keeps it so as that switch changes) ?>
                            <input type="checkbox" class="settings-switch" name="usageStats" value="1"<?= $settingsUsageStatsOn ? ' checked' : '' ?><?= $settingsErrorReportsOn ? '' : ' disabled' ?>
                                   aria-describedby="settings-usage-stats-hint" data-usage-stats>
                        </label>
                    </div>
                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save</button></div></noscript>
                </form>
            </section>

            <!-- Attachments (chatKeepOriginal, hbHubChatKeepOriginalOn() in assets/php/userSettings.php) -->
            <section class="settings-card settings-panel" id="attachments" aria-labelledby="settings-attachments-heading" data-settings-panel>
                <?= settingsPanelHead('attachments', 'Attachments', 'For files you send in the chat.', true) ?>
                <form action="/settings#attachments" method="post" data-autosave>
                    <input type="hidden" name="action" value="keepOriginal">
                    <label class="settings-checkbox settings-line">
                        <span class="settings-item-text">
                            <span class="settings-label">Option to keep originals</span>
                            <span class="settings-hint" id="settings-keep-original-hint">
                                Pictures are turned into JPEG XL and videos into WebM before they're sent, so they take up
                                less. Turn this on to get the "Keep original pictures" and "Keep original videos" boxes
                                under the attachments you're about to send, to send them exactly as they are instead.
                            </span>
                        </span>
                        <input type="checkbox" class="settings-switch" name="chatKeepOriginal" value="1"<?= $settingsKeepOriginalOn ? ' checked' : '' ?>
                               aria-describedby="settings-keep-original-hint">
                    </label>
                    <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save</button></div></noscript>
                </form>
            </section>

            <!-- Password -->
            <section class="settings-card settings-panel" id="password" aria-labelledby="settings-password-heading" data-settings-panel>
                <?= settingsPanelHead('password', 'Password', 'Changing it logs you out on every other device.') ?>
                <form action="/settings#password" method="post">
                    <input type="hidden" name="action" value="password">
                    <!-- For password managers, so they know which account the new password is for -->
                    <input type="text" name="username" value="<?= htmlspecialchars($settingsUser['userName']) ?>" autocomplete="username" hidden>

                    <div class="settings-field">
                        <label class="settings-label" for="settings-current-password">Current password</label>
                        <input type="password" id="settings-current-password" name="currentPassword" maxlength="72" required autocomplete="current-password">
                        <?= settingsErrorSlot($settingsErrors, 'currentPassword') ?>
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
                        <!-- The rules as chips that settings.js ticks off while typing (the server checks them too) -->
                        <div class="settings-password-rules" id="settings-new-password-hint">
                            <?php if ($settingsPasswordPolicy['minLength'] > 1): ?>
                            <ul class="settings-checklist">
                                <li data-check="length">At least <?= $settingsPasswordPolicy['minLength'] ?> characters</li>
                            </ul>
                            <?php endif; ?>
                            <?php if ($settingsPasswordPolicy['charTypes'] > 0): ?>
                            <p class="settings-hint"><?= $settingsPasswordPolicy['charTypes'] === 4 ? 'All of these:' : 'At least ' . $settingsPasswordPolicy['charTypes'] . ' of these:' ?></p>
                            <ul class="settings-checklist">
                                <li data-check="lower">Lowercase (a-z)</li>
                                <li data-check="upper">Uppercase (A-Z)</li>
                                <li data-check="number">Number (0-9)</li>
                                <li data-check="special">Special character (!, ?, # ...)</li>
                            </ul>
                            <?php endif; ?>
                            <ul class="settings-checklist">
                                <li data-check="common">Not a common password</li>
                            </ul>
                        </div>
                        <?= settingsErrorSlot($settingsErrors, 'newPassword') ?>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="settings-btn settings-btn-primary">Change password</button>
                    </div>
                </form>
            </section>

            <!-- Two-factor login -->
            <section class="settings-card settings-panel" id="totp" aria-labelledby="settings-totp-heading" data-settings-panel>
                <?= settingsPanelHead('totp', 'Two-factor login') ?>
                <?php if ($settingsTotpIsOn): ?>
                    <p class="settings-line">
                        <span class="settings-hint">When you log in, you're asked for a code from your authenticator app after your password.</span>
                        <span class="settings-status is-on">On</span>
                    </p>
                    <form action="/settings#totp" method="post">
                        <input type="hidden" name="action" value="totpDisable">
                        <div class="settings-field">
                            <label class="settings-label" for="settings-totp-code">To turn it off, enter a code from your app</label>
                            <input type="text" id="settings-totp-code" class="settings-code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                                   required autocomplete="one-time-code">
                            <?= settingsErrorSlot($settingsErrors, 'totp') ?>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-danger">Turn off two-factor login</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="settings-line">
                        <span class="settings-hint">A 6 digit code from an authenticator app on your phone when you log in, so your password alone isn't enough.</span>
                        <span class="settings-status">Off</span>
                    </p>
                    <?php if ($settingsTotpSetupSecret === null): ?>
                        <div class="settings-actions">
                            <a class="settings-btn settings-btn-primary" href="/settings?totp=setup#totp">Set up</a>
                        </div>
                    <?php else: ?>
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
                                <?= settingsErrorSlot($settingsErrors, 'totp') ?>
                            </div>
                            <div class="settings-actions">
                                <a class="settings-btn" href="/settings#totp">Cancel</a>
                                <button type="submit" class="settings-btn settings-btn-primary">Turn on two-factor login</button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($settingsDiscord !== null): ?>
            <!-- Discord login -->
            <section class="settings-card settings-panel" id="discord" aria-labelledby="settings-discord-heading" data-settings-panel>
                <?= settingsPanelHead('discord', 'Discord login', null, $settingsDiscordName !== null) ?>
                <?php if ($settingsDiscordName !== null): ?>
                    <form class="settings-group settings-line" action="/settings#discord" method="post" data-confirm="Unlink your Discord account? You can only log in with your password after this.">
                        <input type="hidden" name="action" value="discordUnlink">
                        <span class="settings-item-text">
                            <span class="settings-label">Linked to <?= htmlspecialchars($settingsDiscordName) ?></span>
                            <span class="settings-hint">
                                You can log in with Discord instead of your password.<?= $settingsTotpIsOn ? ' You\'re still asked for your two-factor code.' : '' ?>
                            </span>
                        </span>
                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Unlink</button>
                    </form>
                    <form class="settings-group" action="/settings#discord" method="post" data-autosave>
                        <input type="hidden" name="action" value="discordProfile">
                        <label class="settings-checkbox settings-line">
                            <span class="settings-item-text">
                                <span class="settings-label">Show my Discord username on my profile</span>
                                <span class="settings-hint" id="settings-discord-profile-hint">
                                    Everyone logged in to HB Hub can see it there. It's checked with Discord every night, so a new Discord username shows up by itself.
                                </span>
                            </span>
                            <input type="checkbox" class="settings-switch" name="profileShowDiscord" value="1"<?= $settingsDiscordOnProfile ? ' checked' : '' ?>
                                   aria-describedby="settings-discord-profile-hint">
                        </label>
                        <noscript><div class="settings-actions"><button type="submit" class="settings-btn settings-btn-primary">Save</button></div></noscript>
                    </form>
                <?php else: ?>
                    <form action="/settings#discord" method="post">
                        <input type="hidden" name="action" value="discordLink">
                        <p class="settings-hint">
                            Log in with Discord instead of typing your password (which keeps working too). Discord asks you to
                            let HB Hub see your Discord username, and nothing else is shared. It's shown on your profile,
                            which you can turn off here once it's linked.
                        </p>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-discord"><?= hbHubDiscordLogo() ?>Link Discord</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- Logged in devices -->
            <section class="settings-card settings-panel" id="devices" aria-labelledby="settings-devices-heading" data-settings-panel>
                <?= settingsPanelHead('devices', 'Logged in devices', 'Everywhere you\'re logged in right now. If there\'s one you don\'t recognise, log it out and change your password.') ?>
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
            </section>

            <!-- Delete account -->
            <section class="settings-card settings-panel is-danger" id="delete" aria-labelledby="settings-delete-heading" data-settings-panel>
                <?= settingsPanelHead('delete', 'Delete account') ?>
                <form action="/settings#delete" method="post" data-confirm="Delete your account? This can't be undone.">
                    <input type="hidden" name="action" value="deleteAccount">
                    <!-- For password managers, so they know which account the password is for -->
                    <input type="text" name="username" value="<?= htmlspecialchars($settingsUser['userName']) ?>" autocomplete="username" hidden>
                    <p class="settings-hint" id="settings-delete-hint">
                        Your name, picture, bio and settings are deleted and you're logged out everywhere. Messages
                        you've sent stay in their chats, from "<?= HBHUB_DELETED_USER_NAME ?>". This can't be undone.
                    </p>
                    <div class="settings-field">
                        <label class="settings-label" for="settings-delete-password">Enter your password to confirm</label>
                        <input type="password" id="settings-delete-password" name="password" maxlength="72" required autocomplete="current-password"
                               aria-describedby="settings-delete-hint">
                        <?= settingsErrorSlot($settingsErrors, 'deleteAccount') ?>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-solid">Delete my account</button>
                    </div>
                </form>
            </section>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
