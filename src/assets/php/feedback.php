<?php
// Feedback from users to the site admins. The Feedback button at the bottom of the side bar (navPanel.php) opens
// otherPages/feedback.php, which saves it here with hbHubFeedbackSend(). Site admins read it on the admin panel's
// Feedback tab (admin/feedback.php), mark it as done (or new again) and delete it. They can also mark it as done with a
// reply (hbHubFeedbackReply()), which the user gets as a notification under the bell (notificationTypes/feedback.php).
// The reply is kept in that notification and as the reason in the admin log (admin/feedback.php), not with the feedback.
//
// Each one is a row in HBHub-Feedback: who sent it, what they wrote, the path of the page they were on when they
// pressed the button (only the path, never the query, so no chat ids or search words), whether it was sent from the dev
// site, when, and who marked it done.

const HBHUB_FEEDBACK_MAX = 2000; // characters, the length of feedbackContent
const HBHUB_FEEDBACK_PER_HOUR = 5; // per user, so the button can't be used to spam the admins
const HBHUB_FEEDBACK_PAGE = '/feedback';
const HBHUB_FEEDBACK_REPLY_MAX = 500; // characters, the message an admin sends with Reply, and mark completed
const HBHUB_FEEDBACK_DEV_FOLDER = 'dev_public'; // the web root the dev branch is uploaded to (.github/workflows/ftp-upload.yml)

/**
 * Whether this request is on the dev site: the dev branch is uploaded to its own web root folder on the same server and
 * shares the database with the live site, so feedback from both lands in the same list.
 */
function hbHubFeedbackIsDev(): bool
{
    return basename(rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/')) === HBHUB_FEEDBACK_DEV_FOLDER;
}

/**
 * The address the Feedback button was pressed on (?from=, path and query), to send the user back to: a page on this
 * site, checked like login's ?return= (hbHubReturnPath() in session.php). Null when it isn't one, or is the feedback
 * page itself.
 */
function hbHubFeedbackReturn(mixed $from): ?string
{
    // One leading slash only - "//host" and "/\host" are treated by browsers as another site
    if (!is_string($from) || strlen($from) > 2000 || preg_match('#^/(?![/\\\\])[^\p{Cc}]*$#u', $from) !== 1) {
        return null;
    }
    return rtrim((string) parse_url($from, PHP_URL_PATH), '/') === HBHUB_FEEDBACK_PAGE ? null : $from;
}

/**
 * The part of that address that's saved with the feedback: only the path, without the query (so no chat ids or
 * search words), and only when it's plain enough to show and link to on the admin panel: letters, digits and / . _ ~ -.
 */
function hbHubFeedbackPage(?string $return): ?string
{
    $path = $return !== null ? (string) parse_url($return, PHP_URL_PATH) : '';
    return preg_match('~^/[A-Za-z0-9/._\~-]{0,254}$~', $path) === 1 ? $path : null;
}

/**
 * Tidies text typed into the feedback or reply box: Windows/old Mac line breaks become "\n", the ends are trimmed and
 * any run of empty lines (or lines with only spaces or invisible characters) shrinks to a single empty line, so a
 * paragraph break still works but a wall of new lines doesn't. Also used when showing feedback saved before this.
 */
function hbHubFeedbackTidy(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // \h is every horizontal space (with /u), plus zero-width spaces/joiners, the word joiner and the BOM
    return trim((string) preg_replace('/(?:[\h\x{200B}-\x{200D}\x{2060}\x{FEFF}]*\n){3,}/u', "\n\n", $text));
}

/**
 * How many more the user can send right now (HBHUB_FEEDBACK_PER_HOUR in the last hour).
 */
function hbHubFeedbackLeft(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT COUNT(*) FROM `HBHub-Feedback`
        WHERE feedbackUserId = ? AND feedbackCreatedTimestamp > NOW() - INTERVAL 1 HOUR');
    $statement->execute([$userId]);
    return max(0, HBHUB_FEEDBACK_PER_HOUR - (int) $statement->fetchColumn());
}

/**
 * Saves a user's feedback. $content is already checked (not empty, at most HBHUB_FEEDBACK_MAX characters) and
 * $page comes from hbHubFeedbackPage(). It's marked as from the dev site when it was sent there (hbHubFeedbackIsDev()).
 */
function hbHubFeedbackSend(PDO $db, int $userId, string $content, ?string $page): void
{
    $db->prepare('INSERT INTO `HBHub-Feedback` (feedbackUserId, feedbackContent, feedbackPage, feedbackFromDev) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $content, $page, (int) hbHubFeedbackIsDev()]);
}

/**
 * Marks feedback as done by a site admin, or as new again with $done false. Marking it done twice keeps who did it first.
 * Returns whether it changed anything.
 */
function hbHubFeedbackSetDone(PDO $db, int $feedbackId, bool $done, int $adminId): bool
{
    $statement = $done
        ? $db->prepare('UPDATE `HBHub-Feedback` SET feedbackDoneTimestamp = NOW(), feedbackDoneByUserId = ?
            WHERE feedbackId = ? AND feedbackDoneTimestamp IS NULL')
        : $db->prepare('UPDATE `HBHub-Feedback` SET feedbackDoneTimestamp = NULL, feedbackDoneByUserId = NULL
            WHERE feedbackId = ? AND feedbackDoneTimestamp IS NOT NULL');
    $statement->execute($done ? [$adminId, $feedbackId] : [$feedbackId]);
    return $statement->rowCount() > 0;
}

/**
 * The reply typed on the admin panel (the form's `reply` field), trimmed, or null when it's empty or longer than
 * HBHUB_FEEDBACK_REPLY_MAX characters.
 */
function hbHubFeedbackReplyText(mixed $reply): ?string
{
    $reply = is_string($reply) ? hbHubFeedbackTidy(mb_scrub($reply, 'UTF-8')) : '';
    return $reply !== '' && mb_strlen($reply) <= HBHUB_FEEDBACK_REPLY_MAX ? $reply : null;
}

/**
 * Marks feedback as done by a site admin and sends the user who sent it a notification with the admin's reply
 * ($reply from hbHubFeedbackReplyText()). Only when it wasn't done yet, so two admins replying at once only send one,
 * and no notification when the account that sent it has been deleted. Returns whether it changed anything.
 */
function hbHubFeedbackReply(PDO $db, int $feedbackId, int $adminId, string $reply): bool
{
    require_once __DIR__ . '/notifications.php';

    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT feedbackUserId FROM `HBHub-Feedback` WHERE feedbackId = ? AND feedbackDoneTimestamp IS NULL FOR UPDATE');
        $statement->execute([$feedbackId]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            $db->rollBack();
            return false;
        }

        hbHubFeedbackSetDone($db, $feedbackId, true, $adminId);
        if ($userId !== null) {
            // Nobody who did it, so it says "An admin" and not which one: feedback goes to the admins as a whole
            hbHubNotify($db, (int) $userId, 'feedback.reply', null, ['feedbackId' => $feedbackId, 'reply' => $reply]);
        }
        $db->commit();
        return true;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

/**
 * Deletes feedback for good. Returns whether there was any to delete.
 */
function hbHubFeedbackDelete(PDO $db, int $feedbackId): bool
{
    $statement = $db->prepare('DELETE FROM `HBHub-Feedback` WHERE feedbackId = ?');
    $statement->execute([$feedbackId]);
    return $statement->rowCount() > 0;
}
