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

// 2.33: the vendor's Google Play importer (admin/: the Y4APK ▸ Google Play and
// Settings pages, APK upload to the server, Google Drive or FTP, and the Google
// API client) is gone. Y4APK ▸ App updates ▸ Add from Google Play adds apps,
// and App updates uploads files to R2. Its AJAX actions answered logged-out
// visitors too, guarded only by a nonce, one of them saving a remote file under
// its own name in uploads/.

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

/**
 * One time removal of what the old importer stored: its title and upload
 * settings, and the Google Drive and FTP credentials it kept in plain text.
 * at_mod_feature stays: Write with AI uses it as its default MOD.
 */
if (!function_exists('y4apk_purge_importer_options')) {
	function y4apk_purge_importer_options() {
		if (get_option('y4apk_importer_purged')) {
			return;
		}
		foreach (['at_post_title_start', 'at_post_title_end', 'at_is_mod_title', 'at_is_mod_feature_title', 'at_is_title_version', 'at_is_blocks_content',
			'at_is_get_apk', 'at_upload_storage', 'at_gdrive_client_id', 'at_gdrive_client_secret', 'at_gdrive_folder_name', 'at_gdrive_token',
			'at_ftp_server_ip', 'at_ftp_port', 'at_ftp_username', 'at_ftp_password', 'at_ftp_directory', 'at_ftp_url', 'file_progress'] as $name) {
			delete_option($name);
		}
		update_option('y4apk_importer_purged', 1, false);
	}
	add_action('admin_init', 'y4apk_purge_importer_options');
}
