<?php
// The games visitors can play without an account, mostly so search engines have something to find: Wordle,
// Ore Mining Inc., and solo Blackjack, Baccarat and Roulette, at /play (the files are in guestGames/, which is only
// reached through the rewrites in .htaccess). The normal versions (games/, gambling/) still need an account, and
// so does everything else on the site.
//
// Each guest page is the normal game's own page, included by the one in guestGames/ after hbHubGuestGamePage():
// the game's page sees $guestGame (one of HBHUB_GUEST_GAMES, with its key) and swaps the app's nav panel for the
// frontpage's top bar, its title and description for the ones here, and its JSON address for the guest one.
// head.php puts the guest bits in the <head>, top.php the "playing as a guest" bar at the top of the game and
// bottom.php the text about the game under it, with the popup that asks the visitor to sign up after a while
// (guestPrompt.js).
//
// Nothing about a guest is stored on the server. Their game is kept in their own browser instead, as a sealed token
// (hbHubGuestSeal()): the game as JSON, encrypted and signed with the site's key, so the browser can hold on to it
// but can't read or change it. Every request sends it along, the server opens it, plays the move by the same rules
// as the normal game and sends a new one back. That keeps the hidden parts hidden (the shoe, the dealer's hole card)
// and lets the server trust it when it's brought over to an account.
// - Wordle and Ore Mining Inc. keep their token in localStorage. Logging in or signing up from the guest game goes
//   to the normal version, which brings that progress over to the account: today's Wordle games, and the Ore Mining
//   Inc. save, but only into an account that doesn't have them yet - it never writes over anything.
// - The casino games keep theirs in the page only, so the play chips start over every time the page is opened, and
//   they never come over to an account. A guest who runs out gets a new stack (hbHubGuestCasinoRefill()).
require_once __DIR__ . '/../games/access.php';
require_once __DIR__ . '/../casino/access.php';
require_once __DIR__ . '/../loginLimit.php'; // hbHubDeviceToken(), for browsers that have logged in before

const HBHUB_GUEST_TOKEN_MAX = 300000; // characters. An Ore Mining Inc. save late in the game is the biggest, at well under 100 KB
const HBHUB_GUEST_CASINO_CHIPS = 2500; // what a guest starts with at the casino games, and gets again when they run out
const HBHUB_GUEST_SITE = 'https://hbhub.noahgajnielsen.dk';

