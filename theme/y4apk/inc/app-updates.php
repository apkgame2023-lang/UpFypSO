<?php
/**
 * Y4APK ▸ App updates: which posts have a newer version out than the file they
 * offer.
 *
 * In the background, posts that know their Google Play app (meta wp_GP_ID, set
 * by the Google Play importer) are checked against Aptoide's public app data —
 * the same source the importer reads versions from, since Google Play's own
 * page often says "Varies with device". A few apps at a time, every 15 minutes,
 * round and round: about 100 an hour, so 6,000 apps take two to three days per
 * round, gently for the host and for Aptoide.
 *
 * Nothing on the site changes by itself. The newest version is stored on the
 * post, and the posts whose download is older are listed — most downloaded
 * first — with a form per post to enter the new file's version, size and link.
 * Saving that updates the download and the version number in the title.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_App_Updates {

	const SLUG     = 'y4apk-app-updates';
	const HOOK     = 'y4apk_version_check';
	const BATCH    = 25;
	const LATEST   = '_y4apk_latest_version';
	const CHECKED  = '_y4apk_latest_checked';
	const NEEDS    = '_y4apk_needs_update';
	const PER_PAGE = 50;

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		// Any save, including the block editor's (REST, not an admin page).
		add_action('save_post_post', [__CLASS__, 'refresh_flag'], 20);
		if (!is_admin()) {
			return;
		}
		add_action('admin_init', [__CLASS__, 'schedule']);
		add_action('admin_menu', [__CLASS__, 'register_page'], 104);
		add_action('admin_post_y4apk_vc_save', [__CLASS__, 'handle_save']);
		add_action('admin_post_y4apk_vc_check', [__CLASS__, 'handle_check']);
		add_action('admin_post_y4apk_vc_settings', [__CLASS__, 'handle_settings']);
		add_filter('manage_post_posts_columns', [__CLASS__, 'column']);
		add_action('manage_post_posts_custom_column', [__CLASS__, 'column_value'], 10, 2);
		add_filter('views_edit-post', [__CLASS__, 'view_link']);
		add_action('pre_get_posts', [__CLASS__, 'filter_list']);
		add_filter('post_row_actions', [__CLASS__, 'row_action'], 10, 2);
		add_action('admin_notices', [__CLASS__, 'check_notice']);
	}

	public static function check_notice() {
		if (empty($_GET['y4apk_checked'])) {
			return;
		}
		echo $_GET['y4apk_checked'] === 'busy'
			? '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('The version service did not answer. Try again in a minute.', 'y4apk') . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Version checked — see the Latest version column.', 'y4apk') . '</p></div>';
	}

	public static function enabled() {
		return get_option('y4apk_vc_on', '1') !== '0';
	}

	public static function schedules($schedules) {
		$schedules['y4apk_15min'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Y4APK)'];
		return $schedules;
	}

	public static function schedule() {
		add_option('y4apk_vc_on', '1', '', 'yes');
		$next = wp_next_scheduled(self::HOOK);
		if (self::enabled() && !$next) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'y4apk_15min', self::HOOK);
		} elseif (!self::enabled() && $next) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Versions
	 * ---------------------------------------------------------------- */

	/** "v2.742.Android" → "2.742"; "" when there is no number. */
	public static function number($version) {
		return preg_match('/\d+(?:\.\d+)*/', (string) $version, $m) ? $m[0] : '';
	}

	/** Index and entry of the post's APK download (the first APK-type link, else the first link). */
	public static function download($post_id) {
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		if (!is_array($links) || !$links) {
			return [null, null];
		}
		foreach ($links as $i => $link) {
			$name = strtolower(isset($link['download_name']) ? (string) $link['download_name'] : '');
			if (in_array($name, ['apk', 'apks', 'xapk', 'apkm', 'mod'], true)) {
				return [$i, $link];
			}
		}
		$first = array_key_first($links);
		return [$first, $links[$first]];
	}

	public static function current_version($post_id) {
		list(, $link) = self::download($post_id);
		$version = $link && !empty($link['download_version']) ? $link['download_version'] : get_post_meta($post_id, 'wp_version_GP', true);
		return (string) $version;
	}

	/** Is the stored latest version newer than the post's download? */
	public static function needs_update($post_id) {
		$latest = self::number(get_post_meta($post_id, self::LATEST, true));
		$current = self::number(self::current_version($post_id));
		return $latest !== '' && $current !== '' && version_compare($latest, $current, '>');
	}

	public static function refresh_flag($post_id) {
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		if (self::needs_update($post_id)) {
			update_post_meta($post_id, self::NEEDS, '1');
		} else {
			delete_post_meta($post_id, self::NEEDS);
		}
	}

	/**
	 * Latest version of a Google Play app from Aptoide's public API.
	 * Returns the version string, '' when the app is not there, or null when
	 * the service did not answer (try again later).
	 */
	public static function fetch($package) {
		$response = wp_remote_get('https://ws2.aptoide.com/api/7/app/getMeta/package_name=' . rawurlencode($package), [
			'timeout'    => 8,
			'user-agent' => 'Y4APK-VersionCheck/' . Y4APK_THEME_VERSION . ' (+' . home_url('/') . ')',
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$data = json_decode(wp_remote_retrieve_body($response), true);
		if ($code === 200 && !empty($data['data']['file']['vername'])) {
			return sanitize_text_field($data['data']['file']['vername']);
		}
		if ($code === 404 || ($code === 200 && isset($data['info']['status']) && $data['info']['status'] === 'FAIL')) {
			return '';
		}
		return null;
	}

	public static function check_post($post_id) {
		$package = trim((string) get_post_meta($post_id, 'wp_GP_ID', true));
		if ($package === '' || !preg_match('/^[A-Za-z][\w.]+$/', $package)) {
			return false;
		}
		$latest = self::fetch($package);
		if ($latest === null) {
			return null;
		}
		update_post_meta($post_id, self::LATEST, $latest);
		update_post_meta($post_id, self::CHECKED, time());
		self::refresh_flag($post_id);
		return true;
	}

	/** One background step: the next few posts with a Google Play ID, by post ID, round and round. */
	public static function tick() {
		if (!self::enabled() || get_transient('y4apk_vc_lock')) {
			return;
		}
		set_transient('y4apk_vc_lock', 1, 5 * MINUTE_IN_SECONDS);
		global $wpdb;
		$cursor = (int) get_option('y4apk_vc_cursor', 0);
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = 'wp_GP_ID' AND pm.meta_value <> '' AND pm.post_id > %d
			   AND p.post_type = 'post' AND p.post_status = 'publish'
			 ORDER BY pm.post_id LIMIT %d", $cursor, self::BATCH));
		foreach ($ids as $id) {
			$done = self::check_post((int) $id);
			if ($done === null) {
				break; // the service is busy or unreachable: pick up here next time
			}
			$cursor = (int) $id;
		}
		if (count($ids) < self::BATCH && (!$ids || $cursor === (int) end($ids))) {
			$cursor = 0; // round finished
			update_option('y4apk_vc_round_done', time(), false);
		}
		update_option('y4apk_vc_cursor', $cursor, false);
		delete_transient('y4apk_vc_lock');
	}

	/* ---------------------------------------------------------------- *
	 * Posts list: column, "Needs update" view, "Check version now"
	 * ---------------------------------------------------------------- */

	public static function column($columns) {
		$out = [];
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ($key === 'title') {
				$out['y4apk_latest'] = __('Latest version', 'y4apk');
			}
		}
		return $out;
	}

	public static function column_value($column, $post_id) {
		if ($column !== 'y4apk_latest') {
			return;
		}
		if (!get_post_meta($post_id, 'wp_GP_ID', true)) {
			echo '<span style="color:#8c8f94">' . esc_html__('No Google Play ID', 'y4apk') . '</span>';
			return;
		}
		$checked = (int) get_post_meta($post_id, self::CHECKED, true);
		if (!$checked) {
			echo '<span style="color:#8c8f94">' . esc_html__('Not checked yet', 'y4apk') . '</span>';
			return;
		}
		$latest = (string) get_post_meta($post_id, self::LATEST, true);
		if ($latest === '') {
			echo '<span style="color:#8c8f94">' . esc_html__('Not found', 'y4apk') . '</span>';
		} elseif (get_post_meta($post_id, self::NEEDS, true)) {
			echo '<strong style="color:#b32d2e">' . esc_html($latest) . '</strong><br><span style="color:#b32d2e">' . esc_html__('Newer than your file', 'y4apk') . '</span>';
		} else {
			echo esc_html($latest) . '<br><span style="color:#008a20">✓ ' . esc_html__('Up to date', 'y4apk') . '</span>';
		}
		echo '<br><small style="color:#8c8f94">' . esc_html(sprintf(__('checked %s ago', 'y4apk'), human_time_diff($checked))) . '</small>';
	}

	public static function needs_count() {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS));
	}

	public static function view_link($views) {
		$count = self::needs_count();
		if ($count) {
			$views['y4apk_needs_update'] = '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
				. esc_html__('Needs update', 'y4apk') . ' <span class="count">(' . number_format_i18n($count) . ')</span></a>';
		}
		return $views;
	}

	public static function filter_list($query) {
		if (is_admin() && $query->is_main_query() && !empty($_GET['y4apk_needs_update'])) {
			$query->set('meta_key', self::NEEDS);
		}
	}

	public static function row_action($actions, $post) {
		if ($post->post_type === 'post' && get_post_meta($post->ID, 'wp_GP_ID', true) && current_user_can('edit_post', $post->ID)) {
			$actions['y4apk_check'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_vc_check&post=' . $post->ID), 'y4apk-vc-check-' . $post->ID)) . '">'
				. esc_html__('Check version now', 'y4apk') . '</a>';
		}
		return $actions;
	}

	public static function handle_check() {
		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		check_admin_referer('y4apk-vc-check-' . $post_id);
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		$done = self::check_post($post_id);
		$back = wp_get_referer() ? wp_get_referer() : admin_url('edit.php');
		wp_safe_redirect(add_query_arg('y4apk_checked', $done === null ? 'busy' : 'done', remove_query_arg('y4apk_checked', $back)));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ App updates
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page('y4apk-gplay', __('App updates', 'y4apk'), __('App updates', 'y4apk'),
			'edit_posts', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-vc-settings');
		update_option('y4apk_vc_on', empty($_POST['on']) ? '0' : '1', true);
		self::schedule();
		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
		exit;
	}

	/** Save a new file for one post: the APK download's version, size and link, and the version in the title. */
	public static function handle_save() {
		$post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		check_admin_referer('y4apk-vc-save-' . $post_id);
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		$in = wp_unslash($_POST);
		$version = sanitize_text_field(isset($in['version']) ? $in['version'] : '');
		$size = sanitize_text_field(isset($in['size']) ? $in['size'] : '');
		$url = esc_url_raw(isset($in['url']) ? trim($in['url']) : '');
		$back = add_query_arg(['page' => self::SLUG, 'paged' => isset($in['paged']) ? absint($in['paged']) : 1], admin_url('admin.php'));

		list($index, $link) = self::download($post_id);
		if ($index === null || $version === '' || $url === '') {
			wp_safe_redirect(add_query_arg('y4apk_error', 'missing', $back));
			exit;
		}

		$old = self::number(isset($link['download_version']) ? $link['download_version'] : '');
		$new = self::number($version);
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$links[$index]['download_version'] = $version;
		$links[$index]['download_url'] = $url;
		if ($size !== '') {
			$links[$index]['download_size'] = $size;
		}
		update_post_meta($post_id, 'repeatable_download_link', $links);
		if ($new !== '') {
			update_post_meta($post_id, 'wp_version_GP', $new);
		}

		// The version in the title follows, when the old one is plainly there
		// ("v2.742" → "v2.750"); a bare number like "1" is left alone.
		$title = get_post_field('post_title', $post_id);
		$args = ['ID' => $post_id];
		if ($old !== '' && $new !== '' && strpos($old, '.') !== false && strpos($title, $old) !== false) {
			$args['post_title'] = str_replace($old, $new, $title);
		}
		wp_update_post($args); // also marks the post as just updated
		self::refresh_flag($post_id);

		wp_safe_redirect(add_query_arg('y4apk_saved', $post_id, $back));
		exit;
	}

	public static function render_page() {
		global $wpdb;
		$with_id = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'wp_GP_ID' AND meta_value <> ''");
		$checked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::CHECKED));
		$needs_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NEEDS)));
		$round_done = (int) get_option('y4apk_vc_round_done', 0);

		// Most downloaded first (last 30 days, Y4APK ▸ Analytics), then newest posts.
		$downloads = [];
		if (class_exists('Y4APK_Stats') && $needs_ids) {
			foreach ($wpdb->get_results($wpdb->prepare(
				'SELECT dim, SUM(n) AS n FROM ' . Y4APK_Stats::table() . " WHERE event = 'file_download' AND day >= %s GROUP BY dim",
				gmdate('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS))) as $r) {
				$downloads[(int) $r->dim] = (int) $r->n;
			}
		}
		usort($needs_ids, function ($a, $b) use ($downloads) {
			$da = isset($downloads[$a]) ? $downloads[$a] : 0;
			$db = isset($downloads[$b]) ? $downloads[$b] : 0;
			return $da === $db ? $b - $a : $db - $da;
		});
		$paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
		$pages = max(1, (int) ceil(count($needs_ids) / self::PER_PAGE));
		$page_ids = array_slice($needs_ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
		if ($page_ids) {
			_prime_post_caches($page_ids, false, true);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('App updates', 'y4apk'); ?></h1>
			<?php if (!empty($_GET['y4apk_saved'])) : $sid = absint($_GET['y4apk_saved']); ?>
				<div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('Saved: %s', 'y4apk'), '<a href="' . esc_url(get_permalink($sid)) . '" target="_blank">' . esc_html(get_the_title($sid)) . '</a>'); ?></p></div>
			<?php elseif (!empty($_GET['y4apk_error'])) : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Enter the new version and the download link.', 'y4apk'); ?></p></div>
			<?php endif; ?>

			<p style="max-width:900px"><?php esc_html_e('In the background, posts with a Google Play ID are checked against Aptoide’s public app data (which follows Google Play), about 100 apps an hour. Posts whose download is older than the latest version are listed here, most downloaded first. Nothing on the site changes until you save a new file below.', 'y4apk'); ?></p>
			<p><strong><?php printf(esc_html__('%1$s of %2$s apps checked', 'y4apk'), number_format_i18n(min($checked, $with_id)), number_format_i18n($with_id)); ?></strong>
				· <?php printf(esc_html__('%s need an update', 'y4apk'), '<strong>' . number_format_i18n(count($needs_ids)) . '</strong>'); ?>
				<?php if ($round_done) : ?> · <?php printf(esc_html__('last full round finished %s ago', 'y4apk'), esc_html(human_time_diff($round_done))); ?><?php endif; ?>
				<?php if (!self::enabled()) : ?> · <strong style="color:#b32d2e"><?php esc_html_e('checking is switched off', 'y4apk'); ?></strong><?php endif; ?></p>

			<table class="widefat striped" style="max-width:1300px">
				<thead><tr>
					<th><?php esc_html_e('Post', 'y4apk'); ?></th>
					<th><?php esc_html_e('Your file', 'y4apk'); ?></th>
					<th><?php esc_html_e('Latest', 'y4apk'); ?></th>
					<th style="text-align:right"><?php esc_html_e('Downloads (30 days)', 'y4apk'); ?></th>
					<th style="width:46%"><?php esc_html_e('New file', 'y4apk'); ?></th>
				</tr></thead>
				<tbody>
				<?php if (!$page_ids) : ?>
					<tr><td colspan="5"><?php echo $checked
						? esc_html__('Every checked post is up to date.', 'y4apk')
						: esc_html__('Nothing checked yet — the first results appear within about 15 minutes.', 'y4apk'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($page_ids as $id) :
					list(, $link) = self::download($id);
					$current = self::current_version($id);
					$latest = (string) get_post_meta($id, self::LATEST, true);
					$suggest = ($n = self::number($current)) !== '' ? str_replace($n, self::number($latest), $current) : $latest; ?>
					<tr>
						<td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><strong><?php echo esc_html(get_the_title($id)); ?></strong></a><br>
							<small><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode(get_post_meta($id, 'wp_GP_ID', true))); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Play', 'y4apk'); ?></a></small></td>
						<td><code><?php echo esc_html($current); ?></code></td>
						<td><code style="color:#b32d2e"><?php echo esc_html($latest); ?></code></td>
						<td style="text-align:right"><?php echo esc_html(number_format_i18n(isset($downloads[$id]) ? $downloads[$id] : 0)); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
								<input type="hidden" name="action" value="y4apk_vc_save">
								<input type="hidden" name="post" value="<?php echo esc_attr($id); ?>">
								<input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>">
								<?php wp_nonce_field('y4apk-vc-save-' . $id); ?>
								<input name="version" value="<?php echo esc_attr($suggest); ?>" style="width:130px" aria-label="<?php esc_attr_e('Version', 'y4apk'); ?>">
								<input name="size" value="<?php echo esc_attr($link && isset($link['download_size']) ? $link['download_size'] : ''); ?>" placeholder="158 MB" style="width:90px" aria-label="<?php esc_attr_e('Size', 'y4apk'); ?>">
								<input name="url" type="url" required placeholder="<?php esc_attr_e('Link to the new file', 'y4apk'); ?>" style="flex:1;min-width:180px" aria-label="<?php esc_attr_e('Download link', 'y4apk'); ?>">
								<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($pages > 1) : ?>
				<p><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]); ?></p>
			<?php endif; ?>
			<p class="description" style="max-width:900px"><?php esc_html_e('Save puts the new version, size and link on the post’s APK download, changes the version in the title (for example v2.742 → v2.750) and marks the post as updated today. Other downloads on the post (OBB, iOS) are left as they are. The link field starts empty on purpose: it must be the new file.', 'y4apk'); ?></p>

			<?php if (current_user_can('manage_options')) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="y4apk_vc_settings">
					<?php wp_nonce_field('y4apk-vc-settings'); ?>
					<p><label><input type="checkbox" name="on" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Check versions in the background', 'y4apk'); ?></label>
						<button class="button"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

Y4APK_App_Updates::init();
