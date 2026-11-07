<?php
/**
 * App icons on Cloudflare R2 (2.50), not on this server.
 *
 * The icon Google Play shows for an app becomes the post's featured image as
 * an attachment whose picture lives on R2 (files.y4apk.net/i/…), in two sizes
 * (512 and 192 pixels, WebP) straight from Google's own resizer. WordPress —
 * the theme, Rank Math, the editor, the media library — sees an ordinary image:
 * its address and sizes come from the filters below. Nothing goes into
 * wp-content/uploads, and an icon that is replaced is deleted from R2 with its
 * attachment.
 *
 * Y4APK_App_Updates keeps each post's icon the same as Google Play's in the
 * background; Write with AI makes its icons here too.
 */

defined('ABSPATH') || exit;

class Y4APK_Icons {

	const META  = '_y4apk_r2_image'; // on the attachment: ['urls' => [width => url], 'keys' => [R2 keys], 'src' => the Google Play address]
	const SIZES = [512, 192];

	public static function init() {
		add_filter('wp_get_attachment_url', [__CLASS__, 'url'], 20, 2);
		add_filter('image_downsize', [__CLASS__, 'downsize'], 5, 3);
		add_action('delete_attachment', [__CLASS__, 'deleted']);
	}

	/** Can icons go to R2? (keys, bucket and its public address in App updates ▸ Settings) */
	public static function ready() {
		return class_exists('Y4APK_R2') && Y4APK_R2::can_store();
	}

	/** An attachment kept on R2: its data, or null. */
	public static function data($id) {
		$d = $id ? get_post_meta((int) $id, self::META, true) : null;
		return is_array($d) && !empty($d['urls']) && is_array($d['urls']) ? $d : null;
	}

	public static function url($url, $id) {
		$d = self::data($id);
		return $d ? (string) $d['urls'][max(array_keys($d['urls']))] : $url;
	}

	/** The smallest stored size at least as big as the one asked for (the biggest when none is). */
	public static function downsize($out, $id, $size) {
		$d = self::data($id);
		if (!$d) {
			return $out;
		}
		$urls = $d['urls'];
		ksort($urls);
		$max = max(array_keys($urls));
		$want = $max;
		if (is_array($size)) {
			$want = max((int) ($size[0] ?? 0), (int) ($size[1] ?? 0)) ?: $max;
		} elseif ($size !== 'full') {
			$subs = wp_get_registered_image_subsizes();
			if (isset($subs[$size])) {
				$want = max((int) $subs[$size]['width'], (int) $subs[$size]['height']) ?: $max;
			}
		}
		foreach ($urls as $w => $u) {
			if ((int) $w >= $want) {
				return [$u, (int) $w, (int) $w, (int) $w < $max];
			}
		}
		return [$urls[$max], (int) $max, (int) $max, false];
	}

	public static function deleted($id) {
		$d = self::data($id);
		if ($d && self::ready()) {
			foreach ((array) ($d['keys'] ?? []) as $key) {
				Y4APK_R2::remove($key);
			}
		}
	}

