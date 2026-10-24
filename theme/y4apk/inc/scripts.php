<?php
/**
 * Script Code: the code a post offers to copy — a Roblox script's
 * loadstring(…) line, in English and in Thai — kept in its own box under the
 * post, like Download Links, and shown in the download section (where the
 * Download button goes) with a Copy button.
 *
 * It used to be pasted into the article as a Custom HTML block: a <style>, a
 * <div class="copy-wrap"> holding the code, and the script for its button,
 * copied into every post. The box keeps the code as plain text and the page
 * shows it escaped, so nothing pasted into a post runs on the page. "Move into
 * the box" takes the pasted blocks out of the article: one post from its box,
 * or every post from Posts.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Scripts {

	const META   = '_y4apk_scripts';        // [['label' => 'ENG', 'code' => 'loadstring(…)()'], …]
	const COUNT  = 'y4apk_scripts_pasted';  // posts with code still pasted in the article, kept for an hour
	const HIDE   = 'y4apk_scripts_hide';    // user meta: when the Posts notice was put off (for 30 days)
	const ROWS   = 20;
	const MAX    = 50000;                   // characters of one code
	const BATCH  = 50;                      // posts per round of "move all"

	public static function init() {
		add_action('save_post_post', [__CLASS__, 'save']);
		add_action('admin_post_y4apk_scripts_move', [__CLASS__, 'handle_move']);
		add_action('admin_notices', [__CLASS__, 'notice']);
	}

	/* ---------------------------------------------------------------- *
	 * The codes
	 * ---------------------------------------------------------------- */

	/** The post's codes: [['label', 'code'], …]. */
	public static function get($post_id) {
		$rows = get_post_meta($post_id, self::META, true);
		return is_array($rows) ? self::clean($rows) : [];
	}

	/** Keeps the rows that have code, as plain text; none: the meta is removed. */
	public static function put($post_id, $rows) {
		$rows = self::clean($rows);
		if ($rows) {
			update_post_meta($post_id, self::META, wp_slash($rows));
		} else {
			delete_post_meta($post_id, self::META);
		}
		return $rows;
	}

	private static function clean($rows) {
		$out = [];
		foreach ((array) $rows as $row) {
			$code = isset($row['code']) ? str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], (string) $row['code']) : '';
			$code = mb_substr(wp_check_invalid_utf8(trim($code, "\n")), 0, self::MAX);
			if (trim($code) === '') {
				continue;
			}
			$label = isset($row['label']) ? mb_substr(sanitize_text_field((string) $row['label']), 0, 60) : '';
			$out[] = ['label' => $label, 'code' => $code];
			if (count($out) >= self::ROWS) {
				break;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- *
	 * The box under the post
	 * ---------------------------------------------------------------- */

	public static function box($post) {
		$rows = self::get($post->ID);
		$pasted = self::pasted($post->post_content);
		wp_nonce_field('y4apk_scripts', 'y4apk_scripts_nonce');
		if (isset($_GET['y4apk_scripts_moved'])) : ?>
			<div class="notice notice-success inline"><p><?php
				$n = absint($_GET['y4apk_scripts_moved']);
				echo esc_html($n
					? sprintf(_n('Moved %s code here from the article.', 'Moved %s codes here from the article.', $n, 'y4apk'), number_format_i18n($n))
					: __('No code was found to move.', 'y4apk'));
			?></p></div>
		<?php endif;
		if ($pasted) : ?>
			<div class="notice notice-warning inline"><p>
				<?php echo esc_html(sprintf(_n('%s code block is still pasted in the article.', '%s code blocks are still pasted in the article.', $pasted, 'y4apk'), number_format_i18n($pasted))); ?>
				<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_scripts_move&post=' . $post->ID), 'y4apk_scripts_move_' . $post->ID)); ?>"><?php esc_html_e('Move them into this box', 'y4apk'); ?></a>
				<span class="description"><?php esc_html_e('Save your changes first: the page reloads.', 'y4apk'); ?></span>
			</p></div>
		<?php endif; ?>
		<p class="description"><?php esc_html_e('Code people copy from this post, such as a Roblox script. It is shown in the download section, where the Download button goes, each with a Copy button. It is shown as plain text: HTML or scripts in it do not run.', 'y4apk'); ?></p>
		<div class="table-container">
			<table id="script-table" class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th></th>
						<th class="script-label-col"><strong><?php esc_html_e('Name (optional)', 'y4apk'); ?></strong></th>
						<th class="script-code-col"><strong><?php esc_html_e('Code', 'y4apk'); ?></strong></th>
						<th><strong><?php esc_html_e('Actions', 'y4apk'); ?></strong></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows ?: [['label' => '', 'code' => '']] as $row) {
						self::row($row);
					}
					self::row(['label' => '', 'code' => ''], 'empty-s-row'); ?>
				</tbody>
			</table>
		</div>
		<button type="button" id="add-script-row" class="add-btn components-button is-secondary" style="margin-top:16px"><?php esc_html_e('Add Code', 'y4apk'); ?></button>
		<?php
	}

	private static function row($row, $class = '') {
		?>
		<tr<?php echo $class ? ' class="' . esc_attr($class) . '" style="display:none"' : ''; ?>>
			<td class="handle_icon"><span class="handle">&#9776;</span></td>
			<td><input type="text" class="widefat" name="script_label[]" value="<?php echo esc_attr($row['label']); ?>" placeholder="<?php esc_attr_e('e.g. ENG, ภาษาไทย', 'y4apk'); ?>" maxlength="60"></td>
			<td><textarea class="widefat script-code" name="script_code[]" rows="3" spellcheck="false" placeholder="loadstring(game:HttpGet(&quot;https://…&quot;))()"><?php echo esc_textarea($row['code']); ?></textarea></td>
			<td><button type="button" class="remove-btn components-button is-destructive"><?php esc_html_e('Remove', 'y4apk'); ?></button></td>
		</tr>
		<?php
	}

	public static function save($post_id) {
		if (!isset($_POST['y4apk_scripts_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['y4apk_scripts_nonce'])), 'y4apk_scripts')
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
			return;
		}
		$labels = isset($_POST['script_label']) ? array_values((array) wp_unslash($_POST['script_label'])) : [];
		$codes = isset($_POST['script_code']) ? array_values((array) wp_unslash($_POST['script_code'])) : [];
		$rows = [];
		foreach ($codes as $i => $code) {
			$rows[] = ['label' => isset($labels[$i]) ? $labels[$i] : '', 'code' => $code];
		}
		self::put($post_id, $rows);
	}

	/* ---------------------------------------------------------------- *
	 * On the post page
	 * ---------------------------------------------------------------- */

	/** The codes, in the download section (#download-now). */
	public static function render($post_id) {
		$rows = self::get($post_id);
		if (!$rows) {
			return;
		}
		// The unlock gate (Y4APK ▸ Direct Link ▸ Lock the script): tap the ad once, then the code shows.
		$gate = class_exists('Y4APK_Direct_Link') ? Y4APK_Direct_Link::script_gate() : null;
		$locked = $gate ? ' is-locked' : '';
		?>
		<div class="y4-scripts<?php echo $locked; ?>"<?php if ($gate) : ?> data-y4-url="<?php echo esc_attr($gate[0]); ?>" data-y4-every="<?php echo esc_attr($gate[1]); ?>"<?php endif; ?>>
			<div class="block f-app-script">
				<span class="muted"></span>
				<div class="attachment-name"><?php esc_html_e('COPY SCRIPT', 'y4apk'); ?></div>
				<?php if (class_exists('Y4APK_Members') && ($by = Y4APK_Members::byline($post_id)) !== '') : ?><div class="y4-byline"><?php echo $by; ?></div><?php endif; ?>
				<?php foreach ($rows as $row) : ?>
				<div class="y4-code">
					<div class="y4-code-bar">
						<span class="y4-code-label"><?php echo esc_html($row['label']); ?></span>
						<button type="button" class="y4-code-copy" data-done="<?php esc_attr_e('Copied!', 'y4apk'); ?>"><?php esc_html_e('Copy', 'y4apk'); ?></button>
					</div>
					<pre class="y4-code-text"><code><?php echo esc_html($row['code']); ?></code></pre>
				</div>
				<?php endforeach; ?>
				<?php if ($gate) : ?>
				<div class="y4-unlock" role="button" tabindex="0" aria-label="<?php esc_attr_e('Unlock the script', 'y4apk'); ?>">
					<span class="y4-unlock-step"><?php esc_html_e('Almost there — 1 step to unlock', 'y4apk'); ?></span>
					<span class="y4-unlock-count"><span class="y4-unlock-n">0</span>/1</span>
				</div>
				<?php endif; ?>
				<?php if (class_exists('Y4APK_Members_Admin') && Y4APK_Roblox::is_roblox_post($post_id)) { Y4APK_Members_Admin::report_ui($post_id); } ?>
			</div>
		</div>
		<?php if ($gate) : ?>
		<script>
		(function () {
			var box = document.currentScript.previousElementSibling;
			while (box && !(box.classList && box.classList.contains('y4-scripts'))) { box = box.previousElementSibling; }
			if (!box || box.y4Ready) { return; }
			box.y4Ready = 1;
			var url = box.getAttribute('data-y4-url'), every = box.getAttribute('data-y4-every'),
				gate = box.querySelector('.y4-unlock'), key = 'y4apk_script_unlock', inApp = <?php echo Y4APK_Direct_Link::in_app_expr(); ?>;
			function done() {
				try {
					if (every === 'visit') sessionStorage.setItem(key, '1');
					else if (every === 'day') localStorage.setItem(key, String(Date.now()));
				} catch (e) {}
			}
			function unlocked() {
				if (inApp) { return true; } // the installed app cannot open the ad tab
				try {
					if (every === 'always') { return false; }
					if (every === 'visit') { return !!sessionStorage.getItem(key); }
					if (every === 'day') { return Date.now() - (parseInt(localStorage.getItem(key) || '0', 10) || 0) < 864e5; }
				} catch (e) {}
				return false;
			}
			function reveal() {
				box.classList.remove('is-locked');
				var n = box.querySelector('.y4-unlock-n'); if (n) { n.textContent = '1'; }
			}
			if (unlocked()) { reveal(); return; }
			function go() {
				if (box.y4Done) { return; }
				box.y4Done = 1;
				if (window.y4apkTrack) { window.y4apkTrack('direct_link', 'script'); }
				window.open(url, '_blank', 'noopener');
				done();
				setTimeout(reveal, 400); // after the new tab takes focus
			}
			gate.addEventListener('click', go);
			gate.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
		})();
		</script>
		<?php endif; ?>
		<script>
		(function () {
			if (window.y4apkCopy) { return; }
			window.y4apkCopy = 1;
			function select(el) {
				var r = document.createRange(), s = window.getSelection();
				r.selectNodeContents(el);
				s.removeAllRanges();
				s.addRange(r);
			}
			function fallback(text) {
				var t = document.createElement('textarea'), ok = false;
				t.value = text;
				t.setAttribute('readonly', '');
				t.style.cssText = 'position:fixed;top:-1000px;opacity:0';
				document.body.appendChild(t);
				t.select();
				try { ok = document.execCommand('copy'); } catch (e) {}
				document.body.removeChild(t);
				return ok;
			}
			document.addEventListener('click', function (e) {
				var b = e.target.closest ? e.target.closest('.y4-code-copy') : null,
					code = b && b.closest('.y4-code') ? b.closest('.y4-code').querySelector('code') : null;
				if (!code) { return; }
				var text = code.textContent, label = b.getAttribute('data-label') || b.textContent;
				b.setAttribute('data-label', label);
				function done() {
					b.textContent = b.getAttribute('data-done');
					b.classList.add('is-done');
					clearTimeout(b.y4t);
					b.y4t = setTimeout(function () { b.textContent = label; b.classList.remove('is-done'); }, 2000);
				}
				function failed() {
					if (fallback(text)) { done(); } else { select(code); } // copied by hand from the selection
				}
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(text).then(done, failed);
				} else {
					failed();
				}
			});
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Moving pasted code out of the article
	 * ---------------------------------------------------------------- */

	/** How many <div class="copy-wrap"> blocks are pasted in this content. */
	public static function pasted($content) {
		return (int) preg_match_all('~<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bcopy-wrap\b~i', (string) $content);
	}

	/**
	 * The content without its pasted code, and the codes: [content, rows].
	 * Takes out each <div class="copy-wrap">…<pre>…</pre></div>, then the
	 * <style> and <script> that came with them, then a Custom HTML block left
	 * empty. A "-- ENG" first line becomes the code's name.
	 */
	public static function take_out($content) {
		$rows = [];
		$content = preg_replace_callback('~<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bcopy-wrap\b[^"\']*\1[^>]*>(.*?)</pre>\s*</div>~is', function ($m) use (&$rows) {
			if (!preg_match('~<pre\b[^>]*>(.*)$~is', $m[2], $pre)) {
				return $m[0];
			}
			// What the page showed: the text, its tags gone (<br> a new line), its &quot; a ".
			$text = preg_replace('~<br\s*/?>~i', "\n", $pre[1]);
			$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$text = trim(str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $text), "\n");
			if (trim($text) === '') {
				return $m[0];
			}
			$label = '';
			$lines = explode("\n", $text);
			if (count($lines) > 1 && preg_match('/^\s*--(?!\[)\s*([^()="\'\[\]{}]{1,40}?)\s*$/u', $lines[0], $l)) {
				$label = $l[1];
				$text = trim(implode("\n", array_slice($lines, 1)), "\n");
			}
			$rows[] = ['label' => $label, 'code' => $text];
			return '';
		}, (string) $content);
		if (!$rows) {
			return [$content, []];
		}
		$content = preg_replace_callback('~<(style|script)\b[^>]*>.*?</\1>\s*~is', function ($m) {
			return preg_match('/copy-(wrap|btn)/i', $m[0]) ? '' : $m[0];
		}, $content);
		// (?:(?!-->).)* keeps to one block's attributes: a block's JSON never holds "-->".
		$content = preg_replace('~<!--\s*wp:html(?:\s+\{(?:(?!-->).)*\})?\s*-->\s*<!--\s*/wp:html\s*-->\s*~s', '', $content);
		return [$content, $rows];
	}

	/** Moves one post's pasted code into its box: how many codes, or a WP_Error. */
	public static function move($post_id) {
		$post = get_post($post_id);
		if (!$post || $post->post_type !== 'post') {
			return 0;
		}
		list($content, $found) = self::take_out($post->post_content);
		if (!$found) {
			return 0;
		}
		$before = self::get($post->ID);
		$have = array_column($before, 'code');
		$add = array_values(array_filter($found, function ($row) use ($have) {
			return !in_array($row['code'], $have, true);
		}));
		self::put($post->ID, array_merge($before, $add));

		// The article without the pasted blocks; its date stays (the post's file is no newer),
		// and what was there stays in Revisions.
		$keep = function ($data, $arr) use ($post) {
			if (isset($arr['ID']) && (int) $arr['ID'] === (int) $post->ID) {
				$data['post_modified'] = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};
		add_filter('wp_insert_post_data', $keep, 99, 2);
		$kses = has_filter('content_save_pre', 'wp_filter_post_kses');
		if ($kses) {
			kses_remove_filters(); // only taking out: nothing else in the article is filtered
		}
		$r = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $content]), true);
		if ($kses) {
			kses_init_filters();
		}
		remove_filter('wp_insert_post_data', $keep, 99);
		if (is_wp_error($r)) {
			self::put($post->ID, $before);
			return $r;
		}
		delete_transient(self::COUNT);
		return count($found);
	}

	private static function pasted_ids($after, $limit) {
		global $wpdb;
		return array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
			AND ID > %d AND post_content LIKE %s ORDER BY ID LIMIT %d",
			$after, '%' . $wpdb->esc_like('copy-wrap') . '%', $limit
		)));
	}

	private static function pasted_count() {
		$n = get_transient(self::COUNT);
		if ($n === false) {
			global $wpdb;
			$n = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_content LIKE %s",
				'%' . $wpdb->esc_like('copy-wrap') . '%'
			));
			set_transient(self::COUNT, $n, HOUR_IN_SECONDS);
		}
		return (int) $n;
	}

	/** One post (from its box), or every post, a round of BATCH at a time (from Posts). */
	public static function handle_move() {
		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		if ($post_id) {
			check_admin_referer('y4apk_scripts_move_' . $post_id);
			if (!current_user_can('edit_post', $post_id)) {
				wp_die(esc_html__('You are not allowed to edit this post.', 'y4apk'), 403);
			}
			$n = self::move($post_id);
			if (is_wp_error($n)) {
				wp_die(esc_html($n->get_error_message()));
			}
			wp_safe_redirect(add_query_arg('y4apk_scripts_moved', $n, get_edit_post_link($post_id, 'url')) . '#script-code');
			exit;
		}
		check_admin_referer('y4apk_scripts_move_all');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$state = [];
		foreach (['after', 'posts', 'codes', 'failed'] as $k) {
			$state[$k] = isset($_GET[$k]) ? absint($_GET[$k]) : 0;
		}
		@set_time_limit(120);
		$start = time();
		$ids = self::pasted_ids($state['after'], self::BATCH);
		foreach ($ids as $id) {
			$n = self::move($id);
			if (is_wp_error($n)) {
				$state['failed']++;
			} elseif ($n) {
				$state['posts']++;
				$state['codes'] += $n;
			}
			$state['after'] = $id;
			if (time() - $start > 20) {
				break;
			}
		}
		if (!$ids || !self::pasted_ids($state['after'], 1)) {
			delete_transient(self::COUNT);
			wp_safe_redirect(add_query_arg(['y4apk_scripts_done' => 1, 'posts' => $state['posts'], 'codes' => $state['codes'], 'failed' => $state['failed']], admin_url('edit.php')));
			exit;
		}
		// The next round as a new page, not a redirect: browsers stop after about 20 redirects.
		$next = wp_nonce_url(add_query_arg(array_merge(['action' => 'y4apk_scripts_move', 'all' => 1], $state), admin_url('admin-post.php')), 'y4apk_scripts_move_all');
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . esc_url($next) . '"><title>' . esc_html__('Moving code…', 'y4apk') . '</title></head>';
		echo '<body style="font:15px/1.5 system-ui,sans-serif;padding:40px;color:#1d2327"><p><strong>' . esc_html__('Moving pasted code into the Script Code boxes…', 'y4apk') . '</strong></p><p>';
		echo esc_html(sprintf(__('%1$s posts done (%2$s codes) — keep this page open, it goes on by itself.', 'y4apk'), number_format_i18n($state['posts']), number_format_i18n($state['codes'])));
		echo '</p><p><a href="' . esc_url($next) . '">' . esc_html__('Continue', 'y4apk') . '</a></p></body></html>';
		exit;
	}

	/** On Posts: how many posts still have pasted code, with "Move all"; and the result after. */
	public static function notice() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->id !== 'edit-post' || !current_user_can('manage_options')) {
			return;
		}
		if (isset($_GET['y4apk_scripts_done'])) {
			$posts = isset($_GET['posts']) ? absint($_GET['posts']) : 0;
			$codes = isset($_GET['codes']) ? absint($_GET['codes']) : 0;
			$failed = isset($_GET['failed']) ? absint($_GET['failed']) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Script Code: moved %1$s codes from %2$s posts into their box.', 'y4apk'), number_format_i18n($codes), number_format_i18n($posts)));
			if ($failed) {
				echo ' ' . esc_html(sprintf(_n('%s post could not be saved and was left as it was.', '%s posts could not be saved and were left as they were.', $failed, 'y4apk'), number_format_i18n($failed)));
			}
			echo '</p></div>';
			return;
		}
		if (isset($_GET[self::HIDE]) && check_admin_referer(self::HIDE)) {
			update_user_meta(get_current_user_id(), self::HIDE, time());
		}
		if (time() - (int) get_user_meta(get_current_user_id(), self::HIDE, true) < 30 * DAY_IN_SECONDS) {
			return;
		}
		$n = self::pasted_count();
		if (!$n) {
			return;
		}
		$all = wp_nonce_url(admin_url('admin-post.php?action=y4apk_scripts_move&all=1'), 'y4apk_scripts_move_all');
		$hide = wp_nonce_url(add_query_arg(self::HIDE, 1, admin_url('edit.php')), self::HIDE);
		?>
		<div class="notice notice-info">
			<p><strong><?php echo esc_html(sprintf(_n('%s post has its script code pasted into the article.', '%s posts have their script code pasted into the article.', $n, 'y4apk'), number_format_i18n($n))); ?></strong>
				<?php esc_html_e('The Script Code box under each post is the place for it now: shown as plain text, with a Copy button, in the download section. Moving takes the pasted blocks (their HTML, style and script) out of the articles; each post keeps its date, and its previous version stays in Revisions.', 'y4apk'); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url($all); ?>"><?php echo esc_html(sprintf(_n('Move the code of %s post', 'Move the code of all %s posts', $n, 'y4apk'), number_format_i18n($n))); ?></a>
				<a class="button-link" style="margin-left:8px" href="<?php echo esc_url($hide); ?>"><?php esc_html_e('Not now', 'y4apk'); ?></a></p>
		</div>
		<?php
	}
}

Y4APK_Scripts::init();
