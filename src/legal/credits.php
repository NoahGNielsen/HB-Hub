<?php
// Credits: the third-party icons, code and link lists HB Hub uses, and who made them. Built like the frontpage (index.php) - its
// top bar (homeNav.php), a dark hero, a grid of cards and a dark last call - with homepage.css plus legal.css.
// Open to everyone, like the frontpage.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$creditsUser = $hbHubUser ?? null;

// One entry per icon, with the attribution Flaticon gives you ("copy attribution" on the icon's download page):
// the link, its title and its text. 'use' says where on HB Hub the icon shows up.
const HBHUB_CREDITS_ICONS = [
    ['file' => 'chat.png', 'name' => 'Chat', 'use' => 'Chat in the side bar',
        'href' => 'https://www.flaticon.com/free-icons/comments', 'title' => 'comments icons', 'text' => 'Comments icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'controler.png', 'name' => 'Controller', 'use' => 'Games in the side bar',
        'href' => 'https://www.flaticon.com/free-icons/joystick', 'title' => 'joystick icons', 'text' => 'Joystick icons created by Good Ware - Flaticon', 'by' => 'Good Ware'],
    ['file' => 'dice.png', 'name' => 'Dice', 'use' => 'Casino in the side bar',
        'href' => 'https://www.flaticon.com/free-icons/dice', 'title' => 'dice icons', 'text' => 'Dice icons created by Jonggol - Flaticon', 'by' => 'Jonggol'],
    ['file' => 'gif.png', 'name' => 'GIF', 'use' => 'The GIF button in the chat',
        'href' => 'https://www.flaticon.com/free-icons/gif-file', 'title' => 'gif file icons', 'text' => 'Gif file icons created by Grand Iconic - Flaticon', 'by' => 'Grand Iconic'],
    ['file' => 'notification.png', 'name' => 'Notification', 'use' => 'The bell, with something new',
        'href' => 'https://www.flaticon.com/free-icons/notification', 'title' => 'notification icons', 'text' => 'Notification icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'no-notification.png', 'name' => 'No notification', 'use' => 'The bell, when it\'s quiet',
        'href' => 'https://www.flaticon.com/free-icons/notification', 'title' => 'notification icons', 'text' => 'Notification icons created by JessHG - Flaticon', 'by' => 'JessHG'],
    ['file' => 'user.png', 'name' => 'User', 'use' => 'Chat members, and a missing profile picture',
        'href' => 'https://www.flaticon.com/free-icons/user', 'title' => 'user icons', 'text' => 'User icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'settings.png', 'name' => 'Settings', 'use' => 'The cog next to your name in the chat',
        'href' => 'https://www.flaticon.com/free-icons/settings', 'title' => 'settings icons', 'text' => 'Settings icons created by Pixel Perfect - Flaticon', 'by' => 'Pixel Perfect'],
];

