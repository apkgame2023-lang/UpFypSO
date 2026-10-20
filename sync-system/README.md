# App sync system

Three parts that share one MySQL database:

```
 catalog page ──► scraper/app_scraper.py ──► MySQL `apps` ──► node/version_monitor.js ──► Firebase Cloud Messaging
  (HTML/JS)        Selenium + BeautifulSoup    (schema.sql)     compares versions,          topic "app_<id>"
                   upsert Name/Version/Link                     logs push_notifications

 any HTTP API ◄── node/api_rotator.js: requests with a pool of API keys, failing over on 429 / quota errors
```

| Part | File | What it does |
| --- | --- | --- |
| 1 | `scraper/app_scraper.py` | Renders a catalog page in headless Chrome, extracts each item's Name, Version and Link, and upserts them into `apps`. It follows "next page" links, obeys robots.txt and sends an identifiable User-Agent. |
| 2 | `node/version_monitor.js` | Runs on a timer (or `--once` from cron). It finds apps whose `latest_version` is higher than `notified_version`, sends an FCM push for each, and records every attempt. |
| 3 | `node/api_rotator.js` | Sends requests with a pool of API keys. A rate-limited key rests until its Retry-After, an exhausted quota rests longer, a rejected key is retired, and 5xx errors are retried on the same key. |

## How the data flows

1. **Scrape.** `app_scraper.py` identifies an item by its normalized link (`link_hash` = SHA-256).
   A new link inserts a row. A known link updates the name and link and, when the version text
   changed, moves the old text to `previous_version` and stamps `version_changed_at`. A blank
   version never overwrites a known one.
2. **Compare.** `version_monitor.js` reads the rows where `latest_version <> notified_version`:
   - `notified_version` is `NULL` (an app it has never seen): the version is stored as the baseline and nothing is sent.
   - The version is higher (`2.10 > 2.9`, `1.0 > 1.0-rc2`): it sends a push to topic `app_<id>`,
     then sets `notified_version` and adds a row to `push_notifications`.
   - The same version written differently (`v1.2` / `1.2.0`), a lower version, or text with no
     number: the new text is adopted with no push.
   - A failed push is retried on later passes, up to `MONITOR_MAX_ATTEMPTS` per app and version.
3. **Clients** subscribe to the topics of the apps they follow (`FirebaseMessaging.subscribeToTopic("app_42")`
   on Android, `messaging.subscribeToTopic` server-side for web tokens).

Delivery is at least once. If the monitor dies between sending a push and updating the
database, the next pass sends that push again. Only one monitor works per database at a time
(MySQL `GET_LOCK`), so a cron job next to the daemon is safe.

## Setup

Requirements: Python 3.10+, Node.js 22+, MySQL 5.7+ or MariaDB 10.3+, and Chrome or Chromium.

```bash
# Database
mysql -u root -p -e "CREATE DATABASE app_sync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'app_sync'@'localhost' IDENTIFIED BY 'change-me';
  GRANT SELECT, INSERT, UPDATE, DELETE ON app_sync.* TO 'app_sync'@'localhost';"
mysql -u root -p app_sync < sync-system/schema.sql

# Configuration
cp sync-system/.env.example sync-system/.env   # then edit it

# Part 1
python3 -m venv .venv && . .venv/bin/activate
pip install -r sync-system/scraper/requirements.txt

# Parts 2 and 3
cd sync-system/node && npm ci
```

For live pushes, download a service account key from **Firebase console → Project settings →
Service accounts → Generate new private key**. Save it as `sync-system/service-account.json`
(git-ignored) and set `PUSH_MODE=live`.

## Running

```bash
# Try the scraper on the sample catalog (its default selectors match it)
python -m http.server 8000 --directory sync-system/examples &
python sync-system/scraper/app_scraper.py --url http://localhost:8000/catalog.html --next "a[rel=next]" --dry-run

# Real catalog, saved to MySQL
python sync-system/scraper/app_scraper.py --url https://example.com/apps \
  --item ".card" --name "h3" --version ".meta@data-version" --link "a.download"

# Monitor: forever, or one pass
node sync-system/node/version_monitor.js
node sync-system/node/version_monitor.js --once

# Rotator from the command line (keys from API_KEYS)
node sync-system/node/api_rotator.js https://api.example.com/v1/status
```

In code:

```js
const { ApiKeyRotator, AllKeysExhaustedError } = require('./api_rotator');

const api = new ApiKeyRotator({
  keys: process.env.API_KEYS.split(','),
  auth: { in: 'query', name: 'key' },   // or { in: 'header', name: 'x-api-key' }; default Bearer
  maxWaitMs: 10_000,                    // wait this long for a resting key before giving up
});
api.on('rotate', ({ from, to }) => metrics.increment('api_key_rotation', { from, to }));

try {
  const { data } = await api.request('https://api.example.com/v1/items', { method: 'POST', json: { q: 'x' } });
} catch (err) {
  if (err instanceof AllKeysExhaustedError) scheduleRetry(err.retryAt);
  else throw err;
}
```

Only pool keys you are allowed to use together, such as keys from separate paid projects or
from a fallback provider. Many APIs' terms of service forbid extra keys whose only purpose is
to get around a per-account quota.

### Scheduling

```cron
# Scrape hourly, then run the monitor once
7 * * * *  cd /srv/app && .venv/bin/python sync-system/scraper/app_scraper.py && node sync-system/node/version_monitor.js --once
```

Or run the monitor as a service (systemd, pm2 `pm2 start sync-system/node/version_monitor.js`).
It stops cleanly on SIGINT/SIGTERM once the current pass ends.

`app_scraper.py` exits with code 0 when it finished, 1 on an error, and 2 when no items were
found (usually the site's markup changed). `version_monitor.js --once` exits 1 when the pass
failed or any push failed.

## Tests

```bash
cd sync-system/node && npm test
```

The tests cover key failover (rate limit, quota, retired key, waiting for a key to come back,
5xx retries, header and query auth) against a local HTTP server. They also cover version
comparison and the FCM message format.
