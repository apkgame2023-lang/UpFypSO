# Y4APK Theme

WordPress theme for Y4APK (APK / MOD APK download site). Based on the MODW build
purchased under order #391748 (MODW · ELITE), rebranded to Y4APK and switched
from the vendor's licensing service to our own update source.

## What changed

### License activation removed

The theme no longer contacts any external licensing server and no longer asks
for a license key.

| Removed | Was |
| --- | --- |
| `inc/extractor/googleplay.class.php` | `M_O_D_W_Base` — key validation + updates against `lic.apktemplates.com` |
| `AT_License` class | The **License Activation** admin page, the "Please Activate Your Theme" front-end overlay, and the dismissible activation notice |
| `SK::checkLicenseKey()` | Blocked the Google Play importer unless a key validated |
| `inc/extractor/settings.php` | Indirection that only loaded the admin when licensed |

The Customizer panel and the whole importer admin are now loaded
unconditionally from `inc/extractor/googleplay.php`. The importer's only
remaining gate is a `manage_options` capability check, which is what it should
have been all along.

Left-over license rows (`MODW_lic_*`) and the old `modw_up` update transient are
deleted from the options table once, on the first admin page load.

### Rebranded to Y4APK

Everything user-visible, plus every internal identifier:

- text domain `apktemplates` → `y4apk` (also in `style.css`)
- constants `APKT_THEME_NAME` / `APKT_THEME_VERSION` / `APKT_TRANSLATE` → `Y4APK_*`
- functions `at_options`, `at_gplay`, `at_settings`, `apkt_header`, … → `y4apk_*`
- class `APKT_GoogleDrive` → `Y4APK_GoogleDrive`
- admin menu **APKTEMPLATES** → **Y4APK**; page slugs `at-gplay` / `at-settings` → `y4apk-gplay` / `y4apk-settings`
- AJAX actions, nonce, and the localized JS object → `y4apk_*` (`.js` and `.min.js` both updated)
- CSS classes `apktemplates-header|logo|title` → `y4apk-*` (`.css` and `.min.css` both updated)
- links to `apktemplates.com` → `y4apk.com`
- the header logo is now a Y4APK mark (`assets/img/y4apk.svg`, set in the site's
  own Golos Bold); the old `modw.svg` stays in place so a saved logo path keeps
  resolving
- `screenshot.png` is a render of this theme, replacing the MODW screenshot with
  "Made by APKTEMPLATES" across it

**Nothing stored in the database was renamed.** Option keys still use the `at_`
prefix, post meta still uses `wp_*_GP`, and all 56 Kirki Customizer settings keep
their original names — so existing settings, imported apps and Customizer values
carry over untouched. The Google Drive OAuth redirect is still
`https://your-site/?at_upload=gdrive`, so the credentials registered in Google
Cloud Console keep working.

Front-end markup and CSS are unchanged: the site looks exactly the same.

## Installing

Upload the theme folder to `wp-content/themes/` and activate it. The folder can
be named anything — `y4apk`, or the old `modw`; nothing depends on it.

WordPress keeps Customizer values in the option `theme_mods_<folder-name>`, so
installing into a *new* folder normally loses the logo, header menus and every
other Customizer setting. The theme handles that itself: on the first admin page
load it finds the old `theme_mods_*` row, copies the settings across, repoints
any URL that pointed into the old folder, and tells you what it did.

Nothing is overwritten — anything already configured for the new folder wins —
and the old row is left untouched, so the previous folder still works if you
switch back. To run it again, or to pick a different source folder, use
**Appearance ▸ Y4APK Updates ▸ Customizer settings**.

## Setting the logo

**Y4APK ▸ Site Logo** sets the header logo from a plain admin screen: upload a
file, pick from the media library, or paste a URL. It writes the same
`logo_img` theme mod as **Customizer ▸ Y4APK Panel ▸ Header ▸ Site Logo**, so
either place works and both stay in sync.

The separate page exists because the Customizer is fragile: it needs Kirki for
the Y4APK panel, and its JavaScript is easily broken by an unrelated plugin —
asset minifiers and JS optimisers are the usual culprits — which otherwise
leaves no way to change the logo at all. Only the media-library button on that
page needs JavaScript; the file upload and the URL field are plain form posts.

The Customizer panel is registered on `customize_register` and
`customize_preview_init` (both at priority 0) — **never on a normal page view**.
See "The database abuse this theme caused" below; loading it on the front end is
what took the site down.

