<?php
// Profile page: /profile?user=id shows that user, without ?user= it shows the logged in user.
// Banned users don't have a profile (the same as in the chat's user search).
// A card with the banner along the top (a picture, or the colors they picked: assets/php/profile.php), the profile
// picture over its bottom edge with a dot for online (hover it for how long they've been offline), their status next to
// it, and on the right a Message button (someone else) or the settings cog (your own). Under it the name, badges
// (Site admin, class, education, and Engine Crew for giving parts to Ore Mining Inc.'s Cosmic Engine once its Crew Deck
// is built), the bio and when they joined. Everything on it is changed in settings (otherPages/settings.php).
// On your own profile the status can be clicked to change it in a popup (or, without a status, a greyed out "Click here
// to add a status!" only you see). The popup is a form POSTed back here with action=status (and clear=1 for Clear
// status), which saves only the status and comes back with a toast. Without JS the status links to settings instead.
// profile.js paints the banner's colors, as the content security policy doesn't allow style attributes, and opens the
// popup; statusForm.js adds its emoji row. jxlImages.js draws a banner picture for browsers that can't show JPEG XL.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // "2.X", "Graduate (X)" or "Not HTX"
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/profile.php'; // banner, status and education badge
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php'; // the Engine Crew badge (engine.php)

const PROFILE_ROLE_ADMIN = 2; // userRole in HBHub-Users

// Saving your status from the popup. A wrong one shows your profile again with the popup open and the error in it.
$profileStatusError = null;
$profileStatusForm = null; // what was typed in, when it's shown again: [emoji, text, clearAfter]
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? null) !== 'status') {
        http_response_code(400);
        exit;
    }
    $statement = hbHubDatabase()->prepare('SELECT userSettings FROM `HBHub-Users` WHERE userId = ?');
    $statement->execute([(int) $hbHubUser['userId']]);
    $currentStatus = hbHubProfileStatus(hbHubUserSettings($statement->fetchColumn() ?: null));

    $postValue = static fn (string $key): string => is_string($_POST[$key] ?? null) ? trim(str_replace("\r\n", "\n", $_POST[$key])) : '';
    $isClear = ($_POST['clear'] ?? null) === '1';
    $statusToSave = $isClear
        ? ['emoji' => '', 'text' => '', 'expires' => null]
        : hbHubProfileStatusFromForm($postValue('statusEmoji'), $postValue('statusText'), $postValue('statusClearAfter'), $currentStatus);

    if (is_string($statusToSave)) {
        $profileStatusError = $statusToSave;
        $profileStatusForm = [$postValue('statusEmoji'), $postValue('statusText'), $postValue('statusClearAfter')];
    } else {
        hbHubProfileSaveStatus(hbHubDatabase(), (int) $hbHubUser['userId'], $statusToSave);
        require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
        hbHubSetToast(hbHubProfileHasStatus($statusToSave) ? 'statusSaved' : 'statusCleared');
        header('Location: /profile', true, 303);
        exit;
    }
}

// Only your own profile can be POSTed to, so a status error always shows yours
$profileUserId = $profileStatusError === null ? ($_GET['user'] ?? (string) $hbHubUser['userId']) : (string) $hbHubUser['userId'];
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
$profilePresence = $profileIsOnline ? 'Online' : 'Offline for ' . hbHubProfileTimeSpan((int) $profile['lastSeenSeconds']);
// A deleted account (userStatus NULL) and the system account that makes class chats can't be messaged
$profileCanMessage = !$profileIsOwn && $profile['userStatus'] !== null && (int) $profile['userId'] !== HBHUB_SYSTEM_USER_ID;

// Same shape as database/defaultUserSettings.json
$profileSettings = hbHubUserSettings($profile['userSettings']);
$profileClass = hbHubClassOf($profileSettings);
$profileEducation = hbHubProfileEducation($profileSettings);
$profileStatus = hbHubProfileStatus($profileSettings);
$profileHasStatus = hbHubProfileHasStatus($profileStatus);
// The popup's fields: what was typed in after an error, otherwise the status you have now
[$profileStatusEmoji, $profileStatusText, $profileStatusClearAfter] = $profileStatusForm ?? [$profileStatus['emoji'], $profileStatus['text'], null];
$profileGradient = hbHubProfileBannerGradient($profileSettings);
$profileEngineCrew = hbHubMinerEngineCrew(hbHubDatabase(), (int) $profile['userId']);
$profileIgnitionCrew = $profileEngineCrew && hbHubMinerEngineIgnitionCrew(hbHubDatabase(), (int) $profile['userId']);

