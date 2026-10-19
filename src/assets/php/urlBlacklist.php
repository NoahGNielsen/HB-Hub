<?php
// The URL blacklist: sites whose addresses can't be sent in a message - IP loggers and other links that are dangerous or
// give away where someone is. The list itself is assets/urlBlacklist.txt, one domain per line (subdomains count too).
// Checked when a chat message is sent, edited or sent with an attachment (chat/index.php), and in the Matador and chess
// game chats (games/*/chat.php). chat.js gets the list too (data-url-blacklist on the blocked link popup), so it can
// stop the message before it's sent and the text stays in the box, but the server's check is the one that counts.
// A domain counts anywhere in the text, with or without https://, so it can't be sent as plain text to copy either.

const HBHUB_URL_BLACKLIST_FILE = __DIR__ . '/../urlBlacklist.txt';
const HBHUB_URL_BLACKLIST_MESSAGE = 'Woah, hold up! Looks like you are sending a dangerous link or a link that compromises people\'s privacy!';

/**
 * The blacklisted domains, lowercase, from assets/urlBlacklist.txt (read once per request).
 *
 * @return list<string>
 */
function hbHubUrlBlacklist(): array
{
    static $domains = null;
    if ($domains !== null) {
        return $domains;
    }

    $domains = [];
    foreach (file(HBHUB_URL_BLACKLIST_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $domain = mb_strtolower(trim(explode('#', $line, 2)[0]));
        if ($domain !== '') {
            $domains[$domain] = true; // keyed, so a domain in there twice is only matched once
        }
    }
    return $domains = array_keys($domains);
}

/**
 * Whether the text has a blacklisted domain (or a subdomain of one) anywhere in it.
 * Browsers read some things in an address as something else, so those are undone first: %-encoding (grabify%2Elink),
 * other kinds of full stops (grabify。link), and invisible characters like zero-width spaces, which they leave out.
 * "notgrabify.link" or "grabify.linked" aren't the same site, so they're fine.
 */
function hbHubUrlBlacklisted(string $text): bool
{
    static $pattern = null;
    $domains = hbHubUrlBlacklist();
    if ($domains === []) {
        return false;
    }
    $pattern ??= '/(?<![\p{L}\p{N}_.-])(?:[\p{L}\p{N}_-]+\.)*(?:'
        . implode('|', array_map(static fn (string $domain): string => preg_quote($domain, '/'), $domains))
        . ')(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])/iu';

    $text = mb_scrub(rawurldecode($text), 'UTF-8'); // decoding can leave bytes that aren't UTF-8, which /u can't read
    $text = preg_replace('/\p{Cf}+/u', '', str_replace(['。', '．', '｡'], '.', $text)) ?? $text;
    return preg_match($pattern, $text) === 1;
}
