<?php
$onboardingYears = ['1', '2', '3'];
$onboardingClasses = ['T', 'Y', 'X', 'U'];
$onboardingAvatarTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
$onboardingAvatarMaxBytes = 5 * 1024 * 1024;

$onboardingValues = ['name' => '', 'year' => '', 'class' => '', 'description' => ''];
$onboardingErrors = []; // step number => message

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Body was larger than post_max_size, so PHP dropped all of it - the picture is the only thing that big
        $onboardingErrors[3] = 'That picture is too big. Please choose a smaller one.';
    } else {
        foreach (array_keys($onboardingValues) as $key) {
            $value = $_POST[$key] ?? '';
            $onboardingValues[$key] = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['passwordConfirm'] ?? null) ? $_POST['passwordConfirm'] : '';

        // 1. Name
        if ($onboardingValues['name'] === '') {
            $onboardingErrors[1] = 'Please tell us what you should be known as.';
        } elseif (preg_match('/^[^\p{Cc}]+$/u', $onboardingValues['name']) !== 1) {
            $onboardingErrors[1] = 'That name contains characters that can\'t be used.';
        } elseif (mb_strlen($onboardingValues['name']) > 64) {
            $onboardingErrors[1] = 'Please keep it to 64 characters or fewer.';
        }

        // 2. Class
        if (!in_array($onboardingValues['year'], $onboardingYears, true)) {
            $onboardingErrors[2] = 'Please choose your year.';
        } elseif (!in_array($onboardingValues['class'], $onboardingClasses, true)) {
            $onboardingErrors[2] = 'Please choose your class.';
        }

        // 3. Picture (optional)
        $avatar = $_FILES['avatar'] ?? null;
        $avatarBlob = null;
        if (is_array($avatar) && is_int($avatar['error'] ?? null) && $avatar['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($avatar['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                || ($avatar['error'] === UPLOAD_ERR_OK && $avatar['size'] > $onboardingAvatarMaxBytes)) {
                $onboardingErrors[3] = 'That picture is too big. Please choose one under 5 MB.';
            } elseif ($avatar['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($avatar['tmp_name'])) {
                $onboardingErrors[3] = 'Something went wrong uploading your picture. Please try again.';
            } elseif (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($avatar['tmp_name']), $onboardingAvatarTypes, true)
                || getimagesize($avatar['tmp_name']) === false) {
                $onboardingErrors[3] = 'Please upload a PNG, JPEG, GIF or WebP image.';
            } else {
                $avatarBlob = file_get_contents($avatar['tmp_name']);
            }
        }

        // 4. Description (optional)
        if (preg_match('/^[^\p{Cc}]*$/u', str_replace("\n", '', $onboardingValues['description'])) !== 1) {
            $onboardingErrors[4] = 'Your description contains characters that can\'t be used.';
        } elseif (mb_strlen($onboardingValues['description']) > 255) {
            $onboardingErrors[4] = 'Please keep it to 255 characters or fewer.';
        }

        // 5. Password (bcrypt only uses the first 72 bytes)
        $passwordCharTypes = (int) preg_match('/\p{Ll}/u', $password) + (int) preg_match('/\p{Lu}/u', $password)
            + (int) preg_match('/\p{N}/u', $password) + (int) preg_match('/[^\p{Ll}\p{Lu}\p{N}]/u', $password);
        $commonPasswords = file($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt', FILE_IGNORE_NEW_LINES) ?: [];
        $passwordIsCommon = in_array(mb_strtolower($password), array_map(fn ($line) => mb_strtolower(rtrim($line, "\r")), $commonPasswords), true);

        if (mb_strlen($password) < 8) {
            $onboardingErrors[5] = 'Your password needs to be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $onboardingErrors[5] = 'Your password is too long. Please keep it to 72 characters or fewer.';
        } elseif ($passwordCharTypes < 3) {
            $onboardingErrors[5] = 'Your password needs at least 3 of: lowercase letters, uppercase letters, numbers and special characters.';
        } elseif ($passwordIsCommon) {
            $onboardingErrors[5] = 'That password is too common. Please choose another one.';
        } elseif ($password !== $passwordConfirm) {
            $onboardingErrors[5] = 'The passwords don\'t match.';
        }

        if (!$onboardingErrors) {
            // TODO: create the account - insert into `HBHub-Users` with password_hash($password, PASSWORD_DEFAULT),
            // store the description as NULL if it's empty, store $avatarBlob (if any) in `HBHub-Attachments`, save the class, then log in and redirect.
        }
    }
}

