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

$onboardingYears = ['1', '2', '3'];
$onboardingClasses = ['T', 'Y', 'X', 'U'];
$onboardingAvatarTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

// Requirement modes used by the onboarding_*_requirements settings
const ONBOARDING_REQUIRED = 0;
const ONBOARDING_OPTIONAL = 1;
const ONBOARDING_DISABLED = 2;

// Limits are capped to what the database columns can hold
$onboardingNameMax = max(1, min(64, (int) $siteConfig['user_name_maxSize']));
$onboardingDescriptionMax = max(1, min(255, (int) $siteConfig['user_description_maxSize']));
$onboardingDescriptionMin = 10; // only when the description is required
$onboardingAvatarMaxBytes = max(1, (int) $siteConfig['user_avatar_maxSize']) * 1000; // setting is in KB
$onboardingAvatarMaxLabel = rtrim(rtrim(number_format($onboardingAvatarMaxBytes / 1000000, 1, '.', ''), '0'), '.') . ' MB';

$onboardingAvatarMode = (int) $siteConfig['onboarding_avatar_requirements'];
if ((int) $siteConfig['site_save_diskspace'] === 2) {
    $onboardingAvatarMode = ONBOARDING_DISABLED; // user uploads are turned off
}
$onboardingDescriptionMode = (int) $siteConfig['onboarding_description_requirements'];

// 1 = just don't use a common password, 2 = weak, 3 = medium, 4 = strong
$onboardingPasswordPolicies = [
    1 => ['minLength' => 1, 'charTypes' => 0],
    2 => ['minLength' => 8, 'charTypes' => 0],
    3 => ['minLength' => 8, 'charTypes' => 3],
    4 => ['minLength' => 12, 'charTypes' => 4],
];
$onboardingPasswordPolicy = $onboardingPasswordPolicies[(int) $siteConfig['onboarding_password_requirements']] ?? $onboardingPasswordPolicies[3];
$onboardingPasswordMin = $onboardingPasswordPolicy['minLength'];
$onboardingPasswordCharTypes = $onboardingPasswordPolicy['charTypes'];

// The questions shown, in order
$onboardingSteps = ['name', 'class'];
if ($onboardingAvatarMode !== ONBOARDING_DISABLED) $onboardingSteps[] = 'avatar';
if ($onboardingDescriptionMode !== ONBOARDING_DISABLED) $onboardingSteps[] = 'description';
$onboardingSteps[] = 'password';

