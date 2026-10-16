<?php
/**
 * Y4APK self-hosted theme updater.
 *
 * Replaces the third party licensing/update service with an update source that
 * we own. Two sources are supported, checked in this order:
 *
 * 1. A JSON manifest (define Y4APK_UPDATE_MANIFEST or filter y4apk_update_manifest)
 *      {
 *          "version"      : "2.8.25",
 *          "download_url" : "https://y4apk.com/updates/y4apk-2.8.25.zip",
 *          "details_url"  : "https://y4apk.com/changelog/",
 *          "changelog"    : "<p>What changed…</p>",
 *          "requires"     : "5.4",
 *          "tested"       : "6.4.2",
 *          "requires_php" : "7.4"
 *      }
 *
 * 2. GitHub releases (define Y4APK_UPDATE_REPO as "owner/repo", or filter
 *    y4apk_update_repo). The newest release is used; a .zip release asset is
 *    preferred over the auto generated source archive.
 *
 * For a private repository define Y4APK_GITHUB_TOKEN in wp-config.php.
 *
 * No license key, no activation and no remote validation is involved.
 */

if (!defined('ABSPATH')) exit;

/**
 * Admin, cron and WP-CLI only: nothing here affects how the site renders, and
 * the site is the hot path.
 */
if (!is_admin() && !wp_doing_cron() && !defined('WP_CLI')) {
	return;
}

