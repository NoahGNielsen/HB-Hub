<?php
// Set $hbHubShowSiteFooter = true before including this file to show the site footer (legal links and copyright).
// Only the frontpage, login and onboarding do - everywhere else this just renders the toast.
if (!empty($hbHubShowSiteFooter)):
    // header.php has normally loaded the config already (through maintenance.php)
    $siteConfig ??= require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
    $footerYear = (int) ($siteConfig['footer_copyrightYear'] ?? date('Y'));
?>
<footer class="site-footer">
    <nav class="site-footer-links" aria-label="Legal">
        <a href="/legal/terms">Terms</a>
        <a href="/legal/privacy">Privacy</a>
        <a href="/legal/credits">Credits</a>
    </nav>
    <p class="site-footer-copyright">&copy; <?= (int) date('Y') > $footerYear ? $footerYear . '&ndash;' . date('Y') : $footerYear ?> Noah Gaj Nedergaard Nielsen</p>
</footer>
<?php endif;

require_once __DIR__ . '/toast.php';
hbHubRenderToast();
