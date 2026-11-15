<?php
// "Do not sell or share my personal information": the opt-out page that laws like California's CCPA ask sites to link
// to. HB Hub never sells or shares personal data, so there is nothing to opt out of - this page says so, explains what
// "sell" and "share" mean, which outside services still see something (the same list as privacy.php) and how to ask
// questions anyway. If the browser sends a Global Privacy Control signal (the Sec-GPC: 1 header), it says it was seen.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like terms.php and privacy.php. Open to everyone, like the
// frontpage, and so in Danish like every public page. The site footer (footer.php) links here.
// Keep it in step with privacy.php: if an outside service is added there, it belongs here too, and
// HBHUB_DO_NOT_SELL_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php'; // only for hbHubLegalDanishDate()

const HBHUB_DO_NOT_SELL_UPDATED = '15 November 2026';

// Global Privacy Control: browsers with it turned on send "Sec-GPC: 1" on every request
$doNotSellHasGpc = trim($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Sælg eller del ikke mine personoplysninger - HB Hub</title>
    <meta name="description" content="HB Hub sælger og deler ikke dine personoplysninger. Læs, hvad det betyder, og hvilke tjenester der indgår i driften af siden.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="do-not-sell-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Juridisk</p>
                    <h1 id="do-not-sell-title">Sælg eller del ikke mine personoplysninger</h1>
                    <p class="legal-intro">
                        HB Hub sælger og deler ikke dine personoplysninger og har aldrig gjort det. Siden har ingen reklamer,
                        ingen sporing og ingen dataaftaler med andre. Du skal derfor ikke foretage dig noget for at fravælge det.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_DO_NOT_SELL_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#meaning">Baggrund</a></li>
                        <li><a href="#never">Hvad HB Hub ikke gør</a></li>
                        <li><a href="#services">Eksterne tjenester</a></li>
                        <li><a href="#gpc">Global Privacy Control</a></li>
                        <li><a href="#ask">Kontakt</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <?php if ($doNotSellHasGpc): ?>
                        <p class="legal-note">
                            Din browser sender et Global Privacy Control-signal. HB Hub behandler det som en anmodning om ikke at
                            sælge eller dele dine oplysninger, hvilket i forvejen ikke sker.
                        </p>
                    <?php endif; ?>

                    <section id="meaning" aria-labelledby="do-not-sell-meaning">
                        <h2 id="do-not-sell-meaning">1. Baggrund</h2>
                        <p>
                            Visse privatlivslove, fx Californiens CCPA, giver ret til at fravælge, at en hjemmeside
                            <strong>sælger</strong> dine personoplysninger (overdrager dem til en anden virksomhed mod betaling
                            eller anden værdi) eller <strong>deler</strong> dem (overdrager dem til brug for målrettede reklamer).
                            Hjemmesider, der gør det, skal tilbyde en side som denne.
                        </p>
                        <p>
                            HB Hub drives fra Danmark, hvor dine data er beskyttet af GDPR. Siden findes, så det er klart for alle,
                            uanset hvor de bor.
                        </p>
                    </section>

                    <section id="never" aria-labelledby="do-not-sell-never">
                        <h2 id="do-not-sell-never">2. Hvad HB Hub ikke gør</h2>
                        <p>Det betyder, at HB Hub:</p>
                        <ul>
                            <li>sælger ikke dine personoplysninger,</li>
                            <li>deler dem ikke til reklamer (der er ingen reklamer eller reklame-id'er på siden),</li>
                            <li>sporer dig ikke på tværs af hjemmesider (der er ingen sporingspixels, analyseprofiler eller sporingscookies),</li>
                            <li>bruger kun browserfingeraftryk til at forhindre misbrug, aldrig til reklamer eller sporing, og du kan fravælge det med Do Not Track (se <a href="/legal/privacy#fingerprint">privatlivspolitikken</a>),</li>
                            <li>giver ikke dine beskeder, din profil eller din spilhistorik til andre virksomheder til deres egne formål.</li>
                        </ul>
                        <p>
                            Skulle det ændre sig, opdateres denne side og <a href="/legal/privacy">privatlivspolitikken</a> først,
                            og du får mulighed for at sige nej.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="do-not-sell-services">
                        <h2 id="do-not-sell-services">3. Eksterne tjenester</h2>
                        <p>
                            Nogle få tjenester indgår i driften af siden. De modtager kun det, der er nødvendigt for deres opgave,
                            og det er hverken salg eller deling:
                        </p>
                        <ul>
                            <li><strong>Simply.com</strong> (Danmark) hoster hjemmesiden og databasen i EU.</li>
                            <li><strong>Giphy</strong> ser din IP-adresse, når en GIF vises i chatten. Søgninger sendes via HB Hubs server, så Giphy ikke ved, hvem der søger.</li>
                            <li><strong>GitHub</strong> modtager kun det samlede antal medlemmer og beskeder til sidens README.</li>
                            <li>
                                <strong>Discord</strong>, kun hvis du logger ind, opretter dig eller kobler din konto til med Discord.
                                Discord ser da din IP-adresse, og at du bruger HB Hub. Mens kontoen er koblet til, spørger HB Hubs
                                server hver nat Discord om dit nuværende brugernavn ud fra dit Discord-id. Andre oplysninger om dig
                                sendes ikke til Discord.
                            </li>
                        </ul>
                        <p>
                            Detaljerne står i <a href="/legal/privacy#services">privatlivspolitikken</a> sammen med
                            <a href="/legal/privacy#cookies">listen over cookies</a>, som alle er nødvendige for, at siden fungerer.
                        </p>
                    </section>

                    <section id="gpc" aria-labelledby="do-not-sell-gpc">
                        <h2 id="do-not-sell-gpc">4. Global Privacy Control</h2>
                        <p>
                            Global Privacy Control (GPC) er en indstilling i visse browsere og udvidelser, der beder hjemmesider om
                            ikke at sælge eller dele dine data. HB Hub respekterer signalet, men da intet sælges eller deles, fungerer
                            siden ens, uanset om det er slået til.
                            <?php if (!$doNotSellHasGpc): ?>
                                Din browser sender ikke signalet i øjeblikket.
                            <?php endif; ?>
                        </p>
                    </section>

                    <section id="ask" aria-labelledby="do-not-sell-ask">
                        <h2 id="do-not-sell-ask">5. Kontakt</h2>
                        <p>
                            Ønsker du en skriftlig bekræftelse, en kopi af dine data eller at få dem slettet, kan du skrive til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. Du får svar inden for en måned.
                            Du behøver ikke begrunde din henvendelse, og den har ingen betydning for, hvordan du behandles på HB Hub.
                            Dine øvrige rettigheder står i <a href="/legal/privacy#rights">privatlivspolitikken</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
