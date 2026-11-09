<?php
/**
 * VIP (2.62): a membership that takes the ads off and the wait out.
 *
 * - A member is VIP until a time (usermeta y4apk_vip_until). Days are added to
 *   the end: 7 more days on top of 3 left make 10. Nothing renews by itself.
 * - What VIP gives: no ads anywhere (the theme's ad slots, Direct Link, the
 *   ad that unlocks a script), the download page without its countdown and
 *   straight to the file, and a VIP badge on the profile.
 * - While VIP the account also has the role y4apk_vip, so ad code put in by
 *   plugins can be told to skip VIPs (WPCode ▸ a snippet ▸ Smart Conditional
 *   Logic ▸ User Role is not VIP). The page also gets the body class y4-vip.
 * - Redeem codes: Y4APK ▸ VIP ▸ Codes makes them in batches (each worth a
 *   plan's days), to sell or give away anywhere; a member types one on
 *   /account/vip/. Each works once (a single UPDATE … WHERE used_by = 0, so two
 *   people at the same moment cannot both have it). Wrong codes: 8 an hour.
 * - Payments: none built in yet. "Get VIP" opens the address set in Settings
 *   (a shop, a Telegram account…) where people buy a code; without one the
 *   page offers the code box only.
 *
 * Pages for signed-in people are never kept by the page cache, so a VIP's page
 * is made for them; visitors who are not signed in get the cached one, ads and
 * all.
 */

defined('ABSPATH') || exit;

class Y4APK_VIP {

	const UNTIL  = 'y4apk_vip_until'; // usermeta: the Unix time VIP ends
	const LOG    = 'y4apk_vip_log';   // usermeta: [['at', 'days', 'how', 'ref', 'by'], …], the last 60
	const ROLE   = 'y4apk_vip';       // a second role while VIP, for other plugins' conditions
	const OPTION = 'y4apk_vip';
	const SLUG   = 'y4apk-vip';
	const CRON   = 'y4apk_vip_expire';
	const DBV    = '1';
	const TRIES  = 8;                 // wrong codes in an hour before the box waits
	const ABC    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I: codes are read aloud and typed

