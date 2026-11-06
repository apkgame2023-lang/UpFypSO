<?php
/**
 * Versions from members: one page of them, and their cards (2.47).
 *
 * Shared by the post's page (all of WordPress) and versions.php (SHORTINIT: the
 * database only, for the next pages and the sort), so both draw the same cards.
 * It needs $wpdb and nothing else: what only WordPress knows (the site's address,
 * R2's public address, the words) comes in $cfg, as Y4APK_Versions::cfg() keeps it.
 *
 * Built for many versions under one game: the versions of a main post are its
 * children (posts.post_parent, which has an index), so a page is one indexed
 * query of 12 rows, plus two small ones for their details and their members.
 */

if (!defined('ABSPATH')) {
	exit;
}

const Y4APK_VD_PER = 12;

/** One page: ['items' => […], 'total' => n, 'page' => n, 'pages' => n]. $sort: 'new' (newest first) or 'views'. $not: a version left out (the one being read). */
function y4apk_vd_page($parent, $page, $sort, $not, $cfg) {
	global $wpdb;
	$parent = (int) $parent;
	$not = (int) $not;
	$per = Y4APK_VD_PER;
	$where = $wpdb->prepare("p.post_parent = %d AND p.post_type = 'post' AND p.post_status = 'publish'", $parent) . ($not ? $wpdb->prepare(' AND p.ID <> %d', $not) : '');
	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where");
	$pages = max(1, (int) ceil($total / $per));
	$page = max(1, min($pages, (int) $page));
	$out = ['items' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];
	if (!$total) {
		return $out;
	}
	$offset = ($page - 1) * $per;
	$cols = 'p.ID, p.post_name, p.post_date_gmt, p.post_author, p.post_title';
	$rows = $sort === 'views'
		? $wpdb->get_results($wpdb->prepare("SELECT $cols FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'wpb_post_views_count'
			WHERE $where ORDER BY CAST(v.meta_value AS UNSIGNED) DESC, p.post_date_gmt DESC, p.ID DESC LIMIT %d OFFSET %d", $per, $offset))
		: $wpdb->get_results($wpdb->prepare("SELECT $cols FROM {$wpdb->posts} p WHERE $where ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d OFFSET %d", $per, $offset));
	if (!$rows) {
		return $out;
	}
	$ids = implode(',', array_map(function ($r) { return (int) $r->ID; }, $rows));
	$meta = [];
	foreach ((array) $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids) AND meta_key IN ('wp_version_GP', 'wp_mods', 'wp_sizes_GP', 'wpb_post_views_count', 'repeatable_download_link')") as $m) {
		$meta[(int) $m->post_id][$m->meta_key] = $m->meta_value;
	}
	$uids = implode(',', array_unique(array_map(function ($r) { return (int) $r->post_author; }, $rows)));
	$users = [];
	foreach ((array) $wpdb->get_results("SELECT ID, user_login FROM {$wpdb->users} WHERE ID IN ($uids)") as $u) {
		$users[(int) $u->ID] = ['login' => (string) $u->user_login];
	}
	$caps = $wpdb->prefix . 'capabilities';
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($uids) AND meta_key IN ('y4apk_name', 'y4apk_pic', 'y4apk_rbx', %s)", $caps)) as $m) {
		$users[(int) $m->user_id][$m->meta_key === $caps ? 'caps' : $m->meta_key] = $m->meta_value;
	}
	// Only members are named: a version put there by the site's staff shows the site's name, never theirs
	foreach ($users as $id => $u) {
		$roles = isset($u['caps']) ? @unserialize($u['caps'], ['allowed_classes' => false]) : null;
		if (!is_array($roles) || empty($roles[isset($cfg['role']) ? $cfg['role'] : 'y4apk_author'])) {
			$users[$id] = ['login' => '', 'staff' => true];
		}
	}
	foreach ($rows as $r) {
		$id = (int) $r->ID;
		$m = isset($meta[$id]) ? $meta[$id] : [];
		$u = isset($users[(int) $r->post_author]) ? $users[(int) $r->post_author] : ['login' => ''];
		$links = isset($m['repeatable_download_link']) ? @unserialize($m['repeatable_download_link'], ['allowed_classes' => false]) : null;
		$out['items'][] = [
			'id'    => $id,
			'url'   => y4apk_vd_url($r, $cfg),
			'v'     => isset($m['wp_version_GP']) ? (string) $m['wp_version_GP'] : '',
			'mods'  => isset($m['wp_mods']) ? trim((string) $m['wp_mods']) : '',
			'size'  => isset($m['wp_sizes_GP']) ? (string) $m['wp_sizes_GP'] : '',
			'type'  => is_array($links) && isset($links[0]['download_name']) ? strtoupper((string) $links[0]['download_name']) : 'APK',
			'views' => isset($m['wpb_post_views_count']) ? (int) $m['wpb_post_views_count'] : 0,
			'time'  => $r->post_date_gmt && $r->post_date_gmt !== '0000-00-00 00:00:00' ? (int) strtotime($r->post_date_gmt . ' UTC') : 0,
			'title' => html_entity_decode((string) $r->post_title, ENT_QUOTES, 'UTF-8'),
			'who'   => y4apk_vd_person($u, $cfg),
		];
	}
	return $out;
}

