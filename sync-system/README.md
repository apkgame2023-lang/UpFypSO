# App sync system for the Y4APK theme

Three workers that run outside WordPress — on a VPS or your own computer, not
on the PHP hosting — and feed the [Y4APK theme](../theme/y4apk) through its
**Catalog sync** (`theme/y4apk/inc/sync.php`, theme 2.30+):

```
 app catalogs ──► scraper/app_scraper.py ──► POST /wp-json/y4apk/v1/sync/items ──┐  (or --target db:
 (HTML / JS)       Selenium + BeautifulSoup                                       │   wp_y4apk_sync_items)
                   Name, Version, Link, Google Play ID                            ▼
                                                     WordPress: matched to posts, newer versions
                                                     in App updates, new drafts for Write with AI
 node/version_monitor.js ──► POST /wp-json/y4apk/v1/sync/run  (every 5 minutes) ──► push.php Web Push
                         └─► wp-cron.php                                         ──► Write with AI queue
 node/api_rotator.js: the key failover rules — in Node for any API, and ported into
                      the theme's Write with AI (inc/key-pool.php) for the AI keys
```

| Part | File | What it does |
| --- | --- | --- |
| 1 | `scraper/app_scraper.py` | Renders a catalog page in headless Chrome, extracts each item's Name, Version, Link (and Google Play ID with `--package`), follows "next page" links, obeys robots.txt, and sends the items to WordPress. |
| 2 | `node/version_monitor.js` | Every 5 minutes asks WordPress for a sync run, then opens `wp-cron.php`. The run compares each followed post's version with what its followers were told and sends the theme's own Web Push (`inc/push.php`). |
| 3 | `node/api_rotator.js` | Sends requests with a pool of API keys and fails over on 429 / quota errors. The monitor uses it as its HTTP client (retries, Retry-After). Its rules also run inside the theme, so Write with AI rotates its AI keys while writing new posts. |

## What happens to a catalog item

1. **The scraper** sends `{name, version, link, package?, source_url}` to
   `POST /wp-json/y4apk/v1/sync/items` (200 per request), signed in with the
   sync key. WordPress answers how many items were new, changed, matched.
2. **WordPress matches it** to a post by Google Play ID (from `--package`, a
   Play link, `?id=`, or a path part like `/com.example.app/`), else by App
   Name.
   - **Matched, newer version:** the post shows in App updates ▸ *Need a new
     file* as "Latest · catalog-site.com". Followers are not told yet — there
     is no new file to download.
   - **No post** (with *Make posts for apps the site has none for* on): a
     draft made by *Add from Google Play*, written by Write with AI —
     article, MOD, title, permalink, SEO, icon, screenshots, category — and
     published once written if ticked. At most 10 a day by default.
3. **You upload the new file** (App updates or the post editor). The theme
   tells followers. If the file went up without a post save (the REST API, an
   import), the monitor's next run catches it with the same rule and sends
   the push, once.

The version comparison always happens in WordPress, with the rule `push.php`
uses for a save in the editor, so an update is never announced twice or by two
different rules.

## Setup

Requirements: Python 3.10+, Node.js 22+, Chrome or Chromium where the scraper
runs, and the Y4APK theme 2.30+ on the site.

1. **On the site:** update the theme, then in wp-admin open **Y4APK ▸ App
   updates ▸ Settings ▸ Catalog sync**. Copy the sync key; tick *Make posts
   for apps the site has none for* if you want automatic posts. For the AI
   writing, add your key(s) in **Write with AI ▸ Settings** — several per AI
   with commas between them.
2. **Where the workers run:**

   ```bash
   cp sync-system/.env.example sync-system/.env   # WP_URL, WP_SYNC_KEY, catalog selectors

   python3 -m venv .venv && . .venv/bin/activate
   pip install -r sync-system/scraper/requirements.txt

   cd sync-system/node && npm ci                  # no dependencies; sets up the scripts
   ```

## Running

```bash
# Try the scraper on the sample catalog without sending anything
python -m http.server 8000 --directory sync-system/examples &
python sync-system/scraper/app_scraper.py --url http://localhost:8000/catalog.html \
  --next "a[rel=next]" --package "@data-package" --dry-run

# A real catalog, sent to WordPress
python sync-system/scraper/app_scraper.py --url https://example.com/apps \
  --item ".card" --name "h3" --version ".meta@data-version" --link "a.download"

# The monitor: forever, one run, or what WordPress has
node sync-system/node/version_monitor.js
node sync-system/node/version_monitor.js --once
node sync-system/node/version_monitor.js --status
```

On the same server as WordPress, the scraper can write into its database
instead (`--target db` with the `MYSQL_*` settings from `wp-config.php`);
WordPress matches those items on its next run.

### Scheduling

```cron
# Scrape every hour; the monitor every 5 minutes (or run it as a service)
7 * * * *    cd /srv/sync && .venv/bin/python sync-system/scraper/app_scraper.py
*/5 * * * *  cd /srv/sync && node sync-system/node/version_monitor.js --once
```

As a service: `pm2 start sync-system/node/version_monitor.js` or a systemd unit.
It stops cleanly on SIGINT/SIGTERM once the current run ends.

`app_scraper.py` exits 0 when done, 1 on an error, 2 when no items were found
(usually the catalog's markup changed). `version_monitor.js --once` exits 1
when WordPress could not be reached or refused the key.

## About keys and catalogs

- Pool only API keys you are allowed to use together, such as keys of separate
  paid projects. Limits belong to the account or project, not the key: two
  Gemini keys of one Google Cloud project share one limit. Extra free accounts
  made to get past a free tier are against Google's and Groq's terms.
- The scraper reads robots.txt and stops where it says no, waits between pages
  (or the site's Crawl-delay), and names itself in `SCRAPER_USER_AGENT`. Put a
  way to reach you there.

## Tests

```bash
cd sync-system/node && npm test
```

The rotator tests cover failover on a rate limit, quota, a rejected key,
waiting for a key to come back, 5xx retries, and header or query auth. The
monitor tests cover the sync run and `wp-cron.php` calls, both kinds of
sign-in, retries, and the errors for a wrong key or an old theme. All of them
run against a local HTTP server.
