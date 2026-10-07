<?php
// Password rules, from the onboarding_password_requirements setting. Shared by onboarding (new account)
// and the settings page (new password), so both ask for the same.

/**
 * The password rules the site config asks for.
 * 1 = just don't use a common password, 2 = weak, 3 = medium, 4 = strong.
 *
 * @return array{minLength: int, charTypes: int} charTypes is how many of lowercase, uppercase, numbers and special characters are needed
 */
function hbHubPasswordPolicy(array $siteConfig): array
{
    $policies = [
        1 => ['minLength' => 1, 'charTypes' => 0],
        2 => ['minLength' => 8, 'charTypes' => 0],
        3 => ['minLength' => 8, 'charTypes' => 3],
        4 => ['minLength' => 12, 'charTypes' => 4],
    ];
    return $policies[(int) $siteConfig['onboarding_password_requirements']] ?? $policies[3];
}

/**
 * Why the password can't be used, or null when it's fine.
 * $what is how the messages refer to it, e.g. "Your password" or "Your new password".
 */
function hbHubPasswordError(string $password, string $passwordConfirm, array $policy, string $what = 'Your password'): ?string
{
    $minLength = $policy['minLength'];
    $charTypes = $policy['charTypes'];

    // bcrypt only uses the first 72 bytes
    $passwordCharTypes = (int) preg_match('/\p{Ll}/u', $password) + (int) preg_match('/\p{Lu}/u', $password)
        + (int) preg_match('/\p{N}/u', $password) + (int) preg_match('/[^\p{Ll}\p{Lu}\p{N}]/u', $password);
    $commonPasswords = file($_SERVER['DOCUMENT_ROOT'] . '/assets/commonPasswords.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $passwordIsCommon = in_array(mb_strtolower($password), array_map(fn ($line) => mb_strtolower(rtrim($line, "\r")), $commonPasswords), true);

    if ($password === '') {
        return 'Please choose a password.';
    } elseif (mb_strlen($password) < $minLength) {
        return "$what needs to be at least $minLength characters.";
    } elseif (strlen($password) > 72) {
        return "$what is too long. Please keep it to 72 characters or fewer.";
    } elseif ($passwordCharTypes < $charTypes) {
        return $charTypes === 4
            ? "$what needs lowercase letters, uppercase letters, numbers and special characters."
            : "$what needs at least $charTypes of: lowercase letters, uppercase letters, numbers and special characters.";
    } elseif ($passwordIsCommon) {
        return 'That password is too common. Please choose another one.';
    } elseif ($password !== $passwordConfirm) {
        return 'The passwords don\'t match.';
    }
    return null;
}
