<?php
// Checked here as well as in header.php, so no account is created while the site is in maintenance mode
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/maintenance.php';
$hbHubSessionOptional = true; // has to work without being logged in
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
$siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

// Already logged in, so go straight to where they were headed
if ($hbHubUser !== null) {
    header('Location: ' . hbHubReturnPath(), true, 302);
    exit;
}

// A banned account was used in this browser (session.php), so no new account is made here instead of the form
if (hbHubBannedCookieUserId() !== null):
    http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <title>Sign up - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main>
        <div class="login">
            <h1>You can't sign up here</h1>
            <p class="onboarding-hint">An account that was used on this device has been banned, so a new account can't be made from it.</p>
            <p class="userMgmt-switch">Already have an account? <a href="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>">Go to login</a></p>
        </div>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
<?php
    exit;
endif;

// GET /onboarding?checkName=... answers {"taken": true|false}, so onboarding.js can say a name is taken while they
// type. Only a hint: the unique userNameLower is what really stops two accounts sharing a name when saving.
if (isset($_GET['checkName'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => 'methodNotAllowed']);
        exit;
    }
    $checkName = is_string($_GET['checkName']) ? trim($_GET['checkName']) : '';
    if ($checkName === '' || mb_strlen($checkName) > 35) { // 35 is the most a name can be
        http_response_code(400);
        echo json_encode(['error' => 'invalidName']);
        exit;
    }
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/database.php';
    try {
        // Same lookup as the login (loginCheckDB.php); deleted accounts have userNameLower NULL, so they free their name
        $statement = hbHubDatabase()->prepare('SELECT 1 FROM `HBHub-Users` WHERE userNameLower = ? LIMIT 1');
        $statement->execute([mb_strtolower($checkName)]);
        echo json_encode(['taken' => $statement->fetchColumn() !== false]);
    } catch (PDOException $e) {
        error_log('Onboarding: could not check a name: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'databaseError']);
    }
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/schoolClass.php'; // the years and fields of study, and the class chat
$onboardingAvatarTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

// "Sign up with Discord" on the first step, when it's set up (assets/php/discord.php). Once they're back from Discord,
// $onboardingDiscordUser is who they are there: the form starts with its name, bio and picture, and the account is
// made linked to it. Nobody signs up with Discord alone: the rest of the form is filled in as usual.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/discord.php';
$onboardingDiscord = hbHubDiscordClient($siteConfig);
$onboardingHasDiscord = $onboardingDiscord !== null;
$onboardingDiscordUser = $onboardingHasDiscord ? hbHubDiscordSignupGet($onboardingDiscord) : null;

// GET /onboarding?discordForget: "Don't link it" - forgets the Discord account, and the form starts over without it
if (isset($_GET['discordForget'])) {
    hbHubDiscordSignupClear();
    header('Location: /onboarding' . hbHubReturnQuery(), true, 303);
    exit;
}

// Requirement modes used by the onboarding_*_requirements settings
const ONBOARDING_REQUIRED = 0;
const ONBOARDING_OPTIONAL = 1;
const ONBOARDING_DISABLED = 2;

// Limits are capped to what the database columns can hold
$onboardingNameMax = max(1, min(35, (int) $siteConfig['user_name_maxSize'])); // names are capped at 35
$onboardingDescriptionMax = max(1, min(255, (int) $siteConfig['user_description_maxSize']));
$onboardingDescriptionMin = 10; // only when the description is required
$onboardingAvatarMaxBytes = max(1, (int) $siteConfig['user_avatar_maxSize']) * 1000; // setting is in KB
$onboardingAvatarMaxLabel = rtrim(rtrim(number_format($onboardingAvatarMaxBytes / 1000000, 1, '.', ''), '0'), '.') . ' MB';

$onboardingAvatarMode = (int) $siteConfig['onboarding_avatar_requirements'];
if ((int) $siteConfig['site_save_diskspace'] === 2) {
    $onboardingAvatarMode = ONBOARDING_DISABLED; // user uploads are turned off
}
$onboardingDescriptionMode = (int) $siteConfig['onboarding_description_requirements'];

// GET /onboarding?discordAvatar: the Discord account's picture, for onboarding.js to put in the picture step as if it
// had been chosen (so it can still be removed or swapped). Fetched by the server, as the CSP keeps Discord's CDN out.
// 404 when there's no Discord account being signed up with, it has no picture or pictures are turned off.
if (isset($_GET['discordAvatar'])) {
    $discordAvatar = $onboardingDiscordUser !== null && $onboardingAvatarMode !== ONBOARDING_DISABLED
        ? hbHubDiscordAvatarFetch($onboardingDiscordUser) : null;
    $discordAvatarType = $discordAvatar !== null ? (new finfo(FILEINFO_MIME_TYPE))->buffer($discordAvatar) : null;
    header('Cache-Control: no-store');
    if ($discordAvatar === null || !in_array($discordAvatarType, $onboardingAvatarTypes, true) || strlen($discordAvatar) > $onboardingAvatarMaxBytes) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $discordAvatarType);
    echo $discordAvatar;
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/password.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/account.php'; // the name rules and the allow list of characters
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/urlBlacklist.php'; // IP loggers and other links that can't be in a bio
$onboardingPasswordPolicy = hbHubPasswordPolicy($siteConfig);
$onboardingPasswordMin = $onboardingPasswordPolicy['minLength'];
$onboardingPasswordCharTypes = $onboardingPasswordPolicy['charTypes'];

// The questions shown, in order
$onboardingSteps = ['name', 'class'];
if ($onboardingAvatarMode !== ONBOARDING_DISABLED) $onboardingSteps[] = 'avatar';
if ($onboardingDescriptionMode !== ONBOARDING_DISABLED) $onboardingSteps[] = 'description';
$onboardingSteps[] = 'password';

$onboardingValues = ['name' => '', 'year' => '', 'class' => '', 'description' => ''];
$onboardingErrors = []; // step name => message

// Back from Discord: start with its display name (or username, when the display name breaks the name rules), and its
// bio when Discord sent one that's allowed here. Only a start - they're normal fields, so they can change both.
if ($onboardingDiscordUser !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ([$onboardingDiscordUser['displayName'] ?? '', $onboardingDiscordUser['name'] ?? ''] as $discordName) {
        $discordName = is_string($discordName) ? mb_substr(trim($discordName), 0, $onboardingNameMax) : '';
        if ($discordName !== '' && hbHubNameError($discordName, $onboardingNameMax) === null) {
            $onboardingValues['name'] = $discordName;
            break;
        }
    }
    $discordBio = is_string($onboardingDiscordUser['bio'] ?? null) ? mb_substr(trim($onboardingDiscordUser['bio']), 0, $onboardingDescriptionMax) : '';
    if ($onboardingDescriptionMode !== ONBOARDING_DISABLED && hbHubIsLatinText($discordBio, true) && !hbHubUrlBlacklisted($discordBio)) {
        $onboardingValues['description'] = str_replace("\r\n", "\n", $discordBio);
    }
}

// Why signing up with Discord didn't work, from /discord (userMgmt/discord.php)
$onboardingDiscordError = $onboardingHasDiscord && $onboardingDiscordUser === null && ($_GET['discord'] ?? null) === 'failed'
    ? 'Signing up with Discord didn\'t work. Please try again, or fill in the form yourself.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Body was larger than post_max_size, so PHP dropped all of it - the picture is the only thing that big
        if (in_array('avatar', $onboardingSteps, true)) {
            $onboardingErrors['avatar'] = 'That picture is too big. Please choose a smaller one.';
        } else {
            $onboardingErrors['name'] = 'Something went wrong. Please try again.';
        }
    } else {
        foreach (array_keys($onboardingValues) as $key) {
            $value = $_POST[$key] ?? '';
            $onboardingValues[$key] = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
        }
        if ($onboardingDescriptionMode === ONBOARDING_DISABLED) {
            $onboardingValues['description'] = '';
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['passwordConfirm'] ?? null) ? $_POST['passwordConfirm'] : '';

        // Name - the same rules as in settings (account.php), including the allow list of characters
        if (($nameError = hbHubNameError($onboardingValues['name'], $onboardingNameMax)) !== null) {
            $onboardingErrors['name'] = $nameError;
        }

        // Class - the field of study only for 1. to 3. year, not for Not HTX
        if (($classError = hbHubClassError($onboardingValues['year'], $onboardingValues['class'])) !== null) {
            $onboardingErrors['class'] = $classError;
        }

        // Picture
        $avatar = $onboardingAvatarMode === ONBOARDING_DISABLED ? null : ($_FILES['avatar'] ?? null);
        $avatarBlob = null;
        $avatarType = null;
        if (is_array($avatar) && is_int($avatar['error'] ?? null) && $avatar['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($avatar['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                || ($avatar['error'] === UPLOAD_ERR_OK && $avatar['size'] > $onboardingAvatarMaxBytes)) {
                $onboardingErrors['avatar'] = "That picture is too big. Please choose one under $onboardingAvatarMaxLabel.";
            } elseif ($avatar['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($avatar['tmp_name'])) {
                $onboardingErrors['avatar'] = 'Something went wrong uploading your picture. Please try again.';
            } elseif (!in_array($avatarType = (new finfo(FILEINFO_MIME_TYPE))->file($avatar['tmp_name']), $onboardingAvatarTypes, true)
                || getimagesize($avatar['tmp_name']) === false) {
                $onboardingErrors['avatar'] = 'Please upload a PNG, JPEG, GIF or WebP image.';
            } else {
                $avatarBlob = file_get_contents($avatar['tmp_name']);
            }
        } elseif ($onboardingAvatarMode === ONBOARDING_REQUIRED) {
            $onboardingErrors['avatar'] = 'Please upload a picture of yourself.';
        }

        // Description
        $descriptionLength = mb_strlen($onboardingValues['description']);
        if (!hbHubIsLatinText($onboardingValues['description'], true)) {
            $onboardingErrors['description'] = 'Your description can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji.';
        } elseif (hbHubUrlBlacklisted($onboardingValues['description'])) {
            $onboardingErrors['description'] = 'Woah, hold up! Your description has a dangerous link or a link that compromises people\'s privacy in it.';
        } elseif ($descriptionLength > $onboardingDescriptionMax) {
            $onboardingErrors['description'] = "Please keep it to $onboardingDescriptionMax characters or fewer.";
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength === 0) {
            $onboardingErrors['description'] = 'Please write a little about yourself.';
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength < $onboardingDescriptionMin) {
            $onboardingErrors['description'] = "Please write at least $onboardingDescriptionMin characters.";
        }

        // Password
        $passwordError = hbHubPasswordError($password, $passwordConfirm, $onboardingPasswordPolicy);
        if ($passwordError !== null) {
            $onboardingErrors['password'] = $passwordError;
        }

        if (!$onboardingErrors) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/onboardingSaveToDB.php';
            // A whole class signing up at school shares one address, so the default leaves room for that
            $onboardingAccountsPerHour = max(1, (int) ($siteConfig['onboarding_accounts_per_ip_hour'] ?? 20));
            $onboardingErrors = onboardingSaveToDB($onboardingValues, $password, $avatarBlob, $avatarType, $onboardingAccountsPerHour, $onboardingDiscordUser);
            if (!$onboardingErrors) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
                hbHubSetToast($onboardingDiscordUser !== null ? 'onboardingDiscord' : 'onboarding');
                if ($onboardingDiscordUser !== null) {
                    hbHubDiscordSignupClear(); // linked now
                }
                header('Location: ' . hbHubReturnPath(), true, 303);
                exit;
            }
        }
    }
}