## Direct Link ads (2.13)

Y4APK ▸ **Direct Link** opens an ad link — a Monetag *Direct Link* (SmartLink) or
any other address — in a new tab. It replaces the "Unlock Content" box that was
written into `single.php`, and keeps it as an option:

| When | What visitors see |
| --- | --- |
| **When a download button is tapped** (default) | Tapping a link to a `/download/N/` page on a game page opens the ad in a new tab; the visitor carries on to the download page. Once per visit, once a day, or every tap. |
| "Unlock Content" box | The old full-screen box over every game page until the ad is tapped, once per visit. |
| Off | Nothing. |

Why the default changed: Google may rank pages lower in mobile results when
their content is covered as the visitor arrives from search, and the box covered
every game page. Opening the ad on the download tap keeps most of the clicks —
nearly everyone who comes for a file taps Download — without covering anything.

**Not inside the installed app** (2.13.1). The app has a single window, so an ad
opened from it took that window over, and the ad's close button closed the whole
app instead of leading on to the download. Inside the app — standalone display,
or the APK recognised by its referrer — download taps go straight to the
download page and the Unlock box is not shown. Browsers are unaffected.

The link defaults to `/update-ads`, the address the box opened, so nothing stops
earning after an update; paste the Monetag link straight in to skip that page.
The script is in the footer of game pages only, carries the `y4apk-pwa` keyword and
the don't-delay markers (a speed plugin that waits for the first tap would miss
the tap that matters), and opens the ad with `noopener`, so the ad page cannot
redirect the tab the visitor came from.

## The Y4APK app (2.10; install banner rules 2.11–2.12)

`inc/pwa.php` makes the site installable as an app — an icon on the home screen
that opens full screen, keeps recently viewed pages for offline use, and updates
itself whenever the site does. Settings live under **Y4APK ▸ App**.

| URL | What it is |
| --- | --- |
| `/?y4apk_pwa=manifest` | web app manifest (name, colours, icons, shortcuts to the two header categories) |
| `/?y4apk_pwa=sw` | service worker, served from the root so it covers the whole site |
| `/?y4apk_pwa=offline` | offline page, listing the pages still available |
| `/.well-known/assetlinks.json` | Digital Asset Links for the Android APK, once pasted in |

**Service worker** (`assets/js/pwa-sw.js`). Pages are network first, falling back
to a recent copy and then the offline page; same-origin CSS, JS, fonts and images
are served from cache and refreshed in the background. It never touches anything
that is not a GET, any other origin (ads, analytics, CDNs), wp-admin, logins, the
REST API, AJAX, `/download/` pages or the files themselves. Caches are versioned by
theme version plus a settings revision, so updates roll over cleanly.

**Off switch.** Unticking *Installable app* still answers `/?y4apk_pwa=sw`, with a
worker that deletes its caches and unregisters itself, so browsers that already
installed the app are cleaned up on their next visit rather than left holding it.

**Install banner** (`assets/js/y4apk-pwa-install.js`), a few seconds after each page
loads, for visitors who do not have the app: Chrome, Edge and Samsung Internet get
a one-tap **Install**; iPhone Safari gets the *Share ▸ Add to Home Screen* hint;
Android browsers that cannot install apps — the TikTok, Facebook and LINE in-app
browsers — get **Download** pointing at the APK, if one is set.

- **Closed:** it comes back after *N* minutes (setting, default 5; `0` = on the
  next page), on whatever page the visitor is on by then. After a tap on Install
  or Download it stays away for at least 10 minutes while the install finishes.
- **Already installed:** the manifest lists the web app (by its manifest address)
  and, once `assetlinks.json` is in, the APK under `related_applications`, and the
  banner asks Chrome through `getInstalledRelatedApps()`. Those visitors get a
  banner of their own on the same schedule (its own title and text; can be
  switched off), and the choice stays theirs — nothing opens by itself. APK on
  the phone → an **Open** button (an `intent://` link that opens the same page in
  the APK, falling back to this page). Web app installed → a reminder that it is
  on the home screen, since browsers give pages no way to open it. Android only.
- **Uninstalled:** Chrome stops reporting the app and offers the install again,
  so the banner returns by itself.
