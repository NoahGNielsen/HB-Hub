<?php
// "Do not sell or share my personal information": the opt-out page that laws like California's CCPA ask sites to link
// to. HB Hub never sells or shares personal data, so there is nothing to opt out of - this page says so, explains what
// "sell" and "share" mean, which outside services still see something (the same list as privacy.php) and how to ask
// questions anyway. If the browser sends a Global Privacy Control signal (the Sec-GPC: 1 header), it says it was seen.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like terms.php and privacy.php. Open to everyone, like the
// frontpage. The site footer (footer.php) links here.
// Keep it in step with privacy.php: if an outside service is added there, it belongs here too, and
// HBHUB_DO_NOT_SELL_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_DO_NOT_SELL_UPDATED = '30 October 2026';

// Global Privacy Control: browsers with it turned on send "Sec-GPC: 1" on every request
$doNotSellHasGpc = trim($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Do Not Sell or Share My Personal Information - HB Hub</title>
    <meta name="description" content="HB Hub never sells or shares your personal information, for advertising or anything else. Here's what that means and who still sees what.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="do-not-sell-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Legal</p>
                    <h1 id="do-not-sell-title">Do Not Sell or Share My Personal Information</h1>
                    <p class="legal-intro">
                        Short answer: HB Hub doesn't sell or share your personal information, and never has. There are no ads,
                        no tracking and no data deals, so you're already opted out. You don't need to do anything.
                    </p>
                    <p class="legal-updated">Last updated <?= HBHUB_DO_NOT_SELL_UPDATED ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p class="legal-toc-title" aria-hidden="true">On this page</p>
                    <ol>
                        <li><a href="#meaning">What "sell" and "share" mean</a></li>
                        <li><a href="#never">What HB Hub never does</a></li>
                        <li><a href="#services">Who still sees something</a></li>
                        <li><a href="#gpc">Global Privacy Control</a></li>
                        <li><a href="#ask">Asking anyway</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <?php if ($doNotSellHasGpc): ?>
                        <p class="legal-note">
                            Your browser is sending a Global Privacy Control signal. HB Hub sees it and treats it as a request not
                            to sell or share your information, which it already doesn't do.
                        </p>
                    <?php endif; ?>

                    <section id="meaning" aria-labelledby="do-not-sell-meaning">
                        <h2 id="do-not-sell-meaning">1. What "sell" and "share" mean</h2>
                        <p>
                            Some privacy laws, like California's CCPA, give people the right to stop a website from
                            <strong>selling</strong> their personal information (giving it to another company for money or
                            something else of value) or <strong>sharing</strong> it (giving it to another company so they can
                            show you targeted ads based on what you do on other sites). Sites that do either have to offer a way
                            to opt out, usually on a page like this one.
                        </p>
                        <p>
                            HB Hub is run from Denmark, where the GDPR protects your data anyway. This page is here so it's clear
                            to everyone, wherever they are.
                        </p>
                    </section>

                    <section id="never" aria-labelledby="do-not-sell-never">
                        <h2 id="do-not-sell-never">2. What HB Hub never does</h2>
                        <ul>
                            <li>Sell your personal information, to anyone, for any price.</li>
                            <li>Share it for advertising. There are no ads on HB Hub, and no advertising ids.</li>
                            <li>Track you around the web. There are no tracking pixels, no analytics profiles and no tracking cookies.</li>
                            <li>Hand your messages, profile or game history to other companies to use for their own purposes.</li>
                        </ul>
                        <p>
                            If that ever changed, this page and the <a href="/legal/privacy">Privacy Policy</a> would be updated
                            first, and you'd get a real way to say no.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="do-not-sell-services">
                        <h2 id="do-not-sell-services">3. Who still sees something</h2>
                        <p>
                            A website can't run entirely on its own, so a few services are involved. None of this is selling or
                            sharing, as they only get what they need to do their job:
                        </p>
                        <ul>
                            <li><strong>Simply.com</strong> (Denmark) hosts the website and the database, in the EU.</li>
                            <li><strong>Giphy</strong> sees your IP address when your browser loads a GIF in the chat. Your GIF searches go through HB Hub's server, so Giphy doesn't learn who searched.</li>
                            <li><strong>GitHub</strong> only gets the total number of members and messages, for the counters in the README.</li>
                            <li><strong>Discord</strong>, only if you log in or sign up with Discord, or link your Discord account: you allow it on Discord's own page, so Discord sees your IP address and that you use HB Hub. While it's linked, HB Hub's server asks Discord once a night for the linked account's current username, which only sends Discord its own Discord id back. HB Hub never sends Discord anything else about you.</li>
                        </ul>
                        <p>
                            The <a href="/legal/privacy#services">Privacy Policy</a> has the full details, and the
                            <a href="/legal/privacy#cookies">list of cookies</a> the site uses (all of them needed to log in).
                        </p>
                    </section>

                    <section id="gpc" aria-labelledby="do-not-sell-gpc">
                        <h2 id="do-not-sell-gpc">4. Global Privacy Control</h2>
                        <p>
                            Global Privacy Control (GPC) is a setting in some browsers and extensions that tells every site you
                            visit not to sell or share your data. HB Hub respects it, though since nothing is sold or shared,
                            your experience on the site is the same either way.
                            <?php if (!$doNotSellHasGpc): ?>
                                Your browser isn't sending it right now.
                            <?php endif; ?>
                        </p>
                    </section>

                    <section id="ask" aria-labelledby="do-not-sell-ask">
                        <h2 id="do-not-sell-ask">5. Asking anyway</h2>
                        <p>
                            Want it in writing, a copy of the data HB Hub has about you, or all of it deleted? Email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, and you'll get an answer
                            within a month. You don't need to give a reason, and asking won't change how you're treated on
                            HB Hub. Your other rights are listed in the <a href="/legal/privacy#rights">Privacy Policy</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
