<?php
/**
 * Update notifications: visitors follow an app from its post ("Notify me when
 * updated"), and when the post's APK version goes up — saved in App updates or
 * in the post editor — every follower gets a notification on their phone or
 * computer, in the Y4APK app too.
 *
 * Standard Web Push: the site identifies itself to the browser's push service
 * with VAPID (RFC 8292) and encrypts each message for that one browser
 * (RFC 8291, aes128gcm). The service worker (assets/js/pwa-sw.js) shows them,
 * so this needs Y4APK ▸ App switched on.
 *
 * Tables: {prefix}y4apk_push_subs (one row per browser), _follows (browser ×
 * post) and _queue (messages still to send, sent 100 at a time in parallel).
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Push {

	const OPTION       = 'y4apk_push';
	const LOG          = 'y4apk_push_log';
	const DB_VERSION   = '1';
	const BATCH        = 100;
	const MAX_FOLLOWS  = 300;
	const VERSION_META = '_y4apk_push_version'; // highest version followers were told about
	const HOOK         = 'y4apk_push_send';
	const TTL          = 172800; // push services keep an undelivered message two days

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);
		add_action('save_post', [__CLASS__, 'saved'], 100, 2);
		add_action(self::HOOK, [__CLASS__, 'process']);
		add_action('y4apk_after_download_links', [__CLASS__, 'button']);
		add_action('before_delete_post', [__CLASS__, 'post_deleted']);
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'install']);
			add_action('admin_post_y4apk_push_settings', [__CLASS__, 'handle_settings']);
		}
	}

	public static function enabled() {
		$o = get_option(self::OPTION, []);
		return !isset($o['on']) || $o['on'];
	}

	/** On, and the service worker that shows notifications is there. */
	public static function ready() {
		return self::enabled() && class_exists('Y4APK_PWA') && Y4APK_PWA::get('enabled');
	}

	private static function table($name) {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_push_' . $name;
	}

	private static function installed() {
		return get_option('y4apk_push_db') === self::DB_VERSION;
	}

	public static function install() {
		if (self::installed()) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta('CREATE TABLE ' . self::table('subs') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hash char(40) NOT NULL,
			endpoint text NOT NULL,
			p256dh varchar(100) NOT NULL,
			auth varchar(40) NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash)
		) $charset;");
		dbDelta('CREATE TABLE ' . self::table('follows') . " (
			sub_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (sub_id,post_id),
			KEY post_id (post_id)
		) $charset;");
		dbDelta('CREATE TABLE ' . self::table('queue') . " (
			sub_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			version varchar(60) NOT NULL,
			tries tinyint(3) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (sub_id,post_id)
		) $charset;");
		update_option('y4apk_push_db', self::DB_VERSION, true);
	}

	/* ---------------------------------------------------------------- *
	 * Keys and encryption
	 * ---------------------------------------------------------------- */

	public static function b64e($bin) {
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	public static function b64d($text) {
		$text = strtr((string) $text, '-_', '+/');
		return (string) base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
	}

	/** A raw P-256 public key (0x04 || x || y) as PEM, for OpenSSL. */
	private static function pem($point) {
		$der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	/** [private key resource, raw public key], or null when OpenSSL cannot make one. */
	private static function new_key() {
		$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
		if (!$key) {
			return null;
		}
		$d = openssl_pkey_get_details($key);
		return [$key, "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
	}

	/** The site's VAPID key pair, made the first time it is needed: ['public' => base64url, 'private' => PEM]. */
	public static function vapid() {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		if (empty($o['public']) || empty($o['private'])) {
			$k = self::new_key();
			if (!$k || !openssl_pkey_export($k[0], $pem)) {
				return null;
			}
			$o['public'] = self::b64e($k[1]);
			$o['private'] = $pem;
			update_option(self::OPTION, $o, false);
		}
		return $o;
	}

	/** An ECDSA signature from OpenSSL (DER) as JWT wants it (r || s, 32 bytes each). */
	private static function der_to_raw($der) {
		$pos = 2;
		$out = '';
		for ($i = 0; $i < 2; $i++) {
			$len = ord($der[$pos + 1]);
			$int = ltrim(substr($der, $pos + 2, $len), "\0");
			$out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
			$pos += 2 + $len;
		}
		return $out;
	}

	/** Authorization header for one push service (VAPID, valid 12 hours). */
	public static function vapid_header($endpoint, $vapid) {
		$p = wp_parse_url($endpoint);
		$aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
		$flags = JSON_UNESCAPED_SLASHES;
		$data = self::b64e(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256'], $flags)) . '.'
			. self::b64e(wp_json_encode(['aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => home_url('/')], $flags));
		if (!openssl_sign($data, $der, $vapid['private'], OPENSSL_ALGO_SHA256)) {
			return '';
		}
		return 'vapid t=' . $data . '.' . self::b64e(self::der_to_raw($der)) . ', k=' . $vapid['public'];
	}

	/** The message encrypted for one browser (RFC 8291, aes128gcm), or false. */
	public static function encrypt($payload, $p256dh, $auth) {
		$ua_public = self::b64d($p256dh);
		$secret = self::b64d($auth);
		if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04" || strlen($secret) !== 16) {
			return false;
		}
		$k = self::new_key();
		$peer = openssl_pkey_get_public(self::pem($ua_public));
		$shared = $k && $peer ? openssl_pkey_derive($peer, $k[0]) : false;
		if (!is_string($shared) || strlen($shared) !== 32) {
			return false;
		}
		$ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua_public . $k[1], $secret);
		$salt = random_bytes(16);
		$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
		$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
		$tag = '';
		$sealed = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
		if ($sealed === false) {
			return false;
		}
		return $salt . pack('N', 4096) . chr(65) . $k[1] . $sealed . $tag;
	}

	/* ---------------------------------------------------------------- *
	 * Following
	 * ---------------------------------------------------------------- */

	/** Push services browsers use; the site only ever sends to these. */
	public static function valid_endpoint($url) {
		$p = wp_parse_url((string) $url);
		if (!$p || empty($p['host']) || strlen($url) > 1000) {
			return false;
		}
		$scheme = isset($p['scheme']) ? $p['scheme'] : '';
		if ($scheme !== 'https' && !($scheme === 'http' && apply_filters('y4apk_push_allow_http', false))) {
			return false;
		}
		$host = strtolower($p['host']);
		$hosts = apply_filters('y4apk_push_hosts', ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com']);
		foreach ($hosts as $h) {
			if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) {
				return true;
			}
		}
		return false;
	}

	private static function same_site() {
		$home = wp_parse_url(home_url('/'), PHP_URL_HOST);
		foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
			if (!empty($_SERVER[$h])) {
				return wp_parse_url(wp_unslash($_SERVER[$h]), PHP_URL_HOST) === $home;
			}
		}
		return false;
	}

	public static function routes() {
		register_rest_route('y4apk/v1', '/follow', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [__CLASS__, 'rest_follow'],
		]);
	}

	/** POST {post, follow, subscription: {endpoint, keys: {p256dh, auth}}} from the post's button. */
	public static function rest_follow(WP_REST_Request $request) {
		global $wpdb;
		if (!self::ready()) {
			return new WP_Error('y4apk_push_off', 'Update notifications are off.', ['status' => 403]);
		}
		if (!self::same_site()) {
			return new WP_Error('y4apk_push_origin', 'Not from this site.', ['status' => 403]);
		}
		$post = absint($request->get_param('post'));
		if (!$post || get_post_type($post) !== 'post' || get_post_status($post) !== 'publish') {
			return new WP_Error('y4apk_push_post', 'No such post.', ['status' => 404]);
		}
		$sub = (array) $request->get_param('subscription');
		$endpoint = isset($sub['endpoint']) ? (string) $sub['endpoint'] : '';
		if (!self::valid_endpoint($endpoint)) {
			return new WP_Error('y4apk_push_endpoint', 'Not a browser push address.', ['status' => 400]);
		}
		self::install();
		$hash = sha1($endpoint);
		$id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('subs') . ' WHERE hash = %s', $hash));

		if (rest_sanitize_boolean($request->get_param('follow'))) {
			$p256dh = isset($sub['keys']['p256dh']) ? (string) $sub['keys']['p256dh'] : '';
			$auth = isset($sub['keys']['auth']) ? (string) $sub['keys']['auth'] : '';
			$raw = self::b64d($p256dh);
			if (strlen($raw) !== 65 || $raw[0] !== "\x04" || strlen(self::b64d($auth)) !== 16) {
				return new WP_Error('y4apk_push_keys', 'Not a browser push key.', ['status' => 400]);
			}
			if ($id) {
				$wpdb->update(self::table('subs'), ['p256dh' => $p256dh, 'auth' => $auth], ['id' => $id]);
			} else {
				$wpdb->insert(self::table('subs'), ['hash' => $hash, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'created' => current_time('mysql', true)]);
				$id = (int) $wpdb->insert_id;
			}
			$exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('follows') . ' WHERE sub_id = %d AND post_id = %d', $id, $post));
			if (!$exists) {
				if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('follows') . ' WHERE sub_id = %d', $id)) >= self::MAX_FOLLOWS) {
					return new WP_Error('y4apk_push_many', 'Following too many apps.', ['status' => 429]);
				}
				$wpdb->insert(self::table('follows'), ['sub_id' => $id, 'post_id' => $post, 'created' => current_time('mysql', true)]);
			}
			// What the follower has now: the next higher version is news.
			if ((string) get_post_meta($post, self::VERSION_META, true) === '') {
				$n = Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($post));
				if ($n !== '') {
					update_post_meta($post, self::VERSION_META, $n);
				}
			}
			$following = true;
		} else {
			if ($id) {
				$wpdb->delete(self::table('follows'), ['sub_id' => $id, 'post_id' => $post]);
				$wpdb->delete(self::table('queue'), ['sub_id' => $id, 'post_id' => $post]);
				if (!(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('follows') . ' WHERE sub_id = %d', $id))) {
					self::drop_sub($id);
				}
			}
			$following = false;
		}
		$followers = self::followers_of([$post]);
		return ['following' => $following, 'followers' => isset($followers[$post]) ? $followers[$post] : 0];
	}

	/** Followers per post, for the given posts. */
	public static function followers_of(array $ids) {
		global $wpdb;
		$ids = array_filter(array_map('intval', $ids));
		if (!$ids || !self::installed()) {
			return [];
		}
		$out = [];
		foreach ($wpdb->get_results('SELECT post_id, COUNT(*) AS n FROM ' . self::table('follows') . ' WHERE post_id IN (' . implode(',', $ids) . ') GROUP BY post_id') as $r) {
			$out[(int) $r->post_id] = (int) $r->n;
		}
		return $out;
	}

	/** [post id => followers], most followed first. */
	public static function most_followed($limit) {
		global $wpdb;
		if (!self::installed()) {
			return [];
		}
		$out = [];
		foreach ($wpdb->get_results($wpdb->prepare('SELECT post_id, COUNT(*) AS n FROM ' . self::table('follows') . ' GROUP BY post_id ORDER BY n DESC LIMIT %d', $limit)) as $r) {
			$out[(int) $r->post_id] = (int) $r->n;
		}
		return $out;
	}

	private static function drop_sub($id) {
		global $wpdb;
		foreach (['follows', 'queue'] as $t) {
			$wpdb->delete(self::table($t), ['sub_id' => $id]);
		}
		$wpdb->delete(self::table('subs'), ['id' => $id]);
	}

	public static function post_deleted($post_id) {
		global $wpdb;
		if (self::installed()) {
			$wpdb->delete(self::table('follows'), ['post_id' => $post_id]);
			$wpdb->delete(self::table('queue'), ['post_id' => $post_id]);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Sending
	 * ---------------------------------------------------------------- */

	/** After any save: a higher APK version than followers last heard of is news. */
	public static function saved($post_id, $post) {
		if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		$n = Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($post_id));
		if ($n === '') {
			return;
		}
		// A download folder still waiting for its file (Write with AI put Google Play's newer version in):
		// nothing to download yet, so no "updated" message until the file is there.
		$waiting = (string) get_post_meta($post_id, Y4APK_App_Updates::NO_FILE, true);
		if ($waiting !== '') {
			list(, $link) = Y4APK_App_Updates::download($post_id);
			if ($link && html_entity_decode((string) $link['download_url']) === $waiting) {
				return;
			}
		}
		$known = (string) get_post_meta($post_id, self::VERSION_META, true);
		if ($known !== '' && !version_compare($n, $known, '>')) {
			return;
		}
		update_post_meta($post_id, self::VERSION_META, $n);
		if ($known !== '') {
			self::queue($post_id, $n);
		}
	}

	/** Queue the message for every follower, and send the first batch right away. */
	public static function queue($post_id, $version) {
		global $wpdb;
		if (!self::enabled() || !self::installed()) {
			return 0;
		}
		$subs = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT sub_id FROM ' . self::table('follows') . ' WHERE post_id = %d', $post_id)));
		if (!$subs) {
			return 0;
		}
		$now = current_time('mysql', true);
		foreach (array_chunk($subs, 200) as $chunk) {
			$values = [];
			foreach ($chunk as $sub) {
				$values[] = $wpdb->prepare('(%d, %d, %s, 0, %s)', $sub, $post_id, $version, $now);
			}
			$wpdb->query('INSERT INTO ' . self::table('queue') . ' (sub_id, post_id, version, tries, created) VALUES ' . implode(',', $values)
				. ' ON DUPLICATE KEY UPDATE version = VALUES(version), tries = 0');
		}
		$log = get_option(self::LOG, []);
		$log = is_array($log) ? $log : [];
		array_unshift($log, ['t' => time(), 'post' => (int) $post_id, 'version' => $version, 'queued' => count($subs), 'sent' => 0, 'gone' => 0, 'failed' => 0]);
		update_option(self::LOG, array_slice($log, 0, 20), false);

		self::process();
		return count($subs);
	}

	private static function payload($post_id, $version) {
		$name = trim((string) get_post_meta($post_id, 'wp_title_GP', true));
		$name = $name !== '' ? $name : wp_strip_all_tags(get_the_title($post_id));
		return wp_json_encode([
			'title' => sprintf(__('%s has an update', 'y4apk'), $name),
			'body'  => sprintf(__('Version %s is ready to download.', 'y4apk'), $version),
			'url'   => get_permalink($post_id),
			'icon'  => (string) get_the_post_thumbnail_url($post_id, 'thumbnail'),
			'tag'   => 'y4apk-' . $post_id,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/** Send up to $limit queued messages in parallel. Number sent. */
	public static function process($limit = self::BATCH) {
		global $wpdb;
		if (!self::installed()) {
			return 0;
		}
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT q.sub_id, q.post_id, q.version, q.tries, s.endpoint, s.p256dh, s.auth FROM ' . self::table('queue') . ' q
			 JOIN ' . self::table('subs') . ' s ON s.id = q.sub_id ORDER BY q.created LIMIT %d', $limit ?: self::BATCH));
		$vapid = $rows ? self::vapid() : null;
		if (!$rows || !$vapid) {
			return 0;
		}
		$payloads = [];
		$requests = [];
		foreach ($rows as $i => $r) {
			$key = $r->post_id . '|' . $r->version;
			if (!isset($payloads[$key])) {
				$payloads[$key] = self::payload((int) $r->post_id, $r->version);
			}
			$body = self::encrypt($payloads[$key], $r->p256dh, $r->auth);
			$auth = $body !== false ? self::vapid_header($r->endpoint, $vapid) : '';
			if ($auth === '') {
				continue; // unusable keys: dropped below
			}
			$requests[$i] = [
				'url'     => $r->endpoint,
				'type'    => 'POST',
				'data'    => $body,
				'headers' => [
					'Authorization'    => $auth,
					'TTL'              => (string) self::TTL,
					'Urgency'          => 'normal',
					'Topic'            => 'y4apk-' . (int) $r->post_id, // a newer update replaces one not yet delivered
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
				],
			];
		}
		$codes = self::send_all($requests);

		$count = [];
		$sent = 0;
		foreach ($rows as $i => $r) {
			$code = isset($codes[$i]) ? $codes[$i] : -1;
			$k = $r->post_id . '|' . $r->version;
			$count[$k] = isset($count[$k]) ? $count[$k] : ['sent' => 0, 'gone' => 0, 'failed' => 0];
			$done = ['sub_id' => $r->sub_id, 'post_id' => $r->post_id];
			if ($code >= 200 && $code < 300) {
				$wpdb->delete(self::table('queue'), $done);
				$count[$k]['sent']++;
				$sent++;
			} elseif ($code === 404 || $code === 410) { // unsubscribed or expired: forget that browser
				self::drop_sub((int) $r->sub_id);
				$count[$k]['gone']++;
			} elseif ($code === -1 || in_array($code, [400, 401, 403, 413], true) || $r->tries >= 2) {
				$wpdb->delete(self::table('queue'), $done);
				$count[$k]['failed']++;
			} else { // busy or unreachable: try again later
				$wpdb->update(self::table('queue'), ['tries' => $r->tries + 1], $done);
			}
		}
		$log = get_option(self::LOG, []);
		if (is_array($log)) {
			foreach ($log as &$entry) {
				$k = $entry['post'] . '|' . $entry['version'];
				if (isset($count[$k])) {
					foreach (['sent', 'gone', 'failed'] as $f) {
						$entry[$f] += $count[$k][$f];
					}
					unset($count[$k]);
				}
			}
			unset($entry);
			update_option(self::LOG, $log, false);
		}
		if ((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('queue')) && !wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
		}
		return $sent;
	}

	/** [index => HTTP status, 0 when unreachable], sent in parallel. */
	private static function send_all($requests) {
		if (!$requests) {
			return [];
		}
		$codes = [];
		$class = class_exists('\WpOrg\Requests\Requests') ? '\WpOrg\Requests\Requests' : (class_exists('Requests') ? 'Requests' : '');
		if ($class) {
			foreach ($class::request_multiple($requests, ['timeout' => 10, 'connect_timeout' => 5]) as $i => $res) {
				$codes[$i] = is_object($res) && isset($res->status_code) ? (int) $res->status_code : 0;
			}
			return $codes;
		}
		foreach ($requests as $i => $r) {
			$res = wp_remote_post($r['url'], ['headers' => $r['headers'], 'body' => $r['data'], 'timeout' => 10]);
			$codes[$i] = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
		}
		return $codes;
	}

	/* ---------------------------------------------------------------- *
	 * The post's button
	 * ---------------------------------------------------------------- */

	public static function button($post_id) {
		$vapid = self::ready() && get_post_status($post_id) === 'publish' ? self::vapid() : null;
		if (!$vapid) {
			return;
		}
		$config = [
			'post'     => (int) $post_id,
			'endpoint' => esc_url_raw(rest_url('y4apk/v1/follow')),
			'key'      => $vapid['public'],
			'off'      => __('Notify me when updated', 'y4apk'),
			'on'       => __('Notifications on for this app', 'y4apk'),
			'done'     => __('Done. You’ll get a notification when a new version is out.', 'y4apk'),
			'blocked'  => __('Notifications are blocked for this site. Allow them in your browser’s site settings, then try again.', 'y4apk'),
			'error'    => __('That did not work. Please try again.', 'y4apk'),
		];
		?>
		<style>
			.y4apk-follow { margin: 14px auto 0; text-align: center; }
			.y4apk-follow-btn { display: inline-flex; align-items: center; gap: .5rem; height: auto; min-height: 2.5rem; padding: .5rem 1.25rem; border: 2px solid #29b1d8; border-radius: 1.5rem; background: transparent; color: #29b1d8; font-weight: 700; font-size: .875rem; cursor: pointer; }
			.y4apk-follow.is-on .y4apk-follow-btn { background: #29b1d8; color: #fff; }
			.y4apk-follow-btn:disabled { opacity: .6; cursor: wait; }
			.y4apk-follow-note { margin: 8px 0 0; font-size: .8125rem; opacity: .8; }
		</style>
		<div class="y4apk-follow" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>" hidden>
			<button type="button" class="y4apk-follow-btn" aria-pressed="false"><span aria-hidden="true">🔔</span> <span class="y4apk-follow-label"><?php echo esc_html($config['off']); ?></span></button>
			<p class="y4apk-follow-note" role="status" hidden></p>
		</div>
		<script id="y4apk-follow-js" data-no-optimize="1" data-cfasync="false" data-no-defer="1">
		(function () {
			var box = document.querySelector('.y4apk-follow');
			if (!box || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
			var cfg = JSON.parse(box.getAttribute('data-config')), post = String(cfg.post), KEY = 'y4apk_follows';
			var btn = box.querySelector('button'), label = box.querySelector('.y4apk-follow-label'), note = box.querySelector('.y4apk-follow-note');
			function list() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
			function keep(l) { try { localStorage.setItem(KEY, JSON.stringify(l)); } catch (e) {} }
			function paint() {
				var on = list().indexOf(post) !== -1 && Notification.permission === 'granted';
				box.classList.toggle('is-on', on);
				label.textContent = on ? cfg.on : cfg.off;
				btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			}
			function say(text) { note.textContent = text || ''; note.hidden = !text; }
			function bytes(s) {
				var b = atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(b.length);
				for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i);
				return a;
			}
			function subscription() {
				var ready = Promise.race([navigator.serviceWorker.ready, new Promise(function (_, no) { setTimeout(function () { no(new Error('sw')); }, 10000); })]);
				return ready.then(function (reg) {
					return reg.pushManager.getSubscription().then(function (s) {
						return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(cfg.key) });
					});
				});
			}
			function send(sub, follow) {
				return fetch(cfg.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ post: cfg.post, follow: follow, subscription: sub.toJSON() }) })
					.then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); });
			}
			btn.addEventListener('click', function () {
				var following = box.classList.contains('is-on');
				btn.disabled = true;
				say('');
				var go = following
					? subscription().then(function (s) { return send(s, false); }).then(function () { keep(list().filter(function (p) { return p !== post; })); })
					: Promise.resolve(Notification.requestPermission()).then(function (p) {
						if (p !== 'granted') throw new Error('blocked');
						return subscription();
					}).then(function (s) { return send(s, true); }).then(function () {
						var l = list();
						if (l.indexOf(post) === -1) l.push(post);
						keep(l);
						say(cfg.done);
					});
				go.catch(function (e) { say(e && e.message === 'blocked' ? cfg.blocked : cfg.error); }).then(function () { btn.disabled = false; paint(); });
			});
			box.hidden = false;
			paint();
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Admin: a card in App updates ▸ Settings
	 * ---------------------------------------------------------------- */

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-push-settings');
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		$o['on'] = empty($_POST['on']) ? 0 : 1;
		update_option(self::OPTION, $o, false);
		wp_safe_redirect(add_query_arg(['page' => Y4APK_App_Updates::SLUG, 'view' => 'settings', 'y4apk_settings' => 1], admin_url('admin.php')) . '#au-push');
		exit;
	}

	public static function settings_card() {
		global $wpdb;
		$subs = $follows = 0;
		if (self::installed()) {
			$subs = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('subs'));
			$follows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('follows'));
		}
		$top = self::most_followed(5);
		$log = get_option(self::LOG, []);
		$app_on = class_exists('Y4APK_PWA') && Y4APK_PWA::get('enabled');
		?>
		<div class="au-card au-pad" id="au-push" style="margin-top:14px">
			<h2 style="font-size:15px;margin:0 0 6px"><?php esc_html_e('Update notifications', 'y4apk'); ?></h2>
			<p class="au-note" style="margin:0 0 12px"><?php esc_html_e('Each post shows “Notify me when updated” under its downloads. When the post’s APK version goes up — saved here or in the post editor — everyone following that app gets a notification that opens the post. Works in Chrome, Edge and Firefox on phones and computers, in the Y4APK app, and in iPhone web apps added to the Home Screen.', 'y4apk'); ?></p>
			<?php if (!$app_on) : ?>
				<p class="au-bad" style="margin:0 0 12px"><?php printf(esc_html__('Needs the Y4APK app switched on (%s): notifications come through its service worker.', 'y4apk'),
					'<a href="' . esc_url(admin_url('admin.php?page=y4apk-pwa')) . '">' . esc_html__('Y4APK ▸ App', 'y4apk') . '</a>'); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 12px">
				<input type="hidden" name="action" value="y4apk_push_settings"><?php wp_nonce_field('y4apk-push-settings'); ?>
				<label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Show the button and send update notifications', 'y4apk'); ?></label>
				<button class="button" style="margin-left:8px"><?php esc_html_e('Save', 'y4apk'); ?></button>
			</form>
			<p style="margin:0 0 10px"><strong><?php echo esc_html(sprintf(__('%1$s browsers follow %2$s apps in all.', 'y4apk'), number_format_i18n($subs), number_format_i18n($follows))); ?></strong>
				<?php if ($top) : ?><br><span class="au-meta"><?php esc_html_e('Most followed:', 'y4apk'); ?>
					<?php echo esc_html(implode(' · ', array_map(function ($id, $n) {
						return get_the_title($id) . ' (' . number_format_i18n($n) . ')';
					}, array_keys($top), $top))); ?></span><?php endif; ?></p>
			<?php if (is_array($log) && $log) : ?>
				<dl class="au-dl">
					<?php foreach (array_slice($log, 0, 8) as $e) : ?>
						<dt><?php echo esc_html(sprintf(__('%s ago', 'y4apk'), human_time_diff($e['t']))); ?></dt>
						<dd><?php echo esc_html(sprintf(__('%1$s → %2$s: %3$s followers · %4$s sent · %5$s no longer subscribed · %6$s failed', 'y4apk'),
							get_the_title($e['post']), $e['version'], number_format_i18n($e['queued']), number_format_i18n($e['sent']), number_format_i18n($e['gone']), number_format_i18n($e['failed']))); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<p class="au-note"><?php esc_html_e('For the Y4APK Android app: when you build a new APK in PWABuilder, keep “Notification delegation” on in the Android options, so notifications show as coming from the Y4APK app.', 'y4apk'); ?></p>
		</div>
		<?php
	}
}

Y4APK_Push::init();
