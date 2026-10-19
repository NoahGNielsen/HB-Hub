<?php
// Feedback from users to the site admins. The Feedback button at the bottom of the side bar (navPanel.php) opens
// otherPages/feedback.php, which saves it here with hbHubFeedbackSend(). Site admins read it on the admin panel's
// Feedback tab (admin/feedback.php), mark it as done (or new again) and delete it.
//
// Each one is a row in HBHub-Feedback: who sent it, what they wrote, the path of the page they were on when they
// pressed the button (only the path, never the query, so no chat ids or search words), when, and who marked it done.

const HBHUB_FEEDBACK_MAX = 2000; // characters, the length of feedbackContent
const HBHUB_FEEDBACK_PER_HOUR = 5; // per user, so the button can't be used to spam the admins
const HBHUB_FEEDBACK_PAGE = '/otherPages/feedback';

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
 * $page comes from hbHubFeedbackPage().
 */
function hbHubFeedbackSend(PDO $db, int $userId, string $content, ?string $page): void
{
    $db->prepare('INSERT INTO `HBHub-Feedback` (feedbackUserId, feedbackContent, feedbackPage) VALUES (?, ?, ?)')
        ->execute([$userId, $content, $page]);
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
 * Deletes feedback for good. Returns whether there was any to delete.
 */
function hbHubFeedbackDelete(PDO $db, int $feedbackId): bool
{
    $statement = $db->prepare('DELETE FROM `HBHub-Feedback` WHERE feedbackId = ?');
    $statement->execute([$feedbackId]);
    return $statement->rowCount() > 0;
}