// Every guest game: its address, the normal version's (where logging in takes them), which section's access it
// follows (games_access or casino_access in the site config: a guest game is only open while its section is open to
// everyone), and its texts for search engines: the <title>, the meta description, the text under the game, its genre
// for the structured data, its keywords (English and Danish searches, added to the site's own in header.php) and what
// the sign-up popup says ({startChips} is what a new account starts with, HBHUB_CASINO_START_CHIPS). A new one also
// needs a line in sitemap.xml and llms.txt.
const HBHUB_GUEST_GAMES = [
    'wordle' => [
        'path' => '/play/wordle',
        'game' => '/games/wordle/',
        'section' => 'games',
        'name' => 'Wordle',
        'title' => 'Free Daily Wordle in English & Danish - No Sign-Up | HB Hub',
        'description' => 'Guess today\'s five-letter word in six tries. A new English and a new Danish Wordle every day at midnight. Free to play in your browser, no account needed.',
        'card' => 'Guess the day\'s five-letter word in six tries, in English or Danish.',
        'genre' => ['Word game', 'Puzzle'],
        'keywords' => ['Wordle', 'Wordle dansk', 'dansk Wordle', 'daily Wordle', 'gratis Wordle', 'ordspil', 'word game'],
        'languages' => ['en', 'da'],
        'about' => [
            'Wordle is the daily word game: you get six tries to guess a five-letter word, and after every guess the letters turn green (right letter, right place), yellow (in the word, somewhere else) or grey (not in it). HB Hub has two a day, one in English and one in Danish (dansk Wordle, with æ, ø and å), and everyone gets the same words.',
            'It\'s free and runs in your browser on a phone, tablet or computer. You can play today\'s words right here without an account. With a free HB Hub account you also get your streak, your stats and a spot on the leaderboard.',
        ],
        'docs' => '/docs/games/wordle',
        'prompt' => 'Make a free account to keep your streak going, see your stats and get on the leaderboard. Today\'s game comes with you.',
    ],
    'oreMiningInc' => [
        'path' => '/play/ore-mining-inc',
        'game' => '/games/ore-mining-inc/',
        'section' => 'games',
        'name' => 'Ore Mining Inc.',
        'title' => 'Ore Mining Inc. - Free Idle Clicker Game Online | HB Hub',
        'description' => 'Mine an asteroid, build a galaxy-wide mining company and prestige across the multiverse in this free idle clicker game. Plays in your browser, no sign-up needed.',
        'card' => 'An idle clicker: mine an asteroid, build a mining company and prestige across the multiverse.',
        'genre' => ['Idle game', 'Clicker game', 'Incremental game'],
        'keywords' => ['Ore Mining Inc.', 'idle game', 'clicker game', 'incremental game', 'browser game', 'gratis spil', 'browserspil'],
        'languages' => ['en'],
        'about' => [
            'Ore Mining Inc. is a free idle clicker game. Click the asteroid to mine ore, spend it on drones, drills and laser rigs that mine for you, and grow from one miner on a rock into a mining company that spans the galaxy. Catch Golden Comets for boosts, unlock upgrades and hot keys, and earn achievements on the way.',
            'When a galaxy runs dry, Warp Jump for Dark Matter, Big Crunch the universe for Antimatter and Big Rip the multiverse for Strange Matter, which buys skills in a big skill tree. Your machines keep mining while you\'re away. Your game is saved in this browser; make a free account to keep it safe on HB Hub, play it on any device and get on the leaderboard.',
        ],
        'docs' => '/docs/games/ore-mining-inc',
        'prompt' => 'Make a free account to keep your mining company safe, play it on any device and get on the leaderboard. Everything you\'ve mined so far comes with you.',
    ],
    'blackjack' => [
        'path' => '/play/blackjack',
        'game' => '/gambling/blackjack/',
        'section' => 'casino',
        'name' => 'Blackjack',
        'title' => 'Free Blackjack Online - Play Solo, No Sign-Up | HB Hub',
        'description' => 'Play free blackjack against the dealer with play chips: 6 decks, blackjack pays 3:2, double, split and insurance. No account, no download and no real money.',
        'card' => 'Beat the dealer without going over 21. Double, split and insurance, with play chips.',
        'genre' => ['Card game', 'Casino game'],
        'keywords' => ['blackjack', 'free blackjack', 'blackjack online', 'gratis blackjack', 'kortspil', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Blackjack is the casino card game where you play against the dealer: get closer to 21 than the dealer without going over. This is a free solo table with play chips, using the same rules as the tables on HB Hub: 6 decks, blackjack pays 3:2, the dealer stands on every 17, and you can double down, split up to 3 hands and take insurance.',
            'The chips are just for fun. They aren\'t money, can\'t be bought or won, and start over every time you open the page. With a free HB Hub account you get your own chips, daily chips, tables with up to 5 friends and a leaderboard.',
        ],
        'docs' => '/docs/casino/blackjack',
        'prompt' => 'Make a free account to get {startChips} chips of your own, daily chips, tables with your friends and a spot on the leaderboard. (The guest chips are just for trying it out, so they don\'t come with you.)',
    ],
    'baccarat' => [
        'path' => '/play/baccarat',
        'game' => '/gambling/baccarat/',
        'section' => 'casino',
        'name' => 'Baccarat',
        'title' => 'Free Baccarat Online - Punto Banco, No Sign-Up | HB Hub',
        'description' => 'Bet on Player, Banker or Tie in free punto banco baccarat with play chips. 8 decks, the real drawing rules and pair bets. No account and no real money needed.',
        'card' => 'Bet on Player, Banker or a tie in punto banco, with play chips.',
        'genre' => ['Card game', 'Casino game'],
        'keywords' => ['baccarat', 'punto banco', 'free baccarat', 'gratis baccarat', 'kortspil', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Baccarat (punto banco) is the casino card game where you bet on which hand ends closest to 9: the Player or the Banker, or that they tie. Nobody plays the hands, the cards are dealt by fixed rules, so it\'s easy to learn. This is a free solo table with play chips, using the same rules as the tables on HB Hub: 8 decks, Player pays 1:1, Banker 1:1 less a small cut, Tie 8:1 and the pairs 11:1.',
            'The chips are just for fun. They aren\'t money, can\'t be bought or won, and start over every time you open the page. With a free HB Hub account you get your own chips, daily chips, tables with up to 6 friends and a leaderboard.',
        ],
        'docs' => '/docs/casino/baccarat',
        'prompt' => 'Make a free account to get {startChips} chips of your own, daily chips, tables with your friends and a spot on the leaderboard. (The guest chips are just for trying it out, so they don\'t come with you.)',
    ],
    'roulette' => [
        'path' => '/play/roulette',
        'game' => '/gambling/roulette/',
        'section' => 'casino',
        'name' => 'Roulette',
        'title' => 'Free European Roulette Online - No Sign-Up | HB Hub',
        'description' => 'Spin a single-zero European roulette wheel for free with play chips: straight, split, street, corner, line and outside bets. No account, no download, no real money.',
        'card' => 'Spin a single-zero wheel and bet straight on the board, with play chips.',
        'genre' => ['Casino game'],
        'keywords' => ['roulette', 'European roulette', 'free roulette', 'roulette online', 'gratis roulette', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Roulette is the casino game of the spinning wheel: bet on where the ball lands. This is European roulette with a single green zero, played free with play chips. Bet on a number (35:1), a split, street, corner or line, or the outside bets: red or black, odd or even, 1-18 or 19-36, a dozen or a column.',
            'The chips are just for fun. They aren\'t money, can\'t be bought or won, and start over every time you open the page. With a free HB Hub account you get your own chips, daily chips and a spot on the leaderboard.',
        ],
        'docs' => '/docs/casino/roulette',
        'prompt' => 'Make a free account to get {startChips} chips of your own, daily chips and a spot on the leaderboard. (The guest chips are just for trying it out, so they don\'t come with you.)',
    ],
];

