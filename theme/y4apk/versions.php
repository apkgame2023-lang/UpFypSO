<?php
/**
 * Versions from members: the next pages, and the other sort (2.47).
 *
 * GET ?p=<main post>&page=<n>&sort=new|views[&x=<a version left out>] →
 * {html, pager, range, total, page, pages}. The post's page (cached, the same for
 * everyone) has the first 12; this sends the others as the visitor moves through
 * them, and the first again when the cached page may be old. WordPress with
 * SHORTINIT (database only), as suggest.php and views.php: three small indexed
 * queries, the same cards as the page (inc/versions-data.php). Only published
 * versions; nothing about the visitor is read or kept. Cloudflare may keep an
 * answer a minute.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=60, s-maxage=60');
if (!isset($_SERVER['REQUEST_METHOD']) || ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD')) {
	http_response_code(405);
	exit;
}
$y4p = isset($_GET['p']) ? (int) $_GET['p'] : 0;
$y4page = isset($_GET['page']) ? max(1, min(1000000, (int) $_GET['page'])) : 1;
$y4sort = isset($_GET['sort']) && $_GET['sort'] === 'views' ? 'views' : 'new';
$y4x = isset($_GET['x']) ? (int) $_GET['x'] : 0;
if ($y4p <= 0) {
	echo '{"html":"","pager":"","range":"","total":0,"page":1,"pages":1}';
	exit;
}

// wp-load.php, a few folders up from wp-content/themes/<theme>/ (from the address too: a linked theme folder resolves elsewhere).
$y4load = '';
foreach (array_unique([__DIR__, dirname((string) $_SERVER['SCRIPT_FILENAME'])]) as $y4dir) {
	for ($y4i = 0; $y4i < 6 && $y4load === ''; $y4i++) {
		$y4dir = dirname($y4dir);
		if (is_file($y4dir . '/wp-load.php')) {
			$y4load = $y4dir . '/wp-load.php';
		}
	}
}
if ($y4load === '') {
	echo '{"html":"","pager":"","range":"","total":0,"page":1,"pages":1}';
	exit;
}
define('SHORTINIT', true);
require $y4load;
require __DIR__ . '/inc/versions-data.php';

global $wpdb;
$wpdb->suppress_errors(true);
$y4cfg = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'y4apk_versions_cfg')), true);
$y4cfg = is_array($y4cfg) ? $y4cfg : ['home' => '', 'pretty' => false, 'short' => false, 'r2' => '', 't' => []];
// Only a published main post's versions
$y4ok = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' AND post_status = 'publish'", $y4p));
$y4data = $y4ok ? y4apk_vd_page($y4p, $y4page, $y4sort, $y4x, $y4cfg) : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
echo json_encode([
	'html'  => y4apk_vd_cards($y4data, $y4cfg),
	'pager' => y4apk_vd_pager($y4data, $y4sort, $y4cfg),
	'range' => y4apk_vd_range($y4data, $y4cfg),
	'total' => $y4data['total'],
	'page'  => $y4data['page'],
	'pages' => $y4data['pages'],
	'first' => $y4data['items'] ? $y4data['items'][0]['id'] : 0,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
