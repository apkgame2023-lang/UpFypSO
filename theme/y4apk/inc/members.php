<?php
/**
 * Members: people who share Roblox scripts on the site, without ever seeing
 * WordPress. Everything happens on pages of the theme:
 *
 *   /account/             the member's dashboard (scripts, status, views)
 *   /account/login/ …/register/ …/forgot/ …/reset/ …/verify/
 *   /upload/              share a script: game, details, code, review (2.38; was /account/submit/)
 *   /account/edit/<id>/   change one of their scripts
 *   /account/settings/    password, closing the account
 *   /@handle             a member's public page: scripts, activity, statistics, Follow (2.39; /u/<handle>/ redirects)
 *   /account/following/  the members you follow and their new scripts; /account/customize/ edit profile
 *
 * The role is "Script Author" (y4apk_author) and has only the "read"
 * capability: it cannot reach wp-admin, cannot use the editor or the REST
 * API to write, cannot upload files, and cannot publish. The theme itself
 * saves what the form sends — as plain text, as a *pending* post that the
 * site owner approves (Y4APK ▸ Review). Registration is closed until it is
 * switched on in Y4APK ▸ Members.
 *
 * Also here, because they go together: what the site tells strangers about
 * its own accounts (author URLs, the REST users list, the generator tag,
 * XML-RPC, the users sitemap, oEmbed's author) is closed or made neutral.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Members {

	const ROLE     = 'y4apk_author';
	const OPTION   = 'y4apk_members';
	const VERSION  = '5';               // rewrite rules and the role, written again when this changes
	const VERIFIED = 'y4apk_email_ok';  // '0' until the email link is opened (absent: an older account, fine)
	const BANNED   = 'y4apk_banned';
	const REJECT   = '_y4apk_reject_reason';
	const WANTED   = '_y4apk_game_wanted'; // the game the member asked for, when it is not in the list
	const HOSTS    = '_y4apk_hosts';       // addresses the code loads from
	const HASHES   = '_y4apk_code_hash';
	const MEMBER_POST = '_y4apk_member_post';
	// y4_do => the screen that takes it
	const ACTIONS = ['register' => 'register', 'login' => 'login', 'forgot' => 'forgot', 'reset' => 'reset', 'resend' => 'login', 'submit' => 'submit',
		'edit' => 'edit', 'delete' => 'home', 'password' => 'settings', 'close' => 'settings', 'customize' => 'customize'];
	const GENERATOR = '/<meta\b[^>]*\bname\s*=\s*["\']?generator\b[^>]*>[ \t]*\n?/i';

	private static $head_level = null;

	/** What this request is about: set by route(), read by template/account.php. */
	public static $ctx = null;

	public static function init() {
		add_action('init', [__CLASS__, 'setup'], 6);
		add_filter('query_vars', [__CLASS__, 'query_vars']);
		add_action('template_redirect', [__CLASS__, 'route'], 0);
		add_filter('redirect_canonical', [__CLASS__, 'no_slash'], 10, 2);
		add_action('template_redirect', [__CLASS__, 'author_pages'], 1);
		add_filter('template_include', [__CLASS__, 'template'], 20);
		add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('wp_ajax_y4apk_fresh', [__CLASS__, 'fresh']);
		add_action('wp_ajax_nopriv_y4apk_fresh', [__CLASS__, 'fresh']);
		add_filter('document_title_parts', [__CLASS__, 'title_parts']);
		add_action('wp_head', [__CLASS__, 'noindex'], 1);

		// Who may sign in, and where they are sent.
		add_filter('wp_authenticate_user', [__CLASS__, 'authenticate'], 20, 2);
		add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
		add_action('admin_init', [__CLASS__, 'keep_out']);
		add_filter('show_admin_bar', [__CLASS__, 'admin_bar']);
		add_action('transition_post_status', [__CLASS__, 'on_publish'], 10, 3);

		// What strangers can learn about the accounts.
		add_action('init', [__CLASS__, 'privacy'], 1);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'on'          => 0,   // registration open
			'ts_site'     => '',  // Cloudflare Turnstile
			'ts_secret'   => '',
			'per_day'     => 5,   // scripts a member may send in 24 hours
			'max_pending' => 15,  // waiting for review at once
			'report_hide' => 5,   // reports that put a member's script back in review (0: never)
			'notify'      => 1,   // email the site owner when a script arrives
			'from_name'   => 'Y4APK',
			'reply_to'    => '',  // where replies go (optional; mail comes from a no-reply address)
			'smtp_on'     => 0,   // send through the site's mailbox (inc/mail.php)
			'smtp_host'   => 'smtp.hostinger.com',
			'smtp_port'   => 465,
			'smtp_secure' => 'ssl',
			'smtp_user'   => '',
			'smtp_pass'   => '',  // encrypted (Y4APK_Mail::hide)
			'hosts'       => "raw.githubusercontent.com\ngist.githubusercontent.com\ngithub.com\ngitlab.com\nraw.githack.com\ncdn.jsdelivr.net\npastebin.com\nrawscripts.net\nscriptblox.com\napi.luarmor.net\npaste.ee",
		];
	}

	public static function settings() {
		$saved = get_option(self::OPTION, []);
		$o = array_merge(self::defaults(), is_array($saved) ? $saved : []);
		if (defined('Y4APK_TURNSTILE_SITE') && Y4APK_TURNSTILE_SITE) {
			$o['ts_site'] = (string) Y4APK_TURNSTILE_SITE;
		}
		if (defined('Y4APK_TURNSTILE_SECRET') && Y4APK_TURNSTILE_SECRET) {
			$o['ts_secret'] = (string) Y4APK_TURNSTILE_SECRET;
		}
		$o['per_day'] = max(1, min(50, (int) $o['per_day']));
		$o['max_pending'] = max(1, min(200, (int) $o['max_pending']));
		$o['report_hide'] = max(0, min(50, (int) $o['report_hide']));
		return $o;
	}

	public static function open() {
		return !empty(self::settings()['on']);
	}

	public static function turnstile_on() {
		$s = self::settings();
		return $s['ts_site'] !== '' && $s['ts_secret'] !== '';
	}

	/* ---------------------------------------------------------------- *
	 * The role, the pages' addresses
	 * ---------------------------------------------------------------- */

	public static function setup() {
		add_rewrite_rule('^account/?$', 'index.php?y4_acct=home', 'top');
		add_rewrite_rule('^upload/?$', 'index.php?y4_acct=submit', 'top');
		add_rewrite_rule('^account/edit/([0-9]+)/?$', 'index.php?y4_acct=edit&y4_id=$matches[1]', 'top');
		add_rewrite_rule('^account/([a-z]+)/?$', 'index.php?y4_acct=$matches[1]', 'top');
		add_rewrite_rule('^@([a-z0-9_]{3,20})/page/([0-9]+)/?$', 'index.php?y4_acct=profile&y4_user=$matches[1]&paged=$matches[2]', 'top');
		add_rewrite_rule('^@([a-z0-9_]{3,20})/?$', 'index.php?y4_acct=profile&y4_user=$matches[1]', 'top');
		// The addresses before 2.39: they redirect to /@name.
		add_rewrite_rule('^u/([a-z0-9_]{3,20})/page/([0-9]+)/?$', 'index.php?y4_acct=profile&y4_user=$matches[1]&paged=$matches[2]', 'top');
		add_rewrite_rule('^u/([a-z0-9_]{3,20})/?$', 'index.php?y4_acct=profile&y4_user=$matches[1]', 'top');
		if (get_option('y4apk_members_ver') !== self::VERSION) {
			remove_role(self::ROLE);
			add_role(self::ROLE, __('Script Author', 'y4apk'), ['read' => true]);
			flush_rewrite_rules(false);
			update_option('y4apk_members_ver', self::VERSION);
		}
	}

	public static function query_vars($vars) {
		return array_merge($vars, ['y4_acct', 'y4_id', 'y4_user']);
	}

	public static function url($view = '', $args = []) {
		$url = $view === 'submit' ? home_url('/upload/') : home_url('/account/' . ($view !== '' && $view !== 'home' ? $view . '/' : ''));
		return $args ? add_query_arg($args, $url) : $url;
	}

	public static function profile_url($user) {
		return home_url('/@' . rawurlencode($user->user_login));
	}

	public static function is_member($user) {
		return $user instanceof WP_User && $user->exists() && in_array(self::ROLE, (array) $user->roles, true);
	}

	public static function is_verified($user_id) {
		return get_user_meta($user_id, self::VERIFIED, true) !== '0';
	}

	/** A member whose public page and scripts may be shown. */
	public static function is_public($user) {
		return self::is_member($user) && self::is_verified($user->ID) && !get_user_meta($user->ID, self::BANNED, true);
	}

	public static function nonce($do) {
		return wp_nonce_field('y4apk_acct_' . $do, '_y4n', false, false);
	}

	/* ---------------------------------------------------------------- *
	 * Keeping members to the theme's pages
	 * ---------------------------------------------------------------- */

	public static function authenticate($user, $password = '') {
		if (!($user instanceof WP_User) || !self::is_member($user)) {
			return $user;
		}
		// WordPress asks this before it checks the password: say "not confirmed" or "closed" only to
		// someone who has the right one, so the answer cannot be used to find which accounts exist.
		if (!wp_check_password((string) $password, $user->user_pass, $user->ID)) {
			return $user;
		}
		if (get_user_meta($user->ID, self::BANNED, true)) {
			return new WP_Error('y4_banned', __('This account is closed.', 'y4apk'));
		}
		if (!self::is_verified($user->ID)) {
			return new WP_Error('y4_unverified', __('Confirm your email first: open the link we sent you.', 'y4apk'));
		}
		return $user;
	}

	public static function login_redirect($to, $requested, $user) {
		return ($user instanceof WP_User && self::is_member($user)) ? self::url() : $to;
	}

	/** wp-admin is for the people who run the site. */
	public static function keep_out() {
		if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		$script = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
		if ($script === 'admin-post.php') {
			return; // the front-end report and review forms post here
		}
		if (self::is_member(wp_get_current_user())) {
			wp_safe_redirect(self::url());
			exit;
		}
	}

	public static function head_start() {
		self::$head_level = ob_get_level();
		ob_start();
	}

	public static function head_end() {
		// Another plugin's buffer on top of ours (or ours already closed): leave the page as it is.
		if (self::$head_level === null || ob_get_level() !== self::$head_level + 1) {
			return;
		}
		self::$head_level = null;
		echo preg_replace(self::GENERATOR, '', (string) ob_get_clean());
	}

	public static function admin_bar($show) {
		return self::is_member(wp_get_current_user()) ? false : $show;
	}

	/* ---------------------------------------------------------------- *
	 * What strangers can learn about the accounts
	 * ---------------------------------------------------------------- */

	public static function privacy() {
		// ?author=1 redirects to /author/<login>/: the login name of whoever wrote the post.
		if (!is_admin() && isset($_GET['author'])) {
			wp_safe_redirect(home_url('/'), 301);
			exit;
		}
		remove_action('wp_head', 'wp_generator');
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		add_filter('the_generator', '__return_empty_string');
		// Plugins print their own <meta name="generator"> with their version: take them out of the head.
		add_action('wp_head', [__CLASS__, 'head_start'], -99999);
		add_action('wp_head', [__CLASS__, 'head_end'], PHP_INT_MAX);
		add_filter('xmlrpc_enabled', '__return_false');
		add_filter('wp_headers', function ($h) {
			unset($h['X-Pingback']);
			return $h;
		});
		// The users list: ids, names, login-based slugs and the gravatar hash of the email.
		add_filter('rest_endpoints', function ($routes) {
			if (!current_user_can('list_users') && !current_user_can('edit_others_posts')) {
				unset($routes['/wp/v2/users'], $routes['/wp/v2/users/(?P<id>[\d]+)']);
			}
			return $routes;
		});
		add_filter('rest_prepare_post', function ($response) {
			if (!current_user_can('edit_others_posts') && $response instanceof WP_REST_Response) {
				$data = $response->get_data();
				if (isset($data['author'])) {
					$data['author'] = 0;
					$response->set_data($data);
					$response->remove_link('author'); // the link names the author's number
				}
			}
			return $response;
		});
		add_filter('oembed_response_data', function ($data) {
			if (isset($data['author_name'])) {
				$data['author_name'] = get_bloginfo('name');
				$data['author_url'] = home_url('/');
			}
			return $data;
		});
		add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
			return $name === 'users' ? false : $provider;
		}, 10, 2);
		// Feeds and themes print the author's name: the site's name for the people who run it.
		add_filter('the_author', [__CLASS__, 'public_author']);
		add_filter('login_errors', function () {
			return __('Wrong username or password.', 'y4apk'); // the same words whether or not the name exists
		});
	}

	public static function public_author($name) {
		global $authordata;
		if ($authordata instanceof WP_User && !self::is_member($authordata)) {
			return get_bloginfo('name');
		}
		return $name;
	}

	/** /author/<login>/ : a member's own page is /@handle; the others do not exist. */
	public static function author_pages() {
		if (!is_author()) {
			return;
		}
		$user = get_queried_object();
		if ($user instanceof WP_User && self::is_public($user)) {
			wp_safe_redirect(self::profile_url($user), 301);
			exit;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header(404);
		nocache_headers();
		include get_404_template();
		exit;
	}

	/** Neutral names for the administrators: what is printed is not what they sign in with. */
	public static function public_names() {
		$out = [];
		foreach (get_users(['role__in' => ['administrator', 'editor'], 'fields' => 'all']) as $u) {
			$bad = strcasecmp($u->display_name, $u->user_login) === 0 || strcasecmp($u->user_nicename, $u->user_login) === 0
				|| strcasecmp($u->nickname, $u->user_login) === 0 || strcasecmp($u->display_name, $u->user_email) === 0;
			$out[] = ['user' => $u, 'ok' => !$bad];
		}
		return $out;
	}

	public static function neutralise_names() {
		$base = sanitize_title(get_bloginfo('name')) ?: 'team';
		$n = 0;
		foreach (self::public_names() as $row) {
			if ($row['ok']) {
				continue;
			}
			$u = $row['user'];
			$slug = $base;
			for ($i = 1; get_user_by('slug', $slug) && get_user_by('slug', $slug)->ID !== $u->ID; $i++) {
				$slug = $base . '-' . $i;
			}
			wp_update_user(['ID' => $u->ID, 'display_name' => get_bloginfo('name'), 'nickname' => get_bloginfo('name'), 'user_nicename' => $slug]);
			$n++;
		}
		return $n;
	}

	/* ---------------------------------------------------------------- *
	 * Checks that keep bots and floods out
	 * ---------------------------------------------------------------- */

	/** The visitor's address; behind Cloudflare, the one Cloudflare says (only when the request really came from it). */
	public static function client_ip() {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && self::is_cloudflare($ip)) {
			$real = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
			if (filter_var($real, FILTER_VALIDATE_IP)) {
				return $real;
			}
		}
		return $ip;
	}

	private static function is_cloudflare($ip) {
		$ranges = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
		$bin = @inet_pton($ip);
		if ($bin === false) {
			return false;
		}
		foreach ($ranges as $range) {
			list($net, $bits) = explode('/', $range);
			$nb = inet_pton($net);
			if (strlen($nb) !== strlen($bin)) {
				continue;
			}
			$bytes = intdiv((int) $bits, 8);
			$rest = (int) $bits % 8;
			if ($bytes && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
				continue;
			}
			if ($rest) {
				$mask = chr((0xFF << (8 - $rest)) & 0xFF);
				if ((substr($bin, $bytes, 1) & $mask) !== (substr($nb, $bytes, 1) & $mask)) {
					continue;
				}
			}
			return true;
		}
		return false;
	}

	/** A hash of the address: enough to count and to stop floods, not to find anyone. */
	public static function ip_hash() {
		return substr(hash_hmac('sha256', self::client_ip(), wp_salt('auth')), 0, 20);
	}

	/** Counts one try; false once $max tries have happened within $window seconds. */
	public static function hit($key, $max, $window) {
		$k = 'y4t_' . md5($key);
		$n = (int) get_transient($k);
		if ($n >= $max) {
			return false;
		}
		set_transient($k, $n + 1, $window);
		return true;
	}

	public static function blocked($key, $max) {
		return (int) get_transient('y4t_' . md5($key)) >= $max;
	}

	/** Hidden fields of every form: a trap bots fill in, and the time the form was opened. */
	public static function trap_fields() {
		return '<div class="acc-hp" aria-hidden="true"><label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<input type="hidden" name="y4t" value="' . esc_attr(self::trap_value()) . '">';
	}

	private static function trap_value() {
		$t = time();
		return $t . '.' . hash_hmac('sha256', (string) $t, wp_salt('nonce'));
	}

	/**
	 * New hidden values for forms that a page cache kept: the nonces, and the time
	 * field (the page swaps it only when it is over an hour old, so the 3-second
	 * wait still counts from when the visitor opened the page).
	 */
	public static function fresh() {
		nocache_headers();
		$n = [];
		foreach (array_slice((array) ($_POST['do'] ?? []), 0, 5) as $do) {
			$do = sanitize_key(wp_unslash($do));
			if (isset(self::ACTIONS[$do]) || $do === 'logout') {
				$n[$do] = wp_create_nonce('y4apk_acct_' . $do);
			}
		}
		wp_send_json(['now' => time(), 't' => self::trap_value(), 'n' => $n]);
	}

	private static function trap_ok() {
		if (!empty($_POST['website'])) {
			return false;
		}
		$p = isset($_POST['y4t']) ? explode('.', (string) wp_unslash($_POST['y4t'])) : [];
		if (count($p) !== 2 || !ctype_digit($p[0]) || !hash_equals(hash_hmac('sha256', $p[0], wp_salt('nonce')), $p[1])) {
			return false;
		}
		$age = time() - (int) $p[0];
		return $age >= 3 && $age <= 6 * HOUR_IN_SECONDS;
	}

	public static function turnstile_widget() {
		if (self::turnstile_on()) {
			echo '<div class="cf-turnstile acc-ts" data-sitekey="' . esc_attr(self::settings()['ts_site']) . '" data-theme="light"></div>';
		}
	}

	private static function turnstile_ok() {
		if (!self::turnstile_on()) {
			return true;
		}
		$token = isset($_POST['cf-turnstile-response']) ? (string) wp_unslash($_POST['cf-turnstile-response']) : '';
		if ($token === '' || strlen($token) > 2048) {
			return false;
		}
		$r = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['timeout' => 8, 'body' => [
			'secret' => self::settings()['ts_secret'], 'response' => $token, 'remoteip' => self::client_ip()]]);
		if (is_wp_error($r)) {
			return false;
		}
		$json = json_decode((string) wp_remote_retrieve_body($r), true);
		return is_array($json) && !empty($json['success']);
	}

	/** Trap, Turnstile and the form's own nonce: true, or the reason (shown to the visitor). */
	private static function guard($do, $need_human = true) {
		if (!isset($_POST['_y4n']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['_y4n'])), 'y4apk_acct_' . $do)) {
			return __('This page was open too long. Try again.', 'y4apk');
		}
		if ($need_human) {
			if (!self::trap_ok()) {
				return __('Please wait a few seconds and try again.', 'y4apk');
			}
			if (!self::turnstile_ok()) {
				return __('Could not confirm you are human. Try again.', 'y4apk');
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------- *
	 * Mail
	 * ---------------------------------------------------------------- */

	/** An email in the site's design (inc/mail.php): $body is a message() array, or plain paragraphs. */
	public static function mail($to, $subject, $body) {
		return Y4APK_Mail::send($to, $subject, is_array($body) ? $body : ['lines' => preg_split('/\n{2,}/', trim((string) $body))]);
	}

	/** What each email says: the parts Y4APK_Mail lays out. */
	public static function message($kind, $d) {
		$site = self::settings()['from_name'];
		$d = wp_parse_args($d, ['name' => '', 'link' => '', 'title' => '', 'why' => '', 'count' => 0]);
		$author = sprintf(__('You got this email because you shared a script on %s.', 'y4apk'), $site);
		$owner = __('You get these because “Email me when a script is waiting” is on in Y4APK ▸ Members.', 'y4apk');
		switch ($kind) {
			case 'verify':
				return ['title' => __('Confirm your email', 'y4apk'), 'preheader' => __('One click, and you can start sharing scripts.', 'y4apk'), 'hi' => $d['name'],
					'lines' => [sprintf(__('Thanks for joining %s. Confirm that this is your email address, then share your first Roblox script.', 'y4apk'), $site)],
					'button' => [__('Confirm my email', 'y4apk'), $d['link']],
					'note' => __('This link works for 48 hours. If you did not create an account, ignore this email: nothing happens.', 'y4apk'),
					'why' => sprintf(__('You got this email because someone signed up at %s with this address.', 'y4apk'), $site)];
			case 'reset':
				return ['title' => __('Reset your password', 'y4apk'), 'preheader' => sprintf(__('Choose a new password for your %s account.', 'y4apk'), $site), 'hi' => $d['name'],
					'lines' => [sprintf(__('We got a request to reset the password of your %s account.', 'y4apk'), $site)],
					'button' => [__('Choose a new password', 'y4apk'), $d['link']],
					'note' => __('If you did not ask for this, ignore this email: your password stays the same.', 'y4apk'),
					'why' => __('You got this email because a new password was asked for this address.', 'y4apk')];
			case 'live':
				return ['title' => __('Your script is live 🎉', 'y4apk'), 'preheader' => sprintf(__('“%s” is on the site now.', 'y4apk'), $d['title']), 'hi' => $d['name'],
					'lines' => [__('Good news: we checked your script and it is live now. Share the link with your players.', 'y4apk')],
					'card' => [__('Your script', 'y4apk'), $d['title']], 'button' => [__('See your script', 'y4apk'), $d['link']],
					'note' => __('Keep the link working: scripts that stop working get removed.', 'y4apk'), 'why' => $author];
			case 'changes':
				return ['title' => __('Your script needs a change', 'y4apk'), 'preheader' => __('One thing to fix, and it can go live.', 'y4apk'), 'hi' => $d['name'],
					'lines' => [__('We checked your script and could not publish it yet. Here is what to change:', 'y4apk')],
					'card' => [__('Your script', 'y4apk'), $d['title']], 'quote' => $d['why'], 'button' => [__('Edit and send again', 'y4apk'), $d['link']], 'why' => $author];
			case 'waiting':
				return ['title' => __('A script is waiting for review', 'y4apk'), 'preheader' => sprintf(__('@%1$s shared “%2$s”.', 'y4apk'), $d['name'], $d['title']),
					'lines' => [sprintf(__('@%s shared a new script. It stays hidden until you approve it.', 'y4apk'), $d['name'])],
					'card' => [__('Script', 'y4apk'), $d['title']], 'button' => [__('Open Review', 'y4apk'), admin_url('admin.php?page=y4apk-review')], 'why' => $owner];
			case 'reported':
				return ['title' => __('A script was hidden after reports', 'y4apk'), 'preheader' => sprintf(__('“%s” is back in review.', 'y4apk'), $d['title']),
					'lines' => [sprintf(_n('It got %d report from visitors, so it is back in review until you look at it.', 'It got %d reports from visitors, so it is back in review until you look at it.', $d['count'], 'y4apk'), $d['count'])],
					'card' => [__('Script', 'y4apk'), $d['title']], 'button' => [__('Open Review', 'y4apk'), admin_url('admin.php?page=y4apk-review')], 'why' => $owner];
		}
		return ['lines' => []];
	}

	private static function send_verification($user) {
		$token = wp_generate_password(32, false, false);
		update_user_meta($user->ID, 'y4apk_vtoken', hash('sha256', $token));
		update_user_meta($user->ID, 'y4apk_vexp', time() + 2 * DAY_IN_SECONDS);
		$link = self::url('verify', ['u' => $user->ID, 't' => $token]);
		self::mail($user->user_email, sprintf(__('Confirm your email for %s', 'y4apk'), self::settings()['from_name']), self::message('verify', ['name' => $user->user_login, 'link' => $link]));
	}

	/* ---------------------------------------------------------------- *
	 * Routing
	 * ---------------------------------------------------------------- */

	public static function route() {
		$view = (string) get_query_var('y4_acct');
		if ($view === '') {
			return;
		}
		$views = ['home', 'login', 'register', 'forgot', 'reset', 'verify', 'submit', 'edit', 'settings', 'logout', 'profile', 'customize', 'following'];
		global $wp_query;
		if (!in_array($view, $views, true)) {
			$wp_query->set_404(); // /account/anything-else/ does not exist
			status_header(404);
			return;
		}
		$path = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
		if ($view === 'submit' && $_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~^/account/submit~', $path)) {
			wp_safe_redirect(self::url('submit'), 301);
			exit;
		}
		if ($view === 'profile' && preg_match('~^/@[a-z0-9_]+/$~i', $path)) {
			wp_safe_redirect(home_url('/@' . rawurlencode((string) get_query_var('y4_user'))) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), 301); // /@name, as people type it
			exit;
		}
		if ($view === 'profile' && preg_match('~^/u/~', $path)) {
			$paged = (int) get_query_var('paged');
			wp_safe_redirect(home_url('/@' . rawurlencode((string) get_query_var('y4_user')) . ($paged > 1 ? '/page/' . $paged . '/' : '')), 301);
			exit;
		}
		$wp_query->is_404 = false;
		status_header(200);
		nocache_headers();
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true); // the page cache must not keep a page with a form's nonce in it
		}
		// Caches in front of WordPress have headers of their own: CDNs (Cloudflare), LiteSpeed (Hostinger).
		header('CDN-Cache-Control: no-store');
		header('Surrogate-Control: no-store');
		header('X-LiteSpeed-Cache-Control: no-cache');
		do_action('litespeed_control_set_nocache', 'y4apk member page');
		$listed = $view === 'profile' && !isset($_GET['tab']) && !isset($_GET['sort']) && !isset($_GET['q']); // one address per profile in search engines
		header('X-Robots-Tag: ' . ($listed ? 'index, follow' : 'noindex, nofollow'));

		$user = wp_get_current_user();
		$ctx = ['view' => $view, 'screen' => $view, 'errors' => [], 'ok' => '', 'old' => [], 'user' => $user->exists() ? $user : null, 'member' => self::is_member($user)];
		if (isset($_GET['ok'])) {
			$ctx['ok'] = sanitize_key(wp_unslash($_GET['ok']));
		}
		self::$ctx = &$ctx;

		$guest_only = ['login', 'register', 'forgot', 'reset'];
		$members_only = ['home', 'submit', 'edit', 'settings', 'customize', 'following'];
		if ($ctx['user'] && in_array($view, $guest_only, true)) {
			wp_safe_redirect(self::url());
			exit;
		}
		if ($view === 'logout') {
			self::do_logout($ctx); // signs out, or asks first (screen 'logout')
		}
		if (!$ctx['user'] && in_array($view, $members_only, true)) {
			wp_safe_redirect(self::url('login', $view === 'submit' ? ['next' => 'upload'] : []));
			exit;
		}
		if ($ctx['user'] && !$ctx['member'] && in_array($view, $members_only, true)) {
			$ctx['screen'] = 'staff'; // an administrator who found the page: it is not for them
			return;
		}

		$do = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do'])) ? sanitize_key(wp_unslash($_POST['y4_do'])) : '';
		if ($do !== '' && isset(self::ACTIONS[$do]) && self::ACTIONS[$do] === $view && method_exists(__CLASS__, 'do_' . $do)) {
			call_user_func_array([__CLASS__, 'do_' . $do], [&$ctx]);
		}

		switch ($view) {
			case 'verify':
				self::view_verify($ctx);
				break;
			case 'reset':
				self::view_reset($ctx);
				break;
			case 'home':
				self::view_home($ctx);
				break;
			case 'submit':
			case 'edit':
				self::view_form($ctx);
				break;
			case 'profile':
				self::view_profile($ctx);
				break;
			case 'following':
				self::view_following($ctx);
				break;
		}
	}

	/** WordPress would add a slash to /@name: a profile's address is without one. */
	public static function no_slash($redirect, $requested) {
		return get_query_var('y4_acct') === 'profile' ? false : $redirect;
	}

	public static function template($template) {
		return self::$ctx ? (locate_template('template/account.php') ?: $template) : $template;
	}

	public static function assets() {
		if (self::$ctx) {
			wp_enqueue_style('y4apk-roblox', get_template_directory_uri() . '/assets/css/roblox.css', ['core', 'styles'], Y4APK_THEME_VERSION); // the script cards
			wp_enqueue_style('y4apk-account', get_template_directory_uri() . '/assets/css/account.css', ['y4apk-roblox'], Y4APK_THEME_VERSION);
		}
	}

	/** A round tile with the first letter, in a colour of the name's own: no pictures to upload, none to host. */
	public static function avatar($name, $size = 56) {
		$letter = mb_strtoupper(mb_substr(ltrim((string) $name, '@'), 0, 1)) ?: '?';
		$hue = abs(crc32((string) $name)) % 360;
		return '<span class="acc-av" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;font-size:' . round($size * .46) . 'px;background:linear-gradient(135deg,hsl(' . $hue . ',70%,52%),hsl(' . (($hue + 40) % 360) . ',65%,38%))" aria-hidden="true">' . esc_html($letter) . '</span>';
	}

	public static function noindex() {
		if (self::$ctx && self::$ctx['view'] !== 'profile') {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		}
	}

	public static function title_parts($parts) {
		if (!self::$ctx) {
			return $parts;
		}
		$names = ['home' => __('My scripts', 'y4apk'), 'login' => __('Sign in', 'y4apk'), 'register' => __('Create an account', 'y4apk'), 'forgot' => __('Reset your password', 'y4apk'),
			'reset' => __('Choose a new password', 'y4apk'), 'verify' => __('Confirm your email', 'y4apk'), 'submit' => __('Share a script', 'y4apk'), 'edit' => __('Edit your script', 'y4apk'),
			'settings' => __('Account settings', 'y4apk'), 'profile' => '', 'customize' => __('Edit profile', 'y4apk'), 'following' => __('Following', 'y4apk'), 'logout' => __('Sign out', 'y4apk')];
		$v = self::$ctx['view'];
		if ($v === 'profile' && !empty(self::$ctx['profile'])) {
			$p = self::$ctx['profile'];
			$name = Y4APK_Social::name($p);
			$parts['title'] = sprintf(__('%s’s Roblox scripts', 'y4apk'), $name !== $p->user_login ? $name . ' (@' . $p->user_login . ')' : '@' . $p->user_login);
		} elseif (!empty($names[$v])) {
			$parts['title'] = $names[$v];
		}
		return $parts;
	}

	/* ---------------------------------------------------------------- *
	 * Actions (the forms' answers)
	 * ---------------------------------------------------------------- */

	private static function reserved($handle) {
		return in_array($handle, ['admin', 'administrator', 'root', 'support', 'help', 'staff', 'moderator', 'mod', 'owner', 'system', 'official', 'webmaster', 'info', 'contact', 'team', 'roblox',
			'y4apk', 'y4', 'null', 'undefined', 'account', 'login', 'register', 'wp', 'wordpress', 'user', 'users', 'guest', 'anonymous'], true);
	}

	private static function password_problem($pass, $handle = '', $email = '') {
		if (strlen($pass) < 10) {
			return __('Use at least 10 characters for the password.', 'y4apk');
		}
		if (strlen($pass) > 100) {
			return __('That password is too long (100 characters at most).', 'y4apk');
		}
		$low = strtolower($pass);
		if (($handle !== '' && strpos($low, strtolower($handle)) !== false) || ($email !== '' && $low === strtolower($email))) {
			return __('The password should not contain your name or email.', 'y4apk');
		}
		if (preg_match('/^(.)\1+$/', $pass) || in_array($low, ['1234567890', 'password123', 'qwertyuiop', 'password12', 'iloveyou12', '0123456789', 'abcdefghij', 'roblox1234'], true)) {
			return __('That password is too easy to guess.', 'y4apk');
		}
		return '';
	}

	private static function do_register(&$ctx) {
		$ctx['screen'] = 'register';
		if (!self::open()) {
			$ctx['errors'][] = __('Registration is closed at the moment.', 'y4apk');
			return;
		}
		$g = self::guard('register');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$ip = self::ip_hash();
		if (!self::hit('reg_try_' . $ip, 20, HOUR_IN_SECONDS)) { // tries of any kind: a person who mistypes is fine, a script is not
			$ctx['errors'][] = __('Too many tries from here. Try again later.', 'y4apk');
			return;
		}
		$handle = strtolower(trim((string) wp_unslash(isset($_POST['handle']) ? $_POST['handle'] : '')));
		$email = sanitize_email((string) wp_unslash(isset($_POST['email']) ? $_POST['email'] : ''));
		$pass = (string) wp_unslash(isset($_POST['password']) ? $_POST['password'] : '');
		$ctx['old'] = ['handle' => $handle, 'email' => $email];
		if (!preg_match('/^[a-z0-9_]{3,20}$/', $handle)) {
			$ctx['errors'][] = __('Pick a name of 3–20 letters, numbers or underscores.', 'y4apk');
		} elseif (self::reserved($handle) || username_exists($handle) || get_user_by('slug', $handle)) {
			$ctx['errors'][] = __('That name is taken. Pick another.', 'y4apk');
		}
		if (!is_email($email) || strlen($email) > 100) {
			$ctx['errors'][] = __('Enter a valid email address.', 'y4apk');
		}
		if ($p = self::password_problem($pass, $handle, $email)) {
			$ctx['errors'][] = $p;
		}
		if (empty($_POST['terms'])) {
			$ctx['errors'][] = __('Tick the box to agree to the rules.', 'y4apk');
		}
		if ($ctx['errors']) {
			return;
		}
		if (email_exists($email)) {
			$ctx['screen'] = 'check'; // the same answer whether or not the email is known
			$ctx['email'] = $email;
			return;
		}
		if (!self::hit('reg_ip_' . $ip, 4, HOUR_IN_SECONDS) || !self::hit('reg_all', 150, HOUR_IN_SECONDS)) { // accounts made: 4 an hour from one address, 150 in all
			$ctx['errors'][] = __('Too many sign-ups from here. Try again later.', 'y4apk');
			return;
		}
		$id = wp_insert_user(['user_login' => $handle, 'user_pass' => $pass, 'user_email' => $email, 'role' => self::ROLE, 'display_name' => $handle,
			'nickname' => $handle, 'show_admin_bar_front' => 'false', 'rich_editing' => 'false']);
		if (is_wp_error($id)) {
			$ctx['errors'][] = __('Could not create the account. Try again.', 'y4apk');
			return;
		}
		update_user_meta($id, self::VERIFIED, '0');
		update_user_meta($id, 'y4apk_ip', $ip);
		self::send_verification(get_userdata($id));
		$ctx['screen'] = 'check';
		$ctx['email'] = $email;
	}

	private static function view_verify(&$ctx) {
		$id = isset($_GET['u']) ? absint($_GET['u']) : 0;
		$token = isset($_GET['t']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['t'])) : '';
		$user = $id ? get_userdata($id) : false;
		$ctx['screen'] = 'verify';
		$ctx['good'] = false;
		if (!self::hit('verify_' . self::ip_hash(), 30, HOUR_IN_SECONDS) || !$user || !self::is_member($user) || $token === '') {
			return;
		}
		if (self::is_verified($user->ID)) {
			return; // the same answer as a wrong link: an address with someone else's number in it must not say who is a member
		}
		$hash = (string) get_user_meta($user->ID, 'y4apk_vtoken', true);
		if ($hash !== '' && hash_equals($hash, hash('sha256', $token)) && (int) get_user_meta($user->ID, 'y4apk_vexp', true) > time()) {
			update_user_meta($user->ID, self::VERIFIED, '1');
			delete_user_meta($user->ID, 'y4apk_vtoken');
			delete_user_meta($user->ID, 'y4apk_vexp');
			$ctx['good'] = true;
		}
	}

	private static function do_resend(&$ctx) {
		$ctx['screen'] = 'login';
		$g = self::guard('resend');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$who = trim((string) wp_unslash(isset($_POST['login']) ? $_POST['login'] : ''));
		if (self::hit('resend_ip_' . self::ip_hash(), 3, HOUR_IN_SECONDS) && self::hit('resend_' . strtolower($who), 3, HOUR_IN_SECONDS)) {
			$user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', strtolower($who));
			if ($user && self::is_member($user) && !self::is_verified($user->ID) && !get_user_meta($user->ID, self::BANNED, true)) {
				self::send_verification($user);
			}
		}
		$ctx['screen'] = 'check';
		$ctx['email'] = is_email($who) ? $who : '';
	}

	private static function do_login(&$ctx) {
		$ctx['screen'] = 'login';
		$g = self::guard('login');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$who = trim((string) wp_unslash(isset($_POST['login']) ? $_POST['login'] : ''));
		$pass = (string) wp_unslash(isset($_POST['password']) ? $_POST['password'] : '');
		$ctx['old'] = ['login' => $who];
		$ip = self::ip_hash();
		// Six wrong tries from one address at one name; ten from one address at any; thirty at one name from anywhere.
		// A lock on the name alone would let anyone lock a member out by guessing at it.
		$by = 'login_' . $ip . '_' . strtolower($who);
		$by_name = 'login_name_' . strtolower($who);
		if (self::blocked('login_ip_' . $ip, 10) || self::blocked($by, 6) || self::blocked($by_name, 30)) {
			$ctx['errors'][] = __('Too many tries. Wait 15 minutes and try again.', 'y4apk');
			return;
		}
		$user = ($who !== '' && $pass !== '') ? wp_authenticate($who, $pass) : null;
		if ($user instanceof WP_User && !self::is_member($user)) {
			$user = null; // the people who run the site sign in at the usual place, with its own protections
		}
		if (is_wp_error($user) && in_array($user->get_error_code(), ['y4_unverified', 'y4_banned'], true)) {
			$ctx['errors'][] = $user->get_error_message();
			if ($user->get_error_code() === 'y4_unverified') {
				$ctx['can_resend'] = true;
			}
			return;
		}
		if (!($user instanceof WP_User)) {
			self::hit('login_ip_' . $ip, 10, 15 * MINUTE_IN_SECONDS);
			self::hit($by, 6, 15 * MINUTE_IN_SECONDS);
			self::hit($by_name, 30, 15 * MINUTE_IN_SECONDS);
			$ctx['errors'][] = __('Wrong username or password.', 'y4apk');
			return;
		}
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID, !empty($_POST['remember']), is_ssl());
		do_action('wp_login', $user->user_login, $user);
		wp_safe_redirect(isset($_GET['next']) && $_GET['next'] === 'upload' ? self::url('submit') : self::url());
		exit;
	}

	/**
	 * Signs out with a good token (in the link, or from the form below). Without one
	 * (the header's menu is on cached pages, which cannot carry one) a signed-in
	 * member is asked first, on a page that has a fresh token.
	 */
	private static function do_logout(&$ctx) {
		$n = isset($_REQUEST['_y4n']) ? sanitize_key(wp_unslash($_REQUEST['_y4n'])) : '';
		if ($n !== '' && wp_verify_nonce($n, 'y4apk_acct_logout')) {
			wp_logout();
		} elseif (is_user_logged_in()) {
			$ctx['screen'] = 'logout';
			return;
		}
		wp_safe_redirect(self::url('login', ['ok' => 'out']));
		exit;
	}

	public static function logout_url() {
		return self::url('logout', ['_y4n' => wp_create_nonce('y4apk_acct_logout')]);
	}

	private static function do_forgot(&$ctx) {
		$ctx['screen'] = 'forgot';
		$g = self::guard('forgot');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$who = trim((string) wp_unslash(isset($_POST['login']) ? $_POST['login'] : ''));
		if (self::hit('forgot_ip_' . self::ip_hash(), 4, HOUR_IN_SECONDS) && self::hit('forgot_' . strtolower($who), 3, HOUR_IN_SECONDS)) {
			$user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', strtolower($who));
			if ($user && self::is_member($user) && self::is_verified($user->ID) && !get_user_meta($user->ID, self::BANNED, true)) {
				$key = get_password_reset_key($user);
				if (!is_wp_error($key)) {
					self::mail($user->user_email, __('Choose a new password', 'y4apk'),
						self::message('reset', ['name' => $user->user_login, 'link' => self::url('reset', ['key' => $key, 'login' => $user->user_login])]));
				}
			}
		}
		$ctx['screen'] = 'forgot_sent';
	}

	private static function view_reset(&$ctx) {
		$key = isset($_REQUEST['key']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_REQUEST['key'])) : '';
		$login = isset($_REQUEST['login']) ? strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) wp_unslash($_REQUEST['login']))) : '';
		$ctx['reset'] = ['key' => $key, 'login' => $login];
		if ($ctx['screen'] === 'reset' && !isset($ctx['reset_done'])) {
			$user = ($key !== '' && $login !== '') ? check_password_reset_key($key, $login) : false;
			if (!($user instanceof WP_User) || !self::is_member($user)) {
				$ctx['screen'] = 'reset_bad';
			}
		}
	}

	private static function do_reset(&$ctx) {
		$ctx['screen'] = 'reset';
		$g = self::guard('reset', false);
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		if (!self::hit('reset_ip_' . self::ip_hash(), 10, HOUR_IN_SECONDS)) {
			$ctx['errors'][] = __('Too many tries. Try again later.', 'y4apk');
			return;
		}
		$key = preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash(isset($_POST['key']) ? $_POST['key'] : ''));
		$login = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) wp_unslash(isset($_POST['login']) ? $_POST['login'] : '')));
		$_REQUEST['key'] = $key;
		$_REQUEST['login'] = $login;
		$user = ($key !== '' && $login !== '') ? check_password_reset_key($key, $login) : false;
		if (!($user instanceof WP_User) || !self::is_member($user)) {
			$ctx['screen'] = 'reset_bad';
			return;
		}
		$pass = (string) wp_unslash(isset($_POST['password']) ? $_POST['password'] : '');
		if ($p = self::password_problem($pass, $user->user_login, $user->user_email)) {
			$ctx['errors'][] = $p;
			$ctx['reset_done'] = false;
			return;
		}
		if ($pass !== (string) wp_unslash(isset($_POST['password2']) ? $_POST['password2'] : '')) {
			$ctx['errors'][] = __('The two passwords are not the same.', 'y4apk');
			$ctx['reset_done'] = false;
			return;
		}
		reset_password($user, $pass);
		wp_safe_redirect(self::url('login', ['ok' => 'reset']));
		exit;
	}

	private static function do_password(&$ctx) {
		$ctx['screen'] = 'settings';
		$g = self::guard('password', false);
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$user = $ctx['user'];
		$old = (string) wp_unslash(isset($_POST['current']) ? $_POST['current'] : '');
		$new = (string) wp_unslash(isset($_POST['password']) ? $_POST['password'] : '');
		if (!self::hit('pw_' . $user->ID, 6, HOUR_IN_SECONDS) || !wp_check_password($old, $user->user_pass, $user->ID)) {
			$ctx['errors'][] = __('Your current password is not right.', 'y4apk');
		} elseif ($p = self::password_problem($new, $user->user_login, $user->user_email)) {
			$ctx['errors'][] = $p;
		} elseif ($new !== (string) wp_unslash(isset($_POST['password2']) ? $_POST['password2'] : '')) {
			$ctx['errors'][] = __('The two passwords are not the same.', 'y4apk');
		}
		if ($ctx['errors']) {
			return;
		}
		wp_set_password($new, $user->ID);
		wp_set_auth_cookie($user->ID, true, is_ssl()); // wp_set_password signs everyone out, this device included
		wp_safe_redirect(self::url('settings', ['ok' => 'pw']));
		exit;
	}

	private static function do_close(&$ctx) {
		$ctx['screen'] = 'settings';
		$g = self::guard('close', false);
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$user = $ctx['user'];
		if (!self::hit('close_' . $user->ID, 5, HOUR_IN_SECONDS) || !wp_check_password((string) wp_unslash(isset($_POST['current']) ? $_POST['current'] : ''), $user->user_pass, $user->ID)
			|| strtolower(trim((string) wp_unslash(isset($_POST['confirm']) ? $_POST['confirm'] : ''))) !== $user->user_login) {
			$ctx['errors'][] = __('Type your name and your password to close the account.', 'y4apk');
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$posts = get_posts(['author' => $user->ID, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
		$delete_all = !empty($_POST['delete_scripts']);
		$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
		foreach ($posts as $pid) {
			if ($delete_all) {
				wp_trash_post($pid);
			}
		}
		wp_logout();
		wp_delete_user($user->ID, $delete_all || !$admins ? null : (int) $admins[0]); // scripts left on the site belong to the site
		wp_safe_redirect(self::url('login', ['ok' => 'closed']));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * The dashboard, the form, the public page
	 * ---------------------------------------------------------------- */

	/** A member's scripts with what the page shows for each: [post, status key, label, views, reason]. */
	public static function my_scripts($user_id) {
		$out = [];
		$posts = get_posts(['author' => $user_id, 'post_type' => 'post', 'post_status' => ['publish', 'pending', 'draft', 'private'], 'numberposts' => 100, 'orderby' => 'modified', 'order' => 'DESC']);
		foreach ($posts as $p) {
			$reason = (string) get_post_meta($p->ID, self::REJECT, true);
			if ($p->post_status === 'publish') {
				$key = 'live';
			} elseif ($p->post_status === 'pending') {
				$key = 'review';
			} else {
				$key = $reason !== '' ? 'changes' : 'draft';
			}
			$game = Y4APK_Roblox::game_of($p->ID);
			$out[] = ['post' => $p, 'key' => $key, 'views' => (int) get_post_meta($p->ID, 'wpb_post_views_count', true),
				'game' => $game ? $game->name : (string) get_post_meta($p->ID, self::WANTED, true), 'reason' => $reason];
		}
		return $out;
	}

	private static function view_home(&$ctx) {
		$ctx['scripts'] = self::my_scripts($ctx['user']->ID);
		$ctx['stats'] = ['all' => count($ctx['scripts']), 'live' => 0, 'review' => 0, 'views' => 0];
		foreach ($ctx['scripts'] as $s) {
			$ctx['stats']['live'] += $s['key'] === 'live' ? 1 : 0;
			$ctx['stats']['review'] += $s['key'] === 'review' ? 1 : 0;
			$ctx['stats']['views'] += $s['views'];
		}
		$ctx['stats']['followers'] = Y4APK_Social::followers_count($ctx['user']->ID);
		$ctx['stats']['following'] = Y4APK_Social::following_count($ctx['user']->ID);
	}

	/** /account/following/: the members you follow, and their newest scripts. */
	private static function view_following(&$ctx) {
		$ids = Y4APK_Social::following_ids($ctx['user']->ID);
		$ctx['people'] = Y4APK_Social::following($ctx['user']->ID, 200);
		$ctx['feed'] = $ids ? get_posts(['author__in' => $ids, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 24, 'orderby' => 'date', 'order' => 'DESC']) : [];
	}

	private static function do_customize(&$ctx) {
		$ctx['screen'] = 'customize';
		$g = self::guard('customize', false);
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$err = Y4APK_Social::save($ctx['user'], $_POST, $_FILES);
		if ($err) {
			$ctx['errors'] = $err;
			foreach (['display', 'bio', 'theme', 'youtube', 'discord', 'roblox'] as $k) {
				$ctx['old'][$k] = isset($_POST[$k]) ? sanitize_textarea_field(wp_unslash($_POST[$k])) : ''; // shown again as typed
			}
			return;
		}
		wp_safe_redirect(self::url('customize', ['ok' => 'profile']));
		exit;
	}

	private static function view_profile(&$ctx) {
		$login = strtolower(preg_replace('/[^a-z0-9_]/', '', (string) get_query_var('y4_user')));
		$user = $login !== '' ? get_user_by('login', $login) : false;
		if (!$user || !self::is_public($user)) {
			global $wp_query;
			$wp_query->set_404();
			status_header(404);
			self::$ctx = null;
			return;
		}
		$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['activity', 'stats'], true) ? $_GET['tab'] : 'scripts';
		$sort = isset($_GET['sort']) && $_GET['sort'] === 'views' ? 'views' : 'new';
		$search = isset($_GET['q']) ? trim(mb_substr(sanitize_text_field(wp_unslash($_GET['q'])), 0, 60)) : '';
		// Every live script of the member, for the numbers, the activity and the statistics.
		$all = get_posts(['author' => $user->ID, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'date', 'order' => 'DESC']);
		$views = 0;
		$per = [];
		foreach ($all as $p) {
			$n = (int) get_post_meta($p->ID, 'wpb_post_views_count', true);
			$views += $n;
			$per[$p->ID] = $n;
		}
		$ctx['profile'] = $user;
		$ctx['tab'] = $tab;
		$ctx['sort'] = $sort;
		$ctx['search'] = $search;
		$ctx['views'] = $views;
		$ctx['count'] = count($all);
		$ctx['followers'] = Y4APK_Social::followers_count($user->ID);
		$ctx['following_n'] = Y4APK_Social::following_count($user->ID);
		$me = $ctx['user'];
		$ctx['own'] = $me && (int) $me->ID === (int) $user->ID;
		$ctx['can_follow'] = $me && !$ctx['own'] && self::is_public($me);
		$ctx['i_follow'] = $ctx['can_follow'] && Y4APK_Social::is_following($me->ID, $user->ID);
		$ctx['followers_list'] = Y4APK_Social::followers($user->ID);
		$ctx['following_list'] = Y4APK_Social::following($user->ID);
		if ($tab === 'scripts') {
			$args = ['author' => $user->ID, 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 24, 'paged' => max(1, (int) get_query_var('paged')), 'ignore_sticky_posts' => true];
			$args += $sort === 'views' ? ['meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC'] : ['orderby' => 'date', 'order' => 'DESC'];
			if ($search !== '') {
				$args['s'] = $search;
			}
			$ctx['query'] = new WP_Query($args);
		} elseif ($tab === 'activity') {
			$events = [['time' => strtotime($user->user_registered . ' UTC'), 'kind' => 'joined', 'post' => null]];
			foreach (array_slice($all, 0, 40) as $p) {
				$made = (int) get_post_time('U', true, $p);
				$changed = (int) get_post_modified_time('U', true, $p);
				$events[] = ['time' => $made, 'kind' => 'shared', 'post' => $p];
				if ($changed - $made > DAY_IN_SECONDS) {
					$events[] = ['time' => $changed, 'kind' => 'updated', 'post' => $p];
				}
			}
			usort($events, function ($a, $b) { return $b['time'] - $a['time']; });
			$ctx['events'] = array_slice($events, 0, 30);
		} else {
			arsort($per);
			$ctx['top'] = array_slice($per, 0, 5, true);
			$games = [];
			foreach ($all as $p) {
				$g = Y4APK_Roblox::game_of($p->ID);
				if ($g) {
					$games[$g->term_id] = isset($games[$g->term_id]) ? [$g, $games[$g->term_id][1] + 1] : [$g, 1];
				}
			}
			uasort($games, function ($a, $b) { return $b[1] - $a[1]; });
			$ctx['games'] = array_slice($games, 0, 5, true);
		}
	}

	/** The scripts' address list on a post written by a member: "by @handle". */
	/** The member who shared a post, when they have a public page; else null (the site's own posts). */
	public static function author($post_id) {
		$post = get_post($post_id);
		$user = $post ? get_userdata($post->post_author) : false;
		return $user && self::is_public($user) ? $user : null;
	}

	/* ---- the submit / edit form ---- */

	private static function view_form(&$ctx) {
		$ctx['games'] = get_terms(['taxonomy' => Y4APK_Roblox::TAX, 'hide_empty' => false, 'orderby' => 'name']);
		$ctx['games'] = is_array($ctx['games']) ? $ctx['games'] : [];
		$user = $ctx['user'];
		$ctx['post'] = null;
		if ($ctx['view'] === 'edit') {
			$post = get_post(absint(get_query_var('y4_id')));
			if (!$post || $post->post_type !== 'post' || (int) $post->post_author !== (int) $user->ID || $post->post_status === 'trash') {
				$ctx['screen'] = 'notfound';
				return;
			}
			$ctx['post'] = $post;
			if (empty($ctx['old'])) {
				$game = Y4APK_Roblox::game_of($post->ID);
				$hub = Y4APK_Roblox::hub_name($post->ID);
				$desc = '';
				if (preg_match('~<p>(.*?)</p>~s', $post->post_content, $m)) {
					$desc = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8');
				}
				$ctx['old'] = ['game' => $game ? $game->term_id : 0, 'game_new' => (string) get_post_meta($post->ID, self::WANTED, true), 'hub' => $hub,
					'keyless' => Y4APK_Roblox::keyless($post->post_title) ? 1 : 0, 'desc' => $desc, 'codes' => Y4APK_Scripts::get($post->ID),
					'scope' => $game && strtolower($game->name) === 'universal' ? 'universal' : 'game'];
			}
		} elseif (empty($ctx['old'])) {
			$ctx['old'] = ['game' => 0, 'game_new' => '', 'hub' => '', 'keyless' => 1, 'desc' => '', 'scope' => 'game', 'codes' => [['label' => '', 'code' => '']]];
		}
		if (!self::is_verified($user->ID)) {
			$ctx['screen'] = 'unverified';
		} elseif (!self::open() && !$ctx['post']) {
			$ctx['screen'] = 'closed';
		}
	}

	/** Reads the form: [data, errors]. */
	private static function read_script_form($existing = null) {
		$err = [];
		$game = isset($_POST['game']) ? absint($_POST['game']) : 0;
		$game_new = trim(mb_substr(sanitize_text_field(wp_unslash(isset($_POST['game_new']) ? $_POST['game_new'] : '')), 0, 120)); // a name, or a roblox.com link
		$hub = trim(mb_substr(sanitize_text_field(wp_unslash(isset($_POST['hub']) ? $_POST['hub'] : '')), 0, 40));
		$desc = trim(mb_substr(sanitize_textarea_field(wp_unslash(isset($_POST['desc']) ? $_POST['desc'] : '')), 0, 600));
		// The upload page's "Key system" chip (keysys), or the older form's "No key needed" switch (keyless).
		$keyless = isset($_POST['keysys']) ? ((string) wp_unslash($_POST['keysys']) === '1' ? 0 : 1) : (!empty($_POST['keyless']) ? 1 : 0);
		$scope = isset($_POST['scope']) && $_POST['scope'] === 'universal' ? 'universal' : 'game';
		$labels = isset($_POST['label']) ? array_values((array) wp_unslash($_POST['label'])) : [];
		$codes = isset($_POST['code']) ? array_values((array) wp_unslash($_POST['code'])) : [];
		$rows = [];
		foreach (array_slice($codes, 0, 3) as $i => $c) {
			$c = str_replace(["\r\n", "\r"], "\n", trim((string) $c));
			if ($c !== '') {
				$rows[] = ['label' => trim(mb_substr(sanitize_text_field(isset($labels[$i]) ? (string) $labels[$i] : ''), 0, 30)), 'code' => $c];
			}
		}
		$old = ['game' => $game, 'game_new' => $game_new, 'hub' => $hub, 'desc' => $desc, 'keyless' => $keyless, 'scope' => $scope, 'codes' => $rows ?: [['label' => '', 'code' => '']]];
		$step = 4; // the wizard's step to open again: the first one with a mistake

		$term = $game && $scope === 'game' ? get_term($game, Y4APK_Roblox::TAX) : null;
		$game_label = $game_new;
		if ($scope === 'universal') {
			// One "game" for scripts that work in any game; save_script() makes it the first time.
			$term = get_term_by('slug', 'universal', Y4APK_Roblox::TAX) ?: null;
			$game_label = 'Universal';
		} elseif ((!$term || is_wp_error($term)) && $game_new !== '') {
			// A game already here, by its roblox.com link, place ID or name.
			$place = Y4APK_Roblox::place_from($game_new);
			$found = $place !== '' ? get_terms(['taxonomy' => Y4APK_Roblox::TAX, 'hide_empty' => false, 'number' => 1, 'meta_key' => Y4APK_Roblox::M_PLACE, 'meta_value' => $place]) : [];
			$term = $found && !is_wp_error($found) ? $found[0] : (get_term_by('slug', sanitize_title($game_new), Y4APK_Roblox::TAX) ?: get_term_by('name', $game_new, Y4APK_Roblox::TAX));
			if ((!$term || is_wp_error($term)) && $place !== '') {
				$game_label = Y4APK_Roblox::name_from_link($game_new); // the title says the game's name, not its link
				$game_label = $game_label !== '' ? $game_label : sprintf(__('Roblox game %s', 'y4apk'), $place);
			}
		}
		if (!$term || is_wp_error($term)) {
			$term = null;
			if ($game_new === '' && $scope !== 'universal') {
				$err[] = __('Choose the game this script is for, or type its name.', 'y4apk');
				$step = 1;
			}
		}
		if (mb_strlen($hub) < 2) {
			$err[] = __('Give the script a name: the one its maker gave it, like “Redz Hub”, or your own.', 'y4apk');
			$step = min($step, 2);
		}
		if (!$rows) {
			$err[] = __('Paste the script’s code.', 'y4apk');
			$step = min($step, 3);
		}
		$hosts = [];
		$hashes = [];
		foreach ($rows as $row) {
			$c = $row['code'];
			if (strlen($c) > 4000) {
				$err[] = __('A code is longer than 4,000 characters. Share the loadstring line, not the whole script.', 'y4apk');
				$step = min($step, 3);
				break;
			}
			if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $c)) {
				$err[] = __('The code has characters that are not text.', 'y4apk');
				$step = min($step, 3);
				break;
			}
			if (preg_match('/ROBLOSECURITY|discord(?:app)?\.com\/api\/webhooks|webhook\.site|grabify|iplogger|ipgrabber|\.roblox\.com\/my\/settings/i', $c)) {
				$err[] = __('This code looks like it steals accounts, so it cannot be shared here.', 'y4apk');
				$step = min($step, 3);
				break;
			}
			if (preg_match_all('~https?://([a-z0-9.-]+)~i', $c, $m)) {
				foreach ($m[1] as $h) {
					$hosts[strtolower($h)] = 1;
				}
			}
			$hashes[] = sha1(preg_replace('/\s+/', '', strtolower($c)));
		}
		if (!$err && $hashes) {
			global $wpdb;
			foreach ($hashes as $h) {
				$dup = $wpdb->get_var($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status NOT IN ('trash', 'auto-draft') LIMIT 1", self::HASHES, $h)); // a deleted script's code can be shared again
				if ($dup && (!$existing || (int) $dup !== (int) $existing->ID)) {
					$err[] = __('This script is already on the site.', 'y4apk');
					$step = min($step, 3);
					break;
				}
			}
		}
		$old['step'] = $step;
		if ($term) {
			$old['game'] = (int) $term->term_id;
			$old['game_new'] = '';
		}
		return [['game' => $term, 'universal' => $scope === 'universal', 'game_new' => $game_new, 'game_label' => $game_label, 'hub' => $hub, 'desc' => $desc, 'keyless' => $keyless, 'rows' => $rows, 'hosts' => array_keys($hosts), 'hashes' => $hashes], $err, $old];
	}

	private static function save_script($user, $d, $existing = null) {
		if (!$d['game'] && !empty($d['universal'])) {
			$u = Y4APK_Roblox::find_or_create('Universal', false);
			$d['game'] = is_wp_error($u) ? null : get_term($u, Y4APK_Roblox::TAX);
			$d['game_new'] = $d['game'] ? '' : 'Universal';
		}
		$gname = $d['game'] ? $d['game']->name : $d['game_label'];
		$title = trim($gname . ' Script ' . $d['hub'] . ($d['keyless'] ? ' (No Key)' : ''));
		$text = $d['desc'] !== '' ? $d['desc'] : sprintf(__('Free %1$s script by %2$s for Roblox. Copy the loadstring and run it in your executor.', 'y4apk'), $gname, $d['hub']);
		$content = '';
		foreach (preg_split('/\n{2,}/', $text) as $para) {
			$content .= "<!-- wp:paragraph -->\n<p>" . nl2br(esc_html($para)) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		$cats = Y4APK_Roblox::categories();
		$arr = ['post_title' => $title, 'post_content' => trim($content), 'post_status' => 'pending', 'post_type' => 'post', 'post_author' => $user->ID];
		if ($existing) {
			$arr['ID'] = $existing->ID;
			$id = wp_update_post(wp_slash($arr), true);
		} else {
			if ($cats) {
				$arr['post_category'] = [(int) $cats[0]];
			}
			$id = wp_insert_post(wp_slash($arr), true);
		}
		if (is_wp_error($id) || !$id) {
			return 0;
		}
		update_post_meta($id, 'wp_title_GP', wp_slash(trim($gname . ' Script - [' . $d['hub'] . ']')));
		update_post_meta($id, self::MEMBER_POST, 1);
		delete_post_meta($id, self::REJECT);
		Y4APK_Scripts::put($id, $d['rows']);
		update_post_meta($id, self::HOSTS, $d['hosts']);
		delete_post_meta($id, self::HASHES);
		foreach ($d['hashes'] as $h) {
			add_post_meta($id, self::HASHES, $h);
		}
		if ($d['game']) {
			wp_set_object_terms($id, [(int) $d['game']->term_id], Y4APK_Roblox::TAX);
			delete_post_meta($id, self::WANTED);
			if (!get_post_thumbnail_id($id) && ($att = (int) get_term_meta($d['game']->term_id, Y4APK_Roblox::M_ICON, true))) {
				set_post_thumbnail($id, $att);
			}
		} else {
			update_post_meta($id, self::WANTED, wp_slash($d['game_new']));
		}
		return (int) $id;
	}

	private static function do_submit(&$ctx) {
		$ctx['screen'] = 'submit';
		$user = $ctx['user'];
		if (!$ctx['member'] || !self::is_verified($user->ID)) {
			return;
		}
		list($d, $err, $old) = self::read_script_form();
		$ctx['old'] = $old; // kept on every answer below, so nothing typed is lost
		$g = self::guard('submit');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		$s = self::settings();
		if (!$s['on']) {
			$ctx['errors'][] = __('Sharing is closed at the moment.', 'y4apk');
			return;
		}
		global $wpdb;
		$today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status <> 'trash' AND post_date > %s",
			$user->ID, wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS))); // post_date: a post waiting for review has no post_date_gmt
		$waiting = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status = 'pending'", $user->ID));
		if ($today >= $s['per_day']) {
			$ctx['errors'][] = sprintf(_n('You can share %d script a day. Come back tomorrow.', 'You can share %d scripts a day. Come back tomorrow.', $s['per_day'], 'y4apk'), $s['per_day']);
			return;
		}
		if ($waiting >= $s['max_pending']) {
			$ctx['errors'][] = __('You have many scripts waiting for review. Wait for some to be checked.', 'y4apk');
			return;
		}
		if ($err) {
			$ctx['errors'] = $err;
			return;
		}
		$id = self::save_script($user, $d);
		if (!$id) {
			$ctx['errors'][] = __('Could not save the script. Try again.', 'y4apk');
			return;
		}
		self::tell_owner($id, $user);
		wp_safe_redirect(self::url('home', ['ok' => 'submitted']));
		exit;
	}

	private static function do_edit(&$ctx) {
		$ctx['screen'] = 'edit';
		$user = $ctx['user'];
		$post = get_post(absint(get_query_var('y4_id')));
		if (!$ctx['member'] || !$post || (int) $post->post_author !== (int) $user->ID || $post->post_type !== 'post' || $post->post_status === 'trash') {
			$ctx['screen'] = 'notfound';
			return;
		}
		list($d, $err, $old) = self::read_script_form($post);
		$ctx['old'] = $old;
		$g = self::guard('edit');
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		if ($err) {
			$ctx['errors'] = $err;
			return;
		}
		if (!self::hit('edit_' . $user->ID, 20, HOUR_IN_SECONDS)) {
			$ctx['errors'][] = __('You are editing too fast. Try again later.', 'y4apk');
			return;
		}
		if (!self::save_script($user, $d, $post)) {
			$ctx['errors'][] = __('Could not save the script. Try again.', 'y4apk');
			return;
		}
		self::tell_owner($post->ID, $user);
		wp_safe_redirect(self::url('home', ['ok' => 'resubmitted']));
		exit;
	}

	private static function do_delete(&$ctx) {
		$ctx['screen'] = 'home';
		$user = $ctx['user'];
		$g = self::guard('delete', false);
		$post = get_post(isset($_POST['id']) ? absint($_POST['id']) : 0);
		if ($g === true && $ctx['member'] && $post && (int) $post->post_author === (int) $user->ID && $post->post_type === 'post') {
			wp_trash_post($post->ID);
			wp_safe_redirect(self::url('home', ['ok' => 'deleted']));
			exit;
		}
		$ctx['errors'][] = is_string($g) ? $g : __('Could not delete that script.', 'y4apk');
	}

	/** An email to the site owner when something is waiting (at most one every 15 minutes). */
	private static function tell_owner($post_id, $user) {
		if (!self::settings()['notify'] || !self::hit('notify_owner', 1, 15 * MINUTE_IN_SECONDS)) {
			return;
		}
		self::mail(get_option('admin_email'), __('A script is waiting for review', 'y4apk'),
			self::message('waiting', ['name' => $user->user_login, 'title' => wp_strip_all_tags(get_the_title($post_id))]));
	}

	/** Tells the member when their script goes live (from the review page or the editor). */
	public static function on_publish($new, $old, $post) {
		if ($new !== 'publish' || $old === 'publish' || $post->post_type !== 'post' || !get_post_meta($post->ID, self::MEMBER_POST, true)) {
			return;
		}
		$user = get_userdata($post->post_author);
		if (!$user || !self::is_member($user) || get_post_meta($post->ID, '_y4apk_told', true)) {
			return;
		}
		update_post_meta($post->ID, '_y4apk_told', 1);
		self::mail($user->user_email, __('Your script is live', 'y4apk'),
			self::message('live', ['name' => $user->user_login, 'title' => wp_strip_all_tags(get_the_title($post->ID)), 'link' => get_permalink($post->ID)]));
	}
}

Y4APK_Members::init();
