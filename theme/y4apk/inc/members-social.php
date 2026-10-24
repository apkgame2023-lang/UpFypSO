<?php
/**
 * Y4APK Members ▸ the social part (2.39): profiles at /@name with a theme, a
 * display name, a bio, links and a Roblox avatar; members follow each other.
 *
 *   Following      one usermeta row per follow: y4apk_follows = the followed member's ID
 *   Profile        y4apk_name, y4apk_bio, y4apk_theme, y4apk_links, y4apk_rbx (usermeta)
 *   Roblox avatar  found by Roblox username; the picture stays on Roblox's CDN (no uploads
 *                  here), and its address, which expires, is renewed every day
 *   Last seen      y4apk_seen, at most once every five minutes while a member is signed in
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
	const HOOK    = 'y4apk_avatars';
	const UA      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

	public static function init() {
		add_action('wp_ajax_y4apk_follow', [__CLASS__, 'ajax_follow']);
		add_action('init', [__CLASS__, 'seen'], 20);
		add_action(self::HOOK, [__CLASS__, 'refresh_avatars']);
		add_action('init', [__CLASS__, 'schedule']);
		add_action('deleted_user', [__CLASS__, 'forget']);
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

	/** The member's picture: their Roblox avatar, else a tile with their first letter. */
	public static function avatar($user, $size = 56) {
		$r = self::roblox($user);
		if ($r['img'] !== '' && Y4APK_Roblox::cdn_url($r['img'])) {
			return '<img class="acc-av acc-av-img" src="' . esc_url($r['img']) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px">';
		}
		return Y4APK_Members::avatar($user->user_login, $size);
	}

	/** "Online now" (in the last five minutes), "Active 3h ago", or '' (never seen). */
	public static function seen_text($user) {
		$t = (int) get_user_meta($user->ID, self::SEEN, true);
		if (!$t) {
			return '';
		}
		return time() - $t < 5 * MINUTE_IN_SECONDS ? __('Online now', 'y4apk') : sprintf(__('Active %s', 'y4apk'), Y4APK_Roblox::ago($t));
	}

	public static function seen() {
		if (!is_user_logged_in() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
			return;
		}
		$user = wp_get_current_user();
		if (Y4APK_Members::is_member($user) && time() - (int) get_user_meta($user->ID, self::SEEN, true) > 5 * MINUTE_IN_SECONDS) {
			update_user_meta($user->ID, self::SEEN, time());
		}
	}

	/** Reads the Edit profile form and saves what is right: a list of mistakes (empty when all saved). */
	public static function save($user, $post) {
		$err = [];
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
