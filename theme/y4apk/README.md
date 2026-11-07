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

**Several links (2.43).** Y4APK ▸ Direct Link keeps a list of up to 20 links,
each with a name, a weight (1–10) and where it is used (download buttons, the
script unlock, or both). Each tap picks one: at random, a weight-3 link three
times as often as a weight-1 one, or in turn (the browser remembers whose turn it
is). The table shows how often each was opened in the last 30 days
(`direct_link_n`, by link id), so a slow Monetag link can be told from a good one.
A site set up before 2.43 keeps its one link, as the first row.

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
| `/@name` | The member's public page (2.39; `/u/<name>/` redirects): see *Profiles and following* below |
| `/account/following/` | The members you follow, and their newest scripts |
| `/account/customize/` | **Edit profile**: display name, bio, Roblox avatar, theme, YouTube and Discord links |

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

## Profiles and following (2.39)

A member's page is `https://y4apk.com/@name` (no slash: WordPress's added one is
redirected away; `/@name/page/2/` pages). It is a card in the member's **theme**
— Ocean, Midnight, Pumpkin, Aurora, Cosmic or Matrix, each a banner with a
moving touch (lines, a grid, fog, glow, stars, rain; still for people who ask
for less motion) — with the display name written large across it, the avatar,
*Follow* (*Edit profile* on your own page; signed out it leads to sign-up) and
*Share*, then the name, `@name`, *Joined*, *Online now* / *Active 3h ago*, the
bio, YouTube and Discord links, and Followers · Following · Scripts · Views
(followers and following open a list). Three tabs: **Scripts** (*Recent* /
*Most viewed*, a search), **Activity** (joined, shared, updated) and
**Statistics** (views, scripts, followers, views per script, the five most
viewed scripts as bars, the games). `?tab`, `?sort` and `?q` pages are noindex.

**Following** is one usermeta row per follow (`y4apk_follows` = the followed
member's ID), toggled by `admin-ajax.php` (`y4apk_follow`: a nonce, members only,
not yourself, 60 changes an hour, up to 1,000 follows); a deleted account's rows
go with it. `/account/following/` lists them, with Unfollow, and their newest
24 live scripts.

**Edit profile** (`/account/customize/`) keeps a display name (2–30 characters;
names with *Y4APK*, *admin*, *moderator*, *official* or *staff* are refused), a
bio (200), the theme, and links that must be `youtube.com/@…` / `channel/…` and
`discord.gg/…` / `discord.com/invite/…` (shown `nofollow ugc`). The **avatar** is
the member's Roblox avatar: they type their Roblox username, the theme asks
Roblox for the user (`users.roblox.com`) and the headshot
(`thumbnails.roblox.com`), and the picture stays on Roblox's CDN — nothing is
uploaded to the site. Ten lookups an hour per member; the addresses expire, so
they are renewed daily (`y4apk_avatars`, 100 users a call). Without one, the
letter tile as before. A preview beside the form follows what is typed.

*Online now* / *Active …* comes from `y4apk_seen`, written at most every five
minutes while a member is signed in. Signed-in pages now have the tabs My
scripts · Following · Edit profile · Settings · Sign out; *My public page*
opens in the same tab. In 10Web ▸ Cloudflare CDN ▸ Bypass Urls add `/@`.

**Online now (2.40).** The light on a profile and its avatar pulses green
while the member is on the site, and is grey — *Offline · Active 6m ago* —
otherwise. Every page a signed-in member has open pings `admin-ajax.php`
(`y4apk_ping`) every 90 seconds while visible, which writes `y4apk_seen` at most
once a minute; seen in the last 3 minutes is online. A profile that is open asks
`status.php` (SHORTINIT, one query; 0 for banned members) every 40 seconds while
visible, for half an hour at most, so the light goes out soon after the member
leaves and comes back when they return, without reloading.

**Pictures members upload (2.40).** Edit profile has a **banner** (drag and
drop or pick; PNG, JPG or WebP up to 5 MB; at least 600 × 200) and a **profile
picture** (also GIF; up to 4 MB; at least 96 × 96), both previewed at once. On
saving, the theme checks the file is really such a picture, refuses more than
40 megapixels, turns phone photos the right way up, cuts the middle to 3:1 or
1:1, scales to 1800 × 600 or 400 × 400 and writes a new WebP — which drops
anything else a file carries — and puts it in Cloudflare R2 at
`https://files.y4apk.net/members/<id>/…` (Y4APK ▸ App updates' R2 settings;
nothing stays on this server). Twelve changes an hour. Replacing or removing a
picture deletes the old one from R2; so do closing the account and a ban. A
picture of their own comes before the Roblox avatar; a banner replaces the
theme's. Y4APK ▸ Members ▸ *Members' pictures* shows the newest 24 with *Take
down*.

**Fixed with it:** short numbers dropped a zero from whole thousands — 20,215
views showed as *2K* and 120,000 as *12K* (game pages, cards, profiles).

## Emails (2.40)

Every email the theme sends — confirm your email, new password, your script is
live, your script needs a change, a script is waiting, a script was hidden after
reports, the test — uses one design (`inc/mail.php`): the Y4Apk wordmark, a
white card with a blue line on top, a title, the text, a card for the script, a
highlighted reason, a big button and its link written out underneath, and a
footer saying why the reader got it; with a preheader (the line inboxes show
after the subject) and a plain-text copy for mail apps without HTML. It is built
with tables and inline styles, as mail apps need. Y4APK ▸ Members ▸ Email links
to a preview of each.

**From the domain, not "via" the server.** PHP's `mail()` hands mail to the web
server, which sends it as itself (Gmail: *via srv….main-hosting.eu*, *mailed-by*
the server) and does not sign it for the domain — and such mail lands in Spam
more often. Y4APK ▸ Members ▸ *Send through your mailbox* sends over SMTP as a
mailbox of the domain (Hostinger: make `noreply@` in hPanel ▸ Emails, then
`smtp.hostinger.com`, port 465, SSL): the mail server signs it with the domain's
DKIM key and the envelope sender is the mailbox, so it shows only
`noreply@y4apk.com`, mailed-by and signed-by the domain. The password is kept
encrypted with a key from the site's salts, or in wp-config.php as
`Y4APK_SMTP_PASS`. With an SMTP plugin active (FluentSMTP, WP Mail SMTP, Post
SMTP, Easy WP SMTP) the theme leaves sending to it. Either way the theme drops
PHPMailer's `X-Mailer` header and makes Message-IDs end in the domain. A mail
server that does not answer is given 20 seconds, not PHPMailer's 300, so a
sign-up page cannot hang. *Send myself a test email* says what it went through,
and what the mail server said when it failed. An optional *Replies go to*
address is set as Reply-To.