$creditsDesigners = count(array_unique(array_column(HBHUB_CREDITS_ICONS, 'by')));
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Credits - HB Hub</title>
    <meta name="description" content="Credits for the third-party icons, code and lists used on HB Hub.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <!-- Hero: dark like the frontpage's, with the icons stuck on like stickers -->
        <section class="home-hero credits-hero" aria-labelledby="credits-title">
            <div class="home-hero-glow" aria-hidden="true"></div>
            <div class="home-hero-inner">
                <div class="home-hero-text">
                    <p class="home-eyebrow">Credits</p>
                    <h1 id="credits-title">Made at school,<br><span class="home-hero-accent">with a little help.</span></h1>
                    <p class="home-lead">
                        HB Hub is written by hand, but not everything on it is drawn by hand.
                        These are the people whose free icons and code it's built with. Thank you!
                    </p>
                </div>

                <ul class="credits-stickers" aria-hidden="true">
                    <?php foreach (HBHUB_CREDITS_ICONS as $creditsIcon): ?>
                        <li><img src="/assets/images/icons/<?= htmlspecialchars($creditsIcon['file']) ?>" alt=""></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <dl class="home-stats">
                <div>
                    <dt>Icons</dt>
                    <dd><?= count(HBHUB_CREDITS_ICONS) ?></dd>
                </div>
                <div>
                    <dt>Designers</dt>
                    <dd><?= $creditsDesigners ?></dd>
                </div>
                <div>
                    <dt>Code libraries</dt>
                    <dd>2</dd>
                </div>
                <div>
                    <dt>Blacklists</dt>
                    <dd>2</dd>
                </div>
            </dl>
        </section>

        <!-- Icons -->
        <section class="credits-section" id="icons" aria-labelledby="credits-icons-title">
            <p class="home-kicker credits-kicker">Icons</p>
            <h2 id="credits-icons-title">The icons, and who drew them.</h2>
            <p class="credits-section-lead">All free from Flaticon. Every one of them has a job somewhere on HB Hub.</p>
            <ul class="credits-grid">
                <?php foreach (HBHUB_CREDITS_ICONS as $creditsIcon): ?>
                    <li class="credits-card">
                        <span class="credits-card-icon"><img src="/assets/images/icons/<?= htmlspecialchars($creditsIcon['file']) ?>" alt="" width="28" height="28"></span>
                        <h3><?= htmlspecialchars($creditsIcon['name']) ?></h3>
                        <p class="credits-card-use"><?= htmlspecialchars($creditsIcon['use']) ?></p>
                        <p class="credits-card-by"><a  target="_blank" rel="noopener noreferrer" href="<?= htmlspecialchars($creditsIcon['href']) ?>" title="<?= htmlspecialchars($creditsIcon['title']) ?>"><?= htmlspecialchars($creditsIcon['text']) ?></a></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Code -->
        <section class="credits-section" id="code" aria-labelledby="credits-code-title">
            <p class="home-kicker credits-kicker">Code</p>
            <h2 id="credits-code-title">Two libraries, no frameworks.</h2>
            <p class="credits-section-lead">Everything else on HB Hub is plain PHP, HTML, CSS and JavaScript.</p>
            <div class="credits-library">
                <div class="credits-qr" aria-hidden="true">
                    <?php
                    // A made-up 15x15 QR code: the three corner squares are real, the rest is just a pattern
                    $creditsQrRows = [
                        '111111101111111', '100000101000001', '101110101011101', '101110101011101', '101110101011101',
                        '100000101000001', '111111101111111', '000000000000000', '111111101101001', '100000100110110',
                        '101110101011010', '101110100100111', '101110101101100', '100000100011011', '111111101010110',
                    ];
                    foreach (str_split(implode('', $creditsQrRows)) as $creditsModule): ?>
                        <i<?= $creditsModule === '1' ? ' class="is-on"' : '' ?>></i>
                    <?php endforeach; ?>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/kazuhikoarase/qrcode-generator" target="_blank" rel="noopener noreferrer">QR Code Generator for JavaScript</a></h3>
                    <p>By Kazuhiko Arase. Draws the QR code you scan to set up two-factor login.</p>
                    <p class="credits-license">MIT license</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A made-up picture file, squeezed: JPEG XL's logo letters on a card -->
                <div class="credits-jxl" aria-hidden="true">
                    <span class="credits-jxl-letters">JXL</span>
                    <span class="credits-jxl-bar"></span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/jamsinclair/jSquash" target="_blank" rel="noopener noreferrer">jSquash</a> with <a href="https://github.com/libjxl/libjxl" target="_blank" rel="noopener noreferrer">libjxl</a></h3>
                    <p>
                        jSquash by Jamie Sinclair (built on Google's Squoosh) runs libjxl, the JPEG XL Project's encoder and
                        decoder, in your browser. It turns pictures you attach in the chat into JPEG XL, and shows
                        JPEG XL pictures in browsers that can't yet.
                    </p>
                    <p class="credits-license">Apache 2.0 and BSD 3-Clause licenses</p>
                </div>
            </div>
            <p class="credits-fineprint">"QR Code" is a registered trademark of DENSO WAVE INCORPORATED.</p>
        </section>

        <!-- Lists: where most of the blocked links in assets/urlBlacklist.txt come from (the IP loggers are picked by hand) -->
        <section class="credits-section" id="lists" aria-labelledby="credits-lists-title">
            <p class="home-kicker credits-kicker">Lists</p>
            <h2 id="credits-lists-title">The links you can't send.</h2>
            <p class="credits-section-lead">Most of the links in these lists are blocked in the chat and bios.</p>
            <div class="credits-library">
                <!-- A long link squeezed into a short one: the long one blurs away on hover, like it does behind a shortener -->
                <div class="credits-short" aria-hidden="true">
                    <span class="credits-short-long"><i></i><i></i><i></i></span>
                    <span class="credits-short-arrow">&darr;</span>
                    <span class="credits-short-link">bit.ly/hb</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/PeterDaveHello/url-shorteners" target="_blank" rel="noopener noreferrer">url-shorteners</a></h3>
                    <p>By Peter Dave Hello. URL shorteners, which can hide where a link really goes.</p>
                    <p class="credits-license">CC BY-SA 4.0 license</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A made-up scam address with a "Blocked" stamp on it, which stamps down again on hover -->
                <div class="credits-scam" aria-hidden="true">
                    <span class="credits-scam-url">free-c0ins.io</span>
                    <span class="credits-scam-stamp">Blocked</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/CryptoScamDB/blacklist" target="_blank" rel="noopener noreferrer">CryptoScamDB blacklist</a></h3>
                    <p>By CryptoScamDB. Crypto scam and phishing sites, turned from its YAML into a plain list of domains.</p>
                </div>
            </div>
        </section>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
