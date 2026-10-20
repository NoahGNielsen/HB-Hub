# 🚀 Setup

> [!IMPORTANT]
> HB Hub is made for one site, [hbhub.noahgajnielsen.dk](https://hbhub.noahgajnielsen.dk), and isn't a site builder
> you can rebrand from a config file. The name, domain, school hours, legal pages, texts and design are written into
> the code. To run your own version, fork this project and change the code yourself.

1. **Upload the site.** Upload the contents of `src/` to the web root. The [deploy workflow](../.github/workflows/ftp-upload.yml) does this to `hbhub/` on every push to `main`.
2. **Add the config.** Copy [`config-examples/hbHubSiteConfig.example.php`](../config-examples/hbHubSiteConfig.example.php) into the folder **one level above** the web root, rename it to `hbHubSiteConfig.php` and fill in the values ([what they do](config.md)). The site only looks for it there.
   > For local development with `src/` as the web root, that means the repo root (it's in `.gitignore`).
3. **Create the database.** Import [`database/schema.sql`](../database/schema.sql), for example in phpMyAdmin. It always holds the full, current schema.
4. **Add the nightly clean-up.** Log in over SSH, run `crontab -e` and add the line below (with the path to your web root). It deletes expired chat attachments, tidies up abandoned game rooms and removes admin log rows older than a year at 2:30 every night. The site also does this bit by bit as people use it, so it works without, just slower to free up space.
   ```text
   30 2 * * * /usr/local/bin/php /var/www/noahgajnielsen.dk/hbhub/cron/nightly.php
   ```

## 🔄 Updating the database

Every change to `schema.sql` also gets its own file in [`database/updates/`](../database/updates/), named after the date and what changed. To update an existing database, run the files newer than your last update, oldest first.

---

[← Back to the docs](index.md)
