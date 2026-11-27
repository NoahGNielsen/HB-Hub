<?php
// The frontpage: what HB Hub is, for visitors, with its own top bar (homeNav.php) instead of the app's nav panel
// (navPanel.php). Logged in users are sent straight on to the chat, unless the address has ?home (what double clicking
// the HB Hub logo on the chat page opens, navBrand.js), and then get a way back into the app instead of Log ind / Opret
// konto. Like every public page (the ones you can see without logging in) it's in Danish; the app behind the login is
// in English.
$hbHubSessionOptional = true; // the frontpage is open to everyone
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
if ($hbHubUser !== null && !isset($_GET['home'])) {
    // A visit from a tagged link (?utm_source=, like the one on noahgajnielsen.dk) is counted here, before the
    // redirect, as header.php never runs for it: as a view of / and under "Visits from links" (pageViews.php), unless
    // the user has turned "Anonymous usage statistics" off, the same check header.php does
    if (isset($_GET['utm_source'])) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userSettings.php';
        if (hbHubUsageStatsOn(hbHubUserSettings($hbHubUser['userSettings'] ?? null))) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pageViews.php';
            hbHubPageViewCount('/', true);
        }
    }
    header('Location: /chat/', true, 302);
    exit;
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/round.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/matador/board.php';
$siteConfig ??= require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';

$homeUser = $hbHubUser ?? null;

// Casino and Games get a "Coming soon" tag while they aren't open to everyone (casino_access / games_access)
$homeCasinoOpen = (int) ($siteConfig['casino_access'] ?? 2) === 1;
$homeGamesOpen = (int) ($siteConfig['games_access'] ?? 2) === 1;

// The same numbers as the README badges (assets/php/github/), left out when the database can't be reached. Members
// leave out deleted (userStatus NULL) and banned accounts; messages count deleted ones too, since they were still sent.
$homeMembers = null;
$homeMessages = null;
try {
    $homeDb = hbHubDatabase();
    $homeStatement = $homeDb->prepare('SELECT COUNT(*) FROM `HBHub-Users` WHERE userStatus IS NOT NULL AND userStatus <> ?');
    $homeStatement->execute([HBHUB_SESSION_STATUS_BANNED]);
    $homeMembers = (int) $homeStatement->fetchColumn();
    $homeMessages = (int) $homeDb->query('SELECT COUNT(*) FROM `HBHub-Messages` WHERE messageEvent = 0')->fetchColumn();
} catch (PDOException $e) {
    error_log('Frontpage: could not count members and messages: ' . $e->getMessage());
}

