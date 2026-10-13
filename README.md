<div align="center">

<img src="https://raw.githubusercontent.com/NoahGNielsen/HB-Hub/refs/heads/main/src/assets/images/icons/chat.png" alt="HB Hub" width="96">

# HB Hub

**An unblocked messaging hub for my school.**

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

| | |
|---|---|
| 💬 **Direct messages & group chats** | Message requests, replies, edits, deletions and unread tracking |
| 👑 **Group roles** | Group owners and admins can rename the group, change its icon and background, add, kick and promote members |
| 📢 **Chat notices** | Changes in a group (new name, new admin, someone joined) show up in the chat |
| 🎞️ **GIFs & smileys** | GIF picker powered by Giphy, plus a smiley menu |
| 🔔 **Notifications** | Browser notifications for new messages, with per-chat muting |
| 🔐 **Two-factor login** | TOTP codes from any authenticator app, set up with a QR code |
| 🛡️ **Login protection** | Password strength rules, a common-password list and lockout after failed attempts |
| ⏳ **Self-blocking** | Block the Games and Gambling pages during school hours or all the time. Turning a block off doesn't happen straight away |
| 👤 **Profiles** | Profile picture, name, year, class and bio |
| 🧰 **Maintenance mode** | Turn off logins and show a message from the site config |

## 🧱 Tech stack

- **Backend:** PHP 8.4 (no framework, no dependencies), PDO
- **Database:** MySQL 8.4
- **Frontend:** Plain HTML, CSS and JavaScript
- **Hosting:** Apache, deployed over FTPS by GitHub Actions on every push to `main`

## 🚀 Setup

1. **Upload the site.** Upload the contents of `src/` to the web root. The [deploy workflow](.github/workflows/ftp-upload.yml) does this to `hbhub/` on every push to `main`.
2. **Add the config.** Copy [`config-examples/hbHubSiteConfig.example.php`](config-examples/hbHubSiteConfig.example.php) into the folder **one level above** the web root, rename it to `hbHubSiteConfig.php` and fill in the values. The site only looks for it there.
   > For local development with `src/` as the web root, that means the repo root (it's in `.gitignore`).
3. **Create the database.** Import [`database/schema.sql`](database/schema.sql), for example in phpMyAdmin.

## 📁 Folders

```text
HB-Hub/
├── src/               The website itself (everything here gets uploaded)
├── config-examples/   Templates for config files that live outside the web root
└── database/          Database schema and default values
                       (defaultUserSettings.json is the default for the userSettings column in HBHub-Users)
```
## 📜 License

Licensed under the [Apache License 2.0](LICENSE). See [NOTICE](NOTICE) for third-party icons and code.

<div align="center">

Made by [Noah G. Nielsen](https://noahgajnielsen.dk)

</div>
