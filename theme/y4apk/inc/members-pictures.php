<?php
/**
 * Y4APK ▸ Members' pictures (2.43): the profile pictures and banners members
 * upload, checked by a person.
 *
 * Every new picture is marked "to look at" (y4apk_pic_new / y4apk_banner_new: the
 * time it came). The page opens on those, 60 at a time, newest first: tick the
 * ones that do not belong and take them down (they go from R2 too), mark the rest
 * as fine, or all of a page at once. So the queue holds only what came since the
 * last look, however many members there are; All shows every picture, with
 * search by @name and filters. The queries go by meta_key (indexed), never
 * through every member.
 */

defined('ABSPATH') || exit;

class Y4APK_Pictures {
	const SLUG     = 'y4apk-pictures';
	const NEW_PIC  = 'y4apk_pic_new';
	const NEW_BAN  = 'y4apk_banner_new';
	const PER_PAGE = 60;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu'], 30);
		add_action('admin_post_y4apk_pictures', [__CLASS__, 'act']);
		add_action('admin_init', [__CLASS__, 'first_run']);
	}

	public static function new_key($kind) {
		return $kind === 'banner' ? self::NEW_BAN : self::NEW_PIC;
	}

	/** A picture that came: on the "to look at" list. */
	public static function came($user_id, $kind) {
		update_user_meta($user_id, self::new_key($kind), (string) time());
	}

	public static function count_new() {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)", self::NEW_PIC, self::NEW_BAN)) + self::count_covers(true);
	}

	/** Posts' own covers (2.55): new ones (to look at) or all. */
	public static function count_covers($new) {
		global $wpdb;
		return class_exists('Y4APK_Cover') ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash' WHERE m.meta_key = %s", $new ? Y4APK_Cover::NEW : Y4APK_Cover::META)) : 0; // 2.70: not the covers of deleted posts
	}

	public static function menu() {
		$n = self::count_new();
		add_submenu_page(Y4APK_MENU, __('Members’ pictures', 'y4apk'), __('Pictures', 'y4apk') . ($n ? ' <span class="awaiting-mod">' . number_format_i18n($n) . '</span>' : ''), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	/** The pictures that were there before 2.43 go on the list once, so the first look covers them too. */
	public static function first_run() {
		if (get_option('y4apk_pictures_v') === '1' || !current_user_can('manage_options')) {
			return;
		}
		global $wpdb;
		$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT m.user_id, IF(m.meta_key = %s, %s, %s), %s FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->usermeta} n ON n.user_id = m.user_id AND n.meta_key = IF(m.meta_key = %s, %s, %s) WHERE m.meta_key IN (%s, %s) AND n.umeta_id IS NULL ORDER BY m.umeta_id",
			Y4APK_Social::PIC, self::NEW_PIC, self::NEW_BAN, (string) time(), Y4APK_Social::PIC, self::NEW_PIC, self::NEW_BAN, Y4APK_Social::PIC, Y4APK_Social::BANNER));
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('user_meta');
		}
		update_option('y4apk_pictures_v', '1', false);
	}

	/** Looks fine / take down: the ticked pictures ("<user>:<kind>"), or one card's own button ("ok|<user>:<kind>"). */
	public static function act() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_pictures');
		$do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
		$items = isset($_POST['pics']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['pics'])) : [];
		if (isset($_POST['one']) && preg_match('/^(ok|down)\|(\d+:(?:pic|banner|cover))$/', (string) wp_unslash($_POST['one']), $m)) {
			$do = $m[1];
			$items = [$m[2]];
		}
		$done = 0;
		foreach (array_slice($items, 0, 500) as $it) {
			if (!preg_match('/^(\d+):(pic|banner|cover)$/', $it, $m)) {
				continue;
			}
			if ($m[2] === 'cover') { // a post's cover (2.55): its post ID
				if ($do === 'down' && class_exists('Y4APK_Cover')) {
					Y4APK_Cover::drop((int) $m[1]);
				} elseif (class_exists('Y4APK_Cover')) {
					delete_post_meta((int) $m[1], Y4APK_Cover::NEW);
				}
				$done++;
				continue;
			}
			if ($do === 'down') {
				Y4APK_Social::drop_image((int) $m[1], $m[2]);
			}
			delete_user_meta((int) $m[1], self::new_key($m[2]));
			$done++;
		}
		$back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '';
		$back = $back !== '' && strpos($back, admin_url()) === 0 ? $back : admin_url('admin.php?page=' . self::SLUG);
		wp_safe_redirect(add_query_arg(['done' => $done, 'did' => $do === 'down' ? 'down' : 'ok'], remove_query_arg(['done', 'did'], $back)));
		exit;
	}

	/** Posts' covers (2.55), in the same grid: tick, Fine, Take down (deleted from R2), open full size, the post and its member. */
	private static function cover_grid($rows, $tab, $here) {
		if (!$rows) {
			echo '<div class="y4p-empty">' . ($tab === 'new' ? '<b>' . esc_html__('All caught up', 'y4apk') . '</b>' . esc_html__('No new post covers to look at.', 'y4apk') : esc_html__('No covers match.', 'y4apk')) . '</div>';
			return;
		}
		$ids = array_map('intval', wp_list_pluck($rows, 'post_id'));
		_prime_post_caches($ids, false, true);
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-y4p>
			<input type="hidden" name="action" value="y4apk_pictures"><input type="hidden" name="back" value="<?php echo esc_attr($here); ?>"><?php wp_nonce_field('y4apk_pictures'); ?>
			<div class="y4p-bar">
				<label><input type="checkbox" data-all> <?php esc_html_e('Select all on this page', 'y4apk'); ?></label>
				<span class="y4p-picked" data-picked></span>
				<span class="y4p-gap"></span>
				<button class="button" name="do" value="ok" data-needs><?php esc_html_e('Looks fine', 'y4apk'); ?></button>
				<button class="button y4p-down" name="do" value="down" data-needs data-ask="<?php esc_attr_e('Take the ticked covers down? They are deleted from R2.', 'y4apk'); ?>"><?php esc_html_e('Take down', 'y4apk'); ?></button>
				<?php if ($tab === 'new') : ?><button class="button button-primary" name="do" value="ok" data-page><?php echo esc_html(sprintf(__('All %s on this page look fine', 'y4apk'), number_format_i18n(count($ids)))); ?></button><?php endif; ?>
			</div>
			<div class="y4p-grid">
				<?php foreach ($ids as $pid) :
					$url = Y4APK_Cover::url($pid);
					$post = get_post($pid);
					if ($url === '' || !$post) {
						delete_post_meta($pid, Y4APK_Cover::NEW);
						continue;
					}
					$u = get_userdata((int) $post->post_author);
					$c = get_post_meta($pid, Y4APK_Cover::META, true);
					$is_new = (bool) get_post_meta($pid, Y4APK_Cover::NEW, true);
					$id = $pid . ':cover';
					?>
				<div class="y4p-card is-cover">
					<label class="y4p-img" title="<?php esc_attr_e('Tick', 'y4apk'); ?>">
						<input type="checkbox" name="pics[]" value="<?php echo esc_attr($id); ?>">
						<img src="<?php echo esc_url($url); ?>" alt="" loading="lazy" decoding="async">
						<?php if (substr($url, -4) === '.gif') : ?><em><?php esc_html_e('moving', 'y4apk'); ?></em><?php endif; ?>
						<?php if ($tab === 'all' && $is_new) : ?><em class="y4p-new"><?php esc_html_e('new', 'y4apk'); ?></em><?php endif; ?>
					</label>
					<span class="y4p-meta">
						<a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank" rel="noopener"><?php echo esc_html(wp_strip_all_tags(get_the_title($pid))); ?></a>
						<small><?php echo esc_html(($u ? '@' . $u->user_login : '') . (is_array($c) && !empty($c['at']) ? ' · ' . Y4APK_Roblox::ago((int) $c['at']) : '') . ($post->post_status !== 'publish' ? ' · ' . $post->post_status : '')); ?></small>
					</span>
					<span class="y4p-acts">
						<?php if ($is_new) : ?><button class="button button-small" name="one" value="<?php echo esc_attr('ok|' . $id); ?>"><?php esc_html_e('Fine', 'y4apk'); ?></button><?php endif; ?>
						<button class="button button-small y4p-down" name="one" value="<?php echo esc_attr('down|' . $id); ?>" data-ask="<?php esc_attr_e('Take this cover down? It is deleted from R2, and the post shows a screenshot instead.', 'y4apk'); ?>"><?php esc_html_e('Take down', 'y4apk'); ?></button>
						<a class="y4p-open" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('Open full size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Open full size', 'y4apk'); ?>"><span class="dashicons dashicons-external"></span></a>
					</span>
				</div>
				<?php endforeach; ?>
			</div>
		</form>
		<?php
	}

	public static function render() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		global $wpdb;
		$tab = isset($_GET['tab']) && $_GET['tab'] === 'all' ? 'all' : 'new';
		$kind = isset($_GET['kind']) && in_array($_GET['kind'], ['pic', 'banner', 'moving', 'cover'], true) ? $_GET['kind'] : '';
		$q = isset($_GET['q']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '', wp_unslash($_GET['q']))) : '';
		$paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
		$base = admin_url('admin.php?page=' . self::SLUG);
		$new_n = self::count_new();
		// 2.70: "All pictures" counts what its list shows (pictures and banners); covers have their own count in the list's menu
		$all_n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND meta_value <> ''", Y4APK_Social::PIC, Y4APK_Social::BANNER));
		$covers_new = self::count_covers(true);
		$covers_all = self::count_covers(false);

		if ($kind === 'cover' && class_exists('Y4APK_Cover')) { // posts' covers: by post meta, newest first
			$cjoin = "JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash'"; // 2.70: not the covers of deleted posts
			$cwhere = $wpdb->prepare('m.meta_key = %s', $tab === 'new' ? Y4APK_Cover::NEW : Y4APK_Cover::META);
			if ($q !== '') {
				$cjoin .= " JOIN {$wpdb->users} u ON u.ID = p.post_author";
				$cwhere .= $wpdb->prepare(' AND u.user_login LIKE %s', $wpdb->esc_like($q) . '%');
			}
			$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} m $cjoin WHERE $cwhere");
			$rows = $wpdb->get_results($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m $cjoin WHERE $cwhere ORDER BY m.meta_id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ($paged - 1) * self::PER_PAGE));
		} else {
		// What to show: by meta_key, newest first; a name narrows it through the users table
		$keys = $tab === 'new' ? [self::NEW_PIC, self::NEW_BAN] : [Y4APK_Social::PIC, Y4APK_Social::BANNER];
		if ($kind === 'pic') {
			$keys = [$keys[0]];
		} elseif ($kind === 'banner') {
			$keys = [$keys[1]];
		}
		$where = $wpdb->prepare('m.meta_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')', $keys) . " AND m.meta_value <> ''";
		$join = '';
		if ($q !== '') {
			$join = "JOIN {$wpdb->users} u ON u.ID = m.user_id";
			$where .= $wpdb->prepare(' AND u.user_login LIKE %s', $wpdb->esc_like($q) . '%');
		}
		if ($kind === 'moving') {
			$join .= " JOIN {$wpdb->usermeta} pm ON pm.user_id = m.user_id AND pm.meta_key = " . ($tab === 'new' ? "IF(m.meta_key = '" . self::NEW_PIC . "', '" . Y4APK_Social::PIC . "', '" . Y4APK_Social::BANNER . "')" : 'm.meta_key');
			$where .= $wpdb->prepare(' AND pm.meta_value LIKE %s', '%' . $wpdb->esc_like('.gif') . '%');
		}
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} m $join WHERE $where");
		$rows = $wpdb->get_results($wpdb->prepare("SELECT m.user_id, m.meta_key FROM {$wpdb->usermeta} m $join WHERE $where ORDER BY m.umeta_id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ($paged - 1) * self::PER_PAGE));
		}
		$pages = max(1, (int) ceil($total / self::PER_PAGE));
		$here = add_query_arg(array_filter(['tab' => $tab === 'all' ? 'all' : '', 'kind' => $kind, 'q' => $q, 'paged' => $paged > 1 ? $paged : '']), $base);
		?>
		<div class="wrap y4p">
			<h1><?php esc_html_e('Members’ pictures', 'y4apk'); ?></h1>
			<p class="description" style="max-width:820px"><?php esc_html_e('Profile pictures and banners members upload, kept in Cloudflare R2. New ones wait here until you look: take down what does not belong (it is deleted from R2 too), mark the rest as fine. A ban takes all of a member’s pictures down.', 'y4apk'); ?></p>
			<?php if (isset($_GET['done'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf($_GET['did'] === 'down' ? _n('%s picture taken down.', '%s pictures taken down.', (int) $_GET['done'], 'y4apk') : _n('%s picture marked as fine.', '%s pictures marked as fine.', (int) $_GET['done'], 'y4apk'), number_format_i18n((int) $_GET['done']))); ?></p></div><?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a class="nav-tab<?php echo $tab === 'new' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base); ?>"><?php esc_html_e('To look at', 'y4apk'); ?> <span class="count">(<?php echo esc_html(number_format_i18n($new_n)); ?>)</span></a>
				<a class="nav-tab<?php echo $tab === 'all' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'all', $base)); ?>"><?php esc_html_e('All pictures', 'y4apk'); ?> <span class="count">(<?php echo esc_html(number_format_i18n($all_n)); ?>)</span></a>
			</nav>

			<form class="y4p-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><?php if ($tab === 'all') : ?><input type="hidden" name="tab" value="all"><?php endif; ?>
				<select name="kind">
					<option value=""><?php esc_html_e('Pictures and banners', 'y4apk'); ?></option>
					<option value="pic" <?php selected($kind, 'pic'); ?>><?php esc_html_e('Profile pictures', 'y4apk'); ?></option>
					<option value="banner" <?php selected($kind, 'banner'); ?>><?php esc_html_e('Banners', 'y4apk'); ?></option>
					<option value="moving" <?php selected($kind, 'moving'); ?>><?php esc_html_e('Moving (GIF)', 'y4apk'); ?></option>
					<option value="cover" <?php selected($kind, 'cover'); ?>><?php echo esc_html($tab === 'new' ? sprintf(__('Post covers (%s new)', 'y4apk'), number_format_i18n($covers_new)) : sprintf(__('Post covers (%s)', 'y4apk'), number_format_i18n($covers_all))); ?></option>
				</select>
				<input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('@name begins with…', 'y4apk'); ?>">
				<button class="button"><?php esc_html_e('Show', 'y4apk'); ?></button>
				<span class="y4p-total"><?php echo esc_html(sprintf(_n('%s picture', '%s pictures', $total, 'y4apk'), number_format_i18n($total))); ?></span>
			</form>

			<?php if ($kind !== 'cover' && $tab === 'new' && $covers_new) : ?><div class="notice notice-info inline"><p><?php echo esc_html(sprintf(_n('%s new post cover to look at.', '%s new post covers to look at.', $covers_new, 'y4apk'), number_format_i18n($covers_new))); ?> <a href="<?php echo esc_url(add_query_arg('kind', 'cover', $base)); ?>"><?php esc_html_e('Look at them', 'y4apk'); ?> →</a></p></div><?php endif; ?>
			<?php if ($kind === 'cover') : self::cover_grid($rows, $tab, $here); if ($pages > 1) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%', remove_query_arg('paged', $here)), 'format' => '', 'current' => $paged, 'total' => $pages]); ?></div></div>
			<?php endif; ?>
			<?php elseif (!$rows) : ?>
				<div class="y4p-empty"><?php if ($tab === 'new' && $q === '' && $kind === '') : ?><b><?php esc_html_e('All caught up', 'y4apk'); ?></b><?php esc_html_e('No new pictures to look at. New ones show up here (and on the Dashboard) as members upload them.', 'y4apk'); ?><?php else : esc_html_e('No pictures match.', 'y4apk'); endif; ?></div>
			<?php else : ?>
			<?php
			$ids = array_map('intval', wp_list_pluck($rows, 'user_id'));
			cache_users($ids);
			update_meta_cache('user', $ids);
			?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-y4p>
				<input type="hidden" name="action" value="y4apk_pictures"><input type="hidden" name="back" value="<?php echo esc_attr($here); ?>"><?php wp_nonce_field('y4apk_pictures'); ?>
				<div class="y4p-bar">
					<label><input type="checkbox" data-all> <?php esc_html_e('Select all on this page', 'y4apk'); ?></label>
					<span class="y4p-picked" data-picked></span>
					<span class="y4p-gap"></span>
					<button class="button" name="do" value="ok" data-needs><?php esc_html_e('Looks fine', 'y4apk'); ?></button>
					<button class="button y4p-down" name="do" value="down" data-needs data-ask="<?php esc_attr_e('Take the ticked pictures down? They are deleted from R2.', 'y4apk'); ?>"><?php esc_html_e('Take down', 'y4apk'); ?></button>
					<?php if ($tab === 'new') : ?><button class="button button-primary" name="do" value="ok" data-page><?php echo esc_html(sprintf(__('All %s on this page look fine', 'y4apk'), number_format_i18n(count($rows)))); ?></button><?php endif; ?>
				</div>
				<div class="y4p-grid">
					<?php foreach ($rows as $row) :
						$is_banner = in_array($row->meta_key, [Y4APK_Social::BANNER, self::NEW_BAN], true);
						$k = $is_banner ? 'banner' : 'pic';
						$pu = get_userdata((int) $row->user_id);
						$url = $pu ? Y4APK_Social::image_url($pu, $k) : '';
						if (!$pu || $url === '') {
							if ($tab === 'new') { // its picture is gone (taken down elsewhere): off the list
								delete_user_meta((int) $row->user_id, self::new_key($k));
							}
							continue;
						}
						$v = get_user_meta($pu->ID, $is_banner ? Y4APK_Social::BANNER : Y4APK_Social::PIC, true);
						$is_new = (bool) get_user_meta($pu->ID, self::new_key($k), true);
						$id = $pu->ID . ':' . $k;
						$banned = (bool) get_user_meta($pu->ID, Y4APK_Members::BANNED, true);
						?>
					<div class="y4p-card<?php echo $is_banner ? ' is-banner' : ''; ?>">
						<label class="y4p-img" title="<?php esc_attr_e('Tick', 'y4apk'); ?>">
							<input type="checkbox" name="pics[]" value="<?php echo esc_attr($id); ?>">
							<img src="<?php echo esc_url($url); ?>" alt="" loading="lazy" decoding="async">
							<?php if (substr($url, -4) === '.gif') : ?><em><?php esc_html_e('moving', 'y4apk'); ?></em><?php endif; ?>
							<?php if ($tab === 'all' && $is_new) : ?><em class="y4p-new"><?php esc_html_e('new', 'y4apk'); ?></em><?php endif; ?>
						</label>
						<span class="y4p-meta">
							<a href="<?php echo esc_url(Y4APK_Members::profile_url($pu)); ?>" target="_blank" rel="noopener">@<?php echo esc_html($pu->user_login); ?></a>
							<small><?php echo esc_html(($is_banner ? __('banner', 'y4apk') : __('picture', 'y4apk')) . (is_array($v) && !empty($v['at']) ? ' · ' . Y4APK_Roblox::ago((int) $v['at']) : '') . ($banned ? ' · ' . __('banned', 'y4apk') : '')); ?></small>
						</span>
						<span class="y4p-acts">
							<?php if ($is_new) : ?><button class="button button-small" name="one" value="<?php echo esc_attr('ok|' . $id); ?>"><?php esc_html_e('Fine', 'y4apk'); ?></button><?php endif; ?>
							<button class="button button-small y4p-down" name="one" value="<?php echo esc_attr('down|' . $id); ?>" data-ask="<?php esc_attr_e('Take this picture down? It is deleted from R2.', 'y4apk'); ?>"><?php esc_html_e('Take down', 'y4apk'); ?></button>
							<a class="y4p-open" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('Open full size', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Open full size', 'y4apk'); ?>"><span class="dashicons dashicons-external"></span></a>
						</span>
					</div>
					<?php endforeach; ?>
				</div>
			</form>
			<?php if ($pages > 1) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%', remove_query_arg('paged', $here)), 'format' => '', 'current' => $paged, 'total' => $pages]); ?></div></div>
			<?php endif; ?>
			<?php endif; ?>
			<?php $imagick = class_exists('Imagick') && Imagick::queryFormats('GIF'); $cap = Y4APK_Social::upload_max(); ?>
			<div class="y4p-limits">
				<h2><?php esc_html_e('How pictures are sent', 'y4apk'); ?></h2>
				<p class="description">
					<?php echo esc_html(sprintf(__('Members may send pictures up to %1$s. They go from the browser straight to R2 (with the CORS rule in Y4APK ▸ App updates ▸ R2 settings, the same one your uploads use); this server then fetches each one, makes it the size it is shown at, keeps that on R2 and deletes what was sent. If a browser cannot reach R2, the picture comes through this server instead, which takes up to %2$s.', 'y4apk'), size_format($cap), size_format(wp_max_upload_size()))); ?>
					<?php echo esc_html($imagick ? __('Moving GIFs are shrunk to the size they are shown at, frame by frame (Imagick).', 'y4apk') : __('Imagick is not on this server, so moving GIFs are kept as they are sent: up to 1000 × 1000 (banners 2000 × 1000) and 10 MB (banners 20 MB). Turn on the imagick extension in hPanel ▸ Advanced ▸ PHP Configuration to take bigger ones.', 'y4apk')); ?>
				</p>
			</div>
		</div>
		<style>
		.y4p .nav-tab-wrapper{margin:14px 0 0}.y4p-filters{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:14px 0}.y4p-total{color:#646970}
		.y4p-bar{position:sticky;top:32px;z-index:3;display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;margin:0 0 12px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
		.y4p-bar label{font-weight:600}.y4p-picked{color:#646970}.y4p-gap{flex:1}.y4p .y4p-down{color:#b32d2e;border-color:#e8a6a6}.y4p .y4p-down:hover{color:#8a2424;border-color:#b32d2e}
		.y4p-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:12px}
		.y4p-card{display:flex;flex-direction:column;min-width:0;background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;transition:box-shadow .12s,border-color .12s}
		.y4p-card.is-banner,.y4p-card.is-cover{grid-column:span 2}.y4p-card.is-cover .y4p-img{aspect-ratio:16/9}.y4p-card:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 2px #2271b1}
		.y4p-img{position:relative;display:block;aspect-ratio:1;background:#f0f0f1 repeating-conic-gradient(#e6e6e8 0 25%,#f6f6f7 0 50%) 0 0/16px 16px;cursor:pointer}
		.y4p-card.is-banner .y4p-img{aspect-ratio:auto;height:0;padding-top:calc(50% - 6px)}
		.y4p-img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
		.y4p-img input{position:absolute;z-index:2;left:8px;top:8px;margin:0;width:20px;height:20px;border-radius:5px;box-shadow:0 0 0 2px #fff}
		.y4p-img input:checked::before{width:24px;height:24px;margin:-2px}
		.y4p-img em{position:absolute;right:6px;bottom:6px;padding:1px 7px;border-radius:99px;font-size:11px;font-style:normal;font-weight:700;color:#fff;background:rgba(0,0,0,.62)}.y4p-img em.y4p-new{top:6px;bottom:auto;background:#2271b1}
		.y4p-meta{display:flex;flex-direction:column;padding:8px 10px 2px;min-width:0}.y4p-meta a{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;text-decoration:none}.y4p-meta small{color:#646970;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
		.y4p-acts{display:flex;align-items:center;gap:6px;padding:6px 10px 10px}.y4p-open{margin-left:auto;color:#646970;text-decoration:none}.y4p-open:hover{color:#2271b1}
		.y4p-empty{padding:48px 20px;margin-top:12px;text-align:center;background:#fff;border:1px dashed #c3c4c7;border-radius:12px;color:#646970;font-size:14px}
		.y4p-empty b{display:block;margin-bottom:4px;font-size:16px;color:#1d2327}
		.y4p-limits{max-width:900px;margin-top:28px}
		@media (max-width:782px){.y4p-card.is-banner{grid-column:span 1}.y4p-bar{top:46px}.y4p-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}}
		</style>
		<script>
		(function () {
			var f = document.querySelector('[data-y4p]');
			if (!f) { return; }
			var boxes = function () { return [].slice.call(f.querySelectorAll('input[name="pics[]"]')); };
			var all = f.querySelector('[data-all]'), out = f.querySelector('[data-picked]');
			var count = function () {
				var n = boxes().filter(function (x) { return x.checked; }).length;
				out.textContent = n ? <?php echo wp_json_encode(__('%s ticked', 'y4apk')); ?>.replace('%s', n) : '';
				all.checked = n > 0 && n === boxes().length;
			};
			all.addEventListener('change', function () { var on = this.checked; boxes().forEach(function (b) { b.checked = on; }); count(); });
			f.addEventListener('change', function (e) { if (e.target.name === 'pics[]') { count(); } });
			f.addEventListener('click', function (e) {
				var b = e.target.closest('button');
				if (!b) { return; }
				if (b.hasAttribute('data-page')) { boxes().forEach(function (x) { x.checked = true; }); return; }
				if (b.hasAttribute('data-needs') && !boxes().some(function (x) { return x.checked; })) { e.preventDefault(); alert(<?php echo wp_json_encode(__('Tick at least one picture first.', 'y4apk')); ?>); return; }
				if (b.hasAttribute('data-ask') && !confirm(b.getAttribute('data-ask'))) { e.preventDefault(); }
			});
		})();
		</script>
		<?php
	}
}

Y4APK_Pictures::init();