**The logo (2.40.1).** The header is the fox icon and the Y4Apk wordmark, as PNG
files in `assets/img/mail/` (Outlook shows neither SVG nor WebP), three times the
size they are shown at so they stay sharp on phones. The wordmark has a faint
white edge: Gmail's dark mode darkens the background but not images, and the
edge keeps the black *Y4* readable there.

**Moving pictures (2.42.1).** A GIF with more than one frame — as a profile
picture or a banner — is kept as a GIF: PHP's GD reads only a GIF's first frame,
so before this it became a still WebP. It is not re-encoded but rebuilt block by
block (`Y4APK_Social::clean_gif()`): the frames, their timing and the loop stay;
comments, other extensions and anything after the GIF's end are dropped, and a
GIF that is cut short or malformed is refused. It is cut to shape on the page
(`object-fit` / `background-size: cover`). Up to 1000 × 1000 (picture) or
2000 × 1000 (banner), 500 frames, and the size limits as before. A one-frame GIF
still becomes a WebP.

**Up to 100 MB, straight to R2 (2.42.2).** On Save, Edit profile sends each new
picture from the browser straight to R2, with a bar on the picture and the
percentage on the button: `y4apk_pic_start` (members only, 20 an hour) gives a
link signed for 15 minutes to PUT one file at `incoming/<member>/<kind>-<random>`
— the same CORS rule as App updates — and the form then carries only that key.
The server fetches it (streamed to a temporary file, cut off past 100 MB),
deletes it from R2, and handles it as before: a still picture becomes a small
WebP, a moving GIF is cut to shape and size frame by frame with Imagick (320 ×
320, banners 1200 × 400; without Imagick it is kept as sent if it is at most
1000 × 1000 and 10 MB, banners 2000 × 1000 and 20 MB), at most 1,000 frames and
80 million frame-pixels. Keys never used (a page closed before Save) are deleted
daily after an hour. If the browser cannot reach R2, the pictures go through the
site as before, within PHP's `upload_max_filesize` / `post_max_size`; a form
bigger than `post_max_size` (which PHP drops whole) now says so instead of doing
nothing. Y4APK ▸ Pictures says what this server takes and whether it has
Imagick.

## A member's own pages, the header menu, sharing, the script box (2.41)

**My scripts is the member's own page.** /account/ opens with the same head as
their public page — banner (theirs, or the theme's), big picture, name, @handle,
"Online now", bio, links, followers, following, scripts, views — with what only
they have: *View public page* on the banner, *Change banner*, a camera on the
picture (both to Edit profile), *Share*, *Edit profile* and *Share a script*.
Following, Edit profile and Settings have a slim version of it. The tabs are the
public page's (with icons, Sign out at the end). Each script is a card with its
picture, status (Live, In review, Needs changes, Draft), when it changed, its
game, its views, and Open / Edit / Delete; the status buttons and a search box
above narrow the list in the page.

**The header knows who is signed in.** Next to the search: *Sign in* for
visitors; for a member their picture and name, opening a menu — their page, My
scripts, Share a script, Following, Edit profile, Account settings, Sign out.
Pages are cached and the same for everyone, so the HTML always has *Sign in*,
and a small script swaps in the member from the `y4apk_me` cookie: their login,
display name, picture address and letter colour, all of it on their public page
anyway. The cookie is set with the sign-in cookie (as long-lived), kept up to
date on each page a signed-in member opens (a new name or picture, or members
signed in before 2.41), and removed on sign-out or when a page finds nobody
signed in. Not for administrators: they have the admin bar. *Sign out* gets a
fresh token first and signs out in one click; /account/logout/ without a token
asks "Sign out?" instead of signing out from a stale link.

**Share.** The Share button on a profile and on My scripts opens the site's own
dialog instead of the browser's share sheet (on Windows a bare system box asking
for "more information"): a card of the page, the link with *Copy*, and
Facebook, X, WhatsApp, Telegram, LINE and Email, each a plain link (no
third-party script on the page). Where the device has a share sheet (phones), a
*More* button opens it.

**The script box.** COPY SCRIPT on a script post looks like a code editor: the
hub's name with the game, *No key* and when it was updated; who shared it (their
picture and name, to their page); tabs when there is more than one code (ENG,
ภาษาไทย…); a dark panel with line numbers and Lua colours (the colouring wraps
the same text in spans, so what *Copy* copies is unchanged); then three steps —
copy, open your executor, paste and execute. Locked, the code is blurred under a
card with a lock, the one step and an *Unlock script* button.

**Display names** keep capitals as typed (`Dez`, `DEZ`); the @username stays in
small letters. The Edit profile page says so.

## FAQ, the legal pages for every region, one-line sign-in panel (2.53)

