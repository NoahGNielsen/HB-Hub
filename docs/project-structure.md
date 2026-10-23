# 🧱 Project structure

## Tech stack

- **Backend:** PHP 8.4 with PDO. No framework and no Composer packages
- **Database:** MySQL 8.4
- **Frontend:** Plain HTML, CSS and JavaScript
- **Real-time:** HTTP polling (the host is shared hosting, so no WebSockets). Game timers move forward on the next request
- **Hosting:** Apache, deployed over FTPS by GitHub Actions on every push to `main`

## 📁 Folders

```text
HB-Hub/
├── src/                 The website itself (everything here gets uploaded)
│   ├── chat/            Messages and group chats
│   ├── gambling/        The casino: chips, Blackjack, Baccarat and Roulette
│   ├── games/           The Games section: Matador, Chess, UNO, Wordle and Ore Mining Inc.
│   ├── admin/           The admin panel
│   ├── userMgmt/        Login and sign-up
│   ├── otherPages/      Profile, settings and notifications
│   ├── legal/           Terms, privacy policy and credits
│   ├── docs/            The help docs on the site (/docs/): a page for every feature and game
│   ├── cron/            The nightly clean-up (see Setup)
│   └── assets/          CSS, JavaScript, images and the shared PHP code
├── docs/                This documentation (for running and working on HB Hub)
├── config-examples/     Templates for config files that live outside the web root
├── database/            Database schema, update scripts and default values
│                        (defaultUserSettings.json and defaultChatSettings.json are the
│                        defaults for the settings columns on users and chats)
└── .github/workflows/   Deploying, and updating the README badges every hour
```

## 🔗 Addresses

`src/.htaccess` drops `.php` from every address, and gives a few pages a shorter one than their folder:

| Address                   | File                          |
|---------------------------|-------------------------------|
| `/login`, `/onboarding`   | `userMgmt/login.php`, `userMgmt/onboarding.php` |
| `/profile`, `/settings`, `/notifications`, `/feedback`, `/avatar`, `/nav-updates` | `otherPages/*.php` |
| `/games/ore-mining-inc/` | `games/spaceOreMiner/`        |

Links in the code use the short address. The old long ones still work: a GET is redirected to the short one.

---

[← Back to the docs](index.md)
