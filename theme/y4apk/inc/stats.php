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
		add_action('admin_menu', [__CLASS__, 'register_page'], 9); // first: the other Y4APK pages go under it
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
		add_submenu_page(Y4APK_MENU, __('Analytics', 'y4apk'), __('Analytics', 'y4apk'), 'manage_options', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-save-stats');
		update_option('y4apk_stats_on', empty($_POST['on']) ? '0' : '1', true);
		update_option('y4apk_stats_ga', empty($_POST['ga']) ? '0' : '1', true);
		y4apk_purge_pages();
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

	/** Per day for some events, every day of the period there (0 when none): [event => [day => n]]. */
	private static function per_day($events, $from, $to) {
		global $wpdb;
		$in = implode(',', array_fill(0, count($events), '%s'));
		$out = [];
		$days = [];
		for ($t = strtotime($from); $t <= strtotime($to); $t += DAY_IN_SECONDS) {
			$days[gmdate('Y-m-d', $t)] = 0;
		}
		foreach ($events as $e) {
			$out[$e] = $days;
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT day, event, SUM(n) AS n FROM ' . self::table() . " WHERE day BETWEEN %s AND %s AND event IN ($in) GROUP BY day, event", array_merge([$from, $to], $events))) as $r) {
			if (isset($out[$r->event][$r->day])) {
				$out[$r->event][$r->day] = (int) $r->n;
			}
		}
		return $out;
	}

	/** Y4APK ▸ Analytics (2.45): in the Dashboard's design, the whole width. */
	public static function render_page() {
		$D = 'Y4APK_Dashboard';
		$days = isset($_GET['days']) && in_array((int) $_GET['days'], [7, 30, 90], true) ? (int) $_GET['days'] : 30;
		$to = current_time('Y-m-d');
		$from = gmdate('Y-m-d', strtotime($to) - ($days - 1) * DAY_IN_SECONDS);
		$pto = gmdate('Y-m-d', strtotime($from) - DAY_IN_SECONDS);
		$pfrom = gmdate('Y-m-d', strtotime($pto) - ($days - 1) * DAY_IN_SECONDS);
		list($totals, , $top, $last) = self::load($from, $to);
		list($prev) = self::load($pfrom, $pto);
		$series = self::per_day(['app_new', 'app_daily', 'file_download', 'direct_link', 'pwa_installed', 'banner_tap', 'executor_dl'], $from, $to);
		global $wpdb;
		$prev_files = (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(n) FROM ' . self::table() . " WHERE day BETWEEN %s AND %s AND event = 'file_download'", $pfrom, $pto));
		$since = get_option('y4apk_stats_since');
		$counted = $since ? min($days, max(1, (int) floor((strtotime($to) - strtotime(max($since, $from))) / DAY_IN_SECONDS) + 1)) : $days;
		$users_avg = array_sum($series['app_daily']) / max(1, $counted);
		$per = sprintf(_n('vs the day before', 'vs the %d days before', $days, 'y4apk'), $days);
		$apk = self::sum($totals, 'banner_tap', 'apk') + self::sum($totals, 'banner_tap', 'update');
		$apk_prev = self::sum($prev, 'banner_tap', 'apk') + self::sum($prev, 'banner_tap', 'update');
		$files = array_sum($series['file_download']);
		$base = admin_url('admin.php?page=' . self::SLUG);
		$fmt = get_option('date_format');
		$tiles = [
			[__('Web app installs', 'y4apk'), self::sum($totals, 'pwa_installed'), $D::delta(self::sum($totals, 'pwa_installed'), self::sum($prev, 'pwa_installed'), $per), $series['pwa_installed']],
			[__('APK downloads', 'y4apk'), $apk, $D::delta($apk, $apk_prev, $per), $series['banner_tap']],
			[__('App users per day', 'y4apk'), $users_avg < 10 ? round($users_avg, 1) : (int) round($users_avg), '<span class="y4d-delta">' . esc_html(sprintf(__('average · today %s', 'y4apk'), number_format_i18n($series['app_daily'][$to] ?? 0))) . '</span>', $series['app_daily']],
			[__('New app users', 'y4apk'), self::sum($totals, 'app_new'), $D::delta(self::sum($totals, 'app_new'), self::sum($prev, 'app_new'), $per), $series['app_new']],
			[__('File downloads', 'y4apk'), $files, $D::delta($files, $prev_files, $per), $series['file_download']],
			[__('Ad opens', 'y4apk'), self::sum($totals, 'direct_link'), $D::delta(self::sum($totals, 'direct_link'), self::sum($prev, 'direct_link'), $per), $series['direct_link']],
			[__('Executor downloads', 'y4apk'), self::sum($totals, 'executor_dl'), $D::delta(self::sum($totals, 'executor_dl'), self::sum($prev, 'executor_dl'), $per), $series['executor_dl']],
		];
		$modes = [
			'prompt' => __('Install (Chrome / Samsung Internet)', 'y4apk'),
			'apk'    => __('Download APK (in-app browsers)', 'y4apk'),
			'ios'    => __('Add to Home Screen hint (iPhone)', 'y4apk'),
			'update' => __('Update APK (older version installed)', 'y4apk'),
			'open'   => __('Open in app (APK installed)', 'y4apk'),
			'home'   => __('Reminder (web app installed)', 'y4apk'),
		];
		$D::styles();
		?>
		<div class="wrap y4d y4s">
			<header class="y4d-head">
				<div>
					<h1><?php esc_html_e('Analytics', 'y4apk'); ?></h1>
					<p class="y4d-sub"><?php
						echo esc_html(sprintf(__('%1$s – %2$s', 'y4apk'), date_i18n($fmt, strtotime($from)), date_i18n($fmt, strtotime($to))) . ' · '
							. ($last ? sprintf(__('last data %s', 'y4apk'), date_i18n($fmt, strtotime($last))) : __('no data received yet', 'y4apk'))
							. ($since ? ' · ' . sprintf(__('counting since %s', 'y4apk'), date_i18n($fmt, strtotime($since))) : ''));
					?></p>
				</div>
				<div class="y4d-head-tools">
					<nav class="y4d-range" aria-label="<?php esc_attr_e('Period', 'y4apk'); ?>">
						<?php foreach ([7, 30, 90] as $r) : ?><a href="<?php echo esc_url(add_query_arg('days', $r, $base)); ?>"<?php echo $r === $days ? ' class="is-on" aria-current="true"' : ''; ?>><?php echo esc_html(sprintf(__('%d days', 'y4apk'), $r)); ?></a><?php endforeach; ?>
					</nav>
					<a class="button" href="#y4s-settings"><?php esc_html_e('Settings', 'y4apk'); ?></a>
				</div>
			</header>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(__('Saved.', 'y4apk') . ' ' . y4apk_cache_note()); ?></p></div><?php endif; ?>
			<?php if (!self::enabled()) : ?><div class="notice notice-warning"><p><?php esc_html_e('Counting is switched off (Settings, below).', 'y4apk'); ?></p></div><?php endif; ?>

			<section class="y4d-kpis">
				<?php foreach ($tiles as $t) : ?>
				<div class="y4d-kpi">
					<span class="y4d-kpi-l"><?php echo esc_html($t[0]); ?></span>
					<span class="y4d-kpi-n"><?php echo esc_html(is_float($t[1]) && floor($t[1]) != $t[1] ? number_format_i18n($t[1], 1) : number_format_i18n((int) $t[1])); ?></span>
					<span class="y4d-kpi-s"><?php echo wp_kses($t[2], ['span' => ['class' => []]]); ?></span>
					<?php echo $D::spark($D::buckets($t[3])); ?>
				</div>
				<?php endforeach; ?>
			</section>

			<h2 class="y4d-h"><?php esc_html_e('Downloads', 'y4apk'); ?></h2>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-7">
					<h3><?php esc_html_e('File downloads per day', 'y4apk'); ?> <small><?php esc_html_e('the Download button on download pages', 'y4apk'); ?></small></h3>
					<?php echo $D::columns($series['file_download'], __('downloads', 'y4apk')); ?>
					<p class="y4d-foot"><?php echo esc_html(sprintf(__('%1$s in %2$d days · busiest day %3$s', 'y4apk'), number_format_i18n($files), $days, $files ? number_format_i18n(max($series['file_download'])) : '0')); ?></p>
				</div>
				<div class="y4d-card y4d-span-5">
					<h3><?php esc_html_e('Most downloaded', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('last %d days', 'y4apk'), $days)); ?></small></h3>
					<?php
					$rows = [];
					$ids = array_filter(array_map(function ($r) { return absint($r->dim); }, $top));
					if ($ids) {
						_prime_post_caches($ids, false, false);
					}
					foreach (array_slice($top, 0, 10) as $r) {
						$id = absint($r->dim);
						$title = $id ? get_the_title($id) : '';
						$rows[] = [$title !== '' ? '<a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . esc_html($title) . '</a>' : esc_html($r->dim !== '' ? sprintf(__('Post #%s (gone)', 'y4apk'), $r->dim) : __('A download page without a post', 'y4apk')), (int) $r->n];
					}
					echo $D::ranked($rows, $files, __('No downloads counted in this period yet.', 'y4apk'));
					?>
				</div>
			</div>

			<h2 class="y4d-h"><?php esc_html_e('The app', 'y4apk'); ?></h2>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-6">
					<h3><?php esc_html_e('App users per day', 'y4apk'); ?> <small><?php esc_html_e('devices that opened the app that day', 'y4apk'); ?></small></h3>
					<?php echo $D::columns($series['app_daily'], __('app users', 'y4apk')); ?>
				</div>
				<div class="y4d-card y4d-span-6">
					<h3><?php esc_html_e('New app users per day', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('APK %1$s · web app %2$s', 'y4apk'), number_format_i18n(self::sum($totals, 'app_new', 'apk')), number_format_i18n(self::sum($totals, 'app_new', 'webapp')))); ?></small></h3>
					<?php echo $D::columns($series['app_new'], __('new app users', 'y4apk')); ?>
				</div>
			</div>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-7">
					<h3><?php esc_html_e('Install banner', 'y4apk'); ?> <small><?php esc_html_e('seen once per visit; the tap rate is taps ÷ seen', 'y4apk'); ?></small></h3>
					<table class="y4d-table y4s-funnel"><thead><tr><th><?php esc_html_e('Banner', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Seen', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Tapped', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Closed', 'y4apk'); ?></th><th><?php esc_html_e('Tap rate', 'y4apk'); ?></th></tr></thead><tbody>
					<?php foreach ($modes as $mode => $label) :
						$seen = self::sum($totals, 'banner_seen', $mode);
						$tap = self::sum($totals, 'banner_tap', $mode);
						$closed = self::sum($totals, 'banner_close', $mode);
						$rate = $seen ? 100 * $tap / $seen : 0; ?>
						<tr data-tip="<?php echo esc_attr($label . ' · ' . number_format_i18n($rate, 1) . '%'); ?>"><td><?php echo esc_html($label); ?></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($seen)); ?></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($tap)); ?></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($closed)); ?></td>
							<td class="y4s-rate"><span class="y4d-rk-bar"><i style="width:<?php echo esc_attr(max($seen ? 1 : 0, min(100, $rate))); ?>%"></i></span><b><?php echo $seen ? esc_html(number_format_i18n($rate, 1)) . '%' : '–'; ?></b></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
				<div class="y4d-card y4d-span-5">
					<?php
					$visits = ['apk' => self::sum($totals, 'app_open', 'apk'), 'webapp' => self::sum($totals, 'app_open', 'webapp')];
					$vt = array_sum($visits);
					$versions = isset($totals['app_version']) ? $totals['app_version'] : [];
					uksort($versions, function ($a, $b) { return version_compare($b, $a); });
					$latest = class_exists('Y4APK_PWA') ? (string) Y4APK_PWA::get('apk_version') : '';
					?>
					<h3><?php esc_html_e('Visits inside the app', 'y4apk'); ?> <small><?php echo esc_html(sprintf(_n('%s visit', '%s visits', $vt, 'y4apk'), number_format_i18n($vt))); ?></small></h3>
					<div class="y4s-split" data-tip="<?php echo esc_attr(sprintf(__('Android APK %1$s · web app %2$s', 'y4apk'), number_format_i18n($visits['apk']), number_format_i18n($visits['webapp']))); ?>">
						<?php if ($vt) : ?><i class="is-apk" style="flex:<?php echo (int) $visits['apk']; ?>"></i><i class="is-web" style="flex:<?php echo (int) $visits['webapp']; ?>"></i><?php endif; ?>
					</div>
					<p class="y4s-legend"><span><i class="is-apk"></i><?php esc_html_e('Android APK', 'y4apk'); ?> <b><?php echo esc_html(number_format_i18n($visits['apk'])); ?></b></span><span><i class="is-web"></i><?php esc_html_e('Web app', 'y4apk'); ?> <b><?php echo esc_html(number_format_i18n($visits['webapp'])); ?></b></span></p>
					<h3 style="margin-top:14px"><?php esc_html_e('APK versions in use', 'y4apk'); ?></h3>
					<?php
					$rows = [];
					foreach (array_slice($versions, 0, 8, true) as $v => $n) {
						$rows[] = ['<code>' . esc_html($v) . '</code>' . ((string) $v === $latest ? '<span class="y4d-chip">' . esc_html__('latest', 'y4apk') . '</span>' : ''), (int) $n];
					}
					echo $D::ranked($rows, array_sum($versions), __('No APK visits with a known version yet. The APK reports its version through its Start URL (Y4APK ▸ App, step 2).', 'y4apk'));
					?>
				</div>
			</div>

			<h2 class="y4d-h"><?php esc_html_e('Ads and executors', 'y4apk'); ?></h2>
			<div class="y4d-row">
				<div class="y4d-card y4d-span-7">
					<h3><?php esc_html_e('Direct Link ads opened per day', 'y4apk'); ?> <a class="y4d-all" href="<?php echo esc_url(admin_url('admin.php?page=y4apk-direct-link')); ?>"><?php esc_html_e('Direct Link', 'y4apk'); ?> →</a></h3>
					<?php echo $D::columns($series['direct_link'], __('ads opened', 'y4apk')); ?>
					<?php
					$names = [];
					if (class_exists('Y4APK_Direct_Link')) {
						foreach (Y4APK_Direct_Link::links() as $l) {
							$names[$l['id']] = $l['name'] !== '' ? $l['name'] : preg_replace('~^https?://~', '', $l['url']);
						}
					}
					$by = isset($totals['direct_link_n']) ? $totals['direct_link_n'] : [];
					arsort($by);
					$rows = [];
					foreach ($by as $id => $n) {
						$rows[] = [esc_html(isset($names[$id]) ? $names[$id] : sprintf(__('Link %s (removed)', 'y4apk'), $id)), (int) $n];
					}
					if (count($rows) > 1) {
						echo '<h3 style="margin-top:12px">' . esc_html__('By link', 'y4apk') . '</h3>' . $D::ranked($rows, array_sum($by), '');
					}
					?>
				</div>
				<div class="y4d-card y4d-span-5">
					<h3><?php esc_html_e('Executor downloads', 'y4apk'); ?> <small><?php echo esc_html(sprintf(__('last %d days', 'y4apk'), $days)); ?></small><?php if (class_exists('Y4APK_Executors')) : ?> <a class="y4d-all" href="<?php echo esc_url(admin_url('edit.php?post_type=' . Y4APK_Executors::CPT)); ?>"><?php esc_html_e('Executors', 'y4apk'); ?> →</a><?php endif; ?></h3>
					<?php
					$by = isset($totals['executor_dl']) ? $totals['executor_dl'] : [];
					arsort($by);
					$rows = [];
					foreach (array_slice($by, 0, 10, true) as $id => $n) {
						$title = get_the_title((int) $id);
						$rows[] = [$title !== '' ? '<a href="' . esc_url(get_permalink((int) $id)) . '" target="_blank">' . esc_html($title) . '</a>' : esc_html('#' . $id), (int) $n];
					}
					echo $D::ranked($rows, array_sum($by), __('No executor downloads in this period yet.', 'y4apk'));
					?>
				</div>
			</div>

			<h2 class="y4d-h"><?php esc_html_e('Every event', 'y4apk'); ?></h2>
			<div class="y4d-row"><div class="y4d-card y4d-span-12">
				<table class="y4d-table"><thead><tr><th><?php esc_html_e('Event', 'y4apk'); ?></th><th class="y4d-num"><?php echo esc_html(sprintf(__('Last %d days', 'y4apk'), $days)); ?></th><th class="y4d-num"><?php esc_html_e('The period before', 'y4apk'); ?></th><th class="y4d-num"><?php esc_html_e('Change', 'y4apk'); ?></th></tr></thead><tbody>
				<?php foreach ((require get_template_directory() . '/inc/stats-events.php') as $ev => $label) :
					$now = $ev === 'file_download' ? $files : self::sum($totals, $ev);
					$was = $ev === 'file_download' ? $prev_files : self::sum($prev, $ev); ?>
					<tr><td><?php echo esc_html($label); ?> <code class="y4s-ev"><?php echo esc_html($ev); ?></code></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($now)); ?></td><td class="y4d-num"><?php echo esc_html(number_format_i18n($was)); ?></td><td class="y4d-num"><?php echo wp_kses($D::delta($now, $was, ''), ['span' => ['class' => []]]); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div></div>

			<h2 class="y4d-h" id="y4s-settings"><?php esc_html_e('Settings', 'y4apk'); ?></h2>
			<div class="y4d-row"><div class="y4d-card y4d-span-12">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="y4apk_save_stats">
					<?php wp_nonce_field('y4apk-save-stats'); ?>
					<p><label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <b><?php esc_html_e('Count these events', 'y4apk'); ?></b></label><br>
						<span class="description"><?php esc_html_e('Counts only — no IP addresses, cookies or user IDs. Visitors’ browsers send at most one small request per page, only when there is something to count, to a light counter that loads no plugins. Needs the app switched on (Y4APK ▸ App).', 'y4apk'); ?></span></p>
					<p><label><input type="checkbox" name="ga" value="1" <?php checked(self::send_to_ga()); ?>> <b><?php esc_html_e('Also send them to Google Analytics', 'y4apk'); ?></b></label><br>
						<span class="description"><?php esc_html_e('When the site has Google Analytics (Site Kit), the same events arrive there named y4apk_… (y4apk_banner_tap, y4apk_app_open, y4apk_file_download, …) with the detail in y4apk_detail — for countries, devices, traffic sources and the rest.', 'y4apk'); ?></span></p>
					<?php submit_button(__('Save', 'y4apk'), 'primary', 'submit', false); ?>
				</form>
			</div></div>
			<div class="y4d-tip" role="tooltip" hidden></div>
		</div>
		<style>
		.y4d-span-6{grid-column:span 6}@media (max-width:1100px){.y4d-span-6{grid-column:span 12}}
		.y4s-rate{display:flex;align-items:center;gap:8px;min-width:140px}.y4s-rate .y4d-rk-bar{flex:1}.y4s-rate b{min-width:44px;text-align:right;font-variant-numeric:tabular-nums}
		.y4s-split{display:flex;gap:2px;height:22px;margin:6px 0 8px;border-radius:6px;overflow:hidden;background:#f0f0f1}.y4s-split i{display:block;min-width:2px}.y4s-split .is-apk,.y4s-legend .is-apk{background:#2a78d6}.y4s-split .is-web,.y4s-legend .is-web{background:#eb6834}
		.y4s-legend{display:flex;flex-wrap:wrap;gap:6px 18px;margin:0;color:var(--ink2)}.y4s-legend span{display:inline-flex;align-items:center;gap:6px}.y4s-legend i{width:10px;height:10px;border-radius:3px}.y4s-legend b{color:var(--ink)}
		.y4s-ev{margin-left:6px;font-size:11px;color:var(--mute);background:#f6f7f7}
		</style>
		<?php
		$D::script();
	}
}

Y4APK_Stats::init();
