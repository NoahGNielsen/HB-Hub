<?php
// The admin log: everything site admins do in the admin panel goes in HBHub-AdminLog, so there's a record of who did
// what to whom. That includes just opening a user's or a chat's admin panel (admin/user.php, admin/chat.php), since
// that shows all their messages and personal info, and downloading a user's data. Changes are logged by the admin
// pages right after they've been made (adminLog()), opening one with adminLogView().
// The one thing in it no admin did is the automatic ban for uploading a banned profile picture ('user.autoban', by
// bannedAvatars.php), which has no admin (adminLogByUserId NULL), so admins can see every ban in one place.
// The log is read under Admin log in the admin panel's side menu (admin/log.php). Nobody can change or delete it from
// the site; rows older than ADMIN_LOG_KEEP_DAYS are removed by the nightly clean-up (cron/nightly.php, adminLogSweep()).
//
// A row is: the admin (adminLogByUserId), what they did (adminLogAction, one of ADMIN_LOG_ACTIONS), the user and/or
// chat it was about, and a few details as JSON (adminLogData, like {"amount": -500} for chips). Never a password,
// a name or a message's text: those are on the user's or chat's own page, and go away with them.

const ADMIN_LOG_KEEP_DAYS = 365;
const ADMIN_LOG_VIEW_MINUTES = 10; // opening the same user or chat again within this many minutes isn't logged again

// What each action says on the log page: {user} and {chat} become links to them, the rest is filled in from
// adminLogData by admin/log.php (adminLogText()). Kept here so every action that can be logged is in one place.
const ADMIN_LOG_ACTIONS = [
    'user.view' => 'Opened {user}\'s admin panel',
    'user.export' => 'Downloaded all of {user}\'s data',
    'user.ban' => 'Banned {user}',
    'user.unban' => 'Unbanned {user}',
    'user.autoban' => '{user} was banned automatically for uploading a banned profile picture', // no admin, see the top
    'user.name' => 'Changed {user}\'s name',
    'user.bio' => 'Changed {user}\'s bio',
    'user.avatar' => 'Removed {user}\'s profile picture',
    'user.block' => 'Changed {user}\'s gambling block',
    'user.password' => 'Reset {user}\'s password',
    'user.totp' => 'Removed two-factor login from {user}\'s account',
    'user.logout' => 'Logged {user} out everywhere',
    'user.chips' => 'Changed {user}\'s chips',
    'user.messages' => 'Deleted all of {user}\'s messages',
    'user.delete' => 'Deleted {user}\'s account',
    'message.delete' => 'Deleted a message from {user} in {chat}',
    'chat.view' => 'Opened the admin panel of {chat}',
    'chat.rename' => 'Renamed {chat}',
    'chat.icon' => 'Removed the icon of {chat}',
    'chat.background' => 'Changed the background of {chat}',
    'chat.kick' => 'Kicked {user} from {chat}',
    'chat.delete' => 'Deleted {chat}',
    'feedback.done' => 'Marked feedback as done',
    'feedback.new' => 'Marked feedback as new',
    'feedback.delete' => 'Deleted feedback',
    'banned.add' => 'Added a banned picture',
    'banned.remove' => 'Removed a banned picture',
];

/**
 * Logs that the admin did $action (a key of ADMIN_LOG_ACTIONS), about $userId and/or $chatId, with $data as details.
 * $adminId is null for what the site did by itself ('user.autoban').
 */
function adminLog(PDO $db, ?int $adminId, string $action, ?int $userId = null, ?int $chatId = null, ?array $data = null): void
{
    $db->prepare('INSERT INTO `HBHub-AdminLog` (adminLogByUserId, adminLogAction, adminLogUserId, adminLogChatId, adminLogData)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$adminId, $action, $userId, $chatId, $data !== null ? json_encode($data) : null]);
}

/**
 * Logs that the admin opened a user's or chat's admin panel ('user.view' or 'chat.view'), unless they already did in
 * the last ADMIN_LOG_VIEW_MINUTES. That keeps reloads, the page coming back after each change and going through the
 * pages of messages from filling the log, while every visit still shows up.
 */
function adminLogView(PDO $db, int $adminId, string $action, ?int $userId, ?int $chatId): void
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-AdminLog`
        WHERE adminLogByUserId = ? AND adminLogTimestamp > NOW() - INTERVAL ' . ADMIN_LOG_VIEW_MINUTES . ' MINUTE
            AND adminLogAction = ? AND adminLogUserId <=> ? AND adminLogChatId <=> ?
        LIMIT 1');
    $statement->execute([$adminId, $action, $userId, $chatId]);
    if ($statement->fetchColumn() === false) {
        adminLog($db, $adminId, $action, $userId, $chatId);
    }
}

/**
 * Removes the rows older than ADMIN_LOG_KEEP_DAYS, for the nightly clean-up. Returns how many went.
 */
function adminLogSweep(PDO $db): int
{
    return $db->exec('DELETE FROM `HBHub-AdminLog` WHERE adminLogTimestamp < NOW() - INTERVAL ' . ADMIN_LOG_KEEP_DAYS . ' DAY');
}
