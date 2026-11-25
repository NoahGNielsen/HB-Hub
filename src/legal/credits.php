<?php
// Credits: the third-party icons, code, link lists, dictionaries and fonts HB Hub uses, and who made them. Built like the frontpage (index.php) - its
// top bar (homeNav.php), a dark hero, a grid of cards and a dark last call - with homepage.css plus legal.css.
// Open to everyone, like the frontpage, and so in Danish like every public page. The Flaticon attributions ('text',
// 'title') stay word for word as Flaticon gives them.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$creditsUser = $hbHubUser ?? null;

// One entry per icon, with the attribution Flaticon gives you ("copy attribution" on the icon's download page):
// the link, its title and its text. 'use' says where on HB Hub the icon shows up.
const HBHUB_CREDITS_ICONS = [
    ['file' => 'chat.png', 'name' => 'Chat', 'use' => 'Chat i sidebjælken',
        'href' => 'https://www.flaticon.com/free-icons/comments', 'title' => 'comments icons', 'text' => 'Comments icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'controler.png', 'name' => 'Controller', 'use' => 'Spil i sidebjælken',
        'href' => 'https://www.flaticon.com/free-icons/joystick', 'title' => 'joystick icons', 'text' => 'Joystick icons created by Good Ware - Flaticon', 'by' => 'Good Ware'],
    ['file' => 'dice.png', 'name' => 'Terninger', 'use' => 'Casino i sidebjælken',
        'href' => 'https://www.flaticon.com/free-icons/dice', 'title' => 'dice icons', 'text' => 'Dice icons created by Jonggol - Flaticon', 'by' => 'Jonggol'],
    ['file' => 'gif.png', 'name' => 'GIF', 'use' => 'GIF-knappen i chatten',
        'href' => 'https://www.flaticon.com/free-icons/gif-file', 'title' => 'gif file icons', 'text' => 'Gif file icons created by Grand Iconic - Flaticon', 'by' => 'Grand Iconic'],
    ['file' => 'notification.png', 'name' => 'Notifikation', 'use' => 'Klokken, når der er noget nyt',
        'href' => 'https://www.flaticon.com/free-icons/notification', 'title' => 'notification icons', 'text' => 'Notification icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'no-notification.png', 'name' => 'Ingen notifikationer', 'use' => 'Klokken, når der er stille',
        'href' => 'https://www.flaticon.com/free-icons/notification', 'title' => 'notification icons', 'text' => 'Notification icons created by JessHG - Flaticon', 'by' => 'JessHG'],
    ['file' => 'user.png', 'name' => 'Bruger', 'use' => 'Person-ikonet på forsiden og pladsholderen til profilbilledet under oprettelsen',
        'href' => 'https://www.flaticon.com/free-icons/user', 'title' => 'user icons', 'text' => 'User icons created by Magnific - Flaticon', 'by' => 'Magnific'],
    ['file' => 'settings.png', 'name' => 'Indstillinger', 'use' => 'Tandhjulet ved dit navn i chatten og på din egen profil',
        'href' => 'https://www.flaticon.com/free-icons/settings', 'title' => 'settings icons', 'text' => 'Settings icons created by Pixel Perfect - Flaticon', 'by' => 'Pixel Perfect'],
];

