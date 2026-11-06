<?php
/**
 * Roblox executors (2.44): Y4APK ▸ Executors keeps the executors people run
 * scripts with — name, maker, logo and banner, the systems it runs on, whether
 * it works with the current Roblox, its price, key system and sUNC — and their
 * files: uploaded from the browser straight to Cloudflare R2 (as App updates
 * does) into a download folder in files/up, or a link to the maker's site.
 *
 * /executors/ lists them (filters for system and status, search), each has its
 * page /executors/<name>/ with the downloads and the scripts tested with it.
 * Members tick the executors their script works with when they share it; the
 * script's page shows them as "Tested with" chips. Downloads are counted with
 * the site's analytics (executor_dl, by executor), never per visitor.
 */

defined('ABSPATH') || exit;

class Y4APK_Executors {
	const CPT       = 'y4apk_executor';
	const META      = '_y4apk_exec';      // developer, platforms, status, price, key, sunc, version, banner, files
	const ON_SCRIPT = '_y4apk_executors'; // on a script post: [executor IDs]
	const BY_ONE    = '_y4apk_executor';  // the same, one row per executor (to look scripts up by executor)
	const VERSION   = '1';
	const EXT       = ['apk', 'zip', 'exe', 'msi', 'dmg', 'ipa', '7z', 'rar'];

	public static function init() {
		add_action('init', [__CLASS__, 'register']);
		add_action('add_meta_boxes', [__CLASS__, 'boxes']);
		add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
		add_action('save_post_post', [__CLASS__, 'save_script'], 10, 2);
		add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
		add_filter('template_include', [__CLASS__, 'template'], 30);
		add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
		add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
		foreach (['start', 'parts', 'complete', 'abort'] as $a) {
			add_action('wp_ajax_y4apk_exec_' . $a, [__CLASS__, 'ajax_' . $a]);
		}
	}

	public static function platforms() {
		return ['windows' => 'Windows', 'mac' => 'Mac', 'android' => 'Android', 'ios' => 'iOS'];
	}

	public static function statuses() {
		return ['working' => [__('Working', 'y4apk'), __('updated for current Roblox', 'y4apk')], 'outdated' => [__('Outdated', 'y4apk'), __('not updated yet', 'y4apk')], 'unknown' => [__('Status unknown', 'y4apk'), '']];
	}

