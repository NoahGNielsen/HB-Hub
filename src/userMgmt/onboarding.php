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
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <title>Opret konto - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main>
        <div class="login">
            <h1>Du kan ikke oprette dig her</h1>
            <p class="onboarding-hint">En konto, der er blevet brugt på denne enhed, er blevet udelukket, så der kan ikke oprettes en ny konto herfra.</p>
            <p class="userMgmt-switch">Har du allerede en konto? <a href="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>">Gå til login</a></p>
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
    ? 'Det virkede ikke at oprette dig med Discord. Prøv igen, eller udfyld formularen selv.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Body was larger than post_max_size, so PHP dropped all of it - the picture is the only thing that big
        if (in_array('avatar', $onboardingSteps, true)) {
            $onboardingErrors['avatar'] = 'Billedet er for stort. Vælg et mindre.';
        } else {
            $onboardingErrors['name'] = 'Noget gik galt. Prøv igen.';
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
        if (($nameError = hbHubNameError($onboardingValues['name'], $onboardingNameMax, null, true)) !== null) {
            $onboardingErrors['name'] = $nameError;
        }

        // Class - the field of study only for 1. to 3. year, not for Not HTX
        if (($classError = hbHubClassError($onboardingValues['year'], $onboardingValues['class'], false, true)) !== null) {
            $onboardingErrors['class'] = $classError;
        }

        // Picture
        $avatar = $onboardingAvatarMode === ONBOARDING_DISABLED ? null : ($_FILES['avatar'] ?? null);
        $avatarBlob = null;
        $avatarType = null;
        if (is_array($avatar) && is_int($avatar['error'] ?? null) && $avatar['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($avatar['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                || ($avatar['error'] === UPLOAD_ERR_OK && $avatar['size'] > $onboardingAvatarMaxBytes)) {
                $onboardingErrors['avatar'] = "Billedet er for stort. Vælg et under $onboardingAvatarMaxLabel.";
            } elseif ($avatar['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($avatar['tmp_name'])) {
                $onboardingErrors['avatar'] = 'Noget gik galt, da dit billede skulle uploades. Prøv igen.';
            } elseif (!in_array($avatarType = (new finfo(FILEINFO_MIME_TYPE))->file($avatar['tmp_name']), $onboardingAvatarTypes, true)
                || getimagesize($avatar['tmp_name']) === false) {
                $onboardingErrors['avatar'] = 'Upload et billede i PNG, JPEG, GIF eller WebP.';
            } else {
                $avatarBlob = file_get_contents($avatar['tmp_name']);
            }
        } elseif ($onboardingAvatarMode === ONBOARDING_REQUIRED) {
            $onboardingErrors['avatar'] = 'Upload et billede af dig selv.';
        }

        // Description
        $descriptionLength = mb_strlen($onboardingValues['description']);
        if (!hbHubIsLatinText($onboardingValues['description'], true)) {
            $onboardingErrors['description'] = 'Din beskrivelse kan kun bruge bogstaver fra det latinske alfabet (som a-z, æ, ø og å), tal, tegnsætning og emoji.';
        } elseif (hbHubUrlBlacklisted($onboardingValues['description'])) {
            $onboardingErrors['description'] = 'Hov, vent lige! Din beskrivelse har et farligt link eller et link, der krænker folks privatliv.';
        } elseif ($descriptionLength > $onboardingDescriptionMax) {
            $onboardingErrors['description'] = "Hold den på højst $onboardingDescriptionMax tegn.";
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength === 0) {
            $onboardingErrors['description'] = 'Skriv lidt om dig selv.';
        } elseif ($onboardingDescriptionMode === ONBOARDING_REQUIRED && $descriptionLength < $onboardingDescriptionMin) {
            $onboardingErrors['description'] = "Skriv mindst $onboardingDescriptionMin tegn.";
        }

        // Password
        $passwordError = hbHubPasswordError($password, $passwordConfirm, $onboardingPasswordPolicy, danish: true);
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
<html lang="da">
<head>
    <?php $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/userMgmt.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/userMgmt.css') ?>">
    <script src="/assets/js/onboarding.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/onboarding.js') ?>" defer></script>
    <title>Opret konto - HB Hub</title>
    <meta name="description" content="Opret en gratis HB Hub-konto: du kommer med i din HTX-klasses gruppechat på HANSENBERG og får private beskeder, et casino med legechips, og Matador, skak og UNO.">
</head>
<body>
    <main>
        <form class="onboarding" action="/onboarding<?= htmlspecialchars(hbHubReturnQuery()) ?>" method="post" enctype="multipart/form-data" data-start-step="<?= $onboardingStartStep ?>"
              <?php if ($onboardingDiscordUser !== null && ($onboardingDiscordUser['avatar'] ?? null) !== null && $onboardingAvatarMode !== ONBOARDING_DISABLED && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>data-discord-avatar="/onboarding?discordAvatar=1"<?php endif; ?>
              data-common-passwords="/assets/commonPasswords.txt?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt') ?>">
            <div class="onboarding-header">
                <img class="onboarding-logo" src="/assets/images/favicon.svg" alt="HB Hub logo">
                <div>
                    <h1>Velkommen til HB Hub!</h1>
                    <p class="onboarding-intro">Lad os sætte din konto op. Det tager kun et minut.</p>
                </div>
            </div>

            <!-- One segment per question, filled in by onboarding.js -->
            <div class="onboarding-progress" hidden>
                <p class="onboarding-counter">Trin 1 af <?= count($onboardingSteps) ?></p>
                <ol class="onboarding-progress-steps" aria-hidden="true">
                    <?php foreach ($onboardingSteps as $step): ?><li></li><?php endforeach; ?>
                </ol>
            </div>

            <section class="onboarding-step" aria-labelledby="onboarding-q1">
                <h2 id="onboarding-q1"><label for="onboarding-name">Vælg et brugernavn</label></h2>
                <p class="onboarding-hint" id="onboarding-name-about">
                    Alle på HB Hub ser dig under det, og du logger ind med det. Det kan være dit navn, et kælenavn eller hvad som
                    helst andet.
                </p>
                <div class="onboarding-input-wrap">
                    <!-- autocomplete="username" so password managers save it with the password, but that also makes some
                         browsers suggest a saved email here - the check in onboarding.js catches that -->
                    <input type="text" id="onboarding-name" name="name" minlength="<?= HBHUB_USER_NAME_MIN ?>" maxlength="<?= $onboardingNameMax ?>" required autocomplete="username"
                           placeholder="fx Alex, alex_h eller Skakkongen" aria-describedby="onboarding-name-about"
                           value="<?= htmlspecialchars($onboardingValues['name']) ?>" data-counter="onboarding-name-counter">
                </div>
                <p class="onboarding-hint"><span id="onboarding-name-counter"><?= mb_strlen($onboardingValues['name']) ?></span>/<?= $onboardingNameMax ?> tegn (mindst <?= HBHUB_USER_NAME_MIN ?>)</p>
                <p class="onboarding-warning" role="alert" data-name-email-warning hidden>
                    Det ligner en e-mailadresse. Vælg et navn eller kælenavn i stedet - det kan ses af alle.
                </p>
                <!-- Shown by onboarding.js once it has checked the name half a second after they stop typing -->
                <p class="onboarding-error" role="alert" data-name-taken-error hidden>
                    Det navn er allerede taget. Vælg et andet.
                </p>
                <?php if (isset($onboardingErrors['name'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['name']) ?></p>
                <?php endif; ?>
                <?php if ($onboardingDiscordUser !== null): ?>
                    <p class="onboarding-discord-linked">
                        <?= hbHubDiscordLogo() ?>
                        <span>Du opretter dig med Discord som <strong><?= htmlspecialchars($onboardingDiscordUser['name'] ?? '') ?></strong>, så du også kan logge ind med den. Dit navn, din bio og dit billede er fra Discord - ret dem, som du vil.
                        <a href="/onboarding<?= htmlspecialchars(hbHubReturnQuery() === '' ? '?discordForget=1' : hbHubReturnQuery() . '&discordForget=1') ?>">Kobl den ikke til</a></span>
                    </p>
                <?php elseif ($onboardingHasDiscord): ?>
                    <p class="userMgmt-or"><span>eller</span></p>
                    <!-- Belongs to the form below this one, so it doesn't need anything filled in -->
                    <button type="submit" class="onboarding-btn userMgmt-discord" form="onboarding-discord"><?= hbHubDiscordLogo() ?>Opret dig med Discord</button>
                    <?php if ($onboardingDiscordError !== null): ?>
                        <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingDiscordError) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="onboarding-step" aria-labelledby="onboarding-q2">
                <h2 id="onboarding-q2">Hvilken klasse går du i?</h2>
                <!-- Radio buttons drawn as tiles; the form sends the same year/class values the dropdowns used to -->
                <fieldset class="onboarding-choice">
                    <legend>Årgang</legend>
                    <div class="onboarding-tiles onboarding-tiles-year">
                        <?php foreach (HBHUB_CLASS_YEARS as $year): ?>
                            <label class="onboarding-tile">
                                <input type="radio" name="year" value="<?= $year ?>" required<?= $onboardingValues['year'] === $year ? ' checked' : '' ?>>
                                <span class="onboarding-tile-face"><?= $year ?>.<span class="onboarding-tile-sub">år</span></span>
                            </label>
                        <?php endforeach; ?>
                        <label class="onboarding-tile">
                            <input type="radio" name="year" value="<?= HBHUB_CLASS_NOT_HTX ?>" required<?= $onboardingValues['year'] === HBHUB_CLASS_NOT_HTX ? ' checked' : '' ?>>
                            <span class="onboarding-tile-face onboarding-tile-face-text">Ikke HTX</span>
                        </label>
                    </div>
                </fieldset>
                <!-- Only for 1. to 3. year: hidden by onboarding.js until one of them is picked. Not required in the markup,
                     so Not HTX can be sent without JS; the server checks it. -->
                <fieldset class="onboarding-choice onboarding-reveal" data-class-field>
                    <legend>Studieretning</legend>
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
                <h2 id="onboarding-q3">Upload et billede af dig selv!<?php if ($onboardingAvatarMode === ONBOARDING_OPTIONAL): ?> <span class="onboarding-optional">(Valgfrit)</span><?php endif; ?></h2>
                <div class="onboarding-avatar">
                    <!-- The picture itself opens the file picker too -->
                    <label for="onboarding-avatar" class="onboarding-avatar-frame">
                        <img class="onboarding-avatar-preview is-placeholder" src="/assets/images/icons/user.png" alt="Dit profilbillede">
                    </label>
                    <div class="onboarding-avatar-actions">
                        <input type="file" id="onboarding-avatar" name="avatar" class="onboarding-file-input"<?= $onboardingAvatarMode === ONBOARDING_REQUIRED ? ' required' : '' ?>
                               accept="<?= implode(',', $onboardingAvatarTypes) ?>" data-max-bytes="<?= $onboardingAvatarMaxBytes ?>" data-max-label="<?= $onboardingAvatarMaxLabel ?>">
                        <label for="onboarding-avatar" class="onboarding-btn">Vælg billede</label>
                        <button type="button" class="onboarding-btn" data-action="remove-avatar" hidden>Fjern</button>
                    </div>
                </div>
                <p class="onboarding-hint">PNG, JPEG, GIF eller WebP, op til <?= $onboardingAvatarMaxLabel ?>.</p>
                <p class="onboarding-error" role="alert" data-avatar-error<?= isset($onboardingErrors['avatar']) ? '' : ' hidden' ?>><?= htmlspecialchars($onboardingErrors['avatar'] ?? '') ?></p>
            </section>
            <?php endif; ?>

            <?php if ($onboardingDescriptionMode !== ONBOARDING_DISABLED): ?>
            <section class="onboarding-step" aria-labelledby="onboarding-q4">
                <h2 id="onboarding-q4"><label for="onboarding-description">Beskriv dig selv</label><?php if ($onboardingDescriptionMode === ONBOARDING_OPTIONAL): ?> <span class="onboarding-optional">(Valgfrit)</span><?php endif; ?></h2>
                <textarea id="onboarding-description" name="description" maxlength="<?= $onboardingDescriptionMax ?>" rows="5"
                          <?= $onboardingDescriptionMode === ONBOARDING_REQUIRED ? 'required data-min-length="' . $onboardingDescriptionMin . '"' : '' ?>
                          data-counter="onboarding-description-counter"><?= htmlspecialchars($onboardingValues['description']) ?></textarea>
                <p class="onboarding-hint">
                    <span id="onboarding-description-counter"><?= mb_strlen($onboardingValues['description']) ?></span>/<?= $onboardingDescriptionMax ?> tegn<?= $onboardingDescriptionMode === ONBOARDING_REQUIRED ? " (mindst $onboardingDescriptionMin)" : '' ?>
                </p>
                <?php if (isset($onboardingErrors['description'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['description']) ?></p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="onboarding-step" aria-labelledby="onboarding-q5">
                <h2 id="onboarding-q5">Sidst men ikke mindst: hvad skal din adgangskode være?</h2>
                <div class="onboarding-field">
                    <label for="onboarding-password">Adgangskode</label>
                    <div class="onboarding-input-wrap">
                        <input type="password" id="onboarding-password" name="password" minlength="<?= $onboardingPasswordMin ?>" maxlength="72" required autocomplete="new-password"
                               aria-describedby="onboarding-password-rules" data-min-length="<?= $onboardingPasswordMin ?>" data-char-types="<?= $onboardingPasswordCharTypes ?>">
                    </div>
                </div>
                <div class="onboarding-field onboarding-reveal" data-password-confirm-field>
                    <label for="onboarding-password-confirm">Gentag adgangskode</label>
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
                        <li data-check="length">Mindst <?= $onboardingPasswordMin ?> tegn</li>
                    </ul>
                    <?php endif; ?>
                    <?php if ($onboardingPasswordCharTypes > 0): ?>
                    <p class="onboarding-hint"><?= $onboardingPasswordCharTypes === 4 ? 'Alle disse:' : "Mindst $onboardingPasswordCharTypes af disse:" ?></p>
                    <ul class="onboarding-checklist">
                        <li data-check="lower">Lille bogstav (a-z)</li>
                        <li data-check="upper">Stort bogstav (A-Z)</li>
                        <li data-check="number">Tal (0-9)</li>
                        <li data-check="special">Specialtegn (!, ?, # ...)</li>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php if (isset($onboardingErrors['password'])): ?>
                    <p class="onboarding-error" role="alert"><?= htmlspecialchars($onboardingErrors['password']) ?></p>
                <?php endif; ?>
                <p class="onboarding-hint">Ved at oprette en konto accepterer du <a href="/legal/terms" target="_blank">vilkårene</a> og <a href="/legal/privacy" target="_blank">privatlivspolitikken</a>.</p>
            </section>

            <div class="onboarding-nav">
                <button type="button" class="onboarding-btn" data-action="back" hidden>Tilbage</button>
                <button type="button" class="onboarding-btn onboarding-btn-primary" data-action="next" hidden>Næste</button>
                <button type="submit" class="onboarding-btn onboarding-btn-primary" data-action="submit">Opret konto</button>
            </div>

            <p class="userMgmt-switch">Har du allerede en konto? <a href="/login<?= htmlspecialchars(hbHubReturnQuery()) ?>">Gå til login</a></p>
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
