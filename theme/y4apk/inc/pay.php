<?php
/**
 * Payments (2.63): VIP bought on the site, given at once.
 *
 * Built so a payment company plugs in; the first is NOWPayments (crypto: USDT,
 * BTC, ETH and a few hundred more, from anywhere; cards too once the account
 * has passed their short business check). The buyer never sees the owner's
 * name, only the site's.
 *
 *   1. On /account/vip/ a signed-in member picks a plan (for themself, or as a
 *      gift code) and taps Pay: an order is written (y4apk_orders), the
 *      company's payment page is made for it (an invoice) and the member goes
 *      there.
 *   2. The company tells the site about the payment (IPN) at
 *      /wp-json/y4apk/v1/pay/nowpayments. The message is signed with the IPN
 *      secret (HMAC-SHA512) and is then asked again of the company's API, so
 *      a forged one does nothing. The amount and currency must be the order's.
 *   3. Paid ("finished"): VIP is added to the member's account, or a code made
 *      for them to give; once only, whatever repeats (one UPDATE … WHERE
 *      granted = 0 decides). An email says so.
 *   4. Back on the VIP page the member sees the order: waiting, confirming
 *      (crypto needs a few minutes), done, the gift code to copy.
 *
 * The keys are kept encrypted in the database (Y4APK_Mail::hide), or in
 * wp-config.php: Y4APK_NOWPAYMENTS_KEY and Y4APK_NOWPAYMENTS_IPN.
 */

defined('ABSPATH') || exit;

class Y4APK_Pay {

	const OPTION = 'y4apk_pay';
	const DBV    = '3'; // 2.64: meta (a top-up's or a tip's details); 2.65: expires (the on-site payment's end)
	const ROUTE  = 'y4apk/v1';
	const DONE   = ['finished'];
	const WAIT   = ['new', 'waiting', 'confirming', 'confirmed', 'sending', 'partially_paid'];

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action('rest_api_init', [__CLASS__, 'rest']);
		if (is_admin()) {
			add_action('admin_post_y4apk_pay_test', [__CLASS__, 'handle_test']);
			add_action('admin_post_y4apk_pay_mins', [__CLASS__, 'handle_mins']);
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_orders';
	}

