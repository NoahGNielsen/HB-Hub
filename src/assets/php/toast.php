<?php
// One-off notifications shown on the next page, e.g. after logging in.
// The cookie only holds a key, so it can't be used to show arbitrary text.

const HBHUB_TOAST_COOKIE = 'hbHubToast';
const HBHUB_TOASTS = [
    'login' => 'You are now logged in. Welcome back!',
    'onboarding' => 'Your account has been created. Welcome to HB Hub!',
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