/** A version's address: the short one (/v/k7x2pq9m4z/) when it has one. */
function y4apk_vd_url($r, $cfg) {
	$home = (string) $cfg['home'];
	if (!empty($cfg['short']) && preg_match('/^[a-z0-9]{10}$/', (string) $r->post_name)) {
		return $home . '/v/' . $r->post_name . '/';
	}
	return !empty($cfg['pretty']) && $r->post_name !== '' ? $home . '/' . rawurlencode(urldecode($r->post_name)) . '/' : $home . '/?p=' . (int) $r->ID;
}

/** The member as a card shows them: name, @login, their picture (theirs on R2, else their Roblox avatar), else a letter on a colour. */
function y4apk_vd_person($u, $cfg) {
	if (!empty($u['staff']) || (string) $u['login'] === '') {
		$site = isset($cfg['site']) && $cfg['site'] !== '' ? (string) $cfg['site'] : 'Y4APK';
		return ['name' => $site, 'login' => '', 'pic' => '', 'letter' => strtoupper(substr($site, 0, 1)), 'hue' => 195];
	}
	$login = (string) $u['login'];
	$name = isset($u['y4apk_name']) ? trim((string) $u['y4apk_name']) : '';
	$pic = '';
	$p = isset($u['y4apk_pic']) ? @unserialize($u['y4apk_pic'], ['allowed_classes' => false]) : null;
	if (is_array($p) && !empty($p['url']) && !empty($cfg['r2']) && strpos((string) $p['url'], $cfg['r2'] . '/') === 0) {
		$pic = (string) $p['url'];
	}
	if ($pic === '' && isset($u['y4apk_rbx'])) {
		$x = @unserialize($u['y4apk_rbx'], ['allowed_classes' => false]);
		if (is_array($x) && !empty($x['img']) && preg_match('~^https://[a-z0-9-]+\.rbxcdn\.com/[^\s"\'<>]+$~i', (string) $x['img'])) {
			$pic = (string) $x['img'];
		}
	}
	$letter = function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($login, 0, 1)) : strtoupper(substr($login, 0, 1));
	return ['name' => $name !== '' ? $name : $login, 'login' => $login, 'pic' => $pic, 'letter' => $letter !== '' ? $letter : '?', 'hue' => abs(crc32($login)) % 360];
}

