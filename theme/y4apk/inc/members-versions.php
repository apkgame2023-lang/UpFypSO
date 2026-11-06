<?php
/**
 * Versions from members (2.46)
 *
 * A member who shares a game or app the site already has gets their own post,
 * linked to the site's post (Y4APK_Share::PARENT). The site's post (the main
 * one) lists them under "Versions from members", newest first, as a Roblox game
 * lists its scripts; each is the member's own, with their name, and the main
 * post and its file are never touched. The site's lists (home, categories,
 * search, feeds, "Similar") show only main posts; a member's profile and
 * My uploads still show their own.
 *
 * 2.44–2.45 put an approved newer version into the main post instead (its
 * download pointed at the member's file). unmerge() undoes that once: the
 * member's post comes back to Review, to be approved under the main post, and
 * Review shows a box to point the main post's download at its own file again.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Versions {
	const FIXED   = 'y4apk_versions_fixed'; // option: the 2.44–2.45 merges were undone
	const RESTORE = '_y4apk_restore';       // on a main post a merge changed: [['from' => member post, 'keys' => [R2 keys], 'version' => '6.0.32'], …]
	const SHOW    = 10;                     // versions shown before "Show more"
	const MAX     = 100;                    // versions a page lists

	public static function init() {
		add_action('pre_get_posts', [__CLASS__, 'only_main'], 30);
		add_action('transition_post_status', [__CLASS__, 'changed'], 20, 3);
		add_action('before_delete_post', [__CLASS__, 'deleting']);
		add_action('admin_init', [__CLASS__, 'unmerge']);
		add_action('admin_post_y4apk_restore_file', [__CLASS__, 'restore']);
	}

	/* ---------------------------------------------------------------- *
	 * Main posts and their versions
	 * ---------------------------------------------------------------- */

	/** The main post a member's version is under (published), or 0. */
	public static function parent_of($post_id) {
		$parent = (int) get_post_meta($post_id, Y4APK_Share::PARENT, true);
		return $parent && get_post_status($parent) === 'publish' && get_post_type($parent) === 'post' ? $parent : 0;
	}

	/** The published versions under a main post, newest first: [post IDs, how many in all]. */
	public static function children($parent, $n = self::MAX, $not = []) {
		$q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $n, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC',
			'meta_key' => Y4APK_Share::PARENT, 'meta_value' => (string) (int) $parent, 'post__not_in' => array_map('intval', (array) $not), 'ignore_sticky_posts' => true,
			'y4_children' => 1, 'update_post_term_cache' => false]);
		return [array_map('intval', $q->posts), (int) $q->found_posts];
	}

	/** "v6.0.32 by Dez": a version as the breadcrumb and the lists name it. */
	public static function label($post_id) {
		$v = (string) get_post_meta($post_id, 'wp_version_GP', true);
		$user = get_userdata(get_post_field('post_author', $post_id));
		$by = $user ? Y4APK_Social::name($user) : '';
		return trim(($v !== '' ? 'v' . $v : '') . ($by !== '' ? ' ' . sprintf(__('by %s', 'y4apk'), $by) : '')) ?: get_the_title($post_id);
	}

	/** The app's name, without the version and MOD the title has. */
	public static function app_name($post_id) {
		$n = html_entity_decode((string) get_post_meta($post_id, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8');
		return $n !== '' ? $n : wp_strip_all_tags(get_the_title($post_id));
	}

	/** 'game' or 'app', by the main post's category (for the "Share your version" link). */
	public static function kind_of($post_id) {
		$roots = Y4APK_Share::roots();
		$app = $roots['app'][0];
		if ($app) {
			foreach (get_the_category($post_id) as $c) {
				if ((int) $c->term_id === (int) $app->term_id || (int) $c->parent === (int) $app->term_id) {
					return 'app';
				}
			}
		}
		return 'game';
	}

	/**
	 * The site's lists show main posts only: every front-end list of posts
	 * (home, categories, tags, search, feeds, "Similar"), but not one post, a
	 * member's own lists (by author), a list of chosen posts, or this file's
	 * own lists of versions ('y4_children').
	 */
	public static function only_main($q) {
		if (is_admin() || $q->get('y4_children') || $q->is_singular() || $q->get('p') || $q->get('name') || $q->get('page_id') || $q->get('pagename')
			|| $q->get('post__in') || $q->get('author') || $q->get('author_name') || $q->get('author__in')) {
			return;
		}
		$type = $q->get('post_type');
		if ($type !== '' && $type !== 'post' && $type !== 'any' && !(is_array($type) && in_array('post', $type, true))) {
			return;
		}
		$not = ['key' => Y4APK_Share::PARENT, 'compare' => 'NOT EXISTS'];
		$mq = $q->get('meta_query');
		$q->set('meta_query', is_array($mq) && $mq ? ['relation' => 'AND', $mq, $not] : [$not]);
	}

	/** A version published or taken down: the main post's page shows the new list (page caches that know the post). */
	public static function changed($new, $old, $post) {
		if ($post->post_type !== 'post' || ($new !== 'publish' && $old !== 'publish') || $new === $old) {
			return;
		}
		$parent = (int) get_post_meta($post->ID, Y4APK_Share::PARENT, true);
		if ($parent) {
			self::purge($parent);
		}
	}

	public static function purge($post_id) {
		clean_post_cache($post_id);
		if (function_exists('w3tc_flush_post')) {
			w3tc_flush_post($post_id);
		} elseif (function_exists('wp_cache_post_change')) {
			wp_cache_post_change($post_id);
		} elseif (function_exists('wpfc_clear_post_cache_by_id')) {
			wpfc_clear_post_cache_by_id($post_id);
		} elseif (defined('LSCWP_V')) {
			do_action('litespeed_purge_post', $post_id);
		} elseif (function_exists('rocket_clean_post')) {
			rocket_clean_post($post_id);
		}
		do_action('y4apk_purge_post', $post_id);
	}

	/** A main post deleted for good: its versions stand on their own. */
	public static function deleting($post_id) {
		if (get_post_type($post_id) !== 'post') {
			return;
		}
		global $wpdb;
		$wpdb->delete($wpdb->postmeta, ['meta_key' => Y4APK_Share::PARENT, 'meta_value' => (string) (int) $post_id]);
	}

	/* ---------------------------------------------------------------- *
	 * On the post's page
	 * ---------------------------------------------------------------- */

	/** "Versions from members" on a main post; "More versions of …" on a member's version. */
	public static function render($post_id) {
		if (!class_exists('Y4APK_Share') || (class_exists('Y4APK_Roblox') && Y4APK_Roblox::is_roblox_post($post_id))) {
			return;
		}
		$parent = self::parent_of($post_id);
		$main = $parent ?: $post_id;
		if (!$parent) {
			$cats = wp_list_pluck(get_the_category($post_id), 'term_id');
			$roots = Y4APK_Share::roots();
			$ours = false;
			foreach ($roots as $r) {
				if ($r[0] && (in_array((int) $r[0]->term_id, $cats, true) || array_intersect($cats, wp_list_pluck($r[1], 'term_id')))) {
					$ours = true;
				}
			}
			if (!$ours) {
				return; // not a game or an app (a page, a Roblox script…)
			}
		}
		list($ids, $total) = self::children($main, self::MAX, [$post_id]);
		$can_add = Y4APK_Share::open() && class_exists('Y4APK_R2') && Y4APK_R2::ready();
		if (!$ids && !$parent && !$can_add) {
			return;
		}
		$name = self::app_name($main);
		$add = $can_add ? Y4APK_Members::url('share/' . self::kind_of($main), ['newer' => $main]) : '';
		?>
		<section class="block y4v" id="versions" aria-labelledby="y4v-title">
			<div class="y4v-head">
				<div class="y4v-text">
					<h2 class="y4v-title" id="y4v-title"><?php echo esc_html($parent ? sprintf(__('More versions of %s', 'y4apk'), $name) : __('Versions from members', 'y4apk')); ?>
						<?php if ($total) : ?><span class="y4v-count"><?php echo esc_html(number_format_i18n($total)); ?></span><?php endif; ?></h2>
					<p class="y4v-sub"><?php echo esc_html($parent
						? __('The site’s own post, and what other members shared for it.', 'y4apk')
						: ($ids ? sprintf(__('Shared by our members for %s. Each one is the member’s own post.', 'y4apk'), $name) : sprintf(__('Have another version of %s? Share it: it shows here with your name.', 'y4apk'), $name))); ?></p>
				</div>
				<?php if ($add) : ?><a class="y4v-add" href="<?php echo esc_url($add); ?>" rel="nofollow"><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg><?php esc_html_e('Share your version', 'y4apk'); ?></a><?php endif; ?>
			</div>
			<?php if ($parent || $ids) : ?>
			<ul class="y4v-list">
				<?php if ($parent) {
					self::card($parent, true);
				}
				foreach (array_slice($ids, 0, self::SHOW) as $id) {
					self::card($id);
				} ?>
			</ul>
			<?php if (count($ids) > self::SHOW) : ?>
			<details class="y4v-more">
				<summary><?php echo esc_html(sprintf(_n('Show %s more', 'Show %s more', count($ids) - self::SHOW, 'y4apk'), number_format_i18n(count($ids) - self::SHOW))); ?></summary>
				<ul class="y4v-list"><?php foreach (array_slice($ids, self::SHOW) as $id) {
					self::card($id);
				} ?></ul>
			</details>
			<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/** One version in the list: who shared it, the version, MOD, the file and its size. */
	private static function card($id, $main = false) {
		$v = (string) get_post_meta($id, 'wp_version_GP', true);
		$mods = trim((string) get_post_meta($id, 'wp_mods', true));
		$size = (string) get_post_meta($id, 'wp_sizes_GP', true);
		$links = get_post_meta($id, 'repeatable_download_link', true);
		$type = is_array($links) && isset($links[0]['download_name']) ? strtoupper((string) $links[0]['download_name']) : 'APK';
		$views = (int) get_post_meta($id, 'wpb_post_views_count', true);
		$user = $main ? null : Y4APK_Members::author($id);
		?>
		<li><a class="y4v-card<?php echo $main ? ' y4v-main' : ''; ?>" href="<?php echo esc_url(get_permalink($id)); ?>">
			<span class="y4v-who"><?php if ($main) {
				$icon = get_the_post_thumbnail_url($id, 'thumbnail');
				echo $icon ? '<img class="y4v-icon" src="' . esc_url($icon) . '" alt="" width="44" height="44" loading="lazy" decoding="async">' : '<span class="y4v-icon"></span>';
			} elseif ($user) {
				echo Y4APK_Social::avatar($user, 44);
			} else {
				echo Y4APK_Members::avatar('?', 44);
			} ?></span>
			<span class="y4v-body">
				<span class="y4v-line">
					<b class="y4v-v"><?php echo esc_html($v !== '' ? 'v' . $v : get_the_title($id)); ?></b>
					<?php if ($main) : ?><span class="y4v-chip y4v-chip-main"><?php esc_html_e('Main post', 'y4apk'); ?></span><?php endif; ?>
					<span class="y4v-chip<?php echo $mods !== '' ? ' y4v-chip-mod' : ''; ?>"><?php echo esc_html($mods !== '' ? __('MOD', 'y4apk') : __('Original', 'y4apk')); ?></span>
				</span>
				<?php if ($mods !== '') : ?><span class="y4v-mods"><?php echo esc_html($mods); ?></span><?php endif; ?>
				<span class="y4v-by"><?php if ($user) : ?><span class="y4v-name"><?php echo esc_html(Y4APK_Social::name($user)); ?></span> · <?php endif; ?>
					<time datetime="<?php echo esc_attr(get_post_time('c', true, $id)); ?>"><?php echo esc_html(get_the_date('', $id)); ?></time><?php if ($views > 0) : ?> · <?php echo esc_html(sprintf(_n('%s view', '%s views', $views, 'y4apk'), number_format_i18n($views))); ?><?php endif; ?></span>
			</span>
			<span class="y4v-file"><span class="y4v-type"><?php echo esc_html($type); ?></span><?php if ($size !== '') : ?><span class="y4v-size"><?php echo esc_html($size); ?></span><?php endif; ?></span>
			<svg class="y4v-go" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</a></li>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * 2.44–2.45 merges: undone once
	 * ---------------------------------------------------------------- */

	/** Each member's version that went into a main post comes back to Review, under that post; the main post gets a restore box. */
	public static function unmerge() {
		if (get_option(self::FIXED) || !current_user_can('edit_others_posts') || !class_exists('Y4APK_Share')) {
			return;
		}
		update_option(self::FIXED, time(), false);
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Y4APK_Share::MERGED));
		foreach ((array) $rows as $r) {
			$id = (int) $r->post_id;
			$parent = (int) $r->meta_value;
			$post = get_post($id);
			$d = $post ? Y4APK_Share::data($id) : null;
			if (!$d || !$parent || !get_post($parent)) {
				continue;
			}
			if ($post->post_status === 'trash') {
				wp_untrash_post($id);
			}
			wp_update_post(['ID' => $id, 'post_status' => 'pending', 'post_name' => '']); // not "__trashed": its address comes from its title when approved
			Y4APK_Share::adopt($id, $parent);
			delete_post_meta($id, Y4APK_Share::MERGED);
			delete_post_meta($id, 'repeatable_download_link'); // approving makes its own download folders
			$fix = get_post_meta($parent, self::RESTORE, true);
			$fix = is_array($fix) ? $fix : [];
			$fix[] = ['from' => $id, 'keys' => array_column($d['files'], 'key'), 'version' => Y4APK_App_Updates::number($d['version'])];
			update_post_meta($parent, self::RESTORE, $fix);
		}
		// Sent with 2.44–2.45 as a newer version, still waiting: a version under that post
		$waiting = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s WHERE p.post_type = 'post' AND p.post_status IN ('pending', 'draft') AND k.post_id IS NULL", Y4APK_Share::META, Y4APK_Share::PARENT));
		foreach ((array) $waiting as $id) {
			$d = Y4APK_Share::data((int) $id);
			if ($d && $d['update_of'] && get_post_status($d['update_of']) === 'publish') {
				Y4APK_Share::adopt((int) $id, $d['update_of']);
			}
		}
		delete_transient('y4apk_review_counts');
	}

	/** The main posts whose download still points at a member's file: [post ID => [row index => ['folder', 'url', 'key', 'label']]]. */
	public static function to_restore() {
		global $wpdb;
		$out = [];
		foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::RESTORE)) as $pid) {
			$pid = (int) $pid;
			$fix = get_post_meta($pid, self::RESTORE, true);
			$keys = [];
			foreach ((array) $fix as $f) {
				$keys = array_merge($keys, (array) ($f['keys'] ?? []));
			}
			$rows = self::rows_on($pid, $keys);
			if ($rows) {
				$out[$pid] = $rows;
			} else {
				delete_post_meta($pid, self::RESTORE); // fixed already (here, in App updates, or by hand)
			}
		}
		return $out;
	}

	/** The post's download rows whose page points at one of these R2 files. */
	private static function rows_on($post_id, $keys) {
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$o = Y4APK_R2::settings();
		$out = [];
		foreach (is_array($links) ? $links : [] as $i => $l) {
			$folder = Y4APK_R2::folder_of(html_entity_decode((string) ($l['download_url'] ?? '')));
			$page = $folder !== '' && is_file($o['dir'] . '/' . $folder . '/default.php') ? (string) file_get_contents($o['dir'] . '/' . $folder . '/default.php') : '';
			foreach ($keys as $k) {
				if ($page !== '' && strpos($page, Y4APK_R2::public_url($k)) !== false) {
					$out[$i] = ['folder' => $folder, 'key' => $k, 'label' => (string) $l['download_name'], 'version' => (string) ($l['download_version'] ?? '')];
					break;
				}
			}
		}
		return $out;
	}

	/** Review's box: the main posts a 2.44–2.45 approval pointed at a member's file, with the post's own files on R2 to pick from. */
	public static function restore_box() {
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return;
		}
		$all = self::to_restore();
		if (!$all) {
			return;
		}
		$msg = isset($_GET['restored']) ? sanitize_key($_GET['restored']) : '';
		?>
		<div class="y4rs">
			<h2><?php esc_html_e('Put your post’s own file back', 'y4apk'); ?></h2>
			<p class="description"><?php esc_html_e('Before 2.46, approving a member’s newer version pointed your post’s download at the member’s file. Their version is now their own post (waiting below, to approve under your post). Pick your post’s own file to point its download back at it.', 'y4apk'); ?></p>
			<?php if ($msg === 'fail') : ?><div class="notice notice-error"><p><?php echo esc_html(isset($_GET['why']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['why']))) : __('Could not change the download.', 'y4apk')); ?></p></div><?php endif; ?>
			<?php foreach ($all as $pid => $rows) :
				$name = self::app_name($pid);
				$member_keys = [];
				foreach ((array) get_post_meta($pid, self::RESTORE, true) as $f) {
					$member_keys = array_merge($member_keys, (array) ($f['keys'] ?? []));
				}
				$prefix = (ctype_alpha(Y4APK_R2::app_name($pid)[0]) ? strtoupper(Y4APK_R2::app_name($pid)[0]) : 'A') . '/' . Y4APK_R2::app_name($pid);
				$found = Y4APK_R2::list_keys($prefix, 200);
				$found = is_wp_error($found) ? [] : array_values(array_filter($found, function ($f) use ($member_keys) {
					return !in_array($f['key'], $member_keys, true);
				})); ?>
			<div class="y4rv-card y4rs-card">
				<div class="y4rs-head"><strong><a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank"><?php echo esc_html(get_the_title($pid)); ?></a></strong></div>
				<?php foreach ($rows as $i => $row) :
					$obb = strtoupper($row['label']) === 'OBB';
					$mine = array_values(array_filter($found, function ($f) use ($obb) {
						$is_obb = (bool) preg_match('/-OBB-|\.(obb|zip)$/i', $f['key']);
						return $obb === $is_obb;
					})); ?>
				<div class="y4rs-row">
					<p><?php echo esc_html(sprintf(__('%1$s download now gives the member’s file: %2$s', 'y4apk'), $row['label'], basename($row['key']))); ?></p>
					<?php if ($mine) : ?>
					<ul class="y4rs-files">
						<?php foreach (array_slice($mine, 0, 12) as $f) : ?>
						<li><span class="y4rs-name"><?php echo esc_html(basename($f['key'])); ?></span><span class="y4rs-meta"><?php echo esc_html(Y4APK_R2::size_text($f['size']) . ($f['time'] ? ' · ' . wp_date(get_option('date_format'), $f['time']) : '')); ?></span>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="y4apk_restore_file"><input type="hidden" name="post" value="<?php echo esc_attr($pid); ?>"><input type="hidden" name="row" value="<?php echo esc_attr($i); ?>"><input type="hidden" name="key" value="<?php echo esc_attr($f['key']); ?>"><?php wp_nonce_field('y4apk_restore_' . $pid); ?><button class="button button-primary"><?php esc_html_e('Use this file', 'y4apk'); ?></button></form></li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="y4rs-none"><?php echo esc_html(sprintf(__('No other file named %s… is on R2.', 'y4apk'), $prefix)); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rs-url"><input type="hidden" name="action" value="y4apk_restore_file"><input type="hidden" name="post" value="<?php echo esc_attr($pid); ?>"><input type="hidden" name="row" value="<?php echo esc_attr($i); ?>"><?php wp_nonce_field('y4apk_restore_' . $pid); ?>
						<input type="url" name="url" required placeholder="<?php esc_attr_e('Or paste the file’s address (https://…apk)', 'y4apk'); ?>"><input type="text" name="version" placeholder="<?php esc_attr_e('Its version', 'y4apk'); ?>" size="10"><button class="button"><?php esc_html_e('Use this address', 'y4apk'); ?></button></form>
				</div>
				<?php endforeach; ?>
				<p class="y4rs-tip"><?php esc_html_e('Not sure which file was there? Hostinger ▸ Files ▸ Backups can bring back the download page (files/up/<folder>/default.php) from a day before.', 'y4apk'); ?>
					<?php foreach ($rows as $row) : ?><code><?php echo esc_html($row['folder']); ?></code> <?php endforeach; ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rs-keep"><input type="hidden" name="action" value="y4apk_restore_file"><input type="hidden" name="post" value="<?php echo esc_attr($pid); ?>"><input type="hidden" name="keep" value="1"><?php wp_nonce_field('y4apk_restore_' . $pid); ?><button class="button-link"><?php esc_html_e('Keep it as it is', 'y4apk'); ?></button></form>
			</div>
			<?php endforeach; ?>
		</div>
		<style>.y4rs{margin:18px 0 26px}.y4rs-card{border-left:4px solid #dba617}.y4rs-head{font-size:14px;margin-bottom:6px}.y4rs-row{padding:8px 0;border-top:1px solid #f0f0f1}.y4rs-row p{margin:4px 0}
			.y4rs-files{margin:6px 0;padding:0;list-style:none}.y4rs-files li{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:6px 10px;margin:0 0 6px;border:1px solid #e6e7e9;border-radius:8px;background:#f9fafb}
			.y4rs-name{flex:1;min-width:160px;font-family:monospace;font-size:12px;overflow-wrap:anywhere}.y4rs-meta{color:#646970;font-size:12px}.y4rs-files form{margin:0}
			.y4rs-url{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}.y4rs-url input[type=url]{flex:1;min-width:220px}.y4rs-none{color:#646970}.y4rs-tip{font-size:12px;color:#646970}.y4rs-tip code{font-size:11px}.y4rs-keep{margin:0}</style>
		<?php
	}

	/** Review ▸ "Use this file": the main post's download page points at its own file again, with its size and version. */
	public static function restore() {
		$pid = absint($_POST['post'] ?? 0);
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_restore_' . $pid);
		$back = function ($args) {
			wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=y4apk-review')));
			exit;
		};
		if (!empty($_POST['keep'])) {
			delete_post_meta($pid, self::RESTORE);
			$back(['restored' => 'kept']);
		}
		$keys = [];
		foreach ((array) get_post_meta($pid, self::RESTORE, true) as $f) {
			$keys = array_merge($keys, (array) ($f['keys'] ?? []));
		}
		$rows = self::rows_on($pid, $keys);
		$i = (int) ($_POST['row'] ?? -1);
		if (!isset($rows[$i])) {
			$back(['restored' => 'done']);
		}
		$key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
		$url = $key !== '' ? Y4APK_R2::public_url($key) : esc_url_raw(trim(wp_unslash($_POST['url'] ?? '')), ['https', 'http']);
		if ($url === '' || in_array($key, $keys, true)) {
			$back(['restored' => 'fail']);
		}
		$ok = Y4APK_R2::point_folder($rows[$i]['folder'], $url);
		if (is_wp_error($ok)) {
			$back(['restored' => 'fail', 'why' => rawurlencode($ok->get_error_message())]);
		}
		// Its size and version, from the file's name on R2 ("…-MOD-v6.0.30-Y4APK.COM-…") or as typed
		$links = get_post_meta($pid, 'repeatable_download_link', true);
		$was = (string) $links[$i]['download_version'];
		foreach ((array) get_post_meta($pid, self::RESTORE, true) as $f) {
			if (in_array($rows[$i]['key'], (array) ($f['keys'] ?? []), true) && !empty($f['version'])) {
				$was = (string) $f['version']; // the member's version, as the merge wrote it into the title
			}
		}
		$v = Y4APK_App_Updates::number(sanitize_text_field(wp_unslash($_POST['version'] ?? '')));
		if ($v === '' && preg_match('/-v(\d+(?:\.\d+)+)-/i', basename($url), $m)) {
			$v = $m[1];
		}
		if ($key !== '') {
			$size = Y4APK_R2::size_of($key);
			if ($size > 0) {
				$links[$i]['download_size'] = Y4APK_R2::size_text($size);
			}
		}
		if ($v !== '') {
			$links[$i]['download_version'] = $v;
		}
		update_post_meta($pid, 'repeatable_download_link', $links);
		list($main) = Y4APK_App_Updates::download($pid);
		if ((int) $main === $i) {
			if (!empty($links[$i]['download_size'])) {
				update_post_meta($pid, 'wp_sizes_GP', $links[$i]['download_size']);
			}
			if ($v !== '' && $was !== '' && $v !== $was) {
				update_post_meta($pid, 'wp_version_GP', $v);
				// The title and Rank Math's titles had the member's version put in: the post's own one again
				global $wpdb;
				$title = get_post_field('post_title', $pid, 'raw');
				if (strpos($was, '.') !== false && strpos($title, $was) !== false) {
					$wpdb->update($wpdb->posts, ['post_title' => str_replace($was, $v, $title)], ['ID' => $pid]); // not "Updated just now" again
				}
				foreach (['rank_math_title', 'rank_math_description', 'rank_math_facebook_title', 'rank_math_twitter_title'] as $k) {
					$value = (string) get_post_meta($pid, $k, true);
					if (strpos($was, '.') !== false && $value !== '' && strpos($value, $was) !== false) {
						update_post_meta($pid, $k, wp_slash(str_replace($was, $v, $value)));
					}
				}
			}
		}
		self::purge($pid);
		if (!self::rows_on($pid, $keys)) {
			delete_post_meta($pid, self::RESTORE);
		}
		$back(['restored' => 'ok']);
	}
}

Y4APK_Versions::init();
