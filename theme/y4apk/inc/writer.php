<?php
/**
 * Y4APK ▸ Write with AI: writes a post's article with Gemini (Google) or Groq —
 * both have free tiers — or ChatGPT (OpenAI) or Claude (Anthropic), asked the
 * way it is asked in ChatGPT ("ช่วยเขียนบทความของเกม Tasty Blue ไม่ต้องมีหัวข้อ
 * ไม่ยาว เป็นภาษาอังกฤษ"), with the app's Google Play details to go on, and lays
 * it out like the site's articles:
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
 * minutes; when a free tier's limit is reached, the queue waits for it). Each
 * write keeps what it replaced, so it can be undone as long as nobody has
 * changed that part of the post since.
 *
 * Gemini is reached through its own generateContent API (AI Studio's new "AQ."
 * keys do not work on its OpenAI-compatible endpoint), Groq through its
 * OpenAI-compatible one, Claude through the Messages API — all with
 * wp_remote_request(), as WordPress code does (the theme runs on PHP 7.4
 * hosting with no Composer).
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
	const WAIT     = 'y4apk_writer_wait';   // ['until', 'why']: the AI's limit was reached
	const HOOK     = 'y4apk_writer_tick';
	const DONE     = '_y4apk_ai';           // ['time', 'provider', 'model', 'words'] of the last article written
	const BACKUP   = '_y4apk_ai_backup';    // per part: ['before', 'after', 'time'], for Undo
	const ICON     = '_y4apk_icon_src';     // the Play icon the featured image was made from
	const SHORT    = 40;                    // fewer words than this: no article yet
	const PER_PAGE = 50;
	const ARTICLE  = 1;
	const ICONS    = 2;
	const SHOTS    = 4;
	const DETAILS  = 8;   // title, version, MOD, price, App Information, download folder, focus keyword
	const CATEGORY = 16;  // Games/Apps and the genre, as Google Play has them
	const PARTS    = 31;
	const SCREENS  = 5;   // screenshots kept from Google Play
	const MOD_AUTO  = '_y4apk_mod_auto';  // the App MOD Feature written in by Write with AI (replaced when a better one is found)
	const MOD_FOUND = '_y4apk_mod_found'; // … when it was found by the AI (not Settings')
	const SEO_AUTO  = '_y4apk_seo_title'; // the Rank Math SEO title written in by Write with AI
	const SEO_MAX   = 60;                 // characters Google shows of a title
	const DB        = 'y4apk_writer_db';  // the version posts written here were last brought up to
	const DESC_AUTO = '_y4apk_seo_desc';  // the Rank Math SEO description written in by Write with AI
	const GONE      = '_y4apk_gone';      // when the app was found gone from Google Play (its ID shows nothing, and no new listing)

	public static function init() {
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		if (!is_admin()) {
			return;
		}
		add_action('admin_init', [__CLASS__, 'schedule']);
		add_action('admin_init', [__CLASS__, 'migrate']);
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

	/** The services that can write. url: the API; keys: where to make a key; const: the wp-config.php alternative. */
	public static function providers() {
		return [
			'gemini' => ['name' => 'Gemini', 'by' => 'Google', 'free' => true, 'url' => 'https://generativelanguage.googleapis.com/v1beta',
				'keys' => 'https://aistudio.google.com/apikey', 'limits' => 'https://aistudio.google.com/rate-limit', 'const' => 'Y4APK_GEMINI_KEY'],
			'groq'   => ['name' => 'Groq', 'by' => 'Groq', 'free' => true, 'url' => 'https://api.groq.com/openai/v1',
				'keys' => 'https://console.groq.com/keys', 'limits' => 'https://console.groq.com/settings/limits', 'const' => 'Y4APK_GROQ_KEY'],
			'openai' => ['name' => 'ChatGPT', 'by' => 'OpenAI', 'free' => false, 'url' => 'https://api.openai.com/v1',
				'keys' => 'https://platform.openai.com/api-keys', 'limits' => 'https://platform.openai.com/settings/organization/billing', 'const' => 'Y4APK_OPENAI_KEY'],
			'claude' => ['name' => 'Claude', 'by' => 'Anthropic', 'free' => false, 'url' => 'https://api.anthropic.com/v1',
				'keys' => 'https://platform.claude.com/', 'limits' => 'https://platform.claude.com/', 'const' => 'Y4APK_CLAUDE_KEY'],
		];
	}

	/** Claude's current models, for the list before Test has read the account's (≈ cost of 1,000 short articles). */
	public static function claude_models() {
		return [
			'claude-opus-5-5'   => __('Claude Opus 5.5 — the most capable (≈ $14 per 1,000 articles)', 'y4apk'),
			'claude-sonnet-5-5' => __('Claude Sonnet 5.5 (≈ $7 per 1,000 articles)', 'y4apk'),
			'claude-haiku-5-5'  => __('Claude Haiku 5.5 — the cheapest (≈ $0.40 per 1,000 articles)', 'y4apk'),
		];
	}

	public static function defaults() {
		return [
			'provider'     => '',
			'keys'         => [],
			'models'       => [],
			'prompt'       => 'ช่วยเขียนบทความของ{type} {name} ไม่ต้องมีหัวข้อ ไม่ยาว เป็นภาษาอังกฤษ',
			'prompt_other' => 'ช่วยเขียนบทความของ {name} ไม่ต้องมีหัวข้อ ไม่ยาว เป็นภาษาอังกฤษ',
			'heading'      => '{name} MOD APK ({mod}) For Android - Download MOD APK from Y4Apk Free and Premium.',
			'title'        => '{name} MOD APK v{version} ({mod})',
			'mod_default'  => (string) get_option('at_mod_feature', '') !== '' ? (string) get_option('at_mod_feature') : 'Unlimited Money', // games
			'mod_default_app' => 'Premium Unlocked',
			'mod_lookup'   => 1,
			'seo_title'    => '{name} MOD APK v{version} ({mod}) Download',
			'seo_on'       => 1,
			'slug_on'      => 1,
			'match'        => 1, // the post title is Google's title; the article and icon use the same (short) name
			'link_self'    => 1,
			'fallback'     => 1, // an AI at its limit: go on with the next one that has a key (free ones)
			'fallback_paid' => 0, // … and the paid ones too
			'facts'        => 1,
			'link_name'    => 1,
			'link_type'    => 1,
			'parts'        => self::ARTICLE | self::ICONS | self::SHOTS | self::DETAILS,
			'per_run'      => 3,
			'paused'       => '',
		];
	}

	/** The settings; 'key' and 'model' are the chosen service's. */
	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], self::defaults());
		$o['keys'] = is_array($o['keys']) ? $o['keys'] : [];
		$o['models'] = is_array($o['models']) ? $o['models'] : [];
		// 2.23 had one service, OpenAI: its key and model.
		if (!empty($o['key']) && empty($o['keys']['openai'])) {
			$o['keys']['openai'] = $o['key'];
		}
		if (!empty($o['model']) && empty($o['models']['openai'])) {
			$o['models']['openai'] = $o['model'];
		}
		foreach (self::providers() as $p => $info) {
			// A key may also live in wp-config.php, out of the database.
			if (defined($info['const']) && constant($info['const'])) {
				$o['keys'][$p] = (string) constant($info['const']);
			}
			$o['keys'][$p] = isset($o['keys'][$p]) ? trim((string) $o['keys'][$p]) : '';
			$o['models'][$p] = !empty($o['models'][$p]) ? (string) $o['models'][$p] : ($p === 'claude' ? 'claude-opus-5-5' : 'auto');
		}
		if (!isset(self::providers()[$o['provider']])) {
			$o['provider'] = $o['keys']['openai'] !== '' ? 'openai' : 'gemini';
		}
		$o['key'] = $o['keys'][$o['provider']];
		$o['model'] = $o['models'][$o['provider']];
		$o['per_run'] = max(1, min(10, (int) $o['per_run']));
		$o['parts'] = (int) $o['parts'] & self::PARTS;
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
		$now = self::settings();
		$o = [
			'provider'     => isset($in['provider'], self::providers()[$in['provider']]) ? (string) $in['provider'] : $now['provider'],
			'keys'         => [],
			'models'       => [],
			'prompt'       => trim(sanitize_textarea_field(isset($in['prompt']) ? $in['prompt'] : '')),
			'prompt_other' => trim(sanitize_textarea_field(isset($in['prompt_other']) ? $in['prompt_other'] : '')),
			'heading'      => trim(sanitize_text_field(isset($in['heading']) ? $in['heading'] : '')),
			'title'        => trim(sanitize_text_field(isset($in['title']) ? $in['title'] : '')),
			'mod_default'  => trim(sanitize_text_field(isset($in['mod_default']) ? $in['mod_default'] : '')),
			'mod_default_app' => trim(sanitize_text_field(isset($in['mod_default_app']) ? $in['mod_default_app'] : '')),
			'mod_lookup'   => empty($in['mod_lookup']) ? 0 : 1,
			'seo_title'    => trim(sanitize_text_field(isset($in['seo_title']) ? $in['seo_title'] : '')),
			'seo_on'       => empty($in['seo_on']) ? 0 : 1,
			'slug_on'      => empty($in['slug_on']) ? 0 : 1,
			'match'        => empty($in['match']) ? 0 : 1,
			'link_self'    => empty($in['link_self']) ? 0 : 1,
			'fallback'     => empty($in['fallback']) ? 0 : 1,
			'fallback_paid' => empty($in['fallback_paid']) ? 0 : 1,
			'facts'        => empty($in['facts']) ? 0 : 1,
			'link_name'    => empty($in['link_name']) ? 0 : 1,
			'link_type'    => empty($in['link_type']) ? 0 : 1,
			'key'          => '', // 2.23's single key now lives in keys[openai]
			'model'        => '',
		];
		$saved = get_option(self::OPTION, []);
		foreach (self::providers() as $p => $info) {
			$old = isset($saved['keys'][$p]) ? (string) $saved['keys'][$p] : ($p === 'openai' && !empty($saved['key']) ? (string) $saved['key'] : '');
			$new = isset($in['keys'][$p]) ? trim((string) $in['keys'][$p]) : '';
			$o['keys'][$p] = !empty($in['forget'][$p]) ? '' : ($new !== '' ? $new : $old); // left empty: keep the saved one
			if ($new !== '' || !empty($in['forget'][$p])) {
				delete_transient('y4apk_writer_models_' . $p);
				delete_transient(self::WAIT . '_' . $p); // a new key (one with billing, say): asked again at once
			}
			$model = isset($in['models'][$p]) ? preg_replace('/[^A-Za-z0-9._:\/\-]/', '', (string) $in['models'][$p]) : '';
			$o['models'][$p] = $model !== '' ? $model : $now['models'][$p];
		}
		foreach (['prompt', 'prompt_other', 'title', 'seo_title'] as $k) {
			if ($o[$k] === '') {
				$o[$k] = self::defaults()[$k];
			}
		}
		self::save_settings($o);
		delete_transient(self::WAIT); // another AI chosen, or a new key: the queue goes on at once (each AI keeps its own wait)
		wp_safe_redirect(self::url('settings', ['saved' => 1]));
		exit;
	}

	public static function url($view = 'posts', $args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG, 'view' => $view], $args), admin_url('admin.php'));
	}

	public static function provider_name($p) {
		$all = self::providers();
		return isset($all[$p]) ? $all[$p]['name'] : $p;
	}

	/* ---------------------------------------------------------------- *
	 * The AI services
	 * ---------------------------------------------------------------- */

	/**
	 * The service's JSON answer, or a WP_Error coded busy (its limit or load:
	 * data 'wait' says how long) | key | credit | model | api.
	 */
	private static function api($provider, $method, $path, $body = null, $timeout = 90, $headers = []) {
		$info = self::providers()[$provider];
		$key = self::settings()['keys'][$provider];
		if ($key === '') {
			return new WP_Error('key', sprintf(__('Add your %s API key in Settings first.', 'y4apk'), $info['name']));
		}
		if ($provider === 'claude') {
			$headers = array_merge(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], $headers);
		} elseif ($provider === 'gemini') {
			$headers = array_merge(['x-goog-api-key' => $key], $headers); // "AQ." and "AIza" keys alike
		} else {
			$headers = array_merge(['Authorization' => 'Bearer ' . $key], $headers);
		}
		$args = ['method' => $method, 'timeout' => $timeout, 'headers' => $headers + ['Content-Type' => 'application/json']];
		if ($body !== null) {
			$args['body'] = wp_json_encode($body);
		}
		$r = wp_remote_request($info['url'] . $path, $args);
		if (is_wp_error($r)) {
			return new WP_Error('busy', sprintf(__('Could not reach %1$s: %2$s', 'y4apk'), $info['name'], $r->get_error_message()), ['wait' => 300]);
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		$json = json_decode((string) wp_remote_retrieve_body($r), true);
		if ($code >= 200 && $code < 300 && is_array($json)) {
			return $json;
		}
		// Errors come as {error: {message, type, code, param}}, Gemini's as [{error: {code, message, status}}],
		// Claude's as {type: "error", error: {type, message}}.
		if (isset($json[0]['error'])) {
			$json = $json[0];
		}
		$err = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
		$msg = isset($err['message']) ? (string) $err['message'] : 'HTTP ' . $code;
		$what = strtolower(implode(' ', array_filter([
			isset($err['code']) && is_string($err['code']) ? $err['code'] : '',
			isset($err['type']) ? (string) $err['type'] : '',
			isset($err['status']) ? (string) $err['status'] : '',
		])));
		$model = isset($body['model']) ? $body['model'] : (preg_match('~/models/([^:/?]+)~', $path, $mm) ? rawurldecode($mm[1]) : '');
		if ($code === 401 || preg_match('/invalid_api_key|authentication_error|permission_denied/', $what) || preg_match('/api key (not valid|expired)|invalid api key|incorrect api key/i', $msg)) {
			return new WP_Error('key', sprintf(__('%s did not accept the API key. Check it in Settings.', 'y4apk'), $info['name']));
		}
		if ($code === 402 || preg_match('/insufficient_quota|billing_error/', $what) || stripos($msg, 'credit balance') !== false) {
			return new WP_Error('credit', sprintf(__('The %1$s account has no credit left: add some at %2$s.', 'y4apk'), $info['name'], preg_replace('~^https?://|/$~', '', $info['limits'])));
		}
		if ($code === 429 || $code >= 500) {
			$wait = (int) wp_remote_retrieve_header($r, 'retry-after');
			if (!$wait && preg_match('/retry in ([\d.]+)\s*s/i', $msg, $m)) {
				$wait = (int) ceil((float) $m[1]);
			}
			// A free tier's daily limit: no use asking again before it resets.
			if ($code === 429 && preg_match('/per ?day|perday|\(rpd\)|\(tpd\)|daily/i', $msg . ' ' . wp_json_encode($err))) {
				$wait = max($wait, HOUR_IN_SECONDS);
				$msg = sprintf(__('%s’s daily limit is reached; the queue waits for it.', 'y4apk'), $info['name']) . ' ' . $msg;
			}
			return new WP_Error('busy', sprintf(__('%1$s is busy: %2$s', 'y4apk'), $info['name'], mb_substr($msg, 0, 300)), ['wait' => max(20, min(DAY_IN_SECONDS, $wait ?: 60)), 'status' => $code]);
		}
		if ($code === 404 || preg_match('/model_not_found|not_found/', $what)) {
			return new WP_Error('model', sprintf(__('This %1$s account cannot use the model “%2$s”. Choose another in Settings.', 'y4apk'), $info['name'], $model));
		}
		return new WP_Error('api', sprintf(__('%1$s: %2$s', 'y4apk'), $info['name'], mb_substr($msg, 0, 300)), ['param' => isset($err['param']) ? (string) $err['param'] : '']);
	}

	/** The text models a service's account has (ids, sorted), kept for a day. */
	public static function models($provider, $fresh = false) {
		$cached = get_transient('y4apk_writer_models_' . $provider);
		if (!$fresh && is_array($cached)) {
			return $cached;
		}
		$path = ['claude' => '/models?limit=100', 'gemini' => '/models?pageSize=1000'];
		$r = self::api($provider, 'GET', isset($path[$provider]) ? $path[$provider] : '/models', null, 20);
		if (is_wp_error($r)) {
			return $r;
		}
		$skip = [
			'openai' => '/audio|realtime|transcribe|tts|image|search|codex|embed|instruct|moderation|preview|chat-latest|\d{4}-?\d{2}-?\d{2}/',
			'gemini' => '/embed|tts|image|audio|live|computer|robotics|aqa|learnlm|gemma/',
			'groq'   => '/whisper|tts|guard|playai|orpheus|distil/',
			'claude' => '/^$/',
		];
		$want = ['openai' => '/^(gpt-\d|o\d)/', 'gemini' => '/^gemini-/', 'groq' => '/./', 'claude' => '/^claude-/'];
		$ids = [];
		// Gemini: {models: [{name: "models/…", supportedGenerationMethods}]}; the others: {data: [{id}]}.
		$list = isset($r['models']) ? (array) $r['models'] : (isset($r['data']) ? (array) $r['data'] : []);
		foreach ($list as $m) {
			if (isset($m['supportedGenerationMethods']) && !in_array('generateContent', (array) $m['supportedGenerationMethods'], true)) {
				continue;
			}
			$id = preg_replace('~^models/~', '', isset($m['id']) ? (string) $m['id'] : (isset($m['name']) ? (string) $m['name'] : ''));
			if (preg_match($want[$provider], $id) && !preg_match($skip[$provider], $id)) {
				$ids[] = $id;
			}
		}
		$ids = array_values(array_unique($ids));
		if ($provider !== 'claude') { // Claude lists its newest first
			natsort($ids);
			$ids = array_values($ids);
		}
		set_transient('y4apk_writer_models_' . $provider, $ids, DAY_IN_SECONDS);
		return $ids;
	}

	/**
	 * "Automatic": OpenAI's newest GPT mini; Gemini's newest Flash-Lite (the
	 * largest free daily allowance), else Flash; on Groq, gpt-oss 120B, else
	 * Llama 3.3 70B. Claude has no automatic choice (Claude Opus 5.5 by default).
	 */
	public static function pick($provider, $ids) {
		if ($provider === 'groq') {
			foreach (['openai/gpt-oss-120b', 'llama-3.3-70b-versatile', 'openai/gpt-oss-20b'] as $id) {
				if (in_array($id, $ids, true)) {
					return $id;
				}
			}
			return $ids ? $ids[0] : 'llama-3.3-70b-versatile';
		}
		if ($provider === 'gemini') {
			if (in_array('gemini-flash-lite-latest', $ids, true)) {
				return 'gemini-flash-lite-latest';
			}
			foreach (['flash-lite', 'flash'] as $kind) {
				$best = self::newest($ids, '/^gemini-(\d+(?:\.\d+)?)-' . $kind . '(-preview)?$/');
				if ($best !== '') {
					return $best;
				}
			}
			return 'gemini-flash-lite-latest';
		}
		$best = self::newest($ids, '/^gpt-(\d+(?:\.\d+)?)o?-mini$/');
		return $best !== '' ? $best : 'gpt-4o-mini';
	}

	/** The newest release (version number: group 1); a preview (group 2) only when there is no release. */
	private static function newest($ids, $pattern) {
		$best = '';
		$score = -1.0;
		foreach ($ids as $id) {
			if (preg_match($pattern, $id, $m)) {
				$s = (float) $m[1] + (empty($m[2]) ? 1000 : 0);
				if ($s > $score) {
					$best = $id;
					$score = $s;
				}
			}
		}
		return $best;
	}

	/** The model a service writes with: the one chosen in Settings, or the automatic pick. */
	public static function model($provider = null) {
		$o = self::settings();
		$provider = $provider ?: $o['provider'];
		$m = $o['models'][$provider];
		if ($m !== '' && $m !== 'auto') {
			return $m;
		}
		$ids = self::models($provider);
		return self::pick($provider, is_array($ids) ? $ids : []);
	}

	private static function count_usage($in, $out) {
		$u = get_option(self::USAGE, []);
		$u = wp_parse_args(is_array($u) ? $u : [], ['posts' => 0, 'in' => 0, 'out' => 0]);
		$u['in'] += (int) $in;
		$u['out'] += (int) $out;
		update_option(self::USAGE, $u, false);
	}

	/** The model's answer as text, or a WP_Error. */
	/** $search: let the AI search the web first, where it can (Gemini: Google Search; Claude: web search). */
	public static function chat($system, $user, $provider, $model, $max = 4000, $search = false) {
		if ($provider === 'claude') {
			return self::chat_claude($system, $user, $model, $search);
		}
		return $provider === 'gemini' ? self::chat_gemini($system, $user, $model, $search) : self::chat_compatible($system, $user, $provider, $model, $max);
	}

	/** Can this AI search the web while answering? */
	public static function searches($provider) {
		return $provider === 'claude' || $provider === 'gemini' && !get_transient(self::nosearch_key(self::model('gemini')));
	}

	/** Set for 6 hours when Google Search ran out on a Gemini model (or its free tier has none). */
	private static function nosearch_key($model) {
		return 'y4apk_writer_nosearch_' . md5((string) $model);
	}

	/** Said with the result: how the MOD was found when the search could not be used. */
	private static $note = '';

	/** Gemini: generateContent, with the key in x-goog-api-key. */
	private static function chat_gemini($system, $user, $model, $search = false) {
		$body = [
			'systemInstruction' => ['parts' => [['text' => $system]]],
			'contents'          => [['role' => 'user', 'parts' => [['text' => $user]]]],
			'generationConfig'  => ['maxOutputTokens' => 8192],
		];
		// Flash thinks by default; a little is plenty for a short article. Flash-Lite does not unless asked.
		if (strpos($model, 'flash-lite') === false) {
			$body['generationConfig']['thinkingConfig'] = preg_match('/^gemini-2\./', $model) ? ['thinkingBudget' => 512] : ['thinkingLevel' => 'low'];
		}
		if ($search && !get_transient(self::nosearch_key($model))) {
			$body['tools'] = [['google_search' => new stdClass()]]; // Grounding with Google Search
		}
		$path = '/models/' . rawurlencode($model) . ':generateContent';
		$dropped = false;
		for ($try = 0; $try < 3; $try++) {
			$r = self::api('gemini', 'POST', $path, $body);
			if (!is_wp_error($r) || !in_array($r->get_error_code(), ['api', 'busy'], true)) {
				break;
			}
			$m = strtolower($r->get_error_message());
			$data = $r->get_error_data();
			// Google Search has its own quota — none at all on some free tiers ("You exceeded your current
			// quota" at once): asked again without it, so the article is still written.
			if (isset($body['tools']) && (preg_match('/search|ground|tool/', $m) || isset($data['status']) && $data['status'] === 429)) {
				unset($body['tools']);
				$dropped = true;
			} elseif ($r->get_error_code() === 'api' && isset($body['generationConfig']['thinkingConfig']) && strpos($m, 'think') !== false) {
				unset($body['generationConfig']['thinkingConfig']); // a model that does not take it
			} else {
				break;
			}
		}
		if (is_wp_error($r)) {
			return $r;
		}
		if ($dropped) {
			set_transient(self::nosearch_key($model), 1, 6 * HOUR_IN_SECONDS); // no asking with it again for a while
			self::$note = __('Google Search is not in this Gemini key’s quota (free tier): the MOD is what Gemini knows; Settings’ MOD where it does not', 'y4apk');
		}
		$u = isset($r['usageMetadata']) ? (array) $r['usageMetadata'] : [];
		self::count_usage(isset($u['promptTokenCount']) ? $u['promptTokenCount'] : 0,
			(isset($u['candidatesTokenCount']) ? $u['candidatesTokenCount'] : 0) + (isset($u['thoughtsTokenCount']) ? $u['thoughtsTokenCount'] : 0));
		$cand = isset($r['candidates'][0]) ? $r['candidates'][0] : [];
		$finish = isset($cand['finishReason']) ? (string) $cand['finishReason'] : '';
		$text = '';
		foreach (isset($cand['content']['parts']) ? (array) $cand['content']['parts'] : [] as $part) {
			if (isset($part['text']) && empty($part['thought'])) {
				$text .= $part['text'];
			}
		}
		$text = trim($text);
		if ($text === '') {
			if (!empty($r['promptFeedback']['blockReason']) || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION', 'SPII'], true)) {
				return new WP_Error('refused', __('Gemini declined to write this one.', 'y4apk'));
			}
			return new WP_Error('api', $finish === 'MAX_TOKENS' ? __('The answer was cut off before it was done.', 'y4apk') : __('Gemini sent back an empty answer.', 'y4apk'));
		}
		return $text;
	}

	/** OpenAI, and Groq through its OpenAI-compatible endpoint. */
	private static function chat_compatible($system, $user, $provider, $model, $max) {
		$body = [
			'model'    => $model,
			'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
		];
		$body['max_completion_tokens'] = $max;
		// Reasoning models think first; a little is plenty for a short article.
		if (($provider === 'openai' && preg_match('/^(gpt-5|o\d)/', $model))
			|| ($provider === 'groq' && strpos($model, 'gpt-oss') !== false)) {
			$body['reasoning_effort'] = 'low';
		}
		$r = self::api($provider, 'POST', '/chat/completions', $body);
		if (is_wp_error($r) && $r->get_error_code() === 'api' && isset($body['reasoning_effort'])
			&& (stripos($r->get_error_message(), 'reasoning') !== false || (isset($r->get_error_data()['param']) && $r->get_error_data()['param'] === 'reasoning_effort'))) {
			unset($body['reasoning_effort']); // a model that does not take it
			$r = self::api($provider, 'POST', '/chat/completions', $body);
		}
		if (is_wp_error($r)) {
			return $r;
		}
		if (isset($r['usage']) && is_array($r['usage'])) {
			self::count_usage(isset($r['usage']['prompt_tokens']) ? $r['usage']['prompt_tokens'] : 0, isset($r['usage']['completion_tokens']) ? $r['usage']['completion_tokens'] : 0);
		}
		$choice = isset($r['choices'][0]) ? $r['choices'][0] : [];
		$text = isset($choice['message']['content']) ? trim((string) $choice['message']['content']) : '';
		if ($text === '') {
			return new WP_Error('api', isset($choice['finish_reason']) && $choice['finish_reason'] === 'length'
				? __('The answer was cut off before it was done.', 'y4apk')
				: sprintf(__('%s sent back an empty answer.', 'y4apk'), self::provider_name($provider)));
		}
		return $text;
	}

	/** Claude: the Messages API. */
	private static function chat_claude($system, $user, $model, $search = false) {
		$body = [
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => $system,
			'messages'      => [['role' => 'user', 'content' => $user]],
			'output_config' => ['effort' => 'low'], // a short article: little thinking needed
		];
		$headers = [];
		// If a safety classifier declines, the API writes it with Anthropic's recommended other model instead.
		if (preg_match('/^claude-(opus-5(-5)?|fable-5-1|sonnet-5-5)$/', $model)) {
			$body['fallbacks'] = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		if ($search) {
			// The dynamic-filtering web search on the models that have it, the basic one on the others.
			$type = preg_match('/^claude-(opus-(4-[6-9]|5)|sonnet-(4-6|5)|fable|mythos)/', $model) ? 'web_search_20260209' : 'web_search_20250305';
			$body['tools'] = [['type' => $type, 'name' => 'web_search', 'max_uses' => 3]];
		}
		for ($try = 0; $try < 4; $try++) {
			$r = self::api('claude', 'POST', '/messages', $body, 120, $headers);
			if (!is_wp_error($r) || $r->get_error_code() !== 'api') {
				break;
			}
			// A model, or an account, that does not take one of these: ask again without it.
			$m = strtolower($r->get_error_message());
			if (isset($body['tools']) && (strpos($m, 'web_search') !== false || strpos($m, 'tool') !== false)) {
				unset($body['tools']); // web search not turned on for this organization, or not on this model
			} elseif (isset($body['output_config']) && (strpos($m, 'effort') !== false || strpos($m, 'output_config') !== false)) {
				unset($body['output_config']);
			} elseif (isset($body['fallbacks']) && (strpos($m, 'fallback') !== false || strpos($m, 'anthropic-beta') !== false)) {
				unset($body['fallbacks'], $headers['anthropic-beta']);
			} else {
				break;
			}
		}
		$text = '';
		for ($turn = 0; ; $turn++) {
			if (is_wp_error($r)) {
				return $r;
			}
			if (isset($r['usage']) && is_array($r['usage'])) {
				self::count_usage(isset($r['usage']['input_tokens']) ? $r['usage']['input_tokens'] : 0, isset($r['usage']['output_tokens']) ? $r['usage']['output_tokens'] : 0);
			}
			$stop = isset($r['stop_reason']) ? (string) $r['stop_reason'] : '';
			if ($stop === 'refusal') {
				return new WP_Error('refused', __('Claude declined to write this one.', 'y4apk'));
			}
			foreach (isset($r['content']) ? (array) $r['content'] : [] as $block) {
				$type = isset($block['type']) ? $block['type'] : '';
				if ($type === 'server_tool_use' || $type === 'web_search_tool_result') {
					$text = ''; // "Let me search for it…": only what is written after the search is the answer
				} elseif ($type === 'text' && isset($block['text'])) {
					$text .= $block['text'];
				}
			}
			// A long search loop pauses: sent back as it is, the API carries on where it stopped.
			if ($stop !== 'pause_turn' || $turn >= 2 || empty($r['content'])) {
				break;
			}
			$body['messages'][] = ['role' => 'assistant', 'content' => $r['content']];
			$r = self::api('claude', 'POST', '/messages', $body, 120, $headers);
		}
		$text = trim($text);
		if ($text === '') {
			return new WP_Error('api', $stop === 'max_tokens' ? __('The answer was cut off before it was done.', 'y4apk') : __('Claude sent back an empty answer.', 'y4apk'));
		}
		return $text;
	}

	/** The queue waits until the AI's limit lets it go on. */
	/** The queue waits: every AI it may use is at its limit. */
	private static function wait($seconds, $why) {
		$seconds = max(20, (int) $seconds);
		set_transient(self::WAIT, ['until' => time() + $seconds, 'why' => (string) $why], $seconds);
	}

	/** One AI at its limit (or, after it, refusing its key): not asked until then. */
	private static function wait_for($provider, $seconds, $why) {
		$seconds = max(20, (int) $seconds);
		set_transient(self::WAIT . '_' . $provider, ['until' => time() + $seconds, 'why' => (string) $why], $seconds);
	}

	/** The queue's wait and every AI's own. */
	private static function clear_waits() {
		delete_transient(self::WAIT);
		foreach (array_keys(self::providers()) as $p) {
			delete_transient(self::WAIT . '_' . $p);
		}
	}

	public static function provider_wait($provider) {
		$w = get_transient(self::WAIT . '_' . $provider);
		return is_array($w) && $w['until'] > time() ? $w : null;
	}

	/** The AIs that write, in turn: the chosen one, then (Settings) the other free ones with a key, then the paid ones. */
	public static function order() {
		$o = self::settings();
		$list = [$o['provider']];
		if ($o['fallback']) {
			foreach ([true, false] as $free) {
				foreach (self::providers() as $p => $info) {
					if ($p !== $o['provider'] && $o['keys'][$p] !== '' && $info['free'] === $free && ($free || $o['fallback_paid'])) {
						$list[] = $p;
					}
				}
			}
		}
		return $list;
	}

	/** The AI that writes next ('' when every one is at its limit). */
	public static function current() {
		foreach (self::order() as $p) {
			if (!self::provider_wait($p)) {
				return $p;
			}
		}
		return '';
	}

	/**
	 * Asks the AIs in turn (order()): one at its limit waits on its own and the
	 * next one answers, so the queue goes on with Groq while Gemini's day is
	 * used up. $make($provider) gives [system, user]. [answer, provider, model],
	 * or a WP_Error — 'busy' (wait: the soonest) only when every one waits.
	 */
	private static function ask($make, $max = 4000, $search = false) {
		$chosen = self::settings()['provider'];
		$why = [];
		foreach (self::order() as $p) {
			if ($w = self::provider_wait($p)) {
				$why[] = self::provider_name($p) . ': ' . $w['why'];
				continue;
			}
			$model = self::model($p);
			list($system, $user) = $make($p);
			$answer = self::chat($system, $user, $p, $model, $max, $search);
			if (!is_wp_error($answer)) {
				return [$answer, $p, $model];
			}
			$code = $answer->get_error_code();
			if ($code === 'busy') {
				$data = $answer->get_error_data();
				self::wait_for($p, isset($data['wait']) ? $data['wait'] : 60, $answer->get_error_message());
			} elseif ($p !== $chosen && in_array($code, ['key', 'credit', 'model'], true)) {
				self::wait_for($p, DAY_IN_SECONDS, $answer->get_error_message()); // a stand-in that cannot write: not asked today
			} else {
				return $answer; // the chosen AI's key, or this post's own trouble
			}
			$why[] = self::provider_name($p) . ': ' . $answer->get_error_message();
		}
		$soonest = 0;
		foreach (self::order() as $p) {
			$w = self::provider_wait($p);
			$soonest = $w && (!$soonest || $w['until'] < $soonest) ? $w['until'] : $soonest;
		}
		return new WP_Error('busy', implode(' · ', array_unique($why)), ['wait' => max(20, $soonest - time())]);
	}

	public static function waiting() {
		$w = get_transient(self::WAIT);
		return is_array($w) && $w['until'] > time() ? $w : null;
	}

	/* ---------------------------------------------------------------- *
	 * The article
	 * ---------------------------------------------------------------- */

	/** The MOD in the post's words: App MOD Feature, else the download's MOD Info, else the title's "(…)". */
	public static function mod($post_id) {
		$mod = self::plain(get_post_meta($post_id, 'wp_mods', true));
		if ($mod === '') {
			list(, $link) = Y4APK_App_Updates::download($post_id);
			$mod = is_array($link) && isset($link['download_mod_info']) ? self::plain($link['download_mod_info']) : '';
		}
		if ($mod === '' && preg_match('/\(([^()]{2,80})\)/', self::plain(get_post_field('post_title', $post_id, 'raw')), $m)) {
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
		$title = self::plain(get_post_field('post_title', $post_id, 'raw'));
		$name = self::plain(get_post_meta($post_id, 'wp_title_GP', true)); // "Mint: Business &amp; Stock News" → "… & …", escaped once when written
		$name = $name !== '' ? $name : Y4APK_App_Updates::name_from_title($title);
		$package = Y4APK_App_Updates::package_from(get_post_meta($post_id, 'wp_GP_ID', true));
		if ($play === null) {
			$play = $package !== '' ? Y4APK_App_Updates::play_app($package) : ['status' => 'none'];
		}
		$on_play = $play['status'] === 'ok';
		list(, $download) = Y4APK_App_Updates::download($post_id);
		$top = self::top_category($post_id);
		$developer = self::plain(get_post_meta($post_id, 'wp_developers_GP', true));
		$apk = $package !== '' || ($download && Y4APK_App_Updates::is_apk($download));
		$mod = self::mod($post_id);
		$auto = self::auto_mod($post_id);
		$game = $on_play ? !empty($play['game']) : ($top && stripos($top->slug . ' ' . $top->name, 'game') !== false);
		$name = $name !== '' ? $name : $title;
		$short = self::short_name($name);
		return [
			'name'         => $name,
			'short'        => $short,
			'show'         => $apk && self::settings()['match'] ? $short : $name, // in the title, the article and the icon
			// No MOD written by you: the one found for the app, else Settings' for games or apps (see write_now()).
			'mod'          => $mod !== '' && $auto === '' || !$apk ? $mod : self::default_mod($game),
			'mod_saved'    => $mod !== '' && $auto === '',
			'mod_auto'     => $auto, // the MOD Write with AI put in before, to be replaced by a better one
			'apk'          => $apk,
			'game'         => $game,
			'category'     => $top ? self::plain($top->name) : '',
			'category_url' => $top ? get_category_link($top) : '',
			'developer'    => $developer !== '' ? $developer : ($on_play ? $play['developer'] : ''),
			'package'      => $package,
			'play_url'     => $package !== '' ? 'https://play.google.com/store/apps/details?id=' . $package : '',
			'on_play'      => $on_play,
			'play'         => $play,
		];
	}

	/**
	 * Puts right what 2.26.0–2.26.1 wrote, once, 200 posts per admin page:
	 * "&amp;amp;" in articles (a name the importer stored as "&amp;", escaped
	 * again); the old file's version in the download row and App Version of
	 * posts whose folder waits for its file, while the title has Google Play's
	 * latest; and one name everywhere (see match_post()).
	 */
	public static function migrate() {
		$from = (string) get_option(self::DB, '');
		if ($from === '2.29.0' || get_transient('y4apk_writer_migrating')) {
			return;
		}
		if ($from === '2.26.3') {
			// 2.29: the queue's wait was every AI's; now each AI waits on its own and the next one goes on.
			$w = self::waiting();
			if ($w) {
				self::wait_for(self::settings()['provider'], $w['until'] - time(), $w['why']);
				delete_transient(self::WAIT);
			}
			update_option(self::DB, '2.29.0', false);
			return;
		}
		$old = version_compare($from !== '' ? $from : '0', '2.26.2', '<'); // before 2.26.2: all of it; 2.26.2: the versions only
		set_transient('y4apk_writer_migrating', 1, 5 * MINUTE_IN_SECONDS);
		global $wpdb;
		$at = get_option(self::DB . '_at', false);
		if ($at === false && $old) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_content LIKE %s",
				self::DONE, '%' . $wpdb->esc_like('&amp;amp;') . '%'
			));
			foreach ($ids as $id) {
				self::set_content((int) $id, str_replace('&amp;amp;', '&amp;', get_post_field('post_content', $id, 'raw')));
			}
			$waiting = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", Y4APK_App_Updates::NO_FILE));
			foreach ($waiting as $w) {
				$id = (int) $w->post_id;
				$latest = Y4APK_App_Updates::number(get_post_meta($id, Y4APK_App_Updates::LATEST, true));
				list($i, $main) = Y4APK_App_Updates::download($id);
				$links = get_post_meta($id, 'repeatable_download_link', true);
				if ($latest === '' || !$main || !is_array($links) || !isset($links[$i]) || html_entity_decode((string) $main['download_url']) !== $w->meta_value) {
					continue;
				}
				$v = self::version_text($latest, isset($main['download_name']) ? $main['download_name'] : '');
				if ((string) $links[$i]['download_version'] !== $v) {
					$links[$i]['download_version'] = $v;
					update_post_meta($id, 'repeatable_download_link', $links);
				}
				if ((string) get_post_meta($id, 'wp_version_GP', true) !== $latest) {
					update_post_meta($id, 'wp_version_GP', $latest);
				}
			}
		}
		$at = (int) $at;
		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND post_id > %d ORDER BY post_id LIMIT 200", self::DONE, self::BACKUP, (int) $at
		)));
		foreach ($ids as $id) {
			if ($old) {
				self::match_post($id);
			}
			self::version_texts($id);
			$at = $id;
		}
		if (count($ids) < 200) {
			update_option(self::DB, '2.26.3', false); // the next admin page: 2.29's step
			delete_option(self::DB . '_at');
		} else {
			update_option(self::DB . '_at', $at, false); // the rest on the next admin page
		}
		delete_transient('y4apk_writer_migrating');
	}

	/** The download rows' versions as the site writes them: "368" → "v368.apk" (2.26.2 left whole numbers as they were). */
	private static function version_texts($id) {
		$links = get_post_meta($id, 'repeatable_download_link', true);
		if (!is_array($links)) {
			return;
		}
		$new = $links;
		foreach ($new as $k => $l) {
			if (is_array($l) && !empty($l['download_version'])) {
				$new[$k]['download_version'] = self::version_text($l['download_version'], isset($l['download_name']) ? $l['download_name'] : '');
			}
		}
		if ($new !== $links) {
			update_post_meta($id, 'repeatable_download_link', $new);
			$b = get_post_meta($id, self::BACKUP, true);
			if (isset($b['links']['after']) && $b['links']['after'] === $links) {
				$b['links']['after'] = $new; // Undo still knows them
				update_post_meta($id, self::BACKUP, wp_slash($b));
			}
		}
	}

	/** Saves a post's text as it is (no filters), Undo still knowing it. */
	private static function set_content($id, $new) {
		global $wpdb;
		$old = get_post_field('post_content', $id, 'raw');
		if ($new === $old) {
			return;
		}
		$wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => $id]);
		clean_post_cache($id);
		$b = get_post_meta($id, self::BACKUP, true);
		if (isset($b['content']['after']) && $b['content']['after'] === $old) {
			$b['content']['after'] = $new;
			update_post_meta($id, self::BACKUP, wp_slash($b));
		}
	}

	/**
	 * A post written by 2.26.0–2.26.1 with one name everywhere, as now: the
	 * title is Google's title ("Mint MOD APK v6.0.3 (Premium Unlocked) Download",
	 * where both were written here), the article's bold name, closing heading
	 * and icon say "Mint", and Rank Math gets its description.
	 */
	private static function match_post($id) {
		global $wpdb;
		if (!self::settings()['match']) {
			return;
		}
		$name = self::plain(get_post_meta($id, 'wp_title_GP', true));
		if ($name === '') {
			return;
		}
		$short = self::short_name($name);
		if ($short !== $name && get_post_meta($id, self::DONE, true)) {
			$c = get_post_field('post_content', $id, 'raw');
			$full = preg_quote(self::text($name), '~');
			$c = preg_replace('~(<strong>(?:<a [^>]*>)?)' . $full . '((?:</a>)? MOD APK)~u', '${1}' . self::text($short) . '$2', $c, 1);
			$c = preg_replace('~(<h2 class="wp-block-heading">)' . $full . '( MOD APK)~u', '${1}' . self::text($short) . '$2', $c, 1);
			self::set_content($id, $c);
			$thumb = (int) get_post_thumbnail_id($id);
			if ($thumb && get_post_meta($id, self::ICON, true)) {
				self::icon_text($thumb, ['show' => $short, 'mod' => self::mod($id)]);
			}
		}
		$b = get_post_meta($id, self::BACKUP, true);
		$title = (string) get_post_field('post_title', $id, 'raw');
		$seo = (string) get_post_meta($id, 'rank_math_title', true);
		if (isset($b['title']['after']) && $b['title']['after'] === $title && $seo !== '' && $seo === (string) get_post_meta($id, self::SEO_AUTO, true)) {
			$stored = trim(wp_strip_all_tags((string) get_post_meta($id, 'wp_title_GP', true))); // 2.26.0 wrote "&amp;" as it was stored
			foreach (array_unique([$name, $stored]) as $long) {
				if ($short !== $long && strpos($seo, $long . ' ') === 0) {
					$seo = $short . substr($seo, strlen($long));
					break;
				}
			}
			if ($seo !== (string) get_post_meta($id, 'rank_math_title', true)) {
				update_post_meta($id, 'rank_math_title', wp_slash($seo));
				update_post_meta($id, self::SEO_AUTO, wp_slash($seo));
			}
			if ($title !== $seo) {
				$wpdb->update($wpdb->posts, ['post_title' => $seo], ['ID' => $id]);
				clean_post_cache($id);
				$b['title']['after'] = $seo;
				update_post_meta($id, self::BACKUP, wp_slash($b));
			}
		}
		if (get_post_meta($id, self::DONE, true) && Y4APK_App_Updates::package_from(get_post_meta($id, 'wp_GP_ID', true)) !== '') {
			self::update_description($id);
		}
	}

	/** Text as it reads: no tags, "&amp;" → "&". */
	public static function plain($s) {
		return trim(html_entity_decode(wp_strip_all_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/** Settings' MOD for when none is found: games "Unlimited Money", apps "Premium Unlocked". */
	public static function default_mod($game) {
		$o = self::settings();
		return trim($game ? $o['mod_default'] : $o['mod_default_app']);
	}

	/**
	 * The App MOD Feature Write with AI filled in itself (2.25 marked none: its
	 * Undo copy shows App MOD Feature empty before and this after), '' when the
	 * one there is yours.
	 */
	public static function auto_mod($post_id) {
		$now = trim((string) get_post_meta($post_id, 'wp_mods', true));
		if ($now === '') {
			return '';
		}
		if ((string) get_post_meta($post_id, self::MOD_AUTO, true) === $now) {
			return $now;
		}
		$b = get_post_meta($post_id, self::BACKUP, true);
		if (!isset($b['meta']['before']['wp_mods'], $b['meta']['after']['wp_mods']) || $b['meta']['before']['wp_mods'] !== '' || $b['meta']['after']['wp_mods'] !== $now) {
			return '';
		}
		// 2.25 put in Settings' MOD — or the one from your title "(…)" or download row, which stays yours.
		$title = isset($b['title']['before']) ? (string) $b['title']['before'] : (string) get_post_field('post_title', $post_id, 'raw');
		if (stripos($title, '(' . $now . ')') !== false || stripos($title, '[' . $now . ']') !== false) {
			return '';
		}
		foreach (isset($b['links']['before']) ? (array) $b['links']['before'] : (array) get_post_meta($post_id, 'repeatable_download_link', true) as $l) {
			if (is_array($l) && isset($l['download_mod_info']) && trim((string) $l['download_mod_info']) === $now) {
				return '';
			}
		}
		return $now;
	}

	/**
	 * The name without its tagline, for titles that would run long and the
	 * permalink: "ActionDirector - Video Editing" → "ActionDirector"; a colon
	 * cuts only before a long tagline ("Spotify: Music and Podcasts" → "Spotify",
	 * but "Truck Simulator: Europe" stays).
	 */
	public static function short_name($name) {
		$name = trim((string) $name);
		$parts = preg_split('/\s+[-–—|]\s+/u', $name, 2);
		$short = trim($parts[0]);
		if (preg_match('/^(.{3,}?):\s+(\S+(?:\s+\S+){2,})$/u', $short, $m)) {
			$short = trim($m[1]);
		}
		return mb_strlen($short) >= 3 ? $short : $name;
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
	public static function prompt($ctx, $ask_mod = false, $provider = null) {
		$o = self::settings();
		$provider = $provider ? $provider : $o['provider'];
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
			. 'two paragraphs separated by one blank line. No title, no headings, no lists, no markdown, no emojis, no links. '
			. 'Begin the first paragraph with the exact words "' . $ctx['show'] . ' is".'
			. ($o['link_self'] && $ctx['short'] !== '' ? ' Mention ' . $ctx['short'] . ' once more in the second paragraph.' : '');
		if ($ask_mod) {
			$system .= ' After the article, add one more line: "MOD: " followed by the MOD feature that MOD APK download sites list for this '
				. ($ctx['game'] ? 'game' : 'app') . ', as they write it in their titles (for example "Premium Unlocked", "Unlimited Money" or "Menu, God Mode")'
				. (self::searches($provider) ? ', found with a web search' : ', from what you know') . '; write "MOD: unknown" if you cannot tell.';
		}
		return [$system, $user];
	}

	/** "Premium unlocked." → "Premium Unlocked"; '' for an answer that is not a short MOD label. */
	public static function clean_mod($text) {
		$s = trim(wp_strip_all_tags((string) $text), " \t\"'“”‘’`*[]().:;");
		if (mb_strlen($s) < 3 || mb_strlen($s) > 40 || preg_match('/unknown|not sure|n\/a|\bnone\b|no mod|cannot|can\'t|sorry|i don/i', $s)
			|| !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \/,&+\-.]*$/u', $s)) {
			return '';
		}
		return preg_replace_callback('/(^|[\s\/,&+\-])(\p{Ll})/u', function ($m) {
			return $m[1] . mb_strtoupper($m[2]);
		}, $s);
	}

	/** The answer without its "MOD: …" line, and the MOD on it: [text, mod]. */
	public static function take_mod($answer) {
		$mod = '';
		$text = preg_replace_callback('/^[ \t*_]*MOD[ \t*_]*:[ \t]*(.*?)[ \t*_]*$/mi', function ($m) use (&$mod) {
			$mod = self::clean_mod($m[1]);
			return '';
		}, (string) $answer);
		return [trim($text), $mod];
	}

	/** The MOD that MOD APK sites list for the app, asked on its own (when no article is written): '' when not found, or a WP_Error when the AI is busy. */
	public static function lookup_mod($ctx) {
		$asked = self::ask(function ($provider) use ($ctx) {
			return ['You answer with one short label only.', sprintf('Which MOD feature do MOD APK download sites list for the Android %1$s "%2$s"%3$s%4$s? %5$sReply with only the feature as such sites write it in their titles, for example "Premium Unlocked", "Unlimited Money" or "Menu, God Mode". If you cannot tell, reply "unknown".',
				$ctx['game'] ? 'game' : 'app', $ctx['name'],
				$ctx['play_url'] !== '' ? ' (' . preg_replace('~^.*id=~', '', $ctx['play_url']) . ')' : '',
				$ctx['developer'] !== '' ? ' by ' . $ctx['developer'] : '',
				self::searches($provider) ? 'Search the web for it. ' : '')];
		}, 1000, true);
		if (is_wp_error($asked)) {
			return $asked->get_error_code() === 'busy' ? $asked : '';
		}
		$answer = $asked[0];
		list($rest, $mod) = self::take_mod($answer);
		return $mod !== '' ? $mod : self::clean_mod(strtok(trim($answer), "\n"));
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
		// One long paragraph (some models do not break them): two, split between sentences near the middle.
		if (count($paras) === 1) {
			$sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“‘(])/u', $paras[0]);
			if (count($sentences) >= 3) {
				$half = (int) ceil(count($sentences) / 2);
				$paras = [implode(' ', array_slice($sentences, 0, $half)), implode(' ', array_slice($sentences, $half))];
			}
		}
		$paras = array_slice($paras, 0, 6);

		$name = self::text($ctx['show']);
		if ($o['link_name'] && $ctx['play_url'] !== '' && $ctx['on_play']) { // not a link to a page Google Play no longer has
			$name = '<a href="' . esc_url($ctx['play_url']) . '">' . $name . '</a>';
		}
		$blocks = [];
		$linked = false;
		$self = !$o['link_self'] || empty($ctx['permalink']); // true once the post's own address is linked
		$words = 0;
		foreach ($paras as $i => $p) {
			$words += count(preg_split('/\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY));
			$html = self::text($p);
			$lead = 0;
			foreach ($i === 0 ? array_unique([$ctx['name'], $ctx['show']]) : [] as $start) {
				$n = mb_strlen($start);
				if ($n && mb_strtolower(mb_substr($p, 0, $n)) === mb_strtolower($start) && preg_match('/^\s+\S/u', mb_substr($p, $n))) {
					$lead = $n;
					break;
				}
			}
			if ($lead) {
				// "OTR 2 is an open-world …" → "<strong>OTR 2 MOD APK [Unlimited Money]</strong> – is an open-world …"
				$rest = self::text(ltrim(mb_substr($p, $lead)));
				$html = $ctx['apk'] ? '<strong>' . $name . ' ' . self::text(self::label($ctx)) . '</strong> – ' . $rest : '<strong>' . $name . '</strong> ' . $rest;
			} elseif ($i > 0) {
				// The post's own address on its name ("ActionDirector"), else on a word of the last paragraph.
				if (!$self && $ctx['short'] !== '') {
					$html = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote(self::text($ctx['short']), '/') . '(?![\p{L}\p{N}])/u', '<a href="' . esc_url($ctx['permalink']) . '">$0</a>', $html, 1, $hits);
					$self = $hits > 0;
				}
				if (!$self && $i === count($paras) - 1) {
					$html = preg_replace('/(?<![\p{L}\p{N}\-])[a-z]{6,}(?![\p{L}\p{N}\-])/u', '<a href="' . esc_url($ctx['permalink']) . '">$0</a>', $html, 1, $hits);
					$self = $hits > 0;
				}
				if (!$linked && $o['link_type'] && $ctx['category_url'] !== '') {
					$word = $ctx['game'] ? 'game' : 'app';
					// Not inside a link already made (its address or words).
					$html = preg_replace('/(?<![\w\/.-])' . $word . '\b(?![^<]*<\/a>)(?![^<]*">)/', '<a href="' . esc_url($ctx['category_url']) . '">' . $word . '</a>', $html, 1, $hits);
					$linked = $hits > 0;
				}
			}
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
		}
		if ($ctx['apk'] && trim($o['heading']) !== '') {
			$h = $o['heading'];
			if ($ctx['mod'] === '') {
				$h = preg_replace('/\s*[(\[]\s*\{mod\}\s*[)\]]/', '', $h);
			}
			$h = trim(preg_replace('/\s+/u', ' ', self::fill($h, ['name' => $ctx['show']] + $ctx)));
			$blocks[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . self::text($h) . "</h2>\n<!-- /wp:heading -->";
		}
		return ['html' => implode("\n\n", $blocks), 'words' => $words];
	}

	/* ---------------------------------------------------------------- *
	 * Writing a post
	 * ---------------------------------------------------------------- */

	/** code: busy (the AI's limit; wait: seconds) | play (Google Play busy) | locked | key | credit | model | refused | api | ''. */
	private static function result($ok, $message, $retry = false, $code = '', $wait = 0) {
		return ['ok' => $ok, 'message' => $message, 'retry' => $retry, 'code' => $code, 'wait' => (int) $wait];
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
			return self::result(false, __('Google Play is busy; trying again later.', 'y4apk'), true, 'play');
		}
		$done = [];
		// Gone from its Google Play ID: moved to a new one (the same name and developer), or gone for good.
		if ($ctx['play']['status'] === 'missing' && $ctx['package'] !== '') {
			$found = Y4APK_App_Updates::find_listing($ctx['name'], (string) get_post_meta($post_id, 'wp_developers_GP', true), $ctx['package']);
			if ($found === null) {
				return self::result(false, __('Google Play is busy; trying again later.', 'y4apk'), true, 'play');
			}
			if ($found !== '') {
				$was = (string) get_post_meta($post_id, 'wp_GP_ID', true);
				update_post_meta($post_id, 'wp_GP_ID', $found);
				self::remember($post_id, 'package', $was, $found);
				delete_post_meta($post_id, self::GONE);
				$done[] = sprintf(__('moved on Google Play: %1$s → %2$s', 'y4apk'), $ctx['package'], $found);
				$ctx = self::context($post_id);
			} else {
				update_post_meta($post_id, self::GONE, time());
			}
		} elseif ($ctx['play']['status'] === 'ok') {
			delete_post_meta($post_id, self::GONE);
		}
		self::$note = '';
		$o = self::settings();
		$provider = $o['provider'];
		$model = self::model($provider);
		// No MOD written by you: the one MOD APK sites list for the app, asked with the article
		// (one request), or on its own when only the details are written.
		$ask_mod = $ctx['apk'] && !$ctx['mod_saved'] && $o['mod_lookup'] && ($parts & (self::ARTICLE | self::DETAILS));
		$found = '';
		$answer = null;
		if ($parts & self::ARTICLE) {
			$asked = self::ask(function ($p) use ($ctx, $ask_mod) { return self::prompt($ctx, $ask_mod, $p); }, 4000, $ask_mod);
			if (is_wp_error($asked)) {
				$data = $asked->get_error_data();
				return self::result(false, $asked->get_error_message(), $asked->get_error_code() === 'busy', $asked->get_error_code(), isset($data['wait']) ? $data['wait'] : 0);
			}
			list($answer, $provider, $model) = $asked;
			if ($provider !== $o['provider']) {
				$done[] = sprintf(__('by %1$s (%2$s is at its limit)', 'y4apk'), self::provider_name($provider), self::provider_name($o['provider']));
			}
			list($answer, $found) = self::take_mod($answer);
		} elseif ($ask_mod) {
			$found = self::lookup_mod($ctx);
			if (is_wp_error($found)) {
				$data = $found->get_error_data();
				return self::result(false, $found->get_error_message(), true, 'busy', isset($data['wait']) ? $data['wait'] : 0);
			}
		}
		if ($ask_mod) {
			$before = (string) get_post_meta($post_id, self::MOD_FOUND, true);
			if ($found === '' && $ctx['mod_auto'] !== '' && $before === $ctx['mod_auto']) {
				$found = $before; // found last time; this search came back empty
			}
			$ctx['mod'] = $found !== '' ? $found : self::default_mod($ctx['game']);
			$ctx['mod_found'] = $found !== '';
			$done[] = $found !== '' ? sprintf(__('MOD “%s” (found)', 'y4apk'), $found) : sprintf(__('MOD “%s” (none found; Settings’)', 'y4apk'), $ctx['mod']);
			if (self::$note !== '') {
				$done[] = self::$note;
			}
		}
		if ($parts & self::DETAILS) {
			$done = array_merge($done, self::update_details($post_id, $ctx, $answer !== null));
		}
		if ($answer !== null) {
			$ctx['permalink'] = self::permalink($post_id); // after Post details may have changed it
			$article = self::layout($answer, $ctx);
			if (!$article) {
				return self::result(false, __('The answer had no paragraphs.', 'y4apk'));
			}
			$saved = self::save_article(get_post($post_id), $article, $provider, $model);
			if (is_wp_error($saved)) {
				return self::result(false, $saved->get_error_message());
			}
			array_unshift($done, sprintf(_n('%s word', '%s words', $article['words'], 'y4apk'), number_format_i18n($article['words'])));
			// Added from Google Play to be published: now that it has its article.
			if (get_post_meta($post_id, Y4APK_Discover::PUBLISH, true) && get_post_status($post_id) !== 'publish') {
				wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
				delete_post_meta($post_id, Y4APK_Discover::PUBLISH);
				$done[] = __('published', 'y4apk');
			}
			if ($ctx['apk'] && ($d = self::update_description($post_id)) !== '') {
				$done[] = $d;
			}
		}
		if ($parts & self::CATEGORY) {
			$done[] = $ctx['on_play'] ? self::update_category($post_id, $ctx) : __('category left as it is (no Google Play app)', 'y4apk');
		}
		if ($parts & (self::ICONS | self::SHOTS) && !$ctx['on_play']) {
			$done[] = $ctx['play_url'] === '' ? __('no Google Play app linked: icon and screenshots left as they are', 'y4apk') : __('not on Google Play any more (removed, and no new listing with its name): icon and screenshots left as they are', 'y4apk');
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

	private static function save_article($post, $article, $provider, $model) {
		$r = wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($article['html'])], true);
		if (is_wp_error($r)) {
			return $r;
		}
		self::remember($post->ID, 'content', $post->post_content, get_post_field('post_content', $post->ID, 'raw'));
		update_post_meta($post->ID, self::DONE, ['time' => time(), 'provider' => $provider, 'model' => $model, 'words' => $article['words']]);
		$u = get_option(self::USAGE, []);
		$u = wp_parse_args(is_array($u) ? $u : [], ['posts' => 0, 'in' => 0, 'out' => 0]);
		$u['posts']++;
		update_option(self::USAGE, $u, false);
		return true;
	}

	/** The app's icon from Google Play as the featured image: the attachment ID, 0 when it already is, or a WP_Error. */
	/** The icon's title "Mint MOD", and "Mint MOD APK (Premium Unlocked)" as alt text (Rank Math looks for the focus keyword there), caption and description. */
	private static function icon_text($att, $ctx) {
		$alt = $ctx['show'] . ' MOD APK' . ($ctx['mod'] !== '' ? ' (' . $ctx['mod'] . ')' : '');
		if (get_post_meta($att, '_wp_attachment_image_alt', true) === $alt && get_post_field('post_title', $att, 'raw') === $ctx['show'] . ' MOD') {
			return;
		}
		wp_update_post(['ID' => $att, 'post_title' => wp_slash($ctx['show'] . ' MOD'), 'post_excerpt' => wp_slash($alt), 'post_content' => wp_slash($alt)]);
		update_post_meta($att, '_wp_attachment_image_alt', wp_slash($alt));
	}

	private static function update_icon($post_id, $ctx) {
		$src = isset($ctx['play']['icon']) ? $ctx['play']['icon'] : '';
		if ($src === '') {
			return new WP_Error('icon', __('Google Play shows none', 'y4apk'));
		}
		$thumb = (int) get_post_thumbnail_id($post_id);
		if ($thumb && $src === get_post_meta($post_id, self::ICON, true)) {
			self::icon_text($thumb, $ctx); // the same icon: its words follow the name and MOD
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
		$file = wp_upload_bits(sanitize_file_name($ctx['show'] . '-MOD.' . $types[$size['mime']]), null, $body);
		if (!empty($file['error'])) {
			return new WP_Error('icon', $file['error']);
		}
		$att = wp_insert_attachment(['post_mime_type' => $size['mime'], 'post_title' => 'icon', 'post_status' => 'inherit'], $file['file'], $post_id, true);
		if (is_wp_error($att)) {
			return $att;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $file['file']));
		self::icon_text($att, $ctx);
		set_post_thumbnail($post_id, $att);
		self::remember($post_id, 'thumb', $thumb, $att);
		update_post_meta($post_id, self::ICON, $src);
		return $att;
	}

	/** The app's screenshots from Google Play (the first five): how many, or false when they already are. */
	private static function update_screenshots($post_id, $ctx) {
		$list = [];
		foreach (array_slice(isset($ctx['play']['screenshots']) ? $ctx['play']['screenshots'] : [], 0, self::SCREENS) as $u) {
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

	/* ---------------------------------------------------------------- *
	 * Post details: title, version, MOD, price, download folder, keywords
	 * ---------------------------------------------------------------- */

	/** Download types and the file extension their folders and versions take. */
	private static function file_ext($type) {
		$ext = ['OBB' => 'zip', 'ZIP' => 'zip', 'IPA' => 'ipa', 'XAPK' => 'xapk', 'APKS' => 'apks'];
		$t = strtoupper(trim((string) $type));
		return isset($ext[$t]) ? $ext[$t] : 'apk';
	}

	/** "1.22.0" → "v1.22.0.apk" (and "368" → "v368.apk"), as the site writes download versions; anything else stays as it is. */
	public static function version_text($version, $type) {
		$v = trim((string) $version);
		return preg_match('/^v?(\d+(?:\.\d+)*)$/i', $v, $m) ? 'v' . $m[1] . '.' . self::file_ext($type) : $v;
	}

	/** Settings ▸ Title, filled: "Idle Painter MOD APK v1.24.22 (Unlimited Money)". */
	public static function title_for($template, $name, $version, $mod) {
		$t = (string) $template;
		if ($version === '') {
			$t = preg_replace('/\s*v?\{version\}/', '', $t);
		}
		if ($mod === '') {
			$t = preg_replace('/\s*[(\[]\s*\{mod\}\s*[)\]]/', '', $t);
		}
		return trim(preg_replace('/\s+/u', ' ', strtr($t, ['{name}' => $name, '{version}' => $version, '{mod}' => $mod])));
	}

	/**
	 * A title from a template that fits in $max characters, as Google shows it:
	 * with the full name, else the short one ("ActionDirector - Video Editing" →
	 * "ActionDirector"), else without "Download"; the version and MOD always stay.
	 */
	public static function fit_title($template, $names, $version, $mod, $max = self::SEO_MAX) {
		$templates = array_unique([(string) $template, trim(preg_replace('/\s*\bDownload\b/i', '', (string) $template))]);
		$tries = [];
		foreach ($templates as $t) {
			foreach (array_unique(array_filter((array) $names, 'strlen')) as $n) {
				$tries[] = self::title_for($t, $n, $version, $mod);
			}
		}
		foreach ($tries as $t) {
			if (mb_strlen($t) <= $max) {
				return $t;
			}
		}
		return end($tries);
	}

	/** The post's address, as it will be once published. */
	public static function permalink($post_id) {
		$post = get_post($post_id);
		if (!$post || $post->post_status === 'publish') {
			return get_permalink($post_id);
		}
		$p = clone $post;
		$p->post_status = 'publish';
		$p->post_name = $p->post_name !== '' ? $p->post_name : sanitize_title($p->post_title, $p->ID);
		return get_permalink($p);
	}

	/** "ActionDirector" → "actiondirector-mod": the slug Rank Math's focus keyword "ActionDirector MOD" is found in. */
	public static function slug_for($ctx) {
		return $ctx['apk'] ? sanitize_title($ctx['short'] . ' MOD') : sanitize_title($ctx['short']);
	}

	/** "Mint MOD APK (Premium Unlocked) is a comprehensive …", at most 160 characters: the article's first paragraph, as Google shows a description. */
	public static function description_from($html) {
		if (!preg_match('~<p>(.*?)</p>~s', (string) $html, $m)) {
			return '';
		}
		$t = trim(preg_replace('/\s+/u', ' ', self::plain($m[1])));
		$t = preg_replace('/^(.+? MOD APK) \[([^\]]+)\] – /u', '$1 ($2) ', $t, 1, $hits);
		if (!$hits) {
			$t = preg_replace('/^(.+? MOD APK) – /u', '$1 ', $t, 1);
		}
		if (mb_strlen($t) > 160) {
			$t = preg_replace('/[\s,;:–-]+\S*$/u', '', mb_substr($t, 0, 159)) . '…';
		}
		return $t;
	}

	/** Rank Math's SEO description from the article, where it has none (or one written here): '' or what was done. */
	private static function update_description($post_id) {
		if (!self::settings()['seo_on']) {
			return '';
		}
		$now = (string) get_post_meta($post_id, 'rank_math_description', true);
		if ($now !== '' && $now !== (string) get_post_meta($post_id, self::DESC_AUTO, true)) {
			return ''; // yours
		}
		$d = self::description_from(get_post_field('post_content', $post_id, 'raw'));
		if ($d === '' || $d === $now) {
			return '';
		}
		update_post_meta($post_id, 'rank_math_description', wp_slash($d));
		update_post_meta($post_id, self::DESC_AUTO, wp_slash($d));
		self::remember($post_id, 'desc', $now, $d);
		return __('SEO description', 'y4apk');
	}

	/** The meta Post details may change, for Undo. */
	private static function details_meta($post_id) {
		$keys = ['wp_mods', 'price', 'wp_title_GP', 'wp_version_GP', 'wp_sizes_GP', 'rank_math_focus_keyword', 'rank_math_title', 'rank_math_facebook_title', 'rank_math_twitter_title',
			Y4APK_App_Updates::NO_FILE, self::MOD_AUTO, self::MOD_FOUND, self::SEO_AUTO];
		$out = [];
		foreach ($keys as $k) {
			$out[$k] = (string) get_post_meta($post_id, $k, true);
		}
		return $out;
	}

	/**
	 * Makes a post ready for its file: the title with Google Play's latest
	 * version and the MOD (when the post has no file yet; with one, the title
	 * keeps the file's version), App MOD Feature, App Price 0, App Information
	 * from Google Play, the download row (type, "v1.22.0.apk", MOD Info) and —
	 * with no file yet — a download folder whose page waits for it, listed in
	 * App updates ▸ Need a new file; and the Rank Math focus keywords.
	 */
	private static function update_details($post_id, $ctx, $article = false) {
		$done = [];
		$o = self::settings();
		if ($ctx['mod_auto'] !== '' && (string) get_post_meta($post_id, self::MOD_AUTO, true) === '') {
			update_post_meta($post_id, self::MOD_AUTO, wp_slash($ctx['mod_auto'])); // 2.25's, marked as such (also for Undo)
		}
		$meta_before = self::details_meta($post_id);
		$name = $ctx['name'];
		$mod = $ctx['apk'] ? $ctx['mod'] : '';
		// The MOD Write with AI put in before ("Unlimited Money" for every post in 2.25), where a better one replaces it.
		$old_mod = $ctx['apk'] && $ctx['mod_auto'] !== '' && $mod !== '' && $ctx['mod_auto'] !== $mod ? $ctx['mod_auto'] : '';
		$latest = $ctx['on_play'] && !empty($ctx['play']['version']) ? (string) $ctx['play']['version'] : '';

		if ($ctx['apk']) {
			if (!$ctx['mod_saved'] && $mod !== '') {
				update_post_meta($post_id, 'wp_mods', wp_slash($mod));
				update_post_meta($post_id, self::MOD_AUTO, wp_slash($mod));
				if (!empty($ctx['mod_found'])) {
					update_post_meta($post_id, self::MOD_FOUND, wp_slash($mod));
				}
			}
			// 0 for every MOD APK, paid on Google Play or not ("$4.99" → 0): free to download here.
			if (trim((string) get_post_meta($post_id, 'price', true)) !== '0') {
				update_post_meta($post_id, 'price', '0');
			}
		}
		if (trim((string) get_post_meta($post_id, 'wp_title_GP', true)) === '') {
			update_post_meta($post_id, 'wp_title_GP', $name);
		}
		if ($ctx['on_play']) {
			Y4APK_App_Updates::apply_info($post_id, $ctx['play']); // publisher, age rating, Android, rating, as Settings there allow
			if ($latest !== '') {
				update_post_meta($post_id, Y4APK_App_Updates::LATEST, $latest);
				update_post_meta($post_id, Y4APK_App_Updates::SOURCE, 'play');
				update_post_meta($post_id, Y4APK_App_Updates::CHECKED, time());
			}
		}

		// The download row: the site's way of writing it, and a folder when there is no file yet.
		$links = get_post_meta($post_id, 'repeatable_download_link', true);
		$links = is_array($links) ? $links : [];
		$links_before = $links;
		list($i, $main) = Y4APK_App_Updates::download($post_id);
		$url = $main ? trim(html_entity_decode((string) (isset($main['download_url']) ? $main['download_url'] : ''))) : '';
		$waiting = $url !== '' && $url === (string) get_post_meta($post_id, Y4APK_App_Updates::NO_FILE, true); // a folder made here, still without its file
		$has_file = $main && !in_array($url, ['', '#'], true) && !$waiting;
		if ($ctx['apk']) {
			if ($main === null) {
				$i = $links ? max(array_keys($links)) + 1 : 0;
				$links[$i] = ['download_name' => 'MOD', 'download_version' => '', 'download_size' => '', 'download_url' => '', 'download_mod_info' => ''];
			}
			foreach ($links as $k => $l) {
				if (!empty($l['download_version'])) {
					$links[$k]['download_version'] = self::version_text($l['download_version'], isset($l['download_name']) ? $l['download_name'] : '');
				}
				if ($old_mod !== '' && isset($l['download_mod_info']) && trim($l['download_mod_info']) === $old_mod) {
					$links[$k]['download_mod_info'] = $mod;
				}
			}
			if (!$has_file) {
				$row = &$links[$i];
				$row['download_name'] = !empty($row['download_name']) ? $row['download_name'] : 'MOD';
				// The file to come is Google Play's latest: its version, as in the title (not the old file's).
				if ($latest !== '') {
					$row['download_version'] = self::version_text($latest, $row['download_name']);
				} elseif (empty($row['download_version'])) {
					$row['download_version'] = self::version_text((string) get_post_meta($post_id, 'wp_version_GP', true), $row['download_name']);
				}
				if (empty($row['download_mod_info']) && $mod !== '') {
					$row['download_mod_info'] = $mod;
				}
				if (!$waiting && class_exists('Y4APK_R2')) {
					$made = Y4APK_R2::make_folder($post_id, strtoupper($row['download_name']) === 'OBB' ? 'OBB' : 'MOD', self::file_ext($row['download_name']));
					if (is_wp_error($made)) {
						$done[] = sprintf(__('no download folder (%s)', 'y4apk'), $made->get_error_message());
					} else {
						$row['download_url'] = $made['url'];
						update_post_meta($post_id, Y4APK_App_Updates::NO_FILE, $made['url']);
						$done[] = sprintf(__('download folder %s', 'y4apk'), $made['folder']);
					}
				}
				unset($row);
			}
			// No size (Google Play shows none any more): App Size, else the app's APK on Aptoide (2.28; 2.25 wrote "—").
			if (isset($links[$i]) && in_array(trim((string) (isset($links[$i]['download_size']) ? $links[$i]['download_size'] : '')), ['', '—', '-'], true)) {
				$size = (string) Y4APK_App_Updates::size_for($post_id);
				$links[$i]['download_size'] = $size;
				if ($size !== '' && in_array(trim((string) get_post_meta($post_id, 'wp_sizes_GP', true)), ['', '—', '-'], true)) {
					update_post_meta($post_id, 'wp_sizes_GP', $size);
				}
				if ($size !== '') {
					$done[] = sprintf(__('size %s', 'y4apk'), $size);
				}
			}
			if ($links !== $links_before) {
				update_post_meta($post_id, 'repeatable_download_link', $links);
				self::remember($post_id, 'links', $links_before, get_post_meta($post_id, 'repeatable_download_link', true));
			}
			// App Version (the page's Version box): the latest too while the file is to come.
			$n = !$has_file && $latest !== '' ? $latest : '';
			if ($n === '' && trim((string) get_post_meta($post_id, 'wp_version_GP', true)) === '') {
				$n = Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($post_id));
			}
			if ($n !== '' && (string) get_post_meta($post_id, 'wp_version_GP', true) !== $n) {
				update_post_meta($post_id, 'wp_version_GP', $n);
			}
		}

		// The title: Settings ▸ Title when it has no "(MOD)" yet or is the one written here before; else the
		// MOD found and, with no file yet, the latest version.
		$title = get_post_field('post_title', $post_id, 'raw');
		$b = get_post_meta($post_id, self::BACKUP, true);
		$ours = is_array($b) && isset($b['title']['after']) && $b['title']['after'] === $title;
		$version = !$has_file && $latest !== '' ? $latest : Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($post_id));
		// One name everywhere ("Mint", as in the permalink and the focus keyword), else the full one when it fits.
		$names = $o['match'] ? [$ctx['short']] : [$name, $ctx['short']];
		$new = $title;
		if ($ctx['apk'] && (strpos($title, '(') === false || $ours)) {
			$new = $o['match'] ? self::fit_title($o['seo_title'], $names, $version, $mod) // the same as Google's title
				: self::title_for($o['title'], $name, $version, $mod);
		} elseif ($ctx['apk']) {
			if ($old_mod !== '') {
				$new = str_replace(['(' . $old_mod . ')', '[' . $old_mod . ']'], ['(' . $mod . ')', '[' . $mod . ']'], $new);
			}
			if (!$has_file && $latest !== '' && preg_match('/\bv(\d+(?:\.\d+)+)/i', $new, $m) && $m[1] !== $latest) {
				$new = preg_replace('/\bv' . preg_quote($m[1], '/') . '/i', 'v' . $latest, $new, 1);
			}
		}
		// The permalink: "actiondirector-mod", the focus keyword in the address (the old one redirects to it).
		$slug_before = (string) get_post_field('post_name', $post_id, 'raw');
		$slug = $slug_before;
		if ($o['slug_on'] && $ctx['apk'] && $ctx['short'] !== '') {
			$want = self::slug_for($ctx);
			// Kept: "actiondirector-mod", "actiondirector-mod-apk" (and "-2" when another post had it first); not one with a version or MOD in it.
			if ($want !== '' && !preg_match('/^' . preg_quote($want, '/') . '(-apk)?(-\d+)?$/', urldecode($slug_before))) {
				$slug = $want;
			}
		}
		$update = ['ID' => $post_id];
		if ($new !== '' && $new !== $title) {
			$update['post_title'] = wp_slash($new);
		}
		if ($slug !== $slug_before) {
			$update['post_name'] = $slug;
		}
		if (count($update) > 1) {
			wp_update_post($update);
		}
		if (isset($update['post_title'])) {
			self::remember($post_id, 'title', $title, get_post_field('post_title', $post_id, 'raw'));
			foreach (['rank_math_facebook_title', 'rank_math_twitter_title'] as $key) {
				$now = (string) get_post_meta($post_id, $key, true);
				if ($now === $title || $old_mod !== '' && strpos($now, '(' . $old_mod . ')') !== false) {
					update_post_meta($post_id, $key, wp_slash($now === $title ? $new : str_replace('(' . $old_mod . ')', '(' . $mod . ')', $now)));
				}
			}
			$done[] = sprintf(__('title “%s”', 'y4apk'), $new);
		}
		if (isset($update['post_name'])) {
			$slug_after = (string) get_post_field('post_name', $post_id, 'raw');
			self::remember($post_id, 'slug', $slug_before, $slug_after);
			$done[] = sprintf(__('permalink /%s/', 'y4apk'), urldecode($slug_after));
		}

		// Rank Math's SEO title (what Google shows), at most 60 characters: "ActionDirector MOD APK v7.12.5 (Premium Unlocked) Download".
		$seo_now = (string) get_post_meta($post_id, 'rank_math_title', true);
		if ($ctx['apk'] && $o['seo_on']) {
			$seo_auto = (string) get_post_meta($post_id, self::SEO_AUTO, true);
			if ($seo_now === '' || strpos($seo_now, '%title%') !== false || $seo_now === $title || $seo_now === $new || $seo_auto !== '' && $seo_now === $seo_auto) {
				$seo = self::fit_title($o['seo_title'], $names, $version, $mod);
				if ($seo !== '' && $seo !== $seo_now) {
					update_post_meta($post_id, 'rank_math_title', wp_slash($seo));
					update_post_meta($post_id, self::SEO_AUTO, wp_slash($seo));
					$done[] = sprintf(__('SEO title “%1$s” (%2$d characters)', 'y4apk'), $seo, mb_strlen($seo));
				}
			}
		} elseif ($seo_now !== '' && $seo_now === $title && isset($update['post_title'])) {
			update_post_meta($post_id, 'rank_math_title', wp_slash($new));
		}

		// Rank Math focus keywords, when there are none (or 2.25's): "ActionDirector MOD" first, as in the permalink.
		$fk = trim((string) get_post_meta($post_id, 'rank_math_focus_keyword', true));
		if ($fk === '' || $fk === implode(',', [$name, $name . ' MOD', $name . ' MOD APK'])) {
			$s = $ctx['short'];
			$words = $ctx['apk'] ? array_unique([$s . ' MOD', $s . ' MOD APK', $s, $name]) : [$name];
			if (implode(',', $words) !== $fk) {
				update_post_meta($post_id, 'rank_math_focus_keyword', wp_slash(implode(',', $words)));
				$done[] = sprintf(__('focus keywords %s', 'y4apk'), implode(', ', $words));
			}
		}

		// The article already written with the old MOD, when no new one is written now.
		if ($old_mod !== '' && !$article) {
			$c = get_post_field('post_content', $post_id, 'raw');
			$c2 = str_replace(['MOD APK [' . self::text($old_mod) . ']', 'MOD APK (' . self::text($old_mod) . ')'], ['MOD APK [' . self::text($mod) . ']', 'MOD APK (' . self::text($mod) . ')'], $c);
			if ($c2 !== $c) {
				wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($c2)]);
				self::remember($post_id, 'content', $c, get_post_field('post_content', $post_id, 'raw'));
				$done[] = sprintf(__('MOD “%1$s” → “%2$s” in the article', 'y4apk'), $old_mod, $mod);
			}
		}
		$meta_after = self::details_meta($post_id);
		if ($meta_after !== $meta_before) {
			self::remember($post_id, 'meta', $meta_before, $meta_after);
		}
		Y4APK_App_Updates::refresh_flag($post_id);
		return $done;
	}

	/** Games or Apps, and the genre, as Google Play has them ("GAME_SIMULATION" → Games › Simulation). */
	private static function update_category($post_id, $ctx) {
		$gid = isset($ctx['play']['genre_id']) ? (string) $ctx['play']['genre_id'] : '';
		$genre = isset($ctx['play']['genre']) ? trim((string) $ctx['play']['genre']) : '';
		if ($gid === '' || $genre === '') {
			return __('category left as it is (Google Play shows no genre)', 'y4apk');
		}
		$parent_name = strpos($gid, 'GAME') === 0 ? 'Games' : 'Apps';
		$parent = get_term_by('slug', sanitize_title($parent_name), 'category') ?: get_term_by('name', $parent_name, 'category');
		if (!$parent) {
			$made = wp_insert_term($parent_name, 'category');
			if (is_wp_error($made)) {
				return sprintf(__('category not set (%s)', 'y4apk'), $made->get_error_message());
			}
			$parent = get_term($made['term_id'], 'category');
		}
		$slug = sanitize_title(str_replace(['GAME_', '_'], ['', '-'], $gid));
		$child = null;
		foreach (get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'parent' => $parent->term_id]) as $t) {
			if ($t->slug === $slug || strcasecmp($t->name, $genre) === 0) {
				$child = $t;
				break;
			}
		}
		if (!$child) {
			$made = wp_insert_term($genre, 'category', ['parent' => $parent->term_id, 'slug' => term_exists($slug, 'category') ? $slug . '-' . sanitize_title($parent_name) : $slug]);
			if (is_wp_error($made)) {
				return sprintf(__('category not set (%s)', 'y4apk'), $made->get_error_message());
			}
			$child = get_term($made['term_id'], 'category');
		}
		$before = array_map('intval', wp_get_post_categories($post_id));
		$want = [(int) $parent->term_id, (int) $child->term_id];
		$label = $parent->name . ' › ' . $child->name;
		if (!array_diff($want, $before) && !array_diff($before, $want)) {
			return sprintf(__('category already %s, as on Google Play', 'y4apk'), $label);
		}
		wp_set_post_categories($post_id, $want);
		self::remember($post_id, 'cats', $before, array_map('intval', wp_get_post_categories($post_id)));
		return sprintf(__('category %s', 'y4apk'), $label);
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
		if (isset($b['title'])) {
			if (get_post_field('post_title', $post_id, 'raw') === $b['title']['after']) {
				wp_update_post(['ID' => $post_id, 'post_title' => wp_slash((string) $b['title']['before'])]);
				$back[] = __('title', 'y4apk');
			} else {
				$kept[] = __('title', 'y4apk');
			}
		}
		if (isset($b['package']) && $b['package']['before'] !== '') {
			if ((string) get_post_meta($post_id, 'wp_GP_ID', true) === (string) $b['package']['after']) {
				update_post_meta($post_id, 'wp_GP_ID', $b['package']['before']);
				$back[] = __('Google Play ID', 'y4apk');
			} else {
				$kept[] = __('Google Play ID', 'y4apk');
			}
		}
		if (isset($b['desc'])) {
			if ((string) get_post_meta($post_id, 'rank_math_description', true) === (string) $b['desc']['after']) {
				foreach (['rank_math_description', self::DESC_AUTO] as $key) {
					$b['desc']['before'] === '' ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, wp_slash($b['desc']['before']));
				}
				$back[] = __('SEO description', 'y4apk');
			} else {
				$kept[] = __('SEO description', 'y4apk');
			}
		}
		if (isset($b['slug'])) {
			if (get_post_field('post_name', $post_id, 'raw') === $b['slug']['after']) {
				wp_update_post(['ID' => $post_id, 'post_name' => (string) $b['slug']['before']]);
				$back[] = __('permalink', 'y4apk');
			} else {
				$kept[] = __('permalink', 'y4apk');
			}
		}
		if (isset($b['links'])) {
			if (get_post_meta($post_id, 'repeatable_download_link', true) === $b['links']['after']) {
				$b['links']['before'] ? update_post_meta($post_id, 'repeatable_download_link', $b['links']['before']) : delete_post_meta($post_id, 'repeatable_download_link');
				$back[] = __('download links', 'y4apk');
			} else {
				$kept[] = __('download links', 'y4apk');
			}
		}
		if (isset($b['meta'])) {
			$changed = false;
			foreach ((array) $b['meta']['after'] as $key => $after) {
				$now = (string) get_post_meta($post_id, $key, true);
				$before = isset($b['meta']['before'][$key]) ? (string) $b['meta']['before'][$key] : '';
				if ($now === (string) $after && $before !== $now) {
					$before === '' ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, wp_slash($before));
					$changed = true;
				}
			}
			if ($changed) {
				$back[] = __('App Information, SEO title and focus keywords', 'y4apk');
			}
		}
		if (isset($b['cats'])) {
			if (array_map('intval', wp_get_post_categories($post_id)) === array_map('intval', (array) $b['cats']['after'])) {
				wp_set_post_categories($post_id, array_map('intval', (array) $b['cats']['before']));
				$back[] = __('category', 'y4apk');
			} else {
				$kept[] = __('category', 'y4apk');
			}
		}
		delete_post_meta($post_id, self::BACKUP);
		delete_transient('y4apk_writer_short');
		Y4APK_App_Updates::refresh_flag($post_id);
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

	public static function enqueue($ids, $parts, $front = false) {
		$q = self::queue();
		$have = array_flip(array_map(function ($item) { return (int) $item[0]; }, $q));
		$new = [];
		foreach (array_map('intval', $ids) as $id) {
			if ($id > 0 && !isset($have[$id])) {
				$new[] = [$id, (int) $parts & self::PARTS, 0];
				$have[$id] = 1;
			}
		}
		$added = count($new);
		$q = $front ? array_merge($new, $q) : array_merge($q, $new); // front: posts just added from Google Play, before the 5,000 waiting
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
		if ($o['paused'] !== '' || !self::queue() || get_transient('y4apk_writer_lock') || self::waiting()) {
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
			if (!$r['ok'] && $r['code'] === 'busy') {
				// The AI's limit (per minute, or a free tier's per day), not the post's doing:
				// it keeps its place, and the rounds wait until the AI takes requests again.
				self::wait($r['wait'], $r['message']);
				break;
			}
			if (!$r['ok'] && $r['retry'] && $tries < 4) {
				// Google Play busy, or the post is being written: to the back of the queue, and on next round.
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

	/** Posts Rank Math has no SEO score for (its Analytics ▸ "No Data"), less those already written here. */
	private static function count_gone() {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::GONE));
	}

	private static function count_noscore() {
		$q = new WP_Query(array_merge(self::query_args('noscore', 0, ''), ['posts_per_page' => 1]));
		return (int) $q->found_posts;
	}

	/** The query for a list: show = noscore | short | notai | ai | all | queued. */
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
		if ($show === 'noscore') {
			// Rank Math scores a post when it is opened in the editor: written here, it is done all the same.
			$args['meta_query'] = [
				'relation' => 'AND',
				['relation' => 'OR', ['key' => 'rank_math_seo_score', 'compare' => 'NOT EXISTS'], ['key' => 'rank_math_seo_score', 'value' => '', 'compare' => '=']],
				['key' => self::DONE, 'compare' => 'NOT EXISTS'],
			];
		} elseif ($show === 'short') {
			$args['post__in'] = self::short_ids() ?: [0];
		} elseif ($show === 'queued') {
			$args['post__in'] = array_map(function ($i) { return (int) $i[0]; }, self::queue()) ?: [0];
		} elseif ($show === 'notai') {
			$args['meta_query'] = [['key' => self::DONE, 'compare' => 'NOT EXISTS']];
		} elseif ($show === 'ai') {
			$args['meta_query'] = [['key' => self::DONE, 'compare' => 'EXISTS']];
		} elseif ($show === 'gone') {
			$args['meta_query'] = [['key' => self::GONE, 'compare' => 'EXISTS']];
		}
		return $args;
	}

	private static function filters() {
		$show = isset($_REQUEST['show']) ? sanitize_key(wp_unslash($_REQUEST['show'])) : 'noscore';
		return [
			in_array($show, ['noscore', 'short', 'notai', 'ai', 'all', 'queued', 'gone'], true) ? $show : 'noscore',
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
		$parts = isset($_POST['parts']) ? (int) $_POST['parts'] & self::PARTS : self::settings()['parts'];
		if (!$parts) {
			wp_send_json_error(['message' => __('Tick at least one of Article, Icon and Screenshots.', 'y4apk')]);
		}
		if ($parts !== self::settings()['parts'] && empty($_POST['keep'])) { // keep: Add from Google Play's own parts, not the Posts tab's ticks
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
		$added = self::enqueue($ids, $parts, !empty($_POST['front']));
		wp_send_json_success(['added' => $added, 'waiting' => count(self::queue()), 'message' => sprintf(_n('%s post added to the queue.', '%s posts added to the queue.', $added, 'y4apk'), number_format_i18n($added))]);
	}

	public static function ajax_queue() {
		self::check(0, 'manage_options');
		$do = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
		if ($do === 'pause') {
			self::save_settings(['paused' => __('Paused by you.', 'y4apk')]);
		} elseif ($do === 'resume') {
			self::save_settings(['paused' => '']);
			self::clear_waits();
		} elseif ($do === 'clear') {
			update_option(self::QUEUE, [], false);
		} elseif ($do === 'speed') {
			self::save_settings(['per_run' => max(1, min(10, isset($_POST['per_run']) ? (int) $_POST['per_run'] : 3))]);
		} elseif ($do === 'run') {
			$paused = self::settings()['paused'];
			if ($paused === '') {
				delete_transient('y4apk_writer_lock');
				self::clear_waits(); // "now": every AI is asked again
				self::tick();
			}
		}
		self::schedule();
		wp_send_json_success(['reload' => true]);
	}

	/** Settings ▸ Test: the key, the models it can use, and one tiny answer (which also proves there is credit). */
	public static function ajax_test() {
		self::check(0, 'manage_options');
		$provider = isset($_POST['provider']) ? sanitize_key($_POST['provider']) : '';
		$provider = isset(self::providers()[$provider]) ? $provider : self::settings()['provider'];
		$models = self::models($provider, true);
		if (is_wp_error($models)) {
			wp_send_json_error(['message' => $models->get_error_message()]);
		}
		$model = self::model($provider);
		$reply = self::chat('Reply with one word.', 'Say OK.', $provider, $model, 1000);
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
		$o = self::settings();
		$ask_mod = $ctx['apk'] && !$ctx['mod_saved'] && $o['mod_lookup'];
		$user = '';
		$asked = self::ask(function ($p) use ($ctx, $ask_mod, &$user) {
			$prompt = self::prompt($ctx, $ask_mod, $p);
			$user = $prompt[1];
			return $prompt;
		}, 4000, $ask_mod);
		if (is_wp_error($asked)) {
			wp_send_json_error(['message' => $asked->get_error_message(), 'prompt' => $user]);
		}
		list($answer, $provider, $model) = $asked;
		list($answer, $found) = self::take_mod($answer);
		if ($ask_mod) {
			$ctx['mod'] = $found !== '' ? $found : self::default_mod($ctx['game']);
		}
		$ctx['permalink'] = self::permalink($id);
		$article = self::layout($answer, $ctx);
		wp_send_json_success([
			'mod'     => $ctx['apk'] ? $ctx['mod'] : '',
			'found'   => $found !== '',
			'id'      => $id,
			'title'   => get_the_title($id),
			'edit'    => get_edit_post_link($id, 'raw'),
			'prompt'  => $user,
			'model'   => self::provider_name($provider) . ' · ' . $model,
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
		$article = null;
		if ($post) {
			$ctx = self::context($id);
			$mod = isset($_POST['mod']) ? self::clean_mod(wp_unslash($_POST['mod'])) : '';
			if ($ctx['apk'] && !$ctx['mod_saved'] && $mod !== '') {
				$ctx['mod'] = $mod; // the one the preview found
			}
			$ctx['permalink'] = self::permalink($id);
			$article = self::layout(self::take_mod($answer)[0], $ctx);
		}
		if (!$article) {
			wp_send_json_error(['message' => __('Nothing to save.', 'y4apk')]);
		}
		$provider = self::settings()['provider'];
		$saved = self::save_article($post, $article, $provider, self::model($provider));
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
				. esc_html__('Write with AI', 'y4apk') . '</a>';
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
		$actions['y4apk_writer_queue'] = __('Write with AI (queue)', 'y4apk');
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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%s post added to the AI writing queue.', '%s posts added to the AI writing queue.', $n['queued'], 'y4apk'), number_format_i18n($n['queued'])))
				. ' <a href="' . esc_url(self::url('queue')) . '">' . esc_html__('See the queue', 'y4apk') . '</a></p></div>';
			return;
		}
		$title = '<a href="' . esc_url(get_permalink($n['id'])) . '" target="_blank">' . esc_html(get_the_title($n['id'])) . '</a>';
		echo '<div class="notice notice-' . ($n['ok'] ? 'success' : 'error') . ' is-dismissible"><p>'
			. ($n['ok'] ? sprintf(esc_html__('Written with AI: %1$s — %2$s', 'y4apk'), $title, esc_html($n['message']))
				: sprintf(esc_html__('Not written: %1$s — %2$s', 'y4apk'), $title, esc_html($n['message'])))
			. '</p></div>';
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Write with ChatGPT
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page(Y4APK_MENU, __('Write with AI', 'y4apk'), __('Write with AI', 'y4apk'),
			'edit_posts', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function scripts() {
		if (!isset($_GET['page']) || $_GET['page'] !== self::SLUG) {
			return;
		}
		wp_enqueue_script('y4apk-writer', get_template_directory_uri() . '/assets/js/y4apk-writer.js', [], Y4APK_THEME_VERSION, true);
		if (isset($_GET['view']) && $_GET['view'] === 'add') {
			wp_enqueue_script('y4apk-discover', get_template_directory_uri() . '/assets/js/y4apk-discover.js', ['y4apk-writer'], Y4APK_THEME_VERSION, true);
			wp_localize_script('y4apk-discover', 'y4apkDiscover', Y4APK_Discover::script_text());
		}
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
				'undoAsk'  => __('Put back what this post had before the AI wrote it?', 'y4apk'),
				'testOk'   => __('✓ Connected · model: %1$s · answered “%2$s”', 'y4apk'),
				'saved'    => __('Saved to the post.', 'y4apk'),
			],
		]);
	}

	private static function ago($t) {
		return sprintf(__('%s ago', 'y4apk'), human_time_diff((int) $t));
	}

	/** A post's line in the list: what the AI last did to it, or that it waits in the queue. */
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
			.aw-fallback { margin: 14px 0 4px; display: grid; gap: 6px; }
			.aw-fallback .description { margin: 2px 0 0; }
			.au-chip.aw-gone { background: #fdeeee; border-color: #f4c6c7; color: #b32d2e; }
			a.au-chip { text-decoration: none; color: var(--au-blue); }
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
			.y4apk-writer a.au-pill { text-decoration: none; color: inherit; }
			.aw-settings .aw-providers { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 10px 0 14px; }
			.aw-settings .aw-provider { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 14px 16px; border: 1px solid var(--au-line); border-radius: 10px; cursor: pointer; background: #fff; }
			.aw-settings .aw-provider.is-on { border-color: var(--au-blue); box-shadow: 0 0 0 1px var(--au-blue); background: #f6faff; }
			.aw-provider-top { display: flex; align-items: center; gap: 8px; }
			.aw-provider-top input { margin: 0; }
			.aw-provider-name { font-weight: 600; font-size: 14px; }
			.aw-provider-name small { font-weight: 400; color: var(--au-muted); }
			.aw-badge { margin-left: auto; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #f0f0f1; color: #50575e; white-space: nowrap; }
			.aw-badge.is-free { background: #e7f7ec; color: #007017; }
			.aw-provider-note { font-size: 12.5px; color: var(--au-muted); line-height: 1.5; }
			.aw-provider-state { font-size: 12px; color: #50575e; margin-top: auto; }
			.aw-panel { border: 1px solid var(--au-line); border-radius: 10px; padding: 14px 18px; background: var(--au-soft); }
			.aw-panel .aw-steps { margin-bottom: 10px; }
			.aw-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 6px 18px; }
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
		$views = ['posts', 'queue', 'add', 'settings'];
		$view = isset($_GET['view']) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'posts';
		if ($view === 'settings' && !current_user_can('manage_options') || $view === 'add' && !current_user_can('publish_posts')) {
			$view = 'posts';
		}
		$queue = self::queue();
		$usage = wp_parse_args((array) get_option(self::USAGE, []), ['posts' => 0, 'in' => 0, 'out' => 0]);
		$models = $o['key'] !== '' ? get_transient('y4apk_writer_models_' . $o['provider']) : false;
		$model = $o['key'] !== '' ? ($o['model'] !== 'auto' ? $o['model'] : (is_array($models) ? self::pick($o['provider'], $models) : '')) : '';
		$short = count(self::short_ids());
		$noscore = self::count_noscore();
		$written = self::count_done();
		$all = self::count_all();
		$per_hour = $o['per_run'] * 12;
		?>
		<div class="wrap y4apk-au y4apk-writer">
			<?php self::styles(); ?>
			<div class="au-head">
				<div>
					<h1><?php esc_html_e('Write with AI', 'y4apk'); ?></h1>
					<p><?php esc_html_e('Writes a post’s article with Gemini or Groq (free tiers), ChatGPT or Claude, asked the way you ask in ChatGPT; lays it out like your articles (name and MOD in bold, the closing heading), and brings the app’s icon and screenshots up to date from Google Play.', 'y4apk'); ?></p>
				</div>
				<div class="au-actions">
					<?php if ($o['key'] === '') : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('settings')); ?>"><span class="au-dot is-red"></span><?php esc_html_e('Not connected: choose an AI and add its API key', 'y4apk'); ?></a>
					<?php elseif ($o['paused'] !== '' && $queue) : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('queue')); ?>"><span class="au-dot is-amber"></span><?php esc_html_e('Queue paused', 'y4apk'); ?></a>
					<?php elseif (($now = self::current()) === '' && $queue) : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('queue')); ?>"><span class="au-dot is-amber"></span><?php echo esc_html(sprintf(__('Waiting for %s’s limit', 'y4apk'), implode(' / ', array_map([__CLASS__, 'provider_name'], self::order())))); ?></a>
					<?php elseif ($now !== '' && $now !== $o['provider']) : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('settings')); ?>"><span class="au-dot is-amber"></span><?php echo esc_html(sprintf(__('%1$s · %2$s (%3$s is at its limit)', 'y4apk'), self::provider_name($now), self::model($now), self::provider_name($o['provider']))); ?></a>
					<?php else : ?>
						<a class="au-pill" href="<?php echo esc_url(self::url('settings')); ?>"><span class="au-dot"></span><?php echo esc_html(self::provider_name($o['provider']) . ' · ' . ($model !== '' ? $model : __('key saved', 'y4apk'))); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<?php if (!empty($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'y4apk'); ?></p></div>
			<?php endif; ?>

			<div class="au-kpis">
				<a class="au-card au-kpi<?php echo $noscore ? ' is-alert' : ''; ?>" href="<?php echo esc_url(self::url('posts', ['show' => 'noscore'])); ?>">
					<span class="au-kpi-label"><?php esc_html_e('To write', 'y4apk'); ?></span>
					<span class="au-kpi-value"><?php echo esc_html(number_format_i18n($noscore)); ?></span>
					<span class="au-kpi-sub"><?php printf(esc_html__('No Rank Math score yet (“No Data”) · %s under 40 words', 'y4apk'), esc_html(number_format_i18n($short))); ?></span>
				</a>
				<a class="au-card au-kpi" href="<?php echo esc_url(self::url('posts', ['show' => 'ai'])); ?>">
					<span class="au-kpi-label"><?php esc_html_e('Written by AI', 'y4apk'); ?></span>
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
				<?php if (current_user_can('publish_posts')) : ?>
					<a class="au-tab<?php echo $view === 'add' ? ' is-on' : ''; ?>" href="<?php echo esc_url(self::url('add')); ?>"><?php esc_html_e('Add from Google Play', 'y4apk'); ?></a>
				<?php endif; ?>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="au-tab<?php echo $view === 'settings' ? ' is-on' : ''; ?>" href="<?php echo esc_url(self::url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a>
				<?php endif; ?>
			</nav>

			<?php
			if ($view === 'queue') {
				self::render_queue($o, $queue, $per_hour);
			} elseif ($view === 'add') {
				Y4APK_Discover::render();
			} elseif ($view === 'settings') {
				self::render_settings($o);
			} else {
				self::render_posts($o, ['noscore' => $noscore, 'short' => $short, 'notai' => $all - $written, 'ai' => $written, 'all' => $all, 'queued' => count($queue), 'gone' => self::count_gone()]);
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
			'noscore' => __('To write (no Rank Math score)', 'y4apk'),
			'short'  => __('Under 40 words', 'y4apk'),
			'notai'  => __('Not written by AI', 'y4apk'),
			'ai'     => __('Written by AI', 'y4apk'),
			'queued' => __('In the queue', 'y4apk'),
			'gone'   => __('Not on Google Play', 'y4apk'),
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
				<label title="<?php esc_attr_e('Title with the latest version and the MOD, App MOD Feature, App Price 0, App Information, the download row (v1.24.22.apk) and — with no file yet — its download folder, Rank Math focus keywords', 'y4apk'); ?>"><input type="checkbox" class="aw-part" value="<?php echo esc_attr(self::DETAILS); ?>" <?php checked((bool) ($o['parts'] & self::DETAILS)); ?>> <?php esc_html_e('Post details', 'y4apk'); ?></label>
				<label title="<?php esc_attr_e('Games or Apps and the genre, as on Google Play (replaces the post’s categories)', 'y4apk'); ?>"><input type="checkbox" class="aw-part" value="<?php echo esc_attr(self::CATEGORY); ?>" <?php checked((bool) ($o['parts'] & self::CATEGORY)); ?>> <?php esc_html_e('Category from Google Play', 'y4apk'); ?></label>
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
				<span class="au-meta"><?php printf(esc_html__('Connect an AI first in %s.', 'y4apk'), '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'y4apk') . '</a>'); ?></span>
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
						<?php if ($package && get_post_meta($id, self::GONE, true)) : ?><span class="au-chip aw-gone" title="<?php esc_attr_e('Its Google Play page shows nothing, and no listing has its name: removed by Google or the developer. Put a new Google Play ID in the post if it is somewhere else.', 'y4apk'); ?>"><?php esc_html_e('Not on Google Play', 'y4apk'); ?></span>
							<a class="au-chip" href="<?php echo esc_url('https://play.google.com/store/search?q=' . rawurlencode(self::plain(get_post_meta($id, 'wp_title_GP', true) ?: get_the_title($id))) . '&c=apps'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Search Google Play', 'y4apk'); ?></a><?php endif; ?>
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
		<?php $w = self::waiting(); ?>
		<?php if ($o['paused'] !== '' && $queue) : ?>
			<p class="aw-paused"><strong><?php esc_html_e('Paused:', 'y4apk'); ?></strong> <?php echo esc_html($o['paused']); ?></p>
		<?php elseif ($w && $queue) : ?>
			<p class="aw-paused"><strong><?php esc_html_e('Waiting:', 'y4apk'); ?></strong> <?php echo esc_html($w['why']); ?>
				<?php echo esc_html(sprintf(__('The queue goes on in %s, by itself.', 'y4apk'), human_time_diff(time(), $w['until']))); ?></p>
		<?php endif; ?>
		<div class="au-card au-pad" style="margin-bottom:16px">
			<div class="au-toolbar" style="margin:0">
				<div>
					<strong><?php echo esc_html(sprintf(_n('%s post waiting', '%s posts waiting', count($queue), 'y4apk'), number_format_i18n(count($queue)))); ?></strong>
					<span class="au-small"><?php
						if (!$queue) {
							esc_html_e('Add posts from the Posts tab, or with “Write with AI (queue)” in Posts ▸ Bulk actions.', 'y4apk');
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

	private static function render_settings($o) {
		$all = self::providers();
		$notes = [
			'gemini' => __('Free: a number of requests a day set by Google (most with the “Flash-Lite” models). Google may use free-tier requests to improve its products.', 'y4apk'),
			'groq'   => __('Free: open models (gpt-oss, Llama), with a daily limit set by Groq.', 'y4apk'),
			'openai' => __('Paid by use, separately from a ChatGPT plan: well under a cent an article with a “mini” model.', 'y4apk'),
			'claude' => __('Paid by use, separately from a Claude plan. Claude Opus 5.5 unless you choose another model; Haiku 5.5 costs far less.', 'y4apk'),
		];
		$link = function ($url) {
			return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('~^https?://|/$~', '', $url)) . '</a>';
		};
		$steps = [
			'gemini' => [sprintf(esc_html__('Open %s and sign in with a Google account.', 'y4apk'), $link($all['gemini']['keys'])),
				esc_html__('Create API key, copy it, paste it below, Save, then Test.', 'y4apk'),
				sprintf(esc_html__('Your free limits: %s.', 'y4apk'), $link($all['gemini']['limits']))],
			'groq'   => [sprintf(esc_html__('Sign up free at %s.', 'y4apk'), $link('https://console.groq.com/')),
				esc_html__('API Keys ▸ Create API Key, copy it, paste it below, Save, then Test.', 'y4apk'),
				sprintf(esc_html__('Your free limits: %s.', 'y4apk'), $link($all['groq']['limits']))],
			'openai' => [sprintf(esc_html__('Sign in at %s (your ChatGPT login works).', 'y4apk'), $link('https://platform.openai.com/')),
				esc_html__('Settings ▸ Billing: add credit ($5 is plenty to start).', 'y4apk'),
				esc_html__('API keys ▸ Create new secret key, copy it, paste it below, Save, then Test.', 'y4apk')],
			'claude' => [sprintf(esc_html__('Sign in at %s (your Claude login works).', 'y4apk'), $link($all['claude']['keys'])),
				esc_html__('Billing: add credit.', 'y4apk'),
				esc_html__('API Keys ▸ Create Key, copy it, paste it below, Save, then Test.', 'y4apk')],
		];
		$auto = [
			'gemini' => __('Automatic: the newest Flash-Lite (the largest free allowance)', 'y4apk'),
			'groq'   => __('Automatic: gpt-oss 120B, else Llama 3.3 70B', 'y4apk'),
			'openai' => __('Automatic: the newest “mini”', 'y4apk'),
		];
		$placeholder = ['gemini' => 'AQ.… / AIza…', 'groq' => 'gsk_…', 'openai' => 'sk-…', 'claude' => 'sk-ant-…'];
		?>
		<div class="au-settings aw-settings">
			<form class="au-card au-pad" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="grid-column:1/-1">
				<input type="hidden" name="action" value="y4apk_writer_settings">
				<?php wp_nonce_field('y4apk-writer-settings'); ?>
				<h2><?php esc_html_e('Which AI writes', 'y4apk'); ?></h2>
				<div class="aw-providers">
					<?php foreach ($all as $p => $info) : ?>
						<label class="aw-provider<?php echo $o['provider'] === $p ? ' is-on' : ''; ?>">
							<span class="aw-provider-top">
								<input type="radio" name="provider" value="<?php echo esc_attr($p); ?>" <?php checked($o['provider'], $p); ?>>
								<span class="aw-provider-name"><?php echo esc_html($info['name']); ?> <small><?php echo esc_html($info['by']); ?></small></span>
								<span class="aw-badge<?php echo $info['free'] ? ' is-free' : ''; ?>"><?php echo $info['free'] ? esc_html__('Free tier', 'y4apk') : esc_html__('Paid', 'y4apk'); ?></span>
							</span>
							<span class="aw-provider-note"><?php echo esc_html($notes[$p]); ?></span>
							<span class="aw-provider-state"><?php echo $o['keys'][$p] !== '' ? '✓ ' . esc_html__('Key saved', 'y4apk') : esc_html__('No key yet', 'y4apk'); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="aw-fallback">
					<label><input type="checkbox" name="fallback" value="1" <?php checked($o['fallback']); ?>> <?php esc_html_e('When this AI reaches its limit (Gemini’s day used up …), go on with the other free AIs that have a key — Gemini, Groq — and back to this one when it takes requests again', 'y4apk'); ?></label>
					<label><input type="checkbox" name="fallback_paid" value="1" <?php checked($o['fallback_paid']); ?>> <?php esc_html_e('… and then the paid ones with a key (ChatGPT, Claude): they cost money', 'y4apk'); ?></label>
					<?php
					$turns = [];
					foreach (self::order() as $p) {
						$w = self::provider_wait($p);
						$turns[] = self::provider_name($p) . ($w ? ' (' . sprintf(__('at its limit, back in %s', 'y4apk'), human_time_diff(time(), $w['until'])) . ')' : '');
					}
					?>
					<p class="description"><?php echo esc_html(sprintf(__('In turn now: %s', 'y4apk'), implode(' → ', $turns))); ?></p>
				</div>

				<?php foreach ($all as $p => $info) :
					$key = $o['keys'][$p];
					$list = get_transient('y4apk_writer_models_' . $p);
					$list = is_array($list) ? $list : [];
					$names = $p === 'claude' ? self::claude_models() : [];
					foreach ($list as $id) {
						$names += [$id => $id];
					}
					if ($o['models'][$p] !== 'auto') {
						$names += [$o['models'][$p] => $o['models'][$p]];
					} ?>
					<div class="aw-panel" data-panel="<?php echo esc_attr($p); ?>" <?php echo $o['provider'] === $p ? '' : 'hidden'; ?>>
						<ol class="aw-steps"><?php foreach ($steps[$p] as $step) : ?><li><?php echo $step; // escaped above ?></li><?php endforeach; ?></ol>
						<div class="aw-fields">
							<div>
								<label class="au-label" for="aw-key-<?php echo esc_attr($p); ?>"><?php echo esc_html(sprintf(__('%s API key', 'y4apk'), $info['name'])); ?></label>
								<?php if (defined($info['const']) && constant($info['const'])) : ?>
									<p class="description"><?php echo esc_html(sprintf(__('Set in wp-config.php (%s).', 'y4apk'), $info['const'])); ?></p>
								<?php else : ?>
									<input type="password" id="aw-key-<?php echo esc_attr($p); ?>" name="keys[<?php echo esc_attr($p); ?>]" autocomplete="off"
										placeholder="<?php echo esc_attr($key !== '' ? '•••• ' . substr($key, -4) . ' — ' . __('saved; leave empty to keep it', 'y4apk') : $placeholder[$p]); ?>">
									<?php if ($key !== '') : ?><label><input type="checkbox" name="forget[<?php echo esc_attr($p); ?>]" value="1"> <?php esc_html_e('Remove the saved key', 'y4apk'); ?></label><?php endif; ?>
								<?php endif; ?>
							</div>
							<div>
								<label class="au-label" for="aw-model-<?php echo esc_attr($p); ?>"><?php esc_html_e('Model', 'y4apk'); ?></label>
								<select id="aw-model-<?php echo esc_attr($p); ?>" name="models[<?php echo esc_attr($p); ?>]" class="aw-model">
									<?php if (isset($auto[$p])) : ?>
										<option value="auto" <?php selected($o['models'][$p], 'auto'); ?>><?php echo esc_html($auto[$p] . ($list ? ' (' . self::pick($p, $list) . ')' : '')); ?></option>
									<?php endif; ?>
									<?php foreach ($names as $id => $label) : ?>
										<option value="<?php echo esc_attr($id); ?>" <?php selected($o['models'][$p], $id); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e('Test fills this list with the models your account has.', 'y4apk'); ?></p>
							</div>
						</div>
						<p><button type="button" class="button aw-test" data-provider="<?php echo esc_attr($p); ?>" <?php disabled($key === ''); ?>><?php esc_html_e('Test the connection', 'y4apk'); ?></button>
							<span class="aw-test-result au-meta"><?php echo $key === '' ? esc_html__('Save a key first.', 'y4apk') : ''; ?></span></p>
					</div>
				<?php endforeach; ?>

				<div class="au-settings" style="margin-top:20px">
					<div>
						<h2><?php esc_html_e('What to ask', 'y4apk'); ?></h2>
						<label class="au-label" for="aw-prompt"><?php esc_html_e('For games and apps', 'y4apk'); ?></label>
						<textarea id="aw-prompt" name="prompt" rows="3"><?php echo esc_textarea($o['prompt']); ?></textarea>
						<label class="au-label" for="aw-prompt-other"><?php esc_html_e('For other posts (no APK, e.g. Roblox scripts)', 'y4apk'); ?></label>
						<textarea id="aw-prompt-other" name="prompt_other" rows="3"><?php echo esc_textarea($o['prompt_other']); ?></textarea>
						<p class="description"><?php esc_html_e('{name} the app’s name · {type} เกม or แอป · {type_en} game or app · {mod} the MOD · {category} · {developer}', 'y4apk'); ?></p>
						<label><input type="checkbox" name="facts" value="1" <?php checked($o['facts']); ?>> <?php esc_html_e('Give the AI the app’s Google Play description to go on (so it does not guess), asking it not to copy it', 'y4apk'); ?></label>
					</div>
					<div>
						<h2><?php esc_html_e('How it is laid out', 'y4apk'); ?></h2>
						<label><input type="checkbox" name="link_name" value="1" <?php checked($o['link_name']); ?>> <?php esc_html_e('Link the app’s name (first words, in bold with its MOD) to its Google Play page', 'y4apk'); ?></label>
						<label><input type="checkbox" name="link_type" value="1" <?php checked($o['link_type']); ?>> <?php esc_html_e('Link the word “game” (or “app”) after the first paragraph to the post’s category', 'y4apk'); ?></label>
						<label><input type="checkbox" name="link_self" value="1" <?php checked($o['link_self']); ?>> <?php esc_html_e('Link the app’s name in the second paragraph to the post’s own permalink', 'y4apk'); ?></label>
						<label class="au-label" for="aw-heading" style="margin-top:10px"><?php esc_html_e('Closing heading (games and apps; empty: none)', 'y4apk'); ?></label>
						<input type="text" id="aw-heading" name="heading" value="<?php echo esc_attr($o['heading']); ?>">
						<h2 style="margin-top:16px"><?php esc_html_e('Post details', 'y4apk'); ?></h2>
						<label class="au-label" for="aw-title"><?php esc_html_e('Title (for titles with no “(MOD)” yet, or written here before)', 'y4apk'); ?></label>
						<input type="text" id="aw-title" name="title" value="<?php echo esc_attr($o['title']); ?>">
						<p class="description"><?php esc_html_e('{name} · {version}: Google Play’s latest while the post has no file, else the file’s · {mod}. Used when “One name everywhere” below is off.', 'y4apk'); ?></p>
						<label class="au-label" for="aw-seo-title"><?php esc_html_e('Rank Math SEO title (what Google shows)', 'y4apk'); ?></label>
						<input type="text" id="aw-seo-title" name="seo_title" value="<?php echo esc_attr($o['seo_title']); ?>">
						<label><input type="checkbox" name="seo_on" value="1" <?php checked($o['seo_on']); ?>> <?php echo esc_html(sprintf(__('Write it, at most %d characters: a long name is shortened (“ActionDirector - Video Editing” → “ActionDirector”), then “Download” left out', 'y4apk'), self::SEO_MAX)); ?></label>
						<p class="description"><?php esc_html_e('Only where Rank Math has the default title (%title% …) or one written here; one you typed stays.', 'y4apk'); ?></p>
						<label><input type="checkbox" name="match" value="1" <?php checked($o['match']); ?>> <?php esc_html_e('One name everywhere: the post title is this SEO title (“Mint MOD APK v6.0.3 (Premium Unlocked) Download”), and the article, its heading and the icon’s alt text say “Mint” too — as the permalink and the focus keyword, so Rank Math finds it everywhere', 'y4apk'); ?></label>
						<label><input type="checkbox" name="slug_on" value="1" <?php checked($o['slug_on']); ?>> <?php esc_html_e('Permalink from the short name: /actiondirector-mod/ (Rank Math’s focus keyword “ActionDirector MOD”; the old address redirects to it)', 'y4apk'); ?></label>
						<h2 style="margin-top:16px"><?php esc_html_e('MOD', 'y4apk'); ?></h2>
						<label><input type="checkbox" name="mod_lookup" value="1" <?php checked($o['mod_lookup']); ?>> <?php esc_html_e('Find the MOD that MOD APK sites list for each app (“Premium Unlocked”, “Unlimited Money”, “Menu, God Mode” …), with the article, in the same request', 'y4apk'); ?></label>
						<p class="description"><?php esc_html_e('Gemini and Claude search the web for it (Google Search / web search); Groq and OpenAI answer from what they know. A MOD you wrote yourself is never changed; one put in by Write with AI before is replaced.', 'y4apk'); ?></p>
						<div class="aw-fields">
							<div>
								<label class="au-label" for="aw-mod-default"><?php esc_html_e('Not found: games', 'y4apk'); ?></label>
								<input type="text" id="aw-mod-default" name="mod_default" value="<?php echo esc_attr($o['mod_default']); ?>" placeholder="<?php esc_attr_e('e.g. Unlimited Money (empty: none)', 'y4apk'); ?>">
							</div>
							<div>
								<label class="au-label" for="aw-mod-default-app"><?php esc_html_e('Not found: apps', 'y4apk'); ?></label>
								<input type="text" id="aw-mod-default-app" name="mod_default_app" value="<?php echo esc_attr($o['mod_default_app']); ?>" placeholder="<?php esc_attr_e('e.g. Premium Unlocked (empty: none)', 'y4apk'); ?>">
							</div>
						</div>
						<p class="description"><?php esc_html_e('Goes into the title, the article and App MOD Feature when the AI cannot tell (or finding it is off).', 'y4apk'); ?></p>
					</div>
				</div>
				<p style="margin:16px 0 0"><button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
			</form>

			<div class="au-card au-pad" style="grid-column:1/-1">
				<h2><?php esc_html_e('Try it on one post', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Shows exactly what the AI is asked and what the article would look like, without changing the post.', 'y4apk'); ?></p>
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
