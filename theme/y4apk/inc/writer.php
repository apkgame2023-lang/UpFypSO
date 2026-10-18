<?php
/**
 * Y4APK ▸ Write with ChatGPT: writes a post's article through OpenAI's API,
 * asked the way it is asked in ChatGPT ("ช่วยเขียนบทความของเกม Tasty Blue
 * ไม่ต้องมีหัวข้อ ไม่ยาว เป็นภาษาอังกฤษ"), with the app's Google Play details
 * to go on, and lays it out like the site's articles:
 *
 *   <strong><a>Name</a> MOD APK [MOD]</strong> – is …   (first paragraph)
 *   … the <a>game</a> …                                (the post's category)
 *   <h2>Name MOD APK (MOD) For Android - Download MOD APK from Y4Apk Free and Premium.</h2>
 *
 * The same run can bring the app's icon (the featured image, "<Name>-MOD.webp")
 * and its screenshots up to date from Google Play.
 *
 * Posts are written from the page, one after another while it is open, or from
 * a queue WP-Cron works through in the background (a few posts every 5
 * minutes). Each write keeps what it replaced, so it can be undone as long as
 * nobody has changed that part of the post since.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Writer {

	const SLUG     = 'y4apk-writer';
	const OPTION   = 'y4apk_writer';
	const QUEUE    = 'y4apk_writer_queue';  // [[post ID, parts, tries], …], first in first out
	const LOG      = 'y4apk_writer_log';    // the last 100 results, newest first
	const USAGE    = 'y4apk_writer_usage';  // ['posts', 'in', 'out'] tokens so far
	const HOOK     = 'y4apk_writer_tick';
	const DONE     = '_y4apk_ai';           // ['time', 'model', 'words'] of the last article written
	const BACKUP   = '_y4apk_ai_backup';    // per part: ['before', 'after', 'time'], for Undo
	const ICON     = '_y4apk_icon_src';     // the Play icon the featured image was made from
	const SHORT    = 40;                    // fewer words than this: no article yet
	const PER_PAGE = 50;
	const ARTICLE  = 1;
	const ICONS    = 2;
	const SHOTS    = 4;
	const API      = 'https://api.openai.com/v1';

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		if (!is_admin()) {
			return;
		}
		add_action('admin_init', [__CLASS__, 'schedule']);
		add_action('admin_menu', [__CLASS__, 'register_page'], 105);
		add_action('admin_enqueue_scripts', [__CLASS__, 'scripts']);
		add_action('admin_post_y4apk_writer_settings', [__CLASS__, 'handle_settings']);
		add_action('admin_post_y4apk_writer_one', [__CLASS__, 'handle_one']);
		foreach (['write', 'undo', 'enqueue', 'queue', 'test', 'preview', 'apply'] as $a) {
			add_action('wp_ajax_y4apk_writer_' . $a, [__CLASS__, 'ajax_' . $a]);
		}
		add_filter('post_row_actions', [__CLASS__, 'row_action'], 11, 2);
		add_filter('bulk_actions-edit-post', [__CLASS__, 'bulk_action']);
		add_filter('handle_bulk_actions-edit-post', [__CLASS__, 'handle_bulk'], 10, 3);
		add_action('admin_notices', [__CLASS__, 'notice']);
	}

	/* ---------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return [
			'key'          => '',
			'model'        => 'auto',
			'prompt'       => 'ช่วยเขียนบทความของ{type} {name} ไม่ต้องมีหัวข้อ ไม่ยาว เป็นภาษาอังกฤษ',
			'prompt_other' => 'ช่วยเขียนบทความของ {name} ไม่ต้องมีหัวข้อ ไม่ยาว เป็นภาษาอังกฤษ',
			'heading'      => '{name} MOD APK ({mod}) For Android - Download MOD APK from Y4Apk Free and Premium.',
			'facts'        => 1,
			'link_name'    => 1,
			'link_type'    => 1,
			'parts'        => self::ARTICLE | self::ICONS | self::SHOTS,
			'per_run'      => 3,
			'paused'       => '',
		];
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], self::defaults());
		// The key may also live in wp-config.php, out of the database.
		if (defined('Y4APK_OPENAI_KEY') && Y4APK_OPENAI_KEY) {
			$o['key'] = Y4APK_OPENAI_KEY;
		}
		$o['per_run'] = max(1, min(10, (int) $o['per_run']));
		$o['parts'] = (int) $o['parts'] & 7;
		return $o;
	}

	private static function save_settings($changes) {
		$o = get_option(self::OPTION, []);
		update_option(self::OPTION, array_merge(is_array($o) ? $o : [], $changes), false);
	}

	public static function handle_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-writer-settings');
		$in = wp_unslash($_POST);
		$o = [
			'model'        => preg_replace('/[^A-Za-z0-9._:\-]/', '', isset($in['model']) ? (string) $in['model'] : 'auto') ?: 'auto',
			'prompt'       => trim(sanitize_textarea_field(isset($in['prompt']) ? $in['prompt'] : '')),
			'prompt_other' => trim(sanitize_textarea_field(isset($in['prompt_other']) ? $in['prompt_other'] : '')),
			'heading'      => trim(sanitize_text_field(isset($in['heading']) ? $in['heading'] : '')),
			'facts'        => empty($in['facts']) ? 0 : 1,
			'link_name'    => empty($in['link_name']) ? 0 : 1,
			'link_type'    => empty($in['link_type']) ? 0 : 1,
		];
		foreach (['prompt', 'prompt_other'] as $k) {
			if ($o[$k] === '') {
				$o[$k] = self::defaults()[$k];
			}
		}
		if (!empty($in['key'])) { // left empty: keep the saved one
			$o['key'] = trim((string) $in['key']);
			delete_transient('y4apk_writer_models');
		}
		if (!empty($in['forget_key'])) {
			$o['key'] = '';
		}
		self::save_settings($o);
		wp_safe_redirect(self::url('settings', ['saved' => 1]));
		exit;
	}

	public static function url($view = 'posts', $args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG, 'view' => $view], $args), admin_url('admin.php'));
	}

	/* ---------------------------------------------------------------- *
	 * OpenAI
	 * ---------------------------------------------------------------- */

	/** The API's JSON, or a WP_Error coded busy (try later) | key | credit | model | api. */
	private static function api($method, $path, $body = null, $timeout = 90) {
		$key = self::settings()['key'];
		if ($key === '') {
			return new WP_Error('key', __('Add your OpenAI API key in Settings first.', 'y4apk'));
		}
		$args = ['method' => $method, 'timeout' => $timeout, 'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json']];
		if ($body !== null) {
			$args['body'] = wp_json_encode($body);
		}
		$r = wp_remote_request(self::API . $path, $args);
		if (is_wp_error($r)) {
			return new WP_Error('busy', sprintf(__('Could not reach OpenAI: %s', 'y4apk'), $r->get_error_message()));
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		$json = json_decode((string) wp_remote_retrieve_body($r), true);
		if ($code >= 200 && $code < 300 && is_array($json)) {
			return $json;
		}
		$err = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
		$msg = isset($err['message']) ? (string) $err['message'] : 'HTTP ' . $code;
		$what = isset($err['code']) && is_string($err['code']) ? $err['code'] : (isset($err['type']) ? (string) $err['type'] : '');
		if ($code === 401 || $what === 'invalid_api_key') {
			return new WP_Error('key', __('OpenAI did not accept the API key. Check it in Settings.', 'y4apk'));
		}
		if ($what === 'insufficient_quota') {
			return new WP_Error('credit', __('The OpenAI account has no credit left: add some at platform.openai.com ▸ Billing.', 'y4apk'));
		}
		if ($code === 429 || $code >= 500) {
			return new WP_Error('busy', sprintf(__('OpenAI is busy: %s', 'y4apk'), $msg));
		}
		if ($what === 'model_not_found' || $code === 404) {
			return new WP_Error('model', sprintf(__('This OpenAI account cannot use the model “%s”. Choose another in Settings.', 'y4apk'), isset($body['model']) ? $body['model'] : ''));
		}
		return new WP_Error('api', sprintf(__('OpenAI: %s', 'y4apk'), $msg), ['param' => isset($err['param']) ? (string) $err['param'] : '']);
	}

	/** The chat models this account has (ids, sorted), kept for a day. */
	public static function models($fresh = false) {
		$cached = get_transient('y4apk_writer_models');
		if (!$fresh && is_array($cached)) {
			return $cached;
		}
		$r = self::api('GET', '/models', null, 20);
		if (is_wp_error($r)) {
			return $r;
		}
		$ids = [];
		foreach (isset($r['data']) ? (array) $r['data'] : [] as $m) {
			$id = isset($m['id']) ? (string) $m['id'] : '';
			// Text models, without dated snapshots and special-purpose ones.
			if (preg_match('/^(gpt-\d|o\d)/', $id) && !preg_match('/audio|realtime|transcribe|tts|image|search|codex|embed|instruct|moderation|preview|chat-latest|\d{4}-?\d{2}-?\d{2}/', $id)) {
				$ids[] = $id;
			}
		}
		natsort($ids);
		$ids = array_values($ids);
		set_transient('y4apk_writer_models', $ids, DAY_IN_SECONDS);
		return $ids;
	}

	/** Of the account's models, the newest GPT "mini": good at short articles, and cheap. */
	public static function pick($ids) {
		$best = '';
		$best_v = -1.0;
		foreach ($ids as $id) {
			if (preg_match('/^gpt-(\d+(?:\.\d+)?)o?-mini$/', $id, $m) && (float) $m[1] > $best_v) {
				$best = $id;
				$best_v = (float) $m[1];
			}
		}
		return $best !== '' ? $best : 'gpt-4o-mini';
	}

	/** The model to write with: the one chosen in Settings, or the pick. */
	public static function model() {
		$m = self::settings()['model'];
		if ($m !== '' && $m !== 'auto') {
			return $m;
		}
		$ids = self::models();
		return self::pick(is_array($ids) ? $ids : []);
	}

	/** The model's answer as text, or a WP_Error. */
	public static function chat($system, $user, $model, $max = 4000) {
		$body = [
			'model'                 => $model,
			'messages'              => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
			'max_completion_tokens' => $max,
		];
		// Reasoning models think first; a little is plenty for a short article.
		if (preg_match('/^(gpt-5|o\d)/', $model)) {
			$body['reasoning_effort'] = 'low';
		}
		$r = self::api('POST', '/chat/completions', $body);
		if (is_wp_error($r) && $r->get_error_code() === 'api' && isset($body['reasoning_effort'])
			&& (stripos($r->get_error_message(), 'reasoning') !== false || (isset($r->get_error_data()['param']) && $r->get_error_data()['param'] === 'reasoning_effort'))) {
			unset($body['reasoning_effort']);
			$r = self::api('POST', '/chat/completions', $body);
		}
		if (is_wp_error($r)) {
			return $r;
		}
		if (isset($r['usage']) && is_array($r['usage'])) {
			$u = get_option(self::USAGE, []);
			$u = wp_parse_args(is_array($u) ? $u : [], ['posts' => 0, 'in' => 0, 'out' => 0]);
			$u['in'] += isset($r['usage']['prompt_tokens']) ? (int) $r['usage']['prompt_tokens'] : 0;
			$u['out'] += isset($r['usage']['completion_tokens']) ? (int) $r['usage']['completion_tokens'] : 0;
			update_option(self::USAGE, $u, false);
		}
		$choice = isset($r['choices'][0]) ? $r['choices'][0] : [];
		$text = isset($choice['message']['content']) ? trim((string) $choice['message']['content']) : '';
		if ($text === '') {
			return new WP_Error('api', isset($choice['finish_reason']) && $choice['finish_reason'] === 'length'
				? __('The answer was cut off before it was done.', 'y4apk')
				: __('OpenAI sent back an empty answer.', 'y4apk'));
		}
		return $text;
	}

	/* ---------------------------------------------------------------- *
	 * The article
	 * ---------------------------------------------------------------- */

	/** The MOD in the post's words: App MOD Feature, else the download's MOD Info, else the title's "(…)". */
	public static function mod($post_id) {
		$mod = trim((string) get_post_meta($post_id, 'wp_mods', true));
		if ($mod === '') {
			list(, $link) = Y4APK_App_Updates::download($post_id);
			$mod = is_array($link) && isset($link['download_mod_info']) ? trim((string) $link['download_mod_info']) : '';
		}
		if ($mod === '' && preg_match('/\(([^()]{2,80})\)/', wp_strip_all_tags(get_post_field('post_title', $post_id)), $m)) {
			$mod = trim($m[1]);
		}
		return trim($mod, " []()");
	}

	private static function top_category($post_id) {
		$cats = get_the_category($post_id);
		if (!$cats) {
			return null;
		}
		$c = $cats[0];
		while ($c->parent && ($p = get_category($c->parent)) && !is_wp_error($p)) {
			$c = $p;
		}
		return $c;
	}

	/** What the article is about, and where its links go. */
	public static function context($post_id, $play = null) {
		$title = wp_strip_all_tags(get_post_field('post_title', $post_id));
		$name = trim(wp_strip_all_tags((string) get_post_meta($post_id, 'wp_title_GP', true)));
		$name = $name !== '' ? $name : Y4APK_App_Updates::name_from_title($title);
		$package = Y4APK_App_Updates::package_from(get_post_meta($post_id, 'wp_GP_ID', true));
		if ($play === null) {
			$play = $package !== '' ? Y4APK_App_Updates::play_app($package) : ['status' => 'none'];
		}
		$on_play = $play['status'] === 'ok';
		list(, $download) = Y4APK_App_Updates::download($post_id);
		$top = self::top_category($post_id);
		$developer = trim((string) get_post_meta($post_id, 'wp_developers_GP', true));
		return [
			'name'         => $name !== '' ? $name : $title,
			'mod'          => self::mod($post_id),
			'apk'          => $package !== '' || ($download && Y4APK_App_Updates::is_apk($download)),
			'game'         => $on_play ? !empty($play['game']) : ($top && stripos($top->slug . ' ' . $top->name, 'game') !== false),
			'category'     => $top ? $top->name : '',
			'category_url' => $top ? get_category_link($top) : '',
			'developer'    => $developer !== '' ? $developer : ($on_play ? $play['developer'] : ''),
			'play_url'     => $package !== '' ? 'https://play.google.com/store/apps/details?id=' . $package : '',
			'on_play'      => $on_play,
			'play'         => $play,
		];
	}

	/** "MOD APK [Unlimited Money]", the words after the name in bold. */
	private static function label($ctx) {
		return 'MOD APK' . ($ctx['mod'] !== '' ? ' [' . $ctx['mod'] . ']' : '');
	}

	private static function fill($template, $ctx) {
		return strtr($template, [
			'{name}'      => $ctx['name'],
			'{type}'      => $ctx['game'] ? 'เกม' : 'แอป',
			'{type_en}'   => $ctx['game'] ? 'game' : 'app',
			'{mod}'       => $ctx['mod'],
			'{category}'  => $ctx['category'],
			'{developer}' => $ctx['developer'],
		]);
	}

	/** [system message, the message as you would type it in ChatGPT]. */
	public static function prompt($ctx) {
		$o = self::settings();
		$user = self::fill($ctx['apk'] ? $o['prompt'] : $o['prompt_other'], $ctx);
		if ($o['facts'] && $ctx['on_play']) {
			$p = $ctx['play'];
			$facts = array_filter([
				'Name: ' . ($p['title'] !== '' ? $p['title'] : $ctx['name']),
				$ctx['developer'] !== '' ? 'Developer: ' . $ctx['developer'] : '',
				!empty($p['genre']) ? 'Genre: ' . $p['genre'] : '',
				!empty($p['description']) ? "Description:\n" . mb_substr($p['description'], 0, 2500) : '',
			]);
			$user .= "\n\n---\nFacts from its store page, for accuracy only. Write in your own words: do not copy its sentences, and do not mention the store, MOD or APK.\n" . implode("\n", $facts);
		}
		$system = 'You write short articles for an Android games and apps website. Reply with the article only, as plain text: '
			. 'paragraphs separated by one blank line. No title, no headings, no lists, no markdown, no emojis, no links. '
			. 'Begin the first paragraph with the exact words "' . $ctx['name'] . ' is".';
		return [$system, $user];
	}

	/** The words of a post's text, headings left out. */
	public static function words($content) {
		$text = preg_replace('~<h[1-6][^>]*>.*?</h[1-6]>~is', ' ', (string) $content);
		$text = html_entity_decode(wp_strip_all_tags(strip_shortcodes($text)), ENT_QUOTES, 'UTF-8');
		return count(preg_split('/[\s\p{Z}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
	}

	private static function text($s) {
		return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
	}

	/** The answer as the post's blocks: ['html', 'words'], or null when it has no paragraphs. */
	public static function layout($answer, $ctx) {
		$o = self::settings();
		$text = str_replace(["\r\n", "\r"], "\n", trim((string) $answer));
		$text = preg_replace('/^\s{0,3}#{1,6}\s.*$/m', '', $text);           // a heading line
		$text = preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $text);             // **bold**
		$text = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/mu', '', $text);       // list marks
		$paras = preg_split('/\n\s*\n/', $text);
		if (count($paras) === 1) {
			$paras = explode("\n", $text);
		}
		$paras = array_values(array_filter(array_map(function ($p) {
			return trim(preg_replace('/\s+/u', ' ', $p), " \t\"“”");
		}, $paras), 'strlen'));
		if (!$paras) {
			return null;
		}
		$paras = array_slice($paras, 0, 6);

		$name = self::text($ctx['name']);
		if ($o['link_name'] && $ctx['play_url'] !== '') {
			$name = '<a href="' . esc_url($ctx['play_url']) . '">' . $name . '</a>';
		}
		$blocks = [];
		$linked = false;
		$words = 0;
		foreach ($paras as $i => $p) {
			$words += count(preg_split('/\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY));
			$html = self::text($p);
			$n = mb_strlen($ctx['name']);
			if ($i === 0 && mb_strtolower(mb_substr($p, 0, $n)) === mb_strtolower($ctx['name']) && preg_match('/^\s+\S/u', mb_substr($p, $n))) {
				// "OTR 2 is an open-world …" → "<strong>OTR 2 MOD APK [Unlimited Money]</strong> – is an open-world …"
				$rest = self::text(ltrim(mb_substr($p, $n)));
				$html = $ctx['apk'] ? '<strong>' . $name . ' ' . self::text(self::label($ctx)) . '</strong> – ' . $rest : '<strong>' . $name . '</strong> ' . $rest;
			} elseif ($i > 0 && !$linked && $o['link_type'] && $ctx['category_url'] !== '') {
				$word = $ctx['game'] ? 'game' : 'app';
				$html = preg_replace('/\b' . $word . '\b/', '<a href="' . esc_url($ctx['category_url']) . '">' . $word . '</a>', $html, 1, $hits);
				$linked = $hits > 0;
			}
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
		}
		if ($ctx['apk'] && trim($o['heading']) !== '') {
			$h = $o['heading'];
			if ($ctx['mod'] === '') {
				$h = preg_replace('/\s*[(\[]\s*\{mod\}\s*[)\]]/', '', $h);
			}
			$h = trim(preg_replace('/\s+/u', ' ', self::fill($h, $ctx)));
			$blocks[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . self::text($h) . "</h2>\n<!-- /wp:heading -->";
		}
		return ['html' => implode("\n\n", $blocks), 'words' => $words];
	}

	/* ---------------------------------------------------------------- *
	 * Writing a post
	 * ---------------------------------------------------------------- */

	private static function result($ok, $message, $retry = false, $code = '') {
		return ['ok' => $ok, 'message' => $message, 'retry' => $retry, 'code' => $code];
	}

	/** Remembers a part's value before and after a write, for Undo. */
	private static function remember($post_id, $part, $before, $after) {
		$b = get_post_meta($post_id, self::BACKUP, true);
		$b = is_array($b) ? $b : [];
		$b[$part] = ['before' => $before, 'after' => $after, 'time' => time()];
		update_post_meta($post_id, self::BACKUP, wp_slash($b));
	}

	/** One write per post at a time (a double click, a link opened twice): true when this request may write it. */
	private static function lock($post_id) {
		global $wpdb;
		$key = 'y4apk_writer_busy_' . (int) $post_id;
		if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, time()))) {
			return true;
		}
		// Left behind by a request that died: taken over after 3 minutes.
		$since = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
		return (int) $since < time() - 180
			&& (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time(), $key, $since));
	}

	private static function unlock($post_id) {
		global $wpdb;
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'y4apk_writer_busy_' . (int) $post_id));
	}

	public static function locked($post_id) {
		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", 'y4apk_writer_busy_' . (int) $post_id));
	}

	/** Writes the post's article and/or brings its icon and screenshots up to date ($parts: ARTICLE | ICONS | SHOTS). */
	public static function write($post_id, $parts) {
		if (!self::lock($post_id)) {
			return self::result(false, __('This post is being written right now.', 'y4apk'), true, 'locked');
		}
		try {
			return self::write_now($post_id, $parts);
		} finally {
			self::unlock($post_id);
		}
	}

	private static function write_now($post_id, $parts) {
		$post = get_post($post_id);
		if (!$post || $post->post_type !== 'post') {
			return self::result(false, __('Post not found.', 'y4apk'));
		}
		$ctx = self::context($post_id);
		if ($ctx['play']['status'] === 'busy') {
			return self::result(false, __('Google Play is busy; trying again later.', 'y4apk'), true, 'busy');
		}
		$done = [];
		if ($parts & self::ARTICLE) {
			list($system, $user) = self::prompt($ctx);
			$model = self::model();
			$answer = self::chat($system, $user, $model);
			if (is_wp_error($answer)) {
				return self::result(false, $answer->get_error_message(), $answer->get_error_code() === 'busy', $answer->get_error_code());
			}
			$article = self::layout($answer, $ctx);
			if (!$article) {
				return self::result(false, __('The answer had no paragraphs.', 'y4apk'));
			}
			$saved = self::save_article($post, $article, $model);
			if (is_wp_error($saved)) {
				return self::result(false, $saved->get_error_message());
			}
			$done[] = sprintf(_n('%s word', '%s words', $article['words'], 'y4apk'), number_format_i18n($article['words']));
		}
		if ($parts & (self::ICONS | self::SHOTS) && !$ctx['on_play']) {
			$done[] = $ctx['play_url'] === '' ? __('no Google Play app linked: icon and screenshots left as they are', 'y4apk') : __('not found on Google Play: icon and screenshots left as they are', 'y4apk');
		} else {
			if ($parts & self::ICONS) {
				$icon = self::update_icon($post_id, $ctx);
				$done[] = is_wp_error($icon) ? sprintf(__('icon not updated (%s)', 'y4apk'), $icon->get_error_message()) : ($icon ? __('new icon', 'y4apk') : __('icon already the latest', 'y4apk'));
			}
			if ($parts & self::SHOTS) {
				$n = self::update_screenshots($post_id, $ctx);
				$done[] = $n === false ? __('screenshots already the latest', 'y4apk') : ($n ? sprintf(_n('%s screenshot', '%s screenshots', $n, 'y4apk'), number_format_i18n($n)) : __('no screenshots on Google Play', 'y4apk'));
			}
		}
		delete_transient('y4apk_writer_short');
		return self::result(true, ucfirst(implode(' · ', $done)));
	}

	private static function save_article($post, $article, $model) {
		$r = wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($article['html'])], true);
		if (is_wp_error($r)) {
			return $r;
		}
		self::remember($post->ID, 'content', $post->post_content, get_post_field('post_content', $post->ID, 'raw'));
		update_post_meta($post->ID, self::DONE, ['time' => time(), 'model' => $model, 'words' => $article['words']]);
		$u = get_option(self::USAGE, []);
		$u = wp_parse_args(is_array($u) ? $u : [], ['posts' => 0, 'in' => 0, 'out' => 0]);
		$u['posts']++;
		update_option(self::USAGE, $u, false);
		return true;
	}

	/** The app's icon from Google Play as the featured image: the attachment ID, 0 when it already is, or a WP_Error. */
	private static function update_icon($post_id, $ctx) {
		$src = isset($ctx['play']['icon']) ? $ctx['play']['icon'] : '';
		if ($src === '') {
			return new WP_Error('icon', __('Google Play shows none', 'y4apk'));
		}
		$thumb = (int) get_post_thumbnail_id($post_id);
		if ($thumb && $src === get_post_meta($post_id, self::ICON, true)) {
			return 0;
		}
		$r = wp_remote_get($src . '=s512-rw', ['timeout' => 25, 'user-agent' => Y4APK_App_Updates::UA]);
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return new WP_Error('icon', __('could not download it', 'y4apk'));
		}
		$body = (string) wp_remote_retrieve_body($r);
		$size = @getimagesizefromstring($body);
		$types = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
		if (!$size || !isset($types[$size['mime']])) {
			return new WP_Error('icon', __('not an image', 'y4apk'));
		}
		$alt = $ctx['name'] . ' MOD APK' . ($ctx['mod'] !== '' ? ' (' . $ctx['mod'] . ')' : '');
		$file = wp_upload_bits(sanitize_file_name($ctx['name'] . '-MOD.' . $types[$size['mime']]), null, $body);
		if (!empty($file['error'])) {
			return new WP_Error('icon', $file['error']);
		}
		$att = wp_insert_attachment(wp_slash([
			'post_mime_type' => $size['mime'],
			'post_title'     => $ctx['name'] . ' MOD',
			'post_excerpt'   => $alt, // caption
			'post_content'   => $alt, // description
			'post_status'    => 'inherit',
		]), $file['file'], $post_id, true);
		if (is_wp_error($att)) {
			return $att;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $file['file']));
		update_post_meta($att, '_wp_attachment_image_alt', wp_slash($alt));
		set_post_thumbnail($post_id, $att);
		self::remember($post_id, 'thumb', $thumb, $att);
		update_post_meta($post_id, self::ICON, $src);
		return $att;
	}

	/** The app's screenshots from Google Play (up to 10): how many, or false when they already are. */
	private static function update_screenshots($post_id, $ctx) {
		$list = [];
		foreach (array_slice(isset($ctx['play']['screenshots']) ? $ctx['play']['screenshots'] : [], 0, 10) as $u) {
			$list[] = ['ss_url' => esc_url_raw($u . '=w1052-h592-rw')];
		}
		if (!$list) {
			return 0;
		}
		$old = get_post_meta($post_id, 'ss_images', true);
		if ($old === $list) {
			return false;
		}
		update_post_meta($post_id, 'ss_images', $list);
		self::remember($post_id, 'ss', $old, $list);
		return count($list);
	}

	/** Puts back what the last write replaced, part by part, where nobody has changed it since. */
	public static function undo($post_id) {
		$b = get_post_meta($post_id, self::BACKUP, true);
		if (!is_array($b) || !$b) {
			return self::result(false, __('Nothing to undo.', 'y4apk'));
		}
		$back = [];
		$kept = [];
		if (isset($b['content'])) {
			if (get_post_field('post_content', $post_id, 'raw') === $b['content']['after']) {
				wp_update_post(['ID' => $post_id, 'post_content' => wp_slash((string) $b['content']['before'])]);
				delete_post_meta($post_id, self::DONE);
				$back[] = __('text', 'y4apk');
			} else {
				$kept[] = __('text', 'y4apk');
			}
		}
		if (isset($b['thumb'])) {
			if ((int) get_post_thumbnail_id($post_id) === (int) $b['thumb']['after']) {
				$b['thumb']['before'] ? set_post_thumbnail($post_id, (int) $b['thumb']['before']) : delete_post_thumbnail($post_id);
				delete_post_meta($post_id, self::ICON);
				$back[] = __('icon', 'y4apk');
			} else {
				$kept[] = __('icon', 'y4apk');
			}
		}
		if (isset($b['ss'])) {
			if (get_post_meta($post_id, 'ss_images', true) === $b['ss']['after']) {
				$b['ss']['before'] ? update_post_meta($post_id, 'ss_images', $b['ss']['before']) : delete_post_meta($post_id, 'ss_images');
				$back[] = __('screenshots', 'y4apk');
			} else {
				$kept[] = __('screenshots', 'y4apk');
			}
		}
		delete_post_meta($post_id, self::BACKUP);
		delete_transient('y4apk_writer_short');
		$msg = $back ? sprintf(__('Put back: %s.', 'y4apk'), implode(', ', $back)) : '';
		if ($kept) {
			$msg .= ' ' . sprintf(__('Left as they are (changed since): %s.', 'y4apk'), implode(', ', $kept));
		}
		return self::result((bool) $back, trim($msg));
	}

	private static function log($post_id, $r) {
		$log = get_option(self::LOG, []);
		$log = is_array($log) ? $log : [];
		array_unshift($log, ['t' => time(), 'id' => (int) $post_id, 'ok' => (bool) $r['ok'], 'm' => (string) $r['message']]);
		update_option(self::LOG, array_slice($log, 0, 100), false);
	}

	/* ---------------------------------------------------------------- *
	 * The background queue
	 * ---------------------------------------------------------------- */

	public static function schedules($schedules) {
		$schedules['y4apk_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Y4APK)'];
		return $schedules;
	}

	public static function queue() {
		$q = get_option(self::QUEUE, []);
		return is_array($q) ? $q : [];
	}

	/** Runs every 5 minutes while there is something to write and the queue is not paused. */
	public static function schedule() {
		$on = self::queue() && self::settings()['paused'] === '';
		$next = wp_next_scheduled(self::HOOK);
		if ($on && !$next) {
			wp_schedule_event(time() + 30, 'y4apk_5min', self::HOOK);
		} elseif (!$on && $next) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	public static function enqueue($ids, $parts) {
		$q = self::queue();
		$have = array_flip(array_map(function ($item) { return (int) $item[0]; }, $q));
		$added = 0;
		foreach (array_map('intval', $ids) as $id) {
			if ($id > 0 && !isset($have[$id])) {
				$q[] = [$id, (int) $parts & 7, 0];
				$have[$id] = 1;
				$added++;
			}
		}
		update_option(self::QUEUE, $q, false);
		self::schedule();
		return $added;
	}

	private static function dequeue($post_id) {
		update_option(self::QUEUE, array_values(array_filter(self::queue(), function ($item) use ($post_id) {
			return (int) $item[0] !== (int) $post_id;
		})), false);
	}

	/** A few posts from the front of the queue (Settings ▸ per run), within about two and a half minutes. */
	public static function tick() {
		$o = self::settings();
		if ($o['paused'] !== '' || !self::queue() || get_transient('y4apk_writer_lock')) {
			return 0;
		}
		set_transient('y4apk_writer_lock', 1, 5 * MINUTE_IN_SECONDS);
		@set_time_limit(300);
		$start = time();
		$done = 0;
		while ($done < $o['per_run'] && time() - $start < 150) {
			$q = self::queue();
			if (!$q) {
				break;
			}
			list($id, $parts, $tries) = array_map('intval', $q[0] + [0, 7, 0]);
			$r = self::write($id, $parts);
			if (!$r['ok'] && in_array($r['code'], ['key', 'credit', 'model'], true)) {
				// The account, not the post: stop until it is sorted out.
				self::save_settings(['paused' => $r['message']]);
				self::log($id, $r);
				break;
			}
			if (!$r['ok'] && $r['retry'] && $tries < 4) {
				// Busy (Google Play, OpenAI, or the post is being written): to the back of the queue, and on next round.
				self::dequeue($id);
				update_option(self::QUEUE, array_merge(self::queue(), [[$id, $parts, $tries + 1]]), false);
				break;
			}
			self::dequeue($id);
			self::log($id, $r);
			$done++;
		}
		delete_transient('y4apk_writer_lock');
		self::schedule();
		return $done;
	}

	/* ---------------------------------------------------------------- *
	 * Which posts
	 * ---------------------------------------------------------------- */

	/** Posts whose text is under SHORT words (headings left out). */
	public static function short_ids() {
		$ids = get_transient('y4apk_writer_short');
		if (is_array($ids)) {
			return $ids;
		}
		global $wpdb;
		$ids = [];
		$rows = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'post'
			AND post_status IN ('publish', 'draft', 'pending', 'future', 'private') AND CHAR_LENGTH(post_content) < 2500");
		foreach ($rows as $row) {
			if (self::words($row->post_content) < self::SHORT) {
				$ids[] = (int) $row->ID;
			}
		}
		set_transient('y4apk_writer_short', $ids, 10 * MINUTE_IN_SECONDS);
		return $ids;
	}

	private static function count_done() {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = 'post' AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'private')", self::DONE));
	}

	private static function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'future', 'private')");
	}

	/** The query for a list: show = short | notai | ai | all | queued. */
	private static function query_args($show, $cat, $search) {
		$args = [
			'post_type'      => 'post',
			'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		];
		if ($cat) {
			$args['cat'] = $cat;
		}
		if ($search !== '') {
			$args['s'] = $search;
		}
		if ($show === 'short') {
			$args['post__in'] = self::short_ids() ?: [0];
		} elseif ($show === 'queued') {
			$args['post__in'] = array_map(function ($i) { return (int) $i[0]; }, self::queue()) ?: [0];
		} elseif ($show === 'notai') {
			$args['meta_query'] = [['key' => self::DONE, 'compare' => 'NOT EXISTS']];
		} elseif ($show === 'ai') {
			$args['meta_query'] = [['key' => self::DONE, 'compare' => 'EXISTS']];
		}
		return $args;
	}

	private static function filters() {
		$show = isset($_REQUEST['show']) ? sanitize_key(wp_unslash($_REQUEST['show'])) : 'short';
		return [
			in_array($show, ['short', 'notai', 'ai', 'all', 'queued'], true) ? $show : 'short',
			isset($_REQUEST['cat']) ? absint($_REQUEST['cat']) : 0,
			isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '',
		];
	}

	/* ---------------------------------------------------------------- *
	 * AJAX (the page)
	 * ---------------------------------------------------------------- */

	private static function check($post_id = 0, $cap = 'edit_posts') {
		check_ajax_referer('y4apk-writer', 'nonce');
		if (!current_user_can($cap) || ($post_id && !current_user_can('edit_post', $post_id))) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
		@set_time_limit(300);
	}

	/** What to update, as ticked on the page; remembered for the Posts list's actions. */
	private static function parts_in() {
		$parts = isset($_POST['parts']) ? (int) $_POST['parts'] & 7 : self::settings()['parts'];
		if (!$parts) {
			wp_send_json_error(['message' => __('Tick at least one of Article, Icon and Screenshots.', 'y4apk')]);
		}
		if ($parts !== self::settings()['parts']) {
			self::save_settings(['parts' => $parts]);
		}
		return $parts;
	}

	public static function ajax_write() {
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		self::check($id);
		$r = self::write($id, self::parts_in());
		if ($r['code'] !== 'locked') {
			self::log($id, $r);
		}
		if ($r['ok']) {
			self::dequeue($id);
		}
		$r['status'] = self::status_html($id);
		wp_send_json_success($r);
	}

	public static function ajax_undo() {
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		self::check($id);
		$r = self::undo($id);
		$r['status'] = self::status_html($id);
		wp_send_json_success($r);
	}

	public static function ajax_enqueue() {
		self::check();
		$parts = self::parts_in();
		if (!empty($_POST['all'])) {
			list($show, $cat, $search) = self::filters();
			$q = new WP_Query(array_merge(self::query_args($show, $cat, $search), ['posts_per_page' => -1, 'no_found_rows' => true]));
			$ids = $q->posts;
		} else {
			$ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
		}
		$ids = array_values(array_filter($ids, function ($id) { return current_user_can('edit_post', $id); }));
		$added = self::enqueue($ids, $parts);
		wp_send_json_success(['added' => $added, 'waiting' => count(self::queue()), 'message' => sprintf(_n('%s post added to the queue.', '%s posts added to the queue.', $added, 'y4apk'), number_format_i18n($added))]);
	}

	public static function ajax_queue() {
		self::check(0, 'manage_options');
		$do = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
		if ($do === 'pause') {
			self::save_settings(['paused' => __('Paused by you.', 'y4apk')]);
		} elseif ($do === 'resume') {
			self::save_settings(['paused' => '']);
		} elseif ($do === 'clear') {
			update_option(self::QUEUE, [], false);
		} elseif ($do === 'speed') {
			self::save_settings(['per_run' => max(1, min(10, isset($_POST['per_run']) ? (int) $_POST['per_run'] : 3))]);
		} elseif ($do === 'run') {
			$paused = self::settings()['paused'];
			if ($paused === '') {
				delete_transient('y4apk_writer_lock');
				self::tick();
			}
		}
		self::schedule();
		wp_send_json_success(['reload' => true]);
	}

	/** Settings ▸ Test: the key, the models it can use, and one tiny answer (which also proves there is credit). */
	public static function ajax_test() {
		self::check(0, 'manage_options');
		$models = self::models(true);
		if (is_wp_error($models)) {
			wp_send_json_error(['message' => $models->get_error_message()]);
		}
		$model = self::model();
		$reply = self::chat('Reply with one word.', 'Say OK.', $model, 1000);
		if (is_wp_error($reply)) {
			wp_send_json_error(['message' => $reply->get_error_message(), 'models' => $models, 'model' => $model]);
		}
		wp_send_json_success(['models' => $models, 'model' => $model, 'reply' => mb_substr($reply, 0, 40)]);
	}

	/** A post from its ID, its edit or public address, or words from its title. */
	private static function find_post($ref) {
		$ref = trim((string) $ref);
		if ($ref === '') {
			return 0;
		}
		if (ctype_digit($ref)) {
			$id = (int) $ref;
		} elseif (preg_match('~[?&]post=(\d+)~', $ref, $m)) {
			$id = (int) $m[1];
		} elseif (preg_match('~^https?://~i', $ref)) {
			$id = url_to_postid($ref);
		} else {
			$q = new WP_Query(['post_type' => 'post', 'post_status' => 'any', 's' => $ref, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
			$id = $q->posts ? (int) $q->posts[0] : 0;
		}
		return $id && get_post_type($id) === 'post' ? $id : 0;
	}

	/** Settings ▸ Try it: what would be asked and written for one post, without saving. */
	public static function ajax_preview() {
		self::check();
		$id = self::find_post(isset($_POST['ref']) ? wp_unslash($_POST['ref']) : '');
		if (!$id || !current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('No post found: type its ID, its address or words from its title.', 'y4apk')]);
		}
		$ctx = self::context($id);
		list($system, $user) = self::prompt($ctx);
		$model = self::model();
		$answer = self::chat($system, $user, $model);
		if (is_wp_error($answer)) {
			wp_send_json_error(['message' => $answer->get_error_message(), 'prompt' => $user]);
		}
		$article = self::layout($answer, $ctx);
		wp_send_json_success([
			'id'      => $id,
			'title'   => get_the_title($id),
			'edit'    => get_edit_post_link($id, 'raw'),
			'prompt'  => $user,
			'model'   => $model,
			'answer'  => $answer,
			'html'    => $article ? $article['html'] : '',
			'words'   => $article ? $article['words'] : 0,
			'play'    => $ctx['on_play'],
		]);
	}

	/** Settings ▸ Try it ▸ Use this text: saves the previewed answer to the post. */
	public static function ajax_apply() {
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		self::check($id);
		$answer = isset($_POST['answer']) ? sanitize_textarea_field(wp_unslash($_POST['answer'])) : '';
		$post = get_post($id);
		$article = $post ? self::layout($answer, self::context($id)) : null;
		if (!$article) {
			wp_send_json_error(['message' => __('Nothing to save.', 'y4apk')]);
		}
		$saved = self::save_article($post, $article, self::model());
		if (is_wp_error($saved)) {
			wp_send_json_error(['message' => $saved->get_error_message()]);
		}
		$r = self::result(true, sprintf(_n('%s word', '%s words', $article['words'], 'y4apk'), number_format_i18n($article['words'])));
		self::log($id, $r);
		delete_transient('y4apk_writer_short');
		wp_send_json_success($r + ['view' => get_permalink($id)]);
	}

	/* ---------------------------------------------------------------- *
	 * Posts list: "Write with ChatGPT" (one post) and the bulk action (queue)
	 * ---------------------------------------------------------------- */

	public static function row_action($actions, $post) {
		if ($post->post_type === 'post' && current_user_can('edit_post', $post->ID)) {
			$actions['y4apk_write'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_writer_one&post=' . $post->ID), 'y4apk-writer-one-' . $post->ID)) . '" onclick="this.textContent=\'' . esc_js(__('Writing… (up to a minute)', 'y4apk')) . '\'">'
				. esc_html__('Write with ChatGPT', 'y4apk') . '</a>';
		}
		return $actions;
	}

	public static function handle_one() {
		$id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		check_admin_referer('y4apk-writer-one-' . $id);
		if (!current_user_can('edit_post', $id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		@set_time_limit(300);
		$r = self::write($id, self::settings()['parts']);
		if ($r['code'] === 'locked') {
			// The same link opened twice: the first request writes it; come back with its result.
			for ($i = 0; $i < 120 && self::locked($id); $i++) {
				sleep(1);
			}
		} else {
			self::log($id, $r);
			set_transient('y4apk_writer_notice_' . get_current_user_id(), ['id' => $id] + $r, 5 * MINUTE_IN_SECONDS);
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url('edit.php');
		wp_safe_redirect($back);
		exit;
	}

	public static function bulk_action($actions) {
		$actions['y4apk_writer_queue'] = __('Write with ChatGPT (queue)', 'y4apk');
		return $actions;
	}

	public static function handle_bulk($redirect, $action, $ids) {
		if ($action !== 'y4apk_writer_queue') {
			return $redirect;
		}
		$ids = array_filter(array_map('intval', $ids), function ($id) { return current_user_can('edit_post', $id); });
		$added = self::enqueue($ids, self::settings()['parts']);
		set_transient('y4apk_writer_notice_' . get_current_user_id(), ['queued' => $added], 5 * MINUTE_IN_SECONDS);
		return $redirect;
	}

	public static function notice() {
		$key = 'y4apk_writer_notice_' . get_current_user_id();
		$n = get_transient($key);
		if (!is_array($n)) {
			return;
		}
		delete_transient($key);
		if (isset($n['queued'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%s post added to the ChatGPT queue.', '%s posts added to the ChatGPT queue.', $n['queued'], 'y4apk'), number_format_i18n($n['queued'])))
				. ' <a href="' . esc_url(self::url('queue')) . '">' . esc_html__('See the queue', 'y4apk') . '</a></p></div>';
			return;
		}
		$title = '<a href="' . esc_url(get_permalink($n['id'])) . '" target="_blank">' . esc_html(get_the_title($n['id'])) . '</a>';
		echo '<div class="notice notice-' . ($n['ok'] ? 'success' : 'error') . ' is-dismissible"><p>'
			. ($n['ok'] ? sprintf(esc_html__('Written with ChatGPT: %1$s — %2$s', 'y4apk'), $title, esc_html($n['message']))
				: sprintf(esc_html__('Not written: %1$s — %2$s', 'y4apk'), $title, esc_html($n['message'])))
			. '</p></div>';
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Write with ChatGPT
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page('y4apk-gplay', __('Write with ChatGPT', 'y4apk'), __('Write with ChatGPT', 'y4apk'),
			'edit_posts', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function scripts() {
		if (!isset($_GET['page']) || $_GET['page'] !== self::SLUG) {
			return;
		}
		wp_enqueue_script('y4apk-writer', get_template_directory_uri() . '/assets/js/y4apk-writer.js', [], Y4APK_THEME_VERSION, true);
		wp_localize_script('y4apk-writer', 'y4apkWriter', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('y4apk-writer'),
			'text'  => [
				'writing'  => __('Writing %1$s of %2$s: %3$s', 'y4apk'),
				'done'     => __('Done: %1$s written, %2$s not.', 'y4apk'),
				'stopped'  => __('Stopped after %s.', 'y4apk'),
				'pick'     => __('Tick some posts first.', 'y4apk'),
				'parts'    => __('Tick at least one of Article, Icon and Screenshots.', 'y4apk'),
				'leave'    => __('Posts are still being written. Leave anyway?', 'y4apk'),
				'all'      => __('Add all %s posts of this list to the queue?', 'y4apk'),
				'clear'    => __('Empty the queue? Nothing that is already written changes.', 'y4apk'),
				'working'  => __('Working…', 'y4apk'),
				'undoAsk'  => __('Put back what this post had before ChatGPT wrote it?', 'y4apk'),
				'testOk'   => __('✓ Connected · model: %1$s · answered “%2$s”', 'y4apk'),
				'saved'    => __('Saved to the post.', 'y4apk'),
			],
		]);
	}

	private static function ago($t) {
		return sprintf(__('%s ago', 'y4apk'), human_time_diff((int) $t));
	}

	/** A post's line in the list: what ChatGPT last did to it, or that it waits in the queue. */
	public static function status_html($post_id) {
		$done = get_post_meta($post_id, self::DONE, true);
		$queued = in_array((int) $post_id, array_map(function ($i) { return (int) $i[0]; }, self::queue()), true);
		$out = '';
		if (is_array($done) && !empty($done['time'])) {
			$out .= '<span class="aw-ok">✓ ' . esc_html(sprintf(__('Written %1$s · %2$s words', 'y4apk'), self::ago($done['time']), number_format_i18n((int) $done['words']))) . '</span>';
		}
		if ($queued) {
			$out .= '<span class="aw-queued">' . esc_html__('In the queue', 'y4apk') . '</span>';
		}
		return $out !== '' ? $out : '<span class="au-meta">—</span>';
	}

	private static function styles() {
		Y4APK_App_Updates::styles();
		?>
		<style>
			.aw-bar { display: flex; align-items: center; gap: 10px 18px; flex-wrap: wrap; padding: 14px 18px; margin-bottom: 12px; position: sticky; top: 32px; z-index: 5; }
			.aw-bar .aw-parts { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; font-size: 13px; }
			.aw-bar .aw-parts strong { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--au-muted); }
			.aw-bar .aw-buttons { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
			.aw-progress { grid-column: 1 / -1; width: 100%; display: flex; align-items: center; gap: 12px; font-size: 13px; }
			.aw-progress .au-bar { flex: 1; height: 8px; }
			.aw-progress .au-bar > span { transition: width .3s; }
			.aw-row { display: grid; grid-template-columns: 22px minmax(260px, 1.6fr) minmax(180px, .9fr) minmax(200px, 1fr) auto; gap: 16px; padding: 14px 18px; align-items: center; }
			.aw-row.is-busy { border-color: var(--au-blue); box-shadow: 0 0 0 1px var(--au-blue); }
			.aw-row.is-good { border-color: #b8e6bf; }
			.aw-row.is-bad { border-color: #f4c6c7; }
			.aw-status { font-size: 12.5px; display: flex; flex-direction: column; gap: 3px; }
			.aw-ok { color: #007017; }
			.aw-queued { color: #8a6100; }
			.aw-msg { font-size: 12px; color: var(--au-muted); }
			.aw-msg.is-bad { color: var(--au-red); }
			.aw-actions { display: flex; gap: 6px; justify-content: flex-end; white-space: nowrap; }
			.aw-filters { display: flex; gap: 4px 14px; flex-wrap: wrap; align-items: center; font-size: 13px; }
			.aw-filters a { text-decoration: none; }
			.aw-filters a.is-on { font-weight: 600; color: var(--au-ink); }
			.aw-find { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 0; }
			.aw-find input[type=search] { min-width: 240px; border-radius: 8px; }
			.aw-settings textarea { width: 100%; min-height: 64px; border-radius: 8px; font-size: 13px; }
			.aw-settings input[type=text], .aw-settings input[type=password], .aw-settings select { width: 100%; max-width: 560px; border-radius: 6px; }
			.aw-settings .description { color: var(--au-muted); font-size: 12.5px; margin: 4px 0 10px; }
			.aw-steps { margin: 0 0 12px 18px; padding: 0; color: var(--au-ink); }
			.aw-steps li { margin: 0 0 6px; }
			.aw-try { display: flex; gap: 8px; flex-wrap: wrap; }
			.aw-try input { flex: 1; min-width: 240px; border-radius: 6px; }
			.aw-preview { margin-top: 14px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 14px; }
			.aw-preview pre { white-space: pre-wrap; word-break: break-word; background: var(--au-soft); border: 1px solid var(--au-line); border-radius: 8px; padding: 12px; font-size: 12.5px; max-height: 420px; overflow: auto; margin: 0; }
			.aw-article { border: 1px solid var(--au-line); border-radius: 8px; padding: 4px 16px; font-size: 14px; line-height: 1.65; }
			.aw-article h2 { font-size: 17px; line-height: 1.4; }
			.aw-log { display: flex; flex-direction: column; }
			.aw-log-row { display: grid; grid-template-columns: 130px minmax(200px, 1fr) minmax(200px, 1.4fr) auto; gap: 12px; padding: 10px 18px; border-top: 1px solid #f0f0f1; font-size: 13px; align-items: center; }
			.aw-log-row:first-child { border-top: 0; }
			.aw-paused { margin: 0 0 14px; padding: 10px 14px; border-radius: 8px; background: #fcf9e8; border: 1px solid #f0d77a; font-size: 13px; }
			@media (max-width: 1100px) {
				.aw-row { grid-template-columns: 22px 1fr 1fr; }
				.aw-row .aw-status, .aw-row .aw-actions { grid-column: 2 / -1; justify-content: flex-start; }
				.aw-preview { grid-template-columns: 1fr; }
				.aw-log-row { grid-template-columns: 1fr; gap: 2px; }
			}
		</style>
		<?php
	}

	public static function render_page() {
		$o = self::settings();
		$views = ['posts', 'queue', 'settings'];
		$view = isset($_GET['view']) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'posts';
		if ($view === 'settings' && !current_user_can('manage_options')) {
			$view = 'posts';
		}
		$queue = self::queue();
		$usage = wp_parse_args((array) get_option(self::USAGE, []), ['posts' => 0, 'in' => 0, 'out' => 0]);
		$models = $o['key'] !== '' ? get_transient('y4apk_writer_models') : false;
		$model = $o['key'] !== '' ? ($o['model'] !== 'auto' ? $o['model'] : (is_array($models) ? self::pick($models) : '')) : '';
		$short = count(self::short_ids());
		$written = self::count_done();
		$all = self::count_all();
		$per_hour = $o['per_run'] * 12;
		?>
		<div class="wrap y4apk-au y4apk-writer">
			<?php self::styles(); ?>
			<div class="au-head">
				<div>
					<h1><?php esc_html_e('Write with ChatGPT', 'y4apk'); ?></h1>
					<p><?php esc_html_e('Writes a post’s article the way you ask for it in ChatGPT, lays it out like your articles (name and MOD in bold, the closing heading), and brings the app’s icon and screenshots up to date from Google Play.', 'y4apk'); ?></p>
				</div>
				<div class="au-actions">
					<?php if ($o['key'] === '') : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('settings')); ?>"><span class="au-dot is-red"></span><?php esc_html_e('Not connected: add your OpenAI API key', 'y4apk'); ?></a>
					<?php elseif ($o['paused'] !== '' && $queue) : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('queue')); ?>"><span class="au-dot is-amber"></span><?php esc_html_e('Queue paused', 'y4apk'); ?></a>
					<?php else : ?>
						<span class="au-pill"><span class="au-dot"></span><?php echo esc_html($model !== '' ? sprintf(__('Connected · %s', 'y4apk'), $model) : __('API key saved', 'y4apk')); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php if (!empty($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'y4apk'); ?></p></div>
			<?php endif; ?>

			<div class="au-kpis">
				<a class="au-card au-kpi<?php echo $short ? ' is-alert' : ''; ?>" href="<?php echo esc_url(self::url('posts', ['show' => 'short'])); ?>">
					<span class="au-kpi-label"><?php esc_html_e('No article yet', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($short)); ?></span>
					<span class="au-kpi-sub"><?php printf(esc_html__('Under %s words of text', 'y4apk'), esc_html(number_format_i18n(self::SHORT))); ?></span>
				</a>
				<a class="au-card au-kpi" href="<?php echo esc_url(self::url('posts', ['show' => 'ai'])); ?>">
					<span class="au-kpi-label"><?php esc_html_e('Written by ChatGPT', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($written)); ?> <small>/ <?php echo esc_html(number_format_i18n($all)); ?></small></span>
					<span class="au-bar"><span style="width:<?php echo esc_attr($all ? round(100 * $written / $all, 1) : 0); ?>%"></span></span>
				</a>
				<a class="au-card au-kpi" href="<?php echo esc_url(self::url('queue')); ?>">
					<span class="au-kpi-label"><?php esc_html_e('In the queue', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n(count($queue))); ?></span>
					<span class="au-kpi-sub"><?php echo $queue
						? esc_html(sprintf(__('About %1$s at %2$s posts an hour', 'y4apk'), self::duration(count($queue) / $per_hour), $per_hour))
						: esc_html__('Nothing waiting', 'y4apk'); ?></span>
				</a>
				<div class="au-card au-kpi">
					<span class="au-kpi-label"><?php esc_html_e('Used so far', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n(round(($usage['in'] + $usage['out']) / 1000))); ?>K <small><?php esc_html_e('tokens', 'y4apk'); ?></small></span>
					<span class="au-kpi-sub"><?php printf(esc_html__('%1$s articles · %2$s in, %3$s out', 'y4apk'), esc_html(number_format_i18n($usage['posts'])), esc_html(number_format_i18n($usage['in'])), esc_html(number_format_i18n($usage['out']))); ?></span>
				</div>
			</div>

			<nav class="au-tabs">
				<a class="au-tab<?php echo $view === 'posts' ? ' is-on' : ''; ?>" href="<?php echo esc_url(self::url('posts')); ?>"><?php esc_html_e('Posts', 'y4apk'); ?></a>
				<a class="au-tab<?php echo $view === 'queue' ? ' is-on' : ''; ?>" href="<?php echo esc_url(self::url('queue')); ?>"><?php esc_html_e('Queue', 'y4apk'); ?><span class="au-count"><?php echo esc_html(number_format_i18n(count($queue))); ?></span></a>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="au-tab<?php echo $view === 'settings' ? ' is-on' : ''; ?>" href="<?php echo esc_url(self::url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a>
				<?php endif; ?>
			</nav>

			<?php
			if ($view === 'queue') {
				self::render_queue($o, $queue, $per_hour);
			} elseif ($view === 'settings') {
				self::render_settings($o, $models, $model);
			} else {
				self::render_posts($o, ['short' => $short, 'notai' => $all - $written, 'ai' => $written, 'all' => $all, 'queued' => count($queue)]);
			}
			?>
		</div>
		<?php
	}

	private static function duration($hours) {
		return $hours < 1 ? sprintf(_n('%s minute', '%s minutes', max(1, (int) round($hours * 60)), 'y4apk'), max(1, (int) round($hours * 60)))
			: ($hours < 48 ? sprintf(_n('%s hour', '%s hours', (int) ceil($hours), 'y4apk'), (int) ceil($hours))
			: sprintf(_n('%s day', '%s days', (int) ceil($hours / 24), 'y4apk'), (int) ceil($hours / 24)));
	}

	private static function render_posts($o, $counts) {
		list($show, $cat, $search) = self::filters();
		$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
		$q = new WP_Query(array_merge(self::query_args($show, $cat, $search), ['posts_per_page' => self::PER_PAGE, 'paged' => $paged]));
		$names = [
			'short'  => __('No article yet', 'y4apk'),
			'notai'  => __('Not written by ChatGPT', 'y4apk'),
			'ai'     => __('Written by ChatGPT', 'y4apk'),
			'queued' => __('In the queue', 'y4apk'),
			'all'    => __('All', 'y4apk'),
		];
		?>
		<div class="au-toolbar">
			<div class="aw-filters">
				<?php foreach ($names as $k => $label) : ?>
					<a class="<?php echo $show === $k ? 'is-on' : ''; ?>" href="<?php echo esc_url(self::url('posts', array_filter(['show' => $k, 'cat' => $cat, 's' => $search]))); ?>"><?php echo esc_html($label); ?> <span class="au-count"><?php echo esc_html(number_format_i18n($counts[$k])); ?></span></a>
				<?php endforeach; ?>
			</div>
			<form class="aw-find" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<input type="hidden" name="view" value="posts">
				<input type="hidden" name="show" value="<?php echo esc_attr($show); ?>">
				<?php wp_dropdown_categories(['show_option_all' => __('All categories', 'y4apk'), 'name' => 'cat', 'selected' => $cat, 'hierarchical' => true, 'hide_empty' => true, 'orderby' => 'name']); ?>
				<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search titles…', 'y4apk'); ?>">
				<button class="button"><?php esc_html_e('Filter', 'y4apk'); ?></button>
			</form>
		</div>

		<div class="au-card aw-bar" id="aw-bar" data-total="<?php echo esc_attr($q->found_posts); ?>">
			<div class="aw-parts">
				<strong><?php esc_html_e('Update', 'y4apk'); ?></strong>
				<label><input type="checkbox" class="aw-part" value="<?php echo esc_attr(self::ARTICLE); ?>" <?php checked((bool) ($o['parts'] & self::ARTICLE)); ?>> <?php esc_html_e('Article', 'y4apk'); ?></label>
				<label><input type="checkbox" class="aw-part" value="<?php echo esc_attr(self::ICONS); ?>" <?php checked((bool) ($o['parts'] & self::ICONS)); ?>> <?php esc_html_e('App icon (featured image)', 'y4apk'); ?></label>
				<label><input type="checkbox" class="aw-part" value="<?php echo esc_attr(self::SHOTS); ?>" <?php checked((bool) ($o['parts'] & self::SHOTS)); ?>> <?php esc_html_e('Screenshots', 'y4apk'); ?></label>
			</div>
			<div class="aw-buttons">
				<button type="button" class="button button-primary" id="aw-write" <?php disabled($o['key'] === ''); ?>><?php esc_html_e('Write selected now', 'y4apk'); ?></button>
				<button type="button" class="button" id="aw-queue" <?php disabled($o['key'] === ''); ?>><?php esc_html_e('Add selected to the queue', 'y4apk'); ?></button>
				<?php if ($q->found_posts) : ?>
					<button type="button" class="button" id="aw-queue-all" data-show="<?php echo esc_attr($show); ?>" data-cat="<?php echo esc_attr($cat); ?>" data-s="<?php echo esc_attr($search); ?>" <?php disabled($o['key'] === ''); ?>><?php printf(esc_html__('Queue all %s', 'y4apk'), esc_html(number_format_i18n($q->found_posts))); ?></button>
				<?php endif; ?>
			</div>
			<div class="aw-progress" id="aw-progress" hidden>
				<span class="au-bar"><span style="width:0%"></span></span>
				<span class="aw-progress-text"></span>
				<button type="button" class="button-link" id="aw-stop"><?php esc_html_e('Stop', 'y4apk'); ?></button>
			</div>
			<?php if ($o['key'] === '') : ?>
				<span class="au-meta"><?php printf(esc_html__('Connect ChatGPT first in %s.', 'y4apk'), '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'y4apk') . '</a>'); ?></span>
			<?php endif; ?>
		</div>

		<?php if (!$q->posts) : ?>
			<div class="au-card au-empty"><?php esc_html_e('No posts here.', 'y4apk'); ?></div>
			<?php return; ?>
		<?php endif; ?>

		<div class="au-toolbar">
			<label><input type="checkbox" id="aw-all"> <?php esc_html_e('Select all on this page', 'y4apk'); ?></label>
			<span class="au-toolbar-info"><?php printf(esc_html__('%1$s posts · page %2$s of %3$s', 'y4apk'), esc_html(number_format_i18n($q->found_posts)), esc_html(number_format_i18n($paged)), esc_html(number_format_i18n(max(1, $q->max_num_pages)))); ?></span>
		</div>
		<div class="au-list" id="aw-list">
			<?php foreach ($q->posts as $id) :
				$package = get_post_meta($id, 'wp_GP_ID', true);
				$words = self::words(get_post_field('post_content', $id, 'raw'));
				$cats = wp_list_pluck(get_the_category($id), 'name');
				$backup = get_post_meta($id, self::BACKUP, true); ?>
				<div class="au-card aw-row" data-id="<?php echo esc_attr($id); ?>" data-title="<?php echo esc_attr(get_the_title($id)); ?>">
					<input type="checkbox" class="aw-pick" value="<?php echo esc_attr($id); ?>" aria-label="<?php esc_attr_e('Select', 'y4apk'); ?>">
					<div class="au-app">
						<?php echo Y4APK_App_Updates::icon($id); ?>
						<div>
							<a class="au-title" href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a>
							<div class="au-links">
								<a href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank"><?php esc_html_e('View', 'y4apk'); ?></a>
								<?php if ($package) : ?><a class="au-google" href="<?php echo esc_url('https://play.google.com/store/apps/details?id=' . rawurlencode($package)); ?>" target="_blank" rel="noopener">Google Play</a><?php endif; ?>
							</div>
						</div>
					</div>
					<div class="au-chips">
						<span class="au-chip"><?php echo esc_html(sprintf(_n('%s word', '%s words', $words, 'y4apk'), number_format_i18n($words))); ?></span>
						<?php if (!$package) : ?><span class="au-chip"><?php esc_html_e('No Google Play app', 'y4apk'); ?></span><?php endif; ?>
						<?php if ($cats) : ?><span class="au-chip"><?php echo esc_html(implode(', ', array_slice($cats, 0, 2))); ?></span><?php endif; ?>
					</div>
					<div class="aw-status"><div class="aw-state"><?php echo self::status_html($id); ?></div><span class="aw-msg"></span></div>
					<div class="aw-actions">
						<button type="button" class="button aw-one" <?php disabled($o['key'] === ''); ?>><?php esc_html_e('Write now', 'y4apk'); ?></button>
						<button type="button" class="button-link aw-undo" <?php echo $backup ? '' : 'hidden'; ?>><?php esc_html_e('Undo', 'y4apk'); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="au-pages"><?php
			echo paginate_links([
				'base'    => add_query_arg('paged', '%#%', self::url('posts', array_filter(['show' => $show, 'cat' => $cat, 's' => $search]))),
				'format'  => '',
				'current' => $paged,
				'total'   => max(1, $q->max_num_pages),
			]); ?></div>
		<p class="au-note"><?php esc_html_e('“Write now” replaces the post’s text with the new article (about a minute each). Undo puts back what was there, as long as it has not been changed since. Posts with no Google Play app get the article only.', 'y4apk'); ?></p>
		<?php
	}

	private static function render_queue($o, $queue, $per_hour) {
		$log = get_option(self::LOG, []);
		$log = is_array($log) ? $log : [];
		$next = wp_next_scheduled(self::HOOK);
		?>
		<?php if ($o['paused'] !== '' && $queue) : ?>
			<p class="aw-paused"><strong><?php esc_html_e('Paused:', 'y4apk'); ?></strong> <?php echo esc_html($o['paused']); ?></p>
		<?php endif; ?>
		<div class="au-card au-pad" style="margin-bottom:16px">
			<div class="au-toolbar" style="margin:0">
				<div>
					<strong><?php echo esc_html(sprintf(_n('%s post waiting', '%s posts waiting', count($queue), 'y4apk'), number_format_i18n(count($queue)))); ?></strong>
					<span class="au-small"><?php
						if (!$queue) {
							esc_html_e('Add posts from the Posts tab, or with “Write with ChatGPT (queue)” in Posts ▸ Bulk actions.', 'y4apk');
						} elseif ($o['paused'] !== '') {
							esc_html_e('Nothing is written while the queue is paused.', 'y4apk');
						} else {
							echo esc_html(sprintf(__('Written in the background, %1$s posts every 5 minutes: done in about %2$s.', 'y4apk'), $o['per_run'], self::duration(count($queue) / $per_hour)));
							if ($next) {
								echo ' ' . esc_html(sprintf(__('Next round in %s.', 'y4apk'), human_time_diff(time(), $next)));
							}
						} ?></span>
				</div>
				<?php if (current_user_can('manage_options')) : ?>
				<div class="au-actions">
					<label><?php esc_html_e('Posts every 5 minutes', 'y4apk'); ?>
						<select id="aw-speed">
							<?php foreach ([1, 2, 3, 5, 8, 10] as $n) : ?>
								<option value="<?php echo esc_attr($n); ?>" <?php selected($o['per_run'], $n); ?>><?php echo esc_html($n); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php if ($queue) : ?>
						<?php if ($o['paused'] !== '') : ?>
							<button type="button" class="button button-primary aw-q" data-do="resume"><?php esc_html_e('Resume', 'y4apk'); ?></button>
						<?php else : ?>
							<button type="button" class="button aw-q" data-do="run"><?php esc_html_e('Run a round now', 'y4apk'); ?></button>
							<button type="button" class="button aw-q" data-do="pause"><?php esc_html_e('Pause', 'y4apk'); ?></button>
						<?php endif; ?>
						<button type="button" class="button-link aw-q" data-do="clear" style="color:var(--au-red)"><?php esc_html_e('Empty the queue', 'y4apk'); ?></button>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<h2 style="font-size:15px;margin:0 0 10px"><?php esc_html_e('Latest results', 'y4apk'); ?></h2>
		<?php if (!$log) : ?>
			<div class="au-card au-empty"><?php esc_html_e('Nothing written yet.', 'y4apk'); ?></div>
		<?php else : ?>
			<div class="au-card aw-log">
				<?php foreach ($log as $row) : $title = get_the_title($row['id']); ?>
					<div class="aw-log-row">
						<span class="au-meta"><?php echo esc_html(self::ago($row['t'])); ?></span>
						<span><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><?php echo esc_html($title !== '' ? $title : '#' . $row['id']); ?></a></span>
						<span class="<?php echo $row['ok'] ? 'aw-ok' : 'aw-msg is-bad'; ?>"><?php echo esc_html(($row['ok'] ? '✓ ' : '✗ ') . $row['m']); ?></span>
						<span><a href="<?php echo esc_url(get_permalink($row['id'])); ?>" target="_blank"><?php esc_html_e('View', 'y4apk'); ?></a></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif;
	}

	private static function render_settings($o, $models, $model) {
		$from_config = defined('Y4APK_OPENAI_KEY') && Y4APK_OPENAI_KEY;
		?>
		<div class="au-settings aw-settings">
			<form class="au-card au-pad" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="grid-column:1/-1">
				<input type="hidden" name="action" value="y4apk_writer_settings">
				<?php wp_nonce_field('y4apk-writer-settings'); ?>
				<div class="au-settings">
					<div>
						<h2><?php esc_html_e('Connect ChatGPT', 'y4apk'); ?></h2>
						<p><?php esc_html_e('The site talks to ChatGPT through OpenAI’s API. It is paid by use, separately from a ChatGPT plan (Free or Plus do not include it):', 'y4apk'); ?></p>
						<ol class="aw-steps">
							<li><?php printf(esc_html__('Sign in at %s (your ChatGPT login works).', 'y4apk'), '<a href="https://platform.openai.com/" target="_blank" rel="noopener">platform.openai.com</a>'); ?></li>
							<li><?php esc_html_e('Settings ▸ Billing: add credit ($5 is plenty to start; a short article costs well under a cent with a “mini” model).', 'y4apk'); ?></li>
							<li><?php esc_html_e('API keys ▸ Create new secret key, copy it, paste it below and Save, then Test.', 'y4apk'); ?></li>
						</ol>
						<label class="au-label" for="aw-key"><?php esc_html_e('OpenAI API key', 'y4apk'); ?></label>
						<?php if ($from_config) : ?>
							<p class="description"><?php esc_html_e('Set in wp-config.php (Y4APK_OPENAI_KEY).', 'y4apk'); ?></p>
						<?php else : ?>
							<input type="password" id="aw-key" name="key" autocomplete="off" placeholder="<?php echo esc_attr($o['key'] !== '' ? '•••• ' . substr($o['key'], -4) . ' — ' . __('saved; leave empty to keep it', 'y4apk') : 'sk-…'); ?>">
							<?php if ($o['key'] !== '') : ?><label><input type="checkbox" name="forget_key" value="1"> <?php esc_html_e('Remove the saved key', 'y4apk'); ?></label><?php endif; ?>
						<?php endif; ?>
						<label class="au-label" for="aw-model" style="margin-top:12px"><?php esc_html_e('Model', 'y4apk'); ?></label>
						<select id="aw-model" name="model">
							<option value="auto" <?php selected($o['model'], 'auto'); ?>><?php echo esc_html(sprintf(__('Automatic: the newest “mini”%s', 'y4apk'), $o['model'] === 'auto' && $model !== '' ? ' (' . $model . ')' : '')); ?></option>
							<?php foreach (is_array($models) ? $models : [] as $m) : ?>
								<option value="<?php echo esc_attr($m); ?>" <?php selected($o['model'], $m); ?>><?php echo esc_html($m); ?></option>
							<?php endforeach; ?>
							<?php if ($o['model'] !== 'auto' && (!is_array($models) || !in_array($o['model'], $models, true))) : ?>
								<option value="<?php echo esc_attr($o['model']); ?>" selected><?php echo esc_html($o['model']); ?></option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e('Test fills this list with the models your account has.', 'y4apk'); ?></p>
						<p><button type="button" class="button" id="aw-test" <?php disabled($o['key'] === ''); ?>><?php esc_html_e('Test the connection', 'y4apk'); ?></button> <span id="aw-test-result" class="au-meta"></span></p>
					</div>
					<div>
						<h2><?php esc_html_e('What to ask', 'y4apk'); ?></h2>
						<label class="au-label" for="aw-prompt"><?php esc_html_e('For games and apps', 'y4apk'); ?></label>
						<textarea id="aw-prompt" name="prompt" rows="3"><?php echo esc_textarea($o['prompt']); ?></textarea>
						<label class="au-label" for="aw-prompt-other"><?php esc_html_e('For other posts (no APK, e.g. Roblox scripts)', 'y4apk'); ?></label>
						<textarea id="aw-prompt-other" name="prompt_other" rows="3"><?php echo esc_textarea($o['prompt_other']); ?></textarea>
						<p class="description"><?php esc_html_e('{name} the app’s name · {type} เกม or แอป · {type_en} game or app · {mod} the MOD · {category} · {developer}', 'y4apk'); ?></p>
						<label><input type="checkbox" name="facts" value="1" <?php checked($o['facts']); ?>> <?php esc_html_e('Give ChatGPT the app’s Google Play description to go on (so it does not guess), asking it not to copy it', 'y4apk'); ?></label>
						<h2 style="margin-top:16px"><?php esc_html_e('How it is laid out', 'y4apk'); ?></h2>
						<label><input type="checkbox" name="link_name" value="1" <?php checked($o['link_name']); ?>> <?php esc_html_e('Link the app’s name (first words, in bold with its MOD) to its Google Play page', 'y4apk'); ?></label>
						<label><input type="checkbox" name="link_type" value="1" <?php checked($o['link_type']); ?>> <?php esc_html_e('Link the word “game” (or “app”) after the first paragraph to the post’s category', 'y4apk'); ?></label>
						<label class="au-label" for="aw-heading" style="margin-top:10px"><?php esc_html_e('Closing heading (games and apps; empty: none)', 'y4apk'); ?></label>
						<input type="text" id="aw-heading" name="heading" value="<?php echo esc_attr($o['heading']); ?>">
					</div>
				</div>
				<p style="margin:16px 0 0"><button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
			</form>

			<div class="au-card au-pad" style="grid-column:1/-1">
				<h2><?php esc_html_e('Try it on one post', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Shows exactly what ChatGPT is asked and what the article would look like, without changing the post.', 'y4apk'); ?></p>
				<div class="aw-try">
					<input type="text" id="aw-try-ref" placeholder="<?php esc_attr_e('Post ID, address, or words from its title', 'y4apk'); ?>">
					<button type="button" class="button" id="aw-try" <?php disabled($o['key'] === ''); ?>><?php esc_html_e('Write a preview', 'y4apk'); ?></button>
				</div>
				<div id="aw-try-out" hidden>
					<div class="aw-preview">
						<div>
							<span class="au-label"><?php esc_html_e('Asked', 'y4apk'); ?></span>
							<pre class="aw-try-prompt"></pre>
						</div>
						<div>
							<span class="au-label"><?php esc_html_e('Article', 'y4apk'); ?> <span class="aw-try-meta"></span></span>
							<div class="aw-article"></div>
							<p><button type="button" class="button button-primary" id="aw-apply"><?php esc_html_e('Use this text for the post', 'y4apk'); ?></button> <span id="aw-apply-result" class="au-meta"></span></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

Y4APK_Writer::init();
