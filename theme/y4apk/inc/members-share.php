<?php
/**
 * Share a game or app (2.44): members send an APK, XAPK, APKS, APKM, ZIP or OBB
 * — up to 10 GB — for a game or app, from /upload/game/ or /upload/app/.
 *
 *   1. What: a Google Play link, package or name finds it on Google Play (its
 *      name, icon, developer, version) or on the site (then it is a newer
 *      version of that post); or it is typed in, with an icon, when it is not on
 *      Google Play.
 *   2. Category: the Games or Apps categories of the header's menu.
 *   3. Details: version, what is modded, Android version, a few words.
 *   4. File: from the browser straight to Cloudflare R2 (S3 multipart upload:
 *      parts of 16 MB or more, four at a time, through links this server signs),
 *      so a 10 GB file never passes through this server. A failed part is tried
 *      again; a stopped upload carries on where it stopped, even after the page
 *      was closed (the same file chosen again).
 *   5. Review and send: the post waits, hidden, in Y4APK ▸ Review.
 *
 * Approving makes the post like the site's own: a download folder in files/up
 * that points at the file on R2 (as App updates makes them), the download rows,
 * App Information, the category; then it is published at once, or written by
 * Write with AI first (article, icon, screenshots, details) and published when
 * the article is in. A version of a game or app the site already has is the
 * member's own post (2.46), linked to that main post (_y4apk_parent): the main
 * post lists it under "Versions from members", like a Roblox game lists its
 * scripts, and the main post and its file are never touched.
 *
 * Files sent but never used (a wizard left half way) are deleted from R2 after
 * two days, unfinished uploads are cancelled, and a waiting post that is deleted
 * takes its files with it.
 */

defined('ABSPATH') || exit;

class Y4APK_Share {
	const META     = '_y4apk_share';       // the member's answers: kind, package, name, play, version, mods, requires, desc, files, update_of, at
	const LIVE     = '_y4apk_share_live';  // its files are in use (published, or merged into another post): never deleted with it
	const OK       = '_y4apk_share_ok';    // approved, Write with AI writing it before it goes live
	const MERGED   = '_y4apk_share_into';  // 2.44–2.45 only: a version put into this post (now undone, see unmerge())
	const PARENT   = '_y4apk_parent';      // 2.46: a member's own post of a game already on the site, shown under that post
	const SHORT_DESC = 60;                 // fewer words than this from the member: Write with AI writes the version's article (2.47)
	const DONE_UP  = '_y4apk_share_files'; // user meta: [R2 key => [size, name, time]] finished uploads not used yet
	const OPEN_UP  = '_y4apk_share_open';  // user meta: [upload ID => [key, time]] uploads not finished
	const OPTION   = 'y4apk_share';
	const HOOK     = 'y4apk_share_clean';
	const TYPES    = ['apk', 'xapk', 'apks', 'apkm', 'zip', 'obb'];
	const ZIPPED   = ['apk', 'xapk', 'apks', 'apkm', 'zip']; // start with "PK"
	const MODS     = ['Unlimited Money', 'Unlimited Gems', 'Premium Unlocked', 'Pro Unlocked', 'No Ads', 'Mod Menu', 'Unlocked All', 'God Mode', 'High Damage', 'Free Shopping'];