// Every game and casino round ever played, added together. Counted the way the admin panel's games and casino panels
// count them (adminOverviewGames() and adminOverviewCasino() in admin/overview.php): a multiplayer game is one game
// however many played it (its rows grouped by the room and the second it ended), and games that were left or are
// still going don't count. Blackjack and Baccarat count rounds, Roulette and Slots spins. Ore Mining Inc. has no
// games, so it isn't in it. Left out on its own when a table can't be read.
$homeGames = null;
try {
    $homeGameKey = "CONCAT_WS(',', COALESCE(roomId, 0), roomType, resultTimestamp)";
    $homeGames = (int) $homeDb->query('SELECT
            (SELECT COUNT(DISTINCT ' . $homeGameKey . ') FROM `HBHub-ChessResults`)
          + (SELECT COUNT(DISTINCT ' . $homeGameKey . ') FROM `HBHub-MatadorResults` WHERE playerLeft = 0)
          + (SELECT COUNT(DISTINCT ' . $homeGameKey . ') FROM `HBHub-UnoResults`)
          + (SELECT COUNT(DISTINCT ' . $homeGameKey . ') FROM `HBHub-DoodleResults` WHERE playerLeft = 0)
          + (SELECT COUNT(*) FROM `HBHub-WordleGames` WHERE gameResult <> 0 OR gameDate < CURDATE())
          + (SELECT COUNT(*) FROM `HBHub-MinesweeperGames` WHERE gameResult IN (1, 2))
          + (SELECT COUNT(*) FROM `HBHub-SudokuGames` WHERE gameResult IN (1, 2))
          + (SELECT COUNT(*) FROM `HBHub-SnakeGames` WHERE gameResult = 1)
          + (SELECT COUNT(*) FROM `HBHub-BlockfallGames` WHERE gameResult = 1)
          + (SELECT COUNT(DISTINCT roundId) FROM `HBHub-BlackjackPlayerRounds`)
          + (SELECT COUNT(DISTINCT roundId) FROM `HBHub-BaccaratPlayerRounds`)
          + (SELECT COUNT(*) FROM `HBHub-RouletteSpins`)
          + (SELECT COUNT(*) FROM `HBHub-SlotSpins`)')->fetchColumn();
} catch (Throwable $e) {
    error_log('Frontpage: could not count games played: ' . $e->getMessage());
}

/**
 * A whole number written the Danish way (and the way the Matador board writes kroner): 8.000
 */
function homeKroner(int $amount): string
{
    return number_format($amount, 0, ',', '.');
}

/**
 * A count (messages sent, games played) rounded down to the nearest hundred with a plus after it (1.847 becomes
 * "1.800+"), so the frontpage doesn't need to be exact. Under 100 it's shown as it is, since "0+" would say nothing.
 */
function homeRoundedCount(int $amount): string
{
    return $amount < 100 ? homeKroner($amount) : homeKroner(intdiv($amount, 100) * 100) . '+';
}

// The section icons next to Chat, Casino and Games are 48 x 48 copies (icons/small/) of the 192 x 192 ones the rest of
// the site uses, as they're only shown at 18 px. (The rest are 192 x 192, down from Flaticon's 512 x 512, as nothing
// shows them bigger than 56 px: still sharp on a 3x phone screen, at a third of the bytes.)

// Title deeds shown in the Games section, straight from the classic board
$homeDeeds = [];
foreach ([1, 21, 39] as $homeField) {
    $homeDeeds[] = hbHubMatadorEdition(HBHUB_MATADOR_DEFAULT_EDITION)['fields'][$homeField];
}

// The chessboard in the Games section: Scholar's mate (1. e4 e5 2. Bc4 Nc6 3. Qh5 Nf6?? 4. Qxf7#), a8 first like
// HBHUB_CHESS_START_BOARD is written, white pieces upper case. The last move (h5 to f7) and the king in check are marked.
$homeChessBoard = 'r.bqkb.r' . 'pppp.Qpp' . '..n..n..' . '....p...' . '..B.P...' . '........' . 'PPPP.PPP' . 'RNB.K.NR';
$homeChessLast = ['h5', 'f7'];
$homeChessCheck = 'e8';
// Each piece is the filled chess letter (the pawn told not to turn into an emoji), with the outlined one on top as
// White's edge, like chess.css draws them. The 12 letters come from the Noto Sans Symbols 2 font (assets/fonts/,
// only those 12 letters, @font-face in homepage.css).
$homeChessFilled = ['k' => '♚', 'q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞', 'p' => "♟\u{FE0E}"];
$homeChessOutlined = ['k' => '♔', 'q' => '♕', 'r' => '♖', 'b' => '♗', 'n' => '♘', 'p' => '♙'];
$homeChessSquares = []; // [['class' => 'is-light is-last', 'piece' => '<span ...>'], ...] for the 64 squares, a8 first
for ($homeIndex = 0; $homeIndex < 64; $homeIndex++) {
    $homeFile = $homeIndex % 8;
    $homeRank = 8 - intdiv($homeIndex, 8);
    $homeName = chr(ord('a') + $homeFile) . $homeRank;
    $homeClass = ($homeFile + $homeRank) % 2 === 0 ? 'is-light' : 'is-dark'; // a1 is dark, h1 light
    if (in_array($homeName, $homeChessLast, true)) {
        $homeClass .= ' is-last';
    }
    if ($homeName === $homeChessCheck) {
        $homeClass .= ' is-check';
    }

    $homePiece = $homeChessBoard[$homeIndex];
    $homeType = strtolower($homePiece);
    $homePieceHtml = '';
    if ($homePiece !== '.') {
        $homePieceHtml = $homePiece !== $homeType
            ? '<span class="home-piece is-white" data-edge="' . $homeChessOutlined[$homeType] . '">' . $homeChessFilled[$homeType] . '</span>'
            : '<span class="home-piece">' . $homeChessFilled[$homeType] . '</span>';
    }
    $homeChessSquares[] = ['class' => $homeClass, 'piece' => $homePieceHtml];
}
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php
    $headerHomeNav = true;
    $headerLocale = 'da_DK';
    $headerKeywords = ['HANSENBERG chat', 'HTX klassechat', 'skolechat', 'klassechat', 'Matador online', 'UNO online', 'skak online'];
    include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php';
    ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <title>HB Hub - Chat, casino og spil for elever på HANSENBERG</title>
    <meta name="description" content="HB Hub er lavet af en elev til eleverne på HANSENBERG Tekniske Gymnasium (HTX): klassechats, private beskeder, casino med legechips, og Matador, skak, UNO og Wordle.">
    <meta property="og:title" content="HB Hub - Hele skolen samlet ét sted">
    <meta property="og:description" content="Chat med din HTX-klasse, spil Blackjack om chips i stedet for penge, og tag et slag Matador eller skak med vennerne fra HANSENBERG. Lige i browseren.">
    <!-- Tells Google the site's name for search results, who it's for, and which school it's about ("about", so it can
         tie the site to HANSENBERG when someone searches for the school). A data block, not a script, so the CSP doesn't stop it. -->
    <script type="application/ld+json">{"@context": "https://schema.org", "@type": "WebSite", "name": "HB Hub", "alternateName": "HBHub", "url": "https://hbhub.noahgajnielsen.dk/", "description": "En elevlavet chat-, casino- og spilside for elever på HANSENBERG Tekniske Gymnasium (HTX) i Kolding.", "inLanguage": "da", "keywords": "HANSENBERG, HANSENBERG Tekniske Gymnasium, HTX, Kolding, skolechat", "about": {"@type": "HighSchool", "name": "HANSENBERG", "alternateName": "HANSENBERG Tekniske Gymnasium", "url": "https://www.hansenberg.dk/", "address": {"@type": "PostalAddress", "addressLocality": "Kolding", "addressCountry": "DK"}}, "audience": {"@type": "EducationalAudience", "educationalRole": "student", "audienceType": "Elever på HANSENBERG Tekniske Gymnasium (HTX)"}}</script>
