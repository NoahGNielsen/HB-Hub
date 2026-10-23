<?php
// The help docs (src/docs/): every page, grouped the way the side menu and the docs' front page (docs/index.php) list
// them. top.php and bottom.php build each page around its text from this list, so a new page only needs a line here
// (and one in sitemap.xml). The order here is also the order of the Previous / Next links under each page.
//
// Each section is key => ['title', 'icon' (an emoji), 'pages' => [path => [title, summary]]]. The path is the page's
// clean URL (a folder's index.php is its folder, with the slash at the end), the summary is one sentence, used for the
// meta description and the cards on the docs' front page.

const HBHUB_DOCS_SECTIONS = [
    'start' => [
        'title' => 'Getting started',
        'icon' => '👋',
        'pages' => [
            '/docs/' => ['Help & docs', 'Everything HB Hub can do, and how to use it.'],
            '/docs/getting-started' => ['Getting started', 'Making an account, logging in and finding your way around HB Hub.'],
            '/docs/account' => ['Profile & settings', 'Your profile picture, name and bio, your password, your devices and deleting your account.'],
            '/docs/two-factor' => ['Two-factor login & security', 'Turning on two-factor login, the password rules and what happens after wrong guesses.'],
            '/docs/notifications' => ['Notifications', 'The bell, browser notifications, the message sound and muting chats.'],
            '/docs/self-blocking' => ['Blocking the Casino & Games', 'Blocking the Casino and Games for yourself, during school hours or all the time.'],
            '/docs/feedback' => ['Feedback & getting help', 'Reporting bugs and people, sending ideas, and who to ask for help.'],
        ],
    ],
    'chat' => [
        'title' => 'Chat',
        'icon' => '💬',
        'pages' => [
            '/docs/chat/' => ['Chat basics', 'DMs, message requests, replies, reactions, editing and deleting, and the chat menu.'],
            '/docs/chat/formatting' => ['Styling your messages', 'Bold, italic, underline, code, headings, lists and links in your messages.'],
            '/docs/chat/groups' => ['Group chats', 'Making a group, its owner and admins, and changing its name, icon and background.'],
            '/docs/chat/attachments' => ['Files & pictures', 'Sending files and pictures, the picture viewer, and how attachment tickets work.'],
            '/docs/chat/gifs' => ['GIFs & smileys', 'Sending GIFs from Giphy and smileys from the smiley menu.'],
            '/docs/chat/mentions' => ['@mentions', 'Mentioning someone in a chat or a game chat, and who gets told.'],
            '/docs/chat/safety' => ['Staying safe in the chat', 'Blocking people, link safety, the spam limit and reporting someone.'],
        ],
    ],
    'casino' => [
        'title' => 'Casino',
        'icon' => '🎰',
        'pages' => [
            '/docs/casino/' => ['How the casino works', 'Chips, the daily chips, tables and codes, leaderboards, and what chips are and aren\'t.'],
            '/docs/casino/blackjack' => ['Blackjack', 'Beat the dealer without going over 21, solo or at a table with up to 5 players.'],
            '/docs/casino/baccarat' => ['Baccarat', 'Bet on the Player, the Banker or a tie at a table with up to 6 players.'],
            '/docs/casino/roulette' => ['Roulette', 'European roulette with a single zero, and every bet placed straight on the board.'],
        ],
    ],
    'games' => [
        'title' => 'Games',
        'icon' => '🎲',
        'pages' => [
            '/docs/games/' => ['How games work', 'Opening a game, codes and invites, what the host can do, game chats and leaderboards.'],
            '/docs/games/matador' => ['Matador', 'The Danish Monopoly for 2-4 players, with houses, hotels, trades and Prøv lykken.'],
            '/docs/games/chess' => ['Chess', 'Two-player chess, with or without a clock, draw offers and rematches.'],
            '/docs/games/uno' => ['UNO', 'The card game for 2-6 players, with stacking, catching and official scoring.'],
            '/docs/games/wordle' => ['Wordle', 'Guess the day\'s five-letter word in six tries, in English and in Danish.'],
            '/docs/games/space-ore-miner' => ['Space Ore Miner', 'A clicker game: mine an asteroid, build a mining company and prestige across the multiverse.'],
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
