<?php
/**
 * Y4APK app analytics counter.
 *
 * Browsers send a small batch of event counts here (navigator.sendBeacon, from
 * the boot script in <head>): {"e": {"banner_tap:apk": 1, ...}}. This adds them
 * to the day's totals in {prefix}y4apk_stats and answers 204.
 *
 * Built to be cheap, because every visit can send one: WordPress is loaded with
 * SHORTINIT (database only — no plugins, no theme, no options preload), then one
 * small read for the timezone and one INSERT … ON DUPLICATE KEY UPDATE. Nothing
 * personal is stored: no IP, no cookie, no user ID — only counts per day.
 */

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit;
}

$y4apk_body = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($y4apk_body) || empty($y4apk_body['e']) || !is_array($y4apk_body['e'])) {
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

// wp-load.php, a few folders up from wp-content/themes/<theme>/.
$y4apk_load = '';
for ($y4apk_dir = __DIR__, $y4apk_i = 0; $y4apk_i < 6; $y4apk_i++) {
	$y4apk_dir = dirname($y4apk_dir);
	if (is_file($y4apk_dir . '/wp-load.php')) {
		$y4apk_load = $y4apk_dir . '/wp-load.php';
		break;
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

// The site's own calendar day, like the rest of WordPress.
$y4apk_tz = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('timezone_string', 'gmt_offset')", OBJECT_K);
$y4apk_zone = isset($y4apk_tz['timezone_string']) ? (string) $y4apk_tz['timezone_string']->option_value : '';
$y4apk_offset = isset($y4apk_tz['gmt_offset']) ? (float) $y4apk_tz['gmt_offset']->option_value : 0;
try {
	$y4apk_day = $y4apk_zone !== ''
		? (new DateTime('now', new DateTimeZone($y4apk_zone)))->format('Y-m-d')
		: gmdate('Y-m-d', time() + (int) round($y4apk_offset * 3600));
} catch (Exception $e) {
	$y4apk_day = gmdate('Y-m-d');
}

$y4apk_events = require __DIR__ . '/inc/stats-events.php';
$y4apk_rows = [];
foreach (array_slice($y4apk_body['e'], 0, 20, true) as $y4apk_key => $y4apk_count) {
	$y4apk_parts = explode(':', (string) $y4apk_key, 2);
	if (!isset($y4apk_events[$y4apk_parts[0]])) {
		continue;
	}
	$y4apk_dim = isset($y4apk_parts[1]) ? substr(preg_replace('/[^A-Za-z0-9._-]/', '', $y4apk_parts[1]), 0, 40) : '';
	$y4apk_rows[] = $wpdb->prepare('(%s, %s, %s, %d)', $y4apk_day, $y4apk_parts[0], $y4apk_dim, max(1, min(10, (int) $y4apk_count)));
}

if ($y4apk_rows) {
	$wpdb->query("INSERT INTO {$wpdb->prefix}y4apk_stats (day, event, dim, n) VALUES " . implode(',', $y4apk_rows)
		. ' ON DUPLICATE KEY UPDATE n = n + VALUES(n)');
}

http_response_code(204);
