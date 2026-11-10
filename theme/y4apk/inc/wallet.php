<?php
/**
 * The wallet and the creator program (2.64).
 *
 *   Coins     (2.68: Y4 Coins, 100 to the dollar by default.) Bought in packs
 *             (crypto on the payment company's page; bigger packs give more
 *             coins) or given by the site. Spent on the site: VIP and gifts.
 *             No cash value: never paid out, never moved to another member
 *             except as a gift. Kept as `credit`, in micro units like money.
 *   Earnings  What a creator made: gifts from members (less the site's share)
 *             and downloads of the files they shared (a rate per 1,000).
 *             Withdrawn: the creator asks, the owner pays by hand (crypto,
 *             PayPal, TrueMoney…) and marks it paid. Gifts wait 14 days before
 *             they can be withdrawn. The owner's name is never on the site.
 *
 * Small amounts work: a 10-coin rose or a 300-coin VIP comes out of the coins,
 * so the payment company's smallest payment only applies to buying coins.
 * Gifts to a creator are refused from an account less than a day old or from
 * the creator's own connection (compared as one-way hashes).
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
	const DBV      = '3'; // 2.67.1: downloads by country (y4apk_dl_geo); 2.68: tips.gift
	const IP_LAST  = 'y4apk_ip_last';      // usermeta: a one-way hash of the connection a creator last opened their wallet from
	const GIFTS    = ['rose' => '🌹', 'fire' => '🔥', 'diamond' => '💎', 'crown' => '👑'];
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
		if (!get_option('y4apk_wallet_2681')) { // 2.68.1: coins are bought from $12.49
			$o = get_option(self::OPTION);
			if (is_array($o) && isset($o['min_topup']) && (float) $o['min_topup'] < 12.49) {
				$o['min_topup'] = '12.49';
				update_option(self::OPTION, $o, false);
			}
			update_option('y4apk_wallet_2681', 1, false);
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
			gift varchar(16) NOT NULL DEFAULT '',
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
		dbDelta('CREATE TABLE ' . self::t('dl_geo') . " (
			day date NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			cc char(2) NOT NULL DEFAULT '',
			n int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,user_id,cc)
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
			'min_topup'  => '12.49',  // the smallest payment (2.68.1: also the smallest amount of coins to buy)
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
			// 2.68: Y4 Coins
			'coin_name'  => 'Y4 Coins',
			'coins_per'  => '100',    // coins in one dollar (or one of the currency)
			'packs'      => '12.49=1249, 20=2100, 50=5500, 100=12000', // price=coins
			'gifts'      => ['rose' => 10, 'fire' => 50, 'diamond' => 500, 'crown' => 2000], // coins; 0 hides one
			'gift_day'   => '10000',  // coins one member may give away in 24 hours
			'hold_days'  => '14',     // days a gift waits before it can be withdrawn
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
			'coin_name'  => mb_substr(sanitize_text_field($in['coin_name'] ?? ''), 0, 24) ?: 'Y4 Coins',
			'coins_per'  => (string) max(1, min(1000, absint($in['coins_per'] ?? 100))),
			'packs'      => self::packs_text($in['packs'] ?? ''),
			'gifts'      => array_map(function ($k) use ($in) { return min(1000000, absint($in['gift_' . $k] ?? 0)); }, array_combine(array_keys(self::GIFTS), array_keys(self::GIFTS))),
			'gift_day'   => (string) absint($in['gift_day'] ?? 10000),
			'hold_days'  => (string) min(90, absint($in['hold_days'] ?? 14)),
		];
		// 2.68: the dollar amounts of top-ups and tips are no longer asked; kept for orders made before
		foreach (['topups', 'bonus_from', 'bonus_pct', 'site_tips', 'tips', 'min_tip'] as $k) {
			if (!isset($in[$k])) {
				$o[$k] = self::settings()[$k];
			}
		}
		update_option(self::OPTION, $o, false);
	}

	/* ---------------------------------------------------------------- *
	 * Coins (2.68)
	 * ---------------------------------------------------------------- */

	/** Coins in one dollar (or one of the currency). */
	public static function coins_per() {
		return max(1, min(1000, (int) self::settings()['coins_per']));
	}

	/** Micro units in one coin. */
	public static function coin_m() {
		return intdiv(self::M, self::coins_per());
	}

	/** Micro units → whole coins (rounded towards zero). */
	public static function coins($m) {
		return intdiv((int) $m, self::coin_m());
	}

	/** Whole coins → micro units. */
	public static function from_coins($n) {
		return (int) $n * self::coin_m();
	}

	public static function coin_name() {
		$n = (string) self::settings()['coin_name'];
		return $n !== '' ? $n : 'Y4 Coins';
	}

	/** "1,250 Y4 Coins" */
	public static function fmt_coins($m) {
		$n = self::coins($m);
		return ($n < 0 ? '−' : '') . number_format_i18n(abs($n)) . ' ' . self::coin_name();
	}

	/** The coin, drawn: a gold disc with a star. */
	public static function coin_icon($size = 20) {
		$s = (int) $size;
		return '<svg class="y4-coin" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#f5a623"/><circle cx="12" cy="12" r="8.4" fill="#ffd34d"/><path fill="#e08e0b" d="m12 6.6 1.6 3.3 3.6.5-2.6 2.5.6 3.6L12 14.8l-3.2 1.7.6-3.6-2.6-2.5 3.6-.5L12 6.6Z"/></svg>';
	}

	/** "10=1000, 20=2100" → the same, cleaned: price=coins pairs, by price, at most 6. */
	private static function packs_text($text) {
		$out = [];
		foreach (preg_split('/[,;\n]+/', (string) $text) as $part) {
			if (preg_match('/^\s*([0-9]+(?:\.[0-9]{1,2})?)\s*=\s*([0-9]+)\s*$/', $part, $mm) && (float) $mm[1] > 0 && (float) $mm[1] <= 1000 && (int) $mm[2] > 0) {
				$out[(string) round((float) $mm[1], 2)] = (int) $mm[2];
			}
		}
		ksort($out, SORT_NUMERIC);
		$out = array_slice($out, 0, 6, true);
		return implode(', ', array_map(function ($p, $c) { return $p . '=' . $c; }, array_keys($out), $out));
	}

	/** The coin packs on sale: [['price' => 10.0, 'coins' => 1000, 'extra' => % more than the plain rate], …]. */
	public static function packs() {
		$s = self::settings();
		$out = [];
		foreach (explode(',', self::packs_text($s['packs'])) as $part) {
			if (strpos($part, '=') === false) {
				continue;
			}
			list($price, $coins) = array_map('trim', explode('=', $part));
			$price = (float) $price;
			if ($price < (float) $s['min_topup']) {
				continue; // under the payment company's smallest payment
			}
			$plain = $price * self::coins_per();
			$out[] = ['price' => $price, 'coins' => (int) $coins, 'extra' => $plain > 0 ? (int) round(100 * ((int) $coins - $plain) / $plain) : 0];
		}
		return $out;
	}

	/** The pack sold at $price, or null. */
	public static function pack($price) {
		foreach (self::packs() as $p) {
			if (abs($p['price'] - (float) $price) < .001) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * 2.68.1: coins for any amount (dollars): a pack's own coins at its price;
	 * otherwise the rate of the biggest pack it reaches (so $35 gets the $20
	 * pack's +5%), or the plain rate below the first pack.
	 * ['coins' => int, 'extra' => % more than the plain rate].
	 */
	public static function coins_for($amount) {
		$amount = round((float) $amount, 2);
		$rate = self::coins_per();
		$extra = 0;
		foreach (self::packs() as $p) {
			if (abs($p['price'] - $amount) < .001) {
				return ['coins' => $p['coins'], 'extra' => $p['extra']];
			}
			if ($p['price'] <= $amount && $p['coins'] / $p['price'] > $rate) {
				$rate = $p['coins'] / $p['price'];
				$extra = $p['extra'];
			}
		}
		return ['coins' => (int) floor(round($amount * $rate, 4)), 'extra' => $extra];
	}

	/** The coin rates for the page's live sum: [[from price, coins per dollar, extra %], …]. */
	public static function rates() {
		$out = [[0, self::coins_per(), 0]];
		foreach (self::packs() as $p) {
			$out[] = [$p['price'], $p['coins'] / $p['price'], $p['extra']];
		}
		return $out;
	}

	/** The gifts on offer: key => ['icon', 'name', 'coins'], cheapest first. */
	public static function gifts() {
		$names = ['rose' => __('Rose', 'y4apk'), 'fire' => __('Fire', 'y4apk'), 'diamond' => __('Diamond', 'y4apk'), 'crown' => __('Crown', 'y4apk')];
		$set = (array) self::settings()['gifts'];
		$out = [];
		foreach (self::GIFTS as $k => $icon) {
			$n = (int) ($set[$k] ?? 0);
			if ($n > 0) {
				$out[$k] = ['icon' => $icon, 'name' => $names[$k], 'coins' => $n];
			}
		}
		uasort($out, function ($a, $b) { return $a['coins'] <=> $b['coins']; });
		return $out;
	}

	/** What a VIP plan costs in coins: its own number (2.68.1), or its price × coins in one dollar. */
	public static function vip_coins($plan) {
		if ((int) ($plan['coins'] ?? 0) > 0) {
			return (int) $plan['coins'];
		}
		return (int) ceil(round((float) ($plan['price'] ?? 0) * self::coins_per(), 4));
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
	 * Takes $amount (micro) from a member: $from 'credit' (coins: VIP, gifts) or
	 * 'earned' (a withdrawal). Inside tx(). The split taken, or false when there
	 * is not enough.
	 */
	private static function take($user_id, $amount, $kind, $ref = '', $other = 0, $note = '', $from = 'credit') {
		global $wpdb;
		$w = self::t('wallets');
		$r = $wpdb->get_row($wpdb->prepare("SELECT credit, earned FROM $w WHERE user_id = %d FOR UPDATE", (int) $user_id));
		if (!$r || $amount <= 0) {
			return false;
		}
		$have_c = max(0, (int) $r->credit);
		$have_e = max(0, (int) $r->earned);
		$c = $from === 'earned' ? 0 : $amount;
		$e = $from === 'earned' ? $amount : 0;
		if ($c > $have_c || $e > $have_e) {
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
				return sprintf(__('Coins bought · order #%s', 'y4apk'), $row->ref);
			case 'bonus':
				return __('Bonus coins', 'y4apk');
			case 'vip':
				return $row->note === 'gift' ? sprintf(__('VIP gift code · %s', 'y4apk'), Y4APK_VIP::plan_label((int) $row->ref)) : sprintf(__('VIP · %s', 'y4apk'), Y4APK_VIP::plan_label((int) $row->ref));
			case 'tip_out':
				$g = self::gift_label($row->note);
				return $g !== '' ? sprintf(__('%1$s to %2$s', 'y4apk'), $g, (int) $row->other_id ? $who($row->other_id) : self::site_name())
					: ((int) $row->other_id ? sprintf(__('Tip to %s', 'y4apk'), $who($row->other_id)) : sprintf(__('Tip to %s', 'y4apk'), self::site_name()));
			case 'tip_in':
				$g = self::gift_label($row->note);
				$from = (int) $row->other_id ? $who($row->other_id) : __('a supporter', 'y4apk');
				return $g !== '' ? sprintf(__('%1$s from %2$s', 'y4apk'), $g, $from) : sprintf(__('Tip from %s', 'y4apk'), $from);
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

	/** "🌹 Rose" for a gift's key, '' for none (tips before 2.68). */
	public static function gift_label($key) {
		$key = (string) $key;
		if (!isset(self::GIFTS[$key])) {
			return '';
		}
		$names = ['rose' => __('Rose', 'y4apk'), 'fire' => __('Fire', 'y4apk'), 'diamond' => __('Diamond', 'y4apk'), 'crown' => __('Crown', 'y4apk')];
		return self::GIFTS[$key] . ' ' . $names[$key];
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
		$amount = self::from_coins(self::vip_coins($plan)); // 2.68: paid in coins
		$days = (int) $plan['days'];
		$taken = self::tx(function () use ($user_id, $amount, $days, $gift) {
			return self::take($user_id, $amount, 'vip', (string) $days, 0, $gift ? 'gift' : '');
		});
		if (!$taken) {
			return ['ok' => false, 'why' => sprintf(__('Not enough %s. Buy coins first.', 'y4apk'), self::coin_name())];
		}
		$code = '';
		if ($gift) {
			$code = Y4APK_VIP::make_one($days, __('Paid with coins', 'y4apk'));
		} else {
			Y4APK_VIP::extend($user_id, $days, 'pay', self::coin_name());
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
			$meta = Y4APK_Pay::meta($o);
			$m = self::to_m($o->amount);
			if (!empty($meta['coins'])) { // 2.68: a coin pack, its coins as they were when it was ordered
				$bonus = max(0, self::from_coins((int) $meta['coins']) - $m);
			} else {
				$bonus = self::bonus($o->amount); // a top-up ordered before 2.68
			}
			self::add($user->ID, $m, 0, 'topup', (string) $o->id);
			if ($bonus > 0) {
				self::add($user->ID, $bonus, 0, 'bonus', (string) $o->id);
			}
			self::mail_member($user, sprintf(__('Your %s are in', 'y4apk'), self::coin_name()), [
				sprintf(__('%s have been added to your wallet.', 'y4apk'), self::fmt_coins($m + $bonus)),
				__('Use them for VIP or to send gifts to creators: nothing else to pay.', 'y4apk'),
			], sprintf(__('You got this email because you bought coins (order #%s).', 'y4apk'), $o->id));
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
	 * 2.68: a gift out of the member's coins, to a creator or to the site
	 * ($to = 0). true, or a WP_Error that says why not.
	 */
	public static function gift($from, $to, $key, $name, $msg) {
		$s = self::settings();
		$gifts = self::gifts();
		$from = (int) $from;
		$to = (int) $to;
		if (!isset($gifts[$key])) {
			return new WP_Error('y4apk_gift', __('Choose a gift.', 'y4apk'));
		}
		if ($to && ($to === $from || !self::creator($to)['ok'])) {
			return new WP_Error('y4apk_gift', __('This member cannot get gifts.', 'y4apk'));
		}
		if ($to) {
			$why = self::self_gift($from, $to);
			if ($why !== '') {
				return new WP_Error('y4apk_gift', $why);
			}
		}
		if (!Y4APK_Members::hit('gift:' . $from, 30, 10 * MINUTE_IN_SECONDS)) {
			return new WP_Error('y4apk_gift', __('That is a lot of gifts at once. Wait a few minutes and try again.', 'y4apk'));
		}
		$amount = self::from_coins($gifts[$key]['coins']);
		$limit = self::from_coins((int) $s['gift_day']);
		if ($limit > 0 && self::given_today($from) + $amount > $limit) {
			return new WP_Error('y4apk_gift', sprintf(__('You can give up to %s a day. Try again tomorrow.', 'y4apk'), self::fmt_coins($limit)));
		}
		$ok = self::tx(function () use ($from, $to, $amount, $key) {
			return self::take($from, $amount, 'tip_out', '', $to, $key);
		});
		if (!$ok) {
			return new WP_Error('y4apk_gift', sprintf(__('Not enough %s. Buy coins first.', 'y4apk'), self::coin_name()));
		}
		self::give_tip($from, $to, $amount, $name, $msg, 'wallet', 0, $key);
		return true;
	}

	/**
	 * Why $from may not send a creator a gift ('' when they may): an account
	 * less than a day old, or the creator's own connection. Only one-way hashes
	 * of connections are compared, the same kind registration keeps.
	 */
	public static function self_gift($from, $to) {
		$u = get_userdata((int) $from);
		if (!$u || time() - (int) strtotime($u->user_registered . ' UTC') < DAY_IN_SECONDS) {
			return __('New accounts can send gifts to creators after their first day.', 'y4apk');
		}
		$now = Y4APK_Members::ip_hash();
		$theirs = array_filter([(string) get_user_meta($to, 'y4apk_ip', true), (string) get_user_meta($to, self::IP_LAST, true)]);
		$mine = array_filter([$now, (string) get_user_meta($from, 'y4apk_ip', true)]);
		if (array_intersect($mine, $theirs)) {
			return __('Gifts cannot be sent between accounts on the same connection.', 'y4apk');
		}
		return '';
	}

	/** Coins (micro) a member gave away in the last 24 hours. */
	public static function given_today($user_id) {
		global $wpdb;
		return -(int) $wpdb->get_var($wpdb->prepare('SELECT SUM(credit) FROM ' . self::t('ledger') . " WHERE user_id = %d AND kind = 'tip_out' AND made > %d", (int) $user_id, time() - DAY_IN_SECONDS));
	}

	/** The receiving side of a gift or tip (from coins, or a tip paid on the payment page before 2.68). */
	private static function give_tip($from, $to, $amount, $name, $msg, $via, $order_id, $gift = '') {
		global $wpdb;
		$share = (float) self::settings()['share'];
		$net = $to ? (int) floor($amount * (100 - $share) / 100) : 0;
		$wpdb->insert(self::t('tips'), [
			'from_id' => (int) $from, 'to_id' => (int) $to, 'amount' => (int) $amount, 'net' => $net,
			'name' => mb_substr(sanitize_text_field($name), 0, 40), 'msg' => mb_substr(sanitize_text_field($msg), 0, 160),
			'via' => $via, 'gift' => (string) $gift, 'order_id' => (int) $order_id, 'made' => time(),
		], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d']);
		if ($to && $net > 0) {
			self::add($to, 0, $net, 'tip_in', (string) $wpdb->insert_id, $from, (string) $gift);
			$creator = get_userdata($to);
			// One email in 30 minutes at most: a fan sending ten roses is one email
			if ($creator && !get_transient('y4apk_giftmail_' . $to)) {
				set_transient('y4apk_giftmail_' . $to, 1, 30 * MINUTE_IN_SECONDS);
				$what = $gift !== '' ? self::gift_label($gift) : self::fmt($amount);
				$lines = [sprintf(__('%1$s sent you %2$s. %3$s is in your earnings.', 'y4apk'), $name !== '' ? $name : __('Someone', 'y4apk'), $what, self::fmt($net))];
				if ($msg !== '') {
					$lines[] = '“' . $msg . '”';
				}
				$hold = (int) self::settings()['hold_days'];
				if ($hold > 0) {
					$lines[] = sprintf(_n('Gifts can be withdrawn %s day after they are sent.', 'Gifts can be withdrawn %s days after they are sent.', $hold, 'y4apk'), number_format_i18n($hold));
				}
				self::mail_member($creator, __('You got a gift', 'y4apk'), $lines, __('You got this email because you are a creator on the site.', 'y4apk'));
			}
		}
	}

	/**
	 * A creator's earnings, split: ['free' => withdrawable now, 'held' => gifts
	 * from the last hold_days days, 'next' => when the oldest held one frees].
	 */
	public static function hold($user_id) {
		global $wpdb;
		$earned = max(0, self::balance($user_id)['earned']);
		$days = (int) self::settings()['hold_days'];
		if ($days <= 0 || $earned <= 0) {
			return ['free' => $earned, 'held' => 0, 'next' => 0];
		}
		$since = time() - $days * DAY_IN_SECONDS;
		$r = $wpdb->get_row($wpdb->prepare('SELECT SUM(earned) AS a, MIN(made) AS first FROM ' . self::t('ledger') . " WHERE user_id = %d AND kind = 'tip_in' AND made > %d", (int) $user_id, $since));
		$held = min($earned, max(0, (int) ($r->a ?? 0)));
		return ['free' => $earned - $held, 'held' => $held, 'next' => $held ? (int) $r->first + $days * DAY_IN_SECONDS : 0];
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
		if (class_exists('Y4APK_Geo')) {
			Y4APK_Geo::count($day, $author, Y4APK_Geo::visitor_country()); // 2.67.1: only the country's two letters
		}
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
		if (class_exists('Y4APK_Geo')) {
			Y4APK_Geo::daily(); // 2.67.1: the networks' numbers by country, before the day is added
		}
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
			$amount = class_exists('Y4APK_Geo') ? Y4APK_Geo::earned((int) $r->user_id, (string) $r->day, (int) $r->n, $rate) : intdiv((int) $r->n * $rate, 1000); // 2.67.1: by country
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
		$h = self::hold($user_id); // 2.68: gifts wait before they can be withdrawn
		if ($amount > $h['free']) {
			return new WP_Error('y4apk_payout', $h['held'] > 0
				? sprintf(__('You can withdraw %1$s now. %2$s from recent gifts frees up on %3$s.', 'y4apk'), self::fmt($h['free']), self::fmt($h['held']), wp_date(get_option('date_format'), $h['next']))
				: __('Not enough in your earnings.', 'y4apk'));
		}
		$details = Y4APK_Mail::hide(wp_json_encode(['method' => $method, 'to' => $to]));
		$id = self::tx(function () use ($wpdb, $user_id, $amount, $method, $details) {
			$wpdb->insert(self::t('payouts'), ['user_id' => (int) $user_id, 'amount' => (int) $amount, 'method' => $method, 'details' => $details, 'status' => 'requested', 'made' => time()],
				['%d', '%d', '%s', '%s', '%s', '%d']);
			$id = (int) $wpdb->insert_id;
			return $id && self::take($user_id, $amount, 'payout', (string) $id, 0, $method, 'earned') ? $id : false;
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
		if ($do !== '' && Y4APK_Members::unconfirmed($ctx['user'])) { // 2.66: money waits for a confirmed email
			$ctx['errors'][] = __('Confirm your email first: open the link we sent you, then try again.', 'y4apk');
			$do = '';
		}
		// The tip form: to a creator (?to=name) or to the site
		$to = null;
		$to_name = isset($_REQUEST['to']) ? sanitize_user(wp_unslash($_REQUEST['to'])) : '';
		if ($to_name !== '' && $to_name !== 'site') {
			$tu = get_user_by('login', $to_name);
			$to = $tu && $tu->ID !== $uid && self::creator($tu->ID)['ok'] ? $tu : null;
			if (!$to) {
				$ctx['errors'][] = __('This member cannot get gifts.', 'y4apk');
			}
		}
		if (self::creator($uid)['ok']) { // 2.68: so a fan account on the creator's own connection cannot gift them (a hash, not the address)
			$ih = Y4APK_Members::ip_hash();
			if (get_user_meta($uid, self::IP_LAST, true) !== $ih) {
				update_user_meta($uid, self::IP_LAST, $ih);
			}
		}
		if ($do === 'wallet_topup') {
			$amount = self::money_in('amount') ?: self::money_in('pack'); // 2.68.1: any amount, or a pack
			$ctx['old'] += ['amount' => self::money_in('amount') ?: ''];
			if ($amount < (float) $s['min_topup'] || $amount > 1000) {
				$ctx['errors'][] = sprintf(__('Buy coins for %1$s to %2$s.', 'y4apk'), Y4APK_VIP::money($s['min_topup']), Y4APK_VIP::money(1000));
			} else {
				$pack = ['price' => $amount] + self::coins_for($amount);
				$go = Y4APK_Pay::create($uid, 'topup', $pack['price'], ['meta' => ['coins' => $pack['coins']]], 'Coins');
				if (is_wp_error($go)) {
					$ctx['errors'][] = $go->get_error_message();
				} else {
					wp_redirect($go);
					exit;
				}
			}
		}
		if ($do === 'wallet_tip') { // 2.68: a gift, from coins
			$key = isset($_POST['gift']) ? sanitize_key(wp_unslash($_POST['gift'])) : '';
			$name = isset($_POST['name']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['name'])), 0, 40) : '';
			$msg = isset($_POST['msg']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['msg'])), 0, 160) : '';
			$ctx['old'] += ['gift' => $key, 'name' => $name, 'msg' => $msg];
			if ($to_name === '' || $to_name === 'site' || $to) {
				$r = self::gift($uid, $to ? (int) $to->ID : 0, $key, $name, $msg);
				if (is_wp_error($r)) {
					$ctx['errors'][] = $r->get_error_message();
				} else {
					wp_safe_redirect(self::url(['ok' => 'tipped', 'g' => $key, 'to' => $to ? $to->user_login : 'site']) . '#tip');
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
			'hold'    => self::hold($uid),
			'packs'   => self::packs(),
			'gifts'   => self::gifts(),
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
		} elseif ($do === 'geo' && class_exists('Y4APK_Geo')) { // 2.67.1
			$note = Y4APK_Geo::handle();
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
