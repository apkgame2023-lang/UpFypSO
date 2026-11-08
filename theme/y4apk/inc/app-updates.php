<?php
/**
 * Y4APK ▸ App updates: which posts have a newer version out than the file they
 * offer.
 *
 * In the background (WP-Cron, every 15 minutes, a few posts at a time):
 *
 *  1. Link posts to their Google Play app (meta wp_GP_ID). Most posts are
 *     written by hand, so: a Google Play link in the post's content is taken as
 *     is; otherwise Google Play is searched for the post's App Name, and the
 *     post is linked only when a result's name — and publisher, when the post
 *     has one — match exactly. Anything less certain becomes a suggestion to
 *     confirm on the page. The ID can also be typed into App Information.
 *  2. Check linked posts' latest version on Google Play itself (the number on
 *     the app's page), falling back to Aptoide's public data when Play does not
 *     show one.
 *
 * Posts whose download is older than the latest version are listed, most
 * downloaded first, each with a form for the new file; saving it updates the
 * download, the version in the title and in the Rank Math title and
 * description, and App Version / App Size. Nothing else on a post changes by
 * itself, except the App Information fields ticked in Settings (publisher, age
 * rating, Android version, rating and votes), which follow Google Play.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_App_Updates {

	const SLUG         = 'y4apk-app-updates';
	const HOOK         = 'y4apk_version_check';
	const BATCH        = 20;  // version checks per step
	const SCAN_BATCH   = 100; // posts whose content is searched for a Play link per step (no requests)
	const SEARCH_BATCH = 6;   // Google Play name searches per step (up to 3 requests each)
	const LATEST       = '_y4apk_latest_version';
	const SOURCE       = '_y4apk_latest_source';
	const CHECKED      = '_y4apk_latest_checked';
	const NEEDS        = '_y4apk_needs_update';
	const LINK         = '_y4apk_gp_link';    // content | scanned | search | suggested | nomatch | confirmed | rejected
	const SUGGEST      = '_y4apk_gp_suggest'; // {"id","title","developer"}
	const INFO         = '_y4apk_gp_info';    // {"developer","rated","requires","rating","votes","read"} as last read from Google Play
	const NEWS_LINES = 5; // "What's new" lines shown before "Show all"
	const NO_FILE      = '_y4apk_no_file';    // the download folder Write with AI made, its page still waiting for a file
	const PER_PAGE     = 50;
	const PRIORITY     = 8;   // of the 20 checks a step, for the posts Google shows most and people follow
	const ICON_BATCH   = 8;   // icons copied from Google Play to R2 per step (two pictures each)
	const FOLLOW_BATCH = 40;  // posts brought up to Google Play's version per step from what is already known (no requests)
	const ICON_SRC     = '_y4apk_icon_src';   // the Google Play icon the featured image is (as Write with AI keeps it)
	const NEW_TYPES    = ['MOD', 'APK', 'XAPK', 'APKS', 'OBB']; // a first download's type (2.50.1)
	const OUTSIDE      = '_y4apk_outside_links'; // 2.50.4: downloads on other sites taken off the post: [['at' => index, 'row' => row], …] (to put back)
	const FOLLOWED     = '_y4apk_followed';   // 2.57: the last version put in by itself: {"from","to","at"}
	const GSC          = 'y4apk_gsc';
	const UA           = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		// Any save, including the block editor's (REST, not an admin page) —
		// after the Download Links box has saved its rows (save_post, 10).
		add_action('save_post', [__CLASS__, 'saved'], 99, 2);
		if (!is_admin()) {
			return;
		}
		add_action('admin_init', [__CLASS__, 'schedule']);
		add_action('admin_menu', [__CLASS__, 'register_page'], 104);
		add_action('admin_post_y4apk_vc_save', [__CLASS__, 'handle_save']);
		add_action('admin_post_y4apk_vc_check', [__CLASS__, 'handle_check']);
		add_action('admin_post_y4apk_vc_link', [__CLASS__, 'handle_link']);
		add_action('admin_post_y4apk_vc_bulk', [__CLASS__, 'handle_bulk']);
		add_action('admin_post_y4apk_vc_run', [__CLASS__, 'handle_run']);
		add_action('admin_post_y4apk_vc_settings', [__CLASS__, 'handle_settings']);
		add_action('admin_post_y4apk_gsc_sync', [__CLASS__, 'handle_gsc_sync']);
		add_action('admin_post_y4apk_gsc_upload', [__CLASS__, 'handle_gsc_upload']);
		add_action('admin_post_y4apk_vc_outside', [__CLASS__, 'handle_outside']);
		add_filter('manage_post_posts_columns', [__CLASS__, 'column']);
		add_action('manage_post_posts_custom_column', [__CLASS__, 'column_value'], 10, 2);
		add_filter('views_edit-post', [__CLASS__, 'view_link']);
		add_action('pre_get_posts', [__CLASS__, 'filter_list']);
		add_filter('post_row_actions', [__CLASS__, 'row_action'], 10, 2);
		add_action('admin_notices', [__CLASS__, 'check_notice']);
		add_action('add_meta_boxes_post', [__CLASS__, 'meta_box']);
	}

	public static function enabled() {
		return get_option('y4apk_vc_on', '1') !== '0';
	}

	public static function schedules($schedules) {
		$schedules['y4apk_15min'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Y4APK)'];
		return $schedules;
	}

	public static function schedule() {
		add_option('y4apk_vc_on', '1', '', 'yes');
		// 2.17.0 read every version from Aptoide, some of them other builds of
		// the app: forget those results, so every post is checked on Google Play.
		if ((int) get_option('y4apk_vc_schema', 1) < 2) {
			foreach ([self::LATEST, self::CHECKED, self::NEEDS] as $key) {
				delete_post_meta_by_key($key);
			}
			update_option('y4apk_vc_cursor', 0, false);
			update_option('y4apk_vc_schema', 2, true);
		}
		// 2.18.1 suggests only apps with a similar name: drop earlier ones that are not.
		if ((int) get_option('y4apk_vc_schema', 1) < 3) {
			global $wpdb;
			foreach ($wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'suggested'", self::LINK)) as $id) {
				$s = json_decode((string) get_post_meta($id, self::SUGGEST, true), true);
				$name = trim((string) get_post_meta($id, 'wp_title_GP', true));
				$name = $name !== '' ? $name : self::name_from_title(get_post_field('post_title', $id));
				if (!is_array($s) || !self::similar($name, isset($s['title']) ? $s['title'] : '')) {
					delete_post_meta($id, self::SUGGEST);
					update_post_meta($id, self::LINK, 'nomatch');
				}
			}
			update_option('y4apk_vc_schema', 3, true);
		}
		// 2.19.0 compares the newest APK download, not the first one: posts that
		// list an older version first may no longer need an update.
		if ((int) get_option('y4apk_vc_schema', 1) < 4) {
			global $wpdb;
			foreach ($wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS)) as $id) {
				self::refresh_flag((int) $id);
			}
			update_option('y4apk_vc_schema', 4, true);
		}
		// Before 2.21.1 the JSON lost its backslashes on saving: Thai and other
		// non-Latin names came back as "u0e40u0e01…", names with quotes not at
		// all. Look those suggestions up again (Play details are re-read anyway).
		if ((int) get_option('y4apk_vc_schema', 1) < 5) {
			global $wpdb;
			foreach ($wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::SUGGEST)) as $id) {
				$raw = (string) get_post_meta($id, self::SUGGEST, true);
				if (!is_array(json_decode($raw, true)) || preg_match('/u0[0-9a-f]{3}/i', $raw)) {
					delete_post_meta($id, self::SUGGEST);
					delete_post_meta($id, self::LINK);
				}
			}
			update_option('y4apk_vc_schema', 5, true);
		}
		// 2.50: Roblox scripts are not apps. Earlier versions looked them up on
		// Google Play like any post ("Steal An Egg Script" → the Play game Steal
		// An Egg): forget what was found for them, and a Google Play ID this
		// page set by itself.
		if ((int) get_option('y4apk_vc_schema', 1) < 6) {
			global $wpdb;
			$ids = array_map('intval', $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND " . self::scripts_sql('p')));
			foreach (array_chunk($ids, 500) as $chunk) {
				$in = implode(',', $chunk);
				$wpdb->query($wpdb->prepare("DELETE g FROM {$wpdb->postmeta} g JOIN {$wpdb->postmeta} l ON l.post_id = g.post_id AND l.meta_key = %s
					WHERE g.meta_key = 'wp_GP_ID' AND g.post_id IN ($in) AND l.meta_value IN ('content', 'search', 'confirmed')", self::LINK));
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN (%s, %s, %s, %s, %s, %s, %s)",
					self::LINK, self::SUGGEST, self::NEEDS, self::LATEST, self::SOURCE, self::CHECKED, self::INFO));
				foreach ($chunk as $id) {
					clean_post_cache($id);
				}
			}
			update_option('y4apk_vc_schema', 6, true);
		}
		// 2.50.1: games and apps on Google Play with no download yet are listed
		// in "Need a new file" (the upload makes their download folder).
		if ((int) get_option('y4apk_vc_schema', 1) < 7) {
			global $wpdb;
			$ids = $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'wp_GP_ID' AND g.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = 'repeatable_download_link'
				WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_parent = 0 AND (d.meta_id IS NULL OR d.meta_value IN ('', 'a:0:{}'))");
			foreach (array_slice($ids, 0, 5000) as $id) {
				self::refresh_flag((int) $id);
			}
			update_option('y4apk_vc_schema', 7, true);
		}
		// 2.50.2: a download row with an empty link is no file either
		if ((int) get_option('y4apk_vc_schema', 1) < 8) {
			global $wpdb;
			$ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT d.post_id FROM {$wpdb->postmeta} d JOIN {$wpdb->posts} p ON p.ID = d.post_id
				WHERE d.meta_key = 'repeatable_download_link' AND p.post_type = 'post' AND p.post_status = 'publish' AND (d.meta_value LIKE %s OR d.meta_value LIKE %s)",
				'%' . $wpdb->esc_like('s:12:"download_url";s:0:"";') . '%', '%' . $wpdb->esc_like('s:12:"download_url";s:1:"#";') . '%'));
			foreach (array_slice($ids, 0, 5000) as $id) {
				self::refresh_flag((int) $id);
			}
			update_option('y4apk_vc_schema', 8, true);
		}
		// 2.50.5: posts given a file of their own since their outside links were taken off leave that list
		if ((int) get_option('y4apk_vc_schema', 1) < 9) {
			global $wpdb;
			foreach (array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::OUTSIDE))) as $id) {
				self::file_came($id, get_post_meta($id, 'repeatable_download_link', true));
			}
			delete_transient('y4apk_outside_scan');
			update_option('y4apk_vc_schema', 9, true);
		}
		$next = wp_next_scheduled(self::HOOK);
		if (self::enabled() && !$next) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'y4apk_15min', self::HOOK);
		} elseif (!self::enabled() && $next) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Versions of the post
	 * ---------------------------------------------------------------- */

	/** "v2.742.Android" → "2.742"; "" when there is no number. */
	public static function number($version) {
		return preg_match('/\d+(?:\.\d+)*/', (string) $version, $m) ? $m[0] : '';
	}

	/**
	 * SQL: post $p is a Roblox script (2.50) — it has a Roblox game, script
	 * codes, or "script" in its title in a Roblox category. Scripts are not apps:
	 * no Google Play, no file to update.
	 */
	public static function scripts_sql($p = 'p') {
		global $wpdb;
		$sql = "(EXISTS (SELECT 1 FROM {$wpdb->term_relationships} xr JOIN {$wpdb->term_taxonomy} xt ON xt.term_taxonomy_id = xr.term_taxonomy_id WHERE xr.object_id = $p.ID AND xt.taxonomy = 'roblox_game')"
			. " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} xm WHERE xm.post_id = $p.ID AND xm.meta_key = '_y4apk_scripts')";
		$cats = class_exists('Y4APK_Roblox') ? array_map('intval', Y4APK_Roblox::categories()) : [];
		if ($cats) {
			$sql .= " OR (LOCATE('script', LOWER($p.post_title)) > 0 AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} xr2 JOIN {$wpdb->term_taxonomy} xt2 ON xt2.term_taxonomy_id = xr2.term_taxonomy_id"
				. " WHERE xr2.object_id = $p.ID AND xt2.taxonomy = 'category' AND xt2.term_id IN (" . implode(',', $cats) . ')))';
		}
		return $sql . ')';
	}

	public static function is_script_post($post_id) {
		if (!class_exists('Y4APK_Roblox')) {
			return false;
		}
		return (bool) Y4APK_Roblox::game_of($post_id) || (bool) get_post_meta($post_id, '_y4apk_scripts', true)
			|| (stripos((string) get_post_field('post_title', $post_id), 'script') !== false && Y4APK_Roblox::is_roblox_post($post_id));
	}

	/** Is this download an Android app file (APK, MOD…), rather than OBB, IPA…? */
	public static function is_apk($link) {
		$name = strtolower(isset($link['download_name']) ? trim((string) $link['download_name']) : '');
		return in_array($name, ['apk', 'apks', 'xapk', 'apkm', 'mod'], true);
	}

	/**
	 * Index and entry of the post's APK download: the APK-type link with the
	 * highest version (posts that keep older versions list them too), else the
	 * first link.
	 */
	public static function download($post_id) {
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		if (!is_array($links) || !$links) {
			return [null, null];
		}
		$best = null;
		foreach ($links as $i => $link) {
			if (!self::is_apk($link)) {
				continue;
			}
			$n = self::number(isset($link['download_version']) ? $link['download_version'] : '');
			if ($best === null || ($n !== '' && ($best[2] === '' || version_compare($n, $best[2], '>')))) {
				$best = [$i, $link, $n];
			}
		}
		if ($best !== null) {
			return [$best[0], $best[1]];
		}
		$first = array_key_first($links);
		return [$first, $links[$first]];
	}

	/**
	 * The post's other downloads, next to its APK download: 'same' ones carry
	 * the same version (OBB, IPA, another APK build) and are offered for update
	 * too; 'kept' ones (older versions, other files) are left as they are.
	 */
	public static function other_downloads($post_id) {
		list($index, $main) = self::download($post_id);
		$out = ['same' => [], 'kept' => []];
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		if ($index === null || !is_array($links)) {
			return $out;
		}
		$n = self::number(isset($main['download_version']) ? $main['download_version'] : '');
		foreach ($links as $i => $link) {
			if ($i === $index) {
				continue;
			}
			$same = $n !== '' && self::number(isset($link['download_version']) ? $link['download_version'] : '') === $n;
			$out[$same ? 'same' : 'kept'][$i] = $link;
		}
		return $out;
	}

	/** The site's own addresses (2.50.4): its domain, the download pages' and R2's — "y4apk.com", "y4apk.net" and their subdomains. */
	public static function own_domains() {
		static $d = null;
		if ($d === null) {
			$hosts = [wp_parse_url(home_url(), PHP_URL_HOST)];
			if (class_exists('Y4APK_R2')) {
				$o = Y4APK_R2::settings();
				$hosts[] = wp_parse_url((string) $o['dir_url'], PHP_URL_HOST);
				$hosts[] = wp_parse_url((string) $o['public'], PHP_URL_HOST);
			}
			$d = ['y4apk.com', 'y4apk.net']; // always the site's own, whatever the settings say
			foreach (array_filter($hosts) as $h) {
				$h = strtolower(preg_replace('/^www\./', '', $h));
				$parts = explode('.', $h);
				$d[] = count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $h;
			}
			$d = array_values(array_unique(apply_filters('y4apk_own_domains', $d)));
		}
		return $d;
	}

	/** A download on another site (files.modyolo.com…), not one of Y4APK's own. */
	public static function outside_link($link) {
		if (self::no_link($link)) {
			return false;
		}
		$host = strtolower((string) wp_parse_url(trim(html_entity_decode((string) $link['download_url'])), PHP_URL_HOST));
		if ($host === '') {
			return false; // a link on this site ("/files/up/…")
		}
		foreach (self::own_domains() as $d) {
			if ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d) {
				return false;
			}
		}
		return true;
	}

	/** A download row with no link to a file yet (empty, or "#"). */
	public static function no_link($link) {
		return !is_array($link) || in_array(trim(html_entity_decode((string) (isset($link['download_url']) ? $link['download_url'] : ''))), ['', '#'], true);
	}

	/** "v4.5.2.zip" with the new number "4.6.0" → "v4.6.0.zip". */
	public static function with_number($version, $new) {
		$n = self::number($version);
		return $n !== '' && $new !== '' ? str_replace($n, $new, (string) $version) : (string) $version;
	}

	/** Fingerprint of the post's downloads, so a form made from older ones is refused. */
	private static function links_sig($post_id) {
		return md5(wp_json_encode(get_post_meta($post_id, 'repeatable_download_link', true)));
	}

	public static function current_version($post_id) {
		list(, $link) = self::download($post_id);
		$version = $link && !empty($link['download_version']) ? $link['download_version'] : get_post_meta($post_id, 'wp_version_GP', true);
		return (string) $version;
	}

	/** Is the stored latest version newer than the post's download — or is its download a folder still waiting for a file? */
	public static function needs_update($post_id) {
		list($first, $first_link) = self::download($post_id);
		if ($first === null || self::no_link($first_link) || self::outside_link($first_link)) {
			// 2.50.1: a game or app on Google Play with no download at all: it needs its first file
			// 2.50.4: so does one whose download is on another site, or was (taken off, waiting for yours)
			return (self::package_from(get_post_meta($post_id, 'wp_GP_ID', true)) !== '' || get_post_meta($post_id, self::OUTSIDE, true))
				&& !wp_get_post_parent_id($post_id) && !self::is_script_post($post_id);
		}
		$waiting = (string) get_post_meta($post_id, self::NO_FILE, true);
		if ($waiting !== '') {
			list(, $link) = self::download($post_id);
			if ($link && html_entity_decode((string) $link['download_url']) === $waiting) {
				return true;
			}
		}
		$latest = self::number(get_post_meta($post_id, self::LATEST, true));
		$current = self::number(self::current_version($post_id));
		return $latest !== '' && $current !== '' && version_compare($latest, $current, '>');
	}

	public static function saved($post_id, $post) {
		if ($post && $post->post_type === 'post') {
			self::refresh_flag($post_id);
		}
	}

	public static function refresh_flag($post_id) {
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		if (self::needs_update($post_id)) {
			update_post_meta($post_id, self::NEEDS, '1');
		} else {
			delete_post_meta($post_id, self::NEEDS);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Google Play and Aptoide
	 * ---------------------------------------------------------------- */

	/** A Google Play package from a Play URL or a bare ID; '' if neither. */
	public static function package_from($text) {
		$text = trim((string) $text);
		if (preg_match('~play\.google\.com/store/apps/details\?(?:[^"\'\s<>]*&(?:amp;)?)?id=([A-Za-z][\w.]*)~i', $text, $m)) {
			return rtrim($m[1], '.');
		}
		return preg_match('/^[A-Za-z][\w]*(\.[A-Za-z0-9_]+)+$/', $text) ? $text : '';
	}

	/** A Google Play page (search, category), as get() fetches it: [status code, body] or null. */
	public static function play_page($url) {
		return strpos((string) $url, 'https://play.google.com/') === 0 ? self::get($url) : null;
	}

	/** [status code, body], or null when the request failed outright. */
	private static function get($url) {
		$r = wp_remote_get($url, [
			'timeout'    => 12,
			'user-agent' => self::UA,
			'headers'    => ['Accept-Language' => 'en-US,en;q=0.9'],
		]);
		return is_wp_error($r) ? null : [(int) wp_remote_retrieve_response_code($r), (string) wp_remote_retrieve_body($r)];
	}

	/** The JSON in a Play page's AF_initDataCallback({key: 'ds:5', … data: [...], sideChannel: {}}). */
	private static function play_block($html, $key = 'ds:5') {
		$at = strpos($html, "key: '" . $key . "'");
		if ($at === false) {
			return null;
		}
		$start = strpos($html, 'data:', $at);
		$end = $start === false ? false : strpos($html, ', sideChannel:', $start);
		if ($end === false) {
			return null;
		}
		$data = json_decode(substr($html, $start + 5, $end - $start - 5), true);
		return is_array($data) ? $data : null;
	}

	private static function dig($data, $path) {
		$v = self::dig_any($data, $path);
		return is_scalar($v) ? $v : null;
	}

	private static function dig_any($data, $path) {
		foreach ($path as $key) {
			if (!is_array($data) || !array_key_exists($key, $data)) {
				return null;
			}
			$data = $data[$key];
		}
		return $data;
	}

	/** A Play image address without its size ("…=w526-h296-rw" → "…"), or '' when it is not one. */
	public static function play_image($url) {
		$url = trim((string) $url);
		return preg_match('~^https://[a-z0-9-]+\.googleusercontent\.com/~i', $url) ? preg_replace('~=[^/]*$~', '', $url) : '';
	}

	private static function valid_version($v) {
		return is_string($v) && preg_match('/^\d[\w.\-]{0,40}$/', $v);
	}

	/** The Play Store whose page is read (ratings and age ratings differ by country). */
	public static function country() {
		$c = strtoupper((string) get_option('y4apk_vc_country', 'TH'));
		return preg_match('/^[A-Z]{2}$/', $c) ? $c : 'TH';
	}

	/** The page's schema.org data (SoftwareApplication / VideoGame), when there is any. */
	private static function play_ld($html) {
		if (!preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $m)) {
			return [];
		}
		foreach ($m[1] as $json) {
			$d = json_decode(trim($json), true);
			if (is_array($d) && (isset($d['aggregateRating']) || isset($d['contentRating']))) {
				return $d;
			}
		}
		return [];
	}

	/** Play's "What's new" HTML as plain lines (at most 1,000 characters). */
	public static function plain_lines($html, $max = 1000) {
		$text = html_entity_decode(wp_strip_all_tags(preg_replace('~<br\s*/?>|</p>|</li>~i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8');
		$lines = array_filter(array_map('trim', explode("\n", $text)), 'strlen');
		return mb_substr(implode("\n", $lines), 0, $max);
	}

	/** An age rating as App Information keeps it: "Rated for 12+", "PEGI 12", "Teen" → "12+", "12+", "13+". */
	public static function age($text) {
		$t = trim((string) $text);
		if (preg_match('/(\d{1,2})\s*\+/', $t, $m) || preg_match('/\b(?:PEGI|USK|ACB|ClassInd)\D{0,10}(\d{1,2})\b/i', $t, $m)) {
			return $m[1] . '+';
		}
		$esrb = ['early childhood' => '3+', 'everyone' => '3+', 'teen' => '13+', 'mature' => '17+', 'adults only' => '18+'];
		return isset($esrb[strtolower($t)]) ? $esrb[strtolower($t)] : '';
	}

	/**
	 * An app's details as Google Play shows them: English, the chosen store's
	 * country, then the US store, then any. status: ok | missing | busy.
	 */
	public static function play_app($package) {
		$tries = array_values(array_unique(['&hl=en&gl=' . self::country(), '&hl=en&gl=US', '&hl=en']));
		foreach ($tries as $i => $params) {
			$r = self::get('https://play.google.com/store/apps/details?id=' . rawurlencode($package) . $params);
			if ($r === null || $r[0] === 429 || $r[0] >= 500) {
				return ['status' => 'busy'];
			}
			if ($r[0] === 404 && $i < count($tries) - 1) {
				continue;
			}
			if ($r[0] !== 200) {
				return ['status' => 'missing'];
			}
			$data = self::play_block($r[1]);
			$ld = self::play_ld($r[1]);
			$version = self::dig($data, [1, 2, 140, 0, 0, 0]);
			$updated = self::dig($data, [1, 2, 145, 0, 1, 0]);
			$developer = (string) self::dig($data, [1, 2, 68, 0]);
			$rated = (string) self::dig($data, [1, 2, 9, 0]);
			$requires = (string) self::dig($data, [1, 2, 140, 1, 1, 0, 0, 1]); // "5.1" or "5.1 and up"
			$rating = self::dig($data, [1, 2, 51, 0, 1]);
			$votes = self::dig($data, [1, 2, 51, 2, 1]);
			$news = (string) self::dig($data, [1, 2, 144, 1, 1]); // "What's new", HTML with <br>
			// For Y4APK ▸ Write with ChatGPT: the icon, the screenshots and the description.
			$icon = self::play_image(self::dig($data, [1, 2, 95, 0, 3, 2]));
			if ($icon === '' && preg_match('~<meta[^>]+property="og:image"[^>]+content="([^"]+)"~i', $r[1], $og)) {
				$icon = self::play_image(html_entity_decode($og[1], ENT_QUOTES));
			}
			$shots = [];
			foreach ((array) self::dig_any($data, [1, 2, 78, 0]) as $shot) {
				$u = self::play_image(self::dig($shot, [3, 2]));
				if ($u !== '') {
					$shots[] = $u;
				}
			}
			$about = (string) self::dig($data, [1, 2, 72, 0, 1]);
			if ($about === '' && isset($ld['description'])) {
				$about = (string) $ld['description'];
			}
			// The schema.org data, when the page's own data has moved.
			if ($developer === '' && isset($ld['author']['name'])) {
				$developer = (string) $ld['author']['name'];
			}
			if ($rated === '' && isset($ld['contentRating'])) {
				$rated = (string) $ld['contentRating'];
			}
			if (!is_numeric($rating) && isset($ld['aggregateRating']['ratingValue'])) {
				$rating = $ld['aggregateRating']['ratingValue'];
			}
			if (!is_numeric($votes) && isset($ld['aggregateRating']['ratingCount'])) {
				$votes = str_replace([',', '.', ' '], '', (string) $ld['aggregateRating']['ratingCount']);
			}
			return [
				'status'    => 'ok',
				'title'     => (string) self::dig($data, [1, 2, 0, 0]),
				'developer' => trim($developer),
				'version'   => self::valid_version($version) ? $version : '',
				'shown'     => is_string($version) ? $version : '', // e.g. "Varies with device"
				'updated'   => is_numeric($updated) ? (int) $updated : 0,
				'rated'     => self::age($rated),
				'rated_text'=> $rated,
				'requires'  => preg_match('/^\s*(\d+(?:\.\d+){0,2})/', $requires, $m) ? $m[1] : '',
				'rating'    => is_numeric($rating) && $rating >= 1 && $rating <= 5 ? round((float) $rating, 1) : 0,
				'votes'     => is_numeric($votes) && $votes > 0 ? (int) $votes : 0,
				'whatsnew'  => self::plain_lines($news),
				'icon'      => $icon,
				'screenshots' => array_values(array_unique($shots)),
				'description' => self::plain_lines($about, 4000),
				'genre'     => (string) self::dig($data, [1, 2, 79, 0, 0, 0]),
				'game'      => strpos((string) self::dig($data, [1, 2, 79, 0, 0, 2]), 'GAME') === 0,
				'genre_id'  => (string) self::dig($data, [1, 2, 79, 0, 0, 2]), // "GAME_SIMULATION", "TOOLS"
				'readable'  => $data !== null,
				'store'     => preg_match('/gl=([A-Z]{2})/', $params, $g) ? $g[1] : '',
			];
		}
		return ['status' => 'missing'];
	}

	/** Version from Aptoide's public API: string, '' when not there, null when it did not answer. */
	public static function aptoide_version($package) {
		$m = self::aptoide_meta($package);
		return $m === null ? null : $m['version'];
	}

	/** Aptoide's public data for an app: ['version' => … or '', 'size' => bytes or 0], or null when it did not answer. */
	public static function aptoide_meta($package) {
		$r = self::get('https://ws2.aptoide.com/api/7/app/getMeta/package_name=' . rawurlencode($package));
		if ($r === null || $r[0] === 429 || $r[0] >= 500) {
			return null;
		}
		$data = json_decode($r[1], true);
		$file = $r[0] === 200 && isset($data['data']['file']) && is_array($data['data']['file']) ? $data['data']['file'] : [];
		$v = isset($file['vername']) ? (string) $file['vername'] : '';
		$size = isset($file['filesize']) ? $file['filesize'] : (isset($data['data']['size']) ? $data['data']['size'] : 0);
		return [
			// Plain dotted numbers only: Aptoide also lists other builds of an app
			// ("1009.0-wv" for Brawl Stars, whose Play version is 69.414).
			'version' => preg_match('/^\d+(?:\.\d+){0,4}$/', $v) ? $v : '',
			'size'    => $r[0] === 200 && is_numeric($size) && $size > 0 ? (float) $size : 0,
		];
	}

	/** "245.12MB", as the site writes sizes. */
	public static function size_text($bytes) {
		$bytes = (float) $bytes;
		return $bytes >= 1073741824 ? sprintf('%.2fGB', $bytes / 1073741824) : sprintf('%.2fMB', $bytes / 1048576);
	}

	/**
	 * A size for a download that has none (2.28; Google Play no longer shows
	 * sizes): App Size if the post has one, else the size of the app's APK on
	 * Aptoide — the same app, near enough until the file is uploaded and its
	 * own size goes in. '' when there is none, null when Aptoide was busy.
	 */
	public static function size_for($post_id) {
		$app = trim((string) get_post_meta($post_id, 'wp_sizes_GP', true));
		if (!in_array($app, ['', '—', '-'], true)) {
			return $app;
		}
		$package = self::package_from(get_post_meta($post_id, 'wp_GP_ID', true));
		if ($package === '' || get_transient('y4apk_nosize_' . md5($package))) {
			return '';
		}
		$m = self::aptoide_meta($package);
		if ($m === null) {
			return null;
		}
		if ($m['size'] > 0) {
			return self::size_text($m['size']);
		}
		set_transient('y4apk_nosize_' . md5($package), 1, WEEK_IN_SECONDS); // not there: not asked again for a week
		return '';
	}

	/** The main download's size when it has none (and an empty App Size): true when one was found, false when not, null when Aptoide was busy. */
	public static function fill_size($post_id) {
		list($i, $main) = self::download($post_id);
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$have = $main && isset($main['download_size']) ? trim((string) $main['download_size']) : '';
		if (!$main || !is_array($links) || !isset($links[$i]) || !in_array($have, ['', '—', '-'], true)) {
			return false;
		}
		$size = self::size_for($post_id);
		if (!$size) {
			return $size === null ? null : false;
		}
		$app = trim((string) get_post_meta($post_id, 'wp_sizes_GP', true));
		$links[$i]['download_size'] = $size;
		update_post_meta($post_id, 'repeatable_download_link', $links);
		if ($app === '' || $app === '—') {
			update_post_meta($post_id, 'wp_sizes_GP', $size);
		}
		return true;
	}

	/** ['version' => …, 'source' => play|aptoide|''], or null to try again later. */
	public static function latest($package, $play = null) {
		$play = $play !== null ? $play : self::play_app($package);
		if ($play['status'] === 'busy') {
			return null;
		}
		if ($play['status'] === 'ok' && $play['version'] !== '') {
			return ['version' => $play['version'], 'source' => 'play'];
		}
		$apt = self::aptoide_version($package);
		if ($apt === null) {
			return null;
		}
		$varies = $play['status'] === 'ok' && $play['shown'] !== '';
		return ['version' => $apt, 'source' => $apt !== '' ? 'aptoide' : ($varies ? 'varies' : '')];
	}

	/** Icons a background step may still copy to R2 (each is two downloads and two uploads). */
	private static $icon_budget = null;

	public static function check_post($post_id) {
		$package = self::package_from(get_post_meta($post_id, 'wp_GP_ID', true));
		if ($package === '' || self::is_script_post($post_id) || get_post_meta($post_id, '_y4apk_parent', true)) {
			return false;
		}
		$play = self::play_app($package);
		$latest = self::latest($package, $play);
		if ($latest === null) {
			return null;
		}
		if ($play['status'] === 'ok') {
			self::apply_info($post_id, $play);
			$icons = self::$icon_budget === null || self::$icon_budget > 0;
			$done = self::sync_images($post_id, $play, $icons);
			if ($done['icon'] && self::$icon_budget !== null) {
				self::$icon_budget--;
			}
		}
		self::fill_size($post_id); // a download with no size: Aptoide's, once
		update_post_meta($post_id, self::LATEST, $latest['version']);
		update_post_meta($post_id, self::SOURCE, $latest['source']);
		update_post_meta($post_id, self::CHECKED, time());
		self::refresh_flag($post_id);
		self::follow_version($post_id, $latest['version'], $latest['source']);
		return true;
	}

	/* ---------------------------------------------------------------- *
	 * Following Google Play by itself (2.50)
	 * ---------------------------------------------------------------- */

	/** What follows Google Play by itself: key => [label, default]. */
	public static function auto_parts() {
		return [
			'version' => [__('The version: when Google Play has a newer one, it goes at once into App Version, the title and the Rank Math title and description, and the post is marked updated. Its download keeps the version of its file — and the post stays in “Need a new file” — until you upload the new file. A version you uploaded that is newer than Google Play’s is never lowered.', 'y4apk'), 1],
			'icon'    => [__('The icon (featured image): kept on Cloudflare R2, at your files address, so it takes no space on this server. A picture of your own that is not square is never replaced.', 'y4apk'), 1],
			'shots'   => [__('The screenshots: shown from Google Play’s own servers, so they take no space anywhere. Screenshots you uploaded yourself are kept.', 'y4apk'), 1],
			'free'    => [__('When an icon is replaced, delete the old one from this server (only when nothing else uses it). With this, icons already on this server move to R2 too, a few each step.', 'y4apk'), 1],
		];
	}

	public static function auto_on($key) {
		$on = get_option('y4apk_vc_auto', []);
		$parts = self::auto_parts();
		return is_array($on) && isset($on[$key]) ? (bool) $on[$key] : !empty($parts[$key][1]);
	}

	/**
	 * Google Play has a newer version than the post shows: the post says so at
	 * once — App Version, the version in the title and in Rank Math's title,
	 * description and social titles — and is marked updated. The downloads keep
	 * their own versions: the file is still the old one, so the post stays in
	 * "Need a new file" until it is uploaded. Never lowers a version, and only
	 * Google Play's own number counts (Aptoide lists other builds too). true
	 * when the post changed.
	 */
	public static function follow_version($post_id, $latest, $source = 'play') {
		if ($source !== 'play' || !self::auto_on('version')) {
			return false;
		}
		$new = self::number($latest);
		$shown = self::number(get_post_meta($post_id, 'wp_version_GP', true));
		$file = self::number(self::current_version($post_id));
		$old = $shown !== '' ? $shown : $file;
		if ($new === '' || ($old !== '' && !version_compare($new, $old, '>')) || ($file !== '' && !version_compare($new, $file, '>'))) {
			return false;
		}
		update_post_meta($post_id, 'wp_version_GP', $new);
		$args = ['ID' => (int) $post_id];
		self::put_version($post_id, [$shown, $file], $new, $args);
		self::update_quietly($args); // also marks it as just updated
		update_option('y4apk_vc_followed', (int) get_option('y4apk_vc_followed', 0) + 1, false);
		update_post_meta($post_id, self::FOLLOWED, wp_json_encode(['from' => $old, 'to' => $new, 'at' => time()])); // for Auto updates
		return true;
	}

	/**
	 * The new version in place of the old one in the title and Rank Math's
	 * texts, when the old one is plainly there ("v2.742" → "v2.750"; a bare
	 * "1" is left alone). $olds: the versions it may say, most likely first.
	 */
	private static function put_version($post_id, $olds, $new, &$args) {
		$keys = ['rank_math_title', 'rank_math_description', 'rank_math_facebook_title', 'rank_math_twitter_title'];
		$title = (string) get_post_field('post_title', $post_id, 'raw');
		foreach (array_unique($olds) as $old) {
			if ($old === '' || $old === $new || strpos($old, '.') === false) {
				continue;
			}
			$re = '/(?<![\d.])' . preg_quote($old, '/') . '(?!\.?\d)/';
			$found = false;
			if (preg_match($re, $title)) {
				$args['post_title'] = preg_replace($re, $new, $title);
				$found = true;
			}
			foreach ($keys as $key) {
				$value = (string) get_post_meta($post_id, $key, true);
				if ($value !== '' && preg_match($re, $value)) {
					update_post_meta($post_id, $key, wp_slash(preg_replace($re, $new, $value)));
					$found = true;
				}
			}
			if ($found) {
				return;
			}
		}
	}

	/**
	 * wp_update_post, from a background step too: there is no user then, so
	 * WordPress would put the article through its HTML filter on the way (and
	 * take out its iframes and scripts). Nothing in the article changes here.
	 */
	private static function update_quietly($args) {
		$kses = has_filter('content_save_pre', 'wp_filter_post_kses');
		if ($kses) {
			kses_remove_filters();
		}
		if (isset($args['post_title'])) {
			$args['post_title'] = wp_slash($args['post_title']);
		}
		$r = wp_update_post($args, true);
		if ($kses) {
			kses_init_filters();
		}
		return $r;
	}

	/** The icon and screenshots the same as Google Play's: ['icon' => replaced, 'shots' => replaced]. */
	public static function sync_images($post_id, $play, $icons = true) {
		$done = ['icon' => false, 'shots' => false];
		if (self::auto_on('shots') && !empty($play['screenshots'])) {
			$list = [];
			foreach (array_slice($play['screenshots'], 0, class_exists('Y4APK_Writer') ? Y4APK_Writer::SCREENS : 5) as $u) {
				$list[] = ['ss_url' => esc_url_raw($u . '=w1052-h592-rw')]; // as Write with AI keeps them
			}
			$old = get_post_meta($post_id, 'ss_images', true);
			if ($old !== $list && self::play_pictures($old)) {
				update_post_meta($post_id, 'ss_images', $list);
				$done['shots'] = true;
			}
		}
		if ($icons && self::auto_on('icon') && !empty($play['icon'])) {
			$done['icon'] = self::sync_icon($post_id, $play['icon']) === true;
		}
		return $done;
	}

	/** No screenshots, or only Google Play's: they may follow Google Play. */
	private static function play_pictures($rows) {
		if (!is_array($rows) || !$rows) {
			return true;
		}
		foreach ($rows as $r) {
			$u = is_array($r) ? trim((string) ($r['ss_url'] ?? '')) : '';
			if ($u !== '' && !preg_match('~^https?://[a-z0-9.-]*googleusercontent\.com/~i', $u)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The featured image as Google Play's icon, kept on R2: true when it was
	 * replaced, false when it already is (or it is the owner's own picture: not
	 * square), a WP_Error when it could not be.
	 */
	public static function sync_icon($post_id, $src) {
		if (!class_exists('Y4APK_Icons') || !Y4APK_Icons::ready()) {
			return false;
		}
		$src = self::play_image($src);
		if ($src === '') {
			return false;
		}
		$thumb = (int) get_post_thumbnail_id($post_id);
		$mine = Y4APK_Icons::data($thumb);
		if ($mine && $mine['src'] === $src) {
			return false;
		}
		if ($thumb && !$mine && !Y4APK_Icons::square($thumb)) {
			return false; // a picture of the owner's own
		}
		if ($thumb && !$mine && !self::auto_on('free') && self::play_image(get_post_meta($post_id, self::ICON_SRC, true)) === $src) {
			return false; // Google Play's icon already, kept on this server as asked
		}
		$name = trim(html_entity_decode(wp_strip_all_tags((string) get_post_meta($post_id, 'wp_title_GP', true)), ENT_QUOTES, 'UTF-8'));
		$name = $name !== '' ? $name : self::name_from_title(get_post_field('post_title', $post_id));
		$title = $thumb ? (string) get_post_field('post_title', $thumb, 'raw') : '';
		$alt = $thumb ? (string) get_post_meta($thumb, '_wp_attachment_image_alt', true) : '';
		$att = Y4APK_Icons::make($post_id, $src, $title !== '' ? $title : $name, $alt !== '' ? $alt : $name); // its words as they were (Write with AI chose them for Rank Math)
		if (is_wp_error($att)) {
			return $att;
		}
		set_post_thumbnail($post_id, $att);
		update_post_meta($post_id, self::ICON_SRC, $src);
		if ($thumb) {
			global $wpdb;
			// Members' versions under this post that show its icon follow it
			foreach ($wpdb->get_col($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s AND p.post_parent = %d",
				(string) $thumb, (int) $post_id)) as $kid) {
				update_post_meta((int) $kid, '_thumbnail_id', $att);
			}
			self::drop_icon($thumb, $post_id);
		}
		if (class_exists('Y4APK_Versions')) {
			Y4APK_Versions::purge($post_id);
		}
		return true;
	}

	/** The icon a post no longer shows: gone from R2, or from this server when asked — never while anything else uses it. */
	private static function drop_icon($att, $post_id) {
		if (Y4APK_Icons::used_elsewhere($att, $post_id)) {
			return;
		}
		$parent = (int) get_post_field('post_parent', $att);
		if (Y4APK_Icons::data($att) || (self::auto_on('free') && in_array($parent, [0, (int) $post_id], true))) {
			wp_delete_attachment($att, true);
		}
	}

	/* ---------------------------------------------------------------- *
	 * App Information from Google Play
	 * ---------------------------------------------------------------- */

	/** App Information fields kept up to date from Google Play: key => [label, post meta]. */
	public static function info_fields() {
		return [
			'publisher' => [__('App Publisher', 'y4apk'), 'wp_developers_GP'],
			'rated'     => [__('App Rated Year’s (age rating, e.g. 12+)', 'y4apk'), 'wp_contentrated_GP'],
			'requires'  => [__('App Required OS (Android version)', 'y4apk'), 'wp_requires_GP'],
			'rating'    => [__('Total Rating and Total Votes', 'y4apk'), 'avg_rating'],
		];
	}

	/** What posts show (2.22): key => [label, default]. */
	public static function post_parts() {
		return [
			'fresh'    => [__('“Updated … ago” under the title, with “= same version as Google Play ✓” when the check agrees', 'y4apk'), 1],
			'whatsnew' => [__('“What’s new in v…” from Google Play, when the post has that version', 'y4apk'), 1],
			'install'  => [__('“How to install” steps under the downloads (with the OBB step when there is an OBB)', 'y4apk'), 1],
		];
	}

	public static function post_part_on($key) {
		$on = get_option('y4apk_post_parts', []);
		$parts = self::post_parts();
		return is_array($on) && isset($on[$key]) ? (bool) $on[$key] : !empty($parts[$key][1]);
	}

	/** The post's version in a few words, for the post page: ['ago', 'version', 'same' (as Google Play)]. */
	public static function freshness($post_id) {
		$n = self::number(self::current_version($post_id));
		$latest = self::number(get_post_meta($post_id, self::LATEST, true));
		return [
			'ago'     => human_time_diff(get_post_modified_time('U', true, $post_id)),
			'version' => $n,
			'same'    => $n !== '' && $n === $latest && (int) get_post_meta($post_id, self::CHECKED, true) > time() - 7 * DAY_IN_SECONDS,
		];
	}

	/** Google Play's "What's new" for the post, only when it is about the version the post offers: ['version', 'lines', 'updated'] or null. */
	public static function whats_new($post_id) {
		$i = json_decode((string) get_post_meta($post_id, self::INFO, true), true);
		$n = self::number(self::current_version($post_id));
		if (!is_array($i) || empty($i['whatsnew']) || $n === '' || self::number(isset($i['version']) ? $i['version'] : '') !== $n) {
			return null;
		}
		$lines = self::news_lines($i['whatsnew']);
		return ['version' => $n, 'lines' => array_slice($lines, 0, self::NEWS_LINES), 'more' => array_slice($lines, self::NEWS_LINES),
			'updated' => isset($i['updated']) ? (int) $i['updated'] : 0];
	}

	/**
	 * "What's new" as a short list (2.27): many apps paste every version's notes
	 * there ("Version 2.1.1: / - Bus price fix / Version 2.1: / - Bus / …"), so
	 * only the newest version's are kept, without their own "-" or "•" (the
	 * list has its bullets).
	 */
	public static function news_lines($text) {
		$heading = '/^(?:what[\'’]?s new(?:\s+in)?\s*)?(?:(?:version|ver\.?|v)\s*\d+(?:[.\-]\d+)*|\d+(?:\.\d+)+)\s*[:\-–]?\s*$/iu';
		$sections = [[]];
		foreach (explode("\n", (string) $text) as $line) {
			$line = trim(preg_replace('/^\s*(?:[-–—•*·▪►✓✔]+\s*|\d{1,2}[.)]\s+)/u', '', $line));
			if ($line === '') {
				continue;
			}
			if (preg_match($heading, $line)) {
				$sections[] = [];
				continue;
			}
			$sections[count($sections) - 1][] = $line;
		}
		foreach ($sections as $lines) {
			if ($lines) {
				return $lines; // the first notes there are: the newest version's
			}
		}
		return [];
	}

	public static function info_on($key) {
		$on = get_option('y4apk_vc_info', ['publisher' => 1, 'rated' => 1, 'requires' => 1, 'rating' => 1]);
		return is_array($on) && !empty($on[$key]);
	}

	/**
	 * Remember what Google Play shows for the post's app, and copy it into the
	 * ticked App Information fields. Empty values never overwrite; the post's
	 * modified date stays as it is.
	 */
	public static function apply_info($post_id, $play) {
		$info = [
			'developer' => $play['developer'],
			'rated'     => $play['rated'],
			'requires'  => $play['requires'],
			'rating'    => $play['rating'],
			'votes'     => $play['votes'],
			'store'     => isset($play['store']) ? $play['store'] : '',
			'version'   => isset($play['version']) ? $play['version'] : '',
			'updated'   => isset($play['updated']) ? (int) $play['updated'] : 0,
			'whatsnew'  => isset($play['whatsnew']) ? $play['whatsnew'] : '',
			'read'      => time(),
		];
		update_post_meta($post_id, self::INFO, wp_slash(wp_json_encode($info))); // post meta strips one level of backslashes

		$changed = false;
		foreach (['publisher' => 'developer', 'rated' => 'rated', 'requires' => 'requires'] as $key => $from) {
			$meta = self::info_fields()[$key][1];
			if (self::info_on($key) && $info[$from] !== '' && (string) get_post_meta($post_id, $meta, true) !== $info[$from]) {
				update_post_meta($post_id, $meta, $info[$from]);
				$changed = true;
			}
		}
		if (self::info_on('rating') && $info['rating'] > 0 && $info['votes'] > 0
			&& ((float) get_post_meta($post_id, 'avg_rating', true) !== (float) $info['rating'] || (int) get_post_meta($post_id, 'total_votes', true) !== $info['votes'])) {
			// As App Information saves them: the theme's stars use all three.
			update_post_meta($post_id, 'avg_rating', $info['rating']);
			update_post_meta($post_id, 'total_votes', $info['votes']);
			update_post_meta($post_id, 'total_rating', round($info['rating'] * $info['votes']));
			$changed = true;
		}
		if ($changed) {
			update_option('y4apk_vc_info_updated', (int) get_option('y4apk_vc_info_updated', 0) + 1, false);
		}
		return $changed;
	}

	/* ---------------------------------------------------------------- *
	 * Google Search data (Search Console, through Site Kit or a CSV)
	 * ---------------------------------------------------------------- */

	/**
	 * Clicks and impressions per post over the last 28 days:
	 * ['synced', 'source' (sitekit|csv), 'from', 'to', 'rows', 'posts' => [id => [impressions, clicks, position]]].
	 */
	public static function gsc() {
		$d = get_option(self::GSC, []);
		return is_array($d) && isset($d['posts']) && is_array($d['posts']) ? $d : ['synced' => 0, 'source' => '', 'rows' => 0, 'posts' => []];
	}

	public static function sitekit_active() {
		return defined('GOOGLESITEKIT_VERSION');
	}

	/**
	 * Search Console's numbers per page, read through Site Kit as $user_id (an
	 * administrator Site Kit lets see Search Console). true, or WP_Error.
	 */
	public static function gsc_sync_sitekit($user_id = 0) {
		if (!self::sitekit_active()) {
			return new WP_Error('y4apk_no_sitekit', __('Site Kit by Google is not active.', 'y4apk'));
		}
		$from = gmdate('Y-m-d', current_time('timestamp') - 29 * DAY_IN_SECONDS);
		$to = gmdate('Y-m-d', current_time('timestamp') - 2 * DAY_IN_SECONDS); // Search Console runs ~2 days behind
		$before = get_current_user_id();
		if ($user_id) {
			wp_set_current_user($user_id);
		}
		try {
			$request = new WP_REST_Request('GET', '/google-site-kit/v1/modules/search-console/data/searchanalytics');
			$request->set_query_params(['startDate' => $from, 'endDate' => $to, 'dimensions' => 'page', 'limit' => 25000]);
			$response = rest_do_request($request);
		} catch (Throwable $e) {
			$response = new WP_Error('y4apk_sitekit', $e->getMessage());
		}
		if ($user_id) {
			wp_set_current_user($before);
		}
		if (is_wp_error($response)) {
			return $response;
		}
		if ($response->is_error()) {
			return $response->as_error();
		}
		// Google's row objects, as plain arrays.
		$rows = json_decode(wp_json_encode($response->get_data()), true);
		if (!is_array($rows)) {
			return new WP_Error('y4apk_sitekit', __('Site Kit answered, but not with Search Console rows.', 'y4apk'));
		}
		$pages = [];
		foreach ($rows as $r) {
			if (isset($r['keys'][0])) {
				$pages[] = [(string) $r['keys'][0], (int) ($r['clicks'] ?? 0), (int) ($r['impressions'] ?? 0), (float) ($r['position'] ?? 0)];
			}
		}
		self::gsc_store($pages, 'sitekit', $from, $to);
		return true;
	}

	/**
	 * Rows from a Search Console export (Performance ▸ Pages ▸ Export: the
	 * ZIP, or its Pages.csv). Number of pages read, or WP_Error.
	 */
	public static function gsc_import_file($path, $name) {
		$csv = '';
		if (preg_match('/\.zip$/i', $name)) {
			if (!class_exists('ZipArchive')) {
				return new WP_Error('y4apk_zip', __('This server cannot open ZIP files: upload the Pages.csv from inside the ZIP instead.', 'y4apk'));
			}
			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				return new WP_Error('y4apk_zip', __('That ZIP file could not be opened.', 'y4apk'));
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry = (string) $zip->getNameIndex($i);
				if (preg_match('/(^|\/)(pages|หน้า)[^\/]*\.csv$/iu', $entry)) {
					$csv = (string) $zip->getFromIndex($i);
					break;
				}
			}
			$zip->close();
		} else {
			$csv = (string) file_get_contents($path);
		}
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
		$lines = preg_split('/\r\n|\n|\r/', trim($csv));
		if (!$csv || count($lines) < 2) {
			return new WP_Error('y4apk_csv', __('No pages found: export Performance ▸ Pages from Search Console and upload the ZIP or its Pages.csv.', 'y4apk'));
		}
		// Columns by name: "Top pages", "Clicks", "Impressions", "CTR", "Position" (in any language Search Console exports, the order is the same).
		$head = array_map('strtolower', str_getcsv(array_shift($lines)));
		$col = function ($names, $fallback) use ($head) {
			foreach ($head as $i => $h) {
				foreach ($names as $n) {
					if (strpos($h, $n) !== false) {
						return $i;
					}
				}
			}
			return $fallback;
		};
		$c_page = $col(['page', 'url', 'หน้า'], 0);
		$c_clicks = $col(['click', 'คลิก'], 1);
		$c_impr = $col(['impression', 'การแสดงผล'], 2);
		$c_pos = $col(['position', 'อันดับ', 'ตำแหน่ง'], 4);
		$num = function ($v) {
			return (float) str_replace([',', ' ', "\xC2\xA0"], '', (string) $v);
		};
		$pages = [];
		foreach ($lines as $line) {
			$r = str_getcsv($line);
			if (isset($r[$c_page]) && preg_match('~^https?://~i', $r[$c_page])) {
				$pages[] = [$r[$c_page], (int) $num($r[$c_clicks] ?? 0), (int) $num($r[$c_impr] ?? 0), $num($r[$c_pos] ?? 0)];
			}
		}
		if (!$pages) {
			return new WP_Error('y4apk_csv', __('No page addresses found in that file.', 'y4apk'));
		}
		self::gsc_store($pages, 'csv', '', '');
		return count($pages);
	}

	/** The post a page address belongs to, by its slug (its /download/N/ pages count for it too). */
	private static function gsc_slug($url) {
		$path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
		$base = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
		if ($base !== '' && strpos($path . '/', $base . '/') === 0) {
			$path = trim(substr($path, strlen($base)), '/');
		}
		$parts = array_values(array_filter(explode('/', $path), 'strlen'));
		if (count($parts) >= 2 && $parts[count($parts) - 2] === 'download' && ctype_digit($parts[count($parts) - 1])) {
			array_splice($parts, -2);
		}
		if ($parts && in_array(end($parts), ['amp', 'download'], true)) {
			array_pop($parts);
		}
		return $parts ? strtolower(rawurlencode(rawurldecode(end($parts)))) : '';
	}

	/** Match pages to posts and keep the totals per post. */
	private static function gsc_store($pages, $source, $from, $to) {
		global $wpdb;
		$by_slug = [];
		foreach ($pages as $p) {
			$slug = self::gsc_slug($p[0]);
			if ($slug !== '') {
				$by_slug[$slug][] = $p;
			}
		}
		$ids = [];
		foreach (array_chunk(array_keys($by_slug), 500) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '%s'));
			foreach ($wpdb->get_results($wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name IN ($in)", $chunk)) as $r) {
				$ids[strtolower($r->post_name)] = (int) $r->ID;
			}
		}
		$posts = [];
		$tries = 0;
		foreach ($by_slug as $slug => $rows) {
			$id = isset($ids[$slug]) ? $ids[$slug] : 0;
			if (!$id && $tries < 100) { // another permalink structure: ask WordPress, for a hundred at most
				$tries++;
				$id = url_to_postid($rows[0][0]);
				$id = $id && get_post_type($id) === 'post' ? $id : 0;
			}
			if (!$id) {
				continue;
			}
			foreach ($rows as $p) {
				$t = isset($posts[$id]) ? $posts[$id] : [0, 0, 0.0];
				// Average position, weighted by impressions.
				$t[2] = ($t[0] + $p[2]) > 0 ? ($t[2] * $t[0] + $p[3] * $p[2]) / ($t[0] + $p[2]) : 0;
				$t[0] += $p[2];
				$t[1] += $p[1];
				$posts[$id] = $t;
			}
		}
		foreach ($posts as $id => $t) {
			$posts[$id] = [$t[0], $t[1], round($t[2], 1)];
		}
		update_option(self::GSC, ['synced' => time(), 'source' => $source, 'from' => $from, 'to' => $to, 'rows' => count($pages), 'posts' => $posts], false);
	}

	/**
	 * Linked posts Google shows most — and posts people follow — whose version
	 * has not been checked for a day, most important first.
	 */
	public static function priority_ids($limit) {
		global $wpdb;
		$score = [];
		foreach (self::gsc()['posts'] as $id => $t) {
			$score[(int) $id] = (float) $t[0];
		}
		if (class_exists('Y4APK_Push')) {
			foreach (Y4APK_Push::most_followed(200) as $id => $n) {
				$score[$id] = (isset($score[$id]) ? $score[$id] : 0) + 500 * $n;
			}
		}
		if (!$score || $limit < 1) {
			return [];
		}
		arsort($score);
		$top = array_slice(array_keys($score), 0, 400);
		$in = implode(',', array_map('intval', $top));
		$linked = array_flip(array_map('intval', $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'wp_GP_ID' AND meta_value <> '' AND post_id IN ($in)")));
		$checked = [];
		foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in)", self::CHECKED)) as $r) {
			$checked[(int) $r->post_id] = (int) $r->meta_value;
		}
		$out = [];
		foreach ($top as $id) {
			if (isset($linked[$id]) && (!isset($checked[$id]) || $checked[$id] < time() - DAY_IN_SECONDS) && get_post_status($id) === 'publish' && !self::is_script_post($id)) {
				$out[] = $id;
				if (count($out) >= $limit) {
					break;
				}
			}
		}
		return $out;
	}

	public static function handle_gsc_sync() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-gsc-sync');
		$r = self::gsc_sync_sitekit();
		$back = ['page' => self::SLUG, 'view' => 'settings'];
		if (is_wp_error($r)) {
			set_transient('y4apk_gsc_error', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
			$back['y4apk_error'] = 'gsc';
		} else {
			// The daily sync reads as this administrator, whom Site Kit just let in.
			update_option('y4apk_gsc_user', get_current_user_id(), false);
			$back['y4apk_gsc'] = 1;
		}
		wp_safe_redirect(add_query_arg($back, admin_url('admin.php')));
		exit;
	}

	public static function handle_gsc_upload() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-gsc-upload');
		$back = ['page' => self::SLUG, 'view' => 'settings'];
		$file = isset($_FILES['gsc']) ? $_FILES['gsc'] : null;
		$r = $file && empty($file['error']) && is_uploaded_file($file['tmp_name'])
			? self::gsc_import_file($file['tmp_name'], (string) $file['name'])
			: new WP_Error('y4apk_csv', __('Choose the file Search Console exported.', 'y4apk'));
		if (is_wp_error($r)) {
			set_transient('y4apk_gsc_error', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
			$back['y4apk_error'] = 'gsc';
		} else {
			$back['y4apk_gsc'] = 1;
		}
		wp_safe_redirect(add_query_arg($back, admin_url('admin.php')));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Linking posts to Google Play
	 * ---------------------------------------------------------------- */

	/** "OTR 2 MOD APK v1.2.1 (Unlimited Money)" → "OTR 2". */
	public static function name_from_title($title) {
		$title = wp_strip_all_tags((string) $title);
		$cut = preg_split('/\s+(?:MOD\b|APK\b|v\d|\(|\[|[-–|]\s)/iu', $title, 2);
		return trim($cut[0]);
	}

	public static function norm($s) {
		return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')));
	}

	/** Same app? Exact name, and exact publisher when the post has one (else a name long enough not to be generic). */
	public static function same_app($name, $developer, $app) {
		$n = self::norm($name);
		if ($n === '' || $app['status'] !== 'ok' || self::norm($app['title']) !== $n) {
			return false;
		}
		return trim((string) $developer) !== ''
			? self::norm($app['developer']) === self::norm($developer)
			: mb_strlen($n) >= 6;
	}

	/** The words of a name that tell apps apart ("Steal An Egg Script - [Chilli HUB]" → steal, egg, chilli). */
	public static function words($s) {
		static $common = ['the', 'a', 'an', 'of', 'in', 'on', 'for', 'and', 'vs', 'to', 'mod', 'apk', 'script', 'scripts',
			'hub', 'key', 'no', 'free', 'get', 'download', 'premium', 'unlocked', 'unlimited', 'money', 'game', 'games',
			'app', 'apps', 'android', 'ios', 'latest', 'version', 'new'];
		$words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY);
		return array_values(array_unique(array_diff($words, $common)));
	}

	/** Worth suggesting: the Play name shares at least half of the post name's telling words. */
	public static function similar($name, $title) {
		$a = self::words($name);
		return $a && count(array_intersect($a, self::words($title))) / count($a) >= 0.5;
	}

	/** Posts without a Google Play ID, by link state (null = not looked at yet), newest first. */
	private static function unlinked($state, $limit) {
		global $wpdb;
		$where = $state === null ? 'l.post_id IS NULL' : $wpdb->prepare('l.meta_value = %s', $state);
		return array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'wp_GP_ID'
			 LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = %s
			 WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_parent = 0
			   AND (g.meta_value IS NULL OR g.meta_value = '') AND $where AND NOT " . self::scripts_sql('p') . "
			 ORDER BY p.ID DESC LIMIT %d", self::LINK, $limit)));
	}

	private static function link($post_id, $package, $how) {
		update_post_meta($post_id, 'wp_GP_ID', $package);
		update_post_meta($post_id, self::LINK, $how);
		delete_post_meta($post_id, self::SUGGEST);
	}

	/** Step 1: a Google Play link in the post itself. No requests. */
	public static function scan_content($post_id) {
		$package = self::package_from(get_post_field('post_content', $post_id));
		if ($package !== '') {
			self::link($post_id, $package, 'content');
		} else {
			update_post_meta($post_id, self::LINK, 'scanned');
		}
	}

	/** Step 2: search Google Play for the App Name. true / false, or null to try again later. */
	public static function search_post($post_id) {
		$name = trim((string) get_post_meta($post_id, 'wp_title_GP', true));
		$named = $name !== '';
		if (!$named) {
			$name = self::name_from_title(get_post_field('post_title', $post_id));
		}
		if ($name === '') {
			update_post_meta($post_id, self::LINK, 'nomatch');
			return false;
		}
		$developer = $named ? (string) get_post_meta($post_id, 'wp_developers_GP', true) : '';

		$r = self::get('https://play.google.com/store/search?q=' . rawurlencode($name) . '&c=apps&hl=en&gl=US');
		if ($r === null || $r[0] === 429 || $r[0] >= 500) {
			return null;
		}
		preg_match_all('~/store/apps/details\?id=([A-Za-z][\w.]*)~', $r[1], $m);
		$candidates = array_slice(array_values(array_unique($m[1])), 0, 2);
		if (!$candidates) {
			update_post_meta($post_id, self::LINK, 'nomatch');
			return false;
		}
		$first = null;
		foreach ($candidates as $package) {
			$app = self::play_app($package);
			if ($app['status'] === 'busy') {
				return null;
			}
			// Linked without asking only with the post's own App Name — never a guess from the title.
			if ($named && self::same_app($name, $developer, $app)) {
				self::link($post_id, $package, 'search');
				return true;
			}
			if ($first === null && $app['status'] === 'ok' && self::similar($name, $app['title'])) {
				$first = ['id' => $package, 'title' => $app['title'], 'developer' => $app['developer']];
			}
		}
		if ($first) {
			update_post_meta($post_id, self::SUGGEST, wp_slash(wp_json_encode($first)));
			update_post_meta($post_id, self::LINK, 'suggested');
		} else {
			update_post_meta($post_id, self::LINK, 'nomatch');
		}
		return false;
	}

	/**
	 * An app gone from its Google Play ID may be there under a new one: the
	 * listing with exactly this name (and developer, when known), not $not.
	 * Its package, '' when there is none, null when Google is busy.
	 */
	public static function find_listing($name, $developer, $not = '') {
		$name = trim(html_entity_decode(wp_strip_all_tags((string) $name), ENT_QUOTES, 'UTF-8'));
		if ($name === '') {
			return '';
		}
		$r = self::get('https://play.google.com/store/search?q=' . rawurlencode($name) . '&c=apps&hl=en&gl=US');
		if ($r === null || $r[0] === 429 || $r[0] >= 500) {
			return null;
		}
		preg_match_all('~/store/apps/details\?id=([A-Za-z][\w.]*)~', $r[1], $m);
		$candidates = array_slice(array_values(array_diff(array_unique($m[1]), [$not])), 0, 3);
		foreach ($candidates as $package) {
			$app = self::play_app($package);
			if ($app['status'] === 'busy') {
				return null;
			}
			if (self::same_app($name, html_entity_decode((string) $developer, ENT_QUOTES, 'UTF-8'), $app)) {
				return $package;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- *
	 * The background step
	 * ---------------------------------------------------------------- */

	public static function tick() {
		$done = ['scanned' => 0, 'searched' => 0, 'checked' => 0, 'busy' => false];
		if (!self::enabled() || get_transient('y4apk_vc_lock')) {
			return $done;
		}
		set_transient('y4apk_vc_lock', 1, 5 * MINUTE_IN_SECONDS);
		self::$icon_budget = self::ICON_BATCH;

		$scan = self::unlinked(null, self::SCAN_BATCH);
		if ($scan) {
			_prime_post_caches($scan, false, false); // their content in one query
		}
		foreach ($scan as $id) {
			self::scan_content($id);
			$done['scanned']++;
		}

		foreach (self::unlinked('scanned', self::SEARCH_BATCH) as $id) {
			if (self::search_post($id) === null) {
				$done['busy'] = true;
				break; // Google is busy: no more requests this step
			}
			$done['searched']++;
		}

		// Important posts first: checked at least daily, not once a round.
		$done['priority'] = 0;
		if (!$done['busy']) {
			foreach (self::priority_ids(self::PRIORITY) as $id) {
				if (self::check_post($id) === null) {
					$done['busy'] = true;
					break;
				}
				$done['priority']++;
				$done['checked']++;
			}
		}

		if (!$done['busy']) {
			global $wpdb;
			$cursor = (int) get_option('y4apk_vc_cursor', 0);
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND pm.post_id > %d
				   AND p.post_type = 'post' AND p.post_status = 'publish' AND NOT " . self::scripts_sql('p') . "
				 ORDER BY pm.post_id LIMIT %d", $cursor, self::BATCH - $done['priority']));
			$all = true;
			foreach ($ids as $id) {
				$r = self::check_post((int) $id);
				if ($r === null) {
					$done['busy'] = true;
					$all = false;
					break; // pick up here next time
				}
				$cursor = (int) $id;
				$done['checked']++;
			}
			if ($all && count($ids) < self::BATCH - $done['priority']) {
				$cursor = 0; // round finished
				update_option('y4apk_vc_round_done', time(), false);
			}
			update_option('y4apk_vc_cursor', $cursor, false);
		}

		// Posts already known to be behind Google Play (checked before 2.50, or
		// with the setting just switched on): their version, from what is known.
		$done['followed'] = self::follow_known(self::FOLLOW_BATCH);

		// Google Search numbers once a day, as the administrator who first synced them.
		$gsc_user = (int) get_option('y4apk_gsc_user', 0);
		if ($gsc_user && self::sitekit_active() && self::gsc()['source'] !== 'csv' && self::gsc()['synced'] < time() - 20 * HOUR_IN_SECONDS
			&& (int) get_option('y4apk_gsc_tried', 0) < time() - HOUR_IN_SECONDS) {
			update_option('y4apk_gsc_tried', time(), false);
			$r = self::gsc_sync_sitekit($gsc_user);
			$done['gsc'] = !is_wp_error($r);
		}

		update_option('y4apk_vc_last_step', time(), false);
		delete_transient('y4apk_vc_lock');
		self::$icon_budget = null;
		return $done;
	}

	/** Up to $n posts that need a new file, brought up to Google Play's version from the stored check (no requests). */
	public static function follow_known($n) {
		if (!self::auto_on('version')) {
			return 0;
		}
		global $wpdb;
		$cursor = (int) get_option('y4apk_vc_follow_cursor', 0);
		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT n.post_id FROM {$wpdb->postmeta} n JOIN {$wpdb->postmeta} s ON s.post_id = n.post_id AND s.meta_key = %s AND s.meta_value = 'play'
			 WHERE n.meta_key = %s AND n.post_id > %d ORDER BY n.post_id LIMIT %d", self::SOURCE, self::NEEDS, $cursor, $n)));
		$changed = 0;
		foreach ($ids as $id) {
			if (get_post_status($id) === 'publish' && self::follow_version($id, (string) get_post_meta($id, self::LATEST, true), 'play')) {
				$changed++;
			}
		}
		update_option('y4apk_vc_follow_cursor', count($ids) < $n ? 0 : end($ids), false);
		return $changed;
	}

	/* ---------------------------------------------------------------- *
	 * Posts list: column, "Needs update" view, "Check version now"
	 * ---------------------------------------------------------------- */

	public static function check_notice() {
		if (empty($_GET['y4apk_checked'])) {
			return;
		}
		echo $_GET['y4apk_checked'] === 'busy'
			? '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Google Play did not answer. Try again in a minute.', 'y4apk') . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Version checked — see the Latest version column.', 'y4apk') . '</p></div>';
	}

	public static function column($columns) {
		$out = [];
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ($key === 'title') {
				$out['y4apk_latest'] = __('Latest version', 'y4apk');
			}
		}
		return $out;
	}

	public static function column_value($column, $post_id) {
		if ($column !== 'y4apk_latest') {
			return;
		}
		if (!get_post_meta($post_id, 'wp_GP_ID', true)) {
			$state = get_post_meta($post_id, self::LINK, true);
			$label = $state === 'suggested' ? __('Suggestion to confirm', 'y4apk')
				: ($state === 'nomatch' || $state === 'rejected' ? __('Not found on Google Play', 'y4apk')
				: ($state === 'scanned' ? __('Searching Google Play…', 'y4apk') : __('No Google Play ID yet', 'y4apk')));
			echo '<span style="color:#8c8f94">' . esc_html($label) . '</span>';
			return;
		}
		$checked = (int) get_post_meta($post_id, self::CHECKED, true);
		if (!$checked) {
			echo '<span style="color:#8c8f94">' . esc_html__('Not checked yet', 'y4apk') . '</span>';
			return;
		}
		$latest = (string) get_post_meta($post_id, self::LATEST, true);
		$source = get_post_meta($post_id, self::SOURCE, true) === 'aptoide' ? 'Aptoide' : 'Google Play';
		if ($latest === '') {
			echo '<span style="color:#8c8f94">' . esc_html(get_post_meta($post_id, self::SOURCE, true) === 'varies'
				? __('Google Play shows “Varies with device”', 'y4apk')
				: __('No version shown', 'y4apk')) . '</span>';
		} elseif (get_post_meta($post_id, self::NEEDS, true)) {
			echo '<strong style="color:#b32d2e">' . esc_html($latest) . '</strong><br><span style="color:#b32d2e">' . esc_html__('Newer than your file', 'y4apk') . '</span>';
		} else {
			echo esc_html($latest) . '<br><span style="color:#008a20">✓ ' . esc_html__('Up to date', 'y4apk') . '</span>';
		}
		echo '<br><small style="color:#8c8f94">' . esc_html(sprintf(__('%1$s, checked %2$s ago', 'y4apk'), $source, human_time_diff($checked))) . '</small>';
	}

	private static function count_meta($key, $value = null) {
		global $wpdb;
		return (int) ($value === null
			? $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $key))
			: $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $key, $value)));
	}

	public static function view_link($views) {
		$count = self::count_meta(self::NEEDS);
		if ($count) {
			$views['y4apk_needs_update'] = '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
				. esc_html__('Needs update', 'y4apk') . ' <span class="count">(' . number_format_i18n($count) . ')</span></a>';
		}
		return $views;
	}

	public static function filter_list($query) {
		if (is_admin() && $query->is_main_query() && !empty($_GET['y4apk_needs_update'])) {
			$query->set('meta_key', self::NEEDS);
		}
	}

	public static function row_action($actions, $post) {
		if ($post->post_type === 'post' && $post->post_status === 'publish' && current_user_can('edit_post', $post->ID) && !$post->post_parent
			&& get_post_meta($post->ID, 'repeatable_download_link', true) && !self::is_script_post($post->ID)) {
			$actions['y4apk_file'] = '<a href="' . esc_url(add_query_arg(['page' => self::SLUG, 'view' => 'file', 'post' => $post->ID], admin_url('admin.php'))) . '">'
				. esc_html__('Update file', 'y4apk') . '</a>';
		}
		if ($post->post_type === 'post' && get_post_meta($post->ID, 'wp_GP_ID', true) && current_user_can('edit_post', $post->ID)) {
			$actions['y4apk_check'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_vc_check&post=' . $post->ID), 'y4apk-vc-check-' . $post->ID)) . '">'
				. esc_html__('Check version now', 'y4apk') . '</a>';
		}
		return $actions;
	}

	/** The editor's "File and Google Play" box (2.50): the file's version against Google Play's, and a way to upload a new one any time. */
	public static function meta_box($post) {
		if ($post->post_status === 'auto-draft' || self::is_script_post($post->ID) || get_post_meta($post->ID, '_y4apk_parent', true)) {
			return;
		}
		add_meta_box('y4apk-file', __('File and Google Play', 'y4apk'), [__CLASS__, 'meta_box_html'], 'post', 'side', 'high');
	}

	public static function meta_box_html($post) {
		list($i, $link) = self::download($post->ID);
		$file = $i !== null ? self::current_version($post->ID) : '';
		$package = (string) get_post_meta($post->ID, 'wp_GP_ID', true);
		$latest = (string) get_post_meta($post->ID, self::LATEST, true);
		$checked = (int) get_post_meta($post->ID, self::CHECKED, true);
		$behind = $i !== null && self::needs_update($post->ID);
		$row = 'display:flex;justify-content:space-between;gap:8px;margin:0 0 6px';
		echo '<p style="' . esc_attr($row) . '"><span>' . esc_html__('Your file', 'y4apk') . '</span><strong>'
			. esc_html($i === null ? __('No download yet', 'y4apk') : trim(($link['download_name'] ?? '') . ' ' . ($file !== '' ? 'v' . ltrim($file, 'vV') : ''))) . '</strong></p>';
		if ($package === '') {
			echo '<p style="' . esc_attr($row) . '"><span>' . esc_html__('Google Play', 'y4apk') . '</span><span style="color:#8c8f94">' . esc_html__('Not linked', 'y4apk') . '</span></p>';
		} else {
			$state = $latest === '' ? '<span style="color:#8c8f94">' . esc_html($checked ? __('No version shown', 'y4apk') : __('Not checked yet', 'y4apk')) . '</span>'
				: ($behind ? '<strong style="color:#b32d2e">v' . esc_html(ltrim($latest, 'vV')) . ' · ' . esc_html__('newer', 'y4apk') . '</strong>'
				: '<span style="color:#008a20">v' . esc_html(ltrim($latest, 'vV')) . ' ✓</span>');
			echo '<p style="' . esc_attr($row) . '"><span>' . esc_html__('Google Play', 'y4apk') . '</span>' . $state . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
			if ($checked) {
				echo '<p style="margin:0 0 10px;color:#8c8f94;font-size:12px">' . esc_html(sprintf(__('Checked %s ago', 'y4apk'), human_time_diff($checked))) . '</p>';
			}
		}
		if ($i !== null) {
			echo '<p style="margin:0 0 8px"><a class="button' . ($behind ? ' button-primary' : '') . '" style="width:100%;text-align:center" target="_blank" href="'
				. esc_url(add_query_arg(['page' => self::SLUG, 'view' => 'file', 'post' => $post->ID], admin_url('admin.php'))) . '">' . esc_html__('Update the file', 'y4apk') . '</a></p>';
		}
		if ($package !== '') {
			echo '<p style="margin:0"><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_vc_check&post=' . $post->ID), 'y4apk-vc-check-' . $post->ID)) . '">'
				. esc_html__('Check Google Play now', 'y4apk') . '</a></p>';
		}
	}

	public static function handle_check() {
		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		check_admin_referer('y4apk-vc-check-' . $post_id);
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		$done = self::check_post($post_id);
		$back = wp_get_referer() ? wp_get_referer() : admin_url('edit.php');
		wp_safe_redirect(add_query_arg('y4apk_checked', $done === null ? 'busy' : 'done', remove_query_arg('y4apk_checked', $back)));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ App updates
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page(Y4APK_MENU, __('App updates', 'y4apk'), __('App updates', 'y4apk'),
			'edit_posts', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-vc-settings');
		update_option('y4apk_vc_on', empty($_POST['on']) ? '0' : '1', true);
		$country = strtoupper(sanitize_text_field(wp_unslash(isset($_POST['country']) ? $_POST['country'] : '')));
		update_option('y4apk_vc_country', preg_match('/^[A-Z]{2}$/', $country) ? $country : 'TH', false);
		$info = [];
		foreach (array_keys(self::info_fields()) as $key) {
			$info[$key] = empty($_POST['info'][$key]) ? 0 : 1;
		}
		update_option('y4apk_vc_info', $info, false);
		$parts = [];
		foreach (array_keys(self::post_parts()) as $key) {
			$parts[$key] = empty($_POST['parts'][$key]) ? 0 : 1;
		}
		update_option('y4apk_post_parts', $parts, true);
		$auto = [];
		foreach (array_keys(self::auto_parts()) as $key) {
			$auto[$key] = empty($_POST['auto'][$key]) ? 0 : 1;
		}
		update_option('y4apk_vc_auto', $auto, false);
		self::schedule();
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'view' => 'settings', 'y4apk_settings' => 1], admin_url('admin.php')));
		exit;
	}

	public static function handle_run() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-vc-run');
		delete_transient('y4apk_vc_lock');
		$done = self::tick();
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'ran' => implode('.', [$done['scanned'], $done['searched'], $done['checked'], (int) $done['busy']])], admin_url('admin.php')));
		exit;
	}

	/** Use / not this / a typed ID, for a post's Google Play link. */
	public static function handle_link() {
		$post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		check_admin_referer('y4apk-vc-link-' . $post_id);
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		$in = wp_unslash($_POST);
		$back = add_query_arg(['page' => self::SLUG, 'view' => 'suggest'], admin_url('admin.php'));
		if (!empty($in['reject'])) {
			delete_post_meta($post_id, self::SUGGEST);
			update_post_meta($post_id, self::LINK, 'rejected');
		} else {
			$package = self::package_from(isset($in['id']) ? $in['id'] : '');
			if ($package === '') {
				wp_safe_redirect(add_query_arg('y4apk_error', 'id', $back));
				exit;
			}
			self::link($post_id, $package, 'confirmed');
			self::check_post($post_id);
		}
		wp_safe_redirect($back);
		exit;
	}

	/** "Not on Google Play" for the ticked suggestions. */
	public static function handle_bulk() {
		check_admin_referer('y4apk-vc-bulk');
		foreach (array_map('absint', isset($_POST['posts']) ? (array) $_POST['posts'] : []) as $post_id) {
			if ($post_id && current_user_can('edit_post', $post_id)) {
				delete_post_meta($post_id, self::SUGGEST);
				update_post_meta($post_id, self::LINK, 'rejected');
			}
		}
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'view' => 'suggest'], admin_url('admin.php')));
		exit;
	}

	/** Save a new file for one post. */
	public static function handle_save() {
		$post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		check_admin_referer('y4apk-vc-save-' . $post_id);
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		// 2.60.1: a dropped connection never leaves a half-done save
		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$in = wp_unslash($_POST);
		$version = sanitize_text_field(isset($in['version']) ? $in['version'] : '');
		$size = sanitize_text_field(isset($in['size']) ? $in['size'] : '');
		// The same Save sent again (the browser does after an error page): it went through the first time
		$once = 'y4apk_vc_saved_' . md5($post_id . '|' . (isset($in['sig']) ? (string) $in['sig'] : '') . '|' . (isset($in['r2']) ? (string) $in['r2'] : '') . '|' . $version);
		$url = esc_url_raw(isset($in['url']) ? trim($in['url']) : '');
		$back = isset($in['back']) && $in['back'] === 'file'
			? add_query_arg(['page' => self::SLUG, 'view' => 'file', 'post' => $post_id], admin_url('admin.php'))
			: add_query_arg(['page' => self::SLUG, 'paged' => isset($in['paged']) ? absint($in['paged']) : 1], admin_url('admin.php'));

		$add = isset($in['mode']) && $in['mode'] === 'add';

		list($index, $link) = self::download($post_id);
		// 2.50.1: no download yet — the upload makes its folder, and here its row
		$fresh = $index === null;
		if ($fresh) {
			$type = strtoupper(sanitize_text_field(isset($in['type']) ? $in['type'] : 'MOD'));
			$type = in_array($type, self::NEW_TYPES, true) ? $type : 'MOD';
			$have = get_post_meta($post_id, 'repeatable_download_link', true);
			$index = is_array($have) && $have ? max(array_map('intval', array_keys($have))) + 1 : 0;
			$link = ['download_name' => $type, 'download_version' => '', 'download_size' => '', 'download_url' => '',
				'download_mod_info' => class_exists('Y4APK_Writer') ? Y4APK_Writer::mod($post_id) : ''];
			$add = false;
			if ($version === '' || ($url === '' && empty($in['r2']))) {
				wp_safe_redirect(add_query_arg('y4apk_error', 'nofile', $back));
				exit;
			}
		}

		// 2.50.4: the post's other downloads as the form has them — changed,
		// uploaded again, or taken off — and files added on the page.
		$others = self::other_downloads($post_id);
		$rows = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [];
		$drops = [];
		foreach ($others['same'] as $i => $o) {
			if (!empty($rows[$i]['del'])) {
				$drops[$i] = true;
			}
		}
		$kept_in = isset($in['kept']) && is_array($in['kept']) ? $in['kept'] : [];
		$edits = [];
		$edit_up = [];
		foreach ($others['kept'] as $i => $o) {
			$r = isset($kept_in[$i]) && is_array($kept_in[$i]) ? $kept_in[$i] : null;
			if (!$r) {
				continue;
			}
			if (!empty($r['del'])) {
				$drops[$i] = true;
				continue;
			}
			$e = [];
			foreach (['version' => 'download_version', 'size' => 'download_size'] as $f => $k) {
				$val = sanitize_text_field(isset($r[$f]) ? $r[$f] : '');
				if ($val !== '' && $val !== trim((string) (isset($o[$k]) ? $o[$k] : ''))) {
					$e[$k] = $val;
				}
			}
			$u = esc_url_raw(isset($r['url']) ? trim($r['url']) : '');
			if ($u !== '' && $u !== html_entity_decode((string) (isset($o['download_url']) ? $o['download_url'] : ''))) {
				$e['download_url'] = $u;
			}
			$t = strtoupper(sanitize_text_field(isset($r['type']) ? $r['type'] : ''));
			if ($t !== '' && strcasecmp($t, trim((string) (isset($o['download_name']) ? $o['download_name'] : ''))) !== 0 && in_array($t, self::NEW_TYPES, true)) {
				$e['download_name'] = $t;
			}
			if (!empty($r['r2'])) {
				$edit_up[$i] = sanitize_text_field($r['r2']);
			}
			if ($e) {
				$edits[$i] = $e;
			}
		}
		$extras = [];
		foreach (array_slice(isset($in['extra']) && is_array($in['extra']) ? $in['extra'] : [], 0, 10) as $r) {
			if (!is_array($r)) {
				continue;
			}
			$k = sanitize_text_field(isset($r['r2']) ? $r['r2'] : '');
			$u = esc_url_raw(isset($r['url']) ? trim($r['url']) : '');
			if ($k === '' && $u === '') {
				continue; // a line left empty
			}
			$t = strtoupper(sanitize_text_field(isset($r['type']) ? $r['type'] : ''));
			$v = sanitize_text_field(isset($r['version']) ? $r['version'] : '');
			if ($v === '') {
				wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
				exit;
			}
			$extras[] = ['r2' => $k, 'row' => ['download_name' => in_array($t, self::NEW_TYPES, true) ? $t : 'MOD', 'download_version' => $v,
				'download_size' => sanitize_text_field(isset($r['size']) ? $r['size'] : ''), 'download_url' => $u,
				'download_mod_info' => isset($link['download_mod_info']) ? $link['download_mod_info'] : '']];
		}
		// The new file is saved when it was touched — or when nothing else was (Save as before)
		$main_out = !$fresh && self::outside_link($link) && empty($in['r2']) && empty($in['main_dirty']);
		$touch_main = $fresh || !empty($in['main_dirty']) || !empty($in['r2']) || (!$drops && !$edits && !$edit_up && !$extras && !$main_out);
		if (!$touch_main && !$fresh && self::outside_link($link) && empty($in['r2'])) {
			$drops[$index] = true; // its link is another site's and no file came for it: off, waiting for yours
		}

		if ($touch_main && !$fresh && ($version === '' || ($url === '' && empty($in['r2'])))) {
			// 2.50.2: an uploaded file is enough: its download link is the post's folder, or a new one
			wp_safe_redirect(add_query_arg('y4apk_error', self::no_link($link) ? 'nofile' : 'missing', $back));
			exit;
		}
		if (!isset($in['sig']) || $in['sig'] !== self::links_sig($post_id)) {
			wp_safe_redirect(get_transient($once) ? add_query_arg('y4apk_saved', $post_id, $back) : add_query_arg('y4apk_error', 'changed', $back));
			exit;
		}

		// The APK download, and each ticked download with the same version;
		// with a file uploaded to R2 for it, its key.
		$updates = [$index => ['download_version' => $version, 'download_size' => $size, 'download_url' => $url]];
		if (!$fresh) {
			// 2.50.3: the type chosen in the form (the post's own one, or one of the usual)
			$was = trim((string) (isset($link['download_name']) ? $link['download_name'] : ''));
			$pick = trim(sanitize_text_field(isset($in['type']) ? $in['type'] : ''));
			if ($pick !== '' && strcasecmp($pick, $was) !== 0 && in_array(strtoupper($pick), self::NEW_TYPES, true)) {
				$updates[$index]['download_name'] = strtoupper($pick);
			}
		}
		$uploads = [];
		if (!empty($in['r2'])) {
			$uploads[$index] = sanitize_text_field($in['r2']);
		}
		foreach ($others['same'] as $i => $other) {
			if (empty($rows[$i]['on']) || isset($drops[$i])) {
				continue;
			}
			$r = $rows[$i];
			$updates[$i] = [
				'download_version' => sanitize_text_field(isset($r['version']) ? $r['version'] : ''),
				'download_size'    => sanitize_text_field(isset($r['size']) ? $r['size'] : ''),
				'download_url'     => esc_url_raw(isset($r['url']) ? trim($r['url']) : ''),
			];
			if ($updates[$i]['download_version'] === '' || ($updates[$i]['download_url'] === '' && empty($r['r2']))) {
				wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
				exit;
			}
			if (!empty($r['r2'])) {
				$uploads[$i] = sanitize_text_field($r['r2']);
			}
		}

		if (!$touch_main) {
			$updates = [];
			$uploads = [];
		}

		// Replace: the new files take the old ones' place. Add: each new file
		// goes just above the one it follows, which stays as an older version
		// — so it needs another link (an upload makes one).
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$links = is_array($links) ? $links : [];
		if ($fresh) {
			$links[$index] = $link; // the post's first download
		}
		foreach ($updates as $i => $u) {
			if ($add && !isset($uploads[$i]) && $u['download_url'] === html_entity_decode((string) (isset($links[$i]['download_url']) ? $links[$i]['download_url'] : ''))) {
				wp_safe_redirect(add_query_arg('y4apk_error', 'same-link', $back));
				exit;
			}
		}

		// 2.50.5: each uploaded file named on R2 for the version and type saved
		// with it (the upload starts as soon as the file is picked, with what the
		// boxes said then): "…-MOD-v1.7.7-…" becomes "…-MOD-v1.7.3-…".
		$notes = [];
		$later = []; // 2.60.1: big files, renamed once Save has answered: key => ['to', 'size', 'folder']
		$named = function ($key, $type, $version, $folder = '') use (&$notes, &$later) {
			if (!class_exists('Y4APK_R2')) {
				return $key;
			}
			$to = Y4APK_R2::key_for($key, $type, $version);
			if ($to === $key) {
				return $key;
			}
			$done = get_transient('y4apk_r2_done_' . md5($key));
			$bytes = is_array($done) ? (float) $done['size'] : 0;
			if ($bytes <= 0 || $bytes > Y4APK_R2::RENAME_NOW) {
				$later[$key] = ['to' => $to, 'size' => $bytes, 'folder' => $folder];
				return $key; // under its first name for now
			}
			$moved = Y4APK_R2::move($key, $to, is_array($done) ? $done['size'] : 0);
			if (is_wp_error($moved)) {
				$notes[] = $moved->get_error_message();
				return $key; // saved under its first name
			}
			if (is_array($done)) {
				delete_transient('y4apk_r2_done_' . md5($key));
				$done['key'] = $to;
				set_transient('y4apk_r2_done_' . md5($to), $done, 3 * DAY_IN_SECONDS);
			}
			return $to;
		};
		foreach ($uploads as $i => $key) {
			$uploads[$i] = $named($key, isset($updates[$i]['download_name']) ? $updates[$i]['download_name'] : (isset($links[$i]['download_name']) ? $links[$i]['download_name'] : 'MOD'),
				isset($updates[$i]['download_version']) ? $updates[$i]['download_version'] : '');
		}
		foreach ($edit_up as $i => $key) {
			$edit_up[$i] = $named($key, isset($edits[$i]['download_name']) ? $edits[$i]['download_name'] : (isset($links[$i]['download_name']) ? $links[$i]['download_name'] : 'MOD'),
				isset($edits[$i]['download_version']) ? $edits[$i]['download_version'] : (isset($links[$i]['download_version']) ? $links[$i]['download_version'] : ''));
		}
		foreach ($extras as $n => $x) {
			if ($x['r2'] !== '') {
				$extras[$n]['r2'] = $named($x['r2'], $x['row']['download_name'], $x['row']['download_version']);
			}
		}
		// A file uploaded before, saved again with another version: its name follows too
		if ($touch_main && !$fresh && !isset($uploads[$index]) && class_exists('Y4APK_R2') && isset($updates[$index])) {
			$folder = Y4APK_R2::folder_of(html_entity_decode((string) $updates[$index]['download_url']));
			$was = $folder !== '' ? Y4APK_R2::folder_key($folder) : '';
			if ($was !== '') {
				$now = $named($was, isset($updates[$index]['download_name']) ? $updates[$index]['download_name'] : (isset($link['download_name']) ? $link['download_name'] : 'MOD'), $updates[$index]['download_version'], $folder);
				if ($now !== $was) {
					$pointed = Y4APK_R2::point_folder($folder, Y4APK_R2::public_url($now));
					if (is_wp_error($pointed)) {
						$notes[] = $pointed->get_error_message();
					}
				}
			}
		}

		// Uploaded files: point the download pages at them, now that the form is good.
		foreach ($uploads as $i => $key) {
			$r = class_exists('Y4APK_R2')
				? Y4APK_R2::attach($post_id, $key, html_entity_decode((string) $links[$i]['download_url']), isset($updates[$i]['download_name']) ? $updates[$i]['download_name'] : (isset($links[$i]['download_name']) ? $links[$i]['download_name'] : 'MOD'), !$add)
				: new WP_Error('y4apk_r2', 'R2 uploads are not available.');
			if (is_wp_error($r)) {
				set_transient('y4apk_upload_error', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
				wp_safe_redirect(add_query_arg('y4apk_error', 'upload', $back));
				exit;
			}
			$updates[$i]['download_url'] = $r['url'];
			$updates[$i]['download_size'] = $r['size'];
			if ($i === $index) {
				$size = $r['size'];
			}
			if (isset($later[$key])) {
				$later[$key]['folder'] = $r['folder'];
			}
		}

		// 2.50.4: another download uploaded again keeps its folder; an added file gets its own
		foreach ($edit_up as $i => $key) {
			$name = isset($edits[$i]['download_name']) ? $edits[$i]['download_name'] : (isset($links[$i]['download_name']) ? $links[$i]['download_name'] : 'MOD');
			$r = class_exists('Y4APK_R2') ? Y4APK_R2::attach($post_id, $key, html_entity_decode((string) $links[$i]['download_url']), $name, true) : new WP_Error('y4apk_r2', 'R2 uploads are not available.');
			if (is_wp_error($r)) {
				set_transient('y4apk_upload_error', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
				wp_safe_redirect(add_query_arg('y4apk_error', 'upload', $back));
				exit;
			}
			$edits[$i] = array_merge(isset($edits[$i]) ? $edits[$i] : [], ['download_url' => $r['url'], 'download_size' => $r['size']]);
			if (isset($later[$key])) {
				$later[$key]['folder'] = $r['folder'];
			}
		}
		foreach ($extras as $n => $x) {
			if ($x['r2'] === '') {
				continue;
			}
			$r = class_exists('Y4APK_R2') ? Y4APK_R2::attach($post_id, $x['r2'], '', $x['row']['download_name'], false) : new WP_Error('y4apk_r2', 'R2 uploads are not available.');
			if (is_wp_error($r)) {
				set_transient('y4apk_upload_error', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
				wp_safe_redirect(add_query_arg('y4apk_error', 'upload', $back));
				exit;
			}
			$extras[$n]['row']['download_url'] = $r['url'];
			$extras[$n]['row']['download_size'] = $r['size'];
			if (isset($later[$x['r2']])) {
				$later[$x['r2']]['folder'] = $r['folder'];
			}
		}

		// A folder Write with AI made has its file now (or the post another link).
		if (isset($updates[$index]) && (isset($uploads[$index]) || $updates[$index]['download_url'] !== (string) get_post_meta($post_id, self::NO_FILE, true))) {
			delete_post_meta($post_id, self::NO_FILE);
		}

		$new_links = [];
		$placed = false;
		$gone = [];
		$pos = 0;
		foreach ($links as $i => $entry) {
			$at = $pos++;
			if (isset($drops[$i])) {
				if (is_array($entry) && self::outside_link($entry)) {
					$gone[] = ['at' => $at, 'row' => $entry]; // another site's: kept, Put them back can return it
				}
				continue; // taken off the post (its file stays on R2)
			}
			if (isset($edits[$i])) {
				$new_links[] = array_merge($entry, $edits[$i]);
			} elseif (!isset($updates[$i])) {
				$new_links[] = $entry;
			} else {
				$u = $updates[$i];
				if ($u['download_size'] === '') {
					unset($u['download_size']);
				}
				$new_links[] = array_merge($entry, $u);
			}
			if ($i === $index && !$placed) {
				foreach ($extras as $x) {
					$new_links[] = $x['row']; // added files: just under the new one
				}
				$placed = true;
			}
			if (isset($updates[$i]) && $add) {
				$new_links[] = $entry;
			}
		}
		if (!$placed) {
			foreach ($extras as $x) {
				$new_links[] = $x['row'];
			}
		}
		if ($gone) {
			$prev = get_post_meta($post_id, self::OUTSIDE, true);
			update_post_meta($post_id, self::OUTSIDE, array_merge(is_array($prev) ? $prev : [], $gone));
			delete_transient('y4apk_outside_scan');
		}
		self::file_came($post_id, $new_links);
		if ($notes) {
			set_transient('y4apk_upload_note', implode(' ', $notes), 5 * MINUTE_IN_SECONDS);
			$back = add_query_arg('y4apk_note', 1, $back);
		}
		if (!$touch_main) {
			// Only the other downloads changed: the post's version and title stay
			update_post_meta($post_id, 'repeatable_download_link', $new_links);
			self::refresh_flag($post_id);
			if (class_exists('Y4APK_Versions')) {
				Y4APK_Versions::purge($post_id);
			}
			self::saved_then_rename($once, add_query_arg('y4apk_saved', $post_id, $back), $later);
		}

		$old = self::number(isset($link['download_version']) ? $link['download_version'] : '');
		$new = self::number($version);
		$shown = self::number(get_post_meta($post_id, 'wp_version_GP', true)); // may be ahead of the file (2.50: it follows Google Play)
		if ($size !== '') {
			update_post_meta($post_id, 'wp_sizes_GP', $size);
		}
		update_post_meta($post_id, 'repeatable_download_link', $new_links);
		// A post already showing Google Play's newer version keeps it (the file catches up later).
		$ahead = $shown !== '' && $new !== '' && self::auto_on('version') && version_compare($shown, $new, '>');
		if ($new !== '' && !$ahead) {
			update_post_meta($post_id, 'wp_version_GP', $new);
		}

		// The version follows in the title and the Rank Math SEO title,
		// description and social titles, when the old one is plainly there
		// ("v2.742" → "v2.750"); a bare number like "1" is left alone. The one
		// the post shows first (it may already be Google Play's), then the file's.
		$args = ['ID' => $post_id];
		if ($new !== '' && !$ahead) {
			self::put_version($post_id, [$shown, $old], $new, $args);
		}
		self::update_quietly($args); // also marks the post as just updated
		self::refresh_flag($post_id);

		self::saved_then_rename($once, add_query_arg('y4apk_saved', $post_id, $back), $later);
	}

	/**
	 * Save's end (2.60.1): noted as done (so the same Save sent again says
	 * saved), the page answered at once, then the big files renamed for their
	 * version — after the answer, so no Cloudflare 520 however big they are.
	 */
	private static function saved_then_rename($once, $url, $later) {
		set_transient($once, 1, 15 * MINUTE_IN_SECONDS);
		$later = array_filter((array) $later, function ($l) {
			return !empty($l['folder']);
		});
		if (!$later || !class_exists('Y4APK_R2')) {
			wp_safe_redirect($url);
			exit;
		}
		foreach ($later as $from => $l) {
			Y4APK_R2::rename_later($from, $l['to'], $l['size'], $l['folder']);
		}
		wp_safe_redirect(add_query_arg('y4apk_renaming', 1, $url));
		Y4APK_R2::answer_now();
		Y4APK_R2::run_renames();
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Downloads on other sites (2.50.4)
	 * ---------------------------------------------------------------- */

	/** Posts with downloads on other sites: ['posts', 'links', 'hosts' => [host => n], 'ids', 'back' (posts whose were taken off)], kept 30 minutes. */
	public static function outside_scan($fresh = false) {
		$c = $fresh ? false : get_transient('y4apk_outside_scan');
		if (is_array($c)) {
			return $c;
		}
		global $wpdb;
		$c = ['posts' => 0, 'links' => 0, 'hosts' => [], 'ids' => [], 'back' => 0];
		$last = 0;
		do {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = 'repeatable_download_link' AND p.post_type = 'post' AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private') AND m.post_id > %d
				ORDER BY m.post_id LIMIT 2000", $last));
			foreach ($rows as $r) {
				$last = (int) $r->post_id;
				$links = maybe_unserialize($r->meta_value);
				$n = 0;
				foreach (is_array($links) ? $links : [] as $l) {
					if (is_array($l) && self::outside_link($l)) {
						$n++;
						$h = strtolower((string) wp_parse_url(trim(html_entity_decode((string) $l['download_url'])), PHP_URL_HOST));
						$c['hosts'][$h] = (isset($c['hosts'][$h]) ? $c['hosts'][$h] : 0) + 1;
					}
				}
				if ($n) {
					$c['posts']++;
					$c['links'] += $n;
					$c['ids'][] = (int) $r->post_id;
				}
			}
		} while (count($rows) === 2000);
		arsort($c['hosts']);
		$c['back'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::OUTSIDE));
		// 2.50.5: of those, the posts with no file of the site's own yet: they wait for an upload
		$c['waiting'] = [];
		$aside = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = %s AND p.post_type = 'post' AND p.post_status <> 'trash'", self::OUTSIDE)));
		foreach (array_chunk($aside, 500) as $chunk) {
			update_meta_cache('post', $chunk);
			foreach ($chunk as $id) {
				list($i, $l) = self::download($id);
				if ($i === null || self::no_link($l) || self::outside_link($l)) {
					$c['waiting'][] = $id;
				}
			}
		}
		set_transient('y4apk_outside_scan', $c, 30 * MINUTE_IN_SECONDS);
		return $c;
	}

	/** A post's downloads on other sites off it (kept in OUTSIDE, to put back): true when there were any. */
	public static function take_outside($post_id) {
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		if (!is_array($links)) {
			return false;
		}
		$keep = [];
		$gone = [];
		$at = 0;
		foreach ($links as $l) {
			if (is_array($l) && self::outside_link($l)) {
				$gone[] = ['at' => $at, 'row' => $l];
			} else {
				$keep[] = $l;
			}
			$at++;
		}
		if (!$gone) {
			return false;
		}
		$prev = get_post_meta($post_id, self::OUTSIDE, true);
		update_post_meta($post_id, self::OUTSIDE, array_merge(is_array($prev) ? $prev : [], $gone));
		update_post_meta($post_id, 'repeatable_download_link', $keep);
		self::refresh_flag($post_id); // waits in "Need a new file" when no file of the site's own is left
		clean_post_cache($post_id);
		return true;
	}

	/** A post whose links to other sites were taken off has its own file now: it leaves that list (nothing to put back). */
	private static function file_came($post_id, $links) {
		if (!get_post_meta($post_id, self::OUTSIDE, true)) {
			return;
		}
		$best = null;
		foreach ((array) $links as $l) {
			if (is_array($l) && self::is_apk($l) && !self::no_link($l) && !self::outside_link($l)) {
				$best = $l;
			}
		}
		if ($best) {
			delete_post_meta($post_id, self::OUTSIDE);
			delete_transient('y4apk_outside_scan');
		}
	}

	/** What take_outside() took off, back where it was. */
	public static function put_outside_back($post_id) {
		$back = get_post_meta($post_id, self::OUTSIDE, true);
		if (!is_array($back) || !$back) {
			delete_post_meta($post_id, self::OUTSIDE);
			return false;
		}
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$links = is_array($links) ? array_values($links) : [];
		usort($back, function ($a, $b) {
			return (int) $a['at'] <=> (int) $b['at'];
		});
		foreach ($back as $b) {
			array_splice($links, min((int) $b['at'], count($links)), 0, [$b['row']]);
		}
		update_post_meta($post_id, 'repeatable_download_link', $links);
		delete_post_meta($post_id, self::OUTSIDE);
		self::refresh_flag($post_id);
		clean_post_cache($post_id);
		return true;
	}

	public static function handle_outside() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-vc-outside');
		if (function_exists('set_time_limit')) {
			@set_time_limit(600);
		}
		global $wpdb;
		$undo = !empty($_POST['undo']);
		$n = 0;
		if ($undo) {
			foreach (array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::OUTSIDE))) as $id) {
				$n += self::put_outside_back($id) ? 1 : 0;
			}
		} else {
			foreach (self::outside_scan(true)['ids'] as $id) {
				$n += self::take_outside($id) ? 1 : 0;
			}
		}
		delete_transient('y4apk_outside_scan');
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'outside' => $undo ? 'back' : 'off', 'n' => $n], admin_url('admin.php')));
		exit;
	}

	/** Google Play and Aptoide for one app, as this server sees them. */
	private static function render_test($text) {
		$package = self::package_from($text);
		if ($package === '') {
			echo '<div class="au-card au-pad"><p class="au-bad">' . esc_html__('That is not a Google Play link or app ID.', 'y4apk') . '</p></div>';
			return;
		}
		$play = self::play_app($package);
		$apt = self::aptoide_version($package);
		$latest = self::latest($package, $play);
		$v = function ($key) use ($play) {
			return isset($play[$key]) ? $play[$key] : '';
		};
		$rows = [
			[__('App ID', 'y4apk'), $package],
			[__('Google Play page', 'y4apk'), $play['status'] === 'ok'
				? ($play['readable'] ? sprintf(__('read (%s store)', 'y4apk'), $v('store') ?: __('any', 'y4apk')) : __('opened, but its data was not found (Google may have changed the page)', 'y4apk'))
				: ($play['status'] === 'busy' ? __('did not answer (busy or blocked)', 'y4apk') : __('not found', 'y4apk'))],
			[__('Name on Google Play', 'y4apk'), $v('title')],
			[__('Version on Google Play', 'y4apk'), $v('shown')],
			[__('Updated on Google Play', 'y4apk'), $v('updated') ? date_i18n(get_option('date_format'), $v('updated')) : ''],
			[__('Version on Aptoide', 'y4apk'), $apt === null ? __('did not answer', 'y4apk') : ($apt === '' ? __('not found, or not a plain version (another build)', 'y4apk') : $apt)],
			[__('Version used', 'y4apk'), $latest === null ? __('none — try again later', 'y4apk') : ($latest['version'] !== '' ? $latest['version'] . ' (' . ($latest['source'] === 'play' ? 'Google Play' : 'Aptoide') . ')' : __('none found', 'y4apk'))],
			[__('App Publisher', 'y4apk'), $v('developer')],
			[__('App Rated Year’s', 'y4apk'), $v('rated') !== '' ? $v('rated') . ($v('rated_text') !== $v('rated') ? ' — “' . $v('rated_text') . '”' : '') : $v('rated_text')],
			[__('App Required OS', 'y4apk'), $v('requires') !== '' ? sprintf(__('Android %s and up', 'y4apk'), $v('requires')) : ''],
			[__('Total Rating', 'y4apk'), $v('rating') ? number_format_i18n($v('rating'), 1) . ' ★' : ''],
			[__('Total Votes', 'y4apk'), $v('votes') ? number_format_i18n($v('votes')) : ''],
		];
		echo '<div class="au-card au-pad"><dl class="au-dl">';
		foreach ($rows as $row) {
			echo '<dt>' . esc_html($row[0]) . '</dt><dd>' . esc_html($row[1] !== '' && $row[1] !== 0 ? (string) $row[1] : '—') . '</dd>';
		}
		echo '</dl></div>';
	}

	/** Small "★ 4.4 (56,612) · 12+ · Android 5.1+ · Publisher" chips from the last Google Play read. */

	/** A download's type, to choose (2.50.3): the usual ones, and the row's own when it is another. */
	private static function type_select($name, $is) {
		$is = trim((string) $is) !== '' ? trim((string) $is) : 'MOD';
		$types = self::NEW_TYPES;
		if (!in_array(strtoupper($is), $types, true)) {
			$types[] = $is;
		}
		echo '<select class="au-type" name="' . esc_attr($name) . '" aria-label="' . esc_attr__('Type', 'y4apk') . '">';
		foreach ($types as $t) {
			echo '<option value="' . esc_attr($t) . '"' . selected(strtoupper($t) === strtoupper($is), true, false) . '>' . esc_html(strtoupper($t)) . '</option>';
		}
		echo '</select>';
	}

	/** A download taken off the post when saved (2.50.4): $name is its "del" field, '' for a row added on the page. */
	private static function del_button($name, $on = false) {
		echo '<label class="au-del" title="' . esc_attr__('Remove this download from the post', 'y4apk') . '">'
			. ($name !== '' ? '<input type="checkbox" name="' . esc_attr($name) . '" value="1" hidden' . ($on ? ' checked' : '') . '>' : '')
			. '<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Remove', 'y4apk') . '</span></label>';
	}

	/** One post's "new file" form (Need a new file, and Update any file). */
	private static function file_row($id, $c) {
		$paged = isset($c['paged']) ? (int) $c['paged'] : 1;
		$gsc = isset($c['gsc']) ? $c['gsc'] : ['posts' => []];
		$downloads = isset($c['downloads']) ? $c['downloads'] : [];
		$followers = isset($c['followers']) ? $c['followers'] : [];
		$back = isset($c['back']) ? (string) $c['back'] : '';
		list(, $link) = self::download($id);
		$none = $link === null; // 2.50.1: no download yet — saving makes it
		$outside = !$none && self::outside_link($link); // 2.50.4: a link to another site: no file of the site's own
		$nolink = $none || self::no_link($link) || $outside; // 2.50.2: or a row whose link is empty: the upload makes its folder
			$others = self::other_downloads($id);
			$current = self::current_version($id);
			$latest = (string) get_post_meta($id, self::LATEST, true);
			$new_n = self::number($latest);
			$source = get_post_meta($id, self::SOURCE, true) === 'aptoide' ? 'Aptoide' : 'Google Play';
			// Google Play's version when it is newer (else the file's own, to change: one newer than Google Play's is fine)
			$ahead = $new_n !== '' && (self::number($current) === '' || version_compare($new_n, self::number($current), '>'));
			$suggest = !$ahead ? $current : (self::number($current) !== '' ? self::with_number($current, $new_n) : $latest);
			if ($none) {
				$first = $new_n !== '' ? $new_n : self::number(get_post_meta($id, 'wp_version_GP', true));
				$suggest = class_exists('Y4APK_Writer') ? Y4APK_Writer::version_text($first, 'MOD') : $first;
				$ahead = $new_n !== '';
			}
			$package = (string) get_post_meta($id, 'wp_GP_ID', true);
			$uploads = class_exists('Y4APK_R2') && Y4APK_R2::ready();
			$title = get_the_title($id); ?>
			<div class="au-card au-row" data-title="<?php echo esc_attr(strtolower($title)); ?>">
				<div class="au-app">
					<?php echo self::icon($id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in icon() ?>
					<div style="min-width:0">
						<a class="au-title" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($title); ?></a>
						<div class="au-links">
							<a href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank"><?php esc_html_e('View post', 'y4apk'); ?> ↗</a>
							<?php if ($package !== '') : ?><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($package)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Play', 'y4apk'); ?> ↗</a><?php endif; ?>
						</div>
						<?php echo self::info_chips($id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in info_chips() ?>
					</div>
				</div>
				<div class="au-vers">
					<span class="au-label"><?php esc_html_e('Your file', 'y4apk'); ?></span>
					<?php if ($outside) : ?>
						<span class="au-ver is-out" title="<?php echo esc_attr(html_entity_decode((string) $link['download_url'])); ?>"><?php echo esc_html(sprintf(__('On %s', 'y4apk'), wp_parse_url(html_entity_decode((string) $link['download_url']), PHP_URL_HOST))); ?></span>
					<?php elseif ($nolink || get_post_meta($id, self::NO_FILE, true) !== '') : ?>
						<span class="au-ver" title="<?php esc_attr_e('Write with AI made its download folder; upload the file here.', 'y4apk'); ?>"><?php esc_html_e('No file yet', 'y4apk'); ?></span>
					<?php else : ?>
						<span class="au-ver"><?php echo esc_html($current); ?></span>
					<?php endif; ?>
					<?php if ($package === '') : ?>
						<span class="au-label"><?php esc_html_e('Google Play', 'y4apk'); ?></span>
						<span class="au-ver"><?php esc_html_e('Not linked', 'y4apk'); ?></span>
					<?php else : ?>
						<span class="au-label"><?php echo esc_html(sprintf(__('Latest · %s', 'y4apk'), $source)); ?></span>
						<span class="au-ver<?php echo $ahead ? ' is-new' : ''; ?>"><?php echo esc_html($latest !== '' ? $latest : '—'); ?></span>
					<?php endif; ?>
					<?php if (isset($gsc['posts'][$id])) : $g = $gsc['posts'][$id]; ?>
						<span class="au-meta au-google" title="<?php esc_attr_e('Google Search, last 28 days', 'y4apk'); ?>"><?php echo esc_html(sprintf(_n('%s impression', '%s impressions', $g[0], 'y4apk'), number_format_i18n($g[0])) . ' · ' . sprintf(_n('%s click on Google', '%s clicks on Google', $g[1], 'y4apk'), number_format_i18n($g[1]))); ?></span>
					<?php endif; ?>
					<span class="au-meta"><?php echo esc_html(sprintf(_n('%s download in 30 days', '%s downloads in 30 days', isset($downloads[$id]) ? $downloads[$id] : 0, 'y4apk'), number_format_i18n(isset($downloads[$id]) ? $downloads[$id] : 0))); ?></span>
					<?php if (!empty($followers[$id])) : ?>
						<span class="au-meta au-follow"><?php echo esc_html(sprintf(_n('🔔 %s follower waits for this', '🔔 %s followers wait for this', $followers[$id], 'y4apk'), number_format_i18n($followers[$id]))); ?></span>
					<?php endif; ?>
				</div>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="au-form">
					<input type="hidden" name="action" value="y4apk_vc_save">
					<input type="hidden" name="post" value="<?php echo esc_attr($id); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>">
					<?php if ($back !== '') : ?><input type="hidden" name="back" value="<?php echo esc_attr($back); ?>"><?php endif; ?>
					<input type="hidden" name="sig" value="<?php echo esc_attr(self::links_sig($id)); ?>">
					<?php wp_nonce_field('y4apk-vc-save-' . $id); ?>
					<span class="au-label"><?php esc_html_e('New file', 'y4apk'); ?></span>
					<div class="au-line" data-row="main">
						<?php self::type_select('type', $link && !empty($link['download_name']) ? $link['download_name'] : 'MOD'); // 2.50.3: its type, to change ?>
						<input type="text" name="version" value="<?php echo esc_attr($suggest); ?>" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
						<input type="text" name="size" value="<?php echo esc_attr($link && !empty($link['download_size']) ? $link['download_size'] : ''); ?>" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
						<input type="url" name="url" value="<?php echo esc_attr(!$nolink ? $link['download_url'] : ''); ?>" data-orig="<?php echo esc_attr(!$nolink ? $link['download_url'] : ''); ?>" placeholder="<?php echo esc_attr($nolink ? __('Made for you when you upload (or paste a link)', 'y4apk') : __('Link to the new file', 'y4apk')); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
						<?php if ($uploads) { Y4APK_R2::upload_control('r2', 'main'); } ?>
						<span class="au-del-none"></span>
					</div>
					<?php foreach ($others['same'] as $i => $o) :
						// OBB and other Android files follow the APK; an iOS file only when ticked.
						$on = !in_array(strtolower(trim(isset($o['download_name']) ? (string) $o['download_name'] : '')), ['ipa', 'ios'], true); ?>
						<?php $out_i = self::outside_link($o); ?>
						<div class="au-line<?php echo $out_i ? ' is-del is-out' : ''; ?>" data-row="<?php echo esc_attr($i); ?>">
							<label class="au-type" title="<?php esc_attr_e('Update this download too', 'y4apk'); ?>"><input type="checkbox" name="rows[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked($on); ?>
								onchange="this.closest('.au-line').querySelectorAll('input:not([type=checkbox])').forEach(function (e) { e.disabled = !this.checked; }, this)"> <?php echo esc_html($o['download_name']); ?></label>
							<input type="text" name="rows[<?php echo esc_attr($i); ?>][version]" value="<?php echo esc_attr(self::with_number(isset($o['download_version']) ? $o['download_version'] : '', $new_n)); ?>" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>" <?php disabled(!$on); ?>>
							<input type="text" name="rows[<?php echo esc_attr($i); ?>][size]" value="<?php echo esc_attr(isset($o['download_size']) ? $o['download_size'] : ''); ?>" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>" <?php disabled(!$on); ?>>
							<input type="url" name="rows[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr(isset($o['download_url']) ? $o['download_url'] : ''); ?>" data-orig="<?php echo esc_attr(isset($o['download_url']) ? $o['download_url'] : ''); ?>" placeholder="<?php esc_attr_e('Link to the new file', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>" <?php disabled(!$on); ?>>
							<?php if ($uploads) { Y4APK_R2::upload_control('rows[' . $i . '][r2]', (string) $i, !$on); } ?>
							<?php self::del_button('rows[' . $i . '][del]', $out_i); ?>
						</div>
					<?php endforeach; ?>
					<div class="au-extras"></div>
					<button type="button" class="button au-add">+ <?php esc_html_e('Add a file', 'y4apk'); ?></button>
					<template class="au-extra-tpl"><div class="au-line au-extra" data-row="x__N__">
						<?php self::type_select('extra[__N__][type]', 'OBB'); ?>
						<input type="text" name="extra[__N__][version]" value="" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
						<input type="text" name="extra[__N__][size]" value="" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
						<input type="url" name="extra[__N__][url]" value="" placeholder="<?php esc_attr_e('Made for you when you upload (or paste a link)', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
						<?php if ($uploads) { Y4APK_R2::upload_control('extra[__N__][r2]', 'x__N__'); } ?>
						<?php self::del_button(''); ?>
					</div></template>
					<div class="au-foot">
						<?php if ($nolink) : ?>
							<input type="hidden" name="mode" value="replace">
							<span class="au-first"><?php echo esc_html($outside
								? __('Upload your file and Save: its download link (a new folder in files/up) takes the place of the other site’s. Save without a file just takes that link off.', 'y4apk')
								: __('First file: upload it and Save — its download link (a new folder in files/up) and the download on the post are made for you.', 'y4apk')); ?></span>
						<?php else : ?>
						<label><input type="radio" name="mode" value="replace" checked> <?php esc_html_e('Replace the old file', 'y4apk'); ?></label>
						<label><input type="radio" name="mode" value="add"> <?php esc_html_e('Add as new, keep the old file below it', 'y4apk'); ?></label>
						<?php endif; ?>
					</div>
					<p class="au-warn" hidden><?php esc_html_e('“Add as new” keeps the old download, so the new one needs another link: upload the new file or paste its link — or choose “Replace the old file” to keep this link.', 'y4apk'); ?></p>
					<?php if ($nolink && !$outside) : ?><p class="au-warn au-need" hidden><?php esc_html_e('Upload the file first (or paste a link to it): its download link is made for you.', 'y4apk'); ?></p><?php endif; ?>
					<?php if ($others['kept']) : // 2.50.4: the post's other downloads (older versions, other files), to change, upload again or take off ?>
						<span class="au-label au-label-kept"><?php esc_html_e('Other downloads on the post', 'y4apk'); ?></span>
						<?php foreach ($others['kept'] as $i => $o) : ?>
							<?php $out_i = self::outside_link($o); ?>
							<div class="au-line au-keptline<?php echo $out_i ? ' is-del is-out' : ''; ?>" data-row="k<?php echo esc_attr($i); ?>">
								<?php self::type_select('kept[' . $i . '][type]', isset($o['download_name']) ? $o['download_name'] : ''); ?>
								<input type="text" name="kept[<?php echo esc_attr($i); ?>][version]" value="<?php echo esc_attr(isset($o['download_version']) ? $o['download_version'] : ''); ?>" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
								<input type="text" name="kept[<?php echo esc_attr($i); ?>][size]" value="<?php echo esc_attr(isset($o['download_size']) ? $o['download_size'] : ''); ?>" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
								<input type="url" name="kept[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr(isset($o['download_url']) ? $o['download_url'] : ''); ?>" placeholder="<?php esc_attr_e('Link to the file', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
								<?php if ($uploads) { Y4APK_R2::upload_control('kept[' . $i . '][r2]', 'k' . $i); } ?>
								<?php self::del_button('kept[' . $i . '][del]', $out_i); ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ($outside || array_filter(array_merge($others['same'], $others['kept']), [__CLASS__, 'outside_link'])) : ?>
						<p class="au-outnote"><?php esc_html_e('Links to other sites are not Y4APK’s: Save takes them off (the bin keeps one), and the post waits for your file. Upload it for the new one and its download link is made for you.', 'y4apk'); ?></p>
					<?php endif; ?>
					<input type="hidden" name="main_dirty" value="">
					<div class="au-save">
						<span class="au-save-note"><?php esc_html_e('Save puts every change in: the new file, added files, and the other downloads (changed, uploaded again, or removed with the bin — their files stay on R2).', 'y4apk'); ?></span>
						<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
					</div>
				</form>
			</div>
		<?php
	}

	private static function info_chips($post_id) {
		$i = json_decode((string) get_post_meta($post_id, self::INFO, true), true);
		if (!is_array($i)) {
			return '';
		}
		$chips = [];
		if (!empty($i['rating'])) {
			$chips[] = '★ ' . number_format_i18n($i['rating'], 1) . (!empty($i['votes']) ? ' (' . number_format_i18n($i['votes']) . ')' : '');
		}
		if (!empty($i['rated'])) {
			$chips[] = $i['rated'];
		}
		if (!empty($i['requires'])) {
			$chips[] = 'Android ' . $i['requires'] . '+';
		}
		if (!empty($i['developer'])) {
			$chips[] = $i['developer'];
		}
		$out = '';
		foreach ($chips as $c) {
			$out .= '<span class="au-chip">' . esc_html($c) . '</span>';
		}
		return $out ? '<div class="au-chips" title="' . esc_attr__('As Google Play shows it', 'y4apk') . '">' . $out . '</div>' : '';
	}

	public static function icon($post_id) {
		$src = get_the_post_thumbnail_url($post_id, 'thumbnail');
		if ($src) {
			return '<img class="au-icon" src="' . esc_url($src) . '" alt="" loading="lazy">';
		}
		$t = trim((string) get_the_title($post_id));
		return '<span class="au-icon au-icon-none" aria-hidden="true">' . esc_html($t !== '' ? mb_strtoupper(mb_substr($t, 0, 1)) : '?') . '</span>';
	}

	/* ---------------------------------------------------------------- *
	 * Auto updates (2.57): which posts follow Google Play's version by
	 * themselves already, which do not yet (and why), and the order the
	 * background checks them in.
	 * ---------------------------------------------------------------- */

	/** Every game and app linked to Google Play, where it stands: id => row ('state': done | behind | cant | never). In post ID order. */
	public static function auto_rows() {
		global $wpdb;
		$keys = ['wp_version_GP' => 'shown', self::LATEST => 'latest', self::SOURCE => 'source', self::CHECKED => 'checked', self::NEEDS => 'needs', self::FOLLOWED => 'followed', '_y4apk_parent' => 'parent'];
		$cols = [];
		foreach ($keys as $k => $as) {
			$cols[] = "MAX(CASE WHEN m.meta_key = '" . esc_sql($k) . "' THEN m.meta_value END) AS $as";
		}
		$rows = $wpdb->get_results("SELECT p.ID AS id, p.post_title AS title, p.post_modified_gmt AS modified, " . implode(', ', $cols) . "
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'wp_GP_ID' AND g.meta_value <> ''
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('" . implode("','", array_map('esc_sql', array_keys($keys))) . "')
			WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_parent = 0 AND NOT " . self::scripts_sql('p') . "
			GROUP BY p.ID ORDER BY p.ID");
		$out = [];
		foreach ($rows as $r) {
			if (!empty($r->parent)) {
				continue; // a member's version: its main post is the one checked
			}
			$f = json_decode((string) $r->followed, true);
			$row = [
				'id'       => (int) $r->id,
				'title'    => (string) $r->title,
				'shown'    => self::number($r->shown),
				'latest'   => self::number($r->latest),
				'source'   => (string) $r->source,
				'checked'  => (int) $r->checked,
				'needs'    => !empty($r->needs),
				'followed' => is_array($f) && !empty($f['to']) ? $f : null,
				'modified' => $r->modified ? (int) strtotime($r->modified . ' UTC') : 0,
			];
			if (!$row['checked']) {
				$row['state'] = 'never';
			} elseif ($row['source'] !== 'play' || $row['latest'] === '') {
				$row['state'] = 'cant'; // Google Play shows no number ("Varies with device"), or only Aptoide's (other builds)
			} elseif ($row['shown'] === '' || version_compare($row['latest'], $row['shown'], '>')) {
				$row['state'] = 'behind';
			} else {
				$row['state'] = 'done';
			}
			$out[$row['id']] = $row;
		}
		return $out;
	}

	/** The Auto updates tab. */
	private static function render_auto($url) {
		$all = self::auto_rows();
		$subs = ['done', 'behind', 'queue', 'cant'];
		$sub = isset($_GET['sub']) && in_array($_GET['sub'], $subs, true) ? $_GET['sub'] : 'done';
		$only = isset($_GET['only']) && in_array($_GET['only'], ['file', 'ok'], true) ? $_GET['only'] : '';
		$q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
		$on = self::enabled();
		$follow = self::auto_on('version');

		$by = ['done' => [], 'behind' => [], 'cant' => [], 'never' => []];
		foreach ($all as $id => $r) {
			$by[$r['state']][] = $id;
		}
		$waiting_file = count(array_filter($by['done'], function ($id) use ($all) { return $all[$id]['needs']; }));
		$week = 0;
		foreach ($all as $r) {
			if ($r['followed'] && (int) $r['followed']['at'] > time() - WEEK_IN_SECONDS) {
				$week++;
			}
		}

		// When the next background step runs, and how many posts each one checks
		$last = (int) get_option('y4apk_vc_last_step', 0);
		$next = (int) wp_next_scheduled(self::HOOK);
		$next = $next ?: ($last ? $last + 15 * MINUTE_IN_SECONDS : time());
		$step = 15 * MINUTE_IN_SECONDS;
		$eta = function ($n) use ($next, $step, $on) {
			if (!$on) {
				return __('switched off', 'y4apk');
			}
			$t = $next + $n * $step;
			return $t - time() < 60 ? __('any moment', 'y4apk') : sprintf(__('in %s', 'y4apk'), human_time_diff(time(), $t));
		};

		// The order things happen in
		$queue = [];       // id => step (the version checks: important posts, then by post ID from where the round is)
		$prio = $on ? array_values(array_filter(self::priority_ids(self::PRIORITY), function ($id) use ($all) { return isset($all[$id]); })) : [];
		$slots = max(1, self::BATCH - count($prio));
		foreach ($prio as $id) {
			$queue[$id] = 0;
		}
		$cursor = (int) get_option('y4apk_vc_cursor', 0);
		$ids = array_keys($all);
		$after = array_values(array_filter($ids, function ($id) use ($cursor) { return $id > $cursor; }));
		$before = array_values(array_filter($ids, function ($id) use ($cursor) { return $id <= $cursor; }));
		$i = 0;
		foreach (array_merge($after, $before) as $id) {
			if (!isset($queue[$id])) {
				$queue[$id] = intdiv($i++, $slots);
			}
		}
		$round = (int) ceil(count($ids) / $slots) * $step;
		$fq = [];          // id => step (posts known to be behind, put in from what is known: FOLLOW_BATCH a step)
		$fc = (int) get_option('y4apk_vc_follow_cursor', 0);
		$fl = array_values(array_filter($by['behind'], function ($id) use ($all) { return $all[$id]['needs']; }));
		$i = 0;
		foreach (array_merge(array_filter($fl, function ($id) use ($fc) { return $id > $fc; }), array_filter($fl, function ($id) use ($fc) { return $id <= $fc; })) as $id) {
			$fq[$id] = intdiv($i++, self::FOLLOW_BATCH);
		}

		// This view's posts, in its order
		if ($sub === 'done') {
			$list = $by['done'];
			if ($only !== '') {
				$list = array_values(array_filter($list, function ($id) use ($all, $only) { return $only === 'file' ? $all[$id]['needs'] : !$all[$id]['needs']; }));
			}
			$when = function ($id) use ($all) { $r = $all[$id]; return $r['followed'] && $r['followed']['to'] === $r['shown'] ? (int) $r['followed']['at'] : $r['modified']; };
			usort($list, function ($a, $b) use ($when) { return $when($b) <=> $when($a); });
		} elseif ($sub === 'behind') {
			$list = $by['behind'];
			usort($list, function ($a, $b) use ($fq) { return [isset($fq[$a]) ? 0 : 1, $fq[$a] ?? 0, $a] <=> [isset($fq[$b]) ? 0 : 1, $fq[$b] ?? 0, $b]; });
		} elseif ($sub === 'queue') {
			$list = array_keys($queue); // already in order
		} else {
			$list = $by['cant'];
			usort($list, function ($a, $b) use ($all) { return strcasecmp($all[$a]['title'], $all[$b]['title']); });
		}
		$pos = array_flip($list);
		if ($q !== '') {
			$list = array_values(array_filter($list, function ($id) use ($all, $q) { return mb_stripos($all[$id]['title'], $q) !== false; }));
		}
		$paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
		$pages = max(1, (int) ceil(count($list) / self::PER_PAGE));
		$page_ids = array_slice($list, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
		if ($page_ids) {
			_prime_post_caches($page_ids, false, true);
		}
		$link = function ($s, $args = []) use ($url, $q) {
			return $url('auto', array_merge(['sub' => $s], $q !== '' ? ['q' => $q] : [], $args));
		};
		$n = function ($x) { return number_format_i18n($x); };
		$total = count($all);
		?>
		<div class="au-autos">
			<a class="au-card au-kpi au-auto is-green<?php echo $sub === 'done' ? ' is-on' : ''; ?>" href="<?php echo esc_url($link('done')); ?>">
				<span class="au-kpi-label"><?php esc_html_e('Up to date with Google Play', 'y4apk'); ?></span>
				<span class="au-kpi-value"><?php echo esc_html($n(count($by['done']))); ?> <small>/ <?php echo esc_html($n($total)); ?></small></span>
				<span class="au-kpi-sub"><?php echo esc_html(sprintf(__('The post shows the latest version · %1$s wait for your file · %2$s updated by themselves this week', 'y4apk'), $n($waiting_file), $n($week))); ?></span>
			</a>
			<a class="au-card au-kpi au-auto is-amber<?php echo $sub === 'behind' ? ' is-on' : ''; ?>" href="<?php echo esc_url($link('behind')); ?>">
				<span class="au-kpi-label"><?php esc_html_e('Not updated yet', 'y4apk'); ?></span>
				<span class="au-kpi-value"><?php echo esc_html($n(count($by['behind']))); ?></span>
				<span class="au-kpi-sub"><?php echo esc_html(!$follow ? __('Google Play has newer versions · following the version is off in Settings', 'y4apk')
					: ($by['behind'] ? sprintf(__('Google Play has a newer version · %s go in by themselves at the next steps', 'y4apk'), $n(count($fq))) : __('Every checked post shows Google Play’s version', 'y4apk'))); ?></span>
			</a>
			<a class="au-card au-kpi au-auto is-blue<?php echo $sub === 'queue' ? ' is-on' : ''; ?>" href="<?php echo esc_url($link('queue')); ?>">
				<span class="au-kpi-label"><?php esc_html_e('Queue', 'y4apk'); ?></span>
				<span class="au-kpi-value"><?php echo esc_html($on ? sprintf(__('Next %s', 'y4apk'), $eta(0)) : __('Off', 'y4apk')); ?></span>
				<span class="au-kpi-sub"><?php echo esc_html(sprintf(__('About %1$s posts a step, every 15 minutes · a full round ≈ %2$s · %3$s never checked', 'y4apk'), $n($slots), human_time_diff(0, $round), $n(count($by['never'])))); ?></span>
			</a>
			<a class="au-card au-kpi au-auto is-grey<?php echo $sub === 'cant' ? ' is-on' : ''; ?>" href="<?php echo esc_url($link('cant')); ?>">
				<span class="au-kpi-label"><?php esc_html_e('Can’t follow by itself', 'y4apk'); ?></span>
				<span class="au-kpi-value"><?php echo esc_html($n(count($by['cant']))); ?></span>
				<span class="au-kpi-sub"><?php esc_html_e('Google Play shows no version number (“Varies with device”)', 'y4apk'); ?></span>
			</a>
		</div>

		<div class="au-toolbar">
			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="au-auto-find">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<input type="hidden" name="view" value="auto">
				<input type="hidden" name="sub" value="<?php echo esc_attr($sub); ?>">
				<input type="search" name="q" class="au-search" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Find a game or app in this list…', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Find a game or app in this list', 'y4apk'); ?>">
			</form>
			<span class="au-toolbar-info">
				<?php if ($sub === 'done') : ?>
					<a href="<?php echo esc_url($link('done')); ?>" class="<?php echo $only === '' ? 'is-on' : ''; ?>"><?php esc_html_e('All', 'y4apk'); ?></a> ·
					<a href="<?php echo esc_url($link('done', ['only' => 'file'])); ?>" class="<?php echo $only === 'file' ? 'is-on' : ''; ?>"><?php esc_html_e('Waiting for your file', 'y4apk'); ?></a> ·
					<a href="<?php echo esc_url($link('done', ['only' => 'ok'])); ?>" class="<?php echo $only === 'ok' ? 'is-on' : ''; ?>"><?php esc_html_e('File up to date too', 'y4apk'); ?></a> ·
				<?php endif; ?>
				<?php echo esc_html(sprintf(__('%1$s posts · page %2$s of %3$s', 'y4apk'), $n(count($list)), $n($paged), $n($pages))); ?>
			</span>
		</div>
		<p class="au-note" style="margin:0 0 12px"><?php echo esc_html([
			'done'   => __('Newest first. “Updated by itself” is the version the background put into the post (its App Version, title and Rank Math texts) when Google Play had a newer one. The file stays the old one until you upload the new file.', 'y4apk'),
			'behind' => __('Google Play has a newer version than the post shows. Posts whose file is older go in by themselves, 40 at each step, in this order.', 'y4apk'),
			'queue'  => __('The order the background checks Google Play in: posts Google Search shows most and people follow come first (at least daily), then all the others by turn. A post with a newer version is updated as soon as it is checked. Times are estimates: when Google Play is busy a step does fewer.', 'y4apk'),
			'cant'   => __('Google Play does not show a version number for these (it says “Varies with device”), so the version cannot follow it by itself. Aptoide’s number is shown when there is one, but only to look at: it may be another build. Update these by hand with Update any file.', 'y4apk'),
		][$sub]); ?></p>

		<div class="au-list">
			<?php if (!$page_ids) : ?>
				<div class="au-card au-empty"><?php echo esc_html($q !== '' ? __('Nothing with that name in this list.', 'y4apk') : __('Nothing in this list.', 'y4apk')); ?></div>
			<?php endif; ?>
			<?php foreach ($page_ids as $id) :
				$r = $all[$id];
				$file = self::number(self::current_version($id));
				$package = self::package_from(get_post_meta($id, 'wp_GP_ID', true));
				$check = wp_nonce_url(admin_url('admin-post.php?action=y4apk_vc_check&post=' . $id), 'y4apk-vc-check-' . $id);
				$checked = $r['checked'] ? sprintf(__('Checked %s ago', 'y4apk'), human_time_diff($r['checked'])) : __('Never checked', 'y4apk');
				$v = function ($x) { return $x !== '' ? 'v' . $x : '—'; };
				// What to say about it
				$chip = ['', ''];
				$line = '';
				$action = '';
				if ($sub === 'queue') {
					$st = (int) $queue[$id];
					$chip = $st === 0 ? ['is-blue', __('Next step', 'y4apk')] : ['', sprintf(__('#%s in the queue', 'y4apk'), $n($pos[$id] + 1))];
					$line = sprintf(__('Next check %1$s · %2$s', 'y4apk'), $eta($st), $r['checked'] ? sprintf(__('last checked %s ago', 'y4apk'), human_time_diff($r['checked'])) : __('never checked yet', 'y4apk'))
						. ($st === 0 && in_array($id, $prio, true) ? ' · ' . __('first: Google shows it often', 'y4apk') : '');
				} elseif ($r['state'] === 'done') {
					$rec = $r['followed'] && $r['followed']['to'] === $r['shown'] ? $r['followed'] : null;
					if (!$rec && $r['needs'] && $file !== '' && version_compare($r['shown'], $file, '>')) {
						$rec = ['from' => $file, 'to' => $r['shown'], 'at' => $r['modified']]; // put in before 2.57 kept a note of it
					}
					$chip = $rec ? ['is-green', __('Updated by itself', 'y4apk')] : ['is-green', __('Up to date', 'y4apk')];
					$line = $rec ? sprintf(__('%1$s → %2$s · %3$s ago', 'y4apk'), $v(self::number($rec['from'])), $v($rec['to']), human_time_diff((int) $rec['at'])) : $checked;
					if ($r['needs']) {
						$action = '<a class="button button-primary" href="' . esc_url($url('file', ['post' => $id])) . '">' . esc_html__('Upload the new file', 'y4apk') . '</a>';
					}
				} elseif ($r['state'] === 'behind') {
					if (!$follow) {
						$chip = ['is-grey', __('Off in Settings', 'y4apk')];
						$line = __('Following Google Play’s version is switched off', 'y4apk');
					} elseif (isset($fq[$id])) {
						$chip = ['is-amber', __('Waiting', 'y4apk')];
						$line = sprintf(__('Goes in by itself %s', 'y4apk'), $eta($fq[$id])) . ' · ' . $checked;
					} else {
						$chip = ['is-grey', __('Left as it is', 'y4apk')];
						$line = __('Your file is this version or newer: App Version was not changed. Fix it in the post, or upload the file again.', 'y4apk');
					}
					$action = '<a class="button" href="' . esc_url($check) . '">' . esc_html__('Check now', 'y4apk') . '</a>';
				} elseif ($r['state'] === 'cant') {
					$chip = ['is-grey', $r['source'] === 'varies' ? __('Varies with device', 'y4apk') : ($r['source'] === 'aptoide' ? __('Only Aptoide’s number', 'y4apk') : __('No version found', 'y4apk'))];
					$line = $checked;
					$action = '<a class="button" href="' . esc_url($url('file', ['post' => $id])) . '">' . esc_html__('Update the file', 'y4apk') . '</a>';
				} else {
					$chip = ['', __('Never checked', 'y4apk')];
					$line = sprintf(__('Next check %s', 'y4apk'), $eta((int) ($queue[$id] ?? 0)));
				}
				if ($sub === 'queue' && $r['checked']) {
					$action = '<a class="button" href="' . esc_url($check) . '">' . esc_html__('Check now', 'y4apk') . '</a>';
				} elseif ($sub === 'queue') {
					$action = '<a class="button button-primary" href="' . esc_url($check) . '">' . esc_html__('Check now', 'y4apk') . '</a>';
				}
				?>
				<div class="au-card au-arow">
					<div class="au-app">
						<?php echo self::icon($id); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<div style="min-width:0">
							<a class="au-title" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a>
							<span class="au-links">
								<a href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View post', 'y4apk'); ?> ↗</a>
								<?php if ($package !== '') : ?><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($package)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Play', 'y4apk'); ?> ↗</a><?php endif; ?>
							</span>
						</div>
					</div>
					<dl class="au-avers">
						<div><dt><?php esc_html_e('Post', 'y4apk'); ?></dt><dd><?php echo esc_html($v($r['shown'])); ?></dd></div>
						<div><dt><?php esc_html_e('Google Play', 'y4apk'); ?></dt><dd class="<?php echo $r['state'] === 'behind' ? 'is-new' : ''; ?>"><?php echo esc_html($r['state'] === 'cant' ? ($r['source'] === 'aptoide' && $r['latest'] !== '' ? 'Aptoide v' . $r['latest'] : '—') : $v($r['latest'])); ?></dd></div>
						<div><dt><?php esc_html_e('Your file', 'y4apk'); ?></dt><dd class="<?php echo $r['needs'] ? 'is-old' : ''; ?>"><?php echo esc_html($v($file)); ?></dd></div>
					</dl>
					<div class="au-state">
						<span class="au-st <?php echo esc_attr($chip[0]); ?>"><?php echo esc_html($chip[1]); ?></span>
						<?php if ($sub !== 'queue' && $r['state'] === 'done' && $r['needs']) : ?><span class="au-st is-amber"><?php esc_html_e('Waiting for your file', 'y4apk'); ?></span><?php endif; ?>
						<span class="au-small"><?php echo esc_html($line); ?></span>
					</div>
					<div class="au-act"><?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ($pages > 1) : ?>
			<div class="au-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<?php endif;
	}

	public static function styles() {
		?>
		<style>
			.y4apk-au { --au-blue: #2271b1; --au-ink: #1d2327; --au-muted: #646970; --au-line: #dcdcde; --au-soft: #f6f7f7; --au-red: #d63638; --au-green: #00a32a; --au-amber: #dba617; margin-right: 20px; color: var(--au-ink); }
			.y4apk-au *, .y4apk-au *::before, .y4apk-au *::after { box-sizing: border-box; }
			.y4apk-au [hidden] { display: none !important; }
			.au-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin: 16px 0 18px; }
			.au-head h1 { font-size: 26px; font-weight: 600; margin: 0; padding: 0; line-height: 1.2; }
			.au-head p { margin: 6px 0 0; color: var(--au-muted); font-size: 13px; max-width: 820px; }
			.au-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
			.au-actions form { margin: 0; }
			.au-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: #fff; border: 1px solid var(--au-line); font-size: 12.5px; }
			.au-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--au-green); box-shadow: 0 0 0 3px rgba(0, 163, 42, .15); flex: none; }
			.au-dot.is-amber { background: var(--au-amber); box-shadow: 0 0 0 3px rgba(219, 166, 23, .18); }
			.au-dot.is-red { background: var(--au-red); box-shadow: 0 0 0 3px rgba(214, 54, 56, .15); }
			.au-dot.is-grey { background: #a7aaad; box-shadow: none; }
			.au-card { background: #fff; border: 1px solid var(--au-line); border-radius: 12px; box-shadow: 0 1px 2px rgba(0, 0, 0, .04); }
			.au-pad { padding: 18px 20px; }
			.au-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 22px; }
			.au-kpi { padding: 16px 18px; display: flex; flex-direction: column; gap: 7px; text-decoration: none; color: inherit; transition: border-color .15s, box-shadow .15s; }
			a.au-kpi:hover, a.au-kpi:focus { border-color: var(--au-blue); box-shadow: 0 2px 8px rgba(34, 113, 177, .12); color: inherit; }
			.au-kpi-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--au-muted); }
			.au-kpi-value { font-size: 28px; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; }
			.au-kpi-value small { font-size: 14px; font-weight: 400; color: var(--au-muted); }
			.au-kpi.is-alert .au-kpi-value { color: var(--au-red); }
			.au-kpi-sub { font-size: 12.5px; color: var(--au-muted); }
			.au-bar { height: 6px; border-radius: 6px; background: #eef0f2; overflow: hidden; }
			.au-bar > span { display: block; height: 100%; border-radius: 6px; background: var(--au-blue); }
			.au-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--au-line); margin: 0 0 16px; overflow-x: auto; }
			.au-tab { padding: 10px 14px; font-size: 14px; font-weight: 500; color: var(--au-muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
			.au-tab:hover, .au-tab:focus { color: var(--au-ink); box-shadow: none; }
			.au-tab.is-on { color: var(--au-blue); border-bottom-color: var(--au-blue); }
			.au-count { display: inline-block; min-width: 20px; padding: 1px 7px; margin-left: 6px; border-radius: 999px; background: #e7e9ec; color: var(--au-ink); font-size: 11.5px; text-align: center; }
			.au-tab.is-on .au-count { background: var(--au-blue); color: #fff; }
			.au-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
			.au-toolbar .au-search { min-width: 280px; padding: 4px 12px; border-radius: 8px; }
			.au-toolbar-info { color: var(--au-muted); font-size: 13px; }
			.au-list { display: flex; flex-direction: column; gap: 10px; }
			.au-row { display: grid; grid-template-columns: minmax(240px, 1.2fr) minmax(150px, .5fr) minmax(440px, 2.1fr); gap: 20px; padding: 16px 18px; align-items: start; }
			.au-app { display: flex; gap: 12px; min-width: 0; }
			.au-icon { width: 52px; height: 52px; border-radius: 12px; object-fit: cover; flex: none; background: var(--au-soft); border: 1px solid var(--au-line); }
			.au-icon-none { display: inline-flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600; color: var(--au-muted); }
			.au-title { font-size: 14px; font-weight: 600; color: var(--au-ink); text-decoration: none; line-height: 1.35; }
			.au-title:hover { color: var(--au-blue); }
			.au-links { font-size: 12px; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px 12px; }
			.au-links a { text-decoration: none; }
			.au-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
			.au-chip { font-size: 11.5px; padding: 2px 8px; border-radius: 999px; background: var(--au-soft); border: 1px solid #e4e6e8; color: #3c434a; white-space: nowrap; }
			.au-vers { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }
			.au-ver { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; padding: 2px 8px; border-radius: 6px; background: var(--au-soft); border: 1px solid #e4e6e8; }
			.au-ver.is-new { background: #fcf0f1; border-color: #f4c6c7; color: var(--au-red); font-weight: 600; }
			.au-meta { font-size: 12px; color: var(--au-muted); }
			.au-google { color: #1a73e8; }
			.au-follow { color: #8a6100; }
			.au-toolbar-info a { text-decoration: none; }
			.au-toolbar-info a.is-on { font-weight: 600; color: var(--au-ink); }
			.au-form { display: flex; flex-direction: column; gap: 8px; margin: 0; }
			.au-line { display: grid; grid-template-columns: 78px 140px 105px minmax(160px, 1fr) auto 30px; gap: 8px; align-items: center; }
			.au-line + .au-line, .au-extras .au-line { margin-top: 6px; }
			.au-del { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; color: #8c8f94; cursor: pointer; }
			.au-del:hover { background: #fcf0f1; color: #b32d2e; }
			.au-line.is-del .au-del { background: #b32d2e; color: #fff; }
			.au-line.is-del > :not(.au-del):not(.au-up-status) { opacity: .35; text-decoration: line-through; pointer-events: none; }
			.au-add { margin-top: 8px; align-self: flex-start; justify-self: start; width: auto; }
			.au-label-kept { display: block; margin-top: 16px; padding-top: 12px; border-top: 1px dashed #dcdcde; }
			.au-save { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; }
			.au-save-note { font-size: 12px; color: var(--au-muted); }
			.au-ver.is-out { background: #fcf0f1; color: #b32d2e; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.au-outnote { margin: 10px 0 0; padding: 8px 12px; border-radius: 8px; background: #fcf9e8; color: #6b5300; font-size: 12.5px; }
			.au-outside { margin-bottom: 14px; border-left: 4px solid #d63638; }
			.au-outside strong { font-size: 14px; margin-right: 8px; }
			.au-outside .au-note { margin: 8px 0 10px; }
			.au-outside-actions { display: inline-flex; gap: 8px; flex-wrap: wrap; margin: 0; }
			.au-out-line { display: flex; align-items: center; gap: 6px 14px; flex-wrap: wrap; }
			.au-out-line + .au-out-line, .au-out-line + .au-note { margin-top: 10px; }
			.au-out-line .au-small { flex: 1; min-width: 220px; margin: 0; }
			.au-out-back { display: flex; align-items: center; gap: 8px; margin: 12px 0 0; padding-top: 10px; border-top: 1px dashed #dcdcde; }
			.au-warn { margin: 0; padding: 8px 12px; border-radius: 8px; background: #fcf0f1; color: #8a2424; font-size: 12.5px; }
			.au-up-btn { white-space: nowrap; }
			.au-up-btn.disabled { opacity: .45; pointer-events: none; }
			.au-up.is-busy .au-up-btn { opacity: .5; pointer-events: none; }
			.au-up-status { grid-column: 1 / -1; font-size: 12px; color: #1d2327; background: #f0f6fc; border-radius: 6px; padding: 5px 10px; }
			.au-up-status .button-link { margin-left: 6px; }
			.au-line input[type=text], .au-line input[type=url] { width: 100%; min-height: 32px; margin: 0; border-radius: 6px; }
			.au-line input:disabled { opacity: .45; }
			.au-type { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; color: #50575e; overflow: hidden; white-space: nowrap; }
			.au-type input[type=checkbox] { margin: 0; }
			.au-foot { display: flex; align-items: center; gap: 6px 16px; flex-wrap: wrap; padding-top: 2px; }
			.au-foot label { font-size: 12.5px; white-space: nowrap; }
			.au-foot .button-primary { margin-left: auto; min-width: 96px; }
			.au-kept { font-size: 12px; color: var(--au-muted); }
			.au-empty { padding: 48px 20px; text-align: center; color: var(--au-muted); font-size: 14px; }
			.au-note { color: var(--au-muted); font-size: 12.5px; max-width: 1100px; margin: 14px 0 0; line-height: 1.6; }
			.au-pages { margin-top: 14px; }
			.au-pages .page-numbers { display: inline-block; min-width: 32px; padding: 5px 9px; margin-right: 4px; border: 1px solid var(--au-line); border-radius: 8px; background: #fff; text-align: center; text-decoration: none; }
			.au-pages .page-numbers.current { background: var(--au-blue); border-color: var(--au-blue); color: #fff; }
			.au-srow { display: grid; grid-template-columns: 22px minmax(220px, 1fr) minmax(220px, 1fr) minmax(360px, 1.3fr); gap: 16px; padding: 14px 18px; align-items: center; }
			.au-srow form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 0; }
			.au-srow form input[name=id] { flex: 1; min-width: 200px; border-radius: 6px; }
			.au-small { display: block; font-size: 12px; color: var(--au-muted); margin-top: 2px; }
			.au-first { flex: 1; min-width: 200px; font-size: 12px; color: var(--au-muted); }
			select.au-type { width: 100%; min-width: 0; min-height: 30px; padding: 0 18px 0 6px; font-size: 12px; background-position: right 2px center; }

			.au-srow.au-hit { grid-template-columns: minmax(0, 1fr) auto; }
			.au-label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--au-muted); margin-bottom: 2px; }
			.au-dl { display: grid; grid-template-columns: minmax(180px, 240px) 1fr; gap: 0; margin: 0; }
			.au-dl dt, .au-dl dd { margin: 0; padding: 9px 0; border-bottom: 1px solid #f0f0f1; }
			.au-dl dt { font-weight: 600; color: #50575e; }
			.au-dl dt:last-of-type, .au-dl dd:last-of-type { border-bottom: 0; }
			.au-bad { color: var(--au-red); margin: 0; }
			.au-settings { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
			.au-settings h2 { font-size: 15px; margin: 0 0 6px; }
			.au-settings p { margin: 0 0 12px; color: var(--au-muted); }
			.au-settings label { display: block; margin: 8px 0; }
			.au-code { display: block; padding: 10px 12px; border-radius: 8px; background: #1d2327; color: #f0f0f1; font-size: 12.5px; word-break: break-all; }
			/* Auto updates (2.57) */
			.au-autos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 16px; }
			.au-auto { position: relative; border-top: 3px solid var(--au-line); }
			.au-auto.is-green { border-top-color: var(--au-green); }
			.au-auto.is-amber { border-top-color: var(--au-amber); }
			.au-auto.is-blue { border-top-color: var(--au-blue); }
			.au-auto.is-grey { border-top-color: #a7aaad; }
			.au-auto.is-on { box-shadow: 0 0 0 2px var(--au-blue); border-color: transparent; border-top-color: transparent; }
			.au-auto .au-kpi-value { font-size: 24px; }
			.au-auto-find { margin: 0; }
			.au-arow { display: grid; grid-template-columns: minmax(240px, 1.4fr) minmax(250px, 1fr) minmax(220px, 1fr) 170px; gap: 18px; padding: 14px 18px; align-items: center; }
			.au-avers { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0; }
			.au-avers dt { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--au-muted); }
			.au-avers dd { margin: 2px 0 0; font: 600 13px/1.3 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--au-ink); overflow-wrap: anywhere; }
			.au-avers dd.is-new { color: var(--au-green); }
			.au-avers dd.is-old { color: var(--au-red); }
			.au-state { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 0; }
			.au-state .au-small { flex-basis: 100%; margin: 0; }
			.au-st { display: inline-flex; align-items: center; height: 22px; padding: 0 9px; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1; background: #f0f0f1; color: #50575e; white-space: nowrap; }
			.au-st.is-green { background: #edfaef; color: #00691b; }
			.au-st.is-amber { background: #fcf9e8; color: #7a5d00; }
			.au-st.is-blue { background: #e8f1fb; color: #135e96; }
			.au-st.is-grey { background: #f0f0f1; color: #50575e; }
			.au-act { display: flex; justify-content: flex-end; }
			.au-act .button { white-space: nowrap; }
			@media (max-width: 1400px) {
				.au-autos { grid-template-columns: repeat(2, minmax(0, 1fr)); }
				.au-arow { grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr); }
				.au-act { justify-content: flex-start; }
				.au-row { grid-template-columns: minmax(220px, 1fr) minmax(140px, .45fr); }
				.au-row .au-form { grid-column: 1 / -1; }
				.au-srow { grid-template-columns: 22px 1fr 1fr; }
				.au-srow form { grid-column: 2 / -1; }
				.au-srow.au-hit { grid-template-columns: minmax(0, 1fr) auto; }
			}
			@media (max-width: 782px) {
				.y4apk-au { margin-right: 10px; }
				.au-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
				.au-kpi { padding: 12px 14px; }
				.au-kpi:last-child { grid-column: 1 / -1; }
				.au-kpi-value { font-size: 22px; }
				.au-row, .au-srow, .au-arow { grid-template-columns: 1fr; }
				.au-autos { gap: 10px; }
				.au-act { justify-content: flex-start; }
				.au-srow form { grid-column: auto; }
				.au-line { grid-template-columns: 76px 1fr 1fr; }
				.au-line input[type=url] { grid-column: 1 / -1; }
				/* 2.60.1: Upload under the link, the bin alone at the right (it sat on the button) */
				.au-line .au-up { grid-column: 1 / 3; justify-self: start; }
				.au-line .au-del, .au-line .au-del-none { grid-column: 3; justify-self: end; }
				.au-del { width: 40px; height: 40px; border: 1px solid #dcdcde; border-radius: 10px; background: #fff; }
				.au-toolbar .au-search { min-width: 0; width: 100%; }
				.au-dl { grid-template-columns: 1fr; }
				.au-dl dt { border-bottom: 0; padding-bottom: 0; }
			}
		</style>
		<?php
	}

	public static function render_page() {
		global $wpdb;
		// Games and apps: not Roblox scripts, not members' versions (their main post is checked)
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_parent = 0 AND NOT " . self::scripts_sql('p'));
		$linked = (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND p.post_type = 'post' AND p.post_status = 'publish' AND NOT " . self::scripts_sql('p'));
		$checked = self::count_meta(self::CHECKED);
		$suggested = self::count_meta(self::LINK, 'suggested');
		$waiting = max(0, $total - $linked - $suggested - self::count_meta(self::LINK, 'nomatch') - self::count_meta(self::LINK, 'rejected'));
		$needs_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS)));
		$info_read = self::count_meta(self::INFO);
		$info_updated = (int) get_option('y4apk_vc_info_updated', 0);
		$info_any = (bool) array_filter(array_map([__CLASS__, 'info_on'], array_keys(self::info_fields())));
		$round_done = (int) get_option('y4apk_vc_round_done', 0);
		$last_step = (int) get_option('y4apk_vc_last_step', 0);

		$views = ['needs', 'auto', 'file', 'suggest', 'test', 'settings'];
		$view = isset($_GET['view']) && in_array($_GET['view'], $views, true) ? $_GET['view'] : (!empty($_GET['test']) ? 'test' : 'needs');
		if ($view === 'settings' && !current_user_can('manage_options')) {
			$view = 'needs';
		}
		$url = function ($v, $args = []) {
			return add_query_arg(array_merge(['page' => self::SLUG, 'view' => $v], $args), admin_url('admin.php'));
		};

		// Most downloaded first (last 30 days, Y4APK ▸ Analytics), then newest posts.
		$downloads = [];
		if (class_exists('Y4APK_Stats') && $needs_ids) {
			foreach ($wpdb->get_results($wpdb->prepare(
				'SELECT dim, SUM(n) AS n FROM ' . Y4APK_Stats::table() . " WHERE event = 'file_download' AND day >= %s GROUP BY dim",
				gmdate('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS))) as $r) {
				$downloads[(int) $r->dim] = (int) $r->n;
			}
		}
		// Google search first when there are numbers for it (impressions, then
		// clicks), else downloads; newest posts break ties.
		$gsc = self::gsc();
		$order = isset($_GET['order']) && in_array($_GET['order'], ['google', 'downloads'], true) ? $_GET['order'] : ($gsc['posts'] ? 'google' : 'downloads');
		$followers = class_exists('Y4APK_Push') ? Y4APK_Push::followers_of($needs_ids) : [];
		usort($needs_ids, function ($a, $b) use ($downloads, $gsc, $order) {
			$key = function ($id) use ($downloads, $gsc, $order) {
				$g = isset($gsc['posts'][$id]) ? $gsc['posts'][$id] : [0, 0];
				$d = isset($downloads[$id]) ? $downloads[$id] : 0;
				return $order === 'google' ? [$g[0], $g[1], $d, $id] : [$d, $g[0], $g[1], $id];
			};
			return $key($b) <=> $key($a);
		});
		// 2.50.5: only the posts whose links to other sites were taken off, waiting for a file
		$only_out = $view === 'needs' && isset($_GET['only']) && $_GET['only'] === 'outside' && current_user_can('manage_options');
		if ($only_out) {
			$wait = array_flip(self::outside_scan()['waiting']);
			$rest = array_values(array_diff(array_keys($wait), $needs_ids));
			$needs_ids = array_merge(array_values(array_filter($needs_ids, function ($id) use ($wait) {
				return isset($wait[$id]);
			})), $rest); // in the same order (Google first), then any not flagged yet
		}
		$paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
		$pages = max(1, (int) ceil(count($needs_ids) / self::PER_PAGE));
		$page_ids = $view === 'needs' ? array_slice($needs_ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE) : [];

		$suggest_ids = $view === 'suggest' ? array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'suggested' ORDER BY post_id DESC LIMIT 50", self::LINK))) : [];
		if ($page_ids || $suggest_ids) {
			_prime_post_caches(array_merge($page_ids, $suggest_ids), false, true);
		}

		// Background status, from the last step's age.
		if (!self::enabled()) {
			$status = ['is-grey', __('Switched off', 'y4apk')];
		} elseif (!$last_step) {
			$status = ['is-amber', __('Waiting for the first step', 'y4apk')];
		} else {
			$age = time() - $last_step;
			$ago = human_time_diff($last_step);
			$status = $age < 45 * MINUTE_IN_SECONDS ? ['', sprintf(__('Running · last step %s ago', 'y4apk'), $ago)]
				: ($age < 3 * HOUR_IN_SECONDS ? ['is-amber', sprintf(__('Slow · last step %s ago', 'y4apk'), $ago)]
				: ['is-red', sprintf(__('Stalled · last step %s ago — check the cron job', 'y4apk'), $ago)]);
		}
		$pct = function ($a, $b) {
			return $b > 0 ? min(100, round(100 * $a / $b, 1)) : 0;
		};
		self::styles();
		?>
		<div class="wrap y4apk-au">
			<div class="au-head">
				<div>
					<h1><?php esc_html_e('App updates', 'y4apk'); ?></h1>
					<p><?php esc_html_e('Every 15 minutes, games and apps (not Roblox scripts) are linked to their Google Play app and checked against its latest version. A newer version goes into the post by itself — its title, App Version, icon and screenshots follow Google Play, as set in Settings — and the post is listed here until you upload the new file, those Google Search shows most first. Update any file shows any post’s file, any time.', 'y4apk'); ?></p>
				</div>
				<div class="au-actions">
					<span class="au-pill"><span class="au-dot <?php echo esc_attr($status[0]); ?>"></span><?php echo esc_html($status[1]); ?></span>
					<?php if (current_user_can('manage_options')) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="y4apk_vc_run"><?php wp_nonce_field('y4apk-vc-run'); ?>
							<button class="button"><?php esc_html_e('Run a step now', 'y4apk'); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php if (!empty($_GET['y4apk_note']) && !empty($_GET['y4apk_saved'])) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html(sprintf(__('Saved. The file’s name on R2 could not follow its version (%s): it downloads under its first name.', 'y4apk'), (string) get_transient('y4apk_upload_note'))); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_saved'])) : $sid = absint($_GET['y4apk_saved']); ?>
				<div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('Saved: %s', 'y4apk'), '<a href="' . esc_url(get_permalink($sid)) . '" target="_blank">' . esc_html(get_the_title($sid)) . '</a>'); ?><?php if (!empty($_GET['y4apk_renaming'])) : ?> <?php esc_html_e('Its file is being renamed on R2 for its version (a big file takes a minute or two); it downloads all the while.', 'y4apk'); ?><?php endif; ?></p></div>
			<?php elseif (!empty($_GET['y4apk_gsc'])) : $g = self::gsc(); ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(__('Google Search numbers updated: %1$s pages, %2$s posts matched.', 'y4apk'), number_format_i18n($g['rows']), number_format_i18n(count($g['posts'])))); ?></p></div>
			<?php elseif (isset($_GET['outside'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['outside'] === 'back'
					? sprintf(__('Put back on %s posts.', 'y4apk'), number_format_i18n(absint($_GET['n'] ?? 0)))
					: sprintf(__('Downloads on other sites taken off %s posts: those with no file of Y4APK’s own wait in Need a new file. Clear the 10Web cache so visitors see it.', 'y4apk'), number_format_i18n(absint($_GET['n'] ?? 0)))); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_settings'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'y4apk'); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_error'])) : ?>
				<div class="notice notice-error"><p><?php
					$errors = [
						'id'        => __('That is not a Google Play link or app ID.', 'y4apk'),
						'same-link' => __('Nothing saved: “Add” keeps the old download, so the new one needs the new file’s link. Paste it, or choose “Replace”.', 'y4apk'),
						'changed'   => __('Nothing saved: the post’s downloads were changed after this page was opened. Here they are as they are now.', 'y4apk'),
						'gsc'       => sprintf(__('Google Search numbers not updated: %s', 'y4apk'), (string) get_transient('y4apk_gsc_error')),
						'upload'    => sprintf(__('Nothing saved: %s', 'y4apk'), (string) get_transient('y4apk_upload_error')),
						'nofile'    => __('Nothing saved: upload the file first (its download link is made for you), or paste a link to it.', 'y4apk'),
					];
					echo esc_html(isset($errors[$_GET['y4apk_error']]) ? $errors[$_GET['y4apk_error']] : __('Enter the new version and the download link.', 'y4apk')); ?></p></div>
			<?php elseif (isset($_GET['ran'])) : $ran = array_map('intval', explode('.', sanitize_text_field(wp_unslash($_GET['ran'])))) + [0, 0, 0, 0]; ?>
				<div class="notice notice-<?php echo $ran[3] ? 'warning' : 'success'; ?> is-dismissible"><p><?php
					printf(esc_html__('Step done: %1$d posts searched for a Google Play link, %2$d looked up on Google Play, %3$d versions checked.', 'y4apk'), $ran[0], $ran[1], $ran[2]);
					if ($ran[3]) {
						echo ' ' . esc_html__('Google Play stopped answering for now; the next step carries on.', 'y4apk');
					} ?></p></div>
			<?php endif; ?>

			<div class="au-kpis">
				<div class="au-card au-kpi">
					<span class="au-kpi-label"><?php esc_html_e('Linked to Google Play', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($linked)); ?> <small>/ <?php echo esc_html(number_format_i18n($total)); ?></small></span>
					<span class="au-bar"><span style="width:<?php echo esc_attr($pct($linked, $total)); ?>%"></span></span>
					<span class="au-kpi-sub"><?php echo $waiting > 0 ? esc_html(sprintf(__('%s still to look up', 'y4apk'), number_format_i18n($waiting))) : esc_html__('Every post looked up', 'y4apk'); ?></span>
				</div>
				<div class="au-card au-kpi">
					<span class="au-kpi-label"><?php esc_html_e('Versions checked', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n(min($checked, $linked))); ?> <small>/ <?php echo esc_html(number_format_i18n($linked)); ?></small></span>
					<span class="au-bar"><span style="width:<?php echo esc_attr($pct(min($checked, $linked), $linked)); ?>%"></span></span>
					<span class="au-kpi-sub"><?php echo $round_done ? esc_html(sprintf(__('Last full round finished %s ago', 'y4apk'), human_time_diff($round_done))) : esc_html__('First round in progress', 'y4apk'); ?></span>
				</div>
				<a class="au-card au-kpi<?php echo $needs_ids ? ' is-alert' : ''; ?>" href="<?php echo esc_url($url('needs')); ?>">
					<span class="au-kpi-label"><?php esc_html_e('Need a new file', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n(count($needs_ids))); ?></span>
					<span class="au-kpi-sub"><?php esc_html_e('Newer on Google Play than your file', 'y4apk'); ?></span>
				</a>
				<a class="au-card au-kpi" href="<?php echo esc_url($url('suggest')); ?>">
					<span class="au-kpi-label"><?php esc_html_e('Suggestions to confirm', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($suggested)); ?></span>
					<span class="au-kpi-sub"><?php esc_html_e('Play apps with a similar name', 'y4apk'); ?></span>
				</a>
				<div class="au-card au-kpi">
					<span class="au-kpi-label"><?php esc_html_e('App Information from Play', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($info_read)); ?> <small><?php esc_html_e('posts read', 'y4apk'); ?></small></span>
					<span class="au-kpi-sub"><?php echo $info_any
						? esc_html(sprintf(__('%1$s updates applied · %2$s new versions put in · %3$s store', 'y4apk'), number_format_i18n($info_updated), number_format_i18n((int) get_option('y4apk_vc_followed', 0)), self::country()))
						: esc_html__('Not applied (switched off in Settings)', 'y4apk'); ?></span>
				</div>
			</div>

			<nav class="au-tabs" aria-label="<?php esc_attr_e('App updates', 'y4apk'); ?>">
				<a class="au-tab<?php echo $view === 'needs' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('needs')); ?>"><?php esc_html_e('Need a new file', 'y4apk'); ?><span class="au-count"><?php echo esc_html(number_format_i18n(count($needs_ids))); ?></span></a>
				<a class="au-tab<?php echo $view === 'auto' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('auto')); ?>"><?php esc_html_e('Auto updates', 'y4apk'); ?></a>
				<a class="au-tab<?php echo $view === 'file' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('file')); ?>"><?php esc_html_e('Update any file', 'y4apk'); ?></a>
				<a class="au-tab<?php echo $view === 'suggest' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('suggest')); ?>"><?php esc_html_e('Suggestions', 'y4apk'); ?><span class="au-count"><?php echo esc_html(number_format_i18n($suggested)); ?></span></a>
				<a class="au-tab<?php echo $view === 'test' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('test')); ?>"><?php esc_html_e('Test one app', 'y4apk'); ?></a>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="au-tab<?php echo $view === 'settings' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a>
				<?php endif; ?>
			</nav>

			<?php if ($view === 'needs') :
				$os = current_user_can('manage_options') ? self::outside_scan() : null;
				if ($os && ($os['posts'] || $os['back'] || !empty($os['waiting']))) : ?>
				<div class="au-card au-pad au-outside">
					<?php if (!empty($os['waiting'])) : ?>
						<div class="au-out-line">
							<strong><?php echo esc_html(sprintf(_n('%s post waits for your file', '%s posts wait for your file', count($os['waiting']), 'y4apk'), number_format_i18n(count($os['waiting'])))); ?></strong>
							<span class="au-small"><?php esc_html_e('Their links to other sites were taken off. Upload each one’s file and Save: it leaves this list.', 'y4apk'); ?></span>
							<?php if ($only_out) : ?>
								<a class="button" href="<?php echo esc_url($url('needs')); ?>"><?php esc_html_e('Show all posts', 'y4apk'); ?></a>
							<?php else : ?>
								<a class="button button-primary" href="<?php echo esc_url($url('needs', ['only' => 'outside'])); ?>"><?php esc_html_e('Show them', 'y4apk'); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php if ($os['posts']) : ?>
						<div class="au-out-line">
							<strong><?php echo esc_html(sprintf(_n('%1$s post has a download on another site (%2$s link)', '%1$s posts have downloads on other sites (%2$s links)', $os['posts'], 'y4apk'), number_format_i18n($os['posts']), number_format_i18n($os['links']))); ?></strong>
							<span class="au-small"><?php echo esc_html(implode(' · ', array_map(function ($h, $n) { return $h . ' ' . number_format_i18n($n); }, array_slice(array_keys($os['hosts']), 0, 5), array_slice($os['hosts'], 0, 5)))); ?></span>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="au-outside-actions">
								<input type="hidden" name="action" value="y4apk_vc_outside"><?php wp_nonce_field('y4apk-vc-outside'); ?>
								<button class="button button-primary" onclick="return confirm(<?php echo esc_attr(wp_json_encode(sprintf(__('Take the downloads on other sites off %s posts? They wait for your files after that.', 'y4apk'), number_format_i18n($os['posts'])))); ?>)"><?php esc_html_e('Take them off', 'y4apk'); ?></button>
							</form>
						</div>
						<p class="au-note"><?php esc_html_e('Take them off: those links leave the posts, and a post with no file of Y4APK’s own left waits for your upload — visitors see no download on it until then. Clear the 10Web cache afterwards.', 'y4apk'); ?></p>
					<?php endif; ?>
					<?php if ($os['back']) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="au-out-back">
							<input type="hidden" name="action" value="y4apk_vc_outside"><?php wp_nonce_field('y4apk-vc-outside'); ?>
							<span class="au-small"><?php echo esc_html(sprintf(_n('Links taken off %s post are kept, in case you want them back.', 'Links taken off %s posts are kept, in case you want them back.', $os['back'], 'y4apk'), number_format_i18n($os['back']))); ?></span>
							<button class="button-link" name="undo" value="1" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Put the links taken off back on their posts?', 'y4apk'))); ?>)"><?php esc_html_e('Put them back', 'y4apk'); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="au-toolbar">
					<input type="search" class="au-search" data-au-filter="au-needs" placeholder="<?php esc_attr_e('Filter this page by name…', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Filter this page by name', 'y4apk'); ?>">
					<span class="au-toolbar-info">
						<?php esc_html_e('Order:', 'y4apk'); ?>
						<a href="<?php echo esc_url($url('needs', array_filter(['order' => 'google', 'only' => $only_out ? 'outside' : '']))); ?>" class="<?php echo $order === 'google' ? 'is-on' : ''; ?>"><?php esc_html_e('Google search', 'y4apk'); ?></a> ·
						<a href="<?php echo esc_url($url('needs', array_filter(['order' => 'downloads', 'only' => $only_out ? 'outside' : '']))); ?>" class="<?php echo $order === 'downloads' ? 'is-on' : ''; ?>"><?php esc_html_e('Downloads', 'y4apk'); ?></a>
						· <?php echo esc_html(sprintf(__('%1$s posts · page %2$s of %3$s', 'y4apk'), number_format_i18n(count($needs_ids)), number_format_i18n($paged), number_format_i18n($pages))); ?>
					</span>
				</div>
				<?php if (!$gsc['posts']) : ?>
					<p class="au-note" style="margin:0 0 12px"><?php printf(esc_html__('No Google Search numbers yet, so posts are ordered by downloads. %s to see first the posts Google shows most.', 'y4apk'),
						'<a href="' . esc_url($url('settings')) . '#au-gsc">' . esc_html__('Connect them in Settings', 'y4apk') . '</a>'); ?></p>
				<?php elseif ($order === 'google') : ?>
					<p class="au-note" style="margin:0 0 12px"><?php echo esc_html(sprintf(__('Ordered by how often Google showed each post in search (%1$s), from Search Console, updated %2$s ago.', 'y4apk'),
						$gsc['from'] ? $gsc['from'] . ' – ' . $gsc['to'] : __('your export', 'y4apk'), human_time_diff($gsc['synced']))); ?></p>
				<?php endif; ?>
				<div class="au-list" id="au-needs">
				<?php if (!$page_ids) : ?>
					<div class="au-card au-empty"><?php echo $checked
						? esc_html__('Every checked post is up to date.', 'y4apk')
						: esc_html__('Nothing checked yet — results appear as posts get linked and checked.', 'y4apk'); ?></div>
				<?php endif; ?>
				<?php foreach ($page_ids as $id) {
					self::file_row($id, ['paged' => $paged, 'gsc' => $gsc, 'downloads' => $downloads, 'followers' => $followers]);
				} ?>
				</div>
				<?php if ($pages > 1) : ?>
					<div class="au-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>
				<p class="au-note"><?php esc_html_e('Save puts the new version, size and link on the post’s newest APK download and in App Information, changes the version in the title and in the Rank Math SEO title, description and social titles (for example v2.742 → v2.750), and marks the post as updated today. Downloads with the same version (OBB, IPA) are listed under it with the new version filled in: ticked ones are updated too (an iOS file starts unticked). Older versions are left as they are. Replace (the default) swaps the old files for the new ones and keeps their links; Add puts each new file just above its old one, which stays as an older version (it needs another link: upload the file, or paste one). The size and link start with what the post has now, so change only what is different for the new file.', 'y4apk'); ?></p>

			<?php elseif ($view === 'auto') :
				self::render_auto($url);

			elseif ($view === 'file') :
				$file_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
				$q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
				if (!$file_id && $q !== '' && ($found = url_to_postid($q))) {
					$file_id = $found; // its address
				} elseif (!$file_id && ctype_digit($q)) {
					$file_id = (int) $q;
				}
				$file_ok = $file_id && get_post_type($file_id) === 'post' && current_user_can('edit_post', $file_id) && !self::is_script_post($file_id) && !get_post_meta($file_id, '_y4apk_parent', true); ?>
				<div class="au-card au-pad" style="margin-bottom:14px">
					<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="au-toolbar" style="margin:0">
						<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
						<input type="hidden" name="view" value="file">
						<input type="search" name="q" class="au-search" style="flex:1" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Game or app name, or its post’s address', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Find a post', 'y4apk'); ?>">
						<button class="button button-primary"><?php esc_html_e('Find', 'y4apk'); ?></button>
					</form>
					<p class="au-note" style="margin-top:10px"><?php esc_html_e('Upload a new file for any post, whenever you like — also when Google Play has nothing newer, or a version newer than Google Play’s (a beta, a new MOD). The editor’s “File and Google Play” box opens this too.', 'y4apk'); ?></p>
				</div>
				<?php if ($file_ok) : ?>
					<div class="au-list"><?php self::file_row($file_id, ['back' => 'file', 'gsc' => self::gsc()]); ?></div>
				<?php elseif ($q !== '') :
					$hits = array_values(array_filter(get_posts(['post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'numberposts' => 30, 'post_parent' => 0, 'orderby' => 'relevance', 'suppress_filters' => false]), function ($p) {
						return !self::is_script_post($p->ID);
					})); ?>
					<div class="au-list">
						<?php if (!$hits) : ?>
							<div class="au-card au-empty"><?php esc_html_e('No game or app post with that name.', 'y4apk'); ?></div>
						<?php endif; ?>
						<?php foreach (array_slice($hits, 0, 20) as $p) :
							$cur = self::current_version($p->ID);
							$lat = (string) get_post_meta($p->ID, self::LATEST, true); ?>
							<div class="au-card au-srow au-hit">
								<div class="au-app">
									<?php echo self::icon($p->ID); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<div style="min-width:0"><a class="au-title" href="<?php echo esc_url($url('file', ['post' => $p->ID])); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
										<span class="au-small"><?php echo esc_html(trim(sprintf(__('File %s', 'y4apk'), $cur !== '' ? $cur : '—') . ($lat !== '' ? ' · ' . sprintf(__('Google Play %s', 'y4apk'), $lat) : ''))); ?></span></div>
								</div>
								<a class="button button-primary" href="<?php echo esc_url($url('file', ['post' => $p->ID])); ?>"><?php esc_html_e('Update the file', 'y4apk'); ?></a>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			<?php elseif ($view === 'suggest') : ?>
				<form id="y4apk-bulk" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="y4apk_vc_bulk"><?php wp_nonce_field('y4apk-vc-bulk'); ?>
				</form>
				<div class="au-toolbar">
					<span class="au-toolbar-info"><?php esc_html_e('Google Play found an app for these posts, but not with exactly the same name and publisher — or the post has no App Name. Use it, type the right ID, or mark the post as not on Google Play.', 'y4apk'); ?></span>
					<?php if ($suggest_ids) : ?>
						<span class="au-actions">
							<label><input type="checkbox" aria-label="<?php esc_attr_e('Select all', 'y4apk'); ?>" onclick="document.querySelectorAll('.y4apk-pick').forEach(function (c) { c.checked = this.checked; }, this)"> <?php esc_html_e('Select all', 'y4apk'); ?></label>
							<button class="button" form="y4apk-bulk"><?php esc_html_e('Not on Google Play — all ticked', 'y4apk'); ?></button>
						</span>
					<?php endif; ?>
				</div>
				<div class="au-list" id="y4apk-link">
				<?php if (!$suggest_ids) : ?>
					<div class="au-card au-empty"><?php esc_html_e('No suggestions waiting.', 'y4apk'); ?></div>
				<?php endif; ?>
				<?php foreach ($suggest_ids as $id) :
					$s = json_decode((string) get_post_meta($id, self::SUGGEST, true), true);
					if (!is_array($s) || empty($s['id'])) {
						continue;
					} ?>
					<div class="au-card au-srow">
						<input type="checkbox" class="y4apk-pick" name="posts[]" value="<?php echo esc_attr($id); ?>" form="y4apk-bulk" aria-label="<?php esc_attr_e('Select', 'y4apk'); ?>">
						<div class="au-app">
							<?php echo self::icon($id); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<div style="min-width:0"><span class="au-label"><?php esc_html_e('Post', 'y4apk'); ?></span>
								<a class="au-title" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a>
								<span class="au-small"><?php echo esc_html(trim(get_post_meta($id, 'wp_title_GP', true) . ' · ' . get_post_meta($id, 'wp_developers_GP', true), ' ·')); ?></span></div>
						</div>
						<div><span class="au-label"><?php esc_html_e('Suggested Google Play app', 'y4apk'); ?></span>
							<a class="au-title" href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($s['id'])); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($s['title'] ?: $s['id']); ?> ↗</a>
							<span class="au-small"><?php echo esc_html(trim(($s['developer'] ?? '') . ' · ' . $s['id'], ' ·')); ?></span></div>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="y4apk_vc_link">
							<input type="hidden" name="post" value="<?php echo esc_attr($id); ?>">
							<?php wp_nonce_field('y4apk-vc-link-' . $id); ?>
							<input name="id" value="<?php echo esc_attr($s['id']); ?>" aria-label="<?php esc_attr_e('Google Play ID', 'y4apk'); ?>">
							<button class="button button-primary"><?php esc_html_e('Use', 'y4apk'); ?></button>
							<button class="button" name="reject" value="1"><?php esc_html_e('Not on Google Play', 'y4apk'); ?></button>
						</form>
					</div>
				<?php endforeach; ?>
				</div>
				<?php if ($suggested > count($suggest_ids) && $suggest_ids) : ?>
					<p class="au-note"><?php echo esc_html(sprintf(__('Showing the newest %1$s of %2$s; the rest appear as these are done.', 'y4apk'), count($suggest_ids), number_format_i18n($suggested))); ?></p>
				<?php endif; ?>
				<p class="au-note"><?php esc_html_e('You can also put the ID in the post’s App Information ▸ Google Play ID.', 'y4apk'); ?></p>

			<?php elseif ($view === 'test') : ?>
				<div class="au-card au-pad" style="margin-bottom:14px">
					<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="au-toolbar" style="margin:0">
						<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
						<input type="hidden" name="view" value="test">
						<input name="test" class="au-search" style="flex:1" value="<?php echo esc_attr(isset($_GET['test']) ? sanitize_text_field(wp_unslash($_GET['test'])) : ''); ?>" placeholder="https://play.google.com/store/apps/details?id=com.eurosport" aria-label="<?php esc_attr_e('Google Play link or app ID', 'y4apk'); ?>">
						<button class="button button-primary"><?php esc_html_e('Test', 'y4apk'); ?></button>
					</form>
					<p class="au-note" style="margin-top:10px"><?php esc_html_e('What this server reads from Google Play and Aptoide for any app — to compare with the Play Store yourself. Nothing is saved.', 'y4apk'); ?></p>
				</div>
				<?php if (!empty($_GET['test'])) {
					self::render_test(sanitize_text_field(wp_unslash($_GET['test'])));
				} ?>

			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="y4apk_vc_settings">
					<?php wp_nonce_field('y4apk-vc-settings'); ?>
					<div class="au-settings">
						<div class="au-card au-pad">
							<h2><?php esc_html_e('Background checks', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Every 15 minutes: link up to 100 posts by the Google Play link in their content, look up 6 on Google Play, and check 20 versions.', 'y4apk'); ?></p>
							<label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Link posts and check versions in the background', 'y4apk'); ?></label>
						</div>
						<div class="au-card au-pad">
							<h2><?php esc_html_e('Google Play store', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Ratings and age ratings differ by country: these are read from this country’s Play Store (two letters, e.g. TH Thailand, US United States). Apps not offered there are read from the US store.', 'y4apk'); ?></p>
							<label><input type="text" name="country" value="<?php echo esc_attr(self::country()); ?>" maxlength="2" style="width:70px;text-transform:uppercase" aria-label="<?php esc_attr_e('Country', 'y4apk'); ?>"></label>
						</div>
						<div class="au-card au-pad">
							<h2><?php esc_html_e('Keep App Information up to date', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Each time a post’s version is checked, these fields are set to what Google Play shows. Empty values never overwrite, and the post’s updated date does not change.', 'y4apk'); ?></p>
							<?php foreach (self::info_fields() as $key => $field) : ?>
								<label><input type="checkbox" name="info[<?php echo esc_attr($key); ?>]" value="1" <?php checked(self::info_on($key)); ?>> <?php echo esc_html($field[0]); ?></label>
							<?php endforeach; ?>
						</div>
						<div class="au-card au-pad">
							<h2><?php esc_html_e('Follow Google Play by itself', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Each time a post is checked (the posts Google shows most every day, the others every few days). Roblox scripts are left out: they are not on Google Play.', 'y4apk'); ?></p>
							<?php foreach (self::auto_parts() as $key => $part) : ?>
								<label><input type="checkbox" name="auto[<?php echo esc_attr($key); ?>]" value="1" <?php checked(self::auto_on($key)); ?>> <?php echo esc_html($part[0]); ?></label>
							<?php endforeach; ?>
							<?php if (!class_exists('Y4APK_Icons') || !Y4APK_Icons::ready()) : ?>
								<p class="au-note" style="color:#b32d2e"><?php esc_html_e('Icons need R2 (below): until it is set up, they are left as they are.', 'y4apk'); ?></p>
							<?php endif; ?>
						</div>
						<div class="au-card au-pad">
							<h2><?php esc_html_e('On each post', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Shows visitors the file is fresh and how to install it.', 'y4apk'); ?></p>
							<?php foreach (self::post_parts() as $key => $part) : ?>
								<label><input type="checkbox" name="parts[<?php echo esc_attr($key); ?>]" value="1" <?php checked(self::post_part_on($key)); ?>> <?php echo esc_html($part[0]); ?></label>
							<?php endforeach; ?>
						</div>
					</div>
					<p><button class="button button-primary"><?php esc_html_e('Save settings', 'y4apk'); ?></button></p>
				</form>
				<?php $g = self::gsc(); ?>
				<div class="au-card au-pad" id="au-gsc" style="margin-top:14px">
					<h2 style="font-size:15px;margin:0 0 6px"><?php esc_html_e('Google Search numbers (Search Console)', 'y4apk'); ?></h2>
					<p class="au-note" style="margin:0 0 12px"><?php esc_html_e('How often Google showed and people clicked each post in the last 28 days. They order “Need a new file”, and the posts Google shows most get their version checked every day instead of once a round.', 'y4apk'); ?></p>
					<p style="margin:0 0 12px"><strong><?php
						echo $g['posts']
							? esc_html(sprintf(__('%1$s posts with numbers, from %2$s, updated %3$s ago.', 'y4apk'), number_format_i18n(count($g['posts'])),
								$g['source'] === 'csv' ? __('an uploaded export', 'y4apk') : __('Site Kit', 'y4apk'), human_time_diff($g['synced'])))
							: esc_html__('No numbers yet.', 'y4apk'); ?></strong></p>
					<div class="au-settings">
						<div>
							<h2><?php esc_html_e('From Site Kit (every day)', 'y4apk'); ?></h2>
							<p><?php echo self::sitekit_active()
								? esc_html__('Site Kit is active. Sync once here; after that the numbers update by themselves once a day.', 'y4apk')
								: esc_html__('Site Kit by Google is not active. Turn it on and connect Search Console, or upload an export.', 'y4apk'); ?></p>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="y4apk_gsc_sync"><?php wp_nonce_field('y4apk-gsc-sync'); ?>
								<button class="button button-primary" <?php disabled(!self::sitekit_active()); ?>><?php esc_html_e('Sync from Site Kit now', 'y4apk'); ?></button>
							</form>
						</div>
						<div>
							<h2><?php esc_html_e('Or upload an export', 'y4apk'); ?></h2>
							<p><?php esc_html_e('Search Console ▸ Performance ▸ Search results ▸ Pages ▸ Export ▸ Download CSV. Upload the ZIP, or the Pages.csv inside it.', 'y4apk'); ?></p>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
								<input type="hidden" name="action" value="y4apk_gsc_upload"><?php wp_nonce_field('y4apk-gsc-upload'); ?>
								<input type="file" name="gsc" accept=".zip,.csv">
								<button class="button"><?php esc_html_e('Upload', 'y4apk'); ?></button>
							</form>
						</div>
					</div>
				</div>
				<?php if (class_exists('Y4APK_Push')) {
					Y4APK_Push::settings_card();
				}
				if (class_exists('Y4APK_R2')) {
					Y4APK_R2::settings_card();
				} ?>
				<div class="au-card au-pad" style="margin-top:14px">
					<h2 style="font-size:15px;margin:0 0 6px"><?php esc_html_e('Server cron job (Hostinger)', 'y4apk'); ?></h2>
					<p class="au-note" style="margin:0 0 10px"><?php esc_html_e('WordPress runs background steps when someone opens an uncached page, so with a page cache they can come rarely. A cron job in hPanel ▸ Advanced ▸ Cron Jobs (type PHP, every 15 or 30 minutes) runs them on time. Its command:', 'y4apk'); ?></p>
					<code class="au-code"><?php echo esc_html('/usr/bin/php ' . ABSPATH . 'wp-cron.php'); ?></code>
				</div>
			<?php endif; ?>
		</div>
		<script>
			// "Add as new" with the old link and no upload: say so here, before anything is sent.
			document.querySelectorAll('.au-form').forEach(function (form) {
				form.addEventListener('submit', function (e) {
					var addR = form.querySelector('input[name=mode][value=add]'), add = !!(addR && addR.checked);
					var key = form.querySelector('.au-line[data-row=main] .au-up-key'), link = form.querySelector('input[name=url]');
					var need = form.querySelector('.au-need');
					if (need) {
						need.hidden = !!(link.value.trim() || (key && key.value));
						if (!need.hidden) {
							e.preventDefault();
							return;
						}
					}
					var url = form.querySelector('input[name=url]'), up = form.querySelector('.au-line[data-row=main] .au-up-key');
					var same = add && url.value.trim() === url.getAttribute('data-orig') && !(up && up.value);
					form.querySelector('.au-warn:not(.au-need)').hidden = !same;
					if (same) {
						e.preventDefault();
					}
				});
				form.querySelectorAll('input[type=radio][name=mode]').forEach(function (r) {
					r.addEventListener('change', function () { form.querySelector('.au-warn:not(.au-need)').hidden = true; });
				});
			});
			// 2.50.3: the type — "v5.9.1.apk" becomes "v5.9.1.xapk" for XAPK; an .xapk, .apks, .obb or .zip file picks its type
			var exts = { MOD: 'apk', APK: 'apk', XAPK: 'xapk', APKS: 'apks', OBB: 'zip' };
			function ending(sel) {
				var v = sel.closest('.au-line').querySelector('input[name="version"], input[name$="[version]"]');
				if (v && exts[sel.value]) {
					v.value = v.value.replace(/\.(apk|xapk|apks|apkm|zip|obb)$/i, '.' + exts[sel.value]);
				}
			}
			document.addEventListener('change', function (e) {
				var f = e.target, line = f.closest && f.closest('.au-line');
				if (!line) {
					return;
				}
				// 2.50.4: the new file (or a download following it) was touched: Save updates it
				var row = line.getAttribute('data-row') || '';
				if (row === 'main' || /^\d+$/.test(row)) {
					f.form.querySelector('input[name=main_dirty]').value = '1';
				}
				if (f.matches('select.au-type')) {
					ending(f);
					return;
				}
				if (f.type !== 'file' || !f.closest('.au-up') || !f.files.length) {
					return;
				}
				var sel = line.querySelector('select.au-type');
				var ext = (f.files[0].name.split('.').pop() || '').toLowerCase();
				var to = { xapk: 'XAPK', apks: 'APKS', obb: 'OBB', zip: 'OBB' }[ext];
				if (sel && to && sel.value !== to && [].some.call(sel.options, function (o) { return o.value === to; })) {
					sel.value = to;
					ending(sel);
				}
			}, true); // before the upload starts, so it is named with its type
			document.addEventListener('input', function (e) {
				var line = e.target.closest && e.target.closest('.au-line');
				var row = line ? line.getAttribute('data-row') || '' : '';
				if (row === 'main' || /^\d+$/.test(row)) {
					e.target.form.querySelector('input[name=main_dirty]').value = '1';
				}
			});
			// 2.50.4: the bin takes a download off (click again to keep it); a file added here just goes
			document.addEventListener('click', function (e) {
				var bin = e.target.closest && e.target.closest('.au-del');
				if (bin) {
					e.preventDefault();
					var line = bin.closest('.au-line'), box = bin.querySelector('input[type=checkbox]');
					if (!box) {
						line.remove();
						return;
					}
					box.checked = !box.checked;
					line.classList.toggle('is-del', box.checked);
					return;
				}
				var add = e.target.closest && e.target.closest('.au-add');
				if (add) {
					var form = add.closest('form'), tpl = form.querySelector('.au-extra-tpl');
					var n = (form.au_n = (form.au_n || 0) + 1);
					var wrap = document.createElement('div');
					wrap.innerHTML = tpl.innerHTML.replace(/__N__/g, String(n));
					var line = wrap.firstElementChild;
					form.querySelector('.au-extras').appendChild(line);
					// The new file's version to start with, ending as its type
					var main = form.querySelector('.au-line[data-row=main] input[name=version]');
					var v = line.querySelector('input[name$="[version]"]');
					v.value = main ? main.value : '';
					ending(line.querySelector('select.au-type'));
					v.focus();
				}
			});
			document.querySelectorAll('[data-au-filter]').forEach(function (input) {
				var list = document.getElementById(input.getAttribute('data-au-filter'));
				input.addEventListener('input', function () {
					var q = input.value.trim().toLowerCase();
					list.querySelectorAll('[data-title]').forEach(function (row) {
						row.hidden = q !== '' && row.getAttribute('data-title').indexOf(q) === -1;
					});
				});
			});
		</script>
		<?php
	}
}

Y4APK_App_Updates::init();
