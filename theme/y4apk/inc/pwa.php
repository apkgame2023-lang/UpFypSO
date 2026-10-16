<?php
/**
 * Y4APK app: makes the site installable (a Progressive Web App) and supplies
 * what the Android APK built from it needs.
 *
 * Front end
 *   /?y4apk_pwa=manifest        web app manifest
 *   /?y4apk_pwa=sw              service worker (served from the site root so its
 *                               scope is the whole site; see assets/js/pwa-sw.js)
 *   /?y4apk_pwa=offline         page shown when there is no connection
 *   /.well-known/assetlinks.json  Digital Asset Links for the Android APK, so it
 *                               opens full screen instead of with a URL bar
 *   <head> tags + a small boot script, and the install banner
 *   (assets/js/y4apk-pwa-install.js).
 *
 * Admin
 *   Y4APK ▸ App — settings, and the steps to turn the site into an APK.
 *
 * Cost on a normal page view: one autoloaded option and a few tags. The
 * endpoints answer early on `init` and exit; the manifest is the only one that
 * touches the database (two category lookups for its shortcuts), and browsers
 * fetch it rarely.
 *
 * Switching the app off keeps answering /?y4apk_pwa=sw — with a worker that
 * deletes its caches and unregisters itself — so browsers that already
 * installed the old one are cleaned up rather than left holding it.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Y4APK_PWA')) {

class Y4APK_PWA {

	const OPTION = 'y4apk_pwa';
	const QUERY  = 'y4apk_pwa';
	const SLUG   = 'y4apk-app';

	private static $settings = null;

	public static function init() {
		add_action('init', [__CLASS__, 'maybe_serve'], 1);

		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'ensure_option']);
			add_action('admin_init', [__CLASS__, 'maybe_generate_icons']);
			add_action('update_option_site_icon', [__CLASS__, 'generate_icons'], 10, 0);
			add_action('admin_menu', [__CLASS__, 'register_page'], 101);
			add_action('admin_post_y4apk_save_pwa', [__CLASS__, 'handle_save']);
			add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
			return;
		}

		if (!self::get('enabled')) {
			return;
		}

		add_action('wp_head', [__CLASS__, 'head_tags'], 2);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
		add_filter('site_icon_meta_tags', [__CLASS__, 'site_icon_tags']);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'enabled'          => 1,
			'name'             => '',
			'short_name'       => 'Y4APK',
			'description'      => '',
			'theme_color'      => '#29b1d8',
			'background_color' => '#fbfdfd',
			'icon_source'      => 'site_icon',
			'icon_background'  => '#ffffff',
			'icon_custom'      => 0,
			'orientation'      => 'any',
			'screenshots'      => [],
			'generated'        => [],
			'banner'           => 1,
			'banner_again'     => 5,
			'banner_open'      => 1,
			'banner_title'     => '',
			'banner_text'      => '',
			'owner_title'      => '',
			'owner_text'       => '',
			'dl_help'          => 1,
			'apk_url'          => '',
			'assetlinks'       => '',
			'rev'              => 1,
		];
	}

	public static function settings() {
		if (self::$settings === null) {
			$saved = get_option(self::OPTION, []);
			self::$settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);
		}
		return self::$settings;
	}

	public static function get($key) {
		$s = self::settings();
		return isset($s[$key]) ? $s[$key] : null;
	}

	/**
	 * Store the defaults once, autoloaded, so the front end reads them from the
	 * options already in memory rather than querying for a missing option on
	 * every request.
	 */
	public static function ensure_option() {
		if (get_option(self::OPTION, null) === null) {
			add_option(self::OPTION, self::defaults(), '', 'yes');
		}
	}

	public static function app_name() {
		$name = trim((string) self::get('name'));
		return $name !== '' ? $name : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	}

	public static function short_name() {
		$short = trim((string) self::get('short_name'));
		return $short !== '' ? $short : self::app_name();
	}

	public static function description() {
		$desc = trim((string) self::get('description'));
		return $desc !== '' ? $desc : wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES);
	}

	/** Path the app lives under — "/" unless WordPress sits in a subdirectory. */
	public static function scope() {
		$path = wp_parse_url(home_url('/'), PHP_URL_PATH);
		return $path ? trailingslashit($path) : '/';
	}

	public static function endpoint($what) {
		return add_query_arg(self::QUERY, $what, home_url('/'));
	}

	/** Changes whenever the theme or the app settings change, so caches roll over. */
	public static function version() {
		return Y4APK_THEME_VERSION . '-' . (int) self::get('rev');
	}

	private static function asset($path) {
		return get_template_directory_uri() . '/assets/' . ltrim($path, '/');
	}

	/* ---------------------------------------------------------------- *
	 * Icons
	 *
	 * Three sources, chosen under Y4APK ▸ App:
	 *   site_icon  the Site Icon (Appearance ▸ Customize ▸ Site Identity)
	 *   custom     any image uploaded or picked from the Media Library
	 *   bundled    the Y4 icon shipped in assets/img/pwa/
	 * For the first two, every size the app needs is generated from the one
	 * image (see generate_icons()). If that is not possible — no image, or the
	 * server cannot read it — the app falls back to the bundled icon rather than
	 * shipping a broken one.
	 * ---------------------------------------------------------------- */

	/** Attachment the icons are made from, or 0 for the bundled icon. */
	public static function source_id() {
		$mode = self::get('icon_source');
		if ($mode === 'custom') {
			$id = (int) self::get('icon_custom');
			return ($id && wp_attachment_is_image($id)) ? $id : 0;
		}
		if ($mode === 'bundled') {
			return 0;
		}
		return (int) get_option('site_icon');
	}

	public static function icons() {
		$gen = self::generated();
		if ($gen) {
			$icons = [];
			foreach (['any', 'maskable'] as $purpose) {
				foreach ([192, 512] as $size) {
					$icons[] = ['src' => $gen["$purpose-$size"], 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => $purpose];
				}
			}
			return $icons;
		}
		return [
			['src' => self::asset('img/pwa/icon-192.png'),          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
			['src' => self::asset('img/pwa/icon-512.png'),          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
			['src' => self::asset('img/pwa/icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
			['src' => self::asset('img/pwa/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
		];
	}

	/** Generated icons, if they were made from the current source and colour. */
	private static function generated() {
		$gen = self::get('generated');
		$id = self::source_id();
		if (!$id || !is_array($gen) || empty($gen['any-192']) || empty($gen['maskable-512'])) {
			return [];
		}
		if ((int) $gen['source'] !== $id || $gen['bg'] !== self::get('icon_background')) {
			return [];
		}
		return $gen;
	}

	public static function apple_touch_icon() {
		$gen = self::generated();
		return $gen ? $gen['apple-180'] : self::asset('img/pwa/apple-touch-icon.png');
	}

	/* ---------------------------------------------------------------- *
	 * Icon generation (admin only)
	 * ---------------------------------------------------------------- */

	/**
	 * Make the five icons the app needs from one image:
	 *   any-192, any-512  the image as it is, transparent background
	 *   maskable-192/512  Android crops these to the launcher's shape — circle,
	 *                     squircle, teardrop — and only promises to keep the centre
	 *                     circle, 80% of the width. Art reaching the corners (the
	 *                     Y4APK fox's ears do) would be cut off, so the visible
	 *                     pixels are scaled to fit that circle, on a solid colour.
	 *   apple-180         iPhone shows transparency as black, so a solid colour.
	 *
	 * Runs on admin requests only — when the source image or the background
	 * colour changes — never while a visitor waits.
	 */
	public static function maybe_generate_icons() {
		$id = self::source_id();
		if ($id && !self::generated()) {
			self::generate_icons();
		}
	}

	/** @return true|string true, or a short reason it could not be done */
	public static function generate_icons() {
		$id = self::source_id();
		$file = $id ? get_attached_file($id) : '';

		if (!$id || !$file || !is_readable($file)) {
			return 'missing';
		}
		if (!function_exists('imagecreatefromstring')) {
			return 'no-gd';
		}

		$src = @imagecreatefromstring((string) file_get_contents($file));
		if (!$src) {
			return 'unreadable';
		}
		if (!imageistruecolor($src)) {
			imagepalettetotruecolor($src);
		}
		imagealphablending($src, true);
		imagesavealpha($src, true);

		list($bx, $by, $bw, $bh) = self::opaque_box($src);
		$bg = sanitize_hex_color(self::get('icon_background')) ?: '#ffffff';

		$uploads = wp_upload_dir();
		$dir = trailingslashit($uploads['basedir']) . 'y4apk-app';
		$url = trailingslashit($uploads['baseurl']) . 'y4apk-app';
		if (!wp_mkdir_p($dir)) {
			imagedestroy($src);
			return 'not-writable';
		}

		$stamp = substr(md5($id . '|' . $bg . '|' . filemtime($file)), 0, 8);
		$out = ['source' => $id, 'bg' => $bg, 'width' => imagesx($src), 'height' => imagesy($src)];

		// name => [size, solid background?, diagonal share of the canvas the art may span]
		$targets = [
			'any-192'      => [192, false, 0],
			'any-512'      => [512, false, 0],
			'maskable-192' => [192, true, 0.80],
			'maskable-512' => [512, true, 0.80],
			'apple-180'    => [180, true, 1.02],
		];

		foreach ($targets as $name => $t) {
			list($size, $solid, $span) = $t;

			$canvas = imagecreatetruecolor($size, $size);
			if ($solid) {
				list($r, $g, $b) = sscanf($bg, '#%02x%02x%02x');
				imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
				imagealphablending($canvas, true);
				// fit the diagonal inside the safe circle, and never wider than 92%
				$scale = min(($size * $span) / sqrt($bw * $bw + $bh * $bh), ($size * 0.92) / max($bw, $bh));
			} else {
				imagealphablending($canvas, false);
				imagesavealpha($canvas, true);
				imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
				$scale = ($size * 0.96) / max($bw, $bh);
			}

			$dw = max(1, (int) round($bw * $scale));
			$dh = max(1, (int) round($bh * $scale));
			imagecopyresampled($canvas, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2),
				$bx, $by, $dw, $dh, $bw, $bh);

			imagepng($canvas, "$dir/$name.png", 9);
			imagedestroy($canvas);

			$out[$name] = "$url/$name.png?v=$stamp";
		}

		imagedestroy($src);

		$all = self::settings();
		$all['generated'] = $out;
		$all['rev'] = (int) $all['rev'] + 1;
		update_option(self::OPTION, $all, true);
		self::$settings = null;

		return true;
	}

	/** Bounding box of the pixels that are not (almost) fully transparent. */
	private static function opaque_box($img) {
		$w = imagesx($img);
		$h = imagesy($img);
		$minx = $w; $miny = $h; $maxx = -1; $maxy = -1;
		$step = max(1, (int) floor(max($w, $h) / 256));

		for ($y = 0; $y < $h; $y += $step) {
			for ($x = 0; $x < $w; $x += $step) {
				$alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F; // 127 = transparent
				if ($alpha < 110) {
					if ($x < $minx) $minx = $x;
					if ($x > $maxx) $maxx = $x;
					if ($y < $miny) $miny = $y;
					if ($y > $maxy) $maxy = $y;
				}
			}
		}

		if ($maxx < 0) {
			return [0, 0, $w, $h];
		}
		$pad = $step + 1;
		$minx = max(0, $minx - $pad); $miny = max(0, $miny - $pad);
		$maxx = min($w - 1, $maxx + $pad); $maxy = min($h - 1, $maxy + $pad);
		return [$minx, $miny, $maxx - $minx + 1, $maxy - $miny + 1];
	}

	public static function small_icon() {
		$icons = self::icons();
		return $icons[0]['src'];
	}

	public static function logo() {
		return get_theme_mod('logo_img', get_template_directory_uri() . '/assets/img/y4apk.svg');
	}

	/** Android package name from the pasted assetlinks.json, or ''. */
	public static function apk_package() {
		$links = json_decode((string) self::get('assetlinks'), true);
		$package = isset($links[0]['target']['package_name']) ? (string) $links[0]['target']['package_name'] : '';
		return preg_match('/^[a-zA-Z]\w*(\.[a-zA-Z]\w*)+$/', $package) ? $package : '';
	}

	/**
	 * Screenshots for the install dialog. Chrome shows its larger, app-store
	 * style install sheet when there are some: "narrow" (portrait) ones on
	 * phones, "wide" ones on desktop.
	 */
	public static function screenshots() {
		$out = [];
		foreach ((array) self::get('screenshots') as $id) {
			$img = wp_get_attachment_image_src((int) $id, 'full');
			if (!$img || empty($img[1]) || empty($img[2])) {
				continue;
			}
			$mime = get_post_mime_type((int) $id);
			$alt = trim((string) get_post_meta((int) $id, '_wp_attachment_image_alt', true));
			$out[] = [
				'src'         => $img[0],
				'sizes'       => $img[1] . 'x' . $img[2],
				'type'        => $mime ?: 'image/png',
				'form_factor' => $img[1] > $img[2] ? 'wide' : 'narrow',
				'label'       => $alt !== '' ? $alt : get_the_title((int) $id),
			];
		}
		return $out;
	}

	/**
	 * Problems Chrome would have with the chosen screenshots: it ignores the
	 * whole set when one is outside 320–3840 px, longer than 2.3× its width,
	 * or shaped differently from the others of its kind.
	 *
	 * @return string[]
	 */
	public static function screenshot_problems() {
		$problems = [];
		$ratios = [];
		foreach (self::screenshots() as $shot) {
			list($w, $h) = array_map('intval', explode('x', $shot['sizes']));
			if (min($w, $h) < 320 || max($w, $h) > 3840) {
				$problems[] = sprintf(__('%1$s is %2$s px; each side must be between 320 and 3840.', 'y4apk'), $shot['label'], $shot['sizes']);
			}
			if (max($w, $h) / max(1, min($w, $h)) > 2.3) {
				$problems[] = sprintf(__('%s is too long and thin (at most 2.3 times as long as it is wide).', 'y4apk'), $shot['label']);
			}
			$ratios[$shot['form_factor']][] = round($w / $h, 2);
		}
		foreach ($ratios as $kind => $list) {
			if (count(array_unique($list)) > 1) {
				$problems[] = $kind === 'narrow'
					? __('The portrait screenshots have different shapes. Take them all on the same phone.', 'y4apk')
					: __('The landscape screenshots have different shapes. Use the same size for all of them.', 'y4apk');
			}
		}
		return $problems;
	}

	/* ---------------------------------------------------------------- *
	 * Endpoints
	 * ---------------------------------------------------------------- */

	public static function maybe_serve() {
		$path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);

		if ($path === self::scope() . '.well-known/assetlinks.json') {
			self::serve_assetlinks();
			return;
		}

		if (empty($_GET[self::QUERY])) {
			return;
		}

		$what = sanitize_key(wp_unslash($_GET[self::QUERY]));

		if ($what === 'sw') {
			self::serve_sw();
		} elseif (self::get('enabled') && $what === 'manifest') {
			self::serve_manifest();
		} elseif (self::get('enabled') && $what === 'offline') {
			self::serve_offline();
		}
	}

	private static function no_cache($type) {
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		nocache_headers();
		header('Content-Type: ' . $type);
		header('CDN-Cache-Control: no-store');
		header('X-Robots-Tag: noindex');
	}

	public static function manifest() {
		$scope = self::scope();

		$manifest = [
			'id'               => $scope,
			'name'             => self::app_name(),
			'short_name'       => self::short_name(),
			'description'      => self::description(),
			'lang'             => get_bloginfo('language'),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'start_url'        => $scope,
			'scope'            => $scope,
			'display'          => 'standalone',
			'orientation'      => self::get('orientation') === 'portrait' ? 'portrait' : 'any',
			'background_color' => self::get('background_color'),
			'theme_color'      => self::get('theme_color'),
			'categories'       => ['games', 'entertainment', 'utilities'],
			'icons'            => self::icons(),
			'prefer_related_applications' => false,
		];

		// Long-press shortcuts: the two categories the header menu already uses.
		$shortcuts = [];
		foreach (['menu_select_1', 'menu_select_2'] as $mod) {
			$cat_id = (int) get_theme_mod($mod);
			if (!$cat_id) {
				continue;
			}
			$name = get_cat_name($cat_id);
			$link = get_category_link($cat_id);
			if ($name && $link && !is_wp_error($link)) {
				$shortcuts[] = [
					'name'  => $name,
					'url'   => wp_make_link_relative($link),
					'icons' => [['src' => self::small_icon(), 'sizes' => '192x192', 'type' => 'image/png']],
				];
			}
		}
		if ($shortcuts) {
			$manifest['shortcuts'] = $shortcuts;
		}

		$screenshots = self::screenshots();
		if ($screenshots) {
			$manifest['screenshots'] = $screenshots;
		}

		// Lets the banner ask Chrome (getInstalledRelatedApps) whether this
		// visitor already has the app: the web app itself, found by this
		// manifest's address, and the APK built from it once its assetlinks.json
		// is in.
		$related = [['platform' => 'webapp', 'url' => self::endpoint('manifest')]];
		$package = self::apk_package();
		if ($package) {
			array_unshift($related, ['platform' => 'play', 'id' => $package]);
		}
		$manifest['related_applications'] = $related;

		return apply_filters('y4apk_pwa_manifest', $manifest);
	}

	private static function serve_manifest() {
		self::no_cache('application/manifest+json; charset=utf-8');
		echo wp_json_encode(self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		exit;
	}

	private static function serve_sw() {
		self::no_cache('application/javascript; charset=utf-8');
		header('Service-Worker-Allowed: ' . self::scope());

		if (!self::get('enabled')) {
			echo self::kill_switch_sw();
			exit;
		}

		$offline = self::endpoint('offline');
		$precache = array_values(array_unique(array_filter([
			$offline,
			self::logo(),
			self::small_icon(),
		], [__CLASS__, 'same_origin'])));

		$js = file_get_contents(get_template_directory() . '/assets/js/pwa-sw.js');
		echo strtr($js, [
			'__VERSION__'     => self::version(),
			'__SCOPE__'       => self::scope(),
			'__OFFLINE_URL__' => esc_url_raw($offline),
			'__PRECACHE__'    => wp_json_encode($precache, JSON_UNESCAPED_SLASHES),
		]);
		exit;
	}

	private static function kill_switch_sw() {
		return "/* Y4APK app is switched off: clear caches and step aside. */\n"
			. "self.addEventListener('install', function () { self.skipWaiting(); });\n"
			. "self.addEventListener('activate', function (event) {\n"
			. "\tevent.waitUntil(caches.keys().then(function (keys) {\n"
			. "\t\treturn Promise.all(keys.filter(function (k) { return k.indexOf('y4apk-') === 0; })\n"
			. "\t\t\t.map(function (k) { return caches.delete(k); }));\n"
			. "\t}).then(function () { return self.registration.unregister(); }));\n"
			. "});\n";
	}

	public static function same_origin($url) {
		$host = wp_parse_url(home_url('/'), PHP_URL_HOST);
		return $url && wp_parse_url($url, PHP_URL_HOST) === $host;
	}

	private static function serve_offline() {
		self::no_cache('text/html; charset=utf-8');
		$theme = esc_attr(self::get('theme_color'));
		$bg = esc_attr(self::get('background_color'));
		$prefix = 'y4apk-pages-';
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?php echo $theme; ?>">
<title><?php echo esc_html(self::app_name()); ?></title>
<style>
	body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
		background: <?php echo $bg; ?>; color: #172021; text-align: center;
		font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	main { max-width: 26rem; padding: 2rem 1.5rem; }
	img { width: 9rem; height: 3rem; object-fit: contain; }
	h1 { font-size: 1.5rem; margin: 1.5rem 0 .5rem; }
	p { margin: 0 0 1.5rem; color: #51605f; }
	button { border: 0; border-radius: 2rem; padding: .75rem 2rem; font: inherit; font-weight: 700;
		color: #fff; background: <?php echo $theme; ?>; cursor: pointer; }
	#recent { margin-top: 2rem; text-align: left; }
	#recent h2 { font-size: .875rem; text-transform: uppercase; letter-spacing: .05em; color: #51605f; }
	#recent a { display: block; padding: .5rem 0; color: <?php echo $theme; ?>; text-decoration: none;
		border-bottom: 1px solid #e3eced; }
</style>
</head>
<body>
<main>
	<img src="<?php echo esc_url(self::logo()); ?>" alt="<?php echo esc_attr(self::app_name()); ?>">
	<h1><?php esc_html_e('You are offline', 'y4apk'); ?></h1>
	<p><?php esc_html_e('Check your connection and try again. Pages you opened recently are still available below.', 'y4apk'); ?></p>
	<button type="button" onclick="location.reload()"><?php esc_html_e('Try again', 'y4apk'); ?></button>
	<div id="recent" hidden>
		<h2><?php esc_html_e('Available offline', 'y4apk'); ?></h2>
		<nav></nav>
	</div>
</main>
<script>
(function () {
	if (!window.caches) return;
	caches.keys().then(function (keys) {
		var pages = keys.filter(function (k) { return k.indexOf(<?php echo wp_json_encode($prefix); ?>) === 0; });
		return Promise.all(pages.map(function (k) { return caches.open(k).then(function (c) { return c.keys(); }); }));
	}).then(function (lists) {
		var seen = {}, nav = document.querySelector('#recent nav');
		[].concat.apply([], lists || []).reverse().slice(0, 12).forEach(function (req) {
			var url = new URL(req.url), path = url.pathname;
			if (seen[path]) return;
			seen[path] = 1;
			var label = decodeURIComponent(path).replace(/^\/|\/$/g, '').split('/').pop().replace(/[-_]+/g, ' ');
			var a = document.createElement('a');
			a.href = req.url;
			a.textContent = label || <?php echo wp_json_encode(__('Home', 'y4apk')); ?>;
			nav.appendChild(a);
		});
		if (nav.children.length) document.getElementById('recent').hidden = false;
	}).catch(function () {});
})();
</script>
</body>
</html><?php
		exit;
	}

	private static function serve_assetlinks() {
		$raw = trim((string) self::get('assetlinks'));
		if ($raw === '') {
			return; // not configured: let WordPress answer with its normal 404
		}
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		status_header(200);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=3600');
		header('Access-Control-Allow-Origin: *');
		echo $raw;
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Front end
	 * ---------------------------------------------------------------- */

	/** Settings for the install banner, or null where it should not appear. */
	private static function banner_config() {
		if (!self::get('banner')) {
			return null;
		}
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
		if (strpos($uri, '/download/') !== false) {
			return null; // the countdown pages stay as they are
		}

		$title = trim((string) self::get('banner_title'));
		$text  = trim((string) self::get('banner_text'));
		$owner_title = trim((string) self::get('owner_title'));
		$owner_text  = trim((string) self::get('owner_text'));

		return [
			'icon'         => self::small_icon(),
			'title'        => $title !== '' ? $title : sprintf(__('Get the %s app', 'y4apk'), self::short_name()),
			'text'         => $text !== '' ? $text : __('Faster, full screen, one tap from your home screen.', 'y4apk'),
			'iosText'      => __('Tap Share, then "Add to Home Screen".', 'y4apk'),
			'installLabel' => __('Install', 'y4apk'),
			'apkLabel'     => __('Download', 'y4apk'),
			'closeLabel'   => __('Close', 'y4apk'),
			'apkUrl'       => esc_url_raw((string) self::get('apk_url')),
			'package'      => self::apk_package(),
			'owners'       => (bool) self::get('banner_open'),
			'ownerTitle'   => $owner_title !== '' ? $owner_title : sprintf(__('You have the %s app', 'y4apk'), self::short_name()),
			'openText'     => $owner_text !== '' ? $owner_text : __('Open this page in the app, or keep browsing here.', 'y4apk'),
			'homeText'     => $owner_text !== '' ? $owner_text : __('Open it from your home screen, or keep browsing here.', 'y4apk'),
			'openLabel'    => __('Open', 'y4apk'),
			'again'        => max(0, (int) self::get('banner_again')),
			'delay'        => 4000,
		];
	}

	/**
	 * The boot script must run as soon as the page parses: it registers the
	 * service worker and catches the browser's one-off install offer. Speed
	 * plugins that "delay JavaScript until user interaction" would hold it back
	 * until a tap — and the robots that check installability (PWABuilder,
	 * Lighthouse) never tap. Hence every common don't-delay marker, and the
	 * keyword y4apk-pwa in its id and its body, for plugins that exclude by
	 * keyword (10Web Booster does).
	 */
	public static function head_tags() {
		$boot = [
			'sw'     => self::endpoint('sw'),
			'scope'  => self::scope(),
			'pkg'    => self::apk_package(),
			'banner' => self::banner_config(),
		];
		?>
<link rel="manifest" href="<?php echo esc_url(self::endpoint('manifest')); ?>">
<meta name="theme-color" content="<?php echo esc_attr(self::get('theme_color')); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr(self::short_name()); ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="<?php echo esc_url(self::apple_touch_icon()); ?>">
<script id="y4apk-pwa-boot" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
/* y4apk-pwa boot — exclude from JavaScript delay */
(function () {
	var c = <?php echo wp_json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
	window.Y4APK_PWA = c.banner;
	// Inside the app? The APK opens its first page with an android-app://<package>
	// referrer, remembered for the rest of the visit.
	var inApp = navigator.standalone === true || !!(window.matchMedia && matchMedia('(display-mode: standalone)').matches);
	try {
		if (c.pkg && document.referrer.indexOf('android-app://' + c.pkg) === 0) sessionStorage.setItem('y4apk_in_app', '1');
		inApp = inApp || sessionStorage.getItem('y4apk_in_app') === '1';
	} catch (e) {}
	window.__y4apkInApp = inApp;
	if (inApp && /Android/i.test(navigator.userAgent)) {
		// Downloads in the app get no browser download bar: explain where they went.
		document.documentElement.classList.add('y4apk-app-android');
		document.addEventListener('click', function (e) {
			var link = e.target.closest && e.target.closest('[data-y4apk-download]');
			var help = document.getElementById('y4apk-dl-help');
			if (link && help) setTimeout(function () { help.hidden = false; }, 800);
		}, true);
	}
	if (c.banner) {
		window.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			window.__y4apkInstallPrompt = e;
			document.dispatchEvent(new Event('y4apk:installable'));
		});
	}
	if (!('serviceWorker' in navigator)) return;
	function register() { navigator.serviceWorker.register(c.sw, { scope: c.scope }).catch(function () {}); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', register); else register();
})();
</script>
		<?php
	}

	/**
	 * Under the Download button: inside the installed app there is no browser
	 * download bar and no Downloads list, so progress only shows in the phone's
	 * notifications. A line saying so, and a short guide after the tap, both
	 * shown only in the app on Android (the boot script marks <html>).
	 */
	public static function download_help() {
		if (!self::get('enabled') || !self::get('dl_help')) {
			return;
		}
		$theme = sanitize_hex_color(self::get('theme_color')) ?: '#29b1d8';
		?>
<p class="y4apk-dl-note"><?php esc_html_e('In the app, downloads show in your notifications: swipe down from the top of the screen to follow them.', 'y4apk'); ?></p>
<div id="y4apk-dl-help" class="y4apk-dl-help" role="dialog" aria-modal="true" aria-labelledby="y4apk-dl-help-title" hidden onclick="if (event.target === this) this.hidden = true">
	<div class="y4apk-dl-help__box">
		<strong id="y4apk-dl-help-title" class="y4apk-dl-help__title"><?php esc_html_e('Your download is starting', 'y4apk'); ?></strong>
		<p><?php esc_html_e('Swipe down from the top of the screen to see its progress. When it finishes, tap it there to install.', 'y4apk'); ?></p>
		<p class="y4apk-dl-help__small"><?php esc_html_e('Nothing there? Allow notifications for Chrome in your phone’s settings, or on the download page tap ⋮ then “Open in Chrome” to download in the browser instead.', 'y4apk'); ?></p>
		<button type="button" class="y4apk-dl-help__ok" onclick="this.closest('.y4apk-dl-help').hidden = true"><?php esc_html_e('OK', 'y4apk'); ?></button>
	</div>
</div>
<style>
.y4apk-dl-note{display:none;max-width:22rem;margin:.75rem auto 0;font-size:.8125rem;line-height:1.4;color:#51605f;text-align:center}
.y4apk-app-android .y4apk-dl-note{display:block}
.y4apk-dl-help{position:fixed;inset:0;z-index:1000000;display:flex;align-items:flex-end;justify-content:center;padding:.75rem;background:rgba(23,32,33,.45)}
.y4apk-dl-help[hidden]{display:none}
.y4apk-dl-help__box{width:100%;max-width:30rem;padding:1.25rem;border-radius:1rem;background:#fff;color:#172021;box-shadow:0 .5rem 2rem rgba(23,32,33,.25);line-height:1.45;text-align:left}
.y4apk-dl-help__title{display:block;margin-bottom:.4rem;font-size:1.0625rem}
.y4apk-dl-help__box p{margin:0 0 .6rem;font-size:.9375rem}
.y4apk-dl-help__box .y4apk-dl-help__small{font-size:.8125rem;color:#51605f}
.y4apk-dl-help__ok{display:block;width:100%;margin-top:.4rem;padding:.65rem;border:0;border-radius:2rem;font:inherit;font-weight:700;color:#fff;background:<?php echo esc_attr($theme); ?>;cursor:pointer}
</style>
		<?php
	}

	/** This prints its own apple-touch-icon; keep WordPress's from competing with it. */
	public static function site_icon_tags($tags) {
		return array_values(array_filter($tags, function ($tag) {
			return strpos($tag, 'apple-touch-icon') === false;
		}));
	}

	public static function enqueue() {
		if (!self::banner_config()) {
			return;
		}

		// "y4apk-pwa" in the file name, so keyword exclusions match it too.
		wp_enqueue_script('y4apk-pwa-install', self::asset('js/y4apk-pwa-install.js'), [], Y4APK_THEME_VERSION, true);

		// Its own handle: this hook can run before the theme registers "styles",
		// and inline CSS added to a handle that is not registered yet is dropped.
		wp_register_style('y4apk-pwa', false, [], Y4APK_THEME_VERSION);
		wp_enqueue_style('y4apk-pwa');
		wp_add_inline_style('y4apk-pwa', self::banner_css());
	}

	private static function banner_css() {
		$theme = sanitize_hex_color(self::get('theme_color')) ?: '#29b1d8';
		return '.y4apk-install{position:fixed;left:.75rem;right:.75rem;bottom:.75rem;z-index:99999;'
			. 'display:flex;align-items:center;gap:.75rem;max-width:30rem;margin:0 auto;padding:.75rem .75rem .75rem 1rem;'
			. 'background:#fff;color:#172021;border-radius:1rem;box-shadow:0 .5rem 2rem rgba(23,32,33,.18);'
			. 'transform:translateY(150%);transition:transform .25s ease}'
			. '.y4apk-install--in{transform:none}'
			. '.y4apk-install__icon{flex:none;width:3rem;height:3rem;border-radius:.75rem}'
			. '.y4apk-install__body{flex:1;min-width:0;line-height:1.3}'
			. '.y4apk-install__title{display:block;font-size:.9375rem}'
			. '.y4apk-install__text{display:block;font-size:.8125rem;color:#51605f}'
			. '.y4apk-install__cta{flex:none;border:0;border-radius:2rem;padding:.5rem 1rem;font:inherit;font-weight:700;'
			. 'font-size:.875rem;color:#fff!important;background:' . $theme . ';cursor:pointer;text-decoration:none}'
			. '.y4apk-install__close{flex:none;border:0;background:none;font-size:1.5rem;line-height:1;'
			. 'color:#8a9a9b;cursor:pointer;padding:.25rem}'
			. '@media (display-mode:standalone){.y4apk-install{display:none}}';
	}

	/* ---------------------------------------------------------------- *
	 * Admin: Y4APK ▸ App
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page('y4apk-gplay', __('App', 'y4apk'), __('App', 'y4apk'),
			'manage_options', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function admin_assets() {
		if (!isset($_GET['page']) || $_GET['page'] !== self::SLUG) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script('y4apk-pwa-admin', self::asset('js/y4apk-pwa-admin.js'), ['jquery', 'media-editor'], Y4APK_THEME_VERSION, true);
		wp_localize_script('y4apk-pwa-admin', 'Y4APK_PWA_ADMIN', [
			'iconTitle'        => __('Choose the app icon', 'y4apk'),
			'useImage'         => __('Use this image', 'y4apk'),
			'shotsTitle'       => __('Choose up to 8 screenshots', 'y4apk'),
			'useImages'        => __('Use these screenshots', 'y4apk'),

			'manifest'         => __('Manifest', 'y4apk'),
			'manifestOk'       => __('Readable, %d icons.', 'y4apk'),
			'manifestBad'      => __('Could not be read. Is the app switched on and saved?', 'y4apk'),
			'worker'           => __('Service worker file', 'y4apk'),
			'workerOk'         => __('Served correctly as JavaScript.', 'y4apk'),
			'workerHtml'       => __('Returns a web page instead of the service worker: a cache or CDN is ignoring the ?y4apk_pwa= part of the address. Exclude addresses containing y4apk_pwa from page caching.', 'y4apk'),
			'workerBad'        => __('Not served correctly (%s).', 'y4apk'),
			'page'             => __('Startup script on the page visitors get', 'y4apk'),
			'pageOk'           => __('Present and runs straight away.', 'y4apk'),
			'pageNoManifest'   => __('The page visitors get has no app tags yet. Clear the 10Web / page cache.', 'y4apk'),
			'pageNoBoot'       => __('Missing from the page visitors get: probably merged into a bundle by "JavaScript aggregation". Add y4apk-pwa to its excluded files, then clear the cache.', 'y4apk'),
			'pageDelayed'      => __('Held back by a speed plugin (its type was changed to "%s"). Add y4apk-pwa to the JavaScript delay exclusions, then clear the cache.', 'y4apk'),
			'pageUnknown'      => __('Could not load the home page to look.', 'y4apk'),
			'browser'          => __('Service worker starts in this browser', 'y4apk'),
			'browserWaiting'   => __('Starting…', 'y4apk'),
			'browserOk'        => __('Installed and running.', 'y4apk'),
			'browserSlow'      => __('Registered, but did not finish starting within 15 seconds. Run the check again.', 'y4apk'),
			'browserBad'       => __('The browser refused it: %s', 'y4apk'),
			'browserNoSupport' => __('This browser has no service worker support; try Chrome.', 'y4apk'),
			'summaryOk'        => __('All good', 'y4apk'),
			'summaryOkHint'    => __('Re-test on PWABuilder; it should now find the service worker.', 'y4apk'),
			'summaryBad'       => __('Something needs fixing', 'y4apk'),
			'summaryBadHint'   => __('Follow the ✗ above, clear the cache, and run the check again.', 'y4apk'),
		]);
	}

	public static function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-save-pwa');

		$in = wp_unslash($_POST);
		$old = self::settings();
		$error = '';

		$assetlinks = isset($in['assetlinks']) ? trim((string) $in['assetlinks']) : '';
		if ($assetlinks !== '') {
			$decoded = json_decode($assetlinks, true);
			if (!is_array($decoded) || !isset($decoded[0]['target'])) {
				$error = 'assetlinks';
				$assetlinks = $old['assetlinks'];
			} else {
				$assetlinks = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
			}
		}

		// Custom icon: a file uploaded right here wins over one picked from the Media Library.
		$source = isset($in['icon_source']) && in_array($in['icon_source'], ['site_icon', 'custom', 'bundled'], true)
			? $in['icon_source'] : 'site_icon';
		$custom = isset($in['icon_custom']) ? absint($in['icon_custom']) : (int) $old['icon_custom'];

		if (!empty($_FILES['icon_file']['name'])) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$uploaded = media_handle_upload('icon_file', 0, [], [
				'test_form' => false,
				'mimes'     => ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp'],
			]);
			if (is_wp_error($uploaded)) {
				$error = 'icon-upload';
			} else {
				$custom = (int) $uploaded;
				$source = 'custom';
			}
		}
		if ($source === 'custom' && (!$custom || !wp_attachment_is_image($custom))) {
			$error = $error ?: 'icon-missing';
			$source = $old['icon_source'] === 'custom' ? 'site_icon' : $old['icon_source'];
		}

		$new = [
			'enabled'          => empty($in['enabled']) ? 0 : 1,
			'name'             => sanitize_text_field(isset($in['name']) ? $in['name'] : ''),
			'short_name'       => sanitize_text_field(isset($in['short_name']) ? $in['short_name'] : ''),
			'description'      => sanitize_text_field(isset($in['description']) ? $in['description'] : ''),
			'theme_color'      => sanitize_hex_color(isset($in['theme_color']) ? $in['theme_color'] : '') ?: $old['theme_color'],
			'background_color' => sanitize_hex_color(isset($in['background_color']) ? $in['background_color'] : '') ?: $old['background_color'],
			'icon_source'      => $source,
			'icon_custom'      => $custom,
			'icon_background'  => sanitize_hex_color(isset($in['icon_background']) ? $in['icon_background'] : '') ?: $old['icon_background'],
			'generated'        => $old['generated'],
			'orientation'      => (isset($in['orientation']) && $in['orientation'] === 'portrait') ? 'portrait' : 'any',
			'screenshots'      => array_values(array_filter(array_map('absint', explode(',', isset($in['screenshots']) ? (string) $in['screenshots'] : '')), 'wp_attachment_is_image')),
			'banner'           => empty($in['banner']) ? 0 : 1,
			'banner_again'     => (isset($in['banner_again']) && trim((string) $in['banner_again']) !== '') ? min(43200, absint($in['banner_again'])) : 5,
			'banner_open'      => empty($in['banner_open']) ? 0 : 1,
			'owner_title'      => sanitize_text_field(isset($in['owner_title']) ? $in['owner_title'] : ''),
			'owner_text'       => sanitize_text_field(isset($in['owner_text']) ? $in['owner_text'] : ''),
			'dl_help'          => empty($in['dl_help']) ? 0 : 1,
			'banner_title'     => sanitize_text_field(isset($in['banner_title']) ? $in['banner_title'] : ''),
			'banner_text'      => sanitize_text_field(isset($in['banner_text']) ? $in['banner_text'] : ''),
			'apk_url'          => esc_url_raw(isset($in['apk_url']) ? trim($in['apk_url']) : ''),
			'assetlinks'       => $assetlinks,
			'rev'              => (int) $old['rev'] + 1,
		];

		update_option(self::OPTION, $new, true);
		self::$settings = null;

		if (self::source_id() && !self::generated()) {
			$made = self::generate_icons();
			if ($made !== true && !$error) {
				$error = 'icon-' . $made;
			}
		}

		$args = ['page' => self::SLUG, 'saved' => 1];
		if ($error) {
			$args['error'] = $error;
		}
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
		exit;
	}

	public static function render_page() {
		$s = self::settings();
		$links = $s['assetlinks'] ? json_decode($s['assetlinks'], true) : null;
		$package = isset($links[0]['target']['package_name']) ? $links[0]['target']['package_name'] : '';
		$https = wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https';
		$field = function ($name) use ($s) { return esc_attr((string) $s[$name]); };
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Y4APK App', 'y4apk'); ?></h1>

			<?php if (isset($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e('Saved. Clear the 10Web / page cache so visitors get the new settings.', 'y4apk'); ?>
				</p></div>
			<?php endif; ?>
			<?php
			$errors = [
				'assetlinks'      => __('That is not a valid assetlinks.json — paste the whole file from the PWABuilder download. The previous value was kept.', 'y4apk'),
				'icon-upload'     => __('The icon could not be uploaded. Use a PNG, JPG or WebP image.', 'y4apk'),
				'icon-missing'    => __('Choose an image for the custom icon first.', 'y4apk'),
				'icon-unreadable' => __('The server could not read that image to make the app icons. Try a PNG.', 'y4apk'),
				'icon-no-gd'      => __('The server has no image library (PHP GD), so app icons cannot be made from an image. The bundled icon is used instead.', 'y4apk'),
				'icon-not-writable' => __('The uploads folder is not writable, so the app icons could not be saved.', 'y4apk'),
			];
			$err = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';
			if ($err && isset($errors[$err])) : ?>
				<div class="notice notice-error"><p><?php echo esc_html($errors[$err]); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e('Visitors can install the site as an app: an icon on the home screen that opens full screen, keeps recently viewed pages for offline use, and updates itself with the site. The same app can be packaged as an Android APK — see step 2.', 'y4apk'); ?></p>

			<table class="widefat striped" style="max-width:860px">
				<tbody>
					<tr><td style="width:240px"><strong><?php esc_html_e('Status', 'y4apk'); ?></strong></td>
						<td><?php echo $s['enabled']
							? '<span style="color:#008a20">● ' . esc_html__('On', 'y4apk') . '</span>'
							: '<span style="color:#b32d2e">● ' . esc_html__('Off', 'y4apk') . '</span>'; ?></td></tr>
					<tr><td><strong>HTTPS</strong></td>
						<td><?php echo $https ? '✓' : '✗ ' . esc_html__('Installing requires HTTPS.', 'y4apk'); ?></td></tr>
					<tr><td><strong><?php esc_html_e('Manifest', 'y4apk'); ?></strong></td>
						<td><a href="<?php echo esc_url(self::endpoint('manifest')); ?>" target="_blank"><code><?php echo esc_html(self::endpoint('manifest')); ?></code></a></td></tr>
					<tr><td><strong><?php esc_html_e('Offline page', 'y4apk'); ?></strong></td>
						<td><a href="<?php echo esc_url(self::endpoint('offline')); ?>" target="_blank"><code><?php echo esc_html(self::endpoint('offline')); ?></code></a></td></tr>
					<tr><td><strong><?php esc_html_e('Android APK link', 'y4apk'); ?></strong></td>
						<td><?php echo $package
							? '✓ <code>' . esc_html($package) . '</code> — <a href="' . esc_url(home_url('/.well-known/assetlinks.json')) . '" target="_blank">assetlinks.json</a>'
							: esc_html__('Not set up yet (step 2).', 'y4apk'); ?></td></tr>
				</tbody>
			</table>

			<div id="pwa-check" style="max-width:860px;margin:1.5rem 0;padding:1rem 1.25rem;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1"
				data-manifest="<?php echo esc_url(self::endpoint('manifest')); ?>"
				data-sw="<?php echo esc_url(self::endpoint('sw')); ?>"
				data-home="<?php echo esc_url(home_url('/')); ?>"
				data-scope="<?php echo esc_attr(self::scope()); ?>">
				<h2 style="margin-top:0"><?php esc_html_e('Check the app', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Looks at the site the way a visitor’s browser does — through the cache and any speed plugin — and tests whether the app’s service worker really starts. PWABuilder needs it to find the service worker.', 'y4apk'); ?></p>
				<p><button type="button" class="button button-primary" id="pwa-check-run"><?php esc_html_e('Run the check', 'y4apk'); ?></button></p>
				<ul id="pwa-check-results" style="margin:0"></ul>
			</div>

			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="y4apk_save_pwa">
				<?php wp_nonce_field('y4apk-save-pwa'); ?>

				<h2><?php esc_html_e('1. The app', 'y4apk'); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e('Installable app', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>>
							<?php esc_html_e('Let visitors install the site as an app', 'y4apk'); ?></label>
							<p class="description"><?php esc_html_e('Turning this off also removes the app from browsers that already installed it (its cache is cleared on their next visit).', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-name"><?php esc_html_e('App name', 'y4apk'); ?></label></th>
						<td><input id="pwa-name" class="regular-text" name="name" value="<?php echo $field('name'); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
					<tr><th scope="row"><label for="pwa-short"><?php esc_html_e('Name under the icon', 'y4apk'); ?></label></th>
						<td><input id="pwa-short" class="regular-text" name="short_name" maxlength="15" value="<?php echo $field('short_name'); ?>">
							<p class="description"><?php esc_html_e('Keep it short — about 12 characters fit under a home-screen icon.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-desc"><?php esc_html_e('Description', 'y4apk'); ?></label></th>
						<td><input id="pwa-desc" class="large-text" name="description" value="<?php echo $field('description'); ?>" placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e('Colours', 'y4apk'); ?></th>
						<td><label><input type="color" name="theme_color" value="<?php echo $field('theme_color'); ?>"> <?php esc_html_e('Status bar', 'y4apk'); ?></label>
							&nbsp;&nbsp;<label><input type="color" name="background_color" value="<?php echo $field('background_color'); ?>"> <?php esc_html_e('Splash screen', 'y4apk'); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e('Icon', 'y4apk'); ?></th>
						<td>
							<?php
							$gen = self::generated();
							$icons = self::icons();
							$mode = $s['icon_source'];
							$custom_id = (int) $s['icon_custom'];
							$custom_ok = $custom_id && wp_attachment_is_image($custom_id);
							$small = $gen && min($gen['width'], $gen['height']) < 512;
							?>
							<div style="display:flex;align-items:flex-end;gap:1.25rem;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:520px;margin-bottom:1rem">
								<div style="text-align:center"><img src="<?php echo esc_url($icons[1]['src']); ?>" width="72" height="72" alt=""><br><small><?php esc_html_e('Desktop / browser', 'y4apk'); ?></small></div>
								<div style="text-align:center"><img src="<?php echo esc_url($icons[3]['src']); ?>" width="72" height="72" alt="" style="border-radius:50%"><br><small><?php esc_html_e('Android', 'y4apk'); ?></small></div>
								<div style="text-align:center"><img src="<?php echo esc_url(self::apple_touch_icon()); ?>" width="72" height="72" alt="" style="border-radius:22%"><br><small>iPhone</small></div>
								<div style="align-self:center;color:#50575e"><?php esc_html_e('In use now', 'y4apk'); ?></div>
							</div>
							<?php if ($small) : ?>
								<div class="notice notice-warning inline" style="max-width:520px"><p><?php printf(
									/* translators: 1: width, 2: height */
									esc_html__('The source image is only %1$d × %2$d pixels, so the icons will look soft. 512 × 512 or larger works best.', 'y4apk'),
									(int) $gen['width'], (int) $gen['height']); ?></p></div>
							<?php endif; ?>

							<fieldset style="display:grid;gap:.75rem;max-width:640px">
								<label style="display:flex;align-items:center;gap:.75rem">
									<input type="radio" name="icon_source" value="site_icon" <?php checked($mode, 'site_icon'); ?> <?php disabled(!has_site_icon()); ?>>
									<?php if (has_site_icon()) : ?><img src="<?php echo esc_url(get_site_icon_url(96)); ?>" width="40" height="40" alt=""><?php endif; ?>
									<span><strong><?php esc_html_e('Site Icon', 'y4apk'); ?></strong><br>
									<span class="description"><?php echo has_site_icon()
										? esc_html__('The icon set under Appearance ▸ Customize ▸ Site Identity. Changing it there changes the app too.', 'y4apk')
										: esc_html__('No Site Icon yet — set one under Appearance ▸ Customize ▸ Site Identity.', 'y4apk'); ?></span></span>
								</label>

								<div style="display:flex;align-items:flex-start;gap:.75rem">
									<input type="radio" id="pwa-src-custom" name="icon_source" value="custom" <?php checked($mode, 'custom'); ?> style="margin-top:.75rem">
									<img id="pwa-custom-preview" src="<?php echo $custom_ok ? esc_url(wp_get_attachment_image_url($custom_id, 'thumbnail')) : ''; ?>" width="40" height="40" alt="" style="<?php echo $custom_ok ? '' : 'display:none;'; ?>margin-top:.25rem;object-fit:contain">
									<div>
										<label for="pwa-src-custom"><strong><?php esc_html_e('My own icon', 'y4apk'); ?></strong></label><br>
										<span class="description"><?php esc_html_e('A square PNG, 512 × 512 or larger. A transparent background is fine.', 'y4apk'); ?></span>
										<input type="hidden" id="pwa-icon-custom" name="icon_custom" value="<?php echo $custom_ok ? (int) $custom_id : ''; ?>">
										<p style="margin:.5rem 0 0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
											<button type="button" class="button" id="pwa-pick-icon"><?php esc_html_e('Choose from Media Library', 'y4apk'); ?></button>
											<span><?php esc_html_e('or upload:', 'y4apk'); ?></span>
											<input type="file" name="icon_file" accept="image/png,image/jpeg,image/webp" id="pwa-icon-file">
										</p>
									</div>
								</div>

								<label style="display:flex;align-items:center;gap:.75rem">
									<input type="radio" name="icon_source" value="bundled" <?php checked($mode, 'bundled'); ?>>
									<img src="<?php echo esc_url(self::asset('img/pwa/icon-192.png')); ?>" width="40" height="40" alt="">
									<strong><?php esc_html_e('Bundled Y4 icon', 'y4apk'); ?></strong>
								</label>
							</fieldset>
						</td></tr>
					<tr><th scope="row"><label for="pwa-icon-bg"><?php esc_html_e('Icon background', 'y4apk'); ?></label></th>
						<td><input id="pwa-icon-bg" type="color" name="icon_background" value="<?php echo $field('icon_background'); ?>">
							<p class="description"><?php esc_html_e('Android crops app icons into a circle or rounded square, and iPhone shows transparent areas as black, so the icon is shrunk to fit and placed on this colour.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-orientation"><?php esc_html_e('Screen orientation', 'y4apk'); ?></label></th>
						<td><select id="pwa-orientation" name="orientation">
								<option value="any" <?php selected($s['orientation'], 'any'); ?>><?php esc_html_e('Turns with the phone', 'y4apk'); ?></option>
								<option value="portrait" <?php selected($s['orientation'], 'portrait'); ?>><?php esc_html_e('Always portrait', 'y4apk'); ?></option>
							</select></td></tr>
					<tr><th scope="row"><?php esc_html_e('Screenshots', 'y4apk'); ?></th>
						<td>
							<?php $shots = self::screenshots(); ?>
							<input type="hidden" id="pwa-screenshots" name="screenshots" value="<?php echo esc_attr(implode(',', array_map('intval', (array) $s['screenshots']))); ?>">
							<div id="pwa-shots" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem">
								<?php foreach ((array) $s['screenshots'] as $sid) :
									$thumb = wp_get_attachment_image_url((int) $sid, 'medium');
									if ($thumb) : ?>
									<img src="<?php echo esc_url($thumb); ?>" alt="" style="height:120px;width:auto;border:1px solid #dcdcde;border-radius:6px">
								<?php endif; endforeach; ?>
							</div>
							<button type="button" class="button" id="pwa-pick-shots"><?php esc_html_e('Choose screenshots', 'y4apk'); ?></button>
							<button type="button" class="button-link" id="pwa-clear-shots" <?php echo $shots ? '' : 'hidden'; ?>><?php esc_html_e('Remove all', 'y4apk'); ?></button>
							<p class="description"><?php esc_html_e('Android shows these in a larger, app-store style install sheet. Take 2–5 screenshots of the site on the same phone (portrait), upload them to the Media Library and pick them here. Their alt text becomes the caption.', 'y4apk'); ?></p>
							<?php foreach (self::screenshot_problems() as $problem) : ?>
								<div class="notice notice-warning inline"><p><?php echo esc_html($problem); ?></p></div>
							<?php endforeach; ?>
						</td></tr>
				</tbody></table>

				<h3><?php esc_html_e('Install banner', 'y4apk'); ?></h3>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e('Show the banner', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="banner" value="1" <?php checked($s['banner']); ?>>
							<?php esc_html_e('Invite visitors who do not have the app yet, a few seconds after each page loads', 'y4apk'); ?></label>
							<p class="description"><?php esc_html_e('Never shown inside the app, to visitors whose browser reports the app as installed, or on download pages. Someone who uninstalls the app is invited again.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-again"><?php esc_html_e('After it is closed', 'y4apk'); ?></label></th>
						<td><?php printf(
							/* translators: %s: number field */
							esc_html__('Show it again after %s minutes', 'y4apk'),
							'<input id="pwa-again" type="number" min="0" max="43200" step="1" class="small-text" name="banner_again" value="' . esc_attr((string) (int) $s['banner_again']) . '">'); ?>
							<p class="description"><?php esc_html_e('On whichever page the visitor is on by then. 0 = on the next page they open. Tapping Install or Download keeps it away for at least 10 minutes, to give the install time to finish.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-btitle"><?php esc_html_e('Title', 'y4apk'); ?></label></th>
						<td><input id="pwa-btitle" class="regular-text" name="banner_title" value="<?php echo $field('banner_title'); ?>" placeholder="<?php echo esc_attr(sprintf(__('Get the %s app', 'y4apk'), self::short_name())); ?>"></td></tr>
					<tr><th scope="row"><label for="pwa-btext"><?php esc_html_e('Text', 'y4apk'); ?></label></th>
						<td><input id="pwa-btext" class="large-text" name="banner_text" value="<?php echo $field('banner_text'); ?>" placeholder="<?php esc_attr_e('Faster, full screen, one tap from your home screen.', 'y4apk'); ?>"></td></tr>
				</tbody></table>

				<h3><?php esc_html_e('Visitors who already have the app', 'y4apk'); ?></h3>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e('Suggest the app', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="banner_open" value="1" <?php checked($s['banner_open']); ?>>
							<?php esc_html_e('Show them a banner too, on the same schedule; they choose whether to switch to the app or stay on the site', 'y4apk'); ?></label>
							<p class="description"><?php esc_html_e('Nothing ever opens by itself. With the APK (once assetlinks.json is saved below) the banner has an Open button that opens the same page in the app. With the web app installed from the browser it reminds them the app is on their home screen, as browsers give pages no way to open it. Android with Chrome only: iPhones cannot tell a page whether the app is on the home screen.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-otitle"><?php esc_html_e('Title', 'y4apk'); ?></label></th>
						<td><input id="pwa-otitle" class="regular-text" name="owner_title" value="<?php echo $field('owner_title'); ?>" placeholder="<?php echo esc_attr(sprintf(__('You have the %s app', 'y4apk'), self::short_name())); ?>"></td></tr>
					<tr><th scope="row"><label for="pwa-otext"><?php esc_html_e('Text', 'y4apk'); ?></label></th>
						<td><input id="pwa-otext" class="large-text" name="owner_text" value="<?php echo $field('owner_text'); ?>" placeholder="<?php esc_attr_e('Open this page in the app, or keep browsing here.', 'y4apk'); ?>"></td></tr>
				</tbody></table>

				<h3><?php esc_html_e('Downloads inside the app', 'y4apk'); ?></h3>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e('Download guide', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="dl_help" value="1" <?php checked($s['dl_help']); ?>>
							<?php esc_html_e('Explain where downloads go when the site is used as the app', 'y4apk'); ?></label>
							<p class="description"><?php esc_html_e('An installed app has no browser download bar and no Downloads list; progress only shows in the phone’s notifications. On Android, inside the app, download pages get a line under the Download button saying so, and a short guide after it is tapped. Visitors in the browser see no change.', 'y4apk'); ?></p></td></tr>
				</tbody></table>

				<h2><?php esc_html_e('2. Android APK', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Google Play does not accept apps that distribute modified APKs, so the app is offered as an APK file from this site. PWABuilder turns the installable site into one, free and in the browser:', 'y4apk'); ?></p>
				<ol style="max-width:860px">
					<li><?php printf(
						/* translators: %s: link to PWABuilder */
						esc_html__('Save these settings with the app switched on, clear the cache, then open %s and enter your site address.', 'y4apk'),
						'<a href="https://www.pwabuilder.com/" target="_blank" rel="noopener">pwabuilder.com</a>'); ?></li>
					<li><?php esc_html_e('Choose Package for stores ▸ Android, then the Other Android tab (made for APKs handed out directly; Google Play does not take this kind of site). Package ID: com.y4apk.app — it can never change once people have installed the app. Then Download Package.', 'y4apk'); ?></li>
					<li><strong><?php esc_html_e('Keep the downloaded zip safe, especially signing.keystore and signing-key-info.txt — every future update of the app must be signed with the same key. Lose it and the installed app can never be updated; leak it and others can publish updates in your name.', 'y4apk'); ?></strong></li>
					<li><?php esc_html_e('Open assetlinks.json from the zip and paste all of it below. Without it the app opens with a browser address bar.', 'y4apk'); ?></li>
					<li><?php esc_html_e('Upload the .apk from the zip to the Media Library and paste its URL below. Visitors on Android browsers that cannot install apps (TikTok, Facebook and LINE in-app browsers) then get a Download button instead.', 'y4apk'); ?></li>
				</ol>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="pwa-links">assetlinks.json</label></th>
						<td><textarea id="pwa-links" name="assetlinks" rows="8" class="large-text code" placeholder='[{"relation":["delegate_permission/common.handle_all_urls"],"target":{"namespace":"android_app","package_name":"com.y4apk.app","sha256_cert_fingerprints":["…"]}}]'><?php echo esc_textarea($s['assetlinks']); ?></textarea>
							<p class="description"><?php printf(esc_html__('Served at %s', 'y4apk'), '<code>' . esc_html(home_url('/.well-known/assetlinks.json')) . '</code>'); ?></p></td></tr>
					<tr><th scope="row"><label for="pwa-apk"><?php esc_html_e('APK download URL', 'y4apk'); ?></label></th>
						<td><input id="pwa-apk" type="url" class="large-text code" name="apk_url" value="<?php echo $field('apk_url'); ?>" placeholder="<?php echo esc_attr(home_url('/wp-content/uploads/y4apk-app.apk')); ?>"></td></tr>
				</tbody></table>

				<?php submit_button(__('Save', 'y4apk')); ?>
			</form>

			<h2><?php esc_html_e('If you use 10Web Booster', 'y4apk'); ?></h2>
			<p><?php esc_html_e('Under Website Booster ▸ Settings, add y4apk-pwa to both exclusion lists: JavaScript delay ▸ Excluded JavaScript files, and JavaScript aggregation ▸ Excluded JavaScript files. Otherwise the app’s startup script waits for the first tap, or is merged into a bundle that loads late, and robots like PWABuilder never see the service worker. Then Clear Cache and run the check above.', 'y4apk'); ?></p>
		</div>
		<?php
	}
}

Y4APK_PWA::init();

}
