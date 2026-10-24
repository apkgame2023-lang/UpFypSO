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
unconditionally from `inc/extractor/googleplay.php`. (The importer itself is
gone since 2.33: see *The old Google Play importer, removed*.)

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
carry over untouched.

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

## App updates: which posts have a newer version out (2.17–2.22)

**Y4APK ▸ App updates** lists the posts whose download is older than the app's
latest version, most downloaded first (last 30 days, from Analytics), with a form
per post for the new file. The posts list gets a *Latest version* column (with its
source), a *Needs update (N)* link and a *Check version now* row action. Every
step runs in the background (WP-Cron, every 15 minutes); *Run a step now* runs one
at once.

**1. Linking posts to their Google Play app** (`wp_GP_ID`). Most posts are written
by hand, so, newest first:

- a Google Play link in the post's content is taken as the ID (no requests; 100
  posts a step);
- otherwise Google Play is searched for the post's **App Name** (App Information);
  the post is linked only if one of the first two results has exactly the same
  name — ignoring case and punctuation — and the same publisher when the post has
  one (6 posts a step, up to 3 requests each);
- anything less certain, and posts without an App Name (searched by the title up
  to "MOD"/"APK"/the version), become **suggestions** on the page — only when the
  Play name shares at least half of the post name's telling words (generic words
  like script, hub, mod, apk, free, key do not count), so a Roblox script post is
  not offered "Rscripts: Roblox Scripts" (2.18.1): *Use*, type the right ID, *Not
  on Google Play*, or tick several and *Not on Google Play — all ticked*;
- App Information has a **Google Play ID** field (a Play link or a bare ID); a new
  ID clears the old result so it is checked again.

State per post in `_y4apk_gp_link` (content, scanned, search, suggested, nomatch,
confirmed, rejected) and `_y4apk_gp_suggest`.

**2. Latest version — from Google Play itself** (2.18): the app's page
(`hl=en` and the store country from Settings — TH by default since 2.20 — then
the US store, then any), read from its `AF_initDataCallback` data
(`ds:5` → `[1][2][140][0][0][0]`; name `[1][2][0][0]`, publisher `[1][2][68][0]`,
updated `[1][2][145][0][1][0]`) — the number the Play Store shows. When Play shows
"Varies with device", Aptoide's public API is the fallback, **plain dotted numbers
only** (otherwise the column says Google Play shows "Varies with device", as for
Netflix and TikTok): Aptoide also lists other builds (Brawl Stars "1009.0-wv" while Play has
69.414), which 2.17.0 wrongly used; its results are cleared on upgrade. 20 posts a
step, by post ID, round and round. If Google answers 429/5xx the step stops and the
next one carries on. Stored: `_y4apk_latest_version`, `_y4apk_latest_source`
(play / aptoide), `_y4apk_latest_checked`, `_y4apk_needs_update`.

**Test one app** on the page shows what this server reads from Google Play and
Aptoide for any app ID or Play link — to compare with the Play Store by eye.

**3. Saving a new file** puts the version, size and link on the post's APK download
and in App Information (App Version, App Size), changes the version in the title
and in the **Rank Math** SEO title, description and social titles ("v2.742" →
"v2.750"; bare numbers are left alone), and marks the post updated. Nothing on the
site changes without it. The form starts with the post's current size and link
(2.18.2), so only what differs for the new file needs typing.

Posts with several downloads (2.19):

- the version compared is the **newest** APK-type download (APK, APKS, XAPK, APKM,
  MOD), not the first one, so a post that lists older versions too is compared
  by its newest;
- downloads with the **same version** (OBB/ZIP, IPA, another APK build) get their
  own line in the form with the new version filled in and their current size and
  link: ticked ones are updated too — OBB and Android files start ticked, an
  iOS file (IPA/iOS) unticked;
