<?php
// School classes: which year of HTX someone is in and their field of study (X, U, T, Y or Z), or that they don't go to
// HTX. Asked when signing up (userMgmt/onboarding.php) and changed in settings (otherPages/settings.php), shown on
// profiles (otherPages/profile.php) and in the admin panel (admin/user.php). The field is only asked for 1. to 3. year.
//
// Saved in userSettings (database/defaultUserSettings.json):
// - classStartYear: the school year they were (or are) in 1. year, as the calendar year it starts in, so a 2. year in
//   the 2026/27 school year has 2025. Their year itself isn't saved but worked out from this, so everyone moves up a
//   year on July 1st without anything having to run: 1. becomes 2., 2. becomes 3., and 3. becomes "Graduate" (they've
//   graduated), still with their field of study. NULL when they don't go to HTX.
// - class: their field of study, one of HBHUB_CLASS_FIELDS. NULL when they don't go to HTX.
// - classNotHtx: true when they said they don't go to HTX
// - classChatSchoolYear: the school year their class chat was last brought up to date in (hbHubClassChatSync())
// Accounts made before this have "year" (1-3) instead of classStartYear. Those were all picked in the 2026/27 school
// year (the site started in October 2026), so they're read as that.
//
// Class chats: everyone who started 1. year in the same year with the same field is a class, and has one group chat
// (chatClassKey in HBHub-Chats, like "2026-X"). It follows the class through the years, so its messages stay, and it's
// renamed when they move up ("1.X" to "2.X"). It's made by the system account (HBHUB_SYSTEM_USER_ID), not by whoever
// happened to be first in the class. People are put in it as already accepted, so it's never a message request.
// Nobody is a group admin in it, so nobody can rename it, kick or delete it, and members can't leave it or add others
// (chat/index.php); only site admins can, from the admin panel. Changing class in settings moves them to the other one.
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/userSettings.php';

const HBHUB_CLASS_YEARS = ['1', '2', '3']; // the years someone can say they're in, as the forms send them
const HBHUB_CLASS_NOT_HTX = 'none'; // the year the forms send for "Not HTX"
const HBHUB_CLASS_GRADUATE = 'graduate'; // the year settings sends for a graduate keeping their class as it is
const HBHUB_CLASS_FIELDS = ['X', 'U', 'T', 'Y', 'Z'];
const HBHUB_CLASS_LAST_YEAR = 3; // after this they're a Graduate
const HBHUB_CLASS_NEW_YEAR_MONTH = 7; // everyone moves up a year on the 1st of this month
const HBHUB_CLASS_TIME_ZONE = 'Europe/Copenhagen'; // July 1st in the school's time, not the server's
const HBHUB_CLASS_OLD_SETTINGS_SCHOOL_YEAR = 2026; // the school year an old "year" setting was picked in (see the top)

/**
 * The school year it is now, as the calendar year it started in: 2026 from July 1st 2026 to June 30th 2027.
 */
function hbHubClassSchoolYear(): int
{
    $now = new DateTimeImmutable('now', new DateTimeZone(HBHUB_CLASS_TIME_ZONE));
    $year = (int) $now->format('Y');
    return (int) $now->format('n') >= HBHUB_CLASS_NEW_YEAR_MONTH ? $year : $year - 1;
}

/**
 * Someone's class from their settings, or null when they haven't said (or it can't be read).
 * year is 1-3, or null when they've graduated or don't go to HTX. label is what profiles show: "2.X", "Graduate (X)"
 * or "Not HTX".
 *
 * @return ?array{notHtx: bool, startYear: ?int, year: ?int, field: ?string, label: string}
 */
function hbHubClassOf(array $settings): ?array
{
    if (($settings['classNotHtx'] ?? false) === true) {
        return ['notHtx' => true, 'startYear' => null, 'year' => null, 'field' => null, 'label' => 'Not HTX'];
    }

    $field = $settings['class'] ?? null;
    $startYear = $settings['classStartYear'] ?? null;
    if (!is_int($startYear) && is_int($settings['year'] ?? null) && in_array((string) $settings['year'], HBHUB_CLASS_YEARS, true)) {
        $startYear = HBHUB_CLASS_OLD_SETTINGS_SCHOOL_YEAR - $settings['year'] + 1; // an account from before classStartYear
    }
    if (!is_int($startYear) || !is_string($field) || !in_array($field, HBHUB_CLASS_FIELDS, true)) {
        return null;
    }

    $year = max(1, hbHubClassSchoolYear() - $startYear + 1); // a start year in the future counts as 1. year
    if ($year > HBHUB_CLASS_LAST_YEAR) {
        return ['notHtx' => false, 'startYear' => $startYear, 'year' => null, 'field' => $field, 'label' => 'Graduate (' . $field . ')'];
    }
    return ['notHtx' => false, 'startYear' => $startYear, 'year' => $year, 'field' => $field, 'label' => $year . '.' . $field];
}

