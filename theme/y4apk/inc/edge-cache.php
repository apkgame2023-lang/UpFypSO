<?php
/**
 * Pages kept by Cloudflare (2.74): Y4APK ▸ Speed.
 *
 * The site's server answers in 0.5–2.5 seconds even for a page LiteSpeed has
 * ready (it is far from most visitors, and busy), while Cloudflare answers from
 * a city near the visitor in about 0.1 s. Cloudflare's free plan keeps files
 * (pictures, CSS, JS) but not pages, unless a Cache Rule lets it.
 *
 * So the theme says which pages Cloudflare may keep, and for how long, with a
 * header only Cloudflare reads (Cloudflare-CDN-Cache-Control: browsers ignore
 * it): only a page for a visitor who is not signed in, asked with GET, that no
 * part of the site marked "do not cache" (forms, accounts, messages, paid
 * pages for members, …). The Cache Rule in Cloudflare only sends pages to the
 * cache when nobody is signed in, and keeps them only when this header says so.
 *
 * When something changes (a post published, edited or deleted, a comment
 * approved, the home page, the menu, a "Purge all" in LiteSpeed), the theme
 * asks Cloudflare to drop those pages (the post, the home page, its
 * categories, its game, in every language), in the background, if a Cloudflare
 * API token is set here (or Y4APK_CF_ZONE / Y4APK_CF_TOKEN in wp-config.php).
 * Without one, pages are kept for less time.
 *
 * Nothing about visitors is sent to Cloudflare by this: only the site's own
 * addresses.
 */

defined('ABSPATH') || exit;

class Y4APK_Edge {

	const OPTION = 'y4apk_edge';       // ['on', 'ttl', 'zone', 'token' (encrypted)]
	const QUEUE  = 'y4apk_edge_queue'; // ['all' => bool, 'urls' => […]]: what to drop, in the background
	const HOOK   = 'y4apk_edge_purge';
	const SLUG   = 'y4apk-speed';
	const LAST   = 'y4apk_edge_last';  // the last answer from Cloudflare: ['at', 'ok', 'what']
	const API    = 'https://api.cloudflare.com/client/v4/zones/';

	private static $all = false;
	private static $urls = [];

