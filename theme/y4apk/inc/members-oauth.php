<?php
/**
 * Sign in with Google or Discord (2.66).
 *
 * The usual OAuth "code" flow, by the site itself (no plugin): the button goes
 * to /account/google/ (or /account/discord/), which sends the visitor to the
 * provider with a one-time "state" tied to this browser; the provider sends
 * them back to the same address with a code, which the site trades for the
 * member's id and email (with the site's secret, server to server).
 *
 *   - Someone who signed in this way before: in.
 *   - A member with the same email (confirmed by the provider): the provider
 *     is linked to that account, and the email counts as confirmed.
 *   - Someone new: they pick a username first (a Google name never becomes a
 *     public @handle by itself), then the account is made, already confirmed.
 *   - Signed in already: the provider is linked to this account (Settings).
 *
 * Accounts of the people who run the site are never signed in or linked this
 * way. Only the provider's id, the email and the name the member picks are
 * kept: no picture, no real name, no tokens.
 *
 * The keys: Y4APK ▸ Members ▸ Settings, or in wp-config.php
 * (Y4APK_GOOGLE_ID / Y4APK_GOOGLE_SECRET, Y4APK_DISCORD_ID / Y4APK_DISCORD_SECRET).
 */
if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_OAuth {

	const OPTION = 'y4apk_oauth';
	const COOKIE = 'y4apk_oauth';
	const NOPASS = 'y4apk_nopass'; // usermeta: made by Google/Discord, no password of their own yet

	public static function providers() {
		return apply_filters('y4apk_oauth_providers', [
			'google'  => ['name' => 'Google', 'auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
				'me' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile', 'meta' => 'y4apk_google_id'],
			'discord' => ['name' => 'Discord', 'auth' => 'https://discord.com/oauth2/authorize', 'token' => 'https://discord.com/api/oauth2/token',
				'me' => 'https://discord.com/api/users/@me', 'scope' => 'identify email', 'meta' => 'y4apk_discord_id'],
		]);
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], ['google_id' => '', 'google_secret' => '', 'discord_id' => '', 'discord_secret' => '']);
		foreach (['google', 'discord'] as $p) {
			$o[$p . '_secret'] = class_exists('Y4APK_Mail') ? Y4APK_Mail::reveal($o[$p . '_secret']) : $o[$p . '_secret'];
			$c = 'Y4APK_' . strtoupper($p);
			if (defined($c . '_ID') && defined($c . '_SECRET')) {
				$o[$p . '_id'] = (string) constant($c . '_ID');
				$o[$p . '_secret'] = (string) constant($c . '_SECRET');
				$o[$p . '_config'] = true;
			}
		}
		return $o;
	}

	/** Members ▸ Settings: the ids, and a secret only when one is typed (an empty box keeps the old one). */
	public static function save_settings($in) {
		$old = get_option(self::OPTION, []);
		$old = is_array($old) ? $old : [];
		$new = $old;
		foreach (['google', 'discord'] as $p) {
			$new[$p . '_id'] = substr(sanitize_text_field((string) ($in['oauth_' . $p . '_id'] ?? '')), 0, 200);
			$secret = trim((string) ($in['oauth_' . $p . '_secret'] ?? ''));
			if (!empty($in['oauth_' . $p . '_forget'])) {
				$new[$p . '_secret'] = '';
			} elseif ($secret !== '') {
				$new[$p . '_secret'] = class_exists('Y4APK_Mail') ? Y4APK_Mail::hide(substr($secret, 0, 200)) : substr($secret, 0, 200);
			}
		}
		update_option(self::OPTION, $new, false);
	}

	public static function ready($p) {
		$s = self::settings();
		return isset(self::providers()[$p]) && $s[$p . '_id'] !== '' && $s[$p . '_secret'] !== '';
	}

	/** The providers that are set up. */
	public static function on() {
		return array_values(array_filter(array_keys(self::providers()), [__CLASS__, 'ready']));
	}

	/** The address the provider sends people back to (put it in the provider's app). */
	public static function redirect_uri($p) {
		// The same in every language (2.66.1): on a Thai page every link gets /th/, and the provider
		// takes only the one address registered with it, so this one is built from the bare home address.
		return untrailingslashit((string) get_option('home')) . '/account/' . $p . '/';
	}

	/** A button's address: the sign-in, and where to go after (?next=). */
	public static function start_url($p) {
		return Y4APK_Members::url($p, Y4APK_Members::next_args());
	}

	/* ---------------------------------------------------------------- *
	 * /account/google/ and /account/discord/
	 * ---------------------------------------------------------------- */

	public static function route(&$ctx) {
		$p = $ctx['view'];
		$prov = self::providers()[$p];
		$back = $ctx['user'] ? 'settings' : 'login';
		$ctx['screen'] = $back;
		if (!self::ready($p)) {
			$ctx['errors'][] = sprintf(__('Signing in with %s is not open at the moment.', 'y4apk'), $prov['name']);
			return;
		}
		// Step 3: a new member picks a username
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['y4_do'] ?? '') === 'oauth_finish') {
			self::finish($ctx, $p);
			return;
		}
		// Back from the provider: cancelled (WordPress drops ?error= itself: a state with no code says it), or a code
		if (isset($_GET['state']) && !isset($_GET['code'])) {
			$ctx['errors'][] = sprintf(__('Signing in with %s was cancelled.', 'y4apk'), $prov['name']);
			self::forget_state();
			return;
		}
		if (isset($_GET['code'], $_GET['state'])) {
			self::callback($ctx, $p);
			return;
		}
		// Step 1: off to the provider
		if (!Y4APK_Members::hit('oauth_start_' . Y4APK_Members::ip_hash(), 30, HOUR_IN_SECONDS)) {
			$ctx['errors'][] = __('Too many tries from here. Try again later.', 'y4apk');
			return;
		}
		$state = wp_generate_password(40, false, false);
		set_transient('y4apk_oauth_' . hash('sha256', $state), ['p' => $p, 'next' => Y4APK_Members::next_args(), 'uid' => $ctx['user'] ? (int) $ctx['user']->ID : 0], 15 * MINUTE_IN_SECONDS);
		self::cookie($state, time() + 15 * MINUTE_IN_SECONDS);
		$args = ['client_id' => self::settings()[$p . '_id'], 'redirect_uri' => self::redirect_uri($p), 'response_type' => 'code', 'scope' => $prov['scope'], 'state' => $state];
		$args += $p === 'google' ? ['prompt' => 'select_account', 'access_type' => 'online'] : ['prompt' => 'none'];
		wp_redirect($prov['auth'] . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986)); // phpcs:ignore WordPress.Security.SafeRedirect -- the provider's own address
		exit;
	}

	private static function cookie($value, $expire) {
		if (!headers_sent()) {
			setcookie(self::COOKIE, $value, ['expires' => $expire, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
		}
		$_COOKIE[self::COOKIE] = $value;
	}

	private static function forget_state() {
		if (isset($_COOKIE[self::COOKIE])) {
			delete_transient('y4apk_oauth_' . hash('sha256', (string) $_COOKIE[self::COOKIE]));
			self::cookie('', time() - YEAR_IN_SECONDS);
		}
	}

	/** Step 2: the code for the member's id and email; then in, linked, or "pick a username". */
	private static function callback(&$ctx, $p) {
		$prov = self::providers()[$p];
		$state = (string) wp_unslash($_GET['state']);
		$cookie = isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '';
		$saved = $cookie !== '' && hash_equals($cookie, $state) ? get_transient('y4apk_oauth_' . hash('sha256', $state)) : false;
		self::forget_state();
		if (!is_array($saved) || $saved['p'] !== $p) {
			$ctx['errors'][] = __('That sign-in link expired or was opened in another browser. Try again.', 'y4apk');
			return;
		}
		$me = self::identity($p, (string) wp_unslash($_GET['code']));
		if (is_wp_error($me)) {
			$ctx['errors'][] = $me->get_error_message();
			return;
		}
		$next = (array) $saved['next'];
		// Signed in already: link to this account
		if ($saved['uid']) {
			$user = $ctx['user'];
			if (!$user || (int) $user->ID !== (int) $saved['uid'] || !Y4APK_Members::is_member($user)) {
				$ctx['errors'][] = __('Sign in again, then connect.', 'y4apk');
				return;
			}
			$other = self::user_by_id($p, $me['id']);
			if ($other && (int) $other->ID !== (int) $user->ID) {
				$ctx['errors'][] = sprintf(__('That %s account is already connected to another member.', 'y4apk'), $prov['name']);
				return;
			}
			update_user_meta($user->ID, $prov['meta'], $me['id']);
			if (strtolower($user->user_email) === strtolower($me['email']) && Y4APK_Members::unconfirmed($user)) {
				update_user_meta($user->ID, Y4APK_Members::VERIFIED, '1');
			}
			wp_safe_redirect(Y4APK_Members::url('settings', ['ok' => 'linked']));
			exit;
		}
		// Seen before, or a member with this email
		$user = self::user_by_id($p, $me['id']);
		if (!$user) {
			$by_mail = get_user_by('email', $me['email']);
			if ($by_mail && !Y4APK_Members::is_member($by_mail)) {
				$ctx['errors'][] = __('This email belongs to an account that signs in another way.', 'y4apk'); // staff: never through here
				return;
			}
			if ($by_mail && get_user_meta($by_mail->ID, $prov['meta'], true)) {
				$ctx['errors'][] = sprintf(__('That email is already connected to another %s account. Sign in with that one, or with your password.', 'y4apk'), $prov['name']);
				return;
			}
			if ($by_mail) {
				update_user_meta($by_mail->ID, $prov['meta'], $me['id']);
				$user = $by_mail;
			}
		}
		if ($user) {
			if (!Y4APK_Members::is_member($user)) {
				$ctx['errors'][] = __('This email belongs to an account that signs in another way.', 'y4apk');
				return;
			}
			if (get_user_meta($user->ID, Y4APK_Members::BANNED, true)) {
				$ctx['errors'][] = __('This account is closed.', 'y4apk');
				return;
			}
			if (Y4APK_Members::unconfirmed($user) && strtolower($user->user_email) === strtolower($me['email'])) {
				update_user_meta($user->ID, Y4APK_Members::VERIFIED, '1'); // the provider has confirmed this address
			}
			self::sign_in($user, $next);
		}
		// Someone new
		if (!Y4APK_Members::open()) {
			$ctx['errors'][] = __('Registration is closed at the moment.', 'y4apk');
			return;
		}
		$token = wp_generate_password(40, false, false);
		set_transient('y4apk_oauth_new_' . hash('sha256', $token), ['p' => $p, 'me' => $me, 'next' => $next], 30 * MINUTE_IN_SECONDS);
		self::cookie($token, time() + 30 * MINUTE_IN_SECONDS);
		$ctx['screen'] = 'oauth_pick';
		$ctx['oauth'] = ['p' => $p, 'name' => $prov['name'], 'email' => Y4APK_Members::masked_email($me['email'])];
		$ctx['old']['handle'] = self::suggest($me['handle']);
	}

	/** Step 3: the username, then the account. */
	private static function finish(&$ctx, $p) {
		$prov = self::providers()[$p];
		$token = isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '';
		$saved = $token !== '' ? get_transient('y4apk_oauth_new_' . hash('sha256', $token)) : false;
		if (!is_array($saved) || $saved['p'] !== $p || !wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_oauth_finish')) {
			$ctx['errors'][] = sprintf(__('That took too long. Sign in with %s again.', 'y4apk'), $prov['name']);
			return;
		}
		$me = $saved['me'];
		$handle = strtolower(trim((string) wp_unslash($_POST['handle'] ?? '')));
		$ctx['screen'] = 'oauth_pick';
		$ctx['oauth'] = ['p' => $p, 'name' => $prov['name'], 'email' => Y4APK_Members::masked_email($me['email'])];
		$ctx['old']['handle'] = $handle;
		if (!preg_match('/^[a-z0-9_]{3,20}$/', $handle)) {
			$ctx['errors'][] = __('Pick a name of 3–20 letters, numbers or underscores.', 'y4apk');
		} elseif (Y4APK_Members::reserved($handle) || username_exists($handle) || get_user_by('slug', $handle)) {
			$ctx['errors'][] = __('That name is taken. Pick another.', 'y4apk');
		}
		if (empty($_POST['terms'])) {
			$ctx['errors'][] = __('Tick the box to agree to the rules.', 'y4apk');
		}
		if ($ctx['errors']) {
			return;
		}
		$ip = Y4APK_Members::ip_hash();
		if (!Y4APK_Members::open() || email_exists($me['email']) || self::user_by_id($p, $me['id'])) {
			$ctx['errors'][] = __('Could not create the account. Try again.', 'y4apk');
			return;
		}
		if (!Y4APK_Members::hit('reg_ip_' . $ip, 4, HOUR_IN_SECONDS) || !Y4APK_Members::hit('reg_all', 150, HOUR_IN_SECONDS)) {
			$ctx['errors'][] = __('Too many sign-ups from here. Try again later.', 'y4apk');
			return;
		}
		$id = wp_insert_user(['user_login' => $handle, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $me['email'], 'role' => Y4APK_Members::ROLE,
			'display_name' => $handle, 'nickname' => $handle, 'show_admin_bar_front' => 'false', 'rich_editing' => 'false']);
		if (is_wp_error($id)) {
			$ctx['errors'][] = __('Could not create the account. Try again.', 'y4apk');
			return;
		}
		update_user_meta($id, Y4APK_Members::VERIFIED, '1');
		update_user_meta($id, 'y4apk_ip', $ip);
		update_user_meta($id, $prov['meta'], $me['id']);
		update_user_meta($id, self::NOPASS, $p);
		delete_transient('y4apk_oauth_new_' . hash('sha256', $token));
		self::cookie('', time() - YEAR_IN_SECONDS);
		self::sign_in(get_userdata($id), $saved['next'], 'welcome_social');
	}

	private static function sign_in($user, $next, $ok = '') {
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID, true, is_ssl());
		do_action('wp_login', $user->user_login, $user);
		$next = (array) $next;
		$url = !empty($next['next']) ? ($next['next'] === 'upload' ? Y4APK_Members::url('submit') : Y4APK_Members::url('share/' . ($next['next'] === 'app' ? 'app' : 'game'), !empty($next['newer']) ? ['newer' => (int) $next['newer']] : []))
			: Y4APK_Members::url('', $ok ? ['ok' => $ok] : []);
		wp_safe_redirect($url);
		exit;
	}

	public static function user_by_id($p, $id) {
		$u = get_users(['meta_key' => self::providers()[$p]['meta'], 'meta_value' => (string) $id, 'number' => 1]);
		return $u ? $u[0] : null;
	}

	/** The code for ['id', 'email', 'handle'] (or WP_Error): confirmed emails only. */
	private static function identity($p, $code) {
		$prov = self::providers()[$p];
		$s = self::settings();
		$fail = new WP_Error('y4_oauth', sprintf(__('Could not sign in with %s. Try again.', 'y4apk'), $prov['name']));
		$r = wp_remote_post($prov['token'], ['timeout' => 15, 'headers' => ['Accept' => 'application/json'], 'body' => [
			'client_id' => $s[$p . '_id'], 'client_secret' => $s[$p . '_secret'], 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirect_uri($p)]]);
		$tok = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
		if (!is_array($tok) || empty($tok['access_token'])) {
			return $fail;
		}
		$r = wp_remote_get($prov['me'], ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $tok['access_token'], 'Accept' => 'application/json']]);
		$me = is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200 ? null : json_decode((string) wp_remote_retrieve_body($r), true);
		if (!is_array($me)) {
			return $fail;
		}
		if ($p === 'google') {
			$out = ['id' => (string) ($me['sub'] ?? ''), 'email' => (string) ($me['email'] ?? ''), 'ok' => !empty($me['email_verified']) && $me['email_verified'] !== 'false', 'handle' => '']; // no suggestion: an email's name part is often a real name
		} else {
			$out = ['id' => (string) ($me['id'] ?? ''), 'email' => (string) ($me['email'] ?? ''), 'ok' => !empty($me['verified']), 'handle' => (string) ($me['username'] ?? '')];
		}
		$out['email'] = sanitize_email($out['email']);
		if ($out['id'] === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $out['id'])) {
			return $fail;
		}
		if (!is_email($out['email']) || !$out['ok']) {
			return new WP_Error('y4_oauth_mail', sprintf(__('Your %s account has no confirmed email. Confirm it there first, or sign up with your email here.', 'y4apk'), $prov['name']));
		}
		unset($out['ok']);
		return $out;
	}

	/** A free username like theirs, as a start (they can change it before the account is made). */
	private static function suggest($base) {
		$base = substr(preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['.', '-', ' '], '_', (string) $base))), 0, 16);
		if (strlen($base) < 3) {
			return '';
		}
		$try = $base;
		for ($i = 0; $i < 20; $i++) {
			if (!Y4APK_Members::reserved($try) && !username_exists($try) && !get_user_by('slug', $try)) {
				return $try;
			}
			$try = substr($base, 0, 16) . wp_rand(10, 9999);
		}
		return '';
	}

	/** Settings ▸ Sign-in methods: disconnect (not the last way in of a member with no password). */
	public static function unlink($user, $p) {
		if (isset(self::providers()[$p]) && self::can_unlink($user)) {
			delete_user_meta($user->ID, self::providers()[$p]['meta']);
		}
	}

	public static function can_unlink($user) {
		if (!get_user_meta($user->ID, self::NOPASS, true)) {
			return true;
		}
		$n = 0;
		foreach (self::providers() as $prov) {
			$n += get_user_meta($user->ID, $prov['meta'], true) ? 1 : 0;
		}
		return $n > 1;
	}

	/* ---------------------------------------------------------------- *
	 * The buttons
	 * ---------------------------------------------------------------- */

	public static function icon($p, $size = 20) {
		if ($p === 'google') {
			return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1Z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84Z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A10.94 10.94 0 0 0 12 1 11 11 0 0 0 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52Z"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true"><path d="M20.32 4.37A19.8 19.8 0 0 0 15.4 2.85a.07.07 0 0 0-.08.04c-.21.38-.45.87-.61 1.25a18.27 18.27 0 0 0-5.49 0 12.6 12.6 0 0 0-.62-1.25.08.08 0 0 0-.08-.04 19.74 19.74 0 0 0-4.88 1.52.07.07 0 0 0-.03.03C.53 9.05-.32 13.58.1 18.06c0 .02.01.04.03.06a19.9 19.9 0 0 0 5.99 3.03.08.08 0 0 0 .08-.03c.46-.63.87-1.3 1.23-2a.08.08 0 0 0-.04-.1 13.1 13.1 0 0 1-1.87-.9.08.08 0 0 1 0-.12l.37-.29a.07.07 0 0 1 .08-.01c3.93 1.8 8.18 1.8 12.06 0a.07.07 0 0 1 .08.01l.37.29a.08.08 0 0 1 0 .12c-.6.35-1.22.65-1.87.9a.08.08 0 0 0-.04.1c.36.7.77 1.37 1.23 2a.08.08 0 0 0 .08.03 19.84 19.84 0 0 0 6-3.03.08.08 0 0 0 .03-.05c.5-5.18-.84-9.67-3.55-13.66a.06.06 0 0 0-.03-.03ZM8.02 15.33c-1.18 0-2.16-1.09-2.16-2.42 0-1.33.96-2.42 2.16-2.42 1.21 0 2.18 1.1 2.16 2.42 0 1.33-.96 2.42-2.16 2.42Zm7.97 0c-1.18 0-2.15-1.09-2.15-2.42 0-1.33.95-2.42 2.15-2.42 1.21 0 2.18 1.1 2.16 2.42 0 1.33-.95 2.42-2.16 2.42Z"/></svg>';
	}

	/** "Continue with Google / Discord" on the sign-in and sign-up pages ('' when none is set up). */
	public static function buttons($register = false) {
		$on = self::on();
		if (!$on) {
			return '';
		}
		$out = '<div class="acc-social">';
		foreach ($on as $p) {
			$out .= '<a class="acc-social-btn is-' . esc_attr($p) . '" href="' . esc_url(self::start_url($p)) . '" rel="nofollow">' . self::icon($p) . '<span>'
				. esc_html(sprintf(__('Continue with %s', 'y4apk'), self::providers()[$p]['name'])) . '</span></a>';
		}
		$out .= '</div><div class="acc-or"><span>' . esc_html__('or with your email', 'y4apk') . '</span></div>';
		return $out;
	}
}
