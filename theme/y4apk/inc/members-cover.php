<?php
/**
 * A post's own cover (2.55): a member may add a wide picture (16:9) when they
 * share a script, a game or an app. The cards (search, profiles, the Roblox
 * pages, their uploads) show it; without one they show a screenshot of the
 * game or its icon, as before.
 *
 * It is checked and made 1280 × 720 like the members' pictures (an animated
 * GIF stays animated), kept on R2 next to them (members/<id>/cover-…), taken
 * off R2 when it is replaced, removed or its post is deleted, and listed on
 * Y4APK ▸ Pictures to be looked at.
 */

defined('ABSPATH') || exit;

class Y4APK_Cover {

	const META = '_y4apk_cover';     // ['key', 'url', 'at']
	const NEW  = '_y4apk_cover_new'; // when it came: on Y4APK ▸ Pictures, to look at

	public static function init() {
		add_action('before_delete_post', [__CLASS__, 'gone']);
	}

	public static function url($post_id) {
		$c = get_post_meta((int) $post_id, self::META, true);
		return is_array($c) && !empty($c['url']) ? (string) $c['url'] : '';
	}

	/** The cover sent with the form: checked and made its size (['bytes', 'ext', 'type']), a WP_Error to show, or null when none was sent. */
	public static function take($user) {
		$f = $_FILES['cover'] ?? null;
		if (!is_array($f) || !isset($f['error']) || (int) $f['error'] === UPLOAD_ERR_NO_FILE || (string) ($f['name'] ?? '') === '') {
			return null;
		}
		if (!class_exists('Y4APK_Social')) {
			return null;
		}
		return Y4APK_Social::shape_image($user, $f, 'cover');
	}

	/** Once the post is saved: the new cover kept (in place of the one before), or the old one removed when asked. */
	public static function save($post_id, $user, $img, $remove = false) {
		if (is_array($img)) {
			if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
				return false;
			}
			$key = 'members/' . (int) $user->ID . '/cover-' . (int) $post_id . '-' . strtolower(wp_generate_password(8, false, false)) . '.' . $img['ext'];
			if (is_wp_error(Y4APK_R2::put($key, $img['bytes'], $img['type']))) {
				return false;
			}
			self::drop($post_id);
			update_post_meta($post_id, self::META, ['key' => $key, 'url' => Y4APK_R2::public_url($key), 'at' => time()]);
			update_post_meta($post_id, self::NEW, (string) time());
			return true;
		}
		if ($remove) {
			self::drop($post_id);
		}
		return true;
	}

	public static function drop($post_id) {
		$c = get_post_meta((int) $post_id, self::META, true);
		if (is_array($c) && !empty($c['key']) && strpos($c['key'], 'members/') === 0 && class_exists('Y4APK_R2') && Y4APK_R2::can_store()) {
			Y4APK_R2::remove($c['key']);
		}
		delete_post_meta((int) $post_id, self::META);
		delete_post_meta((int) $post_id, self::NEW);
	}

	public static function gone($post_id) {
		if (get_post_meta((int) $post_id, self::META, true)) {
			self::drop($post_id);
		}
	}

	/** The field in the share and script forms (the form needs enctype="multipart/form-data"). */
	public static function field($post_id = 0) {
		$now = $post_id ? self::url($post_id) : '';
		$max = class_exists('Y4APK_Social') ? Y4APK_Social::upload_max() : wp_max_upload_size();
		$max = min($max, wp_max_upload_size()); // it comes through this server
		?>
		<div class="acc-field y4c" data-cover data-max="<?php echo (int) $max; ?>">
			<label for="f-cover"><?php esc_html_e('Cover', 'y4apk'); ?> <small><?php esc_html_e('(optional)', 'y4apk'); ?></small></label>
			<label class="y4c-box" for="f-cover">
				<img src="<?php echo esc_url($now); ?>" alt="" data-cover-img<?php echo $now === '' ? ' hidden' : ''; ?>>
				<span class="y4c-empty" data-cover-empty<?php echo $now !== '' ? ' hidden' : ''; ?>>
					<svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true"><path d="M19 5v14H5V5h14Zm0-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-4.9 8.6-3 3.9L9 13l-3 4h12l-3.9-5.4Z"/></svg>
					<b><?php esc_html_e('Add a cover', 'y4apk'); ?></b>
					<small><?php echo esc_html(sprintf(__('Wide (16:9), at least 480 × 270 · PNG, JPG, WebP or GIF · up to %s', 'y4apk'), size_format($max))); ?></small>
				</span>
			</label>
			<input id="f-cover" class="y4c-in" type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/gif" data-cover-in>
			<div class="y4c-acts">
				<label class="acc-btn acc-btn-ghost y4c-pick" for="f-cover"><?php echo esc_html($now !== '' ? __('Change', 'y4apk') : __('Choose a picture', 'y4apk')); ?></label>
				<label class="y4c-rm" data-cover-rm-wrap<?php echo $now === '' ? ' hidden' : ''; ?>><input type="checkbox" name="cover_remove" value="1" data-cover-rm> <?php esc_html_e('Remove the cover', 'y4apk'); ?></label>
			</div>
			<p class="acc-hint" data-cover-hint><?php esc_html_e('Shown on the post’s card. Without one, a screenshot of the game (or its icon) is used.', 'y4apk'); ?></p>
		</div>
		<script id="y4apk-pwa-cover" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
		(function () {
			var f = document.querySelector('[data-cover]');
			if (!f) { return; }
			var input = f.querySelector('[data-cover-in]'), img = f.querySelector('[data-cover-img]'), empty = f.querySelector('[data-cover-empty]'), rm = f.querySelector('[data-cover-rm]'), rmw = f.querySelector('[data-cover-rm-wrap]'), hint = f.querySelector('[data-cover-hint]');
			var was = img.getAttribute('src') || '', base = hint.textContent, max = +f.getAttribute('data-max') || 0, url = '';
			var show = function (src) { img.hidden = !src; if (src) { img.src = src; } empty.hidden = !!src; };
			input.addEventListener('change', function () {
				var file = input.files && input.files[0];
				if (url) { URL.revokeObjectURL(url); url = ''; }
				hint.textContent = base; hint.classList.remove('is-bad');
				if (!file) { show(rm.checked ? '' : was); return; }
				if (!/^image\/(png|jpe?g|webp|gif)$/.test(file.type) || (max && file.size > max)) {
					hint.textContent = <?php echo wp_json_encode(__('Use a PNG, JPG, WebP or GIF picture, no bigger than the size above.', 'y4apk')); ?>; hint.classList.add('is-bad');
					input.value = ''; show(rm.checked ? '' : was); return;
				}
				url = URL.createObjectURL(file); show(url); rm.checked = false; rmw.hidden = false;
			});
			rm.addEventListener('change', function () { if (rm.checked) { input.value = ''; show(''); } else { show(was); } });
		})();
		</script>
		<?php
	}
}

Y4APK_Cover::init();