2.53.2: the Customizer stayed on "Loading…": 2.53.1 fetched 1,000 whole posts
(their text and other plugins' filters) only to list their titles in the card
pickers. The list now comes from one light query for the ID and title alone,
on the posts table's own index.

2.53.1:
- **Games and Apps menus** like the Roblox one: 12 categories, those with the
  most posts first, six to a row, then *All Games →* / *All Apps →* and
  *Share a game +* / *Share an app +* (when sharing files is on; a guest
  signs in first and lands in the wizard). Phones: the same 12, with the
  *All …* button.
- **Home page cards** (Customize ▸ Y4APK Panel ▸ Home Featured and Home
  Recommend): each card has *Card name* and *Link*, both optional. Empty,
  they are the post's game or app name and its page, as before; a link may
  be any address, such as /roblox/. The post list holds the newest 1,000
  posts (was 200), and a post already on a card always stays in it.

**FAQ** (/faq/): the page the site already had is now drawn like the other
help pages (Y4APK ▸ Support ▸ Pages can switch it back). 24 answers in six
groups (downloading and installing, MODs and safety, Roblox scripts and
executors, your account and sharing, languages and the app, copyright and
reports), a *Topics* list, a search box that filters the questions as you
type, and a link for each question (/faq/#q-obb opens that one). The OBB
answer links to the site's own *Install OBB* page when it has one. Questions
added in Appearance ▸ Customize ▸ FAQ follow under *More questions* (the
theme's old sample ones and the OBB one the FAQ already answers are left
out). *Help and contact* in the header menus opens the FAQ; it has *Contact
us* beside it and at the end. The footer lists FAQ after Feedback.

**Legal pages**, what the laws outside the US ask for, kept short:
- Terms: how to report content that is illegal where you live; when we
  remove something we say which rule or law, and a decision can be appealed
  within 6 months and a person reviews it (EU Digital Services Act); some
  text and translations are written with AI help and may have mistakes.
- Privacy: who is responsible for the data (the "controller"), consent
  first for analytics and ad cookies where the law asks (EEA, UK,
  Switzerland), what automatic checks of uploads do, personalised ads under
  US state laws and how to turn them off, telling people and authorities
  about a breach, answers within 30 days with no worse treatment, parents
  can ask for a child's data to be deleted.
- DMCA: a counter-notice gives an address and phone number too (17 U.S.C.
  § 512(g)(3)); other illegal content goes through Contact.
- Contact topics: *Report content or a bug*, *Account help & appeals*.
- No country or owner's name anywhere, on purpose: governing law and a
  registered DMCA agent would have to name them.

**Sign-in and register panel**: the tag reads *Games · Apps · Scripts*, and
the lead and the three ticks are each one line.

**Fixes from 2.52.2** (in this release): the tabs between Terms, Privacy and
DMCA came out empty on the live site, because WordPress includes a template
in the global scope and a plugin's wp_head (Rank Math's setup_postdata) set
the global `$pages`; the template runs in a function of its own now. The
magnifier of the Language and Location search box is the standard one.

## Help and legal pages, Support, titles of one's own (2.52)

**Pages** (inc/help.php, template/help.php, assets/css/help.css), laid out the
way the big platforms do: a coloured card at the top, and on the legal ones
*Last updated*, the reading time, tabs between them, *The short version* in
four cards and a numbered *On this page* list.
- **Terms of Service**, **Privacy Policy**, **DMCA Policy**: written for what
  this site really does (accounts, member uploads checked and reviewed, MOD
  files from others, no IP addresses in the statistics, what profiles show,
  AI that writes pages from what a member wrote, ads and other sites). They
  name no person, address or email of the owner. Their texts are in
  inc/help-texts.php and are translated like the rest (the English version
  applies).
- **Copyright**: a notice form with what the law asks for (owner or agent,
  the work, the links on Y4APK, the three statements, a signature).
- **Contact**: topics (question, problem, copyright, privacy, account,
  advertising), *Sending as @name* when signed in, *Your requests* with our
  answers, tips, links.
- **Feedback**: five faces, a few words, the page it came from.
- They are ordinary WordPress pages, made once when the dashboard is opened.
  A page the site already has at a usual address (/privacy-policy/,
  /dmca-policy/, /feedback/, /contact-us/ …) is used as it is (its address
  and Rank Math settings stay); WordPress's *Privacy Policy page* setting
  points to ours. *Y4APK ▸ Support ▸ Pages* can show a page's own text again.
- The footer lists them (and the menu's other items, such as FAQ), the globe
  menu has *Help and contact*, *Send feedback* and the policies, the account
  menu *Help and contact* and *Send feedback*.
- The forms work on cached pages (AJAX), with the bot trap and Turnstile of
  the sign-in forms (Turnstile only for people not signed in), five messages
  an hour (ten for feedback) — only a message that is sent counts.

**Y4APK ▸ Support**: every message, with *To answer*, *Copyright*,
*Feedback* (the average of the last 30 days), *Closed*. Open one to answer by
email (in the sender's language around your words; they get a number when
they write), close it, or delete it. A copyright notice lists its links with
the post each one is (a page, its download page, a member's version, a file
folder on R2): *Hide the ticked posts* makes them drafts, *Put them back* is
for a counter-notice. The owner gets one email at most every 10 minutes; the
address is never on a page.

**Sign in / Create an account**: the panel speaks of games, apps and
scripts, its tag no longer stretches across the card, and making an account
agrees to the Terms of Service and the Privacy Policy (linked).

**A title of one's own** on the game and app share form (step 3, optional):
empty, the post gets the site's usual title, shown in grey in the box as it is
typed (“Name MOD APK v1.2 (Unlimited Money)”). A title must have the game's or
app's name in it (it is added at the start when not), and no links. Write
with AI keeps it; editing the upload shows it again.

Fixed on the way: the share form's search found a game already on the site
only when the post was in the top Games or Apps category, not in one under it
(Action, Racing …), so members shared it again instead of adding a version.

2.52.1, from the live site:
- The tabs between Terms, Privacy and DMCA showed as empty pills there: they
  are a segmented bar now (a white bar, the open one dark), each tab an icon
  tile and its words, with sizes and colours set firmly so a cached page's
  optimiser or another plugin's rules cannot empty them. On phones the three
  sit side by side, the icon over the words.
- The ticks of *The short version* were dark on green (a rule for the cards'
  words reached them): white on a green gradient now.
- The search box of the Language and Location lists: the icon and the words
  in one row that cannot be squeezed (the list's column had made it short).
- The share form's *Post title* hint went up over its box; a hint right under
  its box never does now.

## Languages and location, the way YouTube and TikTok do it (2.51)

**How the big platforms do it**, and what this theme does the same way:
- They translate the **interface**, not what people post: YouTube's menus are
  in Thai while a video keeps its own title. Here the theme's words are
  translated (menus, buttons, messages, the account and upload pages, emails
  sent from a page in that language, categories, menu items, the home page's
  heading); games, apps, scripts and articles stay as they were written.
- **One catalog of texts, a dictionary per language.** Every text in the code
  is a gettext key (`__('Sign in', 'y4apk')`). Facebook and X have people
  translate their catalog; here *Write with AI* does it in the background, and
  you can change any line.
- **A language has its own address**: `/th/`, `/id/`, `/vi/` … like Google's
  and Apple's pages. A page cache (10Web, Cloudflare) keeps a copy per address,
  so every visitor gets their language straight from the cache. The `/th/` is
  stripped before WordPress reads the request (`/th/some-game/` is the same
  post as `/some-game/`) and put back afterwards, so caches keep each language
  apart; every link on a `/th/` page leads to `/th/`.
- **The choice is remembered** (cookie `y4_lang`, like YouTube's PREF): a page
  opened without `/th/` (from Google, an old link) takes such a visitor to it
  before it shows. Someone new whose phone is set to a language the site has
  is asked once, in that language ("ดูเว็บไซต์นี้เป็นภาษาไทยไหม?").
- **Language and location are two settings**, as on YouTube (`hl` and `gl`).
  The location (cookie `y4_gl`) starts as the country Cloudflare sees (or the
  one the browser's language names) and can be changed; dates ("Updated: …")
  are written the way they are there, in the visitor's time zone.
- **Search engines keep one address per page**: a `/th/` page says
  `noindex, follow` and points (canonical, also Rank Math's) to the original,
  because its posts are the same. Sitemaps are unchanged.

**On the site**: a globe button beside the account button on computers, and a
*Language / Location* row in the phone menu and in the account menu. Both open
the same menu: *Language: ไทย ›*, *Location: Thailand ›*, each a list with a
search box and a tick on the current one (a sheet from the bottom on phones).
Countries are named by the browser in the page's language (Intl.DisplayNames).
The globe appears once a language other than English is ready.

**Y4APK ▸ Languages** (inc/i18n.php):
- 41 languages to pick from; 15 are on at first (Thai, Indonesian, Vietnamese,
  Portuguese (Brazil), Spanish (Latin America), Russian, Hindi, Turkish,
  Filipino, Malay, Japanese, Korean, Chinese, French, German). Right-to-left
  ones (Arabic, Urdu, Persian, Hebrew) can be turned on; the layout stays left
  to right.
- The texts are found by reading the theme's files (about 1,100 texts, 7,000
  words, the dashboard's own left out), plus anything a `/xx/` page shows that
  the scan missed, plus WordPress's month names and "3 days".
- *Write with AI* (its key and its turn of AIs) translates 50 texts a
  question, the texts every page shows first, one language after another:
  placeholders (`%s`, `%1$s`), HTML tags and `{words}` must come back as they
  were, or the line is asked again (twice, then it waits for you). In the
  background: every 5 minutes, at most 100 questions a day, so a free Gemini
  key keeps most of its day for articles. *Translate now* goes on while the
  page is open (about 25 questions per language).
- A language is shown to visitors once 90% is done, and stays down to 75% (new
  texts after a theme update do not take it away; they are translated next).
- *Edit texts*: every line, with a search and filters (not translated yet, by
  you, wrong). A line you write is never replaced by the AI; empty one to have
  it translated again.

Under the hood:
- Translations are options (`y4apk_i18n_dict_th` …, not autoloaded); a page
  in a language reads its own once. `y4apk_i18n_live` (autoloaded, tiny) says
  which languages are ready.
- AJAX from a `/th/` page answers in Thai (the page that asked, by its
  Referer on this site). Search suggestions, the versions list (`versions.php
  ?l=th`) and the PWA keep working: the app has one scope and one service
  worker whatever the language.
- What is saved stays in English: a member's post title ("… by name"), a
  script's default description, the guid; `Y4APK_I18n::untranslated()`.
- Fixed on the way: hard-coded English (rating stars, page arrows, the home
  page's SEO text, the footer line, "Today", "Yesterday") is translatable; the
  download page said "Andrid".

After installing: **Y4APK ▸ Languages ▸ Translate now** (or wait for the
background), then clear the page cache. Check a language at `/th/`.

2.51.1, from the first days on the live site:
- Rank Math said "Seems like your site URL has changed since you connected to
  Rank Math": it compares the site's address with the one it was connected
  with on every page, and on a `/th/` page it saw `…/th`. Its check is now
  skipped on language pages (filter `rank_math/registration/do_url_check`);
  the warning it wrote is removed once (it comes back by itself only if the
  address really changed). Nothing had to be reconnected.
- *Translate now* no longer stops at the AI's limit for the minute: it says
  so and goes on by itself when the AI lets it (the background did already).
  *Visitors see it* says why a language waits (the AI's own words) and when it
  goes on; other languages read *Translating · shown at 90%* or *Waiting its
  turn*.
- An answer that is not the list (cut off, a few broken lines, a refusal) no
  longer holds a language up: the lines that are whole are kept, the rest is
  asked again in smaller pieces (50 → 25 → 12 → 6 → 5), and only a piece of 5
  that still goes wrong counts against its texts. Three wrong answers in a row
  stop the AI for 15 minutes, so a free key's day is not spent on it.
- "100% safe" was read as a placeholder (`% s`), so its translations were
  refused: only real ones count now (`%s`, `%d`, `%1$s`, `%.1f`, `%%`). The
  texts the AI got wrong are asked again once.

2.51.2: **Keep in English.** The site's own names stay as they are in every
language: its categories (the menu's *Games* and *Apps*, so *Popular Games*,
*All Games*), menu items, the home page's heading and button, and the home
page's badges *Best of the best* and *Recommend*. Any other text can be kept
in English the same way (Y4APK ▸ Languages ▸ Edit texts ▸ *Keep in English
(every language)*, filter *Kept in English*), and these can be let go to be
translated. Kept texts are not sent to the AI and do not count in the %.
The menu's two *All …* buttons are the same text now (one was not
translatable).

2.51.3: the first box on a game or app page is labelled with what it is,
in the menu's words: **Games** on a game, **Apps** on an app (the top category
the post is in, so the same in every language), instead of *Name*. A post in
neither keeps *Name*; Roblox scripts have their own boxes.

## App updates follow Google Play; members' safe uploads go live at once (2.50)

**App updates** (Y4APK ▸ App updates):
- Roblox scripts are left out. They are not apps, but earlier versions looked
  them up on Google Play like any post ("Steal An Egg Script" → the Play game
  Steal An Egg) and listed them in Suggestions. What was found for them, and a
  Google Play ID the page set by itself, is cleared once. A script is a post
  with a Roblox game, script codes, or "script" in its title in a Roblox
  category; members' versions (child posts) are never looked up either.
- A newer version goes into the post by itself (Settings ▸ Follow Google Play
  by itself, each part can be switched off):
  - **Version**: App Version, the version in the title and in Rank Math's
    title, description and social titles, and the post is marked updated. Its
    download keeps the version of its file, so the post stays in *Need a new
    file* until the file is uploaded. A version newer than Google Play's is
    never lowered; only Google Play's own number counts (not Aptoide's). Posts
    already known to be behind catch up 40 a step, without asking Google.
  - **Icon** (featured image): kept on Cloudflare R2 (`files…/i/…`, 512 and
    192 px), not in wp-content/uploads. WordPress, the theme, Rank Math and
    the search box see a normal image (inc/r2-images.php). A picture of your
    own that is not square is never replaced; members' versions showing the
    old icon follow. The old icon is deleted: from R2 always, from this server
    when *delete the old one* is on and nothing else uses it (another post, a
    Roblox game, the site logo, an article). So icons already on the server
    move to R2 too, 8 a step. Write with AI makes its icons on R2 as well.
  - **Screenshots**: shown from Google Play's own servers (no space anywhere).
    Screenshots you uploaded yourself are kept.
- **Update any file**: a tab that finds any game or app post and shows its
  new-file form, any time, also with a version newer than Google Play's. The
  editor has a *File and Google Play* box (your file, Google Play's version,
  *Update the file*, *Check Google Play now*), and the Posts list an *Update
  file* link. Saving keeps a newer version the post already shows.
- Background steps change posts with WordPress's HTML filter off: a cron step
  has no user, and the filter would take the article's iframes out.

2.50.1: a game or app on Google Play with **no download at all** is listed in
*Need a new file* ("No file yet"), and its form takes a first file: pick the
type (MOD, APK, XAPK, APKS, OBB), upload, Save — the download folder
(`files/up/NNNNN-Name-MOD.apk`, its page pointing at the file on R2) and the
download row (with the MOD text from the post) are made by themselves. A
pasted link works too. Nothing is made before there is a file, so visitors
never get a download that leads nowhere.

2.50.2: the same for a download row whose link is empty (or "#"): it shows "No
file yet", and an upload is enough — the link box is never required once a
file is uploaded (Replace keeps the post's folder; Add, or a row with no
folder, gets a new one). Saving with neither a file nor a link says so on the
page. Posts with such rows are listed in *Need a new file* once.

2.50.3: the file's **type** is a dropdown on every row (MOD, APK, XAPK, APKS,
OBB, and the row's own type when it is another): Save puts it on the
download, and a new folder is named with it. The version's ending follows
("v7.4.1.apk" → "v7.4.1.xapk" for XAPK, ".zip" for OBB), and uploading an
.xapk, .apks or .obb file picks its type by itself.

2.50.4: the new-file form edits **every download** of the post:
- **+ Add a file** adds rows (up to 10 a save), each with its type, version,
  size and upload; each upload gets its own download folder, and the rows go
  just under the new file (an APK with its OBB, an XAPK…).
- **Other downloads on the post** (older versions, other files — "Left as they
  are" before) are rows too: change them, upload a file again (it keeps its
  folder), or take them off with the bin (click again to keep). Their files
  stay on R2.
- Changing only those leaves the new file, the version and the title alone;
  the new file is saved when it was touched (or when nothing else was).
- **Links to other sites** (anything but y4apk.com / y4apk.net and the
  download and R2 addresses in Settings): shown in red, and Save takes them
  off — an upload takes the new file's place with your own folder — and the
  post waits for your file (in *Need a new file*, also when it is not on
  Google Play). On *Need a new file*, a card counts the posts with such links
  (by site) and takes them all off at once; what was taken off is kept and
  **Put them back** returns it.

2.50.5:
- **Which posts wait for a file**: the card on *Need a new file* says how many
  posts lost their links to other sites and have no file of Y4APK's own yet,
  and **Show them** lists just those (in the usual order, with their forms).
  A post leaves the list — and the count — as soon as it is saved with your
  file; posts that already had one when the update is installed leave it at
  once. The links kept aside (Put them back) are a small line under it.
- **The file's name follows the version you save**: an upload starts as soon
  as the file is picked, named with the version the box had then ("…-v1.7.7-
  …"); on Save, R2 renames it ("…-v1.7.3-…", a copy R2 makes itself, in 1 GB
  parts above 5 GB) and the download page points at the new name. A file
  uploaded earlier under the wrong version is renamed the same way when its
  row is saved again with the right version. If R2 refuses, the post is still
  saved and a notice says the file keeps its first name.
- After an upload, the version the file itself says (from inside the APK,
  XAPK…) goes into the version box, and a file of another app than the post's
  is pointed out.
- Requests to R2 can sign their x-amz-* headers (the copy source); checked
  against botocore's signer.

2.50.6:
- **Share window** (inc/share-dialog.php), as the profile page has it: a card
  with the page's picture and name (a Roblox game's banner behind it), its
  link to copy, and Facebook, X, WhatsApp, Telegram, LINE, Email, plus the
  device's own share sheet ("More") where there is one. On the Roblox game
  pages (before: the device's sheet straight away) and on game, app and
  script posts (before: a small menu whose links did not encode the title).
- **Executors on every script**: Y4APK ▸ Executors has, above the list, the
  executors every script with none of its own shows ("Get Executors — Run it
  with one of these"), so older scripts need no editing one by one. A
  script's own (the editor's "Tested with executors" box) still comes first
  and says "tested with".

2.50.7: scripts showing the site's executors (your older ones) got the
block without its styles — the stylesheet came only with a script's own
list. Both now go through one rule (Y4APK_Executors::shown_on), so the chips
and the download window look the same on every script.

**Reports** (Y4APK ▸ Review): a published post is listed only once 3 different
people reported it since you last looked (Members ▸ Limits). Dismiss or Hide
and review again remembers who reported it: they cannot report it again, and
it comes back only with reports from other people. Approving a post that
reports hid clears its reports too.

**Publish at once** (Members ▸ Publish at once, both on):
- Scripts go live at once when: the game is already on the site, every
  address the code loads from is on the trusted list, the code has nothing
  that calls the web itself, writes files, copies to the clipboard, buys,
  loads Roblox assets by number or hides itself (escapes, `string.char`,
  long encoded blobs, `getfenv`), and the description has no links.
- Games and apps go live at once when every file is really the app chosen:
  read on R2 with a few small range requests (inc/apk-check.php) — the APK's
  binary AndroidManifest.xml, XAPK manifest.json, APKM info.json, APKS
  meta.sai or its base.apk, the OBB folders in a ZIP, an OBB's name — and its
  package name is the Google Play app's or the main post's. An APK with no app
  inside is refused at upload. A new game the site already has, or one not on
  Google Play, waits for you.
- Only for members with at least 1 upload you approved yourself (set 0 for
  everyone). Review lists what went live at once this week, with *Hide and
  review again*; visitors can report a member's file (*Report this file*)
  as they report scripts. The member sees "Checked and live!".

## Game and app wizards look like the script one (2.49)

Step 1 of */upload/game/* and */upload/app/* now has what the Roblox script
wizard has:
- two cards: **On Google Play** (find it by name or its Google Play link) and
  **Not on Google Play** (add its name and icon by hand; this replaces the
  "Add it by hand" link);
- the search box;
- **Popular games (or apps) on Y4APK**: the eight most read main posts, with
  their icons, four across (two on a phone). Picking one means "my own version
  of this", so the upload goes on that post's page with the member's name.

The picked card shows the game's name, not the whole post title.

2.49.1: under *Its name* (Not on Google Play), the line about the icon no
longer runs into the box above it.

## Live status dots (2.48)

Every green (working, live, online) and red (outdated, needs changes) dot now
pulses: it keeps sending out a ring of its own colour, as the post's
"Updated …" dot does. That covers:
- executors: the pills on the executors page and on each executor's page, the
  ribbon on each card, the Working / Outdated filters, *At a glance*, the
  *Get Executors* chips and their panel on a script's page, the executors in
  the script form, and the Status column in the admin list;
- My uploads: LIVE and *Needs changes*, and "v… is out";
- admin: the Dashboard's dots (good, bad, online now) and *Online* on the
  Members page.

Amber and grey dots stay still. Red pulses a little faster than green. With
"reduce motion" set on the device, the dots stay still.

## Versions from members: short links, a paged grid, new-version emails (2.47)

**Short links.** A member's version has a short, random address:
`y4apk.com/v/k7x2pq9m4z/` (a letter, then nine letters or digits), like a
YouTube link. The random name says nothing about how many there are or who
sent them. Versions from 2.46 get theirs on the first admin page after the
update. Their old long address, and `/k7x2pq9m4z/` without `/v/`, lead to it
(301). Download pages are `/v/k7x2pq9m4z/download/0/`.

**What the member did not fill in comes from the main post** while the version
has none of its own:
- Age (content rating), the stars and votes, Android ("8.0+") and OS;
- the developer, the price, the screenshots and the icon.

The version's own number, MOD, size, files, title and article are never
taken. A vote on a version starts from the main post's stars and is kept on the
version.

**The article.** When the member wrote fewer than 60 words (or nothing),
Review's *Approve* has *Write the article with AI* ticked. After it goes live,
Write with AI writes its article as the site's posts have it: the same prompt,
the bold name with its MOD, the heading and the SEO description. It is written
about this version (its number and MOD) from the member's words and the main
post's Google Play facts. An Original file gets no "MOD APK". A version never
gets new icons, screenshots or details from Write with AI (they are the main
post's).

**The list** is a grid, four across (three on a tablet, two on a phone), 12 a
page:
- *Newest* or *Most viewed*, "13–24 of 10,000", and page numbers ("‹ 1 … 4 5 6
  … 834 ›");
- each card shows the member's picture and name, the date, the version, MOD or
  Original with what is modded, the file type, size and views.

The first page is in the post's HTML. The other pages, and the other sort, come
from `versions.php` (WordPress with SHORTINIT: three small indexed queries) as
the visitor clicks, without leaving the post. The page may come from a cache,
so page 1 is asked for again when the list comes into view. Versions are their
main post's children (`post_parent`, which has an index), so a game with
100,000 versions pages as fast as one with ten. A link like `?vp=3&vs=views`
works without the script too. On a version's page, the main post comes first
as a wide card. Only members are named: a version put there by the site's own
staff shows "Y4APK".

**New versions.** When App updates finds a newer Google Play version for a main
post, or the post's own version goes up, each member who shared a version of it
is told, in the background (500 versions a round):
- once per new version, by email ("New version of Chicken Gun: v6.0.35" with
  *Share v6.0.35*, which opens the wizard with the game picked);
- on My uploads: "v6.0.35 is out. Yours is v6.0.32: share the new one.", and
  *Add a version* becomes *Share v6.0.35*.

It is about the member's newest version of that game. A member's own new game
counts too. Settings has *Email me when a game or app I shared has a new
version* (on unless they turn it off); My uploads shows it either way.

**Fix: the Roblox script wizard showed all four steps at once** (since 2.44).
An attribute was written as `data-wizdata-start` instead of `data-wiz
data-start`, so its script never started. It goes step by step again, with
its game search and checks.

## Versions from members (2.46)

A member who shares a game or app the site already has no longer changes the
site's post. Their upload is **their own post**, linked to the site's post
(the *main post*). It is listed on the main post's page, the way a Roblox game
lists its scripts.

**On the main post's page**, under the downloads, *Versions from members*:
- each version as a card: the member's picture and name, the version, MOD or
  Original with what is modded, the date, views, the file type and size;
- newest first, ten shown and *Show N more* for the rest (up to 100);
- *Share your version*, which opens the game (or app) wizard with this post
  already picked. With no versions yet, the box only invites members to share.

**A member's version** is a post of its own:
- its title ends with "by <name>", and *Shared by* shows who it is;
- the breadcrumb is Home → … → *Chicken Gun* → *v6.0.32 by Dez*;
- *More versions of Chicken Gun* lists the main post first (*Main post*), then
  the other members' versions;
- it has its own download folders, pointing at the member's file on R2;
- it takes the main post's icon, category, developer and Android version, and
  never Google Play's ID, so App updates and Write with AI leave it alone;
- it has no *Notify me when updated* (people follow the main post).

The site's lists show main posts only: home, categories, tags, search, feeds,
*Similar*, and the search box's suggestions. A member's profile and My uploads
still list their own versions. A version of a version goes under the same main
post. If a main post is deleted for good, its versions become posts on their own.

**Review.** A version says "A member's own version of <post>: approving
publishes it as their post, listed on that page. Your post and its file stay as
they are." The button is *Approve: publish under <name>*. There is no category
choice and no Write with AI: it goes live as the member wrote it, and the
member's email says which page lists it.

**What 2.44–2.45 did, undone.** Then, approving a newer version pointed the main
post's download at the member's file and put the member's version into its
title and size. On the first admin page after the update:
- each version approved that way comes back to Review, as the member's own post
  under that main post;
- versions still waiting become versions under their post.

Review also shows **Put your post's own file back**. For each main post whose
download still gives a member's file, it lists the post's other files on R2
(the same name, newest first, APK and OBB apart). *Use this file* points the
download page back at it, with the row's size and version. The version is read
from the file's name, and the title and Rank Math titles get it back. The
"Updated" date does not move. You can also paste a file's address and version,
or press *Keep it as it is*. If you cannot tell which file was there, Hostinger's
backups have each download page (`files/up/<folder>/default.php`), and the box
names the folders.

## Members' accounts, Analytics, Get Executors, fixes (2.45)

**Y4APK ▸ Members ▸ Accounts** lists every member account, 50 a page. Tiles at
the top count all members, email confirmed (with a bar), not confirmed, online
now and banned; each tile is a filter. Each row shows:
- the member's picture, name and @name;
- their email, marked *Confirmed* or *Not confirmed*, with "reminded … ago";
- when they joined and when they were last seen (or *Online*);
- where they came from: country, system, browser and source;
- how many posts they have live.

**⋯** opens everything about the account: public and sign-in name, email,
joined, followers and following, country, device, how they arrived, language
and bio. You can search by name or email, and sort by newest, oldest, last
seen or name.

Actions, for one account or for the ticked ones:
- *Send confirmation email* (a new link, valid 48 hours);
- *Mark as confirmed*;
- *Ban* or *Allow again*.

*Remind N members to confirm* sends the email to up to 50 who have not
confirmed and were not reminded in the last day; press it again for the next
50. If the mailbox cannot send, the page says so. The settings, emails and
privacy checks that were on this page are the second tab. The Dashboard's
"Email not confirmed" links to the list of those accounts.

**Y4APK ▸ Analytics** is redesigned in the Dashboard's style, the whole width:
- seven tiles with their trend and the change against the period before (web
  app installs, APK downloads, app users per day, new app users, file
  downloads, ad opens, executor downloads);
- downloads per day and the most downloaded posts;
- app users and new app users per day;
- the install banner, with each kind's tap rate as a bar;
- visits inside the app (APK or web app) and the APK versions in use;
- Direct Link ads per day and per link;
- executor downloads by executor;
- every event against the period before;
- the settings, at the bottom.

**Get Executors.** The "Tested with" chips on a script's page are now *Get
Executors*. Tapping one opens a panel on the same page (a sheet from the
bottom on phones) with:
- the executor's status, price, key and sUNC;
- a warning when it is outdated;
- a button for each download, and three steps to install it;
- a link to its page.

A file uploaded to R2 downloads straight from R2, so the visitor stays on the
script. A download that is only a link to the maker's site opens in a new tab.
The executor's own page now downloads straight from R2 too.

**Fixes.**
- Sending a game or app failed with "Could not confirm you are human": the
  Cloudflare Turnstile check was never loaded on /upload/game/ and
  /upload/app/. It is loaded now. At the last step a token older than about
  3 minutes is renewed (they last 5, and a big upload takes longer). Send
  waits for the check to answer, and the "safe to share" tick is kept when the
  form comes back.
- The game wizard no longer asks "a game or an app" again: the switch above
  it decides. The share pages have their own titles.
- The member menu has one **Upload now** instead of "Share a script" and
  "Share a game or app"; it opens /upload/ with the Script / Game / App switch.
  The profile head and the empty My uploads have the same single button.

## Share a game or app, search suggestions, executors (2.44)

**Share a game or app.** Members share a game's or app's file at
`/upload/game/` and `/upload/app/` (a Script / Game / App switch sits on top of
both wizards; "Share a game or app" is in the header's member menu and on My
uploads). Five steps:

1. *Find it*: a Google Play link, package or name finds it on Google Play
   (name, icon, developer, rating, version) and on the site. If the site has it
   already, the member sends a newer version of that post. If it is not on
   Google Play, it is added by hand with an icon.
2. *Category*: the Games or Apps categories of the header's menu, picked from
   Google Play's genre when they match.
3. *Details*: version, what is modded (chips, or "Original"), Android version, a
   few words.
4. *The file*: APK, XAPK, APKS, APKM, ZIP or OBB, up to 10 GB, plus an optional
   OBB or data file. Files go from the browser straight to R2 (S3 multipart:
   16 MB+ parts, four at a time, through links the server signs), so they never
   pass through Hostinger. A failed part is tried again. Pause and Resume work,
   and a stopped upload carries on after the page was closed: the browser
   remembers the parts that arrived, and choosing the same file again resumes.
   The server checks the size, and that an APK, XAPK, APKS, APKM or ZIP really
   is a ZIP inside (a renamed .exe is refused and deleted).
5. *Review and send*: a preview of the post. The member ticks that the file is
   safe and fine to share.

It waits, hidden, in Y4APK ▸ Review, with *Download to check* for each file.
**Approve** makes the post like the site's own: a download folder in
`files/up` pointing at each file on R2 (as App updates makes them), the
download rows, App Information and the category. Then it either goes live at
once (Write with AI then fetches the icon and screenshots in the background),
or Write with AI writes the article first and publishes it when done. A newer
version goes into the existing post instead: its download folder now points at
the new file, and the version follows in the title and Rank Math's titles.
**Send back** emails the member what to change; they edit it from My uploads
and send it again. The member is emailed when it is live, and the post shows
"Shared by @name".

My uploads (the member's dashboard) lists scripts, games and apps together, with
their state (In review, Approved, going live, Live, Needs changes), Edit,
*Newer version* for a live one, and Delete. Files uploaded but never used, and
uploads never finished, are removed from R2 after two days. A waiting upload
that is deleted, or belongs to a banned member, takes its files with it.
Settings (Y4APK ▸ Members): sharing on/off, biggest file (10 GB), per member
per day (3), waiting at most (5), and whether Write with AI is the default.

**Search suggestions.** As visitors type in the search box, matching games,
apps, scripts, Roblox games, executors and articles show under it, with icons,
kind, category and version, and the typed words highlighted. Arrow keys and
Enter work, and "See all results" leads to the usual search page. Before they
type, the box shows their recent searches (kept only in their browser, each
removable) and the six most read posts. It asks `suggest.php` in the theme's
folder: WordPress with SHORTINIT (database only, like `views.php`), a LIKE on
published titles, answered with `Cache-Control` so Cloudflare keeps each answer
for a while. Addresses, categories and the popular list are prepared hourly
(and when posts or categories change) in the `y4apk_suggest_cfg` and
`y4apk_suggest_top` options. Searches are not stored on the server.

**Executors.** Y4APK ▸ Executors keeps the Roblox executors: name, maker,
logo (the featured image), banner, the systems it runs on (Windows, Mac,
Android, iOS), status (Working, updated for current Roblox / Outdated / Status
unknown), price, key system or keyless, sUNC %, version, and a page of text.
Each download row is a file uploaded from the browser straight to R2 (APK, ZIP,
EXE, MSI, DMG, IPA, 7Z, RAR; it gets a download folder in `files/up` on Save)
or a link to the maker's site. `/executors/` lists them as cards, working ones
first, with filters for system and status and a search. Each one has its page
at `/executors/<name>/`, with the download buttons (the Direct Link ad opens on
the tap, as on game pages), how to use it, and the scripts tested with it.
Downloads are counted with the site's analytics (`executor_dl`). When members
share a script, they tick the executors it works with. Admins can set them in
the post editor's "Tested with executors" box. The script's page shows them as
*Get Executors* chips with a status dot (see 2.45), and step 2, "Open your executor",
leads to `/executors/`. The Roblox menu and the Roblox page link there too.
Both pages carry a note that executors are other people's apps, that Roblox
does not allow them, and that they are used at your own risk.

## The Dashboard, where members come from, Pictures, phones (2.43)

**The Dashboard** (Y4APK ▸ Dashboard) is the whole width of the screen and in
parts you can jump to: Overview, Members, What people read, Roblox, App & ads,
Health. The period is 7, 30 or 90 days, against the period before; *Count again*
recounts (the numbers are otherwise kept for 5 minutes). At the top: what is
waiting for you (scripts to review, reported scripts, new pictures), then the
main numbers with their line for the period.

The Members part answers *how many, from where, on what*:

- **New members** per day, and **right now**: members in all over 12 weeks,
  online, seen today, in 7 and 30 days, email not confirmed, banned.
- **The members' journey**: signed up → confirmed email → shared a script → has
  a live script → has followers, with how many get from each step to the next
  and which step loses the most.
- **Where members come from**, as a tree: Google, YouTube, TikTok, Facebook &
  Instagram, Discord, Telegram, X, other search engines, other websites, or
  typed in / bookmark / app; then the websites by name and the first page of
  the visit they signed up in.
- **Countries** (Cloudflare's country header; without it, the browser
  language's region, marked as a guess), **devices** (phone, tablet, computer),
  **system** and **browser** (in-app browsers such as Facebook or TikTok
  counted as themselves), and whether they used the Y4APK app.
- **When members join**: a day × hour map in the site's time zone.
- **Who follows whom**: a network you can drag; a bigger dot has more followers.
- **Members who matter most** (views of their live scripts) and the newest.

*How it is recorded.* At sign-up the account gets a few `y4apk_o_*` fields:
country, device, system, browser, app, where the visit came from (the sending
site's host only, never the address) and the first page of the visit. A small
script keeps the last two in the browser for the visit (`sessionStorage`), and
the sign-up form sends them, so pages stay the same for everyone and the page
cache is fine. No IP address and no full User-Agent are kept. Each field is its
own row, counted with `GROUP BY`, so it stays quick with millions of members. A
member who joined before 2.43 gets the device fields at their next sign-in;
where they came from shows as *Not recorded*.

**Pictures** (Y4APK ▸ Pictures) replace the grid of the 24 newest on the Members
page, which could not keep up with many members. Every picture or banner a
member uploads is put on a *To look at* list (`y4apk_pic_new` /
`y4apk_banner_new`); the page shows them 60 at a time, newest first. Tick the ones
that do not belong and take them down (deleted from R2 too), mark the rest as
fine, or mark the whole page as fine at once, so the list only ever holds what came
since your last look. *All pictures* shows every one, with a filter (pictures,
banners, moving GIFs) and search by @name. The menu and the Dashboard show how
many are waiting. The pictures there before 2.43 go on the list once.

**Phones.** A word, link or title longer than the screen used to push the page
wider, and the phone (and the Android app) zoomed out to fit it. Long words now
break inside their box; tables in posts scroll sideways in their own box; code
wraps; pictures and players stay inside the page. Boxes you type in are 16px on
phones, so iPhones no longer zoom in when you tap them. Every page type was
checked at 320, 360, 375, 390 and 414 px wide, with the menu, search, share and
report boxes open. The Dashboard, Review and Pictures pages fit a phone too.
Game tiles on the Roblox page are bigger on phones, with thinner gaps.

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

**Roblox numbers every 10 minutes, fresh on cached pages too (2.42).** Players
and likes are fetched every 10 minutes (`y4apk_10min`: two calls to Roblox per
50 games, about 290 a day; the screenshots' addresses stay hourly, on their own
schedule, `y4apk_roblox_shots`). Pages stay cached longer than that, so the
numbers that change — players and likes on a game page, players on a script
post and on game tiles — carry `data-rbx-live`, and a small script
(`y4apk-pwa-rbx`) asks `rbx.php` for the stored ones: GET `?g=<term IDs>`,
WordPress with SHORTINIT and one query, answered with
`Cache-Control: public, max-age=60` since nothing in it is private. WP-Cron only
runs when WordPress does, so on a quiet site the 10 minutes can stretch; a
server cron (hPanel ▸ Advanced ▸ Cron Jobs: `wget -q -O - https://<site>/wp-cron.php?doing_wp_cron`
every 5 minutes) keeps it on time.

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
