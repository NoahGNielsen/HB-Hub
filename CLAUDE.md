Last updated 22/10-26
# CLAUDE.md

## Rules (from the project owner)

- **Every change to `database/schema.sql` also goes in its own `.sql` file in `database/migrations/`.** Name it after the
  date and what changed, e.g. `01-01-1970 - Added a new colum with titles.sql` (DD-MM-YYYY). It must hold only the
  statements needed to bring an existing database up to date (`ALTER TABLE ...` etc.). `schema.sql` itself always holds
  the full, current schema.
- **You HAVE to update the legal pages in `src/legal/` whenever a change touches what they describe.** This is not
  optional and goes in the same commit as the change. Before finishing any task, check whether it adds, removes or
  changes any of the following, and if so update the matching page(s):
  - **Cookies** (name, purpose, lifetime) → `HBHUB_PRIVACY_COOKIES` in `privacy.php`.
  - **localStorage/sessionStorage keys** → `HBHUB_PRIVACY_LOCAL_STORAGE` in `privacy.php`.
  - **Database**: a new table, column or JSON setting that stores anything about users (what's kept, how long, who can
    see it, how it's deleted) → `privacy.php`.
  - **Outside services / external origins** (CSP changes, new APIs like Giphy, fonts, hosting) → `privacy.php`, and
    `do-not-sell-or-share-my-personal-information.php` if data is shared with them.
  - **Rules, features, casino/games, bans, moderation, account deletion, age/eligibility** → `terms.php` and/or
    `code-of-conduct.php`.
  - **Third-party code, assets, fonts or sounds** → `credits.php`.
  - Anything else that makes a legal page untrue.
  Every time a page's content changes, set its `HBHUB_*_UPDATED` constant at the top (`HBHUB_PRIVACY_UPDATED`,
  `HBHUB_TERMS_UPDATED`, `HBHUB_CONDUCT_UPDATED`, `HBHUB_DO_NOT_SELL_UPDATED`) to today's date, written like
  `19 October 2026`. When it's unclear whether a page needs a change, update it anyway or tell the owner.
- **Automatically make a commit after making changes**, with an appropriate commit message. Existing history uses
  short, lowercase, plain-English messages (`added leaderboard`, `QOL update to matador`).
- `CLAUDE.md` and `database/migrations/*` are in `.gitignore`, so they are never part of a commit.
- **Simulations and other long runs log live.** Anything that runs for more than a minute or two (balance sims,
  headless-browser scripts, batch jobs) writes its output line by line to a file as it goes, never only at the end, so
  a run that hangs or gets killed still leaves its results and can be checked while it runs. See "Long runs" below.

## What this is

