<?php
/**
 * Y4APK post views counter (2.37).
 *
 * Most visits get a page from the page cache (10Web, Cloudflare) and never reach
 * WordPress, so they were not counted. A small script on each post sends its ID
 * here instead (navigator.sendBeacon) and this adds one to wpb_post_views_count.
 * As cheap as stats.php: WordPress with SHORTINIT (database only), one SELECT and
 * one UPDATE. Nothing about the visitor is stored.
 */

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit;
}

$y4apk_body = json_decode((string) file_get_contents('php://input', false, null, 0, 256), true);
$y4apk_id = is_array($y4apk_body) && isset($y4apk_body['p']) ? (int) $y4apk_body['p'] : 0;
if ($y4apk_id <= 0) {
	http_response_code(204);
	exit;
}

// Only from pages of this site.
$y4apk_host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
$y4apk_from = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
if ($y4apk_host === '' || strtolower((string) parse_url($y4apk_from, PHP_URL_HOST)) !== $y4apk_host) {
	http_response_code(204);
	exit;
}

// wp-load.php, a few folders up from wp-content/themes/<theme>/ (from the address too: a linked theme folder resolves elsewhere).
$y4apk_load = '';
foreach (array_unique([__DIR__, dirname((string) $_SERVER['SCRIPT_FILENAME'])]) as $y4apk_dir) {
	for ($y4apk_i = 0; $y4apk_i < 6 && $y4apk_load === ''; $y4apk_i++) {
		$y4apk_dir = dirname($y4apk_dir);
		if (is_file($y4apk_dir . '/wp-load.php')) {
			$y4apk_load = $y4apk_dir . '/wp-load.php';
		}
	}
}
if ($y4apk_load === '') {
	http_response_code(204);
	exit;
}

define('SHORTINIT', true);
require $y4apk_load;

global $wpdb;
$wpdb->suppress_errors(true);

if ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' AND post_status = 'publish'", $y4apk_id))) {
	// meta_value <> '': MySQL in strict mode refuses '' + 1.
	$y4apk_done = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'wpb_post_views_count' AND meta_value <> ''", $y4apk_id));
	if (!$y4apk_done) {
		$y4apk_meta = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'wpb_post_views_count' LIMIT 1", $y4apk_id));
		if ($y4apk_meta) {
			$wpdb->update($wpdb->postmeta, ['meta_value' => '1'], ['meta_id' => (int) $y4apk_meta]);
		} else {
			$wpdb->insert($wpdb->postmeta, ['post_id' => $y4apk_id, 'meta_key' => 'wpb_post_views_count', 'meta_value' => '1']);
		}
	}
	if (function_exists('wp_cache_delete')) {
		wp_cache_delete($y4apk_id, 'post_meta'); // a persistent object cache would keep the old number
	}
}

http_response_code(204);