	private static $settings = null;

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action(self::CRON, [__CLASS__, 'expire']);
		add_filter('body_class', [__CLASS__, 'body_class']);
		add_action('wp_head', [__CLASS__, 'head_css'], 99);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 103);
			add_action('admin_post_y4apk_vip', [__CLASS__, 'handle_admin']);
			add_action('admin_post_y4apk_vip_csv', [__CLASS__, 'csv']);
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_vip_codes';
	}

	public static function setup() {
		if (!get_role(self::ROLE)) {
			add_role(self::ROLE, 'VIP', ['read' => true]);
		}
		if (get_option('y4apk_vip_db') !== self::DBV) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta('CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code char(16) NOT NULL,
				days smallint(5) unsigned NOT NULL,
				batch varchar(60) NOT NULL DEFAULT '',
				made int(10) unsigned NOT NULL DEFAULT 0,
				used_by bigint(20) unsigned NOT NULL DEFAULT 0,
				used_at int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY batch (batch)
			) " . $wpdb->get_charset_collate() . ';');
			update_option('y4apk_vip_db', self::DBV, false);
		}
		if (!wp_next_scheduled(self::CRON)) {
			wp_schedule_event(time() + 300, 'hourly', self::CRON);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'plans'     => [
				['days' => 7, 'price' => '3.99', 'was' => ''],
				['days' => 30, 'price' => '5.99', 'was' => ''],
				['days' => 365, 'price' => '59.99', 'was' => '74.99'],
			],
			'currency'  => 'USD',
			'buy_url'   => '',
			'buy_label' => '',
		];
	}

	public static function settings() {
		if (self::$settings === null) {
			$o = get_option(self::OPTION, []);
			self::$settings = wp_parse_args(is_array($o) ? $o : [], self::defaults());
			self::$settings['plans'] = array_values(array_filter((array) self::$settings['plans'], function ($p) {
				return is_array($p) && (int) ($p['days'] ?? 0) > 0;
			}));
		}
		return self::$settings;
	}

	public static function currencies() {
		return ['USD' => '$', 'THB' => '฿', 'EUR' => '€', 'GBP' => '£', 'IDR' => 'Rp', 'VND' => '₫', 'PHP' => '₱', 'MYR' => 'RM', 'INR' => '₹', 'BRL' => 'R$'];
	}

	public static function money($amount) {
		$s = self::settings();
		$sym = self::currencies()[$s['currency']] ?? '$';
		$n = (float) $amount;
		return $sym . number_format_i18n($n, floor($n) == $n && $s['currency'] !== 'USD' ? 0 : 2);
	}

	/** "7 days", "1 month", "1 year". */
	public static function plan_label($days) {
		$days = (int) $days;
		if ($days % 365 === 0) {
			$n = $days / 365;
			return sprintf(_n('%s year', '%s years', $n, 'y4apk'), number_format_i18n($n));
		}
		if ($days % 30 === 0) {
			$n = $days / 30;
			return sprintf(_n('%s month', '%s months', $n, 'y4apk'), number_format_i18n($n));
		}
		return sprintf(_n('%s day', '%s days', $days, 'y4apk'), number_format_i18n($days));
	}

	/** "Save 20%" from a plan's old price, or ''. */
	public static function saving($plan) {
		$was = (float) ($plan['was'] ?? 0);
		$now = (float) ($plan['price'] ?? 0);
		if ($was <= 0 || $now <= 0 || $was <= $now) {
			return '';
		}
		return sprintf(__('Save %s%%', 'y4apk'), number_format_i18n(round(100 - $now * 100 / $was)));
	}

	/* ---------------------------------------------------------------- *
	 * Who is VIP
	 * ---------------------------------------------------------------- */

	public static function until($user_id) {
		return (int) get_user_meta((int) $user_id, self::UNTIL, true);
	}

	public static function is_vip($user_id = 0) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id > 0 && self::until($user_id) > time();
	}

	/** Days added to the end of the member's VIP (from now when it is over); the new end. */
	public static function extend($user_id, $days, $how, $ref = '', $by = 0) {
		$user_id = (int) $user_id;
		$days = max(1, min(3650, (int) $days));
		$new = max(time(), self::until($user_id)) + $days * DAY_IN_SECONDS;
		update_user_meta($user_id, self::UNTIL, $new);
		$user = get_userdata($user_id);
		if ($user && !in_array(self::ROLE, (array) $user->roles, true)) {
			$user->add_role(self::ROLE);
		}
		self::log_add($user_id, ['at' => time(), 'days' => $days, 'how' => $how, 'ref' => (string) $ref, 'by' => (int) $by]);
		return $new;
	}

	/** VIP over now (by the site). */
	public static function take($user_id, $by = 0) {
		$user_id = (int) $user_id;
		$left = max(0, self::until($user_id) - time());
		delete_user_meta($user_id, self::UNTIL);
		$user = get_userdata($user_id);
		if ($user) {
			$user->remove_role(self::ROLE);
		}
		self::log_add($user_id, ['at' => time(), 'days' => -(int) ceil($left / DAY_IN_SECONDS), 'how' => 'removed', 'ref' => '', 'by' => (int) $by]);
	}

	private static function log_add($user_id, $row) {
		$log = self::log($user_id);
		$log[] = $row;
		update_user_meta($user_id, self::LOG, array_slice($log, -60));
	}

	public static function log($user_id) {
		$log = get_user_meta((int) $user_id, self::LOG, true);
		return is_array($log) ? $log : [];
	}

	/** Every hour: the VIP role off the accounts whose time is up (the theme itself looks at the time). */
	public static function expire() {
		$ids = get_users(['role' => self::ROLE, 'fields' => 'ID', 'number' => 500, 'meta_query' => [
			'relation' => 'OR',
			['key' => self::UNTIL, 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC'],
			['key' => self::UNTIL, 'compare' => 'NOT EXISTS'],
		]]);
		foreach ($ids as $id) {
			$u = get_userdata((int) $id);
			if ($u) {
				$u->remove_role(self::ROLE);
			}
		}
	}

	/* ---------------------------------------------------------------- *
	 * Redeem codes
	 * ---------------------------------------------------------------- */

	/** What a person typed, as stored: 16 letters and digits, no dashes. */
	public static function normalize($text) {
		return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $text)), 0, 32);
	}

	/** XXXX-XXXX-XXXX-XXXX */
	public static function pretty($code) {
		return implode('-', str_split((string) $code, 4));
	}

	/** $count new codes worth $days each: how many were made. */
	public static function make($count, $days, $batch) {
		$count = max(1, min(1000, (int) $count));
		$made = 0;
		for ($tries = 0; $made < $count && $tries < $count * 3; $tries++) {
			$made += self::make_one($days, $batch) !== '' ? 1 : 0;
		}
		return $made;
	}

	/** One new random code worth $days (2.63: a paid gift is one): the code, or '' (it already existed, very rarely). */
	public static function make_one($days, $batch) {
		global $wpdb;
		$max = strlen(self::ABC) - 1;
		for ($try = 0; $try < 3; $try++) {
			$code = '';
			for ($j = 0; $j < 16; $j++) {
				$code .= self::ABC[random_int(0, $max)];
			}
			if ($wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . ' (code, days, batch, made) VALUES (%s, %d, %s, %d)',
				$code, max(1, min(3650, (int) $days)), substr(sanitize_text_field((string) $batch), 0, 60), time()))) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * A member types a code: ['ok' => true, 'days', 'until'] or ['ok' => false, 'why' => wait|short|wrong|used].
	 */
	public static function redeem($user_id, $text) {
		global $wpdb;
		$key = 'y4apk_vip_try_' . (int) $user_id;
		$tries = (int) get_transient($key);
		if ($tries >= self::TRIES) {
			return ['ok' => false, 'why' => 'wait'];
		}
		$code = self::normalize($text);
		if (strlen($code) !== 16) {
			return ['ok' => false, 'why' => 'short'];
		}
		$row = $wpdb->get_row($wpdb->prepare('SELECT id, days, used_by FROM ' . self::table() . ' WHERE code = %s', $code));
		if (!$row || (int) $row->used_by) {
			set_transient($key, $tries + 1, HOUR_IN_SECONDS);
			return ['ok' => false, 'why' => $row ? 'used' : 'wrong'];
		}
		$took = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET used_by = %d, used_at = %d WHERE id = %d AND used_by = 0', (int) $user_id, time(), (int) $row->id));
		if ($took !== 1) {
			return ['ok' => false, 'why' => 'used'];
		}
		$until = self::extend($user_id, (int) $row->days, 'code', self::pretty($code));
		delete_transient($key);
		return ['ok' => true, 'days' => (int) $row->days, 'until' => $until];
	}

	/* ---------------------------------------------------------------- *
	 * The page: /account/vip/ (Y4APK_Members routes it here)
	 * ---------------------------------------------------------------- */

	public static function url($args = []) {
		return Y4APK_Members::url('vip', $args);
	}

	public static function route(&$ctx) {
		$ctx['screen'] = 'vip';
		$uid = $ctx['user'] ? (int) $ctx['user']->ID : 0;
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do']) && $_POST['y4_do'] === 'vip_redeem') {
			if (!$uid) {
				wp_safe_redirect(Y4APK_Members::url('login'));
				exit;
			}
			if (!wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_vip')) {
				$ctx['errors'][] = __('The page was open too long. Try again.', 'y4apk');
			} else {
				$r = self::redeem($uid, isset($_POST['code']) ? wp_unslash($_POST['code']) : '');
				if ($r['ok']) {
					wp_safe_redirect(self::url(['ok' => 'vip', 'd' => $r['days']]));
					exit;
				}
				$ctx['errors'][] = [
					'wait'  => __('Too many wrong codes. Wait an hour and try again.', 'y4apk'),
					'short' => __('A code has 16 letters and digits, like ABCD-EFGH-JKLM-NPQR.', 'y4apk'),
					'wrong' => __('That code does not exist. Check it and try again.', 'y4apk'),
					'used'  => __('That code has already been used.', 'y4apk'),
				][$r['why']];
				$ctx['old']['code'] = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
			}
		}
		// 2.63: Pay — the order, then the payment company's page
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do']) && $_POST['y4_do'] === 'vip_buy' && class_exists('Y4APK_Pay')) {
			if (!$uid) {
				wp_safe_redirect(Y4APK_Members::url('login'));
				exit;
			}
			$days = absint($_POST['vip_plan'] ?? 0);
			$plan = null;
			foreach (self::settings()['plans'] as $p) {
				if ((int) $p['days'] === $days) {
					$plan = $p;
				}
			}
			if (!wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_vip')) {
				$ctx['errors'][] = __('The page was open too long. Try again.', 'y4apk');
			} elseif (!$plan) {
				$ctx['errors'][] = __('Choose a plan.', 'y4apk');
			} else {
				$go = Y4APK_Pay::start($uid, $plan, !empty($_POST['gift']));
				if (is_wp_error($go)) {
					$ctx['errors'][] = $go->get_error_message();
				} else {
					wp_redirect($go); // the payment company's page, on its own site
					exit;
				}
			}
		}
		$order = null;
		if ($uid && class_exists('Y4APK_Pay') && isset($_GET['order'], $_GET['k'])) {
			$o = Y4APK_Pay::get(absint($_GET['order']));
			if ($o && (int) $o->user_id === $uid && hash_equals((string) $o->token, (string) wp_unslash($_GET['k']))) {
				$order = $o;
			}
		}
		$ctx['vip'] = [
			'order'  => $order,
			'cancel' => !empty($_GET['cancel']),
			'orders' => $uid && class_exists('Y4APK_Pay') ? Y4APK_Pay::for_user($uid) : [],
			'pay'    => class_exists('Y4APK_Pay') && Y4APK_Pay::ready(),
			'until' => $uid ? self::until($uid) : 0,
			'log'   => $uid ? array_reverse(self::log($uid)) : [],
			'set'   => self::settings(),
			'added' => isset($_GET['ok'], $_GET['d']) && $_GET['ok'] === 'vip' ? absint($_GET['d']) : 0,
		];
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-vip', get_template_directory_uri() . '/assets/css/vip.css', ['y4apk-account'], Y4APK_THEME_VERSION);
		}, 20);
	}

	/** "Code ABCD-…", "From the site", "Taken off". */
	public static function how_text($row) {
		switch ($row['how'] ?? '') {
			case 'code':
				return sprintf(__('Code %s', 'y4apk'), $row['ref']);
			case 'admin':
				return __('From the site', 'y4apk');
			case 'pay':
				return __('Payment', 'y4apk');
			case 'removed':
				return __('Ended by the site', 'y4apk');
		}
		return '';
	}

	/* ---------------------------------------------------------------- *
	 * Around the site
	 * ---------------------------------------------------------------- */

	public static function body_class($classes) {
		if (self::is_vip()) {
			$classes[] = 'y4-vip';
		}
		return $classes;
	}

	/** The badge, the header menu's VIP line, and ad boxes hidden for a VIP whatever put them there. */
	public static function head_css() {
		?>
		<style id="y4apk-vip-css">
		.y4-vip .advertisement{display:none!important}
		.vip-badge{display:inline-flex;align-items:center;gap:.25rem;height:1.5rem;padding:0 .55rem;border-radius:999px;background:linear-gradient(135deg,#ffd76a,#f5a524);color:#4a2c00;font-size:.75rem;font-weight:800;letter-spacing:.04em;line-height:1;vertical-align:middle;box-shadow:0 .25rem .75rem -.25rem rgba(245,165,36,.7)}
		.vip-badge svg{width:.95rem;height:.95rem}
		.h-acct-vip{display:flex;align-items:center;gap:.75rem;margin:.25rem .5rem .5rem;padding:.7rem .9rem;border-radius:.9rem;background:linear-gradient(135deg,#232a2e,#36414a);color:#fff!important;font-weight:700;text-decoration:none}
		.h-acct-vip svg{flex:none;color:#ffcf4d}
		.h-acct-vip span{flex:1;min-width:0;line-height:1.25}
		.h-acct-vip small{display:block;color:rgba(255,255,255,.7);font-weight:500;font-size:.75rem}
		.h-acct-vip.is-on{background:linear-gradient(135deg,#ffd76a,#f5a524);color:#3b2300!important}
		.h-acct-vip.is-on svg{color:#7a4a00}.h-acct-vip.is-on small{color:rgba(59,35,0,.75)}
		.y4-vip-cta{display:flex;align-items:center;justify-content:center;gap:.5rem;width:max-content;max-width:100%;margin:1rem auto 0;padding:.6rem 1rem;border-radius:999px;background:linear-gradient(135deg,#232a2e,#36414a);color:#fff!important;font-size:.875rem;font-weight:700;text-decoration:none}
		.y4-vip-cta svg{color:#ffcf4d;flex:none}
		</style>
		<?php
	}

	public static function crown($size = 18) {
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true"><path d="M3 7.5 7.5 11 12 4l4.5 7L21 7.5 19.2 18H4.8L3 7.5ZM5 19.5h14V21H5v-1.5Z"/></svg>';
	}

	public static function badge() {
		return '<span class="vip-badge" title="VIP">' . self::crown(14) . 'VIP</span>';
	}

	/**
	 * The download page's file address for a VIP: straight to the file on R2
	 * when it is one of this site's download folders, else as it is.
	 */
	public static function file_url($url) {
		if (!class_exists('Y4APK_R2') || !self::is_vip()) {
			return $url;
		}
		$o = Y4APK_R2::settings();
		$dir = trailingslashit((string) $o['dir_url']);
		if ($dir === '/' || strpos((string) $url, $dir) !== 0) {
			return $url;
		}
		$folder = trim(substr((string) $url, strlen($dir)), '/');
		if ($folder === '' || strpos($folder, '/') !== false) {
			return $url;
		}
		$key = Y4APK_R2::folder_key($folder);
		return $key !== '' ? Y4APK_R2::public_url($key) : $url;
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ VIP
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_submenu_page(Y4APK_MENU, __('VIP', 'y4apk'), __('VIP', 'y4apk'), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	private static function admin_url($args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	/** A member from what the owner typed: @name, a username or an email. */
	private static function find_user($text) {
		$text = trim(ltrim((string) $text, '@'));
		if ($text === '') {
			return null;
		}
		$u = is_email($text) ? get_user_by('email', $text) : get_user_by('login', $text);
		return $u ?: null;
	}

	public static function handle_admin() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_vip_admin');
		$in = wp_unslash($_POST);
		$do = sanitize_key($in['do'] ?? '');
		$back = ['tab' => sanitize_key($in['tab'] ?? 'members')];
		if ($do === 'give') {
			$u = self::find_user($in['who'] ?? '');
			$days = (int) ($in['days'] === 'other' ? ($in['other'] ?? 0) : ($in['days'] ?? 0));
			if (!$u) {
				$back['note'] = 'nouser';
			} elseif ($days < 1) {
				$back['note'] = 'nodays';
			} else {
				self::extend($u->ID, $days, 'admin', '', get_current_user_id());
				$back += ['note' => 'given', 'n' => $days, 'who' => $u->user_login];
			}
		} elseif ($do === 'take') {
			$u = get_userdata(absint($in['user'] ?? 0));
			if ($u) {
				self::take($u->ID, get_current_user_id());
				$back += ['note' => 'taken', 'who' => $u->user_login];
			}
		} elseif ($do === 'make') {
			$days = (int) ($in['days'] === 'other' ? ($in['other'] ?? 0) : ($in['days'] ?? 0));
			if ($days < 1) {
				$back['note'] = 'nodays';
			} else {
				$batch = trim((string) ($in['batch'] ?? '')) ?: wp_date('Y-m-d H:i') . ' · ' . self::plan_label($days);
				$n = self::make(absint($in['count'] ?? 0), $days, $batch);
				$back += ['note' => 'made', 'n' => $n, 'batch' => substr(sanitize_text_field($batch), 0, 60)];
			}
		} elseif ($do === 'drop') {
			global $wpdb;
			$n = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE batch = %s AND used_by = 0', (string) ($in['batch'] ?? '')));
			$back += ['note' => 'dropped', 'n' => (int) $n];
		} elseif ($do === 'settings') {
			$plans = [];
			foreach ((array) ($in['plans'] ?? []) as $p) {
				$days = absint($p['days'] ?? 0);
				$price = preg_replace('/[^0-9.]/', '', (string) ($p['price'] ?? ''));
				if ($days < 1 || $price === '' || (float) $price <= 0) {
					continue;
				}
				$was = preg_replace('/[^0-9.]/', '', (string) ($p['was'] ?? ''));
				$plans[] = ['days' => min(3650, $days), 'price' => $price, 'was' => (float) $was > (float) $price ? $was : ''];
			}
			$cur = strtoupper(sanitize_key($in['currency'] ?? 'usd'));
			update_option(self::OPTION, [
				'plans'     => array_slice($plans, 0, 4),
				'currency'  => isset(self::currencies()[$cur]) ? $cur : 'USD',
				'buy_url'   => esc_url_raw(trim((string) ($in['buy_url'] ?? '')), ['https', 'http', 'tg']),
				'buy_label' => substr(sanitize_text_field((string) ($in['buy_label'] ?? '')), 0, 40),
			], false);
			self::$settings = null;
			if (class_exists('Y4APK_Pay')) {
				Y4APK_Pay::save_settings($in); // 2.63
			}
			if (function_exists('y4apk_purge_pages')) {
				y4apk_purge_pages();
			}
			$back['note'] = 'saved';
		}
		wp_safe_redirect(self::admin_url($back));
		exit;
	}

	/** Y4APK ▸ VIP ▸ Codes ▸ a batch's codes as a CSV file. */
	public static function csv() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_vip_csv');
		global $wpdb;
		$batch = isset($_GET['batch']) ? (string) wp_unslash($_GET['batch']) : '';
		$all = !empty($_GET['all']);
		$rows = $wpdb->get_results($wpdb->prepare('SELECT code, days, used_by, used_at FROM ' . self::table() . ' WHERE batch = %s' . ($all ? '' : ' AND used_by = 0') . ' ORDER BY id', $batch));
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="vip-codes-' . sanitize_file_name($batch ?: 'batch') . '.csv"');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['code', 'days', 'used_by', 'used_at']);
		foreach ($rows as $r) {
			$u = (int) $r->used_by ? get_userdata((int) $r->used_by) : null;
			fputcsv($out, [self::pretty($r->code), (int) $r->days, $u ? $u->user_login : '', (int) $r->used_at ? wp_date('Y-m-d H:i', (int) $r->used_at) : '']);
		}
		fclose($out);
		exit;
	}

	public static function render() {
		global $wpdb;
		$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['members', 'codes', 'payments', 'settings'], true) ? $_GET['tab'] : 'members';
		$s = self::settings();
		$now = time();
		$t = self::table();
		$vips = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", self::UNTIL, $now));
		$soon = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) BETWEEN %d AND %d", self::UNTIL, $now, $now + 7 * DAY_IN_SECONDS));
		$unused = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE used_by = 0");
		$used30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE used_at > %d", $now - 30 * DAY_IN_SECONDS));
		$notes = [
			'given'   => sprintf(__('%1$s days of VIP given to @%2$s.', 'y4apk'), absint($_GET['n'] ?? 0), sanitize_user(wp_unslash($_GET['who'] ?? ''))),
			'taken'   => sprintf(__('VIP ended for @%s.', 'y4apk'), sanitize_user(wp_unslash($_GET['who'] ?? ''))),
			'nouser'  => __('No member with that name or email.', 'y4apk'),
			'nodays'  => __('Choose how many days.', 'y4apk'),
			'made'    => sprintf(__('%1$s codes made in “%2$s”. Download them below.', 'y4apk'), number_format_i18n(absint($_GET['n'] ?? 0)), sanitize_text_field(wp_unslash($_GET['batch'] ?? ''))),
			'dropped' => sprintf(__('%s unused codes deleted.', 'y4apk'), number_format_i18n(absint($_GET['n'] ?? 0))),
			'saved'   => __('Saved.', 'y4apk') . (function_exists('y4apk_cache_note') ? ' ' . y4apk_cache_note() : ''),
			'paytestok'  => __('NOWPayments answers and accepts the API key. People can pay on the VIP page.', 'y4apk'),
			'paytestbad' => __('NOWPayments did not answer. Try again in a minute.', 'y4apk'),
			'paykeybad'  => __('NOWPayments answers, but the API key is not accepted. Copy it again from NOWPayments ▸ Store settings ▸ API keys.', 'y4apk'),
		];
		$note = isset($_GET['note']) ? sanitize_key($_GET['note']) : '';
		$days_select = function ($name = 'days') use ($s) {
			echo '<select name="' . esc_attr($name) . '" data-vip-days>';
			foreach ($s['plans'] as $p) {
				echo '<option value="' . (int) $p['days'] . '">' . esc_html(self::plan_label($p['days'])) . '</option>';
			}
			echo '<option value="other">' . esc_html__('Other…', 'y4apk') . '</option></select> ';
			echo '<input type="number" name="other" min="1" max="3650" placeholder="' . esc_attr__('days', 'y4apk') . '" style="width:90px" hidden data-vip-other>';
		};
		$form = function ($do, $tab) {
			echo '<input type="hidden" name="action" value="y4apk_vip"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
			wp_nonce_field('y4apk_vip_admin');
		};
		?>
		<div class="wrap y4ui y4vip">
			<?php Y4APK_Admin_UI::head([
				'icon'    => 'crown',
				'tint'    => 'amber',
				'title'   => esc_html__('VIP', 'y4apk'),
				'sub'     => esc_html__('Members who pay go without ads and without the download countdown, and get a VIP badge. Give days by hand, or make redeem codes to sell or give away anywhere: members type them on their VIP page.', 'y4apk'),
				'actions' => '<a class="button button-large" href="' . esc_url(Y4APK_Members::url('vip')) . '" target="_blank">' . esc_html__('View the VIP page', 'y4apk') . '</a>',
			]); ?>
			<?php if ($note && isset($notes[$note])) : ?>
				<div class="notice <?php echo in_array($note, ['nouser', 'nodays', 'paytestbad', 'paykeybad'], true) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div>
			<?php endif; ?>

			<div class="y4ui-kpis">
				<a class="y4ui-kpi" href="<?php echo esc_url(self::admin_url(['tab' => 'members'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('VIP now', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($vips)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('members whose VIP has not ended', 'y4apk'); ?></span></a>
				<a class="y4ui-kpi<?php echo $soon ? ' is-red' : ''; ?>" href="<?php echo esc_url(self::admin_url(['tab' => 'members'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Ending within 7 days', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($soon)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('a chance to offer more days', 'y4apk'); ?></span></a>
				<a class="y4ui-kpi" href="<?php echo esc_url(self::admin_url(['tab' => 'codes'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Unused codes', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($unused)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('ready to sell or give away', 'y4apk'); ?></span></a>
				<a class="y4ui-kpi is-green" href="<?php echo esc_url(self::admin_url(['tab' => 'codes'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Codes used, 30 days', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($used30)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('redeemed by members', 'y4apk'); ?></span></a>
			</div>

			<div class="y4ui-bar-row">
				<nav class="y4ui-tabs">
					<?php foreach (['members' => __('Members', 'y4apk'), 'codes' => __('Codes', 'y4apk'), 'payments' => __('Payments', 'y4apk'), 'settings' => __('Plans and settings', 'y4apk')] as $k => $label) : ?>
						<a href="<?php echo esc_url(self::admin_url(['tab' => $k])); ?>" class="<?php echo $tab === $k ? 'is-on' : ''; ?>"><?php echo esc_html($label); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>

			<?php if ($tab === 'members') :
				$paged = max(1, absint($_GET['paged'] ?? 1));
				$q = new WP_User_Query(['meta_key' => self::UNTIL, 'orderby' => 'meta_value_num', 'order' => 'ASC', 'number' => 50, 'paged' => $paged, 'count_total' => true,
					'meta_query' => [['key' => self::UNTIL, 'value' => $now, 'compare' => '>', 'type' => 'NUMERIC']]]);
				?>
				<div class="y4ui-cols">
					<div class="y4ui-card">
						<div class="y4ui-card-h"><div><h2><?php esc_html_e('VIP members', 'y4apk'); ?></h2><p><?php esc_html_e('Ending soonest first.', 'y4apk'); ?></p></div></div>
						<?php if (!$q->get_results()) : ?>
							<div class="y4ui-empty"><?php esc_html_e('No VIP members yet.', 'y4apk'); ?></div>
						<?php else : ?>
						<div class="y4ui-scroll"><table class="y4ui-table">
							<thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('VIP until', 'y4apk'); ?></th><th><?php esc_html_e('Last added', 'y4apk'); ?></th><th></th></tr></thead>
							<tbody>
							<?php foreach ($q->get_results() as $u) :
								$until = self::until($u->ID);
								$left = (int) ceil(($until - $now) / DAY_IN_SECONDS);
								$last = self::log($u->ID);
								$last = $last ? end($last) : null;
								?>
								<tr>
									<td><a href="<?php echo esc_url(Y4APK_Members::profile_url($u)); ?>" target="_blank"><b><?php echo esc_html(class_exists('Y4APK_Social') ? Y4APK_Social::name($u) : $u->display_name); ?></b></a><br><span class="y4ui-chip">@<?php echo esc_html($u->user_login); ?></span></td>
									<td><?php echo esc_html(wp_date(get_option('date_format'), $until)); ?><br><span class="y4ui-chip <?php echo $left <= 7 ? 'is-amber' : 'is-green'; ?>"><?php echo esc_html(sprintf(_n('%s day left', '%s days left', $left, 'y4apk'), number_format_i18n($left))); ?></span></td>
									<td><?php echo $last ? esc_html(self::how_text($last) . ' · +' . (int) $last['days'] . ' · ' . wp_date(get_option('date_format'), (int) $last['at'])) : '—'; ?></td>
									<td style="text-align:right"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('End this member’s VIP now?', 'y4apk'))); ?>)"><?php $form('take', 'members'); ?><input type="hidden" name="user" value="<?php echo (int) $u->ID; ?>"><button class="button"><?php esc_html_e('End VIP', 'y4apk'); ?></button></form></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table></div>
						<?php $pages = (int) ceil($q->get_total() / 50); if ($pages > 1) : ?>
							<p style="padding:0 20px 16px"><?php for ($i = 1; $i <= $pages; $i++) : ?><a class="button<?php echo $i === $paged ? ' button-primary' : ''; ?>" href="<?php echo esc_url(self::admin_url(['tab' => 'members', 'paged' => $i])); ?>"><?php echo (int) $i; ?></a> <?php endfor; ?></p>
						<?php endif; endif; ?>
					</div>
					<div class="y4ui-side">
						<div class="y4ui-card">
							<div class="y4ui-card-h"><div><h2><?php esc_html_e('Give VIP', 'y4apk'); ?></h2><p><?php esc_html_e('Added to the end of what the member has.', 'y4apk'); ?></p></div></div>
							<form class="y4ui-card-b y4ui-fields" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<?php $form('give', 'members'); ?>
								<div class="y4ui-field"><label for="vip-who"><?php esc_html_e('Member', 'y4apk'); ?></label><input type="text" id="vip-who" name="who" placeholder="@username <?php esc_attr_e('or email', 'y4apk'); ?>" required></div>
								<div class="y4ui-field"><label><?php esc_html_e('How long', 'y4apk'); ?></label><?php $days_select(); ?></div>
								<div><button class="button button-primary button-large"><?php esc_html_e('Give VIP', 'y4apk'); ?></button></div>
							</form>
						</div>
					</div>
				</div>

			<?php elseif ($tab === 'codes') :
				$batches = $wpdb->get_results("SELECT batch, MIN(days) AS days, MIN(made) AS made, COUNT(*) AS n, SUM(used_by > 0) AS used FROM $t GROUP BY batch ORDER BY made DESC LIMIT 200");
				?>
				<div class="y4ui-cols">
					<div class="y4ui-card">
						<div class="y4ui-card-h"><div><h2><?php esc_html_e('Batches of codes', 'y4apk'); ?></h2><p><?php esc_html_e('Download a batch’s unused codes to sell or give them; each works once.', 'y4apk'); ?></p></div></div>
						<?php if (!$batches) : ?>
							<div class="y4ui-empty"><?php esc_html_e('No codes yet. Make the first batch on the right.', 'y4apk'); ?></div>
						<?php else : ?>
						<div class="y4ui-scroll"><table class="y4ui-table">
							<thead><tr><th><?php esc_html_e('Batch', 'y4apk'); ?></th><th><?php esc_html_e('Worth', 'y4apk'); ?></th><th><?php esc_html_e('Used', 'y4apk'); ?></th><th></th></tr></thead>
							<tbody>
							<?php foreach ($batches as $b) :
								$csv = wp_nonce_url(add_query_arg(['action' => 'y4apk_vip_csv', 'batch' => $b->batch], admin_url('admin-post.php')), 'y4apk_vip_csv');
								?>
								<tr>
									<td><b><?php echo esc_html($b->batch !== '' ? $b->batch : '—'); ?></b><br><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $b->made)); ?></span></td>
									<td><span class="y4ui-chip is-blue"><?php echo esc_html(self::plan_label((int) $b->days)); ?></span></td>
									<td><?php echo esc_html(number_format_i18n((int) $b->used) . ' / ' . number_format_i18n((int) $b->n)); ?><span class="y4ui-bar is-green" style="margin-top:6px;width:120px"><i style="width:<?php echo esc_attr((int) round(100 * (int) $b->used / max(1, (int) $b->n))); ?>%"></i></span></td>
									<td style="text-align:right;white-space:nowrap">
										<?php if ((int) $b->used < (int) $b->n) : ?><a class="button button-primary" href="<?php echo esc_url($csv); ?>"><?php esc_html_e('Unused codes (CSV)', 'y4apk'); ?></a><?php endif; ?>
										<a class="button" href="<?php echo esc_url(add_query_arg('all', 1, $csv)); ?>"><?php esc_html_e('All', 'y4apk'); ?></a>
										<?php if ((int) $b->used < (int) $b->n) : ?>
										<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this batch’s unused codes? People who have one can no longer use it.', 'y4apk'))); ?>)"><?php $form('drop', 'codes'); ?><input type="hidden" name="batch" value="<?php echo esc_attr($b->batch); ?>"><button class="button-link button-link-delete"><?php esc_html_e('Delete unused', 'y4apk'); ?></button></form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table></div>
						<?php endif; ?>
					</div>
					<div class="y4ui-side">
						<div class="y4ui-card">
							<div class="y4ui-card-h"><div><h2><?php esc_html_e('Make codes', 'y4apk'); ?></h2><p><?php esc_html_e('Like ABCD-EFGH-JKLM-NPQR, no letters that look alike.', 'y4apk'); ?></p></div></div>
							<form class="y4ui-card-b y4ui-fields" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<?php $form('make', 'codes'); ?>
								<div class="y4ui-field"><label><?php esc_html_e('Each code is worth', 'y4apk'); ?></label><?php $days_select(); ?></div>
								<div class="y4ui-field"><label for="vip-count"><?php esc_html_e('How many', 'y4apk'); ?></label><input type="number" id="vip-count" name="count" min="1" max="1000" value="10" style="width:120px"></div>
								<div class="y4ui-field"><label for="vip-batch"><?php esc_html_e('Batch name (optional)', 'y4apk'); ?></label><input type="text" id="vip-batch" name="batch" maxlength="60" placeholder="<?php esc_attr_e('e.g. Telegram giveaway', 'y4apk'); ?>"></div>
								<div><button class="button button-primary button-large"><?php esc_html_e('Make codes', 'y4apk'); ?></button></div>
							</form>
						</div>
					</div>
				</div>

			<?php elseif ($tab === 'payments') :
				$ot = class_exists('Y4APK_Pay') ? Y4APK_Pay::table() : '';
				$since = $now - 30 * DAY_IN_SECONDS;
				$sums = $ot ? $wpdb->get_results($wpdb->prepare("SELECT currency, SUM(amount) AS s, COUNT(*) AS n FROM $ot WHERE status = 'finished' AND made > %d GROUP BY currency", $since)) : [];
				$waiting = $ot ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $ot WHERE status IN ('waiting', 'confirming', 'confirmed', 'sending')") : 0;
				$trouble = $ot ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $ot WHERE status IN ('partially_paid', 'mismatch', 'failed')") : 0;
				$show = isset($_GET['st']) ? sanitize_key($_GET['st']) : '';
				$where = ['paid' => "status = 'finished'", 'wait' => "status IN ('waiting', 'confirming', 'confirmed', 'sending')", 'trouble' => "status IN ('partially_paid', 'mismatch', 'failed')"][$show] ?? "status <> 'new'";
				$orders = $ot ? $wpdb->get_results("SELECT * FROM $ot WHERE $where ORDER BY id DESC LIMIT 100") : [];
				$money = function ($rows) {
					return $rows ? implode(' + ', array_map(function ($r) { $sym = self::currencies()[$r->currency] ?? ''; return $sym . number_format_i18n((float) $r->s, 2); }, $rows)) : '0';
				};
				?>
				<?php if (!class_exists('Y4APK_Pay') || !Y4APK_Pay::ready()) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e('Paying on the site is off: add the NOWPayments keys in Plans and settings.', 'y4apk'); ?> <a href="<?php echo esc_url(self::admin_url(['tab' => 'settings'])); ?>"><?php esc_html_e('Open the settings', 'y4apk'); ?></a></p></div>
				<?php endif; ?>
				<div class="y4ui-kpis">
					<a class="y4ui-kpi is-green" href="<?php echo esc_url(self::admin_url(['tab' => 'payments', 'st' => 'paid'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Paid, 30 days', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html($money($sums)); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(_n('%s order', '%s orders', array_sum(wp_list_pluck($sums, 'n')), 'y4apk'), number_format_i18n(array_sum(wp_list_pluck($sums, 'n'))))); ?></span></a>
					<a class="y4ui-kpi" href="<?php echo esc_url(self::admin_url(['tab' => 'payments', 'st' => 'wait'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Being paid', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($waiting)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('on the payment page or confirming', 'y4apk'); ?></span></a>
					<a class="y4ui-kpi<?php echo $trouble ? ' is-red' : ''; ?>" href="<?php echo esc_url(self::admin_url(['tab' => 'payments', 'st' => 'trouble'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Need a look', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($trouble)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('paid less, wrong amount, failed', 'y4apk'); ?></span></a>
				</div>
				<div class="y4ui-card">
					<div class="y4ui-card-h"><div><h2><?php esc_html_e('Orders', 'y4apk'); ?></h2><p><?php esc_html_e('Paid ones are given by themselves: VIP on the account, or a gift code. “Paid less” is not given: settle it in NOWPayments, then give the days on Members.', 'y4apk'); ?></p></div></div>
					<?php if (!$orders) : ?>
						<div class="y4ui-empty"><?php esc_html_e('No orders yet.', 'y4apk'); ?></div>
					<?php else : ?>
					<div class="y4ui-scroll"><table class="y4ui-table">
						<thead><tr><th>#</th><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('Plan', 'y4apk'); ?></th><th><?php esc_html_e('Price', 'y4apk'); ?></th><th><?php esc_html_e('State', 'y4apk'); ?></th><th><?php esc_html_e('Paid with', 'y4apk'); ?></th><th><?php esc_html_e('When', 'y4apk'); ?></th></tr></thead>
						<tbody>
						<?php foreach ($orders as $o) : $u = get_userdata((int) $o->user_id); $done = $o->status === 'finished'; $bad = in_array($o->status, ['partially_paid', 'mismatch', 'failed'], true); ?>
							<tr>
								<td><b><?php echo (int) $o->id; ?></b></td>
								<td><?php echo $u ? '<a href="' . esc_url(Y4APK_Members::profile_url($u)) . '" target="_blank">@' . esc_html($u->user_login) . '</a>' : '—'; ?></td>
								<td><?php echo esc_html(self::plan_label($o->days)); ?><?php if ($o->gift) : ?> <span class="y4ui-chip is-amber"><?php esc_html_e('gift', 'y4apk'); ?></span><?php if ($o->code !== '') : ?><br><span class="y4ui-chip is-code"><?php echo esc_html(self::pretty($o->code)); ?></span><?php endif; endif; ?></td>
								<td><?php echo esc_html((self::currencies()[$o->currency] ?? '') . number_format_i18n((float) $o->amount, 2)); ?></td>
								<td><span class="y4ui-chip <?php echo $done ? 'is-green' : ($bad ? 'is-red' : 'is-blue'); ?>"><?php echo esc_html(class_exists('Y4APK_Pay') ? Y4APK_Pay::status_text($o) : $o->status); ?></span></td>
								<td><?php echo esc_html($o->paid !== '' ? $o->paid : '—'); ?><?php if ($o->pay_id !== '') : ?><br><span class="y4ui-kpi-s">ID <?php echo esc_html($o->pay_id); ?></span><?php endif; ?></td>
								<td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $o->made)); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
					<?php endif; ?>
				</div>

			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="vip-settings">
					<?php $form('settings', 'settings'); ?>
					<div class="y4ui-cols">
						<div class="y4ui-card">
							<div class="y4ui-card-h"><div><h2><?php esc_html_e('Plans', 'y4apk'); ?></h2><p><?php esc_html_e('Shown on the VIP page, and the choices for codes. An old price shows crossed out with “Save N%”. Empty a row to drop it.', 'y4apk'); ?></p></div></div>
							<div class="y4ui-scroll"><table class="y4ui-table">
								<thead><tr><th><?php esc_html_e('Days', 'y4apk'); ?></th><th><?php esc_html_e('Price', 'y4apk'); ?></th><th><?php esc_html_e('Old price', 'y4apk'); ?></th><th><?php esc_html_e('Shows as', 'y4apk'); ?></th></tr></thead>
								<tbody>
								<?php $rows = $s['plans']; while (count($rows) < 4) { $rows[] = ['days' => '', 'price' => '', 'was' => '']; }
								foreach ($rows as $i => $p) : ?>
									<tr>
										<td><input type="number" name="plans[<?php echo (int) $i; ?>][days]" value="<?php echo esc_attr($p['days']); ?>" min="1" max="3650" style="width:90px"></td>
										<td><input type="text" name="plans[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr($p['price']); ?>" inputmode="decimal" style="width:100px"></td>
										<td><input type="text" name="plans[<?php echo (int) $i; ?>][was]" value="<?php echo esc_attr($p['was']); ?>" inputmode="decimal" style="width:100px"></td>
										<td><?php echo $p['days'] !== '' ? esc_html(self::plan_label($p['days']) . ' · ' . self::money($p['price'])) . ($p['was'] !== '' ? ' <span class="y4ui-chip is-amber">' . esc_html(self::saving($p)) . '</span>' : '') : '—'; ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table></div>
						</div>
						<div class="y4ui-side">
							<div class="y4ui-card">
								<div class="y4ui-card-h"><div><h2><?php esc_html_e('Getting VIP', 'y4apk'); ?></h2><p><?php esc_html_e('Payments are not built in yet: “Get VIP” opens your own place to buy (a shop, a Telegram account…), where people pay and get a code. Leave it empty and the page offers only the code box.', 'y4apk'); ?></p></div></div>
								<div class="y4ui-card-b y4ui-fields">
									<div class="y4ui-field"><label for="vip-cur"><?php esc_html_e('Currency', 'y4apk'); ?></label><select id="vip-cur" name="currency"><?php foreach (self::currencies() as $c => $sym) : ?><option value="<?php echo esc_attr($c); ?>" <?php selected($s['currency'], $c); ?>><?php echo esc_html($c . ' ' . $sym); ?></option><?php endforeach; ?></select></div>
									<div class="y4ui-field"><label for="vip-buy"><?php esc_html_e('Where to buy (address)', 'y4apk'); ?></label><input type="url" id="vip-buy" name="buy_url" value="<?php echo esc_attr($s['buy_url']); ?>" placeholder="https://t.me/…"></div>
									<div class="y4ui-field"><label for="vip-buy-l"><?php esc_html_e('Button text (optional)', 'y4apk'); ?></label><input type="text" id="vip-buy-l" name="buy_label" value="<?php echo esc_attr($s['buy_label']); ?>" maxlength="40" placeholder="<?php esc_attr_e('Get VIP', 'y4apk'); ?>"></div>
								</div>
							</div>
							<?php if (class_exists('Y4APK_Pay')) : $ps = Y4APK_Pay::settings(); $pay_on = Y4APK_Pay::ready(); ?>
							<div class="y4ui-card">
								<div class="y4ui-card-h"><div><h2><?php esc_html_e('Pay on the site: NOWPayments', 'y4apk'); ?> <span class="y4ui-chip <?php echo $pay_on ? 'is-green' : ''; ?>"><?php echo esc_html($pay_on ? __('On', 'y4apk') : __('Off', 'y4apk')); ?></span></h2><p><?php esc_html_e('Crypto from anywhere (USDT, BTC, ETH and 300 more), cards too once NOWPayments has checked your business. Paid orders give VIP or a gift code by themselves. Buyers see the site’s name, never yours.', 'y4apk'); ?></p></div></div>
								<div class="y4ui-card-b y4ui-fields">
									<ol style="margin:0 0 0 18px;line-height:1.7">
										<li><?php esc_html_e('Sign up at nowpayments.io and add the wallet your money goes to (e.g. USDT).', 'y4apk'); ?></li>
										<li><?php esc_html_e('Store settings ▸ API keys: make a key, paste it below.', 'y4apk'); ?></li>
										<li><?php esc_html_e('Store settings ▸ Instant payment notifications: make the IPN secret, paste it below, and put this address as the IPN callback URL:', 'y4apk'); ?><br><code style="word-break:break-all"><?php echo esc_html(Y4APK_Pay::ipn_url()); ?></code></li>
									</ol>
									<?php if (Y4APK_Pay::in_config()) : ?>
										<p class="description"><?php esc_html_e('The keys come from wp-config.php (Y4APK_NOWPAYMENTS_KEY, Y4APK_NOWPAYMENTS_IPN).', 'y4apk'); ?></p>
									<?php else : ?>
									<div class="y4ui-field"><label for="np-key"><?php esc_html_e('API key', 'y4apk'); ?></label><input type="text" id="np-key" name="np_key" value="" class="y4vip-secret" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore placeholder="<?php echo esc_attr($ps['np_key'] !== '' ? __('Saved: leave empty to keep it, “-” to remove', 'y4apk') : __('Paste it here', 'y4apk')); ?>"><?php if (Y4APK_Pay::tail('np_key') !== '') : ?><p class="description">✓ <?php echo esc_html(sprintf(__('Saved: ends with %s', 'y4apk'), Y4APK_Pay::tail('np_key'))); ?></p><?php endif; ?></div>
									<div class="y4ui-field"><label for="np-ipn"><?php esc_html_e('IPN secret', 'y4apk'); ?></label><input type="text" id="np-ipn" name="np_ipn" value="" class="y4vip-secret" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore placeholder="<?php echo esc_attr($ps['np_ipn'] !== '' ? __('Saved: leave empty to keep it, “-” to remove', 'y4apk') : __('Paste it here', 'y4apk')); ?>"><?php if (Y4APK_Pay::tail('np_ipn') !== '') : ?><p class="description">✓ <?php echo esc_html(sprintf(__('Saved: ends with %s', 'y4apk'), Y4APK_Pay::tail('np_ipn'))); ?></p><?php endif; ?></div>
									<p class="description"><?php esc_html_e('Kept encrypted. Or put them in wp-config.php as Y4APK_NOWPAYMENTS_KEY and Y4APK_NOWPAYMENTS_IPN.', 'y4apk'); ?></p>
									<?php endif; ?>
									<label class="y4ui-opt"><input type="checkbox" name="np_sandbox" value="1" <?php checked(!empty($ps['np_sandbox'])); ?>><span><b><?php esc_html_e('Sandbox (test payments)', 'y4apk'); ?></b><small><?php esc_html_e('With keys from NOWPayments’ sandbox account: no real money moves.', 'y4apk'); ?></small></span></label>
									<?php if ($pay_on) : ?><p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_pay_test'), 'y4apk_pay_test')); ?>"><?php esc_html_e('Test the connection', 'y4apk'); ?></a></p><?php endif; ?>
								</div>
							</div>
							<?php endif; ?>
							<div class="y4ui-card">
								<div class="y4ui-card-h"><div><h2><?php esc_html_e('Ads from plugins', 'y4apk'); ?></h2></div></div>
								<div class="y4ui-card-b"><p><?php esc_html_e('The theme’s ad boxes and Direct Link skip VIPs by themselves. For ad code you put in with a plugin (WPCode, Ad Inserter…), add the condition “User role is not VIP” to each snippet: a VIP account has the role VIP while it lasts.', 'y4apk'); ?></p></div>
							</div>
						</div>
					</div>
					<div class="y4ui-savebar"><span class="y4ui-hint"><?php esc_html_e('Prices are only shown: what people pay is set where they buy.', 'y4apk'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
				</form>
			<?php endif; ?>
		</div>
		<style>.y4vip-secret{width:100%;-webkit-text-security:disc;font-family:ui-monospace,Menlo,Consolas,monospace}.y4vip-secret:placeholder-shown{-webkit-text-security:none;font-family:inherit}</style>
		<script>
		document.querySelectorAll('[data-vip-days]').forEach(function (s) {
			var other = s.parentNode.querySelector('[data-vip-other]');
			s.addEventListener('change', function () { other.hidden = s.value !== 'other'; if (!other.hidden) { other.focus(); } });
		});
		</script>
		<?php
	}
}

/** For templates: is the visitor (or that member) VIP now? */
function y4apk_is_vip($user_id = 0) {
	return Y4APK_VIP::is_vip($user_id);
}

/** Ads on this page? Not for a VIP. */
function y4apk_ads_on() {
	return !Y4APK_VIP::is_vip();
}

Y4APK_VIP::init();
