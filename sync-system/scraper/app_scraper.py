#!/usr/bin/env python3
"""
app_scraper.py: part 1 of the app sync system, feeding the Y4APK theme.

Opens a catalog page in headless Chrome through Selenium, so lists that are
built by JavaScript work too, reads each item's Name, Version and Link (and
Google Play ID, when the page shows one) with BeautifulSoup, and hands them to
WordPress — the theme's Catalog sync (theme/y4apk/inc/sync.php):

  --target rest  (default) POST /wp-json/y4apk/v1/sync/items, signed in with
                 the sync key from App updates ▸ Settings ▸ Catalog sync
                 (WP_SYNC_KEY) or an administrator's Application Password
                 (WP_USER + WP_APP_PASSWORD). Matched at once; the answer
                 says how many items were new, changed, matched.
  --target db    straight into {WP_TABLE_PREFIX}y4apk_sync_items in the
                 WordPress database (MYSQL_*), for a scraper on the same
                 server; WordPress matches them on its next run.

An item is identified by its link (SHA-256, as the theme makes it). WordPress
matches it to a post by Google Play ID, else App Name; a newer version shows in
App updates, and an app the site has no post for can become one.

The scraper is a polite crawler: it obeys robots.txt (including Crawl-delay),
sends an identifiable User-Agent, waits between pages and only follows
pagination on the catalog's own host.

Usage:
    python app_scraper.py --url https://example.com/catalog \\
        --item ".app-item" --name ".app-name" --version ".app-version" --link "a" \\
        [--package "@data-package"] [--target rest|db] [--dry-run]

Selectors are CSS, relative to the item. Add "@attr" to read an attribute
instead of the text, e.g. --version "@data-version" (the item's own attribute)
or --link "a.download@data-href". The link defaults to the href of the
selected element, or of the item itself when it is an <a>.

Options default to environment variables, read from ../.env when present
(see ../.env.example). Keys and passwords are only read from the environment.

Exit codes: 0 done, 1 error, 2 no items found (check the selectors).
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import logging
import os
import re
import sys
import time
from dataclasses import asdict, dataclass
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit, urlunsplit
from urllib.request import Request, urlopen
from urllib.robotparser import RobotFileParser

import pymysql
from bs4 import BeautifulSoup, Tag
from selenium import webdriver
from selenium.common.exceptions import TimeoutException, WebDriverException
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

try:
    from dotenv import load_dotenv
except ImportError:  # python-dotenv is optional: plain environment variables work too
    load_dotenv = None

log = logging.getLogger("app_scraper")

# Column sizes in schema.sql.
MAX_NAME = 255
MAX_VERSION = 64
MAX_LINK = 2048

DB_BATCH = 500
REST_BATCH = 200   # the theme takes at most 500 items per request
REST_TRIES = 4
VERSION_LABEL = re.compile(r"^(?:version|ver\.?)\s*[:\-]?\s*", re.IGNORECASE)
ATTR_SPEC = re.compile(r"^(?P<selector>.*?)@(?P<attr>[A-Za-z_:][\w:.-]*)$")


@dataclass(frozen=True)
class Item:
    name: str
    version: str
    link: str
    source_url: str
    package: str = ""

    @property
    def link_hash(self) -> str:
        return hashlib.sha256(self.link.encode("utf-8")).hexdigest()


@dataclass(frozen=True)
class Selectors:
    item: str
    name: str
    version: str
    link: str
    next: str
    package: str = ""


class RobotsDisallowed(Exception):
    pass


# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

def env_int(name: str, default: int) -> int:
    value = os.environ.get(name, "").strip()
    try:
        return int(value) if value else default
    except ValueError:
        raise SystemExit(f"{name} must be a whole number, not {value!r}")


def env_float(name: str, default: float) -> float:
    value = os.environ.get(name, "").strip()
    try:
        return float(value) if value else default
    except ValueError:
        raise SystemExit(f"{name} must be a number, not {value!r}")


def parse_args(argv: list[str] | None) -> argparse.Namespace:
    env = os.environ.get
    parser = argparse.ArgumentParser(
        description="Scrape Name, Version and Link from a catalog page into MySQL.")
    parser.add_argument("--url", default=env("CATALOG_URL"),
                        help="catalog page to start from (CATALOG_URL)")
    parser.add_argument("--item", default=env("SEL_ITEM", ".app-item"),
                        help="CSS selector of one catalog item (SEL_ITEM)")
    parser.add_argument("--name", default=env("SEL_NAME", ".app-name"),
                        help="name, relative to the item (SEL_NAME)")
    parser.add_argument("--version", default=env("SEL_VERSION", ".app-version"),
                        help="version, relative to the item (SEL_VERSION)")
    parser.add_argument("--link", default=env("SEL_LINK", "a"),
                        help="link, relative to the item (SEL_LINK)")
    parser.add_argument("--package", default=env("SEL_PACKAGE", ""),
                        help="Google Play ID, relative to the item, e.g. '@data-package'; "
                             "empty: WordPress reads it from the link (SEL_PACKAGE)")
    parser.add_argument("--next", default=env("SEL_NEXT", ""),
                        help="'next page' link, e.g. 'a[rel=next]'; empty for one page (SEL_NEXT)")
    parser.add_argument("--max-pages", type=int, default=env_int("MAX_PAGES", 10),
                        help="most pages to follow (MAX_PAGES)")
    parser.add_argument("--delay", type=float, default=env_float("PAGE_DELAY_SECONDS", 2.0),
                        help="seconds between pages; robots.txt Crawl-delay wins when longer (PAGE_DELAY_SECONDS)")
    parser.add_argument("--timeout", type=float, default=env_float("PAGE_TIMEOUT_SECONDS", 20.0),
                        help="seconds to wait for a page and its items (PAGE_TIMEOUT_SECONDS)")
    parser.add_argument("--scrolls", type=int, default=env_int("SCROLLS", 0),
                        help="times to scroll to the bottom for lazy-loaded lists (SCROLLS)")
    parser.add_argument("--user-agent", default=env("SCRAPER_USER_AGENT", "AppSyncBot/1.0"),
                        help="User-Agent sent with every request (SCRAPER_USER_AGENT)")
    parser.add_argument("--target", default=env("SYNC_TARGET", "rest"), choices=["rest", "db"],
                        help="send the items to WordPress over its REST API, or write them into its database (SYNC_TARGET)")
    parser.add_argument("--dry-run", action="store_true",
                        help="print the items as JSON instead of sending them")
    parser.add_argument("--log-level", default=env("LOG_LEVEL", "INFO"),
                        choices=["DEBUG", "INFO", "WARNING", "ERROR"])
    args = parser.parse_args(argv)

    if not args.url:
        parser.error("--url (or CATALOG_URL) is required")
    if urlsplit(args.url).scheme not in ("http", "https"):
        parser.error("--url must be an http(s) URL")
    if not args.item:
        parser.error("--item must not be empty")
    if args.max_pages < 1 or args.delay < 0 or args.timeout <= 0 or args.scrolls < 0:
        parser.error("--max-pages must be at least 1; --delay, --timeout and --scrolls must not be negative")
    return args


def db_config() -> dict:
    missing = [n for n in ("MYSQL_USER", "MYSQL_DATABASE") if not os.environ.get(n)]
    if missing:
        raise SystemExit(f"Set {', '.join(missing)} (see .env.example)")
    return {
        "host": os.environ.get("MYSQL_HOST", "127.0.0.1"),
        "port": env_int("MYSQL_PORT", 3306),
        "user": os.environ["MYSQL_USER"],
        "password": os.environ.get("MYSQL_PASSWORD", ""),
        "database": os.environ["MYSQL_DATABASE"],
        "charset": "utf8mb4",
        "autocommit": False,
        "connect_timeout": 10,
        "init_command": "SET time_zone = '+00:00'",
    }


def wp_table() -> str:
    prefix = os.environ.get("WP_TABLE_PREFIX", "wp_")
    if not re.fullmatch(r"[A-Za-z0-9_]+", prefix):
        raise SystemExit(f"WP_TABLE_PREFIX must be letters, digits and _, not {prefix!r}")
    return f"{prefix}y4apk_sync_items"


def wp_endpoint(route: str) -> str:
    """The REST address of a theme route: WP_API_ROOT (e.g. https://site/?rest_route=),
    else WP_URL + /wp-json."""
    root = os.environ.get("WP_API_ROOT", "").strip()
    if not root:
        site = os.environ.get("WP_URL", "").strip().rstrip("/")
        if not site:
            raise SystemExit("Set WP_URL (your site's address) or WP_API_ROOT (see .env.example)")
        root = site + "/wp-json"
    return root.rstrip("/") + route if "rest_route=" not in root else root + route


def wp_auth_headers() -> dict[str, str]:
    key = os.environ.get("WP_SYNC_KEY", "").strip()
    if key:
        return {"X-Y4APK-Sync-Key": key}
    user, password = os.environ.get("WP_USER", ""), os.environ.get("WP_APP_PASSWORD", "")
    if user and password:
        token = base64.b64encode(f"{user}:{password}".encode()).decode()
        return {"Authorization": f"Basic {token}"}
    raise SystemExit("Set WP_SYNC_KEY (App updates ▸ Settings ▸ Catalog sync), "
                     "or WP_USER and WP_APP_PASSWORD (an administrator's Application Password)")


# --------------------------------------------------------------------------
# robots.txt
# --------------------------------------------------------------------------

class Robots:
    """robots.txt rules per host, fetched once each, read as RFC 9309 says:
    a 4xx answer means no rules, an unreachable file or a 5xx means stay out."""

    def __init__(self, user_agent: str, timeout: float):
        self.user_agent = user_agent
        self.timeout = timeout
        self._parsers: dict[str, RobotFileParser] = {}

    def _parser(self, url: str) -> RobotFileParser:
        parts = urlsplit(url)
        origin = f"{parts.scheme}://{parts.netloc}"
        if origin in self._parsers:
            return self._parsers[origin]

        parser = RobotFileParser(origin + "/robots.txt")
        request = Request(parser.url, headers={"User-Agent": self.user_agent})
        try:
            with urlopen(request, timeout=self.timeout) as response:
                parser.parse(response.read().decode("utf-8", errors="replace").splitlines())
        except HTTPError as err:
            if 400 <= err.code < 500:
                parser.allow_all = True
            else:
                raise RobotsDisallowed(f"{parser.url} answered HTTP {err.code}; not crawling {origin}")
        except (URLError, TimeoutError, OSError) as err:
            raise RobotsDisallowed(f"could not read {parser.url} ({err}); not crawling {origin}")
        self._parsers[origin] = parser
        return parser

    def allowed(self, url: str) -> bool:
        return self._parser(url).can_fetch(self.user_agent, url)

    def crawl_delay(self, url: str) -> float:
        delay = self._parser(url).crawl_delay(self.user_agent)
        return float(delay) if delay else 0.0


# --------------------------------------------------------------------------
# Browser
# --------------------------------------------------------------------------

def make_driver(user_agent: str, timeout: float) -> webdriver.Chrome:
    options = Options()
    options.add_argument("--headless=new")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-gpu")
    options.add_argument("--window-size=1366,900")
    options.add_argument(f"--user-agent={user_agent}")
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        # Chrome refuses to start its sandbox as root (Docker, CI).
        options.add_argument("--no-sandbox")
    if os.environ.get("CHROME_BINARY"):
        options.binary_location = os.environ["CHROME_BINARY"]

    # Without CHROMEDRIVER_PATH, Selenium Manager finds or downloads a matching driver.
    service = Service(executable_path=os.environ.get("CHROMEDRIVER_PATH") or None)
    driver = webdriver.Chrome(options=options, service=service)
    driver.set_page_load_timeout(timeout)
    return driver


def wait_for_items(driver: webdriver.Chrome, selector: str, timeout: float) -> int:
    """Waits until the page has loaded and its item count has stopped growing
    (unchanged for a second), so items added by scripts are not missed.
    Returns the last count; 0 when no item showed up in time."""
    deadline = time.monotonic() + timeout
    try:
        WebDriverWait(driver, timeout).until(
            lambda d: d.execute_script("return document.readyState") == "complete")
    except TimeoutException:
        log.warning("Page still loading after %ss; reading what is there", timeout)

    last, stable_since = -1, time.monotonic()
    while time.monotonic() < deadline:
        count = len(driver.find_elements(By.CSS_SELECTOR, selector))
        now = time.monotonic()
        if count != last:
            last, stable_since = count, now
        elif count > 0 and now - stable_since >= 1.0:
            break
        time.sleep(0.25)
    return max(last, 0)


def scroll_to_end(driver: webdriver.Chrome, times: int) -> None:
    for _ in range(times):
        height = driver.execute_script("return document.body.scrollHeight")
        driver.execute_script("window.scrollTo(0, document.body.scrollHeight)")
        try:
            WebDriverWait(driver, 5).until(
                lambda d: d.execute_script("return document.body.scrollHeight") > height)
        except TimeoutException:
            break  # nothing more loaded


def render(driver: webdriver.Chrome, url: str, sel: Selectors, args: argparse.Namespace) -> tuple[str, str]:
    """Loads the page and returns (html, final URL after redirects)."""
    try:
        driver.get(url)
    except TimeoutException:
        log.warning("%s took longer than %ss to load; reading what is there", url, args.timeout)
    count = wait_for_items(driver, sel.item, args.timeout)
    if count and args.scrolls:
        scroll_to_end(driver, args.scrolls)
        count = wait_for_items(driver, sel.item, args.timeout)
    log.debug("%s: %d elements match %r", url, count, sel.item)
    return driver.page_source, driver.current_url


# --------------------------------------------------------------------------
# Parsing
# --------------------------------------------------------------------------

def clean_text(text: str | None) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def clean_version(text: str | None) -> str:
    """'Version: 2.4.1 ' -> '2.4.1'. Anything else in the text is kept."""
    return VERSION_LABEL.sub("", clean_text(text))[:MAX_VERSION].strip()


def normalize_link(href: str | None, base_url: str) -> str | None:
    """Absolute http(s) URL without its #fragment, scheme and host in lower case;
    None for javascript:, mailto: and other links that are not pages."""
    href = (href or "").strip()
    if not href:
        return None
    parts = urlsplit(urljoin(base_url, href))
    if parts.scheme not in ("http", "https") or not parts.netloc:
        return None
    return urlunsplit((parts.scheme, parts.netloc.lower(), parts.path or "/", parts.query, ""))


def extract(element: Tag, spec: str, default_attr: str | None = None) -> str | None:
    """Text (or attribute) of the first match of spec inside element.
    'sel@attr' reads attr; '@attr' reads the element's own attribute."""
    match = ATTR_SPEC.match(spec)
    selector, attr = (match["selector"].strip(), match["attr"]) if match else (spec.strip(), default_attr)

    node = element.select_one(selector) if selector else element
    if node is None and default_attr and element.name == "a":
        node = element  # the item itself is the link
    if node is None:
        return None
    if attr:
        value = node.get(attr)
        return " ".join(value) if isinstance(value, list) else value
    return node.get_text(" ", strip=True)


def parse_page(html: str, page_url: str, sel: Selectors) -> tuple[list[Item], str | None]:
    """Items on the page, and the next page's URL when sel.next finds one."""
    soup = BeautifulSoup(html, "html.parser")
    base = soup.find("base", href=True)
    base_url = urljoin(page_url, base["href"]) if base else page_url

    items: list[Item] = []
    skipped = 0
    for element in soup.select(sel.item):
        name = clean_text(extract(element, sel.name))[:MAX_NAME].strip()
        link = normalize_link(extract(element, sel.link, default_attr="href"), base_url)
        version = clean_version(extract(element, sel.version))
        package = clean_text(extract(element, sel.package))[:255] if sel.package else ""
        if not name or not link or len(link) > MAX_LINK:
            skipped += 1
            log.debug("Skipped an item without a usable name or link: %s",
                      clean_text(element.get_text(" "))[:120])
            continue
        items.append(Item(name=name, version=version, link=link, source_url=page_url, package=package))
    if skipped:
        log.warning("%s: skipped %d items without a usable name or link", page_url, skipped)

    next_url = None
    if sel.next:
        node = soup.select_one(sel.next)
        if node is not None:
            next_url = normalize_link(node.get("href"), base_url)
    return items, next_url


def crawl(driver: webdriver.Chrome, args: argparse.Namespace, sel: Selectors) -> list[Item]:
    robots = Robots(args.user_agent, args.timeout)
    start_host = urlsplit(args.url).netloc.lower()
    found: dict[str, Item] = {}  # link_hash -> item; the first sighting wins
    visited: set[str] = set()
    url: str | None = args.url

    for page in range(1, args.max_pages + 1):
        if url is None or url in visited:
            break
        if not robots.allowed(url):
            if page == 1:
                raise RobotsDisallowed(f"robots.txt does not allow {args.user_agent!r} to fetch {url}")
            log.warning("robots.txt does not allow %s; stopping here", url)
            break
        if page > 1:
            time.sleep(max(args.delay, robots.crawl_delay(url)))

        visited.add(url)
        html, final_url = render(driver, url, sel, args)
        visited.add(final_url)
        items, next_url = parse_page(html, final_url, sel)

        repeats = 0
        for item in items:
            if item.link_hash in found:
                repeats += 1
            else:
                found[item.link_hash] = item
        log.info("Page %d (%s): %d items%s", page, final_url, len(items),
                 f", {repeats} already seen on an earlier page" if repeats else "")

        if not next_url:
            break
        if urlsplit(next_url).netloc.lower() != start_host:
            log.warning("Next page %s is on another host; not following it", next_url)
            break
        url = next_url
    else:
        if url and url not in visited:
            log.info("Stopped after --max-pages=%d; more pages remain", args.max_pages)

    return list(found.values())


# --------------------------------------------------------------------------
# WordPress: REST
# --------------------------------------------------------------------------

def post_json(url: str, payload: dict, headers: dict[str, str], user_agent: str, timeout: float) -> dict:
    """POSTs JSON and returns the JSON answer. 429, 5xx and network errors are
    tried again (Retry-After, else 2, 4, 8 s); other errors raise RuntimeError."""
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    for attempt in range(1, REST_TRIES + 1):
        request = Request(url, data=body, method="POST", headers={
            **headers, "Content-Type": "application/json", "Accept": "application/json", "User-Agent": user_agent})
        try:
            with urlopen(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8") or "{}")
        except HTTPError as err:
            text = err.read().decode("utf-8", errors="replace")[:500]
            if (err.code == 429 or err.code >= 500) and attempt < REST_TRIES:
                wait = err.headers.get("Retry-After", "")
                delay = float(wait) if wait.isdigit() else 2.0 ** attempt
                log.warning("WordPress answered HTTP %d; trying again in %.0f s", err.code, delay)
                time.sleep(min(delay, 60))
                continue
            try:
                text = json.loads(text).get("message", text)
            except (ValueError, AttributeError):
                pass
            raise RuntimeError(f"WordPress answered HTTP {err.code}: {text}") from None
        except (URLError, TimeoutError, OSError) as err:
            if attempt < REST_TRIES:
                log.warning("Could not reach WordPress (%s); trying again", err)
                time.sleep(2.0 ** attempt)
                continue
            raise RuntimeError(f"Could not reach {url}: {err}") from None
    raise RuntimeError("unreachable")


def send_rest(items: list[Item], args: argparse.Namespace) -> dict[str, int]:
    """Sends the items to the theme in batches; returns its counts added up."""
    url = wp_endpoint("/y4apk/v1/sync/items")
    headers = wp_auth_headers()
    totals: dict[str, int] = {}
    for batch in chunks(items, REST_BATCH):
        answer = post_json(url, {"items": [asdict(item) for item in batch]}, headers, args.user_agent, max(args.timeout, 30))
        flat = dict(answer)
        flat.update(flat.pop("processed", {}) or {})
        for key, value in flat.items():
            if isinstance(value, int):
                totals[key] = totals.get(key, 0) + value
    return totals


# --------------------------------------------------------------------------
# WordPress: straight into its database
# --------------------------------------------------------------------------

# The theme's own upsert (Y4APK_Sync::UPSERT): assignments run left to right,
# so state and changed still see the old version and package (MariaDB's
# SIMULTANEOUS_ASSIGNMENT mode gives the same result). A new or changed item
# waits ('new') for WordPress to match it; an empty version or package never
# replaces a known one.
UPSERT_SQL = """
INSERT INTO {table} (hash, link, name, version, package, source, state, first_seen, last_seen)
VALUES (%s, %s, %s, %s, %s, %s, 'new', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    state      = IF((VALUES(version) <> '' AND version <> VALUES(version))
                    OR (VALUES(package) <> '' AND package <> VALUES(package)), 'new', state),
    changed    = IF(VALUES(version) <> '' AND version <> VALUES(version), VALUES(last_seen), changed),
    version    = IF(VALUES(version) <> '', VALUES(version), version),
    package    = IF(VALUES(package) <> '', VALUES(package), package),
    name       = VALUES(name),
    link       = VALUES(link),
    source     = VALUES(source),
    last_seen  = VALUES(last_seen)
"""


def chunks(seq: list, size: int):
    for i in range(0, len(seq), size):
        yield seq[i:i + size]


def save_db(items: list[Item]) -> dict[str, int]:
    """Upserts the items in one transaction; returns how many were new,
    got a new version, or stayed the same."""
    table = wp_table()
    stats = {"new": 0, "changed": 0, "same": 0}
    connection = pymysql.connect(**db_config())
    try:
        with connection.cursor() as cursor:
            existing: dict[str, str] = {}
            for batch in chunks(items, DB_BATCH):
                hashes = [item.link_hash for item in batch]
                cursor.execute(
                    f"SELECT hash, version FROM {table} WHERE hash IN ("
                    + ", ".join(["%s"] * len(hashes)) + ")", hashes)
                existing.update(dict(cursor.fetchall()))

            for item in items:
                old = existing.get(item.link_hash)
                if old is None:
                    stats["new"] += 1
                elif item.version and item.version != old:
                    stats["changed"] += 1
                    log.info("New version: %s %s -> %s", item.name, old or "(none)", item.version)
                else:
                    stats["same"] += 1

            sql = UPSERT_SQL.format(table=table)
            for batch in chunks(items, DB_BATCH):
                cursor.executemany(sql, [
                    (item.link_hash, item.link, item.name, item.version, item.package,
                     (urlsplit(item.source_url).hostname or urlsplit(item.link).hostname or "")[:191])
                    for item in batch
                ])
        connection.commit()
    except pymysql.err.ProgrammingError as err:
        connection.rollback()
        if err.args and err.args[0] == 1146:  # no such table
            raise SystemExit(f"{table} does not exist yet: update the theme to 2.30+ and open wp-admin once") from None
        raise
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()
    return stats


# --------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------

def main(argv: list[str] | None = None) -> int:
    if load_dotenv is not None:
        load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    args = parse_args(argv)
    logging.basicConfig(level=args.log_level,
                        format="%(asctime)s %(levelname)-7s %(name)s: %(message)s")
    sel = Selectors(item=args.item, name=args.name, version=args.version,
                    link=args.link, next=args.next, package=args.package)

    try:
        driver = make_driver(args.user_agent, args.timeout)
    except WebDriverException as err:
        log.error("Could not start Chrome: %s", err.msg or err)
        return 1
    try:
        items = crawl(driver, args, sel)
    except RobotsDisallowed as err:
        log.error("%s", err)
        return 1
    except WebDriverException as err:
        log.error("Browser error: %s", err.msg or err)
        return 1
    finally:
        driver.quit()

    if not items:
        log.error("No items found on %s with --item %r; check the selectors", args.url, args.item)
        return 2
    if args.dry_run:
        json.dump([asdict(item) for item in items], sys.stdout, indent=2, ensure_ascii=False)
        sys.stdout.write("\n")
        return 0

    if args.target == "db":
        try:
            stats = save_db(items)
        except pymysql.MySQLError as err:
            log.error("MySQL error: %s", err)
            return 1
        log.info("Wrote %d items into %s: %d new, %d with a new version, %d unchanged; "
                 "WordPress matches them on its next run", len(items), wp_table(),
                 stats["new"], stats["changed"], stats["same"])
        return 0

    try:
        stats = send_rest(items, args)
    except RuntimeError as err:
        log.error("%s", err)
        return 1
    log.info("Sent %d items to WordPress: %d new, %d with a new version, %d unchanged, %d refused; "
             "%d matched to posts (%d newer versions), %d without a post",
             len(items), stats.get("new", 0), stats.get("changed", 0), stats.get("same", 0),
             stats.get("invalid", 0), stats.get("matched", 0), stats.get("versions", 0), stats.get("nopost", 0))
    return 0


if __name__ == "__main__":
    sys.exit(main())
