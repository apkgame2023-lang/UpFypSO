<?php
/**
 * Y4APK ▸ Dashboard: the first page of the Y4APK menu. One look at the whole
 * site, the full width of the screen (2.43): the headline numbers with their
 * trend; members in depth (how many, how fast they join, how far they get, where
 * they come from, their countries and devices, when they join, who follows whom,
 * who matters most); what people read; Roblox; the app and the ads; and how each
 * part of the site is doing. Every number links to where you act on it.
 *
 * Counted in the database from what the theme already keeps (members' origins:
 * inc/members-insights.php), with GROUP BY queries so it stays quick with
 * millions of members; nothing is sent anywhere. Kept 5 minutes per period
 * (7, 30 or 90 days; the page says how old the numbers are).
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Dashboard {

	const CACHE  = 'y4apk_dash3_';
	const RANGES = [7, 30, 90];

	/** The days the page is looking at. */
	private static $days = 30;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu'], 8);
		add_action('admin_post_y4apk_dash_refresh', [__CLASS__, 'refresh']);
	}

	public static function menu() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M6 2H18C19.1 2 20 2.9 20 4V7.32C20 7.87 19.55 8.32 19 8.32H5C4.45 8.32 4 7.87 4 7.32V4C4 2.9 4.9 2 6 2Z" fill="#292D32"/><path d="M4 10.3203V11.8803C4 12.9603 4.58 13.9603 5.53 14.4903L8.49 16.1603C9.12 16.5103 9.51 17.1803 9.51 17.9003V20.0003C9.51 21.1003 10.41 22.0003 11.51 22.0003H12.51C13.61 22.0003 14.51 21.1003 14.51 20.0003V17.9003C14.51 17.1803 14.9 16.5103 15.53 16.1603L18.49 14.4903C19.43 13.9603 20.02 12.9603 20.02 11.8803V10.3203C20.02 9.77031 19.57 9.32031 19.02 9.32031H5C4.45 9.32031 4 9.76031 4 10.3203Z" fill="#292D32"/></svg>';
		add_menu_page('Y4APK', 'Y4APK', 'manage_options', Y4APK_MENU, [__CLASS__, 'render'], 'data:image/svg+xml;base64,' . base64_encode($svg), '2.1'); // 2.58.1: at the top, right under WordPress's Dashboard
		add_submenu_page(Y4APK_MENU, __('Dashboard', 'y4apk'), __('Dashboard', 'y4apk'), 'manage_options', Y4APK_MENU, [__CLASS__, 'render']);
	}

	public static function refresh() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_dash_refresh');
		foreach (self::RANGES as $r) {
			delete_transient(self::CACHE . $r);
		}
		delete_transient(self::CACHE . 'members');
		delete_transient('y4apk_review_counts');
		$range = isset($_POST['range']) ? (int) $_POST['range'] : 30;
		wp_safe_redirect(admin_url('admin.php?page=' . Y4APK_MENU . '&range=' . (in_array($range, self::RANGES, true) ? $range : 30)));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * The numbers
	 * ---------------------------------------------------------------- */

	/** One value per day for the last $days days (the site's days): [Y-m-d => n], from rows of [day, n]. */
	private static function fill($rows, $days = null) {
		$days = $days ?: self::$days;
		$out = [];
		$start = strtotime(wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS));
		for ($i = 0; $i < $days; $i++) {
			$out[gmdate('Y-m-d', $start + $i * DAY_IN_SECONDS)] = 0;
		}
		foreach ($rows as $r) {
			if (isset($out[$r->day])) {
				$out[$r->day] = (int) $r->n;
			}
		}
		return $out;
	}

	/** SQL for "is a member": joined to the user ID column $col. */
	private static function member_join($col) {
		global $wpdb;
		return $wpdb->prepare("JOIN {$wpdb->usermeta} cap ON cap.user_id = $col AND cap.meta_key = %s AND cap.meta_value LIKE %s", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like(Y4APK_Members::ROLE) . '%');
	}

	/** Site time as SQL: user_registered and post_date_gmt are UTC. */
	private static function local($col) {
		$min = (int) round((float) get_option('gmt_offset') * 60);
		return "DATE_ADD($col, INTERVAL $min MINUTE)";
	}

	public static function data($fresh = false) {
		$days = self::$days;
		$d = get_transient(self::CACHE . $days);
		if (!$fresh && is_array($d)) {
			return $d;
		}
		global $wpdb;
		$now = time();
		$since = gmdate('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS);
		$before = gmdate('Y-m-d H:i:s', $now - 2 * $days * DAY_IN_SECONDS);
		$d = ['time' => $now, 'days' => $days];
		$mj = self::member_join('u.ID');

		// The site
		$counts = wp_count_posts('post');
		$d['posts'] = (int) $counts->publish;
		$d['drafts'] = (int) $counts->draft + (int) $counts->pending;
		$d['views'] = (int) $wpdb->get_var("SELECT SUM(m.meta_value + 0) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'wpb_post_views_count' AND p.post_status = 'publish'");
		$d['posts_by_day'] = self::fill($wpdb->get_results($wpdb->prepare('SELECT DATE(' . self::local('post_date_gmt') . ") AS day, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt > %s GROUP BY day", $since)));
		$d['posts_prev'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt BETWEEN %s AND %s", $before, $since));

		// Roblox
		$q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => Y4APK_Roblox::only_roblox()]);
		$d['roblox'] = (int) $q->found_posts;
		$all_games = Y4APK_Roblox::games();
		$d['games'] = count($all_games);
		$d['top_games'] = [];
		foreach (array_slice($all_games, 0, 8) as $g) {
			$t = Y4APK_Roblox::totals($g);
			$s = Y4APK_Roblox::stats($g);
			$d['top_games'][] = ['name' => $g->name, 'url' => get_term_link($g), 'icon' => Y4APK_Roblox::icon_url($g), 'scripts' => (int) $g->count, 'views' => $t['views'], 'playing' => $s['playing'],
				'likes' => $s['up'] !== null && ($s['up'] + $s['down']) > 0 ? (int) round(100 * $s['up'] / ($s['up'] + $s['down'])) : null];
		}
		$d['unsorted'] = 0;
		if (Y4APK_Roblox::categories()) {
			$d['unsorted'] = (int) (new WP_Query(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending'], 'posts_per_page' => 1, 'fields' => 'ids',
				'tax_query' => ['relation' => 'AND', ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => Y4APK_Roblox::categories(), 'include_children' => false], ['taxonomy' => Y4APK_Roblox::TAX, 'operator' => 'NOT EXISTS']]]))->found_posts;
		}

		// Members, for the period
		$d['members_by_day'] = self::fill($wpdb->get_results($wpdb->prepare('SELECT DATE(' . self::local('u.user_registered') . ") AS day, COUNT(*) AS n FROM {$wpdb->users} u $mj WHERE u.user_registered > %s GROUP BY day", $since)));
		$d['members_prev'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} u $mj WHERE u.user_registered BETWEEN %s AND %s", $before, $since));
		$d['submitted_by_day'] = self::fill($wpdb->get_results($wpdb->prepare('SELECT DATE(' . self::local('p.post_date_gmt') . ") AS day, COUNT(*) AS n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'post' AND p.post_status <> 'trash' AND p.post_date_gmt > %s GROUP BY day", Y4APK_Members::MEMBER_POST, $since)));
		$d['submitted_prev'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'post' AND p.post_status <> 'trash' AND p.post_date_gmt BETWEEN %s AND %s", Y4APK_Members::MEMBER_POST, $before, $since));
		$d['member_scripts'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'post' AND p.post_status = 'publish'", Y4APK_Members::MEMBER_POST));
		list($d['waiting'], $d['reported']) = Y4APK_Members_Admin::counts();
		$d['pictures_new'] = class_exists('Y4APK_Pictures') ? Y4APK_Pictures::count_new() : 0;
		$d['pending'] = [];
		foreach (get_posts(['post_type' => 'post', 'post_status' => 'pending', 'meta_key' => Y4APK_Members::MEMBER_POST, 'numberposts' => 6, 'orderby' => 'date', 'order' => 'ASC']) as $p) {
			$u = get_userdata($p->post_author);
			$d['pending'][] = ['title' => wp_strip_all_tags(get_the_title($p)), 'by' => $u ? $u->user_login : '', 'ago' => human_time_diff(get_post_datetime($p)->getTimestamp())];
		}

		// What people read
		$d['top'] = [];
		foreach (get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 10, 'meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC']) as $p) {
			$g = Y4APK_Roblox::game_of($p->ID);
			$d['top'][] = ['id' => $p->ID, 'title' => wp_strip_all_tags(get_the_title($p)), 'views' => (int) get_post_meta($p->ID, 'wpb_post_views_count', true), 'kind' => $g ? $g->name : (Y4APK_Roblox::is_roblox_post($p->ID) ? 'Roblox' : ''), 'url' => get_permalink($p)];
		}

		// The app and the ads (Y4APK ▸ Analytics keeps these per day)
		$t = Y4APK_Stats::table();
		$has = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
		$d['stats'] = $has;
		$events = ['direct_link', 'file_download', 'app_daily', 'app_new', 'pwa_installed', 'banner_tap'];
		$d['ev'] = $d['ev_prev'] = array_fill_keys($events, 0);
		$d['ads_by_day'] = $d['dl_by_day'] = $d['app_by_day'] = self::fill([]);
		if ($has) {
			$from = wp_date('Y-m-d', $now - ($days - 1) * DAY_IN_SECONDS);
			$prev_from = wp_date('Y-m-d', $now - (2 * $days - 1) * DAY_IN_SECONDS);
			$list = "'" . implode("','", $events) . "'";
			foreach ($wpdb->get_results($wpdb->prepare("SELECT event, SUM(n) AS n FROM $t WHERE day >= %s AND event IN ($list) GROUP BY event", $from)) as $r) {
				$d['ev'][$r->event] = (int) $r->n;
			}
			foreach ($wpdb->get_results($wpdb->prepare("SELECT event, SUM(n) AS n FROM $t WHERE day >= %s AND day < %s AND event IN ($list) GROUP BY event", $prev_from, $from)) as $r) {
				$d['ev_prev'][$r->event] = (int) $r->n;
			}
			foreach (['ads_by_day' => 'direct_link', 'dl_by_day' => 'file_download', 'app_by_day' => 'app_daily'] as $key => $event) {
				$d[$key] = self::fill($wpdb->get_results($wpdb->prepare("SELECT day, SUM(n) AS n FROM $t WHERE day >= %s AND event = %s GROUP BY day", $from, $event)));
			}
		}

		// The other parts of the site
		$d['needs_update'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_status = 'publish'", Y4APK_App_Updates::NEEDS));
		$usage = get_option(Y4APK_Writer::USAGE, []);
		$d['ai'] = ['queue' => count(Y4APK_Writer::queue()), 'posts' => is_array($usage) && isset($usage['posts']) ? (int) $usage['posts'] : 0, 'tokens' => is_array($usage) ? (int) ($usage['in'] ?? 0) + (int) ($usage['out'] ?? 0) : 0,
			'wait' => Y4APK_Writer::waiting(), 'paused' => Y4APK_Writer::settings()['paused'] !== ''];
		set_transient(self::CACHE . $days, $d, 5 * MINUTE_IN_SECONDS);
		return $d;
	}

	/**
	 * Members in depth, for all of them (not the period): totals, activity, the
	 * journey from sign-up to a live script, where they come from, countries,
	 * devices, when they join, the follow network and the members who matter most.
	 */
	public static function members($fresh = false) {
		$m = get_transient(self::CACHE . 'members');
		if (!$fresh && is_array($m)) {
			return $m;
		}
		global $wpdb;
		$now = time();
		$mj = self::member_join('u.ID');
		$mjm = self::member_join('m.user_id');
		$pre = Y4APK_Insights::PRE;
		$m = ['time' => $now];

		$m['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u $mj");
		$count_meta = function ($key, $value = null, $op = '=') use ($wpdb, $mjm) {
			$where = $value === null ? '' : $wpdb->prepare(" AND m.meta_value $op %s", $value);
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT m.user_id) FROM {$wpdb->usermeta} m $mjm WHERE m.meta_key = %s", $key) . $where);
		};
		$m['unverified'] = $count_meta(Y4APK_Members::VERIFIED, '0');
		$m['banned'] = $count_meta(Y4APK_Members::BANNED, '1');
		$seen = function ($secs) use ($wpdb, $mjm, $now) {
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} m $mjm WHERE m.meta_key = %s AND m.meta_value + 0 > %d", Y4APK_Social::SEEN, $now - $secs));
		};
		$m['online'] = $seen(Y4APK_Social::ONLINE);
		$m['active_1'] = $seen(DAY_IN_SECONDS);
		$m['active_7'] = $seen(7 * DAY_IN_SECONDS);
		$m['active_30'] = $seen(30 * DAY_IN_SECONDS);

		// The journey: joined → confirmed → shared a script → a live script → followed by someone
		$mp = Y4APK_Members::MEMBER_POST;
		$m['shared'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.post_author) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'post' AND p.post_status <> 'trash'", $mp));
		$m['live'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.post_author) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'post' AND p.post_status = 'publish'", $mp));
		$m['followed'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->usermeta} WHERE meta_key = %s", Y4APK_Social::FOLLOWS));
		$m['follows'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", Y4APK_Social::FOLLOWS));

		// Breakdowns: one GROUP BY each
		$group = function ($field, $limit = 12) use ($wpdb, $mjm, $pre) {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT m.meta_value AS v, COUNT(*) AS n FROM {$wpdb->usermeta} m $mjm WHERE m.meta_key = %s AND m.meta_value <> '' GROUP BY m.meta_value ORDER BY n DESC LIMIT %d", $pre . $field, $limit));
			$out = [];
			foreach ((array) $rows as $r) {
				$out[(string) $r->v] = (int) $r->n;
			}
			return $out;
		};
		foreach (['cc' => 40, 'device' => 5, 'os' => 8, 'browser' => 12, 'app' => 3, 'source' => 12, 'ref' => 10, 'land' => 10, 'lang' => 10] as $f => $lim) {
			$m['by_' . $f] = $group($f, $lim);
		}
		$m['cc_guess'] = $count_meta($pre . 'cc_by', 'lang');
		$m['recorded_device'] = $count_meta($pre . 'device');
		$m['recorded_source'] = $count_meta($pre . 'source');

		// When they join: weekday (0 Sunday) × hour, the site's time
		$m['heat'] = array_fill(0, 7, array_fill(0, 24, 0));
		foreach ((array) $wpdb->get_results('SELECT DAYOFWEEK(' . self::local('u.user_registered') . ') AS dw, HOUR(' . self::local('u.user_registered') . ") AS h, COUNT(*) AS n FROM {$wpdb->users} u $mj GROUP BY dw, h") as $r) {
			$m['heat'][((int) $r->dw + 6) % 7][(int) $r->h] = (int) $r->n;
		}
		// How fast the total grew: members at the end of each of the last 12 weeks
		$m['growth'] = [];
		for ($w = 11; $w >= 0; $w--) {
			$m['growth'][] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} u $mj WHERE u.user_registered <= %s", gmdate('Y-m-d H:i:s', $now - $w * WEEK_IN_SECONDS)));
		}

		// The members who matter most: live scripts and their views; their followers
		$top = $wpdb->get_results($wpdb->prepare("SELECT p.post_author AS id, COUNT(*) AS live, SUM(COALESCE(v.meta_value, 0) + 0) AS views FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'wpb_post_views_count'
			WHERE p.post_type = 'post' AND p.post_status = 'publish' GROUP BY p.post_author ORDER BY views DESC LIMIT 10", $mp));
		$fans = [];
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT meta_value AS id, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY n DESC LIMIT 200", Y4APK_Social::FOLLOWS)) as $r) {
			$fans[(int) $r->id] = (int) $r->n;
		}
		$m['top'] = [];
		foreach ((array) $top as $r) {
			$u = get_userdata((int) $r->id);
			if ($u && Y4APK_Members::is_member($u)) {
				$m['top'][] = ['id' => $u->ID, 'login' => $u->user_login, 'name' => Y4APK_Social::name($u), 'live' => (int) $r->live, 'views' => (int) $r->views, 'fans' => isset($fans[$u->ID]) ? $fans[$u->ID] : 0,
					'seen' => Y4APK_Social::seen_at($u), 'o' => Y4APK_Insights::of($u->ID)];
			}
		}
		$m['newest'] = [];
		foreach (get_users(['role' => Y4APK_Members::ROLE, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 8]) as $u) {
			$m['newest'][] = ['id' => $u->ID, 'login' => $u->user_login, 'joined' => strtotime($u->user_registered . ' UTC'), 'verified' => Y4APK_Members::is_verified($u->ID), 'banned' => (bool) get_user_meta($u->ID, Y4APK_Members::BANNED, true),
				'scripts' => (int) count_user_posts($u->ID, 'post', true), 'o' => Y4APK_Insights::of($u->ID)];
		}

		// Who follows whom: the 160 members with the most ties, and the ties between them
		$edges = $wpdb->get_results($wpdb->prepare("SELECT user_id AS a, meta_value AS b FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id DESC LIMIT 4000", Y4APK_Social::FOLLOWS));
		$deg = [];
		foreach ((array) $edges as $e) {
			$deg[(int) $e->a] = ($deg[(int) $e->a] ?? 0) + 1;
			$deg[(int) $e->b] = ($deg[(int) $e->b] ?? 0) + 1;
		}
		arsort($deg);
		$keep = array_slice($deg, 0, 160, true);
		$m['net'] = ['nodes' => [], 'links' => []];
		if ($keep) {
			$names = $wpdb->get_results('SELECT ID, user_login FROM ' . $wpdb->users . ' WHERE ID IN (' . implode(',', array_map('intval', array_keys($keep))) . ')', OBJECT_K);
			foreach ($keep as $id => $n) {
				if (isset($names[$id])) {
					$m['net']['nodes'][] = ['id' => (int) $id, 'n' => '@' . $names[$id]->user_login, 'f' => $fans[$id] ?? 0];
				}
			}
			foreach ((array) $edges as $e) {
				if (isset($keep[(int) $e->a], $keep[(int) $e->b], $names[(int) $e->a], $names[(int) $e->b])) {
					$m['net']['links'][] = [(int) $e->a, (int) $e->b];
				}
			}
		}
		set_transient(self::CACHE . 'members', $m, 5 * MINUTE_IN_SECONDS);
		return $m;
	}

	/* ---------------------------------------------------------------- *
	 * Drawing (light admin screen: the reference palette, validated on #fff)
	 * ---------------------------------------------------------------- */

	const BLUE  = '#2a78d6'; // categorical 1, and the sequential hue
	const ORANGE = '#eb6834'; // categorical 2
	const AQUA  = '#1baf7a'; // categorical 3
	const RAMP  = ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b']; // sequential, light → dark
	const ORD   = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281']; // ordinal (funnel stages)

	public static function n($v) {
		return is_numeric($v) ? number_format_i18n((int) $v) : (string) $v;
	}

	public static function short($v) {
		return Y4APK_Roblox::short_number((int) $v);
	}

	public static function pct($part, $whole) {
		return $whole > 0 ? round(100 * $part / $whole) : 0;
	}

	/** A signed change vs the period before, coloured by direction (up is good for everything here). */
	public static function delta($now, $before, $label) {
		if ($now === 0 && $before === 0) {
			return '<span class="y4d-delta">' . esc_html__('no change', 'y4apk') . '</span>';
		}
		if ($before === 0) {
			return '<span class="y4d-delta y4d-delta-up">▲ ' . esc_html__('new', 'y4apk') . '</span> <span class="y4d-delta">' . esc_html($label) . '</span>';
		}
		$pct = (int) round(100 * ($now - $before) / $before);
		return '<span class="y4d-delta y4d-delta-' . ($pct >= 0 ? 'up">▲ ' : 'down">▼ ') . esc_html(abs($pct) . '%') . '</span> <span class="y4d-delta">' . esc_html($label) . '</span>';
	}

	/** The days of a period put into up to 12 buckets, for a stat tile's trend. */
	public static function buckets($series, $n = 12) {
		$vals = array_values($series);
		$size = max(1, (int) ceil(count($vals) / $n));
		$out = [];
		foreach (array_chunk($vals, $size) as $chunk) {
			$out[] = array_sum($chunk);
		}
		return $out;
	}

	/** A tile's trend: a 2px line, a 10% wash, the end dot ringed in the surface colour. */
	public static function spark($vals) {
		$w = 120; $h = 34; $n = count($vals);
		if ($n < 2) {
			return '';
		}
		$max = max(1, max($vals));
		$pts = [];
		foreach ($vals as $i => $v) {
			$pts[] = round($i * ($w - 8) / ($n - 1) + 2, 1) . ',' . round($h - 4 - ($h - 10) * $v / $max, 1);
		}
		$last = explode(',', end($pts));
		return '<svg class="y4d-spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true" preserveAspectRatio="none"><polygon points="2,' . ($h - 4) . ' ' . implode(' ', $pts) . ' ' . $last[0] . ',' . ($h - 4) . '" fill="' . self::BLUE . '" fill-opacity=".1"/>'
			. '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . self::BLUE . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
			. '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="' . self::BLUE . '" stroke="#fff" stroke-width="2"/></svg>';
	}

	/** Clean axis steps: [step, top]. */
	public static function nice($max) {
		$max = max(1, $max);
		$raw = $max / 3;
		$mag = pow(10, floor(log10($raw)));
		foreach ([1, 2, 2.5, 5, 10] as $m) {
			if ($raw <= $m * $mag) {
				$step = $m * $mag;
				break;
			}
		}
		$step = max(1, $step);
		return [$step, $step * ceil($max / $step)];
	}

	/** Columns per day: ≤24px bars, 4px rounded tops, hairline grid, clean ticks, a tooltip on each day. */
	public static function columns($series, $noun) {
		$w = 720; $h = 190; $left = 34; $bottom = 22; $top = 8;
		$n = max(1, count($series));
		list($step, $ymax) = self::nice(max($series ?: [0]));
		$ph = $h - $bottom - $top;
		$slot = ($w - $left) / $n;
		$bw = min(24, max(2, $slot * .62));
		$out = '<svg class="y4d-cols" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr($noun) . '">';
		for ($v = 0; $v <= $ymax; $v += $step) {
			$y = round($top + $ph - $ph * $v / $ymax, 1);
			$out .= '<line x1="' . $left . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="' . ($v === 0 ? '#c3c2b7' : '#ebeae4') . '" stroke-width="1" shape-rendering="crispEdges"/>'
				. '<text x="' . ($left - 6) . '" y="' . ($y + 3.5) . '" class="y4d-tick" text-anchor="end">' . esc_html(self::short($v)) . '</text>';
		}
		$i = 0;
		$keys = array_keys($series);
		foreach ($series as $day => $v) {
			$x = $left + $i * $slot;
			$bh = $v > 0 ? max(3, $ph * $v / $ymax) : 0;
			$tip = wp_date('D j M', strtotime($day)) . ' · ' . number_format_i18n($v) . ' ' . $noun;
			$out .= '<g class="y4d-hit" data-tip="' . esc_attr($tip) . '"><rect x="' . round($x, 1) . '" y="' . $top . '" width="' . round($slot, 1) . '" height="' . $ph . '" fill="transparent"/>';
			if ($bh > 0) {
				$bx = round($x + ($slot - $bw) / 2, 1); $by = round($top + $ph - $bh, 1); $r = min(4, $bw / 2, $bh);
				$out .= '<path d="M' . $bx . ',' . ($top + $ph) . 'V' . round($by + $r, 1) . 'Q' . $bx . ',' . $by . ' ' . round($bx + $r, 1) . ',' . $by . 'H' . round($bx + $bw - $r, 1) . 'Q' . round($bx + $bw, 1) . ',' . $by . ' ' . round($bx + $bw, 1) . ',' . round($by + $r, 1) . 'V' . ($top + $ph) . 'Z" fill="' . self::BLUE . '"/>';
			}
			$out .= '</g>';
			$i++;
		}
		// x labels: first, middle, last
		foreach (array_unique([0, (int) floor(($n - 1) / 2), $n - 1]) as $k) {
			$x = $left + ($k + .5) * $slot;
			$out .= '<text x="' . round($x, 1) . '" y="' . ($h - 5) . '" class="y4d-tick" text-anchor="' . ($k === 0 ? 'start' : ($k === $n - 1 ? 'end' : 'middle')) . '">' . esc_html(wp_date('j M', strtotime($keys[$k]))) . '</text>';
		}
		return $out . '</svg>';
	}

	/** Ranked bars in one colour: [label (html), n, sub (html)]; the value at the bar's tip. */
	public static function ranked($rows, $total, $empty) {
		if (!$rows) {
			return '<p class="y4d-empty">' . esc_html($empty) . '</p>';
		}
		$max = max(1, max(array_column($rows, 1)));
		$out = '<ol class="y4d-ranked">';
		foreach ($rows as $r) {
			$out .= '<li data-tip="' . esc_attr(wp_strip_all_tags($r[0]) . ' · ' . number_format_i18n($r[1]) . ($total ? ' · ' . self::pct($r[1], $total) . '%' : '')) . '"><span class="y4d-rk-l">' . $r[0] . '</span>'
				. '<span class="y4d-rk-bar"><i style="width:' . max(1, round(100 * $r[1] / $max, 1)) . '%"></i></span><span class="y4d-rk-n">' . esc_html(number_format_i18n($r[1])) . ($total ? ' <small>' . self::pct($r[1], $total) . '%</small>' : '') . '</span></li>';
		}
		return $out . '</ol>';
	}

	private static function cc_badge($cc) {
		return '<span class="y4d-cc">' . esc_html($cc !== '' ? $cc : '··') . '</span>';
	}

	private static function device_icon($kind) {
		$p = ['phone' => '<path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 2v14h8V4H8Zm4 15.2a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/>',
			'tablet' => '<path d="M5 2h14a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 2v14h14V4H5Zm7 15.2a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/>',
			'desktop' => '<path d="M3 4h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1h-7v2h3v2H7v-2h3v-2H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm1 2v9h16V6H4Z"/>'];
		return isset($p[$kind]) ? '<svg class="y4d-ico" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-label="' . esc_attr($kind) . '">' . $p[$kind] . '</svg>' : '';
	}

	/* ---------------------------------------------------------------- *
	 * The page
	 * ---------------------------------------------------------------- */

	public static function render() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$range = isset($_GET['range']) ? (int) $_GET['range'] : 30;
		self::$days = in_array($range, self::RANGES, true) ? $range : 30;
		$days = self::$days;
		$d = self::data();
		$m = self::members();
		$s = Y4APK_Members::settings();
		$members_link = admin_url('admin.php?page=y4apk-members');
		$review_link = admin_url('admin.php?page=y4apk-review');
		$per = sprintf(_n('vs the day before', 'vs the %d days before', $days, 'y4apk'), $days);
		$new_members = array_sum($d['members_by_day']);
		self::styles();
		?>
		<div class="wrap y4d">
			<header class="y4d-head">
				<div>
					<h1><?php esc_html_e('Dashboard', 'y4apk'); ?></h1>
					<p class="y4d-sub"><?php echo esc_html(sprintf(__('Everything on Y4APK at a glance. Counted %s ago.', 'y4apk'), human_time_diff($d['time']))); ?></p>
				</div>
				<div class="y4d-head-tools">
					<nav class="y4d-range" aria-label="<?php esc_attr_e('Period', 'y4apk'); ?>">
						<?php foreach (self::RANGES as $r) : ?><a href="<?php echo esc_url(add_query_arg('range', $r, admin_url('admin.php?page=' . Y4APK_MENU))); ?>"<?php echo $r === $days ? ' class="is-on" aria-current="true"' : ''; ?>><?php echo esc_html(sprintf(__('%d days', 'y4apk'), $r)); ?></a><?php endforeach; ?>
					</nav>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="y4apk_dash_refresh"><input type="hidden" name="range" value="<?php echo (int) $days; ?>"><?php wp_nonce_field('y4apk_dash_refresh'); ?>
						<button class="button"><?php esc_html_e('Count again', 'y4apk'); ?></button></form>
				</div>
			</header>

			<nav class="y4d-jump">
				<?php foreach (['overview' => __('Overview', 'y4apk'), 'members' => __('Members', 'y4apk'), 'content' => __('What people read', 'y4apk'), 'roblox' => __('Roblox', 'y4apk'), 'app' => __('App & ads', 'y4apk'), 'health' => __('Health', 'y4apk')] as $k => $label) : ?>
					<a href="#y4d-<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ($d['waiting'] || $d['reported'] || $d['unsorted'] || $d['pictures_new']) : ?>
			<div class="y4d-todo">
				<?php if ($d['waiting']) : ?><a href="<?php echo esc_url($review_link); ?>"><b><?php echo esc_html(number_format_i18n($d['waiting'])); ?></b> <?php echo esc_html(_n('script waiting for review', 'scripts waiting for review', $d['waiting'], 'y4apk')); ?> →</a><?php endif; ?>
				<?php if ($d['reported']) : ?><a href="<?php echo esc_url($review_link); ?>"><b><?php echo esc_html(number_format_i18n($d['reported'])); ?></b> <?php echo esc_html(_n('reported script', 'reported scripts', $d['reported'], 'y4apk')); ?> →</a><?php endif; ?>
				<?php if ($d['pictures_new']) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=y4apk-pictures')); ?>"><b><?php echo esc_html(number_format_i18n($d['pictures_new'])); ?></b> <?php echo esc_html(_n('new member picture to look at', 'new member pictures to look at', $d['pictures_new'], 'y4apk')); ?> →</a><?php endif; ?>
				<?php if ($d['unsorted']) : ?><a href="<?php echo esc_url(admin_url('edit.php?page=y4apk-roblox-sort')); ?>"><b><?php echo esc_html(number_format_i18n($d['unsorted'])); ?></b> <?php esc_html_e('Roblox posts not in a game', 'y4apk'); ?> →</a><?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- ================================================= overview -->
			<section id="y4d-overview" class="y4d-kpis">
				<?php
				$tiles = [
					[__('Members', 'y4apk'), self::n($m['total']), self::delta($new_members, $d['members_prev'], sprintf(__('%1$s new · %2$s', 'y4apk'), number_format_i18n($new_members), $per)), self::buckets($d['members_by_day']), '#y4d-members'],
					[__('Active members', 'y4apk'), self::n($m['active_7']), '<span class="y4d-delta">' . esc_html(sprintf(__('seen in 7 days · %s online now', 'y4apk'), number_format_i18n($m['online']))) . '</span>', null, '#y4d-members'],
					[__('Published posts', 'y4apk'), self::n($d['posts']), self::delta(array_sum($d['posts_by_day']), $d['posts_prev'], sprintf(__('%1$s new · %2$s', 'y4apk'), number_format_i18n(array_sum($d['posts_by_day'])), $per)), self::buckets($d['posts_by_day']), admin_url('edit.php')],
					[__('Total views', 'y4apk'), self::short($d['views']), '<span class="y4d-delta">' . esc_html__('counted by browsers, page cache too', 'y4apk') . '</span>', null, '#y4d-content'],
					[__('Scripts from members', 'y4apk'), self::n($d['member_scripts']), self::delta(array_sum($d['submitted_by_day']), $d['submitted_prev'], sprintf(__('%1$s sent · %2$s', 'y4apk'), number_format_i18n(array_sum($d['submitted_by_day'])), $per)), self::buckets($d['submitted_by_day']), $review_link],
					[__('Ad opens', 'y4apk'), self::n($d['ev']['direct_link']), self::delta($d['ev']['direct_link'], $d['ev_prev']['direct_link'], $per), self::buckets($d['ads_by_day']), '#y4d-app'],
					[__('File downloads', 'y4apk'), self::n($d['ev']['file_download']), self::delta($d['ev']['file_download'], $d['ev_prev']['file_download'], $per), self::buckets($d['dl_by_day']), '#y4d-app'],
				];
				foreach ($tiles as $t) : ?>
					<a class="y4d-kpi" href="<?php echo esc_url($t[4]); ?>">
						<span class="y4d-kpi-l"><?php echo esc_html($t[0]); ?></span>
						<span class="y4d-kpi-n"><?php echo esc_html($t[1]); ?></span>
						<span class="y4d-kpi-s"><?php echo wp_kses($t[2], ['span' => ['class' => []]]); ?></span>
						<?php if ($t[3]) { echo self::spark($t[3]); } ?>
					</a>
				<?php endforeach; ?>
			</section>

			<!-- ================================================= members -->
			<h2 id="y4d-members" class="y4d-h"><?php esc_html_e('Members', 'y4apk'); ?> <a href="<?php echo esc_url($members_link); ?>"><?php esc_html_e('Settings', 'y4apk'); ?> →</a></h2>
			<?php self::members_part($d, $m); ?>

			<!-- ================================================= content -->
			<h2 id="y4d-content" class="y4d-h"><?php esc_html_e('What people read', 'y4apk'); ?></h2>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-7">
					<h3><?php esc_html_e('Most read', 'y4apk'); ?> <small><?php esc_html_e('all time', 'y4apk'); ?></small></h3>
					<?php
					$rows = [];
					foreach ($d['top'] as $r) {
						$rows[] = ['<a href="' . esc_url(get_edit_post_link($r['id'])) . '">' . esc_html($r['title']) . '</a>' . ($r['kind'] !== '' ? '<span class="y4d-chip">' . esc_html($r['kind']) . '</span>' : ''), $r['views']];
					}
					echo self::ranked($rows, 0, __('No views counted yet.', 'y4apk'));
					?>
				</div>
				<div class="y4d-card y4d-span-5">
					<h3><?php esc_html_e('Posts published', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('last %d days', 'y4apk'), $days)); ?></small></h3>
					<?php echo self::columns($d['posts_by_day'], __('posts', 'y4apk')); ?>
					<p class="y4d-foot"><?php echo esc_html(sprintf(__('%1$s published · %2$s drafts and waiting', 'y4apk'), number_format_i18n(array_sum($d['posts_by_day'])), number_format_i18n($d['drafts']))); ?></p>
				</div>
			</div>

			<!-- ================================================= roblox -->
			<h2 id="y4d-roblox" class="y4d-h"><?php esc_html_e('Roblox', 'y4apk'); ?></h2>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-7">
					<h3><?php echo esc_html(sprintf(_n('%1$s script in %2$s game', '%1$s scripts in %2$s games', $d['roblox'], 'y4apk'), number_format_i18n($d['roblox']), number_format_i18n($d['games']))); ?></h3>
					<table class="y4d-table"><thead><tr><th><?php esc_html_e('Game', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Scripts', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Views', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Playing now', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Likes', 'y4apk'); ?></th></tr></thead><tbody>
						<?php foreach ($d['top_games'] as $g) : ?>
						<tr><td><a class="y4d-game" href="<?php echo esc_url($g['url']); ?>" target="_blank"><img src="<?php echo esc_url($g['icon'], ['http', 'https', 'data']); ?>" alt="" width="28" height="28" loading="lazy"><?php echo esc_html($g['name']); ?></a></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($g['scripts'])); ?></td><td class="y4d-num"><?php echo esc_html(self::short($g['views'])); ?></td><td class="y4d-num"><?php echo esc_html($g['playing'] !== null ? self::short($g['playing']) : '—'); ?></td><td class="y4d-num"><?php echo esc_html($g['likes'] !== null ? $g['likes'] . '%' : '—'); ?></td></tr>
						<?php endforeach; if (!$d['top_games']) : ?><tr><td colspan="5"><?php esc_html_e('No games yet.', 'y4apk'); ?></td></tr><?php endif; ?>
					</tbody></table>
				</div>
				<div class="y4d-card y4d-span-5">
					<h3><?php esc_html_e('Scripts sent by members', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('last %d days', 'y4apk'), $days)); ?></small> <a class="y4d-all" href="<?php echo esc_url($review_link); ?>"><?php esc_html_e('Review', 'y4apk'); ?> →</a></h3>
					<?php echo self::columns($d['submitted_by_day'], __('scripts', 'y4apk')); ?>
					<?php if ($d['pending']) : ?>
					<p class="y4d-label"><?php esc_html_e('Waiting for review', 'y4apk'); ?></p>
					<table class="y4d-table"><tbody>
						<?php foreach ($d['pending'] as $r) : ?><tr><td><?php echo esc_html($r['title']); ?></td><td class="y4d-muted">@<?php echo esc_html($r['by']); ?></td><td class="y4d-num y4d-muted"><?php echo esc_html($r['ago']); ?></td></tr><?php endforeach; ?>
					</tbody></table>
					<?php else : ?><p class="y4d-foot"><?php esc_html_e('Nothing is waiting for review.', 'y4apk'); ?></p><?php endif; ?>
				</div>
			</div>

			<!-- ================================================= app & ads -->
			<h2 id="y4d-app" class="y4d-h"><?php esc_html_e('App & ads', 'y4apk'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=y4apk-analytics')); ?>"><?php esc_html_e('Analytics', 'y4apk'); ?> →</a></h2>
			<div class="y4d-row">
				<?php foreach ([['ads_by_day', __('Ad opens', 'y4apk'), __('ad opens', 'y4apk')], ['dl_by_day', __('File downloads', 'y4apk'), __('downloads', 'y4apk')], ['app_by_day', __('People who opened the app', 'y4apk'), __('people', 'y4apk')]] as $c) : ?>
				<div class="y4d-card y4d-span-4">
					<h3><?php echo esc_html($c[1]); ?> <small><?php echo esc_html(sprintf(__('last %d days', 'y4apk'), $days)); ?></small></h3>
					<?php echo self::columns($d[$c[0]], $c[2]); ?>
					<p class="y4d-foot"><?php echo $d['stats'] ? esc_html(sprintf(__('%s in all', 'y4apk'), number_format_i18n(array_sum($d[$c[0]])))) : esc_html__('Analytics has not counted anything yet', 'y4apk'); ?></p>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- ================================================= health -->
			<h2 id="y4d-health" class="y4d-h"><?php esc_html_e('How the parts of the site are doing', 'y4apk'); ?></h2>
			<?php self::health_part($d, $s, $members_link); ?>
		</div>
		<div class="y4d-tip" role="tooltip" hidden></div>
		<?php
		self::script();
	}

	/** The members part: totals, the journey, where they come from, countries, devices, when, who follows whom, who matters. */
	private static function members_part($d, $m) {
		$total = max(0, $m['total']);
		$days = self::$days;
		$names = Y4APK_Insights::source_names();
		?>
		<div class="y4d-row">
			<div class="y4d-card y4d-span-8">
				<h3><?php esc_html_e('New members', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('per day, last %d days', 'y4apk'), $days)); ?></small></h3>
				<?php echo self::columns($d['members_by_day'], __('new members', 'y4apk')); ?>
				<p class="y4d-foot"><?php echo esc_html(sprintf(__('%1$s joined in %2$d days; %3$s in the %2$d days before.', 'y4apk'), number_format_i18n(array_sum($d['members_by_day'])), $days, number_format_i18n($d['members_prev']))); ?></p>
			</div>
			<div class="y4d-card y4d-span-4 y4d-health-card">
				<h3><?php esc_html_e('Right now', 'y4apk'); ?></h3>
				<p class="y4d-hero"><?php echo esc_html(number_format_i18n($total)); ?> <span><?php echo esc_html(_n('member', 'members', $total, 'y4apk')); ?></span></p>
				<?php echo self::spark($m['growth']); ?>
				<p class="y4d-foot"><?php esc_html_e('Total members, the last 12 weeks', 'y4apk'); ?></p>
				<dl class="y4d-facts">
					<div><dt><i class="y4d-dot y4d-on"></i><?php esc_html_e('Online now', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($m['online'])); ?></dd></div>
					<div><dt><?php esc_html_e('Seen today', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($m['active_1'])); ?></dd></div>
					<div><dt><?php esc_html_e('Seen in 7 days', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($m['active_7'])); ?> <small><?php echo esc_html(self::pct($m['active_7'], $total)); ?>%</small></dd></div>
					<div><dt><?php esc_html_e('Seen in 30 days', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($m['active_30'])); ?> <small><?php echo esc_html(self::pct($m['active_30'], $total)); ?>%</small></dd></div>
					<div><dt><i class="y4d-dot y4d-warn"></i><a href="<?php echo esc_url(admin_url('admin.php?page=y4apk-members&show=pending')); ?>"><?php esc_html_e('Email not confirmed', 'y4apk'); ?></a></dt><dd><?php echo esc_html(number_format_i18n($m['unverified'])); ?></dd></div>
					<div><dt><i class="y4d-dot y4d-bad"></i><?php esc_html_e('Banned', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($m['banned'])); ?></dd></div>
				</dl>
			</div>
		</div>

		<div class="y4d-row">
			<div class="y4d-card y4d-span-12">
				<h3><?php esc_html_e('The members’ journey', 'y4apk'); ?> <small><?php esc_html_e('how far members get, from signing up to a script others follow', 'y4apk'); ?></small></h3>
				<?php
				$steps = [
					[__('Signed up', 'y4apk'), $total, __('made an account', 'y4apk')],
					[__('Confirmed email', 'y4apk'), max(0, $total - $m['unverified']), __('opened the link we emailed', 'y4apk')],
					[__('Shared a script', 'y4apk'), $m['shared'], __('sent at least one', 'y4apk')],
					[__('Has a live script', 'y4apk'), $m['live'], __('at least one approved', 'y4apk')],
					[__('Has followers', 'y4apk'), $m['followed'], __('someone follows them', 'y4apk')],
				];
				?>
				<ol class="y4d-flow">
					<?php foreach ($steps as $i => $st) : $share = self::pct($st[1], $total); $from = $i ? self::pct($st[1], $steps[$i - 1][1]) : 100; ?>
					<li data-tip="<?php echo esc_attr($st[0] . ' · ' . number_format_i18n($st[1]) . ' · ' . $share . '% ' . __('of all members', 'y4apk')); ?>">
						<?php if ($i) : ?><span class="y4d-flow-arrow" aria-hidden="true"><b><?php echo esc_html($from); ?>%</b><small><?php esc_html_e('go on', 'y4apk'); ?></small></span><?php endif; ?>
						<span class="y4d-flow-node">
							<span class="y4d-flow-n"><?php echo esc_html(number_format_i18n($st[1])); ?></span>
							<span class="y4d-flow-l"><?php echo esc_html($st[0]); ?></span>
							<span class="y4d-flow-s"><?php echo esc_html($st[2]); ?></span>
							<span class="y4d-flow-bar"><i style="width:<?php echo esc_attr(max(1, $share)); ?>%;background:<?php echo esc_attr(self::ORD[min($i, 4)]); ?>"></i></span>
							<span class="y4d-flow-p"><?php echo esc_html(sprintf(__('%d%% of members', 'y4apk'), $share)); ?></span>
						</span>
					</li>
					<?php endforeach; ?>
				</ol>
				<?php
				// The biggest drop, said in words
				$drop = null;
				for ($i = 1; $i < count($steps); $i++) {
					$lost = $steps[$i - 1][1] - $steps[$i][1];
					if ($steps[$i - 1][1] > 0 && ($drop === null || $lost > $drop[0])) {
						$drop = [$lost, $i];
					}
				}
				if ($drop && $drop[0] > 0) : ?>
				<p class="y4d-insight"><b><?php esc_html_e('Biggest drop:', 'y4apk'); ?></b> <?php echo esc_html(sprintf(__('%1$s members stop between “%2$s” and “%3$s”.', 'y4apk'), number_format_i18n($drop[0]), $steps[$drop[1] - 1][0], $steps[$drop[1]][0])); ?>
					<?php echo esc_html([1 => __('Remind them to confirm: Members ▸ resend from their sign-in page, or check that the emails arrive (Send myself a test email).', 'y4apk'), 2 => __('Show “Share a script” more: on game pages and after sign-up.', 'y4apk'), 3 => __('Review waiting scripts sooner, and tell members what to change.', 'y4apk'), 4 => __('Members find each other on profiles: link creators from script pages.', 'y4apk')][$drop[1]]); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="y4d-row">
			<div class="y4d-card y4d-span-7">
				<h3><?php esc_html_e('Where members come from', 'y4apk'); ?> <small><?php esc_html_e('the site that sent them, the visit they signed up in', 'y4apk'); ?></small></h3>
				<?php
				$src = $m['by_source'];
				arsort($src);
				$unknown = max(0, $total - array_sum($src));
				if (count($src) > 6) { // six branches at most: the smallest fold into one
					$rest = array_sum(array_slice($src, 5, null, true));
					$src = array_slice($src, 0, 5, true);
					$src['other'] = ($src['other'] ?? 0) + $rest;
				}
				if ($unknown) {
					$src[''] = $unknown;
				}
				?>
				<div class="y4d-tree">
					<div class="y4d-tree-root"><b><?php echo esc_html(number_format_i18n($total)); ?></b><span><?php esc_html_e('members', 'y4apk'); ?></span></div>
					<ul class="y4d-tree-kids">
						<?php foreach ($src as $k => $n) : ?>
						<li class="<?php echo $k === '' ? 'is-none' : ''; ?>" data-tip="<?php echo esc_attr((isset($names[$k]) ? $names[$k] : $k) . ' · ' . number_format_i18n($n) . ' · ' . self::pct($n, $total) . '%'); ?>">
							<span class="y4d-tree-node"><span class="y4d-tree-name"><?php echo esc_html(isset($names[$k]) ? $names[$k] : $k); ?></span><b><?php echo esc_html(number_format_i18n($n)); ?></b><small><?php echo esc_html(self::pct($n, $total)); ?>%</small><span class="y4d-tree-bar"><i style="width:<?php echo esc_attr(max(2, self::pct($n, $total))); ?>%"></i></span></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="y4d-two">
					<div>
						<p class="y4d-label"><?php esc_html_e('Websites that sent members', 'y4apk'); ?></p>
						<?php $rows = []; foreach ($m['by_ref'] as $host => $n) { $rows[] = [esc_html($host), $n]; } echo self::ranked(array_slice($rows, 0, 6), 0, __('None recorded yet.', 'y4apk')); ?>
					</div>
					<div>
						<p class="y4d-label"><?php esc_html_e('First page of the visit', 'y4apk'); ?></p>
						<?php $rows = []; foreach ($m['by_land'] as $path => $n) { $rows[] = ['<code>' . esc_html($path) . '</code>', $n]; } echo self::ranked(array_slice($rows, 0, 6), 0, __('None recorded yet.', 'y4apk')); ?>
					</div>
				</div>
				<?php if ($unknown) : ?><p class="y4d-foot"><?php echo esc_html(sprintf(__('“Not recorded”: %s members who joined before the site started noting it (Y4APK 2.43).', 'y4apk'), number_format_i18n($unknown))); ?></p><?php endif; ?>
			</div>
			<div class="y4d-card y4d-span-5">
				<h3><?php esc_html_e('Countries', 'y4apk'); ?> <small><?php echo esc_html(sprintf(_n('%s country', '%s countries', count($m['by_cc']), 'y4apk'), number_format_i18n(count($m['by_cc'])))); ?></small></h3>
				<?php
				$rows = [];
				foreach (array_slice($m['by_cc'], 0, 10, true) as $cc => $n) {
					$rows[] = [self::cc_badge($cc) . esc_html(Y4APK_Insights::country_name($cc)), $n];
				}
				$rest = array_sum(array_slice($m['by_cc'], 10, null, true));
				if ($rest) {
					$rows[] = [self::cc_badge('+') . esc_html__('Other countries', 'y4apk'), $rest];
				}
				$no_cc = max(0, $total - array_sum($m['by_cc']));
				if ($no_cc) {
					$rows[] = [self::cc_badge('') . esc_html__('Not recorded', 'y4apk'), $no_cc];
				}
				echo self::ranked($rows, $total, __('No countries recorded yet: they are noted from the next sign-up and sign-in.', 'y4apk'));
				?>
				<?php if ($m['cc_guess']) : ?><p class="y4d-foot"><?php echo esc_html(sprintf(__('%s of these come from the browser’s language, not Cloudflare: a guess.', 'y4apk'), number_format_i18n($m['cc_guess']))); ?></p><?php endif; ?>
			</div>
		</div>

		<div class="y4d-row">
			<div class="y4d-card y4d-span-5">
				<h3><?php esc_html_e('Devices', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('%s members recorded', 'y4apk'), number_format_i18n($m['recorded_device']))); ?></small></h3>
				<?php
				$kinds = ['phone' => [__('Phone', 'y4apk'), self::BLUE], 'desktop' => [__('Computer', 'y4apk'), self::ORANGE], 'tablet' => [__('Tablet', 'y4apk'), self::AQUA]];
				$dev_total = max(1, array_sum(array_intersect_key($m['by_device'], $kinds)));
				?>
				<?php if ($m['recorded_device']) : ?>
				<div class="y4d-seg" role="img" aria-label="<?php esc_attr_e('Devices', 'y4apk'); ?>">
					<?php foreach ($kinds as $k => $kd) : $n = $m['by_device'][$k] ?? 0; if (!$n) { continue; } $p = round(100 * $n / $dev_total, 1); ?>
					<span style="flex:<?php echo esc_attr($p); ?>;background:<?php echo esc_attr($kd[1]); ?>" data-tip="<?php echo esc_attr($kd[0] . ' · ' . number_format_i18n($n) . ' · ' . round($p) . '%'); ?>"><?php echo $p >= 14 ? esc_html(round($p) . '%') : ''; ?></span>
					<?php endforeach; ?>
				</div>
				<ul class="y4d-legend">
					<?php foreach ($kinds as $k => $kd) : $n = $m['by_device'][$k] ?? 0; ?><li><i style="background:<?php echo esc_attr($kd[1]); ?>"></i><?php echo self::device_icon($k); ?><?php echo esc_html($kd[0]); ?> <b><?php echo esc_html(number_format_i18n($n)); ?></b> <small><?php echo esc_html(round(100 * $n / $dev_total)); ?>%</small></li><?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<div class="y4d-two">
					<div><p class="y4d-label"><?php esc_html_e('System', 'y4apk'); ?></p>
						<?php $rows = []; foreach ($m['by_os'] as $k => $n) { $rows[] = [esc_html($k), $n]; } echo self::ranked($rows, max(1, $m['recorded_device']), __('Not recorded yet.', 'y4apk')); ?></div>
					<div><p class="y4d-label"><?php esc_html_e('Browser', 'y4apk'); ?></p>
						<?php $rows = []; foreach (array_slice($m['by_browser'], 0, 7, true) as $k => $n) { $rows[] = [esc_html($k), $n]; } echo self::ranked($rows, max(1, $m['recorded_device']), __('Not recorded yet.', 'y4apk')); ?></div>
				</div>
				<?php $apk = $m['by_app']['apk'] ?? 0; $pwa = $m['by_app']['pwa'] ?? 0; ?>
				<p class="y4d-insight"><b><?php esc_html_e('In the Y4APK app:', 'y4apk'); ?></b> <?php echo esc_html(sprintf(__('%1$s signed up in the installed app, %2$s from the home-screen web app, the rest in a browser.', 'y4apk'), number_format_i18n($apk), number_format_i18n($pwa))); ?></p>
			</div>
			<div class="y4d-card y4d-span-7">
				<h3><?php esc_html_e('When members join', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('day and hour, %s time', 'y4apk'), wp_timezone_string())); ?></small></h3>
				<?php echo self::heatmap($m['heat']); ?>
			</div>
		</div>

		<div class="y4d-row">
			<div class="y4d-card y4d-span-7">
				<h3><?php esc_html_e('Who follows whom', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('%1$s follows between %2$s members · a bigger dot has more followers', 'y4apk'), number_format_i18n($m['follows']), number_format_i18n(count($m['net']['nodes'])))); ?></small></h3>
				<?php if (count($m['net']['nodes']) > 1) : ?>
				<canvas class="y4d-net" data-net="<?php echo esc_attr(wp_json_encode($m['net'])); ?>" data-color="<?php echo esc_attr(self::BLUE); ?>" height="380" aria-label="<?php esc_attr_e('A map of members and who follows whom', 'y4apk'); ?>"></canvas>
				<p class="y4d-foot"><?php esc_html_e('Drag a dot to move it. The names shown are the most followed.', 'y4apk'); ?></p>
				<?php else : ?><p class="y4d-empty"><?php esc_html_e('No follows yet. When members follow each other, the map of who follows whom shows here.', 'y4apk'); ?></p><?php endif; ?>
			</div>
			<div class="y4d-card y4d-span-5">
				<h3><?php esc_html_e('Members who matter most', 'y4apk'); ?> <small><?php esc_html_e('views of their live scripts', 'y4apk'); ?></small></h3>
				<?php if (!$m['top']) : ?><p class="y4d-empty"><?php esc_html_e('No live scripts from members yet.', 'y4apk'); ?></p><?php else : ?>
				<table class="y4d-table"><thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Live', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Views', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Followers', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Seen', 'y4apk'); ?></th></tr></thead><tbody>
					<?php foreach ($m['top'] as $r) : ?>
					<tr><td><a href="<?php echo esc_url(Y4APK_Members::profile_url(get_userdata($r['id']))); ?>" target="_blank"><?php echo self::cc_badge($r['o']['cc']); ?>@<?php echo esc_html($r['login']); ?></a> <?php echo self::device_icon($r['o']['device']); ?></td>
						<td class="y4d-num"><?php echo esc_html(number_format_i18n($r['live'])); ?></td><td class="y4d-num"><?php echo esc_html(self::short($r['views'])); ?></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($r['fans'])); ?></td>
						<td class="y4d-num y4d-muted"><?php echo esc_html($r['seen'] ? human_time_diff($r['seen']) : '—'); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table><?php endif; ?>
			</div>
		</div>

		<div class="y4d-row">
			<div class="y4d-card y4d-span-12">
				<h3><?php esc_html_e('Newest members', 'y4apk'); ?></h3>
				<?php if (!$m['newest']) : ?><p class="y4d-empty"><?php esc_html_e('No members yet.', 'y4apk'); ?></p><?php else : ?>
				<table class="y4d-table"><thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('Country', 'y4apk'); ?></th><th><?php esc_html_e('Device', 'y4apk'); ?></th><th><?php esc_html_e('Came from', 'y4apk'); ?></th><th><?php esc_html_e('First page', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Scripts', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Joined', 'y4apk'); ?></th></tr></thead><tbody>
					<?php foreach ($m['newest'] as $r) : $o = $r['o']; ?>
					<tr><td><a href="<?php echo esc_url(get_edit_user_link($r['id'])); ?>">@<?php echo esc_html($r['login']); ?></a><?php if ($r['banned']) : ?><span class="y4d-chip y4d-chip-bad"><?php esc_html_e('banned', 'y4apk'); ?></span><?php elseif (!$r['verified']) : ?><span class="y4d-chip y4d-chip-warn"><?php esc_html_e('unconfirmed', 'y4apk'); ?></span><?php endif; ?></td>
						<td><?php echo self::cc_badge($o['cc']); ?><?php echo esc_html($o['cc'] !== '' ? Y4APK_Insights::country_name($o['cc']) : '—'); ?></td>
						<td><?php echo $o['device'] !== '' ? self::device_icon($o['device']) . esc_html(trim($o['os'] . ' · ' . $o['browser'], ' ·')) . ($o['app'] !== '' ? '<span class="y4d-chip">' . esc_html($o['app'] === 'apk' ? __('Y4APK app', 'y4apk') : __('web app', 'y4apk')) . '</span>' : '') : '<span class="y4d-muted">—</span>'; ?></td>
						<td><?php echo esc_html($o['source'] !== '' ? ($names[$o['source']] ?? $o['source']) . ($o['ref'] !== '' && !in_array($o['source'], ['google', 'youtube', 'tiktok', 'facebook'], true) ? ' (' . $o['ref'] . ')' : '') : '—'); ?></td>
						<td class="y4d-path"><?php echo $o['land'] !== '' ? '<code>' . esc_html($o['land']) . '</code>' : '<span class="y4d-muted">—</span>'; ?></td>
						<td class="y4d-num"><?php echo esc_html(number_format_i18n($r['scripts'])); ?></td><td class="y4d-num y4d-muted"><?php echo esc_html(human_time_diff($r['joined'])); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Sign-ups by weekday × hour: one blue ramp, light → dark, with its scale. */
	private static function heatmap($heat) {
		$max = 0;
		foreach ($heat as $row) {
			$max = max($max, max($row));
		}
		$days = [__('Mon', 'y4apk'), __('Tue', 'y4apk'), __('Wed', 'y4apk'), __('Thu', 'y4apk'), __('Fri', 'y4apk'), __('Sat', 'y4apk'), __('Sun', 'y4apk')];
		$out = '<div class="y4d-heat" role="img" aria-label="' . esc_attr__('Sign-ups by day and hour', 'y4apk') . '"><span></span>';
		for ($h = 0; $h < 24; $h++) {
			$out .= '<span class="y4d-heat-h">' . ($h % 3 === 0 ? esc_html(sprintf('%02d', $h)) : '') . '</span>';
		}
		$busiest = [0, 0, -1];
		foreach ($heat as $dw => $row) {
			$out .= '<span class="y4d-heat-d">' . esc_html($days[$dw]) . '</span>';
			foreach ($row as $h => $n) {
				if ($n > $busiest[2]) {
					$busiest = [$dw, $h, $n];
				}
				$step = $n > 0 && $max > 0 ? min(6, (int) floor(6 * $n / $max)) : -1;
				$out .= '<i data-tip="' . esc_attr($days[$dw] . ' ' . sprintf('%02d:00', $h) . ' · ' . number_format_i18n($n) . ' ' . __('sign-ups', 'y4apk')) . '"' . ($step >= 0 ? ' style="background:' . self::RAMP[$step] . '"' : '') . '></i>';
			}
		}
		$out .= '</div><div class="y4d-scale"><span>' . esc_html__('fewer', 'y4apk') . '</span>';
		foreach (self::RAMP as $c) {
			$out .= '<i style="background:' . $c . '"></i>';
		}
		$out .= '<span>' . esc_html__('more', 'y4apk') . '</span></div>';
		if ($busiest[2] > 0) {
			$out .= '<p class="y4d-insight"><b>' . esc_html__('Busiest:', 'y4apk') . '</b> ' . esc_html(sprintf(__('%1$s around %2$s. A good time for new posts and Discord or Facebook updates.', 'y4apk'), $days[$busiest[0]], sprintf('%02d:00', $busiest[1]))) . '</p>';
		}
		return $out;
	}

	private static function health_part($d, $s, $members_link) {
		$names_bad = count(array_filter(Y4APK_Members::public_names(), function ($r) { return !$r['ok']; }));
		$checks = get_transient('y4apk_members_checks');
		$check_bad = $checks ? count(array_filter($checks['rows'], function ($r) { return $r[1] === false; })) : 0;
		$sec = [
			[__('Public names of the team', 'y4apk'), $names_bad ? 'warn' : 'ok', $names_bad ? sprintf(_n('%s account prints its sign-in name', '%s accounts print their sign-in names', $names_bad, 'y4apk'), $names_bad) : __('the site’s name is shown', 'y4apk')],
			[__('Stranger check', 'y4apk'), !$checks ? 'idle' : ($check_bad ? 'warn' : 'ok'), !$checks ? __('not run yet', 'y4apk') : ($check_bad ? sprintf(_n('%s thing to fix', '%s things to fix', $check_bad, 'y4apk'), $check_bad) : sprintf(__('all clear, %s ago', 'y4apk'), human_time_diff($checks['time'])))],
			[__('Bot protection', 'y4apk'), Y4APK_Members::turnstile_on() ? 'ok' : ($s['on'] ? 'bad' : 'warn'), Y4APK_Members::turnstile_on() ? __('Turnstile on', 'y4apk') : __('Turnstile not set', 'y4apk')],
			[__('Registration', 'y4apk'), $s['on'] ? 'on' : 'idle', $s['on'] ? __('open', 'y4apk') : __('closed', 'y4apk')],
		];
		$r2 = class_exists('Y4APK_R2') && Y4APK_R2::ready();
		$dl = Y4APK_Direct_Link::settings();
		$links = Y4APK_Direct_Link::links();
		$health = [
			[__('Write with AI', 'y4apk'), $d['ai']['paused'] ? 'bad' : ($d['ai']['wait'] ? 'warn' : 'ok'),
				$d['ai']['paused'] ? __('paused: needs your attention', 'y4apk') : ($d['ai']['wait'] ? __('waiting for the AI’s limit', 'y4apk') : sprintf(_n('%s post in the queue', '%s posts in the queue', $d['ai']['queue'], 'y4apk'), number_format_i18n($d['ai']['queue']))),
				sprintf(__('%1$s written · %2$sK tokens', 'y4apk'), number_format_i18n($d['ai']['posts']), number_format_i18n(round($d['ai']['tokens'] / 1000))), admin_url('admin.php?page=y4apk-writer')],
			[__('App updates', 'y4apk'), $d['needs_update'] ? 'warn' : 'ok', $d['needs_update'] ? sprintf(_n('%s app has a newer version', '%s apps have a newer version', $d['needs_update'], 'y4apk'), number_format_i18n($d['needs_update'])) : __('everything is up to date', 'y4apk'), '', admin_url('admin.php?page=y4apk-app-updates')],
			[__('File storage (R2)', 'y4apk'), $r2 ? 'ok' : 'idle', $r2 ? __('connected', 'y4apk') : __('not set up', 'y4apk'), '', admin_url('admin.php?page=y4apk-app-updates')],
			[__('Direct Link ads', 'y4apk'), ($dl['mode'] === 'off' && empty($dl['script_lock'])) || !$links ? 'idle' : 'ok', !$links ? __('no link set', 'y4apk') : sprintf(_n('%1$s link · on download buttons: %2$s', '%1$s links · on download buttons: %2$s', count($links), 'y4apk'), count($links), $dl['mode'] === 'off' ? __('off', 'y4apk') : $dl['mode']),
				!empty($dl['script_lock']) ? __('script unlock: on', 'y4apk') : __('script unlock: off', 'y4apk'), admin_url('admin.php?page=y4apk-direct-link')],
			[__('The app', 'y4apk'), $d['ev']['app_daily'] || $d['ev']['pwa_installed'] ? 'ok' : 'idle', sprintf(__('%s installs in the period', 'y4apk'), number_format_i18n($d['ev']['banner_tap'] + $d['ev']['pwa_installed'])), sprintf(__('%s people opened it', 'y4apk'), number_format_i18n($d['ev']['app_daily'])), admin_url('admin.php?page=y4apk-analytics')],
		];
		$labels = ['ok' => __('fine', 'y4apk'), 'warn' => __('look at it', 'y4apk'), 'bad' => __('needs you', 'y4apk'), 'on' => __('on', 'y4apk'), 'idle' => __('off', 'y4apk')];
		?>
		<div class="y4d-health-grid">
			<?php foreach ($health as $h) : ?>
				<a class="y4d-card y4d-health" href="<?php echo esc_url($h[4]); ?>"><span class="y4d-h-l"><i class="y4d-dot y4d-<?php echo esc_attr($h[1]); ?>" title="<?php echo esc_attr($labels[$h[1]]); ?>"></i><?php echo esc_html($h[0]); ?><em class="y4d-state y4d-state-<?php echo esc_attr($h[1]); ?>"><?php echo esc_html($labels[$h[1]]); ?></em></span><span class="y4d-h-s"><?php echo esc_html($h[2]); ?></span><?php if ($h[3] !== '') : ?><span class="y4d-h-m"><?php echo esc_html($h[3]); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
			<a class="y4d-card y4d-health y4d-sec" href="<?php echo esc_url($members_link); ?>"><span class="y4d-h-l"><i class="y4d-dot y4d-<?php echo $names_bad || $check_bad || ($s['on'] && !Y4APK_Members::turnstile_on()) ? 'warn' : 'ok'; ?>"></i><?php esc_html_e('Privacy and safety', 'y4apk'); ?></span>
				<?php foreach ($sec as $row) : ?><span class="y4d-h-row"><i class="y4d-dot y4d-<?php echo esc_attr($row[1]); ?>"></i><b><?php echo esc_html($row[0]); ?></b> <?php echo esc_html($row[2]); ?></span><?php endforeach; ?></a>
		</div>
		<?php
	}

	public static function styles() {
		echo '<style>
#wpcontent{padding-left:0}#wpbody-content{padding-bottom:40px}
.y4d{--ink:#1d2327;--ink2:#50575e;--mute:#8c8f94;--line:#ebeae4;--card:#fff;--blue:' . self::BLUE . ';margin:0;padding:20px 24px 0;max-width:none;color:var(--ink);font-size:13px}
.y4d *{box-sizing:border-box}
.y4d-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 20px;margin-bottom:12px}
.y4d-head h1{margin:0;padding:0;font-size:26px;font-weight:700;line-height:1.2}.y4d-sub{margin:4px 0 0;color:var(--ink2)}
.y4d-head-tools{display:flex;align-items:center;gap:10px}.y4d-head-tools form{margin:0}
.y4d-range{display:flex;padding:3px;border-radius:10px;background:#e8eaed}.y4d-range a{padding:6px 12px;border-radius:8px;color:var(--ink2);font-weight:600;text-decoration:none}.y4d-range a.is-on{color:var(--ink);background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.y4d-jump{position:sticky;top:32px;z-index:5;display:flex;flex-wrap:wrap;gap:4px;margin:0 -24px 16px;padding:8px 24px;background:rgba(240,240,241,.92);backdrop-filter:blur(6px);border-bottom:1px solid #dcdcde}
.y4d-jump a{padding:5px 12px;border-radius:99px;color:var(--ink2);font-weight:600;text-decoration:none}.y4d-jump a:hover{color:var(--ink);background:#fff}
.y4d-todo{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.y4d-todo a{display:inline-flex;gap:6px;align-items:center;padding:8px 14px;border-radius:99px;background:#fff4de;color:#7a4d00;text-decoration:none;font-weight:600;border:1px solid #f3dca8}.y4d-todo a:hover{background:#ffeab8}.y4d-todo b{font-size:15px}
.y4d-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:8px}
.y4d-kpi{position:relative;display:flex;flex-direction:column;gap:3px;min-height:128px;padding:14px 16px 46px;background:var(--card);border:1px solid #dcdcde;border-radius:14px;text-decoration:none;color:inherit;overflow:hidden;transition:box-shadow .15s,border-color .15s}
.y4d-kpi:hover{border-color:var(--blue);box-shadow:0 8px 20px -12px rgba(42,120,214,.6)}
.y4d-kpi-l{font-size:12px;font-weight:600;color:var(--ink2)}.y4d-kpi-n{font-size:30px;font-weight:700;line-height:1.1;color:var(--ink)}.y4d-kpi-s{font-size:12px;color:var(--mute);overflow-wrap:anywhere}
.y4d-spark{position:absolute;left:0;right:0;bottom:0;width:100%;height:40px}.y4d-health-card .y4d-spark{position:static;height:48px;margin-top:4px}
.y4d-delta{color:var(--mute)}.y4d-delta-up{color:#006300;font-weight:700}.y4d-delta-down{color:#b32d2e;font-weight:700}
.y4d-h{display:flex;align-items:baseline;gap:12px;margin:28px 0 12px!important;font-size:18px!important;font-weight:700;scroll-margin-top:90px}.y4d-h a{font-size:13px;font-weight:600}
.y4d-row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px;margin-bottom:12px}
.y4d-span-4{grid-column:span 4}.y4d-span-5{grid-column:span 5}.y4d-span-7{grid-column:span 7}.y4d-span-8{grid-column:span 8}.y4d-span-12{grid-column:span 12}
@media (max-width:1400px){.y4d-span-5,.y4d-span-7{grid-column:span 6}}
@media (max-width:1100px){.y4d-row>*{grid-column:span 12!important}}
@media (max-width:782px){.y4d-table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch}.y4d-card{min-width:0}}
.y4d-card{min-width:0;padding:16px 18px;background:var(--card);border:1px solid #dcdcde;border-radius:14px}
.y4d-card h3{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 8px;margin:0 0 12px;font-size:14px;color:var(--ink)}.y4d-card h3 small{font-weight:400;color:var(--mute)}.y4d-all{margin-left:auto;font-size:12px;font-weight:600}
.y4d-cols{display:block;width:100%;height:auto;max-height:240px}.y4d-tick{font-size:10px;fill:var(--mute);font-variant-numeric:tabular-nums}
.y4d-hit:hover path{fill:#1c5cab}.y4d-foot{margin:8px 0 0;font-size:12px;color:var(--ink2)}.y4d-label{margin:14px 0 6px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute)}
.y4d-empty{margin:0;color:var(--mute)}.y4d-muted{color:var(--mute)}
.y4d-insight{margin:12px 0 0;padding:10px 12px;border-radius:10px;background:#f1f6fd;color:var(--ink2);line-height:1.55}.y4d-insight b{color:var(--ink)}
.y4d-hero{margin:0;font-size:48px;font-weight:700;line-height:1}.y4d-hero span{font-size:15px;font-weight:600;color:var(--ink2)}
.y4d-facts{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin:14px 0 0}.y4d-facts div{display:flex;justify-content:space-between;gap:8px;padding-top:8px;border-top:1px solid var(--line)}.y4d-facts dt{display:flex;align-items:center;gap:6px;color:var(--ink2)}.y4d-facts dd{margin:0;font-weight:700;font-variant-numeric:tabular-nums}.y4d-facts small{font-weight:400;color:var(--mute)}
.y4d-ranked{margin:0;padding:0;list-style:none;display:grid;gap:6px}.y4d-ranked li{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(60px,1fr) auto;align-items:center;gap:10px;min-height:24px}
.y4d-rk-l{display:flex;align-items:center;gap:6px;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.y4d-rk-l a{overflow:hidden;text-overflow:ellipsis}.y4d-rk-l code{font-size:11px;background:none;padding:0}
.y4d-rk-bar{height:10px}.y4d-rk-bar i{display:block;height:100%;border-radius:0 4px 4px 0;background:var(--blue)}.y4d-ranked li:hover .y4d-rk-bar i{background:#1c5cab}
.y4d-rk-n{font-weight:700;font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}.y4d-rk-n small{font-weight:400;color:var(--mute)}
.y4d-cc{display:inline-grid;place-items:center;flex:none;min-width:24px;height:16px;padding:0 3px;margin-right:4px;border-radius:4px;font-size:10px;font-weight:700;letter-spacing:.03em;color:#3c434a;background:#eef0f2;box-shadow:inset 0 0 0 1px rgba(0,0,0,.06)}
.y4d-ico{flex:none;vertical-align:-2px;margin-right:4px;color:var(--ink2)}
.y4d-chip{margin-left:6px;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600;background:#e8f1fb;color:#184f95;white-space:nowrap}.y4d-chip-warn{background:#fff4de;color:#7a4d00}.y4d-chip-bad{background:#fdeeee;color:#8f1d22}
.y4d-table{width:100%;border-collapse:collapse}.y4d-table th{padding:0 8px 6px 0;text-align:left;font-size:11px;font-weight:700;color:var(--mute);text-transform:uppercase;white-space:nowrap}.y4d-table td{padding:7px 8px 7px 0;border-top:1px solid var(--line);vertical-align:middle}.y4d-table tbody tr:first-child td{border-top:0}
.y4d-num,.y4d-table th.y4d-num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.y4d-path code{font-size:11px;background:none;padding:0;overflow-wrap:anywhere}
.y4d-game{display:inline-flex;align-items:center;gap:8px}.y4d-game img{border-radius:6px;object-fit:cover}
.y4d-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:4px 20px}
/* the journey */
.y4d-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:0;margin:4px 0 0;padding:0;list-style:none}
.y4d-flow li{position:relative;display:flex;align-items:stretch}
.y4d-flow-node{flex:1;display:flex;flex-direction:column;gap:2px;padding:12px 14px;margin-left:34px;border-radius:12px;background:#f6f8fb;box-shadow:inset 0 0 0 1px #e3e8ef}
.y4d-flow li:first-child .y4d-flow-node{margin-left:0}
.y4d-flow-arrow{position:absolute;left:0;top:50%;transform:translateY(-50%);display:grid;justify-items:center;width:34px;font-size:11px;color:var(--mute);line-height:1.1}.y4d-flow-arrow b{color:var(--ink);font-size:12px}
.y4d-flow-arrow::before{content:"";display:block;width:16px;height:2px;margin-bottom:3px;background:#c3c2b7;border-radius:1px}
.y4d-flow-n{font-size:24px;font-weight:700;line-height:1.1}.y4d-flow-l{font-weight:700}.y4d-flow-s{font-size:12px;color:var(--mute)}
.y4d-flow-bar{height:8px;margin-top:8px;border-radius:4px;background:#e3e8ef;overflow:hidden}.y4d-flow-bar i{display:block;height:100%;border-radius:0 4px 4px 0}.y4d-flow-p{font-size:11px;color:var(--ink2)}
@media (max-width:1100px){.y4d-flow{grid-template-columns:minmax(0,1fr)}.y4d-flow-node{margin:30px 0 0}.y4d-flow li:first-child .y4d-flow-node{margin-top:0}.y4d-flow-arrow{top:0;left:50%;transform:translateX(-50%);display:flex;gap:4px;width:auto}.y4d-flow-arrow::before{display:none}}
/* where they come from: a tree */
.y4d-tree{display:grid;justify-items:center;margin:4px 0 6px}
.y4d-tree-root{display:grid;justify-items:center;padding:10px 22px;border-radius:14px;color:#fff;background:var(--blue);box-shadow:0 8px 18px -10px rgba(42,120,214,.8)}.y4d-tree-root b{font-size:22px;line-height:1.1}.y4d-tree-root span{font-size:12px;opacity:.9}
.y4d-tree-kids{position:relative;display:flex;flex-wrap:wrap;justify-content:center;gap:16px 10px;width:100%;margin:22px 0 0;padding:16px 0 0;list-style:none}
.y4d-tree-kids::before{content:"";position:absolute;left:50%;top:-22px;width:2px;height:22px;margin-left:-1px;background:#c3c2b7}
.y4d-tree-kids::after{content:"";position:absolute;left:8%;right:8%;top:0;height:2px;background:#c3c2b7;border-radius:1px}
.y4d-tree-kids li{position:relative;flex:1 1 0;min-width:92px;max-width:150px}
.y4d-tree-kids li::before{content:"";position:absolute;left:50%;top:-16px;width:2px;height:16px;margin-left:-1px;background:#c3c2b7}
.y4d-tree-node{display:grid;gap:1px;padding:9px 11px;border-radius:11px;background:#f6f8fb;box-shadow:inset 0 0 0 1px #e3e8ef;text-align:center}
.y4d-tree-name{font-size:12px;font-weight:600;color:var(--ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.y4d-tree-node b{font-size:18px;line-height:1.2}.y4d-tree-node small{color:var(--mute)}
.y4d-tree-bar{height:6px;margin-top:4px;border-radius:3px;background:#e3e8ef;overflow:hidden}.y4d-tree-bar i{display:block;height:100%;background:var(--blue);border-radius:0 3px 3px 0}
.y4d-tree-kids li.is-none .y4d-tree-node{background:#fafafa;box-shadow:inset 0 0 0 1px #ececec}.y4d-tree-kids li.is-none .y4d-tree-bar i{background:#c3c4c7}
/* devices */
.y4d-seg{display:flex;gap:2px;height:28px;margin:2px 0 10px}.y4d-seg span{display:flex;align-items:center;padding:0 8px;border-radius:4px;color:#fff;font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;min-width:2px}
.y4d-legend{display:flex;flex-wrap:wrap;gap:6px 16px;margin:0 0 6px;padding:0;list-style:none}.y4d-legend li{display:flex;align-items:center;gap:4px;color:var(--ink2)}.y4d-legend i{width:10px;height:10px;border-radius:3px;margin-right:2px}.y4d-legend b{color:var(--ink)}.y4d-legend small{color:var(--mute)}
/* when */
.y4d-heat{display:grid;grid-template-columns:34px repeat(24,minmax(0,1fr));gap:3px;align-items:center}.y4d-heat i{display:block;aspect-ratio:1;min-height:12px;border-radius:3px;background:#f2f3f4}.y4d-heat i:hover{outline:2px solid var(--ink);outline-offset:-1px}
.y4d-heat-h{font-size:10px;color:var(--mute);text-align:center}.y4d-heat-d{font-size:11px;color:var(--ink2)}
.y4d-scale{display:flex;align-items:center;gap:3px;margin-top:10px;font-size:11px;color:var(--mute)}.y4d-scale i{width:18px;height:10px;border-radius:2px}.y4d-scale span{margin:0 4px}
/* who follows whom */
.y4d-net{display:block;width:100%;height:380px;border-radius:12px;background:radial-gradient(circle at 50% 45%,#f6f9fe,#fff 70%);cursor:grab}
/* health */
.y4d-health-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.y4d-health{display:flex;flex-direction:column;gap:4px;text-decoration:none;color:inherit}a.y4d-health:hover{border-color:var(--blue)}
.y4d-h-l{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--ink)}.y4d-h-s{color:#3c434a}.y4d-h-m{font-size:12px;color:var(--mute)}
.y4d-state{margin-left:auto;padding:1px 8px;border-radius:99px;font-size:11px;font-style:normal;font-weight:700;background:#f0f0f1;color:var(--ink2)}.y4d-state-ok{background:#e7f6e7;color:#006300}.y4d-state-warn{background:#fff4de;color:#7a4d00}.y4d-state-bad{background:#fdeeee;color:#8f1d22}.y4d-state-on{background:#e8f1fb;color:#184f95}
.y4d-h-row{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink2)}.y4d-h-row b{color:#3c434a;font-weight:600}
.y4d-dot{display:inline-block;flex:none;width:9px;height:9px;border-radius:50%;background:#c3c4c7}.y4d-ok{background:#0ca30c}.y4d-warn{background:#fab219}.y4d-bad{background:#d03b3b}.y4d-on{background:var(--blue)}.y4d-idle{background:#c3c4c7}.y4d-ok,.y4d-bad,.y4d-on{position:relative}.y4d-ok::after,.y4d-bad::after,.y4d-on::after{content:"";position:absolute;inset:0;border-radius:50%;background:inherit;animation:y4pulse 1.8s ease-out infinite;pointer-events:none}.y4d-bad::after{animation-duration:1.4s}@keyframes y4pulse{0%{transform:scale(1);opacity:.75}80%,100%{transform:scale(2.8);opacity:0}}@media (prefers-reduced-motion:reduce){.y4d-dot::after{animation:none;opacity:0}}
/* the tooltip */
.y4d-tip{position:fixed;z-index:100000;max-width:280px;padding:6px 10px;border-radius:8px;background:#1d2327;color:#fff;font-size:12px;line-height:1.4;pointer-events:none;box-shadow:0 8px 20px -8px rgba(0,0,0,.5)}
@media (max-width:782px){.y4d{padding:12px 12px 0}.y4d-jump{top:46px;margin:0 -12px 12px;padding:8px 12px}.y4d-facts{grid-template-columns:1fr}.y4d-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.y4d-kpi{min-height:112px;padding:12px 12px 40px}.y4d-kpi-n{font-size:24px}}
</style>';
	}

	/** The tooltip on everything with data-tip, and the follow map (a small force layout; no library). */
	public static function script() {
		?>
		<script>
		(function () {
			var tip = document.querySelector('.y4d-tip');
			document.addEventListener('mousemove', function (e) {
				var t = e.target.closest && e.target.closest('[data-tip]');
				if (!t) { tip.hidden = true; return; }
				tip.textContent = t.getAttribute('data-tip'); tip.hidden = false;
				var x = e.clientX + 14, y = e.clientY + 16, w = tip.offsetWidth;
				if (x + w > innerWidth - 8) { x = e.clientX - w - 14; }
				tip.style.left = x + 'px'; tip.style.top = y + 'px';
			});
			// Who follows whom
			[].forEach.call(document.querySelectorAll('canvas[data-net]'), function (cv) {
				var data = JSON.parse(cv.getAttribute('data-net')), color = cv.getAttribute('data-color'), ctx = cv.getContext('2d');
				var dpr = window.devicePixelRatio || 1, W, H;
				var byId = {}, nodes = data.nodes.map(function (n, i) { var a = i * 2.39996; var o = {id: n.id, name: n.n, f: n.f, x: Math.cos(a) * (40 + i * 3), y: Math.sin(a) * (40 + i * 3), vx: 0, vy: 0}; byId[n.id] = o; return o; });
				var links = data.links.map(function (l) { return [byId[l[0]], byId[l[1]]]; }).filter(function (l) { return l[0] && l[1]; });
				var maxF = Math.max.apply(null, nodes.map(function (n) { return n.f; }).concat([1]));
				nodes.forEach(function (n) { n.r = 4 + 12 * Math.sqrt(n.f / maxF); });
				var named = nodes.slice().sort(function (a, b) { return b.f - a.f; }).slice(0, 8);
				var size = function () { W = cv.clientWidth; H = cv.clientHeight; cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); };
				size(); window.addEventListener('resize', size);
				var heat = 1, drag = null, hover = null;
				var step = function () {
					if (heat < 0.002 && !drag) { return; }
					for (var i = 0; i < nodes.length; i++) {
						var a = nodes[i];
						for (var j = i + 1; j < nodes.length; j++) {
							var b = nodes[j], dx = a.x - b.x, dy = a.y - b.y, d2 = dx * dx + dy * dy + 0.01, d = Math.sqrt(d2), f = 2600 / d2, gap = a.r + b.r + 14;
							if (d < gap) { f += (gap - d) * 0.6 / d; } // no two dots on top of each other
							a.vx += dx * f * 0.02; a.vy += dy * f * 0.02; b.vx -= dx * f * 0.02; b.vy -= dy * f * 0.02;
						}
						a.vx -= a.x * 0.0025; a.vy -= a.y * 0.0025;
					}
					links.forEach(function (l) { var dx = l[1].x - l[0].x, dy = l[1].y - l[0].y, d = Math.sqrt(dx * dx + dy * dy) || 1, k = (d - 90) * 0.004; l[0].vx += dx / d * k * 10; l[0].vy += dy / d * k * 10; l[1].vx -= dx / d * k * 10; l[1].vy -= dy / d * k * 10; });
					nodes.forEach(function (n) { if (n === drag) { return; } n.x += n.vx * heat; n.y += n.vy * heat; n.vx *= 0.6; n.vy *= 0.6; });
					// dots never overlap: pushed apart directly, a few rounds
					for (var round = 0; round < 3; round++) {
						for (var p = 0; p < nodes.length; p++) {
							for (var q = p + 1; q < nodes.length; q++) {
								var u = nodes[p], w = nodes[q], ex = w.x - u.x, ey = w.y - u.y, dd = Math.sqrt(ex * ex + ey * ey) || 0.01, need = u.r + w.r + 10;
								if (dd < need) { var push = (need - dd) / 2 / dd; if (u !== drag) { u.x -= ex * push; u.y -= ey * push; } if (w !== drag) { w.x += ex * push; w.y += ey * push; } }
							}
						}
					}
					heat *= 0.985;
				};
				var draw = function () {
					step();
					ctx.clearRect(0, 0, W, H);
					var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
					nodes.forEach(function (n) { minX = Math.min(minX, n.x - n.r); maxX = Math.max(maxX, n.x + n.r); minY = Math.min(minY, n.y - n.r); maxY = Math.max(maxY, n.y + n.r); });
					var s = Math.min((W - 60) / (maxX - minX || 1), (H - 50) / (maxY - minY || 1), 2.2), ox = W / 2 - (minX + maxX) / 2 * s, oy = H / 2 - (minY + maxY) / 2 * s;
					cv.view = {s: s, ox: ox, oy: oy};
					ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(82,81,78,.22)';
					links.forEach(function (l) { ctx.beginPath(); ctx.moveTo(l[0].x * s + ox, l[0].y * s + oy); ctx.lineTo(l[1].x * s + ox, l[1].y * s + oy); ctx.stroke(); });
					nodes.forEach(function (n) {
						ctx.beginPath(); ctx.arc(n.x * s + ox, n.y * s + oy, n.r, 0, 7);
						ctx.fillStyle = n === hover ? '#184f95' : color; ctx.globalAlpha = named.indexOf(n) !== -1 || n === hover ? 1 : 0.55; ctx.fill(); ctx.globalAlpha = 1;
						ctx.lineWidth = 2; ctx.strokeStyle = '#fff'; ctx.stroke();
					});
					ctx.font = '600 11px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif'; ctx.fillStyle = '#1d2327'; ctx.textAlign = 'center';
					ctx.lineJoin = 'round'; ctx.lineWidth = 4; ctx.strokeStyle = 'rgba(255,255,255,.92)';
					var boxes = [], free = function (b) { return !boxes.some(function (o) { return b.x < o.x + o.w && o.x < b.x + b.w && b.y < o.y + o.h && o.y < b.y + b.h; }); };
					(hover ? [hover] : []).concat(named.filter(function (n) { return n !== hover; })).forEach(function (n) {
						var t = n.name + (n.f ? ' · ' + n.f : ''), w = ctx.measureText(t).width + 6, x = n.x * s + ox, cy = n.y * s + oy;
						var spots = [cy - n.r - 6, cy + n.r + 15]; // above the dot, else below it; else only on hover
						for (var k = 0; k < spots.length; k++) {
							var b = {x: x - w / 2, y: spots[k] - 11, w: w, h: 14};
							if (n === hover || free(b)) { boxes.push(b); ctx.strokeText(t, x, spots[k]); ctx.fillText(t, x, spots[k]); break; }
						}
					});
					requestAnimationFrame(draw);
				};
				var at = function (e) {
					var r = cv.getBoundingClientRect(), v = cv.view, x = (e.clientX - r.left - v.ox) / v.s, y = (e.clientY - r.top - v.oy) / v.s, best = null, bd = Infinity;
					nodes.forEach(function (n) { var d = Math.hypot(n.x - x, n.y - y) * v.s; if (d < n.r + 6 && d < bd) { bd = d; best = n; } });
					return {n: best, x: x, y: y};
				};
				cv.addEventListener('mousedown', function (e) { var h = at(e); if (h.n) { drag = h.n; heat = Math.max(heat, 0.4); cv.style.cursor = 'grabbing'; } });
				window.addEventListener('mouseup', function () { drag = null; cv.style.cursor = 'grab'; });
				cv.addEventListener('mousemove', function (e) {
					var h = at(e); hover = h.n;
					if (drag) { drag.x = h.x; drag.y = h.y; drag.vx = drag.vy = 0; heat = Math.max(heat, 0.3); }
					cv.setAttribute('data-tip', hover ? hover.name + ' · ' + hover.f + ' followers' : '');
					if (!hover) { cv.removeAttribute('data-tip'); }
				});
				draw();
			});
		})();
		</script>
		<?php
	}
}

Y4APK_Dashboard::init();
