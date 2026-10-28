# ⚙️ Config options

The config is `hbHubSiteConfig.php`, one level above the web root (see [Setup](setup.md)). Every option is explained in
the [example config](../config-examples/hbHubSiteConfig.example.php). The main ones:

| Key | What it does |
|---|---|
| `database_credentials` | Host, database name, user and password |
| `site_maintenance`, `site_maintenance_message` | Turn off logins and show a message instead |
| `casino_access`, `games_access` | Who can open the Casino and the Games section: `0` nobody, `1` everyone, `2` only admins. The free games at `/play` (no account needed) are only open while their section is `1` |
| `guest_games_secret` | The key the free games' guest tokens are sealed with. Any long random string; without it, one is made from `database_credentials` |
| `giphy_gif_integration`, `giphy_api_key` | The GIF picker. Get a key at [developers.giphy.com](https://developers.giphy.com/) |
| `login_attempts_max`, `login_attempts_lockout_time` | Failed logins before a lockout, and how many minutes it lasts |
| `onboarding_*` | What sign-up requires (profile picture, password strength, bio) and how many accounts one IP can make per hour |
| `user_*_maxSize` | The longest name and bio, and the biggest profile picture |
| `attachments_path`, `attachments_max_total_bytes` | Where chat attachments are kept on disk, and how much space they may take together (9 GB by default) before nobody can send new ones |

---

[← Back to the docs](index.md)
