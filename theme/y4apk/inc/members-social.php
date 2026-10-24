<?php
/**
 * Y4APK Members ▸ the social part (2.39): profiles at /@name with a theme, a
 * display name, a bio, links and a Roblox avatar; members follow each other.
 *
 *   Following      one usermeta row per follow: y4apk_follows = the followed member's ID
 *   Profile        y4apk_name, y4apk_bio, y4apk_theme, y4apk_links, y4apk_rbx (usermeta)
 *   Pictures       a member's own profile picture and banner (2.40): checked, cropped and
 *                  re-encoded here (which drops what a file carries besides the picture),
 *                  then kept in Cloudflare R2 (https://files.y4apk.net/members/<id>/…);
 *                  nothing stays on this server. Removed with the account, on a ban, or
 *                  by the owner in Y4APK ▸ Members.
 *   Roblox avatar  found by Roblox username; the picture stays on Roblox's CDN, and its
 *                  address, which expires, is renewed every day (a picture of their own
 *                  comes first)
 *   Last seen      y4apk_seen: written when a signed-in member opens a page, and every 90
 *                  seconds while one of their tabs is open (a ping); "Online now" means
 *                  seen in the last 3 minutes, and a profile asks status.php again while
 *                  it is open, so the light goes out soon after the member leaves
 *   Header menu    the y4apk_me cookie (2.41): login, display name, picture address and
 *                  letter colour (all on the member's public page anyway), for the header
 *                  to show them signed in. Pages are the same for everyone, so a cached
 *                  page stays right: the header's script reads the cookie in the browser.
 *                  Set with the sign-in cookie, kept up to date while signed in, removed
 *                  on sign-out.
 */

defined('ABSPATH') || exit;

class Y4APK_Social {
	const FOLLOWS = 'y4apk_follows';
	const NAME    = 'y4apk_name';
	const BIO     = 'y4apk_bio';
	const THEME   = 'y4apk_theme';
	const LINKS   = 'y4apk_links';
	const RBX     = 'y4apk_rbx';   // ['id', 'name', 'img', 'at']
	const SEEN    = 'y4apk_seen';
	const PIC     = 'y4apk_pic';    // ['key', 'url', 'at']
	const BANNER  = 'y4apk_banner'; // ['key', 'url', 'at']
	const ONLINE  = 180; // seconds since the last sign of life that still count as online
	const HOOK    = 'y4apk_avatars';
	const ME      = 'y4apk_me';
	const UA      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

	public static function init() {
		add_action('wp_ajax_y4apk_follow', [__CLASS__, 'ajax_follow']);
		add_action('init', [__CLASS__, 'seen'], 20);
		add_action('wp_ajax_y4apk_ping', [__CLASS__, 'ping']);
		add_action('wp_footer', [__CLASS__, 'ping_script'], 50);
		add_action(self::HOOK, [__CLASS__, 'refresh_avatars']);
		add_action('init', [__CLASS__, 'schedule']);
		add_action('deleted_user', [__CLASS__, 'forget']);
		add_action('delete_user', [__CLASS__, 'drop_images']); // before the account goes: its pictures on R2 too
		add_action('set_logged_in_cookie', [__CLASS__, 'me_on_login'], 10, 4);
		add_action('clear_auth_cookie', [__CLASS__, 'me_clear']);
		add_action('template_redirect', [__CLASS__, 'me_sync'], 2);
	}

	/** The profile themes: the banner, the accent, the swatches on the Edit profile page. */
	public static function themes() {
		return [
			'ocean'    => ['name' => __('Ocean', 'y4apk'), 'note' => __('Y4APK blue on deep navy.', 'y4apk'), 'sw' => ['#29b1d8', '#1d4f8f', '#7fe0ff', '#0f1d3a']],
			'midnight' => ['name' => __('Midnight', 'y4apk'), 'note' => __('Clean near-black. Timeless.', 'y4apk'), 'sw' => ['#5b8cff', '#2a3140', '#9aa7bd', '#0d1016']],
			'pumpkin'  => ['name' => __('Pumpkin', 'y4apk'), 'note' => __('Orange on midnight purple, with fog.', 'y4apk'), 'sw' => ['#ff7a18', '#7b3fd1', '#a6f04a', '#160d24']],
			'aurora'   => ['name' => __('Aurora', 'y4apk'), 'note' => __('A slow, glowing gradient.', 'y4apk'), 'sw' => ['#b06bff', '#1fb6ff', '#ff6fb5', '#120c2b']],
			'cosmic'   => ['name' => __('Cosmic', 'y4apk'), 'note' => __('Twinkling stars.', 'y4apk'), 'sw' => ['#8fa8ff', '#6c4bd8', '#30d5c8', '#070a22']],
			'matrix'   => ['name' => __('Matrix', 'y4apk'), 'note' => __('Green digital rain.', 'y4apk'), 'sw' => ['#22e05a', '#0f8a35', '#7dff9e', '#020a04']],
		];
	}

