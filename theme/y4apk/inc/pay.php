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
	const DBV    = '1';
	const ROUTE  = 'y4apk/v1';
	const DONE   = ['finished'];
	const WAIT   = ['new', 'waiting', 'confirming', 'confirmed', 'sending', 'partially_paid'];

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action('rest_api_init', [__CLASS__, 'rest']);
		if (is_admin()) {
			add_action('admin_post_y4apk_pay_test', [__CLASS__, 'handle_test']);
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
		return wp_parse_args(is_array($o) ? $o : [], ['np_key' => '', 'np_ipn' => '', 'np_sandbox' => 0]);
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

	public static function for_user($user_id, $limit = 10) {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE user_id = %d AND status <> 'new' ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit));
	}

	/**
	 * A member buys a plan: the order, then the company's payment page.
	 * The address to send them to, or a WP_Error.
	 */
	public static function start($user_id, $plan, $gift) {
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
		$token = wp_generate_password(20, false, false);
		$wpdb->insert(self::table(), [
			'token' => $token, 'user_id' => (int) $user_id, 'kind' => 'vip', 'days' => (int) $plan['days'], 'gift' => $gift ? 1 : 0,
			'amount' => round((float) $plan['price'], 2), 'currency' => $cur, 'provider' => 'nowpayments', 'status' => 'new', 'made' => time(), 'updated' => time(),
		]);
		$id = (int) $wpdb->insert_id;
		if (!$id) {
			return new WP_Error('y4apk_pay', __('The order could not be saved. Try again.', 'y4apk'));
		}
		$back = Y4APK_VIP::url(['order' => $id, 'k' => $token]);
		$r = self::api('POST', '/invoice', [
			'price_amount'      => round((float) $plan['price'], 2),
			'price_currency'    => strtolower($cur),
			'order_id'          => 'y4-' . $id,
			'order_description' => sprintf('VIP %d days', (int) $plan['days']), // plain ASCII: the IPN signature is made over it
			'ipn_callback_url'  => self::ipn_url(),
			'success_url'       => $back,
			'cancel_url'        => add_query_arg('cancel', 1, $back),
		]);
		if (is_wp_error($r) || empty($r['invoice_url']) || empty($r['id'])) {
			$wpdb->update(self::table(), ['status' => 'failed', 'updated' => time()], ['id' => $id]);
			return new WP_Error('y4apk_pay', __('The payment page could not be opened. Try again in a minute.', 'y4apk') . (is_wp_error($r) && current_user_can('manage_options') ? ' (' . $r->get_error_message() . ')' : ''));
		}
		$wpdb->update(self::table(), ['ext_id' => substr((string) $r['id'], 0, 64), 'status' => 'waiting', 'updated' => time()], ['id' => $id]);
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
		$same = abs((float) ($d['price_amount'] ?? 0) - (float) $order->amount) < 0.01 && strcasecmp((string) ($d['price_currency'] ?? ''), (string) $order->currency) === 0;
		if (!$same) {
			$status = 'mismatch';
		}
		$paid = trim(((string) ($d['actually_paid'] ?? '')) . ' ' . strtoupper((string) ($d['pay_currency'] ?? '')));
		self::update($order, $status, $pay_id, $paid);
		return new WP_REST_Response(['ok' => true], 200);
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
}

Y4APK_Pay::init();
