<?php
// Feedback page: /feedback, opened from the Feedback button at the bottom of the side bar (navPanel.php).
// Lets a logged in user write to the site admins: a bug, an idea, something they'd like changed. Only site admins
// can read it, on the admin panel's Feedback tab (admin/feedback.php).
//
// The button links here with ?from=<the address of the page it was pressed on>, which is kept in the form and is where
// the user is sent back to with a toast once it's sent. Its path (not the query) is saved with the feedback, so admins
// know where a bug was.
// The form (POST message, from) is checked with assets/php/feedback.php, at most HBHUB_FEEDBACK_PER_HOUR an hour.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/feedback.php';

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];
$feedbackFrom = hbHubFeedbackReturn($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['from'] ?? null) : ($_GET['from'] ?? null));
$feedbackMessage = '';
$feedbackError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackMessage = is_string($_POST['message'] ?? null) ? trim(str_replace("\r\n", "\n", $_POST['message'])) : '';

    if ($feedbackMessage === '' || !mb_check_encoding($feedbackMessage, 'UTF-8')) {
        $feedbackError = 'Please write something first.';
    } elseif (mb_strlen($feedbackMessage) > HBHUB_FEEDBACK_MAX) {
        $feedbackError = 'That\'s too long. Please keep it under ' . number_format(HBHUB_FEEDBACK_MAX) . ' characters.';
    } elseif (hbHubFeedbackLeft($db, $userId) === 0) {
        $feedbackError = 'You\'ve sent a lot of feedback in the last hour. Please wait a bit before sending more.';
    } else {
        hbHubFeedbackSend($db, $userId, $feedbackMessage, hbHubFeedbackPage($feedbackFrom));
        require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
        hbHubSetToast('feedbackSent');
        header('Location: ' . ($feedbackFrom ?? '/'), true, 303);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <title>Feedback - HB Hub</title>
    <meta name="description" content="Send feedback to the HB Hub admins: a bug, an idea or something you'd like changed.">
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings">
        <h1>Feedback</h1>

        <section class="settings-card" id="feedback" aria-labelledby="feedback-heading">
            <h2 id="feedback-heading">Tell the admins</h2>
            <p class="settings-intro">
                Found a bug, have an idea, or is there something you'd like changed? Write it here. Only the site admins
                can read it, together with your name and the page you came from.
                To report someone who breaks the <a href="/legal/code-of-conduct">code of conduct</a>, you can use this too.
            </p>
            <form action="<?= HBHUB_FEEDBACK_PAGE ?>#feedback" method="post">
                <?php if ($feedbackFrom !== null): ?>
                    <input type="hidden" name="from" value="<?= htmlspecialchars($feedbackFrom) ?>">
                <?php endif; ?>
                <div class="settings-field">
                    <label class="settings-label" for="feedback-message">Your feedback</label>
                    <textarea id="feedback-message" name="message" maxlength="<?= HBHUB_FEEDBACK_MAX ?>" rows="7" required
                              aria-describedby="feedback-message-hint"><?= htmlspecialchars($feedbackMessage) ?></textarea>
                    <p class="settings-hint" id="feedback-message-hint">
                        Up to <?= number_format(HBHUB_FEEDBACK_MAX) ?> characters. For a bug, it helps to say what you did and what went wrong.
                    </p>
                    <?php if ($feedbackError !== null): ?>
                        <p class="settings-error" role="alert"><?= htmlspecialchars($feedbackError) ?></p>
                    <?php endif; ?>
                </div>
                <div class="settings-actions">
                    <a class="settings-btn" href="<?= htmlspecialchars($feedbackFrom ?? '/') ?>">Cancel</a>
                    <button type="submit" class="settings-btn settings-btn-primary">Send feedback</button>
                </div>
            </form>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
