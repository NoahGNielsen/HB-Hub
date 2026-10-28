<?php
// Changes to a user's account that the settings page (the user themself) and the admin panel (admin/user.php) both make,
// and the rules for names and bios that sign-up (userMgmt/onboarding.php) uses as well.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/chatAttachments.php'; // a deleted account's attachments go with it

// What names and bios may be made of. It's an allow list, so anything not on it - Arabic, Chinese, Cyrillic and every
// other alphabet, invisible characters, combining marks stacked on letters - can't be used.
// The Latin alphabet with its accented and extra letters (æ, ø, å, é, ü, ß, ...), numbers, spaces and punctuation.
// Skips the non-breaking space (U+00A0) and soft hyphen (U+00AD), which are invisible.
const HBHUB_LATIN_TEXT_LETTERS = '\x{20}-\x{7E}\x{A1}-\x{AC}\x{AE}-\x{24F}\x{1E00}-\x{1EFF}'
    . '\x{2010}-\x{2015}\x{2018}-\x{2022}\x{2026}\x{2030}\x{2032}\x{2033}\x{2039}\x{203A}\x{20AC}\x{2122}'; // – — ‘ ’ “ ” • … € ™
// Emoji, arrows and symbols. The invisible joiners emoji are built from (U+FE0F, U+20E3, U+200D) are only allowed
// right after/between these, so they can't be used to make a name that looks like someone else's.
const HBHUB_LATIN_TEXT_EMOJI = '\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F000}-\x{1FAFF}';

const HBHUB_USER_NAME_MIN = 3; // characters a new userName needs at least (older, shorter ones may stay)

/**
 * Whether the text only uses characters on the allow list above. Used for new names and bios (not for ones saved
 * before the list was added, so nobody is forced to change theirs). Newlines are only allowed when $allowNewlines.
 */
function hbHubIsLatinText(string $text, bool $allowNewlines = false): bool
{
    $letters = HBHUB_LATIN_TEXT_LETTERS . ($allowNewlines ? '\n' : '');
    $emoji = HBHUB_LATIN_TEXT_EMOJI;
    $pattern = '/^(?:[' . $letters . $emoji . ']'
        . '|(?<=[' . $emoji . '\x{A9}\x{AE}\x{2122}])\x{FE0F}' // shown as an emoji, also ©️ ®️ ™️
        . '|(?<=[0-9#*])\x{FE0F}(?=\x{20E3})' // the start of a keycap, like 1️⃣
        . '|(?<=[0-9#*]|\x{FE0F})\x{20E3}' // the end of a keycap
        . '|(?<=[' . $emoji . ']|\x{FE0F})\x{200D}(?=[' . $emoji . '])' // joins emoji, like 👨‍👩‍👧
        . ')*\z/u'; // \z, as $ would also let a newline through at the end
    return preg_match($pattern, $text) === 1; // false on invalid UTF-8 too
}

/**
 * Whether the text looks like an email address (something@something.something). Some people typed their email as
 * their name when signing up, which then shows to everyone, so names can't be one. onboarding.js has the same check.
 */
function hbHubLooksLikeEmail(string $text): bool
{
    return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $text) === 1;
}

/**
 * Why the name can't be used, or null when it's fine. Taken names are found when saving (userNameLower is unique).
 * $currentName is the name the account has now: keeping it is fine even if it's from before the allow list (or an
 * email address or a name shorter than HBHUB_USER_NAME_MIN from before those rules).
 */
function hbHubNameError(string $name, int $nameMax, ?string $currentName = null): ?string
{
    if ($name === '') {
        return 'Please choose a username.';
    } elseif ($name !== $currentName && hbHubLooksLikeEmail($name)) {
        return 'That looks like an email address. Your username is shown to everyone, so pick a name or nickname instead - you don\'t need an email here.';
    } elseif ($name !== $currentName && !hbHubIsLatinText($name)) {
        return 'Names can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji.';
    } elseif ($name !== $currentName && mb_strlen($name) < HBHUB_USER_NAME_MIN) {
        return 'Please use at least ' . HBHUB_USER_NAME_MIN . ' characters.';
    } elseif (mb_strlen($name) > $nameMax) {
        return "Please keep it to $nameMax characters or fewer.";
    } elseif (hbHubIsReservedUserName($name)) {
        return 'That name can\'t be used. Please choose another one.';
    }
    return null;
}

/**
 * Takes away the user's profile picture.
 */
function hbHubRemoveAvatar(PDO $db, int $userId): void
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
 * Their profile picture, every one of their sessions, their known devices (loginLimit.php) and their Ore Mining Inc.
 * save (nobody else plays in it) are deleted too, and so are the files of their chat attachments (the messages they
 * were in stay, showing them as deleted) and their profile banner.
 * userNameLower and userPasswordHash end up NULL, so nobody can log in to it or find it in the user search.
 * userStatus, userRole and userFailedLoginCount end up NULL too, so a NULL userStatus means a deleted account.
 */
function hbHubDeleteAccount(PDO $db, int $userId): void
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT userAvatarAttachmentId FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $avatarId = $statement->fetchColumn() ?: null;

        $db->prepare('UPDATE `HBHub-Users` SET userName = ?, userNameLower = NULL, userDescription = NULL, userSettings = NULL,
                userAvatarAttachmentId = NULL, userBannerAttachmentId = NULL,
                userIpv4AdresseOnAccountCreate = NULL, userIpv6AdresseOnAccountCreate = NULL,
                userIpv4AdresseLastAccessed = NULL, userIpv6AdresseLastAccessed = NULL, userPasswordHash = NULL,
                userTotpSecret = NULL, userTotpLastStep = NULL, userDiscordId = NULL, userDiscordName = NULL,
                userFailedLoginCount = NULL, userStatus = NULL,
                userRole = NULL, userAccountLockedTimestamp = NULL, userBannedTimestamp = NULL, userBannedReason = NULL,
                userGhostBannedTimestamp = NULL, userAttachmentTicketsExtra = 0
            WHERE userId = ?')
            ->execute([HBHUB_DELETED_USER_NAME, $userId]);
        $db->prepare('DELETE FROM `HBHub-Sessions` WHERE userId = ?')->execute([$userId]);
        $db->prepare('DELETE FROM `HBHub-LoginDevices` WHERE userId = ?')->execute([$userId]);
        $db->prepare('DELETE FROM `HBHub-MinerSaves` WHERE userId = ?')->execute([$userId]);
        $db->prepare('DELETE FROM `HBHub-MinerCrashReports` WHERE crashReportUserId = ?')->execute([$userId]);
        if ($avatarId !== null) {
            $db->prepare('DELETE FROM `HBHub-Attachments` WHERE attachmentId = ?')->execute([$avatarId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    hbHubChatAttachmentsRemove($db, 'a.userId = ?', [$userId]);
}
