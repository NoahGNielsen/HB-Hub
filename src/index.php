<?php
// The frontpage: what HB Hub is, for visitors, with its own top bar (homeNav.php) instead of the app's nav panel
// (navPanel.php). Logged in users get a way back into the app instead of Log in / Sign up.
$hbHubSessionOptional = true; // the frontpage is open to everyone
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
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

/**
 * Kroner the way the Matador board writes them: 8.000
 */
function homeKroner(int $amount): string
{
    return number_format($amount, 0, ',', '.');
}

// The section icons next to Chat, Casino and Games are 48 x 48 copies (icons/small/) of the 512 x 512 ones the rest of
// the site uses, as they're only shown at 18 px.

// Title deeds shown in the Games section, straight from the board
$homeDeeds = [];
foreach ([1, 21, 39] as $homeField) {
    $homeDeeds[] = HBHUB_MATADOR_FIELDS[$homeField];
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
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <title>HB Hub - Chat, casino and games for HB's students</title>
    <meta name="description" content="HB Hub is where school hangs out online: group chats and DMs, Blackjack, Baccarat and Roulette with play chips, and Matador and chess with your friends.">
    <meta property="og:title" content="HB Hub - The whole school, one tab away">
    <meta property="og:description" content="Group chats with your class, Blackjack with chips that aren't real money, and Matador and chess with your friends. All in your browser.">
    <!-- Tells Google the site's name for search results. A data block, not a script, so the CSP doesn't stop it. -->
    <script type="application/ld+json">{"@context": "https://schema.org", "@type": "WebSite", "name": "HB Hub", "alternateName": "HBHub", "url": "https://hbhub.noahgajnielsen.dk/"}</script>
</head>
<body class="home-body">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <!-- Hero: always dark, like the app's bars -->
        <section class="home-hero" aria-labelledby="home-title">
            <div class="home-hero-glow" aria-hidden="true"></div>
            <div class="home-hero-inner">
                <div class="home-hero-text">
                    <p class="home-eyebrow">Made at school, for school</p>
                    <h1 id="home-title">The whole school,<br><span class="home-hero-accent">one tab away.</span></h1>
                    <p class="home-lead">
                        Group chats with your class, Blackjack with chips that aren't real money,
                        and Matador with friends who'll never let you forget Rådhuspladsen. All in your browser.
                    </p>
                    <div class="home-hero-actions">
                        <?php if ($homeUser === null): ?>
                            <a class="home-btn home-btn-primary home-btn-lg" href="/userMgmt/onboarding">Make an account</a>
                            <a class="home-btn home-btn-glass home-btn-lg" href="/userMgmt/login">I already have one</a>
                        <?php else: ?>
                            <p class="home-welcome">Welcome back, <?= htmlspecialchars($homeUser['userName']) ?>.</p>
                            <a class="home-btn home-btn-primary home-btn-lg" href="/chat/">Go to your chats</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- A made-up group chat, with a chip, a card and a die around it -->
                <div class="home-stage" aria-hidden="true">
                    <img class="home-float home-float-chip" src="/assets/images/icons/chip.svg" alt="">
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
                                <p class="home-chat-title">Friday crew</p>
                                <p class="home-chat-sub">4 members</p>
                            </div>
                        </div>
                        <ul class="home-chat-thread">
                            <li class="home-msg">
                                <span class="home-msg-avatar home-msg-avatar-a">M</span>
                                <p><b>Maja</b>Blackjack after the lesson?</p>
                            </li>
                            <li class="home-msg">
                                <span class="home-msg-avatar home-msg-avatar-b">O</span>
                                <p><b>Oliver</b>Only if I get my 2,500 chips back</p>
                            </li>
                            <li class="home-msg home-msg-me">
                                <p>Nah, i call matador instead :)</p>
                            </li>
                            <li class="home-msg home-msg-notice">
                                <p>Maja invited you to a Matador game</p>
                            </li>
                            <li class="home-msg home-msg-typing">
                                <span class="home-msg-avatar home-msg-avatar-c">S</span>
                                <p><i></i><i></i><i></i></p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <dl class="home-stats">
                <?php if ($homeMembers !== null): ?>
                    <div>
                        <dt>Members</dt>
                        <dd><?= number_format($homeMembers) ?></dd>
                    </div>
                    <div>
                        <dt>Messages sent</dt>
                        <dd><?= number_format($homeMessages) ?></dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt>Chips every day</dt>
                    <dd><?= number_format(HBHUB_CASINO_DAILY_CHIPS) ?></dd>
                </div>
                <div>
                    <dt>Teachers involved</dt>
                    <dd>0</dd>
                </div>
            </dl>
        </section>

        <!-- Chat -->
        <section class="home-feature" id="chat" aria-labelledby="home-chat-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/chat.png" width="18" height="18" alt="">Chat</p>
                <h2 id="home-chat-title">Every group chat you actually need.</h2>
                <p>
                    DMs, group chats for the class, the project and the people you sit with at lunch.
                    Reply, edit, send a GIF, give your friends nicknames, and mute the chat that won't stop.
                </p>
                <ul class="home-ticks">
                    <li>Message requests, so strangers ask first</li>
                    <li>Group owners and admins run their own groups</li>
                    <li>GIFs, smileys and unread counts</li>
                </ul>
                <a class="home-link" href="/chat/">Open chat <span aria-hidden="true">→</span></a>
            </div>
            <div class="home-feature-art" aria-hidden="true">
                <ul class="home-inbox">
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-a">2B</span>
                        <span class="home-inbox-text"><b>2.B</b>Oliver: sent a GIF</span>
                        <span class="home-inbox-count">3</span>
                    </li>
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-b">M</span>
                        <span class="home-inbox-text"><b>Maja</b>see you at lunch</span>
                    </li>
                    <li>
                        <span class="home-inbox-avatar home-msg-avatar-c">P</span>
                        <span class="home-inbox-text"><b>Physics project</b>Sammy: Who has the slides?</span>
                        <span class="home-inbox-count">9+</span>
                    </li>
                    <li class="home-inbox-request">
                        <span class="home-inbox-avatar">P</span>
                        <span class="home-inbox-text"><b>Message request</b>Patrick wants to chat</span>
                        <span class="home-inbox-actions"><span>Accept</span><span>Block</span></span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Casino -->
        <section class="home-feature home-feature-flip" id="casino" aria-labelledby="home-casino-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/dice.png" width="18" height="18" alt="">Casino<?php if (!$homeCasinoOpen): ?><span class="home-soon">Coming soon</span><?php endif; ?></p>
                <h2 id="home-casino-title">Chips, not cash.</h2>
                <p>
                    Everyone starts with <?= number_format(HBHUB_CASINO_START_CHIPS) ?> chips and can collect
                    <?= number_format(HBHUB_CASINO_DAILY_CHIPS) ?> more every day. Lose them all and the only thing
                    you're out is bragging rights.
                </p>
                <ul class="home-ticks">
                    <li>Blackjack solo, or at a table of up to 5</li>
                    <li>Baccarat with 2-6 friends: Player or Banker?</li>
                    <li>European Roulette, every bet on the board</li>
                    <li>Leaderboards, if you're that good!</li>
                </ul>
                <a class="home-link" href="/gambling/">Open the casino <span aria-hidden="true">→</span></a>
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
                        <text class="home-felt-print-big"><textPath href="#home-felt-arc-big" startOffset="50%" text-anchor="middle">BLACKJACK PAYS 3 TO 2</textPath></text>
                        <text class="home-felt-print-small"><textPath href="#home-felt-arc-small" startOffset="50%" text-anchor="middle">DEALER STANDS ON 17</textPath></text>
                    </svg>
                    <div class="home-felt-player">
                        <div class="home-hand">
                            <div class="home-card home-card-black"><span class="home-card-corner">K<br>♠</span><span class="home-card-pip">♠</span></div>
                            <div class="home-card"><span class="home-card-corner">A<br>♥</span><span class="home-card-pip">♥</span></div>
                            <span class="home-hand-total">21</span>
                        </div>
                        <div class="home-bet">
                            <img src="/assets/images/icons/chip.svg" alt="">
                            <img src="/assets/images/icons/chip.svg" alt="">
                            <img src="/assets/images/icons/chip.svg" alt="">
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
                    <p class="home-baccarat-spot is-side">P Pair<small><?= HBHUB_BACCARAT_PAYS['playerPair'] ?> to 1</small></p>
                    <p class="home-baccarat-spot is-side is-tie">Tie<small><?= HBHUB_BACCARAT_PAYS['tie'] ?> to 1</small></p>
                    <p class="home-baccarat-spot is-side is-banker">B Pair<small><?= HBHUB_BACCARAT_PAYS['bankerPair'] ?> to 1</small></p>
                    <p class="home-baccarat-spot">Player<small><?= HBHUB_BACCARAT_PAYS['player'] ?> to 1</small></p>
                    <p class="home-baccarat-spot is-banker">
                        Banker<small><?= HBHUB_BACCARAT_PAYS['banker'] ?> to 1<br>with <?= HBHUB_BACCARAT_BANKER_COMMISSION / 10 ?>% cut</small>
                        <span class="home-baccarat-chips">
                            <img src="/assets/images/icons/chip.svg" alt="">
                            <img src="/assets/images/icons/chip.svg" alt="">
                        </span>
                    </p>
                </div>
            </div>
        </section>

        <!-- Games -->
        <section class="home-feature" id="games" aria-labelledby="home-games-title">
            <div class="home-feature-text">
                <p class="home-kicker"><img src="/assets/images/icons/small/controler.png" width="18" height="18" alt="">Games<?php if (!$homeGamesOpen): ?><span class="home-soon">Coming soon</span><?php endif; ?></p>
                <h2 id="home-games-title">Board games, without the box.</h2>
                <p>
                    Matador, the Danish classic for 2 to 4 players, with houses, hotels and Prøv lykken, and a
                    30 second turn timer so nobody can stall when they're losing. Or chess against a friend, with a
                    clock from 3 to 30 minutes, or none at all.
                </p>
                <ul class="home-ticks">
                    <li>Matador saves after every move, so you can finish tomorrow</li>
                    <li>Draw offers and rematches in chess</li>
                    <li>Wordle: a new word to guess every day, in English and Danish</li>
                    <li>Chats and a leaderboards</li>
                    <li>Public, private with a code or invite only mode</li>
                </ul>
                <a class="home-link" href="/games/">Open games <span aria-hidden="true">→</span></a>
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
            <p class="home-kicker home-kicker-center">Safety</p>
            <h2 id="home-safety-title">Fun, with the brakes built in.</h2>
            <ul class="home-safety-grid">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2"/></svg>
                    <h3>Two-factor login</h3>
                    <p>Add a code from any authenticator app, set up by scanning a QR code.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <h3>Block it during school</h3>
                    <p>Turn off the Casino and Games from 8 AM to 3 PM, or all the time. Switching it back on has to wait.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>
                    <h3>Hard to break into</h3>
                    <p>Strong password rules and a lockout after too many wrong guesses, without locking you out.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M17 6l3 3"/><path d="M14 9l2 2"/></svg>
                    <h3>Passwords stay secret</h3>
                    <p>Passwords are hashed, never stored in plain text. Nobody can read yours, not even the admins.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8l-5 4 5 4"/><path d="M16 8l5 4-5 4"/><path d="M13.5 5l-3 14"/></svg>
                    <h3>Open source</h3>
                    <p>All of the code is public, so you don't have to take our word for it. <a href="https://github.com/NoahGNielsen/HB-Hub" target="_blank" rel="noopener noreferrer">See for yourself on GitHub</a>.</p>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg>
                    <h3>Your account, your call</h3>
                    <p>Delete your account and its data from the settings page whenever you want.</p>
                </li>
            </ul>
        </section>

        <!-- Last call -->
        <section class="home-cta" aria-labelledby="home-cta-title">
            <?php if ($homeUser === null): ?>
                <h2 id="home-cta-title">Your class is already in here.</h2>
                <p>Sign up in under a minute and stop missing the joke.</p>
                <div class="home-hero-actions">
                    <a class="home-btn home-btn-primary home-btn-lg" href="/userMgmt/onboarding">Sign up</a>
                    <a class="home-btn home-btn-glass home-btn-lg" href="/userMgmt/login">Log in</a>
                </div>
            <?php else: ?>
                <h2 id="home-cta-title">Miss anything?</h2>
                <p>Probably. Go catch up.</p>
                <div class="home-hero-actions">
                    <a class="home-btn home-btn-primary home-btn-lg" href="/chat/">Open HB Hub</a>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