/**
 * Whether a guest game is open: its section (games_access or casino_access) is open to everyone.
 * An empty user is nobody in particular, so only "everyone" lets them in.
 */
function hbHubGuestGameIsOpen(array $game): bool
{
    return $game['section'] === 'casino' ? hbHubCasinoIsOpenTo([]) : hbHubGamesIsOpenTo([]);
}

/**
 * For the guest pages in guestGames/, after session.php (with $hbHubSessionOptional = true): someone who's logged in
 * is sent to the normal version of the game (with the same ?view= or ?lang=). So is a browser that has ever logged in
 * (it has the hbHubDevice cookie, see loginLimit.php), through the login: the free games are for visitors without an
 * account, and someone who has one should play the real thing. While the game isn't open to everyone the page doesn't
 * exist. Returns the game, with its key in 'key', for the game's page to see as $guestGame.
 */
function hbHubGuestGamePage(string $key, ?array $user): array
{
    $game = HBHUB_GUEST_GAMES[$key] + ['key' => $key];
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $realGame = $game['game'] . ($query !== '' ? '?' . $query : '');
    if ($user !== null) {
        header('Location: ' . $realGame, true, 302);
        exit;
    }
    if (hbHubDeviceToken() !== null) {
        header('Location: /login?return=' . rawurlencode($realGame), true, 302);
        exit;
    }
    if (!hbHubGuestGameIsOpen($game)) {
        require __DIR__ . '/../statusCodesPages/404.php';
        exit;
    }
    return $game;
}

/**
 * For the guest games' JSON endpoints: sends the JSON headers, and answers 403 {"error": "closed"} while the game isn't
 * open to everyone (the page then reloads, and finds it gone) and 405 to anything but a POST. Every guest request is
 * a POST, as each one carries the game's token.
 */