$profileAvatarSrc = hbHubAvatarUrl((int) $profile['userId'], $profile['userAvatarAttachmentId'] !== null ? (int) $profile['userAvatarAttachmentId'] : null);
$profileBannerSrc = $profile['bannerId'] !== null ? hbHubProfileBannerUrl((int) $profile['userId'], (int) $profile['bannerId']) : null;
$profileJxlWorker = '/assets/js/chatJxlWorker.js?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chatJxlWorker.js');
// Opened from a leaderboard (?from=, see hbHubProfileUrl()): a link back to it over the card
$profileBack = hbHubProfileBackLink($_GET['from'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/profile.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/profile.css') ?>">
    <script src="/assets/js/profile.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/profile.js') ?>" defer></script>
    <?php if ($profileIsOwn): ?>
        <script src="/assets/js/statusForm.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/statusForm.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($profileBannerSrc !== null): ?>
        <script src="/assets/js/jxlImages.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/jxlImages.js') ?>" defer></script>
    <?php endif; ?>
    <title><?= htmlspecialchars($profile['userName']) ?> - HB Hub</title>
    <meta name="description" content="Profile page for the HB Hub">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="profile">
        <?php if ($profileBack !== null): ?>
            <a class="profile-back" href="<?= htmlspecialchars($profileBack[0]) ?>">&larr; Back to <?= htmlspecialchars($profileBack[1]) ?></a>
        <?php endif; ?>
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

                    <?php if ($profileIsOwn): ?>
                        <!-- Your own status, or a greyed out one to add one. profile.js opens the popup from it; without JS it's
                             a link to the status in settings. -->
                        <a class="profile-status is-own<?= $profileHasStatus ? '' : ' is-placeholder' ?>" href="/settings#settings-status-text"
                           aria-label="<?= $profileHasStatus ? 'Change your status' : 'Add a status' ?>" data-status-open>
                            <?php if ($profileHasStatus): ?>
                                <?php if ($profileStatus['emoji'] !== ''): ?>
                                    <span class="profile-status-emoji"><?= htmlspecialchars($profileStatus['emoji']) ?></span>
                                <?php endif; ?>
                                <?php if ($profileStatus['text'] !== ''): ?>
                                    <span class="profile-status-text"><?= htmlspecialchars($profileStatus['text']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="profile-status-text">Click here to add a status!</span>
                            <?php endif; ?>
                        </a>
                    <?php elseif ($profileHasStatus): ?>
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

                <?php if ($profileIsAdmin || $profileClass !== null || $profileEducation !== null || $profileEngineCrew): ?>
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
                        <?php if ($profileEngineCrew): ?>
                            <li class="profile-badge" title="Helped build Ore Mining Inc.'s Cosmic Engine"><span aria-hidden="true">⚙️</span>Engine Crew</li>
                        <?php endif; ?>
                        <?php if ($profileIgnitionCrew): ?>
                            <li class="profile-badge" title="Helped fill the Cosmic Engine's Ignition stage in Ore Mining Inc."><span aria-hidden="true">🚀</span>Ignition</li>
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
                    &middot; a member for <?= hbHubProfileTimeSpan((int) $profile['memberSeconds']) ?>
                </p>
            </div>
        </article>

        <?php if ($profileIsOwn): ?>
            <!-- The status popup (the same fields as in settings). data-open opens it straight away, when saving it went wrong. -->
            <dialog class="profile-dialog" aria-labelledby="profile-status-heading" data-status-dialog<?= $profileStatusError !== null ? ' data-open' : '' ?>>
                <form class="profile-dialog-form" action="/profile" method="post">
                    <input type="hidden" name="action" value="status">
                    <div class="profile-dialog-header">
                        <h2 id="profile-status-heading"><?= $profileHasStatus ? 'Change your status' : 'Add a status' ?></h2>
                        <button type="button" class="profile-dialog-close" aria-label="Close" data-status-close>×</button>
                    </div>

                    <div class="profile-status-row">
                        <label class="profile-visually-hidden" for="profile-status-emoji">Status emoji</label>
                        <input type="text" id="profile-status-emoji" class="profile-status-emoji-input" name="statusEmoji" maxlength="16"
                               placeholder="😀" autocomplete="off" value="<?= htmlspecialchars($profileStatusEmoji) ?>" data-status-emoji>
                        <label class="profile-visually-hidden" for="profile-status-text">Status text</label>
                        <textarea id="profile-status-text" name="statusText" maxlength="<?= HBHUB_PROFILE_STATUS_TEXT_MAX ?>" rows="2"
                                  placeholder="What are you up to?" aria-describedby="profile-status-hint" data-status-text
                                  data-max-lines="<?= HBHUB_PROFILE_STATUS_LINES_MAX ?>"><?= htmlspecialchars($profileStatusText) ?></textarea>
                    </div>
                    <div class="profile-status-picks" data-status-picks hidden></div>

                    <div class="profile-status-clear">
                        <label for="profile-status-clear">Clear after</label>
                        <select id="profile-status-clear" name="statusClearAfter">
                            <?= hbHubProfileStatusClearOptions($profileStatus, $profileStatusClearAfter) ?>
                        </select>
                    </div>
                    <p class="profile-dialog-hint" id="profile-status-hint">
                        Remember, everyone can see it.
                    </p>
                    <?php if ($profileStatusError !== null): ?>
                        <p class="profile-dialog-error" role="alert"><?= htmlspecialchars($profileStatusError) ?></p>
                    <?php endif; ?>

                    <div class="profile-dialog-footer">
                        <?php if ($profileHasStatus): ?>
                            <button type="submit" class="profile-btn is-danger" name="clear" value="1" formnovalidate>Clear status</button>
                        <?php endif; ?>
                        <button type="button" class="profile-btn" data-status-close>Cancel</button>
                        <button type="submit" class="profile-btn is-primary">Save</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
