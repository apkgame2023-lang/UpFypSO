<?php
/**
 * Y4APK Roblox numbers (2.42): players now and likes of some games, as stored by
 * the 10-minute refresh. GET ?g=<term IDs, comma-separated> → {"<id>": {"playing", "likes", "at"}}.
 *
 * Pages are cached for longer than the numbers live, so a game page, a game's
 * tile or a script post asks here for the newest. As cheap as stats.php and
 * views.php: WordPress with SHORTINIT (database only) and one query. Nothing in
 * it is private (the same numbers are on the pages), so a cache may keep the
 * answer for a minute.
 */

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
	http_response_code(405);
	exit;
}

$y4apk_ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', isset($_GET['g']) ? substr((string) $_GET['g'], 0, 400) : '')))), 0, 40);
if (!$y4apk_ids) {
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
$y4apk_rows = $wpdb->get_results("SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'y4apk_stats' AND term_id IN (" . implode(',', $y4apk_ids) . ')'); // integers only
$y4apk_out = [];
foreach ((array) $y4apk_rows as $y4apk_row) {
	$y4apk_s = @unserialize((string) $y4apk_row->meta_value, ['allowed_classes' => false]);
	if (!is_array($y4apk_s)) {
		continue;
	}
	$y4apk_up = isset($y4apk_s['up']) ? (int) $y4apk_s['up'] : 0;
	$y4apk_down = isset($y4apk_s['down']) ? (int) $y4apk_s['down'] : 0;
	$y4apk_out[(int) $y4apk_row->term_id] = [
		'playing' => isset($y4apk_s['playing']) ? (int) $y4apk_s['playing'] : null,
		'likes'   => $y4apk_up + $y4apk_down > 0 ? (int) round(100 * $y4apk_up / ($y4apk_up + $y4apk_down)) : null,
		'at'      => isset($y4apk_s['fetched']) ? (int) $y4apk_s['fetched'] : 0,
	];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Robots-Tag: noindex');
echo json_encode((object) $y4apk_out);
