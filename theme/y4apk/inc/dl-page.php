<?php
/**
 * One download page for every folder (2.75): Y4APK ▸ Download page.
 *
 * Each download link of the site is a folder in files/up with its own
 * default.php: the "Preparing the file" page that starts the download and then
 * opens another page (the ads page). Before, every one held the whole page, so
 * changing the logo or that page meant editing thousands of files.
 *
 * Now a folder's default.php is two lines — the file, and "the page is over
 * there" — and the page itself is one file, files/up/_y4apk/page.php, with its
 * settings in files/up/_y4apk/page.json, both written from here:
 *
 *   - the logo: uploaded here, made smaller and kept on R2 (not this server);
 *   - the page it opens after the download starts, and after how many seconds;
 *   - where a visitor goes when the file is not there (the home page);
 *   - right-click and copy keys blocked, as before (the old "debugger" trap,
 *     which emptied the page, is gone).
 *
 * The page asks R2 whether the file is there before sending the visitor to it
 * (the answer kept 12 hours, a missing one 30 minutes): a file that is gone
 * sends the visitor to the home page instead of Cloudflare's "Object not
 * found". "Use it on every download page" turns the old pages into the
 * two-line kind in the background (each one's file kept; a page it cannot read
 * is left as it is), and keeps each old page as default.php.orig, so "Undo"
 * puts them all back. New folders get the two-line kind.
 *
 * Only from the site (2.75.1): a folder's link pasted somewhere else (a chat,
 * another site) or opened by itself sends the visitor to the home page, and
 * the site's own /download/N/ page opened by itself goes to its post first, so
 * nobody skips the post. "From the site" is the address the browser says it
 * came from (Referer); for the few browsers that never say, the post page
 * marks the tab and the download page leaves a 30-minute mark (y4dl, nothing
 * in it but "1") that the folder page accepts too.
 */

defined('ABSPATH') || exit;

class Y4APK_DLPage {

	const OPTION = 'y4apk_dlpage';      // ['logo', 'logo_key', 'icon', 'icon_key', 'ads', 'delay', 'missing', 'guard', 'check', 'gone', 'from_site', 'away', 'away_url', 'dl_from_site']
	const FROM   = 'y4apk_from';        // ?y4apk_from=<folder> on the site: a folder link opened elsewhere, sent on (2.75.2)
	const JOB    = 'y4apk_dlpage_job';  // ['do' => convert|undo, 'pos', 'done', 'kept', 'at']
	const HOOK   = 'y4apk_dlpage_run';
	const SLUG   = 'y4apk-download-page';
	const DIR    = '_y4apk';            // in files/up
	const VER    = '4';                 // the page's own version: written again when the theme changes it
	const MARK   = 'y4dl';              // "came through a download page of the site" (30 minutes)

