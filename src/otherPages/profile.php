<?php
// Profile page: /otherPages/profile?user=id shows that user, without ?user= it shows the logged in user.
// Banned users don't have a profile (the same as in the chat's user search).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const PROFILE_ROLE_ADMIN = 2; // userRole in HBHub-Users
const PROFILE_ONLINE_SECONDS = 5 * 60; // seen this recently counts as online (last seen is updated on every page load)

/**
 * A length of time as "5 minutes", "3 days", "1 year" etc., rounded down to the biggest whole unit.
 */
function profileTimeSpan(int $seconds): string
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

$profileUserId = $_GET['user'] ?? (string) $hbHubUser['userId'];
if (!is_string($profileUserId) || !ctype_digit($profileUserId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}

// The elapsed times are worked out by the database, so PHP's time zone doesn't matter
$statement = hbHubDatabase()->prepare('SELECT userId, userName, userDescription, userSettings, userAvatarAttachmentId, userRole,
        userCreatedTimestamp,
        GREATEST(TIMESTAMPDIFF(SECOND, userCreatedTimestamp, NOW()), 0) AS memberSeconds,
        GREATEST(TIMESTAMPDIFF(SECOND, userLastSeenTimestamp, NOW()), 0) AS lastSeenSeconds
    FROM `HBHub-Users` WHERE userId = ? AND (userStatus IS NULL OR userStatus <> ?)');
$statement->execute([(int) $profileUserId, HBHUB_SESSION_STATUS_BANNED]);
$profile = $statement->fetch();
if ($profile === false) {
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}

$profileIsOwn = (int) $profile['userId'] === (int) $hbHubUser['userId'];
$profileIsAdmin = (int) $profile['userRole'] === PROFILE_ROLE_ADMIN;
$profileIsOnline = (int) $profile['lastSeenSeconds'] <= PROFILE_ONLINE_SECONDS;

// Same shape as database/defaultUserSettings.json
$profileSettings = json_decode($profile['userSettings'] ?? '', true);
$profileYear = is_array($profileSettings) && is_scalar($profileSettings['year'] ?? null) ? (string) $profileSettings['year'] : '';
$profileClass = is_array($profileSettings) && is_scalar($profileSettings['class'] ?? null) ? (string) $profileSettings['class'] : '';

$profileAvatarSrc = hbHubAvatarUrl((int) $profile['userId'], $profile['userAvatarAttachmentId'] !== null ? (int) $profile['userAvatarAttachmentId'] : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/profile.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/profile.css') ?>">
    <title><?= htmlspecialchars($profile['userName']) ?> - HB Hub</title>
    <meta name="description" content="Profile page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="profile">
        <div class="profile-header">
            <div class="profile-avatar-wrap">
                <?php if ($profileAvatarSrc !== null): ?>
                    <img class="profile-avatar" src="<?= htmlspecialchars($profileAvatarSrc) ?>" alt="Profile picture of <?= htmlspecialchars($profile['userName']) ?>">
                <?php else: ?>
                    <img class="profile-avatar is-placeholder" src="/assets/images/icons/user.png" alt="">
                <?php endif; ?>
                <span class="profile-status-dot<?= $profileIsOnline ? ' is-online' : '' ?>" aria-hidden="true"></span>
            </div>
            <div class="profile-heading">
                <h1 class="profile-name">
                    <?= htmlspecialchars($profile['userName']) ?>
                    <?php if ($profileIsAdmin): ?>
                        <span class="profile-badge">Admin</span>
                    <?php endif; ?>
                </h1>
                <p class="profile-status<?= $profileIsOnline ? ' is-online' : '' ?>">
                    <?php if ($profileIsOnline): ?>
                        Online
                    <?php else: ?>
                        Offline &middot; last seen <?= profileTimeSpan((int) $profile['lastSeenSeconds']) ?> ago
                    <?php endif; ?>
                </p>
                <?php if ($profileIsOwn): ?>
                    <p class="profile-own">This is your profile</p>
                <?php endif; ?>
            </div>
        </div>

        <section class="profile-section" aria-labelledby="profile-bio-heading">
            <h2 id="profile-bio-heading">Bio</h2>
            <?php if ($profile['userDescription'] !== null && trim($profile['userDescription']) !== ''): ?>
                <p class="profile-bio"><?= htmlspecialchars($profile['userDescription']) ?></p>
            <?php else: ?>
                <p class="profile-bio is-empty">No bio yet.</p>
            <?php endif; ?>
        </section>

        <dl class="profile-details">
            <div class="profile-detail">
                <dt>Class</dt>
                <dd><?= $profileYear !== '' && $profileClass !== '' ? htmlspecialchars($profileYear . '.' . $profileClass) : '&ndash;' ?></dd>
            </div>
            <div class="profile-detail">
                <dt>Member for</dt>
                <dd>
                    <?= profileTimeSpan((int) $profile['memberSeconds']) ?>
                    <span class="profile-detail-sub">Joined <?= date('j F Y', strtotime($profile['userCreatedTimestamp'])) ?></span>
                </dd>
            </div>
        </dl>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
