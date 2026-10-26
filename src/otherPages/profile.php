<?php
// Profile page: /profile?user=id shows that user, without ?user= it shows the logged in user.
// Banned users don't have a profile (the same as in the chat's user search).
// A card with the banner along the top (a picture, or the colors they picked: assets/php/profile.php), the profile
// picture over its bottom edge with a dot for online (hover it for how long they've been offline), their status next to
// it, and on the right a Message button (someone else) or the settings cog (your own). Under it the name, badges
// (Site admin, class, education), the bio and when they joined. Everything on it is changed in settings (otherPages/settings.php).
// profile.js paints the banner's colors, as the content security policy doesn't allow style attributes, and
// jxlImages.js draws a banner picture for browsers that can't show JPEG XL.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // "2.X", "Graduate (X)" or "Not HTX"
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/profile.php'; // banner, status and education badge

const PROFILE_ROLE_ADMIN = 2; // userRole in HBHub-Users

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
$statement = hbHubDatabase()->prepare('SELECT u.userId, u.userName, u.userDescription, u.userSettings, u.userAvatarAttachmentId,
        u.userRole, u.userStatus, u.userCreatedTimestamp, ' . hbHubProfileBannerIdSql('u') . ' AS bannerId,
        GREATEST(TIMESTAMPDIFF(SECOND, u.userCreatedTimestamp, NOW()), 0) AS memberSeconds,
        GREATEST(TIMESTAMPDIFF(SECOND, u.userLastSeenTimestamp, NOW()), 0) AS lastSeenSeconds
    FROM `HBHub-Users` u WHERE u.userId = ? AND (u.userStatus IS NULL OR u.userStatus <> ?)');
$statement->execute([(int) $profileUserId, HBHUB_SESSION_STATUS_BANNED]);
$profile = $statement->fetch();
if ($profile === false) {
    require $_SERVER['DOCUMENT_ROOT'] . '/assets/php/statusCodesPages/404.php';
    exit;
}

$profileIsOwn = (int) $profile['userId'] === (int) $hbHubUser['userId'];
$profileIsAdmin = (int) $profile['userRole'] === PROFILE_ROLE_ADMIN;
$profileIsOnline = (int) $profile['lastSeenSeconds'] <= HBHUB_SESSION_ONLINE_SECONDS;
// The online dot's tooltip
$profilePresence = $profileIsOnline ? 'Online' : 'Offline for ' . profileTimeSpan((int) $profile['lastSeenSeconds']);
// A deleted account (userStatus NULL) and the system account that makes class chats can't be messaged
$profileCanMessage = !$profileIsOwn && $profile['userStatus'] !== null && (int) $profile['userId'] !== HBHUB_SYSTEM_USER_ID;

// Same shape as database/defaultUserSettings.json
$profileSettings = hbHubUserSettings($profile['userSettings']);
$profileClass = hbHubClassOf($profileSettings);
$profileEducation = hbHubProfileEducation($profileSettings);
$profileStatus = hbHubProfileStatus($profileSettings);
$profileHasStatus = $profileStatus['emoji'] !== '' || $profileStatus['text'] !== '';
$profileGradient = hbHubProfileBannerGradient($profileSettings);

$profileAvatarSrc = hbHubAvatarUrl((int) $profile['userId'], $profile['userAvatarAttachmentId'] !== null ? (int) $profile['userAvatarAttachmentId'] : null);
$profileBannerSrc = $profile['bannerId'] !== null ? hbHubProfileBannerUrl((int) $profile['userId'], (int) $profile['bannerId']) : null;
$profileJxlWorker = '/assets/js/chatJxlWorker.js?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chatJxlWorker.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/profile.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/profile.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/profile.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/profile.js') ?>" defer></script>
    <?php if ($profileBannerSrc !== null): ?>
        <script src="https://hbhub.noahgajnielsen.dk/assets/js/jxlImages.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/jxlImages.js') ?>" defer></script>
    <?php endif; ?>
    <title><?= htmlspecialchars($profile['userName']) ?> - HB Hub</title>
    <meta name="description" content="Profile page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="profile">
        <article class="profile-card" aria-labelledby="profile-name">
            <!-- The banner: a picture, or the colors they picked as "#a1b2c3 #d4e5f6 90" (profile.js paints them).
                 Without either, the site's own colors from profile.css. -->
            <div class="profile-banner"<?= $profileBannerSrc === null && $profileGradient !== null ? ' data-profile-gradient="' . htmlspecialchars(implode(' ', $profileGradient)) . '"' : '' ?>>
                <?php if ($profileBannerSrc !== null): ?>
                    <img class="profile-banner-picture" src="<?= htmlspecialchars($profileBannerSrc) ?>" alt="" data-jxl data-jxl-worker="<?= htmlspecialchars($profileJxlWorker) ?>">
                <?php endif; ?>
            </div>

            <div class="profile-body">
                <div class="profile-top">
                    <div class="profile-avatar-wrap">
                        <?php if ($profileAvatarSrc !== null): ?>
                            <img class="profile-avatar" src="<?= htmlspecialchars($profileAvatarSrc) ?>" alt="Profile picture of <?= htmlspecialchars($profile['userName']) ?>">
                        <?php else: ?>
                            <span class="profile-avatar profile-avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($profile['userName'], 0, 1))) ?></span>
                        <?php endif; ?>
                        <!-- Hovering (or tapping, as it can take focus) shows "Online" or "Offline for 5 hours" over it,
                             from data-tooltip in profile.css -->
                        <span class="profile-status-dot<?= $profileIsOnline ? ' is-online' : '' ?>" tabindex="0" role="img" aria-label="<?= $profilePresence ?>" data-tooltip="<?= $profilePresence ?>"></span>
                    </div>

                    <?php if ($profileHasStatus): ?>
                        <!-- Their status, in a bubble pointing at the picture -->
                        <p class="profile-status">
                            <?php if ($profileStatus['emoji'] !== ''): ?>
                                <span class="profile-status-emoji"><?= htmlspecialchars($profileStatus['emoji']) ?></span>
                            <?php endif; ?>
                            <?php if ($profileStatus['text'] !== ''): ?>
                                <span class="profile-status-text"><?= htmlspecialchars($profileStatus['text']) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <div class="profile-actions">
                        <?php if ((int) $hbHubUser['userRole'] === PROFILE_ROLE_ADMIN && !$profileIsOwn): ?>
                            <a class="profile-btn" href="/admin/user?user=<?= (int) $profile['userId'] ?>">Admin panel</a>
                        <?php endif; ?>
                        <?php if ($profileCanMessage): ?>
                            <!-- Starts a DM with them, or opens the one there is (chat/index.php, like New chat) -->
                            <form action="/chat/" method="post">
                                <input type="hidden" name="action" value="create">
                                <input type="hidden" name="users[]" value="<?= (int) $profile['userId'] ?>">
                                <button type="submit" class="profile-btn is-primary">
                                    <img src="/assets/images/icons/chat.png" alt="">
                                    Message
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($profileIsOwn): ?>
                            <!-- The same icon as next to your name in the chat -->
                            <a class="profile-settings" href="/settings#profile" aria-label="Settings" title="Settings">
                                <img src="/assets/images/icons/settings.png" alt="">
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <h1 class="profile-name" id="profile-name"><?= htmlspecialchars($profile['userName']) ?></h1>

                <?php if ($profileIsAdmin || $profileClass !== null || $profileEducation !== null): ?>
                    <ul class="profile-badges" aria-label="Badges">
                        <?php if ($profileIsAdmin): ?>
                            <li class="profile-badge is-admin"><img src="/assets/images/icons/admin.svg" alt="">Site admin</li>
                        <?php endif; ?>
                        <?php if ($profileClass !== null): ?>
                            <li class="profile-badge" title="Class"><span aria-hidden="true">🎓</span><?= htmlspecialchars($profileClass['label']) ?></li>
                        <?php endif; ?>
                        <?php if ($profileEducation !== null): ?>
                            <li class="profile-badge" title="Education"><span aria-hidden="true"><?= $profileEducation[1] ?></span><?= htmlspecialchars($profileEducation[2]) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>

                <section class="profile-section" aria-labelledby="profile-bio-heading">
                    <h2 id="profile-bio-heading">Bio</h2>
                    <?php if ($profile['userDescription'] !== null && trim($profile['userDescription']) !== ''): ?>
                        <p class="profile-bio"><?= htmlspecialchars($profile['userDescription']) ?></p>
                    <?php else: ?>
                        <p class="profile-bio is-empty"><?= $profileIsOwn ? 'You haven\'t written a bio yet. Add one in settings.' : 'No bio yet.' ?></p>
                    <?php endif; ?>
                </section>

                <p class="profile-joined">
                    Joined <?= date('j F Y', strtotime($profile['userCreatedTimestamp'])) ?>
                    &middot; a member for <?= profileTimeSpan((int) $profile['memberSeconds']) ?>
                </p>
            </div>
        </article>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