	public static function schedule() {
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Following
	 * ---------------------------------------------------------------- */

	public static function following_ids($user_id) {
		return array_values(array_unique(array_map('intval', (array) get_user_meta($user_id, self::FOLLOWS, false))));
	}

	public static function is_following($user_id, $target_id) {
		return in_array((int) $target_id, self::following_ids($user_id), true);
	}

	public static function followers_count($user_id) {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", self::FOLLOWS, (string) (int) $user_id));
	}

	public static function following_count($user_id) {
		return count(self::following_ids($user_id));
	}

	/** Public members who follow this one (newest first): WP_User[]. */
	public static function followers($user_id, $limit = 60) {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY umeta_id DESC LIMIT %d", self::FOLLOWS, (string) (int) $user_id, (int) $limit));
		return self::public_users($ids);
	}

	public static function following($user_id, $limit = 60) {
		return self::public_users(array_slice(array_reverse(self::following_ids($user_id)), 0, $limit));
	}

	private static function public_users($ids) {
		$out = [];
		foreach ($ids as $id) {
			$u = get_userdata((int) $id);
			if ($u && Y4APK_Members::is_public($u)) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/** Follow or unfollow: [following, followers] or a WP_Error. */
	public static function toggle($user, $target_id, $follow) {
		$target = get_userdata((int) $target_id);
		if (!$target || !Y4APK_Members::is_public($target)) {
			return new WP_Error('y4', __('That member is not here.', 'y4apk'));
		}
		if ((int) $target->ID === (int) $user->ID) {
			return new WP_Error('y4', __('You cannot follow yourself.', 'y4apk'));
		}
		if ($follow && !self::is_following($user->ID, $target->ID)) {
			if (self::following_count($user->ID) >= 1000) {
				return new WP_Error('y4', __('You follow a lot of members already.', 'y4apk'));
			}
			add_user_meta($user->ID, self::FOLLOWS, (string) (int) $target->ID);
		} elseif (!$follow) {
			delete_user_meta($user->ID, self::FOLLOWS, (string) (int) $target->ID);
		}
		return [self::is_following($user->ID, $target->ID), self::followers_count($target->ID)];
	}

	public static function ajax_follow() {
		check_ajax_referer('y4apk_follow', 'nonce');
		$user = wp_get_current_user();
		if (!Y4APK_Members::is_public($user)) {
			wp_send_json_error(['message' => __('Sign in with a member account to follow.', 'y4apk')], 403);
		}
		if (!Y4APK_Members::hit('follow_' . $user->ID, 60, HOUR_IN_SECONDS)) {
			wp_send_json_error(['message' => __('Too many tries. Wait a while.', 'y4apk')], 429);
		}
		$r = self::toggle($user, isset($_POST['id']) ? absint($_POST['id']) : 0, !empty($_POST['follow']));
		if (is_wp_error($r)) {
			wp_send_json_error(['message' => $r->get_error_message()], 400);
		}
		wp_send_json_success(['following' => $r[0], 'followers' => $r[1]]);
	}

	/** A closed account leaves no follows behind, on either side. */
	public static function forget($user_id) {
		global $wpdb;
		$wpdb->delete($wpdb->usermeta, ['meta_key' => self::FOLLOWS, 'meta_value' => (string) (int) $user_id]);
	}

	/* ---------------------------------------------------------------- *
	 * The profile
	 * ---------------------------------------------------------------- */

	public static function name($user) {
		$n = trim((string) get_user_meta($user->ID, self::NAME, true));
		return $n !== '' ? $n : $user->user_login;
	}

	public static function bio($user) {
		return (string) get_user_meta($user->ID, self::BIO, true);
	}

	public static function theme($user) {
		$t = (string) get_user_meta($user->ID, self::THEME, true);
		return isset(self::themes()[$t]) ? $t : 'ocean';
	}

	public static function links($user) {
		$l = get_user_meta($user->ID, self::LINKS, true);
		return wp_parse_args(is_array($l) ? $l : [], ['youtube' => '', 'discord' => '']);
	}

	public static function roblox($user) {
		$r = get_user_meta($user->ID, self::RBX, true);
		return wp_parse_args(is_array($r) ? $r : [], ['id' => '', 'name' => '', 'img' => '', 'at' => 0]);
	}

	/** The address of the member's picture: their own, else their Roblox avatar; '' for none. */
	public static function picture($user) {
		$src = self::image_url($user, 'pic');
		if ($src === '') {
			$r = self::roblox($user);
			$src = $r['img'] !== '' && Y4APK_Roblox::cdn_url($r['img']) ? $r['img'] : '';
		}
		return $src;
	}

	/** The member's picture: their own, else their Roblox avatar, else a tile with their first letter. */
	public static function avatar($user, $size = 56) {
		$src = self::picture($user);
		if ($src !== '') {
			return '<img class="acc-av acc-av-img" src="' . esc_url($src) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px">';
		}
		return Y4APK_Members::avatar($user->user_login, $size);
	}

	/* ---------------------------------------------------------------- *
	 * The header's "signed in as" cookie
	 * ---------------------------------------------------------------- */

	/** What the header shows: public things only. */
	public static function me_value($user) {
		return wp_json_encode(['l' => $user->user_login, 'n' => self::name($user), 'a' => self::picture($user), 'h' => abs(crc32($user->user_login)) % 360], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private static function me_set($value, $expire) {
		if (headers_sent()) {
			return;
		}
		// Raw, %-encoded: the header's script reads it with decodeURIComponent. Not HttpOnly: that script is its reader.
		setrawcookie(self::ME, rawurlencode($value), ['expires' => $expire, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax']);
		$_COOKIE[self::ME] = $value;
	}

	/** With the sign-in cookie, and as long-lived (a session cookie when "remember me" was not ticked). */
	public static function me_on_login($cookie, $expire, $expiration, $user_id) {
		$user = get_userdata($user_id);
		if ($user && Y4APK_Members::is_member($user)) {
			self::me_set(self::me_value($user), (int) $expire);
		}
	}

	public static function me_clear() {
		if (isset($_COOKIE[self::ME])) {
			self::me_set('', time() - YEAR_IN_SECONDS);
			unset($_COOKIE[self::ME]);
		}
	}

	/** A new name or picture, a member signed in before 2.41, or signed out elsewhere: brought up to date. */
	public static function me_sync() {
		$user = wp_get_current_user();
		if (!$user->exists()) {
			self::me_clear();
			return;
		}
		if (!Y4APK_Members::is_member($user)) {
			return;
		}
		$value = self::me_value($user);
		if (!isset($_COOKIE[self::ME]) || wp_unslash($_COOKIE[self::ME]) !== $value) {
			$auth = wp_parse_auth_cookie('', 'logged_in');
			self::me_set($value, $auth && !empty($auth['expiration']) ? (int) $auth['expiration'] : 0);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Pictures a member uploads (R2)
	 * ---------------------------------------------------------------- */

	/** What each picture may be, and what it becomes. */
	public static function image_rules($kind) {
		return $kind === 'banner'
			? ['meta' => self::BANNER, 'max' => 5 * MB_IN_BYTES, 'types' => [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], 'min' => [600, 200], 'out' => [1800, 600], 'label' => __('banner', 'y4apk')]
			: ['meta' => self::PIC, 'max' => 4 * MB_IN_BYTES, 'types' => [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], 'min' => [96, 96], 'out' => [400, 400], 'label' => __('profile picture', 'y4apk')];
	}

	/** The address of the member's own picture ('pic') or banner on R2, or ''. */
	public static function image_url($user, $kind) {
		$v = get_user_meta($user->ID, self::image_rules($kind)['meta'], true);
		if (!is_array($v) || empty($v['url']) || !class_exists('Y4APK_R2')) {
			return '';
		}
		return strpos($v['url'], Y4APK_R2::settings()['public'] . '/') === 0 ? $v['url'] : '';
	}

	/** An uploaded file ($_FILES entry) → cropped, re-encoded, on R2, in the member's profile. True or a WP_Error. */
	public static function store_image($user, $file, $kind) {
		$rules = self::image_rules($kind);
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return new WP_Error('y4', __('Picture uploads are not set up on this site yet.', 'y4apk'));
		}
		if (!is_array($file) || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
			$big = is_array($file) && in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
			return new WP_Error('y4', $big ? sprintf(__('The %s is too big.', 'y4apk'), $rules['label']) : sprintf(__('The %s did not arrive. Try again.', 'y4apk'), $rules['label']));
		}
		if ((int) $file['size'] > $rules['max']) {
			return new WP_Error('y4', sprintf(__('The %1$s is too big: up to %2$s MB.', 'y4apk'), $rules['label'], (int) ($rules['max'] / MB_IN_BYTES)));
		}
		$info = @getimagesize($file['tmp_name']);
		if (!$info || !in_array($info[2], $rules['types'], true)) {
			return new WP_Error('y4', $kind === 'banner' ? __('The banner must be a PNG, JPG or WebP picture.', 'y4apk') : __('The profile picture must be a PNG, JPG, WebP or GIF picture.', 'y4apk'));
		}
		list($w, $h) = $info;
		if ($w < $rules['min'][0] || $h < $rules['min'][1]) {
			return new WP_Error('y4', sprintf(__('The %1$s is too small: at least %2$d × %3$d pixels.', 'y4apk'), $rules['label'], $rules['min'][0], $rules['min'][1]));
		}
		if ($w * $h > 40000000) {
			return new WP_Error('y4', sprintf(__('The %s has too many pixels.', 'y4apk'), $rules['label']));
		}
		if (!Y4APK_Members::hit('img_' . $user->ID, 12, HOUR_IN_SECONDS)) {
			return new WP_Error('y4', __('You changed your pictures many times. Try again in an hour.', 'y4apk'));
		}
		$open = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
		$src = function_exists($open[$info[2]]) ? @call_user_func($open[$info[2]], $file['tmp_name']) : false;
		if (!$src) {
			return new WP_Error('y4', sprintf(__('The %s could not be read.', 'y4apk'), $rules['label']));
		}
		// A phone's photo may be stored on its side, with a note saying which way is up.
		if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
			$exif = @exif_read_data($file['tmp_name']);
			$turn = [3 => 180, 6 => -90, 8 => 90];
			if (!empty($exif['Orientation']) && isset($turn[(int) $exif['Orientation']])) {
				$src = imagerotate($src, $turn[(int) $exif['Orientation']], 0) ?: $src;
				$w = imagesx($src);
				$h = imagesy($src);
			}
		}
		// The middle of the picture, cut to the shape (1:1 or 3:1), no bigger than it needs to be.
		$ratio = $rules['out'][0] / $rules['out'][1];
		$cw = min($w, (int) round($h * $ratio));
		$ch = (int) round($cw / $ratio);
		$ow = min($rules['out'][0], $cw);
		$oh = (int) round($ow / $ratio);
		$dst = imagecreatetruecolor($ow, $oh);
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
		imagecopyresampled($dst, $src, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), $ow, $oh, $cw, $ch);
		imagedestroy($src);
		ob_start();
		$webp = function_exists('imagewebp');
		$webp ? imagewebp($dst, null, 82) : imagepng($dst, null, 8);
		$bytes = (string) ob_get_clean();
		imagedestroy($dst);
		if ($bytes === '') {
			return new WP_Error('y4', sprintf(__('The %s could not be saved.', 'y4apk'), $rules['label']));
		}
		$key = 'members/' . (int) $user->ID . '/' . ($kind === 'banner' ? 'banner' : 'picture') . '-' . strtolower(wp_generate_password(10, false, false)) . ($webp ? '.webp' : '.png');
		$put = Y4APK_R2::put($key, $bytes, $webp ? 'image/webp' : 'image/png');
		if (is_wp_error($put)) {
			return new WP_Error('y4', sprintf(__('The %s could not be stored. Try again later.', 'y4apk'), $rules['label']));
		}
		self::drop_image($user->ID, $kind); // the one it replaces
		update_user_meta($user->ID, $rules['meta'], ['key' => $key, 'url' => Y4APK_R2::public_url($key), 'at' => time()]);
		return true;
	}

	/** Removes the member's picture ('pic') or banner, from R2 too. */
	public static function drop_image($user_id, $kind) {
		$meta = self::image_rules($kind)['meta'];
		$v = get_user_meta($user_id, $meta, true);
		if (is_array($v) && !empty($v['key']) && strpos($v['key'], 'members/' . (int) $user_id . '/') === 0 && class_exists('Y4APK_R2') && Y4APK_R2::can_store()) {
			Y4APK_R2::remove($v['key']);
		}
		delete_user_meta($user_id, $meta);
	}

	public static function drop_images($user_id) {
		self::drop_image($user_id, 'pic');
		self::drop_image($user_id, 'banner');
	}

	/** When the member was last seen (a Unix time; 0: never). */
	public static function seen_at($user) {
		return (int) get_user_meta($user->ID, self::SEEN, true);
	}

	public static function is_online($user) {
		$t = self::seen_at($user);
		return $t && time() - $t < self::ONLINE;
	}

	/** "Online now", "Offline · Active 3h ago", or "Offline" (never seen). */
	public static function seen_text($user) {
		$t = self::seen_at($user);
		if (self::is_online($user)) {
			return __('Online now', 'y4apk');
		}
		return $t ? sprintf(__('Offline · Active %s', 'y4apk'), Y4APK_Roblox::ago($t)) : __('Offline', 'y4apk');
	}

	public static function seen() {
		if (!is_user_logged_in() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
			return;
		}
		self::touch(wp_get_current_user());
	}

	/** At most once a minute: enough for a 3-minute window, few writes. */
	private static function touch($user) {
		if (Y4APK_Members::is_member($user) && time() - self::seen_at($user) >= MINUTE_IN_SECONDS) {
			update_user_meta($user->ID, self::SEEN, time());
		}
	}

	/** The signed-in member's open tab says it is still there. */
	public static function ping() {
		self::touch(wp_get_current_user());
		wp_send_json_success();
	}

	/** On every page a signed-in member has open (pages for signed-in people are never cached). */
	public static function ping_script() {
		if (!is_user_logged_in() || !Y4APK_Members::is_member(wp_get_current_user())) {
			return;
		}
		?>
<script id="y4apk-pwa-ping" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, last = Date.now();
	function ping() {
		if (document.visibilityState !== 'visible') { return; }
		last = Date.now();
		var b = new FormData(); b.append('action', 'y4apk_ping');
		fetch(url, {method: 'POST', body: b, credentials: 'same-origin', keepalive: true}).catch(function () {});
	}
	setInterval(ping, 90000);
	document.addEventListener('visibilitychange', function () { if (Date.now() - last > 60000) { ping(); } });
})();
</script>
		<?php
	}

	/** Reads the Edit profile form and saves what is right: a list of mistakes (empty when all saved). */
	public static function save($user, $post, $files = []) {
		$err = [];
		foreach (['pic' => 'picture', 'banner' => 'banner'] as $kind => $field) {
			if (!empty($post['remove_' . $field])) {
				self::drop_image($user->ID, $kind);
			} elseif (!empty($files[$field]) && (int) $files[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
				$r = self::store_image($user, $files[$field], $kind);
				if (is_wp_error($r)) {
					$err[] = $r->get_error_message();
				}
			}
		}
		$name = trim(preg_replace('/\s+/u', ' ', sanitize_text_field(wp_unslash(isset($post['display']) ? $post['display'] : ''))));
		$bio = trim(sanitize_textarea_field(wp_unslash(isset($post['bio']) ? $post['bio'] : '')));
		$theme = sanitize_key(isset($post['theme']) ? wp_unslash($post['theme']) : '');
		$yt = trim(wp_unslash(isset($post['youtube']) ? (string) $post['youtube'] : ''));
		$dc = trim(wp_unslash(isset($post['discord']) ? (string) $post['discord'] : ''));
		$rbx = trim(wp_unslash(isset($post['roblox']) ? (string) $post['roblox'] : ''));

		if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 30)) {
			$err[] = __('A display name has 2–30 characters.', 'y4apk');
		} elseif ($name !== '' && preg_match('/y4\s*apk|admin|moderator|official|staff/i', $name)) {
			$err[] = __('That display name looks like the site’s own. Pick another.', 'y4apk');
		} else {
			update_user_meta($user->ID, self::NAME, $name);
		}

		$bio = preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $bio));
		if (mb_strlen($bio) > 200) {
			$err[] = __('The bio has up to 200 characters.', 'y4apk');
		} else {
			update_user_meta($user->ID, self::BIO, $bio);
		}

		if (isset(self::themes()[$theme])) {
			update_user_meta($user->ID, self::THEME, $theme);
		}

		$links = self::links($user);
		if ($yt === '') {
			$links['youtube'] = '';
		} elseif (preg_match('~^(?:https?://)?(?:www\.|m\.)?youtube\.com/(@[\w.-]{3,30}|channel/UC[\w-]{20,24}|c/[\w.-]{2,60})/?$~i', $yt, $m)) {
			$links['youtube'] = 'https://www.youtube.com/' . $m[1];
		} else {
			$err[] = __('The YouTube link should look like youtube.com/@yourchannel.', 'y4apk');
		}
		if ($dc === '') {
			$links['discord'] = '';
		} elseif (preg_match('~^(?:https?://)?(?:www\.)?(?:discord\.gg|discord(?:app)?\.com/invite)/([\w-]{2,32})/?$~i', $dc, $m)) {
			$links['discord'] = 'https://discord.gg/' . $m[1];
		} else {
			$err[] = __('The Discord link should look like discord.gg/yourserver.', 'y4apk');
		}
		update_user_meta($user->ID, self::LINKS, $links);

		$r = self::roblox($user);
		if ($rbx === '') {
			delete_user_meta($user->ID, self::RBX);
		} elseif (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $rbx)) {
			$err[] = __('A Roblox username has 3–20 letters, numbers or underscores.', 'y4apk');
		} elseif (strtolower($rbx) !== strtolower($r['name']) || $r['img'] === '') {
			if (!Y4APK_Members::hit('rbx_' . $user->ID, 10, HOUR_IN_SECONDS)) {
				$err[] = __('You changed the Roblox name many times. Try again in an hour.', 'y4apk');
			} else {
				$found = self::lookup($rbx);
				if (is_wp_error($found)) {
					$err[] = $found->get_error_message();
				} else {
					update_user_meta($user->ID, self::RBX, $found);
				}
			}
		}
		return $err;
	}

