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

const HBHUB_DO_NOT_SELL_UPDATED = '30 October 2026';

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
    <meta name="description" content="HB Hub sælger eller deler aldrig dine personoplysninger, hverken til reklamer eller noget andet. Her er, hvad det betyder, og hvem der stadig ser hvad.">
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
                        Kort svar: HB Hub sælger eller deler ikke dine personoplysninger og har aldrig gjort det. Der er ingen
                        reklamer, ingen sporing og ingen dataaftaler, så du har allerede fravalgt det. Du behøver ikke gøre noget.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_DO_NOT_SELL_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#meaning">Hvad "sælge" og "dele" betyder</a></li>
                        <li><a href="#never">Hvad HB Hub aldrig gør</a></li>
                        <li><a href="#services">Hvem der stadig ser noget</a></li>
                        <li><a href="#gpc">Global Privacy Control</a></li>
                        <li><a href="#ask">Spørg alligevel</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <?php if ($doNotSellHasGpc): ?>
                        <p class="legal-note">
                            Din browser sender et Global Privacy Control-signal. HB Hub ser det og behandler det som en anmodning
                            om ikke at sælge eller dele dine oplysninger, hvilket den i forvejen ikke gør.
                        </p>
                    <?php endif; ?>

                    <section id="meaning" aria-labelledby="do-not-sell-meaning">
                        <h2 id="do-not-sell-meaning">1. Hvad "sælge" og "dele" betyder</h2>
                        <p>
                            Nogle privatlivslove, som Californiens CCPA, giver folk ret til at forhindre en hjemmeside i at
                            <strong>sælge</strong> deres personoplysninger (give dem til en anden virksomhed for penge eller noget
                            andet af værdi) eller <strong>dele</strong> dem (give dem til en anden virksomhed, så den kan vise dig
                            målrettede reklamer ud fra det, du gør på andre sider). Sider, der gør en af delene, skal tilbyde en måde
                            at fravælge det på, som regel på en side som denne.
                        </p>
                        <p>
                            HB Hub bliver drevet fra Danmark, hvor GDPR beskytter dine data i forvejen. Denne side er her, så det er
                            tydeligt for alle, uanset hvor de er.
                        </p>
                    </section>

                    <section id="never" aria-labelledby="do-not-sell-never">
                        <h2 id="do-not-sell-never">2. Hvad HB Hub aldrig gør</h2>
                        <ul>
                            <li>Sælger dine personoplysninger, til nogen, for nogen pris.</li>
                            <li>Deler dem til reklamer. Der er ingen reklamer på HB Hub og ingen reklame-id'er.</li>
                            <li>Følger dig rundt på nettet. Der er ingen sporingspixels, ingen analyseprofiler og ingen sporingscookies.</li>
                            <li>Giver dine beskeder, din profil eller din spilhistorik til andre virksomheder til deres egne formål.</li>
                        </ul>
                        <p>
                            Hvis det nogensinde ændrede sig, ville denne side og <a href="/legal/privacy">privatlivspolitikken</a>
                            blive opdateret først, og du ville få en rigtig måde at sige nej på.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="do-not-sell-services">
                        <h2 id="do-not-sell-services">3. Hvem der stadig ser noget</h2>
                        <p>
                            En hjemmeside kan ikke køre helt af sig selv, så nogle få tjenester er involveret. Intet af det er salg
                            eller deling, da de kun får det, de skal bruge for at gøre deres arbejde:
                        </p>
                        <ul>
                            <li><strong>Simply.com</strong> (Danmark) hoster hjemmesiden og databasen, i EU.</li>
                            <li><strong>Giphy</strong> ser din IP-adresse, når din browser henter en GIF i chatten. Dine GIF-søgninger går gennem HB Hubs server, så Giphy ikke får at vide, hvem der søgte.</li>
                            <li><strong>GitHub</strong> får kun det samlede antal medlemmer og beskeder, til tællerne i README'en.</li>
                            <li><strong>Discord</strong>, kun hvis du logger ind eller opretter dig med Discord, eller kobler din Discord-konto til: du giver lov på Discords egen side, så Discord ser din IP-adresse og at du bruger HB Hub. Mens den er koblet til, spørger HB Hubs server Discord én gang om natten om den tilkoblede kontos nuværende brugernavn, hvilket kun sender Discord dens eget Discord-id tilbage. HB Hub sender aldrig Discord andet om dig.</li>
                        </ul>
                        <p>
                            <a href="/legal/privacy#services">Privatlivspolitikken</a> har alle detaljerne og
                            <a href="/legal/privacy#cookies">listen over cookies</a>, siden bruger (de skal alle bruges for at logge ind).
                        </p>
                    </section>

                    <section id="gpc" aria-labelledby="do-not-sell-gpc">
                        <h2 id="do-not-sell-gpc">4. Global Privacy Control</h2>
                        <p>
                            Global Privacy Control (GPC) er en indstilling i nogle browsere og udvidelser, der fortæller alle de
                            sider, du besøger, at de ikke må sælge eller dele dine data. HB Hub respekterer den, men da intet bliver
                            solgt eller delt, er din oplevelse på siden den samme uanset hvad.
                            <?php if (!$doNotSellHasGpc): ?>
                                Din browser sender det ikke lige nu.
                            <?php endif; ?>
                        </p>
                    </section>

                    <section id="ask" aria-labelledby="do-not-sell-ask">
                        <h2 id="do-not-sell-ask">5. Spørg alligevel</h2>
                        <p>
                            Vil du have det på skrift, en kopi af de data, HB Hub har om dig, eller det hele slettet? Skriv til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, så får du svar inden for en
                            måned. Du behøver ikke give en grund, og det ændrer ikke, hvordan du bliver behandlet på HB Hub at
                            spørge. Dine andre rettigheder står i <a href="/legal/privacy#rights">privatlivspolitikken</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
