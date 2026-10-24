<?php
/**
 * Y4APK member status (2.40): when a member was last seen, for the light on
 * their profile while someone has it open. POST {"u": user ID} → {"seen", "now"}.
 *
 * As cheap as stats.php and views.php: WordPress with SHORTINIT (database only)
 * and one query. It answers only for members who have been seen and are not
 * banned; for anyone else "seen" is 0.
 */

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit;
}

$y4apk_body = json_decode((string) file_get_contents('php://input', false, null, 0, 256), true);
$y4apk_id = is_array($y4apk_body) && isset($y4apk_body['u']) ? (int) $y4apk_body['u'] : 0;

// Only from pages of this site.
$y4apk_host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
$y4apk_from = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
if ($y4apk_id <= 0 || $y4apk_host === '' || strtolower((string) parse_url($y4apk_from, PHP_URL_HOST)) !== $y4apk_host) {
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
$y4apk_rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN ('y4apk_seen', 'y4apk_banned')", $y4apk_id), OBJECT_K);
$y4apk_seen = isset($y4apk_rows['y4apk_seen']) && empty($y4apk_rows['y4apk_banned']->meta_value) ? (int) $y4apk_rows['y4apk_seen']->meta_value : 0;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('CDN-Cache-Control: no-store');
echo json_encode(['seen' => $y4apk_seen, 'now' => time()]);
