# UpFypSO
UpFypSO is a modern dark gaming platform built with SOL, featuring responsive design, gaming categories, shorts, trending content, SEO optimization, and high performance.

## Themes

- [`theme/y4apk`](theme/y4apk) — the Y4APK WordPress theme (APK / MOD APK download site).
  Runs without any license activation and updates itself from a source we control;
  see [its README](theme/y4apk/README.md) for setup and release instructions.

## Sync system

- [`sync-system`](sync-system) — workers that run beside the site: a catalog
  scraper (Python, Selenium + BeautifulSoup), a monitor that drives the theme's
  Catalog sync and Web Push on a schedule (Node.js), and an API key rotator.
  They feed the theme through its Catalog sync (2.30); see
  [their README](sync-system/README.md).
