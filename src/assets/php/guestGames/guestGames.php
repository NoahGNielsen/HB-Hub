<?php
// The games visitors can play without an account, mostly so search engines have something to find: Wordle,
// Ore Mining Inc., Minesweeper, Sudoku, Snake, Blockfall, and solo Blackjack, Baccarat, Roulette and Slots, at /play (the files are in guestGames/, which is only
// reached through the rewrites in .htaccess). The normal versions (games/, casino/) still need an account, and
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
// - Minesweeper keeps its board's token in the page only (the frame's game uses it as the game's id), so a board
//   left halfway is gone, and its wins have no leaderboard, so they never come over to an account either.
// - Sudoku has no token: the guest one is a page of its own (guestGames/sudoku/, assets/js/sudokuGuest.js), played
//   only in the browser with the game and best times in localStorage (hbHubSudokuGuest). The members' Sudoku is made
//   and checked on the server for its leaderboard, so a guest's times never come over to an account.
// - Snake has no token either: the members' page plays it only in the browser for a guest (data-snake-guest in
//   assets/js/snake.js), with no server game, and the best scores in localStorage (hbHubSnake). The members' Snake is
//   played along with on the server for its leaderboard, so a guest's scores never come over to an account.
// - Blockfall is the same as Snake: the members' page with data-blockfall-guest (assets/js/blockfall.js), the pieces
//   from Math.random, and the bests in localStorage (hbHubBlockfall), never brought over to an account.
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
// needs a line in sitemap.xml and llms.txt. The texts are in Danish, like every public page (the games themselves are
// the members' own pages, in English, which 'languages' is about).
const HBHUB_GUEST_GAMES = [
    'wordle' => [
        'path' => '/play/wordle',
        'game' => '/games/wordle/',
        'section' => 'games',
        'name' => 'Wordle',
        'title' => 'Gratis dansk og engelsk Wordle hver dag - uden login | HB Hub',
        'description' => 'Gæt dagens ord på fem bogstaver på seks forsøg. Et nyt dansk og et nyt engelsk Wordle hver dag ved midnat. Gratis i din browser, ingen konto nødvendig.',
        'card' => 'Gæt dagens ord på fem bogstaver på seks forsøg, på dansk eller engelsk.',
        'genre' => ['Word game', 'Puzzle'],
        'keywords' => ['Wordle', 'Wordle dansk', 'dansk Wordle', 'daily Wordle', 'gratis Wordle', 'ordspil', 'word game'],
        'languages' => ['en', 'da'],
        'about' => [
            'Wordle er det daglige ordspil: du får seks forsøg til at gætte et ord på fem bogstaver, og efter hvert gæt bliver bogstaverne grønne (rigtigt bogstav, rigtigt sted), gule (i ordet, et andet sted) eller grå (ikke i ordet). HB Hub har to om dagen, et dansk (med æ, ø og å) og et engelsk, og alle får de samme ord.',
            'Det er gratis og kører i din browser på en telefon, tablet eller computer. Du kan spille dagens ord lige her uden en konto. Med en gratis HB Hub-konto får du også din stime, din statistik og en plads på ranglisten.',
        ],
        'docs' => '/docs/games/wordle',
        'prompt' => 'Opret en gratis konto for at holde din stime i gang, se din statistik og komme på ranglisten. Dagens spil kommer med dig.',
    ],
    'oreMiningInc' => [
        'path' => '/play/ore-mining-inc',
        'game' => '/games/ore-mining-inc/',
        'section' => 'games',
        'name' => 'Ore Mining Inc.',
        'title' => 'Ore Mining Inc. - gratis idle clicker-spil online | HB Hub',
        'description' => 'Min en asteroide, byg et mineselskab, der dækker hele galaksen, og prestige på tværs af multiverset i dette gratis idle clicker-spil. Kører i din browser, uden login.',
        'card' => 'Et idle clicker-spil: min en asteroide, byg et mineselskab og prestige på tværs af multiverset.',
        'genre' => ['Idle game', 'Clicker game', 'Incremental game'],
        'keywords' => ['Ore Mining Inc.', 'idle game', 'clicker game', 'incremental game', 'browser game', 'gratis spil', 'browserspil'],
        'languages' => ['en'],
        'about' => [
            'Ore Mining Inc. er et gratis idle clicker-spil (spillet er på engelsk). Klik på asteroiden for at mine malm, brug den på droner, bor og laserrigge, der miner for dig, og voks fra én minearbejder på en sten til et mineselskab, der dækker hele galaksen. Fang Golden Comets for boosts, lås op for opgraderinger og genvejstaster, og tjen præstationer undervejs.',
            'Når en galakse løber tør, så lav et Warp Jump for Dark Matter, et Big Crunch af universet for Antimatter og et Big Rip af multiverset for Strange Matter, som køber færdigheder i et stort færdighedstræ. Dine maskiner bliver ved med at mine, mens du er væk. Dit spil bliver gemt i denne browser; opret en gratis konto for at have det sikkert på HB Hub, spille det på enhver enhed og komme på ranglisten.',
        ],
        'docs' => '/docs/games/ore-mining-inc',
        'prompt' => 'Opret en gratis konto for at have dit mineselskab sikkert, spille det på enhver enhed og komme på ranglisten. Alt, hvad du har minet indtil nu, kommer med dig.',
    ],
    'minesweeper' => [
        'path' => '/play/minesweeper',
        'game' => '/games/minesweeper/',
        'section' => 'games',
        'name' => 'Minesweeper',
        'title' => 'Gratis Minestryger (Minesweeper) online - klassisk Windows-look, uden login | HB Hub',
        'description' => 'Spil klassisk Minesweeper gratis i din browser, med Windows 95-udseende og det hele: brætterne Easy, Medium og Hard, flag, og et første klik, der aldrig er en mine. Ingen konto nødvendig.',
        'card' => 'Klassikeren: åbn alle felter, der ikke skjuler en mine, på et Easy-, Medium- eller Hard-bræt.',
        'genre' => ['Puzzle', 'Logic game'],
        'keywords' => ['Minesweeper', 'free Minesweeper', 'Minesweeper online', 'Minestryger', 'gratis Minestryger', 'puzzle game', 'logic game'],
        'languages' => ['en'],
        'about' => [
            'Minesweeper (minestryger) er den klassiske logikopgave: åbn alle felter på brættet, der ikke skjuler en mine. Hvert felt, du åbner, viser, hvor mange miner der er i de 8 felter rundt om det, så du kan regne ud, hvor de er, og sætte flag på dem (højreklik, Shift + klik eller et langt tryk). Åbner du en mine, er spillet slut. Det første klik er aldrig en mine og åbner altid et område at starte fra.',
            'Vælg Easy (9 gange 9, 10 miner), Medium (16 gange 16, 40 miner) eller Hard (30 gange 30, 160 miner). Det er gratis og kører i din browser, med udseendet fra det gamle Windows-spil. Med en gratis HB Hub-konto tæller dine sejre på ranglisten, med din bedste og gennemsnitlige tid på hvert bræt.',
        ],
        'docs' => '/docs/games/minesweeper',
        'prompt' => 'Opret en gratis konto for at få dine tider på ranglisten og se din bedste og gennemsnitlige tid på hvert bræt.',
    ],
    'sudoku' => [
        'path' => '/play/sudoku',
        'game' => '/games/sudoku/',
        'section' => 'games',
        'name' => 'Sudoku',
        'title' => 'Gratis Sudoku online - fra let til ekspert, uden login | HB Hub',
        'description' => 'Spil gratis Sudoku i din browser: en ny opgave hver gang på Easy, Medium, Hard eller Expert, hver med én løsning. Noter, hints, fortryd og bedste tider. Ingen konto nødvendig.',
        'card' => 'Fyld 9x9-brættet, så hver række, kolonne og boks har 1 til 9 én gang, på Easy, Medium, Hard eller Expert.',
        'genre' => ['Puzzle', 'Logic game'],
        'keywords' => ['Sudoku', 'free Sudoku', 'Sudoku online', 'gratis Sudoku', 'Sudoku dansk', 'puzzle game', 'logic game'],
        'languages' => ['en'],
        'about' => [
            'Sudoku er talgåden: udfyld 9 gange 9-brættet, så hver række, hver kolonne og hver 3 gange 3-boks har tallene 1 til 9 én gang hver. Nogle felter er udfyldt fra start, og de giver dig alt, hvad du skal bruge for at regne resten ud, uden at gætte.',
            'Hvert spil er en ny opgave med præcis én løsning, på fire niveauer: Easy, Medium, Hard og Expert. Skriv noter i felterne, fortryd, slet, eller bed om et hint, når du sidder fast, og se, hvor mange af hvert tal der er tilbage. Det er gratis og kører i din browser; dit spil og din bedste tid på hvert niveau bliver gemt i denne browser, så du kan lukke siden og fortsætte senere. Med en gratis HB Hub-konto tæller dine hurtige løsninger på ranglisten.',
        ],
        'docs' => '/docs/games/sudoku',
        'prompt' => 'Opret en gratis konto for at få dine tider på Sudoku-ranglisten, og spil Matador, skak og UNO med venner.',
    ],
    'snake' => [
        'path' => '/play/snake',
        'game' => '/games/snake/',
        'section' => 'games',
        'name' => 'Snake',
        'title' => 'Gratis Snake online - det klassiske slangespil, uden login | HB Hub',
        'description' => 'Spil det klassiske Snake gratis i din browser: spis æblerne, bliv længere og undgå kanten og din egen hale. Tre hastigheder, piletaster eller swipe. Ingen konto nødvendig.',
        'card' => 'Klassikeren: spis æblerne, bliv længere, og undgå kanten og din egen hale.',
        'genre' => ['Arcade game', 'Snake game'],
        'keywords' => ['Snake', 'Snake game', 'free Snake', 'Snake online', 'slangespil', 'gratis Snake', 'arcade game'],
        'languages' => ['en'],
        'about' => [
            'Snake er det klassiske slangespil: styr slangen rundt på brættet med piletasterne eller W, A, S og D (eller swipe på en telefon), og spis æblerne. Hvert æble gør slangen et felt længere, og spillet er slut, hvis du rammer kanten eller din egen krop. Brættet er 20 gange 12 felter, og fylder slangen det hele, har du vundet.',
            'Vælg mellem Slow, Normal og Fast. Det er gratis og kører i din browser på en telefon, tablet eller computer, og din bedste score ved hver hastighed bliver gemt i denne browser. Med en gratis HB Hub-konto kommer dine spil på Normal og Fast på ranglisten.',
        ],
        'docs' => '/docs/games/snake',
        'prompt' => 'Opret en gratis konto for at få dine scorer på Snake-ranglisten, og spil Matador, skak og UNO med venner.',
    ],
    'blockfall' => [
        'path' => '/play/blockfall',
        'game' => '/games/blockfall/',
        'section' => 'games',
        'name' => 'Blockfall',
        'title' => 'Gratis Blockfall online - puslespillet med faldende brikker, uden login | HB Hub',
        'description' => 'Spil Blockfall gratis i din browser: stabl de faldende brikker, ryd hele linjer, og hold brønden fri. Enduro, et minuts Rush eller et sprint på 20 linjer. Ingen konto nødvendig.',
        'card' => 'Stabl de faldende brikker, ryd hele linjer, og lad ikke brønden blive fuld.',
        'genre' => ['Puzzle', 'Falling block puzzle'],
        'keywords' => ['Blockfall', 'falling block puzzle', 'block puzzle', 'blokspil', 'gratis puslespil', 'puzzle game', 'arcade game'],
        'languages' => ['en'],
        'about' => [
            'Blockfall er HB Hubs puslespil med faldende brikker: syv slags brikker på fire felter falder en ad gangen ned i en brønd, der er 10 felter bred og 20 høj. Flyt og drej dem med tastaturet (eller knapperne på en telefon), så de danner hele vandrette linjer. En hel linje forsvinder og giver point, og jo flere linjer på én gang, jo flere point. Spillet er slut, når der ikke er plads til den næste brik i toppen.',
            'Vælg Enduro, hvor det går hurtigere for hver 10 linjer, til brønden er fuld, Rush, hvor du skal score mest muligt på 60 sekunder, eller Sprint, hvor du skal rydde 20 linjer så hurtigt som muligt. Læg en brik til side med Hold, og se de næste tre. Det er gratis og kører i din browser, og din bedste score og tid bliver gemt i denne browser. Med en gratis HB Hub-konto kommer dine spil på ranglisterne.',
        ],
        'docs' => '/docs/games/blockfall',
        'prompt' => 'Opret en gratis konto for at få dine scorer og tider på Blockfall-ranglisterne, og spil Matador, skak og UNO med venner.',
    ],
    'blackjack' => [
        'path' => '/play/blackjack',
        'game' => '/casino/blackjack/',
        'section' => 'casino',
        'name' => 'Blackjack',
        'title' => 'Gratis Blackjack online - spil alene, uden login | HB Hub',
        'description' => 'Spil gratis blackjack mod dealeren med legechips: 6 kortspil, blackjack betaler 3:2, double, split og forsikring. Ingen konto, ingen download og ingen rigtige penge.',
        'card' => 'Slå dealeren uden at komme over 21. Double, split og forsikring, med legechips.',
        'genre' => ['Card game', 'Casino game'],
        'keywords' => ['blackjack', 'free blackjack', 'blackjack online', 'gratis blackjack', 'kortspil', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Blackjack er casinokortspillet, hvor du spiller mod dealeren: kom tættere på 21 end dealeren uden at komme over. Det her er et gratis bord for dig alene med legechips, med de samme regler som bordene på HB Hub: 6 kortspil, blackjack betaler 3:2, dealeren står på alle 17, og du kan double down, splitte op til 3 hænder og tage forsikring.',
            'Chipsene er kun for sjov. De er ikke penge, kan ikke købes eller vindes, og starter forfra, hver gang du åbner siden. Med en gratis HB Hub-konto får du dine egne chips, daglige chips, borde med op til 5 venner og en rangliste.',
        ],
        'docs' => '/docs/casino/blackjack',
        'prompt' => 'Opret en gratis konto for at få {startChips} chips for dig selv, daglige chips, borde med dine venner og en plads på ranglisten. (Gæstechipsene er kun til at prøve det af, så de kommer ikke med dig.)',
    ],
    'baccarat' => [
        'path' => '/play/baccarat',
        'game' => '/casino/baccarat/',
        'section' => 'casino',
        'name' => 'Baccarat',
        'title' => 'Gratis Baccarat online - Punto Banco, uden login | HB Hub',
        'description' => 'Sats på Player, Banker eller Tie i gratis punto banco-baccarat med legechips. 8 kortspil, de rigtige trækkeregler og par-indsatser. Ingen konto og ingen rigtige penge.',
        'card' => 'Sats på Player, Banker eller uafgjort i punto banco, med legechips.',
        'genre' => ['Card game', 'Casino game'],
        'keywords' => ['baccarat', 'punto banco', 'free baccarat', 'gratis baccarat', 'kortspil', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Baccarat (punto banco) er casinokortspillet, hvor du satser på, hvilken hånd der ender tættest på 9: Player eller Banker, eller at de ender uafgjort. Ingen spiller hænderne, kortene bliver givet efter faste regler, så det er nemt at lære. Det her er et gratis bord for dig alene med legechips, med de samme regler som bordene på HB Hub: 8 kortspil, Player betaler 1:1, Banker 1:1 minus en lille andel, Tie 8:1 og parrene 11:1.',
            'Chipsene er kun for sjov. De er ikke penge, kan ikke købes eller vindes, og starter forfra, hver gang du åbner siden. Med en gratis HB Hub-konto får du dine egne chips, daglige chips, borde med op til 6 venner og en rangliste.',
        ],
        'docs' => '/docs/casino/baccarat',
        'prompt' => 'Opret en gratis konto for at få {startChips} chips for dig selv, daglige chips, borde med dine venner og en plads på ranglisten. (Gæstechipsene er kun til at prøve det af, så de kommer ikke med dig.)',
    ],
    'roulette' => [
        'path' => '/play/roulette',
        'game' => '/casino/roulette/',
        'section' => 'casino',
        'name' => 'Roulette',
        'title' => 'Gratis europæisk Roulette online - uden login | HB Hub',
        'description' => 'Drej et europæisk roulettehjul med ét nul gratis med legechips: straight, split, street, corner, line og de ydre indsatser. Ingen konto, ingen download, ingen rigtige penge.',
        'card' => 'Drej et hjul med ét nul, og sats direkte på bordet, med legechips.',
        'genre' => ['Casino game'],
        'keywords' => ['roulette', 'European roulette', 'free roulette', 'roulette online', 'gratis roulette', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Roulette er casinospillet med det drejende hjul: sats på, hvor kuglen lander. Det her er europæisk roulette med ét grønt nul, spillet gratis med legechips. Sats på et tal (35:1), en split, street, corner eller line, eller de ydre indsatser: rød eller sort, ulige eller lige, 1-18 eller 19-36, et dusin eller en kolonne.',
            'Chipsene er kun for sjov. De er ikke penge, kan ikke købes eller vindes, og starter forfra, hver gang du åbner siden. Med en gratis HB Hub-konto får du dine egne chips, daglige chips og en plads på ranglisten.',
        ],
        'docs' => '/docs/casino/roulette',
        'prompt' => 'Opret en gratis konto for at få {startChips} chips for dig selv, daglige chips og en plads på ranglisten. (Gæstechipsene er kun til at prøve det af, så de kommer ikke med dig.)',
    ],
    'slots' => [
        'path' => '/play/slots',
        'game' => '/casino/slots/',
        'section' => 'casino',
        'name' => 'Slots',
        'title' => 'Gratis spillemaskine online - klassisk med 3 hjul, uden login | HB Hub',
        'description' => 'Drej en gratis klassisk spillemaskine med tre hjul og legechips: fem gevinstlinjer, heldige 7\'ere, diamanter og frugt. Ingen konto, ingen download og ingen rigtige penge.',
        'card' => 'En klassisk spillemaskine med tre hjul, fem gevinstlinjer og heldige 7\'ere, med legechips.',
        'genre' => ['Casino game', 'Slot machine'],
        'keywords' => ['slots', 'slot machine', 'free slots', 'gratis slots', 'spillemaskine', 'enarmet tyveknægt', 'play chips'],
        'languages' => ['en'],
        'about' => [
            'Slots er en klassisk spillemaskine med tre hjul: vælg en indsats, træk i håndtaget, og få tre ens på en af de fem gevinstlinjer (de tre rækker og de to diagonaler). Tre 7\'ere betaler 300 gange linjens indsats, og der er også diamanter, stjerner, klokker og frugt, hvor blandet frugt og et kirsebær til venstre betaler lidt. Det er den samme maskine som i HB Hubs casino, med de samme hjul og den samme gevinsttabel, spillet gratis med legechips.',
            'Chipsene er kun for sjov. De er ikke penge, kan ikke købes eller vindes, og starter forfra, hver gang du åbner siden. Med en gratis HB Hub-konto får du dine egne chips, daglige chips og en plads på ranglisten.',
        ],
        'docs' => '/docs/casino/slots',
        'prompt' => 'Opret en gratis konto for at få {startChips} chips for dig selv, daglige chips og en plads på ranglisten. (Gæstechipsene er kun til at prøve det af, så de kommer ikke med dig.)',
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
 * the key. $kind ('wordle', 'miner', 'minesweeper', 'blackjack', 'baccarat', 'roulette' or 'slots') is sealed in with it, so one game's token
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
    return 'Du løb tør for chips, så her er en ny bunke på ' . number_format(HBHUB_GUEST_CASINO_CHIPS, 0, ',', '.') . ' at spille med.';
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