	public static function init() {
		foreach (['find', 'start', 'parts', 'complete', 'abort'] as $a) {
			add_action('wp_ajax_y4apk_share_' . $a, [__CLASS__, 'ajax_' . $a]);
		}
		add_action('admin_post_y4apk_share_publish', [__CLASS__, 'publish']);
		add_action('admin_post_y4apk_share_settings', [__CLASS__, 'save_settings']);
		add_action('init', [__CLASS__, 'schedule']);
		add_action(self::HOOK, [__CLASS__, 'clean']);
		add_action('trashed_post', [__CLASS__, 'trashed']);
		add_action('before_delete_post', [__CLASS__, 'trashed']);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], ['on' => 1, 'max_gb' => 10, 'per_day' => 3, 'max_pending' => 5, 'ai' => 1]);
		$o['max_gb'] = max(1, min(50, (int) $o['max_gb']));
		$o['per_day'] = max(1, min(50, (int) $o['per_day']));
		$o['max_pending'] = max(1, min(50, (int) $o['max_pending']));
		return $o;
	}

	public static function max_bytes() {
		return (float) self::settings()['max_gb'] * 1073741824;
	}

	/** Members may share: on here, and R2 can take files. */
	public static function open() {
		return (bool) self::settings()['on'] && class_exists('Y4APK_R2') && Y4APK_R2::can_store();
	}

	public static function save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_share_settings');
		$p = wp_unslash($_POST);
		update_option(self::OPTION, ['on' => !empty($p['on']) ? 1 : 0, 'max_gb' => (int) ($p['max_gb'] ?? 10), 'per_day' => (int) ($p['per_day'] ?? 3),
			'max_pending' => (int) ($p['max_pending'] ?? 5), 'ai' => !empty($p['ai']) ? 1 : 0], false);
		wp_safe_redirect(add_query_arg(['page' => 'y4apk-members', 'saved' => 'share'], admin_url('admin.php')) . '#y4-share');
		exit;
	}

	/** The card in Y4APK ▸ Members. */
	public static function settings_card() {
		$o = self::settings();
		$r2 = class_exists('Y4APK_R2') && Y4APK_R2::can_store();
		?>
		<h2 id="y4-share"><?php esc_html_e('Games and apps members share', 'y4apk'); ?></h2>
		<?php if (isset($_GET['saved']) && $_GET['saved'] === 'share') : ?><div class="notice notice-success inline"><p><?php esc_html_e('Saved.', 'y4apk'); ?></p></div><?php endif; ?>
		<p class="description" style="max-width:780px"><?php esc_html_e('Members send a game’s or app’s file (APK, XAPK, APKS, APKM, ZIP, OBB) from /upload/game/ or /upload/app/. It goes from their browser straight to Cloudflare R2 and waits in Review; approving it makes the download folder and the post.', 'y4apk'); ?></p>
		<?php if (!$r2) : ?><div class="notice notice-warning inline"><p><?php esc_html_e('Cloudflare R2 is not set up (Y4APK ▸ App updates ▸ R2 settings), so members cannot send files yet.', 'y4apk'); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="y4apk_share_settings"><?php wp_nonce_field('y4apk_share_settings'); ?>
			<table class="form-table" role="presentation" style="max-width:780px"><tbody>
				<tr><th><?php esc_html_e('Sharing', 'y4apk'); ?></th><td><label><input type="checkbox" name="on" value="1" <?php checked($o['on']); ?>> <?php esc_html_e('Members may share games and apps', 'y4apk'); ?></label></td></tr>
				<tr><th><label for="y4-share-gb"><?php esc_html_e('Biggest file', 'y4apk'); ?></label></th><td><input id="y4-share-gb" type="number" name="max_gb" min="1" max="50" value="<?php echo esc_attr($o['max_gb']); ?>" class="small-text"> GB</td></tr>
				<tr><th><label for="y4-share-day"><?php esc_html_e('Per member', 'y4apk'); ?></label></th><td><input id="y4-share-day" type="number" name="per_day" min="1" max="50" value="<?php echo esc_attr($o['per_day']); ?>" class="small-text"> <?php esc_html_e('a day, and at most', 'y4apk'); ?> <input type="number" name="max_pending" min="1" max="50" value="<?php echo esc_attr($o['max_pending']); ?>" class="small-text"> <?php esc_html_e('waiting for review', 'y4apk'); ?></td></tr>
				<tr><th><?php esc_html_e('Approving', 'y4apk'); ?></th><td><label><input type="checkbox" name="ai" value="1" <?php checked($o['ai']); ?>> <?php esc_html_e('Have Write with AI write the article, icon and screenshots first (the choice is ticked in Review)', 'y4apk'); ?></label></td></tr>
			</tbody></table>
			<?php submit_button(__('Save', 'y4apk'), 'secondary'); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * What a post is
	 * ---------------------------------------------------------------- */

	public static function data($post_id) {
		$d = get_post_meta($post_id, self::META, true);
		return is_array($d) ? wp_parse_args($d, ['kind' => 'game', 'package' => '', 'name' => '', 'play' => [], 'icon' => 0, 'version' => '', 'mods' => '', 'requires' => '',
			'desc' => '', 'files' => [], 'update_of' => 0, 'at' => 0]) : null;
	}

	public static function is_share($post_id) {
		return (bool) get_post_meta($post_id, self::META, true);
	}

	/** The Games and Apps categories the header's menu shows: ['game' => [root, children], 'app' => …]. */
	public static function roots() {
		static $r = null;
		if ($r !== null) {
			return $r;
		}
		$r = [];
		foreach (['game' => ['menu_select_1', 'games'], 'app' => ['menu_select_2', 'apps']] as $kind => $how) {
			$id = (int) get_theme_mod($how[0]);
			$root = $id ? get_category($id) : null;
			if (!$root || is_wp_error($root)) {
				$root = get_category_by_slug($how[1]) ?: null;
			}
			$kids = $root ? get_categories(['parent' => $root->term_id, 'hide_empty' => false, 'exclude' => class_exists('Y4APK_Roblox') ? Y4APK_Roblox::categories() : []]) : [];
			$r[$kind] = [$root, is_array($kids) ? $kids : []];
		}
		return $r;
	}

	/** The categories a member picks from: [['id', 'name', 'icon', 'count'], …]. */
	public static function categories($kind) {
		$roots = self::roots();
		$out = [];
		foreach ($roots[$kind === 'app' ? 'app' : 'game'][1] as $t) {
			$out[] = ['id' => (int) $t->term_id, 'name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'icon' => (string) get_term_meta($t->term_id, 'category_icon', true), 'count' => (int) $t->count];
		}
		return $out;
	}

	/** Is $term_id one of those categories (or the root)? */
	private static function category_ok($kind, $term_id) {
		$roots = self::roots();
		list($root, $kids) = $roots[$kind === 'app' ? 'app' : 'game'];
		if ($root && (int) $root->term_id === (int) $term_id) {
			return true;
		}
		foreach ($kids as $t) {
			if ((int) $t->term_id === (int) $term_id) {
				return true;
			}
		}
		return false;
	}

	/** "APK", "XAPK", "OBB"… for a file name, as the download rows call them. */
	public static function label($name) {
		$ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
		return in_array($ext, self::TYPES, true) ? strtoupper($ext) : 'APK';
	}

	/** "by name" in a saved title: in the site's language, whatever the page's (2.51). */
	private static function by($user) {
		return Y4APK_I18n::untranslated(function () use ($user) {
			return sprintf(__('by %s', 'y4apk'), Y4APK_Social::name($user));
		});
	}

	/** "Name MOD APK v1.2.3 (Unlimited Money)", as the site's titles go. */
	public static function title($name, $version, $mods, $label = 'APK') {
		$v = Y4APK_App_Updates::number($version);
		$mods = trim((string) $mods);
		$orig = $mods === '' || strtolower($mods) === 'original';
		$type = in_array($label, ['XAPK', 'APKS', 'APKM'], true) ? $label : 'APK';
		return trim($name . ($orig ? ' ' : ' MOD ') . $type . ($v !== '' ? ' v' . $v : '') . ($orig ? '' : ' (' . $mods . ')'));
	}

	/* ---------------------------------------------------------------- *
	 * Finding the game or app (AJAX)
	 * ---------------------------------------------------------------- */

	/** The member who may send: the signed-in, verified, not banned member, or a JSON error. */
	private static function member() {
		check_ajax_referer('y4apk_share', 'nonce');
		$u = wp_get_current_user();
		if (!$u->exists() || !Y4APK_Members::is_member($u) || !Y4APK_Members::is_verified($u->ID) || get_user_meta($u->ID, Y4APK_Members::BANNED, true)) {
			wp_send_json_error(['message' => __('Sign in with a confirmed account to share.', 'y4apk')], 403);
		}
		if (!self::open()) {
			wp_send_json_error(['message' => __('Sharing games and apps is closed at the moment.', 'y4apk')], 400);
		}
		return $u;
	}

	/** A post on the site, as the wizard shows it. */
	private static function site_card($id) {
		$icon = get_the_post_thumbnail_url($id, 'thumbnail');
		return ['id' => (int) $id, 'title' => html_entity_decode(wp_strip_all_tags(get_the_title($id)), ENT_QUOTES, 'UTF-8'), 'name' => html_entity_decode((string) get_post_meta($id, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8'),
			'icon' => $icon ? $icon : '', 'version' => Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($id)), 'url' => get_permalink($id),
			'package' => Y4APK_App_Updates::package_from(get_post_meta($id, 'wp_GP_ID', true))];
	}

	/** The wizard's "Popular on Y4APK" (2.49): the most read games (or apps) on the site, main posts only, kept 6 hours. */
	public static function popular($kind, $n = 8) {
		$kind = $kind === 'app' ? 'app' : 'game';
		$key = 'y4apk_share_pop_' . $kind;
		$ids = get_transient($key);
		if (!is_array($ids)) {
			$root = self::roots()[$kind][0];
			$ids = $root ? get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => $n, 'fields' => 'ids', 'cat' => (int) $root->term_id,
				'meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true,
				'meta_query' => [['key' => self::PARENT, 'compare' => 'NOT EXISTS']], 'tax_query' => class_exists('Y4APK_Roblox') ? Y4APK_Roblox::not_roblox() : []]) : [];
			set_transient($key, array_map('intval', $ids), 6 * HOUR_IN_SECONDS);
		}
		$out = [];
		foreach ($ids as $id) {
			if (get_post_status($id) === 'publish') {
				$out[] = self::site_card($id);
			}
		}
		return $out;
	}

	/** An app on Google Play, as the wizard shows it (kept 12 hours). */
	private static function play_card($package) {
		$i = Y4APK_Discover::info($package);
		if ($i === null || !empty($i['missing'])) {
			return $i === null ? null : false;
		}
		list($packages) = Y4APK_Discover::on_site();
		$have = isset($packages[strtolower($package)]) ? (int) $packages[strtolower($package)] : 0;
		$have = $have && get_post_status($have) === 'publish' ? self::site_card($have) : null;
		return ['package' => $package, 'title' => $i['title'], 'developer' => $i['developer'], 'icon' => $i['icon'], 'genre' => $i['genre'], 'game' => $i['game'],
			'rating' => $i['rating'], 'version' => $i['version'], 'url' => $i['url'], 'have' => $have];
	}

	public static function ajax_find() {
		$u = self::member();
		$q = trim(mb_substr(sanitize_text_field(wp_unslash($_POST['q'] ?? '')), 0, 200));
		$kind = ($_POST['kind'] ?? '') === 'app' ? 'app' : 'game';
		if (mb_strlen($q) < 2) {
			wp_send_json_success(['site' => [], 'play' => []]);
		}
		$package = Y4APK_App_Updates::package_from($q);
		$out = ['site' => [], 'play' => [], 'busy' => false];
		if ($package === '') {
			// On the site: by name, in the Games or Apps categories
			$roots = self::roots();
			$cats = array_filter([$roots['game'][0] ? $roots['game'][0]->term_id : 0, $roots['app'][0] ? $roots['app'][0]->term_id : 0]);
			$ids = get_posts(['post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 5, 'fields' => 'ids', 'category__in' => $cats ?: [0], 'no_found_rows' => true,
				'meta_query' => [['key' => self::PARENT, 'compare' => 'NOT EXISTS']]]); // main posts: a version goes under one
			foreach ($ids as $id) {
				$out['site'][] = self::site_card($id);
			}
		}
		if (!Y4APK_Members::hit('share_find_' . $u->ID, 60, HOUR_IN_SECONDS)) {
			$out['busy'] = true;
			wp_send_json_success($out);
		}
		$found = $package !== '' ? [$package] : [];
		if ($package === '') {
			$key = 'y4apk_share_q_' . md5(strtolower($q));
			$found = get_transient($key);
			if (!is_array($found)) {
				$r = Y4APK_Discover::find($q, '');
				$found = is_wp_error($r) ? null : array_merge($r['new'], array_map(function ($h) { return $h[0]; }, $r['have']));
				if ($found !== null) {
					set_transient($key, $found, 6 * HOUR_IN_SECONDS);
				}
			}
			$found = is_array($found) ? array_slice($found, 0, 4) : [];
		}
		foreach ($found as $p) {
			$card = self::play_card($p);
			if ($card === null) {
				$out['busy'] = true;
				break;
			}
			if ($card) {
				$out['play'][] = $card;
			}
		}
		wp_send_json_success($out);
	}

	/* ---------------------------------------------------------------- *
	 * The file, straight to R2 (AJAX)
	 * ---------------------------------------------------------------- */

	/** Where a member's file goes on R2: "B/Blox-Fruits-MOD-v1.2-Y4APK.COM-IDv123456.apk", as the bucket names the site's. */
	private static function object_key($name, $version, $filename) {
		$slug = Y4APK_R2::slug($name) ?: 'App';
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$n = Y4APK_App_Updates::number($version);
		$parts = array_filter([$slug, $ext === 'obb' ? 'OBB' : 'MOD', $n !== '' ? 'v' . $n : '', 'Y4APK.COM', 'IDv' . wp_rand(100000, 999999)], 'strlen');
		return (ctype_alpha($slug[0]) ? strtoupper($slug[0]) : 'A') . '/' . implode('-', $parts) . '.' . $ext;
	}

	private static function open_ups($user_id) {
		$o = get_user_meta($user_id, self::OPEN_UP, true);
		return is_array($o) ? $o : [];
	}

	public static function done_ups($user_id) {
		$o = get_user_meta($user_id, self::DONE_UP, true);
		return is_array($o) ? $o : [];
	}

	private static function upload_of($u, $id) {
		$up = get_transient('y4apk_share_up_' . md5((string) $id));
		if (!is_array($up) || (int) $up['user'] !== (int) $u->ID) {
			wp_send_json_error(['message' => __('That upload is not known here any more. Choose the file again to start over.', 'y4apk'), 'gone' => 1], 400);
		}
		return $up;
	}

	public static function ajax_start() {
		$u = self::member();
		$name = sanitize_file_name(wp_unslash($_POST['name'] ?? ''));
		$size = (float) ($_POST['size'] ?? 0);
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($ext, self::TYPES, true)) {
			wp_send_json_error(['message' => __('Send an APK, XAPK, APKS, APKM, ZIP or OBB file.', 'y4apk')], 400);
		}
		if ($size < 1024) {
			wp_send_json_error(['message' => __('That file is empty.', 'y4apk')], 400);
		}
		if ($size > self::max_bytes()) {
			wp_send_json_error(['message' => sprintf(__('Files can be up to %s.', 'y4apk'), size_format(self::max_bytes()))], 400);
		}
		// A stopped upload of the same file carries on
		$resume = sanitize_text_field(wp_unslash($_POST['resume'] ?? ''));
		if ($resume !== '') {
			$up = get_transient('y4apk_share_up_' . md5($resume));
			if (is_array($up) && (int) $up['user'] === (int) $u->ID && (float) $up['size'] === $size && $up['name'] === $name) {
				wp_send_json_success(['uploadId' => $resume, 'key' => $up['key'], 'partSize' => $up['part'], 'count' => $up['count'], 'resumed' => 1,
					'urls' => Y4APK_R2::mp_urls($up['key'], $resume, $up['count'], range(1, min(50, $up['count'])))]);
			}
		}
		if (!Y4APK_Members::hit('share_up_' . $u->ID, 12, DAY_IN_SECONDS)) {
			wp_send_json_error(['message' => __('You started many uploads today. Try again tomorrow.', 'y4apk')], 429);
		}
		$open = self::open_ups($u->ID);
		if (count($open) >= 4) { // the oldest stopped one makes room
			reset($open);
			$old = key($open);
			Y4APK_R2::mp_abort($open[$old]['key'], $old);
			delete_transient('y4apk_share_up_' . md5((string) $old));
			unset($open[$old]);
		}
		$title = trim(mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 80));
		$version = trim(mb_substr(sanitize_text_field(wp_unslash($_POST['version'] ?? '')), 0, 40));
		$key = self::object_key($title !== '' ? $title : pathinfo($name, PATHINFO_FILENAME), $version, $name);
		$id = Y4APK_R2::mp_start($key, $ext === 'apk' ? 'application/vnd.android.package-archive' : 'application/octet-stream');
		if (is_wp_error($id)) {
			wp_send_json_error(['message' => __('The file storage did not answer. Try again in a minute.', 'y4apk')], 502);
		}
		list($part, $count) = Y4APK_R2::mp_plan($size);
		set_transient('y4apk_share_up_' . md5($id), ['id' => $id, 'key' => $key, 'size' => $size, 'part' => $part, 'count' => $count, 'user' => (int) $u->ID, 'name' => $name, 'time' => time()], 3 * DAY_IN_SECONDS);
		$open[$id] = ['key' => $key, 'time' => time()];
		update_user_meta($u->ID, self::OPEN_UP, $open);
		wp_send_json_success(['uploadId' => $id, 'key' => $key, 'partSize' => $part, 'count' => $count, 'urls' => Y4APK_R2::mp_urls($key, $id, $count, range(1, min(50, $count)))]);
	}

	public static function ajax_parts() {
		$u = self::member();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$up = self::upload_of($u, $id);
		wp_send_json_success(['urls' => Y4APK_R2::mp_urls($up['key'], $id, $up['count'], (array) wp_unslash($_POST['numbers'] ?? []))]);
	}

	public static function ajax_complete() {
		$u = self::member();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$up = self::upload_of($u, $id);
		$etags = array_map('sanitize_text_field', (array) wp_unslash($_POST['etags'] ?? []));
		$size = Y4APK_R2::mp_complete($up['key'], $id, $up['count'], $etags);
		if (is_wp_error($size)) {
			wp_send_json_error(['message' => $size->get_error_message()], 502);
		}
		$open = self::open_ups($u->ID);
		unset($open[$id]);
		update_user_meta($u->ID, self::OPEN_UP, $open);
		delete_transient('y4apk_share_up_' . md5($id));
		$ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
		$bad = (float) $size !== (float) $up['size'] ? sprintf(__('The storage has %1$s of this file, not %2$s. Send it again.', 'y4apk'), size_format($size), size_format($up['size'])) : '';
		if ($bad === '' && in_array($ext, self::ZIPPED, true) && substr(Y4APK_R2::first_bytes($up['key'], 4), 0, 2) !== 'PK') {
			$bad = __('This is not an APK, XAPK, APKS, APKM or ZIP file inside. Check that it opens on a phone, then send it again.', 'y4apk');
		}
		// 2.50: what it really is, from inside (a few small reads): the package name
		$check = $bad === '' && class_exists('Y4APK_Apk_Check') ? Y4APK_Apk_Check::inspect($up['key'], $size, $up['name']) : null;
		if ($check && $check['why'] === 'nomanifest') {
			$bad = __('This APK has no app inside. Check that it installs on a phone, then send it again.', 'y4apk');
		}
		if ($bad !== '') {
			Y4APK_R2::remove($up['key']);
			wp_send_json_error(['message' => $bad], 400);
		}
		$done = self::done_ups($u->ID);
		$done[$up['key']] = ['size' => $size, 'name' => $up['name'], 'time' => time(), 'check' => $check];
		update_user_meta($u->ID, self::DONE_UP, array_slice($done, -10, null, true));
		wp_send_json_success(['key' => $up['key'], 'size' => $size, 'sizeText' => Y4APK_R2::size_text($size), 'label' => self::label($up['name'])]);
	}

	public static function ajax_abort() {
		$u = self::member();
		$id = sanitize_text_field(wp_unslash($_POST['uploadId'] ?? ''));
		$up = get_transient('y4apk_share_up_' . md5($id));
		if (is_array($up) && (int) $up['user'] === (int) $u->ID) {
			Y4APK_R2::mp_abort($up['key'], $id);
			delete_transient('y4apk_share_up_' . md5($id));
			$open = self::open_ups($u->ID);
			unset($open[$id]);
			update_user_meta($u->ID, self::OPEN_UP, $open);
		}
		// A finished file the member took back
		$key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
		$done = self::done_ups($u->ID);
		if ($key !== '' && isset($done[$key])) {
			Y4APK_R2::remove($key);
			unset($done[$key]);
			update_user_meta($u->ID, self::DONE_UP, $done);
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------- *
	 * The wizard's form (Y4APK_Members routes /upload/game/ and /upload/app/ here)
	 * ---------------------------------------------------------------- */

	/** What the wizard opens with: $ctx['share'] for template/share.php. */
	public static function view(&$ctx) {
		$user = $ctx['user'];
		$post = null;
		if ($ctx['view'] === 'edit') {
			$post = get_post(absint(get_query_var('y4_id')));
			if (!$post || (int) $post->post_author !== (int) $user->ID || !self::is_share($post->ID) || in_array($post->post_status, ['publish', 'trash'], true) || get_post_meta($post->ID, self::OK, true)) {
				$ctx['screen'] = 'notfound'; // a live one is changed by sending a newer version
				return;
			}
		}
		$kind = $post ? self::data($post->ID)['kind'] : (get_query_var('y4_kind') === 'app' ? 'app' : 'game');
		$ctx['share'] = ['kind' => $kind, 'post' => $post, 'cats' => ['game' => self::categories('game'), 'app' => self::categories('app')], 'max' => self::max_bytes(),
			'popular' => $post ? [] : self::popular($kind),
			'done' => self::done_ups($user->ID)];
		if (empty($ctx['old'])) {
			$d = $post ? self::data($post->ID) : null;
			$ctx['old'] = $d ? ['kind' => $d['kind'], 'package' => $d['package'], 'name' => $d['name'], 'play' => $d['play'], 'cat' => (int) (wp_get_post_categories($post->ID)[0] ?? 0),
				'version' => $d['version'], 'mods' => $d['mods'], 'requires' => $d['requires'], 'desc' => $d['desc'], 'files' => $d['files'], 'update_of' => (int) $d['update_of'], 'icon' => (int) $d['icon']]
				: ['kind' => $kind, 'package' => '', 'name' => '', 'play' => [], 'cat' => 0, 'version' => '', 'mods' => '', 'requires' => '', 'desc' => '', 'files' => [], 'update_of' => 0, 'icon' => 0];
			// "Newer version" on My uploads, or a post on the site: that post, ready for its new file
			$newer = !$post && isset($_GET['newer']) ? get_post(absint($_GET['newer'])) : null;
			if ($newer && ($up = (int) get_post_meta($newer->ID, self::PARENT, true)) && get_post($up)) {
				$newer = get_post($up); // a version of a version: under the same main post
			}
			if ($newer && $newer->post_type === 'post' && $newer->post_status === 'publish') {
				$ctx['old']['update_of'] = (int) $newer->ID;
				$ctx['old']['name'] = html_entity_decode((string) get_post_meta($newer->ID, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8') ?: wp_strip_all_tags(get_the_title($newer));
				$ctx['old']['package'] = Y4APK_App_Updates::package_from(get_post_meta($newer->ID, 'wp_GP_ID', true));
				$ctx['old']['mods'] = (string) get_post_meta($newer->ID, 'wp_mods', true);
			}
		}
		if (!Y4APK_Members::is_verified($user->ID)) {
			$ctx['screen'] = 'unverified';
		} elseif (!self::open() && !$post) {
			$ctx['screen'] = 'share_closed';
		} else {
			$ctx['screen'] = 'share';
		}
	}

	/**
	 * A member's post becomes a version under a main post (one sent before
	 * 2.46, when versions went into the main post): the main post's ID.
	 */
	public static function adopt($id, $parent) {
		$parent = (int) get_post_meta($parent, self::PARENT, true) ?: (int) $parent; // a version of a version: under the same main post
		$d = self::data($id);
		$post = get_post($id);
		$user = $post ? get_userdata($post->post_author) : null;
		if (!$d || !$post) {
			return 0;
		}
		$main = $d['files'] ? $d['files'][0] : ['label' => 'APK'];
		$title = self::title($d['name'], $d['version'], $d['mods'], $main['label']) . ($user ? ' ' . self::by($user) : '');
		wp_update_post(wp_slash(['ID' => $id, 'post_title' => $title, 'post_category' => wp_get_post_categories($parent), 'post_date' => $post->post_date, 'edit_date' => true, 'post_parent' => $parent])); // still "sent 2 days ago"
		update_post_meta($id, self::PARENT, $parent);
		delete_post_meta($id, 'wp_GP_ID'); // Google Play stays with the main post (App updates, Write with AI)
		if (!get_post_thumbnail_id($id) && get_post_thumbnail_id($parent)) {
			set_post_thumbnail($id, get_post_thumbnail_id($parent));
		}
		$d['update_of'] = $parent;
		update_post_meta($id, self::META, $d);
		return $parent;
	}

	/** Reads the form: [data, errors, old (to fill the form again)]. */
	private static function read($user, $existing = null) {
		$p = wp_unslash($_POST);
		$err = [];
		$step = 5;
		$kind = ($p['kind'] ?? '') === 'app' ? 'app' : 'game';
		$package = Y4APK_App_Updates::package_from((string) ($p['package'] ?? ''));
		$update_of = absint($p['update_of'] ?? 0);
		$name = trim(mb_substr(sanitize_text_field((string) ($p['name'] ?? '')), 0, 80));
		$play = [];
		if ($update_of) {
			$up = (int) get_post_meta($update_of, self::PARENT, true);
			$update_of = $up ?: $update_of; // a version of a version: under the same main post
			$target = get_post($update_of);
			if (!$target || $target->post_status !== 'publish' || $target->post_type !== 'post') {
				$update_of = 0;
			} else {
				$name = $name !== '' ? $name : html_entity_decode((string) get_post_meta($update_of, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8');
				$package = $package !== '' ? $package : Y4APK_App_Updates::package_from(get_post_meta($update_of, 'wp_GP_ID', true));
			}
		}
		if ($package !== '') {
			$i = Y4APK_Discover::info($package); // kept 12 hours since the wizard asked
			if (is_array($i) && empty($i['missing'])) {
				$play = ['title' => $i['title'], 'developer' => $i['developer'], 'icon' => $i['icon'], 'genre' => $i['genre'], 'rating' => $i['rating'], 'version' => $i['version'], 'game' => !empty($i['game'])];
				$name = $name !== '' ? $name : $i['title'];
			}
		}
		if (mb_strlen($name) < 2) {
			$err[] = __('Find the game or app, or type its name.', 'y4apk');
			$step = 1;
		}
		$cat = absint($p['cat'] ?? 0);
		if ($update_of) {
			$cat = (int) (wp_get_post_categories($update_of)[0] ?? 0);
		} elseif (!self::category_ok($kind, $cat)) {
			$err[] = __('Choose its category.', 'y4apk');
			$step = min($step, 2);
		}
		$version = trim(mb_substr(sanitize_text_field((string) ($p['version'] ?? '')), 0, 40));
		if (Y4APK_App_Updates::number($version) === '') {
			$err[] = __('Write the version of the file, like 1.2.3.', 'y4apk');
			$step = min($step, 3);
		}
		$mods = [];
		foreach ((array) ($p['mod'] ?? []) as $m) {
			$m = trim(mb_substr(sanitize_text_field((string) $m), 0, 30));
			if ($m !== '') {
				$mods[] = $m;
			}
		}
		$mod_more = trim(mb_substr(sanitize_text_field((string) ($p['mod_more'] ?? '')), 0, 80));
		if ($mod_more !== '') {
			$mods[] = $mod_more;
		}
		$mods = in_array('Original', $mods, true) ? 'Original' : implode(', ', array_unique($mods));
		$requires = trim(mb_substr(preg_replace('/[^0-9.]/', '', (string) ($p['requires'] ?? '')), 0, 8));
		$desc = trim(mb_substr(sanitize_textarea_field((string) ($p['desc'] ?? '')), 0, 1500));
		// The files: ones this member finished here (or the post already has)
		$have = $existing ? self::data($existing->ID)['files'] : [];
		$have_keys = array_column($have, null, 'key');
		$done = self::done_ups($user->ID);
		$files = [];
		foreach (array_slice((array) ($p['file'] ?? []), 0, 2) as $key) {
			$key = sanitize_text_field((string) $key);
			if (isset($done[$key])) {
				$files[] = ['key' => $key, 'size' => (float) $done[$key]['size'], 'name' => $done[$key]['name'], 'label' => self::label($done[$key]['name']),
					'check' => isset($done[$key]['check']) && is_array($done[$key]['check']) ? $done[$key]['check'] : null];
			} elseif (isset($have_keys[$key])) {
				$files[] = $have_keys[$key];
			}
		}
		if (!$files) {
			$err[] = __('Upload the file first.', 'y4apk');
			$step = min($step, 4);
		} elseif (count($files) === 2 && $files[0]['label'] === 'OBB' && $files[1]['label'] !== 'OBB') {
			$files = array_reverse($files); // the app first, its data second
		}
		$icon = $existing ? (int) self::data($existing->ID)['icon'] : 0;
		if (!$play && !$update_of && !$icon && empty($_FILES['icon']['name'])) {
			$err[] = __('Add the game’s or app’s icon: it is not on Google Play, so we have no picture of it.', 'y4apk');
			$step = 1; // the icon is picked in step 1
		}
		if (empty($p['agree'])) {
			$err[] = __('Tick the box to say the file is safe and fine to share.', 'y4apk');
		}
		$old = ['kind' => $kind, 'package' => $package, 'name' => $name, 'play' => $play, 'cat' => $cat, 'version' => $version, 'mods' => $mods, 'requires' => $requires, 'desc' => $desc,
			'files' => $files, 'update_of' => $update_of, 'icon' => $icon, 'step' => $step, 'agree' => !empty($p['agree'])];
		return [$old, $err];
	}

	/** An icon sent with the form: its attachment ID, 0 when none, or a WP_Error. */
	private static function take_icon($post_id) {
		if (empty($_FILES['icon']['name'])) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		if ((int) $_FILES['icon']['size'] > 5 * MB_IN_BYTES) {
			return new WP_Error('icon', __('The icon can be up to 5 MB.', 'y4apk'));
		}
		$info = @getimagesize($_FILES['icon']['tmp_name']);
		if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] < 96 || $info[1] < 96) {
			return new WP_Error('icon', __('The icon must be a PNG, JPG or WebP picture, at least 96 × 96.', 'y4apk'));
		}
		$id = media_handle_upload('icon', $post_id, [], ['test_form' => false, 'mimes' => ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp']]);
		return is_wp_error($id) ? new WP_Error('icon', __('The icon could not be saved. Try another picture.', 'y4apk')) : (int) $id;
	}

	/** /upload/game/ and /account/edit/N/ (a game or app): send it for review. */
	public static function submit(&$ctx) {
		$user = $ctx['user'];
		$existing = $ctx['view'] === 'edit' ? get_post(absint(get_query_var('y4_id'))) : null;
		self::view($ctx);
		if ($ctx['screen'] === 'notfound') {
			return;
		}
		list($old, $err) = self::read($user, $existing);
		$ctx['old'] = $old;
		if ($ctx['screen'] !== 'share') {
			return;
		}
		$g = Y4APK_Members::guard_share();
		if ($g !== true) {
			$ctx['errors'][] = $g;
			return;
		}
		if (!$existing) {
			$s = self::settings();
			global $wpdb;
			$today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_author = %d AND p.post_type = 'post' AND p.post_date > %s",
				self::META, $user->ID, wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)));
			$waiting = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_author = %d AND p.post_type = 'post' AND p.post_status = 'pending'",
				self::META, $user->ID));
			if ($today >= $s['per_day']) {
				$ctx['errors'][] = sprintf(_n('You can share %d game or app a day. Come back tomorrow.', 'You can share %d games or apps a day. Come back tomorrow.', $s['per_day'], 'y4apk'), $s['per_day']);
				return;
			}
			if ($waiting >= $s['max_pending']) {
				$ctx['errors'][] = __('You have many uploads waiting for review. Wait for some to be checked.', 'y4apk');
				return;
			}
		}
		if ($err) {
			$ctx['errors'] = $err;
			return;
		}
		$main = $old['files'][0];
		$parent = (int) $old['update_of'];
		$title = self::title($old['name'], $old['version'], $old['mods'], $main['label']);
		if ($parent) {
			$title .= ' ' . self::by($user); // the member's own post, under the main one
		}
		$content = '';
		foreach (preg_split('/\n{2,}/', $old['desc'] !== '' ? $old['desc'] : sprintf(__('%1$s %2$s, shared by @%3$s.', 'y4apk'), $old['name'], $old['version'], $user->user_login)) as $para) {
			$content .= "<!-- wp:paragraph -->\n<p>" . nl2br(esc_html($para)) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		$arr = ['post_title' => $title, 'post_content' => trim($content), 'post_status' => 'pending', 'post_type' => 'post', 'post_author' => $user->ID, 'post_category' => $old['cat'] ? [$old['cat']] : [],
			'post_parent' => $parent]; // 2.47: a version is its main post's child (the list of versions reads post_parent)
		if ($existing) {
			$arr['ID'] = $existing->ID;
			$id = wp_update_post(wp_slash($arr), true);
		} else {
			$id = wp_insert_post(wp_slash($arr), true);
		}
		if (is_wp_error($id) || !$id) {
			$ctx['errors'][] = __('Could not save it. Try again.', 'y4apk');
			return;
		}
		$icon = self::take_icon($id);
		if (is_wp_error($icon)) {
			$ctx['errors'][] = $icon->get_error_message();
			$ctx['old']['step'] = 1;
			if (!$existing) {
				wp_delete_post($id, true);
			}
			return;
		}
		$icon = $icon ?: (int) $old['icon'];
		if (!$icon && $parent) {
			$icon = (int) get_post_thumbnail_id($parent); // the main post's icon
		}
		if ($icon) {
			set_post_thumbnail($id, $icon);
		}
		if ($parent) {
			update_post_meta($id, self::PARENT, $parent);
		} else {
			delete_post_meta($id, self::PARENT);
		}
		// Files no longer in the post (replaced while editing) go from R2
		if ($existing) {
			$keep = array_column($old['files'], 'key');
			foreach (self::data($existing->ID)['files'] as $f) {
				if (!in_array($f['key'], $keep, true)) {
					Y4APK_R2::remove($f['key']);
				}
			}
		}
		$done = self::done_ups($user->ID);
		foreach ($old['files'] as $f) {
			unset($done[$f['key']]);
		}
		update_user_meta($user->ID, self::DONE_UP, $done);
		update_post_meta($id, self::META, ['kind' => $old['kind'], 'package' => $old['package'], 'name' => $old['name'], 'play' => $old['play'], 'icon' => $icon, 'version' => $old['version'],
			'mods' => $old['mods'], 'requires' => $old['requires'], 'desc' => $old['desc'], 'files' => $old['files'], 'update_of' => $old['update_of'], 'at' => time()]);
		update_post_meta($id, Y4APK_Members::MEMBER_POST, 1);
		delete_post_meta($id, Y4APK_Members::REJECT);
		$meta = ['wp_title_GP' => $old['name'], 'wp_version_GP' => Y4APK_App_Updates::number($old['version']), 'wp_sizes_GP' => Y4APK_R2::size_text($main['size']), 'wp_mods' => $old['mods'] === 'Original' ? '' : $old['mods'],
			'wp_requires_GP' => $old['requires'] !== '' ? $old['requires'] : ($parent ? (string) get_post_meta($parent, 'wp_requires_GP', true) : ''),
			'wp_developers_GP' => $parent ? (string) get_post_meta($parent, 'wp_developers_GP', true) : ($old['play']['developer'] ?? ''), 'platform' => 'ANDROID', 'price' => '0'];
		if (!$parent && $old['package'] !== '') {
			$meta['wp_GP_ID'] = $old['package']; // a version under a main post leaves Google Play to that post (App updates, Write with AI)
		}
		foreach ($meta as $k => $val) {
			if ($val !== '') {
				update_post_meta($id, $k, wp_slash($val));
			}
		}
		if (self::file_ok($user, $old, $parent, $id)) {
			// 2.50: its file is really that app: live at once (or once Write with AI has written its page)
			$ai = self::writer_ready();
			$r = self::go_live($id, $parent ? 'now' : ($ai ? 'ai' : 'now'), 0, $parent && $ai && self::words($old['desc']) < self::SHORT_DESC, true);
			if ($r[0] !== 'sharefail') {
				wp_safe_redirect(Y4APK_Members::url('home', ['ok' => $r[0] === 'writing' ? 'autosoon' : 'autolive']));
				exit;
			}
		}
		self::tell_owner($id, $user);
		wp_safe_redirect(Y4APK_Members::url('home', ['ok' => $existing ? 'reshared' : 'shared']));
		exit;
	}

	/**
	 * 2.50: can this upload go live at once? Members ▸ Publish at once is on,
	 * the member is trusted, the game or app is on Google Play (or is a version
	 * of a post here), and every file was read from inside and is that app —
	 * the same package name. A new game or app the site already has waits
	 * (it belongs under that post).
	 */
	public static function file_ok($user, $old, $parent, $post_id = 0) {
		$s = Y4APK_Members::settings();
		$pkg = (string) $old['package'];
		if (empty($s['auto_files']) || !Y4APK_Members::trusted($user->ID) || $pkg === '' || (!$parent && empty($old['play']))
			|| ($post_id && get_post_meta($post_id, Y4APK_Members_Admin::HOLD, true))) { // hidden by reports: the owner looks first
			return false;
		}
		foreach ($old['files'] as $f) {
			$c = isset($f['check']) && is_array($f['check']) ? $f['check'] : null;
			if (!$c || strcasecmp((string) $c['package'], $pkg) !== 0) {
				return false;
			}
		}
		if (!$parent) {
			global $wpdb;
			if ($wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'wp_GP_ID'
				WHERE m.meta_value = %s AND p.post_type = 'post' AND p.post_status = 'publish' AND p.post_parent = 0 LIMIT 1", $pkg))) {
				return false;
			}
		}
		return true;
	}

	/** "Checked: com.example.game ✓" for the Review page: what was read from inside the files. */
	public static function check_line($post_id) {
		$d = self::data($post_id);
		$out = [];
		foreach ($d ? $d['files'] : [] as $f) {
			$c = isset($f['check']) && is_array($f['check']) ? $f['check'] : null;
			if (!$c) {
				continue;
			}
			$out[] = $c['package'] !== ''
				? ($d['package'] !== '' && strcasecmp($c['package'], $d['package']) === 0 ? sprintf(__('%s ✓', 'y4apk'), $c['package']) : sprintf(__('%s (not the chosen app)', 'y4apk'), $c['package']))
				: __('could not be read', 'y4apk');
		}
		return $out ? sprintf(__('File: %s', 'y4apk'), implode(', ', array_unique($out))) : '';
	}

	private static function tell_owner($post_id, $user) {
		if (!Y4APK_Members::settings()['notify'] || !Y4APK_Members::hit('notify_owner', 1, 15 * MINUTE_IN_SECONDS)) {
			return;
		}
		Y4APK_Members::mail(get_option('admin_email'), __('A game or app is waiting for review', 'y4apk'), self::message('waiting', ['name' => $user->user_login, 'title' => wp_strip_all_tags(get_the_title($post_id))]));
	}

	/** The emails about a member's game or app, as Y4APK_Members::message() writes them. */
	public static function message($kind, $d) {
		$site = Y4APK_Members::settings()['from_name'];
		$d = wp_parse_args($d, ['name' => '', 'link' => '', 'title' => '', 'why' => '', 'under' => '', 'have' => '', 'latest' => '']);
		$author = sprintf(__('You got this email because you shared a game or app on %s.', 'y4apk'), $site);
		switch ($kind) {
			case 'live':
				return ['title' => __('Your upload is live 🎉', 'y4apk'), 'preheader' => sprintf(__('“%s” is on the site now.', 'y4apk'), $d['title']), 'hi' => $d['name'],
					'lines' => array_filter([__('Good news: we checked your file and it is live now. Share the link: everyone can download it.', 'y4apk'),
						!empty($d['under']) ? sprintf(__('It is your own post, with your name, and the page of %s lists it under “Versions from members”.', 'y4apk'), $d['under']) : '']),
					'card' => [__('Your upload', 'y4apk'), $d['title']], 'button' => [__('See it on the site', 'y4apk'), $d['link']], 'why' => $author];
			case 'changes':
				return ['title' => __('Your upload needs a change', 'y4apk'), 'preheader' => __('One thing to fix, and it can go live.', 'y4apk'), 'hi' => $d['name'],
					'lines' => [__('We checked your upload and could not publish it yet. Here is what to change:', 'y4apk')],
					'card' => [__('Your upload', 'y4apk'), $d['title']], 'quote' => $d['why'], 'button' => [__('Edit and send again', 'y4apk'), $d['link']], 'why' => $author];
			case 'newer':
				return ['title' => sprintf(__('New version of %s', 'y4apk'), $d['title']), 'preheader' => sprintf(__('v%1$s is out. Yours is v%2$s.', 'y4apk'), $d['latest'], $d['have']), 'hi' => $d['name'],
					'lines' => [sprintf(__('%1$s has a new version, v%2$s. The version you shared is v%3$s.', 'y4apk'), $d['title'], $d['latest'], $d['have']),
						sprintf(__('Share the new file: it shows on the page of %s with your name, next to your other versions.', 'y4apk'), $d['title'])],
					'card' => [__('Your version', 'y4apk'), $d['title'] . ' v' . $d['have']], 'button' => [sprintf(__('Share v%s', 'y4apk'), $d['latest']), $d['link']],
					'why' => sprintf(__('You got this because you shared a version of %s. You can turn these emails off in your account’s Settings.', 'y4apk'), $d['title'])];
			case 'waiting':
				return ['title' => __('A game or app is waiting for review', 'y4apk'), 'preheader' => sprintf(__('@%1$s shared “%2$s”.', 'y4apk'), $d['name'], $d['title']),
					'lines' => [sprintf(__('@%s sent a file. It stays hidden until you approve it.', 'y4apk'), $d['name'])],
					'card' => [__('Upload', 'y4apk'), $d['title']], 'button' => [__('Open Review', 'y4apk'), admin_url('admin.php?page=y4apk-review')],
					'why' => __('You get these because “Email me when a script is waiting” is on in Y4APK ▸ Members.', 'y4apk')];
		}
		return ['lines' => []];
	}

	/* ---------------------------------------------------------------- *
	 * Review (Y4APK ▸ Review shows these cards)
	 * ---------------------------------------------------------------- */

	public static function icon_url($post_id) {
		$d = self::data($post_id);
		$t = get_the_post_thumbnail_url($post_id, 'thumbnail');
		if ($t) {
			return $t;
		}
		return $d && !empty($d['play']['icon']) ? $d['play']['icon'] : '';
	}

	/** Words the member wrote about their upload. */
	public static function words($text) {
		$text = trim((string) $text);
		return $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
	}

	public static function writer_ready() {
		if (!class_exists('Y4APK_Writer')) {
			return false;
		}
		$o = Y4APK_Writer::settings();
		return isset($o['key']) && $o['key'] !== '';
	}

	public static function review_card($p) {
		$d = self::data($p->ID);
		$user = get_userdata($p->post_author);
		$icon = self::icon_url($p->ID);
		$into = ($pid = (int) get_post_meta($p->ID, self::PARENT, true) ?: (int) $d['update_of']) ? get_post($pid) : null;
		$cats = self::categories($d['kind']);
		$cat = (int) (wp_get_post_categories($p->ID)[0] ?? 0);
		$ok = (int) get_post_meta($p->ID, self::OK, true);
		$ai = self::writer_ready() && $d['package'] !== '';
		?>
		<div class="y4rv-card y4sh-card">
			<div class="y4sh-top">
				<span class="y4sh-icon"><?php if ($icon !== '') : ?><img src="<?php echo esc_url($icon); ?>" alt="" width="64" height="64"><?php endif; ?></span>
				<div class="y4sh-who">
					<strong><?php echo esc_html(get_the_title($p)); ?></strong>
					<span class="y4rv-sub"><span class="y4sh-kind"><?php echo esc_html($d['kind'] === 'app' ? __('App', 'y4apk') : __('Game', 'y4apk')); ?></span>
						<?php if ($user) {
							echo esc_html(sprintf(__('@%1$s · member for %2$s', 'y4apk'), $user->user_login, human_time_diff(strtotime($user->user_registered))));
						} ?> · <?php echo esc_html(sprintf(__('sent %s ago', 'y4apk'), human_time_diff(get_post_datetime($p)->getTimestamp()))); ?></span>
					<?php if ($into) : ?><span class="y4sh-into"><?php esc_html_e('A member’s own version of', 'y4apk'); ?> <a href="<?php echo esc_url(get_permalink($into)); ?>" target="_blank"><?php echo esc_html(get_the_title($into)); ?></a>: <?php esc_html_e('approving publishes it as their post, listed on that page. Your post and its file stay as they are.', 'y4apk'); ?></span><?php endif; ?>
				</div>
				<div class="y4rv-actions"><a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php esc_html_e('Edit', 'y4apk'); ?></a>
					<?php if ($user) {
						echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="y4rv-form" onsubmit="return confirm(\'' . esc_js(__('Close this account and remove what is waiting?', 'y4apk')) . '\')"><input type="hidden" name="action" value="y4apk_review_ban"><input type="hidden" name="user" value="' . esc_attr($user->ID) . '">';
						wp_nonce_field('y4apk_review_ban_' . $user->ID);
						echo '<button class="button y4rv-danger">' . esc_html__('Ban member', 'y4apk') . '</button></form>';
					} ?></div>
			</div>
			<dl class="y4sh-facts">
				<div><dt><?php esc_html_e('Version', 'y4apk'); ?></dt><dd><?php echo esc_html($d['version']); ?><?php if (!empty($d['play']['version'])) : ?> <small><?php echo esc_html(sprintf(__('(Google Play: %s)', 'y4apk'), $d['play']['version'])); ?></small><?php endif; ?></dd></div>
				<div><dt><?php esc_html_e('MOD', 'y4apk'); ?></dt><dd><?php echo esc_html($d['mods'] !== '' ? $d['mods'] : '—'); ?></dd></div>
				<?php if ($d['requires'] !== '') : ?><div><dt><?php esc_html_e('Android', 'y4apk'); ?></dt><dd><?php echo esc_html($d['requires']); ?>+</dd></div><?php endif; ?>
				<div><dt><?php esc_html_e('Google Play', 'y4apk'); ?></dt><dd><?php if ($d['package'] !== '') : ?><a href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($d['package'])); ?>" target="_blank" rel="noopener"><?php echo esc_html($d['package']); ?></a><?php if (!empty($d['play']['developer'])) : ?> · <?php echo esc_html($d['play']['developer']); ?><?php endif; ?><?php else : esc_html_e('not on Google Play', 'y4apk'); endif; ?></dd></div>
			</dl>
			<?php if ($d['desc'] !== '') : ?><p class="y4rv-desc"><?php echo nl2br(esc_html($d['desc'])); ?></p><?php endif; ?>
			<ul class="y4sh-files">
				<?php foreach ($d['files'] as $f) : ?>
				<li><span class="y4sh-label"><?php echo esc_html($f['label']); ?></span><span class="y4sh-fname"><?php echo esc_html($f['name']); ?></span><span class="y4sh-size"><?php echo esc_html(Y4APK_R2::size_text($f['size'])); ?></span>
					<?php $c = isset($f['check']) && is_array($f['check']) ? $f['check'] : null;
					if ($c) :
						$same = $c['package'] !== '' && $d['package'] !== '' && strcasecmp($c['package'], $d['package']) === 0; ?>
						<span class="y4sh-chk <?php echo $same ? 'is-ok' : 'is-no'; ?>" title="<?php esc_attr_e('Read from inside the file', 'y4apk'); ?>"><?php echo esc_html($c['package'] === ''
							? __('Package not readable', 'y4apk')
							: ($same ? sprintf(__('Package %s ✓', 'y4apk'), $c['package']) : sprintf(__('Package %s — not the chosen app', 'y4apk'), $c['package']))) . ($c['version'] !== '' ? ' · v' . ltrim($c['version'], 'vV') : ''); ?></span>
					<?php endif; ?>
					<a class="button button-small" href="<?php echo esc_url(Y4APK_R2::public_url($f['key'])); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e('Download to check', 'y4apk'); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<p class="y4sh-tip"><?php esc_html_e('Open the file on a test phone or scan it (VirusTotal takes files up to 650 MB) before you approve: it goes to everyone who downloads it.', 'y4apk'); ?></p>
			<?php if ($ok) : ?>
				<div class="y4sh-writing"><?php echo esc_html(sprintf(__('Approved %s ago: Write with AI is writing its article; it goes live when that is done.', 'y4apk'), human_time_diff($ok))); ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rv-form"><input type="hidden" name="action" value="y4apk_share_publish"><input type="hidden" name="post" value="<?php echo esc_attr($p->ID); ?>"><input type="hidden" name="how" value="now"><input type="hidden" name="cat" value="<?php echo esc_attr($cat); ?>"><?php wp_nonce_field('y4apk_share_publish_' . $p->ID); ?><button class="button"><?php esc_html_e('Publish it now', 'y4apk'); ?></button></form></div>
			<?php else : ?>
			<div class="y4rv-decide">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rv-approve">
					<input type="hidden" name="action" value="y4apk_share_publish"><input type="hidden" name="post" value="<?php echo esc_attr($p->ID); ?>">
					<?php wp_nonce_field('y4apk_share_publish_' . $p->ID); ?>
					<?php if (!$into) : ?>
					<label><?php esc_html_e('Category', 'y4apk'); ?>
						<select name="cat"><?php foreach ($cats as $t) : ?><option value="<?php echo esc_attr($t['id']); ?>" <?php selected($cat, $t['id']); ?>><?php echo esc_html($t['name']); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e('Then', 'y4apk'); ?>
						<select name="how">
							<?php if ($ai) : ?><option value="ai" <?php selected(self::settings()['ai']); ?>><?php esc_html_e('Write with AI, then publish', 'y4apk'); ?></option><?php endif; ?>
							<option value="now" <?php selected(!$ai || !self::settings()['ai']); ?>><?php esc_html_e('Publish now, as written', 'y4apk'); ?></option>
						</select></label>
					<button class="button button-primary"><?php esc_html_e('Approve', 'y4apk'); ?></button>
					<?php else : ?>
					<input type="hidden" name="how" value="now">
					<?php if (self::writer_ready()) : $words = self::words($d['desc']); ?>
					<label class="y4sh-ai"><input type="checkbox" name="ai" value="1" <?php checked($words < self::SHORT_DESC); ?>> <?php echo esc_html($words
						? sprintf(_n('Write the article with AI (the member wrote %s word)', 'Write the article with AI (the member wrote %s words)', $words, 'y4apk'), number_format_i18n($words))
						: __('Write the article with AI (the member wrote nothing)', 'y4apk')); ?></label>
					<?php endif; ?>
					<button class="button button-primary"><?php echo esc_html(sprintf(__('Approve: publish under %s', 'y4apk'), html_entity_decode((string) get_post_meta($into->ID, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8') ?: get_the_title($into))); ?></button>
					<?php endif; ?>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rv-reject">
					<input type="hidden" name="action" value="y4apk_review_reject"><input type="hidden" name="post" value="<?php echo esc_attr($p->ID); ?>">
					<?php wp_nonce_field('y4apk_review_reject_' . $p->ID); ?>
					<input type="text" name="reason" maxlength="300" placeholder="<?php esc_attr_e('What should the member change? (they get this by email)', 'y4apk'); ?>">
					<button class="button"><?php esc_html_e('Send back', 'y4apk'); ?></button>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function review_styles() {
		echo '<style>.y4sh-top{display:flex;gap:14px;align-items:flex-start}.y4sh-icon{flex:none;width:64px;height:64px;border-radius:16px;overflow:hidden;background:#f0f0f1}.y4sh-icon img{width:100%;height:100%;object-fit:cover;display:block}'
			. '.y4sh-who{flex:1;min-width:0}.y4sh-who strong{font-size:15px;overflow-wrap:anywhere}.y4sh-kind{display:inline-block;margin-right:6px;padding:1px 8px;border-radius:99px;background:#e8f1fb;color:#1c5cab;font-weight:600}'
			. '.y4sh-into{display:block;margin-top:4px;font-size:12px;padding:4px 8px;border-radius:6px;background:#fff8e5;color:#7a4d00}'
			. '.y4sh-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px 18px;margin:12px 0 4px}.y4sh-facts dt{font-size:11px;font-weight:700;color:#646970;text-transform:uppercase}.y4sh-facts dd{margin:2px 0 0;overflow-wrap:anywhere}'
			. '.y4sh-files{margin:10px 0 4px;padding:0;list-style:none}.y4sh-files li{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:8px 10px;margin:0 0 6px;border:1px solid #e6e7e9;border-radius:8px;background:#f9fafb}'
			. '.y4sh-chk{padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600}.y4sh-chk.is-ok{background:#e7f6ec;color:#0a6b2c}.y4sh-chk.is-no{background:#fff1e6;color:#8a3b00}'
			. '.y4sh-label{padding:1px 8px;border-radius:6px;background:#1d2327;color:#fff;font-size:11px;font-weight:700}.y4sh-fname{flex:1;min-width:120px;overflow-wrap:anywhere;font-family:monospace;font-size:12px}.y4sh-size{color:#646970;font-weight:600}'
			. '.y4sh-tip{margin:4px 0 10px;font-size:12px;color:#646970}.y4sh-ai{display:inline-flex;align-items:center;gap:6px;margin-right:8px;font-size:13px}.y4sh-writing{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;padding:10px 12px;border-radius:8px;background:#eef6ee;color:#14602a}</style>';
	}

	/* ---------------------------------------------------------------- *
	 * Approving: the download folder, the post
	 * ---------------------------------------------------------------- */

	/** A download row for each file: [rows, error message]. Each file gets a folder in files/up, pointing at it on R2. */
	private static function rows_for($post_id, $d) {
		$o = Y4APK_R2::settings();
		if (!is_dir($o['dir']) || !wp_is_writable($o['dir'])) {
			return [[], __('The download pages folder is not set (App updates ▸ R2 settings).', 'y4apk')];
		}
		$rows = [];
		foreach ($d['files'] as $f) {
			$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
			$folder = Y4APK_R2::new_folder($post_id, $f['label'] === 'OBB' ? 'OBB' : 'MOD', $ext);
			$ok = Y4APK_R2::point_folder($folder, Y4APK_R2::public_url($f['key']));
			if (is_wp_error($ok)) {
				return [[], $ok->get_error_message()];
			}
			$rows[] = ['download_name' => $f['label'], 'download_version' => Y4APK_App_Updates::number($d['version']), 'download_size' => Y4APK_R2::size_text($f['size']), 'download_url' => $o['dir_url'] . $folder];
		}
		return [$rows, ''];
	}

	public static function publish() {
		$id = absint($_POST['post'] ?? 0);
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_share_publish_' . $id);
		$post = get_post($id);
		$d = $post ? self::data($id) : null;
		$back = function ($msg, $extra = '') {
			delete_transient('y4apk_review_counts');
			wp_safe_redirect(add_query_arg(array_filter(['page' => 'y4apk-review', 'msg' => $msg, 'why' => $extra !== '' ? rawurlencode($extra) : '']), admin_url('admin.php')));
			exit;
		};
		if (!$d || $post->post_status === 'trash' || $post->post_status === 'publish') {
			$back('gone');
		}
		$r = self::go_live($id, sanitize_key($_POST['how'] ?? 'now'), absint($_POST['cat'] ?? 0), !empty($_POST['ai']));
		$back($r[0], $r[1]);
	}

	/**
	 * Make a member's upload live (approved in Review, or at once when its
	 * checks pass — $auto, 2.50). $how: 'now' | 'ai' (Write with AI writes it,
	 * then it goes live). [message code, error] for the Review page.
	 */
	public static function go_live($id, $how, $cat, $ai, $auto = false) {
		$post = get_post($id);
		$d = $post ? self::data($id) : null;
		if (!$d || $post->post_status === 'trash' || $post->post_status === 'publish') {
			return ['gone', ''];
		}

		$parent = (int) get_post_meta($id, self::PARENT, true);
		if (!$parent && $d['update_of'] && get_post_status($d['update_of']) === 'publish') {
			$parent = self::adopt($id, $d['update_of']); // sent with 2.44–2.45, before versions were their own posts
		}
		if ($parent) {
			$how = 'now'; // a member's version under a main post: its own post, as the member wrote it
		}

		if ($cat && self::category_ok($d['kind'], $cat)) {
			wp_set_post_categories($id, [$cat]);
		}
		if (!get_post_meta($id, 'repeatable_download_link', true)) {
			list($rows, $err) = self::rows_for($id, $d);
			if ($err !== '') {
				return ['sharefail', $err];
			}
			update_post_meta($id, 'repeatable_download_link', $rows);
		}
		update_post_meta($id, self::LIVE, 1);
		delete_post_meta($id, Y4APK_Members::REJECT);
		if ($auto) {
			update_post_meta($id, Y4APK_Members::AUTO, time());
		} else {
			delete_post_meta($id, Y4APK_Members::AUTO);
		}
		if (get_post_meta($id, Y4APK_Members_Admin::HOLD, true)) {
			Y4APK_Members_Admin::seen($id); // hidden by reports, approved again
			delete_post_meta($id, Y4APK_Members_Admin::HOLD);
		}
		if ($how === 'ai' && self::writer_ready() && $d['package'] !== '') {
			update_post_meta($id, Y4APK_Discover::PUBLISH, 1); // Write with AI publishes it with its article
			update_post_meta($id, self::OK, time());
			Y4APK_Writer::enqueue([$id], Y4APK_Writer::ARTICLE | Y4APK_Writer::ICONS | Y4APK_Writer::SHOTS | Y4APK_Writer::DETAILS, true);
			return ['writing', ''];
		}
		delete_post_meta($id, self::OK);
		delete_post_meta($id, Y4APK_Discover::PUBLISH);
		$live = ['ID' => $id, 'post_status' => 'publish'];
		if ($parent) {
			$live['post_parent'] = $parent;
			if (class_exists('Y4APK_Versions') && !Y4APK_Versions::is_short($post->post_name)) {
				$live['post_name'] = Y4APK_Versions::short_id(); // 2.47: y4apk.com/v/k7x2pq9m4z/, not its long title
			}
		} elseif (strpos($post->post_name, '__trashed') !== false) {
			$live['post_name'] = sanitize_title($post->post_title); // was in the bin (a 2.44–2.45 merge): its own address, from its title
		}
		wp_update_post($live); // the member is told (Y4APK_Members::on_publish)
		if ($parent) {
			if ($ai && self::writer_ready()) {
				Y4APK_Writer::enqueue([$id], Y4APK_Writer::ARTICLE, true); // the member wrote little: Write with AI writes its article, from their words
				return ['underai', ''];
			}
			return ['under', '']; // listed on the main post's page (Y4APK_Versions)
		}
		if ($d['package'] !== '' && class_exists('Y4APK_Writer') && !get_post_thumbnail_id($id)) {
			Y4APK_Writer::enqueue([$id], Y4APK_Writer::ICONS | Y4APK_Writer::SHOTS, true); // its icon and screenshots from Google Play, in the background
		}
		return ['shared', ''];
	}

	/* ---------------------------------------------------------------- *
	 * Tidying R2
	 * ---------------------------------------------------------------- */

	public static function schedule() {
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
		}
	}

	/** Files sent but never used, and uploads never finished: gone after two days. */
	public static function clean() {
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return;
		}
		global $wpdb;
		$old = time() - 2 * DAY_IN_SECONDS;
		foreach ($wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)", self::DONE_UP, self::OPEN_UP)) as $uid) {
			$done = self::done_ups($uid);
			foreach ($done as $key => $f) {
				if ((int) $f['time'] < $old) {
					Y4APK_R2::remove($key);
					unset($done[$key]);
				}
			}
			$done ? update_user_meta($uid, self::DONE_UP, $done) : delete_user_meta($uid, self::DONE_UP);
			$open = self::open_ups($uid);
			foreach ($open as $up => $o) {
				if ((int) $o['time'] < $old - DAY_IN_SECONDS) {
					Y4APK_R2::mp_abort($o['key'], $up);
					delete_transient('y4apk_share_up_' . md5((string) $up));
					unset($open[$up]);
				}
			}
			$open ? update_user_meta($uid, self::OPEN_UP, $open) : delete_user_meta($uid, self::OPEN_UP);
		}
	}

	/** A waiting game or app deleted (or trashed by its member, or a ban): its files go from R2. */
	public static function trashed($post_id) {
		$d = self::data($post_id);
		if (!$d || get_post_meta($post_id, self::LIVE, true) || !class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return;
		}
		foreach ($d['files'] as $f) {
			Y4APK_R2::remove($f['key']);
		}
		$d['files'] = [];
		update_post_meta($post_id, self::META, $d);
	}
}

Y4APK_Share::init();
