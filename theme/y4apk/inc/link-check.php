<?php
/**
 * Broken download links (2.66): Y4APK ▸ Broken links.
 *
 * Every download row of every published post is looked at in the background,
 * a few dozen every 10 minutes, so a dead file is found before visitors find
 * it. What is checked depends on the link:
 *
 *   - one of the site's download folders (https://files.y4apk.com/up/…): the
 *     folder must be there, its page must send to a file (not "#"), and that
 *     file must be on R2 (or, for an older page that sends somewhere else,
 *     that address must answer);
 *   - any other address: it must answer (HEAD, then a small GET), and a few
 *     file hosts that answer "200" for a removed file are read for the words
 *     they use.
 *
 * A link that fails three times in a row without a clear "gone" (a time-out,
 * a host that blocks robots) counts as broken too. Working links are looked at
 * again every week, broken ones every 6 hours, so a fixed one leaves the list
 * by itself; a link changed in the post is looked at on the next run.
 *
 * Nothing about visitors is sent or kept: only the post's own links.
 */
if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Links {

	const DBV       = '1';
	const SLUG      = 'y4apk-links';
	const CRON      = 'y4apk_links_check';
	const OPTION    = 'y4apk_links';       // ['on', 'cursor', 'pass_start', 'pass_done', 'last_run']
	const BATCH     = 50;                  // links a run may look at
	const BUDGET    = 20;                  // seconds a run may take
	const SYNC      = 300;                 // posts a run reads the links of
	const OK_AGAIN  = 7 * DAY_IN_SECONDS;
	const BAD_AGAIN = 6 * HOUR_IN_SECONDS;
	const TRIES     = 3;                   // "not sure" this many times in a row: broken
	const UA        = 'Mozilla/5.0 (compatible; Y4APK-LinkCheck/1.0)';

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action('init', [__CLASS__, 'setup']);
		add_action(self::CRON, [__CLASS__, 'run']);
		// A post's links changed: read them again now, and look at the new ones on the next run
		add_action('added_post_meta', [__CLASS__, 'meta_changed'], 10, 3);
		add_action('updated_post_meta', [__CLASS__, 'meta_changed'], 10, 3);
		add_action('deleted_post_meta', [__CLASS__, 'meta_changed'], 10, 3);
		add_action('transition_post_status', [__CLASS__, 'status_changed'], 10, 3);
		add_action('deleted_post', [__CLASS__, 'forget']);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 32);
			add_action('admin_post_y4apk_links', [__CLASS__, 'handle_admin']);
		}
	}

	public static function schedules($s) {
		if (!isset($s['y4apk_10min'])) {
			$s['y4apk_10min'] = ['interval' => 10 * MINUTE_IN_SECONDS, 'display' => __('Every 10 minutes', 'y4apk')];
		}
		return $s;
	}

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_links';
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], ['on' => 1, 'cursor' => 0, 'pass_start' => 0, 'pass_done' => 0, 'last_run' => 0]);
	}

	private static function save($changes) {
		update_option(self::OPTION, array_merge(self::settings(), $changes), false);
	}

	public static function setup() {
		$on = (int) self::settings()['on'];
		$next = wp_next_scheduled(self::CRON);
		if ($on && !$next) {
			wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'y4apk_10min', self::CRON);
		} elseif (!$on && $next) {
			wp_clear_scheduled_hook(self::CRON);
		}
		if (get_option('y4apk_links_db') === self::DBV) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . self::t() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			idx smallint(5) unsigned NOT NULL,
			name varchar(40) NOT NULL DEFAULT '',
			version varchar(40) NOT NULL DEFAULT '',
			url text NOT NULL,
			uhash char(32) NOT NULL DEFAULT '',
			state varchar(8) NOT NULL DEFAULT 'new',
			why varchar(190) NOT NULL DEFAULT '',
			code smallint(5) NOT NULL DEFAULT 0,
			fails tinyint(3) unsigned NOT NULL DEFAULT 0,
			checked int(10) unsigned NOT NULL DEFAULT 0,
			since int(10) unsigned NOT NULL DEFAULT 0,
			ignored tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY post_idx (post_id,idx),
			KEY state (state,ignored),
			KEY checked (checked)
		) " . $wpdb->get_charset_collate() . ';');
		update_option('y4apk_links_db', self::DBV, false);
	}

	/* ---------------------------------------------------------------- *
	 * Which links a post has
	 * ---------------------------------------------------------------- */

	/** A post's download rows, as they are now, in the table. */
	public static function sync_post($post_id) {
		global $wpdb;
		$post_id = (int) $post_id;
		$t = self::t();
		$post = get_post($post_id);
		$rows = $post && $post->post_status === 'publish' && $post->post_type === 'post' ? get_post_meta($post_id, 'repeatable_download_link', true) : [];
		$rows = is_array($rows) ? array_values($rows) : [];
		$have = [];
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT idx, uhash FROM $t WHERE post_id = %d", $post_id)) as $r) {
			$have[(int) $r->idx] = $r->uhash;
		}
		foreach ($rows as $i => $row) {
			$url = is_array($row) ? trim((string) ($row['download_url'] ?? '')) : '';
			$name = is_array($row) ? substr(sanitize_text_field((string) ($row['download_name'] ?? '')), 0, 40) : '';
			$version = is_array($row) ? substr(sanitize_text_field((string) ($row['download_version'] ?? '')), 0, 40) : '';
			$hash = md5($url);
			if (!isset($have[$i])) {
				$wpdb->insert($t, ['post_id' => $post_id, 'idx' => $i, 'name' => $name, 'version' => $version, 'url' => $url, 'uhash' => $hash, 'state' => 'new']);
			} elseif ($have[$i] !== $hash) { // a new address: start again
				$wpdb->update($t, ['name' => $name, 'version' => $version, 'url' => $url, 'uhash' => $hash, 'state' => 'new', 'why' => '', 'code' => 0, 'fails' => 0, 'checked' => 0, 'since' => 0, 'ignored' => 0], ['post_id' => $post_id, 'idx' => $i]);
			} else {
				$wpdb->update($t, ['name' => $name, 'version' => $version], ['post_id' => $post_id, 'idx' => $i]);
			}
		}
		$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE post_id = %d AND idx >= %d", $post_id, count($rows)));
	}

	public static function meta_changed($meta_id, $post_id, $key) {
		if ($key === 'repeatable_download_link' && get_option('y4apk_links_db') === self::DBV) {
			self::sync_post($post_id);
		}
	}

	public static function status_changed($new, $old, $post) {
		if ($new !== $old && $post instanceof WP_Post && $post->post_type === 'post' && ($new === 'publish' || $old === 'publish') && get_option('y4apk_links_db') === self::DBV) {
			self::sync_post($post->ID);
		}
	}

	public static function forget($post_id) {
		global $wpdb;
		if (get_option('y4apk_links_db') === self::DBV) {
			$wpdb->delete(self::t(), ['post_id' => (int) $post_id]);
		}
	}

	/** The next posts after the cursor; at the end, a pass is done and the next one starts. */
	private static function sync_some() {
		global $wpdb;
		$s = self::settings();
		$ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'repeatable_download_link'
			WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", (int) $s['cursor'], self::SYNC)));
		foreach ($ids as $id) {
			self::sync_post($id);
		}
		if (count($ids) < self::SYNC) { // the end: the posts no longer published leave, and round again
			$t = self::t();
			$wpdb->query("DELETE l FROM $t l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE p.ID IS NULL OR p.post_status <> 'publish' OR p.post_type <> 'post'");
			self::save(['cursor' => 0, 'pass_done' => time(), 'pass_start' => time()]);
		} else {
			self::save(['cursor' => end($ids)] + ((int) $s['cursor'] === 0 ? ['pass_start' => time()] : []));
		}
	}

	/* ---------------------------------------------------------------- *
	 * Looking at a link
	 * ---------------------------------------------------------------- */

	/**
	 * Is the file there? ['state' => ok|bad|unsure, 'why' => '…', 'code' => HTTP code or 0].
	 */
	public static function check($url) {
		$url = trim((string) $url);
		if ($url === '' || $url === '#') {
			return self::res('bad', __('No link', 'y4apk'));
		}
		if (!preg_match('~^https?://~i', $url)) {
			return self::res('bad', __('Not a web address', 'y4apk'));
		}
		if (class_exists('Y4APK_R2')) {
			$o = Y4APK_R2::settings();
			if (strpos($url, $o['dir_url']) === 0) {
				return self::check_folder($url, $o);
			}
			if ($o['public'] !== '' && strpos($url, $o['public'] . '/') === 0) {
				return self::check_file($url, true);
			}
		}
		return self::check_file($url);
	}

	private static function res($state, $why = '', $code = 0) {
		return ['state' => $state, 'why' => (string) $why, 'code' => (int) $code];
	}

	/** One of the site's download folders: the folder, its page, the file it sends to. */
	private static function check_folder($url, $o) {
		$name = rawurldecode(trim(substr($url, strlen($o['dir_url'])), '/'));
		if ($name === '' || strpbrk($name, "/\\\0") !== false || $name === '.' || $name === '..') {
			return self::res('bad', __('Not a download folder', 'y4apk'));
		}
		if (is_dir($o['dir'])) { // on this server: read the page itself
			$file = $o['dir'] . '/' . $name . '/default.php';
			if (!is_dir($o['dir'] . '/' . $name)) {
				return self::res('bad', __('The download folder is missing', 'y4apk'));
			}
			$html = is_file($file) ? (string) file_get_contents($file) : '';
			if ($html === '') {
				return self::res('bad', __('The download folder has no page', 'y4apk'));
			}
		} else { // the folders are on another server: read the page as a visitor gets it
			$r = wp_safe_remote_get($url . '/', ['timeout' => 10, 'redirection' => 3, 'user-agent' => self::UA, 'limit_response_size' => 262144]);
			if (is_wp_error($r)) {
				return self::res('unsure', sprintf(__('The download page did not answer (%s)', 'y4apk'), self::short_error($r)));
			}
			$code = (int) wp_remote_retrieve_response_code($r);
			if ($code === 404 || $code === 410) {
				return self::res('bad', __('The download folder is missing', 'y4apk'), $code);
			}
			if ($code >= 400) {
				return self::res('unsure', sprintf(__('The download page answered %d', 'y4apk'), $code), $code);
			}
			$html = (string) wp_remote_retrieve_body($r);
		}
		$to = self::page_target($html, $o);
		if ($to === '#') {
			return self::res('bad', __('The download page has no file in it ("#")', 'y4apk'));
		}
		if ($to === '') {
			return self::res('unsure', __('Could not find the file in the download page', 'y4apk'));
		}
		$r = self::check_file($to, strpos($to, $o['public'] . '/') === 0);
		if ($r['state'] === 'bad' && strpos($to, $o['public'] . '/') === 0) {
			$r['why'] = __('The file is gone from R2', 'y4apk') . ($r['code'] ? ' (' . $r['code'] . ')' : '');
		}
		return $r;
	}

	/** Where a download page sends the visitor: an address, "#" (nothing yet), or '' (cannot tell). */
	public static function page_target($html, $o = null) {
		$o = $o ?: Y4APK_R2::settings();
		$base = preg_quote(rtrim((string) $o['public'], '/'), '~');
		if ($base !== '' && preg_match('~' . $base . '/[^"\'\s<>)]+?\.(?:' . implode('|', Y4APK_R2::TYPES) . ')(?=["\'\s<>)])~i', $html, $m)) {
			return $m[0];
		}
		foreach (['~<a class=d href="([^"]*)"~', '~location\.href="([^"]*)",0\)~', '~\$apk\s*=\s*"([^"]*)"~'] as $re) {
			if (preg_match($re, $html, $m)) {
				$to = trim(html_entity_decode($m[1], ENT_QUOTES));
				return $to === '' || $to === '#' ? '#' : (preg_match('~^https?://~i', $to) ? $to : '');
			}
		}
		return '';
	}

	/** Any address: does it answer with something? */
	private static function check_file($url, $ours = false) {
		$args = ['timeout' => 10, 'redirection' => 5, 'user-agent' => self::UA];
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$read = (bool) preg_match('~(^|\.)(mediafire\.com|drive\.google\.com|docs\.google\.com|sfile\.mobi|apkadmin\.com|userupload\.net|uptodown\.com)$~', $host);
		$r = $read ? null : wp_safe_remote_head($url, $args);
		$code = $r && !is_wp_error($r) ? (int) wp_remote_retrieve_response_code($r) : 0;
		if ($read || !$code || in_array($code, [400, 403, 405, 406, 429, 501], true)) { // no HEAD here: a small GET
			$r = wp_safe_remote_get($url, $args + ['headers' => $read ? [] : ['Range' => 'bytes=0-0'], 'limit_response_size' => 65536]);
			$code = !is_wp_error($r) ? (int) wp_remote_retrieve_response_code($r) : 0;
		}
		if (is_wp_error($r)) {
			return self::res('unsure', sprintf(__('No answer (%s)', 'y4apk'), self::short_error($r)));
		}
		if ($code === 404 || $code === 410) {
			return self::res('bad', sprintf(__('Gone (%d)', 'y4apk'), $code), $code);
		}
		if ($code >= 400) {
			return self::res('unsure', sprintf(__('Answered %d', 'y4apk'), $code), $code);
		}
		if ($read) { // hosts that answer 200 for a removed file
			$body = substr((string) wp_remote_retrieve_body($r), 0, 65536);
			foreach (['File Removed for Violation', 'Invalid or Deleted File', 'the file you have requested does not exist', 'File has been removed', 'File not found', 'file was deleted'] as $gone) {
				if (stripos($body, $gone) !== false) {
					return self::res('bad', sprintf(__('The host says: %s', 'y4apk'), $gone), $code);
				}
			}
		}
		if ($ours && $code === 200) {
			$len = (int) wp_remote_retrieve_header($r, 'content-length');
			if ($len === 0 && strtoupper((string) wp_remote_retrieve_header($r, 'content-range')) === '') {
				return self::res('unsure', __('The file is empty', 'y4apk'), $code);
			}
		}
		return self::res('ok', '', $code);
	}

	private static function short_error($e) {
		$m = $e->get_error_message();
		if (stripos($m, 'resolve') !== false) {
			return __('the domain does not exist', 'y4apk');
		}
		if (stripos($m, 'timed out') !== false || stripos($m, 'timeout') !== false) {
			return __('timed out', 'y4apk');
		}
		if (stripos($m, 'SSL') !== false || stripos($m, 'certificate') !== false) {
			return __('a security certificate problem', 'y4apk');
		}
		if (stripos($m, 'valid URL') !== false) {
			return __('the address is not allowed', 'y4apk');
		}
		return substr(wp_strip_all_tags($m), 0, 80);
	}

	/** Look at one row and keep what was found. */
	public static function check_row($row) {
		global $wpdb;
		$r = self::check($row->url);
		$now = time();
		$fails = $r['state'] === 'unsure' ? min(255, (int) $row->fails + 1) : 0;
		$state = $r['state'];
		if ($state === 'unsure' && $fails >= self::TRIES) {
			$state = 'bad';
			$r['why'] = sprintf(__('%1$s, %2$d times in a row', 'y4apk'), $r['why'], $fails);
		}
		$since = $state === 'ok' ? 0 : ((int) $row->since ?: $now);
		$wpdb->update(self::t(), ['state' => $state, 'why' => substr($r['why'], 0, 190), 'code' => $r['code'], 'fails' => $fails, 'checked' => $now, 'since' => $since], ['id' => (int) $row->id]);
		return $state;
	}

	/** Every 10 minutes: read some posts' links, then look at the links that are due. */
	public static function run($limit = self::BATCH) {
		global $wpdb;
		if (!self::settings()['on'] || get_option('y4apk_links_db') !== self::DBV || get_transient('y4apk_links_lock')) {
			return 0;
		}
		set_transient('y4apk_links_lock', 1, 2 * MINUTE_IN_SECONDS);
		$start = microtime(true);
		self::sync_some();
		$now = time();
		$t = self::t();
		$due = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $t WHERE ignored = 0 AND (checked = 0 OR (state IN ('bad','unsure') AND checked < %d) OR (state = 'ok' AND checked < %d))
			ORDER BY checked = 0 DESC, checked ASC LIMIT %d", $now - self::BAD_AGAIN, $now - self::OK_AGAIN, (int) $limit));
		$n = 0;
		foreach ($due as $row) {
			self::check_row($row);
			$n++;
			if (microtime(true) - $start > self::BUDGET) {
				break;
			}
		}
		self::save(['last_run' => $now]);
		delete_transient('y4apk_links_lock');
		delete_transient('y4apk_links_counts');
		return $n;
	}

	/** ['bad' => n, 'unsure' => n, 'ignored' => n, 'ok' => n, 'new' => n, 'all' => n] */
	public static function counts() {
		global $wpdb;
		$c = get_transient('y4apk_links_counts');
		if (is_array($c)) {
			return $c;
		}
		$c = ['bad' => 0, 'unsure' => 0, 'ignored' => 0, 'ok' => 0, 'new' => 0, 'all' => 0];
		if (get_option('y4apk_links_db') === self::DBV) {
			$t = self::t();
			foreach ((array) $wpdb->get_results("SELECT state, ignored, COUNT(*) AS n FROM $t GROUP BY state, ignored") as $r) {
				$c['all'] += (int) $r->n;
				if ((int) $r->ignored) {
					$c['ignored'] += (int) $r->n;
				} elseif (isset($c[$r->state])) {
					$c[$r->state] += (int) $r->n;
				}
			}
		}
		set_transient('y4apk_links_counts', $c, 5 * MINUTE_IN_SECONDS);
		return $c;
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Broken links
	 * ---------------------------------------------------------------- */

	public static function menu() {
		$n = self::counts()['bad'];
		$label = __('Broken links', 'y4apk') . ($n ? ' <span class="awaiting-mod">' . number_format_i18n($n) . '</span>' : '');
		add_submenu_page(Y4APK_MENU, __('Broken download links', 'y4apk'), $label, 'edit_others_posts', self::SLUG, [__CLASS__, 'render']);
	}

	public static function admin_url($args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function handle_admin() {
		global $wpdb;
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_links_admin');
		$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
		$tab = sanitize_key(wp_unslash($_POST['tab'] ?? 'bad'));
		$id = absint($_POST['id'] ?? 0);
		$t = self::t();
		$note = '';
		if ($do === 'recheck' && $id) {
			$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
			if ($row) {
				self::sync_post($row->post_id); // the post may have changed since
				$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE post_id = %d AND idx = %d", $row->post_id, $row->idx));
			}
			$note = $row ? (self::check_row($row) === 'ok' ? 'fixed' : 'still') : 'gone';
		} elseif (($do === 'ignore' || $do === 'unignore') && $id) {
			$wpdb->update($t, ['ignored' => $do === 'ignore' ? 1 : 0], ['id' => $id]);
			$note = $do;
		} elseif ($do === 'recheck_bad') {
			$rows = (array) $wpdb->get_results("SELECT * FROM $t WHERE ignored = 0 AND state IN ('bad','unsure') ORDER BY checked ASC LIMIT 25");
			$start = microtime(true);
			$fixed = 0;
			foreach ($rows as $row) {
				$fixed += self::check_row($row) === 'ok' ? 1 : 0;
				if (microtime(true) - $start > 25) {
					break;
				}
			}
			$note = 'rechecked';
			set_transient('y4apk_links_fixed', $fixed, MINUTE_IN_SECONDS);
		} elseif ($do === 'pass') { // read every post again and look at every link soon
			self::save(['cursor' => 0, 'pass_start' => time()]);
			$wpdb->query("UPDATE $t SET checked = 0 WHERE ignored = 0");
			wp_schedule_single_event(time(), self::CRON . '_now');
			$note = 'pass';
		} elseif ($do === 'settings') {
			self::save(['on' => empty($_POST['on']) ? 0 : 1]);
			self::setup();
			$note = 'saved';
		}
		delete_transient('y4apk_links_counts');
		wp_safe_redirect(self::admin_url(['tab' => $tab, 'note' => $note]));
		exit;
	}

	public static function render() {
		require __DIR__ . '/link-check-admin.php';
	}
}
add_action(Y4APK_Links::CRON . '_now', [Y4APK_Links::class, 'run']);
Y4APK_Links::init();