</head>
<body class="home-body">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <!-- Hero: always dark, like the app's bars. "home-front-hero" takes the shared hero's orange glow away
             (credits.php keeps it), so the only thing moving up here is the group chat. -->
        <section class="home-hero home-front-hero" aria-labelledby="home-title">
            <div class="home-hero-inner">
                <div class="home-hero-text">
                    <h1 id="home-title">Hele skolen,<br>samlet ét sted.</h1>
                    <p class="home-lead">
                        Chat med din klasse på HANSENBERG, spil Blackjack om chips, og tag et slag Matador med
                        vennerne, der aldrig glemmer, hvem der ejer Rådhuspladsen. Det hele lige i browseren.
                    </p>
                    <div class="home-hero-actions">
                        <?php if ($homeUser === null): ?>
                            <a class="home-btn home-btn-primary home-btn-lg" href="/onboarding">Opret konto</a>
                            <a class="home-btn home-btn-glass home-btn-lg" href="/login">Jeg har allerede en konto</a>
                        <?php else: ?>
                            <p class="home-welcome">Velkommen tilbage, <?= htmlspecialchars($homeUser['userName']) ?>.</p>
                            <a class="home-btn home-btn-primary home-btn-lg" href="/chat/">Videre til dine chats</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- A made-up group chat, with a chip, a card and a die around it -->
                <div class="home-stage" aria-hidden="true">
                    <img class="home-float home-float-chip" src="/assets/images/icons/chip.svg" alt="Casinochip">
                    <div class="home-float home-float-card">
                        <span class="home-card-corner">A<br>♥</span>
                        <span class="home-card-pip">♥</span>
                    </div>
                    <div class="home-float home-float-die">
                        <i></i><i></i><i></i><i></i><i></i><i></i>
                    </div>

                    <div class="home-chat">
                        <div class="home-chat-head">
                            <span class="home-chat-icon">F</span>
                            <div>
                                <p class="home-chat-title">Fredagsholdet</p>
                                <p class="home-chat-sub">4 medlemmer</p>
                            </div>
                        </div>
                        <ul class="home-chat-thread">
                            <li class="home-msg">
                                <span class="home-msg-avatar home-msg-avatar-a">M</span>
                                <p><b>Maja</b>Blackjack?</p>
                            </li>
                            <li class="home-msg">
                                <span class="home-msg-avatar home-msg-avatar-b">O</span>
                                <p><b>Oliver</b>Dealer skal tabe denne gang!</p>
                            </li>
                            <li class="home-msg home-msg-me">
                                <p>Nej, lad os tage et slag Matador :)</p>
                            </li>
                            <li class="home-msg home-msg-notice">
                                <p>Maja har inviteret dig til Matador</p>
                            </li>
                            <li class="home-msg home-msg-typing">
                                <span class="home-msg-avatar home-msg-avatar-c">S</span>
                                <p><i></i><i></i><i></i></p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- The live numbers, written as one sentence along the bottom of the hero instead of a row of boxes
                 (.home-stats, which the casino lobby and credits still use). Each part is left out on its own when
                 its count couldn't be read; the teachers are always there. -->
            <p class="home-tally">
                <?php if ($homeMembers !== null): ?>
                    <b><?= homeKroner($homeMembers) ?></b> medlemmer har sendt
                    <b><?= homeRoundedCount($homeMessages) ?></b> beskeder*<?= $homeGames !== null ? ' og spillet' : '.' ?>
                <?php elseif ($homeGames !== null): ?>
                    Medlemmerne har spillet
                <?php endif; ?>
                <?php if ($homeGames !== null): ?>
                    <b><?= homeRoundedCount($homeGames) ?></b> spil**.
                <?php endif; ?>
                <span class="home-tally-teachers">Lærere: <b>0</b>.</span>
            </p>
        </section>

        <!-- About: the part that matches the Danish searches for the school ("hansenberg", "hansenberg elever", "htx
             kolding chat") and tells Google which school the site is about. It sits right under the hero so it's one of
             the first things both visitors and search engines read. Says plainly that the school doesn't run it, like
             the terms do. Laid out like the feature sections below it, with the picture on the left so the sides take
             turns with Chat. -->
        <section class="home-feature home-feature-flip home-about" id="om" aria-labelledby="home-about-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/user.png" width="18" height="18" alt="Person-ikon" aria-hidden="true">Om HB Hub</p>
                <h2 id="home-about-title">Et sted kun for eleverne på HANSENBERG.</h2>
                <p>
                    HB Hub er en chat- og spilside for eleverne på HANSENBERG Tekniske Gymnasium (HTX) i Kolding. Din
                    klasse har sin egen klassechat, og du kan skrive med alle de andre elever på skolen, enten privat
                    eller i grupper.
                </p>
                <p>
                    HB Hub er lavet og drives af Noah fra 3.U, med lidt hjælp fra August S. fra 3.X. Siden er ikke lavet af
                    HANSENBERG, og skolen har ingen indflydelse på indholdet.
                </p>
            </div>
            <div class="home-feature-art home-about-art" aria-hidden="true">
                <!-- A polaroid of the student who makes HB Hub, held on by a strip of tape. The photo is a real one
                     of the owner (assets/images/home/creator.jpg, cropped and without its EXIF data). The polaroid is
                     never wider than 250px, so the photo comes in two sizes: creator-small.jpg (240px) for ordinary
                     screens and creator.jpg (480px) for sharp ones. -->
                <figure class="home-polaroid">
                    <img src="/assets/images/home/creator-small.jpg?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/images/home/creator-small.jpg') ?>"
                         srcset="/assets/images/home/creator-small.jpg?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/images/home/creator-small.jpg') ?> 1x,
                                 /assets/images/home/creator.jpg?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/images/home/creator.jpg') ?> 2x"
                         width="240" height="240" alt="Noah, der laver HB Hub" loading="lazy" decoding="async">
                    <figcaption>Noha - ham der har skabt HB Hub</figcaption>
                </figure>
            </div>
        </section>

        <!-- Chat -->
        <section class="home-feature" id="chat" aria-labelledby="home-chat-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/chat.png" width="18" height="18" alt="Chat-ikon" aria-hidden="true">Chat</p>
                <h2 id="home-chat-title">Privat, i grupper eller med hele klassen.</h2>
                <p>
                    Skriv privat, eller lav grupper til klassen, projektet og dem, du spiser frokost med. Svar på
                    beskeder, send GIFs, giv vennerne kælenavne, og sæt den gruppe, der aldrig bliver
                    stille, på lydløs.
                </p>
                <ul class="home-ticks">
                    <li>Beskedanmodninger</li>
                    <li>Ejere og admins bestemmer selv over deres grupper</li>
                    <li>GIFs, emojis og styr på ulæste beskeder</li>
                </ul>
                <a class="home-link" href="/chat/">Gå til chatten</a>
            </div>
            <div class="home-feature-art" aria-hidden="true">
                <ul class="home-inbox">
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-a">2B</span>
                        <span class="home-inbox-text"><b>2.B</b>Oliver har sendt en GIF</span>
                        <span class="home-inbox-count">3</span>
                    </li>
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-b">M</span>
                        <span class="home-inbox-text"><b>Maja</b>ses til frokost</span>
                    </li>
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-c">F</span>
                        <span class="home-inbox-text"><b>Fysikprojekt</b>Sammy: Hvem laver slides?</span>
                        <span class="home-inbox-count">9+</span>
                    </li>
                    <li class="home-inbox-request">
                        <span class="home-inbox-avatar">P</span>
                        <span class="home-inbox-text"><b>Beskedanmodning</b>Patrick vil gerne skrive med dig</span>
                        <span class="home-inbox-actions"><span>Accepter</span><span>Bloker</span></span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Casino -->
        <section class="home-feature home-feature-flip" id="casino" aria-labelledby="home-casino-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/dice.png" width="18" height="18" alt="Terning-ikon" aria-hidden="true">Casino<?php if (!$homeCasinoOpen): ?><span class="home-soon">Kommer snart</span><?php endif; ?></p>
                <h2 id="home-casino-title">Spil om chips, ikke penge.</h2>
                <p>
                    Du starter med <?= homeKroner(HBHUB_CASINO_START_CHIPS) ?> chips og kan hente mindst
                    <?= homeKroner(HBHUB_CASINO_DAILY_CALENDAR[0]) ?> nye hver dag, helt op til
                    <?= homeKroner(max(HBHUB_CASINO_DAILY_CALENDAR)) ?>, når du har hentet dem <?= HBHUB_CASINO_DAILY_DAYS ?> dage i træk.
                </p>
                <ul class="home-ticks">
                    <li>Spil alene eller ved sammen med vennerne</li>
                    <li>Kortspil og roulette med de klassiske regler</li>
                    <li>Gratis chips hver dag, og flere jo oftere du henter dem</li>
                    <li>Ranglister, så alle kan se, hvem der er bedst</li>
                </ul>
                <a class="home-link" href="/casino/">Gå til casinoet</a>
            </div>
            <div class="home-feature-art home-casino-art" aria-hidden="true">
                <!-- A blackjack table seen from above: the dealer's hand at the top, the table's print in an arc,
                     and your hand (a blackjack) next to your bet at the bottom -->
                <div class="home-felt">
                    <div class="home-hand home-hand-dealer">
                        <div class="home-card home-card-back"></div>
                        <div class="home-card home-card-black"><span class="home-card-corner">9<br>♣</span><span class="home-card-pip">♣</span></div>
                    </div>
                    <svg class="home-felt-print" viewBox="0 0 300 84">
                        <path id="home-felt-arc-big" d="M 6 8 Q 150 92 294 8" fill="none"/>
                        <path id="home-felt-arc-small" d="M 40 34 Q 150 108 260 34" fill="none"/>
                        <text class="home-felt-print-big"><textPath href="#home-felt-arc-big" startOffset="50%" text-anchor="middle">BLACKJACK BETALER 3 TIL 2</textPath></text>
                        <text class="home-felt-print-small"><textPath href="#home-felt-arc-small" startOffset="50%" text-anchor="middle">DEALEREN STÅR PÅ 17</textPath></text>
                    </svg>
                    <div class="home-felt-player">
                        <div class="home-hand">
                            <div class="home-card home-card-black"><span class="home-card-corner">K<br>♠</span><span class="home-card-pip">♠</span></div>
                            <div class="home-card"><span class="home-card-corner">A<br>♥</span><span class="home-card-pip">♥</span></div>
                            <span class="home-hand-total">21</span>
                        </div>
                        <div class="home-bet">
                            <img src="/assets/images/icons/chip.svg" alt="Casinochip">
                            <img src="/assets/images/icons/chip.svg" alt="Casinochip">
                            <img src="/assets/images/icons/chip.svg" alt="Casinochip">
                        </div>
                    </div>
                </div>
                <!-- And a European roulette wheel in front of it, the ball resting on 17 -->
                <div class="home-roulette">
                    <div class="home-roulette-pockets"><span class="home-roulette-ball"></span></div>
                </div>
                <!-- And a piece of a Baccarat table in the other corner: the Player and the Banker side by side, with
                     the side bets above them (each pair over its own side, the Tie in between), and a bet on the
                     Banker. The payouts are the table's own (round.php). -->
                <div class="home-baccarat">
                    <p class="home-baccarat-spot is-side">P-Pair<small><?= HBHUB_BACCARAT_PAYS['playerPair'] ?> til 1</small></p>
                    <p class="home-baccarat-spot is-side is-tie">Tie<small><?= HBHUB_BACCARAT_PAYS['tie'] ?> til 1</small></p>
                    <p class="home-baccarat-spot is-side is-banker">B-Pair<small><?= HBHUB_BACCARAT_PAYS['bankerPair'] ?> til 1</small></p>
                    <p class="home-baccarat-spot">PLAYER<small><?= HBHUB_BACCARAT_PAYS['player'] ?> til 1</small></p>
                    <p class="home-baccarat-spot is-banker">
                        Banker<small><?= HBHUB_BACCARAT_PAYS['banker'] ?> til 1<br>minus <?= str_replace('.', ',', (string) (HBHUB_BACCARAT_BANKER_COMMISSION / 10)) ?> %</small>
                        <span class="home-baccarat-chips">
                            <img src="/assets/images/icons/chip.svg" alt="Casinochip">
                            <img src="/assets/images/icons/chip.svg" alt="Casinochip">
                        </span>
                    </p>
                </div>
            </div>
        </section>

        <!-- Games -->
        <section class="home-feature" id="games" aria-labelledby="home-games-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/controler.png" width="18" height="18" alt="Controller-ikon" aria-hidden="true">Spil<?php if (!$homeGamesOpen): ?><span class="home-soon">Kommer snart</span><?php endif; ?></p>
                <h2 id="home-games-title">Brætspil, uden at finde æsken frem.</h2>
                <p>
                    Klassiske bræt- og kortspil, du kan spille med vennerne, daglige udfordringer og andre spil til
                    de lange mellemtimer.
                </p>
                <ul class="home-ticks">
                    <li>Spil med vennerne eller alene</li>
                    <li>Spillet bliver gemt, så I kan spille videre i morgen</li>
                    <li>Offentlige rum, eller private</li>
                    <li>Chat med de andre ved bordet, mens I spiller</li>
                    <li>Ranglister og revanchekampe</li>
                </ul>
                <a class="home-link" href="/games/">Se spillene</a>
            </div>
            <div class="home-feature-art home-games-art" aria-hidden="true">
                <!-- A chessboard just after Scholar's mate -->
                <div class="home-chess">
                    <div class="home-chess-board">
                        <?php foreach ($homeChessSquares as $homeChessSquare): ?>
                            <span class="home-chess-square <?= $homeChessSquare['class'] ?>"><?= $homeChessSquare['piece'] ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- And three Matador title deeds fanned out in front of its corner -->
                <div class="home-deeds">
                    <?php foreach ($homeDeeds as $homeDeed): ?>
                        <div class="home-deed home-deed-g<?= (int) $homeDeed['group'] ?>">
                            <p class="home-deed-band">Skøde<b><?= htmlspecialchars($homeDeed['name']) ?></b></p>
                            <dl>
                                <div><dt>Leje</dt><dd>kr. <?= homeKroner($homeDeed['rent'][0]) ?></dd></div>
                                <div><dt>Med hotel</dt><dd>kr. <?= homeKroner($homeDeed['rent'][5]) ?></dd></div>
                                <div><dt>Hus</dt><dd>kr. <?= homeKroner($homeDeed['house']) ?></dd></div>
                            </dl>
                            <p class="home-deed-price">kr. <?= homeKroner($homeDeed['price']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Safety -->
        <section class="home-safety" id="safety" aria-labelledby="home-safety-title">
            <div class="home-safety-intro">
                <h2 id="home-safety-title">Sjovt, og sikkert.</h2>
                <p>Din konto er godt beskyttet, og du bestemmer selv, hvornår du vil spille.</p>
            </div>
            <ul class="home-safety-list">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2"/></svg>
                    <h3>Totrinslogin</h3>
                    <p>Log ind med en ekstra kode fra din godkendelsesapp. Du sætter det op ved at scanne en QR-kode.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <h3>Sluk for det i skoletiden</h3>
                    <p>Slå Casino og Spil fra mellem kl. 8 og 15, eller helt. Vil du slå det til igen, skal du vente lidt.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>
                    <h3>Svær at hacke</h3>
                    <p>Krav om stærke adgangskoder og en spærring efter for mange forkerte forsøg, uden at du selv bliver låst ude.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M17 6l3 3"/><path d="M14 9l2 2"/></svg>
                    <h3>Din adgangskode er kun din</h3>
                    <p>Adgangskoder bliver hashet og gemmes IKKE som tekst. Ingen kan se din adganskode, heller ikke admins.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8l-5 4 5 4"/><path d="M16 8l5 4-5 4"/><path d="M13.5 5l-3 14"/></svg>
                    <h3>Open source</h3>
                    <p>Al koden er offentlig, så du behøver ikke bare stole på os. <a href="https://github.com/NoahGNielsen/HB-Hub?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">Se selv på GitHub</a>.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg>
                    <h3>Din konto, dit valg</h3>
                    <p>Du kan til enhver tid slette din konto og dens data under indstillinger.</p>
                </li>
            </ul>
        </section>

        <!-- Last call -->
        <section class="home-cta" aria-labelledby="home-cta-title">
            <?php if ($homeUser === null): ?>
                <h2 id="home-cta-title">Din klasse er her allerede.</h2>
                <p>Det tager under et minut at oprette en konto, og så misser du ikke det næste spil.</p>
                <div class="home-hero-actions">
                    <a class="home-btn home-btn-primary home-btn-lg" href="/onboarding">Opret konto</a>
                    <a class="home-btn home-btn-glass home-btn-lg" href="/login">Log ind</a>
                </div>
            <?php else: ?>
                <h2 id="home-cta-title">Har du misset noget?</h2>
                <p>Helt sikkert. Kom ind og se, hvad der er sket.</p>
                <div class="home-hero-actions">
                    <a class="home-btn home-btn-primary home-btn-lg" href="/chat/">Åbn HB Hub</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- What the * and ** on the hero's numbers mean: * how the messages are rounded (homeRoundedCount()), ** the
             same rounding plus what counts as a game played ($homeGames). Each is only there when its number is shown. -->
        <?php if ($homeMessages !== null || $homeGames !== null): ?>
            <div class="home-footnote">
                <?php if ($homeMessages !== null): ?>
                    <p>* Rundet ned til nærmeste hundrede.</p>
                <?php endif; ?>
                <?php if ($homeGames !== null): ?>
                    <p>
                        ** Rundet ned til nærmeste hundrede. Tæller alle færdigspillede spil. Et spil med flere
                        spillere tæller som ét. I Blackjack og Baccarat tæller hver runde, i Roulette og Slots
                        hvert spin. Forladte spil, spil i gang og Ore Mining Inc. tæller ikke med.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
