<?php
// When the Terms of Service and the Privacy Policy last changed. They live here (not at the top of legal/terms.php and
// legal/privacy.php) because two places need each of them: the page shows it as "Last updated ...", and footer.php
// hands it to cornerBoxes.js, which shows the "has been updated" box to anyone who agreed to an older version.
// Change the date whenever that page changes.
const HBHUB_TERMS_UPDATED = '16 November 2026';
const HBHUB_PRIVACY_UPDATED = '17 November 2026';

/**
 * A date written like the constants above ("19 October 2026"), the Danish way ("19. oktober 2026"), for the legal
 * pages and the boxes in footer.php, which are in Danish. The constants themselves stay in English, as cornerBoxes.js
 * saves them as the version someone agreed to: changing how they're written would count as a new version.
 */
function hbHubLegalDanishDate(string $date): string
{
    $months = [
        'January' => 'januar', 'February' => 'februar', 'March' => 'marts', 'April' => 'april', 'May' => 'maj',
        'June' => 'juni', 'July' => 'juli', 'August' => 'august', 'September' => 'september', 'October' => 'oktober',
        'November' => 'november', 'December' => 'december',
    ];
    return preg_replace_callback(
        '/^(\d{1,2}) ([A-Z][a-z]+) (\d{4})$/',
        fn(array $m): string => $m[1] . '. ' . ($months[$m[2]] ?? $m[2]) . ' ' . $m[3],
        $date
    ) ?? $date;
}