function y4apk_vd_e($s) {
	return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** "%s views" with the number in: the words come from $cfg['t'] (translated where WordPress is loaded). */
function y4apk_vd_t($cfg, $key, $n = null) {
	$t = isset($cfg['t'][$key]) ? $cfg['t'][$key] : $key;
	if (is_array($t)) {
		$t = (int) $n === 1 ? $t[0] : $t[1];
	}
	return $n !== null ? sprintf($t, number_format((float) $n)) : $t;
}

function y4apk_vd_date($time, $cfg) {
	if (!$time) {
		return '';
	}
	return gmdate('M j, Y', $time + (int) round((float) (isset($cfg['offset']) ? $cfg['offset'] : 0) * 3600)); // "Nov 6, 2026": fits a card on a phone
}

function y4apk_vd_avatar($who, $size = 40) {
	if ($who['pic'] !== '') {
		return '<img class="acc-av acc-av-img" src="' . y4apk_vd_e($who['pic']) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
	}
	$h = (int) $who['hue'];
	return '<span class="acc-av" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .46) . 'px;background:linear-gradient(135deg,hsl(' . $h . ',70%,52%),hsl(' . (($h + 40) % 360) . ',65%,38%))" aria-hidden="true">' . y4apk_vd_e($who['letter']) . '</span>';
}

/** One version: who shared it and when, the version and its MOD, the file, its views. */
function y4apk_vd_card($it, $cfg) {
	$mod = $it['mods'] !== '';
	return '<li><a class="y4v-card" href="' . y4apk_vd_e($it['url']) . '">'
		. '<span class="y4v-top"><span class="y4v-who">' . y4apk_vd_avatar($it['who']) . '</span>'
		. '<span class="y4v-person"><b class="y4v-name">' . y4apk_vd_e($it['who']['name']) . '</b>'
		. ($it['time'] ? '<time datetime="' . gmdate('c', $it['time']) . '">' . y4apk_vd_e(y4apk_vd_date($it['time'], $cfg)) . '</time>' : '') . '</span>'
		. '<svg class="y4v-go" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
		. '<span class="y4v-line"><b class="y4v-v">' . y4apk_vd_e($it['v'] !== '' ? 'v' . $it['v'] : $it['title']) . '</b>'
		. '<span class="y4v-chip' . ($mod ? ' y4v-chip-mod' : '') . '">' . y4apk_vd_e(y4apk_vd_t($cfg, $mod ? 'MOD' : 'Original')) . '</span></span>'
		. '<span class="y4v-mods">' . y4apk_vd_e($mod ? $it['mods'] : y4apk_vd_t($cfg, 'As released, not modded')) . '</span>'
		. '<span class="y4v-foot"><span class="y4v-type">' . y4apk_vd_e($it['type']) . '</span>'
		. ($it['size'] !== '' ? '<span class="y4v-size">' . y4apk_vd_e($it['size']) . '</span>' : '')
		. '<span class="y4v-views" title="' . y4apk_vd_e(y4apk_vd_t($cfg, 'views', $it['views'])) . '"><svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5C6.5 5 2.7 9.1 1.5 12c1.2 2.9 5 7 10.5 7s9.3-4.1 10.5-7C21.3 9.1 17.5 5 12 5Zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9Zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z" fill="currentColor"/></svg>'
		. y4apk_vd_e(y4apk_vd_short($it['views'])) . '</span></span>'
		. '</a></li>';
}

/** 1,234 → "1.2K", 2,500,000 → "2.5M". */
function y4apk_vd_short($n) {
	$n = (int) $n;
	if ($n >= 1000000) {
		return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
	}
	if ($n >= 10000) {
		return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
	}
	return number_format($n);
}

/** The cards of a page. */
function y4apk_vd_cards($data, $cfg) {
	$html = '';
	foreach ($data['items'] as $it) {
		$html .= y4apk_vd_card($it, $cfg);
	}
	return $html;
}

/** "‹ 1 2 3 … 834 ›": the first and last page, and two either side of this one. Links work without the script (?vp=2#versions). */
function y4apk_vd_pager($data, $sort, $cfg) {
	$page = (int) $data['page'];
	$pages = (int) $data['pages'];
	if ($pages < 2) {
		return '';
	}
	$href = function ($n) use ($sort) {
		$q = array_filter([$n > 1 ? 'vp=' . $n : '', $sort === 'views' ? 'vs=views' : '']);
		return ($q ? '?' . implode('&amp;', $q) : '?vp=1') . '#versions';
	};
	$fix = function ($h) {
		return $h;
	};
	$show = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], function ($n) use ($pages) { return $n >= 1 && $n <= $pages; }));
	sort($show);
	$html = '<a class="y4v-pg y4v-pg-step' . ($page <= 1 ? ' is-off' : '') . '" href="' . $fix($href(max(1, $page - 1))) . '" data-vp="' . max(1, $page - 1) . '" aria-label="' . y4apk_vd_e(y4apk_vd_t($cfg, 'Previous page')) . '"' . ($page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
	$last = 0;
	foreach ($show as $n) {
		if ($last && $n > $last + 1) {
			$html .= '<span class="y4v-pg-gap" aria-hidden="true">…</span>';
		}
		$html .= '<a class="y4v-pg' . ($n === $page ? ' is-on' : '') . '" href="' . $fix($href($n)) . '" data-vp="' . $n . '"' . ($n === $page ? ' aria-current="page"' : '') . '>' . number_format($n) . '</a>';
		$last = $n;
	}
	$html .= '<a class="y4v-pg y4v-pg-step' . ($page >= $pages ? ' is-off' : '') . '" href="' . $fix($href(min($pages, $page + 1))) . '" data-vp="' . min($pages, $page + 1) . '" aria-label="' . y4apk_vd_e(y4apk_vd_t($cfg, 'Next page')) . '"' . ($page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '') . '><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
	return $html;
}

/** "13–24 of 10,000". */
function y4apk_vd_range($data, $cfg) {
	if (!$data['total']) {
		return '';
	}
	$from = ($data['page'] - 1) * Y4APK_VD_PER + 1;
	$to = min($data['total'], $from + Y4APK_VD_PER - 1);
	return sprintf(y4apk_vd_t($cfg, '%1$s–%2$s of %3$s'), number_format($from), number_format($to), number_format($data['total']));
}