// Open on the first question with an error
$onboardingStartStep = 1;
foreach ($onboardingSteps as $index => $step) {
    if (isset($onboardingErrors[$step])) {
        $onboardingStartStep = $index + 1;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <script src="/assets/js/onboarding.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/onboarding.js') ?>" defer></script>
    <title>Sign up - HB Hub</title>
    <meta name="description" content="Make a free HB Hub account: you're put in your HTX class's group chat at HANSENBERG, and get DMs, a casino with play chips, and Matador, chess and UNO.">
</head>
<body>
    <main>
        <form class="onboarding" action="/onboarding<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" enctype="multipart/form-data" data-start-step="<?= $onboardingStartStep ?>"
              <?php if ($onboardingDiscordUser !== null && ($onboardingDiscordUser['avatar'] ?? null) !== null && $onboardingAvatarMode !== ONBOARDING_DISABLED && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>data-discord-avatar="/onboarding?discordAvatar=1"<?php endif; ?>
              data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
            <div class="onboarding-header">
                <img class="onboarding-logo" src="/assets/images/favicon.svg" alt="HB Hub logo">
                <div>
                    <h1>Welcome to HB Hub!</h1>
                    <p class="onboarding-intro">Let's set up your account. It only takes a minute.</p>
                </div>
            </div>

            <!-- One segment per question, filled in by onboarding.js -->
            <div class="onboarding-progress" hidden>
                <p class="onboarding-counter">Step 1 of <?= count($onboardingSteps) ?></p>
                <ol class="onboarding-progress-steps" aria-hidden="true">
                    <?php foreach ($onboardingSteps as $step): ?><li></li><?php endforeach; ?>
                </ol>
            </div>

            <section class="onboarding-step" aria-labelledby="onboarding-q1">
                <h2 id="onboarding-q1"><label for="onboarding-name">Choose a username</label></h2>
                <p class="onboarding-hint" id="onboarding-name-about">
                    Everyone on HB Hub sees you by it, and you log in with it. It can be your name, a nickname or anything
                    else.
                </p>
                <div class="onboarding-input-wrap">
                    <!-- autocomplete="username" so password managers save it with the password, but that also makes some
                         browsers suggest a saved email here - the check in onboarding.js catches that -->
                    <input type="text" id="onboarding-name" name="name" minlength="<?= HBHUB_USER_NAME_MIN ?>" maxlength="<?= $onboardingNameMax ?>" required autocomplete="username"
                           placeholder="e.g. Alex, alex_h or TheChessKing" aria-describedby="onboarding-name-about"
                           value="<?= htmlspecialchars($onboardingValues['name']) ?>" data-counter="onboarding-name-counter">
                </div>
                <p class="onboarding-hint"><span id="onboarding-name-counter"><?= mb_strlen($onboardingValues['name']) ?></span>/<?= $onboardingNameMax ?> characters (at least <?= HBHUB_USER_NAME_MIN ?>)</p>
                <p class="onboarding-warning" role="alert" data-name-email-warning hidden>
                    That looks like an email address. Pick a name or nickname instead - it's shown to everyone.
                </p>
                <!-- Shown by onboarding.js once it has checked the name half a second after they stop typing -->
                <p class="onboarding-error" role="alert" data-name-taken-error hidden>
                    That name is already taken. Please choose another one.
                </p>
                <?php if (isset($onboardingErrors['name'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['name']) ?></p>
                <?php endif; ?>
                <?php if ($onboardingDiscordUser !== null): ?>
                    <p class="onboarding-discord-linked">
                        <?= hbHubDiscordLogo() ?>
                        <span>Signing up with Discord as <strong><?= htmlspecialchars($onboardingDiscordUser['name'] ?? '') ?></strong>, so you can log in with it too. Your name, bio and picture are from Discord - change them as you like.
                        <a href="/onboarding<?= htmlspecialchars(hbHubReturnQuery() === '' ? '?discordForget=1' : hbHubReturnQuery() . '&discordForget=1') ?>">Don't link it</a></span>
                    </p>
                <?php elseif ($onboardingHasDiscord): ?>
                    <p class="userMgmt-or"><span>or</span></p>
                    <!-- Belongs to the form below this one, so it doesn't need anything filled in -->
                    <button type="submit" class="onboarding-btn userMgmt-discord" form="onboarding-discord"><?= hbHubDiscordLogo() ?>Sign up with Discord</button>
                    <?php if ($onboardingDiscordError !== null): ?>
                        <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingDiscordError) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q2">
                <h2 id="onboarding-q2">Which class are you in?</h2>
                <!-- Radio buttons drawn as tiles; the form sends the same year/class values the dropdowns used to -->
                <fieldset class="onboarding-choice">
                    <legend>Year</legend>
                    <div class="onboarding-tiles onboarding-tiles-year">
                        <?php foreach (HBHUB_CLASS_YEARS as $year): ?>
                            <label class="onboarding-tile">
                                <input type="radio" name="year" value="<?= $year ?>" required<?= $onboardingValues['year'] === $year ? ' checked' : '' ?>>
                                <span class="onboarding-tile-face"><?= $year ?>.<span class="onboarding-tile-sub">year</span></span>
                            </label>
                        <?php endforeach; ?>
                        <label class="onboarding-tile">
                            <input type="radio" name="year" value="<?= HBHUB_CLASS_NOT_HTX ?>" required<?= $onboardingValues['year'] === HBHUB_CLASS_NOT_HTX ? ' checked' : '' ?>>
                            <span class="onboarding-tile-face onboarding-tile-face-text">Not HTX</span>
                        </label>
                    </div>
                </fieldset>
                <!-- Only for 1. to 3. year: hidden by onboarding.js until one of them is picked. Not required in the markup,
                     so Not HTX can be sent without JS; the server checks it. -->
                <fieldset class="onboarding-choice onboarding-reveal" data-class-field>
                    <legend>Field of study</legend>
                    <div class="onboarding-tiles onboarding-tiles-class">
                        <?php foreach (HBHUB_CLASS_FIELDS as $field): ?>
                            <label class="onboarding-tile">
                                <input type="radio" name="class" value="<?= $field ?>"<?= $onboardingValues['class'] === $field ? ' checked' : '' ?>>
                                <span class="onboarding-tile-face"><?= $field ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php if (isset($onboardingErrors['class'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['class']) ?></p>
                <?php endif; ?>
            </section>

            <?php if ($onboardingAvatarMode !== ONBOARDING_DISABLED): ?>
            <section class="onboarding-step" aria-labelledby="onboarding-q3">
                <h2 id="onboarding-q3">Upload a picture of yourself!<?php if ($onboardingAvatarMode === ONBOARDING_OPTIONAL): ?> <span class="onboarding-optional">(Optional)</span><?php endif; ?></h2>
                <div class="onboarding-avatar">
                    <!-- The picture itself opens the file picker too -->
                    <label for="onboarding-avatar" class="onboarding-avatar-frame">
                        <img class="onboarding-avatar-preview is-placeholder" src="/assets/images/icons/user.png" alt="Your profile picture">
                    </label>
                    <div class="onboarding-avatar-actions">
                        <input type="file" id="onboarding-avatar" name="avatar" class="onboarding-file-input"<?= $onboardingAvatarMode === ONBOARDING_REQUIRED ? ' required' : '' ?>
                               accept="<?= implode(',', $onboardingAvatarTypes) ?>" data-max-bytes="<?= $onboardingAvatarMaxBytes ?>" data-max-label="<?= $onboardingAvatarMaxLabel ?>">
                        <label for="onboarding-avatar" class="onboarding-btn">Choose picture</label>
                        <button type="button" class="onboarding-btn" data-action="remove-avatar" hidden>Remove</button>
                    </div>
                </div>
                <p class="onboarding-hint">PNG, JPEG, GIF or WebP, up to <?= $onboardingAvatarMaxLabel ?>.</p>
                <p class="onboarding-error" role="alert" data-avatar-error<?= isset($onboardingErrors['avatar']) ? '' : ' hidden' ?>><?= htmlspecialchars($onboardingErrors['avatar'] ?? '') ?></p>
            </section>
            <?php endif; ?>

            <?php if ($onboardingDescriptionMode !== ONBOARDING_DISABLED): ?>
            <section class="onboarding-step" aria-labelledby="onboarding-q4">
                <h2 id="onboarding-q4"><label for="onboarding-description">Describe yourself</label><?php if ($onboardingDescriptionMode === ONBOARDING_OPTIONAL): ?> <span class="onboarding-optional">(Optional)</span><?php endif; ?></h2>
                <textarea id="onboarding-description" name="description" maxlength="<?= $onboardingDescriptionMax ?>" rows="5"
                          <?= $onboardingDescriptionMode === ONBOARDING_REQUIRED ? 'required data-min-length="' . $onboardingDescriptionMin . '"' : '' ?>
                          data-counter="onboarding-description-counter"><?= htmlspecialchars($onboardingValues['description']) ?></textarea>
                <p class="onboarding-hint">
                    <span id="onboarding-description-counter"><?= mb_strlen($onboardingValues['description']) ?></span>/<?= $onboardingDescriptionMax ?> characters<?= $onboardingDescriptionMode === ONBOARDING_REQUIRED ? " (at least $onboardingDescriptionMin)" : '' ?>
                </p>
                <?php if (isset($onboardingErrors['description'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['description']) ?></p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="onboarding-step" aria-labelledby="onboarding-q5">
                <h2 id="onboarding-q5">Last but not least, what should your password be?</h2>
                <div class="onboarding-field">
                    <label for="onboarding-password">Password</label>
                    <div class="onboarding-input-wrap">
                        <input type="password" id="onboarding-password" name="password" minlength="<?= $onboardingPasswordMin ?>" maxlength="72" required autocomplete="new-password"
                               aria-describedby="onboarding-password-rules" data-min-length="<?= $onboardingPasswordMin ?>" data-char-types="<?= $onboardingPasswordCharTypes ?>">
                    </div>
                </div>
                <div class="onboarding-field onboarding-reveal" data-password-confirm-field>
                    <label for="onboarding-password-confirm">Repeat password</label>
                    <div class="onboarding-input-wrap">
                        <input type="password" id="onboarding-password-confirm" name="passwordConfirm" required autocomplete="new-password">
                    </div>
                </div>
                <div class="onboarding-strength" data-level="none">
                    <div class="onboarding-strength-track"><div class="onboarding-strength-bar"></div></div>
                    <p class="onboarding-strength-label" aria-live="polite"></p>
                </div>
                <div id="onboarding-password-rules"<?= $onboardingPasswordMin > 1 || $onboardingPasswordCharTypes > 0 ? '' : ' hidden' ?>>
                    <?php if ($onboardingPasswordMin > 1): ?>
                    <ul class="onboarding-checklist">
                        <li data-check="length">At least <?= $onboardingPasswordMin ?> characters</li>
                    </ul>
                    <?php endif; ?>
                    <?php if ($onboardingPasswordCharTypes > 0): ?>
                    <p class="onboarding-hint"><?= $onboardingPasswordCharTypes === 4 ? 'All of these:' : "At least $onboardingPasswordCharTypes of these:" ?></p>
                    <ul class="onboarding-checklist">
                        <li data-check="lower">Lowercase letter (a-z)</li>
                        <li data-check="upper">Uppercase letter (A-Z)</li>
                        <li data-check="number">Number (0-9)</li>
                        <li data-check="special">Special character (!, ?, # ...)</li>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php if (isset($onboardingErrors['password'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['password']) ?></p>
                <?php endif; ?>
                <p class="onboarding-hint">By creating an account, you agree to the <a href="/legal/terms" target="_blank">Terms of Service</a> and the <a href="/legal/privacy" target="_blank">Privacy Policy</a>.</p>
            </section>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn" data-action="back" hidden>Back</button>
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Next</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Create account</button>
            </div>

            <p class="userMgmt-switch">Already have an account? <a href="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>">Go to login</a></p>
        </form>
        <?php if ($onboardingHasDiscord && $onboardingDiscordUser === null): ?>
        <form id="onboarding-discord" action="/discord<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" hidden>
            <input type="hidden" name="action" value="onboarding">
        </form>
        <?php endif; ?>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