/**
 * What the year and field pickers start on for someone's class: the year as the forms send it ('1'-'3', 'none' or
 * 'graduate') and the field, both '' when they haven't said.
 *
 * @return array{year: string, field: string}
 */
function hbHubClassFormValues(array $settings): array
{
    $class = hbHubClassOf($settings);
    return match (true) {
        $class === null => ['year' => '', 'field' => ''],
        $class['notHtx'] => ['year' => HBHUB_CLASS_NOT_HTX, 'field' => ''],
        $class['year'] === null => ['year' => HBHUB_CLASS_GRADUATE, 'field' => $class['field']],
        default => ['year' => (string) $class['year'], 'field' => $class['field']],
    };
}

/**
 * Why the picked year and field can't be saved, or null when they can. The field only counts for 1. to 3. year.
 * $canBeGraduate is for settings, where a graduate can stay one.
 */
function hbHubClassError(string $year, string $field, bool $canBeGraduate = false): ?string
{
    $isHtx = in_array($year, HBHUB_CLASS_YEARS, true) || ($canBeGraduate && $year === HBHUB_CLASS_GRADUATE);
    if (!$isHtx && $year !== HBHUB_CLASS_NOT_HTX) {
        return 'Please choose your year.';
    }
    if ($isHtx && !in_array($field, HBHUB_CLASS_FIELDS, true)) {
        return 'Please choose your field of study.';
    }
    return null;
}

/**
 * The settings with the picked year and field saved in them (checked with hbHubClassError() first).
 * Picking 'graduate' keeps the start year they have, so it only works for someone who's already a Graduate.
 */
function hbHubClassSetSettings(array $settings, string $year, string $field): array
{
    $class = hbHubClassOf($settings);
    unset($settings['year']); // the old way of saving it (see the top)

    if ($year === HBHUB_CLASS_NOT_HTX) {
        $settings['classNotHtx'] = true;
        $settings['classStartYear'] = null;
        $settings['class'] = null;
        return $settings;
    }

    $settings['classNotHtx'] = false;
    $settings['classStartYear'] = $year === HBHUB_CLASS_GRADUATE
        ? $class['startYear'] ?? hbHubClassSchoolYear() - HBHUB_CLASS_LAST_YEAR
        : hbHubClassSchoolYear() - (int) $year + 1;
    $settings['class'] = $field;
    return $settings;
}

/**
 * The class chat's chatClassKey for a class ("2026-X"), or null when they don't have one (not HTX).
 *
 * @param ?array $class from hbHubClassOf()
 */
function hbHubClassChatKey(?array $class): ?string
{
    return $class !== null && !$class['notHtx'] ? $class['startYear'] . '-' . $class['field'] : null;
}

/**
 * What the class chat is called right now: "2.X", or for a class that has graduated "Graduate (X) 2029",
 * so the classes of different years can be told apart.
 *
 * @param array $class from hbHubClassOf(), with a class chat
 */
function hbHubClassChatName(array $class): string
{
    return $class['year'] !== null ? $class['label'] : $class['label'] . ' ' . ($class['startYear'] + HBHUB_CLASS_LAST_YEAR);
}

/**
 * Puts the user in their class's chat, making it if the class doesn't have one yet, already accepted and with a notice
 * that they joined, and renames it when the class has moved up a year. Takes them out of any other class chat they're
 * in (a class they were in before changing it in settings). Then saves the school year it was done in, so
 * hbHubClassChatSyncIfDue() knows to do it again next July.
 * Done right after signing up and changing class, and once a year from the chat page.
 */
