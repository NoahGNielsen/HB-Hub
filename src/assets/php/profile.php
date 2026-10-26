<?php
// What a profile shows besides the name, picture and bio: the banner along the top, the status under the name and the
// education badge. Set in settings (otherPages/settings.php), shown on profiles (otherPages/profile.php).
//
// Saved in userSettings (database/defaultUserSettings.json):
// - profileStatus: { emoji, text, expires } - one emoji and up to HBHUB_PROFILE_STATUS_TEXT_MAX characters on up to
//   HBHUB_PROFILE_STATUS_LINES_MAX lines, with the same rules as the bio (account.php, urlBlacklist.php). '' when not set.
//   expires is the Unix time it clears itself at (picked from HBHUB_PROFILE_STATUS_CLEAR_AFTER), or null when it doesn't.
//   It isn't deleted right then: past it, it's just not shown or put in the forms any more, so the next save of the
//   status (or the profile in settings) writes over it. Statuses from before this have no expires and don't clear.
//   It's set in settings, or in the popup on your own profile (otherPages/profile.php, hbHubProfileSaveStatus()).
// - profileEducation: which kind of education they're taking, a key of HBHUB_PROFILE_EDUCATIONS, or null
// - profileBanner: { color1, color2, gradientAngle } - the banner's gradient, made like a chat's background (Change
//   background in the chat, chatBackgroundOf() in chat.php). "default" colors for the site's own one.
//
// The banner can be a picture instead: userBannerAttachmentId in HBHub-Users. It's kept like a chat attachment
// (chatAttachments.php), on disk and paid for with tickets, but without a chat or a message, and it never expires
// (HBHUB_PROFILE_BANNER_NEVER), so it holds on to its tickets until it's changed or removed. The browser makes it smaller
// and turns it into JPEG XL before it's sent (settings.js, chatJxlWorker.js), and only JPEG XL is taken.
// otherPages/banner.php sends it. Deleting it from the Your attachments popup or the admin panel takes it off the
// profile too, as only a kept one is shown.
require_once __DIR__ . '/chat.php'; // chatBackgroundOf() and the gradient directions, and chatAttachments.php
require_once __DIR__ . '/account.php'; // the allow list the bio uses
require_once __DIR__ . '/urlBlacklist.php'; // and its blacklisted links
require_once __DIR__ . '/userSettings.php'; // hbHubUserSettings(), for saving the status

const HBHUB_PROFILE_STATUS_TEXT_MAX = 45;
const HBHUB_PROFILE_STATUS_LINES_MAX = 2;
// The "Clear after" choices for a status: form value => [seconds, label]. null seconds is "Don't clear".
const HBHUB_PROFILE_STATUS_CLEAR_AFTER = [
    '30m' => [30 * 60, '30 minutes'],
    '1h' => [3600, '1 hour'],
    '8h' => [8 * 3600, '8 hours'],
    '24h' => [24 * 3600, '24 hours'],
    '7d' => [7 * 86400, '7 days'],
    'never' => [null, 'Don\'t clear'],
];
const HBHUB_PROFILE_STATUS_CLEAR_DEFAULT = '24h';
// The extra choice when there's a status already, so editing it doesn't move when it clears
const HBHUB_PROFILE_STATUS_CLEAR_KEEP = 'keep';
// The banner after the browser has made it smaller: two tickets' worth, which a JPEG XL of that size never gets near
const HBHUB_PROFILE_BANNER_MAX_BYTES = 2 * HBHUB_CHAT_ATTACHMENT_TICKET_BYTES;
// The biggest a banner picture is made by the browser (settings.js), both ways, so a phone photo isn't kept at full size
const HBHUB_PROFILE_BANNER_MAX_SIDE = 1600;
const HBHUB_PROFILE_BANNER_NEVER = '9999-12-31 23:59:59'; // attachmentExpiresTimestamp of a banner
const HBHUB_PROFILE_BANNER_NAME = 'banner.jxl'; // attachmentName of a banner

// The education badge: profileEducation => [emoji, name, what's in it]. Kept to the kinds of education there are at
// Hansenberg (hansenberg.dk/uddannelser), not every single one, so the list stays short.
const HBHUB_PROFILE_EDUCATIONS = [
    'science' => ['🔬', 'Science and technology', 'HTX, EUX'],
    'it' => ['💻', 'IT and media', 'Datatekniker, IT-supporter, webudvikler, mediegrafiker'],
    'building' => ['🔨', 'Building and crafts', 'Tømrer, VVS, elektriker, smed, teknisk designer'],
    'vehicles' => ['🔧', 'Vehicles and machines', 'Mekaniker, lastvognsmekaniker, karrosseritekniker, landbrugsmaskiner'],
    'transport' => ['🚚', 'Transport and logistics', 'Lager og terminal, vejgodstransport'],
    'food' => ['🍳', 'Food and service', 'Gastronom, tjener'],
    'health' => ['🩺', 'Health', 'Tandklinikassistent'],
    'animals' => ['🐾', 'Animals and nature', 'Dyrepasser, veterinærsygeplejerske, landbrug, anlægsgartner, beslagsmed'],
    'fitness' => ['💪', 'Fitness and training', 'Fitness- og træneruddannelsen'],
    'beauty' => ['✂️', 'Hair and beauty', 'Frisør'],
];