	public static function init() {
		add_action('template_redirect', [__CLASS__, 'mark'], PHP_INT_MAX);
		add_action('transition_post_status', [__CLASS__, 'status_changed'], 20, 3);
		add_action('post_updated', [__CLASS__, 'updated'], 20, 3);
		add_action('litespeed_purge_post', [__CLASS__, 'queue_post']);
		add_action('litespeed_purge_all', [__CLASS__, 'queue_all']);
		add_action('litespeed_purged_all', [__CLASS__, 'queue_all']);
		add_action('wp_set_comment_status', [__CLASS__, 'comment_changed']);
		add_action('comment_post', [__CLASS__, 'comment_changed']);
		add_action('shutdown', [__CLASS__, 'save_queue'], 999); // after LiteSpeed's own purges at the end of the request
		add_action(self::HOOK, [__CLASS__, 'run']);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 40);
			add_action('admin_post_y4apk_edge', [__CLASS__, 'handle']);
		}
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], ['on' => 1, 'ttl' => 0, 'zone' => '', 'token' => '']);
	}

	/** Zone ID and API token: wp-config.php first (Y4APK_CF_ZONE, Y4APK_CF_TOKEN), else what was saved here (the token kept encrypted). */
	public static function creds() {
		$s = self::settings();
		$zone = defined('Y4APK_CF_ZONE') ? (string) Y4APK_CF_ZONE : (string) $s['zone'];
		$token = defined('Y4APK_CF_TOKEN') ? (string) Y4APK_CF_TOKEN : ($s['token'] !== '' && class_exists('Y4APK_Mail') ? (string) Y4APK_Mail::reveal($s['token']) : '');
		return [preg_match('/^[a-f0-9]{32}$/', $zone) ? $zone : '', $token];
	}

	public static function can_purge() {
		list($zone, $token) = self::creds();
		return $zone !== '' && $token !== '';
	}

	/** How long Cloudflare keeps a page: as chosen, else a day when changes reach it at once, an hour when they do not. */
	public static function ttl() {
		$t = (int) self::settings()['ttl'];
		return $t > 0 ? $t : (self::can_purge() ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
	}

	/* ---------------------------------------------------------------- *
	 * Which pages Cloudflare may keep
	 * ---------------------------------------------------------------- */

	public static function mark() {
		if (!self::settings()['on'] || headers_sent()) {
			return;
		}
		$m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		if (($m !== 'GET' && $m !== 'HEAD') || is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
			|| (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) || is_preview() || is_customize_preview() || is_feed() || is_trackback() || post_password_required()) {
			return;
		}
		foreach (headers_list() as $h) { // a page that already said "not for keeping" (nocache_headers(), a cookie)
			if (preg_match('/^(cache-control:.*(no-store|no-cache|private)|set-cookie:|cdn-cache-control:|surrogate-control:.*no-store|x-litespeed-cache-control:.*no-cache)/i', $h)) {
				return;
			}
		}
		$code = (int) http_response_code();
		if ($code !== 200 && $code !== 404) {
			return;
		}
		$ttl = $code === 404 ? 120 : self::ttl(); // a page not found: briefly (it may be made in a moment)
		header('Cloudflare-CDN-Cache-Control: public, max-age=' . (int) $ttl . ', stale-while-revalidate=60'); // Cloudflare reads it and takes it off
		// 2.74.1: browsers ask again each time (Cloudflare answers from its copy, fast), so a visitor who signs in, or a page that
		// changed, is never an old copy in the browser (Cloudflare would otherwise tell browsers to keep it 4 hours)
		header('Cache-Control: public, max-age=0, must-revalidate');
		header('X-Y4APK-Edge: ' . (int) $ttl); // for Y4APK ▸ Speed ▸ Check now (Cloudflare passes this one on)
	}

	/* ---------------------------------------------------------------- *
	 * What to drop when something changes
	 * ---------------------------------------------------------------- */

	public static function status_changed($new, $old, $post) {
		if (($new === 'publish' || $old === 'publish') && $new !== $old && in_array($post->post_type, ['post', 'page'], true)) {
			self::queue_post($post->ID);
		}
	}

	public static function updated($id, $after, $before) {
		if ($after->post_status === 'publish' && in_array($after->post_type, ['post', 'page'], true)) {
			self::queue_post($id);
			if ($before->post_name !== $after->post_name) {
				self::$urls[] = home_url('/' . $before->post_name . '/'); // its old address
			}
		}
	}

	public static function comment_changed($comment_id) {
		$c = get_comment($comment_id);
		if ($c && (string) $c->comment_approved === '1') {
			self::queue_post((int) $c->comment_post_ID);
		}
	}

	/** A post's pages: its own, the home page, its categories, its main post (a member's version), its Roblox game and the hub. */
	public static function queue_post($post_id) {
		$post_id = (int) (is_object($post_id) ? $post_id->ID : $post_id);
		$p = $post_id ? get_post($post_id) : null;
		if (!$p || !self::can_purge()) {
			return;
		}
		$link = get_permalink($p);
		$urls = [home_url('/'), $link];
		$rows = get_post_meta($p->ID, 'repeatable_download_link', true);
		foreach (is_array($rows) && $link ? array_keys(array_values($rows)) : [] as $i) {
			$urls[] = trailingslashit($link) . 'download/' . $i . '/'; // 2.75: its download pages too
		}
		foreach (wp_get_post_categories($p->ID) as $cat) {
			$urls[] = get_category_link($cat);
		}
		$parent = (int) get_post_meta($p->ID, '_y4apk_parent', true);
		if ($parent) {
			$urls[] = get_permalink($parent);
		}
		if (class_exists('Y4APK_Roblox')) {
			$g = Y4APK_Roblox::game_of($p->ID);
			if ($g) {
				$urls[] = get_term_link($g);
			}
			$hub = Y4APK_Roblox::hub_url();
			if ($hub) {
				$urls[] = $hub;
			}
		}
		foreach ($urls as $u) {
			if (is_string($u) && $u !== '') {
				self::$urls[] = $u;
			}
		}
	}

	public static function queue_all() {
		if (self::can_purge()) {
			self::$all = true;
		}
	}

	/** At the end of the request: what changed waits for the background (it never slows the page). */
	public static function save_queue() {
		if (!self::$all && !self::$urls) {
			return;
		}
		$q = get_option(self::QUEUE, []);
		$q = is_array($q) ? $q : [];
		$q['all'] = !empty($q['all']) || self::$all;
		$q['urls'] = $q['all'] ? [] : array_values(array_unique(array_merge((array) ($q['urls'] ?? []), self::$urls)));
		update_option(self::QUEUE, $q, false);
		self::$all = false;
		self::$urls = [];
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time(), self::HOOK);
		}
	}

	/** Every language's address of a page: /x/ and /th/x/. */
	private static function in_all_languages($urls) {
		$out = [];
		$codes = class_exists('Y4APK_I18n') ? array_diff((array) Y4APK_I18n::ready(), ['en']) : [];
		foreach ($urls as $u) {
			$u = class_exists('Y4APK_I18n') ? Y4APK_I18n::original_url($u) : $u;
			$out[] = $u;
			foreach ($codes as $code) {
				$out[] = Y4APK_I18n::add_code($u, $code);
			}
		}
		return array_values(array_unique($out));
	}

	public static function run() {
		$q = get_option(self::QUEUE, []);
		delete_option(self::QUEUE);
		if (!is_array($q) || (empty($q['all']) && empty($q['urls']))) {
			return;
		}
		if (!empty($q['all'])) {
			self::purge_all();
			return;
		}
		self::purge_urls(self::in_all_languages((array) $q['urls']));
	}

	/* ---------------------------------------------------------------- *
	 * Cloudflare's API
	 * ---------------------------------------------------------------- */

	private static function call($method, $path = '', $body = null) {
		list($zone, $token) = self::creds();
		if ($zone === '' || $token === '') {
			return new WP_Error('y4edge', __('Add the zone ID and an API token first.', 'y4apk'));
		}
		$r = wp_remote_request(self::API . $zone . $path, ['method' => $method, 'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], 'body' => $body !== null ? wp_json_encode($body) : null]);
		if (is_wp_error($r)) {
			return $r;
		}
		$j = json_decode((string) wp_remote_retrieve_body($r), true);
		if (!is_array($j) || empty($j['success'])) {
			$msg = is_array($j) && !empty($j['errors'][0]['message']) ? (string) $j['errors'][0]['message'] : 'HTTP ' . wp_remote_retrieve_response_code($r);
			return new WP_Error('y4edge', sprintf(__('Cloudflare answered: %s', 'y4apk'), $msg));
		}
		return $j;
	}

	private static function note($ok, $what) {
		update_option(self::LAST, ['at' => time(), 'ok' => (bool) $ok, 'what' => (string) $what], false);
	}

	public static function purge_all() {
		$r = self::call('POST', '/purge_cache', ['purge_everything' => true]);
		self::note(!is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : __('Every page', 'y4apk'));
		return $r;
	}

	public static function purge_urls($urls) {
		$urls = array_values(array_filter(array_unique((array) $urls), function ($u) { return is_string($u) && preg_match('~^https?://~', $u); }));
		$last = true;
		foreach (array_chunk($urls, 30) as $part) { // 30 addresses a call on the free plan
			$r = self::call('POST', '/purge_cache', ['files' => $part]);
			if (is_wp_error($r)) {
				$last = $r;
				break;
			}
		}
		self::note($last === true, $last === true ? sprintf(_n('%s page', '%s pages', count($urls), 'y4apk'), number_format_i18n(count($urls))) : $last->get_error_message());
		return $last;
	}

	/** Is the home page kept by Cloudflare? Asks for it twice, as a visitor: Cloudflare's answer the second time (HIT, MISS, DYNAMIC, BYPASS…). */
	public static function check() {
		$status = '';
		$header = '';
		for ($i = 0; $i < 2; $i++) {
			$r = wp_remote_get(home_url('/'), ['timeout' => 15, 'redirection' => 0, 'user-agent' => 'Mozilla/5.0 (Y4APK speed check)', 'cookies' => []]);
			if (is_wp_error($r)) {
				return ['status' => '', 'header' => '', 'error' => $r->get_error_message()];
			}
			$status = strtoupper((string) wp_remote_retrieve_header($r, 'cf-cache-status'));
			$header = (string) wp_remote_retrieve_header($r, 'x-y4apk-edge');
		}
		return ['status' => $status, 'header' => $header, 'error' => ''];
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Speed
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_submenu_page(Y4APK_MENU, __('Speed', 'y4apk'), __('Speed', 'y4apk'), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function admin_url($args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function handle() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_edge');
		$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
		$note = '';
		if ($do === 'save') {
			$s = self::settings();
			$s['on'] = empty($_POST['on']) ? 0 : 1;
			$s['ttl'] = in_array((int) ($_POST['ttl'] ?? 0), [0, 900, 3600, 4 * 3600, 12 * 3600, DAY_IN_SECONDS, 3 * DAY_IN_SECONDS], true) ? (int) $_POST['ttl'] : 0;
			$zone = strtolower(trim(sanitize_text_field(wp_unslash($_POST['zone'] ?? ''))));
			$s['zone'] = preg_match('/^[a-f0-9]{32}$/', $zone) ? $zone : '';
			$token = trim((string) wp_unslash($_POST['token'] ?? ''));
			if (!empty($_POST['token_clear'])) {
				$s['token'] = '';
			} elseif ($token !== '' && preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $token) && class_exists('Y4APK_Mail')) {
				$s['token'] = Y4APK_Mail::hide($token); // kept encrypted
			}
			update_option(self::OPTION, $s, false);
			$note = 'saved';
		} elseif ($do === 'test') {
			$r = self::call('GET');
			$note = is_wp_error($r) ? 'err' : 'test_ok';
			set_transient('y4apk_edge_msg', is_wp_error($r) ? $r->get_error_message() : sprintf(__('Connected to %s.', 'y4apk'), (string) ($r['result']['name'] ?? '')), 120);
		} elseif ($do === 'purge') {
			$r = self::purge_all();
			$note = is_wp_error($r) ? 'err' : 'purged';
			if (is_wp_error($r)) {
				set_transient('y4apk_edge_msg', $r->get_error_message(), 120);
			}
		} elseif ($do === 'check') {
			set_transient('y4apk_edge_check', self::check(), 600);
			$note = 'checked';
		}
		wp_safe_redirect(self::admin_url(['note' => $note]));
		exit;
	}

	public static function render() {
		$s = self::settings();
		$note = sanitize_key(wp_unslash($_GET['note'] ?? ''));
		$msg = (string) get_transient('y4apk_edge_msg');
		$chk = get_transient('y4apk_edge_check');
		$last = get_option(self::LAST);
		$in_config = defined('Y4APK_CF_ZONE') || defined('Y4APK_CF_TOKEN');
		$host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
		$expr = '(http.host eq "' . $host . '" and not http.cookie contains "wordpress_logged_in_" and not http.cookie contains "wp-postpass_" and not http.cookie contains "comment_author_")';
		$notes = ['saved' => __('Saved.', 'y4apk'), 'purged' => __('Cloudflare is dropping every page it kept. Visitors get fresh pages now.', 'y4apk'), 'test_ok' => $msg, 'err' => $msg, 'checked' => ''];
		$form = function ($do) {
			echo '<input type="hidden" name="action" value="y4apk_edge"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
			wp_nonce_field('y4apk_edge');
		};
		?>
		<div class="wrap y4ui">
			<?php Y4APK_Admin_UI::head(['icon' => 'globe', 'tint' => 'blue', 'title' => esc_html__('Speed', 'y4apk'),
				'sub' => esc_html__('Pages kept by Cloudflare near every visitor: about 0.1 s instead of the 0.5–2.5 s the site’s server takes. Only for visitors who are not signed in; members always get the page fresh from the site.', 'y4apk')]); ?>
			<?php if ($note && !empty($notes[$note])) : ?><div class="notice notice-<?php echo $note === 'err' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div><?php endif; ?>

			<div class="y4ui-card">
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('Is it working?', 'y4apk'); ?></h2><p><?php esc_html_e('Asks for the home page twice, as a visitor. HIT: Cloudflare gave it from its own copy. MISS or EXPIRED the first time is normal. DYNAMIC: the Cache Rule below is not there yet.', 'y4apk'); ?></p></div></div>
				<div class="y4ui-card-b">
					<?php if (is_array($chk)) : ?>
						<p><span class="y4ui-chip <?php echo $chk['status'] === 'HIT' ? 'is-green' : ($chk['status'] === 'DYNAMIC' || $chk['error'] !== '' ? 'is-red' : 'is-amber'); ?>"><?php echo esc_html($chk['error'] !== '' ? $chk['error'] : ($chk['status'] !== '' ? $chk['status'] : __('No answer from Cloudflare', 'y4apk'))); ?></span>
						<span class="y4ui-kpi-s"><?php echo esc_html($chk['header'] !== '' ? sprintf(__('The theme lets Cloudflare keep it for %s; browsers ask again each time.', 'y4apk'), human_time_diff(0, (int) $chk['header'])) : __('The theme did not mark the home page (switched off below, or LiteSpeed still has a copy from before 2.74.1: Purge All).', 'y4apk')); ?></span></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('check'); ?><button class="button"><?php esc_html_e('Check now', 'y4apk'); ?></button></form>
				</div>
			</div>

			<div class="y4ui-card" style="margin-top:16px">
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('1. In Cloudflare: a Cache Rule (once)', 'y4apk'); ?></h2><p><?php echo esc_html(sprintf(__('In the zone of %s (not the files domain): Caching ▸ Cache Rules ▸ Create rule.', 'y4apk'), $host)); ?></p></div></div>
				<div class="y4ui-card-b">
					<ol class="y4wal-how">
						<li><?php esc_html_e('Rule name: Pages for visitors.', 'y4apk'); ?></li>
						<li><?php esc_html_e('Custom filter expression ▸ Edit expression, and paste:', 'y4apk'); ?><br><code style="user-select:all;display:inline-block;margin-top:4px"><?php echo esc_html($expr); ?></code></li>
						<li><?php esc_html_e('Cache eligibility: Eligible for cache.', 'y4apk'); ?></li>
						<li><?php esc_html_e('Edge TTL: “Use cache-control header if present, bypass cache if not”.', 'y4apk'); ?></li>
						<li><?php esc_html_e('Browser TTL: leave it (Respect origin). Deploy.', 'y4apk'); ?></li>
					</ol>
					<p class="y4ui-hint"><?php esc_html_e('Signed-in members skip it (their cookie), and Cloudflare keeps only the pages the theme marks: never an account, a form, messages, the wallet or a member’s paid page.', 'y4apk'); ?></p>
				</div>
			</div>

			<form class="y4ui-card" style="margin-top:16px" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php $form('save'); ?>
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('2. Changes reach visitors at once (recommended)', 'y4apk'); ?></h2><p><?php esc_html_e('With an API token, the theme tells Cloudflare to drop a post’s pages (the post, the home page, its categories, its game, in every language) as soon as it is published, edited or deleted, and every page when the whole cache is cleared. Without one, Cloudflare keeps pages for an hour.', 'y4apk'); ?></p></div></div>
				<div class="y4ui-card-b">
					<label class="y4ui-opt"><input type="checkbox" name="on" value="1" <?php checked($s['on']); ?>> <span><b><?php esc_html_e('Let Cloudflare keep pages for visitors', 'y4apk'); ?></b></span></label>
					<div class="y4ui-fields" style="margin-top:12px">
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('Keep a page for', 'y4apk'); ?></span>
							<select name="ttl"><?php foreach ([0 => __('Automatic (a day with a token, an hour without)', 'y4apk'), 900 => __('15 minutes', 'y4apk'), 3600 => __('1 hour', 'y4apk'), 14400 => __('4 hours', 'y4apk'), 43200 => __('12 hours', 'y4apk'), 86400 => __('1 day', 'y4apk'), 259200 => __('3 days', 'y4apk')] as $k => $l) : ?><option value="<?php echo (int) $k; ?>" <?php selected((int) $s['ttl'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('Zone ID', 'y4apk'); ?></span><input type="text" name="zone" value="<?php echo esc_attr($s['zone']); ?>" placeholder="<?php esc_attr_e('32 letters and numbers', 'y4apk'); ?>" autocomplete="off" <?php disabled($in_config); ?>></label>
						<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('API token', 'y4apk'); ?></span><input type="password" name="token" value="" placeholder="<?php echo esc_attr($s['token'] !== '' || defined('Y4APK_CF_TOKEN') ? __('Saved (kept encrypted). Paste a new one to replace it.', 'y4apk') : __('Paste the token', 'y4apk')); ?>" autocomplete="new-password" <?php disabled($in_config); ?>></label>
					</div>
					<?php if ($s['token'] !== '' && !$in_config) : ?><label class="y4ui-opt"><input type="checkbox" name="token_clear" value="1"> <span><?php esc_html_e('Remove the saved token', 'y4apk'); ?></span></label><?php endif; ?>
					<p class="y4ui-hint"><?php echo esc_html($in_config ? __('The zone ID and token come from wp-config.php (Y4APK_CF_ZONE, Y4APK_CF_TOKEN).', 'y4apk')
						: sprintf(__('Zone ID: in Cloudflare, %s ▸ Overview, at the right (API). Token: My Profile ▸ API Tokens ▸ Create Token ▸ Custom token: Permissions “Zone · Cache Purge · Purge” and “Zone · Zone · Read”; Zone Resources “Include · Specific zone · %s”. It can only clear the cache of this site. Or put them in wp-config.php as Y4APK_CF_ZONE and Y4APK_CF_TOKEN.', 'y4apk'), $host, $host)); ?></p>
					<?php if (is_array($last)) : ?><p class="y4ui-hint"><?php echo esc_html(sprintf(__('Last time: %1$s, %2$s ago — %3$s', 'y4apk'), $last['ok'] ? __('cleared', 'y4apk') : __('failed', 'y4apk'), human_time_diff((int) $last['at']), $last['what'])); ?></p><?php endif; ?>
					<div class="y4ui-savebar"><span class="y4ui-hint"><?php echo esc_html(sprintf(__('Pages are kept for %s now.', 'y4apk'), human_time_diff(0, self::ttl()))); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
				</div>
			</form>
			<?php if (self::can_purge()) : ?>
			<div class="y4ui-card" style="margin-top:16px"><div class="y4ui-card-b" style="display:flex;gap:10px;flex-wrap:wrap">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('test'); ?><button class="button"><?php esc_html_e('Test the token', 'y4apk'); ?></button></form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('purge'); ?><button class="button"><?php esc_html_e('Clear Cloudflare’s pages now', 'y4apk'); ?></button></form>
			</div></div>
			<?php endif; ?>
		</div>
		<?php
		delete_transient('y4apk_edge_msg');
	}
}

Y4APK_Edge::init();
