<?php
// Log in with Discord: an account can be linked to a Discord account (userDiscordId and userDiscordName in
// HBHub-Users), and then logging in with Discord works like the right password (two-factor login still asks for the
// code). Linking is only ever done to an account that already exists: on the last step of onboarding (a box to tick,
// the account is made first and then it's on to Discord) or in the settings, after the password. Nobody signs up
// with Discord alone.
// Set up by discord_client_id and discord_client_secret in hbHubSiteConfig.php; without them the buttons aren't shown.
//
// It's Discord's OAuth2 "authorization code" flow, asking only for the identify scope (the id and username):
// 1. hbHubDiscordStart() remembers what was started (login, link or signup) in the hbHubDiscordState cookie, with a
//    random state, and sends the browser to Discord.
// 2. Discord sends it back to /discord (userMgmt/discord.php) with a code and the same state. hbHubDiscordTakeState()
//    checks the state against the cookie, so only the browser that started it can finish it (otherwise someone could
//    send you their own code and get their Discord linked to your account).
// 3. hbHubDiscordFetchUser() swaps the code for a token on the server, asks Discord who it belongs to and revokes the
//    token again straight away. The token never reaches the browser and is never stored.

const HBHUB_DISCORD_API = 'https://discord.com/api/v10';
const HBHUB_DISCORD_AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
const HBHUB_DISCORD_STATE_COOKIE = 'hbHubDiscordState';
const HBHUB_DISCORD_STATE_MINUTES = 10; // how long there is to click Authorize on Discord
const HBHUB_DISCORD_MODES = ['login', 'link', 'signup']; // signup is linking right after onboarding made the account

/**
 * The app's client id and secret, or null when Discord login is turned off (no id or secret, or the example config's
 * placeholders).
 *
 * @return ?array{id: string, secret: string}
 */
function hbHubDiscordClient(array $siteConfig): ?array
{
    $id = is_string($siteConfig['discord_client_id'] ?? null) ? trim($siteConfig['discord_client_id']) : '';
    $secret = is_string($siteConfig['discord_client_secret'] ?? null) ? trim($siteConfig['discord_client_secret']) : '';
    if (!ctype_digit($id) || $secret === '' || $secret === 'your_discord_client_secret') {
        return null;
    }
    return ['id' => $id, 'secret' => $secret];
}

/**
 * Where Discord sends the browser back to. Has to be one of the redirects set up for the app in Discord's developer
 * portal, which is also why a made-up Host header can't send the code anywhere else.
 */
function hbHubDiscordRedirectUri(): string
{
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'hbhub.noahgajnielsen.dk') . '/discord';
}

/**
 * Starts a login or link: remembers $mode and where to go afterwards ($returnPath, from hbHubReturnPath()) with a new
 * random state in the hbHubDiscordState cookie, then sends the browser to Discord. Call before any output.
 * The cookie is only sent to /discord, and is SameSite=Lax so it comes along when Discord sends the browser back.
 */
function hbHubDiscordStart(array $client, string $mode, string $returnPath): never
{
    $state = bin2hex(random_bytes(16));
    setcookie(HBHUB_DISCORD_STATE_COOKIE, $state . '|' . $mode . '|' . $returnPath, [
        'expires' => time() + HBHUB_DISCORD_STATE_MINUTES * 60,
        'path' => '/discord',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // prompt=none skips Discord's Authorize page when the app has been allowed before, so logging in again is one click
    header('Location: ' . HBHUB_DISCORD_AUTHORIZE_URL . '?' . http_build_query([
        'client_id' => $client['id'],
        'response_type' => 'code',
        'redirect_uri' => hbHubDiscordRedirectUri(),
        'scope' => 'identify',
        'state' => $state,
        'prompt' => 'none',
    ]), true, 303);
    exit;
}

/**
 * What hbHubDiscordStart() remembered, when the state Discord sent back matches the cookie, otherwise null (it ran
 * out, or the browser didn't start it). The cookie is removed either way, so each state can only be used once.
 *
 * @return ?array{mode: string, returnPath: string}
 */
function hbHubDiscordTakeState(): ?array
{
    $cookie = $_COOKIE[HBHUB_DISCORD_STATE_COOKIE] ?? null;
    setcookie(HBHUB_DISCORD_STATE_COOKIE, '', [
        'expires' => 1,
        'path' => '/discord',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $state = $_GET['state'] ?? null;
    if (!is_string($cookie) || !is_string($state)) {
        return null;
    }
    $parts = explode('|', $cookie, 3);
    if (count($parts) !== 3 || strlen($parts[0]) !== 32 || !hash_equals($parts[0], $state)
        || !in_array($parts[1], HBHUB_DISCORD_MODES, true)) {
        return null;
    }
    return ['mode' => $parts[1], 'returnPath' => hbHubReturnPath($parts[2])];
}

/**
 * Swaps the code Discord sent back for the Discord account it belongs to, or null when that didn't work (an old or
 * made-up code, or Discord didn't answer). The token is revoked again once used, as it's never needed after this.
 *
 * @return ?array{id: string, name: string} the account's id (a snowflake, up to 20 digits) and username
 */
function hbHubDiscordFetchUser(array $client, mixed $code): ?array
{
    if (!is_string($code) || $code === '' || strlen($code) > 200) {
        return null;
    }

    $credentials = ['client_id' => $client['id'], 'client_secret' => $client['secret']];
    $token = hbHubDiscordRequest('/oauth2/token', $credentials + [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => hbHubDiscordRedirectUri(),
    ]);
    $accessToken = $token['access_token'] ?? null;
    if (!is_string($accessToken)) {
        return null;
    }

    $user = hbHubDiscordRequest('/users/@me', null, $accessToken);
    hbHubDiscordRequest('/oauth2/token/revoke', $credentials + ['token' => $accessToken, 'token_type_hint' => 'access_token']);

    $id = $user['id'] ?? null;
    $name = $user['username'] ?? null;
    if (!is_string($id) || !ctype_digit($id) || strlen($id) > 20 || !is_string($name)) {
        return null;
    }
    return ['id' => $id, 'name' => mb_substr($name, 0, 32)];
}

/**
 * One request to Discord's API: a form POST when $form is given, otherwise a GET with $accessToken.
 * Returns the JSON answer, or null when it wasn't a 200 (logged).
 */
function hbHubDiscordRequest(string $path, ?array $form, ?string $accessToken = null): ?array
{
    $curl = curl_init(HBHUB_DISCORD_API . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        // Discord's firewall turns away requests without one
        CURLOPT_USERAGENT => 'HB Hub (https://hbhub.noahgajnielsen.dk)',
        CURLOPT_HTTPHEADER => ['Accept: application/json', ...($accessToken !== null ? ['Authorization: Bearer ' . $accessToken] : [])],
    ];
    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    // Revoking a token can answer with nothing at all
    $answer = is_string($body) ? ($body === '' ? [] : json_decode($body, true)) : null;
    if ($status !== 200 || !is_array($answer)) {
        error_log('Discord: ' . $path . ' answered ' . $status . ($body === false ? ' (' . curl_error($curl) . ')' : ''));
        return null;
    }
    return $answer;
}
