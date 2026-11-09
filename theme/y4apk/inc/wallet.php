<?php
/**
 * The wallet and the creator program (2.64).
 *
 *   Balance   Money a member put in (Top up, paid like VIP: crypto on the
 *             payment company's page) or the site gave. Spent on the site:
 *             VIP, tips. Never paid out.
 *   Earnings  What a creator made: tips from members (less the site's share)
 *             and downloads of the files they shared (a rate per 1,000).
 *             Spent on the site like a balance, or withdrawn: the creator asks,
 *             the owner pays by hand (crypto, PayPal, TrueMoney…) and marks it
 *             paid. The owner's name is never on the site.
 *
 * Small amounts work: a $0.50 tip or a $2.99 VIP comes out of the balance, so
 * the payment company's smallest payment only applies to topping up (or to
 * paying a tip straight away, when it is big enough).
 *
 * Creators are members with enough followers (300 by default), or ones the
 * owner lets in by hand. A download counts once a day per device: what is kept
 * to tell devices apart is a one-way fingerprint, made with a key that changes
 * every day and is thrown away after it (no IP address is stored), and the
 * fingerprints themselves are deleted after two days. Each day's downloads go
 * into the creator's earnings the day after.
 *
 * Money is kept in millionths (micro units) of the VIP page's currency, in
 * BIGINT columns: a download worth $0.0005 adds up exactly.
 */

defined('ABSPATH') || exit;

class Y4APK_Wallet {

