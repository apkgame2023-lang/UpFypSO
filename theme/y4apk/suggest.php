<?php
/**
 * Y4APK search suggestions (2.44): what the search box shows as a visitor types.
 *
 * GET ?q=… → {items: [{t, n, u, i, v, k, c}], games: [{t, u, i, n, k}]}; with no q,
 * the popular items. WordPress with SHORTINIT (database only), as views.php: a
 * LIKE on the titles of published posts (each word must be in the title), the
 * best few by how well they match and how much they are read, then their icon,
 * version and kind in two more queries. Addresses and kinds come from the
 * y4apk_suggest_cfg option (inc/suggest.php keeps it). Cloudflare may keep each
 * answer five minutes. Nothing about the visitor is read or stored.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=300, s-maxage=600');
if (!isset($_SERVER['REQUEST_METHOD']) || ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD')) {
	http_response_code(405);
	exit;
}
$y4q = isset($_GET['q']) && is_string($_GET['q']) ? trim(preg_replace('/\s+/u', ' ', (string) $_GET['q'])) : '';
$y4q = function_exists('mb_substr') ? mb_substr($y4q, 0, 60) : substr($y4q, 0, 60);

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
	echo '{"items":[],"games":[]}';
	exit;
}
define('SHORTINIT', true);
require $y4load;

global $wpdb;
$wpdb->suppress_errors(true);
$y4opt = function ($name) use ($wpdb) {
	$v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
	$d = is_string($v) ? json_decode($v, true) : null;
	return is_array($d) ? $d : [];
};
$cfg = $y4opt('y4apk_suggest_cfg');
$top = $y4opt('y4apk_suggest_top');
if ($y4q === '') {
	echo json_encode(['top' => isset($top['top']) ? $top['top'] : []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}
$low = function ($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); };
$q = $low($y4q);
$words = array_slice(array_values(array_filter(explode(' ', $q), 'strlen')), 0, 4);

// Roblox games: from the list inc/suggest.php keeps
$games = [];
foreach (isset($top['games']) ? $top['games'] : [] as $g) {
	$name = $low((string) $g['t']);
	$ok = true;
	foreach ($words as $w) {
		if (strpos($name, $w) === false) {
			$ok = false;
			break;
		}
	}
	if ($ok) {
		$g['s'] = (strpos($name, $q) === 0 ? 1000 : 0) + (int) $g['n'];
		$games[] = $g;
	}
}
usort($games, function ($a, $b) { return $b['s'] - $a['s']; });
$games = array_map(function ($g) { unset($g['s']); return $g; }, array_slice($games, 0, 3));

// Posts whose title has every word
$types = isset($cfg['types']) && is_array($cfg['types']) ? array_keys($cfg['types']) : ['post'];
$where = [];
$args = [];
foreach ($words as $w) {
	$where[] = 'p.post_title LIKE %s';
	$args[] = '%' . $wpdb->esc_like($w) . '%';
}
$in = implode(',', array_fill(0, count($types), '%s'));
$rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_title, p.post_name, p.post_type, CAST(v.meta_value AS UNSIGNED) AS views FROM {$wpdb->posts} p
	LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'wpb_post_views_count'
	LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_y4apk_parent'
	WHERE p.post_status = 'publish' AND p.post_type IN ($in) AND k.post_id IS NULL AND " . implode(' AND ', $where) . ' ORDER BY views DESC LIMIT 60', array_merge($types, $args)));
$scored = [];
foreach ((array) $rows as $r) {
	$t = $low(html_entity_decode($r->post_title, ENT_QUOTES, 'UTF-8'));
	$s = log(1 + (int) $r->views);
	if (strpos($t, $q) === 0) {
		$s += 40;
	} elseif (preg_match('/(^|[\s\-:(\[])' . preg_quote($words[0], '/') . '/u', $t)) {
		$s += 20;
	}
	if (strpos($t, $q) !== false) {
		$s += 10;
	}
	$scored[] = [$s, $r];
}
usort($scored, function ($a, $b) { return $b[0] <=> $a[0]; });
$rows = array_map(function ($x) { return $x[1]; }, array_slice($scored, 0, 7));

$items = [];
if ($rows) {
	$ids = array_map(function ($r) { return (int) $r->ID; }, $rows);
	$idl = implode(',', $ids);
	$meta = [];
	foreach ((array) $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($idl) AND meta_key IN ('_thumbnail_id', 'wp_title_GP', 'wp_version_GP')") as $m) {
		$meta[(int) $m->post_id][$m->meta_key] = $m->meta_value;
	}
	$thumbs = array_filter(array_map(function ($id) use ($meta) { return isset($meta[$id]['_thumbnail_id']) ? (int) $meta[$id]['_thumbnail_id'] : 0; }, $ids));
	$files = [];
	if ($thumbs) {
		foreach ((array) $wpdb->get_results('SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode(',', $thumbs) . ") AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')") as $m) {
			$files[(int) $m->post_id][$m->meta_key] = $m->meta_value;
		}
	}
	$cats = [];
	foreach ((array) $wpdb->get_results("SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN ($idl) AND tt.taxonomy = 'category'") as $c) {
		$cats[(int) $c->object_id][] = (int) $c->term_id;
	}
	// A script's Roblox game: its name under the title, its icon when the post has none
	$rbx = [];
	foreach ((array) $wpdb->get_results("SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ($idl) AND tt.taxonomy = 'roblox_game'") as $g) {
		$rbx[(int) $g->object_id] = html_entity_decode($g->name, ENT_QUOTES, 'UTF-8');
	}
	$gicon = [];
	foreach (isset($top['games']) ? $top['games'] : [] as $g) {
		$gicon[$g['t']] = $g['i'];
	}
	$home = isset($cfg['home']) ? $cfg['home'] : '';
	$pretty = isset($cfg['perma']) && $cfg['perma'] === '/%postname%/';
	$up = isset($cfg['up']) ? $cfg['up'] : '';
	foreach ($rows as $r) {
		$id = (int) $r->ID;
		$kind = $r->post_type;
		$cat = '';
		foreach (['roblox' => 'script', 'game' => 'game', 'app' => 'app'] as $k => $as) {
			foreach (isset($cats[$id]) ? $cats[$id] : [] as $c) {
				if (isset($cfg[$k]) && in_array($c, $cfg[$k], true)) {
					$kind = $as;
					$cat = $k === 'roblox' || !isset($cfg['names'][$c]) ? '' : $cfg['names'][$c];
					break 2;
				}
			}
		}
		$slug = isset($cfg['types'][$r->post_type]) ? $cfg['types'][$r->post_type] : '';
		$url = $pretty ? $home . '/' . ($slug !== '' ? $slug . '/' : '') . rawurlencode(urldecode($r->post_name)) . '/' : $home . '/?p=' . $id;
		$icon = '';
		$th = isset($meta[$id]['_thumbnail_id']) ? (int) $meta[$id]['_thumbnail_id'] : 0;
		if ($th && isset($files[$th]['_wp_attached_file'])) {
			$file = (string) $files[$th]['_wp_attached_file'];
			$md = isset($files[$th]['_wp_attachment_metadata']) ? @unserialize($files[$th]['_wp_attachment_metadata'], ['allowed_classes' => false]) : null;
			if (is_array($md) && !empty($md['sizes']['thumbnail']['file'])) {
				$dir = dirname($file);
				$file = ($dir !== '.' ? $dir . '/' : '') . $md['sizes']['thumbnail']['file'];
			}
			$icon = preg_match('~^https?://~', $file) ? $file : $up . '/' . implode('/', array_map('rawurlencode', explode('/', $file)));
		}
		if (isset($rbx[$id])) {
			$cat = $rbx[$id];
			if ($icon === '' && !empty($gicon[$cat]) && strpos($gicon[$cat], 'data:') !== 0) {
				$icon = $gicon[$cat];
			}
		}
		$ver = isset($meta[$id]['wp_version_GP']) && preg_match('/\d+(?:\.\d+)*/', (string) $meta[$id]['wp_version_GP'], $vm) ? $vm[0] : '';
		$items[] = ['t' => html_entity_decode($r->post_title, ENT_QUOTES, 'UTF-8'), 'n' => isset($meta[$id]['wp_title_GP']) ? html_entity_decode($meta[$id]['wp_title_GP'], ENT_QUOTES, 'UTF-8') : '',
			'u' => $url, 'i' => $icon, 'v' => $kind === 'game' || $kind === 'app' ? $ver : '', 'k' => $kind === 'post' ? 'post' : $kind, 'c' => $cat];
	}
}
echo json_encode(['items' => $items, 'games' => $games], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