HB Hub (https://hbhub.noahgajnielsen.dk) is a messaging site for the owner's school, with a virtual-chip casino
(Blackjack, Baccarat, Roulette) and games (Matador, chess, UNO, Wordle, Space Ore Miner). The feature list is in
[docs/features/](docs/features/index.md); keep it up to date when a feature changes.

- **PHP 8.4 + PDO + MySQL 8.4.** No framework, no Composer packages, no build step.
- **Plain HTML/CSS/JS** frontend. No bundler, no npm. Each page loads its own `assets/js/*.js` and `assets/css/*.css`.
- **Shared hosting (Apache, Simply.com)**, so no WebSockets, cron or long-running processes. Real-time is HTTP
  polling, and game timers/cleanup move forward lazily on the next request.
- **No tests and no linter.** PHP and Node aren't installed on the owner's PC, so `php -l` doesn't work here: check JS
  by loading the page or script in headless Brave
  (`"/c/Program Files/BraveSoftware/Brave-Browser/Application/brave.exe" --headless=new`), and PHP by reading it
  carefully.

## Deploy

Pushing to `main` deploys: [.github/workflows/ftp-upload.yml](.github/workflows/ftp-upload.yml) syncs `src/` to
`hbhub/` over FTPS. **The database is not migrated automatically**: the owner runs the `database/migrations/` files by
hand, so tell them when a push needs one run first.

## Layout

```text
src/                     web root (everything here is uploaded)
  chat/, gambling/, games/, admin/, userMgmt/, otherPages/   the pages and their JSON endpoints
  legal/                 terms, privacy, code-of-conduct, do-not-sell, credits (must match the code - see Rules)
  assets/php/            all shared PHP (libraries, not pages); game logic in casino/<game>/ and games/<game>/
  assets/js/, assets/css/  one file per page/feature
docs/                    public docs (start at docs/index.md)
config-examples/         hbHubSiteConfig.example.php - template for the real config
database/                schema.sql, defaultUserSettings.json, defaultChatSettings.json, migrations/ (gitignored)
```

## Things that aren't obvious from the code

- **`session.php` is `require_once`d first by every page/endpoint**, before any output. It sets `$hbHubUser` (or null)
  and redirects to the login when not logged in; set `$hbHubSessionOptional = true` before it for public pages. It also
  rejects cross-origin POSTs globally.
- **Ghost bans** (top of `chat.php`): any new query that shows chat content to users must filter them
  (`chatGhostVisibleSql()`, `cm.memberIsGhost = 0`).
- **Game state** for Matador/Blackjack/Baccarat/UNO is JSON in `roomState` and holds hidden info (card order, the hole
  card, everyone's hand), so **never send it to players as-is** - build a per-player view. Rules files are pure (no
  DB); `room.php` loads state, applies a move under a row lock, saves.
- **Space Ore Miner**: the rules are in both `assets/php/games/spaceOreMiner/miner.php` and `assets/js/spaceOreMiner.js`,
  and the server replays every action to check it, so a rule change goes in both and must give exactly the same numbers
  (whole-number checks instead of trusting `**` for roots, etc.).
- **`userSettings` JSON**: always read with a default - older accounts lack newer keys. `defaultUserSettings.json` /
  `defaultChatSettings.json` document the shape; update them when adding a setting.
- **Notifications**: types are `"section.kind"`; add a section with a file in `assets/php/notificationTypes/` and call
  `hbHubNotify($db, $userId, 'section.kind', $actorId, $data)`.
- **Config**: the real `hbHubSiteConfig.php` is one level above the web root and gitignored. A new key goes in
  [config-examples/hbHubSiteConfig.example.php](config-examples/hbHubSiteConfig.example.php) with a
  `// Default: ... | explanation` comment, is read with a fallback (`$siteConfig['key'] ?? default`), and goes in
  [docs/config.md](docs/config.md) if it's a main one.

## Conventions

- **Includes** use `require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/...'` in pages and `__DIR__` inside
  `assets/php/`.
- **Naming**: functions `hbHub<Area><Thing>()`, constants `HBHUB_<AREA>_<NAME>`, tables `` `HBHub-<Name>` `` (always
  backtick-quoted), columns camelCase prefixed with the table's subject (`userName`, `roomState`). Magic numbers stored
  in the DB are mirrored by constants with a comment naming the column, e.g.
  `const HBHUB_CASINO_ROLE_ADMIN = 2; // userRole in HBHub-Users`. Admin is `userRole = 2`; banned is `userStatus = 4`;
  deleted accounts have `userStatus`/`userRole` NULL and the name `Deleted User`.
- **JSON endpoints**: the JS calls them with `fetch(url, { headers: { Accept: 'application/json' } })` and form-encoded
  POSTs with an `action=` field. They set `Content-Type: application/json` + `Cache-Control: no-store`, answer errors as
  `http_response_code(4xx); echo json_encode(['error' => 'camelCaseCode'])`, and reject wrong methods with 405 + `Allow`.
- **Clean URLs**: `.htaccess` strips `.php`/`.html`, so link to `/chat/users`, not `/chat/users.php`.
- **Security**: all SQL via prepared statements; escape output with `htmlspecialchars`. The CSP in `src/.htaccess`
  allows no inline scripts/styles and only Giphy/Google Fonts as externals. Uploaded images exclude SVG.
- **Concurrency**: anything that changes shared game/wallet state runs in a transaction and locks the row first
  (`SELECT ... FOR UPDATE`). Moves carry an id so a double click only counts once.
- **Polling**: only while the page is visible. The nav bar's numbers, casino chips and browser notifications share one
  request every 15 s (`assets/js/navUpdates.js`, the `nav:poll`/`nav:update` events) - add new counters there instead
  of a new poll.
- **Pictures served by PHP**: the hosting only runs ~10 PHP requests at once, so lists of them use
  `<img data-queued-picture="url">` (+ a `<noscript>` copy) and `assets/js/pictureQueue.js`.
- **Asset cache busting**: stylesheets/scripts are linked with `?v=<?= filemtime(...) ?>` (static assets are cached
  for 90 days).
- **The chat page never reloads**: `chat.js` swaps in the next page's `<main class="chat">`, so each setup block is
  `chatPart((signal, isSwap) => { ... })` and anything it keeps running must stop on `signal`; never call
  `form.submit()` inside `main.chat`; other scripts bind to chat elements with delegated listeners.
- **JS style**: each file is an IIFE with `const`/arrow functions, `data-*` attributes as hooks, and `CustomEvent`s on
  `document` between scripts.
- **Comments**: every file starts with a plain-English comment on what it does, who calls it and the shape of its
  data; functions have short docblocks. The codebase is heavily commented in a conversational tone - match it.
- **UI text** is English (except Matador's Danish game terms).
- **schema.sql** is a phpMyAdmin dump: `CREATE TABLE`s first, then indexes, `AUTO_INCREMENT` and foreign keys as
  separate `ALTER TABLE` blocks further down - keep that structure.

## Long runs (sims, headless browsers, batch jobs)

- **Log every line to a file as it happens.** Never collect the output and write it at the end. In headless Brave,
  `--dump-dom` has hung at the end of 30+ minute runs and lost everything. Instead, `console.log` each line and start
  Brave with `--enable-logging=stderr --v=0 2> log.txt`; the lines are in quotes after `INFO:CONSOLE` in that file.
- Run them in the background, tell the owner when they started and roughly when they'll finish, and read the log file
  when asked for progress. They die if the session or PC shuts down, so say so.
- Give every parallel run its own name, output file and browser profile folder.
- The Space Ore Miner balance sim builds a page from the real `miner.php` constants and the rules part of
  `spaceOreMiner.js`. When the game gets a new `SALT_*` constant or `CONFIG` key, add it to the harness's mapping, or
  the sim dies with a ReferenceError. Where the harness lives is in Claude's memory.