function hbHubGuestGameJson(string $key): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (!hbHubGuestGameIsOpen(HBHUB_GUEST_GAMES[$key])) {
        http_response_code(403);
        echo json_encode(['error' => 'closed']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'methodNotAllowed']);
        exit;
    }
}

/**
 * The key guest tokens are sealed with: guest_games_secret from the site config, or, when that isn't set, one made
 * from the database login (secret too, and the same on every request). Changing it makes every guest's token
 * unreadable, which only means their guest games start over.
 */
function hbHubGuestKey(): string
{
    static $key = null;
    if ($key === null) {
        $siteConfig = require dirname($_SERVER['DOCUMENT_ROOT']) . '/hbHubSiteConfig.php';
        $secret = $siteConfig['guest_games_secret'] ?? null;
        $secret = is_string($secret) && $secret !== '' ? $secret : json_encode($siteConfig['database_credentials'] ?? []);
        $key = hash('sha256', 'HB Hub guest games|' . $secret, true);
    }
    return $key;
}

/**
 * Seals a guest's game into a token for their browser to keep: AES-256-GCM, so it can't be read or changed without
 * the key. $kind ('wordle', 'miner', 'blackjack', 'baccarat' or 'roulette') is sealed in with it, so one game's token
 * can't be passed off as another's. URL-safe base64 of the nonce (12 bytes), the tag (16) and the encrypted JSON.
 */
function hbHubGuestSeal(string $kind, array $data): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $sealed = openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'aes-256-gcm',
        hbHubGuestKey(), OPENSSL_RAW_DATA, $nonce, $tag, $kind);
    return rtrim(strtr(base64_encode($nonce . $tag . $sealed), '+/', '-_'), '=');
}

/**
 * Opens a token from hbHubGuestSeal(). Null when there's none, or it's broken, changed, from another game or sealed
 * with another key - the game then just starts over.
 */
function hbHubGuestOpen(string $kind, mixed $token): ?array
{
    if (!is_string($token) || $token === '' || strlen($token) > HBHUB_GUEST_TOKEN_MAX || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
        return null;
    }
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false || strlen($raw) <= 28) {
        return null;
    }
    $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hbHubGuestKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $kind);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true, 64);
    return is_array($data) ? $data : null;
}

/**
 * Gives a guest a new stack of play chips when they can't afford $minBet any more, between hands. Returns the
 * message to show when it did, or null.
 */
function hbHubGuestCasinoRefill(array &$state, int $minBet): ?string
{
    if ($state['chips'] >= $minBet) {
        return null;
    }
    $state['chips'] = HBHUB_GUEST_CASINO_CHIPS;
    return 'You ran out of chips, so here\'s a new stack of ' . number_format(HBHUB_GUEST_CASINO_CHIPS) . ' to play with.';
}

/**
 * Where the guest game's Log in and Sign up buttons go: the normal version of the game afterwards, which brings the
 * guest's progress over (see the top of this file).
 *
 * @return array{login: string, signup: string}
 */
function hbHubGuestAccountLinks(array $game): array
{
    $return = '?return=' . rawurlencode($game['game']);
    return ['login' => '/login' . $return, 'signup' => '/onboarding' . $return];
}

/**
 * The structured data (schema.org) for a guest game, for search engines: a free browser game. JSON for a
 * <script type="application/ld+json">, which browsers never run, so the CSP's ban on inline scripts doesn't touch it.
 * The slashes in the JSON are escaped, so nothing in it can close the tag.
 */
function hbHubGuestStructuredData(array $game): string
{
    return json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'VideoGame',
        'name' => $game['name'],
        'url' => HBHUB_GUEST_SITE . $game['path'],
        'description' => $game['description'],
        'genre' => $game['genre'],
        'inLanguage' => $game['languages'],
        'gamePlatform' => 'Web browser',
        'applicationCategory' => 'GameApplication',
        'operatingSystem' => 'Any',
        'isAccessibleForFree' => true,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'DKK'],
        'publisher' => ['@type' => 'Organization', 'name' => 'HB Hub', 'url' => HBHUB_GUEST_SITE . '/'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}
