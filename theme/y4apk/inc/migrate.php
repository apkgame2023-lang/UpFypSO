<?php
/**
 * Carry Customizer settings across a theme folder rename.
 *
 * WordPress stores Customizer values in the option `theme_mods_<folder-name>`,
 * so installing this theme into `themes/y4apk` when the site was running from
 * `themes/modw` leaves every setting behind: no logo, no header menus, empty
 * Customizer panel. This finds the old row and copies it across, once.
 *
 * Nothing is overwritten — a value already set for the current folder always
 * wins — and the old row is left in place, so re-activating the old folder
 * still works.
 */

if (!defined('ABSPATH')) exit;

/**
 * Admin, cron and WP-CLI only: nothing here affects how the site renders, and
 * the site is the hot path.
 */
if (!is_admin() && !wp_doing_cron() && !defined('WP_CLI')) {
	return;
}

if (!class_exists('Y4APK_Settings_Migration')) {

class Y4APK_Settings_Migration {

	/** Set once the automatic pass has run, so it never runs twice. */
	const FLAG = 'y4apk_mods_migrated';

	/** Settings that only this theme registers — used to recognise its data. */
	const MARKERS = ['logo_img', 'menu_select_1', 'menu_select_2', 'hero_btn_url', 'hero_title'];

	public static function init() {
		add_action('admin_init', [__CLASS__, 'maybe_migrate']);
		add_action('admin_post_y4apk_migrate_mods', [__CLASS__, 'handle_manual_migrate']);
		add_action('admin_notices', [__CLASS__, 'notice']);
		add_action('y4apk_tools_page', [__CLASS__, 'render_card']);
	}

	/* ---------------------------------------------------------------- */

	/**
	 * Every other theme_mods_* row that looks like it belongs to this theme.
	 *
	 * @return array slug => number of settings stored
	 */
	public static function find_sources() {
		global $wpdb;

		$current = get_stylesheet();
		$found = [];

		$rows = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'theme\\_mods\\_%'"
		);

		foreach ((array) $rows as $row) {
			$slug = substr($row->option_name, strlen('theme_mods_'));

			if ($slug === $current) {
				continue;
			}

			$mods = get_option($row->option_name);
			if (!is_array($mods) || !array_intersect(self::MARKERS, array_keys($mods))) {
				continue;
			}

			$found[$slug] = count($mods);
		}

		arsort($found);

		return $found;
	}

	/** True when the current theme has no Customizer data worth keeping. */
	public static function current_is_empty() {
		$mods = get_option('theme_mods_' . get_stylesheet());

		if (!is_array($mods)) {
			return true;
		}

		return !array_intersect(self::MARKERS, array_keys($mods));
	}

	/**
	 * Copy settings from another theme folder into this one.
	 *
	 * @return int number of settings brought across
	 */
	public static function migrate_from($slug) {
		$legacy = get_option('theme_mods_' . $slug);

		if (!is_array($legacy) || !$legacy) {
			return 0;
		}

		$current = get_option('theme_mods_' . get_stylesheet());
		$current = is_array($current) ? $current : [];

		$copied = 0;

		foreach ($legacy as $key => $value) {
			// Never clobber something already configured for this folder.
			if (isset($current[$key]) && $current[$key] !== '' && $current[$key] !== []) {
				continue;
			}

			$current[$key] = self::repoint($value, $slug);
			$copied++;
		}

		update_option('theme_mods_' . get_stylesheet(), $current);

		return $copied;
	}

	/**
	 * Rewrite URLs that pointed into the old theme folder — a saved logo path
	 * such as .../themes/modw/assets/img/modw.svg would 404 otherwise.
	 */
	private static function repoint($value, $old_slug) {
		if (is_array($value)) {
			return array_map(function ($item) use ($old_slug) {
				return self::repoint($item, $old_slug);
			}, $value);
		}

		if (!is_string($value) || strpos($value, '/themes/' . $old_slug . '/') === false) {
			return $value;
		}

		$value = str_replace('/themes/' . $old_slug . '/', '/themes/' . get_stylesheet() . '/', $value);

		// The bundled logo was renamed along with the theme.
		return str_replace('/assets/img/modw.svg', '/assets/img/y4apk.svg', $value);
	}

	/* ---------------------------------------------------------------- */

	/** Automatic one-shot pass, on the first admin page load. */
	public static function maybe_migrate() {
		if (get_option(self::FLAG)) {
			return;
		}

		// Only step in when there is nothing to lose.
		if (!self::current_is_empty()) {
			update_option(self::FLAG, 'not-needed', false);
			return;
		}

		$sources = self::find_sources();

		if (!$sources) {
			update_option(self::FLAG, 'no-source', false);
			return;
		}

		// The richest row is the one the site was actually using.
		$slug = key($sources);
		$copied = self::migrate_from($slug);

		update_option(self::FLAG, $copied ? $slug : 'no-source', false);

		if ($copied) {
			set_transient('y4apk_mods_migrated_notice', ['slug' => $slug, 'count' => $copied], 5 * MINUTE_IN_SECONDS);
		}
	}

	public static function handle_manual_migrate() {
		if (!current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-migrate-mods');

		$slug = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
		$copied = $slug ? self::migrate_from($slug) : 0;

		update_option(self::FLAG, $copied ? $slug : get_option(self::FLAG), false);

		if ($copied) {
			set_transient('y4apk_mods_migrated_notice', ['slug' => $slug, 'count' => $copied], 5 * MINUTE_IN_SECONDS);
		}

		wp_safe_redirect(admin_url('themes.php?page=y4apk-updates&imported=' . (int) $copied));
		exit;
	}

	public static function notice() {
		$data = get_transient('y4apk_mods_migrated_notice');

		if (!$data || !current_user_can('edit_theme_options')) {
			return;
		}

		delete_transient('y4apk_mods_migrated_notice');
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong>Y4APK:</strong>
				<?php
				printf(
					/* translators: 1: number of settings, 2: old theme folder name */
					esc_html__('Imported %1$d Customizer settings from the old theme folder (%2$s). Menus, logo and layout options are back.', 'y4apk'),
					(int) $data['count'],
					'<code>' . esc_html($data['slug']) . '</code>'
				);
				?>
				<a href="<?php echo esc_url(admin_url('customize.php')); ?>"><?php esc_html_e('Open the Customizer', 'y4apk'); ?></a>
			</p>
		</div>
		<?php
	}

	/** Rendered inside Appearance ▸ Y4APK Updates. */
	public static function render_card() {
		$sources = self::find_sources();
		$mods = get_option('theme_mods_' . get_stylesheet());
		$count = is_array($mods) ? count($mods) : 0;
		?>
		<h2><?php esc_html_e('Customizer settings', 'y4apk'); ?></h2>

		<?php if (isset($_GET['imported'])) : ?>
			<div class="notice notice-<?php echo (int) $_GET['imported'] ? 'success' : 'warning'; ?> inline">
				<p>
					<?php
					$n = (int) $_GET['imported'];
					echo $n
						? esc_html(sprintf(__('Imported %d settings.', 'y4apk'), $n))
						: esc_html__('Nothing new to import — those settings are already in place.', 'y4apk');
					?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: 1: theme folder name, 2: number of stored settings */
				esc_html__('This theme runs from %1$s and has %2$d stored settings.', 'y4apk'),
				'<code>' . esc_html(get_stylesheet()) . '</code>',
				$count
			);
			?>
		</p>

		<?php if ($sources) : ?>
			<p><?php esc_html_e('Settings from another theme folder were found. Importing fills in anything not already set here; nothing currently configured is overwritten.', 'y4apk'); ?></p>
			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
				<input type="hidden" name="action" value="y4apk_migrate_mods" />
				<?php wp_nonce_field('y4apk-migrate-mods'); ?>
				<select name="source">
					<?php foreach ($sources as $slug => $n) : ?>
						<option value="<?php echo esc_attr($slug); ?>">
							<?php echo esc_html(sprintf('%s (%d settings)', $slug, $n)); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e('Import settings', 'y4apk'); ?></button>
			</form>
		<?php else : ?>
			<p><em><?php esc_html_e('No other theme folder with Y4APK settings was found.', 'y4apk'); ?></em></p>
		<?php endif; ?>
		<?php
	}
}

Y4APK_Settings_Migration::init();

}
