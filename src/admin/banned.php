<?php
// Admin panel's banned pictures: /admin/banned, the third list in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// The list of profile pictures that get an account banned straight away when it uploads one (assets/php/bannedAvatars.php).
// It shows every picture on it, newest first, ADMIN_BANNED_SHOWN at a time (?page=): the banned account it came from,
// or that an admin uploaded it, when and by whom it was added, and how many uploads it has caught.
// The pictures themselves come from admin/bannedPicture.php, loaded by pictureQueue.js two at a time (data-queued-picture).
// Banned banner pictures are JPEG XL, so those are loaded straight away instead, and drawn by jxlImages.js where the
// browser can't show them.
// Forms (action=): add uploads a picture to the list, remove takes one off (picture=id). Banning an account on its
// admin panel (admin/user.php) can also put its picture and banner picture here, and unbanning takes them off again.
// Adding and removing are logged in the admin log (assets/php/adminLog.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/bannedAvatars.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

const ADMIN_BANNED_SHOWN = 48; // per page
const ADMIN_BANNED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']; // the same as profile pictures, no SVG

adminOnly($hbHubUser);

$db = hbHubDatabase();
// A bigger one couldn't be uploaded as a profile picture anyway
$adminPictureMaxBytes = max(1, (int) $siteConfig['user_avatar_maxSize']) * 1000; // setting is in KB
$adminPictureMaxLabel = rtrim(rtrim(number_format($adminPictureMaxBytes / 1000000, 1, '.', ''), '0'), '.') . ' MB';
$adminPage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) && strlen($_GET['page']) <= 6 ? max(1, (int) $_GET['page']) : 1;
$adminError = null; // for the Add a picture card

/**
 * Back to the page (at the card that was used) with a toast, so reloading doesn't send the form again.
 */
function adminBannedRedirect(string $toast, string $card, int $page = 1): never
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    hbHubSetToast($toast);
    header('Location: /admin/banned' . ($page > 1 ? '?page=' . $page : '') . '#' . $card, true, 303);
    exit;
}

$adminAction = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) ? $_POST['action'] : null;

if ($adminAction === null && $_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Body was larger than post_max_size, so PHP dropped all of it - the picture is the only thing that big
    $adminError = 'That picture is too big. Please choose a smaller one.';
}

if ($adminAction === 'add') {
    $file = $_FILES['picture'] ?? null;
    if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $adminError = 'Please choose a picture.';
    } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || ($file['error'] === UPLOAD_ERR_OK && $file['size'] > $adminPictureMaxBytes)) {
        $adminError = "That picture is too big. Please choose one under $adminPictureMaxLabel.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $adminError = 'Something went wrong uploading the picture. Please try again.';
    } elseif (!in_array($fileType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), ADMIN_BANNED_TYPES, true)
        || getimagesize($file['tmp_name']) === false) {
        $adminError = 'Please upload a PNG, JPEG, GIF or WebP image.';
    } elseif (!hbHubBannedAvatarAdd($db, $fileType, file_get_contents($file['tmp_name']), (int) $hbHubUser['userId'])) {
        $adminError = 'That picture is already on the list.';
    } else {
        adminLog($db, (int) $hbHubUser['userId'], 'banned.add', null, null, ['pictureId' => (int) $db->lastInsertId()]);
        adminBannedRedirect('adminBannedPictureAdded', 'list');
    }
}

if ($adminAction === 'remove') {
    $pictureId = $_POST['picture'] ?? null;
    if (is_string($pictureId) && ctype_digit($pictureId) && strlen($pictureId) <= 10) {
        if (hbHubBannedAvatarRemove($db, (int) $pictureId)) {
            adminLog($db, (int) $hbHubUser['userId'], 'banned.remove', null, null, ['pictureId' => (int) $pictureId]);
        }
    }
    adminBannedRedirect('adminBannedPictureRemoved', 'list', $adminPage);
}

