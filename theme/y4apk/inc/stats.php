<?php
/**
 * Y4APK app analytics: Y4APK ▸ Analytics.
 *
 * How many people install the app, download the APK, actually use the app
 * (and which versions), how the install banner does, which files get
 * downloaded and how often the Direct Link ad opens.
 *
 * Browsers count events and send them in small batches to stats.php (see that
 * file); the totals live in one table, a row per day × event × detail. Nothing
 * personal is kept. The same events can also go to Google Analytics, when the
 * site has it (Site Kit), for countries, devices and the rest.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Stats {

	const TABLE      = 'y4apk_stats';
	const DB_VERSION = '1';
	const SLUG       = 'y4apk-analytics';
	const KEEP_DAYS  = 400;

	public static function init() {
		add_action('y4apk_stats_prune', [__CLASS__, 'prune']);
		if (!is_admin()) {
			return;
		}
		add_action('admin_init', [__CLASS__, 'install']);
		add_action('admin_menu', [__CLASS__, 'register_page'], 103);
		add_action('admin_post_y4apk_save_stats', [__CLASS__, 'handle_save']);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Both options are stored autoloaded on the first admin visit, so pages never query for them. */
	public static function enabled() {
		return get_option('y4apk_stats_on', '1') !== '0';
	}

	public static function send_to_ga() {
		return get_option('y4apk_stats_ga', '1') !== '0';
	}

	public static function endpoint() {
		return get_template_directory_uri() . '/stats.php';
	}

	public static function install() {
		if (get_option('y4apk_stats_db') !== self::DB_VERSION) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta('CREATE TABLE ' . self::table() . " (
  day date NOT NULL,
  event varchar(32) NOT NULL,
  dim varchar(64) NOT NULL DEFAULT '',
  n int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,event,dim)
) " . $wpdb->get_charset_collate() . ';');
			update_option('y4apk_stats_db', self::DB_VERSION, true);
			add_option('y4apk_stats_on', '1', '', 'yes');
			add_option('y4apk_stats_ga', '1', '', 'yes');
			add_option('y4apk_stats_since', current_time('Y-m-d'), '', 'no');
		}
		if (!wp_next_scheduled('y4apk_stats_prune')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'y4apk_stats_prune');
		}
	}

	public static function prune() {
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s',
			gmdate('Y-m-d', current_time('timestamp') - self::KEEP_DAYS * DAY_IN_SECONDS)));
	}

	/* ---------------------------------------------------------------- *
	 * Admin page
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page('y4apk-gplay', __('Analytics', 'y4apk'), __('Analytics', 'y4apk'),
			'manage_options', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-save-stats');
		update_option('y4apk_stats_on', empty($_POST['on']) ? '0' : '1', true);
		update_option('y4apk_stats_ga', empty($_POST['ga']) ? '0' : '1', true);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php')));
		exit;
	}

	/** Totals for the period: [event][dim] => n, and per day: [event][day] => n. */
	private static function load($from, $to) {
		global $wpdb;
		$t = self::table();
		$totals = [];
		foreach ($wpdb->get_results($wpdb->prepare(
			"SELECT event, dim, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND event <> 'file_download' GROUP BY event, dim", $from, $to)) as $r) {
			$totals[$r->event][$r->dim] = (int) $r->n;
		}
		$daily = [];
		foreach ($wpdb->get_results($wpdb->prepare(
			"SELECT day, event, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND event IN ('app_new', 'app_daily', 'file_download') GROUP BY day, event", $from, $to)) as $r) {
			$daily[$r->event][$r->day] = (int) $r->n;
		}
		$top = $wpdb->get_results($wpdb->prepare(
			"SELECT dim, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND event = 'file_download' GROUP BY dim ORDER BY n DESC LIMIT 15", $from, $to));
		$last = $wpdb->get_var("SELECT MAX(day) FROM $t");
		return [$totals, $daily, $top ?: [], $last];
	}

	private static function sum($totals, $event, $dim = null) {
		if (empty($totals[$event])) {
			return 0;
		}
		return $dim === null ? array_sum($totals[$event]) : (isset($totals[$event][$dim]) ? $totals[$event][$dim] : 0);
	}

	private static function num($n) {
		$decimals = (is_float($n) && $n < 10 && floor($n) != $n) ? 1 : 0;
		return esc_html(number_format_i18n($n, $decimals));
	}

	/**
	 * One measure per day as columns: one series, so no legend — the heading
	 * names it. Hover any day for its value; the peak is labelled.
	 */
	private static function columns($series, $from, $days, $noun) {
		// Drawn at about the size it is shown (a third of a 1100px row), so text stays legible.
		$w = 360; $h = 130; $top = 16; $bottom = 20; $left = 2; $right = 2;
		$plot_w = $w - $left - $right;
		$plot_h = $h - $top - $bottom;
		$slot = $plot_w / $days;
		$bar = max(2, min(24, $slot - 2)); // 2px gap at least, never wider than 24px
		$max = $series ? max($series) : 0;
		$peak_day = $max ? array_search($max, $series, true) : '';

		$out = '<svg class="y4s-cols" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr($noun) . '">';
		$out .= '<line x1="0" x2="' . $w . '" y1="' . ($top + $plot_h) . '" y2="' . ($top + $plot_h) . '" class="y4s-base"/>';
		if (!$max) {
			$out .= '<text x="' . ($w / 2) . '" y="' . ($top + $plot_h / 2) . '" text-anchor="middle" class="y4s-muted">' . esc_html__('No data for this period yet', 'y4apk') . '</text>';
		}
		$start = strtotime($from);
		for ($i = 0; $i < $days; $i++) {
			$day = gmdate('Y-m-d', $start + $i * DAY_IN_SECONDS);
			$v = isset($series[$day]) ? $series[$day] : 0;
			$x = $left + $i * $slot + ($slot - $bar) / 2;
			$label = date_i18n('M j', $start + $i * DAY_IN_SECONDS) . ': ' . number_format_i18n($v) . ' ' . $noun;
			$out .= '<g class="y4s-day"><title>' . esc_html($label) . '</title>';
			$out .= '<rect class="y4s-hit" x="' . round($left + $i * $slot, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . ($top + $plot_h) . '"/>';
			if ($v > 0) {
				$bh = max(1, $plot_h * $v / $max);
				$y = $top + $plot_h - $bh;
				$r = min(4, $bar / 2, $bh);
				$x2 = $x + $bar;
				$base = $top + $plot_h;
				$out .= '<path class="y4s-bar" d="M' . round($x, 2) . ',' . $base . 'V' . round($y + $r, 2)
					. 'Q' . round($x, 2) . ',' . round($y, 2) . ' ' . round($x + $r, 2) . ',' . round($y, 2)
					. 'H' . round($x2 - $r, 2) . 'Q' . round($x2, 2) . ',' . round($y, 2) . ' ' . round($x2, 2) . ',' . round($y + $r, 2)
					. 'V' . $base . 'Z"/>';
				if ($day === $peak_day) {
					$out .= '<text x="' . round($x + $bar / 2, 2) . '" y="' . round($y - 5, 2) . '" text-anchor="middle" class="y4s-val">' . number_format_i18n($v) . '</text>';
				}
			}
			$out .= '</g>';
			if ($i === 0 || $i === $days - 1 || ($days > 7 && $i === (int) floor($days / 2))) {
				$anchor = $i === 0 ? 'start' : ($i === $days - 1 ? 'end' : 'middle');
				$tx = $i === 0 ? $left : ($i === $days - 1 ? $w - $right : $x + $bar / 2);
				$out .= '<text x="' . round($tx, 2) . '" y="' . ($h - 6) . '" text-anchor="' . $anchor . '" class="y4s-muted">' . esc_html(date_i18n('M j', $start + $i * DAY_IN_SECONDS)) . '</text>';
			}
		}
		return $out . '</svg>';
	}

	public static function render_page() {
		$days = isset($_GET['days']) && in_array((int) $_GET['days'], [7, 30, 90], true) ? (int) $_GET['days'] : 30;
		$to = current_time('Y-m-d');
		$from = gmdate('Y-m-d', strtotime($to) - ($days - 1) * DAY_IN_SECONDS);
		list($totals, $daily, $top, $last) = self::load($from, $to);

		$apk_downloads = self::sum($totals, 'banner_tap', 'apk') + self::sum($totals, 'banner_tap', 'update');
		$since = get_option('y4apk_stats_since');
		$daily_users = isset($daily['app_daily']) ? $daily['app_daily'] : [];
		// Average over the days actually counted, not days before counting began.
		$counted = $since ? min($days, max(1, (int) floor((strtotime($to) - strtotime(max($since, $from))) / DAY_IN_SECONDS) + 1)) : $days;
		$users_avg = $daily_users ? array_sum($daily_users) / $counted : 0;
		$files = isset($daily['file_download']) ? array_sum($daily['file_download']) : 0;

		$tiles = [
			[__('Web app installs', 'y4apk'), self::sum($totals, 'pwa_installed'),
				sprintf(__('%s accepted the install prompt', 'y4apk'), number_format_i18n(self::sum($totals, 'pwa_accepted')))],
			[__('APK downloads', 'y4apk'), $apk_downloads,
				sprintf(__('from the banner; %s of them updates', 'y4apk'), number_format_i18n(self::sum($totals, 'banner_tap', 'update')))],
			[__('App users per day', 'y4apk'), $users_avg,
				sprintf(__('average; today %s', 'y4apk'), number_format_i18n(isset($daily_users[$to]) ? $daily_users[$to] : 0))],
			[__('New app users', 'y4apk'), self::sum($totals, 'app_new'),
				sprintf(__('APK %1$s · web app %2$s', 'y4apk'), number_format_i18n(self::sum($totals, 'app_new', 'apk')), number_format_i18n(self::sum($totals, 'app_new', 'webapp')))],
			[__('File downloads', 'y4apk'), $files, __('Download button on download pages', 'y4apk')],
			[__('Direct Link ads opened', 'y4apk'), self::sum($totals, 'direct_link'), __('Y4APK ▸ Direct Link', 'y4apk')],
		];

		$modes = [
			'prompt' => __('Install (Chrome / Samsung Internet, Android)', 'y4apk'),
			'apk'    => __('Download APK (in-app browsers)', 'y4apk'),
			'ios'    => __('Add to Home Screen hint (iPhone)', 'y4apk'),
			'update' => __('Update APK (older version installed)', 'y4apk'),
			'open'   => __('Open in app (APK installed)', 'y4apk'),
			'home'   => __('Reminder (web app installed)', 'y4apk'),
		];
		?>
		<div class="wrap y4s">
			<style>
				.y4s{--series-1:#2a78d6;--ink:#1d2327;--ink-2:#50575e;--line:#dcdcde;--surface:#fff}
				.y4s-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;max-width:1100px;margin:16px 0}
				.y4s-tile{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:14px 16px}
				.y4s-tile b{display:block;font-size:28px;line-height:1.15;color:var(--ink);font-weight:600;margin:4px 0}
				.y4s-tile span,.y4s-tile small{color:var(--ink-2)}
				.y4s-charts{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px;max-width:1100px}
				.y4s-chart{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:12px 14px}
				.y4s-chart h3{margin:0 0 6px;font-size:14px}
				.y4s-cols{width:100%;height:auto;display:block}
				.y4s-bar{fill:var(--series-1)}
				.y4s-hit{fill:transparent}
				.y4s-day:hover .y4s-bar{opacity:.75}
				.y4s-base{stroke:var(--line);stroke-width:1}
				.y4s-muted{fill:var(--ink-2);font-size:11px}
				.y4s-val{fill:var(--ink);font-size:11px;font-weight:600}
				.y4s table.widefat{max-width:1100px;margin-top:8px}
				.y4s td.num,.y4s th.num{text-align:right;font-variant-numeric:tabular-nums}
				.y4s-share{display:inline-block;height:8px;border-radius:0 4px 4px 0;background:var(--series-1);vertical-align:middle;margin-left:8px}
			</style>

			<h1><?php esc_html_e('Y4APK Analytics', 'y4apk'); ?></h1>
			<?php if (!empty($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache (10Web ▸ Clear Cache) so visitors’ pages pick it up.', 'y4apk'); ?></p></div>
			<?php endif; ?>
			<?php if (!self::enabled()) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e('Counting is switched off (below).', 'y4apk'); ?></p></div>
			<?php endif; ?>

			<p>
				<?php foreach ([7, 30, 90] as $d) : ?>
					<a class="button<?php echo $d === $days ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'days' => $d], admin_url('admin.php'))); ?>"><?php printf(esc_html__('%d days', 'y4apk'), $d); ?></a>
				<?php endforeach; ?>
				<span style="margin-left:12px;color:#50575e"><?php
					printf(esc_html__('%1$s – %2$s', 'y4apk'), esc_html(date_i18n(get_option('date_format'), strtotime($from))), esc_html(date_i18n(get_option('date_format'), strtotime($to))));
					echo ' · ';
					echo $last
						? sprintf(esc_html__('last data: %s', 'y4apk'), esc_html(date_i18n(get_option('date_format'), strtotime($last))))
						: esc_html__('no data received yet', 'y4apk');
					if ($since) {
						echo ' · ' . sprintf(esc_html__('counting since %s', 'y4apk'), esc_html(date_i18n(get_option('date_format'), strtotime($since))));
					}
				?></span>
			</p>

			<div class="y4s-tiles">
				<?php foreach ($tiles as $tile) : ?>
					<div class="y4s-tile"><span><?php echo esc_html($tile[0]); ?></span><b><?php echo self::num($tile[1]); ?></b><small><?php echo esc_html($tile[2]); ?></small></div>
				<?php endforeach; ?>
			</div>

			<div class="y4s-charts">
				<div class="y4s-chart"><h3><?php esc_html_e('New app users per day', 'y4apk'); ?></h3>
					<?php echo self::columns(isset($daily['app_new']) ? $daily['app_new'] : [], $from, $days, __('new app users', 'y4apk')); ?></div>
				<div class="y4s-chart"><h3><?php esc_html_e('App users per day', 'y4apk'); ?></h3>
					<?php echo self::columns($daily_users, $from, $days, __('app users', 'y4apk')); ?></div>
				<div class="y4s-chart"><h3><?php esc_html_e('File downloads per day', 'y4apk'); ?></h3>
					<?php echo self::columns(isset($daily['file_download']) ? $daily['file_download'] : [], $from, $days, __('downloads', 'y4apk')); ?></div>
			</div>

			<h2><?php esc_html_e('Install banner', 'y4apk'); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Banner', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Seen', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Button tapped', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Closed', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Tap rate', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<?php foreach ($modes as $mode => $label) :
					$seen = self::sum($totals, 'banner_seen', $mode);
					$tap = self::sum($totals, 'banner_tap', $mode);
					$closed = self::sum($totals, 'banner_close', $mode); ?>
					<tr><td><?php echo esc_html($label); ?></td><td class="num"><?php echo self::num($seen); ?></td><td class="num"><?php echo self::num($tap); ?></td><td class="num"><?php echo self::num($closed); ?></td>
						<td class="num"><?php echo $seen ? esc_html(number_format_i18n(100 * $tap / $seen, 1)) . '%' : '–'; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e('Seen counts each visitor once per visit and banner. The Add to Home Screen hint has no button: iPhones do not tell pages whether it was followed.', 'y4apk'); ?></p>

			<h2><?php esc_html_e('Inside the app', 'y4apk'); ?></h2>
			<?php
			$visits = ['apk' => self::sum($totals, 'app_open', 'apk'), 'webapp' => self::sum($totals, 'app_open', 'webapp')];
			$versions = isset($totals['app_version']) ? $totals['app_version'] : [];
			uksort($versions, function ($a, $b) { return version_compare($b, $a); });
			$version_total = array_sum($versions);
			?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Visits inside the app', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Visits', 'y4apk'); ?></th></tr></thead>
				<tbody>
					<tr><td><?php esc_html_e('Android APK', 'y4apk'); ?></td><td class="num"><?php echo self::num($visits['apk']); ?></td></tr>
					<tr><td><?php esc_html_e('Web app (installed from the browser)', 'y4apk'); ?></td><td class="num"><?php echo self::num($visits['webapp']); ?></td></tr>
				</tbody>
			</table>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('APK version', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Visits', 'y4apk'); ?></th><th><?php esc_html_e('Share', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<?php if (!$versions) : ?>
					<tr><td colspan="3"><?php esc_html_e('No APK visits with a known version yet. The APK reports its version through its Start URL (Y4APK ▸ App, step 2).', 'y4apk'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($versions as $version => $n) : $share = $version_total ? $n / $version_total : 0; ?>
					<tr><td><code><?php echo esc_html($version); ?></code><?php echo $version === (string) Y4APK_PWA::get('apk_version') ? ' — ' . esc_html__('latest', 'y4apk') : ''; ?></td>
						<td class="num"><?php echo self::num($n); ?></td>
						<td><?php echo esc_html(number_format_i18n(100 * $share, 1)); ?>%<span class="y4s-share" style="width:<?php echo esc_attr(round(160 * $share)); ?>px"></span></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e('Most downloaded', 'y4apk'); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Post', 'y4apk'); ?></th><th class="num"><?php esc_html_e('Download taps', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<?php if (!$top) : ?>
					<tr><td colspan="2"><?php esc_html_e('No downloads counted in this period yet.', 'y4apk'); ?></td></tr>
				<?php else :
					$ids = array_filter(array_map(function ($r) { return absint($r->dim); }, $top));
					if ($ids) {
						_prime_post_caches($ids, false, false);
					}
					foreach ($top as $row) : $id = absint($row->dim); $title = $id ? get_the_title($id) : ''; ?>
					<tr><td><?php echo $title !== ''
						? '<a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . esc_html($title) . '</a>'
						: esc_html(sprintf(__('Post #%s', 'y4apk'), $row->dim)); ?></td>
						<td class="num"><?php echo self::num((int) $row->n); ?></td></tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e('Settings', 'y4apk'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_save_stats">
				<?php wp_nonce_field('y4apk-save-stats'); ?>
				<p><label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Count these events', 'y4apk'); ?></label><br>
					<span class="description"><?php esc_html_e('Counts only — no IP addresses, cookies or user IDs. Visitors’ browsers send at most one small request per page, only when there is something to count, to a light counter that loads no plugins. Needs the app switched on (Y4APK ▸ App).', 'y4apk'); ?></span></p>
				<p><label><input type="checkbox" name="ga" value="1" <?php checked(self::send_to_ga()); ?>> <?php esc_html_e('Also send them to Google Analytics', 'y4apk'); ?></label><br>
					<span class="description"><?php esc_html_e('When the site has Google Analytics (Site Kit), the same events arrive there named y4apk_… (y4apk_banner_tap, y4apk_app_open, y4apk_file_download, …) with the detail in y4apk_detail — for countries, devices, traffic sources and the rest.', 'y4apk'); ?></span></p>
				<?php submit_button(__('Save', 'y4apk')); ?>
			</form>
		</div>
		<?php
	}
}

Y4APK_Stats::init();