$onboardingStartStep = $onboardingErrors ? min(array_keys($onboardingErrors)) : 1;
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
        <form class="onboarding" action="/userMgmt/onboarding" method="post" enctype="multipart/form-data" data-start-step="<?= $onboardingStartStep ?>"
              data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
            <h1>Welcome to HB Hub!</h1>

            <div class="onboarding-progress" hidden>
                <p class="onboarding-counter">Step 1 of 5</p>
                <div class="onboarding-progress-track"><div class="onboarding-progress-bar"></div></div>
            </div>

            <section class="onboarding-step" aria-labelledby="onboarding-q1">
                <h2 id="onboarding-q1"><label for="onboarding-name">What should you be known as?</label></h2>
                <div class="onboarding-input-wrap">
                    <input type="text" id="onboarding-name" name="name" maxlength="64" required autocomplete="username"
                           value="<?= htmlspecialchars($onboardingValues['name']) ?>" data-counter="onboarding-name-counter">
                </div>
                <p class="onboarding-hint"><span id="onboarding-name-counter"><?= mb_strlen($onboardingValues['name']) ?></span>/64 characters</p>
                <?php if (isset($onboardingErrors[1])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors[1]) ?></p>
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
                <?php if (isset($onboardingErrors[2])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors[2]) ?></p>
                <?php endif; ?>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q3">
                <h2 id="onboarding-q3">Upload a picture of yourself! <span class="onboarding-optional">(Optional)</span></h2>
                <div class="onboarding-avatar">
                    <img class="onboarding-avatar-preview is-placeholder" src="/assets/images/icons/user.png" alt="">
                    <div class="onboarding-avatar-actions">
                        <input type="file" id="onboarding-avatar" name="avatar" class="onboarding-file-input"
                               accept="<?= implode(',', $onboardingAvatarTypes) ?>" data-max-bytes="<?= $onboardingAvatarMaxBytes ?>">
                        <label for="onboarding-avatar" class="onboarding-btn">Choose picture</label>
                        <button type="button" class="onboarding-btn" data-action="remove-avatar" hidden>Remove</button>
                    </div>
                </div>
                <p class="onboarding-hint">PNG, JPEG, GIF or WebP, up to 5 MB.</p>
                <p class="onboarding-error" role="alert" data-avatar-error<?= isset($onboardingErrors[3]) ? '' : ' hidden' ?>><?= htmlspecialchars($onboardingErrors[3] ?? '') ?></p>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q4">
                <h2 id="onboarding-q4"><label for="onboarding-description">Describe yourself</label> <span class="onboarding-optional">(Optional)</span></h2>
                <textarea id="onboarding-description" name="description" maxlength="255" rows="5"
                          data-counter="onboarding-description-counter"><?= htmlspecialchars($onboardingValues['description']) ?></textarea>
                <p class="onboarding-hint"><span id="onboarding-description-counter"><?= mb_strlen($onboardingValues['description']) ?></span>/255 characters</p>
                <?php if (isset($onboardingErrors[4])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors[4]) ?></p>
                <?php endif; ?>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q5">
                <h2 id="onboarding-q5">Last but not least, what should your password be?</h2>
                <div class="onboarding-field">
                    <label for="onboarding-password">Password</label>
                    <input type="password" id="onboarding-password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"
                           aria-describedby="onboarding-password-rules">
                </div>
                <div class="onboarding-field onboarding-reveal" data-password-confirm-field>
                    <label for="onboarding-password-confirm">Repeat password</label>
                    <input type="password" id="onboarding-password-confirm" name="passwordConfirm" required autocomplete="new-password">
                </div>
                <div class="onboarding-strength" data-level="none">
                    <div class="onboarding-strength-track"><div class="onboarding-strength-bar"></div></div>
                    <p class="onboarding-strength-label" aria-live="polite"></p>
                </div>
                <div id="onboarding-password-rules">
                    <ul class="onboarding-checklist">
                        <li data-check="length">At least 8 characters</li>
                    </ul>
                    <ul class="onboarding-checklist">
                        <li data-check="lower">Lowercase letter (a-z)</li>
                        <li data-check="upper">Uppercase letter (A-Z)</li>
                        <li data-check="number">Number (0-9)</li>
                        <li data-check="special">Special character (!, ?, # ...)</li>
                    </ul>
                </div>
                <?php if (isset($onboardingErrors[5])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors[5]) ?></p>
                <?php endif; ?>
            </section>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn" data-action="back" hidden>Back</button>
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Next</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Create account</button>
            </div>
        </form>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
