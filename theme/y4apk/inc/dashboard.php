<?php
/**
 * Y4APK ▸ Dashboard: the first page of the Y4APK menu. One look at the whole
 * site: the posts and Roblox scripts, members and what waits for review,
 * views, the app and the ads, the AI writer, App updates, R2, and how well
 * the accounts are kept out of sight. Every number links to where you act on it.
 *
 * Counted in the database from what the theme already keeps; nothing is sent
 * anywhere. The numbers are kept 5 minutes (the page says how old they are).
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Dashboard {

	const CACHE = 'y4apk_dash_data';
	const DAYS  = 30;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu'], 8);
		add_action('admin_post_y4apk_dash_refresh', [__CLASS__, 'refresh']);
	}

	public static function menu() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M6 2H18C19.1 2 20 2.9 20 4V7.32C20 7.87 19.55 8.32 19 8.32H5C4.45 8.32 4 7.87 4 7.32V4C4 2.9 4.9 2 6 2Z" fill="#292D32"/><path d="M4 10.3203V11.8803C4 12.9603 4.58 13.9603 5.53 14.4903L8.49 16.1603C9.12 16.5103 9.51 17.1803 9.51 17.9003V20.0003C9.51 21.1003 10.41 22.0003 11.51 22.0003H12.51C13.61 22.0003 14.51 21.1003 14.51 20.0003V17.9003C14.51 17.1803 14.9 16.5103 15.53 16.1603L18.49 14.4903C19.43 13.9603 20.02 12.9603 20.02 11.8803V10.3203C20.02 9.77031 19.57 9.32031 19.02 9.32031H5C4.45 9.32031 4 9.76031 4 10.3203Z" fill="#292D32"/></svg>';
		add_menu_page('Y4APK', 'Y4APK', 'manage_options', Y4APK_MENU, [__CLASS__, 'render'], 'data:image/svg+xml;base64,' . base64_encode($svg), 82);
		add_submenu_page(Y4APK_MENU, __('Dashboard', 'y4apk'), __('Dashboard', 'y4apk'), 'manage_options', Y4APK_MENU, [__CLASS__, 'render']);
	}

	public static function refresh() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_dash_refresh');
		delete_transient(self::CACHE);
		delete_transient('y4apk_review_counts');
		wp_safe_redirect(admin_url('admin.php?page=' . Y4APK_MENU));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * The numbers
	 * ---------------------------------------------------------------- */

	/** One value per day for the last DAYS days: [Y-m-d => n], from rows of [day, n]. */
	private static function fill($rows) {
		$out = [];
		$start = strtotime(wp_date('Y-m-d', time() - (self::DAYS - 1) * DAY_IN_SECONDS));
		for ($i = 0; $i < self::DAYS; $i++) {
			$out[gmdate('Y-m-d', $start + $i * DAY_IN_SECONDS)] = 0;
		}
		foreach ($rows as $r) {
			if (isset($out[$r->day])) {
				$out[$r->day] = (int) $r->n;
			}
		}
		return $out;
	}

	public static function data($fresh = false) {
		$d = get_transient(self::CACHE);
		if (!$fresh && is_array($d)) {
			return $d;
		}
		global $wpdb;
		$since = gmdate('Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS);
		$d = ['time' => time()];

		// The site
		$counts = wp_count_posts('post');
		$d['posts'] = (int) $counts->publish;
		$d['drafts'] = (int) $counts->draft + (int) $counts->pending;
		$d['views'] = (int) $wpdb->get_var("SELECT SUM(m.meta_value + 0) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'wpb_post_views_count' AND p.post_status = 'publish'");
		$d['posts_by_day'] = self::fill($wpdb->get_results($wpdb->prepare("SELECT DATE(post_date) AS day, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt > %s GROUP BY DATE(post_date)", $since)));

		// Roblox
		$q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => Y4APK_Roblox::only_roblox()]);
		$d['roblox'] = (int) $q->found_posts;
		$d['games'] = count(Y4APK_Roblox::games());
		$d['top_games'] = [];
		foreach (array_slice(Y4APK_Roblox::games(), 0, 6) as $g) {
			$t = Y4APK_Roblox::totals($g);
			$d['top_games'][] = ['name' => $g->name, 'url' => get_term_link($g), 'scripts' => (int) $g->count, 'views' => $t['views'], 'playing' => Y4APK_Roblox::stats($g)['playing']];
		}
		$d['unsorted'] = 0;
		if (Y4APK_Roblox::categories()) {
			$d['unsorted'] = (int) (new WP_Query(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending'], 'posts_per_page' => 1, 'fields' => 'ids',
				'tax_query' => ['relation' => 'AND', ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => Y4APK_Roblox::categories(), 'include_children' => false], ['taxonomy' => Y4APK_Roblox::TAX, 'operator' => 'NOT EXISTS']]]))->found_posts;
		}

		// Members
		$role = Y4APK_Members::ROLE;
		$d['members'] = (int) (new WP_User_Query(['role' => $role, 'fields' => 'ID', 'count_total' => true, 'number' => 1]))->get_total();
		$d['members_unverified'] = (int) (new WP_User_Query(['role' => $role, 'fields' => 'ID', 'count_total' => true, 'number' => 1, 'meta_key' => Y4APK_Members::VERIFIED, 'meta_value' => '0']))->get_total();
		$d['members_banned'] = (int) (new WP_User_Query(['role' => $role, 'fields' => 'ID', 'count_total' => true, 'number' => 1, 'meta_key' => Y4APK_Members::BANNED, 'meta_value' => '1']))->get_total();
		$d['members_week'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE u.user_registered > %s", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like($role) . '%', gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
		$d['members_prev_week'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE u.user_registered BETWEEN %s AND %s", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like($role) . '%', gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
		$d['members_by_day'] = self::fill($wpdb->get_results($wpdb->prepare("SELECT DATE(u.user_registered) AS day, COUNT(*) AS n FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE u.user_registered > %s GROUP BY DATE(u.user_registered)", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like($role) . '%', $since)));
		$d['submitted_by_day'] = self::fill($wpdb->get_results($wpdb->prepare("SELECT DATE(p.post_date) AS day, COUNT(*) AS n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'post' AND p.post_status <> 'trash' AND p.post_date > %s GROUP BY DATE(p.post_date)", Y4APK_Members::MEMBER_POST, wp_date('Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS))));
		$d['member_scripts'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'post' AND p.post_status = 'publish'", Y4APK_Members::MEMBER_POST));
		list($d['waiting'], $d['reported']) = Y4APK_Members_Admin::counts();
		$d['newest'] = [];
		foreach (get_users(['role' => $role, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 6]) as $u) {
			$d['newest'][] = ['id' => $u->ID, 'login' => $u->user_login, 'joined' => $u->user_registered, 'verified' => Y4APK_Members::is_verified($u->ID), 'banned' => (bool) get_user_meta($u->ID, Y4APK_Members::BANNED, true),
				'scripts' => count_user_posts($u->ID, 'post', true)];
		}
		$d['pending'] = [];
		foreach (get_posts(['post_type' => 'post', 'post_status' => 'pending', 'meta_key' => Y4APK_Members::MEMBER_POST, 'numberposts' => 5, 'orderby' => 'date', 'order' => 'ASC']) as $p) {
			$u = get_userdata($p->post_author);
			$d['pending'][] = ['title' => get_the_title($p), 'by' => $u ? $u->user_login : '', 'ago' => human_time_diff(get_post_datetime($p)->getTimestamp())];
		}

		// What people read
		$d['top'] = [];
		foreach (get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 8, 'meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC']) as $p) {
			$g = Y4APK_Roblox::game_of($p->ID);
			$d['top'][] = ['id' => $p->ID, 'title' => wp_strip_all_tags(get_the_title($p)), 'views' => (int) get_post_meta($p->ID, 'wpb_post_views_count', true), 'kind' => $g ? $g->name : (Y4APK_Roblox::is_roblox_post($p->ID) ? 'Roblox' : ''), 'url' => get_permalink($p)];
		}

		// The app and the ads (Y4APK ▸ Analytics keeps these per day)
		$t = Y4APK_Stats::table();
		$has = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
		$d['stats'] = $has;
		$d['ev'] = ['direct_link' => 0, 'file_download' => 0, 'app_daily' => 0, 'pwa_installed' => 0, 'banner_tap' => 0];
		$d['ads_by_day'] = $d['dl_by_day'] = self::fill([]);
		if ($has) {
			$from = wp_date('Y-m-d', time() - (self::DAYS - 1) * DAY_IN_SECONDS);
			foreach ($wpdb->get_results($wpdb->prepare("SELECT event, SUM(n) AS n FROM $t WHERE day >= %s AND event IN ('direct_link','file_download','app_daily','pwa_installed','banner_tap') GROUP BY event", $from)) as $r) {
				$d['ev'][$r->event] = (int) $r->n;
			}
			$d['ads_by_day'] = self::fill($wpdb->get_results($wpdb->prepare("SELECT day, SUM(n) AS n FROM $t WHERE day >= %s AND event = 'direct_link' GROUP BY day", $from)));
			$d['dl_by_day'] = self::fill($wpdb->get_results($wpdb->prepare("SELECT day, SUM(n) AS n FROM $t WHERE day >= %s AND event = 'file_download' GROUP BY day", $from)));
		}

		// The other parts of the site
		$d['needs_update'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_status = 'publish'", Y4APK_App_Updates::NEEDS));
		$usage = get_option(Y4APK_Writer::USAGE, []);
		$d['ai'] = ['queue' => count(Y4APK_Writer::queue()), 'posts' => is_array($usage) && isset($usage['posts']) ? (int) $usage['posts'] : 0, 'tokens' => is_array($usage) ? (int) ($usage['in'] ?? 0) + (int) ($usage['out'] ?? 0) : 0,
			'wait' => Y4APK_Writer::waiting(), 'paused' => Y4APK_Writer::settings()['paused'] !== ''];
		return $d;
	}

	/* ---------------------------------------------------------------- *
	 * Drawing
	 * ---------------------------------------------------------------- */

	private static function bars($series, $color, $noun) {
		$w = 300; $h = 72; $n = count($series);
		$max = max(1, max($series));
		$slot = $w / $n;
		$bw = max(2, $slot - 2);
		$out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="y4d-bars" role="img" aria-label="' . esc_attr($noun) . '" preserveAspectRatio="none">';
		$i = 0;
		foreach ($series as $day => $v) {
			$bh = $v > 0 ? max(2, ($h - 6) * $v / $max) : 1;
			$out .= '<rect x="' . round($i * $slot + ($slot - $bw) / 2, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" rx="1.5" fill="' . ($v > 0 ? $color : '#dcdcde') . '"><title>'
				. esc_html(wp_date('M j', strtotime($day)) . ': ' . number_format_i18n($v) . ' ' . $noun) . '</title></rect>';
			$i++;
		}
		return $out . '</svg>';
	}

	private static function delta($now, $before) {
		if ($now === 0 && $before === 0) {
			return '';
		}
		if ($before === 0) {
			return '<span class="y4d-up">▲ ' . esc_html(sprintf(__('%d new this week', 'y4apk'), $now)) . '</span>';
		}
		$pct = round(100 * ($now - $before) / $before);
		return '<span class="' . ($pct >= 0 ? 'y4d-up">▲ ' : 'y4d-down">▼ ') . esc_html(abs($pct) . '% ' . __('vs last week', 'y4apk')) . '</span>';
	}

	private static function dot($state) {
		return '<i class="y4d-dot y4d-' . esc_attr($state) . '"></i>';
	}

	/* ---------------------------------------------------------------- *
	 * The page
	 * ---------------------------------------------------------------- */

	public static function render() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$d = self::data();
		$s = Y4APK_Members::settings();
		$names_bad = count(array_filter(Y4APK_Members::public_names(), function ($r) { return !$r['ok']; }));
		$checks = get_transient('y4apk_members_checks');
		$check_bad = $checks ? count(array_filter($checks['rows'], function ($r) { return $r[1] === false; })) : 0;
		$sec = [
			[__('Public names of the team', 'y4apk'), $names_bad ? 'warn' : 'ok', $names_bad ? sprintf(_n('%s account prints its sign-in name', '%s accounts print their sign-in names', $names_bad, 'y4apk'), $names_bad) : __('the site’s name is shown', 'y4apk')],
			[__('Stranger check', 'y4apk'), !$checks ? 'idle' : ($check_bad ? 'warn' : 'ok'), !$checks ? __('not run yet', 'y4apk') : ($check_bad ? sprintf(_n('%s thing to fix', '%s things to fix', $check_bad, 'y4apk'), $check_bad) : sprintf(__('all clear, %s ago', 'y4apk'), human_time_diff($checks['time'])))],
			[__('Bot protection', 'y4apk'), Y4APK_Members::turnstile_on() ? 'ok' : ($s['on'] ? 'bad' : 'warn'), Y4APK_Members::turnstile_on() ? __('Turnstile on', 'y4apk') : __('Turnstile not set', 'y4apk')],
			[__('Registration', 'y4apk'), $s['on'] ? 'on' : 'idle', $s['on'] ? __('open', 'y4apk') : __('closed', 'y4apk')],
		];
		$ads_total = array_sum($d['ads_by_day']);
		$members_link = admin_url('admin.php?page=y4apk-members');
		$review_link = admin_url('admin.php?page=y4apk-review');
		self::styles();
		?>
		<div class="wrap y4d">
			<div class="y4d-top">
				<div><h1><?php esc_html_e('Dashboard', 'y4apk'); ?></h1>
					<p class="y4d-sub"><?php echo esc_html(sprintf(__('Everything on the site at a glance. Counted %s ago.', 'y4apk'), human_time_diff($d['time']))); ?></p></div>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="y4apk_dash_refresh"><?php wp_nonce_field('y4apk_dash_refresh'); ?>
					<button class="button"><?php esc_html_e('Count again', 'y4apk'); ?></button></form>
			</div>

			<?php if ($d['waiting'] || $d['reported'] || $d['unsorted']) : ?>
			<div class="y4d-todo">
				<?php if ($d['waiting']) : ?><a href="<?php echo esc_url($review_link); ?>"><b><?php echo esc_html(number_format_i18n($d['waiting'])); ?></b> <?php echo esc_html(_n('script waiting for review', 'scripts waiting for review', $d['waiting'], 'y4apk')); ?> →</a><?php endif; ?>
				<?php if ($d['reported']) : ?><a href="<?php echo esc_url($review_link); ?>"><b><?php echo esc_html(number_format_i18n($d['reported'])); ?></b> <?php echo esc_html(_n('reported script', 'reported scripts', $d['reported'], 'y4apk')); ?> →</a><?php endif; ?>
				<?php if ($d['unsorted']) : ?><a href="<?php echo esc_url(admin_url('edit.php?page=y4apk-roblox-sort')); ?>"><b><?php echo esc_html(number_format_i18n($d['unsorted'])); ?></b> <?php esc_html_e('Roblox posts not in a game', 'y4apk'); ?> →</a><?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="y4d-kpis">
				<?php
				$tiles = [
					[__('Published posts', 'y4apk'), $d['posts'], sprintf(__('%s drafts and waiting', 'y4apk'), number_format_i18n($d['drafts'])), admin_url('edit.php')],
					[__('Total views', 'y4apk'), Y4APK_Roblox::short_number($d['views']), __('counted on the site, not in the page cache', 'y4apk'), ''],
					[__('Roblox scripts', 'y4apk'), $d['roblox'], sprintf(_n('in %s game', 'in %s games', $d['games'], 'y4apk'), number_format_i18n($d['games'])), admin_url('edit-tags.php?taxonomy=' . Y4APK_Roblox::TAX)],
					[__('Members', 'y4apk'), $d['members'], self::delta($d['members_week'], $d['members_prev_week']) ?: sprintf(__('%s unconfirmed', 'y4apk'), $d['members_unverified']), $members_link],
					[__('From members', 'y4apk'), $d['member_scripts'], sprintf(__('%s waiting for review', 'y4apk'), number_format_i18n($d['waiting'])), $review_link],
					[__('Ad opens, 30 days', 'y4apk'), number_format_i18n($ads_total), sprintf(__('%s file downloads', 'y4apk'), number_format_i18n($d['ev']['file_download'])), admin_url('admin.php?page=y4apk-analytics')],
				];
				foreach ($tiles as $t) :
					$tag = $t[3] !== '' ? 'a href="' . esc_url($t[3]) . '"' : 'div'; ?>
					<<?php echo $tag; ?> class="y4d-kpi"><span class="y4d-kpi-l"><?php echo esc_html($t[0]); ?></span><span class="y4d-kpi-n"><?php echo esc_html(is_int($t[1]) ? number_format_i18n($t[1]) : $t[1]); ?></span><span class="y4d-kpi-s"><?php echo wp_kses($t[2], ['span' => ['class' => []]]); ?></span></<?php echo $t[3] !== '' ? 'a' : 'div'; ?>>
				<?php endforeach; ?>
			</div>

			<div class="y4d-grid y4d-grid-3">
				<div class="y4d-card"><h2><?php esc_html_e('New members', 'y4apk'); ?> <small><?php esc_html_e('30 days', 'y4apk'); ?></small></h2><?php echo self::bars($d['members_by_day'], '#29b1d8', __('members', 'y4apk')); ?><p class="y4d-foot"><?php echo esc_html(sprintf(__('%s in 30 days', 'y4apk'), number_format_i18n(array_sum($d['members_by_day'])))); ?></p></div>
				<div class="y4d-card"><h2><?php esc_html_e('Scripts sent by members', 'y4apk'); ?> <small><?php esc_html_e('30 days', 'y4apk'); ?></small></h2><?php echo self::bars($d['submitted_by_day'], '#3fc35a', __('scripts', 'y4apk')); ?><p class="y4d-foot"><?php echo esc_html(sprintf(__('%s in 30 days', 'y4apk'), number_format_i18n(array_sum($d['submitted_by_day'])))); ?></p></div>
				<div class="y4d-card"><h2><?php esc_html_e('Ad opens', 'y4apk'); ?> <small><?php esc_html_e('30 days', 'y4apk'); ?></small></h2><?php echo self::bars($d['ads_by_day'], '#f0a020', __('ad opens', 'y4apk')); ?><p class="y4d-foot"><?php echo $d['stats'] ? esc_html(sprintf(__('%s in 30 days', 'y4apk'), number_format_i18n($ads_total))) : esc_html__('Analytics has not counted anything yet', 'y4apk'); ?></p></div>
			</div>

			<div class="y4d-grid y4d-grid-2">
				<div class="y4d-card">
					<h2><?php esc_html_e('Most read', 'y4apk'); ?></h2>
					<table class="y4d-table"><tbody>
						<?php foreach ($d['top'] as $i => $r) : ?>
						<tr><td class="y4d-rank"><?php echo esc_html($i + 1); ?></td><td><a href="<?php echo esc_url(get_edit_post_link($r['id'])); ?>"><?php echo esc_html($r['title']); ?></a><?php if ($r['kind'] !== '') : ?><span class="y4d-chip"><?php echo esc_html($r['kind']); ?></span><?php endif; ?></td><td class="y4d-num"><?php echo esc_html(Y4APK_Roblox::short_number($r['views'])); ?></td></tr>
						<?php endforeach; if (!$d['top']) : ?><tr><td><?php esc_html_e('No views counted yet.', 'y4apk'); ?></td></tr><?php endif; ?>
					</tbody></table>
				</div>
				<div class="y4d-card">
					<h2><?php esc_html_e('Roblox games', 'y4apk'); ?></h2>
					<table class="y4d-table"><thead><tr><th><?php esc_html_e('Game', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Scripts', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Views', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Playing', 'y4apk'); ?></th></tr></thead><tbody>
						<?php foreach ($d['top_games'] as $g) : ?>
						<tr><td><a href="<?php echo esc_url($g['url']); ?>" target="_blank"><?php echo esc_html($g['name']); ?></a></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($g['scripts'])); ?></td><td class="y4d-num"><?php echo esc_html(Y4APK_Roblox::short_number($g['views'])); ?></td><td class="y4d-num"><?php echo esc_html($g['playing'] !== null ? Y4APK_Roblox::short_number($g['playing']) : '—'); ?></td></tr>
						<?php endforeach; if (!$d['top_games']) : ?><tr><td colspan="4"><?php esc_html_e('No games yet.', 'y4apk'); ?></td></tr><?php endif; ?>
					</tbody></table>
				</div>
			</div>

			<div class="y4d-grid y4d-grid-2">
				<div class="y4d-card">
					<h2><?php esc_html_e('Waiting for review', 'y4apk'); ?> <a class="y4d-all" href="<?php echo esc_url($review_link); ?>"><?php esc_html_e('Open Review', 'y4apk'); ?> →</a></h2>
					<?php if (!$d['pending']) : ?><p class="y4d-empty"><?php esc_html_e('Nothing is waiting.', 'y4apk'); ?></p><?php else : ?>
					<table class="y4d-table"><tbody>
						<?php foreach ($d['pending'] as $r) : ?><tr><td><?php echo esc_html($r['title']); ?></td><td class="y4d-muted">@<?php echo esc_html($r['by']); ?></td><td class="y4d-num y4d-muted"><?php echo esc_html($r['ago']); ?></td></tr><?php endforeach; ?>
					</tbody></table><?php endif; ?>
				</div>
				<div class="y4d-card">
					<h2><?php esc_html_e('Newest members', 'y4apk'); ?> <a class="y4d-all" href="<?php echo esc_url($members_link); ?>"><?php esc_html_e('Settings', 'y4apk'); ?> →</a></h2>
					<?php if (!$d['newest']) : ?><p class="y4d-empty"><?php echo esc_html($s['on'] ? __('No members yet.', 'y4apk') : __('Registration is closed. Open it in Members when you are ready.', 'y4apk')); ?></p><?php else : ?>
					<table class="y4d-table"><tbody>
						<?php foreach ($d['newest'] as $m) : ?><tr><td>@<?php echo esc_html($m['login']); ?><?php if ($m['banned']) : ?><span class="y4d-chip y4d-chip-bad"><?php esc_html_e('banned', 'y4apk'); ?></span><?php elseif (!$m['verified']) : ?><span class="y4d-chip y4d-chip-warn"><?php esc_html_e('unconfirmed', 'y4apk'); ?></span><?php endif; ?></td>
							<td class="y4d-num"><?php echo esc_html(sprintf(_n('%s script', '%s scripts', $m['scripts'], 'y4apk'), $m['scripts'])); ?></td><td class="y4d-num y4d-muted"><?php echo esc_html(human_time_diff(strtotime($m['joined'] . ' UTC'))); ?></td></tr><?php endforeach; ?>
					</tbody></table><?php endif; ?>
				</div>
			</div>

			<h2 class="y4d-h"><?php esc_html_e('How the parts of the site are doing', 'y4apk'); ?></h2>
			<div class="y4d-grid y4d-grid-4">
				<?php
				$r2 = class_exists('Y4APK_R2') && Y4APK_R2::ready();
				$dl = Y4APK_Direct_Link::settings();
				$health = [
					[__('Write with AI', 'y4apk'), $d['ai']['paused'] ? 'bad' : ($d['ai']['wait'] ? 'warn' : 'ok'),
						$d['ai']['paused'] ? __('paused: needs your attention', 'y4apk') : ($d['ai']['wait'] ? __('waiting for the AI’s limit', 'y4apk') : sprintf(_n('%s post in the queue', '%s posts in the queue', $d['ai']['queue'], 'y4apk'), number_format_i18n($d['ai']['queue']))),
						sprintf(__('%1$s written · %2$sK tokens', 'y4apk'), number_format_i18n($d['ai']['posts']), number_format_i18n(round($d['ai']['tokens'] / 1000))), admin_url('admin.php?page=y4apk-writer')],
					[__('App updates', 'y4apk'), $d['needs_update'] ? 'warn' : 'ok', $d['needs_update'] ? sprintf(_n('%s app has a newer version', '%s apps have a newer version', $d['needs_update'], 'y4apk'), number_format_i18n($d['needs_update'])) : __('everything is up to date', 'y4apk'), '', admin_url('admin.php?page=y4apk-app-updates')],
					[__('File storage (R2)', 'y4apk'), $r2 ? 'ok' : 'idle', $r2 ? __('connected', 'y4apk') : __('not set up', 'y4apk'), '', admin_url('admin.php?page=y4apk-app-updates')],
					[__('Direct Link ads', 'y4apk'), ($dl['mode'] === 'off' && empty($dl['script_lock'])) || $dl['url'] === '' ? 'idle' : 'ok', $dl['url'] === '' ? __('no link set', 'y4apk') : sprintf(__('on download buttons: %s', 'y4apk'), $dl['mode'] === 'off' ? __('off', 'y4apk') : $dl['mode']),
						!empty($dl['script_lock']) ? __('script unlock: on', 'y4apk') : __('script unlock: off', 'y4apk'), admin_url('admin.php?page=y4apk-direct-link')],
					[__('The app', 'y4apk'), $d['ev']['app_daily'] || $d['ev']['pwa_installed'] ? 'ok' : 'idle', sprintf(__('%s installs, 30 days', 'y4apk'), number_format_i18n($d['ev']['banner_tap'] + $d['ev']['pwa_installed'])), sprintf(__('%s people opened it', 'y4apk'), number_format_i18n($d['ev']['app_daily'])), admin_url('admin.php?page=y4apk-analytics')],
				];
				foreach ($health as $h) : ?>
					<a class="y4d-card y4d-health" href="<?php echo esc_url($h[4]); ?>"><span class="y4d-h-l"><?php echo self::dot($h[1]); ?><?php echo esc_html($h[0]); ?></span><span class="y4d-h-s"><?php echo esc_html($h[2]); ?></span><?php if ($h[3] !== '') : ?><span class="y4d-h-m"><?php echo esc_html($h[3]); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
				<a class="y4d-card y4d-health y4d-sec" href="<?php echo esc_url($members_link); ?>"><span class="y4d-h-l"><?php echo self::dot($names_bad || $check_bad || ($s['on'] && !Y4APK_Members::turnstile_on()) ? 'warn' : 'ok'); ?><?php esc_html_e('Privacy and safety', 'y4apk'); ?></span>
					<?php foreach ($sec as $row) : ?><span class="y4d-h-row"><?php echo self::dot($row[1]); ?><b><?php echo esc_html($row[0]); ?></b> <?php echo esc_html($row[2]); ?></span><?php endforeach; ?></a>
			</div>
		</div>
		<?php
	}

	private static function styles() {
		echo '<style>
.y4d{max-width:1240px}.y4d h2{font-size:14px;margin:0 0 12px;color:#1d2327}.y4d h2 small{font-weight:400;color:#8c8f94;margin-left:6px}
.y4d-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:.5rem}.y4d-top h1{padding:0}.y4d-sub{margin:.25rem 0 0;color:#646970}
.y4d-todo{display:flex;flex-wrap:wrap;gap:.5rem;margin:.75rem 0}.y4d-todo a{display:inline-flex;gap:.375rem;align-items:center;padding:.5rem .875rem;border-radius:99px;background:#fff4de;color:#7a4d00;text-decoration:none;font-weight:600;border:1px solid #f3dca8}.y4d-todo a:hover{background:#ffeab8}.y4d-todo b{font-size:15px}
.y4d-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:12px 0 16px}
.y4d-kpi{display:flex;flex-direction:column;gap:2px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:12px;text-decoration:none;color:inherit;transition:box-shadow .15s,border-color .15s}
a.y4d-kpi:hover{border-color:#29b1d8;box-shadow:0 6px 16px -8px rgba(41,177,216,.5)}.y4d-kpi-l{font-size:12px;color:#646970}.y4d-kpi-n{font-size:28px;font-weight:700;line-height:1.15;color:#1d2327}.y4d-kpi-s{font-size:12px;color:#8c8f94}
.y4d-up{color:#14602a;font-weight:600}.y4d-down{color:#b32d2e;font-weight:600}
.y4d-grid{display:grid;gap:12px;margin-bottom:12px}.y4d-grid-3{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}.y4d-grid-2{grid-template-columns:repeat(auto-fit,minmax(380px,1fr))}.y4d-grid-4{grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
.y4d-card{padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:12px}
.y4d-bars{display:block;width:100%;height:72px}.y4d-foot{margin:8px 0 0;font-size:12px;color:#646970}
.y4d-table{width:100%;border-collapse:collapse;font-size:13px}.y4d-table th{padding:0 0 6px;text-align:left;font-size:11px;font-weight:600;color:#8c8f94;text-transform:uppercase}.y4d-table td{padding:7px 0;border-top:1px solid #f0f0f1;vertical-align:middle}.y4d-table thead+tbody tr:first-child td,.y4d-table tbody:first-child tr:first-child td{border-top:0}
.y4d-num,.y4d-table th.y4d-num{text-align:right;white-space:nowrap}.y4d-rank{width:22px;color:#8c8f94}.y4d-muted{color:#8c8f94}.y4d-empty{margin:0;color:#8c8f94}
.y4d-chip{margin-left:6px;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600;background:#e8f4f8;color:#0b6482}.y4d-chip-warn{background:#fff4de;color:#7a4d00}.y4d-chip-bad{background:#fdeeee;color:#8f1d22}
.y4d-all{float:right;font-size:12px;font-weight:400}.y4d-h{margin:20px 0 10px!important;font-size:15px!important}
.y4d-health{display:flex;flex-direction:column;gap:3px;text-decoration:none;color:inherit}a.y4d-health:hover{border-color:#29b1d8}.y4d-h-l{display:flex;align-items:center;gap:8px;font-weight:700;color:#1d2327}.y4d-h-s{font-size:13px;color:#3c434a}.y4d-h-m{font-size:12px;color:#8c8f94}
.y4d-h-row{display:flex;align-items:center;gap:6px;font-size:12px;color:#646970}.y4d-h-row b{color:#3c434a;font-weight:600}
.y4d-dot{display:inline-block;flex:none;width:9px;height:9px;border-radius:50%;background:#c3c4c7}.y4d-ok{background:#3fc35a}.y4d-warn{background:#f0a020}.y4d-bad{background:#e5484d}.y4d-on{background:#29b1d8}.y4d-idle{background:#c3c4c7}
</style>';
	}
}

Y4APK_Dashboard::init();