- Never inside the app (standalone display, or the APK's `android-app://`
  referrer, which the boot script remembers for the visit) or on download pages. iPhones cannot tell a page whether the app is
  on the home screen, so Safari keeps the hint on the same schedule.

**Startup script and speed plugins.** A small script in `<head>`
(`#y4apk-pwa-boot`) registers the service worker as soon as the page has parsed —
not after every ad has loaded — and catches the browser's one-off install offer.
It must not be delayed, or robots that never tap the page (PWABuilder,
Lighthouse) never see a service worker. It carries the usual don't-delay markers
(`nowprocket`, `data-no-optimize`, `data-noptimize`, `data-no-defer`,
`data-no-delay`, `data-cfasync="false"`) and the keyword `y4apk-pwa` in its id, its
body and the install script's file name. **10Web Booster** needs `y4apk-pwa` in
**both** exclusion lists — *JavaScript delay* and *JavaScript aggregation* — then a
cache clear.

**Check the app.** A button at the top of Y4APK ▸ App fetches the site the way a
logged-out visitor does — through the page cache and the speed plugin — and
reports: is the manifest readable; is the worker served as JavaScript (or is a
cache handing back a web page because it ignores `?y4apk_pwa=`); is the startup
script on the page visitors get, and untouched (it names the plugin's rewritten
`type` when it has been delayed, or says it is missing when it has been
aggregated); and does the worker actually start in this browser.

**Manifest extras.** Screen orientation (turns with the phone, or always
portrait); up to 8 screenshots picked from the Media Library, which Android shows
in its larger app-store style install sheet — the admin warns when Chrome would
ignore them (a side outside 320–3840 px, longer than 2.3:1, or mixed shapes);
`related_applications` with the web app and, once its `assetlinks.json` is in,
the APK's package.
PWABuilder's IARC age-rating suggestion needs a certificate from the IARC rating
process, which is not available for an app outside the stores, so it stays unset.