	public static function register() {
		register_post_type(self::CPT, [
			'labels'        => ['name' => __('Executors', 'y4apk'), 'singular_name' => __('Executor', 'y4apk'), 'add_new_item' => __('Add an executor', 'y4apk'), 'edit_item' => __('Edit executor', 'y4apk'),
				'all_items' => __('Executors', 'y4apk'), 'menu_name' => __('Executors', 'y4apk'), 'search_items' => __('Search executors', 'y4apk'), 'not_found' => __('No executors yet.', 'y4apk')],
			'public'        => true,
			'has_archive'   => 'executors',
			'rewrite'       => ['slug' => 'executors', 'with_front' => false],
			'supports'      => ['title', 'editor', 'thumbnail', 'page-attributes'],
			'show_in_menu'  => defined('Y4APK_MENU') ? Y4APK_MENU : true,
			'show_in_rest'  => false,
			'menu_icon'     => 'dashicons-editor-code',
			'exclude_from_search' => false,
		]);
		if (get_option('y4apk_exec_ver') !== self::VERSION) {
			flush_rewrite_rules(false);
			update_option('y4apk_exec_ver', self::VERSION);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Reading
	 * ---------------------------------------------------------------- */

	public static function data($id) {
		$d = get_post_meta($id, self::META, true);
		$d = wp_parse_args(is_array($d) ? $d : [], ['developer' => '', 'platforms' => [], 'status' => 'unknown', 'price' => '', 'key' => 'keyless', 'sunc' => '', 'version' => '', 'banner' => 0, 'files' => []]);
		$d['platforms'] = array_values(array_intersect(array_keys(self::platforms()), (array) $d['platforms']));
		return $d;
	}

	/** The published executors, best first: working, then by order and downloads. */
	public static function all() {
		static $all = null;
		if ($all !== null) {
			return $all;
		}
		$posts = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC']]);
		$dl = self::downloads();
		$rank = ['working' => 0, 'unknown' => 1, 'outdated' => 2];
		usort($posts, function ($a, $b) use ($dl, $rank) {
			$sa = $rank[self::data($a->ID)['status']] ?? 1;
			$sb = $rank[self::data($b->ID)['status']] ?? 1;
			if ($sa !== $sb) {
				return $sa - $sb;
			}
			if ($a->menu_order !== $b->menu_order) {
				return $a->menu_order - $b->menu_order;
			}
			return ($dl[$b->ID] ?? 0) - ($dl[$a->ID] ?? 0);
		});
		return $all = $posts;
	}

	/** Downloads per executor, from the analytics (kept 10 minutes): [ID => count]. */
	public static function downloads() {
		$c = get_transient('y4apk_exec_dl');
		if (!is_array($c)) {
			global $wpdb;
			$c = [];
			if (class_exists('Y4APK_Stats')) {
				foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT dim, SUM(n) AS n FROM ' . Y4APK_Stats::table() . ' WHERE event = %s GROUP BY dim', 'executor_dl')) as $r) {
					$c[(int) $r->dim] = (int) $r->n;
				}
			}
			set_transient('y4apk_exec_dl', $c, 10 * MINUTE_IN_SECONDS);
		}
		return $c;
	}

	public static function logo($id, $size = 'thumbnail') {
		$u = get_the_post_thumbnail_url($id, $size);
		return $u ? $u : '';
	}

	public static function banner($id) {
		$d = self::data($id);
		$u = $d['banner'] ? wp_get_attachment_image_url((int) $d['banner'], 'large') : '';
		return $u ? $u : '';
	}

	public static function price_text($d) {
		$p = trim((string) $d['price']);
		return $p === '' || strtolower($p) === 'free' ? __('Free', 'y4apk') : $p;
	}

	/** An executor's mark: its logo, else its first letter on a colour of its own. */
	public static function mark($id, $size = 28) {
		$logo = self::logo($id);
		if ($logo !== '') {
			return '<img class="y4x-mark" src="' . esc_url($logo) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async">';
		}
		$t = get_the_title($id);
		return '<span class="y4x-mark y4x-letter" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;background:hsl(' . (abs(crc32($t)) % 360) . ',55%,44%)">' . esc_html(mb_strtoupper(mb_substr($t, 0, 1))) . '</span>';
	}

	public static function platform_icon($p) {
		$i = [
			'windows' => '<path d="M3 5.5 10.5 4.4v7.1H3V5.5Zm0 13 7.5 1.1v-7H3v5.9Zm8.4 1.2L21 21V12.5h-9.6v7.2Zm0-15.4v7.2H21V3l-9.6 1.3Z"/>',
			'mac'     => '<path d="M16.4 12.6c0-2.4 2-3.5 2-3.6a4.3 4.3 0 0 0-3.4-1.8c-1.4-.2-2.8.8-3.5.8s-1.8-.8-3-.8a4.5 4.5 0 0 0-3.8 2.3c-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.2 2.8-2.3a10 10 0 0 0 1.3-2.6s-2.5-1-2.5-3.6ZM14.1 5.5A4 4 0 0 0 15 2.6a4.1 4.1 0 0 0-2.7 1.4 3.8 3.8 0 0 0-1 2.8 3.4 3.4 0 0 0 2.8-1.3Z"/>',
			'android' => '<path d="M17.6 9.5 19.4 6.4a.4.4 0 0 0-.7-.4l-1.8 3.2a11 11 0 0 0-9.8 0L5.3 6a.4.4 0 0 0-.7.4l1.8 3.1A10.6 10.6 0 0 0 1 18h22a10.6 10.6 0 0 0-5.4-8.5ZM7 15.3a1.1 1.1 0 1 1 0-2.2 1.1 1.1 0 0 1 0 2.2Zm10 0a1.1 1.1 0 1 1 0-2.2 1.1 1.1 0 0 1 0 2.2Z"/>',
			'ios'     => '<path d="M16 1H8a3 3 0 0 0-3 3v16a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V4a3 3 0 0 0-3-3Zm-4 20.5a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6ZM17 17H7V4h10v13Z"/>',
		];
		return isset($i[$p]) ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">' . $i[$p] . '</svg>' : '';
	}

	/** The live scripts tested with an executor: post IDs, most read first. */
	public static function scripts_of($id, $n = 12) {
		return get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => $n, 'fields' => 'ids', 'meta_query' => [['key' => self::BY_ONE, 'value' => (int) $id]],
			'meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC']);
	}

	/** Where a download row's file is: the file on R2 itself when it was uploaded here (downloads where you are), else its link. */
	public static function file_url($f) {
		if (!empty($f['key']) && class_exists('Y4APK_R2') && Y4APK_R2::can_store()) {
			return Y4APK_R2::public_url($f['key']);
		}
		return (string) $f['url'];
	}

	/** How to put it on the device, in three short steps. */
	public static function steps($platform) {
		$s = [
			'android' => [__('Download the APK', 'y4apk'), __('Open it and allow installing from your browser if Android asks', 'y4apk'), __('Open Roblox, then the executor, and paste the script', 'y4apk')],
			'windows' => [__('Download it (unzip it if it is a ZIP)', 'y4apk'), __('Run it; Windows may ask before it opens an app from the internet', 'y4apk'), __('Open Roblox, attach the executor and paste the script', 'y4apk')],
			'mac'     => [__('Download it and open the file', 'y4apk'), __('Move the app to Applications and open it', 'y4apk'), __('Open Roblox, then the executor, and paste the script', 'y4apk')],
			'ios'     => [__('Download it on your iPhone or iPad', 'y4apk'), __('Install it the way its maker explains', 'y4apk'), __('Open Roblox, then the executor, and paste the script', 'y4apk')],
		];
		return isset($s[$platform]) ? $s[$platform] : $s['android'];
	}

	/**
	 * On a script's page (2.45): "Get Executors" — the executors it was tested with.
	 * Tapping one opens a panel on the page with its downloads (the file comes
	 * straight down, the page stays) and how to install it.
	 */
	public static function tested_with($post_id) {
		$ids = array_filter(array_map('intval', (array) get_post_meta($post_id, self::ON_SCRIPT, true)));
		if (!$ids) {
			return '';
		}
		$st = self::statuses();
		$plats = self::platforms();
		$chips = '';
		$sheets = '';
		foreach ($ids as $id) {
			if (get_post_status($id) !== 'publish' || get_post_type($id) !== self::CPT) {
				continue;
			}
			$d = self::data($id);
			$s = $st[$d['status']] ?? $st['unknown'];
			$name = get_the_title($id);
			$chips .= '<button type="button" class="y4x-chip is-' . esc_attr($d['status']) . '" data-y4x-open="y4x-sheet-' . (int) $id . '" title="' . esc_attr($s[0]) . '">' . self::mark($id, 22) . '<span>' . esc_html($name) . '</span><i aria-hidden="true"></i>'
				. '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6 1.4 1.4-6 6-6-6 1.4-1.4 3.6 3.6V4Zm-6 14h14v2H5v-2Z"/></svg></button>';
			$banner = self::banner($id);
			$files = '';
			$first = '';
			foreach ($d['files'] as $f) {
				$url = self::file_url($f);
				if ($url === '') {
					continue;
				}
				$first = $first ?: $f['platform'];
				$away = empty($f['key']) && strpos($url, home_url()) !== 0;
				$files .= '<a class="y4x-dl is-' . esc_attr($f['platform']) . '" href="' . esc_url($url) . '" rel="nofollow noopener" data-y4x-get="' . (int) $id . '" data-y4x-plat="' . esc_attr($f['platform']) . '"' . ($away ? ' target="_blank"' : ' download') . '>'
					. '<span class="y4x-dl-ico">' . self::platform_icon($f['platform']) . '</span><span class="y4x-dl-text"><b>' . esc_html($f['label'] !== '' ? $f['label'] : sprintf(__('Download for %s', 'y4apk'), $plats[$f['platform']])) . '</b><small>'
					. esc_html(implode(' · ', array_filter([$plats[$f['platform']], $f['version'] !== '' ? 'v' . $f['version'] : '', $f['size']]))) . '</small></span>'
					. '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6 1.4 1.4-6 6-6-6 1.4-1.4 3.6 3.6V4Zm-6 14h14v2H5v-2Z"/></svg></a>';
			}
			$steps = '';
			foreach (self::steps($first ?: ($d['platforms'][0] ?? 'android')) as $i => $t) {
				$steps .= '<li><b>' . ($i + 1) . '</b><span>' . esc_html($t) . '</span></li>';
			}
			$tags = '<span class="y4x-tag' . (self::price_text($d) === __('Free', 'y4apk') ? ' is-free' : '') . '">' . esc_html(self::price_text($d)) . '</span>'
				. ($d['sunc'] !== '' ? '<span class="y4x-tag is-sunc">sUNC ' . (int) $d['sunc'] . '%</span>' : '')
				. '<span class="y4x-tag">' . esc_html($d['key'] === 'key' ? __('Key system', 'y4apk') : __('Keyless', 'y4apk')) . '</span>';
			$sheets .= '<dialog class="y4x-sheet is-' . esc_attr($d['status']) . '" id="y4x-sheet-' . (int) $id . '" aria-label="' . esc_attr($name) . '">'
				. '<div class="y4x-sheet-top"' . ($banner ? ' style="background-image:url(' . esc_url($banner) . ')"' : '') . '><button type="button" class="y4x-sheet-x" data-y4x-close aria-label="' . esc_attr__('Close', 'y4apk') . '">×</button></div>'
				. '<div class="y4x-sheet-body"><div class="y4x-sheet-who">' . self::mark($id, 64) . '<div><p class="y4x-pill is-' . esc_attr($d['status']) . '"><i class="y4x-dot is-' . esc_attr($d['status']) . '"></i>' . esc_html($s[0]) . ($s[1] ? ' · ' . esc_html($s[1]) : '') . '</p>'
				. '<h3>' . esc_html($name) . '</h3><p class="y4x-sheet-meta">' . esc_html(implode(' · ', array_filter([$d['developer'] !== '' ? sprintf(__('by %s', 'y4apk'), $d['developer']) : '', $d['version'] !== '' ? 'v' . $d['version'] : '']))) . '</p></div></div>'
				. '<div class="y4x-tags">' . $tags . '</div>'
				. ($d['status'] === 'outdated' ? '<p class="y4x-warn">' . esc_html__('Outdated: it may not work with the current Roblox until its maker updates it.', 'y4apk') . '</p>' : '')
				. ($files !== '' ? '<div class="y4x-dls">' . $files . '</div>' : '<p class="y4x-muted">' . esc_html__('No download yet.', 'y4apk') . '</p>')
				. '<ol class="y4x-how">' . $steps . '</ol>'
				. '<p class="y4x-sheet-foot"><a href="' . esc_url(get_permalink($id)) . '">' . esc_html(sprintf(__('More about %s', 'y4apk'), $name)) . ' →</a><span>' . esc_html__('Made by other people, not by Y4APK. Use it at your own risk.', 'y4apk') . '</span></p>'
				. '</div></dialog>';
		}
		if ($chips === '') {
			return '';
		}
		static $js = false;
		$script = '';
		if (!$js) {
			$js = true;
			$script = '<script id="y4apk-pwa-getexec" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false">(function () {'
				. 'document.addEventListener("click", function (e) {'
				. 'var o = e.target.closest("[data-y4x-open]"); if (o) { var d = document.getElementById(o.getAttribute("data-y4x-open")); if (d && d.showModal) { d.showModal(); document.documentElement.classList.add("y4x-lock"); } else if (d) { location.href = d.querySelector(".y4x-sheet-foot a").href; } return; }'
				. 'var c = e.target.closest("[data-y4x-close]"); if (c) { c.closest("dialog").close(); return; }'
				. 'if (e.target.tagName === "DIALOG" && e.target.classList.contains("y4x-sheet")) { e.target.close(); return; }'
				. 'var g = e.target.closest("[data-y4x-get]"); if (g && window.y4apkTrack) { window.y4apkTrack("executor_dl", g.getAttribute("data-y4x-get")); }'
				. '});'
				. 'document.addEventListener("close", function (e) { if (e.target.classList && e.target.classList.contains("y4x-sheet")) { document.documentElement.classList.remove("y4x-lock"); } }, true);'
				. '})();</script>';
		}
		return '<div class="y4x-tested"><div class="y4x-tested-head"><span><b>' . esc_html__('Get Executors', 'y4apk') . '</b><small>' . esc_html__('This script was tested with these. Tap one to download it.', 'y4apk') . '</small></span><a href="' . esc_url(get_post_type_archive_link(self::CPT)) . '">' . esc_html__('All executors', 'y4apk') . ' →</a></div>'
			. '<div class="y4x-chips">' . $chips . '</div>' . $sheets . '</div>' . $script;
	}

	/* ---------------------------------------------------------------- *
	 * Admin: the editor
	 * ---------------------------------------------------------------- */

	public static function boxes() {
		add_meta_box('y4x-details', __('Executor details', 'y4apk'), [__CLASS__, 'box'], self::CPT, 'normal', 'high');
		add_meta_box('y4x-script', __('Tested with executors', 'y4apk'), [__CLASS__, 'script_box'], 'post', 'side');
	}

	public static function admin_assets($hook) {
		$s = get_current_screen();
		if ($s && $s->post_type === self::CPT && in_array($hook, ['post.php', 'post-new.php'], true)) {
			wp_enqueue_media();
		}
	}

	public static function box($post) {
		$d = self::data($post->ID);
		$files = $d['files'] ?: [['platform' => 'android', 'label' => '', 'version' => '', 'url' => '', 'size' => '']];
		$banner = $d['banner'] ? wp_get_attachment_image_url((int) $d['banner'], 'medium') : '';
		wp_nonce_field('y4x_save', 'y4x_nonce');
		$r2 = class_exists('Y4APK_R2') && Y4APK_R2::can_store();
		?>
		<style>
		.y4x-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 18px;margin:6px 0 14px}.y4x-grid label{display:block;font-weight:600;margin-bottom:4px}.y4x-grid input[type=text],.y4x-grid input[type=number],.y4x-grid select{width:100%}
		.y4x-plat{display:flex;flex-wrap:wrap;gap:6px 14px}.y4x-plat label{font-weight:400}
		.y4x-banner{display:flex;gap:12px;align-items:center}.y4x-banner img{width:220px;height:auto;max-height:90px;object-fit:cover;border-radius:8px;background:#f0f0f1}
		.y4x-files{width:100%;border-collapse:collapse;margin-top:6px}.y4x-files th{text-align:left;font-size:12px;color:#646970;padding:4px 6px}.y4x-files td{padding:6px;vertical-align:top;border-top:1px solid #f0f0f1}.y4x-files input[type=text]{width:100%}
		.y4x-up-status{display:block;margin-top:4px;font-size:12px;color:#646970}.y4x-up-bar{height:6px;margin-top:4px;border-radius:4px;background:#f0f0f1;overflow:hidden}.y4x-up-bar i{display:block;height:100%;width:0;background:#2271b1;transition:width .3s}
		</style>
		<div class="y4x-grid">
			<div><label for="y4x-dev"><?php esc_html_e('Made by', 'y4apk'); ?></label><input id="y4x-dev" type="text" name="y4x[developer]" value="<?php echo esc_attr($d['developer']); ?>" placeholder="Lxnny"></div>
			<div><label for="y4x-status"><?php esc_html_e('Status', 'y4apk'); ?></label><select id="y4x-status" name="y4x[status]"><?php foreach (self::statuses() as $k => $s) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($d['status'], $k); ?>><?php echo esc_html($s[0] . ($s[1] ? ' — ' . $s[1] : '')); ?></option><?php endforeach; ?></select></div>
			<div><label for="y4x-price"><?php esc_html_e('Price', 'y4apk'); ?></label><input id="y4x-price" type="text" name="y4x[price]" value="<?php echo esc_attr($d['price']); ?>" placeholder="<?php esc_attr_e('Free, or $4.99/wk', 'y4apk'); ?>"></div>
			<div><label for="y4x-key"><?php esc_html_e('Key', 'y4apk'); ?></label><select id="y4x-key" name="y4x[key]"><option value="keyless" <?php selected($d['key'], 'keyless'); ?>><?php esc_html_e('Keyless', 'y4apk'); ?></option><option value="key" <?php selected($d['key'], 'key'); ?>><?php esc_html_e('Key system', 'y4apk'); ?></option></select></div>
			<div><label for="y4x-sunc"><?php esc_html_e('sUNC (%)', 'y4apk'); ?></label><input id="y4x-sunc" type="number" min="0" max="100" name="y4x[sunc]" value="<?php echo esc_attr($d['sunc']); ?>" placeholder="98"></div>
			<div><label for="y4x-ver"><?php esc_html_e('Version', 'y4apk'); ?></label><input id="y4x-ver" type="text" name="y4x[version]" value="<?php echo esc_attr($d['version']); ?>" placeholder="2.690"></div>
			<div><label><?php esc_html_e('Runs on', 'y4apk'); ?></label><div class="y4x-plat"><?php foreach (self::platforms() as $k => $n) : ?><label><input type="checkbox" name="y4x[platforms][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $d['platforms'], true)); ?>> <?php echo esc_html($n); ?></label><?php endforeach; ?></div></div>
		</div>
		<div class="y4x-grid" style="grid-template-columns:1fr">
			<div><label><?php esc_html_e('Banner (wide picture on its card and page)', 'y4apk'); ?></label>
				<div class="y4x-banner"><img data-y4x-banner-img src="<?php echo esc_url($banner); ?>" alt="" <?php echo $banner ? '' : 'hidden'; ?>><input type="hidden" name="y4x[banner]" value="<?php echo esc_attr((int) $d['banner']); ?>" data-y4x-banner><button type="button" class="button" data-y4x-pick><?php esc_html_e('Choose a picture', 'y4apk'); ?></button><button type="button" class="button-link" data-y4x-unpick><?php esc_html_e('Remove', 'y4apk'); ?></button></div>
				<p class="description"><?php esc_html_e('The logo is the Featured image (square). The text in the editor above is the executor’s page.', 'y4apk'); ?></p></div>
		</div>
		<h3 style="margin:18px 0 0"><?php esc_html_e('Downloads', 'y4apk'); ?></h3>
		<p class="description"><?php echo esc_html($r2 ? __('Upload each file (it goes from your browser straight to R2 and gets a download folder in files/up, like the site’s), or paste a link. One row per system or build.', 'y4apk') : __('Cloudflare R2 is not set up (App updates ▸ R2 settings): paste links.', 'y4apk')); ?></p>
		<table class="y4x-files"><thead><tr><th style="width:120px"><?php esc_html_e('System', 'y4apk'); ?></th><th style="width:170px"><?php esc_html_e('Button text', 'y4apk'); ?></th><th style="width:100px"><?php esc_html_e('Version', 'y4apk'); ?></th><th><?php esc_html_e('File or link', 'y4apk'); ?></th><th style="width:90px"><?php esc_html_e('Size', 'y4apk'); ?></th><th style="width:30px"></th></tr></thead>
			<tbody data-y4x-rows>
			<?php foreach ($files as $i => $f) : ?>
				<tr data-y4x-row>
					<td><select name="y4x[files][<?php echo (int) $i; ?>][platform]"><?php foreach (self::platforms() as $k => $n) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['platform'], $k); ?>><?php echo esc_html($n); ?></option><?php endforeach; ?></select></td>
					<td><input type="text" name="y4x[files][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr($f['label']); ?>" placeholder="<?php esc_attr_e('Download for Android', 'y4apk'); ?>"></td>
					<td><input type="text" name="y4x[files][<?php echo (int) $i; ?>][version]" value="<?php echo esc_attr($f['version']); ?>" placeholder="2.690"></td>
					<td><input type="text" name="y4x[files][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr($f['url']); ?>" placeholder="https://" data-y4x-url>
						<input type="hidden" name="y4x[files][<?php echo (int) $i; ?>][key]" value="" data-y4x-key>
						<?php if ($r2) : ?><label class="button button-small" style="margin-top:6px"><input type="file" hidden data-y4x-file accept=".apk,.zip,.exe,.msi,.dmg,.ipa,.7z,.rar">⬆ <?php esc_html_e('Upload a file', 'y4apk'); ?></label><?php endif; ?>
						<span class="y4x-up-status" data-y4x-status hidden></span></td>
					<td><input type="text" name="y4x[files][<?php echo (int) $i; ?>][size]" value="<?php echo esc_attr($f['size']); ?>" placeholder="120 MB" data-y4x-size></td>
					<td><button type="button" class="button-link-delete" data-y4x-del aria-label="<?php esc_attr_e('Remove', 'y4apk'); ?>">×</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" data-y4x-add><?php esc_html_e('+ Add a download', 'y4apk'); ?></button></p>
		<script>
		(function () {
			var box = document.getElementById('y4x-details'); if (!box) { return; }
			var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('y4apk_exec_up')); ?>, post = <?php echo (int) $post->ID; ?>;
			var rows = box.querySelector('[data-y4x-rows]');
			var renum = function () { [].forEach.call(rows.querySelectorAll('[data-y4x-row]'), function (r, i) { [].forEach.call(r.querySelectorAll('[name]'), function (x) { x.name = x.name.replace(/\[files\]\[\d+\]/, '[files][' + i + ']'); }); }); };
			box.addEventListener('click', function (e) {
				if (e.target.closest('[data-y4x-add]')) { var c = rows.querySelector('[data-y4x-row]').cloneNode(true); [].forEach.call(c.querySelectorAll('input[type=text],input[type=hidden]'), function (x) { x.value = ''; }); var s = c.querySelector('[data-y4x-status]'); s.hidden = true; s.innerHTML = ''; rows.appendChild(c); renum(); }
				var del = e.target.closest('[data-y4x-del]'); if (del && rows.querySelectorAll('[data-y4x-row]').length > 1) { del.closest('tr').remove(); renum(); }
				if (e.target.closest('[data-y4x-pick]') && window.wp && wp.media) {
					var fr = wp.media({ title: <?php echo wp_json_encode(__('Banner', 'y4apk')); ?>, library: { type: 'image' }, multiple: false });
					fr.on('select', function () { var a = fr.state().get('selection').first().toJSON(); box.querySelector('[data-y4x-banner]').value = a.id; var im = box.querySelector('[data-y4x-banner-img]'); im.src = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url); im.hidden = false; });
					fr.open();
				}
				if (e.target.closest('[data-y4x-unpick]')) { box.querySelector('[data-y4x-banner]').value = '0'; box.querySelector('[data-y4x-banner-img]').hidden = true; }
			});
			var call = function (action, data) { var fd = new FormData(); fd.append('action', 'y4apk_exec_' + action); fd.append('nonce', nonce); fd.append('post', post); Object.keys(data).forEach(function (k) { var v = data[k]; if (v && typeof v === 'object') { Object.keys(v).forEach(function (i) { fd.append(k + '[' + i + ']', v[i]); }); } else { fd.append(k, v); } }); return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (!j || !j.success) { throw new Error(j && j.data && j.data.message || 'error'); } return j.data; }); };
			var busy = 0;
			window.addEventListener('beforeunload', function (e) { if (busy) { e.preventDefault(); e.returnValue = ''; } });
			box.addEventListener('change', function (e) {
				var inp = e.target.closest('[data-y4x-file]'); if (!inp || !inp.files.length) { return; }
				var tr = inp.closest('tr'), file = inp.files[0], st = tr.querySelector('[data-y4x-status]'), etags = {}, loaded = {}, up;
				st.hidden = false; st.innerHTML = <?php echo wp_json_encode(__('Starting…', 'y4apk')); ?> + '<span class="y4x-up-bar"><i></i></span>'; busy++;
				var bar = function () { var s = 0; Object.keys(loaded).forEach(function (k) { s += loaded[k]; }); var p = Math.min(100, s / file.size * 100); st.querySelector('i').style.width = p + '%'; st.firstChild.nodeValue = p.toFixed(1) + '% · ' + (file.size / 1048576).toFixed(1) + ' MB'; };
				var urls = {};
				var get = function (n) { if (urls[n]) { return Promise.resolve(urls[n]); } var want = []; for (var k = n; k < n + 50 && k <= up.count; k++) { want.push(k); } return call('parts', { uploadId: up.uploadId, numbers: want }).then(function (d) { Object.keys(d.urls).forEach(function (k) { urls[k] = d.urls[k]; }); return urls[n]; }); };
				var put = function (n, tries) { return get(n).then(function (u) { return new Promise(function (ok, no) { var x = new XMLHttpRequest(), from = (n - 1) * up.partSize; x.open('PUT', u); x.upload.onprogress = function (ev) { loaded[n] = ev.loaded; bar(); }; x.onload = function () { var t = x.getResponseHeader('ETag'); if (x.status < 300 && t) { etags[n] = t.replace(/"/g, ''); ok(); } else { no(new Error('HTTP ' + x.status)); } }; x.onerror = function () { no(new Error('network')); }; x.send(file.slice(from, Math.min(file.size, from + up.partSize))); }); }).catch(function (err) { if ((tries || 0) < 5) { delete urls[n]; return new Promise(function (ok) { setTimeout(ok, 1500 * Math.pow(2, tries || 0)); }).then(function () { return put(n, (tries || 0) + 1); }); } throw err; }); };
				call('start', { name: file.name, size: file.size, version: tr.querySelector('input[name$="[version]"]').value, platform: tr.querySelector('select').value, title: (document.getElementById('title') || {}).value || '' }).then(function (d) {
					up = d; Object.keys(d.urls).forEach(function (k) { urls[k] = d.urls[k]; });
					var next = 1, lanes = [];
					var lane = function () { if (next > up.count) { return Promise.resolve(); } var n = next++; return put(n).then(lane); };
					for (var i = 0; i < Math.min(4, up.count); i++) { lanes.push(lane()); }
					return Promise.all(lanes);
				}).then(function () { return call('complete', { uploadId: up.uploadId, etags: etags }); }).then(function (d) {
					busy--; tr.querySelector('[data-y4x-key]').value = d.key; tr.querySelector('[data-y4x-url]').value = ''; tr.querySelector('[data-y4x-url]').placeholder = d.name; tr.querySelector('[data-y4x-size]').value = d.size;
					st.innerHTML = '✓ ' + <?php echo wp_json_encode(__('Uploaded: it becomes the download when you save.', 'y4apk')); ?>;
				}).catch(function (err) { busy--; st.textContent = <?php echo wp_json_encode(__('Upload failed:', 'y4apk')); ?> + ' ' + err.message; if (up) { call('abort', { uploadId: up.uploadId }).catch(function () {}); } });
			});
		})();
		</script>
		<?php
	}

	public static function save($post_id, $post) {
		if (!isset($_POST['y4x_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['y4x_nonce'])), 'y4x_save') || defined('DOING_AUTOSAVE') || !current_user_can('edit_post', $post_id)) {
			return;
		}
		$in = isset($_POST['y4x']) ? (array) wp_unslash($_POST['y4x']) : [];
		$old = self::data($post_id);
		$d = [
			'developer' => trim(mb_substr(sanitize_text_field($in['developer'] ?? ''), 0, 60)),
			'platforms' => array_values(array_intersect(array_keys(self::platforms()), array_map('sanitize_key', (array) ($in['platforms'] ?? [])))),
			'status'    => isset(self::statuses()[$in['status'] ?? '']) ? $in['status'] : 'unknown',
			'price'     => trim(mb_substr(sanitize_text_field($in['price'] ?? ''), 0, 30)),
			'key'       => ($in['key'] ?? '') === 'key' ? 'key' : 'keyless',
			'sunc'      => ($in['sunc'] ?? '') === '' ? '' : max(0, min(100, (int) $in['sunc'])),
			'version'   => trim(mb_substr(sanitize_text_field($in['version'] ?? ''), 0, 30)),
			'banner'    => absint($in['banner'] ?? 0),
			'files'     => [],
		];
		$o = class_exists('Y4APK_R2') ? Y4APK_R2::settings() : [];
		foreach (array_slice((array) ($in['files'] ?? []), 0, 12) as $f) {
			$row = ['platform' => isset(self::platforms()[$f['platform'] ?? '']) ? $f['platform'] : 'android', 'label' => trim(mb_substr(sanitize_text_field($f['label'] ?? ''), 0, 40)),
				'version' => trim(mb_substr(sanitize_text_field($f['version'] ?? ''), 0, 30)), 'url' => esc_url_raw(trim((string) ($f['url'] ?? ''))), 'size' => trim(mb_substr(sanitize_text_field($f['size'] ?? ''), 0, 20))];
			$key = sanitize_text_field($f['key'] ?? '');
			$done = $key !== '' ? get_transient('y4apk_exec_done_' . md5($key)) : null;
			if (is_array($done) && (int) $done['post'] === (int) $post_id && $o) {
				$folder = Y4APK_R2::folder_of($row['url']);
				if ($folder === '') {
					$folder = Y4APK_R2::new_folder($post_id, 'Executor', strtolower(pathinfo($key, PATHINFO_EXTENSION)));
				}
				if (!is_wp_error(Y4APK_R2::point_folder($folder, Y4APK_R2::public_url($key)))) {
					$row['url'] = $o['dir_url'] . $folder;
					$row['size'] = Y4APK_R2::size_text($done['size']);
					$row['key'] = $key;
					delete_transient('y4apk_exec_done_' . md5($key));
				}
			} else {
				foreach ($old['files'] as $of) { // the file this row had stays its own
					if (!empty($of['key']) && $of['url'] === $row['url']) {
						$row['key'] = $of['key'];
					}
				}
			}
			if ($row['url'] !== '') {
				$d['files'][] = $row;
			}
		}
		if ($d['version'] === '' && $d['files']) {
			$d['version'] = $d['files'][0]['version'];
		}
		update_post_meta($post_id, self::META, $d);
		update_post_meta($post_id, 'wp_title_GP', $post->post_title); // the name download folders are named after
		delete_transient('y4apk_exec_dl');
	}

	/** Posts: the executors a script was tested with. */
	public static function script_box($post) {
		$all = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC']);
		$on = array_map('intval', (array) get_post_meta($post->ID, self::ON_SCRIPT, true));
		wp_nonce_field('y4x_script', 'y4x_script_nonce');
		if (!$all) {
			echo '<p class="description">' . esc_html__('Add executors in Y4APK ▸ Executors first.', 'y4apk') . '</p>';
			return;
		}
		foreach ($all as $x) {
			echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="y4x_on[]" value="' . esc_attr($x->ID) . '" ' . checked(in_array($x->ID, $on, true), true, false) . '> ' . esc_html($x->post_title) . '</label>';
		}
	}

	public static function save_script($post_id) {
		if (!isset($_POST['y4x_script_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['y4x_script_nonce'])), 'y4x_script') || defined('DOING_AUTOSAVE') || !current_user_can('edit_post', $post_id)) {
			return;
		}
		self::put_on_script($post_id, isset($_POST['y4x_on']) ? (array) wp_unslash($_POST['y4x_on']) : []);
	}

	/** Keeps the executors of a script (only real, published ones). */
	public static function put_on_script($post_id, $ids) {
		$ok = [];
		foreach (array_slice(array_map('absint', (array) $ids), 0, 20) as $id) {
			if ($id && get_post_type($id) === self::CPT && get_post_status($id) === 'publish') {
				$ok[] = $id;
			}
		}
		$ok = array_values(array_unique($ok));
		$ok ? update_post_meta($post_id, self::ON_SCRIPT, $ok) : delete_post_meta($post_id, self::ON_SCRIPT);
		delete_post_meta($post_id, self::BY_ONE); // one row each too, so an executor's page finds its scripts
		foreach ($ok as $id) {
			add_post_meta($post_id, self::BY_ONE, $id);
		}
	}

	public static function columns($c) {
		$out = [];
		foreach ($c as $k => $v) {
			$out[$k] = $v;
			if ($k === 'title') {
				$out['y4x_status'] = __('Status', 'y4apk');
				$out['y4x_on'] = __('Runs on', 'y4apk');
				$out['y4x_dl'] = __('Downloads', 'y4apk');
			}
		}
		return $out;
	}

	public static function column($col, $id) {
		$d = self::data($id);
		if ($col === 'y4x_status') {
			$s = self::statuses()[$d['status']] ?? ['', ''];
			static $css = false;
			if (!$css) {
				$css = true; // the live dot (2.48), once for the list
				echo '<style>.y4x-st{display:inline-flex;align-items:center;gap:7px;font-weight:600}.y4x-st i{position:relative;width:8px;height:8px;border-radius:50%;background:#8c8f94}.y4x-st.is-working{color:#0a7a2f}.y4x-st.is-working i{background:#1faa55}.y4x-st.is-outdated{color:#b32d2e}.y4x-st.is-outdated i{background:#e5484d}'
					. '.y4x-st.is-working i::after,.y4x-st.is-outdated i::after{content:"";position:absolute;inset:0;border-radius:50%;background:inherit;animation:y4pulse 1.8s ease-out infinite}.y4x-st.is-outdated i::after{animation-duration:1.4s}@keyframes y4pulse{0%{transform:scale(1);opacity:.75}80%,100%{transform:scale(2.8);opacity:0}}@media (prefers-reduced-motion:reduce){.y4x-st i::after{animation:none;opacity:0}}</style>';
			}
			echo '<span class="y4x-st is-' . esc_attr($d['status']) . '"><i aria-hidden="true"></i>' . esc_html($s[0]) . '</span>';
		} elseif ($col === 'y4x_on') {
			echo esc_html(implode(', ', array_map(function ($p) { return self::platforms()[$p]; }, $d['platforms'])));
		} elseif ($col === 'y4x_dl') {
			echo esc_html(number_format_i18n(self::downloads()[$id] ?? 0));
		}
	}

	/* ---------------------------------------------------------------- *
	 * Admin: files straight to R2
	 * ---------------------------------------------------------------- */

	private static function guard() {
		check_ajax_referer('y4apk_exec_up', 'nonce');
		$post = absint($_POST['post'] ?? 0);
		if (!$post || get_post_type($post) !== self::CPT || !current_user_can('edit_post', $post)) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			wp_send_json_error(['message' => __('Cloudflare R2 is not set up yet (App updates ▸ Settings).', 'y4apk')], 400);
		}
		return $post;
	}

	private static function upload_of($id) {
		$u = get_transient('y4apk_exec_up_' . md5((string) $id));
		if (!is_array($u) || (int) $u['user'] !== get_current_user_id()) {
			wp_send_json_error(['message' => __('That upload is not known here (expired?). Start it again.', 'y4apk')], 400);
		}
		return $u;
	}

	public static function ajax_start() {
		$post = self::guard();
		$name = sanitize_file_name(wp_unslash($_POST['name'] ?? ''));
		$size = (float) ($_POST['size'] ?? 0);
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($ext, self::EXT, true) || $size <= 0 || $size > 20 * 1073741824) {
			wp_send_json_error(['message' => __('Send an APK, ZIP, EXE, MSI, DMG, IPA, 7Z or RAR file.', 'y4apk')], 400);
		}
		$v = Y4APK_App_Updates::number(sanitize_text_field(wp_unslash($_POST['version'] ?? '')));
		$plat = sanitize_key($_POST['platform'] ?? '');
		$title = trim(sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
		$slug = Y4APK_R2::slug($title !== '' ? $title : get_the_title($post)) ?: 'Executor';
		$key = 'Executors/' . implode('-', array_filter([$slug, ucfirst($plat), $v !== '' ? 'v' . $v : '', 'Y4APK.COM', 'IDv' . wp_rand(100000, 999999)], 'strlen')) . '.' . $ext;
		$id = Y4APK_R2::mp_start($key, $ext === 'apk' ? 'application/vnd.android.package-archive' : 'application/octet-stream');
		if (is_wp_error($id)) {
			wp_send_json_error(['message' => $id->get_error_message()], 502);
		}
		list($part, $count) = Y4APK_R2::mp_plan($size);
		set_transient('y4apk_exec_up_' . md5($id), ['id' => $id, 'key' => $key, 'size' => $size, 'part' => $part, 'count' => $count, 'user' => get_current_user_id(), 'post' => $post, 'name' => $name], 2 * DAY_IN_SECONDS);
		wp_send_json_success(['uploadId' => $id, 'partSize' => $part, 'count' => $count, 'urls' => Y4APK_R2::mp_urls($key, $id, $count, range(1, min(50, $count)))]);
	}

	public static function ajax_parts() {
		self::guard();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$u = self::upload_of($id);
		wp_send_json_success(['urls' => Y4APK_R2::mp_urls($u['key'], $id, $u['count'], (array) wp_unslash($_POST['numbers'] ?? []))]);
	}

	public static function ajax_complete() {
		self::guard();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$u = self::upload_of($id);
		$size = Y4APK_R2::mp_complete($u['key'], $id, $u['count'], array_map('sanitize_text_field', (array) wp_unslash($_POST['etags'] ?? [])));
		if (is_wp_error($size)) {
			wp_send_json_error(['message' => $size->get_error_message()], 502);
		}
		if ((float) $size !== (float) $u['size']) {
			wp_send_json_error(['message' => __('R2 does not have the whole file: upload it again.', 'y4apk')], 502);
		}
		delete_transient('y4apk_exec_up_' . md5($id));
		set_transient('y4apk_exec_done_' . md5($u['key']), ['key' => $u['key'], 'size' => $size, 'post' => $u['post']], 3 * DAY_IN_SECONDS);
		wp_send_json_success(['key' => $u['key'], 'size' => Y4APK_R2::size_text($size), 'name' => $u['name']]);
	}

	public static function ajax_abort() {
		self::guard();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$u = get_transient('y4apk_exec_up_' . md5($id));
		if (is_array($u)) {
			Y4APK_R2::mp_abort($u['key'], $id);
			delete_transient('y4apk_exec_up_' . md5($id));
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------- *
	 * The site
	 * ---------------------------------------------------------------- */

	public static function is_page() {
		return is_post_type_archive(self::CPT) || is_singular(self::CPT);
	}

	public static function template($t) {
		return self::is_page() ? (locate_template('template/executors.php') ?: $t) : $t;
	}

	public static function assets() {
		if (self::is_page() || (is_singular('post') && get_post_meta(get_queried_object_id(), self::ON_SCRIPT, true))) {
			wp_enqueue_style('y4apk-executors', get_template_directory_uri() . '/assets/css/executors.css', [], defined('Y4APK_THEME_VERSION') ? Y4APK_THEME_VERSION : null);
		}
	}
}

Y4APK_Executors::init();