$creditsDesigners = count(array_unique(array_column(HBHUB_CREDITS_ICONS, 'by')));
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Krediteringer - HB Hub</title>
    <meta name="description" content="Krediteringer for de ikoner, den kode, de lister og skrifttyper fra andre, som HB Hub bruger.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <!-- Hero: dark like the frontpage's, with the icons stuck on like stickers -->
        <section class="home-hero credits-hero" aria-labelledby="credits-title">
            <div class="home-hero-glow" aria-hidden="true"></div>
            <div class="home-hero-inner">
                <div class="home-hero-text">
                    <h1 id="credits-title">Lavet på skolen,<br><span class="home-hero-accent">med lidt hjælp.</span></h1>
                    <p class="home-lead">
                        HB Hub bruger ikoner, kodebiblioteker, lister, ordbøger og skrifttyper, som andre har lavet
                        og delt gratis. Her er de alle sammen, og hvem der lavede dem. Tak!
                    </p>
                </div>

                <ul class="credits-stickers" aria-hidden="true">
                    <?php foreach (HBHUB_CREDITS_ICONS as $creditsIcon): ?>
                        <li><img src="/assets/images/icons/<?= htmlspecialchars($creditsIcon['file']) ?>" alt="<?= htmlspecialchars($creditsIcon['name']) ?>-ikon"></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <dl class="home-stats">
                <div>
                    <dt>Ikoner</dt>
                    <dd><?= count(HBHUB_CREDITS_ICONS) ?></dd>
                </div>
                <div>
                    <dt>Kodebiblioteker</dt>
                    <dd>4</dd>
                </div>
                <div>
                    <dt>Sortlister</dt>
                    <dd>2</dd>
                </div>
                <div>
                    <dt>Ordbøger</dt>
                    <dd>2</dd>
                </div>
                <div>
                    <dt>Skrifttyper</dt>
                    <dd>5</dd>
                </div>
            </dl>
        </section>

        <!-- Icons -->
        <section class="credits-section" id="icons" aria-labelledby="credits-icons-title">
            <p class="home-kicker credits-kicker">Ikoner</p>
            <h2 id="credits-icons-title">Ikonerne, og hvem der tegnede dem.</h2>
            <p class="credits-section-lead">Alle gratis fra Flaticon. Hver eneste af dem har et job et sted på HB Hub.</p>
            <ul class="credits-grid">
                <?php foreach (HBHUB_CREDITS_ICONS as $creditsIcon): ?>
                    <li class="credits-card">
                        <span class="credits-card-icon"><img src="/assets/images/icons/<?= htmlspecialchars($creditsIcon['file']) ?>" alt="<?= htmlspecialchars($creditsIcon['name']) ?>-ikon" aria-hidden="true" width="28" height="28"></span>
                        <h3><?= htmlspecialchars($creditsIcon['name']) ?></h3>
                        <p class="credits-card-use"><?= htmlspecialchars($creditsIcon['use']) ?></p>
                        <p class="credits-card-by"><a  target="_blank" rel="noopener noreferrer" href="<?= htmlspecialchars($creditsIcon['href']) ?>" title="<?= htmlspecialchars($creditsIcon['title']) ?>"><?= htmlspecialchars($creditsIcon['text']) ?></a></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Code -->
        <section class="credits-section" id="code" aria-labelledby="credits-code-title">
            <p class="home-kicker credits-kicker">Kode</p>
            <h2 id="credits-code-title">Kodebiblioteker, ingen frameworks.</h2>
            <p class="credits-section-lead">Alt andet på HB Hub er almindelig PHP, HTML, CSS og JavaScript.</p>
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
                    <p>Af Kazuhiko Arase. Tegner den QR-kode, du scanner for at sætte totrinslogin op.</p>
                    <p class="credits-license">MIT-licens</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A made-up picture file, squeezed: JPEG XL's logo letters on a card -->
                <div class="credits-jxl" aria-hidden="true">
                    <span class="credits-jxl-letters">JXL</span>
                    <span class="credits-jxl-bar"></span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/jamsinclair/jSquash" target="_blank" rel="noopener noreferrer">jSquash</a> med <a href="https://github.com/libjxl/libjxl" target="_blank" rel="noopener noreferrer">libjxl</a></h3>
                    <p>
                        jSquash af Jamie Sinclair (bygget på Googles Squoosh) kører libjxl, JPEG XL-projektets encoder og
                        decoder, i din browser. Den laver billeder, du vedhæfter i chatten, om til JPEG XL og viser
                        JPEG XL-billeder i browsere, der ikke kan endnu.
                    </p>
                    <p class="credits-license">Apache 2.0- og BSD 3-Clause-licenser</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A made-up video file, like the JPEG XL one, tilted the other way with its bar played most of the way -->
                <div class="credits-jxl credits-webm" aria-hidden="true">
                    <span class="credits-jxl-letters">WebM</span>
                    <span class="credits-jxl-bar"></span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/Vanilagy/mediabunny" target="_blank" rel="noopener noreferrer">Mediabunny</a></h3>
                    <p>
                        Af Vanilagy og bidragydere. Læser videoer, du vedhæfter i chatten, og laver dem, med din browsers
                        egne codecs, om til WebM med VP9, før de bliver sendt.
                    </p>
                    <p class="credits-license">Mozilla Public License 2.0</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A corner of a Minesweeper board around a flag: the squares around it open on hover -->
                <div class="credits-mines" aria-hidden="true">
                    <i class="is-open">1</i><i class="is-open">1</i><i>1</i>
                    <i class="is-open">1</i><i class="is-flag"><img src="/assets/minesweeper/images/flag.png" alt="" width="10" height="14"></i><i>1</i>
                    <i class="is-open">1</i><i>1</i><i>1</i>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/nickarocho/minesweeper" target="_blank" rel="noopener noreferrer">Minesweeper</a></h3>
                    <p>
                        Af Nick Arocho. Hele <a href="/games/minesweeper/">Minesweeper</a>-spillet i Spil-sektionen,
                        med Windows 95-udseende, billeder og skrifttyper, og nogle ændringer til HB Hub: et par små rettelser, og minerne
                        bliver holdt på HB Hubs server, til ranglisten.
                    </p>
                    <p class="credits-license">Ingen licens angivet</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A corner of Tetris' well: a piece above a gap, which drops into it on hover -->
                <div class="credits-blocks" aria-hidden="true">
                    <i></i><i class="is-coral is-falling"></i><i></i><i></i>
                    <i></i><i class="is-coral is-falling"></i><i></i><i></i>
                    <i class="is-sky"></i><i></i><i class="is-sun"></i><i class="is-sun"></i>
                    <i class="is-sky"></i><i></i><i class="is-mint"></i><i class="is-mint"></i>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/larprad/tetris" target="_blank" rel="noopener noreferrer">tetris</a></h3>
                    <p>
                        Af larprad. <a href="/games/tetris/">Tetris</a> i Spil-sektionen er bygget på idéerne i det:
                        en brønd på 10 gange 20, en brik på hold og den næste i forvejen
                        og hastighederne (HB Hub har lagt flere levels ind imellem og efter dem). Koden er skrevet på ny til HB Hub, med sit eget udseende, og
                        brikkerne og pointene bliver holdt på HB Hubs server, til ranglisterne.
                    </p>
                    <p class="credits-license">ISC-licens (angivet i package.json)</p>
                </div>
            </div>
            <p class="credits-fineprint">"QR Code" er et registreret varemærke tilhørende DENSO WAVE INCORPORATED.</p>
            <p class="credits-fineprint">HB Hub har intet med Microsoft at gøre. Windows er et varemærke tilhørende Microsoft Corporation.</p>
            <p class="credits-fineprint">HB Hub har intet med The Tetris Company at gøre. Tetris er et registreret varemærke tilhørende The Tetris Company, LLC.</p>
        </section>

        <!-- Lists: where most of the blocked links in assets/urlBlacklist.txt come from (the IP loggers are picked by hand) -->
        <section class="credits-section" id="lists" aria-labelledby="credits-lists-title">
            <p class="home-kicker credits-kicker">Lister</p>
            <h2 id="credits-lists-title">De links, du ikke kan sende.</h2>
            <p class="credits-section-lead">De fleste links i disse lister er blokeret i chatten og i bioer.</p>
            <div class="credits-library">
                <!-- A long link squeezed into a short one: the long one blurs away on hover, like it does behind a shortener -->
                <div class="credits-short" aria-hidden="true">
                    <span class="credits-short-long"><i></i><i></i><i></i></span>
                    <span class="credits-short-arrow">&darr;</span>
                    <span class="credits-short-link">bit.ly/hb</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/PeterDaveHello/url-shorteners" target="_blank" rel="noopener noreferrer">url-shorteners</a></h3>
                    <p>Af Peter Dave Hello. Linkforkortere, som kan skjule, hvor et link i virkeligheden fører hen.</p>
                    <p class="credits-license">CC BY-SA 4.0-licens</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A made-up scam address with a "Blocked" stamp on it, which stamps down again on hover -->
                <div class="credits-scam" aria-hidden="true">
                    <span class="credits-scam-url">free-c0ins.io</span>
                    <span class="credits-scam-stamp">Blokeret</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/CryptoScamDB/blacklist" target="_blank" rel="noopener noreferrer">CryptoScamDB blacklist</a></h3>
                    <p>Af CryptoScamDB. Kryptosvindel- og phishingsider, lavet om fra dens YAML til en almindelig liste over domæner.</p>
                </div>
            </div>
        </section>

        <!-- Words: where the words Wordle accepts as guesses come from (assets/php/games/wordle/words/). The daily words are picked by hand. -->
        <section class="credits-section" id="words" aria-labelledby="credits-words-title">
            <p class="home-kicker credits-kicker">Ord</p>
            <h2 id="credits-words-title">Alle de ord, du kan gætte.</h2>
            <p class="credits-section-lead">Wordle tjekker dine gæt mod disse ordbøger. Dagens ord bliver valgt fra dem i hånden.</p>
            <div class="credits-library">
                <!-- A guess on a card, its tiles going green on hover like a solved word -->
                <div class="credits-words" aria-hidden="true">
                    <span class="credits-words-row"><i>w</i><i class="is-present">o</i><i>r</i><i class="is-correct">d</i><i>s</i></span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/dolph/dictionary" target="_blank" rel="noopener noreferrer">ENABLE</a></h3>
                    <p>Enhanced North American Benchmark Lexicon, en ordliste lavet til ordspil. Dens ord på fem bogstaver er de engelske ord, Wordle godtager.</p>
                    <p class="credits-license">Offentlig ejendom (public domain)</p>
                </div>
            </div>
            <div class="credits-library">
                <div class="credits-words is-danish" aria-hidden="true">
                    <span class="credits-words-row"><i class="is-correct">b</i><i>j</i><i class="is-present">ø</i><i>r</i><i>n</i></span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://www.stavekontrolden.dk" target="_blank" rel="noopener noreferrer">Stavekontrolden</a></h3>
                    <p>
                        Den danske ordbog fra Foreningen for frit tilgængelige sprogværktøjer, bygget på data fra Det
                        Danske Sprog- og Litteraturselskab. Alle bøjninger af dens ord på fem bogstaver (som "huset" og "spist")
                        er danske ord, Wordle godtager.
                    </p>
                    <p class="credits-license">GPL 2.0-, LGPL 2.1- og MPL 1.1-licenser</p>
                </div>
            </div>
            <p class="credits-fineprint">HB Hubs Wordle er en kopi af Wordle af Josh Wardle. "Wordle" er et varemærke tilhørende The New York Times Company, som intet har med HB Hub at gøre.</p>
        </section>

        <!-- Fonts: the copies in assets/fonts/ (from Google Fonts, Raleway's static weights via Fontsource, each with its license next to it: OFL-*.txt), and Minesweeper's two,
             which came with its code (assets/minesweeper/css/) -->
        <section class="credits-section" id="fonts" aria-labelledby="credits-fonts-title">
            <p class="home-kicker credits-kicker">Skrifttyper</p>
            <h2 id="credits-fonts-title">Bogstaverne på hver side.</h2>
            <p class="credits-section-lead">Alle ligger på HB Hubs egen server, så din browser ikke behøver at spørge nogen andre efter dem.</p>
            <div class="credits-library">
                <!-- A type specimen, going from thin to heavy on hover (Raleway is a variable font) -->
                <div class="credits-font" aria-hidden="true">
                    <span class="credits-font-letters">Aa</span>
                    <span class="credits-font-weights">100 &ndash; 900</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/impallari/Raleway" target="_blank" rel="noopener noreferrer">Raleway</a></h3>
                    <p>
                        Af Matt McInerney, Pablo Impallari og Rodrigo Fuenzalida. Den skrifttype, næsten alt på HB Hub er
                        skrevet med, fra den tyndeste vægt til den tungeste.
                    </p>
                    <p class="credits-license">SIL Open Font License 1.1</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A knight from the chess letters, standing up straight on hover -->
                <div class="credits-font is-chess" aria-hidden="true">
                    <span class="credits-font-letters">♞</span>
                    <span class="credits-font-weights">♔♕♖♗♘♙</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/notofonts/symbols" target="_blank" rel="noopener noreferrer">Noto Sans Symbols 2</a></h3>
                    <p>Af Noto Project Authors, for Google. Kun dens 12 skaktegn, som er brikkerne i skak og på forsidens bræt.</p>
                    <p class="credits-license">SIL Open Font License 1.1</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- Handwriting, like the notebook on the frontpage (its @font-face is in homepage.css) -->
                <div class="credits-font is-hand" aria-hidden="true">
                    <span class="credits-font-letters">Aa</span>
                    <span class="credits-font-weights">æ ø å</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://github.com/googlefonts/caveat" target="_blank" rel="noopener noreferrer">Caveat</a></h3>
                    <p>Af Pablo Impallari. Håndskriften i kladdehæftet og på polaroidet på forsiden.</p>
                    <p class="credits-license">SIL Open Font License 1.1</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- Pixel letters, which go chunky on hover, and one of Minesweeper's numbers in its blue -->
                <div class="credits-font is-pixel" aria-hidden="true">
                    <span class="credits-font-letters">Aa</span>
                    <span class="credits-font-weights">1 2 3</span>
                </div>
                <div class="credits-library-text">
                    <h3><a href="https://www.zone38.net/font/" target="_blank" rel="noopener noreferrer">Press Start</a></h3>
                    <p>Af codeman38, med bogstaver fra gamle Namco-arkadespil. Tallene på Minesweepers bræt.</p>
                </div>
            </div>
            <div class="credits-library">
                <!-- A clock's red digits on black, lighting up on hover -->
                <div class="credits-font is-clock" aria-hidden="true">
                    <span class="credits-font-letters">038</span>
                    <span class="credits-font-weights">0123456789</span>
                </div>
                <div class="credits-library-text">
                    <h3>Digital Dismay</h3>
                    <p>Af NALGames. Den røde minetæller og det ur, der står over Minesweepers bræt.</p>
                </div>
            </div>
        </section>

        <!-- Logos: other companies' logos on HB Hub's buttons -->
        <section class="credits-section" id="logos">
            <p class="credits-fineprint">Discord og Discord-logoet er varemærker tilhørende Discord Inc. HB Hub er ikke tilknyttet Discord Inc.</p>
        </section>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