- older versions are listed as *left as they are*;
- **Replace** swaps the old files for the new ones; **Add as new** puts each new
  file just above its old one, which stays as an older version (so it must have
  the new file's link). Posts that already keep an older APK version start with
  *Add as new*;
- the form is refused if the post's downloads were edited after the page was
  opened.

**4. App Information from Google Play** (2.20). The same page read gives the
publisher (`[1][2][68][0]`), age rating (`[1][2][9][0]`, "Rated for 12+" → `12+`;
PEGI/USK numbers and ESRB words — Teen → 13+ — are turned into the same form),
Android version (`[1][2][140][1][1][0][0][1]`, "5.1 and up" → `5.1`), rating
(`[1][2][51][0][1]`, one decimal) and number of ratings (`[1][2][51][2][1]`);
the page's schema.org data is the fallback. They are kept in `_y4apk_gp_info`
(shown as chips on each row) and, for the fields ticked in Settings, written to
App Information: `wp_developers_GP`, `wp_contentrated_GP`, `wp_requires_GP`, and
`avg_rating` / `total_votes` / `total_rating` (as the App Information box saves
them). Empty values never overwrite; the post's modified date does not change.
Ratings and age ratings differ by country, hence the store setting. The App
Information rating slider now moves by 0.1 so 4.4 survives a save. Note that the
theme's schema markup (`inc/snippet.php`) publishes these numbers as the post's
`aggregateRating`.

**5. Google Search numbers** (2.21). Clicks and impressions per page over the
last 28 days come from Search Console through **Site Kit** (its
`google-site-kit/v1/modules/search-console/data/searchanalytics` datapoint,
`dimensions=page`, up to 25,000 rows): *Sync from Site Kit now* in Settings once,
then once a day by itself, read as the administrator who synced. Without Site Kit,
upload Search Console's export (Performance ▸ Pages ▸ Export: the ZIP or its
Pages.csv). Pages are matched to posts by slug (Thai and other encoded slugs too;
a post's `/download/N/` pages count for it); totals are kept in the option
`y4apk_gsc`. *Need a new file* is ordered by Google impressions (switch: Google
search / Downloads) and shows them per row. Each background step first checks up
to 8 linked posts not checked for a day, picked by impressions plus 500 per
follower, so the posts that matter most are checked daily; the rest go round as
before (a round takes longer: run the cron job every 15 minutes to keep it short).

**6. Update notifications** (2.21, `inc/push.php`). Under each post's downloads,
*Notify me when updated* (shown only where the browser supports web push and
Y4APK ▸ App is on: the app's service worker shows the notifications). Following
stores the browser's push subscription (`{prefix}y4apk_push_subs`) and the post
(`_follows`); `POST /wp-json/y4apk/v1/follow` takes same-site requests only and
push addresses of the browsers' push services only (FCM, Mozilla, Windows, Apple).
When a post's newest APK version goes above the one followers were told about
(`_y4apk_push_version`, set when someone follows) — saved in App updates or in the
post editor — a message per follower goes into `_queue`; the first 100 are sent at
once in parallel, the rest a minute later by WP-Cron. Standard Web Push: VAPID
(RFC 8292, ES256, key pair made on first use and kept in the `y4apk_push` option)
and per-browser encryption (RFC 8291, aes128gcm). The message says "<App> has an
update — Version X is ready to download" and opens the post. Browsers that have
unsubscribed (404/410) are forgotten; busy push services are retried twice. The
App updates rows show how many followers wait; Settings has the switch, totals
and the last sends. For the Android app: keep *Notification delegation* on in
PWABuilder.

2.21.1: the JSON kept in post meta (`_y4apk_gp_info`, `_y4apk_gp_suggest`) is
saved with `wp_slash()`, because post meta strips one level of backslashes: before,
Thai and other non-Latin names came back as "u0e40u0e01…" and names with quotes
not at all. Damaged suggestions are looked up again on upgrade.

The save hook for the update flag now runs on `save_post` (priority 99), after
the Download Links box has saved its rows, so editor saves are seen at once.

**The page** (2.20) is full width: a status pill (running / slow / stalled /
off) and *Run a step now*; five cards (linked, versions checked, need a new
file, suggestions, App Information read); tabs *Need a new file* (filter box,
app icon, Play chips, version change, the form), *Suggestions*, *Test one app*
(now also shows the five fields) and *Settings* (background on/off, store
country, App Information fields, and the exact PHP cron command for this
server).

**Running on time.** WP-Cron runs when an uncached page is opened, which a page
cache makes rare. A Hostinger cron job (type PHP) every 15 minutes —
`/usr/bin/php /home/…/public_html/wp-cron.php` — keeps the steps regular; the
Settings tab shows the line with this server's own path.

**7. Uploading the new file to Cloudflare R2** (2.22, `inc/r2.php`,
`assets/js/y4apk-r2-upload.js`). The site's download links are folders on this
server: `https://files.y4apk.com/up/79275-No-Limit-Drag-Racing-2-MOD.apk` is
`public_html/files/up/79275-No-Limit-Drag-Racing-2-MOD.apk/default.php`, a
"Preparing your download" page that sends the visitor to the file on R2
(`https://files.y4apk.net/A/…apk`). With R2 set up (Settings ▸ *Uploads to
Cloudflare R2*), every download line of the form has **⬆ Upload**:

- the file goes **from the browser straight to R2** as an S3 multipart upload:
  parts of 16 MB (more for very large files, at most 9,000 parts), four at a
  time, each retried three times, through links this server signs (AWS
  Signature V4, region `auto`; checked against botocore's signer). Nothing
  passes through this server, so 2–3 GB files are fine. Progress, speed and
  Cancel (which also aborts the upload on R2) show under the line; Save waits;
- the server then completes the upload and checks R2 has every byte (HEAD); the
  key is named like the bucket's files:
  `T/Test-Racer-MOD-v9.0-Y4APK.COM-IDv123456.apk` (first letter of the app,
  type, version, a random ID, the file's own extension);
- **Save**, with *Replace*, writes the new R2 address into the post's own
  folder page and keeps the post's link; with *Add*, or when the link is not one
  of these folders, it makes a new folder (`65694-Keep-Game-MOD.apk`: since
  2.25 a 5-digit number no other folder has, as the site's own are, not one
  after the highest), its page the *Download page for new folders* in
  Settings. Only uploads finished from this site for this post are
  accepted (a transient per key); only folders inside the folder of pages are
  touched (no `..`, no other hosts);
- the page filler handles every design in use (checked on all 651 pages of
  `files/up`): every R2 file address in the page becomes the new one, and the
  places the pages keep for it (`<a class=d href=…>`, `location.href=…,0)`,
  PHP `$apk="…"`) are filled even while they say `#` or point elsewhere; the
  3-second fallback and ad codes stay. A page with no such place is kept as
  `default.php.bak` and replaced.

Setup: an R2 API token (*Object Read & Write*, the bucket) — its keys can also
be `Y4APK_R2_KEY_ID` / `Y4APK_R2_SECRET` in wp-config.php — and, once, the
bucket's CORS policy (shown on the card, with this site as origin and the ETag
header exposed). *Test the connection* checks the keys from the server and an
upload from the browser.

*Replace* is the default for every post since 2.22 (it keeps the links); *Add*
with the old link and no upload is stopped on the page with an explanation.

## The post page (2.22)

**Lighter.** The whole Font Awesome icon set was loaded from cdnjs in `<head>`
— render-blocking, another server, and its font — for three footer icons; they
are inline SVG now (`add_filter('y4apk_load_fontawesome', '__return_true')`
brings the stylesheet back if something else needs it). `assets/js/main.js`
carried jQuery UI 1.13.1 and Touch Punch (≈125 KB) that nothing used — the
rating popup is plain show/hide — and is now only the theme's own code (3 KB).
The app icon at the top is the page's main picture: it is fetched first
(`fetchpriority="high"`), not lazily. On a mid-range Android phone emulation
(Slow 4G, 4× CPU) the main content appears at 1.4 s instead of 2.3 s and the
page finishes loading at 2.2 s instead of 3.4 s (test site, without ads).

**Why trust the file.** Under the title, a small badge with a pulsing "live"
dot: "Updated 3 days ago", and when the version check agrees, "· ✓ Latest
version" (its tooltip names the version and Google Play; 2.22.1). Before the
downloads, *What's new in v7.8* from Google Play (read with the version check,
`[1][2][144][1][1]`, kept as plain lines in `_y4apk_gp_info`; shown only when
that is the version the post offers). Many apps paste every version's notes
there ("Version 2.1.1: / - Bus price fix / Version 2.1: / - Bus / …"): since
2.27 only the newest version's are shown, without their own "-" or "•", at
most 5 lines and *Show N more* for the rest. After them, *How to install*: download,
allow "Install unknown apps", the OBB folder (`Android/obb/<package>`) when the
post has an OBB, and the Android version needed. Each can be switched off in
App updates ▸ Settings ▸ *On each post*.

**No icon, no broken image** (2.27). A post with no featured image (an app gone
from Google Play, whose icon could not be fetched) showed an empty `<img>` on
every card and at the top of its page; it now shows a tile with the app's
first letter in a colour of its own (`y4apk_icon_url()`, an inline SVG). A
download whose size is not known shows no "(—)" (`y4apk_size_label()`), and
the Download Links box keeps each field on its own: a link used to be dropped
on Save when the row had no size.

## Members, Review and the Dashboard (2.35)

People can share Roblox scripts on the site **without ever seeing WordPress**.
Everything is on pages of the theme:

| Page | What it is |
| --- | --- |
| `/account/login/` `/register/` `/forgot/` | Sign in, create an account (3–20 letters, numbers, `_`; email; a password of 10+ characters), reset a password |
| `/account/` | The member's dashboard: scripts with *Live* / *In review* / *Needs changes* (with the reason), views, Edit and Delete |
| `/upload/` | **Share a script** (2.38; `/account/submit/` redirects here): four steps, below |
| `/account/settings/` | Change password; close the account (the scripts can go with it, or stay without the name) |
| `/u/<name>/` | The member's public page: their live scripts and views |

**The upload page (2.38).** One form in four steps, with the steps listed on the
left (a row of four on phones) and each one checked before the next:

1. *Where does your script work?* **One game**: search the site's games by name,
   or paste a roblox.com link or place ID (a game already here is matched by its
   place ID); popular games are one tap; a game not here yet is sent as typed and
   waits for you in Review (a link's title uses the name in the link). **Any
   game**: the script goes under a *Universal* game, made the first time.
2. *Build your script page*: the game's artwork, the script's name (with a
   counter), the game and the member, a *Key system* chip, *About this script*,
   and the post's title as it will be made.
3. *Add your code*: up to three codes with a name each (*PC*, *Mobile*, *Thai*).
4. *Review and send*: the post as a card, Turnstile, and *Send for review*.

Signed out, `/upload/` goes to sign-in and back. When the server refuses (a code
already on the site, Turnstile), the page opens again on the step to fix with
everything typed kept. Without JavaScript all four steps show on one page, with
the plain game list. Its script is named `y4apk-pwa-upload`, which 10Web's
JavaScript delay leaves alone. In 10Web ▸ Cloudflare CDN ▸ Bypass Urls add
`/upload/` next to `/account/` and `/u/` (with both slashes: `/upload` alone
would also match `/wp-content/uploads/`).

**What a member can do, and cannot.** The role *Script Author* has only the
`read` capability. It cannot open wp-admin (it is sent back to `/account/`), has
no admin bar, and over the REST API cannot write a post or upload a file (403)
nor list users. The theme itself saves what the form sends, as plain text, as a
*pending* post. Nothing a member sends shows until the owner approves it, and
whatever they type is shown escaped: a `<script>` in a name or a code appears
as text.

**Getting in.** Registration is **closed until you open it** (Y4APK ▸ Members).
A new account must confirm its email (a link, valid 48 hours) before it can sign
in; the link works once and gives no hint about other accounts. The sign-up form
says the same thing whether or not the email is already known. Against bots:
Cloudflare **Turnstile** on sign in, sign up, reset and sharing (set the two keys
in Members, or `Y4APK_TURNSTILE_SITE` / `Y4APK_TURNSTILE_SECRET` in
`wp-config.php`), a hidden field bots fill in, a form that must have been open
3 seconds, and limits: 20 tries and 4 new accounts an hour per address (150 for
the whole site), 6 wrong passwords per name and 10 per address in 15 minutes
(then even the right one waits), 5 scripts a day and 15 waiting per member (both
changeable). Addresses are only kept as a keyed hash, behind Cloudflare the real
visitor address is read only when the request came from Cloudflare's own ranges.
Only the *Script Author* role can sign in through `/account/`; the people who run
the site use the normal wp-login.php, with its own protections.

**The code a member sends** must be at most 4,000 characters, plain text, and not
already on the site (a deleted script's code can be sent again). Code that reads
the Roblox cookie (`ROBLOSECURITY`), posts to a Discord webhook, or uses IP
loggers is refused outright. The addresses the code loads from are listed in
Review: green when they are on your list of trusted addresses (Members ▸ Trusted
addresses), amber when not. That is a pointer for where to look first, not a
guarantee: a loadstring loads code that can change after you approve it.

**Y4APK ▸ Review.** The scripts waiting, each with the member (how long a member,
how many live), the code, where it loads from, and: **Approve and publish** (pick
the game, or type a new one or a roblox.com link; a game the member asked for is
offered), **Send back** (the reason is emailed and shown to the member, who edits
and sends it again), **Ban member** (closes the account, removes what waits and
puts what is live back in review). Below, published scripts visitors **reported**:
*Dismiss reports* or *Hide and review again*. A badge on the menu counts what
waits. Approval emails the member that the script is live; a new script emails the
owner (at most once in 15 minutes).

**Reports.** Under the code on a Roblox script, *Report this script* (it does not
work / harmful / spam / other). One report per visitor per script; once a
member's script has as many reports as you set (5), it goes back in review by
itself and you are emailed. Scripts you wrote yourself are never hidden by
reports, only listed.

**Shared by.** A script shared by a member says *Shared by @name* above its code,
linking to their page. The unlock step (2.34) and *Copy* work the same.

**Y4APK ▸ Dashboard** (the menu's first page): published posts and views; Roblox
scripts and games; members (this week against last); scripts from members and what
waits; ad opens and file downloads; charts for 30 days (new members, scripts sent,
ad opens); the most read posts; the Roblox games with their scripts, views and
players; the review queue; the newest members (unconfirmed, banned); and how the
parts are doing: Write with AI (queue, paused, waiting for a limit), App updates
(apps with a newer version), R2, Direct Link and the unlock, the app, and the
privacy checks. Counted in the database and kept 5 minutes.

**What the site tells strangers about its accounts** (also 2.35). `?author=1` used
to redirect to `/author/<sign-in name>/`, and `/wp-json/wp/v2/users` listed every
author's name, slug and Gravatar hash. Now: `?author=` goes home; `/author/…` is a
404 (a member's goes to `/u/<name>/`); the users list answers 404 to anyone who
cannot manage users; posts show author 0 in the REST API; oEmbed and feeds show the
site's name; the users sitemap, the generator tag, XML-RPC and the pingback header
are off; wp-login.php says the same words for a wrong name and a wrong password.
Y4APK ▸ Members ▸ *Check my site now* looks at it the way a stranger does, and
*Use the site's name* sets the team accounts' public name (display name,
nickname, author slug) to the site's, which Rank Math prints in schema. What a
plugin cannot change is the name you sign in with: if it was ever public (it was,
through `?author=1`), make a new administrator with a name nobody can guess and
delete the old one.

Plugins print generator tags of their own, with their version (Site Kit,
Elementor, slider plugins): since 2.35.1 every `<meta name="generator">` printed
in `wp_head` is taken out, whoever printed it, and *Check my site now* quotes
any that are still in the page (a cached copy: clear the page cache and check
again). The Dashboard's numbers are now really kept for 5 minutes (2.35.0
counted them again on every visit).

**Page cache.** `/account/…` is sent with `no-cache` headers and
`DONOTCACHEPAGE`, but exclude `/account/` and `/u/` in 10Web Booster as well: a
cached form has a stale nonce, and its hidden time field is refused after 6
hours. Since 2.35.2 *Check my site now* opens `/account/login/` twice, like a
browser, and warns when the second copy comes from a cache (an older time field,
or a `*cache*` header saying HIT), naming the cache by its headers.

Since 2.35.3 a kept copy still works: the account pages ask `admin-ajax.php`
(`y4apk_fresh`) for new nonces, and for a new time field when theirs is over an
hour old, and hold a quick submit until they are in. They also send
`CDN-Cache-Control`, `Surrogate-Control` and `X-LiteSpeed-Cache-Control`.
Cloudflare Turnstile is loaded from the inline `y4apk-pwa-account` script: as a
plain `<script src>`, 10Web Booster's JavaScript delay held it back until the
visitor moved, and the box stayed empty. **Email** goes through `wp_mail()`: on shared
hosting install an SMTP plugin (FluentSMTP) and use *Send myself a test email*.

## The old Google Play importer, removed (2.33)

The vendor's importer — Y4APK ▸ *Google Play* (a Play URL → a post, its APK
downloaded to the server, Google Drive or FTP) and Y4APK ▸ *Settings* — is
gone, with `admin/` and the Google API client under it (816 of the theme's 946
files, 7 MB). It had been replaced: App updates ▸ *Add from Google Play* adds
apps (a Play link or package name goes straight to that app) and Write with AI
writes them; App updates uploads files to R2.

It was also a risk. Its four AJAX actions were registered for logged-out
visitors too (`wp_ajax_nopriv_…`) and checked a nonce but never a capability,
and one of them fetched a URL from the request and wrote it into `uploads/`
under the URL's own file name. On the first admin visit its stored settings
are deleted, with the Google Drive secret and token and the FTP login it kept in
plain text; `at_mod_feature` stays (Write with AI's default MOD).

Every Y4APK page is under one menu (`Y4APK_MENU`), which opens on the
Dashboard (2.35).

## Roblox (2.32)

Roblox script posts lived in a "Roblox Script" category under Games, mixed in
with the APKs. They have a section of their own now, sorted by the Roblox game
each script is for:

- **`/roblox/`** — every game (icon, scripts, players on Roblox), a "Find a
  game…" box, and the latest scripts, 24 a page. It is an ordinary page (slug
  `roblox`, made once on the first admin visit), so its title, text and Rank
  Math SEO are edited like any page; the theme draws the rest.
- **`/roblox/<game>/`** — the game's banner, icon, scripts, views, players
  and likes, *Play on Roblox* and *Share*; a summary ("Y4APK lists 24 Blox
  Fruits scripts…, 10 of them are keyless. Most viewed right now: …"); search
  within the game, *All* / *Keyless*, sort by *Recently updated*, *Newest* or
  *Most viewed*; the scripts 4 a row (3 on tablets, 2 on phones), 24 a page;
  and more games. The title is "Blox Fruits Scripts", also with Rank Math
  (its default "… Archives"), and the description is written from the numbers
  when the game has none.
- **Header** — *Roblox* after Games and Apps, its menu the games with their
  icons; on phones a third tab. The Roblox category is no longer a Games tile.
- **On a script post** — Home → Roblox → Blox Fruits, and "More Blox Fruits
  scripts" and "Other Roblox games" in place of "Similar".

A game is a term of the `roblox_game` taxonomy (Posts ▸ Roblox Games). A post's
**Roblox Game** box (beside the editor) picks one, or adds one by name, by
`roblox.com/games/<id>/…` link or by place ID. With a link the game's name,
icon, banner, players, visits, likes and maker come from Roblox's public API
(place → universe, `games`, `games/votes`, `thumbnails …/icons` and
`…/multiget/thumbnails`), and are brought up to date daily in one batched call;
when Roblox cannot be reached, the name comes from the link. Without a link the
game's icon is its newest post's picture, and its banner that post's first
screenshot. The game's screen has the link, *Fetch from Roblox now*, and an
icon and banner of your own.

**Kept apart.** Posts with a game, or still in the Roblox category, are left out
of the Games and Apps category pages, the home sections (unless a section *is*
the Roblox category), the Games menu and APK posts' "Similar". The Roblox
category page sends visitors to /roblox/ (301). Posts keep their URLs.

**The posts already there.** Posts says how many Roblox posts have no game yet,
with *Sort them into games*: a list of them with each one's game read from its
title — "Blox Fruits Script - [Zee Hub]", "BLOX FRUITS Script - [Xeter Hub]"
and "Blox Fruits Script Zee Hub VIP (No Key …)" are all *Blox Fruits*, "99 Nights
in the Forest - [Somtank]" is *99 Nights in the Forest* — to correct, untick or
save as they are.

**Fixed with it.** The archive sort (Customizer ▸ archive order and count) was
applied to every query on an archive page, not only its list. And every page
counted a view for `get_the_ID()` — on a list page, the first post in the list —
so the newest post gained a view with each visit to the home page; views are
now counted on single posts and pages only.

**Game screenshots and script facts (2.36).** A Roblox post without screenshots
of its own (every member's post) shows its game's, up to five, in the slider
beside the title, and its card on the game page uses one of them (a different
one per post) instead of the square icon. They come from Roblox's thumbnails API
(`multiget/thumbnails`, WebP 768×432, up to 50 games a call) and stay on
Roblox's CDN: nothing is downloaded or resized on the server. Their addresses
expire, so the hourly refresh asks again; the first run is scheduled on the first
admin visit after the update. Only `https://…rbxcdn.com/` addresses are kept.

Name / Category / OS on a Roblox post became **Game** (icon and link to its
page), **Key** (No key needed / Key system, from the title), **Playing now**
(with a Play link to Roblox; *Updated* when the game has no numbers) and
**Views**. On the Share form, *Script name* explains itself (its maker's name
for it, or your own) and shows the title the post will get as you type.

**Views counted by the browser; Roblox numbers hourly (2.37).** Views were
counted by WordPress while it built a post page, so a page from the page cache
(10Web, Cloudflare: most visits) was not counted, and a page it did build was
counted twice (`track_post_views()` on `wp_head` and again in `header.php`).
Now a small script on each post (`y4apk-pwa-views`, which 10Web's JavaScript
delay leaves alone) sends the post's ID to `views.php`, which adds one to
`wpb_post_views_count` the way `stats.php` counts app events: WordPress with
SHORTINIT, one SELECT and one UPDATE, nothing about the visitor kept. A browser
counts a post once an hour; editors are not counted. Players, likes and
screenshots from Roblox are fetched every hour instead of every day (one call
per 50 games). A cached page still shows the numbers of when it was made.

## Script Code (2.31)

Roblox script posts had their code pasted into the article as a Custom HTML
block, copied into every post: a `<style>`, a `<div class="copy-wrap">` holding
the code, and a `<script>` for its Copy button. The code now has a box of its
own under the post, **Script Code**, next to Download Links: one row per code,
a name (optional: "ENG", "ภาษาไทย") and the code, in any order (drag ☰), with
*Add Code* and *Remove*. It is kept as plain text (`_y4apk_scripts`) and shown
escaped, so nothing in it runs on the page, whoever typed it in.

On the post page the codes come first in the download section, where the
**Download** button at the top goes (`#download-now`): *COPY SCRIPT*, then each
code in a dark box with its name and a **Copy** button ("Copied!" for two
seconds; where the browser will not copy, the code is selected to copy by hand).
Long lines wrap on phones; what is copied is the code exactly as typed.

**Moving the pasted code out.** A post whose article still has `copy-wrap`
blocks says so in its box: *Move them into this box* (save first: the page
reloads). Posts says how many posts have them, with *Move the code of all N
posts* — 50 posts a round, each round its own page, so it goes on past the 20
redirects a browser follows. Moving takes out each `copy-wrap` div, the
`<style>`/`<script>` that came with it, and a Custom HTML block left empty;
anything else in that block stays. A `-- ENG` first line becomes the code's
name. Each post keeps its date (no "Updated today" on hundreds of posts), its
previous version stays in Revisions, and running it again changes nothing.

**Unlock step (2.34).** With **Y4APK ▸ Direct Link ▸ *Lock the script until the
ad is tapped*** on (and a link set), the code is blurred behind *Almost there —
1 step to unlock · 0/1*. Tapping it opens that same Direct Link in a new tab,
then shows the code — one tap unlocks every code on the post. It follows Direct
Link's *How often* (once per visit, once a day, or every tap) and, like the
download-button ad, never runs inside the installed app, where it would take
the one window over; there the code shows straight away. The lock is cosmetic
(the code is in the page for search engines and the app), so it is a monetiser,
not a wall.

## Write with AI (2.23–2.29)

**Y4APK ▸ Write with AI** writes a post's article with one of four AIs, chosen
in Settings, each with its own key (or a `wp-config.php` constant):

| AI | Cost | Endpoint | Automatic model | Key constant |
|---|---|---|---|---|
| **Gemini** (Google) | free tier, daily limit set by Google | `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` (its own API) | `gemini-flash-lite-latest`, else the newest Flash-Lite, else Flash (releases before previews) | `Y4APK_GEMINI_KEY` |
| **Groq** | free tier, daily limit set by Groq | `api.groq.com/openai/v1` (OpenAI-compatible) | `openai/gpt-oss-120b`, else `llama-3.3-70b-versatile` | `Y4APK_GROQ_KEY` |
| **ChatGPT** (OpenAI) | paid by use | `api.openai.com/v1` | the newest `gpt-*-mini` | `Y4APK_OPENAI_KEY` |
| **Claude** (Anthropic) | paid by use | `api.anthropic.com/v1/messages` | — Claude Opus 5.5 unless another is chosen (Sonnet 5.5, Haiku 5.5, or any model *Test* finds) | `Y4APK_CLAUDE_KEY` |

A fresh install starts on Gemini; 2.23's single OpenAI key and model carry
over as ChatGPT's. The question is asked the way it is asked in ChatGPT — the
default prompt is *ช่วยเขียนบทความของ{type} {name} ไม่ต้องมีหัวข้อ ไม่ยาว
เป็นภาษาอังกฤษ* ({type} is เกม or แอป from the app's Google Play genre) — and the
answer is laid out like the site's articles:

```
<strong><a href="(Google Play)">OTR 2</a> MOD APK [Unlimited Money/…]</strong> – is an open-world …
… The <a href="(the post's top category)">game</a> encourages …
<h2>OTR 2 MOD APK (Unlimited Money/…) For Android - Download MOD APK from Y4Apk Free and Premium.</h2>
```

The name is App Name (`wp_title_GP`), else the title up to "MOD"/"v1.2"/"(";
the MOD is App MOD Feature (`wp_mods`), else the download's MOD Info, else the
title's "(…)". The model is told to start with "<Name> is" and to answer in
plain paragraphs; markdown, list marks and heading lines are stripped anyway,
and everything it writes is escaped. With *Give the AI the app's Google Play
description* on, the app's Play name, developer, genre and description go with
the question (`[1][2][72][0][1]`, `[1][2][79][0][0]`), so it does not guess —
asked not to copy it. Posts with no APK (Roblox scripts) get the second prompt,
the name in bold, and no closing heading.

How each is asked:

- **OpenAI-compatible** (ChatGPT, Groq): `POST /chat/completions` with a system
  and a user message, `max_completion_tokens`, and `reasoning_effort: low` for
  reasoning models (`gpt-5*`/`o*`, Groq's gpt-oss), asked again without it if
  a model refuses it.
- **Gemini**: its own `generateContent`, the key in `x-goog-api-key` — AI
  Studio now issues "AQ." auth keys instead of "AIza" ones, and those are
  refused as a Bearer token on Google's OpenAI-compatible endpoint (2.24.0
  used it; 2.24.1 does not). The instructions go in `systemInstruction`,
  `maxOutputTokens: 8192`; Flash gets a little thinking (`thinkingLevel: low`,
  or `thinkingBudget: 512` on 2.5), Flash-Lite none, asked again without it if
  a model refuses it. Only non-thought `text` parts are the article; a
  `SAFETY` (or similar) finish or a blocked prompt is reported as declined.
  Models come from `GET /v1beta/models`, those with `generateContent`.
- **Claude**: the Messages API (`x-api-key`, `anthropic-version: 2023-06-01`),
  `max_tokens: 16000`, the instructions as `system`, `output_config.effort: low`
  (a short article needs little thinking; no `temperature` or thinking budget,
  which current models refuse), and, on Claude Opus 5.5 / Opus 5 / Fable 5.1 /
  Sonnet 5.5, `fallbacks: "default"` with `anthropic-beta:
  server-side-fallback-2026-07-01`, so a request a safety classifier declines
  is written by Anthropic's recommended other model instead. Either is dropped
  and the request sent again if the model or account refuses it. Only the
  `text` blocks are the article (thinking blocks are empty); `stop_reason:
  refusal` is reported as declined and the post leaves the queue.
- Called with `wp_remote_request()`, as WordPress code does: the theme runs on
  PHP 7.4 hosting with no Composer, so no SDK is bundled.

**One AI at its limit, the next one goes on** (2.29). Each AI waits on its
own (`y4apk_writer_wait_<ai>`): when Gemini's day is used up, Gemini is not
asked for an hour and the post is written by the next AI with a key — the
chosen one first, then the other free ones (Gemini, Groq), then, only with
*… and then the paid ones*, ChatGPT and Claude — and the chosen one is asked
again as soon as its wait is over. The result says so ("by Groq (Gemini is at
its limit)"), the post remembers which AI wrote it, the header shows who
writes now, and Settings shows the turn ("Gemini (at its limit, back in 30
minutes) → Groq"). A stand-in that refuses its key or has no credit is
skipped for the day; the chosen AI's own key trouble still pauses the queue.
The queue waits only when every AI in turn does (the soonest wait). *Run a
round now*, *Resume* and a new key saved ask them again at once. Before 2.29
the wait was the queue's, for every AI: with Gemini's day used up nothing was
written, even with a Groq key (or Groq chosen); the update turns such a wait
into Gemini's own.

**Limits.** A 429 or a 5xx (Claude's 529 *overloaded* included) is the AI's
limit or load, not the post's doing: the post keeps its place in the queue and
the rounds wait (`y4apk_writer_wait`) for `retry-after`, else Gemini's "retry
in Ns", else a minute (at least 20 s) — and for an hour when the message is a
daily limit (Gemini's `…PerDay…` quota, Groq's "requests per day (RPD)"). The
Queue tab and the header say what it waits for; *Run a round now* tries at
once. On the page, *Write selected now* waits out a short limit and stops,
saying why, at a daily one. A key the AI refuses, no credit left (OpenAI's
`insufficient_quota`, Claude's 402 / "credit balance is too low"), or a model
the account cannot use pauses the queue with the reason, until *Resume*.

**Post details** (2.25, on by default) make a post ready for its file:

- **Title** from *Settings ▸ Title* (`{name} MOD APK v{version} ({mod})` →
  "Idle Painter MOD APK v1.24.22 (Unlimited Money)") when the title has no
  "(…)" yet or is the one Write with AI wrote; one you wrote keeps its words,
  and only its version (and a MOD put in here) follows. The version is Google
  Play's latest while the post has no file (no download link, or "#"); with a
  file it stays the file's — and while there is no file, the download row
  ("v6.0.3.apk") and App Version (the page's Version box) say the same latest
  version as the title (2.26.2; they kept the old file's before).
- **One name everywhere** (2.26.2, on by default): the post title *is* Google's
  title below ("Mint MOD APK v6.0.3 (Premium Unlocked) Download"), and the
  article's bold name, its closing heading and the icon's alt text say the
  same short name — as the permalink and the focus keyword — so Rank Math
  finds "Mint MOD" in the title, at the start of the text, in a heading and in
  an image's alt text. The App Name box keeps the full Play name. Off: the
  title from *Settings ▸ Title* with the full name. The **short name** is the
  name before " - " / " – " / " | ", or before ": " when a tagline of 3+ words
  follows ("ActionDirector - Video Editing" → "ActionDirector", "Mint:
  Business & Stock News" → "Mint", but "Euro Truck Simulator: Europe" stays).
  Names are read as text: the importer stores "&" as "&amp;", which
  2.26.0–2.26.1 escaped again ("&amp;amp;" in the article).
- **Rank Math SEO title** (2.26, `rank_math_title`), what Google shows: *Settings
  ▸ Rank Math SEO title* (`{name} MOD APK v{version} ({mod}) Download`) in at
  most 60 characters — the full name, else the short one, else without
  "Download"; version and MOD always stay: "ActionDirector MOD APK v7.12.5
  (Premium Unlocked) Download" (58/60) instead of Rank Math's default "%title%
  - Y4Apk" (72/60). Written where Rank Math has its default (empty or
  "%title%…"), the post title, or one written here (`_y4apk_seo_title`); one
  you typed stays.
- **Permalink** (2.26): `/actiondirector-mod/`, the short name + "MOD", so Rank
  Math finds the focus keyword "ActionDirector MOD" in the address. One that
  is already that, or `…-mod-apk` (or either with "-2" when another post had it
  first), stays; one with the version or MOD in it changes, and WordPress's
  `_wp_old_slug` sends the old address to the new one (301).
- **MOD** (2.26): yours — App MOD Feature, else the download's MOD Info, else
  the title's "(…)" — is never changed. A post with none (or with one Write with
  AI put in, `_y4apk_mod_auto`, such as 2.25's "Unlimited Money" for every
  app) gets **the MOD that MOD APK sites list for that app** — ActionDirector
  "Premium Unlocked", not "Unlimited Money". It is asked in the same request
  as the article (a last line "MOD: …", taken off before the text is laid out),
  or on its own when only Post details are written. Gemini answers with
  **Grounding with Google Search** (`tools: [{google_search: {}}]`), Claude
  with its **web search** tool (`web_search_20260209` on Opus 5.5 / 5 / 4.6+,
  Sonnet 5.5 / 5 / 4.6, Fable; `web_search_20250305` on older ones; at most 3
  searches; only the text after the last search is the answer; a `pause_turn`
  is sent back to carry on); each is asked again without it if the model or
  account refuses it. Google Search has its own quota on Gemini, and some free
  keys have none for their model: the request with it gets an instant 429 "You
  exceeded your current quota" while the same model writes fine without it.
  So a 429 with search on is asked again at once without it (2.26.1), and
  Gemini is not asked to search for 6 hours (`y4apk_writer_nosearch_*`); the
  MOD is then what Gemini knows, and the result says so. A 429 without search
  is the model's own limit, as before. Groq and ChatGPT answer from what they know. "unknown",
  or anything that is not a short label, falls back to *Settings ▸ Not found:
  games* ("Unlimited Money") or *apps* ("Premium Unlocked"); a MOD found once
  is kept when a later search comes back empty. Where the MOD changes, it
  changes everywhere: App MOD Feature, the title, the download's MOD Info,
  Rank Math's social titles and — when no new article is written — the
  article's "MOD APK […]" and closing heading.
- **App Price** 0 when empty; **App Information** from Google Play (as App
  updates ▸ Settings allows); App Name and App Version when empty.
- **Download row**: versions written the site's way ("1.22.0" →
  "v1.22.0.apk", "368" → "v368.apk" (2.26.3; whole numbers were left as they
  were, and posts written before are put right once); "v4.5.zip" for an OBB); with no file yet, MOD Info, a size
  (the box keeps a link only with one) and a **new folder** in files/up —
  "65694-Idle-Painter-MOD.apk", a 5-digit number no other folder has, as the
  site's own are — whose default.php is *App updates ▸ Settings ▸ Download
  page for new folders* with its two "#" (Manual download, `location.href="#",0`)
  still waiting. The post is marked (`_y4apk_no_file`) and listed in App
  updates ▸ *Need a new file* as "No file yet", with Play's latest version and
  its folder filled in: Upload + Save puts the file's address in both places
  (the 3-second fallback stays) and clears the mark.
- **Rank Math focus keywords**, when there are none (or 2.25's): the one in
  the permalink first — "ActionDirector MOD, ActionDirector MOD APK,
  ActionDirector, ActionDirector - Video Editing" (`rank_math_focus_keyword`).
- **Rank Math SEO description** (2.26.2, `rank_math_description`), where it has
  none or one written here: the article's first paragraph, "Mint MOD APK
  (Premium Unlocked) is a comprehensive …", at most 160 characters.
- **Posts written by 2.26.0–2.26.1** are put right once after updating, 200 per
  admin page: "&amp;amp;" → "&amp;", the latest version in the download row and
  App Version of posts still waiting for their file, and one name everywhere
  (title = Google's title where both were written here, bold name, heading,
  icon alt text, SEO description). Nothing is asked of the AI for it.
- **A link to the post itself** (2.26, *Link the app's name in the second
  paragraph to the post's own permalink*): the AI is asked to name the app
  once more in the second paragraph, and that name links to the post's
  permalink (made after the permalink above changes; a draft gets the address
  it will have). If the name is not there, a word of the last paragraph is
  linked instead, as in the site's own posts.

**Rank Math's SEO score** is worked out by Rank Math's own script in the post
editor, so a post written here shows "SEO score 0" (it leaves *To write*
anyway, being written) until it is opened in the editor — or until *Rank Math ▸ Status & Tools
▸ Database Tools ▸ Recalculate scores* runs over all posts at once (they all
have focus keywords now). "Index status: Neutral" is Google Search Console's
"not seen yet" for a new address; it changes once Google crawls it.

**Add from Google Play** (2.28, a tab of Write with AI). A search (a name,
words, or a Google Play address) or one of Google Play's lists (popular games
or apps, or a category: Simulation, Racing, Tools, …) shows the apps the site
has no post for — every post's Google Play ID is checked, any status but
trash; those it has are listed apart, and a post with the same App Name but
no ID is pointed out ("maybe the same"). Each card shows the app's icon,
developer, genre, rating and version (Google Play's details, kept 12 hours).
*Add* (or *Add selected*, one after another) makes a draft with its name,
Google Play ID, developer and version, then Write with AI writes it with every
part — article, MOD, title, permalink, SEO title and description, focus
keywords, icon, 5 screenshots, category from Google Play, a download folder
waiting for its file (App updates ▸ Need a new file) and the size from Aptoide
— and publishes it once its article is written (or keeps it a draft, as
ticked). The AI busy: the draft goes first in the queue, which writes and
publishes it. No AI key: it gets everything but the article and stays a
draft. The Posts tab's ticks are not changed by it.

**Sizes** (2.28). Google Play no longer shows an app's size, so a download
with none gets App Size, else the size of the app's APK on Aptoide (its public
`getMeta`, `file.filesize`): "150.00MB", near enough until the file is uploaded
and its own size goes in. Write with AI does it when it writes a post, and App
updates' check does it for every post as it goes round (an app Aptoide does
not have is not asked again for a week). Sizes are never made up.

**Price** (2.28). Every MOD APK here is free to download, paid on Google Play
or not: App Price is set to 0 when a post is written ("$4.99" → 0), and the
page ("Price: 0 USD") and its data for Google (`"price": "0"`) say 0 for every
post. The data's `fileSize` now reads App Size (it read `wp_sizes`, a field no
post has).

**No "updated" push before the file** (2.28). Write with AI puts Google Play's
newer version on a post whose download folder still waits for its file; that
counted as an update and told the post's followers. The push now waits until
the file is there (the folder's mark is gone), and goes then.

**Apps gone from Google Play** (2.27). When a post's Google Play ID shows
nothing in any store, Google Play is searched for its App Name: a listing with
exactly that name *and* the same developer is the app under a new ID — the
post's ID is changed to it (Undo puts the old one back) and it is written from
there, icon and all. Otherwise the post is marked (`_y4apk_gone`) and listed
under *Not on Google Play*, with a chip and a *Search Google Play* link for its
name, to put a new ID in by hand or leave it; its article is still written,
from what the AI knows, but the name no longer links to the dead Play page. A
listing with the same name by another developer is never taken. Once the app
is back on Google Play, the mark goes.

**Category from Google Play** (off by default) sets the post's categories to
Games or Apps and the genre (`GAME_SIMULATION` → Games › Simulation, made if
missing, as the importer names them).

Undo puts back all of these too, the permalink included (the folder stays,
unused). The list opens on
**To write**: the posts Rank Math has no SEO score for — its Analytics ▸ "No
Data" — less those already written here (Rank Math scores a post when it is
opened in the editor). An answer that comes back as one long paragraph is
split in two between sentences; at most 5 screenshots are kept.

The same run can bring the app's **icon** — downloaded at 512 px as WebP
(`[1][2][95][0][3][2]` + `=s512-rw`), saved as `<Name>-MOD.webp` with the title
"<Name> MOD" and "<Name> MOD APK (<MOD>)" as alt text, caption and description,
and set as the featured image (not downloaded again while Play's icon is the
same) — and its **screenshots** (the first 5, `[1][2][78][0][*][3][2]` +
`=w1052-h592-rw`, into `ss_images`) up to date.

- **Posts**: *No article yet* (under 40 words of text, headings left out), *Not
  written by AI*, *Written by AI*, *In the queue*, *All*, by category and title.
  Tick what to update (Article / App icon / Screenshots), then *Write selected
  now* (one after another while the page is open, each row showing its result)
  or *Add selected to the queue* / *Queue all*.
- **Queue**: written in the background by WP-Cron, 1–10 posts every 5 minutes
  (3 by default ≈ 36 an hour). Google Play busy, or the post being written by
  another request: the post goes to the back and the round stops; after 5
  tries it is dropped (and logged).
- **Posts list**: *Write with AI* under each post (writes it now), and *Write
  with AI (queue)* in Bulk actions.
- **Undo** puts back the text, featured image and screenshots the last write
  replaced (kept in `_y4apk_ai_backup`, with what was written) — each only if
  it has not been changed since. WordPress also keeps a revision.
- **Settings**: the AI and its key and model, both prompts ({name} {type}
  {type_en} {mod} {category} {developer}), the closing heading, and the two
  links. *Test the connection* lists the account's models and asks for one
  word, which also shows the key works (and, for the paid ones, that there is
  credit). *Try it on one post* shows the exact question, the MOD found and
  the laid-out article without saving; *Use this text for the post* saves it.

A post is written by one request at a time (a row in `wp_options`, `INSERT
IGNORE`), so a double click or a link opened twice does not pay for it twice.

## App analytics (2.16)

**Y4APK ▸ Analytics** — for the last 7, 30 or 90 days:

| Tile / table | Counted when |
| --- | --- |
| Web app installs | the browser reports the install (`appinstalled`); "accepted the install prompt" alongside |
| APK downloads | Download or Update tapped on the banner |
| App users per day | a device opens the app that day (APK or web app), averaged over the days counted |
| New app users | a device opens the app for the first time (APK / web app split) |
| File downloads | the Download button on a `/download/N/` page — with a *Most downloaded* table per post |
| Direct Link ads opened | the Direct Link ad opens (either mode) |
| Install banner | per banner kind: seen (once per visit), button tapped, closed, tap rate |
| Inside the app | visits in the APK vs the web app, and APK versions in use with their share |

Plus a daily column chart each for new app users, app users and file downloads.

**How it is collected, cheaply.** The boot script queues events and sends them in
one `sendBeacon` per page, only when there is something to count (taps that
lead straight to another page are sent at once). They go to `stats.php` in the
theme folder, which loads WordPress with `SHORTINIT` — no plugins, no theme —
reads the timezone and runs one `INSERT … ON DUPLICATE KEY UPDATE` into
`{prefix}y4apk_stats` (a row per day × event × detail). It accepts only the events
in `inc/stats-events.php`, only from pages of the same site, at most 20 keys and
10 per key per request. Nothing personal is stored: no IP, cookie or user ID.
Rows older than 400 days are pruned daily. "Seen", "per day" and "new" are kept
honest on the device (sessionStorage / localStorage flags), not by tracking people.

**Google Analytics.** When the site has gtag (Site Kit), the same events also go
there as `y4apk_<event>` with the detail in `y4apk_detail` — countries, devices,
traffic sources. Both switches are on the Analytics page.

**No install banner on computers** (2.16): the browser's install offer is used
only on phones and tablets. Desktop Chrome still shows its own install icon in
the address bar.

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

Found by running the theme against a real WordPress install; most predate
the rebrand.

- **R2 upload cancelled, an unfinished upload left behind** (2.27): the abort
  went out while a part was still on its way, and that part could land after
  it, leaving the multipart upload taking space on R2 (S3 and R2 say an abort
  may need repeating). The page now aborts once more 4 seconds later; the
  server keeps the cancelled upload known for 10 minutes for that, and refuses
  anything else for it.
- **"&amp;" in R2 folder and file names** (2.27): "Sneak &amp; Steal" made
  "…-Sneak-amp-Steal-MOD.apk"; names are read as text now.

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
- The service worker (2.10) turned on navigation preload for the whole site,
  but leaves wp-admin, the download pages and search alone — and a navigation
  it leaves alone with preload on is requested twice: once as the preload, once
  for real. So for anyone who had opened the site in Chrome, every wp-admin page
  loaded twice and GET actions (*Check version now*, bulk actions) ran twice.
  2.23 turns preload off, also in browsers that already had it on.
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
- For App updates' uploads to R2: `upload_max_filesize` / `post_max_size` large enough for the APKs uploaded through it