if (!class_exists('Y4APK_Updater')) {

class Y4APK_Updater {

	/** Transient holding the last resolved update payload. */
	const CACHE_KEY = 'y4apk_update_info';

	/** How long a lookup is cached before we ask the update source again. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Theme directory name, e.g. "modw" or "y4apk". */
	private $slug;

	/** Version currently installed. */
	private $version;

	private static $instance = null;

	public static function init() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$theme = wp_get_theme(get_template());

		$this->slug = get_template();
		$this->version = $theme->get('Version');

		add_filter('pre_set_site_transient_update_themes', [$this, 'inject_update']);
		add_filter('themes_api', [$this, 'themes_api'], 10, 3);
		add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
		add_filter('http_request_args', [$this, 'authorize_request'], 10, 2);

		add_action('admin_menu', [$this, 'register_page'], 999);
		add_action('admin_post_y4apk_check_update', [$this, 'handle_force_check']);
		add_action('upgrader_process_complete', [$this, 'flush_cache'], 10, 0);
	}

	/* ---------------------------------------------------------------- *
	 * Update source
	 * ---------------------------------------------------------------- */

	/** "owner/repo" for the GitHub release source, or an empty string. */
	private function get_repo() {
		$repo = defined('Y4APK_UPDATE_REPO') ? Y4APK_UPDATE_REPO : '';
		return trim((string) apply_filters('y4apk_update_repo', $repo), " \t/");
	}

	/** URL of the JSON manifest source, or an empty string. */
	private function get_manifest_url() {
		$url = defined('Y4APK_UPDATE_MANIFEST') ? Y4APK_UPDATE_MANIFEST : '';
		return trim((string) apply_filters('y4apk_update_manifest', $url));
	}

	private function get_token() {
		$token = defined('Y4APK_GITHUB_TOKEN') ? Y4APK_GITHUB_TOKEN : '';
		return trim((string) apply_filters('y4apk_github_token', $token));
	}

	/**
	 * Resolve the newest release, cached.
	 *
	 * @param bool $force Skip the cache and ask the update source again.
	 * @return array|null ['version','package','url','changelog','requires','tested','requires_php']
	 */
	public function get_remote_info($force = false) {
		if (!$force) {
			$cached = get_transient(self::CACHE_KEY);
			if (is_array($cached)) {
				return empty($cached['version']) ? null : $cached;
			}
		}

		$info = null;

		if ($this->get_manifest_url()) {
			$info = $this->fetch_manifest();
		}
		if (empty($info) && $this->get_repo()) {
			$info = $this->fetch_github_release();
		}

		// Cache negative lookups too, so a broken source does not slow the admin down.
		set_transient(self::CACHE_KEY, is_array($info) ? $info : [], self::CACHE_TTL);

		return $info;
	}

	private function request($url, $accept = 'application/json') {
		$args = [
			'timeout'     => 20,
			'redirection' => 5,
			'sslverify'   => true,
			'headers'     => [
				'Accept'     => $accept,
				'User-Agent' => 'Y4APK-Theme-Updater/' . $this->version,
			],
		];

		$token = $this->get_token();
		if ($token) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get($url, $args);

		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			return null;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		return is_array($body) ? $body : null;
	}

	private function fetch_manifest() {
		$data = $this->request($this->get_manifest_url());

		if (empty($data['version']) || empty($data['download_url'])) {
			return null;
		}

		return [
			'version'      => ltrim((string) $data['version'], 'vV'),
			'package'      => esc_url_raw($data['download_url']),
			'url'          => !empty($data['details_url']) ? esc_url_raw($data['details_url']) : '',
			'changelog'    => !empty($data['changelog']) ? wp_kses_post($data['changelog']) : '',
			'requires'     => !empty($data['requires']) ? $data['requires'] : '',
			'tested'       => !empty($data['tested']) ? $data['tested'] : '',
			'requires_php' => !empty($data['requires_php']) ? $data['requires_php'] : '',
		];
	}

	private function fetch_github_release() {
		$repo = $this->get_repo();
		$data = $this->request('https://api.github.com/repos/' . $repo . '/releases/latest');

		// A repository with only pre-releases has no "latest"; fall back to the list.
		if (empty($data['tag_name'])) {
			$list = $this->request('https://api.github.com/repos/' . $repo . '/releases?per_page=5');
			$data = null;
			if (is_array($list)) {
				foreach ($list as $release) {
					if (!empty($release['tag_name']) && empty($release['draft'])) {
						$data = $release;
						break;
					}
				}
			}
		}

		if (empty($data['tag_name'])) {
			return null;
		}

		// Prefer a packaged theme zip attached to the release; the GitHub source
		// archive has a versioned root folder that we rename on install instead.
		$package = '';
		if (!empty($data['assets']) && is_array($data['assets'])) {
			foreach ($data['assets'] as $asset) {
				if (!empty($asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}
		if (!$package && !empty($data['zipball_url'])) {
			$package = $data['zipball_url'];
		}
		if (!$package) {
			return null;
		}

		return [
			'version'      => ltrim((string) $data['tag_name'], 'vV'),
			'package'      => esc_url_raw($package),
			'url'          => !empty($data['html_url']) ? esc_url_raw($data['html_url']) : '',
			'changelog'    => !empty($data['body']) ? wp_kses_post(wpautop($data['body'])) : '',
			'requires'     => '',
			'tested'       => '',
			'requires_php' => '',
		];
	}

	/* ---------------------------------------------------------------- *
	 * WordPress update plumbing
	 * ---------------------------------------------------------------- */

	public function inject_update($transient) {
		if (empty($transient) || !is_object($transient)) {
			$transient = new stdClass();
		}
		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = [];
		}

		$info = $this->get_remote_info();

		if (empty($info['version'])) {
			return $transient;
		}

		if (version_compare($this->version, $info['version'], '<')) {
			$transient->response[$this->slug] = [
				'theme'        => $this->slug,
				'new_version'  => $info['version'],
				'url'          => $info['url'],
				'package'      => $info['package'],
				'requires'     => $info['requires'],
				'requires_php' => $info['requires_php'],
			];
		} else {
			unset($transient->response[$this->slug]);
			$transient->no_update[$this->slug] = [
				'theme'       => $this->slug,
				'new_version' => $this->version,
				'url'         => $info['url'],
				'package'     => '',
			];
		}

		return $transient;
	}

	public function themes_api($result, $action, $args) {
		if ('theme_information' !== $action || empty($args->slug) || $args->slug !== $this->slug) {
			return $result;
		}

		$info = $this->get_remote_info();
		if (empty($info['version'])) {
			return $result;
		}

		$theme = wp_get_theme($this->slug);

		$response = new stdClass();
		$response->slug         = $this->slug;
		$response->name         = $theme->get('Name');
		$response->version      = $info['version'];
		$response->author       = $theme->get('Author');
		$response->homepage     = $info['url'] ? $info['url'] : $theme->get('ThemeURI');
		$response->download_link = $info['package'];
		$response->sections     = ['changelog' => $info['changelog']];
		$response->requires     = $info['requires'];
		$response->requires_php = $info['requires_php'];
		$response->tested       = $info['tested'];

		return $response;
	}

	/**
	 * A GitHub source archive unpacks to "owner-repo-hash/". WordPress installs a
	 * theme into a folder named after the unpacked directory, so rename it to the
	 * current theme slug to update in place instead of creating a second copy.
	 */
	public function fix_source_dir($source, $remote_source, $upgrader, $args = []) {
		global $wp_filesystem;

		if (empty($args['theme']) || $args['theme'] !== $this->slug) {
			return $source;
		}
		if (!$wp_filesystem || basename($source) === $this->slug) {
			return $source;
		}

		$corrected = trailingslashit($remote_source) . $this->slug;

		if ($wp_filesystem->move($source, $corrected, true)) {
			return trailingslashit($corrected);
		}

		return new WP_Error(
			'y4apk_rename_failed',
			__('Could not rename the downloaded update folder.', 'y4apk')
		);
	}

	/** Attach the token to GitHub downloads so private release assets work. */
	public function authorize_request($args, $url) {
		$token = $this->get_token();

		if (!$token || strpos($url, 'github.com') === false) {
			return $args;
		}

		if (!isset($args['headers']) || !is_array($args['headers'])) {
			$args['headers'] = [];
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		// Release assets are served as JSON metadata unless octet-stream is asked for.
		if (strpos($url, 'api.github.com') !== false && strpos($url, '/releases/assets/') !== false) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	public function flush_cache() {
		delete_transient(self::CACHE_KEY);
		delete_site_transient('update_themes');
	}

	/* ---------------------------------------------------------------- *
	 * Appearance ▸ Y4APK Updates
	 * ---------------------------------------------------------------- */

	public function register_page() {
		add_theme_page(
			__('Y4APK Updates', 'y4apk'),
			__('Y4APK Updates', 'y4apk'),
			'edit_theme_options',
			'y4apk-updates',
			[$this, 'render_page']
		);
	}

	public function handle_force_check() {
		if (!current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-check-update');

		$this->flush_cache();
		$this->get_remote_info(true);

		wp_safe_redirect(admin_url('themes.php?page=y4apk-updates&checked=1'));
		exit;
	}

	public function render_page() {
		$info = $this->get_remote_info();
		$source = $this->get_manifest_url() ? $this->get_manifest_url() : ($this->get_repo() ? 'github.com/' . $this->get_repo() : '');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Y4APK Updates', 'y4apk'); ?></h1>

			<?php if (isset($_GET['checked'])) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Update information refreshed.', 'y4apk'); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr>
						<td style="width:220px"><strong><?php esc_html_e('Installed version', 'y4apk'); ?></strong></td>
						<td><?php echo esc_html($this->version); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e('Update source', 'y4apk'); ?></strong></td>
						<td>
							<?php if ($source) : ?>
								<code><?php echo esc_html($source); ?></code>
							<?php else : ?>
								<em><?php esc_html_e('Not configured yet.', 'y4apk'); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e('Latest version', 'y4apk'); ?></strong></td>
						<td>
							<?php if (!empty($info['version'])) : ?>
								<?php echo esc_html($info['version']); ?>
								<?php if (version_compare($this->version, $info['version'], '<')) : ?>
									&nbsp;<a href="<?php echo esc_url(admin_url('themes.php')); ?>" class="button button-primary"><?php esc_html_e('Install update', 'y4apk'); ?></a>
								<?php else : ?>
									&nbsp;<span class="dashicons dashicons-yes-alt" style="color:#46b450"></span> <?php esc_html_e('You are up to date.', 'y4apk'); ?>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e('No release information available.', 'y4apk'); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p>
				<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
					<input type="hidden" name="action" value="y4apk_check_update" />
					<?php wp_nonce_field('y4apk-check-update'); ?>
					<button type="submit" class="button"><?php esc_html_e('Check for updates now', 'y4apk'); ?></button>
				</form>
			</p>

			<?php if (!$source) : ?>
				<h2><?php esc_html_e('Set up your update source', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Add one of these to wp-config.php. GitHub releases:', 'y4apk'); ?></p>
				<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:760px;overflow:auto">define( 'Y4APK_UPDATE_REPO', 'your-github-user/your-repo' );
// Private repository only:
// define( 'Y4APK_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );</pre>
				<p><?php esc_html_e('Or a JSON file you host yourself:', 'y4apk'); ?></p>
				<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:760px;overflow:auto">define( 'Y4APK_UPDATE_MANIFEST', 'https://y4apk.com/updates/y4apk.json' );</pre>
			<?php endif; ?>

			<?php if (!empty($info['changelog'])) : ?>
				<h2><?php esc_html_e('Changelog', 'y4apk'); ?></h2>
				<div style="max-width:760px"><?php echo wp_kses_post($info['changelog']); ?></div>
			<?php endif; ?>

			<?php
			/** Other Y4APK maintenance tools render here (see inc/migrate.php). */
			do_action('y4apk_tools_page');
			?>
		</div>
		<?php
	}
}

}

Y4APK_Updater::init();