	const OPTION   = 'y4apk_wallet';
	const DBV      = '1';
	const SLUG     = 'y4apk-wallet';
	const M        = 1000000;              // micro units in one dollar (or one of the currency)
	const MODE     = 'y4apk_creator';      // usermeta: '' (by followers), 'on' (let in), 'off' (kept out)
	const PAY_TO   = 'y4apk_payout_to';    // usermeta: the last withdrawal's method and details, encrypted
	const CRON     = 'y4apk_wallet_daily';
	const DL_CAP   = 40;                   // downloads one device counts a day, across all posts
	const BOTS     = '/bot|crawl|spider|slurp|headless|phantom|curl|wget|python|java\\/|httpclient|okhttp|go-http|preview|monitor/i';

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action(self::CRON, [__CLASS__, 'daily']);
		add_action('rest_api_init', [__CLASS__, 'rest']);
		add_action('wp_head', [__CLASS__, 'dl_script'], 30);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 30);
			add_action('admin_post_y4apk_wallet', [__CLASS__, 'handle_admin']);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Tables
	 * ---------------------------------------------------------------- */

	public static function t($name) {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_' . $name;
	}

	public static function setup() {
		if (!wp_next_scheduled(self::CRON)) {
			wp_schedule_event(strtotime('tomorrow 00:20') ?: time() + HOUR_IN_SECONDS, 'daily', self::CRON);
		}
		if (get_option('y4apk_wallet_db') === self::DBV) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		dbDelta('CREATE TABLE ' . self::t('wallets') . " (
			user_id bigint(20) unsigned NOT NULL,
			credit bigint(20) NOT NULL DEFAULT 0,
			earned bigint(20) NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (user_id)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('ledger') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(16) NOT NULL DEFAULT '',
			credit bigint(20) NOT NULL DEFAULT 0,
			earned bigint(20) NOT NULL DEFAULT 0,
			other_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ref varchar(64) NOT NULL DEFAULT '',
			note varchar(190) NOT NULL DEFAULT '',
			made int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY kind_made (kind,made)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('tips') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			from_id bigint(20) unsigned NOT NULL DEFAULT 0,
			to_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount bigint(20) NOT NULL DEFAULT 0,
			net bigint(20) NOT NULL DEFAULT 0,
			name varchar(40) NOT NULL DEFAULT '',
			msg varchar(160) NOT NULL DEFAULT '',
			via varchar(10) NOT NULL DEFAULT 'wallet',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hidden tinyint(1) unsigned NOT NULL DEFAULT 0,
			made int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY to_id (to_id),
			KEY made (made)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('payouts') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount bigint(20) NOT NULL DEFAULT 0,
			method varchar(20) NOT NULL DEFAULT '',
			details text,
			status varchar(12) NOT NULL DEFAULT 'requested',
			note varchar(190) NOT NULL DEFAULT '',
			made int(10) unsigned NOT NULL DEFAULT 0,
			done int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('dl_seen') . " (
			fp char(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			day date NOT NULL,
			PRIMARY KEY  (fp,post_id,day),
			KEY day (day)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('dl_count') . " (
			day date NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			n int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,post_id),
			KEY user_day (user_id,day)
		) $cs;");
		update_option('y4apk_wallet_db', self::DBV, false);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'on'         => 1,
			'topups'     => '5, 10, 20, 50, 100',
			'min_topup'  => '10',     // the smallest payment the payment company takes, about
			'bonus_from' => '0',
			'bonus_pct'  => '0',
			'site_tips'  => '1, 2, 5, 10, 20, 50',
			'tips'       => '0.5, 1, 2, 5, 10',
			'min_tip'    => '0.5',
			'share'      => '20',     // % of a tip to a creator the site keeps
			'followers'  => '300',
			'dl_rate'    => '0',      // per 1,000 downloads; 0: downloads earn nothing
			'min_payout' => '10',
			'methods'    => ['usdttrc20', 'usdtbsc', 'binance', 'paypal', 'truemoney', 'promptpay', 'giftcard'],
		];
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], self::defaults());
	}

	public static function on() {
		return (bool) self::settings()['on'];
	}

	/** Ways a creator can be paid: key => [name, what they type]. */
	public static function methods() {
		return [
			'usdttrc20' => [__('USDT (TRC20)', 'y4apk'), __('Wallet address (TRC20 network)', 'y4apk')],
			'usdtbsc'   => [__('USDT (BEP20)', 'y4apk'), __('Wallet address (BEP20 / BSC network)', 'y4apk')],
			'binance'   => [__('Binance Pay', 'y4apk'), __('Binance Pay ID or email', 'y4apk')],
			'paypal'    => [__('PayPal', 'y4apk'), __('PayPal email', 'y4apk')],
			'truemoney' => [__('TrueMoney Wallet', 'y4apk'), __('Phone number', 'y4apk')],
			'promptpay' => [__('PromptPay', 'y4apk'), __('PromptPay number', 'y4apk')],
			'giftcard'  => [__('Gift card (Google Play)', 'y4apk'), __('Email to send the code to', 'y4apk')],
		];
	}

	public static function methods_on() {
		return array_intersect_key(self::methods(), array_flip((array) self::settings()['methods']));
	}

	/** "1, 2, 5" → [1.0, 2.0, 5.0], positive, sorted, at most 8. */
	public static function amounts($list) {
		$out = [];
		foreach (preg_split('/[\s,;]+/', (string) $list) as $x) {
			$x = (float) preg_replace('/[^0-9.]/', '', $x);
			if ($x > 0 && $x <= 10000) {
				$out[] = round($x, 2);
			}
		}
		$out = array_values(array_unique($out, SORT_REGULAR));
		sort($out);
		return array_slice($out, 0, 8);
	}

	public static function save_settings($in) {
		$num = function ($k, $max) use ($in) {
			$v = (float) preg_replace('/[^0-9.]/', '', (string) ($in[$k] ?? '0'));
			return (string) round(max(0, min($max, $v)), 4);
		};
		$o = [
			'on'         => empty($in['on']) ? 0 : 1,
			'topups'     => implode(', ', self::amounts($in['topups'] ?? '')),
			'min_topup'  => $num('min_topup', 1000),
			'bonus_from' => $num('bonus_from', 10000),
			'bonus_pct'  => $num('bonus_pct', 50),
			'site_tips'  => implode(', ', self::amounts($in['site_tips'] ?? '')),
			'tips'       => implode(', ', self::amounts($in['tips'] ?? '')),
			'min_tip'    => $num('min_tip', 100),
			'share'      => $num('share', 90),
			'followers'  => (string) absint($in['followers'] ?? 300),
			'dl_rate'    => $num('dl_rate', 100),
			'min_payout' => $num('min_payout', 10000),
			'methods'    => array_values(array_intersect(array_keys(self::methods()), array_map('sanitize_key', (array) ($in['methods'] ?? [])))),
		];
		update_option(self::OPTION, $o, false);
	}

	/* ---------------------------------------------------------------- *
	 * Money
	 * ---------------------------------------------------------------- */

	/** 2.5 → 2500000 */
	public static function to_m($amount) {
		return (int) round((float) $amount * self::M);
	}

	/** 2500000 → "$2.50"; amounts under a cent keep 4 decimals ("$0.0040"). */
	public static function fmt($m) {
		$m = (int) $m;
		$n = abs($m) / self::M;
		$sign = $m < 0 ? '−' : '';
		if ($m !== 0 && $n < 0.01) {
			$cur = Y4APK_VIP::settings()['currency'];
			return $sign . (Y4APK_VIP::currencies()[$cur] ?? '$') . number_format_i18n($n, 4);
		}
		return $sign . Y4APK_VIP::money(intdiv(abs($m), self::M / 100) / 100); // whole cents, rounded down, in integers (no float drift)
	}

	/** The money as a number with 2 decimals, for a form ("2.50"), rounded down to the cent. */
	public static function plain($m) {
		$c = intdiv(max(0, (int) $m), self::M / 100);
		return intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
	}

	/** One member's money: ['credit' => micro, 'earned' => micro, 'total' => micro]. */
	public static function balance($user_id) {
		global $wpdb;
		$r = $wpdb->get_row($wpdb->prepare('SELECT credit, earned FROM ' . self::t('wallets') . ' WHERE user_id = %d', (int) $user_id));
		$c = $r ? (int) $r->credit : 0;
		$e = $r ? (int) $r->earned : 0;
		return ['credit' => $c, 'earned' => $e, 'total' => $c + $e];
	}

	/** Adds to (or, negative, takes from) a member's balance and earnings, with a line in their history. */
	private static function add($user_id, $credit, $earned, $kind, $ref = '', $other = 0, $note = '') {
		global $wpdb;
		$wpdb->query($wpdb->prepare('INSERT INTO ' . self::t('wallets') . ' (user_id, credit, earned, updated) VALUES (%d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE credit = credit + VALUES(credit), earned = earned + VALUES(earned), updated = VALUES(updated)',
			(int) $user_id, (int) $credit, (int) $earned, time()));
		self::log($user_id, $kind, $credit, $earned, $ref, $other, $note);
	}

	private static function log($user_id, $kind, $credit, $earned, $ref = '', $other = 0, $note = '') {
		global $wpdb;
		$wpdb->insert(self::t('ledger'), [
			'user_id' => (int) $user_id, 'kind' => $kind, 'credit' => (int) $credit, 'earned' => (int) $earned,
			'other_id' => (int) $other, 'ref' => substr((string) $ref, 0, 64), 'note' => mb_substr((string) $note, 0, 190), 'made' => time(),
		], ['%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d']);
	}

	/** Runs $fn in a database transaction: kept when it returns anything but false. */
	private static function tx($fn) {
		global $wpdb;
		$wpdb->query('START TRANSACTION');
		try {
			$r = $fn();
		} catch (Throwable $e) {
			$wpdb->query('ROLLBACK');
			throw $e;
		}
		$wpdb->query($r === false ? 'ROLLBACK' : 'COMMIT');
		return $r;
	}

	/**
	 * Takes $amount (micro) from a member: the balance first, then earnings
	 * ($earned_only: earnings alone, for a withdrawal). Inside tx(). The split
	 * taken, or false when there is not enough.
	 */
	private static function take($user_id, $amount, $kind, $ref = '', $other = 0, $note = '', $earned_only = false) {
		global $wpdb;
		$w = self::t('wallets');
		$r = $wpdb->get_row($wpdb->prepare("SELECT credit, earned FROM $w WHERE user_id = %d FOR UPDATE", (int) $user_id));
		if (!$r || $amount <= 0) {
			return false;
		}
		$have_c = max(0, (int) $r->credit);
		$have_e = max(0, (int) $r->earned);
		$c = $earned_only ? 0 : min($have_c, $amount);
		$e = $amount - $c;
		if ($e > $have_e) {
			return false;
		}
		// The WHERE holds even without the lock: never below zero.
		$n = $wpdb->query($wpdb->prepare("UPDATE $w SET credit = credit - %d, earned = earned - %d, updated = %d WHERE user_id = %d AND credit >= %d AND earned >= %d",
			$c, $e, time(), (int) $user_id, $c, $e));
		if ($n !== 1) {
			return false;
		}
		self::log($user_id, $kind, -$c, -$e, $ref, $other, $note);
		return ['credit' => $c, 'earned' => $e];
	}

	/** A member's history, newest first. */
	public static function history($user_id, $limit = 40) {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('ledger') . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', (int) $user_id, (int) $limit));
	}

	/** What a history line says. */
	public static function label($row) {
		$who = function ($id) {
			$u = $id ? get_userdata((int) $id) : null;
			return $u ? '@' . $u->user_login : __('a member', 'y4apk');
		};
		switch ($row->kind) {
			case 'topup':
				return sprintf(__('Top-up · order #%s', 'y4apk'), $row->ref);
			case 'bonus':
				return __('Top-up bonus', 'y4apk');
			case 'vip':
				return $row->note === 'gift' ? sprintf(__('VIP gift code · %s', 'y4apk'), Y4APK_VIP::plan_label((int) $row->ref)) : sprintf(__('VIP · %s', 'y4apk'), Y4APK_VIP::plan_label((int) $row->ref));
			case 'tip_out':
				return (int) $row->other_id ? sprintf(__('Tip to %s', 'y4apk'), $who($row->other_id)) : sprintf(__('Tip to %s', 'y4apk'), self::site_name());
			case 'tip_in':
				return sprintf(__('Tip from %s', 'y4apk'), (int) $row->other_id ? $who($row->other_id) : __('a supporter', 'y4apk'));
			case 'downloads':
				return sprintf(_n('%1$s download on %2$s', '%1$s downloads on %2$s', (int) $row->note, 'y4apk'), number_format_i18n((int) $row->note), mysql2date(get_option('date_format'), $row->ref));
			case 'payout':
				return sprintf(__('Withdrawal #%s', 'y4apk'), $row->ref);
			case 'payout_back':
				return sprintf(__('Withdrawal #%s returned', 'y4apk'), $row->ref) . ($row->note !== '' ? ': ' . $row->note : '');
			case 'adjust':
				return __('From the site', 'y4apk') . ($row->note !== '' ? ': ' . $row->note : '');
		}
		return $row->kind;
	}

	public static function site_name() {
		$n = class_exists('Y4APK_Members') ? (string) (Y4APK_Members::settings()['from_name'] ?? '') : '';
		return $n !== '' ? $n : get_bloginfo('name');
	}

	/* ---------------------------------------------------------------- *
	 * Creators
	 * ---------------------------------------------------------------- */

	/** ['ok' => may earn, 'followers', 'need', 'mode' => '' | 'on' | 'off']. */
	public static function creator($user_id) {
		static $memo = [];
		$user_id = (int) $user_id;
		if (isset($memo[$user_id])) {
			return $memo[$user_id];
		}
		$u = $user_id ? get_userdata($user_id) : null;
		$mode = (string) get_user_meta($user_id, self::MODE, true);
		$f = class_exists('Y4APK_Social') && $u ? Y4APK_Social::followers_count($user_id) : 0;
		$need = max(0, (int) self::settings()['followers']);
		$ok = $u && Y4APK_Members::is_member($u) && $mode !== 'off' && ($mode === 'on' || $f >= $need);
		return $memo[$user_id] = ['ok' => (bool) $ok, 'followers' => $f, 'need' => $need, 'mode' => $mode];
	}

	/** May earn (kept 10 minutes: asked on every counted download). */
	public static function creator_ok($user_id) {
		$k = 'y4apk_cr_' . (int) $user_id;
		$v = get_transient($k);
		if ($v === false) {
			$v = self::creator($user_id)['ok'] ? 'y' : 'n';
			set_transient($k, $v, 10 * MINUTE_IN_SECONDS);
		}
		return $v === 'y';
	}

	/** The page to tip a creator (or the site, for no one). */
	public static function tip_url($user = null) {
		return self::url($user ? ['to' => $user->user_login] : ['to' => 'site']) . '#tip';
	}

	/* ---------------------------------------------------------------- *
	 * VIP from the balance
	 * ---------------------------------------------------------------- */

	/** ['ok' => true, 'code' => gift code or ''] or ['ok' => false, 'why' => message]. */
	public static function buy_vip($user_id, $plan, $gift) {
		$amount = self::to_m($plan['price']);
		$days = (int) $plan['days'];
		$taken = self::tx(function () use ($user_id, $amount, $days, $gift) {
			return self::take($user_id, $amount, 'vip', (string) $days, 0, $gift ? 'gift' : '');
		});
		if (!$taken) {
			return ['ok' => false, 'why' => __('Not enough in your balance. Top up first.', 'y4apk')];
		}
		$code = '';
		if ($gift) {
			$code = Y4APK_VIP::make_one($days, __('Paid from a balance', 'y4apk'));
		} else {
			Y4APK_VIP::extend($user_id, $days, 'pay', __('Balance', 'y4apk'));
		}
		return ['ok' => true, 'code' => $code];
	}

	/* ---------------------------------------------------------------- *
	 * Top-ups and tips paid on the payment company's page
	 * ---------------------------------------------------------------- */

	/** What a top-up of $amount (dollars) adds as a bonus (micro). */
	public static function bonus($amount) {
		$s = self::settings();
		return (float) $s['bonus_pct'] > 0 && (float) $amount >= (float) $s['bonus_from'] ? (int) floor(self::to_m($amount) * (float) $s['bonus_pct'] / 100) : 0;
	}

	/** Called by Y4APK_Pay::fulfil() once, when a top-up or a tip is paid. */
	public static function order_paid($o) {
		$user = get_userdata((int) $o->user_id);
		if ($o->kind === 'topup') {
			if (!$user) {
				return false;
			}
			$m = self::to_m($o->amount);
			$bonus = self::bonus($o->amount);
			self::add($user->ID, $m, 0, 'topup', (string) $o->id);
			if ($bonus > 0) {
				self::add($user->ID, $bonus, 0, 'bonus', (string) $o->id);
			}
			self::mail_member($user, __('Your top-up is in', 'y4apk'), [
				sprintf(__('%s has been added to your balance.', 'y4apk'), self::fmt($m + $bonus)),
				__('Use it for VIP or to tip creators: nothing else to pay.', 'y4apk'),
			], sprintf(__('You got this email because you topped up your balance (order #%s).', 'y4apk'), $o->id));
			return true;
		}
		if ($o->kind === 'tip') {
			$meta = Y4APK_Pay::meta($o);
			self::give_tip((int) $o->user_id, (int) ($meta['to'] ?? 0), self::to_m($o->amount), (string) ($meta['name'] ?? ''), (string) ($meta['msg'] ?? ''), 'crypto', (int) $o->id);
			return true;
		}
		return false;
	}

	/* ---------------------------------------------------------------- *
	 * Tips
	 * ---------------------------------------------------------------- */

	/**
	 * A tip out of the member's balance, to a creator or to the site ($to = 0).
	 * true, or a WP_Error that says why not.
	 */
	public static function tip($from, $to, $amount, $name, $msg) {
		$s = self::settings();
		if ($amount < self::to_m($s['min_tip']) || $amount > self::to_m(10000)) {
			return new WP_Error('y4apk_tip', sprintf(__('A tip is at least %s.', 'y4apk'), self::fmt(self::to_m($s['min_tip']))));
		}
		if ($to && ((int) $to === (int) $from || !self::creator($to)['ok'])) {
			return new WP_Error('y4apk_tip', __('This member cannot get tips.', 'y4apk'));
		}
		$ok = self::tx(function () use ($from, $to, $amount) {
			return self::take($from, $amount, 'tip_out', '', $to);
		});
		if (!$ok) {
			return new WP_Error('y4apk_tip', __('Not enough in your balance. Top up first.', 'y4apk'));
		}
		self::give_tip($from, $to, $amount, $name, $msg, 'wallet', 0);
		return true;
	}

	/** The receiving side of a tip (paid from a balance or on the payment page). */
	private static function give_tip($from, $to, $amount, $name, $msg, $via, $order_id) {
		global $wpdb;
		$share = (float) self::settings()['share'];
		$net = $to ? (int) floor($amount * (100 - $share) / 100) : 0;
		$wpdb->insert(self::t('tips'), [
			'from_id' => (int) $from, 'to_id' => (int) $to, 'amount' => (int) $amount, 'net' => $net,
			'name' => mb_substr(sanitize_text_field($name), 0, 40), 'msg' => mb_substr(sanitize_text_field($msg), 0, 160),
			'via' => $via, 'order_id' => (int) $order_id, 'made' => time(),
		], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d']);
		if ($to && $net > 0) {
			self::add($to, 0, $net, 'tip_in', (string) $wpdb->insert_id, $from);
			$creator = get_userdata($to);
			if ($creator) {
				$lines = [sprintf(__('%1$s sent you a tip of %2$s. %3$s is in your earnings.', 'y4apk'), $name !== '' ? $name : __('Someone', 'y4apk'), self::fmt($amount), self::fmt($net))];
				if ($msg !== '') {
					$lines[] = '“' . $msg . '”';
				}
				self::mail_member($creator, __('You got a tip', 'y4apk'), $lines, __('You got this email because you are a creator on the site.', 'y4apk'));
			}
		}
	}

	/** Tips to the site, newest first, for the supporters list. */
	public static function supporters($limit = 8) {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('tips') . ' WHERE to_id = 0 AND hidden = 0 ORDER BY id DESC LIMIT %d', (int) $limit));
	}

	/* ---------------------------------------------------------------- *
	 * Downloads that earn
	 * ---------------------------------------------------------------- */

	public static function dl_on() {
		$s = self::settings();
		return $s['on'] && (float) $s['dl_rate'] > 0;
	}

	public static function rest() {
		register_rest_route('y4apk/v1', '/dl', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'dl_hit'],
			'permission_callback' => '__return_true',
		]);
	}

	/** Today's key for the fingerprints: new every day, yesterday's thrown away. */
	private static function salt() {
		$day = wp_date('Y-m-d');
		$s = get_option('y4apk_dl_salt');
		if (!is_array($s) || ($s['day'] ?? '') !== $day) {
			$s = ['day' => $day, 'key' => wp_generate_password(48, true, true)];
			update_option('y4apk_dl_salt', $s, false);
		}
		return $s['key'];
	}

	/** A download button tapped: counted for the post's creator, once a day per device. */
	public static function dl_hit(WP_REST_Request $req) {
		global $wpdb;
		$none = new WP_REST_Response(null, 204);
		if (!self::dl_on()) {
			return $none;
		}
		$d = json_decode((string) $req->get_body(), true);
		$pid = is_array($d) ? absint($d['p'] ?? 0) : 0;
		$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
		if (!$pid || $ua === '' || preg_match(self::BOTS, $ua)) {
			return $none;
		}
		$post = get_post($pid);
		if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || !class_exists('Y4APK_Share') || !Y4APK_Share::is_share($pid)) {
			return $none;
		}
		$author = (int) $post->post_author;
		if (!$author || !self::creator_ok($author)) {
			return $none;
		}
		// Cloudflare hands the visitor's address on in its own header; it is only hashed here, never kept.
		$ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
		$fp = substr(hash_hmac('sha256', $ip . '|' . $ua, self::salt()), 0, 32);
		$day = wp_date('Y-m-d');
		$seen = self::t('dl_seen');
		if ((int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $seen (fp, post_id, day) VALUES (%s, %d, %s)", $fp, $pid, $day)) !== 1) {
			return $none; // this device, this post, today: already counted
		}
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $seen WHERE fp = %s AND day = %s", $fp, $day)) > self::DL_CAP) {
			return $none;
		}
		$wpdb->query($wpdb->prepare('INSERT INTO ' . self::t('dl_count') . ' (day, post_id, user_id, n) VALUES (%s, %d, %d, 1) ON DUPLICATE KEY UPDATE n = n + 1', $day, $pid, $author));
		return $none;
	}

	/** On a creator's post and its download pages: tell the site when a download button is tapped. */
	public static function dl_script() {
		if (!self::dl_on() || !is_singular('post') || !class_exists('Y4APK_Share')) {
			return;
		}
		$pid = (int) get_queried_object_id();
		if (!$pid || !Y4APK_Share::is_share($pid)) {
			return;
		}
		?>
<script id="y4apk-dl" data-cfasync="false" data-no-optimize="1">(function(){var u=<?php echo wp_json_encode(rest_url('y4apk/v1/dl')); ?>,b=JSON.stringify({p:<?php echo (int) $pid; ?>});document.addEventListener('click',function(e){if(!e.target.closest||!e.target.closest('#check,[data-y4-dl]'))return;try{if(!(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:'text/plain'}))))fetch(u,{method:'POST',body:b,keepalive:true});}catch(x){}},true);})();</script>
		<?php
	}

	/** Each day's downloads into the creators' earnings, the day after; old fingerprints out. */
	public static function daily() {
		self::settle();
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('dl_seen') . ' WHERE day < %s', wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS)));
	}

	public static function settle() {
		global $wpdb;
		$today = wp_date('Y-m-d');
		$last = (string) get_option('y4apk_dl_settled', '');
		if ($last >= wp_date('Y-m-d', time() - DAY_IN_SECONDS) && $last !== '') {
			return;
		}
		$lock = get_option('y4apk_dl_settling');
		if ($lock && (int) $lock > time() - 10 * MINUTE_IN_SECONDS) {
			return;
		}
		update_option('y4apk_dl_settling', time(), false);
		$rate = self::to_m(self::settings()['dl_rate']); // micro per 1,000
		$t = self::t('dl_count');
		$where = $last !== '' ? $wpdb->prepare('day > %s AND day < %s', $last, $today) : $wpdb->prepare('day < %s', $today);
		$rows = $wpdb->get_results("SELECT day, user_id, SUM(n) AS n FROM $t WHERE $where GROUP BY day, user_id ORDER BY day");
		foreach ($rows as $r) {
			$amount = intdiv((int) $r->n * $rate, 1000);
			if ($amount > 0 && (int) $r->user_id) {
				self::add((int) $r->user_id, 0, $amount, 'downloads', (string) $r->day, 0, (string) (int) $r->n);
			}
		}
		update_option('y4apk_dl_settled', wp_date('Y-m-d', time() - DAY_IN_SECONDS), false);
		delete_option('y4apk_dl_settling');
	}

	/** Downloads counted for a creator: today (paid tomorrow) and the last 30 days. */
	public static function dl_stats($user_id) {
		global $wpdb;
		$t = self::t('dl_count');
		return [
			'today' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $t WHERE user_id = %d AND day = %s", (int) $user_id, wp_date('Y-m-d'))),
			'month' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $t WHERE user_id = %d AND day >= %s", (int) $user_id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS))),
		];
	}

	/* ---------------------------------------------------------------- *
	 * Withdrawals
	 * ---------------------------------------------------------------- */

	public static function payouts($user_id, $limit = 10) {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t('payouts') . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', (int) $user_id, (int) $limit));
	}

	public static function open_payout($user_id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('payouts') . " WHERE user_id = %d AND status = 'requested' ORDER BY id DESC LIMIT 1", (int) $user_id));
	}

	/** The member's last method and details: ['method' => key, 'to' => text]. */
	public static function pay_to($user_id) {
		$v = json_decode(Y4APK_Mail::reveal((string) get_user_meta($user_id, self::PAY_TO, true)), true);
		return ['method' => is_array($v) ? (string) ($v['method'] ?? '') : '', 'to' => is_array($v) ? (string) ($v['to'] ?? '') : ''];
	}

	/** A creator asks to be paid: the amount leaves their earnings now, and comes back if the request is turned down. */
	public static function request_payout($user_id, $amount, $method, $to) {
		global $wpdb;
		$s = self::settings();
		$methods = self::methods_on();
		$to = trim(sanitize_text_field($to));
		if (!isset($methods[$method])) {
			return new WP_Error('y4apk_payout', __('Choose how to be paid.', 'y4apk'));
		}
		if (mb_strlen($to) < 4 || mb_strlen($to) > 120) {
			return new WP_Error('y4apk_payout', sprintf(__('Fill in: %s.', 'y4apk'), $methods[$method][1]));
		}
		if ($amount < self::to_m($s['min_payout'])) {
			return new WP_Error('y4apk_payout', sprintf(__('You can withdraw from %s.', 'y4apk'), self::fmt(self::to_m($s['min_payout']))));
		}
		if (self::open_payout($user_id)) {
			return new WP_Error('y4apk_payout', __('You already have a withdrawal waiting. It is paid by hand, usually within a few days.', 'y4apk'));
		}
		$details = Y4APK_Mail::hide(wp_json_encode(['method' => $method, 'to' => $to]));
		$id = self::tx(function () use ($wpdb, $user_id, $amount, $method, $details) {
			$wpdb->insert(self::t('payouts'), ['user_id' => (int) $user_id, 'amount' => (int) $amount, 'method' => $method, 'details' => $details, 'status' => 'requested', 'made' => time()],
				['%d', '%d', '%s', '%s', '%s', '%d']);
			$id = (int) $wpdb->insert_id;
			return $id && self::take($user_id, $amount, 'payout', (string) $id, 0, $method, true) ? $id : false;
		});
		if (!$id) {
			return new WP_Error('y4apk_payout', __('Not enough in your earnings.', 'y4apk'));
		}
		update_user_meta($user_id, self::PAY_TO, $details);
		$owner = (string) get_option('admin_email');
		$u = get_userdata($user_id);
		if ($owner !== '' && $u) {
			Y4APK_Members::mail($owner, __('A creator asked to be paid', 'y4apk'), [
				'title' => __('A creator asked to be paid', 'y4apk'),
				'lines' => [sprintf(__('@%1$s asked for %2$s by %3$s.', 'y4apk'), $u->user_login, self::fmt($amount), $methods[$method][0])],
				'button' => [__('Open the withdrawals', 'y4apk'), admin_url('admin.php?page=' . self::SLUG . '&tab=payouts')],
			]);
		}
		return $id;
	}

	/** The owner paid it (by hand), or turns it down (the money goes back to the creator's earnings). */
	public static function finish_payout($id, $paid, $note) {
		global $wpdb;
		$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('payouts') . ' WHERE id = %d', (int) $id));
		if (!$p || $p->status !== 'requested') {
			return false;
		}
		$n = $wpdb->query($wpdb->prepare('UPDATE ' . self::t('payouts') . " SET status = %s, note = %s, done = %d WHERE id = %d AND status = 'requested'",
			$paid ? 'paid' : 'rejected', mb_substr(sanitize_text_field($note), 0, 190), time(), (int) $id));
		if ($n !== 1) {
			return false;
		}
		if (!$paid) {
			self::add((int) $p->user_id, 0, (int) $p->amount, 'payout_back', (string) $p->id, 0, mb_substr(sanitize_text_field($note), 0, 120));
		}
		$u = get_userdata((int) $p->user_id);
		if ($u) {
			$m = self::methods()[$p->method][0] ?? $p->method;
			self::mail_member($u, $paid ? __('Your withdrawal is paid', 'y4apk') : __('Your withdrawal was not paid', 'y4apk'), array_filter([
				$paid ? sprintf(__('%1$s has been sent to you by %2$s.', 'y4apk'), self::fmt($p->amount), $m) : sprintf(__('Your withdrawal of %s was not paid, and the money is back in your earnings.', 'y4apk'), self::fmt($p->amount)),
				$note !== '' ? ($paid ? __('Note:', 'y4apk') : __('Why:', 'y4apk')) . ' ' . $note : '',
			]), sprintf(__('You got this email because you asked to withdraw your earnings (#%s).', 'y4apk'), $p->id));
		}
		return true;
	}

	/** Moves money for a member by hand: payments taken outside the site, a refund, a correction. */
	public static function adjust($user_id, $credit, $earned, $note) {
		self::add($user_id, (int) $credit, (int) $earned, 'adjust', '', get_current_user_id(), $note);
	}

	/* ---------------------------------------------------------------- *
	 * Mail
	 * ---------------------------------------------------------------- */

	private static function mail_member($user, $title, $lines, $why) {
		if (!class_exists('Y4APK_Members')) {
			return;
		}
		Y4APK_Members::mail($user->user_email, $title, [
			'title' => $title,
			'hi' => class_exists('Y4APK_Social') ? Y4APK_Social::name($user) : $user->display_name,
			'lines' => array_values($lines), 'button' => [__('Open your wallet', 'y4apk'), self::url()], 'why' => $why,
		]);
	}

	/* ---------------------------------------------------------------- *
	 * The member's page: /account/wallet/
	 * ---------------------------------------------------------------- */

	public static function url($args = []) {
		return Y4APK_Members::url('wallet', $args);
	}

	private static function money_in($key) {
		$v = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
		return round((float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $v)), 2);
	}

	public static function route(&$ctx) {
		$ctx['screen'] = 'wallet';
		$uid = (int) $ctx['user']->ID;
		$s = self::settings();
		$do = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do']) ? sanitize_key(wp_unslash($_POST['y4_do'])) : '';
		if ($do !== '' && !wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_wallet')) {
			$ctx['errors'][] = __('The page was open too long. Try again.', 'y4apk');
			$do = '';
		}
		if ($do !== '' && !$s['on']) {
			$ctx['errors'][] = __('The wallet is closed at the moment.', 'y4apk');
			$do = '';
		}
		// The tip form: to a creator (?to=name) or to the site
		$to = null;
		$to_name = isset($_REQUEST['to']) ? sanitize_user(wp_unslash($_REQUEST['to'])) : '';
		if ($to_name !== '' && $to_name !== 'site') {
			$tu = get_user_by('login', $to_name);
			$to = $tu && $tu->ID !== $uid && self::creator($tu->ID)['ok'] ? $tu : null;
			if (!$to) {
				$ctx['errors'][] = __('This member cannot get tips.', 'y4apk');
			}
		}
		if ($do === 'wallet_topup') {
			$amount = self::money_in('amount') ?: self::money_in('amount_pick');
			if ($amount < (float) $s['min_topup'] || $amount > 1000) {
				$ctx['errors'][] = sprintf(__('Top up between %1$s and %2$s.', 'y4apk'), Y4APK_VIP::money($s['min_topup']), Y4APK_VIP::money(1000));
			} else {
				$go = Y4APK_Pay::create($uid, 'topup', $amount, [], 'Balance top-up');
				if (is_wp_error($go)) {
					$ctx['errors'][] = $go->get_error_message();
				} else {
					wp_redirect($go);
					exit;
				}
			}
		}
		if ($do === 'wallet_tip') {
			$amount = self::money_in('amount') ?: self::money_in('amount_pick');
			$name = isset($_POST['name']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['name'])), 0, 40) : '';
			$msg = isset($_POST['msg']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['msg'])), 0, 160) : '';
			$ctx['old'] += ['amount' => $amount, 'name' => $name, 'msg' => $msg];
			$to_id = $to ? (int) $to->ID : 0;
			if ($to_name !== '' && $to_name !== 'site' && !$to) {
				// said above
			} elseif (isset($_POST['via']) && $_POST['via'] === 'crypto') {
				if ($amount < max((float) $s['min_topup'], (float) $s['min_tip'])) {
					$ctx['errors'][] = sprintf(__('Paying a tip straight away works from %s. Smaller tips: top up, then tip from your balance.', 'y4apk'), Y4APK_VIP::money($s['min_topup']));
				} else {
					$go = Y4APK_Pay::create($uid, 'tip', $amount, ['meta' => ['to' => $to_id, 'name' => $name, 'msg' => $msg]], $to ? 'Tip to a creator' : 'Tip');
					if (is_wp_error($go)) {
						$ctx['errors'][] = $go->get_error_message();
					} else {
						wp_redirect($go);
						exit;
					}
				}
			} else {
				$r = self::tip($uid, $to_id, self::to_m($amount), $name, $msg);
				if (is_wp_error($r)) {
					$ctx['errors'][] = $r->get_error_message();
				} else {
					wp_safe_redirect(self::url(['ok' => 'tipped', 'to' => $to ? $to->user_login : 'site']) . '#tip');
					exit;
				}
			}
		}
		if ($do === 'wallet_payout') {
			$amount = self::to_m(self::money_in('amount'));
			$method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '';
			$dest = isset($_POST['pay_to']) ? (string) wp_unslash($_POST['pay_to']) : '';
			$r = self::creator($uid)['ok'] || self::balance($uid)['earned'] > 0 ? self::request_payout($uid, $amount, $method, $dest) : new WP_Error('y4apk_payout', __('Withdrawals are for creators.', 'y4apk'));
			if (is_wp_error($r)) {
				$ctx['errors'][] = $r->get_error_message();
				$ctx['old'] += ['method' => $method, 'pay_to' => sanitize_text_field($dest)];
			} else {
				wp_safe_redirect(self::url(['ok' => 'payout']) . '#earn');
				exit;
			}
		}
		$order = null;
		if (isset($_GET['order'], $_GET['k'])) {
			$o = Y4APK_Pay::get(absint($_GET['order']));
			if ($o && (int) $o->user_id === $uid && $o->kind !== 'vip' && hash_equals((string) $o->token, (string) wp_unslash($_GET['k']))) {
				$order = $o;
			}
		}
		self::settle(); // yesterday's downloads, if the daily job has not run yet
		$ctx['wallet'] = [
			'set'     => $s,
			'bal'     => self::balance($uid),
			'creator' => self::creator($uid),
			'dl'      => self::dl_on() ? self::dl_stats($uid) : null,
			'log'     => self::history($uid),
			'orders'  => Y4APK_Pay::for_user($uid, 10, ['topup', 'tip']),
			'order'   => $order,
			'cancel'  => !empty($_GET['cancel']),
			'pay'     => Y4APK_Pay::ready(),
			'to'      => $to,
			'tipping' => $to_name !== '',
			'fans'    => self::supporters(),
			'payouts' => self::payouts($uid),
			'open'    => self::open_payout($uid),
			'pay_to'  => self::pay_to($uid),
		];
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-wallet', get_template_directory_uri() . '/assets/css/wallet.css', ['y4apk-account'], Y4APK_THEME_VERSION);
		}, 20);
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Wallet
	 * ---------------------------------------------------------------- */

	public static function menu() {
		global $wpdb;
		$waiting = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('payouts') . " WHERE status = 'requested'");
		$label = __('Wallet', 'y4apk') . ($waiting ? ' <span class="awaiting-mod">' . number_format_i18n($waiting) . '</span>' : '');
		add_submenu_page(Y4APK_MENU, __('Wallet and creators', 'y4apk'), $label, 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function admin_url($args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render() {
		require __DIR__ . '/wallet-admin.php';
	}

	public static function handle_admin() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_wallet_admin');
		$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
		$tab = sanitize_key(wp_unslash($_POST['tab'] ?? 'overview'));
		$note = '';
		$extra = [];
		if ($do === 'settings') {
			self::save_settings(wp_unslash($_POST));
			$note = 'saved';
			if (function_exists('y4apk_purge_pages')) {
				y4apk_purge_pages();
			}
		} elseif ($do === 'paid' || $do === 'reject') {
			$note = self::finish_payout(absint($_POST['id'] ?? 0), $do === 'paid', (string) wp_unslash($_POST['note'] ?? '')) ? $do : 'gone';
		} elseif ($do === 'mode') {
			$uid = absint($_POST['user'] ?? 0);
			$mode = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
			if ($uid && in_array($mode, ['', 'on', 'off'], true)) {
				$mode === '' ? delete_user_meta($uid, self::MODE) : update_user_meta($uid, self::MODE, $mode);
				delete_transient('y4apk_cr_' . $uid);
				$note = 'mode';
			}
		} elseif ($do === 'creator') {
			$u = self::find_user((string) wp_unslash($_POST['who'] ?? ''));
			if ($u) {
				update_user_meta($u->ID, self::MODE, 'on');
				delete_transient('y4apk_cr_' . $u->ID);
				$note = 'mode';
			} else {
				$note = 'nouser';
			}
		} elseif ($do === 'adjust') {
			$u = self::find_user((string) wp_unslash($_POST['who'] ?? ''));
			$amount = self::to_m((float) preg_replace('/[^0-9.\-]/', '', (string) wp_unslash($_POST['amount'] ?? '0')));
			if (!$u) {
				$note = 'nouser';
			} elseif ($amount === 0) {
				$note = 'noamount';
			} else {
				$which = ($_POST['which'] ?? '') === 'earned' ? 'earned' : 'credit';
				$have = self::balance($u->ID)[$which];
				$amount = max($amount, -$have); // never below zero
				self::adjust($u->ID, $which === 'credit' ? $amount : 0, $which === 'earned' ? $amount : 0, sanitize_text_field(wp_unslash($_POST['why'] ?? '')));
				$note = 'adjusted';
				$extra = ['who' => $u->user_login];
			}
		} elseif ($do === 'hide_tip') {
			global $wpdb;
			$wpdb->query($wpdb->prepare('UPDATE ' . self::t('tips') . ' SET hidden = 1 - hidden WHERE id = %d', absint($_POST['id'] ?? 0)));
			$note = 'tiphidden';
		}
		wp_safe_redirect(self::admin_url(array_merge(['tab' => $tab, 'note' => $note], $extra)));
		exit;
	}

	/** A member from what the owner typed: @name, a username or an email. */
	public static function find_user($text) {
		$text = trim(ltrim(trim((string) $text), '@'));
		if ($text === '') {
			return null;
		}
		$u = is_email($text) ? get_user_by('email', $text) : get_user_by('login', sanitize_user($text));
		return $u ?: null;
	}
}

Y4APK_Wallet::init();
