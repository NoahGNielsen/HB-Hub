<div align="center">

<img src="https://raw.githubusercontent.com/NoahGNielsen/HB-Hub/refs/heads/main/src/assets/images/icons/chat.png" alt="HB Hub" width="96">

# HB Hub

**An unblocked messaging hub for my school, with a casino and games on the side.**

[hbhub.noahgajnielsen.dk](https://hbhub.noahgajnielsen.dk)

[![Members](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FNoahGNielsen%2FHB-Hub%2Fbadges%2FtotalMembers.json&logo=handshake&logoColor=white)](https://hbhub.noahgajnielsen.dk)
[![Messages](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FNoahGNielsen%2FHB-Hub%2Fbadges%2FtotalMesseges.json&logo=wechat&logoColor=white)](https://hbhub.noahgajnielsen.dk)

![Uptime Robot ratio (30 days)](https://img.shields.io/uptimerobot/ratio/m804196409-9f05044a7225156afaa387a0)
![License](https://img.shields.io/github/license/NoahGNielsen/HB-Hub)
![Version](https://img.shields.io/github/v/release/NoahGNielsen/HB-Hub)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
[![Deploy](https://img.shields.io/github/actions/workflow/status/NoahGNielsen/HB-Hub/ftp-upload.yml?branch=main&label=deploy)](https://github.com/NoahGNielsen/HB-Hub/actions/workflows/ftp-upload.yml)

[Features](#-features) · [Tech stack](#-tech-stack) · [Setup](#-setup) · [Folders](#-folders) · [License](#-license)

</div>

> [!NOTE]
> HB Hub is a work in progress. Things can change or break between updates.

---

## ✨ Features

### 💬 Chat

| | |
|---|---|
| **Direct messages & group chats** | Message requests (with Block, to stop someone sending more), replies, emoji reactions, edits, deletions and unread counts |
| **Group roles** | Group owners and admins can rename the group, change its icon and background, and add, kick, mute and promote members |
| **Chat notices** | Changes in a group (new name, new admin, someone joined) show up in the chat |
| **Nicknames & profiles** | Give people nicknames, and open the profile of whoever you're DMing straight from the chat |
| **GIFs & smileys** | GIF picker powered by Giphy, plus a smiley menu |
| **Attachments** | Send files and pictures. Pictures are turned into JPEG XL in the browser (lossless, or visually lossless for JPEGs), other files are zipped on the server. Everyone has 10 tickets of 5 MB, which come back when an attachment expires (90 days, or 3 for big ones) or is deleted |
| **Formatting** | Discord-style `**bold**`, `*italic*`, `__underline__`, `~~strikethrough~~`, `` `code` `` and code blocks, `#` headings, lists and `[masked](https://links)`, from a right-click menu on the message box or Ctrl+B / I / U |
| **@mentions** | Type `@` for a list of the people in the chat. Mentions light up, and the person mentioned gets a notification (not from muted chats or people they blocked). Works in Chess and Matador's game chats too |
| **Chat menu** | Right-click a chat to mute its notifications for a while, hide it or leave it |
| **Link safety** | Links ask before they open, and links to IP loggers and other sites on the URL blacklist (`src/assets/urlBlacklist.txt`) can't be sent at all, in chats or game chats |

### 🎰 Casino

Played with virtual chips only. No real money is involved anywhere.

| | |
|---|---|
| **Chip wallet** | Every account starts with 2,500 chips and can collect 2,500 more once a day. All casino games share the same chips |
| **Blackjack** | Play solo or at a table with up to 5 players. Tables can be public, joined with a 6-character code, or invite-only, and the host picks the minimum bet. Insurance, splits and doubling, with timers so nobody can stall the table |
| **Baccarat** | Punto banco at a table of 2-6 players, public, with a code or invite-only. Bet on Player (1:1), Banker (1:1 less 2.7%, so neither side has an edge), Tie (8:1) and the pairs (11:1). 8 decks, shuffled at 75% with a notice to everyone at the table |
| **Roulette** | European roulette (single zero). Every inside and outside bet can be placed straight on the board |
| **Leaderboards** | Separate leaderboards for Blackjack, Baccarat and Roulette that can be sorted by any column. Win % only counts once you've played 20 rounds |

### 🎲 Games

| | |
|---|---|
| **Matador** | The Danish Monopoly for 2-4 players. Games can be public, joined with a 6-character code, or invite-only |
| **House rules** | One dice (a 6 gives another roll), no auctions: a property nobody buys stays with the bank. Get out of jail by paying, using a "benådet" card or rolling a 6. Taxes and Prøv lykken payments go into Parkering's account, and whoever lands on Parkering gets it (a room setting, on by default) |
| **Full game** | Houses and hotels, mortgages, Prøv lykken cards, the income tax choice, and trading properties, money and cards with the other players at any time |
| **Turn timer** | 30 seconds to act, reset by every move. Run out and your turn is passed on. The timer pauses while you owe money, and the host can pause the whole game |
| **Saves** | The game saves after every move. Leave and your seat is kept for you. The host can give an empty seat to someone else, or save and stop the game to continue it later |
| **In-game chat** | A chat for each game, plus a sound when it's your turn |
| **Chess** | Two-player chess with every rule: castling, en passant, promotion, and draws by stalemate, repetition, the 50-move rule and too few pieces. Public, code or invite-only games, with or without a clock (3+2 up to 30 minutes) |
| **Chess extras** | Draw offers, resigning, aborting before both have moved, claiming the win when your opponent has gone, the host pausing the game (and the clocks), rematches with swapped colours, arrows and highlighted squares drawn with the right mouse button (like chess.com), an in-game chat and a leaderboard |
| **Wordle** | The daily word game: guess the five-letter word in six tries. There's an English and a Danish word every day (the Danish keyboard has æ, ø and å), the same for everyone, with stats, sharing and a leaderboard for each language with win %, average guesses and the current and longest streak |

### 🔔 Notifications

| | |
|---|---|
| **Notification bell** | Invites, message requests, @mentions, kicks and admin changes show up under the bell in the top bar |
| **Browser notifications** | Pop-ups for new messages, except from muted chats |

### 🔐 Account & security

| | |
|---|---|
| **Profiles** | Profile picture, name, year, class and bio |
| **Two-factor login** | TOTP codes from any authenticator app, set up with a QR code |
| **Login protection** | Password strength rules, a common-password list and lockout after failed attempts. Devices you've logged in on before have their own lock, so nobody can lock you out by guessing |
| **Logged in devices** | See every browser you're logged in on and when it was last used, log out any of them, or log out everywhere at once |
| **Self-blocking** | Block the Casino and Games pages during school hours (8 AM - 3 PM) or all the time. Turning a block off waits until school is over, or 6 hours for "All the time", so it can't be switched off in the moment |
| **Account deletion** | Delete your account and its data from the settings page |
| **Feedback** | The Feedback button at the bottom of the side bar sends bugs, ideas and wishes straight to the admins |

### 🛠️ For site admins

| | |
|---|---|
| **Admin panel** | Search every user or chat by name or id (or right-click one in the chat). Users: info, logins and messages, and ban, edit, reset or delete them. Chats: members and messages, and rename, remove the icon, change the background, kick members or delete it |
| **Banned pictures** | Banning someone bans their profile picture too: any account that uploads the same picture (or a resized or slightly cropped copy), or signs up with it, is banned straight away. Add, view and remove pictures under Banned pictures in the admin panel's side menu |
| **Feedback inbox** | Read what users send with the Feedback button, with who sent it and from which page, under Feedback in the admin panel's side menu. Mark it as done or delete it; the number of new ones shows on Admin in the side bar |
| **Admin log** | Everything admins do in the admin panel is logged, opening a user or chat included (as that shows their messages and personal info): who did what to whom, and when. Under Admin log in the admin panel's side menu, or the Admin log button on a user or chat. Can't be changed or deleted, kept for a year |
| **Maintenance mode** | Turn off logins and show a message, straight from the site config |
| **Section switches** | Open the Casino and the Games section to everyone, only to admins, or to nobody |

## 🧱 Tech stack

- **Backend:** PHP 8.4 with PDO. No framework and no Composer packages
- **Database:** MySQL 8.4
- **Frontend:** Plain HTML, CSS and JavaScript
- **Real-time:** HTTP polling (the host is shared hosting, so no WebSockets). Game timers move forward on the next request
- **Hosting:** Apache, deployed over FTPS by GitHub Actions on every push to `main`

## 🚀 Setup

1. **Upload the site.** Upload the contents of `src/` to the web root. The [deploy workflow](.github/workflows/ftp-upload.yml) does this to `hbhub/` on every push to `main`.
2. **Add the config.** Copy [`config-examples/hbHubSiteConfig.example.php`](config-examples/hbHubSiteConfig.example.php) into the folder **one level above** the web root, rename it to `hbHubSiteConfig.php` and fill in the values. The site only looks for it there.
   > For local development with `src/` as the web root, that means the repo root (it's in `.gitignore`).
3. **Create the database.** Import [`database/schema.sql`](database/schema.sql), for example in phpMyAdmin. It always holds the full, current schema.
4. **Add the nightly clean-up.** Log in over SSH, run `crontab -e` and add the line below (with the path to your web root). It deletes expired chat attachments, tidies up abandoned game rooms and removes admin log rows older than a year at 2:30 every night. The site also does this bit by bit as people use it, so it works without, just slower to free up space.
   ```text
   30 2 * * * /usr/local/bin/php /var/www/noahgajnielsen.dk/hbhub/cron/nightly.php
   ```

### 🔄 Updating the database

Every change to `schema.sql` also gets its own file in [`database/updates/`](database/updates/), named after the date and what changed. To update an existing database, run the files newer than your last update, oldest first.

### ⚙️ Config options

Every option is explained in the [example config](config-examples/hbHubSiteConfig.example.php). The main ones:

| Key | What it does |
|---|---|
| `database_credentials` | Host, database name, user and password |
| `site_maintenance`, `site_maintenance_message` | Turn off logins and show a message instead |
| `casino_access`, `games_access` | Who can open the Casino and the Games section: `0` nobody, `1` everyone, `2` only admins |
| `giphy_gif_integration`, `giphy_api_key` | The GIF picker. Get a key at [developers.giphy.com](https://developers.giphy.com/) |
| `login_attempts_max`, `login_attempts_lockout_time` | Failed logins before a lockout, and how many minutes it lasts |
| `onboarding_*` | What sign-up requires (profile picture, password strength, bio) and how many accounts one IP can make per hour |
| `user_*_maxSize` | The longest name and bio, and the biggest profile picture |
| `attachments_path`, `attachments_max_total_bytes` | Where chat attachments are kept on disk, and how much space they may take together (9 GB by default) before nobody can send new ones |

## 📁 Folders

```text
HB-Hub/
├── src/                 The website itself (everything here gets uploaded)
│   ├── chat/            Messages and group chats
│   ├── gambling/        The casino: chips, Blackjack, Baccarat and Roulette
│   ├── games/           The Games section: Matador, Chess and Wordle
│   ├── admin/           The admin panel
│   ├── userMgmt/        Login and sign-up
│   ├── otherPages/      Profile, settings and notifications
│   ├── legal/           Terms, privacy policy and credits
│   └── assets/          CSS, JavaScript, images and the shared PHP code
├── config-examples/     Templates for config files that live outside the web root
├── database/            Database schema, update scripts and default values
│                        (defaultUserSettings.json and defaultChatSettings.json are the
│                        defaults for the settings columns on users and chats)
└── .github/workflows/   Deploying, and updating the README badges every hour
```

## 📜 License

Licensed under the [Apache License 2.0](LICENSE). See [NOTICE](NOTICE) for third-party icons and code.

<div align="center">

Made by [Noah G. Nielsen](https://noahgajnielsen.dk)

</div>
