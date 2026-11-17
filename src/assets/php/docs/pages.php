<?php
// The help docs (src/docs/): every page, grouped the way the side menu and the docs' front page (docs/index.php) list
// them. top.php and bottom.php build each page around its text from this list, so a new page only needs a line here
// (and one in sitemap.xml and llms.txt). The order here is also the order of the Previous / Next links under each page.
//
// Each section is key => ['title', 'icon' (an emoji), 'pages' => [path => [title, summary]]]. The path is the page's
// clean URL (a folder's index.php is its folder, with the slash at the end), the summary is one sentence, used for the
// meta description and the cards on the docs' front page.

const HBHUB_DOCS_SECTIONS = [
    'start' => [
        'title' => 'Kom i gang',
        'icon' => '👋',
        'pages' => [
            '/docs/' => ['Hjælp', 'Alt det, HB Hub kan, og hvordan du bruger det.'],
            '/docs/getting-started' => ['Kom i gang', 'At oprette en konto, logge ind og finde rundt på HB Hub.'],
            '/docs/account' => ['Profil og indstillinger', 'Dit profilbillede, navn og din bio, din adgangskode, dine enheder og at slette din konto.'],
            '/docs/two-factor' => ['Totrinslogin og sikkerhed', 'At slå totrinslogin til, logge ind med Discord, reglerne for adgangskoder og hvad der sker efter forkerte gæt.'],
            '/docs/notifications' => ['Notifikationer', 'Klokken, browsernotifikationer, beskedlyden og at gøre chats lydløse.'],
            '/docs/self-blocking' => ['Bloker Casino og Spil', 'At blokere Casino og Spil for dig selv, i skoletiden eller hele tiden.'],
            '/docs/feedback' => ['Feedback og hjælp', 'At rapportere fejl og folk, sende idéer, og hvem du kan spørge om hjælp.'],
        ],
    ],
    'chat' => [
        'title' => 'Chat',
        'icon' => '💬',
        'pages' => [
            '/docs/chat/' => ['Grundlæggende chat', 'Private beskeder, beskedanmodninger, svar, reaktioner, at rette og slette, fastgjorte beskeder og chatmenuen.'],
            '/docs/chat/formatting' => ['Formatér dine beskeder', 'Fed, kursiv, understregning, kode, overskrifter, lister og links i dine beskeder.'],
            '/docs/chat/groups' => ['Gruppechats', 'At lave en gruppe, dens ejer og admins, kælenavne, at ændre dens navn, ikon og baggrund, og din klassechat.'],
            '/docs/chat/attachments' => ['Filer og billeder', 'At sende filer og billeder, billedfremviseren, og hvordan billetter til vedhæftede filer virker.'],
            '/docs/chat/gifs' => ['GIFs og smileys', 'At sende GIFs fra Giphy og smileys fra smileymenuen.'],
            '/docs/chat/mentions' => ['@omtaler', 'At omtale nogen i en chat eller en spilchat, og hvem der får besked.'],
            '/docs/chat/safety' => ['Tryg i chatten', 'At blokere folk, sikre links, spamgrænsen og at anmelde nogen.'],
        ],
    ],
    'casino' => [
        'title' => 'Casino',
        'icon' => '🎰',
        'pages' => [
            '/docs/casino/' => ['Sådan virker casinoet', 'Chips, de daglige chips, borde og koder, ranglister, og hvad chips er og ikke er.'],
            '/docs/casino/blackjack' => ['Blackjack', 'Slå dealeren uden at komme over 21, alene eller ved et bord med op til 5 spillere.'],
            '/docs/casino/baccarat' => ['Baccarat', 'Sats på Spilleren, Bankeren eller uafgjort, alene eller ved et bord med op til 6 spillere.'],
            '/docs/casino/roulette' => ['Roulette', 'Europæisk roulette med ét nul, og hver indsats lagt direkte på bordet.'],
            '/docs/casino/slots' => ['Slots', 'En spilleautomat med tre hjul og fem gevinstlinjer, dens gevinsttabel og hvad den betaler tilbage.'],
        ],
    ],
    'games' => [
        'title' => 'Spil',
        'icon' => '🎲',
        'pages' => [
            '/docs/games/' => ['Sådan virker spillene', 'At åbne et spil, koder og invitationer, hvad værten kan, spilchats og ranglister.'],
            '/docs/games/matador' => ['Matador', 'Det danske Matador for 2-4 spillere, med huse, hoteller, handler og Prøv lykken.'],
            '/docs/games/chess' => ['Skak', 'Skak for to spillere, med eller uden ur, remistilbud og revancher.'],
            '/docs/games/uno' => ['UNO', 'Kortspillet for 2-6 spillere, med stabling, at fange folk og officiel pointtælling.'],
            '/docs/games/doodle' => ['Doodle Chain', 'Skriv, tegn og gæt i en kæde for 3-10 spillere, og se til sidst, hvor galt det gik.'],
            '/docs/games/wordle' => ['Wordle', 'Gæt dagens ord på fem bogstaver på seks forsøg, på engelsk og på dansk.'],
            '/docs/games/minesweeper' => ['Minesweeper', 'Klassikeren i sit Windows 95-udseende: ryd brættet uden at åbne en mine.'],
            '/docs/games/sudoku' => ['Sudoku', 'Fyld brættet, så hver række, kolonne og boks har 1 til 9, på fire niveauer fra Easy til Expert.'],
            '/docs/games/snake' => ['Snake', 'Styr slangen hen til æblerne, og bliv længere uden at ramme kanten eller dig selv.'],
            '/docs/games/ore-mining-inc' => ['Ore Mining Inc.', 'Et clickerspil: min en asteroide, byg et mineselskab og prestige på tværs af multiverset.'],
        ],
    ],
];

/**
 * A docs page from its path: its title and summary, which section it's in, and the pages before and after it.
 * Every page passes its own path, so a path that isn't in the list is a mistake in the page, not a visitor's.
 *
 * @return array{path: string, title: string, summary: string, section: array, prev: ?array, next: ?array}
 */
function hbHubDocsPage(string $path): array
{
    $flat = []; // every page in order: [path, title, summary, section]
    foreach (HBHUB_DOCS_SECTIONS as $section) {
        foreach ($section['pages'] as $pagePath => [$title, $summary]) {
            $flat[] = ['path' => $pagePath, 'title' => $title, 'summary' => $summary, 'section' => $section];
        }
    }

    $index = array_search($path, array_column($flat, 'path'), true);
    if ($index === false) {
        throw new InvalidArgumentException('Not a docs page: ' . $path);
    }
    return $flat[$index] + [
        'prev' => $flat[$index - 1] ?? null,
        'next' => $flat[$index + 1] ?? null,
    ];
}
