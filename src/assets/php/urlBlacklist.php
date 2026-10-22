<?php
// The URL blacklist: sites whose addresses can't be sent in a message - IP loggers and other links that are dangerous or
// give away where someone is. The list itself is assets/urlBlacklist.txt, one domain per line (subdomains count too).
// Checked when a chat message is sent, edited or sent with an attachment (chat/index.php), and in the Matador, chess and
// UNO game chats (games/*/chat.php), and when a bio is saved (otherPages/settings.php, userMgmt/onboarding.php and
// admin/user.php). chat.js gets the list too (data-url-blacklist on the blocked link popup), so it can
// stop the message before it's sent and the text stays in the box, but the server's check is the one that counts.
// A domain counts anywhere in the text, with or without https://, so it can't be sent as plain text to copy either.
// The list has thousands of domains (too many for one regex, PCRE won't compile it), so the check picks out everything in
// the text that looks like a domain and looks it up, and every domain it's a subdomain of, in the list.

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
    static $blocked = null;
    $blocked ??= array_flip(hbHubUrlBlacklist()); // domain => index, for isset()
    if ($blocked === []) {
        return false;
    }

    $text = mb_scrub(rawurldecode($text), 'UTF-8'); // decoding can leave bytes that aren't UTF-8, which /u can't read
    $text = preg_replace('/\p{Cf}+/u', '', str_replace(['。', '．', '｡'], '.', $text)) ?? $text;

    // Every run of dotted names, as long as it goes ("a.grabify.link.evil.com" is one), then it and each domain it's
    // under: a.grabify.link -> grabify.link -> link. So "evil.com" behind a blocked name is a different site and fine.
    preg_match_all('/(?<![\p{L}\p{N}_.-])[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)+/u', $text, $matches);
    foreach ($matches[0] as $name) {
        $labels = explode('.', mb_strtolower($name));
        for ($i = 0, $last = count($labels) - 1; $i < $last; $i++) {
            if (isset($blocked[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }
    }
    return false;
}
