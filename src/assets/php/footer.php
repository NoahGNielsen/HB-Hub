<?php
// Set $hbHubShowSiteFooter = true before including this file to show the site footer (the docs, the free games, legal
// links and copyright). Only the frontpage, login, onboarding and the pages built like them (legal/, docs/, the free
// games at /play) do - everywhere else this just renders the toast and the cookie notice.
if (!empty($hbHubShowSiteFooter)):
    // header.php has normally loaded the config already (through maintenance.php)
    $siteConfig ??= require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
    $footerYear = (int) ($siteConfig['footer_copyrightYear'] ?? date('Y'));
?>
<footer class="site-footer">
    <nav class="site-footer-links" aria-label="Footer">
        <a href="/docs/">Help &amp; docs</a>
        <a href="/play">Free games</a>
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
<?php
// For anyone logged in: the popup asking them to put HB Hub on their phone's home screen. It starts hidden too;
// appPrompt.js shows it once per browser, and only on phones and tablets.
if (!empty($hbHubUser)):
?>
<dialog class="app-prompt" aria-labelledby="app-prompt-title" data-app-prompt>
    <!-- The dialog itself has no padding, so a click on it (not on this) is a click on the backdrop -->
    <div class="app-prompt-body">
        <img class="app-prompt-icon" src="https://hbhub.noahgajnielsen.dk/assets/images/appIcon192.png?v=<?= filemtime(__DIR__ . '/../images/appIcon192.png') ?>" alt="" width="64" height="64">
        <h2 id="app-prompt-title">Put HB Hub on your home screen</h2>
        <p>Then it's one tap away like any other app, and opens without the browser bars.</p>
        <p class="app-prompt-how" data-app-prompt-how="ios" hidden>
            Press the <strong>Share</strong> button (the square with an arrow pointing up), then
            <strong>Add to Home Screen</strong>.
        </p>
        <p class="app-prompt-how" data-app-prompt-how="other" hidden>
            Open your browser's menu (&vellip;), then <strong>Add to Home screen</strong> or <strong>Install app</strong>.
        </p>
        <div class="app-prompt-actions">
            <button type="button" class="app-prompt-btn" data-app-prompt-close>Got it</button>
            <button type="button" class="app-prompt-btn app-prompt-btn-primary" data-app-prompt-install hidden>Add to home screen</button>
        </div>
    </div>
</dialog>
<script src="https://hbhub.noahgajnielsen.dk/assets/js/appPrompt.js?v=<?= filemtime(__DIR__ . '/../js/appPrompt.js') ?>" defer></script>
<?php endif; ?>
