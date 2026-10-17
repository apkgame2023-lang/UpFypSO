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
 * Nothing on the site changes by itself. Posts whose download is older than the
 * latest version are listed, most downloaded first, each with a form for the
 * new file; saving it updates the download, the version in the title and in the
 * Rank Math title and description, and App Version / App Size.
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
	const PER_PAGE     = 50;
	const UA           = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		// Any save, including the block editor's (REST, not an admin page).
		add_action('save_post_post', [__CLASS__, 'refresh_flag'], 20);
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

	/** Index and entry of the post's APK download (the first APK-type link, else the first link). */
	public static function download($post_id) {
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		if (!is_array($links) || !$links) {
			return [null, null];
		}
		foreach ($links as $i => $link) {
			$name = strtolower(isset($link['download_name']) ? (string) $link['download_name'] : '');
			if (in_array($name, ['apk', 'apks', 'xapk', 'apkm', 'mod'], true)) {
				return [$i, $link];
			}
		}
		$first = array_key_first($links);
		return [$first, $links[$first]];
	}

	public static function current_version($post_id) {
		list(, $link) = self::download($post_id);
		$version = $link && !empty($link['download_version']) ? $link['download_version'] : get_post_meta($post_id, 'wp_version_GP', true);
		return (string) $version;
	}

	/** Is the stored latest version newer than the post's download? */
	public static function needs_update($post_id) {
		$latest = self::number(get_post_meta($post_id, self::LATEST, true));
		$current = self::number(self::current_version($post_id));
		return $latest !== '' && $current !== '' && version_compare($latest, $current, '>');
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
		foreach ($path as $key) {
			if (!is_array($data) || !array_key_exists($key, $data)) {
				return null;
			}
			$data = $data[$key];
		}
		return is_scalar($data) ? $data : null;
	}

	private static function valid_version($v) {
		return is_string($v) && preg_match('/^\d[\w.\-]{0,40}$/', $v);
	}

	/**
	 * An app's details as Google Play shows them (English, US store; then any
	 * store for apps not offered in the US). status: ok | missing | busy.
	 */
	public static function play_app($package) {
		foreach (['&hl=en&gl=US', '&hl=en'] as $i => $params) {
			$r = self::get('https://play.google.com/store/apps/details?id=' . rawurlencode($package) . $params);
			if ($r === null || $r[0] === 429 || $r[0] >= 500) {
				return ['status' => 'busy'];
			}
			if ($r[0] === 404 && $i === 0) {
				continue;
			}
			if ($r[0] !== 200) {
				return ['status' => 'missing'];
			}
			$data = self::play_block($r[1]);
			$version = self::dig($data, [1, 2, 140, 0, 0, 0]);
			$updated = self::dig($data, [1, 2, 145, 0, 1, 0]);
			return [
				'status'    => 'ok',
				'title'     => (string) self::dig($data, [1, 2, 0, 0]),
				'developer' => (string) self::dig($data, [1, 2, 68, 0]),
				'version'   => self::valid_version($version) ? $version : '',
				'shown'     => is_string($version) ? $version : '', // e.g. "Varies with device"
				'updated'   => is_numeric($updated) ? (int) $updated : 0,
				'readable'  => $data !== null,
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
	public static function latest($package) {
		$play = self::play_app($package);
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
		$latest = self::latest($package);
		if ($latest === null) {
			return null;
		}
		update_post_meta($post_id, self::LATEST, $latest['version']);
		update_post_meta($post_id, self::SOURCE, $latest['source']);
		update_post_meta($post_id, self::CHECKED, time());
		self::refresh_flag($post_id);
		return true;
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
			update_post_meta($post_id, self::SUGGEST, wp_json_encode($first));
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

		if (!$done['busy']) {
			global $wpdb;
			$cursor = (int) get_option('y4apk_vc_cursor', 0);
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND pm.post_id > %d
				   AND p.post_type = 'post' AND p.post_status = 'publish'
				 ORDER BY pm.post_id LIMIT %d", $cursor, self::BATCH));
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
			if ($all && count($ids) < self::BATCH) {
				$cursor = 0; // round finished
				update_option('y4apk_vc_round_done', time(), false);
			}
			update_option('y4apk_vc_cursor', $cursor, false);
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
		self::schedule();
		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
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
		$back = add_query_arg(['page' => self::SLUG, 'tab' => 'link'], admin_url('admin.php')) . '#y4apk-link';
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
		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')) . '#y4apk-link');
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

		list($index, $link) = self::download($post_id);
		if ($index === null || $version === '' || $url === '') {
			wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
			exit;
		}

		$old = self::number(isset($link['download_version']) ? $link['download_version'] : '');
		$new = self::number($version);
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$links[$index]['download_version'] = $version;
		$links[$index]['download_url'] = $url;
		if ($size !== '') {
			$links[$index]['download_size'] = $size;
			update_post_meta($post_id, 'wp_sizes_GP', $size);
		}
		update_post_meta($post_id, 'repeatable_download_link', $links);
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
			echo '<p style="color:#b32d2e">' . esc_html__('That is not a Google Play link or app ID.', 'y4apk') . '</p>';
			return;
		}
		$play = self::play_app($package);
		$apt = self::aptoide_version($package);
		$latest = self::latest($package);
		$rows = [
			[__('Google Play page', 'y4apk'), $play['status'] === 'ok'
				? ($play['readable'] ? __('read', 'y4apk') : __('opened, but its data was not found (Google may have changed the page)', 'y4apk'))
				: ($play['status'] === 'busy' ? __('did not answer (busy or blocked)', 'y4apk') : __('not found', 'y4apk'))],
			[__('Name on Google Play', 'y4apk'), isset($play['title']) ? $play['title'] : ''],
			[__('Publisher', 'y4apk'), isset($play['developer']) ? $play['developer'] : ''],
			[__('Version on Google Play', 'y4apk'), isset($play['shown']) ? $play['shown'] : ''],
			[__('Updated on Google Play', 'y4apk'), !empty($play['updated']) ? date_i18n(get_option('date_format'), $play['updated']) : ''],
			[__('Version on Aptoide', 'y4apk'), $apt === null ? __('did not answer', 'y4apk') : ($apt === '' ? __('not found, or not a plain version (another build)', 'y4apk') : $apt)],
			[__('Version used', 'y4apk'), $latest === null ? __('none — try again later', 'y4apk') : ($latest['version'] !== '' ? $latest['version'] . ' (' . ($latest['source'] === 'play' ? 'Google Play' : 'Aptoide') . ')' : __('none found', 'y4apk'))],
		];
		echo '<table class="widefat striped" style="max-width:700px"><tbody>';
		echo '<tr><td style="width:220px"><strong>' . esc_html__('App ID', 'y4apk') . '</strong></td><td><code>' . esc_html($package) . '</code></td></tr>';
		foreach ($rows as $row) {
			echo '<tr><td><strong>' . esc_html($row[0]) . '</strong></td><td>' . esc_html($row[1] !== '' ? $row[1] : '—') . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_page() {
		global $wpdb;
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
		$linked = (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND p.post_type = 'post' AND p.post_status = 'publish'");
		$checked = self::count_meta(self::CHECKED);
		$suggested = self::count_meta(self::LINK, 'suggested');
		$waiting = $total - $linked - $suggested - self::count_meta(self::LINK, 'nomatch') - self::count_meta(self::LINK, 'rejected');
		$needs_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS)));
		$round_done = (int) get_option('y4apk_vc_round_done', 0);
		$last_step = (int) get_option('y4apk_vc_last_step', 0);

		// Most downloaded first (last 30 days, Y4APK ▸ Analytics), then newest posts.
		$downloads = [];
		if (class_exists('Y4APK_Stats') && $needs_ids) {
			foreach ($wpdb->get_results($wpdb->prepare(
				'SELECT dim, SUM(n) AS n FROM ' . Y4APK_Stats::table() . " WHERE event = 'file_download' AND day >= %s GROUP BY dim",
				gmdate('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS))) as $r) {
				$downloads[(int) $r->dim] = (int) $r->n;
			}
		}
		usort($needs_ids, function ($a, $b) use ($downloads) {
			$da = isset($downloads[$a]) ? $downloads[$a] : 0;
			$db = isset($downloads[$b]) ? $downloads[$b] : 0;
			return $da === $db ? $b - $a : $db - $da;
		});
		$paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
		$pages = max(1, (int) ceil(count($needs_ids) / self::PER_PAGE));
		$page_ids = array_slice($needs_ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

		$suggest_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'suggested' ORDER BY post_id DESC LIMIT 30", self::LINK)));
		if ($page_ids || $suggest_ids) {
			_prime_post_caches(array_merge($page_ids, $suggest_ids), false, true);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('App updates', 'y4apk'); ?></h1>
			<?php if (!empty($_GET['y4apk_saved'])) : $sid = absint($_GET['y4apk_saved']); ?>
				<div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('Saved: %s', 'y4apk'), '<a href="' . esc_url(get_permalink($sid)) . '" target="_blank">' . esc_html(get_the_title($sid)) . '</a>'); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_error'])) : ?>
				<div class="notice notice-error"><p><?php echo $_GET['y4apk_error'] === 'id'
					? esc_html__('That is not a Google Play link or app ID.', 'y4apk')
					: esc_html__('Enter the new version and the download link.', 'y4apk'); ?></p></div>
			<?php elseif (isset($_GET['ran'])) : $ran = array_map('intval', explode('.', sanitize_text_field(wp_unslash($_GET['ran'])))) + [0, 0, 0, 0]; ?>
				<div class="notice notice-<?php echo $ran[3] ? 'warning' : 'success'; ?> is-dismissible"><p><?php
					printf(esc_html__('Step done: %1$d posts searched for a Google Play link, %2$d looked up on Google Play, %3$d versions checked.', 'y4apk'), $ran[0], $ran[1], $ran[2]);
					if ($ran[3]) {
						echo ' ' . esc_html__('Google Play stopped answering for now; the next step carries on.', 'y4apk');
					} ?></p></div>
			<?php endif; ?>

			<p style="max-width:900px"><?php esc_html_e('In the background, every 15 minutes: posts are linked to their Google Play app (a Google Play link in the post, or a Google Play search for its App Name that matches exactly), and linked posts get their latest version from Google Play itself — Aptoide when Play shows none. Posts whose download is older are listed below, most downloaded first. Nothing on the site changes until you save a new file.', 'y4apk'); ?></p>

			<table class="widefat striped" style="max-width:900px"><tbody>
				<tr><td style="width:260px"><strong><?php esc_html_e('Posts linked to Google Play', 'y4apk'); ?></strong></td><td><?php printf(esc_html__('%1$s of %2$s', 'y4apk'), number_format_i18n($linked), number_format_i18n($total)); ?>
					<?php if ($suggested) : ?> · <a href="#y4apk-link"><?php printf(esc_html__('%s suggestions to confirm', 'y4apk'), number_format_i18n($suggested)); ?></a><?php endif; ?>
					<?php if ($waiting > 0) : ?> · <?php printf(esc_html__('%s still to look up', 'y4apk'), number_format_i18n($waiting)); ?><?php endif; ?></td></tr>
				<tr><td><strong><?php esc_html_e('Versions checked', 'y4apk'); ?></strong></td><td><?php printf(esc_html__('%1$s of %2$s linked posts', 'y4apk'), number_format_i18n(min($checked, $linked)), number_format_i18n($linked)); ?>
					<?php if ($round_done) : ?> · <?php printf(esc_html__('last full round finished %s ago', 'y4apk'), esc_html(human_time_diff($round_done))); ?><?php endif; ?></td></tr>
				<tr><td><strong><?php esc_html_e('Need a new file', 'y4apk'); ?></strong></td><td><strong><?php echo esc_html(number_format_i18n(count($needs_ids))); ?></strong></td></tr>
				<tr><td><strong><?php esc_html_e('Last background step', 'y4apk'); ?></strong></td><td><?php echo $last_step ? esc_html(sprintf(__('%s ago', 'y4apk'), human_time_diff($last_step))) : esc_html__('not yet', 'y4apk'); ?>
					<?php if (!self::enabled()) : ?> · <strong style="color:#b32d2e"><?php esc_html_e('switched off', 'y4apk'); ?></strong><?php endif; ?>
					<?php if (current_user_can('manage_options')) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
							<input type="hidden" name="action" value="y4apk_vc_run"><?php wp_nonce_field('y4apk-vc-run'); ?>
							<button class="button button-small"><?php esc_html_e('Run a step now', 'y4apk'); ?></button>
						</form>
					<?php endif; ?></td></tr>
			</tbody></table>

			<h2><?php esc_html_e('Posts that need a new file', 'y4apk'); ?></h2>
			<table class="widefat striped" style="max-width:1300px">
				<thead><tr>
					<th><?php esc_html_e('Post', 'y4apk'); ?></th>
					<th><?php esc_html_e('Your file', 'y4apk'); ?></th>
					<th><?php esc_html_e('Latest', 'y4apk'); ?></th>
					<th style="text-align:right"><?php esc_html_e('Downloads (30 days)', 'y4apk'); ?></th>
					<th style="width:46%"><?php esc_html_e('New file', 'y4apk'); ?></th>
				</tr></thead>
				<tbody>
				<?php if (!$page_ids) : ?>
					<tr><td colspan="5"><?php echo $checked
						? esc_html__('Every checked post is up to date.', 'y4apk')
						: esc_html__('Nothing checked yet — results appear as posts get linked and checked.', 'y4apk'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($page_ids as $id) :
					list(, $link) = self::download($id);
					$current = self::current_version($id);
					$latest = (string) get_post_meta($id, self::LATEST, true);
					$source = get_post_meta($id, self::SOURCE, true) === 'aptoide' ? 'Aptoide' : 'Google Play';
					$suggest = ($n = self::number($current)) !== '' ? str_replace($n, self::number($latest), $current) : $latest; ?>
					<tr>
						<td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><strong><?php echo esc_html(get_the_title($id)); ?></strong></a><br>
							<small><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode(get_post_meta($id, 'wp_GP_ID', true))); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Play', 'y4apk'); ?></a></small></td>
						<td><code><?php echo esc_html($current); ?></code></td>
						<td><code style="color:#b32d2e"><?php echo esc_html($latest); ?></code><br><small style="color:#8c8f94"><?php echo esc_html($source); ?></small></td>
						<td style="text-align:right"><?php echo esc_html(number_format_i18n(isset($downloads[$id]) ? $downloads[$id] : 0)); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
								<input type="hidden" name="action" value="y4apk_vc_save">
								<input type="hidden" name="post" value="<?php echo esc_attr($id); ?>">
								<input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>">
								<?php wp_nonce_field('y4apk-vc-save-' . $id); ?>
								<input name="version" value="<?php echo esc_attr($suggest); ?>" style="width:130px" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
								<input name="size" value="" placeholder="<?php echo esc_attr($link && !empty($link['download_size']) ? sprintf(__('was %s', 'y4apk'), $link['download_size']) : __('Size', 'y4apk')); ?>" style="width:110px" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
								<input name="url" type="url" required placeholder="<?php esc_attr_e('Link to the new file', 'y4apk'); ?>" style="flex:1;min-width:180px" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
								<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($pages > 1) : ?>
				<p><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]); ?></p>
			<?php endif; ?>
			<p class="description" style="max-width:900px"><?php esc_html_e('Save puts the new version, size and link on the post’s APK download and in App Information, changes the version in the title and in the Rank Math SEO title, description and social titles (for example v2.742 → v2.750), and marks the post as updated today. Other downloads on the post (OBB, iOS) are left as they are. Size left empty keeps the old size. The link starts empty on purpose: it must be the new file.', 'y4apk'); ?></p>

			<h2 id="y4apk-link"><?php esc_html_e('Suggestions to confirm', 'y4apk'); ?></h2>
			<p class="description" style="max-width:900px"><?php esc_html_e('Posts whose Google Play search found an app, but not with exactly the same name and publisher — or posts without an App Name. Check the suggestion, then use it, type the right ID, or mark it as not on Google Play. You can also put the ID in the post’s App Information ▸ Google Play ID.', 'y4apk'); ?></p>
			<form id="y4apk-bulk" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_vc_bulk"><?php wp_nonce_field('y4apk-vc-bulk'); ?>
			</form>
			<table class="widefat striped" style="max-width:1300px">
				<thead><tr><td class="check-column" style="padding-left:8px"><input type="checkbox" aria-label="<?php esc_attr_e('Select all', 'y4apk'); ?>" onclick="document.querySelectorAll('.y4apk-pick').forEach(function (c) { c.checked = this.checked; }, this)"></td>
					<th><?php esc_html_e('Post', 'y4apk'); ?></th><th><?php esc_html_e('Suggested Google Play app', 'y4apk'); ?></th><th style="width:44%"></th></tr></thead>
				<tbody>
				<?php if (!$suggest_ids) : ?>
					<tr><td colspan="4"><?php esc_html_e('No suggestions waiting.', 'y4apk'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($suggest_ids as $id) :
					$s = json_decode((string) get_post_meta($id, self::SUGGEST, true), true);
					if (!is_array($s) || empty($s['id'])) {
						continue;
					} ?>
					<tr>
						<th class="check-column" style="padding-left:8px"><input type="checkbox" class="y4apk-pick" name="posts[]" value="<?php echo esc_attr($id); ?>" form="y4apk-bulk" aria-label="<?php esc_attr_e('Select', 'y4apk'); ?>"></th>
						<td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><strong><?php echo esc_html(get_the_title($id)); ?></strong></a><br>
							<small><?php echo esc_html(trim(get_post_meta($id, 'wp_title_GP', true) . ' · ' . get_post_meta($id, 'wp_developers_GP', true), ' ·')); ?></small></td>
						<td><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($s['id'])); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html($s['title'] ?: $s['id']); ?></strong></a><br>
							<small><?php echo esc_html(trim(($s['developer'] ?? '') . ' · ' . $s['id'], ' ·')); ?></small></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
								<input type="hidden" name="action" value="y4apk_vc_link">
								<input type="hidden" name="post" value="<?php echo esc_attr($id); ?>">
								<?php wp_nonce_field('y4apk-vc-link-' . $id); ?>
								<input name="id" value="<?php echo esc_attr($s['id']); ?>" style="flex:1;min-width:200px" aria-label="<?php esc_attr_e('Google Play ID', 'y4apk'); ?>">
								<button class="button button-primary"><?php esc_html_e('Use', 'y4apk'); ?></button>
								<button class="button" name="reject" value="1"><?php esc_html_e('Not on Google Play', 'y4apk'); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($suggest_ids) : ?>
				<p><button class="button" form="y4apk-bulk"><?php esc_html_e('Not on Google Play — all ticked', 'y4apk'); ?></button></p>
			<?php endif; ?>

			<h2><?php esc_html_e('Test one app', 'y4apk'); ?></h2>
			<p class="description"><?php esc_html_e('See what this server reads from Google Play and Aptoide for any app, to compare with the Play Store yourself.', 'y4apk'); ?></p>
			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<input name="test" class="regular-text" value="<?php echo esc_attr(isset($_GET['test']) ? sanitize_text_field(wp_unslash($_GET['test'])) : ''); ?>" placeholder="https://play.google.com/store/apps/details?id=com.eurosport">
				<button class="button"><?php esc_html_e('Test', 'y4apk'); ?></button>
			</form>
			<?php if (!empty($_GET['test'])) {
				self::render_test(sanitize_text_field(wp_unslash($_GET['test'])));
			} ?>

			<?php if (current_user_can('manage_options')) : ?>
				<h2><?php esc_html_e('Settings', 'y4apk'); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="y4apk_vc_settings">
					<?php wp_nonce_field('y4apk-vc-settings'); ?>
					<p><label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Link posts and check versions in the background', 'y4apk'); ?></label>
						<button class="button"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
				</form>
				<p class="description" style="max-width:900px"><?php printf(
					/* translators: %s: cron command */
					esc_html__('WordPress runs background steps when someone opens an uncached page, so with a page cache they can come rarely. To run them every 15 minutes regardless, add a cron job in Hostinger (hPanel ▸ Advanced ▸ Cron Jobs, every 15 minutes): %s', 'y4apk'),
					'<code>wget -q -O - ' . esc_html(site_url('wp-cron.php?doing_wp_cron')) . ' &gt;/dev/null 2&gt;&amp;1</code>'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

Y4APK_App_Updates::init();