$onboardingValues = ['name' => '', 'year' => '', 'class' => '', 'description' => ''];
$onboardingErrors = []; // step name => message

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

        // Name
        if ($onboardingValues['name'] === '') {
            $onboardingErrors['name'] = 'Please tell us what you should be known as.';
        } elseif (preg_match('/^[^\p{Cc}]+$/u', $onboardingValues['name']) !== 1) {
            $onboardingErrors['name'] = 'That name contains characters that can\'t be used.';
        } elseif (mb_strlen($onboardingValues['name']) > $onboardingNameMax) {
            $onboardingErrors['name'] = "Please keep it to $onboardingNameMax characters or fewer.";
        }

        // Class
        if (!in_array($onboardingValues['year'], $onboardingYears, true)) {
            $onboardingErrors['class'] = 'Please choose your year.';
        } elseif (!in_array($onboardingValues['class'], $onboardingClasses, true)) {
            $onboardingErrors['class'] = 'Please choose your class.';
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
        if (preg_match('/^[^\p{Cc}]*$/u', str_replace("\n", '', $onboardingValues['description'])) !== 1) {
            $onboardingErrors['description'] = 'Your description contains characters that can\'t be used.';
        } elseif ($descriptionLength > $onboardingDescriptionMax) {
            $onboardingErrors['description'] = "Please keep it to $onboardingDescriptionMax characters or fewer.";
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength === 0) {
            $onboardingErrors['description'] = 'Please write a little about yourself.';
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength < $onboardingDescriptionMin) {
            $onboardingErrors['description'] = "Please write at least $onboardingDescriptionMin characters.";
        }

        // Password (bcrypt only uses the first 72 bytes)
        $passwordCharTypes = (int) preg_match('/\p{Ll}/u', $password) + (int) preg_match('/\p{Lu}/u', $password)
            + (int) preg_match('/\p{N}/u', $password) + (int) preg_match('/[^\p{Ll}\p{Lu}\p{N}]/u', $password);
        $commonPasswords = file($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt', FILE_IGNORE_NEW_LINES) ?: [];
        $passwordIsCommon = in_array(mb_strtolower($password), array_map(fn ($line) => mb_strtolower(rtrim($line, "\r")), $commonPasswords), true);

        if ($password === '') {
            $onboardingErrors['password'] = 'Please choose a password.';
        } elseif (mb_strlen($password) < $onboardingPasswordMin) {
            $onboardingErrors['password'] = "Your password needs to be at least $onboardingPasswordMin characters.";
        } elseif (strlen($password) > 72) {
            $onboardingErrors['password'] = 'Your password is too long. Please keep it to 72 characters or fewer.';
        } elseif ($passwordCharTypes < $onboardingPasswordCharTypes) {
            $onboardingErrors['password'] = $onboardingPasswordCharTypes === 4
                ? 'Your password needs lowercase letters, uppercase letters, numbers and special characters.'
                : "Your password needs at least $onboardingPasswordCharTypes of: lowercase letters, uppercase letters, numbers and special characters.";
        } elseif ($passwordIsCommon) {
            $onboardingErrors['password'] = 'That password is too common. Please choose another one.';
        } elseif ($password !== $passwordConfirm) {
            $onboardingErrors['password'] = 'The passwords don\'t match.';
        }

        if (!$onboardingErrors) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/onboardingSaveToDB.php';
            $onboardingErrors = onboardingSaveToDB($onboardingValues, $password, $avatarBlob, $avatarType);
            if (!$onboardingErrors) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
                hbHubSetToast('onboarding');
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
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <script src="https://hbhub.noahgajnielsen.dk/assets/js/onboarding.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/onboarding.js') ?>" defer></script>
    <title>Onboarding - HB Hub</title>
    <meta name="description" content="Onboarding process for the HB Hub">
</head>
<body>
    <main>
        <form class="onboarding" action="/userMgmt/onboarding<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" enctype="multipart/form-data" data-start-step="<?= $onboardingStartStep ?>"
              data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
            <h1>Welcome to HB Hub!</h1>

            <div class="onboarding-progress" hidden>
                <p class="onboarding-counter">Step 1 of <?= count($onboardingSteps) ?></p>
                <div class="onboarding-progress-track"><div class="onboarding-progress-bar"></div></div>
            </div>

            <section class="onboarding-step" aria-labelledby="onboarding-q1">
                <h2 id="onboarding-q1"><label for="onboarding-name">What should you be known as?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="text" id="onboarding-name" name="name" maxlength="<?= $onboardingNameMax ?>" required autocomplete="username"
                           value="<?= htmlspecialchars($onboardingValues['name']) ?>" data-counter="onboarding-name-counter">
                </div>
                <p class="onboarding-hint"><span id="onboarding-name-counter"><?= mb_strlen($onboardingValues['name']) ?></span>/<?= $onboardingNameMax ?> characters</p>
                <?php if (isset($onboardingErrors['name'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['name']) ?></p>
                <?php endif; ?>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q2">
                <h2 id="onboarding-q2">Which class are you in?</h2>
                <div class="onboarding-field">
                    <label for="onboarding-year">Year</label>
                    <select id="onboarding-year" name="year" required>
                        <option value="" disabled<?= $onboardingValues['year'] === '' ? ' selected' : '' ?>>Choose your year</option>
                        <?php foreach ($onboardingYears as $year): ?>
                            <option value="<?= $year ?>"<?= $onboardingValues['year'] === $year ? ' selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="onboarding-field onboarding-reveal" data-class-field>
                    <label for="onboarding-class">Class</label>
                    <select id="onboarding-class" name="class" required>
                        <option value="" disabled<?= $onboardingValues['class'] === '' ? ' selected' : '' ?>>Choose your class</option>
                        <?php foreach ($onboardingClasses as $class): ?>
                            <option value="<?= $class ?>"<?= $onboardingValues['class'] === $class ? ' selected' : '' ?>><?= $class ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (isset($onboardingErrors['class'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['class']) ?></p>
                <?php endif; ?>
            </section>

            <?php if ($onboardingAvatarMode !== ONBOARDING_DISABLED): ?>
            <section class="onboarding-step" aria-labelledby="onboarding-q3">
                <h2 id="onboarding-q3">Upload a picture of yourself!<?php if ($onboardingAvatarMode === ONBOARDING_OPTIONAL): ?> <span class="onboarding-optional">(Optional)</span><?php endif; ?></h2>
                <div class="onboarding-avatar">
                    <img class="onboarding-avatar-preview is-placeholder" src="/assets/images/icons/user.png" alt="">
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
            </section>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn" data-action="back" hidden>Back</button>
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Next</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Create account</button>
            </div>

            <p class="userMgmt-switch">Already have an account? <a href="/userMgmt/login<?= htmlspecialchars(hbHubReturnQuery()) ?>">Go to login</a></p>
        </form>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
