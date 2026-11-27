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
    <nav class="site-footer-links" aria-label="Sidefod">
        <a href="/docs/">Hjælp</a>
        <a href="/play">Gratis spil</a>
        <a href="/legal/terms">Vilkår</a>
        <a href="/legal/privacy">Privatliv</a>
        <a href="/legal/code-of-conduct">Adfærdskodeks</a>
        <a href="/legal/do-not-sell-or-share-my-personal-information">Sælg eller del ikke mine data</a>
        <a href="/legal/credits">Krediteringer</a>
        <a href="https://github.com/NoahGNielsen/HB-Hub?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">GitHub</a>
        <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">Rapportér et sikkerhedsproblem</a>
    </nav>
    <?php // The copyright line links to the owner's own site ?>
    <p class="site-footer-copyright"><a href="https://noahgajnielsen.dk?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">&copy; <?= (int) date('Y') > $footerYear ? $footerYear . '&ndash;' . date('Y') : $footerYear ?> Noah Gaj Nedergaard Nielsen</a></p>
</footer>
<?php endif;

require_once __DIR__ . '/toast.php';
hbHubRenderToast();

// The cookie notice, on every page. It starts hidden; cornerBoxes.js shows it until it's been clicked away once.
// Next to it, the same kind of box saying the terms and/or the privacy policy have changed. Also hidden; cornerBoxes.js
// shows it to anyone who clicked the cookie notice away back when one of them had an older date than
// data-terms-updated / data-privacy-updated, and hides the part (and the " and ") about the one that hasn't changed.
require_once __DIR__ . '/legalUpdated.php';
?>
<?php // In Danish, like the public pages (and the legal pages they link to). ?>
<aside class="cookie-notice" data-cookie-notice aria-label="Cookies" hidden lang="da">
    <p class="cookie-notice-text">
        HB Hub bruger cookies til at holde dig logget ind og få siden til at virke. Ved at bruge siden accepterer du
        det og <a href="/legal/terms">vilkårene</a> og <a href="/legal/privacy#cookies">privatlivspolitikken</a>.
    </p>
    <button type="button" class="cookie-notice-ok" data-cookie-notice-ok>Forstået</button>
</aside>
<aside class="corner-box" data-corner-terms data-terms-updated="<?= htmlspecialchars(HBHUB_TERMS_UPDATED) ?>" data-privacy-updated="<?= htmlspecialchars(HBHUB_PRIVACY_UPDATED) ?>" aria-label="Vilkår og privatlivspolitik opdateret" hidden lang="da">
    <p class="corner-box-text">
        Hej,
        <span data-corner-legal="terms"><a href="/legal/terms">vilkårene</a> (<?= htmlspecialchars(hbHubLegalDanishDate(HBHUB_TERMS_UPDATED)) ?>)</span><span data-corner-legal-and> og </span><span data-corner-legal="privacy"><a href="/legal/privacy">privatlivspolitikken</a> (<?= htmlspecialchars(hbHubLegalDanishDate(HBHUB_PRIVACY_UPDATED)) ?>)</span>
        er blevet opdateret. Ved at fortsætte med at bruge HB Hub accepterer du
        <span data-corner-legal-them>de nye versioner</span>.
    </p>
    <button type="button" class="corner-box-ok" data-corner-terms-ok>Forstået</button>
</aside>
<script src="/assets/js/cornerBoxes.js?v=<?= filemtime(__DIR__ . '/../js/cornerBoxes.js') ?>" defer></script>
<?php
// For anyone logged in: the popup asking them to put HB Hub on their phone's home screen. It starts hidden too;
// appPrompt.js shows it once per browser, and only on phones and tablets.
if (!empty($hbHubUser)):
?>
<dialog class="app-prompt" aria-labelledby="app-prompt-title" data-app-prompt>
    <!-- The dialog itself has no padding, so a click on it (not on this) is a click on the backdrop -->
    <div class="app-prompt-body">
        <img class="app-prompt-icon" src="/assets/images/appIcon192.png?v=<?= filemtime(__DIR__ . '/../images/appIcon192.png') ?>" alt="HB Hub app icon" aria-hidden="true" width="64" height="64">
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
<script src="/assets/js/appPrompt.js?v=<?= filemtime(__DIR__ . '/../js/appPrompt.js') ?>" defer></script>
<?php endif; ?>
