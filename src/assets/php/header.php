<?php
// The shared bits of every page's <head>: charset, favicon, the main stylesheets and fonts, and what search engines
// and link previews (Discord, Snapchat, iMessage, ...) read. Each page adds its own <title> and description after it.
//
// /manifest.json is the web app manifest, so the site can be added to a phone's home screen and opens without the
// browser bars (JSON can't hold comments, so it's explained here). Its "any" icons are the favicon's tilted tile
// (appIcon192/512.png); the "maskable" ones are full-bleed orange (appIconMaskable192/512.png) with the HB inside
// the middle 80%, since Android crops them to a circle or squircle. Shortcuts (long-press the icon) open Chat, Casino
// and Games. iOS still uses appleTouchIcon.png for the icon.
//
// The canonical URL is the page's clean path without the query string, so /login?return=%2Fchat (where
// session.php sends visitors who aren't logged in) counts as /login, and /index as /. Error pages (anything
// that isn't a 200) are noindex, so they get none.
//
// Pages under the frontpage's top bar (homeNav.php) instead of the app's nav panel set $headerHomeNav = true before
// including this, so they don't wait for navPanel.css, which they don't use.
//
// The font (Raleway, assets/fonts/, @font-face in mainStyle.css) is our own copy instead of Google Fonts', so the
// first paint doesn't wait on two other servers. Its Latin file is preloaded, since the browser would otherwise only
// find it after mainStyle.css has loaded.
require_once __DIR__ . '/maintenance.php';

$headerCanonical = null;
if (http_response_code() === 200) {
    $headerPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $headerPath = is_string($headerPath) && str_starts_with($headerPath, '/') ? $headerPath : '/';
    $headerCanonical = 'https://hbhub.noahgajnielsen.dk' . preg_replace('~(^|/)index$~', '$1', $headerPath);
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="https://hbhub.noahgajnielsen.dk/assets/images/favicon.svg?v=<?= filemtime(__DIR__ . '/../images/favicon.svg') ?>">
<link rel="apple-touch-icon" href="https://hbhub.noahgajnielsen.dk/assets/images/appleTouchIcon.png?v=<?= filemtime(__DIR__ . '/../images/appleTouchIcon.png') ?>">
<link rel="manifest" href="https://hbhub.noahgajnielsen.dk/manifest.json?v=<?= filemtime(__DIR__ . '/../../manifest.json') ?>">
<meta name="apple-mobile-web-app-title" content="HB Hub">
<meta name="theme-color" content="#161a21">
<link rel="preload" href="https://hbhub.noahgajnielsen.dk/assets/fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/mainStyle.css?v=<?= filemtime(__DIR__ . '/../css/mainStyle.css') ?>">
<?php if (empty($headerHomeNav)): ?>
<link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/navPanel.css?v=<?= filemtime(__DIR__ . '/../css/navPanel.css') ?>">
<?php endif; ?>
<link rel="terms-of-service" href="https://hbhub.noahgajnielsen.dk/legal/terms">
<link rel="privacy-policy" href="https://hbhub.noahgajnielsen.dk/legal/privacy">

<!-- Search engines and link previews. og:title/og:description fall back to the page's <title> and description. -->
<?php if ($headerCanonical !== null): ?>
<link rel="canonical" href="<?= htmlspecialchars($headerCanonical) ?>">
<meta property="og:url" content="<?= htmlspecialchars($headerCanonical) ?>">
<?php endif; ?>
<meta property="og:site_name" content="HB Hub">
<meta property="og:type" content="website">
<meta property="og:locale" content="en_GB">
<meta property="og:image" content="https://hbhub.noahgajnielsen.dk/assets/images/shareImage.png?v=<?= filemtime(__DIR__ . '/../images/shareImage.png') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="HB Hub: The whole school, one tab away. Chat, Casino, Games.">
<meta name="twitter:card" content="summary_large_image">