	public static function init() {
		add_action(self::HOOK, [__CLASS__, 'run']);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 41);
			add_action('admin_post_y4apk_dlpage', [__CLASS__, 'handle']);
			add_action('admin_init', [__CLASS__, 'keep_installed']);
		}
		add_action('update_option_site_icon', [__CLASS__, 'site_icon_changed']);
		add_action('wp_head', [__CLASS__, 'post_mark'], 1);
		add_action('init', [__CLASS__, 'from_folder'], 20);
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], ['logo' => '', 'logo_key' => '', 'icon' => '', 'icon_key' => '', 'ads' => 'https://y4apk.com/run-script-apk/', 'delay' => 3, 'missing' => home_url('/'), 'guard' => 1, 'check' => 1, 'gone' => 1, 'from_site' => 1, 'away' => 'login', 'away_url' => '', 'dl_from_site' => 1]);
	}

	private static function up() {
		return class_exists('Y4APK_R2') ? (string) Y4APK_R2::settings()['dir'] : '';
	}

	/** Is the shared page in files/up? */
	public static function ready() {
		$up = self::up();
		return $up !== '' && is_file($up . '/' . self::DIR . '/page.php') && is_file($up . '/' . self::DIR . '/page.json');
	}

	/** A folder's default.php: its file, and the shared page. */
	public static function stub($file) {
		$file = (string) $file;
		return "<?php \$apk = \"" . $file . "\"; // the file (App updates writes it here)\nrequire dirname(__DIR__) . '/" . self::DIR . "/page.php'; // the page: Y4APK ▸ Download page\n";
	}

	public static function is_stub($html) {
		return strpos((string) $html, '/' . self::DIR . '/page.php') !== false;
	}

	/** A file address a stub may hold: http(s), no quotes, $ or backslash. */
	private static function safe_url($u) {
		return $u === '#' || (bool) preg_match('~^https?://[^\s"\'$\\\\<>]+$~i', (string) $u);
	}

	/* ---------------------------------------------------------------- *
	 * The shared page and its settings, in files/up/_y4apk
	 * ---------------------------------------------------------------- */

	/** Writes page.php (when the theme's is newer) and page.json (always). True or a message. */
	public static function install() {
		$up = self::up();
		if ($up === '' || !is_dir($up)) {
			return __('The download pages folder is not set (App updates ▸ Settings).', 'y4apk');
		}
		$dir = $up . '/' . self::DIR;
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			return sprintf(__('Could not make the folder %s.', 'y4apk'), $dir);
		}
		if (!is_file($dir . '/.htaccess')) {
			@file_put_contents($dir . '/.htaccess', self::deny('<FilesMatch "\\.(json|txt|orig)$">', '</FilesMatch>'), LOCK_EX);
		}
		if (!is_dir($dir . '/state')) {
			wp_mkdir_p($dir . '/state');
			@file_put_contents($dir . '/state/.htaccess', self::deny(), LOCK_EX);
		}
		if ((string) get_option('y4apk_dlpage_v') !== self::VER || !is_file($dir . '/page.php')) {
			if (file_put_contents($dir . '/page.php', self::RENDER, LOCK_EX) === false) {
				return sprintf(__('Could not write %s.', 'y4apk'), $dir . '/page.php');
			}
			update_option('y4apk_dlpage_v', self::VER, false);
		}
		$s = self::settings();
		$r2 = class_exists('Y4APK_R2') ? (string) Y4APK_R2::settings()['public'] : '';
		$cfg = [
			'logo'    => (string) $s['logo'],
			'icon'    => (string) ($s['icon'] !== '' ? $s['icon'] : get_site_icon_url(192)),
			'name'    => (string) get_bloginfo('name'),
			'ads'     => (string) $s['ads'],
			'delay'   => max(0, min(30, (int) $s['delay'])),
			'missing' => (string) ($s['missing'] !== '' ? $s['missing'] : home_url('/')),
			'guard'   => (int) (bool) $s['guard'],
			'check'   => (int) (bool) $s['check'],
			'r2_host' => strtolower((string) wp_parse_url($r2, PHP_URL_HOST)),
			'from_site' => (int) (bool) $s['from_site'],
			'away'    => self::away_base(),
			'away_add' => in_array($s['away'], ['post', 'login'], true) ? 1 : 0,
			'hosts'   => self::hosts(),
		];
		if (file_put_contents($dir . '/page.json', wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
			return sprintf(__('Could not write %s.', 'y4apk'), $dir . '/page.json');
		}
		return true;
	}

	/**
	 * Where a folder link opened elsewhere sends the visitor (2.75.2), as set:
	 * 'post' (the file's post), 'login' (the sign-in page, then the post),
	 * 'home', or 'url' (an address). The first two go through the site
	 * (?y4apk_from=<folder>), which knows the post; the folder page adds the name.
	 */
	public static function away_base() {
		$s = self::settings();
		if ($s['away'] === 'post' || $s['away'] === 'login') {
			$home = home_url('/');
			return $home . (strpos($home, '?') === false ? '?' : '&') . self::FROM . '=';
		}
		return $s['away'] === 'url' && $s['away_url'] !== '' ? (string) $s['away_url'] : home_url('/');
	}

	/** ?y4apk_from=<folder>: to the folder's post, or to sign in and then the post; to the home page when the post is not known. */
	public static function from_folder() {
		if (!isset($_GET[self::FROM]) || is_admin() || wp_doing_ajax()) {
			return;
		}
		$s = self::settings();
		$folder = (string) wp_unslash($_GET[self::FROM]);
		$post = 0;
		if ($folder !== '' && strlen($folder) < 200 && strpbrk($folder, "/\\\0") === false && $folder[0] !== '.' && is_dir(self::up() . '/' . $folder)) {
			$post = self::post_of($folder);
		}
		$login = $s['away'] === 'login' && !is_user_logged_in() && class_exists('Y4APK_Members');
		if ($post) {
			$to = $login ? Y4APK_Members::url('login', ['next' => 'post', 'pid' => $post]) : get_permalink($post);
		} else {
			$to = $login ? Y4APK_Members::url('login') : home_url('/');
		}
		nocache_headers();
		wp_safe_redirect($to, 302);
		exit;
	}

	/** The published post a download folder belongs to (0: none), from Broken links' table, else the posts' download rows; kept a day. */
	public static function post_of($folder) {
		$key = 'y4apk_dlf_' . md5($folder);
		$got = get_transient($key);
		if ($got !== false) {
			return (int) $got;
		}
		global $wpdb;
		$dir = class_exists('Y4APK_R2') ? (string) Y4APK_R2::settings()['dir_url'] : '';
		$id = 0;
		if ($dir !== '' && get_option('y4apk_links_db') && class_exists('Y4APK_Links')) {
			$h = [];
			foreach ([$folder, rawurlencode($folder)] as $f) {
				$h[] = md5($dir . $f);
				$h[] = md5($dir . $f . '/');
			}
			$id = (int) $wpdb->get_var($wpdb->prepare('SELECT l.post_id FROM ' . Y4APK_Links::t() . " l JOIN {$wpdb->posts} p ON p.ID = l.post_id AND p.post_status = 'publish'
				WHERE l.uhash IN (%s, %s, %s, %s) ORDER BY l.post_id DESC LIMIT 1", $h[0], $h[1], $h[2], $h[3]));
		}
		if (!$id) {
			$id = (int) $wpdb->get_var($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status = 'publish' AND p.post_type = 'post'
				WHERE m.meta_key = 'repeatable_download_link' AND m.meta_value LIKE %s ORDER BY m.post_id DESC LIMIT 1", '%' . $wpdb->esc_like('/' . $folder) . '%'));
		}
		set_transient($key, $id, $id ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
		return $id;
	}

	/** The site's own addresses: a folder page opened from one of these is "from the site". */
	public static function hosts() {
		$home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$bare = preg_replace('/^www\./', '', $home);
		$dir = class_exists('Y4APK_R2') ? strtolower((string) wp_parse_url((string) Y4APK_R2::settings()['dir_url'], PHP_URL_HOST)) : '';
		return array_values(array_unique(array_filter([$home, $bare, 'www.' . $bare, $dir])));
	}

	/** Where the 30-minute mark can live so the folder pages see it: the site's own domain, when the folders are under it. */
	public static function mark_domain() {
		$bare = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
		$dir = class_exists('Y4APK_R2') ? strtolower((string) wp_parse_url((string) Y4APK_R2::settings()['dir_url'], PHP_URL_HOST)) : '';
		return $bare !== '' && strpos($bare, '.') !== false && ($dir === $bare || substr($dir, -strlen('.' . $bare)) === '.' . $bare) ? $bare : '';
	}

	/** A new Site Icon: the download pages' tab icon follows (when none of their own was uploaded). */
	public static function site_icon_changed() {
		if (self::ready() && self::settings()['icon'] === '') {
			self::install();
		}
	}

	/** The post page marks the tab, for browsers that never say where they came from (2.75.1). */
	public static function post_mark() {
		if (is_singular('post') && !isset($GLOBALS['wp_query']->query_vars['download']) && self::settings()['dl_from_site']) {
			echo '<script>try{sessionStorage.setItem("y4p","' . (int) get_queried_object_id() . '")}catch(e){}</script>' . "\n";
		}
	}

	/**
	 * At the top of a /download/N/ page (2.75.1): opened by itself — not from
	 * the site, and not after its post in this tab — it goes to its post.
	 * Through, it leaves the 30-minute mark the folder pages accept.
	 */
	public static function download_guard() {
		$s = self::settings();
		$id = (int) get_queried_object_id();
		$post = $id ? get_permalink($id) : '';
		if (!$post || (!$s['dl_from_site'] && !$s['from_site'])) {
			return;
		}
		$cfg = ['post' => $s['dl_from_site'] ? $post : '', 'id' => (string) $id, 'mark' => $s['from_site'] ? self::mark_domain() : '', 'name' => self::MARK];
		echo '<script>(function(c){var ok=false;try{var r=document.referrer,b=function(u){return new URL(u).hostname.replace(/^www\./,"")};ok=!!r&&b(r)===b(location.href)}catch(e){}'
			. 'try{ok=ok||sessionStorage.getItem("y4p")===c.id}catch(e){}'
			. 'if(!ok&&c.post){document.documentElement.style.visibility="hidden";location.replace(c.post);return}'
			. 'if(c.mark)document.cookie=c.name+"=1;path=/;max-age=1800;SameSite=Lax;domain="+c.mark+(location.protocol=="https:"?";Secure":"")'
			. '})(' . wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>' . "\n";
	}

	/** "Nobody may open these", for Apache 2.2 and 2.4 and LiteSpeed alike. */
	private static function deny($open = '', $close = '') {
		$in = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
		return $open !== '' ? $open . "\n" . $in . $close . "\n" : $in;
	}

	/** After a theme update: the newer page.php, once. */
	public static function keep_installed() {
		if (current_user_can('manage_options') && self::ready() && (string) get_option('y4apk_dlpage_v') !== self::VER) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------- *
	 * Every folder's page: to the two-line kind, or back
	 * ---------------------------------------------------------------- */

	/** The folders in files/up (their names), in order. */
	private static function folders() {
		$up = self::up();
		$out = [];
		foreach (is_dir($up) ? (array) scandir($up) : [] as $d) {
			if ($d !== '.' && $d !== '..' && $d[0] !== '_' && $d[0] !== '.' && is_dir($up . '/' . $d)) {
				$out[] = $d;
			}
		}
		return $out;
	}

	/** How many use the shared page, how many do not, and how many it cannot read. */
	public static function counts() {
		$up = self::up();
		$n = ['all' => 0, 'shared' => 0, 'old' => 0, 'none' => 0];
		foreach (self::folders() as $d) {
			$f = $up . '/' . $d . '/default.php';
			$n['all']++;
			if (!is_file($f)) {
				$n['none']++;
				continue;
			}
			$head = (string) file_get_contents($f, false, null, 0, 400);
			$n[self::is_stub($head) ? 'shared' : 'old']++;
		}
		return $n;
	}

	/** One folder: old page → two lines (the old one kept as default.php.orig). 'done', 'kept' (cannot read its file) or 'skip'. */
	private static function convert_one($d) {
		$f = self::up() . '/' . $d . '/default.php';
		$html = is_file($f) ? (string) file_get_contents($f) : '';
		if ($html === '' || self::is_stub($html)) {
			return 'skip';
		}
		$to = class_exists('Y4APK_Links') ? Y4APK_Links::page_target($html) : '';
		if ($to === '' || !self::safe_url($to)) {
			return 'kept'; // a page of its own kind: left as it is
		}
		if (!is_file($f . '.orig') && !@copy($f, $f . '.orig')) {
			return 'kept';
		}
		return file_put_contents($f, self::stub($to), LOCK_EX) !== false ? 'done' : 'kept';
	}

	private static function undo_one($d) {
		$f = self::up() . '/' . $d . '/default.php';
		if (!is_file($f . '.orig')) {
			return 'skip';
		}
		$now = (string) file_get_contents($f);
		if (!self::is_stub($now)) {
			return 'skip';
		}
		// The file it had then may have been replaced since (App updates): the old page gets the file of now
		$old = (string) file_get_contents($f . '.orig');
		$to = class_exists('Y4APK_Links') ? Y4APK_Links::page_target($now) : '';
		if ($to !== '' && $to !== '#' && class_exists('Y4APK_R2')) {
			list($old) = Y4APK_R2::fill_page($old, $to);
		}
		if (file_put_contents($f, $old, LOCK_EX) === false) {
			return 'kept';
		}
		@unlink($f . '.orig');
		return 'done';
	}

	public static function start($do) {
		update_option(self::JOB, ['do' => $do === 'undo' ? 'undo' : 'convert', 'pos' => 0, 'done' => 0, 'kept' => 0, 'at' => time()], false);
		self::run(8);
		if (get_option(self::JOB) && !wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + 5, self::HOOK);
		}
	}

	/** Some folders (for $budget seconds), then again in the background until all are done. */
	public static function run($budget = 20) {
		$job = get_option(self::JOB);
		if (!is_array($job)) {
			return;
		}
		$list = self::folders();
		$t0 = microtime(true);
		$pos = (int) $job['pos'];
		while ($pos < count($list) && microtime(true) - $t0 < $budget) {
			$r = $job['do'] === 'undo' ? self::undo_one($list[$pos]) : self::convert_one($list[$pos]);
			if ($r === 'done') {
				$job['done']++;
			} elseif ($r === 'kept') {
				$job['kept']++;
			}
			$pos++;
		}
		$job['pos'] = $pos;
		if ($pos >= count($list)) {
			delete_option(self::JOB);
			update_option('y4apk_dlpage_last', $job + ['end' => time()], false);
			if (function_exists('y4apk_purge_pages')) {
				y4apk_purge_pages(); // and Cloudflare's copies of the folders' pages
			}
			return;
		}
		update_option(self::JOB, $job, false);
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + 10, self::HOOK);
		}
	}

	/* ---------------------------------------------------------------- *
	 * The logo, on R2
	 * ---------------------------------------------------------------- */

	/** A picture for the page, on R2: 'logo' (≤ 800 px WebP) or 'icon' (192 px square PNG, the tab icon). */
	private static function take_logo($f, $kind = 'logo') {
		if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
			return new WP_Error('y4dl', __('The picture did not arrive. Try again.', 'y4apk'));
		}
		if ((int) $f['size'] > 5 * MB_IN_BYTES) {
			return new WP_Error('y4dl', __('The picture can be up to 5 MB.', 'y4apk'));
		}
		$info = @getimagesize($f['tmp_name']);
		if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
			return new WP_Error('y4dl', __('Use a PNG, JPG, WebP or GIF picture.', 'y4apk'));
		}
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return new WP_Error('y4dl', __('R2 is not set up (App updates ▸ Settings).', 'y4apk'));
		}
		if ($info[2] === IMAGETYPE_GIF && $kind !== 'icon') {
			$bytes = (string) file_get_contents($f['tmp_name']);
			$ext = 'gif';
			$type = 'image/gif';
		} else {
			$open = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
			$src = function_exists($open[$info[2]]) ? @call_user_func($open[$info[2]], $f['tmp_name']) : false;
			if (!$src) {
				return new WP_Error('y4dl', __('The picture could not be read.', 'y4apk'));
			}
			$w = imagesx($src);
			$h = imagesy($src);
			$box = $kind === 'icon' ? 192 : 800;
			$k = $kind === 'icon' ? $box / max($w, $h) : min(1, $box / max($w, $h));
			$ow = max(1, (int) round($w * $k));
			$oh = max(1, (int) round($h * $k));
			$cw = $kind === 'icon' ? $box : $ow; // an icon: square, the picture in the middle
			$ch = $kind === 'icon' ? $box : $oh;
			$dst = imagecreatetruecolor($cw, $ch);
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
			imagecopyresampled($dst, $src, (int) (($cw - $ow) / 2), (int) (($ch - $oh) / 2), 0, 0, $ow, $oh, $w, $h);
			imagedestroy($src);
			ob_start();
			$webp = $kind !== 'icon' && function_exists('imagewebp'); // a tab icon: PNG, which every browser shows
			$webp ? imagewebp($dst, null, 90) : imagepng($dst, null, 8);
			$bytes = (string) ob_get_clean();
			imagedestroy($dst);
			$ext = $webp ? 'webp' : 'png';
			$type = $webp ? 'image/webp' : 'image/png';
		}
		$key = 'site/download-' . ($kind === 'icon' ? 'icon' : 'logo') . '-' . strtolower(wp_generate_password(10, false, false)) . '.' . $ext;
		$put = Y4APK_R2::put($key, $bytes, $type);
		if (is_wp_error($put)) {
			return $put;
		}
		return ['key' => $key, 'url' => Y4APK_R2::public_url($key)];
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Download page
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_submenu_page(Y4APK_MENU, __('Download page', 'y4apk'), __('Download page', 'y4apk'), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function handle() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_dlpage');
		$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
		$note = '';
		if ($do === 'save') {
			$s = self::settings();
			$in = wp_unslash($_POST);
			$ads = trim((string) ($in['ads'] ?? ''));
			$s['ads'] = $ads === '' ? '' : esc_url_raw($ads, ['http', 'https']);
			$s['delay'] = max(0, min(30, (int) ($in['delay'] ?? 3)));
			$miss = trim((string) ($in['missing'] ?? ''));
			$s['missing'] = $miss === '' ? home_url('/') : esc_url_raw($miss, ['http', 'https']);
			$s['guard'] = empty($in['guard']) ? 0 : 1;
			$s['check'] = empty($in['check']) ? 0 : 1;
			$s['gone'] = empty($in['gone']) ? 0 : 1;
			$s['from_site'] = empty($in['from_site']) ? 0 : 1;
			$s['away'] = in_array($in['away'] ?? '', ['post', 'login', 'home', 'url'], true) ? $in['away'] : 'login';
			$away_url = trim((string) ($in['away_url'] ?? ''));
			$s['away_url'] = $away_url === '' ? '' : esc_url_raw($away_url, ['http', 'https']);
			if ($s['away'] === 'url' && $s['away_url'] === '') {
				$s['away'] = 'home';
			}
			$s['dl_from_site'] = empty($in['dl_from_site']) ? 0 : 1;
			if (!empty($_FILES['icon']['name'])) {
				$l = self::take_logo($_FILES['icon'], 'icon');
				if (is_wp_error($l)) {
					set_transient('y4apk_dlpage_msg', $l->get_error_message(), 120);
					$note = 'err';
				} else {
					if ($s['icon_key'] !== '' && strpos($s['icon_key'], 'site/') === 0) {
						Y4APK_R2::remove($s['icon_key']);
					}
					$s['icon'] = $l['url'];
					$s['icon_key'] = $l['key'];
				}
			} elseif (!empty($in['icon_remove'])) {
				if ($s['icon_key'] !== '' && strpos($s['icon_key'], 'site/') === 0 && class_exists('Y4APK_R2')) {
					Y4APK_R2::remove($s['icon_key']);
				}
				$s['icon'] = '';
				$s['icon_key'] = '';
			}
			if (!empty($_FILES['logo']['name'])) {
				$l = self::take_logo($_FILES['logo']);
				if (is_wp_error($l)) {
					set_transient('y4apk_dlpage_msg', $l->get_error_message(), 120);
					$note = 'err';
				} else {
					if ($s['logo_key'] !== '' && strpos($s['logo_key'], 'site/') === 0) {
						Y4APK_R2::remove($s['logo_key']); // the one it replaces
					}
					$s['logo'] = $l['url'];
					$s['logo_key'] = $l['key'];
				}
			} elseif (!empty($in['logo_remove'])) {
				if ($s['logo_key'] !== '' && strpos($s['logo_key'], 'site/') === 0 && class_exists('Y4APK_R2')) {
					Y4APK_R2::remove($s['logo_key']);
				}
				$s['logo'] = '';
				$s['logo_key'] = '';
			}
			update_option(self::OPTION, $s, false);
			$r = self::install();
			if ($r !== true) {
				set_transient('y4apk_dlpage_msg', $r, 120);
				$note = 'err';
			} elseif ($note === '') {
				$note = 'saved';
				if (function_exists('y4apk_purge_pages')) {
					y4apk_purge_pages(); // the folders' pages, kept by LiteSpeed and Cloudflare
				}
			}
		} elseif ($do === 'convert' || $do === 'undo') {
			$r = self::install();
			if ($r !== true) {
				set_transient('y4apk_dlpage_msg', $r, 120);
				$note = 'err';
			} else {
				self::start($do);
				$note = $do === 'undo' ? 'undoing' : 'converting';
			}
		}
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'note' => $note], admin_url('admin.php')));
		exit;
	}

	public static function render() {
		$job = get_option(self::JOB);
		if (is_array($job)) {
			self::run(5); // a little more while the owner watches
			$job = get_option(self::JOB);
		}
		$s = self::settings();
		$n = self::counts();
		$last = get_option('y4apk_dlpage_last');
		$note = sanitize_key(wp_unslash($_GET['note'] ?? ''));
		$msg = (string) get_transient('y4apk_dlpage_msg');
		delete_transient('y4apk_dlpage_msg');
		$example = '';
		if (class_exists('Y4APK_R2')) {
			$up = self::up();
			foreach (self::folders() as $d) {
				if (self::is_stub((string) @file_get_contents($up . '/' . $d . '/default.php', false, null, 0, 400))) {
					$example = Y4APK_R2::settings()['dir_url'] . rawurlencode($d) . '/';
					break;
				}
			}
		}
		$form = function ($do) {
			echo '<input type="hidden" name="action" value="y4apk_dlpage"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
			wp_nonce_field('y4apk_dlpage');
		};
		$notes = ['saved' => __('Saved: every download page that uses the shared page shows it now.', 'y4apk'), 'converting' => __('Turning the download pages into the shared one, in the background.', 'y4apk'), 'undoing' => __('Putting the old pages back, in the background.', 'y4apk'), 'err' => $msg];
		?>
		<div class="wrap y4ui">
			<?php if (is_array($job)) : ?><meta http-equiv="refresh" content="6"><?php endif; ?>
			<?php Y4APK_Admin_UI::head(['icon' => 'globe', 'tint' => 'violet', 'title' => esc_html__('Download page', 'y4apk'),
				'sub' => esc_html__('The “Preparing the file” page of every download (files/up/…): its logo, the page it opens after the download starts, and what happens when the file is gone. One place for all of them.', 'y4apk')]); ?>
			<?php if ($note && !empty($notes[$note])) : ?><div class="notice notice-<?php echo $note === 'err' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div><?php endif; ?>

			<form class="y4ui-card" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php $form('save'); ?>
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('The page', 'y4apk'); ?></h2><p><?php esc_html_e('Changes show on every download page that uses the shared page at once.', 'y4apk'); ?><?php if ($example !== '') : ?> <a href="<?php echo esc_url($example); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open one to see it', 'y4apk'); ?> ↗</a><?php endif; ?></p></div></div>
				<div class="y4ui-card-b">
					<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
						<div style="width:220px;height:90px;border-radius:12px;background:#fbfdfd;box-shadow:inset 0 0 0 1px #e3eaec;display:grid;place-items:center;overflow:hidden">
							<?php if ($s['logo'] !== '') : ?><img src="<?php echo esc_url($s['logo']); ?>" alt="" style="max-width:200px;max-height:76px"><?php else : ?><b style="font-size:22px;color:#ff2a2a"><?php echo esc_html(get_bloginfo('name')); ?></b><?php endif; ?>
						</div>
						<div>
							<label class="y4ui-label" for="y4dl-logo"><?php esc_html_e('Logo', 'y4apk'); ?></label><br>
							<input type="file" id="y4dl-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
							<p class="y4ui-hint"><?php esc_html_e('PNG, JPG, WebP or GIF, up to 5 MB: made at most 800 px and kept on R2 (Cloudflare), not on this server. Without one, the site’s name is shown.', 'y4apk'); ?></p>
							<?php if ($s['logo'] !== '') : ?><label class="y4ui-opt"><input type="checkbox" name="logo_remove" value="1"> <span><?php esc_html_e('Remove the logo', 'y4apk'); ?></span></label><?php endif; ?>
						</div>
					</div>
					<?php $icon = $s['icon'] !== '' ? $s['icon'] : (string) get_site_icon_url(192); ?>
					<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
						<div style="width:220px;height:44px;border-radius:10px 10px 0 0;background:#dfe5e8;display:flex;align-items:center;gap:8px;padding:0 12px;box-sizing:border-box;overflow:hidden" title="<?php esc_attr_e('How the browser tab looks', 'y4apk'); ?>">
							<?php if ($icon !== '') : ?><img src="<?php echo esc_url($icon); ?>" alt="" width="16" height="16" style="flex:none"><?php else : ?><span class="dashicons dashicons-admin-site-alt3" style="font-size:16px;width:16px;height:16px;color:#5b6b72"></span><?php endif; ?>
							<span style="font-size:12px;white-space:nowrap;color:#26343a">Preparing the file</span>
						</div>
						<div>
							<label class="y4ui-label" for="y4dl-icon"><?php esc_html_e('Tab icon', 'y4apk'); ?></label><br>
							<input type="file" id="y4dl-icon" name="icon" accept="image/png,image/jpeg,image/webp,image/gif">
							<p class="y4ui-hint"><?php echo esc_html($s['icon'] !== '' ? __('The small picture on the browser tab: made a 192 px square PNG and kept on R2 (Cloudflare).', 'y4apk') : __('The small picture on the browser tab (now: the site’s own icon, from Appearance ▸ Customize ▸ Site Identity). Upload one to use another: made a 192 px square PNG and kept on R2 (Cloudflare).', 'y4apk')); ?></p>
							<?php if ($s['icon'] !== '') : ?><label class="y4ui-opt"><input type="checkbox" name="icon_remove" value="1"> <span><?php esc_html_e('Remove it (the site’s own icon again)', 'y4apk'); ?></span></label><?php endif; ?>
						</div>
					</div>
					<div class="y4ui-fields">
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('After the download starts, open this page', 'y4apk'); ?></span><input type="url" name="ads" value="<?php echo esc_attr($s['ads']); ?>" placeholder="https://"></label>
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('…after (seconds)', 'y4apk'); ?></span><input type="number" min="0" max="30" name="delay" value="<?php echo esc_attr($s['delay']); ?>"></label>
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('When the file is gone, send visitors to', 'y4apk'); ?></span><input type="url" name="missing" value="<?php echo esc_attr($s['missing']); ?>"></label>
					</div>
					<p class="y4ui-hint"><?php esc_html_e('“Open this page” empty: no other page opens. On a slow phone a big file can take a few seconds to start; 4–5 seconds is safer than 3, so the next page never stops a download that has not started yet.', 'y4apk'); ?></p>
					<label class="y4ui-opt" style="margin-top:10px"><input type="checkbox" name="check" value="1" <?php checked($s['check']); ?>> <span><b><?php esc_html_e('Check the file is there first', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('Asks R2 before sending a visitor to a file (the answer is kept 12 hours, a missing one 30 minutes): a file that is gone sends the visitor to the page above instead of Cloudflare’s “Object not found”.', 'y4apk'); ?></span></span></label>
					<label class="y4ui-opt"><input type="checkbox" name="gone" value="1" <?php checked($s['gone']); ?>> <span><b><?php esc_html_e('Say so on the site when a file is gone', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('When Broken links found a file surely gone (not a host that only did not answer), its download page on the site shows “This file is not available right now” with the post’s other downloads, instead of the countdown.', 'y4apk'); ?></span></span></label>
					<label class="y4ui-opt"><input type="checkbox" name="from_site" value="1" <?php checked($s['from_site']); ?>> <span><b><?php esc_html_e('Download folders only from the site', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('A folder’s link (files.…/up/…) pasted in a chat or on another site, or typed in, sends the visitor on (below). Opened from the site’s download page, it works as before.', 'y4apk'); ?></span>
						<span style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px"><span class="y4ui-label" style="margin:0"><?php esc_html_e('Send them to', 'y4apk'); ?></span>
							<select name="away" id="y4dl-away" onchange="document.getElementById('y4dl-away-url').style.display=this.value==='url'?'':'none'">
								<?php foreach (['login' => __('Sign in (or sign up), then the file’s post', 'y4apk'), 'post' => __('The file’s post', 'y4apk'), 'home' => __('The home page', 'y4apk'), 'url' => __('This address…', 'y4apk')] as $k => $label) : ?>
								<option value="<?php echo esc_attr($k); ?>" <?php selected($s['away'], $k); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="url" name="away_url" id="y4dl-away-url" value="<?php echo esc_attr($s['away_url']); ?>" placeholder="https://" style="min-width:260px;<?php echo $s['away'] === 'url' ? '' : 'display:none'; ?>">
						</span>
						<span class="y4ui-hint" style="display:block;margin-top:6px"><?php esc_html_e('Members who are already signed in go straight to the post. A folder whose post is not known: the home page (or the sign-in page).', 'y4apk'); ?></span></span></label>
					<label class="y4ui-opt"><input type="checkbox" name="dl_from_site" value="1" <?php checked($s['dl_from_site']); ?>> <span><b><?php esc_html_e('The site’s download pages only after their post', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('A /download/N/ page opened by itself (its link pasted somewhere) goes to its post first, so nobody skips the post page. From the post, it works as before.', 'y4apk'); ?></span></span></label>
					<label class="y4ui-opt"><input type="checkbox" name="guard" value="1" <?php checked($s['guard']); ?>> <span><b><?php esc_html_e('Block right-click and copy keys', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('As the old pages did. It only stops the casual: the file’s address is in the page anyway. The old pages’ trap that emptied the page when developer tools were open is not used.', 'y4apk'); ?></span></span></label>
					<div class="y4ui-savebar"><span class="y4ui-hint"><?php echo esc_html(self::ready() ? __('The shared page is in files/up/_y4apk.', 'y4apk') : __('Save once to put the shared page in files/up/_y4apk.', 'y4apk')); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
				</div>
			</form>

			<div class="y4ui-card" style="margin-top:16px">
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('Every download page', 'y4apk'); ?></h2><p><?php echo esc_html(sprintf(__('%1$s download folders: %2$s use the shared page, %3$s still have a page of their own.', 'y4apk'), number_format_i18n($n['all']), number_format_i18n($n['shared']), number_format_i18n($n['old']))); ?></p></div></div>
				<div class="y4ui-card-b">
					<?php if (is_array($job)) : ?>
						<p><span class="y4ui-chip is-amber"><?php echo esc_html($job['do'] === 'undo' ? __('Putting the old pages back…', 'y4apk') : __('Turning them into the shared page…', 'y4apk')); ?></span>
						<span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%1$s of %2$s folders looked at', 'y4apk'), number_format_i18n((int) $job['pos']), number_format_i18n($n['all']))); ?></span></p>
					<?php else : ?>
						<?php if (is_array($last)) : ?><p class="y4ui-hint"><?php echo esc_html(sprintf(__('Last time: %1$s changed, %2$s left as they were (a page whose file could not be read), %3$s ago.', 'y4apk'), number_format_i18n((int) $last['done']), number_format_i18n((int) $last['kept']), human_time_diff((int) ($last['end'] ?? time())))); ?></p><?php endif; ?>
						<div style="display:flex;gap:10px;flex-wrap:wrap">
							<?php if ($n['old']) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Use the shared page on every download page? Each keeps its file; the old pages are kept (default.php.orig) so Undo can put them back.', 'y4apk')); ?>')"><?php $form('convert'); ?><button class="button button-primary"><?php esc_html_e('Use it on every download page', 'y4apk'); ?></button></form><?php endif; ?>
							<?php if ($n['shared']) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Put the old pages back (each with its file of now)?', 'y4apk')); ?>')"><?php $form('undo'); ?><button class="button"><?php esc_html_e('Undo: the old pages back', 'y4apk'); ?></button></form><?php endif; ?>
						</div>
					<?php endif; ?>
					<p class="y4ui-hint" style="margin-top:10px"><?php esc_html_e('New folders (Write with AI, App updates, members’ uploads) use the shared page once it is there.', 'y4apk'); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/** files/up/_y4apk/page.php: the page itself (plain PHP: no WordPress is loaded for a download). */
	const RENDER = <<<'PHP'
<?php
/*
 * Y4APK download page (2.75): every download folder's default.php sets $apk (its file) and comes here.
 * Written by the theme (WordPress ▸ Y4APK ▸ Download page); its settings are in page.json beside it.
 * Changes made here by hand are replaced when that page is saved after a theme update.
 */
if (!isset($apk)) { header('Location: /', true, 302); exit; }
$y4c = json_decode((string) @file_get_contents(__DIR__ . '/page.json'), true);
$y4c = is_array($y4c) ? $y4c : [];
$y4file = trim((string) $apk);
$y4miss = !empty($y4c['missing']) ? (string) $y4c['missing'] : '/';

/* Only from the site (2.75.1): opened from one of its addresses, or with the mark a download page of the site leaves; else to the home page */
if (!empty($y4c['from_site'])) {
	$y4ref = strtolower((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
	if (!($y4ref !== '' && in_array($y4ref, (array) ($y4c['hosts'] ?? []), true)) && empty($_COOKIE['y4dl'])) {
		$y4away = !empty($y4c['away']) ? (string) $y4c['away'] : '/';
		if (!empty($y4c['away_add'])) { // the site finds this folder's post (2.75.2)
			$y4folder = '';
			foreach (get_included_files() as $y4inc) {
				if (basename($y4inc) === 'default.php') { $y4folder = basename(dirname($y4inc)); break; }
			}
			$y4away .= rawurlencode($y4folder);
		}
		header('Cache-Control: no-store');
		header('Location: ' . $y4away, true, 302);
		exit;
	}
}

if (!function_exists('y4dl_there')) {
	/* Is the file there? Only asked of the site's own R2 address, the answer kept a while; not sure: yes. */
	function y4dl_there($url, $c) {
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));
		if (empty($c['check']) || $host === '' || $host !== (string) ($c['r2_host'] ?? '')) { return true; }
		$f = __DIR__ . '/state/' . md5($url);
		$s = @file_get_contents($f);
		if ($s !== false && preg_match('/^([012])\|(\d+)$/', $s, $m) && time() - (int) $m[2] < [1800, 43200, 600][(int) $m[1]]) { return $m[1] !== '0'; }
		if (!function_exists('curl_init')) { return true; }
		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Y4APK-Download-Check']);
		curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code >= 200 && $code < 300) { $ok = 1; }
		elseif ($code === 404 || $code === 410 || ($code >= 300 && $code < 400)) { $ok = 0; } // a redirect: not a real file of the bucket
		else { $ok = 2; } // no clear answer (a hiccup, a challenge): let the visitor try, and ask again in 10 minutes
		@file_put_contents($f, $ok . '|' . time(), LOCK_EX);
		return $ok !== 0;
	}
}

if ($y4file === '' || $y4file === '#' || !preg_match('~^https?://~i', $y4file) || !y4dl_there($y4file, $y4c)) {
	header('Cache-Control: no-store');
	header('Location: ' . $y4miss, true, 302);
	exit;
}
header('Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
$y4delay = max(0, min(30, (int) ($y4c['delay'] ?? 3)));
$y4ads = (string) ($y4c['ads'] ?? '');
$y4ads = preg_match('~^https?://~i', $y4ads) && $y4delay > 0 ? $y4ads : '';
$y4logo = (string) ($y4c['logo'] ?? '');
$y4name = (string) ($y4c['name'] ?? 'Y4APK');
$y4icon = (string) ($y4c['icon'] ?? '');
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name=viewport content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Preparing the file</title>
<?php if ($y4icon !== '') : ?><link rel="icon" href="<?php echo $e($y4icon); ?>"><link rel="apple-touch-icon" href="<?php echo $e($y4icon); ?>"><?php endif; ?>
<style>
body{margin:0;height:100vh;display:flex;justify-content:center;align-items:center;background:#fbfdfd;font-family:Arial,sans-serif;overflow:hidden;user-select:none}
#bg{position:fixed;inset:0;z-index:-1}
.c{text-align:center;margin:0 16px;padding:24px 20px}
.t,.d{text-shadow:0 0 3px #fbfdfd,0 0 6px #fbfdfd,0 0 10px #fbfdfd}
.c img{display:block;margin:0 auto;width:auto;height:auto;max-width:min(50vw,300px);max-height:28vh}
.n{font-size:34px;font-weight:800;color:#ff2a2a;letter-spacing:.02em}
.s{border:6px solid #eee;border-top:6px solid #ff2a2a;border-radius:50%;width:50px;height:50px;margin:20px auto;animation:r 1s linear infinite}
.t{color:#666;font-size:14px;margin-top:10px}
.d{display:inline-block;margin-top:10px;color:#ff2a2a;text-decoration:none}
@keyframes r{to{transform:rotate(360deg)}}
@media(max-width:600px){.c img{max-width:min(70vw,300px)}.s{width:60px;height:60px}}
@media(prefers-reduced-motion:reduce){.s{animation:none}}
</style>
</head>
<body>
<canvas id=bg></canvas>
<div class=c>
<?php if ($y4logo !== '') : ?><img src="<?php echo $e($y4logo); ?>" alt="<?php echo $e($y4name); ?>"><?php else : ?><div class=n><?php echo $e($y4name); ?></div><?php endif; ?>
<div class=s></div>
<p class=t>Preparing your download...</p>
<?php if ($y4ads !== '') : ?><p class=t>Redirecting in <b id=cnt><?php echo (int) $y4delay; ?></b>s</p><?php endif; ?>
<a class=d href="<?php echo $e($y4file); ?>" rel="nofollow">Manual download</a>
</div>
<script>
<?php if (!empty($y4c['guard'])) : ?>
document.oncontextmenu=()=>false;
document.onselectstart=()=>false;
document.onkeydown=e=>{if(e.key=="F12"||(e.ctrlKey&&["u","c","x","s"].includes(String(e.key).toLowerCase()))||(e.ctrlKey&&e.shiftKey))e.preventDefault()};
<?php endif; ?>
if(top!==self)top.location=self.location;
(function(){var c=document.getElementById("bg"),x=c.getContext&&c.getContext("2d"),p=[];if(!x)return;
var rs=function(){c.width=innerWidth;c.height=innerHeight};onresize=rs;rs();
for(var i=0;i<50;i++)p.push({x:Math.random()*c.width,y:Math.random()*c.height,vx:(Math.random()-.5)*.6,vy:(Math.random()-.5)*.6});
(function d(){x.clearRect(0,0,c.width,c.height);p.forEach(function(a){a.x+=a.vx;a.y+=a.vy;(a.x<0||a.x>c.width)&&(a.vx*=-1);(a.y<0||a.y>c.height)&&(a.vy*=-1);
x.beginPath();x.arc(a.x,a.y,2,0,7);x.fillStyle="#ff2a2a";x.fill();p.forEach(function(b){var dx=a.x-b.x,dy=a.y-b.y;if(dx*dx+dy*dy<14400){x.beginPath();x.moveTo(a.x,a.y);x.lineTo(b.x,b.y);x.strokeStyle="rgba(255,40,40,.35)";x.stroke()}})});
if(!document.hidden)requestAnimationFrame(d);else setTimeout(d,500)})()})();
var f=<?php echo json_encode($y4file, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,ads=<?php echo json_encode($y4ads, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,t=<?php echo (int) $y4delay; ?>;
setTimeout(function(){location.href=f},0);
if(ads){var k=document.getElementById("cnt");setInterval(function(){if(t>0&&k)k.textContent=--t},1000);setTimeout(function(){location.href=ads},t*1000)}
</script>
</body>
</html>
PHP;
}

Y4APK_DLPage::init();