	/* ---------------------------------------------------------------- *
	 * Roblox avatars
	 * ---------------------------------------------------------------- */

	/** A Roblox user by username: ['id', 'name', 'img', 'at'] or a WP_Error. */
	public static function lookup($username) {
		$r = wp_remote_post('https://users.roblox.com/v1/usernames/users', ['timeout' => 10, 'user-agent' => self::UA,
			'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => wp_json_encode(['usernames' => [$username], 'excludeBannedUsers' => true])]);
		$j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
		if (!is_array($j)) {
			return new WP_Error('y4', __('Roblox did not answer. Try again later.', 'y4apk'));
		}
		if (empty($j['data'][0]['id'])) {
			return new WP_Error('y4', __('We could not find that Roblox user.', 'y4apk'));
		}
		$id = (string) (int) $j['data'][0]['id'];
		$imgs = self::headshots([$id]);
		return ['id' => $id, 'name' => (string) $j['data'][0]['name'], 'img' => isset($imgs[$id]) ? $imgs[$id] : '', 'at' => time()];
	}

	/** [user ID => headshot URL] for up to 100 Roblox users. */
	private static function headshots($ids) {
		$r = wp_remote_get('https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds=' . implode(',', array_map('rawurlencode', $ids)) . '&size=150x150&format=Webp&isCircular=false',
			['timeout' => 10, 'user-agent' => self::UA, 'headers' => ['Accept' => 'application/json']]);
		$j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
		$out = [];
		foreach (is_array($j) && isset($j['data']) ? (array) $j['data'] : [] as $row) {
			if (isset($row['targetId'], $row['imageUrl'], $row['state']) && $row['state'] === 'Completed' && Y4APK_Roblox::cdn_url($row['imageUrl'])) {
				$out[(string) $row['targetId']] = (string) $row['imageUrl'];
			}
		}
		return $out;
	}

	/** Daily: the avatars' addresses on Roblox's CDN expire, so ask for them again. */
	public static function refresh_avatars() {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::RBX));
		$by = [];
		foreach ($rows as $row) {
			$v = maybe_unserialize($row->meta_value);
			if (is_array($v) && !empty($v['id'])) {
				$by[(string) $v['id']][] = (int) $row->user_id;
			}
		}
		foreach (array_chunk(array_keys($by), 100) as $chunk) {
			foreach (self::headshots($chunk) as $rid => $url) {
				foreach ($by[$rid] as $uid) {
					$u = get_userdata($uid);
					if (!$u) {
						continue;
					}
					$v = self::roblox($u);
					$v['img'] = $url;
					$v['at'] = time();
					update_user_meta($uid, self::RBX, $v);
				}
			}
		}
	}
}

Y4APK_Social::init();