// The page of the list, without the files (admin/bannedPicture.php sends those), with the pictures' fingerprints
// worked out first, so the page can say which ones only catch the exact file
hbHubBannedAvatarFillFingerprints($db);
$adminSimilarity = rtrim(rtrim(number_format(hbHubBannedAvatarSimilarity(), 1, '.', ''), '0'), '.');
$adminTotal = (int) $db->query('SELECT COUNT(*) FROM `HBHub-BannedAvatars`')->fetchColumn();
$adminPages = max(1, intdiv($adminTotal + ADMIN_BANNED_SHOWN - 1, ADMIN_BANNED_SHOWN));
$adminPage = min($adminPage, $adminPages);
$adminPictures = $db->query('SELECT b.bannedAvatarId, b.bannedAvatarType, b.bannedAvatarFromUserId, fu.userName AS fromName,
        b.bannedAvatarAddedByUserId, au.userName AS addedByName, b.bannedAvatarMatchCount, b.bannedAvatarLastMatchTimestamp,
        b.bannedAvatarCreatedTimestamp, LENGTH(b.bannedAvatarFingerprints) > 0 AS catchesSimilar
    FROM `HBHub-BannedAvatars` b
    LEFT JOIN `HBHub-Users` fu ON fu.userId = b.bannedAvatarFromUserId
    LEFT JOIN `HBHub-Users` au ON au.userId = b.bannedAvatarAddedByUserId
    ORDER BY b.bannedAvatarId DESC
    LIMIT ' . ADMIN_BANNED_SHOWN . ' OFFSET ' . (($adminPage - 1) * ADMIN_BANNED_SHOWN))->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <script src="/assets/js/pictureQueue.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/pictureQueue.js') ?>" defer></script>
    <script src="/assets/js/jxlImages.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/jxlImages.js') ?>" defer></script>
    <title>Banned pictures - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- Users, chats, banned pictures or feedback, each with how many there are in all (new feedback only) -->
            <?= adminListsMenuHtml($db, 'banned') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <!-- Add a picture -->
                <section class="settings-card" id="add" aria-labelledby="admin-add-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-add-heading">Add a banned picture</h2>
                            <p class="settings-intro">
                                Any account that uploads one of these as its profile picture or banner, or signs up with it, is banned straight away.
                                Copies count too when they look at least <?= $adminSimilarity ?>% the same: resized, re-saved, or cropped by up to
                                about a fifth from any side or to a square. A heavier crop, a mirrored copy or one drawn over isn't caught, and a
                                very plain picture (one colour, a smooth gradient) only catches the exact same file, so it doesn't ban other plain
                                pictures. Site admins are never banned by it.
                                Banning an account puts its picture here too, and unbanning it takes the picture off again.
                            </p>
                        </div>
                    </div>
                    <form action="/admin/banned#add" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="settings-field">
                            <label class="settings-label" for="admin-banned-picture">Picture</label>
                            <input type="file" id="admin-banned-picture" name="picture" accept="<?= implode(',', ADMIN_BANNED_TYPES) ?>" required aria-describedby="admin-banned-picture-hint">
                            <p class="settings-hint" id="admin-banned-picture-hint">PNG, JPEG, GIF or WebP, up to <?= $adminPictureMaxLabel ?>.</p>
                            <?php if ($adminError !== null): ?>
                                <p class="settings-error" role="alert"><?= htmlspecialchars($adminError) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Add to banned pictures</button>
                        </div>
                    </form>
                </section>

                <!-- The list -->
                <section class="settings-card" id="list" aria-labelledby="admin-list-heading">
                    <h2 id="admin-list-heading">Banned pictures</h2>

                    <?php if ($adminPictures === []): ?>
                        <p class="settings-hint">There are no banned pictures yet.</p>
                    <?php else: ?>
                        <p class="settings-hint admin-list-count">
                            <?= number_format(($adminPage - 1) * ADMIN_BANNED_SHOWN + 1) ?>&ndash;<?= number_format(($adminPage - 1) * ADMIN_BANNED_SHOWN + count($adminPictures)) ?>
                            of <?= number_format($adminTotal) ?>
                        </p>
                        <ul class="admin-banned-grid">
                            <?php foreach ($adminPictures as $picture): ?>
                                <?php
                                $pictureId = (int) $picture['bannedAvatarId'];
                                $pictureSrc = '/admin/bannedPicture?picture=' . $pictureId;
                                $pictureMatches = (int) $picture['bannedAvatarMatchCount'];
                                ?>
                                <li class="admin-banned-item">
                                    <a class="admin-banned-image" href="<?= $pictureSrc ?>" target="_blank" rel="noopener">
                                        <?php if ($picture['bannedAvatarType'] === 'image/jxl'): ?>
                                            <!-- A banner picture: jxlImages.js draws it where the browser can't show JPEG XL -->
                                            <img src="<?= $pictureSrc ?>" alt="Banned banner picture <?= $pictureId ?>" loading="lazy" data-jxl
                                                 data-jxl-worker="/assets/js/chatJxlWorker.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/chatJxlWorker.js') ?>">
                                        <?php else: ?>
                                            <!-- pictureQueue.js loads it, two at a time (see there) -->
                                            <img data-queued-picture="<?= $pictureSrc ?>" alt="Banned picture <?= $pictureId ?>">
                                            <noscript><img src="<?= $pictureSrc ?>" alt="Banned picture <?= $pictureId ?>" loading="lazy"></noscript>
                                        <?php endif; ?>
                                    </a>
                                    <dl class="admin-banned-details">
                                        <div>
                                            <dt>From</dt>
                                            <dd><?php if ($picture['bannedAvatarFromUserId'] !== null): ?>
                                                <a class="admin-link" href="/admin/user?user=<?= (int) $picture['bannedAvatarFromUserId'] ?>"><?= htmlspecialchars($picture['fromName']) ?></a>
                                            <?php else: ?>
                                                Uploaded by an admin
                                            <?php endif; ?></dd>
                                        </div>
                                        <div>
                                            <dt>Added</dt>
                                            <dd><?= adminTime($picture['bannedAvatarCreatedTimestamp']) ?><?php if ($picture['bannedAvatarAddedByUserId'] !== null): ?>
                                                <span class="admin-small">by <a class="admin-link" href="/admin/user?user=<?= (int) $picture['bannedAvatarAddedByUserId'] ?>"><?= htmlspecialchars($picture['addedByName']) ?></a></span><?php endif; ?></dd>
                                        </div>
                                        <div>
                                            <dt>Catches</dt>
                                            <dd><?= $picture['catchesSimilar'] ? 'Similar copies too' : 'Only the exact file' ?></dd>
                                        </div>
                                        <div>
                                            <dt>Caught</dt>
                                            <dd><?= number_format($pictureMatches) ?> <?= $pictureMatches === 1 ? 'upload' : 'uploads' ?><?php if ($picture['bannedAvatarLastMatchTimestamp'] !== null): ?>
                                                <span class="admin-small">last <?= adminTime($picture['bannedAvatarLastMatchTimestamp']) ?></span><?php endif; ?></dd>
                                        </div>
                                    </dl>
                                    <form action="/admin/banned<?= $adminPage > 1 ? '?page=' . $adminPage : '' ?>#list" method="post"
                                          data-confirm="Remove this picture from the banned pictures? Accounts can upload it again without being banned.">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="picture" value="<?= $pictureId ?>">
                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-small">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($adminPages > 1): ?>
                        <nav class="admin-pages" aria-label="Pages">
                            <?php if ($adminPage > 1): ?>
                                <a class="settings-btn" href="/admin/banned<?= $adminPage > 2 ? '?page=' . ($adminPage - 1) : '' ?>#list" rel="prev">Previous</a>
                            <?php endif; ?>
                            <span class="admin-pages-text">Page <?= $adminPage ?> of <?= $adminPages ?></span>
                            <?php if ($adminPage < $adminPages): ?>
                                <a class="settings-btn" href="/admin/banned?page=<?= $adminPage + 1 ?>#list" rel="next">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
