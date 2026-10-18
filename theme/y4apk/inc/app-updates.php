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
	const NO_FILE      = '_y4apk_no_file';    // the download folder Write with AI made, its page still waiting for a file
	const PER_PAGE     = 50;
	const PRIORITY     = 8;   // of the 20 checks a step, for the posts Google shows most and people follow
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
		add_filter('manage_post_posts_columns', [__CLASS__, 'column']);
		add_action('manage_post_posts_custom_column', [__CLASS__, 'column_value'], 10, 2);
		add_filter('views_edit-post', [__CLASS__, 'view_link']);
		add_action('pre_get_posts', [__CLASS__, 'filter_list']);
		add_filter('post_row_actions', [__CLASS__, 'row_action'], 10, 2);
		add_action('admin_notices', [__CLASS__, 'check_notice']);
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
		$r = self::get('https://ws2.aptoide.com/api/7/app/getMeta/package_name=' . rawurlencode($package));
		if ($r === null || $r[0] === 429 || $r[0] >= 500) {
			return null;
		}
		$data = json_decode($r[1], true);
		$v = isset($data['data']['file']['vername']) ? $data['data']['file']['vername'] : '';
		// Plain dotted numbers only: Aptoide also lists other builds of an app
		// ("1009.0-wv" for Brawl Stars, whose Play version is 69.414).
		return $r[0] === 200 && preg_match('/^\d+(?:\.\d+){0,4}$/', (string) $v) ? $v : '';
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

	public static function check_post($post_id) {
		$package = self::package_from(get_post_meta($post_id, 'wp_GP_ID', true));
		if ($package === '') {
			return false;
		}
		$play = self::play_app($package);
		$latest = self::latest($package, $play);
		if ($latest === null) {
			return null;
		}
		if ($play['status'] === 'ok') {
			self::apply_info($post_id, $play);
		}
		update_post_meta($post_id, self::LATEST, $latest['version']);
		update_post_meta($post_id, self::SOURCE, $latest['source']);
		update_post_meta($post_id, self::CHECKED, time());
		self::refresh_flag($post_id);
		return true;
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
		return ['version' => $n, 'lines' => explode("\n", $i['whatsnew']), 'updated' => isset($i['updated']) ? (int) $i['updated'] : 0];
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
			if (isset($linked[$id]) && (!isset($checked[$id]) || $checked[$id] < time() - DAY_IN_SECONDS) && get_post_status($id) === 'publish') {
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
			 WHERE p.post_type = 'post' AND p.post_status = 'publish'
			   AND (g.meta_value IS NULL OR g.meta_value = '') AND $where
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

	/* ---------------------------------------------------------------- *
	 * The background step
	 * ---------------------------------------------------------------- */

	public static function tick() {
		$done = ['scanned' => 0, 'searched' => 0, 'checked' => 0, 'busy' => false];
		if (!self::enabled() || get_transient('y4apk_vc_lock')) {
			return $done;
		}
		set_transient('y4apk_vc_lock', 1, 5 * MINUTE_IN_SECONDS);

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
				   AND p.post_type = 'post' AND p.post_status = 'publish'
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
		return $done;
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
		if ($post->post_type === 'post' && get_post_meta($post->ID, 'wp_GP_ID', true) && current_user_can('edit_post', $post->ID)) {
			$actions['y4apk_check'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_vc_check&post=' . $post->ID), 'y4apk-vc-check-' . $post->ID)) . '">'
				. esc_html__('Check version now', 'y4apk') . '</a>';
		}
		return $actions;
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
		add_submenu_page('y4apk-gplay', __('App updates', 'y4apk'), __('App updates', 'y4apk'),
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
		$in = wp_unslash($_POST);
		$version = sanitize_text_field(isset($in['version']) ? $in['version'] : '');
		$size = sanitize_text_field(isset($in['size']) ? $in['size'] : '');
		$url = esc_url_raw(isset($in['url']) ? trim($in['url']) : '');
		$back = add_query_arg(['page' => self::SLUG, 'paged' => isset($in['paged']) ? absint($in['paged']) : 1], admin_url('admin.php'));

		$add = isset($in['mode']) && $in['mode'] === 'add';

		list($index, $link) = self::download($post_id);
		if ($index === null || $version === '' || $url === '') {
			wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
			exit;
		}
		if (!isset($in['sig']) || $in['sig'] !== self::links_sig($post_id)) {
			wp_safe_redirect(add_query_arg('y4apk_error', 'changed', $back));
			exit;
		}

		// The APK download, and each ticked download with the same version;
		// with a file uploaded to R2 for it, its key.
		$updates = [$index => ['download_version' => $version, 'download_size' => $size, 'download_url' => $url]];
		$uploads = [];
		if (!empty($in['r2'])) {
			$uploads[$index] = sanitize_text_field($in['r2']);
		}
		$rows = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [];
		$others = self::other_downloads($post_id);
		foreach ($others['same'] as $i => $other) {
			if (empty($rows[$i]['on'])) {
				continue;
			}
			$r = $rows[$i];
			$updates[$i] = [
				'download_version' => sanitize_text_field(isset($r['version']) ? $r['version'] : ''),
				'download_size'    => sanitize_text_field(isset($r['size']) ? $r['size'] : ''),
				'download_url'     => esc_url_raw(isset($r['url']) ? trim($r['url']) : ''),
			];
			if ($updates[$i]['download_version'] === '' || $updates[$i]['download_url'] === '') {
				wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
				exit;
			}
			if (!empty($r['r2'])) {
				$uploads[$i] = sanitize_text_field($r['r2']);
			}
		}

		// Replace: the new files take the old ones' place. Add: each new file
		// goes just above the one it follows, which stays as an older version
		// — so it needs another link (an upload makes one).
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		foreach ($updates as $i => $u) {
			if ($add && !isset($uploads[$i]) && $u['download_url'] === html_entity_decode((string) (isset($links[$i]['download_url']) ? $links[$i]['download_url'] : ''))) {
				wp_safe_redirect(add_query_arg('y4apk_error', 'same-link', $back));
				exit;
			}
		}

		// Uploaded files: point the download pages at them, now that the form is good.
		foreach ($uploads as $i => $key) {
			$r = class_exists('Y4APK_R2')
				? Y4APK_R2::attach($post_id, $key, html_entity_decode((string) $links[$i]['download_url']), isset($links[$i]['download_name']) ? $links[$i]['download_name'] : 'MOD', !$add)
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
		}

		// A folder Write with AI made has its file now (or the post another link).
		if (isset($uploads[$index]) || $updates[$index]['download_url'] !== (string) get_post_meta($post_id, self::NO_FILE, true)) {
			delete_post_meta($post_id, self::NO_FILE);
		}

		$new_links = [];
		foreach ($links as $i => $entry) {
			if (!isset($updates[$i])) {
				$new_links[] = $entry;
				continue;
			}
			$u = $updates[$i];
			if ($u['download_size'] === '') {
				unset($u['download_size']);
			}
			$new_links[] = array_merge($entry, $u);
			if ($add) {
				$new_links[] = $entry;
			}
		}

		$old = self::number(isset($link['download_version']) ? $link['download_version'] : '');
		$new = self::number($version);
		if ($size !== '') {
			update_post_meta($post_id, 'wp_sizes_GP', $size);
		}
		update_post_meta($post_id, 'repeatable_download_link', $new_links);
		if ($new !== '') {
			update_post_meta($post_id, 'wp_version_GP', $new);
		}

		// The version follows in the title and the Rank Math SEO title,
		// description and social titles, when the old one is plainly there
		// ("v2.742" → "v2.750"); a bare number like "1" is left alone.
		$args = ['ID' => $post_id];
		if ($old !== '' && $new !== '' && strpos($old, '.') !== false) {
			$title = get_post_field('post_title', $post_id);
			if (strpos($title, $old) !== false) {
				$args['post_title'] = str_replace($old, $new, $title);
			}
			foreach (['rank_math_title', 'rank_math_description', 'rank_math_facebook_title', 'rank_math_twitter_title'] as $key) {
				$value = (string) get_post_meta($post_id, $key, true);
				if ($value !== '' && strpos($value, $old) !== false) {
					update_post_meta($post_id, $key, str_replace($old, $new, $value));
				}
			}
		}
		wp_update_post($args); // also marks the post as just updated
		self::refresh_flag($post_id);

		wp_safe_redirect(add_query_arg('y4apk_saved', $post_id, $back));
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
			.au-line { display: grid; grid-template-columns: 66px 150px 115px minmax(160px, 1fr) auto; gap: 8px; align-items: center; }
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
			@media (max-width: 1400px) {
				.au-row { grid-template-columns: minmax(220px, 1fr) minmax(140px, .45fr); }
				.au-row .au-form { grid-column: 1 / -1; }
				.au-srow { grid-template-columns: 22px 1fr 1fr; }
				.au-srow form { grid-column: 2 / -1; }
			}
			@media (max-width: 782px) {
				.y4apk-au { margin-right: 10px; }
				.au-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
				.au-kpi { padding: 12px 14px; }
				.au-kpi:last-child { grid-column: 1 / -1; }
				.au-kpi-value { font-size: 22px; }
				.au-row, .au-srow { grid-template-columns: 1fr; }
				.au-srow form { grid-column: auto; }
				.au-line { grid-template-columns: 60px 1fr 1fr; }
				.au-line input[type=url] { grid-column: 1 / -1; }
				.au-toolbar .au-search { min-width: 0; width: 100%; }
				.au-dl { grid-template-columns: 1fr; }
				.au-dl dt { border-bottom: 0; padding-bottom: 0; }
			}
		</style>
		<?php
	}

	public static function render_page() {
		global $wpdb;
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
		$linked = (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND p.post_type = 'post' AND p.post_status = 'publish'");
		$checked = self::count_meta(self::CHECKED);
		$suggested = self::count_meta(self::LINK, 'suggested');
		$waiting = max(0, $total - $linked - $suggested - self::count_meta(self::LINK, 'nomatch') - self::count_meta(self::LINK, 'rejected'));
		$needs_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS)));
		$info_read = self::count_meta(self::INFO);
		$info_updated = (int) get_option('y4apk_vc_info_updated', 0);
		$info_any = (bool) array_filter(array_map([__CLASS__, 'info_on'], array_keys(self::info_fields())));
		$round_done = (int) get_option('y4apk_vc_round_done', 0);
		$last_step = (int) get_option('y4apk_vc_last_step', 0);

		$views = ['needs', 'suggest', 'test', 'settings'];
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
					<p><?php esc_html_e('Every 15 minutes, posts are linked to their Google Play app and checked against its latest version. Posts whose file is older are listed — those Google Search shows most first (or the most downloaded) — and nothing changes on them until you save a new file. App Information (publisher, age rating, Android version, rating) follows Google Play as set in Settings.', 'y4apk'); ?></p>
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

			<?php if (!empty($_GET['y4apk_saved'])) : $sid = absint($_GET['y4apk_saved']); ?>
				<div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('Saved: %s', 'y4apk'), '<a href="' . esc_url(get_permalink($sid)) . '" target="_blank">' . esc_html(get_the_title($sid)) . '</a>'); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_gsc'])) : $g = self::gsc(); ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(__('Google Search numbers updated: %1$s pages, %2$s posts matched.', 'y4apk'), number_format_i18n($g['rows']), number_format_i18n(count($g['posts'])))); ?></p></div>
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
						? esc_html(sprintf(__('%1$s updates applied · %2$s store', 'y4apk'), number_format_i18n($info_updated), self::country()))
						: esc_html__('Not applied (switched off in Settings)', 'y4apk'); ?></span>
				</div>
			</div>

			<nav class="au-tabs" aria-label="<?php esc_attr_e('App updates', 'y4apk'); ?>">
				<a class="au-tab<?php echo $view === 'needs' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('needs')); ?>"><?php esc_html_e('Need a new file', 'y4apk'); ?><span class="au-count"><?php echo esc_html(number_format_i18n(count($needs_ids))); ?></span></a>
				<a class="au-tab<?php echo $view === 'suggest' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('suggest')); ?>"><?php esc_html_e('Suggestions', 'y4apk'); ?><span class="au-count"><?php echo esc_html(number_format_i18n($suggested)); ?></span></a>
				<a class="au-tab<?php echo $view === 'test' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('test')); ?>"><?php esc_html_e('Test one app', 'y4apk'); ?></a>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="au-tab<?php echo $view === 'settings' ? ' is-on' : ''; ?>" href="<?php echo esc_url($url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a>
				<?php endif; ?>
			</nav>

			<?php if ($view === 'needs') : ?>
				<div class="au-toolbar">
					<input type="search" class="au-search" data-au-filter="au-needs" placeholder="<?php esc_attr_e('Filter this page by name…', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Filter this page by name', 'y4apk'); ?>">
					<span class="au-toolbar-info">
						<?php esc_html_e('Order:', 'y4apk'); ?>
						<a href="<?php echo esc_url($url('needs', ['order' => 'google'])); ?>" class="<?php echo $order === 'google' ? 'is-on' : ''; ?>"><?php esc_html_e('Google search', 'y4apk'); ?></a> ·
						<a href="<?php echo esc_url($url('needs', ['order' => 'downloads'])); ?>" class="<?php echo $order === 'downloads' ? 'is-on' : ''; ?>"><?php esc_html_e('Downloads', 'y4apk'); ?></a>
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
				<?php foreach ($page_ids as $id) :
					list(, $link) = self::download($id);
					$others = self::other_downloads($id);
					$current = self::current_version($id);
					$latest = (string) get_post_meta($id, self::LATEST, true);
					$new_n = self::number($latest);
					$source = get_post_meta($id, self::SOURCE, true) === 'aptoide' ? 'Aptoide' : 'Google Play';
					$suggest = self::number($current) !== '' ? self::with_number($current, $new_n) : $latest;
					$uploads = class_exists('Y4APK_R2') && Y4APK_R2::ready();
					$title = get_the_title($id); ?>
					<div class="au-card au-row" data-title="<?php echo esc_attr(strtolower($title)); ?>">
						<div class="au-app">
							<?php echo self::icon($id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in icon() ?>
							<div style="min-width:0">
								<a class="au-title" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($title); ?></a>
								<div class="au-links">
									<a href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank"><?php esc_html_e('View post', 'y4apk'); ?> ↗</a>
									<a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode(get_post_meta($id, 'wp_GP_ID', true))); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Play', 'y4apk'); ?> ↗</a>
								</div>
								<?php echo self::info_chips($id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in info_chips() ?>
							</div>
						</div>
						<div class="au-vers">
							<span class="au-label"><?php esc_html_e('Your file', 'y4apk'); ?></span>
							<?php if (get_post_meta($id, self::NO_FILE, true) !== '') : ?>
								<span class="au-ver" title="<?php esc_attr_e('Write with AI made its download folder; upload the file here.', 'y4apk'); ?>"><?php esc_html_e('No file yet', 'y4apk'); ?></span>
							<?php else : ?>
								<span class="au-ver"><?php echo esc_html($current); ?></span>
							<?php endif; ?>
							<span class="au-label"><?php echo esc_html(sprintf(__('Latest · %s', 'y4apk'), $source)); ?></span>
							<span class="au-ver is-new"><?php echo esc_html($latest); ?></span>
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
							<input type="hidden" name="sig" value="<?php echo esc_attr(self::links_sig($id)); ?>">
							<?php wp_nonce_field('y4apk-vc-save-' . $id); ?>
							<span class="au-label"><?php esc_html_e('New file', 'y4apk'); ?></span>
							<div class="au-line" data-row="main">
								<span class="au-type"><?php echo esc_html($link && !empty($link['download_name']) ? $link['download_name'] : 'APK'); ?></span>
								<input type="text" name="version" value="<?php echo esc_attr($suggest); ?>" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
								<input type="text" name="size" value="<?php echo esc_attr($link && !empty($link['download_size']) ? $link['download_size'] : ''); ?>" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
								<input type="url" name="url" required value="<?php echo esc_attr($link && !empty($link['download_url']) ? $link['download_url'] : ''); ?>" data-orig="<?php echo esc_attr($link && !empty($link['download_url']) ? $link['download_url'] : ''); ?>" placeholder="<?php esc_attr_e('Link to the new file', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
								<?php if ($uploads) { Y4APK_R2::upload_control('r2', 'main'); } ?>
							</div>
							<?php foreach ($others['same'] as $i => $o) :
								// OBB and other Android files follow the APK; an iOS file only when ticked.
								$on = !in_array(strtolower(trim(isset($o['download_name']) ? (string) $o['download_name'] : '')), ['ipa', 'ios'], true); ?>
								<div class="au-line" data-row="<?php echo esc_attr($i); ?>">
									<label class="au-type" title="<?php esc_attr_e('Update this download too', 'y4apk'); ?>"><input type="checkbox" name="rows[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked($on); ?>
										onchange="this.closest('.au-line').querySelectorAll('input:not([type=checkbox])').forEach(function (e) { e.disabled = !this.checked; }, this)"> <?php echo esc_html($o['download_name']); ?></label>
									<input type="text" name="rows[<?php echo esc_attr($i); ?>][version]" value="<?php echo esc_attr(self::with_number(isset($o['download_version']) ? $o['download_version'] : '', $new_n)); ?>" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>" <?php disabled(!$on); ?>>
									<input type="text" name="rows[<?php echo esc_attr($i); ?>][size]" value="<?php echo esc_attr(isset($o['download_size']) ? $o['download_size'] : ''); ?>" placeholder="<?php esc_attr_e('Size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>" <?php disabled(!$on); ?>>
									<input type="url" name="rows[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr(isset($o['download_url']) ? $o['download_url'] : ''); ?>" data-orig="<?php echo esc_attr(isset($o['download_url']) ? $o['download_url'] : ''); ?>" placeholder="<?php esc_attr_e('Link to the new file', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>" <?php disabled(!$on); ?>>
									<?php if ($uploads) { Y4APK_R2::upload_control('rows[' . $i . '][r2]', (string) $i, !$on); } ?>
								</div>
							<?php endforeach; ?>
							<div class="au-foot">
								<label><input type="radio" name="mode" value="replace" checked> <?php esc_html_e('Replace the old file', 'y4apk'); ?></label>
								<label><input type="radio" name="mode" value="add"> <?php esc_html_e('Add as new, keep the old file below it', 'y4apk'); ?></label>
								<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
							</div>
							<p class="au-warn" hidden><?php esc_html_e('“Add as new” keeps the old download, so the new one needs another link: upload the new file or paste its link — or choose “Replace the old file” to keep this link.', 'y4apk'); ?></p>
							<?php if ($others['kept']) : ?>
								<div class="au-kept"><?php esc_html_e('Left as they are:', 'y4apk'); ?>
									<?php echo esc_html(implode(' · ', array_map(function ($o) {
										return trim((isset($o['download_name']) ? $o['download_name'] : '') . ' ' . (isset($o['download_version']) ? $o['download_version'] : '')
											. (!empty($o['download_size']) ? ' (' . $o['download_size'] . ')' : ''));
									}, $others['kept']))); ?></div>
							<?php endif; ?>
						</form>
					</div>
				<?php endforeach; ?>
				</div>
				<?php if ($pages > 1) : ?>
					<div class="au-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>
				<p class="au-note"><?php esc_html_e('Save puts the new version, size and link on the post’s newest APK download and in App Information, changes the version in the title and in the Rank Math SEO title, description and social titles (for example v2.742 → v2.750), and marks the post as updated today. Downloads with the same version (OBB, IPA) are listed under it with the new version filled in: ticked ones are updated too (an iOS file starts unticked). Older versions are left as they are. Replace (the default) swaps the old files for the new ones and keeps their links; Add puts each new file just above its old one, which stays as an older version (it needs another link: upload the file, or paste one). The size and link start with what the post has now, so change only what is different for the new file.', 'y4apk'); ?></p>

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
					var add = form.querySelector('input[name=mode][value=add]').checked;
					var url = form.querySelector('input[name=url]'), up = form.querySelector('.au-line[data-row=main] .au-up-key');
					var same = add && url.value.trim() === url.getAttribute('data-orig') && !(up && up.value);
					form.querySelector('.au-warn').hidden = !same;
					if (same) {
						e.preventDefault();
					}
				});
				form.querySelectorAll('input[name=mode]').forEach(function (r) {
					r.addEventListener('change', function () { form.querySelector('.au-warn').hidden = true; });
				});
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
