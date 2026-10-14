<div align="center">

<img src="https://raw.githubusercontent.com/NoahGNielsen/HB-Hub/refs/heads/main/src/assets/images/icons/chat.png" alt="HB Hub" width="96">

# HB Hub

**An unblocked messaging hub for my school, with a casino on the side.**

[hbhub.noahgajnielsen.dk](https://hbhub.noahgajnielsen.dk)

[![Members](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FNoahGNielsen%2FHB-Hub%2Fbadges%2FtotalMembers.json&logo=handshake&logoColor=white)](https://hbhub.noahgajnielsen.dk)
[![Messages](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FNoahGNielsen%2FHB-Hub%2Fbadges%2FtotalMesseges.json&logo=wechat&logoColor=white)](https://hbhub.noahgajnielsen.dk)

![Uptime Robot ratio (30 days)](https://img.shields.io/uptimerobot/ratio/m804196409-9f05044a7225156afaa387a0)
![License](https://img.shields.io/github/license/NoahGNielsen/HB-Hub)
![Version](https://img.shields.io/github/v/release/NoahGNielsen/HB-Hub)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
[![Deploy](https://img.shields.io/github/actions/workflow/status/NoahGNielsen/HB-Hub/ftp-upload.yml?branch=main&label=deploy)](https://github.com/NoahGNielsen/HB-Hub/actions/workflows/ftp-upload.yml)

</div>

> [!NOTE]
> HB Hub is a work in progress. Things can change or break between updates.

---

## ✨ Features

### 💬 Chat

| | |
|---|---|
| **Direct messages & group chats** | Message requests (with Block, to stop someone sending more), replies, edits, deletions and unread counts |
| **Group roles** | Group owners and admins can rename the group, change its icon and background, and add, kick, mute and promote members |
| **Chat notices** | Changes in a group (new name, new admin, someone joined) show up in the chat |
| **Nicknames & profiles** | Give people nicknames, and open the profile of whoever you're DMing straight from the chat |
| **GIFs & smileys** | GIF picker powered by Giphy, plus a smiley menu |
| **Chat menu** | Right-click a chat to mute its notifications for a while, hide it or leave it |

### 🎰 Casino

Played with virtual chips only. No real money is involved anywhere.

| | |
|---|---|
| **Chip wallet** | Every account starts with 2,500 chips and can collect 2,500 more once a day. All games share the same chips |
| **Blackjack** | Play solo or at a table with up to 5 players. Tables can be public, joined with a 6-character code, or invite-only, and the host picks the minimum bet. Insurance, splits and doubling, with timers so nobody can stall the table |
| **Roulette** | European roulette (single zero). Every inside and outside bet can be placed straight on the board |
| **Leaderboards** | Separate leaderboards for Blackjack and Roulette that can be sorted by any column. Win % only counts once you've played 20 rounds |

### 🔔 Notifications

| | |
|---|---|
| **Notification bell** | Invites, message requests, kicks and admin changes show up under the bell in the top bar |
| **Browser notifications** | Pop-ups for new messages, except from muted chats |

### 🔐 Account & security

| | |
|---|---|
| **Profiles** | Profile picture, name, year, class and bio |
| **Two-factor login** | TOTP codes from any authenticator app, set up with a QR code |
| **Login protection** | Password strength rules, a common-password list and lockout after failed attempts. Devices you've logged in on before have their own lock, so nobody can lock you out by guessing |
| **Self-blocking** | Block the Games and Gambling pages during school hours or all the time. Turning a block off doesn't happen straight away |
| **Account deletion** | Delete your account and its data from the settings page |

### 🛠️ For site admins

| | |
|---|---|
| **Admin panel** | Pick any user or chat from a list (or right-click one in the chat). Users: info, logins and messages, and ban, edit, reset or delete them. Chats: members and messages, and rename, remove the icon, change the background, kick members or delete it |
| **Maintenance mode** | Turn off logins and show a message, straight from the site config |
| **Section switches** | Open the casino to everyone, only to admins, or to nobody |

### 🎲 Coming soon

- **Games:** multiplayer Matador (the Danish Monopoly) for 2-4 players, with saves and an in-game chat.

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

### ⚙️ Config options

Every option is explained in the [example config](config-examples/hbHubSiteConfig.example.php). The main ones:

| Key | What it does |
|---|---|
| `database_credentials` | Host, database name, user and password |
| `site_maintenance`, `site_maintenance_message` | Turn off logins and show a message instead |
| `casino_access` | Who can open the casino: `0` nobody, `1` everyone, `2` only admins |
| `giphy_gif_integration`, `giphy_api_key` | The GIF picker. Get a key at [developers.giphy.com](https://developers.giphy.com/) |
| `login_attempts_max`, `login_attempts_lockout_time` | Failed logins before a lockout, and how many minutes it lasts |
| `onboarding_*` | What sign-up requires (profile picture, password strength, bio) and how many accounts one IP can make per hour |

## 📁 Folders

```text
HB-Hub/
├── src/                 The website itself (everything here gets uploaded)
│   ├── chat/            Messages and group chats
│   ├── gambling/        The casino: chips, Blackjack and Roulette
│   ├── games/           The Games section
│   ├── admin/           The admin panel
│   ├── userMgmt/        Login and sign-up
│   ├── otherPages/      Profile, settings and notifications
│   ├── legal/           Terms, privacy policy and credits
│   └── assets/          CSS, JavaScript, images and the shared PHP code
├── config-examples/     Templates for config files that live outside the web root
├── database/            Database schema and default values
│                        (defaultUserSettings.json and defaultChatSettings.json are the
│                        defaults for the settings columns on users and chats)
└── .github/workflows/   Deploying, and updating the README badges every hour
```

## 📜 License

Licensed under the [Apache License 2.0](LICENSE). See [NOTICE](NOTICE) for third-party icons and code.

<div align="center">

Made by [Noah G. Nielsen](https://noahgajnielsen.dk)

</div>
