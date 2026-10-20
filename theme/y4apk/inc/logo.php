<?php
/**
 * Y4APK ▸ Site Logo — set the header logo without the Customizer.
 *
 * The logo lives in the `logo_img` theme mod, which is normally edited through
 * Customizer ▸ Y4APK Panel ▸ Header. That panel needs Kirki, and the Customizer
 * itself is easily broken by an unrelated plugin (JS optimisers and asset
 * minifiers are the usual culprits), which leaves no way to change the logo at
 * all. This page writes the same theme mod from plain admin screens.
 *
 * Three ways in, so at least one always works: upload a file, pick from the
 * media library, or paste a URL. Only the media library button needs JavaScript.
 */

if (!defined('ABSPATH')) exit;

/**
 * Admin, cron and WP-CLI only: nothing here affects how the site renders, and
 * the site is the hot path.
 */
if (!is_admin() && !wp_doing_cron() && !defined('WP_CLI')) {
	return;
}

if (!class_exists('Y4APK_Logo_Page')) {

class Y4APK_Logo_Page {

	const SLUG = 'y4apk-logo';

	/** The Customizer setting this page writes. */
	const MOD = 'logo_img';

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'register_page'], 100);
		add_action('admin_post_y4apk_save_logo', [__CLASS__, 'handle_save']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
	}

	public static function default_logo() {
		return get_template_directory_uri() . '/assets/img/y4apk.svg';
	}

	public static function current_logo() {
		return get_theme_mod(self::MOD, self::default_logo());
	}

	public static function register_page() {
		add_submenu_page(
			'y4apk-gplay',
			__('Site Logo', 'y4apk'),
			__('Site Logo', 'y4apk'),
			'edit_theme_options',
			self::SLUG,
			[__CLASS__, 'render']
		);
	}

	public static function enqueue($hook) {
		if (isset($_GET['page']) && $_GET['page'] === self::SLUG) {
			wp_enqueue_media();
		}
	}

	/* ---------------------------------------------------------------- */

	public static function handle_save() {
		if (!current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-save-logo');

		$status = 'saved';

		if (isset($_POST['reset'])) {
			remove_theme_mod(self::MOD);
			$status = 'reset';
		} elseif (!empty($_FILES['logo_file']['name'])) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = media_handle_upload('logo_file', 0);

			if (is_wp_error($attachment_id)) {
				$status = 'upload-failed';
			} else {
				set_theme_mod(self::MOD, wp_get_attachment_url($attachment_id));
			}
		} else {
			$url = isset($_POST['logo_url']) ? esc_url_raw(trim(wp_unslash($_POST['logo_url']))) : '';

			if ($url === '') {
				remove_theme_mod(self::MOD);
				$status = 'reset';
			} else {
				set_theme_mod(self::MOD, $url);
			}
		}

		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&status=' . $status));
		exit;
	}

	/* ---------------------------------------------------------------- */

	public static function render() {
		$logo = self::current_logo();
		$is_default = !get_theme_mod(self::MOD);
		$status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Site Logo', 'y4apk'); ?></h1>

			<?php if ($status === 'saved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Logo updated.', 'y4apk'); ?></p></div>
			<?php elseif ($status === 'reset') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Logo reset to the bundled Y4APK mark.', 'y4apk'); ?></p></div>
			<?php elseif ($status === 'upload-failed') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('That file could not be uploaded. Check the file type and your upload size limit.', 'y4apk'); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e('The logo shown in the site header. This is the same setting as Customizer ▸ Y4APK Panel ▸ Header ▸ Site Logo — changing it in either place changes both.', 'y4apk'); ?></p>

			<h2><?php esc_html_e('Current logo', 'y4apk'); ?></h2>
			<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;display:inline-block;min-width:220px">
				<img src="<?php echo esc_url($logo); ?>" alt="" style="max-width:288px;max-height:96px;display:block">
			</div>
			<p>
				<code style="word-break:break-all"><?php echo esc_html($logo); ?></code>
				<?php if ($is_default) : ?><br><em><?php esc_html_e('(bundled default)', 'y4apk'); ?></em><?php endif; ?>
			</p>

			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="y4apk_save_logo" />
				<?php wp_nonce_field('y4apk-save-logo'); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="y4apk-logo-file"><?php esc_html_e('Upload a file', 'y4apk'); ?></label></th>
							<td>
								<input type="file" id="y4apk-logo-file" name="logo_file" accept="image/*,.svg" />
								<p class="description"><?php esc_html_e('PNG, SVG or WebP. Around 144 × 48 pixels works best.', 'y4apk'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="y4apk-logo-url"><?php esc_html_e('Or use an image URL', 'y4apk'); ?></label></th>
							<td>
								<input type="url" id="y4apk-logo-url" name="logo_url" class="large-text code"
									value="<?php echo esc_attr($is_default ? '' : $logo); ?>"
									placeholder="<?php echo esc_attr(self::default_logo()); ?>" />
								<p>
									<button type="button" class="button" id="y4apk-pick-logo"><?php esc_html_e('Choose from Media Library', 'y4apk'); ?></button>
								</p>
								<p class="description"><?php esc_html_e('Leave both empty to fall back to the bundled Y4APK logo.', 'y4apk'); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e('Save logo', 'y4apk'); ?></button>
					<button type="submit" name="reset" value="1" class="button"><?php esc_html_e('Reset to default', 'y4apk'); ?></button>
				</p>
			</form>

			<?php if (!class_exists('Kirki')) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e('The Kirki plugin is not active, so the Customizer ▸ Y4APK Panel is unavailable. This page still works; activate Kirki to get the rest of the theme options back.', 'y4apk'); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<script>
		jQuery(function ($) {
			$('#y4apk-pick-logo').on('click', function (e) {
				e.preventDefault();
				if (!window.wp || !wp.media) { return; }
				var frame = wp.media({
					title: <?php echo wp_json_encode(__('Select a logo', 'y4apk')); ?>,
					library: { type: 'image' },
					button: { text: <?php echo wp_json_encode(__('Use this image', 'y4apk')); ?> },
					multiple: false
				});
				frame.on('select', function () {
					var img = frame.state().get('selection').first().toJSON();
					$('#y4apk-logo-url').val(img.url);
				});
				frame.open();
			});
		});
		</script>
		<?php
	}
}

Y4APK_Logo_Page::init();

}
