<?php
/**
 * Upload new files to Cloudflare R2 from Y4APK ▸ App updates, and point the
 * post's download folder at them.
 *
 * The site's download links are folders on this server —
 * https://files.y4apk.com/up/79275-No-Limit-Drag-Racing-2-MOD.apk is
 * public_html/files/up/79275-No-Limit-Drag-Racing-2-MOD.apk/default.php, a
 * "Preparing your download" page that sends the visitor to the file on R2
 * (https://files.y4apk.net/A/…apk). So a new version is: put the file on R2,
 * then change the R2 address in that page.
 *
 * Uploads go from the browser straight to R2 (S3 multipart upload, parts of
 * 16 MB or more, four at a time, through links this server signs with AWS
 * Signature V4), so 2–3 GB files never pass through this server and its upload
 * limits. On Save (Y4APK_App_Updates::handle_save), "Replace" rewrites the R2
 * address in the post's own folder and keeps its link; "Add" — or a link that is
 * not one of these folders — makes a new folder, numbered after the last one,
 * from the design of the existing pages.
 *
 * R2 needs an API token (Object Read & Write) and a CORS rule letting this site
 * PUT and read the ETag header; the settings card shows both.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_R2 {

	const OPTION    = 'y4apk_r2';
	const MIN_PART  = 16777216; // 16 MiB; R2 wants every part but the last the same size
	const MAX_PARTS = 9000;     // S3 allows 10,000
	const TYPES     = ['apk', 'xapk', 'apks', 'apkm', 'zip', 'obb'];

	public static function init() {
		if (!is_admin()) {
			return;
		}
		foreach (['start', 'parts', 'complete', 'abort', 'test', 'test_clean'] as $a) {
			add_action('wp_ajax_y4apk_r2_' . $a, [__CLASS__, 'ajax_' . $a]);
		}
		add_action('admin_post_y4apk_r2_settings', [__CLASS__, 'handle_settings']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], [
			'account' => '',
			'bucket'  => 'files',
			'key_id'  => '',
			'secret'  => '',
			'public'  => 'https://files.y4apk.net',
			'dir'     => ABSPATH . 'files/up',
			'dir_url' => 'https://files.y4apk.com/up/',
		]);
		// Keys may also live in wp-config.php, out of the database.
		if (defined('Y4APK_R2_KEY_ID') && defined('Y4APK_R2_SECRET')) {
			$o['key_id'] = Y4APK_R2_KEY_ID;
			$o['secret'] = Y4APK_R2_SECRET;
		}
		$o['dir'] = untrailingslashit($o['dir']);
		$o['dir_url'] = trailingslashit($o['dir_url']);
		$o['public'] = untrailingslashit($o['public']);
		return $o;
	}

	public static function ready() {
		$o = self::settings();
		return $o['account'] !== '' && $o['bucket'] !== '' && $o['key_id'] !== '' && $o['secret'] !== '' && $o['public'] !== '' && is_dir($o['dir']);
	}

	private static function endpoint() {
		$o = self::settings();
		return untrailingslashit(apply_filters('y4apk_r2_endpoint', 'https://' . $o['account'] . '.r2.cloudflarestorage.com'));
	}

	/* ---------------------------------------------------------------- *
	 * Signing (AWS Signature V4, query string, region "auto")
	 * ---------------------------------------------------------------- */

	/** "A/Name v1.apk" → "A/Name%20v1.apk": each part encoded, the slashes kept. */
	public static function encode_key($key) {
		return implode('/', array_map('rawurlencode', explode('/', $key)));
	}

	/** A link that lets whoever has it make this one request for $expires seconds. */
	public static function presign($method, $key, $query = [], $expires = 3600, $time = null) {
		$o = self::settings();
		$endpoint = self::endpoint();
		$host = wp_parse_url($endpoint, PHP_URL_HOST) . (wp_parse_url($endpoint, PHP_URL_PORT) ? ':' . wp_parse_url($endpoint, PHP_URL_PORT) : '');
		$path = '/' . $o['bucket'] . ($key !== '' ? '/' . self::encode_key($key) : '');
		$time = $time ?: time();
		$amz = gmdate('Ymd\THis\Z', $time);
		$day = gmdate('Ymd', $time);
		$scope = $day . '/auto/s3/aws4_request';
		$query = array_merge(array_map('strval', $query), [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $o['key_id'] . '/' . $scope,
			'X-Amz-Date'          => $amz,
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		]);
		ksort($query, SORT_STRING);
		$pairs = [];
		foreach ($query as $k => $v) {
			$pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
		}
		$qs = implode('&', $pairs);
		$canonical = $method . "\n" . $path . "\n" . $qs . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";
		$to_sign = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash('sha256', $canonical);
		$k = hash_hmac('sha256', $day, 'AWS4' . $o['secret'], true);
		foreach (['auto', 's3', 'aws4_request'] as $part) {
			$k = hash_hmac('sha256', $part, $k, true);
		}
		return $endpoint . $path . '?' . $qs . '&X-Amz-Signature=' . hash_hmac('sha256', $to_sign, $k);
	}

	/** One signed request from this server: [status, body, headers] or WP_Error. */
	private static function call($method, $key, $query = [], $body = null, $headers = []) {
		$r = wp_remote_request(self::presign($method, $key, $query, 600), [
			'method'  => $method,
			'body'    => $body,
			'headers' => $headers,
			'timeout' => 30,
		]);
		if (is_wp_error($r)) {
			return $r;
		}
		return [(int) wp_remote_retrieve_response_code($r), (string) wp_remote_retrieve_body($r), wp_remote_retrieve_headers($r)];
	}

	private static function r2_error($r, $what) {
		if (is_wp_error($r)) {
			return $r;
		}
		$msg = preg_match('~<Message>(.*?)</Message>~s', $r[1], $m) ? html_entity_decode($m[1]) : 'HTTP ' . $r[0];
		return new WP_Error('y4apk_r2', sprintf(__('%1$s: R2 answered “%2$s”.', 'y4apk'), $what, $msg));
	}

	/* ---------------------------------------------------------------- *
	 * Names
	 * ---------------------------------------------------------------- */

	/** "No Limit Drag Racing 2" → "No-Limit-Drag-Racing-2". */
	public static function slug($text) {
		return trim(preg_replace('/[^A-Za-z0-9]+/', '-', remove_accents((string) $text)), '-');
	}

	public static function app_name($post_id) {
		$name = trim((string) get_post_meta($post_id, 'wp_title_GP', true));
		return self::slug($name !== '' ? $name : Y4APK_App_Updates::name_from_title(get_post_field('post_title', $post_id))) ?: 'App';
	}

	public static function ext($filename) {
		$ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
		return in_array($ext, self::TYPES, true) ? $ext : 'apk';
	}

	/** Where a new file goes on R2, as the bucket names them: "N/No-Limit-Drag-Racing-2-MOD-v2.27.57-Y4APK.COM-IDv123456.apk". */
	public static function object_key($post_id, $type, $version, $filename) {
		$name = self::app_name($post_id);
		$letter = ctype_alpha($name[0]) ? strtoupper($name[0]) : 'A';
		$n = Y4APK_App_Updates::number($version);
		$parts = array_filter([$name, self::slug(strtoupper($type)) ?: 'MOD', $n !== '' ? 'v' . $n : '', 'Y4APK.COM', 'IDv' . wp_rand(100000, 999999)], 'strlen');
		return $letter . '/' . implode('-', $parts) . '.' . self::ext($filename);
	}

	public static function public_url($key) {
		return self::settings()['public'] . '/' . self::encode_key($key);
	}

	/** "2.13 GB" / "682.26 MB", as the posts write sizes. */
	public static function size_text($bytes) {
		$bytes = (float) $bytes;
		return $bytes >= 1073741824 ? number_format($bytes / 1073741824, 2) . ' GB' : number_format($bytes / 1048576, 2) . ' MB';
	}

	/* ---------------------------------------------------------------- *
	 * Upload steps (admin-ajax, from assets/js/y4apk-r2-upload.js)
	 * ---------------------------------------------------------------- */

	private static function guard($post_id = 0) {
		check_ajax_referer('y4apk-r2', 'nonce');
		if (!current_user_can('upload_files') || ($post_id && !current_user_can('edit_post', $post_id))) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
		if (!self::ready()) {
			wp_send_json_error(['message' => __('Cloudflare R2 is not set up yet (App updates ▸ Settings).', 'y4apk')], 400);
		}
	}

	private static function upload($id) {
		$u = get_transient('y4apk_r2_up_' . md5((string) $id));
		if (!is_array($u) || (int) $u['user'] !== get_current_user_id()) {
			wp_send_json_error(['message' => __('That upload is not known here (expired?). Start it again.', 'y4apk')], 400);
		}
		return $u;
	}

	private static function part_urls($u, $numbers) {
		$urls = [];
		foreach (array_slice(array_map('absint', (array) $numbers), 0, 100) as $n) {
			if ($n >= 1 && $n <= $u['count']) {
				$urls[$n] = self::presign('PUT', $u['key'], ['partNumber' => $n, 'uploadId' => $u['id']], 6 * HOUR_IN_SECONDS);
			}
		}
		return $urls;
	}

	public static function ajax_start() {
		$post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		self::guard($post_id);
		$size = isset($_POST['size']) ? (float) $_POST['size'] : 0;
		$name = sanitize_file_name(wp_unslash(isset($_POST['name']) ? $_POST['name'] : ''));
		if (!$post_id || $size <= 0 || $size > 50 * 1073741824) {
			wp_send_json_error(['message' => __('Choose the file to upload.', 'y4apk')], 400);
		}
		$type = sanitize_text_field(wp_unslash(isset($_POST['type']) ? $_POST['type'] : 'MOD'));
		$version = sanitize_text_field(wp_unslash(isset($_POST['version']) ? $_POST['version'] : ''));
		$key = self::object_key($post_id, $type, $version, $name);
		$r = self::call('POST', $key, ['uploads' => ''], '', ['Content-Type' => 'application/octet-stream']);
		if (is_wp_error($r) || $r[0] !== 200 || !preg_match('~<UploadId>(.*?)</UploadId>~s', $r[1], $m)) {
			wp_send_json_error(['message' => self::r2_error($r, __('Starting the upload', 'y4apk'))->get_error_message()], 502);
		}
		$part = max(self::MIN_PART, (int) ceil($size / self::MAX_PARTS / 1048576) * 1048576);
		$u = ['id' => html_entity_decode($m[1]), 'key' => $key, 'size' => $size, 'part' => $part, 'count' => (int) ceil($size / $part),
			'user' => get_current_user_id(), 'post' => $post_id];
		set_transient('y4apk_r2_up_' . md5($u['id']), $u, 2 * DAY_IN_SECONDS);
		wp_send_json_success(['uploadId' => $u['id'], 'key' => $key, 'partSize' => $part, 'count' => $u['count'],
			'urls' => self::part_urls($u, range(1, min(50, $u['count'])))]);
	}

	public static function ajax_parts() {
		self::guard();
		$u = self::upload(wp_unslash(isset($_POST['uploadId']) ? $_POST['uploadId'] : ''));
		wp_send_json_success(['urls' => self::part_urls($u, isset($_POST['numbers']) ? wp_unslash($_POST['numbers']) : [])]);
	}

	public static function ajax_complete() {
		self::guard();
		$u = self::upload(wp_unslash(isset($_POST['uploadId']) ? $_POST['uploadId'] : ''));
		$etags = isset($_POST['etags']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['etags'])) : [];
		if (count($etags) !== $u['count']) {
			wp_send_json_error(['message' => __('Some parts of the file are missing: upload it again.', 'y4apk')], 400);
		}
		$xml = '<CompleteMultipartUpload>';
		for ($n = 1; $n <= $u['count']; $n++) {
			if (!isset($etags[$n]) || $etags[$n] === '') {
				wp_send_json_error(['message' => __('Some parts of the file are missing: upload it again.', 'y4apk')], 400);
			}
			$etag = $etags[$n];
			$xml .= '<Part><PartNumber>' . $n . '</PartNumber><ETag>' . esc_html('"' . trim($etag, '"') . '"') . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		$r = self::call('POST', $u['key'], ['uploadId' => $u['id']], $xml, ['Content-Type' => 'application/xml']);
		if (is_wp_error($r) || $r[0] !== 200 || strpos($r[1], '<Error>') !== false) {
			wp_send_json_error(['message' => self::r2_error($r, __('Finishing the upload', 'y4apk'))->get_error_message()], 502);
		}
		// The file as R2 has it now.
		$h = self::call('HEAD', $u['key']);
		$size = !is_wp_error($h) && $h[0] === 200 && isset($h[2]['content-length']) ? (float) $h[2]['content-length'] : 0;
		if ($size !== (float) $u['size']) {
			wp_send_json_error(['message' => sprintf(__('R2 has %1$s bytes of this file, not %2$s: upload it again.', 'y4apk'), number_format($size), number_format($u['size']))], 502);
		}
		delete_transient('y4apk_r2_up_' . md5($u['id']));
		// Save trusts only files finished here.
		set_transient('y4apk_r2_done_' . md5($u['key']), ['key' => $u['key'], 'size' => $size, 'post' => $u['post']], 3 * DAY_IN_SECONDS);
		wp_send_json_success(['key' => $u['key'], 'url' => self::public_url($u['key']), 'size' => self::size_text($size)]);
	}

	public static function ajax_abort() {
		self::guard();
		$u = self::upload(wp_unslash(isset($_POST['uploadId']) ? $_POST['uploadId'] : ''));
		self::call('DELETE', $u['key'], ['uploadId' => $u['id']]);
		delete_transient('y4apk_r2_up_' . md5($u['id']));
		wp_send_json_success();
	}

	/** Settings ▸ Test: the keys (this server lists the bucket), then CORS (the browser PUTs a small file). */
	public static function ajax_test() {
		check_ajax_referer('y4apk-r2', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
		$o = self::settings();
		if ($o['account'] === '' || $o['key_id'] === '' || $o['secret'] === '') {
			wp_send_json_error(['message' => __('Fill in the Account ID and the API token’s keys first.', 'y4apk')]);
		}
		$r = self::call('GET', '', ['list-type' => '2', 'max-keys' => '1']);
		if (is_wp_error($r) || $r[0] !== 200) {
			wp_send_json_error(['message' => self::r2_error($r, __('Reading the bucket', 'y4apk'))->get_error_message()]);
		}
		$folders = is_dir($o['dir']) ? count(glob($o['dir'] . '/*', GLOB_ONLYDIR | GLOB_NOSORT)) : -1;
		wp_send_json_success(['put' => self::presign('PUT', '_y4apk/cors-test.txt', [], 600), 'folders' => $folders, 'writable' => is_dir($o['dir']) && wp_is_writable($o['dir'])]);
	}

	public static function ajax_test_clean() {
		check_ajax_referer('y4apk-r2', 'nonce');
		if (current_user_can('manage_options')) {
			self::call('DELETE', '_y4apk/cors-test.txt');
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------- *
	 * Download folders (files/up/<name>/default.php)
	 * ---------------------------------------------------------------- */

	/** The folder of one of our download links, or '' when the link is not one. */
	public static function folder_of($url) {
		$o = self::settings();
		$url = (string) $url;
		if (strpos($url, $o['dir_url']) !== 0) {
			return '';
		}
		$name = rawurldecode(trim(substr($url, strlen($o['dir_url'])), '/'));
		if ($name === '' || strpbrk($name, "/\\\0") !== false || $name === '.' || $name === '..') {
			return '';
		}
		return is_dir($o['dir'] . '/' . $name) ? $name : '';
	}

	/** A free folder for a new download: "79276-No-Limit-Drag-Racing-2-MOD.apk", numbered after the highest one. */
	public static function new_folder($post_id, $type, $ext) {
		$o = self::settings();
		$next = (int) get_option('y4apk_r2_next', 0);
		if (!$next) {
			foreach (scandir($o['dir']) ?: [] as $d) {
				if (preg_match('/^(\d+)-/', $d, $m)) {
					$next = max($next, (int) $m[1]);
				}
			}
			$next++;
		}
		$base = self::app_name($post_id) . '-' . (self::slug(strtoupper($type)) ?: 'MOD') . '.' . $ext;
		while (file_exists($o['dir'] . '/' . $next . '-' . $base)) {
			$next++;
		}
		update_option('y4apk_r2_next', $next + 1, false);
		return $next . '-' . $base;
	}

	/** The design of the existing pages (any default.php with both places to fill), else a plain one. */
	private static function template() {
		$o = self::settings();
		foreach (array_slice(glob($o['dir'] . '/*/default.php', GLOB_NOSORT) ?: [], 0, 40) as $f) {
			$html = (string) file_get_contents($f);
			if (preg_match('~<a class=d href="[^"]*"~', $html) && preg_match('~location\.href="[^"]*",0\)~', $html)) {
				return $html;
			}
		}
		return '<!DOCTYPE html><html><head><meta name=viewport content="width=device-width,initial-scale=1"><title>Preparing the file</title>'
			. '<style>body{margin:0;height:100vh;display:flex;justify-content:center;align-items:center;font-family:Arial;background:#fbfdfd}.c{text-align:center}.d{color:#ff2a2a}</style></head>'
			. '<body><div class=c><p>Preparing your download...</p><a class=d href="#">Manual download</a></div>'
			. '<script>setTimeout(()=>location.href="#",0);</script></body></html>';
	}

	/**
	 * Put a file address into a download page. The pages come in a few designs
	 * (most: "<a class=d href=…>Manual download" plus location.href=…,0; one
	 * kind sets $apk="…" in PHP), so: every R2 file address already in the page
	 * becomes the new one, and the spots these pages keep for it are filled
	 * even while they still say "#" or point elsewhere. The 3-second fallback
	 * to another page is left alone. Returns the number of places changed.
	 */
	public static function fill_page($html, $file_url) {
		$set = function ($m) use ($file_url) {
			return $m[1] . $file_url . $m[2];
		};
		$host = preg_quote(wp_parse_url(self::settings()['public'], PHP_URL_HOST), '~');
		$html = preg_replace_callback('~(https?://' . $host . '/)[^"\'\s<>)]+?\.(?:' . implode('|', self::TYPES) . ')(?=["\'\s<>)])~i', function () use ($file_url) {
			return $file_url;
		}, $html, -1, $a);
		$html = preg_replace_callback('~(<a class=d href=")[^"]*(")~', $set, $html, 1, $b);
		$html = preg_replace_callback('~(location\.href=")[^"]*(",0\))~', $set, $html, 1, $c);
		$html = preg_replace_callback('~(\$apk\s*=\s*")[^"]*(")~', $set, $html, 1, $d);
		return [$html, $a + $b + $c + $d];
	}

	/** Point a folder's download page at a file (a new folder gets the design of the existing pages). */
	public static function point_folder($folder, $file_url) {
		$o = self::settings();
		if (!preg_match('#^https?://[A-Za-z0-9.:\-]+/[A-Za-z0-9._~%/\-]+$#', $file_url)) {
			return new WP_Error('y4apk_r2_url', __('That file address cannot go into a download page.', 'y4apk'));
		}
		$dir = $o['dir'] . '/' . $folder;
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			return new WP_Error('y4apk_r2_dir', sprintf(__('Could not make the folder %s.', 'y4apk'), $folder));
		}
		$file = $dir . '/default.php';
		$old = is_file($file) ? (string) file_get_contents($file) : '';
		list($html, $hits) = $old !== '' ? self::fill_page($old, $file_url) : ['', 0];
		if (!$hits) {
			if ($old !== '') {
				copy($file, $file . '.bak'); // a design with no place for the address: keep it
			}
			list($html) = self::fill_page(self::template(), $file_url);
		}
		if (file_put_contents($file, $html, LOCK_EX) === false) {
			return new WP_Error('y4apk_r2_write', sprintf(__('Could not write %s.', 'y4apk'), $folder . '/default.php'));
		}
		return true;
	}

	/**
	 * For App updates ▸ Save: a finished upload for one download row.
	 * Returns ['url' => the post's link, 'size' => "2.13 GB"] or WP_Error. With
	 * $replace and the row's link one of our folders, that folder now points at
	 * the new file; otherwise a new folder does.
	 */
	public static function attach($post_id, $key, $current_url, $type, $replace) {
		$done = get_transient('y4apk_r2_done_' . md5((string) $key));
		if (!is_array($done) || (int) $done['post'] !== (int) $post_id) {
			return new WP_Error('y4apk_r2_unknown', __('That upload did not finish here: upload the file again.', 'y4apk'));
		}
		$o = self::settings();
		$folder = $replace ? self::folder_of($current_url) : '';
		$fresh = $folder === '';
		if ($fresh) {
			$folder = self::new_folder($post_id, $type, self::ext($key));
		}
		$ok = self::point_folder($folder, self::public_url($key));
		if (is_wp_error($ok)) {
			return $ok;
		}
		delete_transient('y4apk_r2_done_' . md5((string) $key));
		return ['url' => $fresh ? $o['dir_url'] . $folder : $current_url, 'size' => self::size_text($done['size']), 'folder' => $folder];
	}

	/* ---------------------------------------------------------------- *
	 * Admin
	 * ---------------------------------------------------------------- */

	public static function enqueue($hook) {
		if (strpos((string) $hook, Y4APK_App_Updates::SLUG) === false) {
			return;
		}
		wp_enqueue_script('y4apk-r2-upload', get_template_directory_uri() . '/assets/js/y4apk-r2-upload.js', [], Y4APK_THEME_VERSION, true);
		wp_localize_script('y4apk-r2-upload', 'y4apkR2', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('y4apk-r2'),
			'text'  => [
				'start'     => __('Starting…', 'y4apk'),
				'progress'  => __('%1$s%% · %2$s of %3$s · %4$s/s', 'y4apk'),
				'finishing' => __('Finishing…', 'y4apk'),
				'done'      => __('Uploaded ✓ %s — it becomes the download when you save.', 'y4apk'),
				'cancel'    => __('Cancel', 'y4apk'),
				'cancelled' => __('Upload cancelled.', 'y4apk'),
				'failed'    => __('Upload failed: %s', 'y4apk'),
				'retry'     => __('Try again', 'y4apk'),
				'leave'     => __('A file is still uploading. Leave anyway?', 'y4apk'),
				'cors'      => __('The browser could not send to R2: add the CORS rule shown in Settings.', 'y4apk'),
			],
		]);
	}

	/** The Upload button and its status line, for one download row of the form. */
	public static function upload_control($name, $row, $disabled = false) {
		?>
		<span class="au-up" data-row="<?php echo esc_attr($row); ?>">
			<input type="hidden" class="au-up-key" name="<?php echo esc_attr($name); ?>" value="">
			<label class="button au-up-btn<?php echo $disabled ? ' disabled' : ''; ?>"><input type="file" accept=".apk,.xapk,.apks,.apkm,.zip,.obb" hidden <?php disabled($disabled); ?>>⬆ <?php esc_html_e('Upload', 'y4apk'); ?></label>
		</span>
		<span class="au-up-status" hidden></span>
		<?php
	}

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-r2-settings');
		$in = wp_unslash($_POST);
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		foreach (['account', 'bucket', 'key_id'] as $k) {
			$o[$k] = preg_replace('/[^A-Za-z0-9._\-]/', '', isset($in[$k]) ? (string) $in[$k] : '');
		}
		if (!empty($in['secret'])) { // left empty: keep the saved one
			$o['secret'] = trim((string) $in['secret']);
		}
		foreach (['public', 'dir_url'] as $k) {
			$o[$k] = esc_url_raw(isset($in[$k]) ? trim((string) $in[$k]) : '');
		}
		$o['dir'] = untrailingslashit(sanitize_text_field(isset($in['dir']) ? (string) $in['dir'] : ''));
		update_option(self::OPTION, $o, false);
		wp_safe_redirect(add_query_arg(['page' => Y4APK_App_Updates::SLUG, 'view' => 'settings', 'y4apk_settings' => 1], admin_url('admin.php')) . '#au-r2');
		exit;
	}

	public static function settings_card() {
		$o = self::settings();
		$from_config = defined('Y4APK_R2_KEY_ID') && defined('Y4APK_R2_SECRET');
		$folders = is_dir($o['dir']) ? count(glob($o['dir'] . '/*', GLOB_ONLYDIR | GLOB_NOSORT)) : -1;
		$origin = wp_parse_url(admin_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(admin_url(), PHP_URL_HOST) . (wp_parse_url(admin_url(), PHP_URL_PORT) ? ':' . wp_parse_url(admin_url(), PHP_URL_PORT) : '');
		$cors = wp_json_encode([['AllowedOrigins' => [$origin], 'AllowedMethods' => ['PUT', 'GET', 'HEAD'], 'AllowedHeaders' => ['*'], 'ExposeHeaders' => ['ETag'], 'MaxAgeSeconds' => 3600]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$field = function ($name, $label, $value, $help = '', $type = 'text') {
			echo '<label style="display:block;margin:0 0 10px"><span class="au-label">' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" style="width:100%" autocomplete="off">'
				. ($help ? '<small class="au-small">' . esc_html($help) . '</small>' : '') . '</label>';
		};
		?>
		<div class="au-card au-pad" id="au-r2" style="margin-top:14px">
			<h2 style="font-size:15px;margin:0 0 6px"><?php esc_html_e('Uploads to Cloudflare R2', 'y4apk'); ?></h2>
			<p class="au-note" style="margin:0 0 12px"><?php esc_html_e('With this set up, each row in “Need a new file” gets an Upload button: the file goes from your browser straight to R2 (any size — 2 or 3 GB is fine), and Save points the post’s download page in files/up at it.', 'y4apk'); ?></p>
			<p style="margin:0 0 12px"><strong><?php echo self::ready() ? '✓ ' . esc_html__('Ready.', 'y4apk') : esc_html__('Not set up yet.', 'y4apk'); ?></strong>
				<?php echo $folders >= 0
					? esc_html(sprintf(__('Download folders found in %1$s: %2$s.', 'y4apk'), $o['dir'], number_format_i18n($folders)))
					: esc_html(sprintf(__('The folder %s was not found on this server.', 'y4apk'), $o['dir'])); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_r2_settings"><?php wp_nonce_field('y4apk-r2-settings'); ?>
				<div class="au-settings">
					<div>
						<?php $field('account', __('Cloudflare Account ID', 'y4apk'), $o['account'], __('Cloudflare ▸ R2 ▸ Overview, “Account ID” (32 letters and digits).', 'y4apk')); ?>
						<?php $field('bucket', __('Bucket', 'y4apk'), $o['bucket']); ?>
						<?php if ($from_config) : ?>
							<p class="au-note"><?php esc_html_e('The API token’s keys come from wp-config.php (Y4APK_R2_KEY_ID, Y4APK_R2_SECRET).', 'y4apk'); ?></p>
						<?php else : ?>
							<?php $field('key_id', __('Access Key ID', 'y4apk'), $o['key_id'], __('R2 ▸ Manage API tokens ▸ Create API token: Object Read & Write, this bucket.', 'y4apk')); ?>
							<?php $field('secret', __('Secret Access Key', 'y4apk'), '', $o['secret'] !== '' ? __('Saved. Leave empty to keep it.', 'y4apk') : '', 'password'); ?>
						<?php endif; ?>
					</div>
					<div>
						<?php $field('public', __('Public address of the bucket', 'y4apk'), $o['public'], __('What the download pages send visitors to, e.g. https://files.y4apk.net', 'y4apk')); ?>
						<?php $field('dir', __('Download pages folder on this server', 'y4apk'), $o['dir'], __('Where files/up is: each download link is a folder in it.', 'y4apk')); ?>
						<?php $field('dir_url', __('Download pages address', 'y4apk'), $o['dir_url']); ?>
					</div>
				</div>
				<p><button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
					<button type="button" class="button" id="au-r2-test" <?php disabled($o['account'] === '' || $o['key_id'] === '' || $o['secret'] === ''); ?>><?php esc_html_e('Test the connection', 'y4apk'); ?></button>
					<span id="au-r2-test-result" class="au-meta"></span></p>
			</form>
			<p class="au-note" style="margin:6px 0"><?php esc_html_e('Once, in Cloudflare ▸ R2 ▸ your bucket ▸ Settings ▸ CORS Policy ▸ Add CORS policy, paste this so your browser may upload from this site:', 'y4apk'); ?></p>
			<code class="au-code" style="white-space:pre"><?php echo esc_html($cors); ?></code>
		</div>
		<?php
	}
}

Y4APK_R2::init();
