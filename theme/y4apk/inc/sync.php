<?php
/**
 * Y4APK ▸ Catalog sync (2.30): where the workers outside WordPress
 * (sync-system/ in the repository) meet the theme.
 *
 *  - app_scraper.py reads app catalogs — each item's Name, Version and Link —
 *    and sends them here (POST /wp-json/y4apk/v1/sync/items), or writes them
 *    into {prefix}y4apk_sync_items itself when it can reach the database.
 *  - Each item is matched to a post: by the Google Play ID in its link (or one
 *    the scraper read off the page), else by App Name. A newer version than
 *    Google Play's goes to App updates ▸ Need a new file like any other
 *    ("Latest · example.com"). Followers hear of it once the new file is up,
 *    from push.php, as always — not before.
 *  - An app the site has no post for can become a new post (off by default):
 *    a draft made by Add from Google Play and written by Write with AI's queue
 *    (several keys per AI, Y4APK_Key_Pool), published once written if ticked.
 *    A few a day.
 *  - version_monitor.js calls POST /wp-json/y4apk/v1/sync/run every few
 *    minutes: items still waiting, new posts, followed posts whose version
 *    went up without a save (Y4APK_Push::catch_up) and the push queue — on
 *    time even when a page cache keeps WP-Cron from running. WP-Cron runs the
 *    same every 15 minutes.
 *
 * The workers sign in with the sync key (header X-Y4APK-Sync-Key, from App
 * updates ▸ Settings ▸ Catalog sync), good for these routes only, or with an
 * administrator's Application Password.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Sync {

	const OPTION     = 'y4apk_sync';
	const DB_VERSION = '1';
	const HOOK       = 'y4apk_sync_tick';
	const LOG        = 'y4apk_sync_log';  // the last runs, newest first
	const META       = '_y4apk_sync';     // the catalog listing behind a post: ['version', 'link', 'source', 'seen', 'by']
	const FROM       = '_y4apk_sync_from'; // the catalog link a new post was made from
	const MAX_ITEMS  = 500;  // items one request may send
	const FRESH      = 7;    // days a catalog version counts against Google Play's
	const SEARCHES   = 6;    // Google Play name searches per run (up to 4 requests each)
	const PER_RUN    = 5;    // new posts per run
	const STATES     = ['new', 'matched', 'nopost', 'added', 'unknown', 'missing'];

	// The same upsert as app_scraper.py's database mode: assignments run left to
	// right, so state and changed still see the old version and package. An
	// empty version or package never replaces a known one.
	const UPSERT = " ON DUPLICATE KEY UPDATE
		state = IF((VALUES(version) <> '' AND version <> VALUES(version)) OR (VALUES(package) <> '' AND package <> VALUES(package)), 'new', state),
		changed = IF(VALUES(version) <> '' AND version <> VALUES(version), VALUES(last_seen), changed),
		version = IF(VALUES(version) <> '', VALUES(version), version),
		package = IF(VALUES(package) <> '', VALUES(package), package),
		name = VALUES(name), link = VALUES(link), source = VALUES(source), last_seen = VALUES(last_seen)";

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'install']);
			add_action('admin_init', [__CLASS__, 'schedule']);
			add_action('admin_post_y4apk_sync_settings', [__CLASS__, 'handle_settings']);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Settings and table
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'on'       => 1, // the routes answer
			'key'      => '',
			'versions' => 1, // catalog versions count as the latest version
			'add'      => 0, // apps the site has no post for become posts
			'publish'  => 0, // … published once Write with AI has written them
			'per_day'  => 10,
			'author'   => 0, // 0: the first administrator
		];
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], self::defaults());
		$o['per_day'] = max(0, min(100, (int) $o['per_day']));
		return $o;
	}

	/** The sync key: Y4APK_SYNC_KEY in wp-config.php, else one made the first time it is needed. */
	public static function key() {
		if (defined('Y4APK_SYNC_KEY') && constant('Y4APK_SYNC_KEY')) {
			return (string) constant('Y4APK_SYNC_KEY');
		}
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		if (empty($o['key'])) {
			$o['key'] = wp_generate_password(40, false, false);
			update_option(self::OPTION, $o, false);
		}
		return (string) $o['key'];
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_sync_items';
	}

	private static function installed() {
		return get_option('y4apk_sync_db') === self::DB_VERSION;
	}

	public static function install() {
		if (self::installed()) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta('CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hash char(64) NOT NULL,
			link varchar(2048) NOT NULL,
			name varchar(255) NOT NULL,
			version varchar(64) NOT NULL DEFAULT '',
			package varchar(255) NOT NULL DEFAULT '',
			source varchar(191) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'new',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			match_by varchar(10) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			changed datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash),
			KEY state (state),
			KEY post_id (post_id)
		) $charset;");
		update_option('y4apk_sync_db', self::DB_VERSION, true);
	}

	/** Every 15 minutes while it is on (version_monitor.js, when it runs, comes more often). */
	public static function schedule() {
		$on = self::settings()['on'];
		$next = wp_next_scheduled(self::HOOK);
		if ($on && !$next) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'y4apk_15min', self::HOOK);
		} elseif (!$on && $next) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	/** Who new posts are made by: Settings', else the first administrator. */
	public static function author() {
		$id = (int) self::settings()['author'];
		if ($id && user_can($id, 'publish_posts')) {
			return $id;
		}
		$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
		return $admins ? (int) $admins[0] : 0;
	}

	/* ---------------------------------------------------------------- *
	 * Items
	 * ---------------------------------------------------------------- */

	/** A Google Play ID that looks like one ("com.supercell.clashofclans"), or ''. */
	private static function as_package($text) {
		$text = trim((string) $text);
		if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/i', $text) || preg_match('/\.(?:html?|php|aspx?|jsp|apks?|xapk|zip|obb|json|xml)$/i', $text)) {
			return '';
		}
		return $text;
	}

	/** The Google Play ID in a catalog link: a Play link's id=, an id/package parameter, or a part of the path. */
	public static function package_in($link) {
		$package = Y4APK_App_Updates::package_from($link);
		if ($package !== '') {
			return $package;
		}
		parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $args);
		foreach (['id', 'package', 'pkg', 'package_name', 'app'] as $k) {
			if (isset($args[$k]) && is_string($args[$k]) && ($package = self::as_package($args[$k])) !== '') {
				return $package;
			}
		}
		foreach (explode('/', (string) wp_parse_url($link, PHP_URL_PATH)) as $part) {
			if (($package = self::as_package(rawurldecode($part))) !== '') {
				return $package;
			}
		}
		return '';
	}

	/** One item as sent: ['hash', 'link', 'name', 'version', 'package', 'source'], or null when it has no name or no web link. */
	private static function clean($item) {
		if (!is_array($item)) {
			return null;
		}
		$link = trim((string) (isset($item['link']) ? $item['link'] : ''));
		$scheme = strtolower((string) wp_parse_url($link, PHP_URL_SCHEME));
		$name = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) (isset($item['name']) ? $item['name'] : '')), ENT_QUOTES, 'UTF-8')));
		if ($name === '' || strlen($link) > 2048 || !in_array($scheme, ['http', 'https'], true) || !filter_var($link, FILTER_VALIDATE_URL)) {
			return null;
		}
		$package = isset($item['package']) ? self::as_package(Y4APK_App_Updates::package_from($item['package']) ?: $item['package']) : '';
		$page = isset($item['source_url']) ? (string) $item['source_url'] : $link;
		$source = strtolower((string) (wp_parse_url($page, PHP_URL_HOST) ?: wp_parse_url($link, PHP_URL_HOST)));
		return [
			'hash'    => hash('sha256', $link), // as app_scraper.py makes it
			'link'    => $link,
			'name'    => mb_substr($name, 0, 255),
			'version' => mb_substr(sanitize_text_field((string) (isset($item['version']) ? $item['version'] : '')), 0, 64),
			'package' => $package !== '' ? $package : self::package_in($link),
			'source'  => mb_substr($source, 0, 191),
		];
	}

	/** Saves the items (new ones, and known ones with a new version or Google Play ID, wait to be matched). Counts. */
	public static function store(array $items) {
		global $wpdb;
		$out = ['received' => count($items), 'new' => 0, 'changed' => 0, 'same' => 0, 'invalid' => 0];
		$clean = [];
		foreach ($items as $item) {
			$c = self::clean($item);
			if ($c) {
				$clean[$c['hash']] = $c; // the same link twice: the last one
			} else {
				$out['invalid']++;
			}
		}
		if (!$clean) {
			return $out;
		}
		$table = self::table();
		$known = [];
		foreach (array_chunk(array_keys($clean), 200) as $hashes) {
			$in = implode(',', array_fill(0, count($hashes), '%s'));
			foreach ($wpdb->get_results($wpdb->prepare("SELECT hash, version FROM $table WHERE hash IN ($in)", $hashes)) as $r) {
				$known[$r->hash] = (string) $r->version;
			}
		}
		foreach ($clean as $hash => $c) {
			if (!isset($known[$hash])) {
				$out['new']++;
			} elseif ($c['version'] !== '' && $c['version'] !== $known[$hash]) {
				$out['changed']++;
			} else {
				$out['same']++;
			}
		}
		$now = current_time('mysql', true);
		foreach (array_chunk(array_values($clean), 100) as $chunk) {
			$values = [];
			foreach ($chunk as $c) {
				$values[] = $wpdb->prepare("(%s, %s, %s, %s, %s, %s, 'new', %s, %s)", $c['hash'], $c['link'], $c['name'], $c['version'], $c['package'], $c['source'], $now, $now);
			}
			$wpdb->query("INSERT INTO $table (hash, link, name, version, package, source, state, first_seen, last_seen) VALUES " . implode(',', $values) . self::UPSERT);
		}
		return $out;
	}

	/**
	 * What the site has, any post but trashed ones: [[package => post ID],
	 * [App Name, normalized => post ID] (the short name too, when only one
	 * post has it), [post ID => true for posts with a Google Play ID]].
	 */
	private static function site_index() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ('wp_GP_ID', 'wp_title_GP') AND p.post_type = 'post' AND p.post_status NOT IN ('trash', 'auto-draft')"
		);
		$packages = [];
		$names = [];
		$short = [];
		$linked = [];
		foreach ($rows as $r) {
			$id = (int) $r->post_id;
			if ($r->meta_key === 'wp_GP_ID') {
				$package = Y4APK_App_Updates::package_from($r->meta_value);
				if ($package !== '') {
					$packages[strtolower($package)] = $id;
					$linked[$id] = true;
				}
				continue;
			}
			$full = Y4APK_App_Updates::norm($r->meta_value);
			if ($full !== '') {
				$names[$full] = $id;
			}
			$s = Y4APK_App_Updates::norm(Y4APK_Writer::short_name(html_entity_decode((string) $r->meta_value, ENT_QUOTES, 'UTF-8')));
			if (mb_strlen($s) >= 6) {
				$short[$s][$id] = true;
			}
		}
		foreach ($short as $s => $ids) {
			if (count($ids) === 1 && !isset($names[$s])) {
				$names[$s] = (int) key($ids);
			}
		}
		return [$packages, $names, $linked];
	}

	/** The post an item is about: [post ID, 'package' | 'name'], or [0, '']. */
	private static function match_post($row, $index) {
		list($packages, $names) = $index;
		if ($row->package !== '' && isset($packages[strtolower($row->package)])) {
			return [$packages[strtolower($row->package)], 'package'];
		}
		$n = Y4APK_App_Updates::norm(Y4APK_App_Updates::name_from_title($row->name));
		if (mb_strlen($n) >= 6 && isset($names[$n])) {
			return [$names[$n], 'name'];
		}
		return [0, ''];
	}

	/**
	 * The catalog's listing, kept with the post — and its version as the post's
	 * latest when newer than the one stored. Only for a sure match: by Google
	 * Play ID, or by name for a post App updates cannot check (no Google Play
	 * ID). True when the latest version changed.
	 */
	private static function apply($post_id, $row, $by, $linked) {
		update_post_meta($post_id, self::META, wp_slash([
			'version' => (string) $row->version,
			'link'    => (string) $row->link,
			'source'  => (string) $row->source,
			'seen'    => time(),
			'by'      => $by,
		]));
		$n = Y4APK_App_Updates::number($row->version);
		if ($n === '' || !self::settings()['versions'] || ($by === 'name' && isset($linked[$post_id]))) {
			return false;
		}
		$latest = Y4APK_App_Updates::number(get_post_meta($post_id, Y4APK_App_Updates::LATEST, true));
		if ($latest !== '' && !version_compare($n, $latest, '>')) {
			return false;
		}
		update_post_meta($post_id, Y4APK_App_Updates::LATEST, $n);
		update_post_meta($post_id, Y4APK_App_Updates::SOURCE, 'catalog');
		update_post_meta($post_id, Y4APK_App_Updates::CHECKED, time());
		Y4APK_App_Updates::refresh_flag($post_id);
		return true;
	}

	/** Items waiting ('new') matched to posts, their versions passed on. Counts. */
	public static function process($limit = self::MAX_ITEMS) {
		global $wpdb;
		$out = ['matched' => 0, 'nopost' => 0, 'versions' => 0];
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE state = 'new' ORDER BY id LIMIT %d", $limit));
		if (!$rows) {
			return $out;
		}
		$index = self::site_index();
		foreach ($rows as $row) {
			$fields = [];
			if ($row->package === '' && ($package = self::package_in($row->link)) !== '') {
				$row->package = $fields['package'] = $package; // written straight into the table by app_scraper.py
			}
			list($post_id, $by) = self::match_post($row, $index);
			if ($post_id) {
				$out['versions'] += self::apply($post_id, $row, $by, $index[2]) ? 1 : 0;
				$out['matched']++;
			} else {
				$out['nopost']++;
			}
			$wpdb->update(self::table(), $fields + ['state' => $post_id ? 'matched' : 'nopost', 'post_id' => $post_id, 'match_by' => $by], ['id' => $row->id]);
		}
		return $out;
	}

	/**
	 * Apps no post has, as new posts: Add from Google Play's draft, then Write
	 * with AI's queue (and published once written, with Settings ▸ publish).
	 * An item without a Google Play ID is looked up on Google Play by name (the
	 * exact name, as App updates does). At most PER_RUN a run and Settings ▸
	 * per day. Counts.
	 */
	public static function add_new() {
		global $wpdb;
		$o = self::settings();
		$out = ['added' => 0, 'searched' => 0, 'had' => 0, 'unknown' => 0, 'missing' => 0];
		if (!$o['add'] || !class_exists('Y4APK_Discover') || !class_exists('Y4APK_Writer')) {
			return $out;
		}
		$today = 'y4apk_sync_added_' . gmdate('Ymd');
		$left = min(self::PER_RUN, $o['per_day'] - (int) get_transient($today));
		if ($left <= 0) {
			return $out + ['limit' => true];
		}
		$rows = $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE state = 'nopost' ORDER BY COALESCE(changed, first_seen) DESC, id DESC LIMIT 50");
		if (!$rows) {
			return $out;
		}
		$index = self::site_index();
		$mark = function ($row, $state, $post_id = 0, $package = null) use ($wpdb) {
			$fields = ['state' => $state, 'post_id' => (int) $post_id, 'match_by' => $post_id ? 'package' : ''];
			if ($package !== null) {
				$fields['package'] = $package;
			}
			$wpdb->update(self::table(), $fields, ['id' => $row->id]);
		};
		$was = get_current_user_id();
		wp_set_current_user(self::author()); // Add from Google Play makes the post as the current user
		foreach ($rows as $row) {
			if ($out['added'] >= $left) {
				break;
			}
			$package = (string) $row->package;
			if ($package === '') {
				if ($out['searched'] >= self::SEARCHES) {
					continue; // next run
				}
				$out['searched']++;
				$found = Y4APK_App_Updates::find_listing(Y4APK_App_Updates::name_from_title($row->name), '');
				if ($found === null) {
					break; // Google Play is busy: next run
				}
				if ($found === '') {
					$mark($row, 'unknown');
					$out['unknown']++;
					continue;
				}
				$package = $found;
			}
			if (isset($index[0][strtolower($package)])) {
				$id = $index[0][strtolower($package)];
				self::apply($id, $row, 'package', $index[2]);
				$mark($row, 'matched', $id, $package);
				$out['had']++;
				continue;
			}
			$id = Y4APK_Discover::add($package, (bool) $o['publish']);
			if (is_wp_error($id)) {
				$code = $id->get_error_code();
				if ($code === 'busy') {
					break;
				}
				$mark($row, $code === 'have' ? 'matched' : 'missing', 0, $package); // 'have': a post made since the index was read
				$out[$code === 'have' ? 'had' : 'missing']++;
				continue;
			}
			update_post_meta($id, self::FROM, wp_slash((string) $row->link));
			Y4APK_Writer::enqueue([$id], Y4APK_Writer::PARTS);
			$index[0][strtolower($package)] = $id;
			$mark($row, 'added', $id, $package);
			$out['added']++;
		}
		wp_set_current_user($was);
		if ($out['added']) {
			set_transient($today, (int) get_transient($today) + $out['added'], DAY_IN_SECONDS);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- *
	 * A run: version_monitor.js (POST /sync/run) or WP-Cron
	 * ---------------------------------------------------------------- */

	public static function run($by = 'cron') {
		if (get_transient('y4apk_sync_lock')) {
			return ['skipped' => 'another run is working'];
		}
		set_transient('y4apk_sync_lock', 1, 5 * MINUTE_IN_SECONDS);
		@set_time_limit(300);
		self::install();
		$out = [
			'items' => self::process(),
			'posts' => self::add_new(),
		];
		if (class_exists('Y4APK_Push')) {
			$sent = Y4APK_Push::process(); // what an earlier round left in the queue
			$out['push'] = [
				'sent'      => $sent,
				'caught_up' => (object) Y4APK_Push::catch_up(), // [post ID => version]; queue() sends their first batch itself
				'waiting'   => Y4APK_Push::queued(),
			];
		}
		if (class_exists('Y4APK_Writer')) {
			$wait = Y4APK_Writer::waiting();
			$out['writer'] = [
				'queue'   => count(Y4APK_Writer::queue()),
				'ai'      => Y4APK_Writer::current(),
				'waiting' => $wait ? $wait['why'] : '',
				'paused'  => Y4APK_Writer::settings()['paused'],
			];
		}
		$log = get_option(self::LOG, []);
		$log = is_array($log) ? $log : [];
		array_unshift($log, ['t' => time(), 'by' => $by, 'items' => $out['items'], 'posts' => $out['posts'],
			'push' => isset($out['push']) ? count((array) $out['push']['caught_up']) : 0]);
		update_option(self::LOG, array_slice($log, 0, 10), false);
		delete_transient('y4apk_sync_lock');
		return $out;
	}

	public static function tick() {
		if (self::settings()['on']) {
			self::run('cron');
		}
	}

	/* ---------------------------------------------------------------- *
	 * App updates: where a post's latest version was seen
	 * ---------------------------------------------------------------- */

	/** [version, source] to store: a fresh catalog version (by Google Play ID) newer than $version wins. */
	public static function prefer($post_id, $version, $source) {
		$m = get_post_meta($post_id, self::META, true);
		if (!self::settings()['versions'] || !is_array($m) || (isset($m['by']) ? $m['by'] : '') !== 'package'
			|| (int) (isset($m['seen']) ? $m['seen'] : 0) < time() - self::FRESH * DAY_IN_SECONDS) {
			return [$version, $source];
		}
		$n = Y4APK_App_Updates::number(isset($m['version']) ? $m['version'] : '');
		$have = Y4APK_App_Updates::number($version);
		return $n !== '' && ($have === '' || version_compare($n, $have, '>')) ? [$n, 'catalog'] : [$version, $source];
	}

	/** The catalog's site, for "Latest · …". */
	public static function seen_on($post_id) {
		$m = get_post_meta($post_id, self::META, true);
		return is_array($m) && !empty($m['source']) ? (string) $m['source'] : __('a catalog', 'y4apk');
	}

	/** The catalog page the latest version was seen on, or ''. */
	public static function catalog_link($post_id) {
		$m = get_post_meta($post_id, self::META, true);
		return get_post_meta($post_id, Y4APK_App_Updates::SOURCE, true) === 'catalog' && is_array($m) && !empty($m['link']) ? (string) $m['link'] : '';
	}

	/* ---------------------------------------------------------------- *
	 * REST routes
	 * ---------------------------------------------------------------- */

	public static function routes() {
		$auth = [__CLASS__, 'allowed'];
		register_rest_route('y4apk/v1', '/sync/items', ['methods' => 'POST', 'permission_callback' => $auth, 'callback' => [__CLASS__, 'rest_items']]);
		register_rest_route('y4apk/v1', '/sync/run', ['methods' => 'POST', 'permission_callback' => $auth, 'callback' => [__CLASS__, 'rest_run']]);
		register_rest_route('y4apk/v1', '/sync/status', ['methods' => 'GET', 'permission_callback' => $auth, 'callback' => [__CLASS__, 'rest_status']]);
	}

	/** The sync key in X-Y4APK-Sync-Key, or an administrator (Application Password). */
	public static function allowed(WP_REST_Request $request) {
		if (!self::settings()['on']) {
			return new WP_Error('y4apk_sync_off', 'Catalog sync is off (App updates ▸ Settings).', ['status' => 403]);
		}
		$given = (string) $request->get_header('x-y4apk-sync-key');
		if ($given !== '' && hash_equals(self::key(), $given)) {
			return true;
		}
		if (current_user_can('manage_options')) {
			return true;
		}
		return new WP_Error('y4apk_sync_auth', 'Send the sync key in the X-Y4APK-Sync-Key header, or an administrator\'s Application Password.',
			['status' => rest_authorization_required_code()]);
	}

	/** POST {items: [{name, version, link, package?, source_url?}, …]} from app_scraper.py: saved, then matched. */
	public static function rest_items(WP_REST_Request $request) {
		$items = $request->get_param('items');
		if (!is_array($items) || !$items) {
			return new WP_Error('y4apk_sync_items', 'Send {"items": [{"name": …, "version": …, "link": …}, …]}.', ['status' => 400]);
		}
		if (count($items) > self::MAX_ITEMS) {
			return new WP_Error('y4apk_sync_many', sprintf('At most %d items per request.', self::MAX_ITEMS), ['status' => 413]);
		}
		self::install();
		$out = self::store(array_values($items));
		$out['processed'] = self::process();
		return $out;
	}

	public static function rest_run() {
		return self::run('monitor');
	}

	public static function rest_status() {
		global $wpdb;
		self::install();
		$counts = array_fill_keys(self::STATES, 0);
		foreach ($wpdb->get_results('SELECT state, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY state') as $r) {
			$counts[$r->state] = (int) $r->n;
		}
		$o = self::settings();
		$log = get_option(self::LOG, []);
		return [
			'theme'    => defined('Y4APK_THEME_VERSION') ? Y4APK_THEME_VERSION : '',
			'items'    => $counts,
			'settings' => ['versions' => (bool) $o['versions'], 'add' => (bool) $o['add'], 'publish' => (bool) $o['publish'], 'per_day' => $o['per_day']],
			'last_run' => is_array($log) && $log ? $log[0] : null,
			'push'     => class_exists('Y4APK_Push') ? ['on' => Y4APK_Push::ready(), 'waiting' => Y4APK_Push::queued()] : null,
			'writer'   => class_exists('Y4APK_Writer') ? ['queue' => count(Y4APK_Writer::queue()), 'ai' => Y4APK_Writer::current()] : null,
		];
	}

	/* ---------------------------------------------------------------- *
	 * Admin: a card in App updates ▸ Settings
	 * ---------------------------------------------------------------- */

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-sync-settings');
		$in = wp_unslash($_POST);
		$o = self::settings();
		foreach (['on', 'versions', 'add', 'publish'] as $k) {
			$o[$k] = empty($in[$k]) ? 0 : 1;
		}
		$o['per_day'] = max(0, min(100, isset($in['per_day']) ? (int) $in['per_day'] : 10));
		$o['author'] = isset($in['author']) ? absint($in['author']) : 0;
		if (!empty($in['new_key'])) {
			$o['key'] = wp_generate_password(40, false, false);
		}
		update_option(self::OPTION, $o, false);
		self::schedule();
		wp_safe_redirect(add_query_arg(['page' => Y4APK_App_Updates::SLUG, 'view' => 'settings', 'y4apk_settings' => 1], admin_url('admin.php')) . '#au-sync');
		exit;
	}

	public static function settings_card() {
		global $wpdb;
		if (!current_user_can('manage_options')) {
			return; // the key is shown here
		}
		self::install();
		$o = self::settings();
		$counts = array_fill_keys(self::STATES, 0);
		foreach ($wpdb->get_results('SELECT state, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY state') as $r) {
			$counts[$r->state] = (int) $r->n;
		}
		$recent = $wpdb->get_results('SELECT name, version, source, state, post_id, link, last_seen FROM ' . self::table() . ' ORDER BY last_seen DESC, id DESC LIMIT 8');
		$log = get_option(self::LOG, []);
		$labels = [
			'new'     => __('waiting', 'y4apk'),
			'matched' => __('on the site', 'y4apk'),
			'nopost'  => __('no post', 'y4apk'),
			'added'   => __('made into posts', 'y4apk'),
			'unknown' => __('not found on Google Play', 'y4apk'),
			'missing' => __('gone from Google Play', 'y4apk'),
		];
		$authors = get_users(['role__in' => ['administrator', 'editor', 'author'], 'fields' => ['ID', 'display_name'], 'number' => 50]);
		$in_config = defined('Y4APK_SYNC_KEY') && constant('Y4APK_SYNC_KEY');
		?>
		<div class="au-card au-pad" id="au-sync" style="margin-top:14px">
			<h2 style="font-size:15px;margin:0 0 6px"><?php esc_html_e('Catalog sync', 'y4apk'); ?></h2>
			<p class="au-note" style="margin:0 0 12px"><?php esc_html_e('The sync-system workers in the theme’s repository send what app catalogs list — name, version, link — here. Each item is matched to a post by its Google Play ID, else its App Name; a newer version than Google Play’s shows in Need a new file. Followers are told once the new file is up, as always. Apps the site has no post for can become posts, written by Write with AI.', 'y4apk'); ?></p>
			<p style="margin:0 0 10px"><strong><?php echo esc_html(implode(' · ', array_map(function ($state) use ($counts, $labels) {
				return number_format_i18n($counts[$state]) . ' ' . $labels[$state];
			}, self::STATES))); ?></strong></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 12px;display:grid;gap:6px">
				<input type="hidden" name="action" value="y4apk_sync_settings"><?php wp_nonce_field('y4apk-sync-settings'); ?>
				<label><input type="checkbox" name="on" value="1" <?php checked($o['on']); ?>> <?php esc_html_e('Take items from the workers, and run every 15 minutes', 'y4apk'); ?></label>
				<label><input type="checkbox" name="versions" value="1" <?php checked($o['versions']); ?>> <?php esc_html_e('A catalog’s newer version counts as the app’s latest (matched by Google Play ID; by name only for posts without one)', 'y4apk'); ?></label>
				<label><input type="checkbox" name="add" value="1" <?php checked($o['add']); ?>> <?php esc_html_e('Make posts for apps the site has none for (Add from Google Play, then Write with AI)', 'y4apk'); ?></label>
				<label style="margin-left:24px"><input type="checkbox" name="publish" value="1" <?php checked($o['publish']); ?>> <?php esc_html_e('… and publish each once its article is written (else it stays a draft)', 'y4apk'); ?></label>
				<label style="margin-left:24px"><?php esc_html_e('At most', 'y4apk'); ?> <input type="number" name="per_day" min="0" max="100" value="<?php echo esc_attr($o['per_day']); ?>" style="width:70px"> <?php esc_html_e('new posts a day, by', 'y4apk'); ?>
					<select name="author"><option value="0"><?php esc_html_e('the first administrator', 'y4apk'); ?></option>
						<?php foreach ($authors as $u) : ?><option value="<?php echo esc_attr($u->ID); ?>" <?php selected((int) $o['author'], (int) $u->ID); ?>><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?>
					</select></label>
				<label><?php esc_html_e('Sync key (header X-Y4APK-Sync-Key):', 'y4apk'); ?>
					<input type="text" readonly value="<?php echo esc_attr(self::key()); ?>" style="width:360px;font-family:monospace" onfocus="this.select()">
					<?php if ($in_config) : ?><span class="au-meta"><?php esc_html_e('set in wp-config.php (Y4APK_SYNC_KEY)', 'y4apk'); ?></span>
					<?php else : ?><label><input type="checkbox" name="new_key" value="1"> <?php esc_html_e('Make a new one (the old one stops working)', 'y4apk'); ?></label><?php endif; ?></label>
				<p style="margin:4px 0 0"><button class="button"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
			</form>
			<p class="au-meta" style="margin:0 0 10px"><?php esc_html_e('For the workers’ .env:', 'y4apk'); ?></p>
			<code class="au-code"><?php echo esc_html('WP_URL=' . untrailingslashit(home_url())); ?><br><?php echo esc_html('WP_SYNC_KEY=' . __('(the key above)', 'y4apk')); ?></code>
			<?php if (is_array($log) && $log) : ?>
				<p class="au-meta" style="margin:10px 0 4px"><?php echo esc_html(sprintf(__('Last run %1$s ago (%2$s): %3$s matched, %4$s versions passed on, %5$s new posts.', 'y4apk'),
					human_time_diff($log[0]['t']), $log[0]['by'] === 'monitor' ? 'version_monitor.js' : 'WP-Cron',
					number_format_i18n($log[0]['items']['matched']), number_format_i18n($log[0]['items']['versions']), number_format_i18n($log[0]['posts']['added']))); ?></p>
			<?php endif; ?>
			<?php if ($recent) : ?>
				<dl class="au-dl" style="margin-top:10px">
					<?php foreach ($recent as $r) : ?>
						<dt><?php echo esc_html($r->name . ($r->version !== '' ? ' ' . $r->version : '')); ?></dt>
						<dd><a href="<?php echo esc_url($r->link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($r->source); ?> ↗</a> ·
							<?php echo esc_html(isset($labels[$r->state]) ? $labels[$r->state] : $r->state); ?>
							<?php if ($r->post_id) : ?> · <a href="<?php echo esc_url(get_edit_post_link($r->post_id)); ?>"><?php echo esc_html(get_the_title($r->post_id)); ?></a><?php endif; ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</div>
		<?php
	}
}

Y4APK_Sync::init();