	public static function setup() {
		if (get_option('y4apk_pay_db') === self::DBV) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(20) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(12) NOT NULL DEFAULT 'vip',
			days smallint(5) unsigned NOT NULL DEFAULT 0,
			gift tinyint(1) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'USD',
			provider varchar(20) NOT NULL DEFAULT '',
			ext_id varchar(64) NOT NULL DEFAULT '',
			pay_id varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'new',
			paid varchar(60) NOT NULL DEFAULT '',
			granted tinyint(1) unsigned NOT NULL DEFAULT 0,
			code char(16) NOT NULL DEFAULT '',
			meta text,
			expires int(10) unsigned NOT NULL DEFAULT 0,
			made int(10) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY made (made)
		) " . $wpdb->get_charset_collate() . ';');
		update_option('y4apk_pay_db', self::DBV, false);
	}

	/* ---------------------------------------------------------------- *
	 * Settings and keys
	 * ---------------------------------------------------------------- */

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], ['np_key' => '', 'np_ipn' => '', 'np_sandbox' => 0, 'np_payout' => 'usdttrc20', 'np_onsite' => 1]);
	}

	private static function secret($name) {
		$const = ['np_key' => 'Y4APK_NOWPAYMENTS_KEY', 'np_ipn' => 'Y4APK_NOWPAYMENTS_IPN'][$name];
		if (defined($const) && constant($const) !== '') {
			return trim((string) constant($const));
		}
		$v = (string) self::settings()[$name];
		return trim(class_exists('Y4APK_Mail') ? Y4APK_Mail::reveal($v) : $v);
	}

	/** "…46ZM": the end of a saved key, so the owner can tell which one is stored (2.63.1). */
	public static function tail($name) {
		$v = self::secret($name);
		return $v === '' ? '' : '…' . substr($v, -4);
	}

	public static function in_config() {
		return defined('Y4APK_NOWPAYMENTS_KEY') && defined('Y4APK_NOWPAYMENTS_IPN');
	}

	/** Can people pay on the site? */
	public static function ready() {
		return self::secret('np_key') !== '' && self::secret('np_ipn') !== '';
	}

	public static function save_settings($in) {
		$o = self::settings();
		foreach (['np_key', 'np_ipn'] as $k) {
			$v = trim((string) ($in[$k] ?? ''));
			if ($v === '-') {
				$o[$k] = ''; // "-" empties it
			} elseif ($v !== '') {
				$v = preg_replace('/[^A-Za-z0-9_\-\/+=.]/', '', $v);
				$o[$k] = class_exists('Y4APK_Mail') ? Y4APK_Mail::hide($v) : $v;
			}
		}
		$o['np_sandbox'] = empty($in['np_sandbox']) ? 0 : 1;
		$o['np_payout'] = isset(self::payouts()[$in['np_payout'] ?? '']) ? $in['np_payout'] : 'usdttrc20';
		if (isset($in['np_onsite_set'])) { // 2.65: the payment page on the site, or NOWPayments' own
			$o['np_onsite'] = empty($in['np_onsite']) ? 0 : 1;
		}
		update_option(self::OPTION, $o, false);
	}

	public static function ipn_url() {
		return rest_url(self::ROUTE . '/pay/nowpayments');
	}

	private static function api_base() {
		$base = self::settings()['np_sandbox'] ? 'https://api-sandbox.nowpayments.io/v1' : 'https://api.nowpayments.io/v1';
		return untrailingslashit(apply_filters('y4apk_nowpayments_api', $base));
	}

	private static function api($method, $path, $body = null) {
		$args = ['method' => $method, 'timeout' => 20, 'headers' => ['x-api-key' => self::secret('np_key'), 'Content-Type' => 'application/json']];
		if ($body !== null) {
			$args['body'] = wp_json_encode($body);
		}
		$r = wp_remote_request(self::api_base() . $path, $args);
		if (is_wp_error($r)) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		$json = json_decode((string) wp_remote_retrieve_body($r), true);
		if ($code < 200 || $code >= 300 || !is_array($json)) {
			$msg = is_array($json) && !empty($json['message']) ? (string) $json['message'] : 'HTTP ' . $code;
			return new WP_Error('y4apk_np', $msg, ['code' => $code]);
		}
		return $json;
	}

	/** The coin the money goes out in (NOWPayments ▸ Payout wallets): what minimums are counted towards (2.63.3). */
	public static function payouts() {
		return ['usdttrc20' => 'USDT (TRC20)', 'usdtbsc' => 'USDT (BEP20)', 'usdtsol' => 'USDT (Solana)', 'usdtmatic' => 'USDT (Polygon)', 'usdtton' => 'USDT (TON)',
			'usdcbsc' => 'USDC (BEP20)', 'usdterc20' => 'USDT (ERC20)', 'trx' => 'TRX', 'ltc' => 'LTC', 'bnbbsc' => 'BNB (BSC)', 'sol' => 'SOL', 'btc' => 'BTC', 'eth' => 'ETH'];
	}

	/** Coins people use most, checked first. */
	const POPULAR = ['usdttrc20' => 'USDT (TRC20)', 'usdtbsc' => 'USDT (BEP20)', 'trx' => 'TRX', 'ltc' => 'LTC', 'bnbbsc' => 'BNB (BSC)', 'sol' => 'SOL', 'ton' => 'TON',
		'doge' => 'DOGE', 'xrp' => 'XRP', 'usdcbsc' => 'USDC (BEP20)', 'usdterc20' => 'USDT (ERC20)', 'eth' => 'ETH', 'btc' => 'BTC'];

	/**
	 * The smallest payment NOWPayments takes in each coin, in USD (2.63.3):
	 * below it the payment page says "less than minimal". Asked of the API with
	 * this site's key and kept 12 hours. ['at', 'to', 'rows' => [coin => ['usd', 'err']], 'error'].
	 */
	public static function mins($fresh = false) {
		$c = get_transient('y4apk_pay_mins');
		if (!$fresh) {
			return is_array($c) ? $c : null;
		}
		$to = self::settings()['np_payout'];
		$out = ['at' => time(), 'to' => $to, 'rows' => [], 'cmp' => [], 'error' => ''];
		$t0 = microtime(true);
		$sel = self::api('GET', '/merchant/coins');
		$on = !is_wp_error($sel) && !empty($sel['selectedCurrencies']) ? array_map('strtolower', (array) $sel['selectedCurrencies']) : [];
		$coins = $on ? array_intersect_key(self::POPULAR, array_flip($on)) : self::POPULAR;
		foreach ($coins as $coin => $label) {
			$out['rows'][$coin] = self::min_usd($coin, $to, $t0);
		}
		// Which payout wallet makes paying cheapest (2.63.4): the payout network's fee sets most of the minimum.
		foreach (self::payouts() as $p => $label) {
			$out['cmp'][$p] = $p === $to && isset($out['rows']['usdttrc20']) ? $out['rows']['usdttrc20'] : self::min_usd('usdttrc20', $p, $t0);
		}
		$cheap = function ($a, $b) { return ($a['usd'] ?? PHP_FLOAT_MAX) <=> ($b['usd'] ?? PHP_FLOAT_MAX); };
		uasort($out['rows'], $cheap); // cheapest coin first
		uasort($out['cmp'], $cheap);
		if (!$out['rows'] || count(array_filter(wp_list_pluck($out['rows'], 'usd'), 'is_float')) === 0) {
			$out['error'] = is_wp_error($sel) ? $sel->get_error_message() : __('NOWPayments gave no minimums.', 'y4apk');
		}
		set_transient('y4apk_pay_mins', $out, 12 * HOUR_IN_SECONDS);
		return $out;
	}

	/** One pair's smallest payment in USD: ['usd' => float|null, 'err' => '']. Gives up after 50 seconds in all. */
	private static function min_usd($from, $to, $t0) {
		if (microtime(true) - $t0 > 50) {
			return ['usd' => null, 'err' => __('took too long', 'y4apk')];
		}
		$m = self::api('GET', '/min-amount?' . http_build_query(['currency_from' => $from, 'currency_to' => $to, 'fiat_equivalent' => 'usd']));
		if (is_wp_error($m)) {
			return ['usd' => null, 'err' => $m->get_error_message()];
		}
		$usd = isset($m['fiat_equivalent']) && is_numeric($m['fiat_equivalent']) ? (float) $m['fiat_equivalent'] : null;
		if ($usd === null && !empty($m['min_amount'])) {
			$e = self::api('GET', '/estimate?' . http_build_query(['amount' => $m['min_amount'], 'currency_from' => $from, 'currency_to' => 'usd']));
			$usd = !is_wp_error($e) && isset($e['estimated_amount']) ? (float) $e['estimated_amount'] : null;
		}
		return ['usd' => $usd, 'err' => $usd === null ? __('no answer', 'y4apk') : ''];
	}

	public static function handle_mins() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_pay_mins');
		$p = sanitize_key(wp_unslash($_POST['np_payout'] ?? ''));
		if (isset(self::payouts()[$p])) {
			$o = get_option(self::OPTION, []);
			$o = is_array($o) ? $o : [];
			$o['np_payout'] = $p;
			update_option(self::OPTION, $o, false);
		}
		@set_time_limit(120);
		self::mins(true);
		wp_safe_redirect(add_query_arg(['page' => Y4APK_VIP::SLUG, 'tab' => 'payments'], admin_url('admin.php')) . '#mins');
		exit;
	}

	/** Y4APK ▸ VIP ▸ Settings ▸ Test: the API answers, and the key is accepted. */
	public static function handle_test() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_pay_test');
		$note = 'paytestbad';
		$r = self::api('GET', '/status');
		if (!is_wp_error($r)) {
			$c = self::api('GET', '/currencies');
			$note = is_wp_error($c) ? 'paykeybad' : 'paytestok';
		}
		wp_safe_redirect(add_query_arg(['page' => Y4APK_VIP::SLUG, 'tab' => 'settings', 'note' => $note], admin_url('admin.php')));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Orders
	 * ---------------------------------------------------------------- */

	public static function get($id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
	}

	/**
	 * A member's orders worth showing (2.63.2): paid ones, ones a payment was
	 * started for, and the last hour's. Opening the payment page and leaving
	 * makes an order nobody pays: it is not listed, and ends as expired.
	 */
	public static function for_user($user_id, $limit = 10, $kinds = ['vip']) {
		global $wpdb;
		self::tidy();
		$in = implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, (array) $kinds));
		// 2.63.3: an order shows once a payment was started for it (a coin chosen and sent for), or it is paid
		return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE user_id = %d AND kind IN ($in) AND status NOT IN ('new', 'expired') AND (pay_id <> '' OR status = 'finished') ORDER BY id DESC LIMIT %d",
			(int) $user_id, (int) $limit));
	}

	/** Where the member comes back to from the payment page: the VIP page, or the wallet for a top-up or a tip. */
	public static function back_url($o) {
		$args = ['order' => (int) $o->id, 'k' => (string) $o->token];
		return $o->kind !== 'vip' && class_exists('Y4APK_Wallet') ? Y4APK_Wallet::url($args) : Y4APK_VIP::url($args);
	}

	/** An order's details (2.64): what a top-up or a tip carries. */
	public static function meta($o) {
		$m = json_decode((string) ($o->meta ?? ''), true);
		return is_array($m) ? $m : [];
	}

	/** Orders never paid: expired after 6 hours (no payment started) or 7 days (a payment started, never finished). */
	public static function tidy() {
		global $wpdb;
		$t = self::table();
		$now = time();
		$wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired', updated = %d WHERE status IN ('new', 'waiting') AND pay_id = '' AND made < %d", $now, $now - 6 * HOUR_IN_SECONDS));
		$wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired', updated = %d WHERE status = 'waiting' AND pay_id <> '' AND made < %d", $now, $now - 7 * DAY_IN_SECONDS));
		// 2.65: a payment made on the site has an end; two hours after it, nobody is paying it any more
		$wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired', updated = %d WHERE status = 'waiting' AND expires > 0 AND expires < %d", $now, $now - 2 * HOUR_IN_SECONDS));
	}

	/**
	 * A member buys a plan: the order, then the company's payment page.
	 * The address to send them to, or a WP_Error.
	 */
	public static function start($user_id, $plan, $gift) {
		return self::create($user_id, 'vip', (float) $plan['price'], ['days' => (int) $plan['days'], 'gift' => $gift ? 1 : 0], sprintf('VIP %d days', (int) $plan['days']));
	}

	/**
	 * Any order (2.64): 'vip' (days, gift), 'topup' (the wallet) or 'tip'
	 * (meta: to, name, msg). The order, then the company's payment page.
	 * The address to send the member to, or a WP_Error.
	 */
	public static function create($user_id, $kind, $amount, $args, $desc) {
		global $wpdb;
		if (!self::ready()) {
			return new WP_Error('y4apk_pay', __('Paying on the site is not open yet.', 'y4apk'));
		}
		// No more than 10 unpaid orders an hour from one member.
		$recent = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND made > %d AND status IN ('new', 'waiting')", (int) $user_id, time() - HOUR_IN_SECONDS));
		if ($recent >= 10) {
			return new WP_Error('y4apk_pay', __('Too many unpaid orders. Finish one, or try again in an hour.', 'y4apk'));
		}
		$cur = Y4APK_VIP::settings()['currency'];
		$amount = round((float) $amount, 2);
		$token = wp_generate_password(20, false, false);
		$wpdb->insert(self::table(), [
			'token' => $token, 'user_id' => (int) $user_id, 'kind' => $kind, 'days' => (int) ($args['days'] ?? 0), 'gift' => !empty($args['gift']) ? 1 : 0,
			'amount' => $amount, 'currency' => $cur, 'provider' => 'nowpayments', 'status' => 'new',
			'meta' => !empty($args['meta']) ? wp_json_encode($args['meta']) : '', 'made' => time(), 'updated' => time(),
		]);
		$id = (int) $wpdb->insert_id;
		if (!$id) {
			return new WP_Error('y4apk_pay', __('The order could not be saved. Try again.', 'y4apk'));
		}
		$o = self::get($id);
		if (self::settings()['np_onsite']) {
			return self::checkout_url($o); // 2.65: the payment page on this site
		}
		$url = self::invoice($o);
		if (is_wp_error($url)) {
			$wpdb->update(self::table(), ['status' => 'failed', 'updated' => time()], ['id' => $id]);
		}
		return $url;
	}

	/** What an order is for, in plain ASCII for the payment company (its signature is made over it). */
	public static function description($o) {
		if ($o->kind === 'topup') {
			$n = (int) (self::meta($o)['coins'] ?? 0);
			return $n ? $n . ' coins' : 'Balance top-up'; // 2.68: a coin pack
		}
		if ($o->kind === 'tip') {
			return !empty(self::meta($o)['to']) ? 'Tip to a creator' : 'Tip';
		}
		return sprintf('VIP %d days', (int) $o->days) . ($o->gift ? ' (gift)' : '');
	}

	/** NOWPayments' own payment page for an order (there the buyer picks the coin): its address, or a WP_Error. */
	public static function invoice($o) {
		global $wpdb;
		$back = self::back_url($o);
		$r = self::api('POST', '/invoice', [
			'price_amount'      => round((float) $o->amount, 2),
			'price_currency'    => strtolower($o->currency),
			'order_id'          => 'y4-' . $o->id,
			'order_description' => self::description($o),
			'ipn_callback_url'  => self::ipn_url(),
			'success_url'       => $back,
			'cancel_url'        => add_query_arg('cancel', 1, $back),
		]);
		if (is_wp_error($r) || empty($r['invoice_url']) || empty($r['id'])) {
			return new WP_Error('y4apk_pay', __('The payment page could not be opened. Try again in a minute.', 'y4apk') . (is_wp_error($r) && current_user_can('manage_options') ? ' (' . $r->get_error_message() . ')' : ''));
		}
		$wpdb->update(self::table(), ['ext_id' => substr((string) $r['id'], 0, 64), 'status' => $o->status === 'new' ? 'waiting' : $o->status, 'updated' => time()], ['id' => (int) $o->id]);
		return esc_url_raw((string) $r['invoice_url']);
	}

	/* ---------------------------------------------------------------- *
	 * What the payment company says
	 * ---------------------------------------------------------------- */

	public static function rest() {
		register_rest_route(self::ROUTE, '/pay/nowpayments', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'ipn'],
			'permission_callback' => '__return_true',
		]);
		register_rest_route(self::ROUTE, '/pay/state', [ // 2.65: the payment page asks how its payment is going
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'state'],
			'permission_callback' => '__return_true',
		]);
	}

	private static function sort_deep(&$a) {
		ksort($a);
		foreach ($a as &$v) {
			if (is_array($v)) {
				self::sort_deep($v);
			}
		}
	}

	/** NOWPayments' signature: HMAC-SHA512 of the body with its keys sorted, keyed by the IPN secret. */
	public static function signature_ok($raw, $sig) {
		$secret = self::secret('np_ipn');
		$data = json_decode((string) $raw, true);
		if ($secret === '' || !is_array($data) || $sig === '') {
			return false;
		}
		self::sort_deep($data);
		$sig = strtolower(trim((string) $sig));
		// As their PHP example writes it, and as JavaScript does (letters outside ASCII not escaped).
		foreach ([JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
			if (hash_equals(hash_hmac('sha512', (string) json_encode($data, $flags), $secret), $sig)) {
				return true;
			}
		}
		return false;
	}

	public static function ipn(WP_REST_Request $req) {
		$raw = (string) $req->get_body();
		$signed = self::signature_ok($raw, (string) $req->get_header('x-nowpayments-sig'));
		$d = json_decode($raw, true);
		if (!is_array($d)) {
			return new WP_REST_Response(['ok' => false], 400);
		}
		$id = preg_match('/^y4-(\d+)$/', (string) ($d['order_id'] ?? ''), $m) ? (int) $m[1] : 0;
		$order = $id ? self::get($id) : null;
		if (!$order) {
			return new WP_REST_Response(['ok' => true, 'unknown' => true], 200);
		}
		$status = sanitize_key((string) ($d['payment_status'] ?? ''));
		$pay_id = substr(preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($d['payment_id'] ?? '')), 0, 64);
		// Asked again of the company itself (with this site's key): a forged message cannot make a payment up.
		// Without a good signature, only what the company's API says counts.
		$check = $pay_id !== '' && apply_filters('y4apk_pay_recheck', true) ? self::api('GET', '/payment/' . rawurlencode($pay_id)) : new WP_Error('y4apk_np', 'no check');
		if (!is_wp_error($check)) {
			if ((string) ($check['order_id'] ?? '') !== 'y4-' . $order->id) {
				return new WP_REST_Response(['ok' => false], 409);
			}
			$status = sanitize_key((string) ($check['payment_status'] ?? ''));
			$d = array_merge($d, $check);
		} elseif (!$signed || in_array((int) (($check->get_error_data()['code'] ?? 0)), [400, 401, 403, 404], true)) {
			return new WP_REST_Response(['ok' => false], 401); // 2.63.1: a payment NOWPayments does not know, signed or not
		} elseif (in_array($status, self::DONE, true)) {
			// 2.63.1: "paid" counts only when NOWPayments' API says so; asked to send it again later.
			self::update($order, 'confirming', $pay_id, '');
			return new WP_REST_Response(['ok' => false, 'retry' => true], 503);
		}
		self::apply($order, $d);
		return new WP_REST_Response(['ok' => true], 200);
	}

	/** What NOWPayments says about a payment (checked: from its API, or signed) → the order's new state. */
	private static function apply($order, $d) {
		$status = sanitize_key((string) ($d['payment_status'] ?? ''));
		$pay_id = substr(preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($d['payment_id'] ?? '')), 0, 64);
		$same = abs((float) ($d['price_amount'] ?? 0) - (float) $order->amount) < 0.01 && strcasecmp((string) ($d['price_currency'] ?? ''), (string) $order->currency) === 0;
		if (!$same) {
			$status = 'mismatch';
		}
		$paid = trim(((string) ($d['actually_paid'] ?? '')) . ' ' . strtoupper((string) ($d['pay_currency'] ?? '')));
		self::update($order, $status, $pay_id, $paid);
	}

	/** A new state for an order; paid ones are fulfilled, once. */
	public static function update($order, $status, $pay_id = '', $paid = '') {
		global $wpdb;
		if (in_array($order->status, self::DONE, true) && !in_array($status, ['refunded'], true)) {
			$status = $order->status; // a later, older message does not undo a finished payment
		}
		$wpdb->update(self::table(), array_filter([
			'status'  => substr($status, 0, 20),
			'pay_id'  => $pay_id,
			'paid'    => substr($paid, 0, 60),
			'updated' => time(),
		], function ($v) { return $v !== ''; }), ['id' => (int) $order->id]);
		if (in_array($status, self::DONE, true)) {
			self::fulfil((int) $order->id);
		}
	}

	/** VIP on the account, or a code to give: once per order. */
	public static function fulfil($id) {
		global $wpdb;
		if ($wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET granted = 1 WHERE id = %d AND granted = 0', (int) $id)) !== 1) {
			return false;
		}
		$o = self::get($id);
		if ($o->kind !== 'vip') { // 2.64: a top-up or a tip
			return class_exists('Y4APK_Wallet') ? Y4APK_Wallet::order_paid($o) : false;
		}
		$user = get_userdata((int) $o->user_id);
		$ref = sprintf(__('Order #%s', 'y4apk'), $o->id);
		if ($o->gift) {
			$code = Y4APK_VIP::make_one((int) $o->days, sprintf(__('Paid · order #%s', 'y4apk'), $o->id));
			$wpdb->update(self::table(), ['code' => $code], ['id' => (int) $o->id]);
		} elseif ($user) {
			Y4APK_VIP::extend($user->ID, (int) $o->days, 'pay', $ref);
		}
		if ($user && class_exists('Y4APK_Members')) {
			$lines = $o->gift
				? [sprintf(__('Thank you! Your gift code for %s of VIP is below.', 'y4apk'), Y4APK_VIP::plan_label($o->days)), __('Whoever types it on the VIP page gets the days. It works once.', 'y4apk')]
				: [sprintf(__('Thank you! %s of VIP have been added to your account.', 'y4apk'), Y4APK_VIP::plan_label($o->days)), sprintf(__('VIP until %s.', 'y4apk'), wp_date(get_option('date_format'), Y4APK_VIP::until($user->ID)))];
			Y4APK_Members::mail($user->user_email, $o->gift ? __('Your VIP gift code', 'y4apk') : __('You are VIP', 'y4apk'), [
				'title' => $o->gift ? __('Your VIP gift code', 'y4apk') : __('You are VIP', 'y4apk'),
				'hi' => class_exists('Y4APK_Social') ? Y4APK_Social::name($user) : $user->display_name,
				'lines' => $lines, 'card' => $o->gift ? [__('Gift code', 'y4apk'), Y4APK_VIP::pretty($code)] : null, 'button' => [__('Open the VIP page', 'y4apk'), Y4APK_VIP::url()],
				'why' => sprintf(__('You got this email because you paid for VIP (%s).', 'y4apk'), $ref),
			]);
		}
		return true;
	}

	/** For the member: what an order's state means. */
	public static function status_text($o) {
		if (in_array($o->status, self::DONE, true)) {
			if ($o->kind === 'topup') {
				return __('Paid · coins added', 'y4apk');
			}
			if ($o->kind === 'tip') {
				return __('Paid · tip sent', 'y4apk');
			}
			return $o->gift ? __('Paid · your code is ready', 'y4apk') : __('Paid · VIP added', 'y4apk');
		}
		return [
			'new'            => __('Not paid', 'y4apk'),
			'waiting'        => __('Waiting for your payment', 'y4apk'),
			'confirming'     => __('Payment seen, being confirmed', 'y4apk'),
			'confirmed'      => __('Payment confirmed, finishing', 'y4apk'),
			'sending'        => __('Payment confirmed, finishing', 'y4apk'),
			'partially_paid' => __('Less than the price was paid', 'y4apk'),
			'failed'         => __('Payment failed', 'y4apk'),
			'expired'        => __('Expired: nothing was paid', 'y4apk'),
			'refunded'       => __('Refunded', 'y4apk'),
			'mismatch'       => __('Amount did not match: contact us', 'y4apk'),
		][$o->status] ?? $o->status;
	}

	public static function waiting($o) {
		return in_array($o->status, self::WAIT, true) && $o->status !== 'new';
	}

	/* ---------------------------------------------------------------- *
	 * The payment page on the site (2.65): /account/pay/?order=&k=
	 *
	 * The member picks a coin here; NOWPayments makes the payment by API
	 * (POST /payment) and answers with the amount and the address to send it
	 * to, shown with a QR code. The page asks every few seconds how it is
	 * going; the site asks NOWPayments (GET /payment/{id}, at most every 15
	 * seconds per order), so it moves on even if a notice (IPN) is late.
	 * The checks are the IPN's: the order, the amount and the currency must
	 * be the order's.
	 * ---------------------------------------------------------------- */

	/** Coins offered on the page: ticker => [name, network, colour]. */
	const COINS = [
		'usdttrc20' => ['USDT', 'TRC20', '#26a17b'],
		'usdtbsc'   => ['USDT', 'BEP20', '#26a17b'],
		'trx'       => ['TRX', 'TRON', '#ef0027'],
		'ltc'       => ['LTC', 'Litecoin', '#345d9d'],
		'bnbbsc'    => ['BNB', 'BEP20', '#f0b90b'],
		'sol'       => ['SOL', 'Solana', '#9945ff'],
		'ton'       => ['TON', 'TON', '#0098ea'],
		'usdcbsc'   => ['USDC', 'BEP20', '#2775ca'],
		'doge'      => ['DOGE', 'Dogecoin', '#c2a633'],
		'xrp'       => ['XRP', 'XRP Ledger', '#23292f'],
		'usdterc20' => ['USDT', 'ERC20', '#26a17b'],
		'eth'       => ['ETH', 'Ethereum', '#627eea'],
		'btc'       => ['BTC', 'Bitcoin', '#f7931a'],
	];

	public static function checkout_url($o) {
		return Y4APK_Members::url('pay', ['order' => (int) $o->id, 'k' => (string) $o->token]);
	}

	/**
	 * The coins this account takes (NOWPayments ▸ Coins), in the order above:
	 * ticker => [name, network, colour, smallest payment in dollars or null].
	 * The smallest payment comes from the owner's last "Check minimums".
	 */
	public static function coins() {
		$on = get_transient('y4apk_pay_coins');
		if (!is_array($on)) {
			$r = self::api('GET', '/merchant/coins');
			$on = !is_wp_error($r) && !empty($r['selectedCurrencies']) ? array_map('strtolower', (array) $r['selectedCurrencies']) : [];
			set_transient('y4apk_pay_coins', $on, $on ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
		}
		$mins = self::mins();
		$out = [];
		foreach (self::COINS as $k => $c) {
			if ($on && !in_array($k, $on, true)) {
				continue;
			}
			$c[] = $mins && isset($mins['rows'][$k]) ? $mins['rows'][$k]['usd'] : null;
			$out[$k] = $c;
		}
		return $out;
	}

	/** Pay an order with a coin: NOWPayments makes the payment; true, or a WP_Error for the member. */
	public static function start_payment($o, $coin) {
		global $wpdb;
		$coins = self::coins();
		if (!isset($coins[$coin])) {
			return new WP_Error('y4apk_pay', __('Choose a coin.', 'y4apk'));
		}
		$r = self::api('POST', '/payment', [
			'price_amount'      => round((float) $o->amount, 2),
			'price_currency'    => strtolower($o->currency),
			'pay_currency'      => $coin,
			'order_id'          => 'y4-' . $o->id,
			'order_description' => self::description($o),
			'ipn_callback_url'  => self::ipn_url(),
		]);
		if (is_wp_error($r) || empty($r['payment_id']) || empty($r['pay_address'])) {
			$msg = is_wp_error($r) ? $r->get_error_message() : '';
			if (stripos($msg, 'minimal') !== false || stripos($msg, 'too small') !== false || stripos($msg, 'less than') !== false) {
				return new WP_Error('y4apk_pay', sprintf(__('%s needs a bigger payment than this. Choose USDT (TRC20) or another coin.', 'y4apk'), $coins[$coin][0] . ' (' . $coins[$coin][1] . ')'));
			}
			return new WP_Error('y4apk_pay', __('That coin cannot be used right now. Try another one, or again in a minute.', 'y4apk') . ($msg !== '' && current_user_can('manage_options') ? ' (' . $msg . ')' : ''));
		}
		$meta = array_merge(self::meta($o), ['coin' => $coin, 'address' => substr((string) $r['pay_address'], 0, 140), 'amount' => (string) $r['pay_amount'],
			'extra' => substr((string) ($r['payin_extra_id'] ?? ''), 0, 80), 'network' => substr((string) ($r['network'] ?? ''), 0, 30)]);
		$exp = !empty($r['expiration_estimate_date']) ? (int) strtotime((string) $r['expiration_estimate_date']) : 0;
		$wpdb->update(self::table(), [
			'pay_id' => substr(preg_replace('/[^0-9A-Za-z_-]/', '', (string) $r['payment_id']), 0, 64), 'status' => 'waiting', 'meta' => wp_json_encode($meta),
			'expires' => $exp > time() ? $exp : time() + 20 * MINUTE_IN_SECONDS, 'updated' => time(),
		], ['id' => (int) $o->id]);
		return true;
	}

	/** Ask NOWPayments how the order's payment is going (at most every 15 seconds); the order as it is now. */
	public static function sync($o) {
		if (!$o || $o->pay_id === '' || in_array($o->status, array_merge(self::DONE, ['refunded', 'mismatch']), true)) {
			return $o;
		}
		$k = 'y4apk_pay_sync_' . (int) $o->id;
		if (get_transient($k)) {
			return $o;
		}
		set_transient($k, 1, 15);
		$r = self::api('GET', '/payment/' . rawurlencode($o->pay_id));
		if (is_wp_error($r) || (string) ($r['order_id'] ?? '') !== 'y4-' . $o->id) {
			return $o;
		}
		self::apply($o, $r);
		return self::get($o->id);
	}

	/** The order behind a page's ?order=&k=, if the token is right. */
	private static function by_token($id, $k) {
		$o = $id ? self::get((int) $id) : null;
		return $o && $k !== '' && hash_equals((string) $o->token, (string) $k) ? $o : null;
	}

	/** GET /wp-json/y4apk/v1/pay/state?o=&k= — for the payment page's own checks. */
	public static function state(WP_REST_Request $req) {
		nocache_headers();
		$o = self::by_token(absint($req->get_param('o')), (string) $req->get_param('k'));
		if (!$o) {
			return new WP_REST_Response(['ok' => false], 404);
		}
		$o = self::sync($o);
		$done = in_array($o->status, self::DONE, true);
		return new WP_REST_Response([
			'ok' => true, 'status' => $o->status, 'text' => self::status_text($o), 'done' => $done,
			'wait' => self::waiting($o), 'back' => $done ? self::back_url($o) : '',
		], 200);
	}

	/** What the order buys, for the member. */
	public static function what($o) {
		if ($o->kind === 'topup') {
			$n = (int) (self::meta($o)['coins'] ?? 0); // 2.68
			return $n && class_exists('Y4APK_Wallet') ? Y4APK_Wallet::fmt_coins(Y4APK_Wallet::from_coins($n)) : __('Balance top-up', 'y4apk');
		}
		if ($o->kind === 'tip') {
			$to = (int) (self::meta($o)['to'] ?? 0);
			$u = $to ? get_userdata($to) : null;
			return $u ? sprintf(__('Tip to @%s', 'y4apk'), $u->user_login) : __('Tip', 'y4apk');
		}
		return sprintf($o->gift ? __('VIP gift code · %s', 'y4apk') : __('VIP · %s', 'y4apk'), Y4APK_VIP::plan_label($o->days));
	}

	/** /account/pay/ (Y4APK_Members routes it here). */
	public static function route(&$ctx) {
		$ctx['screen'] = 'pay';
		$uid = (int) $ctx['user']->ID;
		$o = self::by_token(absint($_REQUEST['order'] ?? 0), isset($_REQUEST['k']) ? (string) wp_unslash($_REQUEST['k']) : '');
		if (!$o || (int) $o->user_id !== $uid) {
			$ctx['screen'] = 'notfound';
			return;
		}
		if (in_array($o->status, self::DONE, true)) {
			wp_safe_redirect(self::back_url($o)); // paid: the VIP page or the wallet says what it gave
			exit;
		}
		$do = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do']) ? sanitize_key(wp_unslash($_POST['y4_do'])) : '';
		if ($do !== '' && !wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_pay')) {
			$ctx['errors'][] = __('The page was open too long. Try again.', 'y4apk');
			$do = '';
		}
		if ($do === 'pay_coin') {
			$r = self::start_payment($o, sanitize_key(wp_unslash($_POST['coin'] ?? '')));
			if (is_wp_error($r)) {
				$ctx['errors'][] = $r->get_error_message();
			} else {
				wp_safe_redirect(self::checkout_url($o));
				exit;
			}
		} elseif ($do === 'pay_change') {
			$meta = self::meta($o);
			unset($meta['coin'], $meta['address'], $meta['amount'], $meta['extra'], $meta['network']);
			global $wpdb;
			$wpdb->update(self::table(), ['meta' => $meta ? wp_json_encode($meta) : '', 'expires' => 0, 'updated' => time()], ['id' => (int) $o->id]);
			wp_safe_redirect(self::checkout_url($o));
			exit;
		} elseif ($do === 'pay_page') {
			$url = self::invoice($o);
			if (is_wp_error($url)) {
				$ctx['errors'][] = $url->get_error_message();
			} else {
				wp_redirect($url);
				exit;
			}
		}
		$o = self::sync(self::get($o->id));
		if (in_array($o->status, self::DONE, true)) {
			wp_safe_redirect(self::back_url($o));
			exit;
		}
		$meta = self::meta($o);
		$live = !empty($meta['address']) && (int) $o->expires > time() - 2 * HOUR_IN_SECONDS && !in_array($o->status, ['expired', 'failed'], true);
		$ctx['pay'] = [
			'order' => $o, 'meta' => $meta, 'live' => $live, 'coins' => $live ? [] : self::coins(),
			'what' => self::what($o), 'back' => self::back_url($o),
			'state' => add_query_arg(['o' => (int) $o->id, 'k' => (string) $o->token], rest_url(self::ROUTE . '/pay/state')),
		];
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-pay', get_template_directory_uri() . '/assets/css/pay.css', ['y4apk-account'], Y4APK_THEME_VERSION);
			wp_enqueue_script('y4apk-qr', get_template_directory_uri() . '/assets/js/qrcode.min.js', [], Y4APK_THEME_VERSION, true);
		}, 20);
	}
}

Y4APK_Pay::init();
