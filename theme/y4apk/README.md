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

## Self-hosted updates

`inc/updater.php` puts theme updates on the normal WordPress **Appearance ▸
Themes** screen, from a source we control. Configure one of the two in
`wp-config.php`:

**GitHub releases**

```php
define( 'Y4APK_UPDATE_REPO', 'your-github-user/your-repo' );
// Private repository only:
define( 'Y4APK_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
```

The newest release is used. A `.zip` asset attached to the release is preferred
over GitHub's auto-generated source archive; either works, because the updater
renames the unpacked folder to the installed theme folder before installing.

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
2. Commit and tag: `git tag v2.8.25 && git push origin v2.8.25`.
3. `.github/workflows/release-theme.yml` builds `y4apk-<version>.zip` and
   attaches it to a GitHub release for that tag.

Sites pick the update up within 6 hours, or immediately via *Check for updates
now*.

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
