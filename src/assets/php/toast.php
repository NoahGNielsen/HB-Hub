<?php
// One-off notifications shown on the next page, e.g. after logging in.
// The cookie only holds a key, so it can't be used to show arbitrary text.

const HBHUB_TOAST_COOKIE = 'hbHubToast';
const HBHUB_TOASTS = [
    'login' => 'You are now logged in. Welcome back!',
    'onboarding' => 'Your account has been created. Welcome to HB Hub!',
    'settings' => 'Your settings have been saved.',
    // The status popup on your own profile (otherPages/profile.php)
    'statusSaved' => 'Your status has been saved.',
    'statusCleared' => 'Your status has been cleared.',
    'password' => 'Your password has been changed. Your other devices have been logged out.',
    'totpOn' => 'Two-factor login is now on.',
    'totpOff' => 'Two-factor login is now off.',
    'blockPending' => 'Saved. Your block will change later, see the settings for when.',
    'logout' => 'You are now logged out.',
    'deviceLoggedOut' => 'That device has been logged out.',
    'logoutEverywhere' => 'You have been logged out on every device.',
    'lockedOut' => 'Too many wrong passwords or codes, so you have been logged out.',
    'accountDeleted' => 'Your account has been deleted. Goodbye!',
    'feedbackSent' => 'Thanks! Your feedback has been sent to the admins.',
    // The admin panel (admin/user.php, admin/chat.php, admin/banned.php and admin/feedback.php)
    'adminSaved' => 'Saved.',
    'adminBanned' => 'The user has been banned.',
    'adminUnbanned' => 'The user has been unbanned.',
    'adminGhostBanned' => 'The user has been ghost banned. From now on, what they do in chats only shows to them.',
    'adminGhostUnbanned' => 'The ghost ban has been lifted.',
    'adminBlocked' => 'Gambling and Games are now blocked for the user, and they can\'t lift it themselves.',
    'adminBlockLifted' => 'The admin block has been lifted.',
    'adminPassword' => 'The password has been reset and the user has been logged out everywhere.',
    'adminTotpOff' => 'Two-factor login has been removed.',
    'adminLoggedOut' => 'The user has been logged out of every session.',
    'adminChipsAdded' => 'The chips have been added.',
    'adminChipsRemoved' => 'The chips have been taken away.',
    'adminMessageDeleted' => 'The message has been deleted.',
    'adminMessageUnpinned' => 'The message has been unpinned.',
    'adminMessagesDeleted' => 'All of the user\'s messages have been deleted.',
    'adminAttachmentDeleted' => 'The attachment has been deleted, and its tickets are free again.',
    'adminAttachmentsDeleted' => 'All of the user\'s attachments have been deleted, and their tickets are free again.',
    'adminTicketsAdded' => 'The tickets have been added.',
    'adminTicketsRevoked' => 'The tickets have been taken away.',
    'adminAccountDeleted' => 'The account has been deleted.',
    // A person's accounts at once (admin/person.php, or a switch on admin/user.php)
    'adminBannedAll' => 'The accounts have been banned.',
    'adminUnbannedAll' => 'The accounts have been unbanned.',
    'adminGhostBannedAll' => 'The accounts have been ghost banned. From now on, what they do in chats only shows to them.',
    'adminGhostUnbannedAll' => 'The accounts\' ghost bans have been lifted.',
    'adminBlockedAll' => 'Gambling and Games are now blocked for the accounts, and they can\'t lift it themselves.',
    'adminBlockLiftedAll' => 'The admin block has been lifted from the accounts.',
    'adminLoggedOutAll' => 'The accounts have been logged out of every session.',
    'adminAccountsDeleted' => 'The accounts have been deleted.',
    'adminNoAccounts' => 'Nothing was changed: tick at least one account it can change.',
    'adminMemberKicked' => 'The member has been kicked.',
    'adminChatDeleted' => 'The chat has been deleted.',
    'adminBannedPictureAdded' => 'The picture has been added to the banned pictures.',
    'adminBannedPictureRemoved' => 'The picture has been removed from the banned pictures.',
    'adminFeedbackDone' => 'The feedback has been marked as done.',
    'adminFeedbackNew' => 'The feedback has been marked as new.',
    'adminFeedbackDeleted' => 'The feedback has been deleted.',
    'adminFeedbackReplied' => 'The feedback has been marked as done, and your reply has been sent to them.',
    'adminFeedbackReplyMissing' => 'Nothing was sent: write a reply of at most 500 characters.',
    'adminCrashDeleted' => 'The crash report has been deleted.',
];

/**
 * Shows the toast on the next page load. Call before any output, e.g. right before a redirect.
 */
function hbHubSetToast(string $key): void
{
    // Not httponly: toast.js removes it once shown. Expires on its own in case it never is.
    setcookie(HBHUB_TOAST_COOKIE, $key, [
        'expires' => time() + 60,
        'path' => '/',
        'secure' => true,
        'samesite' => 'Lax',
    ]);
}

function hbHubRenderToast(): void
{
    $message = HBHUB_TOASTS[$_COOKIE[HBHUB_TOAST_COOKIE] ?? ''] ?? null;
    if ($message === null) return;
    ?>
    <div class="toast toast-success" role="status">
        <svg class="toast-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        <p class="toast-message"><?= htmlspecialchars($message) ?></p>
        <div class="toast-timer"></div>
    </div>
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/toast.js?v=<?= filemtime(__DIR__ . '/../js/toast.js') ?>" defer></script>
    <?php
}
