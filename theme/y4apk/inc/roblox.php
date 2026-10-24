<?php
/**
 * Roblox: script posts sorted by the Roblox game they are for, in a section of
 * their own instead of a "Roblox Script" category mixed in with Games and Apps.
 *
 *   /roblox/          every game (icon, scripts, players) and the latest scripts
 *   /roblox/<game>/   that game's scripts, 24 a page: search, Keyless, sort
 *
 * A post's game is a term of the roblox_game taxonomy, picked in the post's
 * "Roblox Game" box (an existing game, or a new one by name, roblox.com link
 * or place ID). With a link, the game's name, icon, banner, players, visits
 * and likes come from Roblox's public API; without one, its icon is the
 * newest post's picture. Posts of a game, or still in the Roblox category,
 * are kept out of the Games and Apps pages, the home sections, the Games menu
 * and other posts' "Similar"; the old category page sends visitors to
 * /roblox/. Posts keep their URLs.
 *
 * The hub is an ordinary page (slug "roblox", made once), so its title, text
 * and SEO are edited like any page; the theme draws the rest.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Roblox {

	const TAX      = 'roblox_game';
	const PAGE     = 'y4apk_roblox_page';   // option: the hub page's ID
	const RULES    = 'y4apk_roblox_rules';  // option: rewrite rules flushed for this taxonomy
	const HOOK     = 'y4apk_roblox_refresh';
	const PER_PAGE = 24;
	const MENU     = 12;                    // games in the header menu
	const UA       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';
	// Term meta
	const M_PLACE    = 'y4apk_place';     // Roblox place ID (the number in roblox.com/games/<id>/…)
	const M_UNIVERSE = 'y4apk_universe';  // its universe ID, which the API asks for
	const M_ICON     = 'y4apk_icon';      // attachment ID
	const M_ICON_SRC = 'y4apk_icon_src';  // 'post' (a post's picture) | 'roblox' | 'manual'
	const M_BANNER   = 'y4apk_banner';    // attachment ID
	const M_STATS    = 'y4apk_stats';     // ['playing', 'visits', 'favorites', 'up', 'down', 'creator', 'updated', 'fetched']
	const M_SHOTS    = 'y4apk_shots';     // ['urls' => up to SHOTS screenshot URLs on Roblox's CDN, 'fetched' => time]
	const SHOTS      = 5;
	const SHOTS_HOOK = 'y4apk_roblox_shots';

	public static function init() {
		add_action('init', [__CLASS__, 'register'], 5);
		add_action('init', [__CLASS__, 'rules'], 99);
		add_action('pre_get_posts', [__CLASS__, 'query'], 20); // after archive_sort()
		add_filter('posts_where', [__CLASS__, 'where'], 10, 2);
		add_action('template_redirect', [__CLASS__, 'redirect']);
		add_filter('template_include', [__CLASS__, 'template']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
		add_filter('document_title_parts', [__CLASS__, 'title_parts']);
		add_filter('rank_math/frontend/title', [__CLASS__, 'rank_math_title']);
		add_filter('rank_math/frontend/description', [__CLASS__, 'rank_math_description']);
		add_filter('cron_schedules', [__CLASS__, 'every_10_minutes']);
		add_action(self::HOOK, [__CLASS__, 'refresh']);
		add_action(self::SHOTS_HOOK, [__CLASS__, 'refresh_shots']);
		add_action('wp_footer', [__CLASS__, 'live_script'], 60);
		add_action('admin_init', [__CLASS__, 'first_shots']);

		add_action('add_meta_boxes_post', [__CLASS__, 'add_box']);
		add_action('save_post_post', [__CLASS__, 'save_box']);
		add_action(self::TAX . '_add_form_fields', [__CLASS__, 'add_fields']);
		add_action(self::TAX . '_edit_form_fields', [__CLASS__, 'edit_fields']);
		add_action('created_' . self::TAX, [__CLASS__, 'save_fields']);
		add_action('edited_' . self::TAX, [__CLASS__, 'save_fields']);
		add_filter('manage_edit-' . self::TAX . '_columns', [__CLASS__, 'columns']);
		add_filter('manage_' . self::TAX . '_custom_column', [__CLASS__, 'column'], 10, 3);
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_init', [__CLASS__, 'make_hub']);
		add_action('admin_notices', [__CLASS__, 'notice']);
		add_action('admin_post_y4apk_roblox_sort', [__CLASS__, 'handle_sort']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
	}

	public static function register() {
		register_taxonomy(self::TAX, 'post', [
			'labels' => [
				'name'          => __('Roblox Games', 'y4apk'),
				'singular_name' => __('Roblox Game', 'y4apk'),
				'menu_name'     => __('Roblox Games', 'y4apk'),
				'all_items'     => __('All Roblox games', 'y4apk'),
				'edit_item'     => __('Edit Roblox game', 'y4apk'),
				'view_item'     => __('View Roblox game', 'y4apk'),
				'update_item'   => __('Update Roblox game', 'y4apk'),
				'add_new_item'  => __('Add a Roblox game', 'y4apk'),
				'new_item_name' => __('New Roblox game', 'y4apk'),
				'search_items'  => __('Search Roblox games', 'y4apk'),
				'not_found'     => __('No Roblox games yet.', 'y4apk'),
				'back_to_items' => __('← Back to Roblox games', 'y4apk'),
			],
			'public'            => true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'show_tagcloud'     => false,
			'show_in_rest'      => false, // the "Roblox Game" box is the editor's one place for it
			'meta_box_cb'       => false,
			'rewrite'           => ['slug' => 'roblox', 'with_front' => false],
		]);
		// Players and likes every 10 minutes (2.42; hourly 2.37, daily before): two calls for 50 games.
		if (wp_get_schedule(self::HOOK) !== 'y4apk_10min') {
			wp_clear_scheduled_hook(self::HOOK);
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'y4apk_10min', self::HOOK);
		}
		// The screenshots' addresses on Roblox's CDN expire: renewed every hour, on their own.
		if (wp_get_schedule(self::SHOTS_HOOK) !== 'hourly') {
			wp_clear_scheduled_hook(self::SHOTS_HOOK);
			wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::SHOTS_HOOK);
		}
	}

	public static function every_10_minutes($schedules) {
		$schedules['y4apk_10min'] = ['interval' => 10 * MINUTE_IN_SECONDS, 'display' => __('Every 10 minutes', 'y4apk')];
		return $schedules;
	}

	/** Marks a number to be brought up to date in the page (live_script): which game, which number. */
	public static function live($term, $what) {
		self::$live = true;
		return ' data-rbx-live="' . (int) $term->term_id . '" data-rbx-n="' . esc_attr($what) . '"';
	}

	private static $live = false;

	/**
	 * Pages are cached for longer than 10 minutes, so a page with players or likes
	 * asks rbx.php for the newest (one small request, itself cached for a minute).
	 */
	public static function live_script() {
		if (!self::$live) {
			return;
		}
		?>
<script id="y4apk-pwa-rbx" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var els = document.querySelectorAll('[data-rbx-live]'), ids = {};
	if (!els.length || !window.fetch) { return; }
	[].forEach.call(els, function (el) { ids[el.getAttribute('data-rbx-live')] = 1; });
	var short = function (n) {
		var u = [[1e9, 'B'], [1e6, 'M'], [1e3, 'K']];
		for (var i = 0; i < u.length; i++) {
			if (n >= u[i][0]) { var v = n / u[i][0], t = v.toFixed(v < 10 ? 1 : 0); return (t.indexOf('.') !== -1 ? t.replace(/\.?0+$/, '') : t) + u[i][1]; }
		}
		return n.toLocaleString();
	};
	fetch(<?php echo wp_json_encode(get_template_directory_uri() . '/rbx.php'); ?> + '?g=' + Object.keys(ids).slice(0, 40).join(','))
		.then(function (r) { return r.json(); })
		.then(function (j) {
			[].forEach.call(els, function (el) {
				var g = j[el.getAttribute('data-rbx-live')], what = el.getAttribute('data-rbx-n');
				if (!g || g[what] === null || g[what] === undefined) { return; }
				el.textContent = what === 'likes' ? g.likes + '%' : short(g[what]);
			});
		})
		.catch(function () {});
})();
</script>
		<?php
	}

	/** /roblox/<game>/ needs the rewrite rules written once. */
	public static function rules() {
		if (get_option(self::RULES) !== '1') {
			flush_rewrite_rules(false);
			update_option(self::RULES, '1');
		}
	}

	/* ---------------------------------------------------------------- *
	 * Which posts are Roblox
	 * ---------------------------------------------------------------- */

	/** The "Roblox Script" category (any category named or slugged Roblox) and its children. */
	public static function categories() {
		static $ids = null;
		if ($ids === null) {
			$ids = [];
			foreach (get_categories(['hide_empty' => false]) as $cat) {
				if (stripos($cat->slug, 'roblox') !== false || stripos($cat->name, 'roblox') !== false) {
					$ids[] = (int) $cat->term_id;
					$ids = array_merge($ids, array_map('intval', get_term_children($cat->term_id, 'category')));
				}
			}
			$ids = array_values(array_unique(apply_filters('y4apk_roblox_categories', $ids)));
		}
		return $ids;
	}

	/** tax_query: posts that are not Roblox (no game, not in the Roblox category). */
	public static function not_roblox() {
		$q = [['taxonomy' => self::TAX, 'operator' => 'NOT EXISTS']];
		if ($cats = self::categories()) {
			$q[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cats, 'operator' => 'NOT IN', 'include_children' => false];
		}
		return array_merge(['relation' => 'AND'], $q);
	}

	/** tax_query: Roblox posts (a game, or the Roblox category). */
	public static function only_roblox() {
		$q = [['taxonomy' => self::TAX, 'operator' => 'EXISTS']];
		if ($cats = self::categories()) {
			$q[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cats, 'operator' => 'IN', 'include_children' => false];
		}
		return array_merge(['relation' => 'OR'], $q);
	}

	/** Adds a clause to a query's tax_query (whatever it already holds). */
	public static function add_tax($tax_query, $clause) {
		$tax_query = is_array($tax_query) ? $tax_query : [];
		return array_filter($tax_query, 'is_array') ? ['relation' => 'AND', $tax_query, $clause] : $clause;
	}

	public static function is_roblox_post($post_id) {
		return (bool) self::game_of($post_id) || (self::categories() && has_category(self::categories(), $post_id));
	}

	/** The post's game (a WP_Term), or null. */
	public static function game_of($post_id) {
		$terms = get_the_terms($post_id, self::TAX);
		return $terms && !is_wp_error($terms) ? $terms[0] : null;
	}

	public static function is_roblox_category($term_id) {
		return in_array((int) $term_id, self::categories(), true);
	}

	/* ---------------------------------------------------------------- *
	 * The hub page
	 * ---------------------------------------------------------------- */

	public static function hub_id() {
		$id = (int) get_option(self::PAGE);
		return $id && get_post_status($id) === 'publish' && get_post_type($id) === 'page' ? $id : 0;
	}

	public static function hub_url() {
		$id = self::hub_id();
		return $id ? get_permalink($id) : '';
	}

	/** Made once, when the site first has Roblox posts: a page at /roblox/. */
	public static function make_hub() {
		if (get_option(self::PAGE) !== false || !current_user_can('manage_options') || wp_doing_ajax() || get_transient('y4apk_roblox_hub_checked')) {
			return;
		}
		set_transient('y4apk_roblox_hub_checked', 1, HOUR_IN_SECONDS); // a site without Roblox posts: asked once an hour
		$any = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids']);
		if (!$any && !self::count_roblox()) {
			return;
		}
		$page = get_page_by_path('roblox');
		$id = $page ? (int) $page->ID : (int) wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __('Roblox Scripts', 'y4apk'),
			'post_name'    => 'roblox',
			'post_content' => "<!-- wp:paragraph -->\n<p>" . esc_html__('Free Roblox scripts, sorted by game. Open a game to see its scripts, copy a loadstring and run it in your executor.', 'y4apk') . "</p>\n<!-- /wp:paragraph -->",
		]);
		update_option(self::PAGE, $id, false);
	}

	private static function count_roblox() {
		$q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false, 'tax_query' => self::only_roblox()]);
		return (int) $q->found_posts;
	}

	/** In the header: when there is a hub or a game. */
	public static function in_menu() {
		return self::hub_id() || self::games(1);
	}

	/* ---------------------------------------------------------------- *
	 * Games
	 * ---------------------------------------------------------------- */

	/** Games with scripts, most scripts first. */
	public static function games($limit = 0, $exclude = 0) {
		$terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => $limit, 'exclude' => $exclude ? [$exclude] : []]);
		return is_array($terms) ? $terms : [];
	}

	public static function icon_url($term, $size = 'thumbnail') {
		$att = (int) get_term_meta($term->term_id, self::M_ICON, true);
		if ($att && ($url = wp_get_attachment_image_url($att, $size))) {
			return $url;
		}
		// The newest post's picture, kept as the game's icon.
		$posts = get_posts(['post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'modified', 'meta_key' => '_thumbnail_id',
			'tax_query' => [['taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => $term->term_id]]]);
		if ($posts && ($thumb = (int) get_post_thumbnail_id($posts[0])) && ($url = wp_get_attachment_image_url($thumb, $size))) {
			update_term_meta($term->term_id, self::M_ICON, $thumb);
			update_term_meta($term->term_id, self::M_ICON_SRC, 'post');
			return $url;
		}
		$letter = mb_strtoupper(mb_substr($term->name, 0, 1));
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="26" fill="hsl(' . (abs(crc32($term->name)) % 360) . ',55%,46%)"/>'
			. '<text x="60" y="60" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="62" font-weight="700" fill="#fff">' . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</text></svg>';
		return 'data:image/svg+xml,' . rawurlencode($svg);
	}

	/** A wide picture for the game page: its banner, else the newest post's first screenshot. */
	public static function banner_url($term) {
		$att = (int) get_term_meta($term->term_id, self::M_BANNER, true);
		if ($att && ($url = wp_get_attachment_image_url($att, 'large'))) {
			return $url;
		}
		$posts = get_posts(['post_type' => 'post', 'numberposts' => 3, 'fields' => 'ids', 'orderby' => 'modified',
			'tax_query' => [['taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => $term->term_id]]]);
		foreach ($posts as $id) {
			$shots = get_post_meta($id, 'ss_images', true);
			if (is_array($shots) && !empty($shots[0]['ss_url'])) {
				return $shots[0]['ss_url'];
			}
		}
		return '';
	}

	public static function stats($term) {
		$s = get_term_meta($term->term_id, self::M_STATS, true);
		return wp_parse_args(is_array($s) ? $s : [], ['playing' => null, 'visits' => null, 'favorites' => null, 'up' => null, 'down' => null, 'creator' => '', 'updated' => 0, 'fetched' => 0]);
	}

	public static function play_url($term) {
		$place = (string) get_term_meta($term->term_id, self::M_PLACE, true);
		return $place !== '' ? 'https://www.roblox.com/games/' . rawurlencode($place) . '/' . sanitize_title($term->name) : '';
	}

	/** Views, newest change and keyless scripts of a game's posts: ['views', 'updated', 'keyless']. */
	public static function totals($term) {
		global $wpdb;
		$tt = (int) $term->term_taxonomy_id;
		$base = "FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_type = 'post' AND p.post_status = 'publish'";
		return [
			'views'   => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(pm.meta_value + 0) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish' AND pm.meta_key = 'wpb_post_views_count'", $tt)),
			'updated' => (string) $wpdb->get_var($wpdb->prepare("SELECT MAX(p.post_modified_gmt) $base", $tt)),
			// Its LIKE '%…%' after prepare(), which reads % as a placeholder.
			'keyless' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base", $tt) . ' AND ' . self::keyless_sql('p')),
		];
	}

	/** "No Key", "NoKey", "No-Key", "Keyless" in the title. */
	public static function keyless($title) {
		return (bool) preg_match('/\bno[\s_-]*key\b|\bkeyless\b/i', (string) $title);
	}

	private static function keyless_sql($alias) {
		$t = "LOWER($alias.post_title)";
		return "($t LIKE '%no key%' OR $t LIKE '%nokey%' OR $t LIKE '%no-key%' OR $t LIKE '%no_key%' OR $t LIKE '%keyless%')";
	}

	/* ---------------------------------------------------------------- *
	 * Queries
	 * ---------------------------------------------------------------- */

	/** The search, Keyless and sort of a game page (or the hub's scripts), from the address. */
	public static function filters() {
		$q = isset($_GET['q']) ? trim(mb_substr(sanitize_text_field(wp_unslash($_GET['q'])), 0, 80)) : '';
		$key = isset($_GET['key']) && $_GET['key'] === 'no';
		$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['views', 'new'], true) ? $_GET['sort'] : 'updated';
		return ['q' => $q, 'keyless' => $key, 'sort' => $sort];
	}

	/** Query args for scripts: search, Keyless, sort. */
	public static function script_args($f) {
		$args = ['y4apk_rq' => $f['q'], 'y4apk_keyless' => $f['keyless'] ? 1 : 0];
		if ($f['sort'] === 'views') {
			$args += ['meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC'];
		} else {
			$args += ['orderby' => $f['sort'] === 'new' ? 'date' : 'modified', 'order' => 'DESC'];
		}
		return $args;
	}

	public static function query($q) {
		if (is_admin() || !$q->is_main_query()) {
			return;
		}
		if ($q->is_tax(self::TAX)) {
			$q->set('posts_per_page', self::PER_PAGE);
			$q->set('post_type', 'post');
			$q->set('meta_key', '');
			foreach (self::script_args(self::filters()) as $k => $v) {
				$q->set($k, $v);
			}
		} elseif ($q->is_category()) {
			// Games, Apps and their genres: without Roblox scripts (the Roblox category itself goes to /roblox/).
			$cat = $q->get_queried_object();
			if ($cat && !self::is_roblox_category($cat->term_id)) {
				$q->set('tax_query', self::add_tax($q->get('tax_query'), self::not_roblox()));
			}
		}
	}

	/** Title search and Keyless, for queries that ask (y4apk_rq, y4apk_keyless). */
	public static function where($where, $q) {
		global $wpdb;
		$s = (string) $q->get('y4apk_rq');
		if ($s !== '') {
			$where .= $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($s) . '%');
		}
		if ($q->get('y4apk_keyless')) {
			$where .= ' AND ' . self::keyless_sql($wpdb->posts);
		}
		return $where;
	}

	/** The old Roblox category page: to /roblox/. */
	public static function redirect() {
		if (is_category() && self::is_roblox_category(get_queried_object_id()) && ($url = self::hub_url())) {
			wp_safe_redirect($url, 301);
			exit;
		}
	}

	public static function template($template) {
		if (is_tax(self::TAX)) {
			return locate_template('template/roblox-game.php') ?: $template;
		}
		$hub = self::hub_id();
		if ($hub && is_page($hub)) {
			return locate_template('template/roblox-hub.php') ?: $template;
		}
		return $template;
	}

	public static function assets() {
		$hub = self::hub_id();
		if (is_tax(self::TAX) || ($hub && is_page($hub)) || (is_singular('post') && self::is_roblox_post(get_queried_object_id()))) {
			// After the theme's own (core: inputs, styles: breadcrumbs), which it enqueues later on the same hook.
			wp_enqueue_style('y4apk-roblox', get_template_directory_uri() . '/assets/css/roblox.css', ['core', 'styles'], Y4APK_THEME_VERSION);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Titles and descriptions
	 * ---------------------------------------------------------------- */

	public static function title_parts($parts) {
		if (is_tax(self::TAX)) {
			$parts['title'] = sprintf(__('%s Scripts', 'y4apk'), single_term_title('', false));
		}
		return $parts;
	}

	/** Rank Math's default for a taxonomy, "Blox Fruits Archives", reads "Blox Fruits Scripts". */
	public static function rank_math_title($title) {
		if (is_tax(self::TAX)) {
			$name = single_term_title('', false);
			$title = str_replace($name . ' Archives', sprintf(__('%s Scripts', 'y4apk'), $name), (string) $title);
		}
		return $title;
	}

	public static function rank_math_description($desc) {
		if (is_tax(self::TAX) && trim((string) $desc) === '') {
			$term = get_queried_object();
			$t = self::totals($term);
			$desc = sprintf(_n('Free %1$s script for Roblox', 'Free %1$s scripts for Roblox: %2$s scripts', $term->count, 'y4apk'), $term->name, number_format_i18n($term->count))
				. ($t['keyless'] ? sprintf(__(', %s keyless', 'y4apk'), number_format_i18n($t['keyless'])) : '')
				. ($t['updated'] ? sprintf(__(', updated %s', 'y4apk'), mysql2date(get_option('date_format'), get_date_from_gmt($t['updated']))) : '')
				. '. ' . __('Copy a loadstring and run it in your executor.', 'y4apk');
		}
		return $desc;
	}

	/* ---------------------------------------------------------------- *
	 * Pieces of the pages
	 * ---------------------------------------------------------------- */

	public static function short_number($n) {
		$n = (float) $n;
		foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $size => $unit) {
			if ($n >= $size) {
				$v = $n / $size;
				$t = number_format($v, $v < 10 ? 1 : 0, '.', '');
				return (strpos($t, '.') !== false ? rtrim(rtrim($t, '0'), '.') : $t) . $unit; // 1.0K → 1K; 20K stays 20K
			}
		}
		return number_format_i18n($n);
	}

	public static function ago($time) {
		$d = max(0, time() - (int) $time);
		if ($d < HOUR_IN_SECONDS) {
			return sprintf(__('%dm ago', 'y4apk'), max(1, (int) floor($d / MINUTE_IN_SECONDS)));
		}
		if ($d < DAY_IN_SECONDS) {
			return sprintf(__('%dh ago', 'y4apk'), (int) floor($d / HOUR_IN_SECONDS));
		}
		if ($d < 30 * DAY_IN_SECONDS) {
			return sprintf(__('%dd ago', 'y4apk'), (int) floor($d / DAY_IN_SECONDS));
		}
		if ($d < YEAR_IN_SECONDS) {
			return sprintf(__('%dmo ago', 'y4apk'), (int) floor($d / (30 * DAY_IN_SECONDS)));
		}
		return sprintf(__('%dy ago', 'y4apk'), (int) floor($d / YEAR_IN_SECONDS));
	}

	/** The script hub in a title's brackets: "Blox Fruits Script - [Zee Hub]" → "Zee Hub". */
	public static function hub_name($post_id) {
		$name = (string) get_post_meta($post_id, 'wp_title_GP', true);
		return preg_match('/\[([^\]]{2,40})\]/u', $name, $m) ? trim($m[1]) : '';
	}

	public static function icon($name) {
		$paths = [
			'eye'    => '<path d="M12 5C6.5 5 2.7 9.3 1.5 12c1.2 2.7 5 7 10.5 7s9.3-4.3 10.5-7C21.3 9.3 17.5 5 12 5Zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Zm0-2.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>',
			'clock'  => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-1 3v5.4l4.3 2.6 1-1.7-3.3-2V7h-2Z"/>',
			'code'   => '<path d="M8.6 16.6 4 12l4.6-4.6L7.2 6 1.2 12l6 6 1.4-1.4Zm6.8 0L20 12l-4.6-4.6L16.8 6l6 6-6 6-1.4-1.4Z"/>',
			'users'  => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 4v2h14v-2c0-2.3-3.7-4-7-4Zm7.5-2.2a3.3 3.3 0 1 0-1.2-6.4 5.9 5.9 0 0 1 0 6.1c.4.2.8.3 1.2.3ZM17.6 13c1.6.9 2.4 2.2 2.4 4v2h3v-2c0-1.9-2.3-3.4-5.4-4Z"/>',
			'thumb'  => '<path d="M2 21h4V9H2v12Zm20-11a2 2 0 0 0-2-2h-6.3l1-4.6V3a1.5 1.5 0 0 0-.5-1.1L13.2 1 7.6 6.6A2 2 0 0 0 7 8v11a2 2 0 0 0 2 2h9a2 2 0 0 0 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2Z"/>',
			'play'   => '<path d="M8 5.5v13a1 1 0 0 0 1.5.9l10.2-6.5a1 1 0 0 0 0-1.8L9.5 4.6A1 1 0 0 0 8 5.5Z"/>',
			'share'  => '<path d="M18 16a3 3 0 0 0-2.4 1.2l-6.7-3.4a3 3 0 0 0 0-1.6l6.7-3.4A3 3 0 1 0 15 7c0 .3 0 .5.1.8L8.4 11.2a3 3 0 1 0 0 3.6l6.7 3.4A3 3 0 1 0 18 16Z"/>',
			'search' => '<path d="M10.5 3a7.5 7.5 0 0 1 6 12l4.7 4.8-1.4 1.4-4.8-4.7A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/>',
			'key'    => '<path d="M7 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm5.6-4A6 6 0 1 0 12.6 14H17v4h4v-4h2v-4H12.6Z"/>',
			'arrow'  => '<path d="M13.2 5.3 19.9 12l-6.7 6.7-1.4-1.4 4.3-4.3H4v-2h12.1l-4.3-4.3 1.4-1.4Z"/>',
		];
		return '<svg class="rbx-i" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="currentColor">' . (isset($paths[$name]) ? $paths[$name] : '') . '</svg>';
	}

	/** Breadcrumbs (breadcrumbsX): Roblox (the hub), then the game, a link unless it is this page. */
	public static function crumbs($game, $sep, $link, &$items, $game_is_current = false) {
		$crumbs = [];
		if ($hub = self::hub_url()) {
			$crumbs[] = [__('Roblox', 'y4apk'), $hub];
		}
		$crumbs[] = [$game->name, $game_is_current ? '' : get_term_link($game)];
		foreach ($crumbs as $c) {
			echo $sep . ($c[1] !== '' ? sprintf($link, esc_url($c[1]), esc_html($c[0])) : esc_html($c[0]));
			$item = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $c[0]];
			if ($c[1] !== '') {
				$item['item'] = $c[1];
			}
			$items[] = $item;
		}
	}

	/** A script card: wide picture with views and age, the title, the game or the script hub. */
	/** A script's wide picture: its first screenshot, else one of its game's (not the same on every card of a game). */
	public static function thumb($post_id) {
		$shots = get_post_meta($post_id, 'ss_images', true);
		if (!is_array($shots) || empty($shots[0]['ss_url'])) {
			$shots = self::game_shots($post_id);
			$shots = $shots ? array_slice($shots, $post_id % count($shots), 1) : [];
		}
		return !empty($shots[0]['ss_url']) ? $shots[0]['ss_url'] : y4apk_icon_url($post_id, 'medium_large');
	}

	public static function card($post_id, $show_game = true) {
		$title = get_the_title($post_id);
		$img = self::thumb($post_id);
		$views = (int) get_post_meta($post_id, 'wpb_post_views_count', true);
		$game = $show_game ? self::game_of($post_id) : null;
		$hub = self::hub_name($post_id);
		?>
		<article class="rbx-card">
			<a class="rbx-card-link" href="<?php echo esc_url(get_permalink($post_id)); ?>">
				<span class="rbx-card-media">
					<img src="<?php echo esc_url($img, ['http', 'https', 'data']); ?>" alt="<?php echo esc_attr(wp_strip_all_tags($title)); ?>" loading="lazy" decoding="async" width="480" height="270">
					<span class="rbx-chips">
						<span class="rbx-chip"><?php echo self::icon('eye'); ?><?php echo esc_html(self::short_number($views)); ?></span>
						<span class="rbx-chip"><?php echo self::icon('clock'); ?><?php echo esc_html(self::ago(get_post_modified_time('U', true, $post_id))); ?></span>
					</span>
					<?php if (self::keyless($title)) : ?><span class="rbx-badge"><?php echo self::icon('key'); ?><?php esc_html_e('Keyless', 'y4apk'); ?></span><?php endif; ?>
				</span>
				<span class="rbx-card-body">
					<span class="rbx-card-title"><?php echo esc_html(wp_strip_all_tags($title)); ?></span>
					<span class="rbx-card-meta">
						<?php if ($game) : ?>
							<img class="rbx-card-game" src="<?php echo esc_url(self::icon_url($game), ['http', 'https', 'data']); ?>" alt="" width="20" height="20" loading="lazy">
							<span><?php echo esc_html($game->name); ?></span>
						<?php elseif ($hub !== '') : ?>
							<?php echo self::icon('code'); ?><span><?php echo esc_html($hub); ?></span>
						<?php endif; ?>
					</span>
				</span>
			</a>
		</article>
		<?php
	}

	/** In place of Name / Category / OS on a Roblox post: its game, the key, players now and views. */
	public static function facts($post_id) {
		$game = self::game_of($post_id);
		$keyless = self::keyless(get_the_title($post_id));
		$s = $game ? self::stats($game) : null;
		$cards = [];
		if ($game) {
			$cards[] = ['code', __('Game', 'y4apk'), '<a class="f-app-meta-data rbx-fact-game" href="' . esc_url(get_term_link($game)) . '"><img src="' . esc_url(self::icon_url($game), ['http', 'https', 'data']) . '" alt="" width="22" height="22" loading="lazy">' . esc_html($game->name) . '</a>'];
		}
		$cards[] = ['key', __('Key', 'y4apk'), '<span class="f-app-meta-data ' . ($keyless ? 'rbx-fact-ok' : 'rbx-fact-warn') . '">' . esc_html($keyless ? __('No key needed', 'y4apk') : __('Key system', 'y4apk')) . '</span>'];
		if ($s && $s['playing'] !== null) {
			$play = self::play_url($game);
			$cards[] = ['users', __('Playing now', 'y4apk'), '<span class="f-app-meta-data rbx-fact-row"><span' . self::live($game, 'playing') . '>' . esc_html(self::short_number($s['playing'])) . '</span>'
				. ($play !== '' ? '<a class="rbx-fact-play" href="' . esc_url($play) . '" target="_blank" rel="nofollow noopener">' . self::icon('play') . esc_html__('Play', 'y4apk') . '</a>' : '') . '</span>'];
		} else {
			$cards[] = ['clock', __('Updated', 'y4apk'), '<span class="f-app-meta-data">' . esc_html(self::ago(get_post_modified_time('U', true, $post_id))) . '</span>'];
		}
		$cards[] = ['eye', __('Views', 'y4apk'), '<span class="f-app-meta-data">' . esc_html(number_format_i18n((int) get_post_meta($post_id, 'wpb_post_views_count', true))) . '</span>'];
		?>
		<div class="f-app-meta rbx-facts">
			<div class="grid-list grid4">
				<?php foreach ($cards as $c) : ?>
				<div class="b-grid">
					<div class="block">
						<i class="f-app-meta-icon c-blue"><?php echo self::icon($c[0]); ?></i>
						<span class="fw-b c-blue"><?php echo esc_html($c[1]); ?></span>
						<?php echo $c[2]; // built and escaped above ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** A game tile: icon, name, scripts and players. */
	public static function tile($term) {
		$s = self::stats($term);
		?>
		<a class="rbx-game" href="<?php echo esc_url(get_term_link($term)); ?>" data-name="<?php echo esc_attr(mb_strtolower($term->name)); ?>">
			<span class="rbx-game-icon"><img src="<?php echo esc_url(self::icon_url($term, 'medium'), ['http', 'https', 'data']); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy" decoding="async" width="160" height="160"></span>
			<span class="rbx-game-name"><?php echo esc_html($term->name); ?></span>
			<span class="rbx-game-meta">
				<span><?php echo self::icon('code'); ?><?php echo esc_html(sprintf(_n('%s script', '%s scripts', $term->count, 'y4apk'), number_format_i18n($term->count))); ?></span>
				<?php if ($s['playing'] !== null) : ?><span title="<?php esc_attr_e('Playing now on Roblox', 'y4apk'); ?>"><?php echo self::icon('users'); ?><span<?php echo self::live($term, 'playing'); ?>><?php echo esc_html(self::short_number($s['playing'])); ?></span></span><?php endif; ?>
			</span>
		</a>
		<?php
	}

	/** On a Roblox post, in place of "Similar": more scripts for its game, and other games. */
	public static function related($post_id) {
		$game = self::game_of($post_id);
		$args = ['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 8, 'post__not_in' => [$post_id], 'orderby' => 'modified', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'no_found_rows' => true];
		$args['tax_query'] = $game ? [['taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => $game->term_id]] : self::only_roblox();
		$q = new WP_Query($args);
		$others = self::games(6, $game ? $game->term_id : 0);
		if (!$q->have_posts() && !$others) {
			return;
		}
		?>
		<div class="rbx rbx-related">
			<?php if ($q->have_posts()) : ?>
			<div class="section">
				<div class="rbx-head">
					<h3 class="section-title rbx-title"><?php echo esc_html($game ? sprintf(__('More %s scripts', 'y4apk'), $game->name) : __('More Roblox scripts', 'y4apk')); ?></h3>
					<a class="rbx-more" href="<?php echo esc_url($game ? get_term_link($game) : self::hub_url()); ?>"><?php echo esc_html($game ? sprintf(__('All %s', 'y4apk'), number_format_i18n($game->count)) : __('All', 'y4apk')); ?><?php echo self::icon('arrow'); ?></a>
				</div>
				<div class="rbx-grid">
					<?php foreach ($q->posts as $p) {
						self::card($p->ID, !$game);
					} ?>
				</div>
			</div>
			<?php endif; ?>
			<?php if ($others) : ?>
			<div class="section">
				<div class="rbx-head">
					<h3 class="section-title rbx-title"><?php esc_html_e('Other Roblox games', 'y4apk'); ?></h3>
					<?php if (self::hub_url()) : ?><a class="rbx-more" href="<?php echo esc_url(self::hub_url()); ?>"><?php esc_html_e('All games', 'y4apk'); ?><?php echo self::icon('arrow'); ?></a><?php endif; ?>
				</div>
				<div class="rbx-games rbx-games-row">
					<?php foreach ($others as $t) {
						self::tile($t);
					} ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The header's "Roblox": games with their icons, like the Games and Apps menus. */
	public static function menu() {
		$url = self::hub_url();
		$games = self::games(self::MENU);
		$first = $games ? get_term_link($games[0]) : '';
		?>
		<div class="h-menu-item h-menu-roblox" style="display:none;">
			<a class="h-link" href="<?php echo esc_url($url ?: $first); ?>">
				<?php esc_html_e('Roblox', 'y4apk'); ?>
				<svg width="16" height="16" class="carrot"><use xlink:href="#i__carrot"></use></svg>
			</a>
			<div class="h-menu-drop" style="display:none;">
				<div class="wrp">
					<?php if ($url) : ?><a class="h-menu-drop-all btn btn-border" href="<?php echo esc_url($url); ?>" style="display:none;"><?php esc_html_e('All Roblox games', 'y4apk'); ?></a><?php endif; ?>
					<ul class="h-menu-subitems">
						<?php foreach ($games as $t) : ?>
						<li class="h-menu-subitem">
							<a href="<?php echo esc_url(get_term_link($t)); ?>">
								<i class="h-menu-subitem-icon"><img src="<?php echo esc_url(self::icon_url($t), ['http', 'https', 'data']); ?>" alt="" width="32" height="32" loading="lazy"></i>
								<span class="h-menu-subitem-text"><?php echo esc_html($t->name); ?></span> <span class="muted"><?php echo esc_html(number_format_i18n($t->count)); ?></span>
							</a>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php if ($url) : ?><a class="h-menu-roblox-all" href="<?php echo esc_url($url); ?>"><?php esc_html_e('All Roblox games', 'y4apk'); ?> →</a><?php endif; ?>
					<?php if (class_exists('Y4APK_Members') && Y4APK_Members::open()) : ?><a class="h-menu-roblox-all" style="margin-left:1.25rem" href="<?php echo esc_url(Y4APK_Members::url(is_user_logged_in() ? 'submit' : 'register')); ?>"><?php esc_html_e('Share your script', 'y4apk'); ?> +</a><?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Roblox's API
	 * ---------------------------------------------------------------- */

	/** The place ID in a roblox.com/games/<id>/… link, or a bare ID; 0 when there is none. */
	public static function place_from($input) {
		$input = trim((string) $input);
		if (preg_match('~^\d{3,15}$~', $input)) {
			return $input;
		}
		if (preg_match('~roblox\.com/(?:[a-z]{2}(?:-[a-z]{2})?/)?games/(\d{3,15})~i', $input, $m) || preg_match('~[?&]placeId=(\d{3,15})~i', $input, $m)) {
			return $m[1];
		}
		return '';
	}

	public static function name_from_link($input) {
		return self::name_from_url($input);
	}

	/** The wide picture for a game: its first Roblox screenshot, its banner, or its icon. */
	public static function artwork($term) {
		$s = get_term_meta($term->term_id, self::M_SHOTS, true);
		if (is_array($s) && !empty($s['urls'][0]) && self::cdn_url($s['urls'][0])) {
			return $s['urls'][0];
		}
		$banner = self::banner_url($term);
		return $banner !== '' ? $banner : self::icon_url($term, 'medium');
	}

	/** "roblox.com/games/2753915549/Blox-Fruits" → "Blox Fruits". */
	private static function name_from_url($input) {
		return preg_match('~/games/\d+/([^/?#]+)~', (string) $input, $m) ? self::clean_name(str_replace(['-', '_'], ' ', rawurldecode($m[1]))) : '';
	}

	private static function get($url) {
		$r = wp_remote_get($url, ['timeout' => 15, 'user-agent' => self::UA, 'headers' => ['Accept' => 'application/json']]);
		if (is_wp_error($r)) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		$json = json_decode((string) wp_remote_retrieve_body($r), true);
		if ($code !== 200 || !is_array($json)) {
			return new WP_Error('roblox', sprintf(__('Roblox answered %d.', 'y4apk'), $code));
		}
		return $json;
	}

	/** What Roblox says about a place: name, players, visits, likes, icon and banner URLs; or a WP_Error. */
	public static function fetch($place) {
		$u = self::get('https://apis.roblox.com/universes/v1/places/' . rawurlencode($place) . '/universe');
		if (is_wp_error($u)) {
			return $u;
		}
		$universe = isset($u['universeId']) ? (string) $u['universeId'] : '';
		if ($universe === '' || $universe === '0') {
			return new WP_Error('roblox', __('Roblox has no game with that place ID.', 'y4apk'));
		}
		$many = self::fetch_many([$universe]);
		if (is_wp_error($many)) {
			return $many;
		}
		if (empty($many[$universe])) {
			return new WP_Error('roblox', __('Roblox has no game with that place ID.', 'y4apk'));
		}
		$data = $many[$universe] + ['place' => (string) $place, 'universe' => $universe];
		$icons = self::images('https://thumbnails.roblox.com/v1/games/icons?universeIds=' . $universe . '&returnPolicy=PlaceHolder&size=512x512&format=Png&isCircular=false');
		$data['icon'] = isset($icons[$universe]) ? $icons[$universe] : '';
		$b = self::get('https://thumbnails.roblox.com/v1/games/multiget/thumbnails?universeIds=' . $universe . '&countPerUniverse=1&defaults=true&size=768x432&format=Png&isCircular=false');
		$shots = self::shots_of([$universe]);
		$data['shots'] = !is_wp_error($shots) && !empty($shots[$universe]) ? $shots[$universe] : [];
		$data['banner'] = '';
		if (!is_wp_error($b) && !empty($b['data'])) {
			foreach ((array) $b['data'] as $row) {
				if (isset($row['thumbnails'][0]['imageUrl'], $row['thumbnails'][0]['state']) && $row['thumbnails'][0]['state'] === 'Completed') {
					$data['banner'] = (string) $row['thumbnails'][0]['imageUrl'];
				}
			}
		}
		return $data;
	}

	/** Names and numbers of several universes at once: [universe => [...]] (up to 50). */
	private static function fetch_many($universes) {
		$ids = implode(',', array_map('rawurlencode', $universes));
		$g = self::get('https://games.roblox.com/v1/games?universeIds=' . $ids);
		if (is_wp_error($g)) {
			return $g;
		}
		$out = [];
		foreach (isset($g['data']) ? (array) $g['data'] : [] as $row) {
			if (!isset($row['id'])) {
				continue;
			}
			$out[(string) $row['id']] = [
				'name'      => isset($row['name']) ? (string) $row['name'] : '',
				'root'      => isset($row['rootPlaceId']) ? (string) $row['rootPlaceId'] : '',
				'playing'   => isset($row['playing']) ? (int) $row['playing'] : null,
				'visits'    => isset($row['visits']) ? (int) $row['visits'] : null,
				'favorites' => isset($row['favoritedCount']) ? (int) $row['favoritedCount'] : null,
				'creator'   => isset($row['creator']['name']) ? (string) $row['creator']['name'] : '',
				'updated'   => isset($row['updated']) ? (int) strtotime((string) $row['updated']) : 0,
				'up'        => null,
				'down'      => null,
			];
		}
		$v = self::get('https://games.roblox.com/v1/games/votes?universeIds=' . $ids);
		if (!is_wp_error($v)) {
			foreach (isset($v['data']) ? (array) $v['data'] : [] as $row) {
				if (isset($row['id']) && isset($out[(string) $row['id']])) {
					$out[(string) $row['id']]['up'] = isset($row['upVotes']) ? (int) $row['upVotes'] : null;
					$out[(string) $row['id']]['down'] = isset($row['downVotes']) ? (int) $row['downVotes'] : null;
				}
			}
		}
		return $out;
	}

	/** [universe => image URL] from a thumbnails answer ({data: [{targetId, state, imageUrl}]}). */
	private static function images($url) {
		$r = self::get($url);
		$out = [];
		if (!is_wp_error($r)) {
			foreach (isset($r['data']) ? (array) $r['data'] : [] as $row) {
				if (isset($row['targetId'], $row['imageUrl']) && (!isset($row['state']) || $row['state'] === 'Completed')) {
					$out[(string) $row['targetId']] = (string) $row['imageUrl'];
				}
			}
		}
		return $out;
	}

	/**
	 * Up to SHOTS screenshots of each universe, from one call for up to 50 games:
	 * [universe => [URL, …]], or a WP_Error. They stay on Roblox's CDN (nothing to
	 * download or resize here); its addresses expire, so the hourly refresh asks again.
	 */
	private static function shots_of($universes) {
		$r = self::get('https://thumbnails.roblox.com/v1/games/multiget/thumbnails?universeIds=' . implode(',', array_map('rawurlencode', $universes))
			. '&countPerUniverse=' . self::SHOTS . '&defaults=true&size=768x432&format=Webp&isCircular=false');
		if (is_wp_error($r)) {
			return $r;
		}
		$out = [];
		foreach (isset($r['data']) ? (array) $r['data'] : [] as $row) {
			$u = isset($row['universeId']) ? (string) $row['universeId'] : (count($universes) === 1 ? (string) $universes[0] : '');
			foreach ($u !== '' && isset($row['thumbnails']) ? (array) $row['thumbnails'] : [] as $t) {
				if (isset($t['imageUrl'], $t['state']) && $t['state'] === 'Completed' && self::cdn_url($t['imageUrl'])) {
					$out[$u][] = (string) $t['imageUrl'];
				}
			}
		}
		return $out;
	}

	public static function cdn_url($url) {
		return (bool) preg_match('~^https://[a-z0-9-]+\.rbxcdn\.com/[^\s"\'<>]+$~i', (string) $url);
	}

	private static function save_shots($term_id, $urls) {
		$urls = array_slice(array_values(array_filter((array) $urls, [__CLASS__, 'cdn_url'])), 0, self::SHOTS);
		if ($urls) {
			update_term_meta($term_id, self::M_SHOTS, ['urls' => $urls, 'fetched' => time()]);
		}
	}

	/** The screenshots of every game with a Roblox link (daily, and once after this update). */
	public static function refresh_shots() {
		$terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'meta_key' => self::M_UNIVERSE, 'meta_compare' => 'EXISTS']);
		$by = [];
		foreach (is_array($terms) ? $terms : [] as $t) {
			$by[(string) get_term_meta($t->term_id, self::M_UNIVERSE, true)] = $t->term_id;
		}
		unset($by['']);
		foreach (array_chunk(array_keys($by), 50) as $chunk) {
			$shots = self::shots_of($chunk);
			if (is_wp_error($shots)) {
				return;
			}
			foreach ($shots as $u => $urls) {
				if (isset($by[$u])) {
					self::save_shots($by[$u], $urls);
				}
			}
		}
	}

	public static function first_shots() {
		if (!get_option('y4apk_roblox_shots_v1')) {
			update_option('y4apk_roblox_shots_v1', 1);
			wp_schedule_single_event(time() + 30, self::SHOTS_HOOK);
		}
	}

	/** A Roblox post without screenshots of its own (a member's) shows its game's: [['ss_url' => URL], …]. */
	public static function game_shots($post_id) {
		$game = self::game_of($post_id);
		if (!$game) {
			return [];
		}
		$s = get_term_meta($game->term_id, self::M_SHOTS, true);
		$urls = is_array($s) && !empty($s['urls']) ? array_values(array_filter((array) $s['urls'], [__CLASS__, 'cdn_url'])) : [];
		if (!$urls && ($banner = self::banner_url($game)) !== '') {
			$urls = [$banner];
		}
		return array_map(function ($url) { return ['ss_url' => $url]; }, $urls);
	}

	/** Keeps what Roblox said; downloads its icon and banner where the game has none of its own. */
	public static function apply($term_id, $data, $replace = false) {
		$stats = self::stats(get_term($term_id));
		foreach (['playing', 'visits', 'favorites', 'up', 'down', 'creator', 'updated'] as $k) {
			if (array_key_exists($k, $data)) {
				$stats[$k] = $data[$k];
			}
		}
		$stats['fetched'] = time();
		update_term_meta($term_id, self::M_STATS, $stats);
		if (!empty($data['place'])) {
			update_term_meta($term_id, self::M_PLACE, (string) $data['place']);
		}
		if (!empty($data['universe'])) {
			update_term_meta($term_id, self::M_UNIVERSE, (string) $data['universe']);
		}
		if (!empty($data['shots'])) {
			self::save_shots($term_id, $data['shots']);
		}
		$term = get_term($term_id);
		$src = (string) get_term_meta($term_id, self::M_ICON_SRC, true);
		if (!empty($data['icon']) && ($replace || !get_term_meta($term_id, self::M_ICON, true) || $src === 'post')) {
			$att = self::sideload($data['icon'], $term->name . ' Roblox icon');
			if (!is_wp_error($att)) {
				update_term_meta($term_id, self::M_ICON, $att);
				update_term_meta($term_id, self::M_ICON_SRC, 'roblox');
			}
		}
		if (!empty($data['banner']) && ($replace || !get_term_meta($term_id, self::M_BANNER, true))) {
			$att = self::sideload($data['banner'], $term->name . ' Roblox');
			if (!is_wp_error($att)) {
				update_term_meta($term_id, self::M_BANNER, $att);
			}
		}
	}

	/** An image from Roblox's CDN (its URLs have no file extension) into the media library. */
	private static function sideload($url, $name) {
		$r = wp_remote_get($url, ['timeout' => 25, 'user-agent' => self::UA]);
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return new WP_Error('roblox', __('could not download it', 'y4apk'));
		}
		$body = (string) wp_remote_retrieve_body($r);
		$size = @getimagesizefromstring($body);
		$types = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
		if (!$size || !isset($types[$size['mime']])) {
			return new WP_Error('roblox', __('not an image', 'y4apk'));
		}
		$file = wp_upload_bits(sanitize_file_name($name . '.' . $types[$size['mime']]), null, $body);
		if (!empty($file['error'])) {
			return new WP_Error('roblox', $file['error']);
		}
		$att = wp_insert_attachment(['post_mime_type' => $size['mime'], 'post_title' => $name, 'post_status' => 'inherit'], $file['file'], 0, true);
		if (is_wp_error($att)) {
			return $att;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $file['file']));
		update_post_meta($att, '_wp_attachment_image_alt', $name);
		return (int) $att;
	}

	/** Hourly: players, visits and likes of every game with a Roblox link; screenshots; icons it still lacks. */
	public static function refresh() {
		$terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'meta_key' => self::M_UNIVERSE, 'meta_compare' => 'EXISTS']);
		if (!is_array($terms) || !$terms) {
			return;
		}
		$by = [];
		foreach ($terms as $t) {
			$by[(string) get_term_meta($t->term_id, self::M_UNIVERSE, true)] = $t->term_id;
		}
		$icons_left = 5;
		foreach (array_chunk(array_keys($by), 50) as $chunk) {
			$many = self::fetch_many($chunk);
			if (is_wp_error($many)) {
				return;
			}
			$need = [];
			foreach ($many as $u => $data) {
				self::apply($by[$u], $data);
				if (!get_term_meta($by[$u], self::M_ICON, true) || get_term_meta($by[$u], self::M_ICON_SRC, true) === 'post') {
					$need[] = $u;
				}
			}
			if ($need && $icons_left > 0) {
				$need = array_slice($need, 0, $icons_left);
				$icons_left -= count($need);
				foreach (self::images('https://thumbnails.roblox.com/v1/games/icons?universeIds=' . implode(',', $need) . '&returnPolicy=PlaceHolder&size=512x512&format=Png&isCircular=false') as $u => $url) {
					if (isset($by[$u])) {
						self::apply($by[$u], ['icon' => $url]);
					}
				}
			}
		}
	}

	/* ---------------------------------------------------------------- *
	 * Finding and making games
	 * ---------------------------------------------------------------- */

	/** Spaces tidied; "BLOX FRUITS" from a post title → "Blox Fruits" (a name from Roblox keeps its own case: "DOORS"). */
	public static function clean_name($name, $fix_case = true) {
		$name = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $name), ENT_QUOTES, 'UTF-8')), " \t-–—|:");
		if ($fix_case && mb_strlen($name) > 3 && !preg_match('/\p{Ll}/u', $name)) {
			$name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
		}
		return mb_substr($name, 0, 80);
	}

	/** The game a script post is most likely for, from its App Name or title. */
	public static function guess($post_id) {
		$t = (string) get_post_meta($post_id, 'wp_title_GP', true);
		$t = $t !== '' ? $t : get_the_title($post_id);
		$t = html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES, 'UTF-8');
		$t = preg_replace('/^\s*roblox\s*[:\-–]?\s*/iu', '', $t);   // "Roblox Blox Fruits Script"
		$t = preg_replace('/\s*[\[\(【].*$/u', '', $t);              // "… [Zee Hub]", "… (No Key)"
		$t = preg_replace('/\s+[-–—|:]\s+.*$/u', '', $t);           // "… - Auto Farm"
		$t = preg_replace('/\s+scripts?\b.*$/iu', '', $t);          // "… Script Zee Hub VIP"
		return self::clean_name($t);
	}

	private static function term_by_name($name) {
		$t = get_term_by('slug', sanitize_title($name), self::TAX);
		return $t ? $t : get_term_by('name', $name, self::TAX);
	}

	/** A game from a name, a roblox.com link or a place ID: its term ID (made if new), or a WP_Error. */
	public static function find_or_create($input, $fetch = true) {
		$input = trim((string) $input);
		$place = self::place_from($input);
		$data = null;
		if ($place !== '') {
			$found = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1, 'meta_key' => self::M_PLACE, 'meta_value' => $place]);
			if ($found && !is_wp_error($found)) {
				return (int) $found[0];
			}
			$data = $fetch ? self::fetch($place) : null;
			$name = $data && !is_wp_error($data) && $data['name'] !== '' ? self::clean_name($data['name'], false) : self::name_from_url($input);
			$name = $name !== '' ? $name : sprintf(__('Roblox game %s', 'y4apk'), $place);
		} else {
			$name = self::clean_name($input);
		}
		if ($name === '') {
			return new WP_Error('roblox', __('Give the game a name, a roblox.com link or a place ID.', 'y4apk'));
		}
		$term = self::term_by_name($name);
		if ($term) {
			$id = (int) $term->term_id;
		} else {
			$r = wp_insert_term($name, self::TAX);
			if (is_wp_error($r)) {
				if ($r->get_error_code() !== 'term_exists') {
					return $r;
				}
				$id = (int) $r->get_error_data();
			} else {
				$id = (int) $r['term_id'];
			}
		}
		if ($place !== '') {
			update_term_meta($id, self::M_PLACE, $place);
			if ($data && !is_wp_error($data)) {
				self::apply($id, $data);
			}
		}
		return $id;
	}

	/** A game without an icon takes the post's picture until Roblox's comes. */
	private static function default_icon($term_id, $post_id) {
		$thumb = (int) get_post_thumbnail_id($post_id);
		if ($thumb && !get_term_meta($term_id, self::M_ICON, true)) {
			update_term_meta($term_id, self::M_ICON, $thumb);
			update_term_meta($term_id, self::M_ICON_SRC, 'post');
		}
	}

	/* ---------------------------------------------------------------- *
	 * The post's "Roblox Game" box
	 * ---------------------------------------------------------------- */

	public static function add_box() {
		add_meta_box('y4apk-roblox', __('Roblox Game', 'y4apk'), [__CLASS__, 'box'], 'post', 'side', 'high');
	}

	public static function box($post) {
		$current = self::game_of($post->ID);
		$games = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name']);
		$guess = !$current && self::categories() && has_category(self::categories(), $post->ID) ? self::guess($post->ID) : '';
		wp_nonce_field('y4apk_roblox_box', 'y4apk_roblox_nonce');
		?>
		<p><label for="y4apk-roblox-game"><strong><?php esc_html_e('Game', 'y4apk'); ?></strong></label>
			<select id="y4apk-roblox-game" name="y4apk_roblox_game" class="widefat">
				<option value="0"><?php esc_html_e('— No game —', 'y4apk'); ?></option>
				<?php foreach (is_array($games) ? $games : [] as $g) : ?>
					<option value="<?php echo esc_attr($g->term_id); ?>" <?php selected($current && $current->term_id === $g->term_id); ?>><?php echo esc_html($g->name); ?> (<?php echo esc_html(number_format_i18n($g->count)); ?>)</option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="y4apk-roblox-new"><?php esc_html_e('Or a new game', 'y4apk'); ?></label>
			<input type="text" id="y4apk-roblox-new" name="y4apk_roblox_new" class="widefat" placeholder="<?php esc_attr_e('Name, roblox.com/games/… link or place ID', 'y4apk'); ?>"></p>
		<?php if ($guess !== '') : ?>
			<p class="description"><?php esc_html_e('From the title:', 'y4apk'); ?> <button type="button" class="button-link" id="y4apk-roblox-guess" data-name="<?php echo esc_attr($guess); ?>"><?php echo esc_html($guess); ?></button></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e('Its scripts page lists this post; it stays out of Games and Apps. A roblox.com link brings the game’s icon, players and likes.', 'y4apk'); ?></p>
		<?php if ($current) : ?>
			<p><a href="<?php echo esc_url(get_term_link($current)); ?>" target="_blank"><?php echo esc_html(sprintf(__('View %s scripts', 'y4apk'), $current->name)); ?> ↗</a></p>
		<?php endif;
	}

	public static function save_box($post_id) {
		if (!isset($_POST['y4apk_roblox_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['y4apk_roblox_nonce'])), 'y4apk_roblox_box')
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
			return;
		}
		$id = isset($_POST['y4apk_roblox_game']) ? absint($_POST['y4apk_roblox_game']) : 0;
		$new = isset($_POST['y4apk_roblox_new']) ? trim(sanitize_text_field(wp_unslash($_POST['y4apk_roblox_new']))) : '';
		if ($new !== '' && current_user_can('manage_categories')) {
			$made = self::find_or_create($new);
			if (!is_wp_error($made)) {
				$id = $made;
			}
		}
		if ($id && get_term($id, self::TAX)) {
			wp_set_object_terms($post_id, [(int) $id], self::TAX);
			self::default_icon($id, $post_id);
		} else {
			wp_set_object_terms($post_id, [], self::TAX);
		}
	}

	/* ---------------------------------------------------------------- *
	 * The game's own screen (Posts ▸ Roblox Games)
	 * ---------------------------------------------------------------- */

	public static function add_fields() {
		?>
		<div class="form-field">
			<input type="hidden" name="y4apk_rbx_term" value="1">
			<label for="y4apk-rbx-link"><?php esc_html_e('Roblox link or place ID', 'y4apk'); ?></label>
			<input type="text" id="y4apk-rbx-link" name="y4apk_rbx_link" placeholder="https://www.roblox.com/games/2753915549/Blox-Fruits">
			<p><?php esc_html_e('Optional. Brings the game’s icon, banner, players, visits and likes from Roblox (they are brought up to date every day).', 'y4apk'); ?></p>
		</div>
		<?php
	}

	public static function edit_fields($term) {
		$place = (string) get_term_meta($term->term_id, self::M_PLACE, true);
		$s = self::stats($term);
		?>
		<tr class="form-field">
			<th scope="row"><label for="y4apk-rbx-link"><?php esc_html_e('Roblox link or place ID', 'y4apk'); ?></label></th>
			<td><input type="hidden" name="y4apk_rbx_term" value="1">
				<input type="text" id="y4apk-rbx-link" name="y4apk_rbx_link" value="<?php echo esc_attr($place !== '' ? 'https://www.roblox.com/games/' . $place : ''); ?>" placeholder="https://www.roblox.com/games/2753915549/Blox-Fruits">
				<p><label><input type="checkbox" name="y4apk_rbx_fetch" value="1"> <?php esc_html_e('Fetch from Roblox now', 'y4apk'); ?></label>
					<label style="margin-left:12px"><input type="checkbox" name="y4apk_rbx_replace" value="1"> <?php esc_html_e('… and use its icon and banner instead of these', 'y4apk'); ?></label></p>
				<?php if ($s['fetched']) : ?>
					<p class="description"><?php echo esc_html(sprintf(__('From Roblox, %1$s: %2$s playing · %3$s visits · %4$s likes · by %5$s', 'y4apk'),
						human_time_diff($s['fetched']) . ' ' . __('ago', 'y4apk'),
						$s['playing'] !== null ? number_format_i18n($s['playing']) : '—',
						$s['visits'] !== null ? self::short_number($s['visits']) : '—',
						$s['up'] !== null && ($s['up'] + $s['down']) > 0 ? round(100 * $s['up'] / ($s['up'] + $s['down'])) . '%' : '—',
						$s['creator'] !== '' ? $s['creator'] : '—')); ?></p>
				<?php endif; ?></td>
		</tr>
		<?php foreach ([self::M_ICON => [__('Icon', 'y4apk'), __('Square. The game’s Roblox icon, else the newest post’s picture.', 'y4apk')], self::M_BANNER => [__('Banner', 'y4apk'), __('Wide, behind the title of the game’s page. Roblox’s, else the newest post’s first screenshot.', 'y4apk')]] as $key => $info) :
			$att = (int) get_term_meta($term->term_id, $key, true);
			$url = $att ? wp_get_attachment_image_url($att, 'medium') : ''; ?>
		<tr class="form-field">
			<th scope="row"><?php echo esc_html($info[0]); ?></th>
			<td class="y4apk-rbx-media">
				<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($att ?: ''); ?>">
				<img src="<?php echo esc_url($url ?: ''); ?>" alt="" style="<?php echo $url ? '' : 'display:none;'; ?>max-width:<?php echo $key === self::M_ICON ? '96px' : '320px'; ?>;height:auto;border-radius:12px;display:block;margin-bottom:8px">
				<button type="button" class="button y4apk-rbx-pick"><?php esc_html_e('Choose', 'y4apk'); ?></button>
				<button type="button" class="button-link y4apk-rbx-clear" style="margin-left:8px"><?php esc_html_e('Remove', 'y4apk'); ?></button>
				<p class="description"><?php echo esc_html($info[1]); ?></p>
			</td>
		</tr>
		<?php endforeach;
	}

	public static function save_fields($term_id) {
		if (!isset($_POST['y4apk_rbx_term']) || !current_user_can('manage_categories')) {
			return;
		}
		foreach ([self::M_ICON, self::M_BANNER] as $key) {
			if (!isset($_POST[$key])) {
				continue;
			}
			$att = absint($_POST[$key]);
			$was = (int) get_term_meta($term_id, $key, true);
			if ($att && $att !== $was) {
				update_term_meta($term_id, $key, $att);
				if ($key === self::M_ICON) {
					update_term_meta($term_id, self::M_ICON_SRC, 'manual');
				}
			} elseif (!$att && $was) {
				delete_term_meta($term_id, $key);
				if ($key === self::M_ICON) {
					delete_term_meta($term_id, self::M_ICON_SRC);
				}
			}
		}
		$link = isset($_POST['y4apk_rbx_link']) ? trim(sanitize_text_field(wp_unslash($_POST['y4apk_rbx_link']))) : '';
		$place = self::place_from($link);
		$was = (string) get_term_meta($term_id, self::M_PLACE, true);
		if ($link === '' && $was !== '') {
			delete_term_meta($term_id, self::M_PLACE);
			delete_term_meta($term_id, self::M_UNIVERSE);
			delete_term_meta($term_id, self::M_STATS);
			return;
		}
		if ($place === '' || ($place === $was && empty($_POST['y4apk_rbx_fetch']))) {
			return;
		}
		update_term_meta($term_id, self::M_PLACE, $place);
		$data = self::fetch($place);
		if (is_wp_error($data)) {
			set_transient('y4apk_roblox_error_' . get_current_user_id(), $data->get_error_message(), MINUTE_IN_SECONDS);
			return;
		}
		self::apply($term_id, $data, !empty($_POST['y4apk_rbx_replace']));
	}

	public static function columns($cols) {
		$out = [];
		foreach ($cols as $k => $v) {
			if ($k === 'name') {
				$out['y4apk_icon'] = '';
			}
			$out[$k] = $v;
			if ($k === 'name') {
				$out['y4apk_roblox'] = __('On Roblox', 'y4apk');
			}
		}
		unset($out['description']);
		return $out;
	}

	public static function column($out, $col, $term_id) {
		$term = get_term($term_id, self::TAX);
		if ($col === 'y4apk_icon') {
			return '<img src="' . esc_url(self::icon_url($term), ['http', 'https', 'data']) . '" alt="" width="40" height="40" style="border-radius:10px;object-fit:cover;display:block">';
		}
		if ($col === 'y4apk_roblox') {
			$s = self::stats($term);
			$url = self::play_url($term);
			if (!$url) {
				return '<span class="description">' . esc_html__('No link yet', 'y4apk') . '</span>';
			}
			return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string) get_term_meta($term_id, self::M_PLACE, true)) . '</a>'
				. ($s['playing'] !== null ? '<br><span class="description">' . esc_html(sprintf(__('%s playing', 'y4apk'), self::short_number($s['playing']))) . '</span>' : '');
		}
		return $out;
	}

	public static function admin_assets($hook) {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && $screen->taxonomy === self::TAX && $hook === 'term.php') {
			wp_enqueue_media();
			wp_add_inline_script('jquery-core', "jQuery(function($){\$(document).on('click','.y4apk-rbx-pick',function(e){e.preventDefault();var td=\$(this).closest('td'),f=wp.media({title:'" . esc_js(__('Choose an image', 'y4apk')) . "',library:{type:'image'},multiple:false});f.on('select',function(){var a=f.state().get('selection').first().toJSON(),u=(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url);td.find('input[type=hidden]').val(a.id);td.find('img').attr('src',u).show();});f.open();});\$(document).on('click','.y4apk-rbx-clear',function(e){e.preventDefault();var td=\$(this).closest('td');td.find('input[type=hidden]').val('');td.find('img').hide();});});");
		}
		if ($screen && $screen->id === 'edit-' . self::TAX) {
			wp_add_inline_style('common', '.column-y4apk_icon{width:52px}.column-y4apk_roblox{width:20%}');
		}
		if ($screen && $screen->base === 'post' && $screen->post_type === 'post') {
			wp_add_inline_script('jquery-core', "jQuery(function($){\$(document).on('click','#y4apk-roblox-guess',function(){var n=String(\$(this).data('name')),s=\$('#y4apk-roblox-game'),o=s.find('option').filter(function(){return \$(this).text().replace(/\\s*\\(\\d[\\d,.]*\\)\$/,'').toLowerCase()===n.toLowerCase();});if(o.length){s.val(o.val());\$('#y4apk-roblox-new').val('');}else{s.val('0');\$('#y4apk-roblox-new').val(n);}});});");
		}
	}

	/* ---------------------------------------------------------------- *
	 * Sorting the posts already in the Roblox category
	 * ---------------------------------------------------------------- */

	/** Posts in the Roblox category with no game yet. */
	private static function unsorted($limit = -1) {
		if (!self::categories()) {
			return [];
		}
		return get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'], 'numberposts' => $limit, 'orderby' => 'title', 'order' => 'ASC',
			'tax_query' => ['relation' => 'AND',
				['taxonomy' => 'category', 'field' => 'term_id', 'terms' => self::categories(), 'include_children' => false],
				['taxonomy' => self::TAX, 'operator' => 'NOT EXISTS']]]);
	}

	public static function admin_menu() {
		add_submenu_page('edit.php', __('Sort Roblox posts', 'y4apk'), __('Sort Roblox posts', 'y4apk'), 'manage_categories', 'y4apk-roblox-sort', [__CLASS__, 'sort_page']);
	}

	public static function notice() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || !current_user_can('manage_categories') || !in_array($screen->id, ['edit-post', 'edit-' . self::TAX], true)) {
			return;
		}
		if ($err = get_transient('y4apk_roblox_error_' . get_current_user_id())) {
			delete_transient('y4apk_roblox_error_' . get_current_user_id());
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf(__('Roblox: %s The game was saved without its Roblox details.', 'y4apk'), $err)) . '</p></div>';
		}
		$n = count(self::unsorted(300));
		if ($n) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html(sprintf(_n('%s Roblox post is not in a game yet.', '%s Roblox posts are not in a game yet.', $n, 'y4apk'), number_format_i18n($n))) . '</strong> '
				. esc_html__('Each game gets its own scripts page at /roblox/<game>/, and Roblox posts stay out of Games and Apps.', 'y4apk')
				. ' <a class="button button-primary" href="' . esc_url(admin_url('edit.php?page=y4apk-roblox-sort')) . '">' . esc_html__('Sort them into games', 'y4apk') . '</a></p></div>';
		}
	}

	public static function sort_page() {
		$posts = self::unsorted(300);
		$games = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name', 'fields' => 'names']);
		$guesses = [];
		foreach ($posts as $p) {
			$g = self::guess($p->ID);
			$known = $g !== '' ? self::term_by_name($g) : null;
			$guesses[$p->ID] = $known ? $known->name : $g;
		}
		$names = array_unique(array_merge(is_array($games) ? $games : [], array_filter(array_values($guesses))));
		natcasesort($names);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Sort Roblox posts into games', 'y4apk'); ?></h1>
			<?php if (isset($_GET['sorted'])) : ?>
				<div class="notice notice-success"><p><?php echo esc_html(sprintf(__('%1$s posts put in %2$s games.', 'y4apk'), number_format_i18n(absint($_GET['sorted'])), number_format_i18n(isset($_GET['games']) ? absint($_GET['games']) : 0))); ?>
					<?php if (self::hub_url()) : ?><a href="<?php echo esc_url(self::hub_url()); ?>" target="_blank"><?php esc_html_e('See the Roblox page', 'y4apk'); ?> ↗</a><?php endif; ?></p></div>
			<?php endif; ?>
			<?php if (!$posts) : ?>
				<p><?php esc_html_e('Every post in the Roblox category is in a game.', 'y4apk'); ?> <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=' . self::TAX)); ?>"><?php esc_html_e('Roblox games', 'y4apk'); ?></a></p>
			<?php else : ?>
			<p><?php esc_html_e('The game of each post, read from its title. Change any that are wrong (posts with the same game name share one game), untick the ones to leave, then save. A game’s Roblox link, for its icon and players, can be added afterwards in Posts ▸ Roblox Games.', 'y4apk'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_roblox_sort">
				<?php wp_nonce_field('y4apk_roblox_sort'); ?>
				<datalist id="y4apk-roblox-names"><?php foreach ($names as $n) : ?><option value="<?php echo esc_attr($n); ?>"><?php endforeach; ?></datalist>
				<table class="wp-list-table widefat fixed striped" style="max-width:1000px">
					<thead><tr><td class="check-column"><input type="checkbox" checked onclick="document.querySelectorAll('.y4apk-rbx-row').forEach(c => c.checked = this.checked)"></td><th><?php esc_html_e('Post', 'y4apk'); ?></th><th style="width:40%"><?php esc_html_e('Game', 'y4apk'); ?></th></tr></thead>
					<tbody>
					<?php foreach ($posts as $p) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" class="y4apk-rbx-row" name="sort[]" value="<?php echo esc_attr($p->ID); ?>" checked></th>
							<td><a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
								<?php if ($app = (string) get_post_meta($p->ID, 'wp_title_GP', true)) : ?><br><span class="description"><?php echo esc_html($app); ?></span><?php endif; ?></td>
							<td><input type="text" class="widefat" name="game[<?php echo esc_attr($p->ID); ?>]" value="<?php echo esc_attr($guesses[$p->ID]); ?>" list="y4apk-roblox-names"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button class="button button-primary button-hero"><?php esc_html_e('Put these posts in their games', 'y4apk'); ?></button></p>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_sort() {
		check_admin_referer('y4apk_roblox_sort');
		if (!current_user_can('manage_categories')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$names = isset($_POST['game']) ? (array) wp_unslash($_POST['game']) : [];
		$sorted = 0;
		$games = [];
		foreach (isset($_POST['sort']) ? array_map('absint', (array) $_POST['sort']) : [] as $post_id) {
			$name = isset($names[$post_id]) ? sanitize_text_field($names[$post_id]) : '';
			if (!$post_id || $name === '' || !current_user_can('edit_post', $post_id)) {
				continue;
			}
			$term = self::find_or_create($name, false);
			if (is_wp_error($term)) {
				continue;
			}
			wp_set_object_terms($post_id, [$term], self::TAX);
			self::default_icon($term, $post_id);
			$games[$term] = 1;
			$sorted++;
		}
		wp_safe_redirect(add_query_arg(['page' => 'y4apk-roblox-sort', 'sorted' => $sorted, 'games' => count($games)], admin_url('edit.php')));
		exit;
	}
}

Y4APK_Roblox::init();
