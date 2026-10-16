<?php
/**
 * Loader for the Google Play extractor and the Customizer panel.
 *
 * This used to sit behind a remote license check: the Customizer options and the
 * whole importer admin were only included when an APKTEMPLATES license key
 * validated against their server. Y4APK owns this build, so both are loaded
 * unconditionally and nothing is sent anywhere for validation.
 */

if (!defined('ABSPATH')) exit;

$theme_dir = get_template_directory();

require_once $theme_dir . '/admin/inc.php';

/**
 * Load the Customizer panel definitions — in the Customizer only.
 *
 * inc/customization.php is expensive to include: building the field choices
 * runs two `posts_per_page => -1` queries (every post on the site, twice) plus
 * three full taxonomy queries, and it registers ~66 Kirki fields.
 *
 * None of that is needed to render the site. The templates read values with
 * get_theme_mod() straight from the options table and carry their own inline
 * defaults, and this theme declares no Kirki `output` rules, so Kirki generates
 * no front-end CSS from these fields.
 *
 * So it is loaded on `customize_register` (which WordPress also fires for the
 * Customizer preview and for saving) and on `customize_preview_init`, both at
 * priority 0 so the fields exist before Kirki reads them — and never on a
 * normal page view.
 *
 * Loading this on every request is what took the site down: it put those
 * whole-table queries on every front-end hit, including bot traffic, until the
 * host throttled the database.
 */
if (!function_exists('y4apk_load_customizer_settings')) {
	function y4apk_load_customizer_settings() {
		static $loaded = false;

		if ($loaded || !class_exists('Kirki')) {
			return;
		}

		$loaded = true;
		require_once get_template_directory() . '/inc/customization.php';
	}
	add_action('customize_register', 'y4apk_load_customizer_settings', 0);
	add_action('customize_preview_init', 'y4apk_load_customizer_settings', 0);
}

/**
 * One time removal of the options the old license client left in the database.
 */
if (!function_exists('y4apk_purge_legacy_license_data')) {
	function y4apk_purge_legacy_license_data() {
		if (get_option('y4apk_license_purged')) {
			return;
		}

		global $wpdb;

		$legacy = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'MODW\\_lic\\_%'"
		);

		foreach ((array) $legacy as $option_name) {
			delete_option($option_name);
		}

		delete_transient('modw_up');
		delete_site_transient('update_themes');

		update_option('y4apk_license_purged', 1, false);
	}
	add_action('admin_init', 'y4apk_purge_legacy_license_data');
}
