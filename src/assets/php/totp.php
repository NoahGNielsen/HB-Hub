<?php
// Two-factor login with an authenticator app (TOTP, RFC 6238): 6 digit codes that change every 30 seconds.
// The secret is kept in HBHub-Users.userTotpSecret, and the time step of the last accepted code in userTotpLastStep.
// Used by the settings page (turning it on and off) and login (asking for the code).

const HBHUB_TOTP_DIGITS = 6;
const HBHUB_TOTP_PERIOD = 30; // seconds
const HBHUB_TOTP_WINDOW = 1; // steps either side of now that are accepted too, for phone clocks that are a little off
const HBHUB_TOTP_ISSUER = 'HB Hub'; // the name the authenticator app shows
const HBHUB_TOTP_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * A new random secret: 20 bytes (160 bits, as RFC 4226 recommends) as 32 base32 characters.
 */
function hbHubTotpNewSecret(): string
{
    $bits = '';
    foreach (str_split(random_bytes(20)) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $secret = '';
    foreach (str_split($bits, 5) as $chunk) {
        $secret .= HBHUB_TOTP_BASE32[bindec($chunk)];
    }
    return $secret;
}

/**
 * Whether the text is a secret from hbHubTotpNewSecret(), e.g. one sent back by the settings form.
 */
function hbHubTotpIsSecret(mixed $secret): bool
{
    return is_string($secret) && preg_match('/^[A-Z2-7]{32}$/', $secret) === 1;
}

/**
 * The secret as the raw bytes the codes are made from.
 */
function hbHubTotpKey(string $secret): string
{
    $bits = '';
    foreach (str_split($secret) as $char) {
        $bits .= str_pad(decbin(strpos(HBHUB_TOTP_BASE32, $char)), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $key .= chr(bindec($chunk));
        }
    }
    return $key;
}

/**
 * The code for one 30 second time step (HOTP, RFC 4226, with the step as the counter).
 */
function hbHubTotpCode(string $key, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), $key, true); // J = 64-bit big-endian counter
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($value % 10 ** HBHUB_TOTP_DIGITS), HBHUB_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * The time step the code belongs to when it's right, otherwise null.
 * Steps up to $lastStep (the last code that was used) are never accepted, so a code only works once.
 * Spaces in the code are ignored, as some apps show it as "123 456".
 */
function hbHubTotpVerify(string $secret, mixed $code, ?int $lastStep = null): ?int
{
    $code = is_string($code) ? preg_replace('/\s+/', '', $code) : '';
    if (!hbHubTotpIsSecret($secret) || preg_match('/^\d{' . HBHUB_TOTP_DIGITS . '}$/', $code) !== 1) {
        return null;
    }

    $key = hbHubTotpKey($secret);
    $now = intdiv(time(), HBHUB_TOTP_PERIOD);
    for ($step = $now - HBHUB_TOTP_WINDOW; $step <= $now + HBHUB_TOTP_WINDOW; $step++) {
        if (($lastStep === null || $step > $lastStep) && hash_equals(hbHubTotpCode($key, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/**
 * The otpauth:// address an authenticator app adds the account from (shown as a QR code, or tapped on a phone).
 */
function hbHubTotpUri(string $secret, string $accountName): string
{
    return 'otpauth://totp/' . rawurlencode(HBHUB_TOTP_ISSUER . ':' . $accountName)
        . '?secret=' . $secret . '&issuer=' . rawurlencode(HBHUB_TOTP_ISSUER)
        . '&algorithm=SHA1&digits=' . HBHUB_TOTP_DIGITS . '&period=' . HBHUB_TOTP_PERIOD;
}

/**
 * The secret in groups of 4, easier to type into an app by hand.
 */
function hbHubTotpFormatSecret(string $secret): string
{
    return implode(' ', str_split($secret, 4));
}