**Icons.** Pick the Site Icon (the default), **your own image** — upload it or choose
it from the Media Library — or the bundled Y4 icon. From the Site Icon or your own
image the theme makes every size the app needs with PHP GD, once, on an admin
request: transparent 192/512 icons, *maskable* 192/512 icons for Android (the
visible artwork scaled into the 80% safe circle on the chosen background colour,
so launchers that crop to a circle never cut the fox's ears off), and a solid
180 px icon for iPhone, which shows transparency as black. Files go to
`wp-content/uploads/y4apk-app/`. If the image cannot be read, the bundled icon is
used rather than a broken one.

**Cost.** One autoloaded option and a few `<head>` tags per page: the homepage runs
the same 27 queries with the app on or off. The endpoints answer early on `init`
and exit — 2 queries for the worker and offline page, 4 for the manifest.

**APK versions (2.15).** Y4APK ▸ App ▸ *APK version* holds the Version of the
APK at the download link (as set in PWABuilder, e.g. `1.0.0.0`).

- The Download button says which version it gets: **Download v1.0.0.0**. The
  link carries `?v=<version>`, so a cache (Cloudflare caches `.apk`) never hands
  out the previous file after the APK is replaced.
- **People with an older APK are asked to update** (switch: *Older APKs*), on
  the install banner's schedule: "Y4APK app 1.0.1.0 is out — You have version
  1.0.0.0" with **Update**, which downloads the new APK; it installs over the
  old one. It is the one banner shown inside the app.
- How the version is known: inside the app, from its Start URL — build the APK
  with Start URL `/?y4apk_app=<Version>`; the boot script remembers it for the
  visit and tidies the address. In Chrome on Android, and inside an APK built
  without that Start URL, from `getInstalledRelatedApps()`, which reports the
  installed version once `assetlinks.json` is saved. No version known → no
  prompt, never a guess. Versions compare as numbers (`1.0.10` > `1.0.9`).

### Turning it into an Android APK

Google Play does not accept apps that distribute modified APKs, so the app is
offered as an APK file from the site itself.

1. Switch the app on, save, clear the 10Web cache.
2. Open [pwabuilder.com](https://www.pwabuilder.com/), enter the site address, then
   *Package for stores ▸ Android*, tab **Other Android** (for APKs handed out
   directly). Package ID `com.y4apk.app` — it can never change once people have
   installed the app. Under *All Settings*: Version `1.0.0.0`, Version code `1`,
   Start URL `/?y4apk_app=1.0.0.0`, Signing key *New*. *Download Package*.
3. **Keep the zip safe** — especially `signing.keystore` and `signing-key-info.txt`.
   Every future update of the app must be signed with that same key; lose it and
   the installed app can never be updated, leak it and others can sign updates in
   your name.
4. Paste the zip's `assetlinks.json` into Y4APK ▸ App. Without it the app opens with
   a browser address bar.
5. Upload the `.apk` to the Media Library (the theme allows `.apk`) and paste its
   URL into *APK download URL*.

## Self-hosted updates

`inc/updater.php` puts theme updates on the normal WordPress **Appearance ▸
Themes** screen, from a source we control.

**Out of the box (2.14)** it reads the GitHub releases of this repository,
`apkgame2023-lang/UpFypSO` (public, so no token). Nothing to configure. In the
admin:

- **Which version is installed:** at the bottom right of every admin page
  (`Version 6.x | Y4APK 2.14.0`), and in a notice on the first admin page after
  an install or update ("Y4APK updated from version 2.13.1 to 2.14.0").
- **A newer release exists:** a notice on every admin page — "Y4APK 2.15.0 is
  available. This site is on version 2.14.0" — with **Update now** (WordPress's
  own one-click theme update), *What's new* (the release page) and *Remind me at
  the next version* (per user, per version).
- WordPress refreshes its own list of updates only every 12 hours; when the
  theme sees a newer release that list lacks, it saves the list again so
  Dashboard ▸ Updates, the Themes screen and the notice always agree.
- The check is cached for 6 hours (failures too), with a 10-second timeout.

To use another source, configure one of the two in `wp-config.php`:

**GitHub releases**

```php
define( 'Y4APK_UPDATE_REPO', 'your-github-user/your-repo' );
// Private repository only:
define( 'Y4APK_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
```

The newest release is used, through the theme `.zip` attached to it
(`y4apk-<version>.zip`); the updater renames the unpacked folder to the installed
theme folder if they differ. A release without that zip is ignored: GitHub's
auto-generated source archive of this repository holds the whole repository, with
the theme in `theme/y4apk`, and would install as a broken theme.

**A JSON file you host**

```php
define( 'Y4APK_UPDATE_MANIFEST', 'https://y4apk.com/updates/y4apk.json' );
```

```json
{
    "version": "2.8.25",
    "download_url": "https://y4apk.com/updates/y4apk-2.8.25.zip",
    "details_url": "https://y4apk.com/changelog/",
    "changelog": "<p>What changed…</p>",
    "requires": "5.4",
    "tested": "6.4.2",
    "requires_php": "7.4"
}
```

Both can also be set with the `y4apk_update_repo` / `y4apk_update_manifest` /
`y4apk_github_token` filters.

Lookups are cached for 6 hours. **Appearance ▸ Y4APK Updates** shows the
installed version, the configured source and the latest available version, and
has a *Check for updates now* button that clears the cache.

## Releasing a new version

1. Bump `Version:` in `style.css`.
2. Commit and tag: `git tag v2.15.0 && git push origin v2.15.0`.
3. `.github/workflows/release-theme.yml` builds `y4apk-<version>.zip` and
   attaches it to a GitHub release for that tag (it refuses a tag that does not
   match `Version:`).

Sites pick the update up within 6 hours, or immediately via Appearance ▸ Y4APK
Updates ▸ *Check for updates now*.

### If an update leaves the theme "broken"

"Template is missing … index.php" on the Themes screen means the theme folder
was only partly written — the upload or update was cut off (a timeout, a 520
from Cloudflare, a full disk). WordPress may still hold the previous copy in
`wp-content/upgrade-temp-backup/themes/y4apk`. To repair, in the host's file
manager: rename `wp-content/themes/y4apk` to `y4apk-broken`, upload the release
zip to `wp-content/themes/`, extract it (it creates `y4apk/`), check the site,
then delete `y4apk-broken` and the zip. Keep the folder name `y4apk`: the
Customizer settings are stored under it.

## The database abuse this theme caused

Versions 2.9.1 and 2.9.2 fix a regression introduced by removing the license
check. It is why the site started timing out, and then why the host suspended
the MySQL user for "database abuse" and the site began showing WordPress's
install screen (WordPress could not read `siteurl`, because it could not reach
the database at all).

The host's log named the offending statement: metadata being fetched for
hundreds of posts at once, with hundreds of post IDs listed in one request
against `wp_postmeta`. That is this, in `inc/customization.php`:

```php
'choices' => Kirki\Util\Helper::get_posts( array( 'post_type' => 'post', 'posts_per_page' => -1 ) )
```

Kirki's helper calls `get_posts()` and leaves `update_post_meta_cache` on, so
WP_Query follows the post query with a single

```sql
SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (…every published post id…)
```

This theme stores about fifteen meta rows per app, so on a site with a few
thousand posts that one statement drags tens of thousands of rows out of
`wp_postmeta` — twice per request, because two pickers use the same helper.

In the original theme `inc/customization.php` was only included when a license
key validated. With an inactive license it never loaded, so nothing paid for it.
Removing the license check and loading it unconditionally put those statements
on **every front-end request, bot traffic included**.

Two fixes:

**2.9.1 — the file no longer loads on the front end.** It is hooked to
`customize_register` and `customize_preview_init` only. The front end never
needed it: templates read values with `get_theme_mod()` straight from the
options table and carry their own inline defaults, and this theme declares no
Kirki `output` rules, so Kirki generates no front-end CSS from these fields.
`inc/updater.php`, `inc/migrate.php` and `inc/logo.php` also return early
outside admin, cron and WP-CLI requests.

**2.9.2 — the query itself is bounded.** `y4apk_post_choices()` and
`y4apk_term_choices()` replace Kirki's unbounded helpers at all five picker
call sites: a recent slice only (200 posts, 300 terms, both filterable via
`y4apk_choices_post_limit` / `y4apk_choices_term_limit`), with
`update_post_meta_cache`, `update_post_term_cache`, `update_term_meta_cache`
and `no_found_rows` all off. So even opening the Customizer on a large site no
longer produces the statement the host flagged.

Measured on a 1200-post install:

| | queries | DB time | largest `wp_postmeta IN (…)` |
| --- | --- | --- | --- |
| Loading the field definitions, before | 6 | ~108 ms | **1200 post IDs** |
| Loading the field definitions, 2.9.2 | 5 | **~6 ms** | **none** |

| Homepage | queries | DB time | page render | queries > 10 ms | largest `wp_postmeta IN (…)` |
| --- | --- | --- | --- | --- | --- |
| Before | 44 | ~99 ms | ~367 ms | 2 | 1200 post IDs, twice |
| 2.9.2 | 55 | **~40 ms** | **~268 ms** | **0** | 10 post IDs (just the posts on screen) |

The query count rises because Kirki's `get_posts(-1)` had been warming the post
cache as a side effect; the replacements are small indexed lookups, which is why
total DB time still falls sharply. The cost of the old behaviour grew with the
post count, so the live site paid far more than this test.

The rendered HTML is byte-identical before and after, and the Customizer still
has all 13 sections, 85 controls and populated pickers.

## Bugs fixed along the way

Found by running the theme against a real WordPress install; all four predate
the rebrand.

- `components/home/home-category.php` passed the text domain as the *default
  value* of `get_theme_mod('terms', …)`, so a site that had not configured the
  category sections got a string where an array was expected: a PHP warning and
  no category sections on the homepage at all.
- `components/single/single-info.php` never defined `$app_name`, so the Safety
  Check line on every app page read "The game/app  has been tested…" with a hole
  in it.
- `components/single/single-download-links.php` left `$grid2_class` undefined
  when a post had neither an APK nor an OBB download.
- `template/header.php` and `template/footer.php` warned when a menu location
  had no menu assigned yet.
- `components/download/download-link.php` warned on the download page of a post
  with no download links, which stores an empty string rather than an array.
- `inc/inits.php` set the screenshots metabox row counter only inside the branch
  for posts that already had screenshots, so on every other post the hidden
  template row rendered with an empty id and the row that "Add Screenshot"
  cloned had an Upload button pointing at no field.

The Customizer panel is also now registered on `init` rather than while
`functions.php` loads, which clears the "translation loading triggered too
early" notice WordPress 6.7+ logs for every `__()` call in it.

## Requirements

- WordPress 5.4+, PHP 7.4+
- [Kirki](https://wordpress.org/plugins/kirki/) — prompted for on activation via TGMPA; the Customizer panel is skipped if it is missing
- For the Google Play importer: `max_execution_time` and `max_input_time` ≥ 300, and `memory_limit` / `post_max_size` / `upload_max_filesize` ≥ 4G for APK uploads