/**
 * A length of time as "5 minutes", "3 days", "1 year" etc., rounded down to the biggest whole unit.
 */
function hbHubProfileTimeSpan(int $seconds): string
{
    $units = [
        'year' => 365 * 86400,
        'month' => 30 * 86400,
        'week' => 7 * 86400,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
    ];
    foreach ($units as $name => $length) {
        $count = intdiv($seconds, $length);
        if ($count >= 1) {
            return $count . ' ' . $name . ($count === 1 ? '' : 's');
        }
    }
    return 'less than a minute';
}

/**
 * The user's status from their settings: ['emoji' => ..., 'text' => ..., 'expires' => ...], the first two '' when not
 * set or when it has cleared itself. expires is a Unix time, or null when it doesn't clear.
 *
 * @return array{emoji: string, text: string, expires: ?int}
 */
function hbHubProfileStatus(array $settings): array
{
    $status = $settings['profileStatus'] ?? null;
    $expires = is_int($status['expires'] ?? null) ? $status['expires'] : null;
    if ($expires !== null && $expires <= time()) {
        return ['emoji' => '', 'text' => '', 'expires' => null];
    }
    return [
        'emoji' => is_string($status['emoji'] ?? null) ? $status['emoji'] : '',
        'text' => is_string($status['text'] ?? null) ? $status['text'] : '',
        'expires' => $expires,
    ];
}

/**
 * Whether there's a status to show (an emoji, a text or both).
 *
 * @param array{emoji: string, text: string, expires: ?int} $status
 */
function hbHubProfileHasStatus(array $status): bool
{
    return $status['emoji'] !== '' || $status['text'] !== '';
}

/**
 * The status to save from a form's emoji, text and "Clear after" choice (a key of HBHUB_PROFILE_STATUS_CLEAR_AFTER, or
 * HBHUB_PROFILE_STATUS_CLEAR_KEEP for when the status there is now clears), or why it can't be saved.
 * $current is the status they have now (hbHubProfileStatus()).
 *
 * @param array{emoji: string, text: string, expires: ?int} $current
 * @return array{emoji: string, text: string, expires: ?int}|string
 */
function hbHubProfileStatusFromForm(string $emoji, string $text, string $clearAfter, array $current): array|string
{
    $error = hbHubProfileStatusError($emoji, $text, $current);
    if ($error !== null) {
        return $error;
    }
    if ($emoji === '' && $text === '') {
        return ['emoji' => '', 'text' => '', 'expires' => null];
    }
    // Left as it is, but it cleared while the form was open: the default, as for a new one
    if ($clearAfter === HBHUB_PROFILE_STATUS_CLEAR_KEEP && !hbHubProfileHasStatus($current)) {
        $clearAfter = HBHUB_PROFILE_STATUS_CLEAR_DEFAULT;
    }
    if ($clearAfter === HBHUB_PROFILE_STATUS_CLEAR_KEEP) {
        $expires = $current['expires'];
    } elseif (isset(HBHUB_PROFILE_STATUS_CLEAR_AFTER[$clearAfter])) {
        $seconds = HBHUB_PROFILE_STATUS_CLEAR_AFTER[$clearAfter][0];
        $expires = $seconds !== null ? time() + $seconds : null;
    } else {
        return 'Please choose when your status should clear.';
    }
    return ['emoji' => $emoji, 'text' => $text, 'expires' => $expires];
}

/**
 * The <option>s of a status's "Clear after" picker, with $selected picked. With a status already there's a first one
 * that leaves when it clears as it is, like "Leave as it is (clears in 3 hours)", which is the one picked by default.
 * Without one it's HBHUB_PROFILE_STATUS_CLEAR_DEFAULT.
 *
 * @param array{emoji: string, text: string, expires: ?int} $current
 */