function hbHubClassChatSync(PDO $db, int $userId): void
{
    $statement = $db->prepare('SELECT userSettings FROM `HBHub-Users` WHERE userId = ?');
    $statement->execute([$userId]);
    $class = hbHubClassOf(hbHubUserSettings($statement->fetchColumn() ?: null));
    $key = hbHubClassChatKey($class);

    if ($key !== null) {
        // Two people of a new class at once can both try to make its chat. One of them gets a duplicate key (or a
        // deadlock, from the gap both locked), and then finds the chat the other one made the second time round.
        for ($try = 1; ; $try++) {
            try {
                hbHubClassChatJoin($db, $userId, $key, hbHubClassChatName($class));
                break;
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($try >= 2 || !in_array($e->errorInfo[1] ?? null, [1062, 1213], true)) {
                    throw $e;
                }
            }
        }
    }

    // Any other class chat they're still in is one they've left behind
    $statement = $db->prepare('SELECT c.chatId, c.chatType FROM `HBHub-ChatMembers` cm JOIN `HBHub-Chats` c ON c.chatId = cm.chatId
        WHERE cm.userId = ? AND c.chatClassKey IS NOT NULL AND NOT (c.chatClassKey <=> ?) AND c.chatStatus = ?');
    $statement->execute([$userId, $key, HBHUB_CHAT_STATUS_ACTIVE]);
    foreach ($statement->fetchAll() as $oldChat) {
        chatLeave($db, $userId, $oldChat);
    }

    $schoolYear = hbHubClassSchoolYear();
    hbHubChangeUserSettings($db, $userId, function (array $settings) use ($schoolYear): array {
        $settings['classChatSchoolYear'] = $schoolYear;
        return $settings;
    });
}

/**
 * The part of hbHubClassChatSync() that finds or makes the class chat and puts the user in it, in one transaction.
 * A class chat that was deleted (everyone in it deleted their account, or a site admin deleted it) gives its key up,
 * so the class gets a new one.
 */
function hbHubClassChatJoin(PDO $db, int $userId, string $key, string $name): void
{
    $db->beginTransaction();
    $statement = $db->prepare('SELECT chatId, chatName, chatStatus FROM `HBHub-Chats` WHERE chatClassKey = ? FOR UPDATE');
    $statement->execute([$key]);
    $chat = $statement->fetch();

    if ($chat !== false && (int) $chat['chatStatus'] !== HBHUB_CHAT_STATUS_ACTIVE) {
        $db->prepare('UPDATE `HBHub-Chats` SET chatClassKey = NULL WHERE chatId = ?')->execute([$chat['chatId']]);
        $chat = false;
    }

    if ($chat === false) {
        $db->prepare('INSERT INTO `HBHub-Chats` (chatName, chatType, chatCreatedBy, chatClassKey) VALUES (?, ?, ?, ?)')
            ->execute([$name, HBHUB_CHAT_TYPE_GROUP, HBHUB_SYSTEM_USER_ID, $key]);
        $chatId = (int) $db->lastInsertId();
    } else {
        $chatId = (int) $chat['chatId'];
        if ($chat['chatName'] !== $name) {
            // Moved up a year. Nobody did it, so there's no notice about it.
            $db->prepare('UPDATE `HBHub-Chats` SET chatName = ? WHERE chatId = ?')->execute([$name, $chatId]);
        }
    }

    // 1 row when they're new in it, 2 when an old row of theirs was changed, 0 when they were in it already
    $statement = $db->prepare('INSERT INTO `HBHub-ChatMembers` (chatId, userId, memberRole, userAcknowledgedJoin, memberIsGhost)
        VALUES (?, ?, ?, 1, 0)
        ON DUPLICATE KEY UPDATE userAcknowledgedJoin = 1, memberIsGhost = 0');
    $statement->execute([$chatId, $userId, HBHUB_CHAT_ROLE_MEMBER]);
    if ($statement->rowCount() > 0) {
        chatNotice($db, $userId, $chatId, HBHUB_CHAT_EVENT_JOIN);
    }
    $db->commit();
}

/**
 * hbHubClassChatSync(), when it hasn't been done yet this school year. The chat page calls it, so the class chat is
 * renamed on the first visit after July 1st, and accounts from before class chats get theirs.
 * Something going wrong is only logged, so the chat page still opens.
 *
 * @return bool whether it was done, so the caller knows the settings it has are old
 */
function hbHubClassChatSyncIfDue(PDO $db, int $userId, array $settings): bool
{
    if (($settings['classChatSchoolYear'] ?? null) === hbHubClassSchoolYear()) {
        return false;
    }
    try {
        hbHubClassChatSync($db, $userId);
    } catch (PDOException $e) {
        error_log('Class chat: could not bring user ' . $userId . "'s up to date: " . $e->getMessage());
    }
    return true;
}
