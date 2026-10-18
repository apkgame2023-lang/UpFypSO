<?php
/**
 * Y4APK ▸ Write with AI ▸ Add from Google Play (2.28).
 *
 * Games and apps on Google Play that the site has no post for — found by a
 * search, a category or Google Play's popular lists — and, one click each, a
 * new post made like the others by Write with AI: the title, the article, the
 * MOD, the icon, the screenshots, the category and a download folder waiting
 * for its file (listed in App updates ▸ Need a new file). It is published once
 * its article is written, or kept as a draft.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Discover {

	const PUBLISH = '_y4apk_publish'; // publish once Write with AI has written its article
	const ADDED   = '_y4apk_added';   // when it was added from Google Play
	const MAX     = 60;               // apps one search shows
	const CACHE   = 12;               // hours an app's Google Play details are kept for the list

	public static function init() {
		if (!is_admin()) {
			return;
		}
		foreach (['find', 'info', 'add'] as $a) {
			add_action('wp_ajax_y4apk_discover_' . $a, [__CLASS__, 'ajax_' . $a]);
		}
	}

	/** Google Play's lists: [id => label], the popular ones first. */
	public static function lists() {
		return [
			__('Popular', 'y4apk') => ['games' => __('Popular games', 'y4apk'), 'apps' => __('Popular apps', 'y4apk')],
			__('Games', 'y4apk')   => [
				'GAME_ACTION' => 'Action', 'GAME_ADVENTURE' => 'Adventure', 'GAME_ARCADE' => 'Arcade', 'GAME_BOARD' => 'Board',
				'GAME_CARD' => 'Card', 'GAME_CASINO' => 'Casino', 'GAME_CASUAL' => 'Casual', 'GAME_EDUCATIONAL' => 'Educational',
				'GAME_MUSIC' => 'Music', 'GAME_PUZZLE' => 'Puzzle', 'GAME_RACING' => 'Racing', 'GAME_ROLE_PLAYING' => 'Role Playing',
				'GAME_SIMULATION' => 'Simulation', 'GAME_SPORTS' => 'Sports', 'GAME_STRATEGY' => 'Strategy', 'GAME_TRIVIA' => 'Trivia',
				'GAME_WORD' => 'Word',
			],
			__('Apps', 'y4apk')    => [
				'ART_AND_DESIGN' => 'Art & Design', 'AUTO_AND_VEHICLES' => 'Auto & Vehicles', 'BOOKS_AND_REFERENCE' => 'Books & Reference',
				'BUSINESS' => 'Business', 'COMMUNICATION' => 'Communication', 'EDUCATION' => 'Education', 'ENTERTAINMENT' => 'Entertainment',
				'FINANCE' => 'Finance', 'HEALTH_AND_FITNESS' => 'Health & Fitness', 'LIFESTYLE' => 'Lifestyle',
				'MAPS_AND_NAVIGATION' => 'Maps & Navigation', 'MUSIC_AND_AUDIO' => 'Music & Audio', 'NEWS_AND_MAGAZINES' => 'News & Magazines',
				'PERSONALIZATION' => 'Personalization', 'PHOTOGRAPHY' => 'Photography', 'PRODUCTIVITY' => 'Productivity',
				'SHOPPING' => 'Shopping', 'SOCIAL' => 'Social', 'SPORTS' => 'Sports', 'TOOLS' => 'Tools', 'TRAVEL_AND_LOCAL' => 'Travel & Local',
				'VIDEO_PLAYERS' => 'Video Players & Editors', 'WEATHER' => 'Weather',
			],
		];
	}

	/** What the site has: [[package => post ID], [App Name, normalized => post ID]] — any post but trashed ones. */
	public static function on_site() {
		static $have = null;
		if ($have !== null) {
			return $have;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ('wp_GP_ID', 'wp_title_GP') AND p.post_type = 'post' AND p.post_status NOT IN ('trash', 'auto-draft')"
		);
		$packages = [];
		$names = [];
		foreach ($rows as $r) {
			if ($r->meta_key === 'wp_GP_ID') {
				$package = Y4APK_App_Updates::package_from($r->meta_value);
				if ($package !== '') {
					$packages[strtolower($package)] = (int) $r->post_id;
				}
			} elseif (($n = Y4APK_App_Updates::norm($r->meta_value)) !== '') {
				$names[$n] = (int) $r->post_id;
			}
		}
		return $have = [$packages, $names];
	}

	/** The Google Play page a search or list is read from. */
	private static function page_url($q, $list) {
		if ($list === 'games' || $list === 'apps') {
			return 'https://play.google.com/store/' . $list . '?hl=en&gl=US';
		}
		if ($list !== '') {
			return 'https://play.google.com/store/apps/category/' . rawurlencode($list) . '?hl=en&gl=US';
		}
		return 'https://play.google.com/store/search?q=' . rawurlencode($q) . '&c=apps&hl=en&gl=US';
	}

	/** ['new' => [packages the site has no post for], 'have' => [[package, post ID, title], …]], or a WP_Error. */
	public static function find($q, $list) {
		$q = trim(wp_strip_all_tags((string) $q));
		$all = self::lists();
		$known = array_merge(...array_values(array_map('array_keys', $all)));
		$list = in_array($list, $known, true) ? $list : '';
		$one = Y4APK_App_Updates::package_from($q);
		if ($list === '' && $one !== '') {
			$found = [$one]; // a Google Play address or package: that app
		} elseif ($list === '' && $q === '') {
			return new WP_Error('empty', __('Type a name or words to search Google Play for, or pick a list.', 'y4apk'));
		} else {
			$r = Y4APK_App_Updates::play_page(self::page_url($q, $list));
			if ($r === null || $r[0] === 429 || $r[0] >= 500) {
				return new WP_Error('busy', __('Google Play is busy; try again in a minute.', 'y4apk'));
			}
			preg_match_all('~/store/apps/details\?id=([A-Za-z][\w.]*)~', (string) $r[1], $m);
			$found = array_slice(array_values(array_unique(array_map(function ($p) { return rtrim($p, '.'); }, $m[1]))), 0, self::MAX);
		}
		list($packages) = self::on_site();
		$out = ['new' => [], 'have' => []];
		foreach ($found as $package) {
			if (isset($packages[strtolower($package)])) {
				$id = $packages[strtolower($package)];
				$out['have'][] = [$package, $id, get_the_title($id), get_edit_post_link($id, 'raw')];
			} else {
				$out['new'][] = $package;
			}
		}
		return $out;
	}

	/** What the list shows of an app, from Google Play (kept 12 hours): an array, ['missing' => true], or null when Google Play is busy. */
	public static function info($package) {
		$key = 'y4apk_disc_' . md5($package);
		$app = get_transient($key);
		if (!is_array($app)) {
			$app = Y4APK_App_Updates::play_app($package);
			if ($app['status'] === 'busy') {
				return null;
			}
			set_transient($key, $app, self::CACHE * HOUR_IN_SECONDS);
		}
		if ($app['status'] !== 'ok') {
			return ['package' => $package, 'missing' => true];
		}
		list(, $names) = self::on_site();
		$n = Y4APK_App_Updates::norm($app['title']);
		$maybe = $n !== '' && isset($names[$n]) ? $names[$n] : 0; // a post with this App Name but no (or another) Google Play ID
		return [
			'package'   => $package,
			'title'     => $app['title'],
			'developer' => $app['developer'],
			'icon'      => $app['icon'] !== '' ? $app['icon'] . '=s128-rw' : '',
			'genre'     => $app['genre'],
			'game'      => !empty($app['game']),
			'rating'    => $app['rating'],
			'votes'     => $app['votes'],
			'version'   => $app['version'],
			'url'       => 'https://play.google.com/store/apps/details?id=' . rawurlencode($package),
			'maybe'     => $maybe ? ['title' => get_the_title($maybe), 'edit' => get_edit_post_link($maybe, 'raw')] : null,
		];
	}

	/**
	 * A new post for an app on Google Play — a draft with its name, Google
	 * Play ID and developer, which Write with AI then writes (and publishes,
	 * with $publish). Its ID, or a WP_Error.
	 */
	public static function add($package, $publish) {
		$package = Y4APK_App_Updates::package_from($package);
		if ($package === '') {
			return new WP_Error('package', __('That is not a Google Play app.', 'y4apk'));
		}
		list($packages) = self::on_site();
		if (isset($packages[strtolower($package)])) {
			return new WP_Error('have', sprintf(__('Already on your site: %s', 'y4apk'), get_the_title($packages[strtolower($package)])));
		}
		delete_transient('y4apk_disc_' . md5($package)); // its latest details for the post
		$app = self::info($package);
		if ($app === null) {
			return new WP_Error('busy', __('Google Play is busy; try again in a minute.', 'y4apk'));
		}
		if (!empty($app['missing'])) {
			return new WP_Error('missing', __('Google Play does not show this app.', 'y4apk'));
		}
		$id = wp_insert_post(wp_slash([
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $app['title'],
			'post_content' => '',
			'post_author'  => get_current_user_id(),
		]), true);
		if (is_wp_error($id)) {
			return $id;
		}
		$meta = [
			'wp_title_GP'      => $app['title'],
			'wp_GP_ID'         => $package,
			'wp_developers_GP' => $app['developer'],
			'wp_version_GP'    => $app['version'],
			'platform'         => 'ANDROID',
			'price'            => '0',
			self::ADDED        => time(),
		];
		if ($publish) {
			$meta[self::PUBLISH] = 1;
		}
		foreach ($meta as $k => $v) {
			if ($v !== '') {
				update_post_meta($id, $k, wp_slash($v));
			}
		}
		return $id;
	}

	/* ---------------------------------------------------------------- *
	 * AJAX (the page: assets/js/y4apk-discover.js)
	 * ---------------------------------------------------------------- */

	private static function check() {
		check_ajax_referer('y4apk-writer', 'nonce');
		if (!current_user_can('publish_posts')) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
	}

	public static function ajax_find() {
		self::check();
		$r = self::find(isset($_POST['q']) ? wp_unslash($_POST['q']) : '', isset($_POST['list']) ? sanitize_text_field(wp_unslash($_POST['list'])) : '');
		is_wp_error($r) ? wp_send_json_error(['message' => $r->get_error_message()]) : wp_send_json_success($r);
	}

	public static function ajax_info() {
		self::check();
		$package = Y4APK_App_Updates::package_from(isset($_POST['package']) ? wp_unslash($_POST['package']) : '');
		$r = $package !== '' ? self::info($package) : ['missing' => true];
		$r === null ? wp_send_json_error(['message' => __('Google Play is busy.', 'y4apk'), 'busy' => true]) : wp_send_json_success($r);
	}

	public static function ajax_add() {
		self::check();
		$publish = !empty($_POST['publish']);
		update_user_meta(get_current_user_id(), 'y4apk_discover_publish', $publish ? 1 : 0);
		$id = self::add(isset($_POST['package']) ? wp_unslash($_POST['package']) : '', $publish);
		if (is_wp_error($id)) {
			wp_send_json_error(['message' => $id->get_error_message()]);
		}
		wp_send_json_success(['id' => $id, 'edit' => get_edit_post_link($id, 'raw')]);
	}

	/* ---------------------------------------------------------------- *
	 * The tab
	 * ---------------------------------------------------------------- */

	public static function script_text() {
		return [
			'text' => [
				'searching' => __('Looking on Google Play…', 'y4apk'),
				'found'     => __('%1$s not on your site yet · %2$s already are', 'y4apk'),
				'none'      => __('Every app here is already on your site.', 'y4apk'),
				'have'      => __('Already on your site', 'y4apk'),
				'maybe'     => __('A post with this name: %s', 'y4apk'),
				'missing'   => __('Not on Google Play', 'y4apk'),
				'add'       => __('Add', 'y4apk'),
				'adding'    => __('Making the post…', 'y4apk'),
				'writing'   => __('Writing it with AI…', 'y4apk'),
				'added'     => __('Added', 'y4apk'),
				'queued'    => __('Made as a draft; the AI is busy, so it is first in the queue and is written (and published) there.', 'y4apk'),
				'noai'      => __('Made as a draft with its icon, screenshots and details; the article needs the AI (Settings).', 'y4apk'),
				'progress'  => __('Adding %1$s of %2$s: %3$s', 'y4apk'),
				'done'      => __('Done: %1$s added.', 'y4apk'),
				'pick'      => __('Tick some apps first.', 'y4apk'),
				'edit'      => __('Edit', 'y4apk'),
				'view'      => __('View', 'y4apk'),
			],
		];
	}

	public static function render() {
		$publish = get_user_meta(get_current_user_id(), 'y4apk_discover_publish', true);
		$publish = $publish === '' ? true : (bool) $publish;
		$added = (int) (new WP_Query(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => self::ADDED, 'fields' => 'ids', 'posts_per_page' => 1]))->found_posts;
		?>
		<div class="au-card au-pad ad-find">
			<form id="ad-form" class="ad-form">
				<input type="search" id="ad-q" placeholder="<?php esc_attr_e('A game or app: name, words, or its Google Play address', 'y4apk'); ?>" autocomplete="off">
				<span class="au-meta"><?php esc_html_e('or', 'y4apk'); ?></span>
				<select id="ad-list">
					<option value=""><?php esc_html_e('— a Google Play list —', 'y4apk'); ?></option>
					<?php foreach (self::lists() as $group => $items) : ?>
						<optgroup label="<?php echo esc_attr($group); ?>">
							<?php foreach ($items as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary"><?php esc_html_e('Find on Google Play', 'y4apk'); ?></button>
			</form>
			<p class="description"><?php echo esc_html(sprintf(__('Shows what your site has no post for yet (%s added from here so far). “Add” makes the post the way Write with AI writes them — title, article, MOD, icon, screenshots, category from Google Play, and a download folder waiting for its file, listed in App updates ▸ Need a new file.', 'y4apk'), number_format_i18n($added))); ?></p>
			<div class="ad-bar" hidden>
				<label><input type="checkbox" id="ad-all"> <?php esc_html_e('Select all', 'y4apk'); ?></label>
				<label><input type="checkbox" id="ad-publish" <?php checked($publish); ?>> <?php esc_html_e('Publish each once its article is written (else: a draft)', 'y4apk'); ?></label>
				<button type="button" class="button button-primary" id="ad-add-selected"><?php esc_html_e('Add selected', 'y4apk'); ?></button>
				<span class="ad-status au-meta"></span>
			</div>
		</div>
		<p class="ad-summary au-meta"></p>
		<div class="ad-grid" id="ad-results"></div>
		<style>
			.ad-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
			.ad-form input[type=search] { flex: 1 1 320px; min-height: 36px; }
			.ad-form select { max-width: 260px; }
			.ad-bar { display: flex; gap: 18px; flex-wrap: wrap; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px solid #eef0f2; }
			.ad-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; margin-top: 12px; }
			.ad-card { display: grid; grid-template-columns: 22px 56px 1fr; gap: 12px; align-items: start; padding: 14px; background: #fff; border: 1px solid #e4e6e8; border-radius: 12px; }
			.ad-card.is-good { border-color: #b8e6bf; }
			.ad-card.is-bad { border-color: #f4c6c7; }
			.ad-card img, .ad-card .ad-ph { width: 56px; height: 56px; border-radius: 14px; background: #f0f2f4; object-fit: cover; }
			.ad-card h3 { margin: 0 0 2px; font-size: 14px; line-height: 1.35; }
			.ad-card h3 a { color: inherit; text-decoration: none; }
			.ad-card .ad-meta { color: #646970; font-size: 12.5px; line-height: 1.5; }
			.ad-card .ad-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
			.ad-card .ad-msg { font-size: 12.5px; }
			.ad-card .ad-msg.is-bad { color: #b32d2e; }
			.ad-card .ad-msg.is-good { color: #1a7f37; }
			.ad-card .ad-maybe { color: #996800; font-size: 12.5px; }
			.ad-have { margin-top: 18px; }
			.ad-have summary { cursor: pointer; color: #646970; }
			.ad-have li { margin: 4px 0; }
		</style>
		<?php
	}
}

Y4APK_Discover::init();