	/**
	 * A Google Play icon as a new attachment on R2: its ID or a WP_Error.
	 * $src is the icon's address without size ("https://play-lh.googleusercontent.com/…").
	 */
	public static function make($post_id, $src, $title, $alt = '') {
		if (!self::ready()) {
			return new WP_Error('y4apk_icon', __('R2 is not set up', 'y4apk'));
		}
		$src = preg_replace('/=[a-z0-9-]+$/i', '', (string) $src); // the size Google added, if any
		if (!preg_match('~^https://[a-z0-9.-]+\.googleusercontent\.com/~i', $src)) {
			return new WP_Error('y4apk_icon', __('not a Google Play picture', 'y4apk'));
		}
		$base = 'i/' . (Y4APK_R2::slug($title) ?: 'icon') . '-' . (int) $post_id . '-' . substr(md5($src), 0, 10);
		$types = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
		$urls = [];
		$keys = [];
		$mime = '';
		foreach (self::SIZES as $w) {
			$r = wp_remote_get($src . '=s' . $w . '-rw', ['timeout' => 20, 'user-agent' => Y4APK_App_Updates::UA]);
			$body = is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200 ? '' : (string) wp_remote_retrieve_body($r);
			$info = $body !== '' ? @getimagesizefromstring($body) : false;
			if (!$info || !isset($types[$info['mime']])) {
				self::drop_keys($keys);
				return new WP_Error('y4apk_icon', __('could not download it', 'y4apk'));
			}
			$key = $base . '-' . $w . '.' . $types[$info['mime']];
			$ok = Y4APK_R2::put($key, $body, $info['mime']);
			if (is_wp_error($ok)) {
				self::drop_keys($keys);
				return $ok;
			}
			$keys[] = $key;
			$urls[(int) $info[0] ?: $w] = Y4APK_R2::public_url($key);
			$mime = $mime ?: $info['mime'];
		}
		ksort($urls);
		$big = max(array_keys($urls));
		$small = min(array_keys($urls));
		$att = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => wp_slash($title), 'post_excerpt' => wp_slash($alt), 'post_content' => wp_slash($alt),
			'post_status' => 'inherit', 'guid' => $urls[$big]], false, (int) $post_id, true);
		if (is_wp_error($att)) {
			self::drop_keys($keys);
			return $att;
		}
		// The small one as its "file": the search box's suggestions (suggest.php) show it as it is.
		update_post_meta($att, '_wp_attached_file', $urls[$small]);
		update_post_meta($att, '_wp_attachment_metadata', ['width' => $big, 'height' => $big, 'file' => $urls[$small], 'sizes' => [], 'image_meta' => []]);
		update_post_meta($att, self::META, ['urls' => $urls, 'keys' => $keys, 'src' => $src]);
		if ($alt !== '') {
			update_post_meta($att, '_wp_attachment_image_alt', wp_slash($alt));
		}
		return (int) $att;
	}

	private static function drop_keys($keys) {
		foreach ($keys as $key) {
			Y4APK_R2::remove($key);
		}
	}

	/** Is this attachment a square picture (an app icon), from its sizes? */
	public static function square($id) {
		if (self::data($id)) {
			return true;
		}
		$m = wp_get_attachment_metadata($id);
		$w = is_array($m) ? (int) ($m['width'] ?? 0) : 0;
		$h = is_array($m) ? (int) ($m['height'] ?? 0) : 0;
		return $w > 0 && $h > 0 && abs($w - $h) <= max(2, 0.03 * max($w, $h));
	}

	/**
	 * Is an attachment used anywhere but as the featured image of $except
	 * (another post's featured image, a Roblox game, the site's icon or logo, an
	 * article's picture)?
	 */
	public static function used_elsewhere($id, $except = 0) {
		global $wpdb;
		$id = (int) $id;
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id <> %d", (string) $id, (int) $except))) {
			return true;
		}
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value = %s", (string) $id))) {
			return true;
		}
		if ((int) get_option('site_icon') === $id || (int) get_theme_mod('custom_logo') === $id) {
			return true;
		}
		if ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status <> 'inherit' AND post_type NOT IN ('revision', 'attachment') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
			'%wp-image-' . $id . '"%', '%wp-image-' . $id . ' %'))) {
			return true;
		}
		// An article showing the picture by its address (any of its sizes: "name.png", "name-150x150.png")
		$file = (string) get_post_meta($id, '_wp_attached_file', true);
		$stem = $file !== '' && !preg_match('~^https?://~', $file) ? pathinfo($file, PATHINFO_FILENAME) : '';
		if ($stem === '') {
			return false;
		}
		$like = '%/' . $wpdb->esc_like($stem);
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status <> 'inherit' AND post_type NOT IN ('revision', 'attachment') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
			$like . '.%', $like . '-%'));
	}
}

Y4APK_Icons::init();