function hbHubProfileStatusClearOptions(array $current, ?string $selected = null): string
{
    $options = [];
    if (hbHubProfileHasStatus($current)) {
        $options[HBHUB_PROFILE_STATUS_CLEAR_KEEP] = 'Leave as it is (' . ($current['expires'] !== null
            ? 'clears in ' . hbHubProfileTimeSpan(max(0, $current['expires'] - time()))
            : 'doesn\'t clear') . ')';
    }
    foreach (HBHUB_PROFILE_STATUS_CLEAR_AFTER as $key => [, $label]) {
        $options[$key] = $label;
    }
    if ($selected === null || !isset($options[$selected])) {
        $selected = array_key_first($options) === HBHUB_PROFILE_STATUS_CLEAR_KEEP ? HBHUB_PROFILE_STATUS_CLEAR_KEEP : HBHUB_PROFILE_STATUS_CLEAR_DEFAULT;
    }

    $html = '';
    foreach ($options as $key => $label) {
        $html .= '<option value="' . htmlspecialchars($key) . '"' . ($key === $selected ? ' selected' : '') . '>'
            . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

/**
 * Saves the user's status (from hbHubProfileStatusFromForm()), and nothing else in their settings. Locked while that
 * happens, so settings saved somewhere else at the same time aren't lost.
 *
 * @param array{emoji: string, text: string, expires: ?int} $status
 */
function hbHubProfileSaveStatus(PDO $db, int $userId, array $status): void
{
    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT userSettings FROM `HBHub-Users` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $settings = hbHubUserSettings($statement->fetchColumn() ?: null);
        $settings['profileStatus'] = $status;
        $db->prepare('UPDATE `HBHub-Users` SET userSettings = ? WHERE userId = ?')
            ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Whether $emoji is exactly one emoji, as the bio's allow list has them (account.php): one character as the user
 * sees it (\X, so 👨‍👩‍👧 and 1️⃣ count as one) with an emoji in it.
 */
function hbHubProfileIsEmoji(string $emoji): bool
{
    return preg_match('/^\X\z/u', $emoji) === 1
        && hbHubIsLatinText($emoji)
        && preg_match('/[' . HBHUB_LATIN_TEXT_EMOJI . ']/u', $emoji) === 1;
}

/**
 * Why the status can't be saved, or null when it's fine. The text has the bio's rules: only the allow list (unless it's
 * the one they have now) and no blacklisted links. $current is the status they have now.
 *
 * @param array{emoji: string, text: string} $current
 */
function hbHubProfileStatusError(string $emoji, string $text, array $current): ?string
{
    if ($emoji !== '' && !hbHubProfileIsEmoji($emoji)) {
        return 'Please pick one emoji for your status.';
    }
    if ($text !== $current['text'] && !hbHubIsLatinText($text, true)) {
        return 'Your status can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji.';
    }
    if (hbHubUrlBlacklisted($text)) {
        return 'Woah, hold up! Your status has a dangerous link or a link that compromises people\'s privacy in it.';
    }
    if (mb_strlen($text) > HBHUB_PROFILE_STATUS_TEXT_MAX) {
        return 'Please keep your status to ' . HBHUB_PROFILE_STATUS_TEXT_MAX . ' characters or fewer.';
    }
    if (substr_count($text, "\n") >= HBHUB_PROFILE_STATUS_LINES_MAX) {
        return 'Please keep your status to ' . HBHUB_PROFILE_STATUS_LINES_MAX . ' lines or fewer.';
    }
    return null;
}

/**
 * The user's education badge from their settings as [key, emoji, name], or null when they haven't picked one.
 *
 * @return ?array{0: string, 1: string, 2: string}
 */
function hbHubProfileEducation(array $settings): ?array
{
    $key = $settings['profileEducation'] ?? null;
    return is_string($key) && isset(HBHUB_PROFILE_EDUCATIONS[$key])
        ? [$key, HBHUB_PROFILE_EDUCATIONS[$key][0], HBHUB_PROFILE_EDUCATIONS[$key][1]]
        : null;
}

/**
 * The banner's gradient from the user's settings as [color1, color2, angle], or null for the site's own one.
 *
 * @return ?array{0: string, 1: string, 2: int}
 */
function hbHubProfileBannerGradient(array $settings): ?array
{
    $gradient = $settings['profileBanner'] ?? null;
    return is_array($gradient)
        ? chatBackgroundOf($gradient['color1'] ?? null, $gradient['color2'] ?? null, $gradient['gradientAngle'] ?? null)
        : null;
}

/**
 * The address of the user's banner picture. The attachment id in it changes with every new one, so an old one is
 * never shown from the cache (like hbHubAvatarUrl()).
 */
function hbHubProfileBannerUrl(int $userId, int $attachmentId): string
{
    return '/banner?user=' . $userId . '&v=' . $attachmentId;
}

/**
 * SQL for the id of the user $alias's (an `HBHub-Users` alias, written in the code) banner picture, NULL when they
 * don't have one or it was deleted.
 */
function hbHubProfileBannerIdSql(string $alias): string
{
    return '(SELECT b.attachmentId FROM `HBHub-ChatAttachments` b WHERE b.attachmentId = ' . $alias . '.userBannerAttachmentId
        AND b.attachmentStatus = ' . HBHUB_CHAT_ATTACHMENT_KEPT . ')';
}

/**
 * Makes the uploaded picture the user's banner, in place of the one they had (whose tickets are then free again).
 * Returns null when it's saved, otherwise an error code: the ones from hbHubChatAttachmentPrepare(), notJxl, disabled,
 * storageFull, busy, or notEnoughTickets.
 */
function hbHubProfileSetBanner(PDO $db, int $userId, mixed $file): ?string
{
    hbHubChatAttachmentsSweep($db);
    $blocked = hbHubChatAttachmentsBlocked($db);
    if ($blocked !== null) {
        return $blocked;
    }

    $attachment = hbHubChatAttachmentPrepare($file, true);
    if (is_string($attachment)) {
        return $attachment;
    }
    if ($attachment['type'] !== 'image/jxl') {
        hbHubChatAttachmentsDiscard([$attachment]);
        return 'notJxl';
    }
    if ($attachment['size'] > HBHUB_PROFILE_BANNER_MAX_BYTES) {
        hbHubChatAttachmentsDiscard([$attachment]);
        return 'tooBig';
    }
    $tickets = hbHubChatAttachmentTickets($attachment['size']);

    // The same lock as a chat upload, so the two can't both squeeze into the user's last tickets or the last free space
    if ((int) $db->query('SELECT GET_LOCK(CONCAT(DATABASE(), \'.hbHubChatAttachments\'), 30)')->fetchColumn() !== 1) {
        hbHubChatAttachmentsDiscard([$attachment]);
        return 'busy';
    }
    $oldBannerId = null;
    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT b.attachmentId AS bannerId, b.attachmentTickets AS bannerTickets FROM `HBHub-Users` u
            LEFT JOIN `HBHub-ChatAttachments` b ON b.attachmentId = u.userBannerAttachmentId AND b.attachmentStatus = ?
            WHERE u.userId = ? FOR UPDATE');
        $statement->execute([HBHUB_CHAT_ATTACHMENT_KEPT, $userId]);
        $old = $statement->fetch();
        $oldBannerId = $old['bannerId'] !== null ? (int) $old['bannerId'] : null;

        // The old banner's tickets count as free, as it goes when this one comes
        $ticketsFree = hbHubChatAttachmentTicketsFree($db, $userId) + (int) $old['bannerTickets'];
        $error = null;
        if ($tickets > $ticketsFree) {
            $error = 'notEnoughTickets';
        } elseif (hbHubChatAttachmentsTotalBytes($db) + $attachment['storedSize'] > hbHubChatAttachmentsMaxTotal()) {
            $error = 'storageFull';
        }
        if ($error !== null) {
            $db->rollBack();
            hbHubChatAttachmentsDiscard([$attachment]);
            return $error;
        }

        $db->prepare('INSERT INTO `HBHub-ChatAttachments` (userId, chatId, messageId, attachmentKey, attachmentName,
                attachmentType, attachmentKind, attachmentSize, attachmentStoredSize, attachmentTickets, attachmentExpiresTimestamp)
            VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $attachment['key'], HBHUB_PROFILE_BANNER_NAME, $attachment['type'], $attachment['kind'],
                $attachment['size'], $attachment['storedSize'], $tickets, HBHUB_PROFILE_BANNER_NEVER]);
        $db->prepare('UPDATE `HBHub-Users` SET userBannerAttachmentId = ? WHERE userId = ?')
            ->execute([(int) $db->lastInsertId(), $userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        hbHubChatAttachmentsDiscard([$attachment]);
        throw $e;
    } finally {
        $db->query('SELECT RELEASE_LOCK(CONCAT(DATABASE(), \'.hbHubChatAttachments\'))')->fetchColumn();
    }

    // After the commit, as a deleted file can't come back
    if ($oldBannerId !== null) {
        hbHubChatAttachmentsRemove($db, 'a.attachmentId = ? AND a.userId = ?', [$oldBannerId, $userId]);
    }
    return null;
}

/**
 * Takes away the user's banner picture, so the gradient is shown again, and frees its tickets.
 */
function hbHubProfileRemoveBanner(PDO $db, int $userId): void
{
    $statement = $db->prepare('SELECT userBannerAttachmentId FROM `HBHub-Users` WHERE userId = ?');
    $statement->execute([$userId]);
    $bannerId = $statement->fetchColumn();
    $db->prepare('UPDATE `HBHub-Users` SET userBannerAttachmentId = NULL WHERE userId = ?')->execute([$userId]);
    if ($bannerId !== false && $bannerId !== null) {
        hbHubChatAttachmentsRemove($db, 'a.attachmentId = ? AND a.userId = ?', [(int) $bannerId, $userId]);
    }
}
