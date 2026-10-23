<?php
// Set $hbHubShowSiteFooter = true before including this file to show the site footer (the docs, legal links and
// copyright). Only the frontpage, login, onboarding and the pages built like them (legal/, docs/) do - everywhere else
// this just renders the toast and the cookie notice.
if (!empty($hbHubShowSiteFooter)):
    // header.php has normally loaded the config already (through maintenance.php)
    $siteConfig ??= require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
    $footerYear = (int) ($siteConfig['footer_copyrightYear'] ?? date('Y'));
?>
<footer class="site-footer">
    <nav class="site-footer-links" aria-label="Footer">
        <a href="/docs/">Help &amp; docs</a>
        <a href="/legal/terms">Terms</a>
        <a href="/legal/privacy">Privacy</a>
        <a href="/legal/code-of-conduct">Code of Conduct</a>
        <a href="/legal/do-not-sell-or-share-my-personal-information">Do not sell or share my data</a>
        <a href="/legal/credits">Credits</a>
        <a href="https://github.com/NoahGNielsen/HB-Hub" target="_blank" rel="noopener noreferrer">GitHub</a>
        <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">Report Security Concern</a>
    </nav>
    <p class="site-footer-copyright">&copy; <?= (int) date('Y') > $footerYear ? $footerYear . '&ndash;' . date('Y') : $footerYear ?> Noah Gaj Nedergaard Nielsen</p>
</footer>
<?php endif;

require_once __DIR__ . '/toast.php';
hbHubRenderToast();

// The cookie notice, on every page. It starts hidden; cookieNotice.js shows it until it's been clicked away once.
?>
<aside class="cookie-notice" data-cookie-notice aria-label="Cookies" hidden>
    <p class="cookie-notice-text">
        HB Hub uses cookies to keep you logged in and the site working. By using the site, you agree to this and to the
        <a href="/legal/terms">Terms</a> and <a href="/legal/privacy#cookies">Privacy Policy</a>.
    </p>
    <button type="button" class="cookie-notice-ok" data-cookie-notice-ok>Got it</button>
</aside>
<script src="https://hbhub.noahgajnielsen.dk/assets/js/cookieNotice.js?v=<?= filemtime(__DIR__ . '/../js/cookieNotice.js') ?>" defer></script>
