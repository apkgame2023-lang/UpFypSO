<?php
/**
 * Versions from members (2.46–2.47)
 *
 * A member who shares a game or app the site already has gets their own post,
 * linked to the site's post (Y4APK_Share::PARENT, and its post_parent). The
 * site's post (the main one) lists them under "Versions from members", as a
 * Roblox game lists its scripts; each is the member's own, with their name, and
 * the main post and its file are never touched. The site's lists (home,
 * categories, search, feeds, "Similar") show only main posts; a member's
 * profile and My uploads still show their own.
 *
 * 2.47:
 * - A version has a short address, /v/k7x2pq9m4z/ (random, so it tells nothing
 *   about how many there are or who sent them), instead of its long title.
 * - What the member did not fill in comes from the main post while the version
 *   has none of its own: the age rating, the stars, Android and OS, the
 *   developer, the screenshots, the icon (inherit()).
 * - The list is a grid of 12 a page, four across, with the newest or the most
 *   viewed first and page numbers. The first page is in the post's HTML; the
 *   others come from versions.php (the database only), so a game with 100,000
 *   versions pages as fast as one with ten.
 * - A member is told when a game or app they shared has a newer version (on
 *   Google Play, or on the site): once per version, by email (they can turn it
 *   off in Settings) and on My uploads.
 *
 * 2.44–2.45 put an approved newer version into the main post instead (its
 * download pointed at the member's file). unmerge() undoes that once: the
 * member's post comes back to Review, to be approved under the main post, and
 * Review shows a box to point the main post's download at its own file again.
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/versions-data.php';

class Y4APK_Versions {
	const FIXED   = 'y4apk_versions_fixed'; // option: the 2.44–2.45 merges were undone
	const RESTORE = '_y4apk_restore';       // on a main post a merge changed: [['from' => member post, 'keys' => [R2 keys], 'version' => '6.0.32'], …]
	const SHORTS  = 'y4apk_versions_short'; // option: versions before 2.47 have their short address and post_parent
	const CFG     = 'y4apk_versions_cfg';   // JSON: what versions.php needs (addresses, R2, the words)
	const TOLD    = 'y4apk_told_newer';     // user meta: [main post => the newer version the member was told about]
	const MAIL    = 'y4apk_newer_mail';     // user meta: '0' when the member turned those emails off
	const HOOK    = 'y4apk_versions_tell';
	const SLUG    = '[a-z][a-z0-9]{9}';     // a version's short address
	const BATCH   = 500;                    // versions looked at per round of telling

	/** What a version takes from its main post while it has none of its own. */
	const INHERIT = ['wp_contentrated_GP' => 1, 'total_rating' => 1, 'total_votes' => 1, 'avg_rating' => 1, 'ss_images' => 1, 'wp_requires_GP' => 1,
		'platform' => 1, 'wp_developers_GP' => 1, 'price' => 1, '_thumbnail_id' => 1];

	public static function init() {
		add_filter('get_post_metadata', [__CLASS__, 'inherit'], 10, 4);
		add_action('init', [__CLASS__, 'rules'], 5); // before Y4APK_Members::setup() writes the rules again
		add_filter('post_link', [__CLASS__, 'link'], 10, 2);
		add_action('template_redirect', [__CLASS__, 'canonical'], 1);
		add_action('pre_get_posts', [__CLASS__, 'only_main'], 30);
		add_action('transition_post_status', [__CLASS__, 'changed'], 20, 3);
		add_action('before_delete_post', [__CLASS__, 'deleting']);
		add_action('admin_init', [__CLASS__, 'unmerge']);
		add_action('admin_init', [__CLASS__, 'shorten']);
		add_action('admin_init', [__CLASS__, 'keep_cfg']);
		add_action('admin_post_y4apk_restore_file', [__CLASS__, 'restore']);
		add_action('added_post_meta', [__CLASS__, 'meta_changed'], 10, 3);
		add_action('updated_post_meta', [__CLASS__, 'meta_changed'], 10, 3);
		add_action(self::HOOK, [__CLASS__, 'tell'], 10, 2);
	}

	/* ---------------------------------------------------------------- *
	 * Main posts and their versions
	 * ---------------------------------------------------------------- */

	/** The main post a member's version is under (published), or 0. */
	public static function parent_of($post_id) {
		$parent = (int) get_post_meta($post_id, Y4APK_Share::PARENT, true);
		return $parent && get_post_status($parent) === 'publish' && get_post_type($parent) === 'post' ? $parent : 0;
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
	 * A version's age rating, stars, Android, OS, developer, price, screenshots
	 * and icon: its own, else its main post's. Its own version, MOD, size, files,
	 * title and article are never taken. (A vote on a version starts from the
	 * main post's stars and is kept on the version.)
	 */
	public static function inherit($value, $id, $key, $single) {
		static $busy = false;
		if ($value !== null || $busy || !is_string($key) || !isset(self::INHERIT[$key])) {
			return $value;
		}
		$busy = true;
		$out = null;
		$parent = (int) get_post_meta($id, Y4APK_Share::PARENT, true);
		if ($parent && $parent !== (int) $id && self::blank(get_post_meta($id, $key, false))) {
			$theirs = get_post_meta($parent, $key, false);
			if (!self::blank($theirs)) {
				$out = $theirs; // get_metadata() gives the first when one is asked for
			}
		}
		$busy = false;
		return $out;
	}

	private static function blank($values) {
		foreach ((array) $values as $v) {
			if ($v !== '' && $v !== '0' && $v !== 0 && $v !== [] && $v !== null && $v !== false) {
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------- *
	 * Short addresses: /v/k7x2pq9m4z/
	 * ---------------------------------------------------------------- */

	public static function rules() {
		add_rewrite_rule('^v/(' . self::SLUG . ')/?$', 'index.php?name=$matches[1]', 'top');
		add_rewrite_rule('^v/(' . self::SLUG . ')/download(/(.*))?/?$', 'index.php?name=$matches[1]&download=$matches[3]', 'top');
	}

	/** A new short address no post has: a letter, then nine letters or digits. */
	public static function short_id() {
		global $wpdb;
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		do {
			$s = $chars[random_int(0, 25)];
			for ($i = 0; $i < 9; $i++) {
				$s .= $chars[random_int(0, 35)];
			}
		} while ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $s)));
		return $s;
	}

	public static function is_short($name) {
		return (bool) preg_match('/^' . self::SLUG . '$/', (string) $name);
	}

	public static function link($url, $post) {
		if ($post && $post->post_type === 'post' && $post->post_status === 'publish' && self::is_short($post->post_name) && get_option('permalink_structure')
			&& get_post_meta($post->ID, Y4APK_Share::PARENT, true)) {
			return home_url('/v/' . $post->post_name . '/');
		}
		return $url;
	}

	/** /r1emlor2o5/ (the post's address without /v/) → /v/r1emlor2o5/: one address for each version. */
	public static function canonical() {
		global $wp_query;
		if (!is_singular('post') || isset($wp_query->query_vars['download']) || is_preview()) {
			return;
		}
		$post = get_queried_object();
		if (!$post instanceof WP_Post || !self::is_short($post->post_name)) {
			return;
		}
		$want = get_permalink($post);
		$path = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
		if (strpos($want, '/v/') !== false && untrailingslashit($path) !== untrailingslashit((string) wp_parse_url($want, PHP_URL_PATH))) {
			$qs = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
			wp_safe_redirect($want . ($qs !== '' ? '?' . $qs : ''), 301);
			exit;
		}
	}

	/**
	 * Versions from before 2.47: their short address (the long one still works,
	 * and leads to it) and their post_parent. A few hundred per admin page.
	 */
	public static function shorten() {
		if (get_option(self::SHORTS) || !class_exists('Y4APK_Share')) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_name, p.post_status, p.post_parent, m.meta_value AS parent FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = 'post' AND (p.post_parent <> CAST(m.meta_value AS UNSIGNED) OR (p.post_status = 'publish' AND p.post_name NOT REGEXP %s)) LIMIT 300", Y4APK_Share::PARENT, '^' . self::SLUG . '$'));
		foreach ((array) $rows as $r) {
			$set = ['post_parent' => (int) $r->parent];
			if ($r->post_status === 'publish' && !self::is_short($r->post_name)) {
				$set['post_name'] = self::short_id();
				if ($r->post_name !== '') {
					add_post_meta((int) $r->ID, '_wp_old_slug', $r->post_name); // the old address leads to the new one
				}
			}
			$wpdb->update($wpdb->posts, $set, ['ID' => (int) $r->ID]); // not "Updated just now"
			clean_post_cache((int) $r->ID);
		}
		if (count((array) $rows) < 300) {
			update_option(self::SHORTS, time(), false);
			flush_rewrite_rules(false);
		}
	}

	/* ---------------------------------------------------------------- *
	 * The site's lists
	 * ---------------------------------------------------------------- */

	/**
	 * The site's lists show main posts only: every front-end list of posts
	 * (home, categories, tags, search, feeds, "Similar"), but not one post, a
	 * member's own lists (by author), a list of chosen posts, or a list of a
	 * main post's versions ('y4_children', post_parent).
	 */
	public static function only_main($q) {
		if (is_admin() || $q->get('y4_children') || $q->is_singular() || $q->get('p') || $q->get('name') || $q->get('page_id') || $q->get('pagename')
			|| $q->get('post__in') || $q->get('author') || $q->get('author_name') || $q->get('author__in') || $q->get('post_parent')) {
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

	/** A main post deleted for good: its versions stand on their own (WordPress sets their post_parent to 0). */
	public static function deleting($post_id) {
		if (get_post_type($post_id) !== 'post') {
			return;
		}
		global $wpdb;
		$wpdb->delete($wpdb->postmeta, ['meta_key' => Y4APK_Share::PARENT, 'meta_value' => (string) (int) $post_id]);
	}

	/* ---------------------------------------------------------------- *
	 * What versions.php needs
	 * ---------------------------------------------------------------- */

	public static function cfg() {
		$pretty = (bool) get_option('permalink_structure');
		// The words in each language visitors can pick (2.51); versions.php takes ?l=
		$langs = [];
		if (class_exists('Y4APK_I18n')) {
			foreach (array_diff(Y4APK_I18n::ready(), ['en']) as $code) {
				$langs[$code] = Y4APK_I18n::in($code, [__CLASS__, 'words']);
			}
		}
		return [
			'home'   => untrailingslashit(home_url()),
			'pretty' => $pretty,
			'short'  => $pretty,
			'r2'     => class_exists('Y4APK_R2') ? (string) Y4APK_R2::settings()['public'] : '',
			'date'   => (string) get_option('date_format'),
			'offset' => (float) get_option('gmt_offset'),
			'role'   => Y4APK_Members::ROLE,
			'site'   => Y4APK_Members::settings()['from_name'],
			't'      => self::words(),
			'langs'  => $langs,
		];
	}

	public static function words() {
		return [
			'MOD'                     => __('MOD', 'y4apk'),
			'Original'                => __('Original', 'y4apk'),
			'As released, not modded' => __('As released, not modded', 'y4apk'),
			'views'                   => [__('%s view', 'y4apk'), __('%s views', 'y4apk')],
			'Previous page'           => __('Previous page', 'y4apk'),
			'Next page'               => __('Next page', 'y4apk'),
			'%1$s–%2$s of %3$s'       => __('%1$s–%2$s of %3$s', 'y4apk'),
		];
	}

	public static function keep_cfg() {
		$c = wp_json_encode(class_exists('Y4APK_I18n') ? Y4APK_I18n::untranslated([__CLASS__, 'cfg']) : self::cfg(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (get_option(self::CFG) !== $c) {
			update_option(self::CFG, $c, false);
		}
	}

	private static function cfg_now() {
		$c = json_decode((string) get_option(self::CFG, ''), true);
		if (!is_array($c)) {
			self::keep_cfg();
			$c = json_decode((string) get_option(self::CFG, ''), true);
		}
		return class_exists('Y4APK_I18n') ? y4apk_vd_lang((array) $c, Y4APK_I18n::current()) : (array) $c; // this page's language
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
			$ours = false;
			foreach (Y4APK_Share::roots() as $r) {
				if ($r[0] && (in_array((int) $r[0]->term_id, $cats, true) || array_intersect($cats, wp_list_pluck($r[1], 'term_id')))) {
					$ours = true;
				}
			}
			if (!$ours) {
				return; // not a game or an app (a page, a Roblox script…)
			}
		}
		$cfg = self::cfg_now();
		$sort = isset($_GET['vs']) && $_GET['vs'] === 'views' ? 'views' : 'new';
		$data = y4apk_vd_page($main, isset($_GET['vp']) ? absint($_GET['vp']) : 1, $sort, $parent ? $post_id : 0, $cfg);
		$can_add = Y4APK_Share::open() && class_exists('Y4APK_R2') && Y4APK_R2::ready();
		if (!$data['total'] && !$parent && !$can_add) {
			return;
		}
		$name = self::app_name($main);
		$add = $can_add ? Y4APK_Members::url('share/' . self::kind_of($main), ['newer' => $main]) : '';
		$state = ['p' => $main, 'x' => $parent ? $post_id : 0, 'sort' => $sort, 'page' => $data['page'], 'total' => $data['total'],
			'first' => $data['items'] ? $data['items'][0]['id'] : 0, 'api' => get_template_directory_uri() . '/versions.php', 'l' => class_exists('Y4APK_I18n') && Y4APK_I18n::current() !== 'en' ? Y4APK_I18n::current() : ''];
		$tab = function ($key, $label) use ($sort) {
			echo '<a class="y4v-tab' . ($sort === $key ? ' is-on' : '') . '" href="' . esc_attr($key === 'views' ? '?vs=views#versions' : '?vp=1#versions') . '" data-vs="' . esc_attr($key) . '" role="tab" aria-selected="' . ($sort === $key ? 'true' : 'false') . '">' . esc_html($label) . '</a>';
		};
		?>
		<section class="block y4v<?php echo $data['total'] ? '' : ' is-empty'; ?>" id="versions" aria-labelledby="y4v-title" data-y4v="<?php echo esc_attr(wp_json_encode($state)); ?>">
			<div class="y4v-head">
				<div class="y4v-text">
					<h2 class="y4v-title" id="y4v-title"><?php echo esc_html($parent ? sprintf(__('More versions of %s', 'y4apk'), $name) : __('Versions from members', 'y4apk')); ?>
						<span class="y4v-count" data-y4v-count<?php echo $data['total'] ? '' : ' hidden'; ?>><?php echo esc_html(number_format_i18n($data['total'])); ?></span></h2>
					<p class="y4v-sub"><?php echo esc_html($parent
						? __('The site’s own post, and what other members shared for it.', 'y4apk')
						: sprintf(__('Shared by our members for %s. Each one is the member’s own post.', 'y4apk'), $name)); ?></p>
				</div>
				<?php if ($add) : ?><a class="y4v-add" href="<?php echo esc_url($add); ?>" rel="nofollow"><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg><?php esc_html_e('Share your version', 'y4apk'); ?></a><?php endif; ?>
			</div>
			<?php if ($parent) {
				self::main_card($parent);
			} ?>
			<div class="y4v-empty" data-y4v-empty<?php echo $data['total'] ? ' hidden' : ''; ?>>
				<svg width="40" height="40" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 7.5v9L12 21l9-4.5v-9L12 3Zm0 2.2 6.6 3.3L12 11.8 5.4 8.5 12 5.2ZM5 10.1l6 3v5.7l-6-3v-5.7Zm8 8.7v-5.7l6-3v5.7l-6 3Z" fill="currentColor"/></svg>
				<p><b><?php echo esc_html($parent ? __('No other versions yet', 'y4apk') : __('No versions from members yet', 'y4apk')); ?></b>
					<span><?php echo esc_html(sprintf(__('Have another version of %s? Share it: it shows here with your name.', 'y4apk'), $name)); ?></span></p>
			</div>
			<div class="y4v-bar" data-y4v-bar<?php echo $data['total'] ? '' : ' hidden'; ?>>
				<div class="y4v-tabs" role="tablist" aria-label="<?php esc_attr_e('Sort versions', 'y4apk'); ?>"><?php $tab('new', __('Newest', 'y4apk')); $tab('views', __('Most viewed', 'y4apk')); ?></div>
				<span class="y4v-range" data-y4v-range><?php echo esc_html(y4apk_vd_range($data, $cfg)); ?></span>
			</div>
			<ul class="y4v-grid" data-y4v-grid aria-live="polite"><?php echo y4apk_vd_cards($data, $cfg); // escaped in y4apk_vd_card() ?></ul>
			<nav class="y4v-pager" data-y4v-pager aria-label="<?php esc_attr_e('Pages of versions', 'y4apk'); ?>"><?php echo y4apk_vd_pager($data, $sort, $cfg); ?></nav>
		</section>
		<?php
		self::script();
	}

	/** On a version's page: the main post, first. */
	private static function main_card($id) {
		$v = (string) get_post_meta($id, 'wp_version_GP', true);
		$mods = trim((string) get_post_meta($id, 'wp_mods', true));
		$size = (string) get_post_meta($id, 'wp_sizes_GP', true);
		$icon = get_the_post_thumbnail_url($id, 'thumbnail');
		?>
		<a class="y4v-mainpost" href="<?php echo esc_url(get_permalink($id)); ?>">
			<?php echo $icon ? '<img src="' . esc_url($icon) . '" alt="" width="56" height="56" loading="lazy" decoding="async">' : '<span class="y4v-mp-icon"></span>'; ?>
			<span class="y4v-mp-body">
				<span class="y4v-line"><span class="y4v-chip y4v-chip-main"><?php esc_html_e('Main post', 'y4apk'); ?></span><b><?php echo esc_html(self::app_name($id)); ?></b></span>
				<small><?php echo esc_html(implode(' · ', array_filter([$v !== '' ? 'v' . $v : '', $mods !== '' ? $mods : __('Original', 'y4apk'), $size]))); ?></small>
			</span>
			<span class="y4v-mp-go"><?php esc_html_e('Open', 'y4apk'); ?><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
		</a>
		<?php
	}

	/** Pages and sort without leaving the post: versions.php sends the cards. The post's page may be from a cache, so page 1 is asked again when the list comes into view. */
	private static function script() {
		?>
<script id="y4apk-pwa-versions" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var box = document.getElementById('versions');
	if (!box || !window.fetch || !window.JSON) { return; }
	var c = JSON.parse(box.getAttribute('data-y4v') || '{}'), busy = false;
	var q = function (s) { return box.querySelector(s); };
	function show(d, sort) {
		q('[data-y4v-grid]').innerHTML = d.html;
		q('[data-y4v-pager]').innerHTML = d.pager;
		q('[data-y4v-range]').textContent = d.range;
		var n = q('[data-y4v-count]');
		n.textContent = (d.total || 0).toLocaleString();
		n.hidden = !d.total; q('[data-y4v-bar]').hidden = !d.total; q('[data-y4v-empty]').hidden = !!d.total;
		box.classList.toggle('is-empty', !d.total);
		[].forEach.call(box.querySelectorAll('[data-vs]'), function (t) { var on = t.getAttribute('data-vs') === sort; t.classList.toggle('is-on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
		c.page = d.page; c.sort = sort; c.total = d.total; c.first = d.first;
	}
	function load(page, sort, scroll, quiet) {
		if (busy) { return; }
		busy = true;
		if (!quiet) { box.classList.add('is-loading'); }
		fetch(c.api + '?p=' + c.p + '&page=' + page + '&sort=' + sort + (c.x ? '&x=' + c.x : '') + (c.l ? '&l=' + encodeURIComponent(c.l) : ''), { credentials: 'omit' })
			.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
			.then(function (d) {
				if (quiet && d.total === c.total && d.first === c.first) { return; }
				show(d, sort);
				if (scroll) { var top = box.getBoundingClientRect().top + window.pageYOffset - 80; window.scrollTo({ top: top, behavior: 'smooth' }); }
			})
			.catch(function () { if (!quiet) { location.href = '?vp=' + page + (sort === 'views' ? '&vs=views' : '') + '#versions'; } })
			.then(function () { busy = false; box.classList.remove('is-loading'); });
	}
	box.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('[data-vp],[data-vs]') : null;
		if (!a || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
		e.preventDefault();
		if (a.getAttribute('aria-disabled') === 'true') { return; }
		if (a.hasAttribute('data-vs')) { if (a.getAttribute('data-vs') !== c.sort) { load(1, a.getAttribute('data-vs'), false); } }
		else { load(+a.getAttribute('data-vp'), c.sort, true); }
	});
	if (c.page === 1 && 'IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); load(1, c.sort, false, true); } }, { rootMargin: '300px' });
		io.observe(box);
	}
})();
</script>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Telling members about a newer version
	 * ---------------------------------------------------------------- */

	/** The newest version known for a main post: Google Play's (App updates) or the post's own, whichever is newer; '' if neither. */
	public static function latest_of($main) {
		$a = Y4APK_App_Updates::number(get_post_meta($main, Y4APK_App_Updates::LATEST, true));
		$b = Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($main));
		if ($a === '' || ($b !== '' && version_compare($b, $a, '>'))) {
			return $b;
		}
		return $a;
	}

	/** For My uploads: the newer version out for this upload's game or app, or ''. */
	public static function newer_for($post_id) {
		$main = (int) get_post_meta($post_id, Y4APK_Share::PARENT, true) ?: (int) $post_id;
		$mine = Y4APK_App_Updates::number(get_post_meta($post_id, 'wp_version_GP', true));
		$latest = self::latest_of($main);
		return $mine !== '' && $latest !== '' && version_compare($latest, $mine, '>') ? $latest : '';
	}

	/** Google Play's version found, or the post's own changed: the members who shared it are told, in the background. */
	public static function meta_changed($meta_id, $post_id, $key) {
		if (($key !== 'wp_version_GP' && $key !== Y4APK_App_Updates::LATEST) || get_post_type($post_id) !== 'post' || get_post_meta($post_id, Y4APK_Share::PARENT, true)) {
			return;
		}
		if (!wp_next_scheduled(self::HOOK, [(int) $post_id, 0])) {
			wp_schedule_single_event(time() + 120, self::HOOK, [(int) $post_id, 0]);
		}
	}

	/** Each member whose newest version of this game is older than the newest known: one email per new version (if they want them). */
	public static function tell($main, $offset = 0) {
		$main = (int) $main;
		if (get_post_status($main) !== 'publish' || !class_exists('Y4APK_Share')) {
			return;
		}
		$latest = self::latest_of($main);
		if ($latest === '') {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_author, m.meta_value AS v FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'wp_version_GP'
			WHERE p.post_parent = %d AND p.post_type = 'post' AND p.post_status = 'publish' ORDER BY p.ID LIMIT %d OFFSET %d", $main, self::BATCH, (int) $offset));
		$best = [];
		if (!$offset && Y4APK_Share::is_share($main)) {
			$best[(int) get_post_field('post_author', $main)] = Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($main)); // a member's own new game: they are told too
		}
		foreach ((array) $rows as $r) {
			$v = Y4APK_App_Updates::number($r->v);
			$a = (int) $r->post_author;
			if (!isset($best[$a]) || ($v !== '' && version_compare($v, $best[$a], '>'))) {
				$best[$a] = $v;
			}
		}
		foreach ($best as $uid => $v) {
			if ($v !== '' && version_compare($latest, $v, '>')) {
				self::tell_one($uid, $main, $v, $latest);
			}
		}
		if (count((array) $rows) === self::BATCH) {
			wp_schedule_single_event(time() + 60, self::HOOK, [$main, (int) $offset + self::BATCH]); // the next ones
		}
	}

	private static function tell_one($uid, $main, $have, $latest) {
		$user = get_userdata($uid);
		if (!$user || !Y4APK_Members::is_public($user)) {
			return;
		}
		$told = get_user_meta($uid, self::TOLD, true);
		$told = is_array($told) ? $told : [];
		if (isset($told[$main]) && version_compare((string) $told[$main], $latest, '>=')) {
			return; // told about this one (or a newer) already
		}
		unset($told[$main]);
		$told[$main] = $latest;
		update_user_meta($uid, self::TOLD, array_slice($told, -300, null, true));
		if (get_user_meta($uid, self::MAIL, true) === '0') {
			return; // they turned these emails off: My uploads still shows it
		}
		$name = self::app_name($main);
		Y4APK_Members::mail($user->user_email, sprintf(__('New version of %1$s: v%2$s', 'y4apk'), $name, $latest), Y4APK_Share::message('newer', [
			'name' => $user->user_login, 'title' => $name, 'have' => $have, 'latest' => $latest,
			'link' => Y4APK_Members::url('share/' . self::kind_of($main), ['newer' => $main]),
		]));
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
			$links[$i]['download_version'] = Y4APK_Writer::version_text($v, (string) ($links[$i]['download_name'] ?? '')); // "v1.3.4.3.apk", as the site writes it (2.66.4)
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
