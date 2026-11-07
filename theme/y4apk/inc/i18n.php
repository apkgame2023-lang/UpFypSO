<?php
/**
 * Languages and locations (2.51), the way YouTube, TikTok, X and Facebook do it.
 *
 * How they do it, and how this does the same:
 *
 * - The interface is translated; what people post is not. YouTube shows its
 *   menus and buttons in Thai while a video keeps its own title. Here the
 *   theme's words (menus, buttons, messages, the account pages, categories,
 *   menu items, the home page's heading) are translated; games, apps, scripts
 *   and articles stay as they were written.
 * - One message catalog, many languages. Every text in the code is a key
 *   (gettext: __('Sign in')); each language is a dictionary of those keys.
 *   Facebook and X have people translate the catalog; here Write with AI does,
 *   in the background, a batch at a time, and Y4APK ▸ Languages lets you fix
 *   any line. A language is offered to visitors only once 90% of it is done,
 *   as the big ones never show a half-translated language.
 * - A language has its own address: /th/, /id/, /vi/ … like Google's and
 *   Apple's pages. A page cache (10Web, Cloudflare) keeps one copy per
 *   address, so every visitor gets their language from the cache. The address
 *   is stripped before WordPress reads the request, so /th/some-game/ is the
 *   same post as /some-game/, and every link on a /th/ page leads to /th/.
 * - The choice is remembered (cookie y4_lang, like YouTube's PREF): opening
 *   any page without /th/ takes such a visitor to it. Someone new whose phone
 *   is set to a language the site has is asked once, in that language.
 * - Language and location are two settings, as on YouTube (hl and gl): the
 *   location (cookie y4_gl) starts as the country Cloudflare sees, or the one
 *   the browser's language names, and can be changed. Dates are written the
 *   way they are in that place.
 * - Search engines keep one address per page: a /th/ page says noindex and
 *   points (canonical) to the original, as its words are the same.
 */

defined('ABSPATH') || exit;

class Y4APK_I18n {

	const OPTION  = 'y4apk_i18n';           // ['langs' => [codes, in the order they are translated]]
	const CATALOG = 'y4apk_i18n_catalog';   // ['v' => theme version, 'at' => time, 'items' => [key => item]]
	const SEEN    = 'y4apk_i18n_seen';      // texts met on /xx/ pages that the scan did not find: [key => item]
	const DICT    = 'y4apk_i18n_dict_';     // + code: [key => translation]
	const LIVE    = 'y4apk_i18n_live';      // what pages need, loaded with WordPress: ['ready' => [codes], 'toast' => [code => [question, yes, no]]]
	const STATE   = 'y4apk_i18n_state';     // [code => ['done', 'total', 'bad' => [key => tries], 'mine' => [key => 1], 'at', 'err', 'toast']]
	const HOOK    = 'y4apk_i18n_tick';
	const SLUG    = 'y4apk-languages';
	const COOKIE  = 'y4_lang';
	const GL      = 'y4_gl';
	const READY   = 90;   // % of the catalog translated before visitors are offered a language
	const KEEP    = 75;   // … and it stays offered down to this (new texts after a theme update do not take it away)
	const PER_DAY = 100;  // questions a day in the background, so Write with AI keeps most of a free key's day
	const BATCH   = 50;   // texts per question to the AI
	const SITE    = 'y4apk site text'; // the context of the site's own texts: categories, menu items, the home page
	const ENGLISH = 'y4apk_i18n_english'; // [key => true: stays in English | false: translated], over english_default()
	const SEP     = "\x04";

	/** The language of this request ('' = English, the original) and whether its links get /xx/. */
	private static $lang = '';
	private static $links = false;
	private static $parsing = false;
	private static $raw_uri = null;
	private static $raw_info = null;
	private static $paused = 0;
	private static $dict = null;
	private static $missed = [];
	private static $collect = false; // misses are noted once the page is being made (not WordPress's start-up: the dashboard's labels)
	private static $home_path = null;
	private static $printed = false;

	/* ---------------------------------------------------------------- *
	 * Languages
	 * ---------------------------------------------------------------- */

	/**
	 * The languages the site can have: address code => [own name, English name,
	 * HTML lang, right to left]. Own names as the big apps list them.
	 */
	public static function languages() {
		return [
			'en'    => ['English', 'English', 'en', 0],
			'th'    => ['ไทย', 'Thai', 'th', 0],
			'id'    => ['Bahasa Indonesia', 'Indonesian', 'id', 0],
			'vi'    => ['Tiếng Việt', 'Vietnamese', 'vi', 0],
			'ms'    => ['Bahasa Melayu', 'Malay', 'ms', 0],
			'fil'   => ['Filipino', 'Filipino', 'fil', 0],
			'es'    => ['Español (Latinoamérica)', 'Spanish (Latin America)', 'es-419', 0],
			'pt'    => ['Português (Brasil)', 'Portuguese (Brazil)', 'pt-BR', 0],
			'ru'    => ['Русский', 'Russian', 'ru', 0],
			'uk'    => ['Українська', 'Ukrainian', 'uk', 0],
			'tr'    => ['Türkçe', 'Turkish', 'tr', 0],
			'hi'    => ['हिन्दी', 'Hindi', 'hi', 0],
			'bn'    => ['বাংলা', 'Bangla', 'bn', 0],
			'ur'    => ['اردو', 'Urdu', 'ur', 1],
			'ar'    => ['العربية', 'Arabic', 'ar', 1],
			'fa'    => ['فارسی', 'Persian', 'fa', 1],
			'he'    => ['עברית', 'Hebrew', 'he', 1],
			'ja'    => ['日本語', 'Japanese', 'ja', 0],
			'ko'    => ['한국어', 'Korean', 'ko', 0],
			'zh'    => ['简体中文', 'Chinese (Simplified)', 'zh-Hans', 0],
			'zh-tw' => ['繁體中文', 'Chinese (Traditional)', 'zh-Hant', 0],
			'fr'    => ['Français', 'French', 'fr', 0],
			'de'    => ['Deutsch', 'German', 'de', 0],
			'it'    => ['Italiano', 'Italian', 'it', 0],
			'pl'    => ['Polski', 'Polish', 'pl', 0],
			'nl'    => ['Nederlands', 'Dutch', 'nl', 0],
			'ro'    => ['Română', 'Romanian', 'ro', 0],
			'cs'    => ['Čeština', 'Czech', 'cs', 0],
			'hu'    => ['Magyar', 'Hungarian', 'hu', 0],
			'el'    => ['Ελληνικά', 'Greek', 'el', 0],
			'sv'    => ['Svenska', 'Swedish', 'sv', 0],
			'km'    => ['ខ្មែរ', 'Khmer', 'km', 0],
			'lo'    => ['ລາວ', 'Lao', 'lo', 0],
			'my'    => ['မြန်မာ', 'Burmese', 'my', 0],
			'ta'    => ['தமிழ்', 'Tamil', 'ta', 0],
			'te'    => ['తెలుగు', 'Telugu', 'te', 0],
			'mr'    => ['मराठी', 'Marathi', 'mr', 0],
			'kk'    => ['Қазақ тілі', 'Kazakh', 'kk', 0],
			'uz'    => ['Oʻzbek', 'Uzbek', 'uz', 0],
			'az'    => ['Azərbaycan', 'Azerbaijani', 'az', 0],
			'sw'    => ['Kiswahili', 'Swahili', 'sw', 0],
		];
	}

	/** The languages turned on at first: where most of an APK site's visitors are. */
	public static function default_langs() {
		return ['th', 'id', 'vi', 'pt', 'es', 'ru', 'hi', 'tr', 'fil', 'ms', 'ja', 'ko', 'zh', 'fr', 'de'];
	}

	/** The languages turned on (not English), in the order they are translated. */
	public static function enabled() {
		$o = get_option(self::OPTION, null);
		$list = is_array($o) && isset($o['langs']) && is_array($o['langs']) ? $o['langs'] : self::default_langs();
		$all = self::languages();
		return array_values(array_filter($list, function ($c) use ($all) {
			return $c !== 'en' && isset($all[$c]);
		}));
	}

	/** Languages visitors are offered: English and those at least READY% translated. */
	public static function ready() {
		return array_merge(['en'], array_values(array_intersect(self::live()['ready'], self::enabled())));
	}

	private static function live() {
		$live = get_option(self::LIVE, null);
		return is_array($live) && isset($live['ready'], $live['toast']) ? $live : self::publish();
	}

	/** Writes down what pages need to know (the ready languages, their first-visit question): one small option, loaded with WordPress. */
	public static function publish() {
		$state = self::state();
		$live = ['ready' => [], 'toast' => []];
		$was = get_option(self::LIVE, []);
		$was = is_array($was) && isset($was['ready']) && is_array($was['ready']) ? $was['ready'] : [];
		foreach (self::enabled() as $c) {
			$pct = self::percent($c, $state);
			if ($pct >= self::READY || ($pct >= self::KEEP && in_array($c, $was, true))) {
				$live['ready'][] = $c;
				if (!empty($state[$c]['toast'][0])) {
					$live['toast'][$c] = $state[$c]['toast'];
				}
			}
		}
		update_option(self::LIVE, $live, true);
		return $live;
	}

	public static function percent($code, $state = null) {
		$state = $state ?? self::state();
		$s = $state[$code] ?? [];
		$total = (int) ($s['total'] ?? 0);
		return $total ? (int) floor(100 * min((int) ($s['done'] ?? 0), $total) / $total) : 0;
	}

	public static function state() {
		$s = get_option(self::STATE, []);
		return is_array($s) ? $s : [];
	}

	/** This request's language ('en' when it is the original). */
	public static function current() {
		return self::$lang !== '' ? self::$lang : 'en';
	}

	public static function html_lang($code = null) {
		$all = self::languages();
		$code = $code ?? self::current();
		return isset($all[$code]) ? $all[$code][2] : 'en';
	}

	/* ---------------------------------------------------------------- *
	 * The address: /th/… (read before WordPress parses the request)
	 * ---------------------------------------------------------------- */

	public static function init() {
		self::detect();

		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::HOOK, [__CLASS__, 'tick']);
		add_action('wp_ajax_y4apk_geo', [__CLASS__, 'ajax_geo']);
		add_action('wp_ajax_nopriv_y4apk_geo', [__CLASS__, 'ajax_geo']);

		if (is_admin() && !wp_doing_ajax()) {
			add_action('admin_menu', [__CLASS__, 'register_page'], 108);
			add_action('admin_init', [__CLASS__, 'schedule']);
			add_action('admin_init', [__CLASS__, 'upgrade']);
			add_action('admin_post_y4apk_i18n_save', [__CLASS__, 'handle_save']);
			add_action('admin_post_y4apk_i18n_texts', [__CLASS__, 'handle_texts']);
		}
		add_action('wp_ajax_y4apk_i18n_run', [__CLASS__, 'ajax_run']);

		// Every request in a language (pages, and the AJAX they make) speaks it.
		add_filter('gettext_y4apk', [__CLASS__, 'f_gettext'], 10, 2);
		add_filter('ngettext_y4apk', [__CLASS__, 'f_ngettext'], 10, 4);
		add_filter('gettext_with_context_y4apk', [__CLASS__, 'f_gettext_x'], 10, 3);
		add_filter('ngettext_with_context_y4apk', [__CLASS__, 'f_ngettext_x'], 10, 5);
		add_filter('gettext_default', [__CLASS__, 'f_core'], 10, 2);
		add_filter('ngettext_default', [__CLASS__, 'f_core_n'], 10, 4);
		add_filter('gettext_with_context_default', [__CLASS__, 'f_core_x'], 10, 3);

		if (self::$lang === '') {
			add_action('wp_head', [__CLASS__, 'head_redirect'], 0);
			return;
		}
		// WordPress made its month and day names before the theme loaded: again, in the language.
		if (isset($GLOBALS['wp_locale']) && class_exists('WP_Locale')) {
			$GLOBALS['wp_locale'] = new WP_Locale();
		}
		add_action('shutdown', [__CLASS__, 'remember_missed'], 0);
		add_action(wp_doing_ajax() ? 'admin_init' : 'wp', function () {
			self::$collect = true;
		}, -1000);
		if (!self::$links) {
			return;
		}
		add_filter('do_parse_request', [__CLASS__, 'strip'], PHP_INT_MAX);
		add_action('parse_request', [__CLASS__, 'unstrip'], -1000);
		add_action('wp', [__CLASS__, 'unstrip'], -1000);
		add_filter('home_url', [__CLASS__, 'f_home_url'], 20, 3);
		add_filter('redirect_canonical', '__return_false', 99);
		add_filter('language_attributes', [__CLASS__, 'f_language_attributes'], 20);
		add_filter('wp_robots', [__CLASS__, 'f_wp_robots'], 99);
		add_filter('rank_math/frontend/robots', [__CLASS__, 'f_rank_math_robots'], 99);
		add_filter('get_canonical_url', [__CLASS__, 'original_url'], 99);
		add_filter('rank_math/frontend/canonical', [__CLASS__, 'original_url'], 99);
		add_filter('rank_math/opengraph/url', [__CLASS__, 'original_url'], 99);
		add_filter('rank_math/frontend/disable_hreflang', '__return_true');
		// Rank Math compares the site's address with the one it was connected with, on every page: /th/ is not a move
		add_filter('rank_math/registration/do_url_check', '__return_false', 99);
		add_filter('get_term', [__CLASS__, 'f_term'], 20, 2);
		add_filter('wp_get_nav_menu_items', [__CLASS__, 'f_menu_items'], 20);
		foreach (['hero_title', 'hero_btn_name'] as $mod) {
			add_filter('theme_mod_' . $mod, [__CLASS__, 'site_text'], 20);
		}
		// Kept as they are in the database: a post's guid, a comment's address.
		add_filter('wp_insert_post_data', [__CLASS__, 'f_post_data'], 99);
		add_filter('preprocess_comment', [__CLASS__, 'f_comment_data'], 99);
		add_action('wp_head', [__CLASS__, 'head_note'], 1);
	}

	/** The address's language (/th/…), or — for AJAX — the language of the page that asked. */
	private static function detect() {
		if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		if (wp_doing_ajax()) {
			$ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
			$host = (string) wp_parse_url($ref, PHP_URL_HOST);
			if ($host !== '' && strtolower($host) === strtolower((string) wp_parse_url(get_option('home'), PHP_URL_HOST))) {
				self::$lang = self::code_in((string) wp_parse_url($ref, PHP_URL_PATH));
			}
			return;
		}
		if (is_admin()) {
			return;
		}
		$path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
		self::$lang = self::code_in($path);
		self::$links = self::$lang !== '';
	}

	/** The site's own path ('' when it is at the root of its domain). */
	private static function home_path() {
		if (self::$home_path === null) {
			self::$home_path = untrailingslashit((string) wp_parse_url((string) get_option('home'), PHP_URL_PATH));
		}
		return self::$home_path;
	}

	/** The language code at the start of a path ("/th/game/" → "th"), when it is turned on. */
	public static function code_in($path) {
		$hp = self::home_path();
		$path = (string) $path;
		if ($hp !== '' && strpos($path . '/', $hp . '/') !== 0) {
			return '';
		}
		$rest = substr($path, strlen($hp));
		if (!preg_match('~^/([a-z]{2,3}(?:-[a-z]{2})?)(?:/|$)~', $rest, $m)) {
			return '';
		}
		return in_array($m[1], self::enabled(), true) ? $m[1] : '';
	}

	/** "/th/game/?x=1" → "/game/?x=1". */
	private static function strip_path($uri) {
		$hp = self::home_path();
		$re = '~^(' . preg_quote($hp, '~') . ')/' . preg_quote(self::$lang, '~') . '(?=/|\?|#|$)~';
		$out = preg_replace($re, '$1', (string) $uri, 1);
		return $out === '' || $out[0] === '?' || $out[0] === '#' ? '/' . $out : $out;
	}

	/** WordPress reads the address without the language… */
	public static function strip($do) {
		if ($do && self::$raw_uri === null && isset($_SERVER['REQUEST_URI'])) {
			self::$raw_uri = (string) $_SERVER['REQUEST_URI'];
			$_SERVER['REQUEST_URI'] = self::strip_path(self::$raw_uri);
			if (!empty($_SERVER['PATH_INFO'])) {
				self::$raw_info = (string) $_SERVER['PATH_INFO'];
				$_SERVER['PATH_INFO'] = self::strip_path(self::$raw_info);
			}
			self::$parsing = true;
		}
		return $do;
	}

	/** … and everything after it sees the address as it was asked for (page caches keep each language apart). */
	public static function unstrip() {
		if (self::$raw_uri !== null) {
			$_SERVER['REQUEST_URI'] = self::$raw_uri;
			if (self::$raw_info !== null) {
				$_SERVER['PATH_INFO'] = self::$raw_info;
			}
			self::$raw_uri = self::$raw_info = null;
		}
		self::$parsing = false;
	}

	/** Addresses that stay as they are: WordPress's own files, feeds, files, the app's service worker. */
	private static function keeps($path) {
		$p = ltrim((string) $path, '/');
		if ($p === '') {
			return false;
		}
		if (strpos($p, 'y4apk_pwa=') !== false || strpos($p, 'y4apk-pwa') !== false) {
			return true;
		}
		$route = (string) preg_replace('~[?#].*$~s', '', $p);
		return (bool) preg_match('~^(wp-|xmlrpc|\.well-known|feed(/|$))|/feed/?$|\.[a-z0-9]{2,5}$~i', $route);
	}

	/** "https://site.com/game/" → "https://site.com/th/game/". */
	public static function add_code($url, $code) {
		if ($code === '' || $code === 'en') {
			return $url;
		}
		$hp = self::home_path();
		$re = '~^((?:https?:)?//[^/?#]+)?(' . preg_quote($hp, '~') . ')(?=/|\?|#|$)~i';
		if (!preg_match($re, (string) $url, $m)) {
			return $url;
		}
		$head = $m[0];
		$tail = (string) substr((string) $url, strlen($head));
		if (preg_match('~^/' . preg_quote($code, '~') . '(?=/|\?|#|$)~', $tail)) {
			return $url; // already
		}
		return $head . '/' . $code . ($tail === '' || $tail[0] !== '/' ? ($tail === '' ? '' : '/' . ltrim($tail, '/')) : $tail);
	}

	/** The same address without its language (canonical, Open Graph). */
	public static function original_url($url) {
		if (!is_string($url) || self::$lang === '') {
			return $url;
		}
		$hp = self::home_path();
		return (string) preg_replace('~^((?:https?:)?//[^/?#]+' . preg_quote($hp, '~') . ')/' . preg_quote(self::$lang, '~') . '(?=/|\?|#|$)~i', '$1', $url, 1);
	}

	public static function f_home_url($url, $path, $scheme) {
		if (!self::$links || self::$parsing || self::$paused || $scheme === 'rest' || self::keeps($path)) {
			return $url;
		}
		return self::add_code($url, self::$lang);
	}

	public static function f_language_attributes($out) {
		$lang = self::html_lang();
		return preg_match('/\blang="[^"]*"/', $out) ? preg_replace('/\blang="[^"]*"/', 'lang="' . esc_attr($lang) . '"', $out) : trim($out . ' lang="' . esc_attr($lang) . '"');
	}

	public static function f_wp_robots($robots) {
		unset($robots['index']);
		$robots['noindex'] = true;
		$robots['follow'] = true;
		return $robots;
	}

	public static function f_rank_math_robots($robots) {
		$robots = is_array($robots) ? $robots : [];
		$robots['index'] = 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	public static function f_post_data($data) {
		if (isset($data['guid']) && is_string($data['guid'])) {
			$data['guid'] = self::original_url($data['guid']);
		}
		return $data;
	}

	public static function f_comment_data($data) {
		if (!empty($data['comment_author_url'])) {
			$data['comment_author_url'] = self::original_url($data['comment_author_url']);
		}
		return $data;
	}

	/** Without a page cache's own robots header, a meta tag says it too (Rank Math's or WordPress's is there already). */
	public static function head_note() {
		if (!has_action('wp_head', 'wp_robots') && !class_exists('RankMath')) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		}
	}

	/**
	 * A visitor who chose a language, on a page without it (a search result, an
	 * old link): to the same page in their language, before anything shows.
	 * In the page, so a cached page does it for everyone who chose.
	 */
	public static function head_redirect() {
		if (is_admin() || is_feed() || is_robots() || (function_exists('is_embed') && is_embed())) {
			return;
		}
		$langs = array_values(array_diff(self::ready(), ['en']));
		if (!$langs) {
			return;
		}
		$base = trailingslashit(self::home_path());
		?>
<script id="y4apk-pwa-lang-go" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function(){try{var m=document.cookie.match(/(?:^|;\s*)<?php echo self::COOKIE; ?>=([a-z-]{2,6})/),L=<?php echo wp_json_encode($langs); ?>,b=<?php echo wp_json_encode($base); ?>,p=location.pathname;if(!m||L.indexOf(m[1])<0||p.indexOf(b)!==0)return;var r=p.slice(b.length);if(/^(wp-|feed(\/|$))/.test(r))return;var f=r.split('/')[0];if(L.indexOf(f)>-1||f==='en')return;location.replace(b+m[1]+'/'+r+location.search+location.hash);}catch(e){}})();
</script>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Translating: the theme's texts, a few of WordPress's, the site's own
	 * ---------------------------------------------------------------- */

	/** Turns translation off for what is saved, not shown ("by %s" in a post title). */
	public static function pause() {
		self::$paused++;
	}

	public static function resume() {
		self::$paused = max(0, self::$paused - 1);
	}

	/** The result of $fn in language $code (words made in the background for every language: versions.php's). */
	public static function in($code, $fn) {
		$keep = [self::$lang, self::$dict, self::$paused, self::$collect];
		self::$lang = $code === 'en' ? '' : (string) $code;
		self::$dict = null;
		self::$paused = 0;
		self::$collect = false;
		try {
			return $fn();
		} finally {
			list(self::$lang, self::$dict, self::$paused, self::$collect) = $keep;
		}
	}

	/** The result of $fn in English, whatever the page's language. */
	public static function untranslated($fn) {
		self::pause();
		try {
			return $fn();
		} finally {
			self::resume();
		}
	}

	private static function dict() {
		if (self::$dict === null) {
			$d = self::$lang !== '' ? get_option(self::DICT . self::$lang, []) : [];
			self::$dict = is_array($d) ? $d : [];
		}
		return self::$dict;
	}

	/** A translation of $key, or null; a miss is remembered so it can be translated. */
	private static $english = null;

	/**
	 * Words that stay in English in every language (2.51.2): the site's own
	 * names — its categories ("Games", "Apps", so "Popular Games"), menu items,
	 * the home page's heading and button — and the home page's badges ("Best of
	 * the best", "Recommend"), as the brand has them. Any other text can be
	 * kept the same way in Y4APK ▸ Languages ▸ Edit texts, and these let go.
	 */
	public static function english_default($key) {
		return strpos($key, self::SITE . self::SEP) === 0 || in_array($key, ['Best of the best', 'Recommend', 'All %s'], true);
	}

	public static function kept($key) {
		if (self::$english === null) {
			$e = get_option(self::ENGLISH, []);
			self::$english = is_array($e) ? $e : [];
		}
		return isset(self::$english[$key]) ? (bool) self::$english[$key] : self::english_default($key);
	}

	private static function look($key, $text, $ctx, $plural = '') {
		if (self::$lang === '' || self::$paused || self::kept($key)) {
			return null;
		}
		$d = self::dict();
		if (isset($d[$key]) && $d[$key] !== '') {
			return $d[$key];
		}
		if (self::$collect && count(self::$missed) < 400 && strlen($text) <= 600 && trim($text) !== '') {
			self::$missed[$key] = ['s' => $text, 'c' => $ctx] + ($plural !== '' ? ['pl' => $plural] : []);
		}
		return null;
	}

	public static function f_gettext($tr, $text) {
		$t = self::look($text, $text, '');
		return $t ?? $tr;
	}

	public static function f_ngettext($tr, $single, $plural, $n) {
		$key = (int) $n === 1 ? $single : $plural;
		$t = self::look($key, $key, '');
		return $t ?? $tr;
	}

	public static function f_gettext_x($tr, $text, $ctx) {
		$t = self::look($ctx . self::SEP . $text, $text, $ctx);
		return $t ?? $tr;
	}

	public static function f_ngettext_x($tr, $single, $plural, $n, $ctx) {
		$key = (int) $n === 1 ? $single : $plural;
		$t = self::look($ctx . self::SEP . $key, $key, $ctx);
		return $t ?? $tr;
	}

	/**
	 * WordPress's own words that pages show: "3 days" (how long ago), month and
	 * day names. Only these; the rest of WordPress's is for its dashboard.
	 */
	public static function core_texts() {
		$out = [];
		foreach (['second', 'min', 'hour', 'day', 'week', 'month', 'year'] as $u) {
			$out['%s ' . $u] = 1;
			$out['%s ' . $u . 's'] = 1;
		}
		foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December',
			'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $w) {
			$out[$w] = 1;
		}
		foreach (['Jan' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Apr' => 'April', 'May' => 'May', 'Jun' => 'June', 'Jul' => 'July',
			'Aug' => 'August', 'Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dec' => 'December'] as $a => $m) {
			$out[$m . ' abbreviation' . self::SEP . $a] = 1;
		}
		$out['May' . self::SEP . 'May'] = 0; // (nothing: "May" is both)
		unset($out['May' . self::SEP . 'May']);
		return $out;
	}

	private static $core = null;

	private static function core_key($key) {
		if (self::$core === null) {
			self::$core = self::core_texts();
		}
		return isset(self::$core[$key]);
	}

	public static function f_core($tr, $text) {
		if (self::$lang === '' || !self::core_key($text)) {
			return $tr;
		}
		$t = self::look('wp' . self::SEP . $text, $text, 'wp');
		return $t ?? $tr;
	}

	public static function f_core_n($tr, $single, $plural, $n) {
		$key = (int) $n === 1 ? $single : $plural;
		if (self::$lang === '' || !self::core_key($key)) {
			return $tr;
		}
		$t = self::look('wp' . self::SEP . $key, $key, 'wp');
		return $t ?? $tr;
	}

	public static function f_core_x($tr, $text, $ctx) {
		if (self::$lang === '' || !self::core_key($ctx . self::SEP . $text)) {
			return $tr;
		}
		$t = self::look('wp' . self::SEP . $ctx . self::SEP . $text, $text, 'wp ' . $ctx);
		return $t ?? $tr;
	}

	/** A text of the site's own (a category, a menu item, the home page's heading). */
	public static function site_text($text) {
		if (!is_string($text) || trim($text) === '' || self::$paused || is_admin()) {
			return $text;
		}
		$plain = trim(wp_strip_all_tags($text));
		if ($plain === '' || $plain !== trim($text)) {
			return $text; // with HTML: as it is
		}
		if (isset(self::$made[$plain])) {
			return $text; // translated already (a term WordPress hands round again)
		}
		$t = self::look(self::SITE . self::SEP . $plain, $plain, self::SITE);
		if ($t !== null) {
			self::$made[trim($t)] = 1;
		}
		return $t ?? $text;
	}

	private static $made = [];

	public static function f_term($term, $tax) {
		if ($term instanceof WP_Term && $tax === 'category' && !self::$paused && !is_admin() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
			$term->name = self::site_text($term->name);
		}
		return $term;
	}

	public static function f_menu_items($items) {
		if (is_admin() || !is_array($items)) {
			return $items;
		}
		foreach ($items as $item) {
			if (isset($item->title) && is_string($item->title)) {
				$item->title = self::site_text($item->title);
			}
		}
		return $items;
	}

	/** Texts a /xx/ page met that the catalog lacks (shown by a part the scan does not read): added, to be translated. */
	public static function remember_missed() {
		if (!self::$missed) {
			return;
		}
		$cat = self::catalog();
		$seen = get_option(self::SEEN, []);
		$seen = is_array($seen) ? $seen : [];
		$new = 0;
		foreach (self::$missed as $key => $item) {
			if (isset($cat['items'][$key]) || isset($seen[$key]) || count($seen) >= 3000) {
				continue;
			}
			$seen[$key] = $item + ['f' => 'seen', 'g' => 1];
			$new++;
		}
		if ($new) {
			update_option(self::SEEN, $seen, false);
		}
		self::$missed = [];
	}

	/* ---------------------------------------------------------------- *
	 * The catalog: every text the visitors' side of the theme can show
	 * ---------------------------------------------------------------- */

	/** The theme's files that are only for the dashboard (their texts are left out; any a page does show is caught by remember_missed()). */
	private static function admin_files() {
		return ['inc/writer.php', 'inc/app-updates.php', 'inc/dashboard.php', 'inc/customization.php', 'inc/r2.php', 'inc/r2-images.php', 'inc/updater.php',
			'inc/migrate.php', 'inc/stats.php', 'inc/logo.php', 'inc/members-admin.php', 'inc/members-list.php', 'inc/members-pictures.php', 'inc/members-insights.php',
			'inc/apk-check.php', 'inc/rest-auth-debug.php', 'inc/rest-api.php', 'inc/discover.php', 'inc/inits.php', 'inc/pwa.php', 'inc/stats-events.php',
			'stats.php', 'status.php', 'rank-math.php'];
	}

	/** First the texts every page shows, then the rest (lower is sooner). */
	private static function rank($file) {
		if ($file === 'site' || preg_match('~^(header|footer|single|index|archive|search|404|page)\.php$|^template/(header|footer)\.php$|^components/~', $file) || $file === 'inc/i18n.php' || $file === 'wp') {
			return 0;
		}
		if ($file === 'seen' || strpos($file, 'template/') === 0 || preg_match('~^inc/(members|members-share|members-social|members-versions|share-dialog|scripts|roblox|executors)\.php$~', $file)) {
			return 1;
		}
		return 2;
	}

	public static function catalog() {
		$c = get_option(self::CATALOG, []);
		return is_array($c) && isset($c['items']) && is_array($c['items']) ? $c : ['v' => '', 'at' => 0, 'items' => []];
	}

	/** Everything to translate: the catalog and the texts pages met, sooner ones first. */
	public static function items() {
		$c = self::catalog();
		if ($c['v'] !== Y4APK_THEME_VERSION) {
			$c = self::scan();
		}
		$seen = get_option(self::SEEN, []);
		$items = $c['items'] + (is_array($seen) ? $seen : []);
		uasort($items, function ($a, $b) {
			return (int) ($a['g'] ?? 2) <=> (int) ($b['g'] ?? 2);
		});
		return $items;
	}

	/** Reads the theme's files for __(), _e(), esc_html__(), _x(), _n() … in the y4apk domain; adds the site's own texts. */
	public static function scan() {
		$root = get_template_directory();
		$skip = array_flip(self::admin_files());
		$items = [];
		$fns = ['__' => [1, 2, 0], '_e' => [1, 2, 0], 'esc_html__' => [1, 2, 0], 'esc_html_e' => [1, 2, 0], 'esc_attr__' => [1, 2, 0], 'esc_attr_e' => [1, 2, 0],
			'_x' => [1, 3, 2], '_ex' => [1, 3, 2], 'esc_html_x' => [1, 3, 2], 'esc_attr_x' => [1, 3, 2], '_n' => [2, 4, 0], '_nx' => [2, 5, 4], '_n_noop' => [2, 3, 0], '_nx_noop' => [2, 4, 3]];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$path = str_replace('\\', '/', (string) $file);
			if (substr($path, -4) !== '.php' || strpos($path, '/inc/tgm/') !== false || strpos($path, '/node_modules/') !== false) {
				continue;
			}
			$rel = ltrim(substr($path, strlen($root)), '/');
			if (isset($skip[$rel])) {
				continue;
			}
			foreach (self::scan_file((string) file_get_contents($path), $fns) as $found) {
				list($text, $ctx, $plural) = $found;
				$texts = $plural !== '' ? [$text, $plural] : [$text];
				foreach ($texts as $t) {
					$key = ($ctx !== '' ? $ctx . self::SEP : '') . $t;
					if (!isset($items[$key])) {
						$items[$key] = ['s' => $t, 'c' => $ctx, 'f' => $rel, 'g' => self::rank($rel)];
					}
				}
			}
		}
		foreach (self::core_texts() as $key => $one) {
			$parts = explode(self::SEP, $key);
			$text = end($parts);
			$items['wp' . self::SEP . $key] = ['s' => $text, 'c' => count($parts) > 1 ? 'wp ' . $parts[0] : 'wp', 'f' => 'wp', 'g' => 0];
		}
		foreach (self::site_texts() as $text) {
			$items[self::SITE . self::SEP . $text] = ['s' => $text, 'c' => self::SITE, 'f' => 'site', 'g' => 0];
		}
		$c = ['v' => Y4APK_THEME_VERSION, 'at' => time(), 'items' => $items];
		update_option(self::CATALOG, $c, false);
		$seen = get_option(self::SEEN, []);
		if (is_array($seen) && $seen) {
			update_option(self::SEEN, array_diff_key($seen, $items), false); // now in the catalog
		}
		self::count_all();
		return $c;
	}

	/** [[text, context, plural]] for each gettext call with literal texts in the y4apk domain. */
	private static function scan_file($src, $fns) {
		$out = [];
		if (!function_exists('token_get_all')) {
			preg_match_all("~\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'y4apk'\\s*\\)~", $src, $m);
			foreach ($m[1] as $s) {
				$out[] = [stripcslashes($s), '', ''];
			}
			return $out;
		}
		$t = token_get_all($src);
		$n = count($t);
		for ($i = 0; $i < $n; $i++) {
			if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || !isset($fns[$t[$i][1]])) {
				continue;
			}
			$prev = $i - 1;
			while ($prev >= 0 && is_array($t[$prev]) && $t[$prev][0] === T_WHITESPACE) {
				$prev--;
			}
			if ($prev >= 0 && is_array($t[$prev]) && in_array($t[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
				continue;
			}
			$j = $i + 1;
			while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
				$j++;
			}
			if (!isset($t[$j]) || $t[$j] !== '(') {
				continue;
			}
			$args = [];
			$cur = [];
			$depth = 0;
			for ($k = $j + 1; $k < $n; $k++) {
				$x = $t[$k];
				if ($x === '(' || $x === '[' || $x === '{') {
					$depth++;
				} elseif ($x === ')' || $x === ']' || $x === '}') {
					if ($depth === 0) {
						$args[] = $cur;
						break;
					}
					$depth--;
				} elseif ($x === ',' && $depth === 0) {
					$args[] = $cur;
					$cur = [];
					continue;
				}
				if (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				$cur[] = $x;
			}
			list($count, $dpos, $cpos) = $fns[$t[$i][1]];
			$dom = isset($args[$dpos - 1]) ? $args[$dpos - 1] : [];
			if (count($dom) !== 1 || !is_array($dom[0]) || !in_array($dom[0][1], ["'y4apk'", '"y4apk"', 'Y4APK_TRANSLATE'], true)) {
				continue;
			}
			$lit = function ($a) {
				if (count($a) !== 1 || !is_array($a[0]) || $a[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
					return null;
				}
				$raw = $a[0][1];
				$body = substr($raw, 1, -1);
				return $raw[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
			};
			$text = $lit($args[0] ?? []);
			if ($text === null || $text === '') {
				continue;
			}
			$plural = $count === 2 ? $lit($args[1] ?? []) : '';
			$ctx = $cpos ? $lit($args[$cpos - 1] ?? []) : '';
			$out[] = [$text, (string) $ctx, (string) $plural];
		}
		return $out;
	}

	/** The site's own words: its categories, its menus' items, the home page's heading and button. */
	private static function site_texts() {
		$out = [];
		$terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 400, 'fields' => 'names']);
		foreach (is_array($terms) ? $terms : [] as $name) {
			$out[] = (string) $name; // as WordPress keeps it ("Action &amp; Adventure"), as the page asks for it
		}
		foreach ((array) get_nav_menu_locations() as $menu) {
			foreach ((array) wp_get_nav_menu_items($menu) as $item) {
				if (isset($item->title)) {
					$out[] = (string) $item->title;
				}
			}
		}
		foreach (['hero_title' => 'Large Category of the most popular games with mods', 'hero_btn_name' => 'All Games'] as $mod => $def) {
			$out[] = (string) get_theme_mod($mod, $def);
		}
		$out = array_map(function ($s) {
			return trim(wp_strip_all_tags($s));
		}, $out);
		return array_values(array_unique(array_filter($out, function ($s) {
			return $s !== '' && strlen($s) <= 300 && !is_numeric($s);
		})));
	}

	/* ---------------------------------------------------------------- *
	 * Translating with Write with AI, a batch at a time, in the background
	 * ---------------------------------------------------------------- */

	public static function schedules($s) {
		if (!isset($s['y4apk_5min'])) {
			$s['y4apk_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Y4APK)'];
		}
		return $s;
	}

	public static function schedule() {
		$todo = false;
		foreach (self::state() as $code => $s) {
			if (in_array($code, self::enabled(), true) && (int) ($s['done'] ?? 0) + count($s['bad'] ?? []) < (int) ($s['total'] ?? 1)) {
				$todo = true;
			}
		}
		$todo = $todo || array_diff(self::enabled(), array_keys(self::state()));
		$next = wp_next_scheduled(self::HOOK);
		if ($todo && !$next) {
			wp_schedule_event(time() + 60, 'y4apk_5min', self::HOOK);
		} elseif (!$todo && $next && !self::enabled()) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	/**
	 * 2.51.1, once: Rank Math's "site URL has changed" that a /th/ page made it
	 * write (it writes it again by itself if the address really changed), and
	 * the texts the AI got wrong are asked again (the check of %s was too strict).
	 */
	public static function upgrade() {
		if (get_option('y4apk_i18n_v') === '2.51.1') {
			return;
		}
		update_option('y4apk_i18n_v', '2.51.1');
		try {
			if (class_exists('\\RankMath\\Helper') && method_exists('\\RankMath\\Helper', 'remove_notification') && function_exists('rank_math') && !empty(rank_math()->notification)) {
				\RankMath\Helper::remove_notification('rank-math-site-url-mismatch');
			}
		} catch (\Throwable $e) {
			// Rank Math changed: nothing to tidy
		}
		$st = self::state();
		foreach ($st as $code => $s) {
			if (is_array($s)) {
				$st[$code]['bad'] = [];
				$st[$code]['size'] = self::BATCH;
				$st[$code]['streak'] = 0;
			}
		}
		update_option(self::STATE, $st, false);
		self::count_all();
	}

	/** Can the AI translate? (a key in Write with AI ▸ Settings) */
	public static function can_translate() {
		if (!class_exists('Y4APK_Writer')) {
			return false;
		}
		foreach (Y4APK_Writer::settings()['keys'] as $k) {
			if ($k !== '') {
				return true;
			}
		}
		return false;
	}

	public static function tick() {
		if (get_transient('y4apk_i18n_lock') || !self::can_translate()) {
			return;
		}
		$wait = get_transient('y4apk_i18n_wait');
		if ($wait && $wait > time()) {
			return;
		}
		$day = 'y4apk_i18n_day_' . gmdate('Ymd');
		if ((int) get_transient($day) >= self::PER_DAY) {
			return; // the rest tomorrow (Translate now in Y4APK ▸ Languages goes on today)
		}
		set_transient('y4apk_i18n_lock', 1, 2 * MINUTE_IN_SECONDS);
		try {
			self::work(45, '', $day);
		} finally {
			delete_transient('y4apk_i18n_lock');
		}
	}

	/** Translates for up to $budget seconds: one language after another, the texts pages show first. */
	public static function work($budget, $only = '', $day = '') {
		@set_time_limit(max(60, (int) $budget + 60));
		$start = microtime(true);
		$items = self::items();
		$done = 0;
		foreach (self::enabled() as $code) {
			if ($only !== '' && $code !== $only) {
				continue;
			}
			while (microtime(true) - $start < $budget) {
				$todo = self::todo($code, $items, self::size($code));
				if (!$todo) {
					break;
				}
				if ($day !== '') {
					$n = (int) get_transient($day);
					if ($n >= self::PER_DAY) {
						return $done;
					}
					set_transient($day, $n + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS);
				}
				$r = self::translate($code, $todo);
				if (is_wp_error($r)) {
					$st = self::state();
					$st[$code]['err'] = $r->get_error_message();
					update_option(self::STATE, $st, false);
					if ($r->get_error_code() === 'busy') {
						$data = $r->get_error_data();
						$wait = max(20, (int) ($data['wait'] ?? 120));
						set_transient('y4apk_i18n_wait', time() + $wait, $wait);
						return new WP_Error('busy', $r->get_error_message(), ['wait' => $wait]);
					}
					return $r;
				}
				$done += $r;
			}
			if (microtime(true) - $start >= $budget) {
				break;
			}
		}
		return $done;
	}

	/** Texts per question for a language: halved after a question that went wrong, back up after one that went right. */
	private static function size($code) {
		$st = self::state();
		$n = (int) ($st[$code]['size'] ?? self::BATCH);
		return max(5, min(self::BATCH, $n ?: self::BATCH));
	}

	/**
	 * A question that went wrong for this batch (an answer that was not the
	 * list, cut off, or declined): asked again in smaller pieces, and only a
	 * piece of 5 that still goes wrong counts against its texts (twice, then
	 * they wait for you). So one odd text never holds a language up.
	 */
	private static function failed($code, $todo, $why) {
		$st = self::state();
		$size = self::size($code);
		$streak = (int) ($st[$code]['streak'] ?? 0) + 1; // wrong in a row: the AI itself, not a text, after a few
		$st[$code]['streak'] = $streak;
		if ($size > 5) {
			$st[$code]['size'] = max(5, intdiv($size, 2));
		} elseif ($streak <= 4) {
			$bad = isset($st[$code]['bad']) && is_array($st[$code]['bad']) ? $st[$code]['bad'] : [];
			foreach (array_keys($todo) as $key) {
				$bad[$key] = (int) ($bad[$key] ?? 0) + 1;
			}
			$st[$code]['bad'] = $bad;
		}
		$st[$code]['err'] = (string) $why;
		update_option(self::STATE, $st, false);
		if ($streak >= 3) {
			// Three wrong in a row: not asked again for 15 minutes (a free key's day is not spent on it)
			return new WP_Error('busy', (string) $why, ['wait' => 15 * MINUTE_IN_SECONDS]);
		}
		return 0;
	}

	/** The AI's answer as [number => text]: the JSON, or — cut off or a little broken — the pairs in it that are whole. */
	private static function parse($answer) {
		$a = strpos($answer, '{');
		$b = strrpos($answer, '}');
		if ($a !== false && $b !== false && $b > $a) {
			$j = json_decode(substr($answer, $a, $b - $a + 1), true);
			if (is_array($j)) {
				return $j;
			}
		}
		$out = [];
		if (preg_match_all('/"(\d+)"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/', $answer, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$v = json_decode($x[2]);
				if (is_string($v)) {
					$out[$x[1]] = $v;
				}
			}
		}
		return $out;
	}

	/** The texts of $code still to translate (left out: those the AI got wrong twice). */
	private static function todo($code, $items, $max) {
		$dict = get_option(self::DICT . $code, []);
		$dict = is_array($dict) ? $dict : [];
		$st = self::state();
		$bad = isset($st[$code]['bad']) && is_array($st[$code]['bad']) ? $st[$code]['bad'] : [];
		$out = [];
		$chars = 0;
		foreach ($items as $key => $it) {
			if ((isset($dict[$key]) && $dict[$key] !== '') || (int) ($bad[$key] ?? 0) >= 2 || self::kept($key)) {
				continue;
			}
			$out[$key] = $it;
			$chars += strlen($it['s']);
			if (count($out) >= $max || $chars > 5000) {
				break;
			}
		}
		return $out;
	}

	/** Placeholders, tags and {words} a translation must keep: [sorted list]. */
	private static function marks($s) {
		preg_match_all('~%(?:\d+\$)?(?:\d+)?(?:\.\d+)?[sdfuxXc]|%%|</?[a-z][^>]*>|\{[a-z_]+\}~', (string) $s, $m);
		$list = array_map(function ($x) {
			return preg_replace('/\s+/', ' ', $x);
		}, $m[0]);
		sort($list);
		return $list;
	}

	/** Asks the AI for one batch; saves what came back right. The number saved, or a WP_Error. */
	public static function translate($code, $todo) {
		$all = self::languages();
		if (!isset($all[$code]) || !$todo) {
			return 0;
		}
		list($native, $english) = $all[$code];
		$lines = [];
		$map = [];
		$i = 0;
		foreach ($todo as $key => $it) {
			$i++;
			$map[(string) $i] = $key;
			$note = '';
			if ($it['c'] === self::SITE) {
				$note = 'a category or menu item of the site';
			} elseif (strpos($it['c'], 'wp') === 0) {
				$note = $it['c'] === 'wp' ? 'a date or time word' : 'abbreviation';
			} elseif ($it['c'] !== '') {
				$note = $it['c'];
			}
			$lines[(string) $i] = $note !== '' ? ['t' => $it['s'], 'note' => $note] : $it['s'];
		}
		$system = "You translate the interface of Y4APK, a website where people download Android games and apps (APK and MOD APK files) and Roblox scripts, and can make an account and share their own. Translate from English into {$english} ({$native}).\n"
			. "Rules:\n"
			. "- Write what big apps such as YouTube, TikTok and Google Play show in {$english}: short, natural and friendly; buttons as buttons are worded there.\n"
			. "- Keep every placeholder exactly: %s, %d, %1\$s, %2\$s, %%, {name}. Their order may change to suit the language.\n"
			. "- Keep HTML tags and their attributes exactly; translate only the words between them.\n"
			. "- Do not translate names: Y4APK, Google Play, Roblox, APK, MOD, XAPK, APKS, APKM, OBB, Android, iOS, Telegram, Discord, YouTube, Facebook, X, WhatsApp, LINE, Cloudflare, file names and code.\n"
			. "- Keep the punctuation and symbols at the start and end (… : · ✓ ▸ →) and the capital letters of codes.\n"
			. "- An item written as {\"t\": text, \"note\": …} has a note on where it is used: translate only t.\n"
			. "Answer with one JSON object and nothing else: the same numbers as keys, each translation as a string.";
		$user = wp_json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$r = Y4APK_Writer::ask_text($system, $user, 8000);
		if (is_wp_error($r)) {
			// This batch's own trouble: smaller pieces. The AI's limit or its key: everything waits.
			return in_array($r->get_error_code(), ['api', 'refused'], true) ? self::failed($code, $todo, $r->get_error_message()) : $r;
		}
		$got = self::parse((string) $r[0]);
		if (!$got) {
			return self::failed($code, $todo, __('The AI did not answer with the list asked for: asked again in smaller pieces.', 'y4apk'));
		}
		$dict = get_option(self::DICT . $code, []);
		$dict = is_array($dict) ? $dict : [];
		$st = self::state();
		$bad = isset($st[$code]['bad']) && is_array($st[$code]['bad']) ? $st[$code]['bad'] : [];
		$saved = 0;
		foreach ($map as $id => $key) {
			$src = $todo[$key]['s'];
			$t = isset($got[$id]) && is_string($got[$id]) ? trim($got[$id]) : '';
			if ($t !== '' && strpos($src, '"') === false && strpos($t, '"') !== false) {
				$t = str_replace('"', '”', (string) preg_replace('/"([^"<>]*)"/u', '“$1”', $t)); // quotes the English did not have: typographic ones
			}
			if ($t !== '' && self::marks($t) === self::marks($src) && strlen($t) < 4 * strlen($src) + 200) {
				// The spaces around the text, as the original has them ("Updated: " + date)
				preg_match('/^\s*/', $src, $lead);
				preg_match('/\s*$/', $src, $trail);
				$dict[$key] = $lead[0] . $t . $trail[0];
				unset($bad[$key]);
				$saved++;
			} else {
				$bad[$key] = (int) ($bad[$key] ?? 0) + 1;
			}
		}
		update_option(self::DICT . $code, $dict, false);
		$st = self::state();
		$st[$code]['bad'] = $bad;
		$st[$code]['at'] = time();
		$st[$code]['err'] = '';
		$st[$code]['by'] = $r[1];
		$st[$code]['size'] = min(self::BATCH, self::size($code) * 2);
		$st[$code]['streak'] = 0;
		update_option(self::STATE, $st, false);
		self::count($code);
		return $saved;
	}

	/** Recounts a language: how much of the catalog it has; its first-visit question in its own words. */
	public static function count($code, $items = null) {
		$items = $items ?? self::items();
		$dict = get_option(self::DICT . $code, []);
		$dict = is_array($dict) ? $dict : [];
		$done = 0;
		$total = 0;
		foreach ($items as $key => $it) {
			if (self::kept($key)) {
				continue; // stays in English: nothing to translate
			}
			$total++;
			if (isset($dict[$key]) && $dict[$key] !== '') {
				$done++;
			}
		}
		$st = self::state();
		$st[$code] = (isset($st[$code]) && is_array($st[$code]) ? $st[$code] : []) + ['bad' => [], 'mine' => [], 'at' => 0, 'err' => ''];
		$st[$code]['done'] = $done;
		$st[$code]['total'] = $total;
		$all = self::languages();
		$ask = 'See this site in %s?';
		$st[$code]['toast'] = [
			isset($dict[$ask]) ? sprintf($dict[$ask], $all[$code][0]) : '',
			$dict['Switch'] ?? '',
			$dict['Not now'] ?? '',
		];
		update_option(self::STATE, $st, false);
		self::publish();
		return $st[$code];
	}

	public static function count_all() {
		$items = self::items_raw();
		foreach (self::enabled() as $code) {
			self::count($code, $items);
		}
	}

	private static function items_raw() {
		$c = self::catalog();
		$seen = get_option(self::SEEN, []);
		return $c['items'] + (is_array($seen) ? $seen : []);
	}

	/* ---------------------------------------------------------------- *
	 * Location: the country Cloudflare sees
	 * ---------------------------------------------------------------- */

	public static function ajax_geo() {
		nocache_headers();
		$cc = '';
		foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY'] as $h) {
			if (!empty($_SERVER[$h])) {
				$cc = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $_SERVER[$h]), 0, 2));
				break;
			}
		}
		wp_send_json(['cc' => in_array($cc, ['', 'XX', 'T1', 'A1', 'A2', 'EU', 'AP'], true) ? '' : $cc]);
	}

	/* ---------------------------------------------------------------- *
	 * The visitors' side: the globe in the header, its menu, the first-visit question
	 * ---------------------------------------------------------------- */

	private static function icons() {
		return [
			'globe' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8ZM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4ZM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3Zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 0 1 5.1 16ZM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8Zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4Zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4Zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6Zm1.7-5.6a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4Z"/>',
			'pin'   => '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5Z"/>',
			'next'  => '<path d="m9.4 6-1.4 1.4 4.6 4.6-4.6 4.6L9.4 18l6-6-6-6Z"/>',
			'back'  => '<path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z"/>',
			'check' => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/>',
			'close' => '<path d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4Z"/>',
			'search' => '<path d="M15.5 14h-.8l-.3-.3A6.5 6.5 0 1 0 14 15.5l.3.3v.8l5 5 1.5-1.5-5-5Zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Z"/>',
		];
	}

	private static function svg($name, $size = 20) {
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true">' . self::icons()[$name] . '</svg>';
	}

	/** Once a language other than English is ready (and on a /xx/ page, to look at one before it is). */
	private static function shown() {
		return self::$lang !== '' || count(self::ready()) > 1;
	}

	/** The globe in the header (computers) — the same menu as the row in the phone menu. */
	public static function button() {
		if (!self::shown()) {
			return;
		}
		$all = self::languages();
		$cur = self::current();
		echo '<div class="y4l" data-y4l><button type="button" class="y4l-btn" data-y4l-open aria-haspopup="dialog" aria-expanded="false" aria-label="' . esc_attr__('Language and location', 'y4apk') . '" title="' . esc_attr__('Language and location', 'y4apk') . '">'
			. self::svg('globe', 20) . '<span class="y4l-code">' . esc_html(strtoupper(preg_replace('/-.*/', '', $cur))) . '</span></button></div>';
		self::assets();
	}

	/** The row in the phone menu: "🌐 ไทย · Thailand ›". */
	public static function menu_row() {
		if (!self::shown()) {
			return;
		}
		$all = self::languages();
		$cur = self::current();
		echo '<button type="button" class="y4l-row" data-y4l-open>' . self::svg('globe', 20) . '<span class="y4l-row-text"><b>' . esc_html(sprintf(__('Language: %s', 'y4apk'), $all[$cur][0])) . '</b><small data-y4l-gl-name></small></span>' . self::svg('next', 18) . '</button>';
	}

	/** Styles, texts and the script for the menu: once per page. */
	public static function assets() {
		if (self::$printed) {
			return;
		}
		self::$printed = true;
		$all = self::languages();
		$cur = self::current();
		$langs = [];
		foreach (array_unique(array_merge(self::ready(), [$cur])) as $c) {
			$langs[] = [$c, $all[$c][0], $all[$c][1], $all[$c][2]];
		}
		$toast = array_intersect_key(self::live()['toast'], array_flip(self::ready()));
		$cfg = [
			'cur'   => $cur,
			'html'  => self::html_lang(),
			'base'  => trailingslashit(self::home_path()),
			'langs' => $langs,
			'toast' => $toast,
			'geo'   => admin_url('admin-ajax.php?action=y4apk_geo'),
			'cc'    => 'AF AX AL DZ AS AD AO AI AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BR BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CG CD CK CR CI HR CU CW CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FJ FI FR GF PF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KP KR KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX FM MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF MK MP NO OM PK PW PS PA PG PY PE PH PN PL PT PR QA RE RO RU RW BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA SS ES LK SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TR TM TC TV UG UA AE GB US UY UZ VU VE VN VG VI WF EH YE ZM ZW',
			'i'     => array_intersect_key(self::icons(), array_flip(['globe', 'pin', 'next', 'back', 'check', 'close', 'search'])),
			'T'     => [
				'title'     => __('Language and location', 'y4apk'),
				'lang'      => __('Language: %s', 'y4apk'),
				'loc'       => __('Location: %s', 'y4apk'),
				'langTitle' => __('Choose your language', 'y4apk'),
				'locTitle'  => __('Choose your location', 'y4apk'),
				'langNote'  => __('Menus, buttons and messages change. Games, apps and articles stay as they were written.', 'y4apk'),
				'locNote'   => __('Dates are written the way they are where you are. Change it if it is wrong.', 'y4apk'),
				'search'    => __('Search', 'y4apk'),
				'none'      => __('Nothing found', 'y4apk'),
				'world'     => __('Worldwide', 'y4apk'),
				'back'      => __('Back', 'y4apk'),
				'close'     => __('Close', 'y4apk'),
				'today'     => __('Today', 'y4apk'),
				'yesterday' => __('Yesterday', 'y4apk'),
			],
		];
		?>
<style id="y4apk-i18n-css">
.y4l{position:relative;flex:none}
.y4l-btn{display:inline-flex;align-items:center;justify-content:center;gap:.375rem;height:2.5rem;min-width:2.5rem;padding:0 .75rem 0 .625rem;border:0;border-radius:2rem;font:inherit;font-size:.8125rem;font-weight:700;letter-spacing:.02em;color:#172021!important;background:#fff;box-shadow:0 .5rem 1rem -.5rem rgba(0,0,0,.18),0 0 0 1px rgba(0,0,0,.06);cursor:pointer;transition:box-shadow .2s ease,transform .1s ease}
.y4l-btn:hover{box-shadow:0 .75rem 1.5rem -.5rem rgba(41,177,216,.45),0 0 0 1px rgba(41,177,216,.4)}
.y4l-btn:active{transform:scale(.98)}
.y4l-btn svg{color:rgba(23,32,33,.7)}
.y4l-row{display:none}
.h-acct-lang{display:flex;align-items:center;gap:.75rem;width:100%;margin:0 0 .375rem;padding:.625rem .75rem;border:0;border-bottom:1px solid rgba(23,32,33,.08);border-radius:.75rem .75rem 0 0;font:inherit;font-size:.9375rem;font-weight:700;text-align:left;color:#172021;background:transparent;cursor:pointer}
.h-acct-lang span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h-acct-lang svg{flex:none;color:rgba(23,32,33,.5)}
.h-acct-lang:hover{background:#f3f7f8}
.h-acct-lang:hover svg:first-child{color:#29b1d8}
@media (min-width:62rem){.y4l{margin-left:.75rem}}
@media (max-width:61.9375rem){
.y4l{display:none}
.y4l-row{display:flex;align-items:center;gap:.75rem;width:calc(100% - 2rem);margin:.75rem 1rem;padding:.75rem .875rem;border:0;border-radius:.875rem;font:inherit;text-align:left;color:#172021;background:#f3f7f8;cursor:pointer}
.y4l-row>svg{flex:none;color:rgba(23,32,33,.55)}
.y4l-row-text{flex:1;min-width:0;display:grid}
.y4l-row-text b{font-size:.9375rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.y4l-row-text small{font-size:.8125rem;color:rgba(23,32,33,.6);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
}
.y4l-pop{position:fixed;z-index:1000;width:20rem;max-width:calc(100vw - 1rem);max-height:min(34rem,calc(100vh - 6rem));display:flex;flex-direction:column;border-radius:1.125rem;color:#172021;background:#fff;box-shadow:0 1.5rem 3rem -1rem rgba(10,20,30,.35),0 0 0 1px rgba(0,0,0,.06);overflow:hidden;animation:y4l-in .16s ease-out;font-size:.9375rem;line-height:1.35;text-align:left}
.y4l-pop[hidden],.y4l-shade[hidden]{display:none}
@keyframes y4l-in{from{opacity:0;transform:translateY(-.375rem) scale(.98)}}
.y4l-shade{position:fixed;inset:0;z-index:999;background:rgba(10,18,24,.45)}
.y4l-head{flex:none;display:flex;align-items:center;gap:.5rem;padding:.75rem .75rem .5rem 1rem;min-height:3.25rem}
.y4l-head h2{flex:1;min-width:0;margin:0;font-size:1rem;font-weight:700;line-height:1.3;color:inherit}
.y4l-ic{flex:none;display:inline-grid;place-items:center;width:2.25rem;height:2.25rem;margin:0;padding:0;border:0;border-radius:50%;color:rgba(23,32,33,.7);background:transparent;cursor:pointer}
.y4l-ic:hover{background:#f3f7f8;color:#172021}
.y4l-body{flex:1;min-height:0;overflow:auto;overscroll-behavior:contain;padding:0 .5rem .5rem}
.y4l-item{display:flex;align-items:center;gap:.75rem;width:100%;margin:0;padding:.75rem;border:0;border-radius:.75rem;font:inherit;text-align:left;color:#172021;background:transparent;cursor:pointer}
.y4l-item:hover,.y4l-item:focus-visible{background:#f3f7f8;outline:0}
.y4l-item>svg{flex:none;color:rgba(23,32,33,.55)}
.y4l-item .y4l-t{flex:1;min-width:0;display:grid;gap:.0625rem}
.y4l-item .y4l-t b{font-weight:600;overflow-wrap:anywhere}
.y4l-item .y4l-t small{font-size:.8125rem;color:rgba(23,32,33,.55);overflow-wrap:anywhere}
.y4l-item .y4l-tick{flex:none;width:1.25rem;height:1.25rem;color:#29b1d8}
.y4l-item[aria-checked="true"]{background:#e9f6fb}
.y4l-item[aria-checked="true"] .y4l-t b{color:#0f6f8c}
.y4l-note{margin:.125rem .75rem .5rem;font-size:.8125rem;color:rgba(23,32,33,.6)}
.y4l-find{position:relative;margin:0 .75rem .5rem}
.y4l-find svg{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:rgba(23,32,33,.45);pointer-events:none}
.y4l-find input{display:block;width:100%;height:2.5rem;margin:0;padding:0 .75rem 0 2.375rem;border:1px solid rgba(23,32,33,.14);border-radius:.75rem;font:inherit;font-size:1rem;color:#172021;background:#f7fafb;box-shadow:none;outline:0}
.y4l-find input:focus{border-color:#29b1d8;background:#fff;box-shadow:0 0 0 3px rgba(41,177,216,.18)}
.y4l-empty{padding:1rem .75rem;color:rgba(23,32,33,.55);font-size:.875rem}
.y4l-sep{height:1px;margin:.25rem .75rem;background:rgba(23,32,33,.08)}
@media (max-width:61.9375rem){
.y4l-pop{left:0!important;right:0!important;top:auto!important;bottom:0;width:auto;max-width:none;max-height:85vh;border-radius:1.25rem 1.25rem 0 0;padding-bottom:env(safe-area-inset-bottom);animation:y4l-up .2s ease-out}
.y4l-pop::before{content:"";display:block;flex:none;width:2.5rem;height:.25rem;margin:.5rem auto 0;border-radius:1rem;background:rgba(23,32,33,.18)}
@keyframes y4l-up{from{transform:translateY(100%)}}
}
.y4l-toast{position:fixed;z-index:998;left:50%;bottom:1rem;transform:translateX(-50%);display:flex;align-items:center;gap:.75rem;width:max-content;max-width:calc(100vw - 2rem);padding:.75rem .75rem .75rem 1rem;border-radius:1rem;color:#fff;background:#172021;box-shadow:0 1rem 2.5rem -1rem rgba(0,0,0,.5);font-size:.9375rem;line-height:1.35;animation:y4l-rise .25s ease-out}
@keyframes y4l-rise{from{opacity:0;transform:translate(-50%,.75rem)}}
.y4l-toast>svg{flex:none;color:#7fd3ec}
.y4l-toast p{flex:1;min-width:0;margin:0;color:inherit}
.y4l-toast button{flex:none;margin:0;padding:.5rem .875rem;border:0;border-radius:2rem;font:inherit;font-size:.875rem;font-weight:700;cursor:pointer;white-space:nowrap}
.y4l-toast .y4l-yes{color:#0b2a33;background:#7fd3ec}
.y4l-toast .y4l-no{color:#fff;background:rgba(255,255,255,.12)}
@media (max-width:30rem){.y4l-toast{flex-wrap:wrap;bottom:.75rem}.y4l-toast p{flex-basis:calc(100% - 2.5rem)}.y4l-toast button{flex:1}}
</style>
<script id="y4apk-pwa-lang" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var C = <?php echo wp_json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, T = C.T, d = document;
	var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
	var svg = function (k, s) { return '<svg viewBox="0 0 24 24" width="' + (s || 20) + '" height="' + (s || 20) + '" fill="currentColor" aria-hidden="true">' + C.i[k] + '</svg>'; };
	var cookie = function (k) { var m = d.cookie.match(new RegExp('(?:^|;\\s*)' + k + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; };
	var keep = function (k, v) { d.cookie = k + '=' + encodeURIComponent(v) + ';path=/;max-age=31536000;samesite=lax' + (location.protocol === 'https:' ? ';secure' : ''); };
	var codes = C.langs.map(function (l) { return l[0]; });
	var names = null;
	try { names = new Intl.DisplayNames([C.html, 'en'], {type: 'region'}); } catch (e) {}
	var country = function (cc) { if (!cc) { return T.world; } try { return names ? names.of(cc) : cc; } catch (e) { return cc; } };
	var guessCC = function () {
		var l = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']);
		for (var i = 0; i < l.length; i++) { var m = /-([A-Z]{2})\b/i.exec(l[i] || ''); if (m) { return m[1].toUpperCase(); } }
		return '';
	};
	var gl = (/^[A-Z]{2}$/.test(cookie('<?php echo self::GL; ?>')) ? cookie('<?php echo self::GL; ?>') : '');
	var glKnown = !!gl;
	if (!gl) { gl = guessCC(); }
	// The same page in another language
	var urlFor = function (code) {
		var p = location.pathname, b = C.base, r = p.indexOf(b) === 0 ? p.slice(b.length) : p.replace(/^\//, '');
		if (C.cur !== 'en' && (r === C.cur || r.indexOf(C.cur + '/') === 0)) { r = r.slice(C.cur.length).replace(/^\//, ''); }
		return b + (code === 'en' ? '' : code + '/') + r + location.search + location.hash;
	};
	// Dates in the visitor's language and place (2.51): <time datetime data-y4t>
	var locale = function () { return C.html + (gl ? '-' + gl : ''); };
	var dates = function () {
		var nodes = d.querySelectorAll('time[data-y4t]');
		if (!nodes.length || !window.Intl || !Intl.DateTimeFormat) { return; }
		var loc = locale(), f, now = new Date(), day = 864e5;
		try { f = new Intl.DateTimeFormat(loc, {dateStyle: 'medium', timeStyle: 'short'}); } catch (e) { try { loc = C.html; f = new Intl.DateTimeFormat(loc, {dateStyle: 'medium', timeStyle: 'short'}); } catch (e2) { return; } }
		var tf = new Intl.DateTimeFormat(loc, {timeStyle: 'short'});
		var start = function (t) { var x = new Date(t); x.setHours(0, 0, 0, 0); return x.getTime(); };
		[].forEach.call(nodes, function (n) {
			var t = new Date(n.getAttribute('datetime'));
			if (isNaN(t)) { return; }
			var diff = Math.round((start(now) - start(t)) / day);
			n.textContent = diff === 0 ? T.today + ', ' + tf.format(t) : diff === 1 ? T.yesterday + ', ' + tf.format(t) : f.format(t);
		});
	};
	// The menu
	var pop, shade, opener, view = 'main';
	var close = function () {
		if (!pop || pop.hidden) { return; }
		pop.hidden = true; shade.hidden = true;
		[].forEach.call(d.querySelectorAll('[data-y4l-open]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
		if (opener && opener.focus) { opener.focus(); }
	};
	var place = function () {
		if (!pop || pop.hidden) { return; }
		if (window.matchMedia('(max-width: 61.9375rem)').matches || !opener || !opener.getBoundingClientRect) { pop.style.top = pop.style.right = pop.style.left = ''; shade.hidden = false; return; }
		var r = opener.getBoundingClientRect();
		shade.hidden = true;
		pop.style.left = 'auto';
		pop.style.top = Math.round(r.bottom + 10) + 'px';
		pop.style.right = Math.max(8, Math.round(d.documentElement.clientWidth - r.right)) + 'px';
	};
	var item = function (attrs, icon, title, sub, checked, more) {
		return '<button type="button" class="y4l-item"' + attrs + (checked === null ? '' : ' role="menuitemradio" aria-checked="' + (checked ? 'true' : 'false') + '"') + '>' + (icon ? svg(icon) : '')
			+ '<span class="y4l-t"><b>' + esc(title) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>'
			+ (checked ? '<span class="y4l-tick">' + svg('check') + '</span>' : '') + (more ? svg('next', 18) : '') + '</button>';
	};
	var head = function (title, back) {
		return '<div class="y4l-head">' + (back ? '<button type="button" class="y4l-ic" data-v="main" aria-label="' + esc(T.back) + '">' + svg('back') + '</button>' : '')
			+ '<h2 id="y4l-h">' + esc(title) + '</h2><button type="button" class="y4l-ic" data-x aria-label="' + esc(T.close) + '">' + svg('close') + '</button></div>';
	};
	var curName = function () { for (var i = 0; i < C.langs.length; i++) { if (C.langs[i][0] === C.cur) { return C.langs[i][1]; } } return C.cur; };
	var show = function (v) {
		view = v;
		var h = '';
		if (v === 'main') {
			h = head(T.title) + '<div class="y4l-body" role="menu">'
				+ item(' data-v="lang"', 'globe', T.lang.replace('%s', curName()), '', null, true)
				+ item(' data-v="loc"', 'pin', T.loc.replace('%s', country(gl)), '', null, true) + '</div>';
		} else if (v === 'lang') {
			h = head(T.langTitle, true) + '<p class="y4l-note">' + esc(T.langNote) + '</p>'
				+ (C.langs.length > 8 ? '<div class="y4l-find">' + svg('search', 18) + '<input type="search" data-find placeholder="' + esc(T.search) + '" aria-label="' + esc(T.search) + '" autocomplete="off"></div>' : '')
				+ '<div class="y4l-body" role="menu">' + C.langs.map(function (l) {
					return item(' data-lang="' + esc(l[0]) + '" lang="' + esc(l[3]) + '" data-q="' + esc((l[1] + ' ' + l[2] + ' ' + l[0]).toLowerCase()) + '"', '', l[1], l[1] !== l[2] ? l[2] : '', l[0] === C.cur, false);
				}).join('') + '<p class="y4l-empty" hidden>' + esc(T.none) + '</p></div>';
		} else {
			var list = C.cc.split(' ').map(function (c) { return [c, country(c)]; });
			try { var col = new Intl.Collator(C.html); list.sort(function (a, b) { return col.compare(a[1], b[1]); }); } catch (e) { list.sort(function (a, b) { return a[1] < b[1] ? -1 : 1; }); }
			if (gl) { list = list.filter(function (x) { return x[0] !== gl; }); list.unshift([gl, country(gl)]); }
			h = head(T.locTitle, true) + '<p class="y4l-note">' + esc(T.locNote) + '</p>'
				+ '<div class="y4l-find">' + svg('search', 18) + '<input type="search" data-find placeholder="' + esc(T.search) + '" aria-label="' + esc(T.search) + '" autocomplete="off"></div>'
				+ '<div class="y4l-body" role="menu">' + list.map(function (x) {
					return item(' data-cc="' + x[0] + '" data-q="' + esc((x[1] + ' ' + x[0]).toLowerCase()) + '"', '', x[1], '', x[0] === gl, false);
				}).join('') + '<p class="y4l-empty" hidden>' + esc(T.none) + '</p></div>';
		}
		pop.innerHTML = h;
		var wide = window.matchMedia('(min-width: 62rem)').matches;
		var f = (wide && pop.querySelector('[data-find]')) || pop.querySelector('[aria-checked="true"]') || pop.querySelector('.y4l-item');
		if (f) { try { f.focus({preventScroll: true}); } catch (e) { f.focus(); } }
		var on = pop.querySelector('[aria-checked="true"]');
		if (on && on.scrollIntoView && v !== 'main') { on.scrollIntoView({block: 'center'}); }
	};
	var build = function () {
		pop = d.createElement('div'); pop.className = 'y4l-pop'; pop.hidden = true;
		pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-modal', 'false'); pop.setAttribute('aria-labelledby', 'y4l-h');
		shade = d.createElement('div'); shade.className = 'y4l-shade'; shade.hidden = true;
		d.body.appendChild(shade); d.body.appendChild(pop);
		shade.addEventListener('click', close);
		pop.addEventListener('click', function (e) {
			var t = e.target.closest ? e.target.closest('button') : null;
			if (!t) { return; }
			if (t.hasAttribute('data-x')) { close(); return; }
			if (t.getAttribute('data-v')) { show(t.getAttribute('data-v')); return; }
			if (t.getAttribute('data-lang')) {
				var code = t.getAttribute('data-lang');
				keep('<?php echo self::COOKIE; ?>', code);
				if (code !== C.cur) { location.href = urlFor(code); } else { close(); }
				return;
			}
			if (t.getAttribute('data-cc')) {
				gl = t.getAttribute('data-cc'); glKnown = true; keep('<?php echo self::GL; ?>', gl); dates(); label(); show('main');
			}
		});
		pop.addEventListener('input', function (e) {
			if (!e.target.hasAttribute('data-find')) { return; }
			var q = e.target.value.trim().toLowerCase(), n = 0;
			[].forEach.call(pop.querySelectorAll('.y4l-item[data-q]'), function (b) { var ok = !q || b.getAttribute('data-q').indexOf(q) > -1; b.hidden = !ok; n += ok ? 1 : 0; });
			var em = pop.querySelector('.y4l-empty'); if (em) { em.hidden = n > 0; }
		});
		pop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.hasAttribute('data-find')) { var first = pop.querySelector('.y4l-item[data-q]:not([hidden])'); if (first) { e.preventDefault(); first.click(); } }
		});
	};
	var label = function () {
		[].forEach.call(d.querySelectorAll('[data-y4l-gl-name]'), function (n) { n.textContent = T.loc.replace('%s', country(gl)); });
	};
	var open = function (btn) {
		if (!pop) { build(); }
		if (!pop.hidden && opener === btn) { close(); return; }
		opener = btn;
		var mm = d.querySelector('.header-menu');
		pop.hidden = false; show('main'); place();
		[].forEach.call(d.querySelectorAll('[data-y4l-open]'), function (b) { b.setAttribute('aria-expanded', b === btn ? 'true' : 'false'); });
		if (!glKnown && window.fetch) {
			fetch(C.geo, {credentials: 'omit', cache: 'no-store'}).then(function (r) { return r.json(); }).then(function (j) {
				if (j && /^[A-Z]{2}$/.test(j.cc || '') && !glKnown) { gl = j.cc; glKnown = true; keep('<?php echo self::GL; ?>', gl); label(); dates(); if (!pop.hidden && view === 'main') { show('main'); } }
			}).catch(function () {});
		}
	};
	d.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-y4l-open]') : null;
		if (b && b.closest('.h-acct-menu')) {
			// From the account menu: that one closes, and the language menu opens under the globe (a sheet on phones)
			var am = b.closest('.h-acct-menu'), box = am.closest('.h-acct'), ab = box && box.querySelector('.h-acct-btn'), g = d.querySelector('.y4l-btn');
			am.hidden = true; if (box) { box.classList.remove('open'); } if (ab) { ab.setAttribute('aria-expanded', 'false'); }
			if (g && g.getClientRects().length) { b = g; }
		}
		if (b) { e.preventDefault(); e.stopPropagation(); open(b); return; }
		// Inside the menu (counted when the click began: the menu redraws itself on a click) it stays open
		var path = e.composedPath ? e.composedPath() : [];
		if (pop && !pop.hidden && path.indexOf(pop) < 0 && !pop.contains(e.target) && e.target.isConnected !== false && window.matchMedia('(min-width: 62rem)').matches) { close(); }
	});
	d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pop && !pop.hidden) { if (view !== 'main') { show('main'); } else { close(); } } });
	window.addEventListener('resize', place);
	window.addEventListener('scroll', function () { if (pop && !pop.hidden && window.matchMedia('(min-width: 62rem)').matches) { place(); } }, {passive: true});
	// First visit, with the phone in a language the site has: asked once, in that language (as YouTube and Google do)
	var ask = function () {
		if (C.cur !== 'en' || cookie('<?php echo self::COOKIE; ?>') || !navigator.languages && !navigator.language) { return; }
		var want = '', l = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language];
		for (var i = 0; i < l.length && !want; i++) {
			var t = String(l[i] || '').toLowerCase(), p = t.split('-')[0];
			if (p === 'en') { return; }
			var c = /^zh-(tw|hk|mo)|^zh-hant/.test(t) ? 'zh-tw' : p === 'tl' ? 'fil' : p === 'in' ? 'id' : p === 'iw' ? 'he' : p === 'nb' || p === 'nn' ? 'no' : p;
			if (codes.indexOf(c) > -1 && C.toast[c]) { want = c; }
		}
		if (!want) { return; }
		var w = C.toast[want], bar = d.createElement('div');
		bar.className = 'y4l-toast'; bar.setAttribute('role', 'dialog'); bar.setAttribute('lang', (C.langs.filter(function (x) { return x[0] === want; })[0] || [])[3] || want);
		bar.innerHTML = svg('globe', 22) + '<p>' + esc(w[0]) + '</p><button type="button" class="y4l-yes">' + esc(w[1] || 'OK') + '</button><button type="button" class="y4l-no">' + esc(w[2] || '✕') + '</button>';
		d.body.appendChild(bar);
		bar.querySelector('.y4l-yes').addEventListener('click', function () { keep('<?php echo self::COOKIE; ?>', want); location.href = urlFor(want); });
		bar.querySelector('.y4l-no').addEventListener('click', function () { keep('<?php echo self::COOKIE; ?>', 'en'); bar.remove(); });
	};
	var ready = function () { label(); dates(); setTimeout(ask, 1200); };
	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', ready); } else { ready(); }
})();
</script>
		<?php
		// Words only this menu uses, in the catalog (the question to newcomers is shown in their own language).
		if (false) {
			__('See this site in %s?', 'y4apk');
			__('Switch', 'y4apk');
			__('Not now', 'y4apk');
		}
	}

	/* ---------------------------------------------------------------- *
	 * Admin: Y4APK ▸ Languages
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page(Y4APK_MENU, __('Languages', 'y4apk'), __('Languages', 'y4apk'), 'manage_options', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-i18n-save');
		$all = self::languages();
		$want = isset($_POST['langs']) && is_array($_POST['langs']) ? array_map('sanitize_key', wp_unslash($_POST['langs'])) : [];
		$order = isset($_POST['order']) ? array_filter(array_map('sanitize_key', explode(',', (string) wp_unslash($_POST['order'])))) : [];
		$list = [];
		foreach (array_merge($order, array_keys($all)) as $c) {
			$c = str_replace('_', '-', $c);
			if ($c !== 'en' && isset($all[$c]) && in_array($c, $want, true) && !in_array($c, $list, true)) {
				$list[] = $c;
			}
		}
		update_option(self::OPTION, ['langs' => $list]);
		if (!empty($_POST['rescan'])) {
			self::scan();
		}
		self::count_all();
		self::publish();
		self::schedule();
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php')));
		exit;
	}

	/** Translate now (the page asks again and again while it is open): [code => percent], left, error. */
	public static function ajax_run() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('You are not allowed to do that.', 'y4apk')], 403);
		}
		check_ajax_referer('y4apk-i18n-run');
		if (!self::can_translate()) {
			wp_send_json_error(['message' => __('Add an AI key first: Y4APK ▸ Write with AI ▸ Settings.', 'y4apk')]);
		}
		if (get_transient('y4apk_i18n_lock')) {
			wp_send_json_success(['busy' => 1, 'langs' => self::progress(), 'left' => self::left()]);
		}
		set_transient('y4apk_i18n_lock', 1, 2 * MINUTE_IN_SECONDS);
		$only = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		$only = str_replace('_', '-', $only);
		try {
			$r = self::work(20, $only);
		} finally {
			delete_transient('y4apk_i18n_lock');
		}
		if (is_wp_error($r)) {
			$data = $r->get_error_data();
			wp_send_json_error(['message' => $r->get_error_message(), 'code' => $r->get_error_code(), 'wait' => is_array($data) ? (int) ($data['wait'] ?? 0) : 0, 'langs' => self::progress(), 'left' => self::left()]);
		}
		wp_send_json_success(['saved' => $r, 'langs' => self::progress(), 'left' => self::left()]);
	}

	private static function progress() {
		$st = self::state();
		$out = [];
		foreach (self::enabled() as $c) {
			$out[$c] = ['pct' => self::percent($c, $st), 'done' => (int) ($st[$c]['done'] ?? 0), 'total' => (int) ($st[$c]['total'] ?? 0), 'bad' => count($st[$c]['bad'] ?? [])];
		}
		return $out;
	}

	/** Texts still to translate, in every language turned on (the AI's wrong twice not counted). */
	private static function left() {
		$st = self::state();
		$n = 0;
		foreach (self::enabled() as $c) {
			$bad = array_filter((array) ($st[$c]['bad'] ?? []), function ($t) {
				return $t >= 2;
			});
			$n += max(0, (int) ($st[$c]['total'] ?? 0) - (int) ($st[$c]['done'] ?? 0) - count($bad));
		}
		return $n;
	}

	public static function handle_texts() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-i18n-texts');
		$code = isset($_POST['lang']) ? str_replace('_', '-', sanitize_key(wp_unslash($_POST['lang']))) : '';
		if (!in_array($code, self::enabled(), true)) {
			wp_die(esc_html__('That language is not turned on.', 'y4apk'));
		}
		$dict = get_option(self::DICT . $code, []);
		$dict = is_array($dict) ? $dict : [];
		$st = self::state();
		$mine = isset($st[$code]['mine']) && is_array($st[$code]['mine']) ? $st[$code]['mine'] : [];
		$bad = isset($st[$code]['bad']) && is_array($st[$code]['bad']) ? $st[$code]['bad'] : [];
		$items = self::items_raw();
		$keys = isset($_POST['k']) && is_array($_POST['k']) ? wp_unslash($_POST['k']) : [];
		$vals = isset($_POST['t']) && is_array($_POST['t']) ? wp_unslash($_POST['t']) : [];
		$ticks = isset($_POST['en']) && is_array($_POST['en']) ? $_POST['en'] : [];
		$english = get_option(self::ENGLISH, []);
		$english = is_array($english) ? $english : [];
		$wrong = 0;
		foreach ($keys as $i => $k64) {
			$key = base64_decode((string) $k64, true);
			if ($key === false || !isset($items[$key])) {
				continue;
			}
			$keep = !empty($ticks[$i]);
			if ($keep === self::english_default($key)) {
				unset($english[$key]);
			} else {
				$english[$key] = $keep;
			}
			$new = trim(str_replace("\r", '', (string) ($vals[$i] ?? '')));
			$old = (string) ($dict[$key] ?? '');
			if ($new === trim($old)) {
				continue;
			}
			if ($new === '') {
				unset($dict[$key], $mine[$key]);
				$bad[$key] = 0; // the AI translates it again
				continue;
			}
			if (self::marks($new) !== self::marks($items[$key]['s'])) {
				$wrong++;
				continue;
			}
			$new = wp_kses_post($new);
			preg_match('/^\s*/', $items[$key]['s'], $lead);
			preg_match('/\s*$/', $items[$key]['s'], $trail);
			$dict[$key] = $lead[0] . $new . $trail[0];
			$mine[$key] = 1;
			unset($bad[$key]);
		}
		update_option(self::DICT . $code, $dict, false);
		$st = self::state();
		$st[$code]['mine'] = $mine;
		$st[$code]['bad'] = $bad;
		update_option(self::STATE, $st, false);
		update_option(self::ENGLISH, $english);
		self::$english = null;
		self::count_all(); // kept in English in every language
		$back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '';
		$back = $back !== '' ? $back : add_query_arg(['page' => self::SLUG, 'edit' => $code], admin_url('admin.php'));
		wp_safe_redirect(add_query_arg(['saved' => 1, 'wrong' => $wrong], $back));
		exit;
	}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$cat = self::catalog();
		if ($cat['v'] !== Y4APK_THEME_VERSION || empty($cat['items'])) {
			self::scan();
		}
		$edit = isset($_GET['edit']) ? str_replace('_', '-', sanitize_key(wp_unslash($_GET['edit']))) : '';
		self::styles();
		echo '<div class="wrap y4i">';
		if ($edit !== '' && in_array($edit, self::enabled(), true)) {
			self::render_texts($edit);
		} else {
			self::render_langs();
		}
		echo '</div>';
	}

	private static function styles() {
		?>
		<style>
		.y4i{max-width:1180px}
		.y4i-lead{max-width:860px;font-size:14px;color:#3c434a}
		.y4i-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:16px 0}
		.y4i-card{padding:14px 16px;border:1px solid #dcdcde;border-radius:10px;background:#fff}
		.y4i-card b{display:block;font-size:22px;line-height:1.2;margin-bottom:2px}
		.y4i-card span{color:#646970}
		.y4i-table{border-radius:10px;overflow:hidden}
		.y4i-table td,.y4i-table th{vertical-align:middle}
		.y4i-name b{display:block;font-size:14px}
		.y4i-name small{color:#646970}
		.y4i-bar{position:relative;height:8px;width:160px;max-width:100%;border-radius:8px;background:#f0f0f1;overflow:hidden}
		.y4i-bar i{position:absolute;inset:0 auto 0 0;border-radius:8px;background:#2271b1}
		.y4i-bar.is-ready i{background:#00a32a}
		.y4i-pct{font-variant-numeric:tabular-nums;color:#50575e}
		.y4i-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
		.y4i-chip.ok{background:#edfaef;color:#00691b}
		.y4i-chip.wait{background:#fcf9e8;color:#7a5d00}
		.y4i-chip.err{background:#fcf0f1;color:#a00}
		.y4i-why{display:block;max-width:300px;margin-top:4px;font-size:12px;line-height:1.4;color:#646970}
		.y4i-off td{opacity:.6}
		.y4i-run{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:14px 0}
		.y4i-run [data-status]{color:#50575e}
		.y4i-texts td{vertical-align:top}
		.y4i-texts .y4i-src{width:42%}
		.y4i-texts .y4i-src div{white-space:pre-wrap;word-break:break-word}
		.y4i-texts .y4i-src small{display:block;margin-top:4px;color:#8c8f94}
		.y4i-texts textarea{width:100%;min-height:38px;resize:vertical}
		.y4i-texts tr.is-missing textarea{border-color:#dba617;background:#fffbea}
		.y4i-texts tr.is-mine .y4i-who{color:#00691b}
		.y4i-who{font-size:11px;color:#8c8f94}
		.y4i-en{display:inline-flex;align-items:center;gap:4px;margin-left:10px;font-size:12px;color:#50575e}
		.y4i-texts tr.is-english textarea{opacity:.45}
		.y4i-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}
		</style>
		<?php
	}

	private static function render_langs() {
		$all = self::languages();
		$on = self::enabled();
		$st = self::state();
		$items = array_filter(self::items_raw(), function ($key) {
			return !self::kept($key);
		}, ARRAY_FILTER_USE_KEY);
		$words = 0;
		foreach ($items as $it) {
			$words += str_word_count(wp_strip_all_tags((string) $it['s']));
		}
		foreach ($on as $c) {
			if ((int) ($st[$c]['total'] ?? 0) !== count($items)) { // texts found since: counted again
				self::count_all();
				$st = self::state();
				break;
			}
		}
		$ready = array_diff(self::ready(), ['en']);
		$can = self::can_translate();
		$wait_until = (int) get_transient('y4apk_i18n_wait');
		?>
		<h1><?php esc_html_e('Languages', 'y4apk'); ?></h1>
		<?php if (!empty($_GET['saved'])) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache (10Web ▸ Clear Cache) so the language menu shows the change.', 'y4apk'); ?></p></div>
		<?php endif; ?>
		<?php if (!$can) : ?>
			<div class="notice notice-warning"><p><?php echo wp_kses_post(sprintf(__('Translations are made by Write with AI. Add a key first in <a href="%s">Y4APK ▸ Write with AI ▸ Settings</a> (Gemini’s is free).', 'y4apk'), esc_url(admin_url('admin.php?page=y4apk-writer')))); ?></p></div>
		<?php endif; ?>
		<p class="y4i-lead"><?php esc_html_e('The way YouTube and TikTok do it: menus, buttons and messages appear in the visitor’s language, at its own address (/th/, /id/ …); games, apps, scripts and articles stay as you wrote them. Write with AI translates the texts in the background, a few at a time. A language shows in the visitors’ menu (the globe in the header) once 90% of it is done, and visitors whose phone is set to it are asked once if they want it.', 'y4apk'); ?></p>
		<div class="y4i-cards">
			<div class="y4i-card"><b><?php echo esc_html(number_format_i18n(count($ready))); ?> / <?php echo esc_html(number_format_i18n(count($on))); ?></b><span><?php esc_html_e('languages ready for visitors', 'y4apk'); ?></span></div>
			<div class="y4i-card"><b><?php echo esc_html(number_format_i18n(count($items))); ?></b><span><?php echo esc_html(sprintf(__('texts to translate (%s words), in each language', 'y4apk'), number_format_i18n($words))); ?></span></div>
			<div class="y4i-card"><b><?php echo esc_html(number_format_i18n(self::left())); ?></b><span><?php esc_html_e('translations still to make', 'y4apk'); ?></span></div>
		</div>
		<div class="y4i-run">
			<button type="button" class="button button-primary" data-run <?php disabled(!$can || !$on); ?>><?php esc_html_e('Translate now', 'y4apk'); ?></button>
			<span data-status><?php echo esc_html($can ? __('Also happens on its own, every 5 minutes, while there is something to translate.', 'y4apk') : ''); ?></span>
		</div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="y4apk_i18n_save">
			<input type="hidden" name="order" value="<?php echo esc_attr(implode(',', $on)); ?>">
			<?php wp_nonce_field('y4apk-i18n-save'); ?>
			<table class="widefat striped y4i-table">
				<thead><tr><th style="width:44px"><?php esc_html_e('On', 'y4apk'); ?></th><th><?php esc_html_e('Language', 'y4apk'); ?></th><th><?php esc_html_e('Address', 'y4apk'); ?></th><th><?php esc_html_e('Translated', 'y4apk'); ?></th><th><?php esc_html_e('Visitors see it', 'y4apk'); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				$rows = array_merge($on, array_values(array_diff(array_keys($all), $on, ['en'])));
				foreach ($rows as $c) :
					$is_on = in_array($c, $on, true);
					$pct = self::percent($c, $st);
					$s = $st[$c] ?? [];
					$err = (string) ($s['err'] ?? '');
					?>
					<tr class="<?php echo $is_on ? '' : 'y4i-off'; ?>" data-lang="<?php echo esc_attr($c); ?>">
						<td><input type="checkbox" name="langs[]" value="<?php echo esc_attr($c); ?>" <?php checked($is_on); ?> aria-label="<?php echo esc_attr($all[$c][1]); ?>"></td>
						<td class="y4i-name"><b lang="<?php echo esc_attr($all[$c][2]); ?>"><?php echo esc_html($all[$c][0]); ?></b><small><?php echo esc_html($all[$c][1]); ?><?php echo $all[$c][3] ? ' · ' . esc_html__('right to left: the layout stays left to right', 'y4apk') : ''; ?></small></td>
						<td><code>/<?php echo esc_html($c); ?>/</code></td>
						<td><?php if ($is_on) : ?>
							<div class="y4i-bar<?php echo $pct >= self::READY ? ' is-ready' : ''; ?>"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
							<span class="y4i-pct" data-pct><?php echo esc_html(sprintf(__('%1$s%% · %2$s of %3$s', 'y4apk'), $pct, number_format_i18n((int) ($s['done'] ?? 0)), number_format_i18n((int) ($s['total'] ?? count($items))))); ?></span>
						<?php else : ?>—<?php endif; ?></td>
						<td><?php if (!$is_on) : ?>—<?php elseif ($pct >= self::READY) : ?><span class="y4i-chip ok"><?php esc_html_e('Yes', 'y4apk'); ?></span><?php elseif ($err !== '') : ?><span class="y4i-chip err"><?php esc_html_e('Waiting for the AI', 'y4apk'); ?></span><small class="y4i-why"><?php echo esc_html($err); ?><?php if ($wait_until > time()) : ?> · <?php echo esc_html(sprintf(__('goes on by itself at %s', 'y4apk'), wp_date(get_option('time_format'), $wait_until))); ?><?php endif; ?></small><?php elseif ($pct > 0) : ?><span class="y4i-chip wait"><?php echo esc_html(sprintf(__('Translating · shown at %s%%', 'y4apk'), self::READY)); ?></span><?php else : ?><span class="y4i-chip"><?php esc_html_e('Waiting its turn', 'y4apk'); ?></span><?php endif; ?></td>
						<td><?php if ($is_on) : ?>
							<a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'edit' => $c], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit texts', 'y4apk'); ?></a> ·
							<a href="<?php echo esc_url(self::add_code(home_url('/'), $c)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'y4apk'); ?></a>
						<?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
				<label style="margin-left:12px"><input type="checkbox" name="rescan" value="1"> <?php esc_html_e('Look for new texts in the theme too', 'y4apk'); ?></label>
			</p>
			<p class="description" style="max-width:860px"><?php esc_html_e('Ticked languages are translated top to bottom. Each one costs a few dozen questions to the AI, once; new texts after a theme update are translated the same way. Search engines keep only the original pages: a /th/ page says noindex and points to the original, because its posts are the same.', 'y4apk'); ?></p>
		</form>
		<script>
		(function () {
			var btn = document.querySelector('.y4i [data-run]'), st = document.querySelector('.y4i [data-status]'), running = false;
			if (!btn) { return; }
			var names = <?php echo wp_json_encode(array_map(function ($l) { return $l[0]; }, $all), JSON_UNESCAPED_UNICODE); ?>;
			var update = function (langs) {
				Object.keys(langs || {}).forEach(function (c) {
					var row = document.querySelector('.y4i tr[data-lang="' + c + '"]');
					if (!row) { return; }
					var l = langs[c], bar = row.querySelector('.y4i-bar i'), pct = row.querySelector('[data-pct]');
					if (bar) { bar.style.width = l.pct + '%'; bar.parentNode.classList.toggle('is-ready', l.pct >= <?php echo (int) self::READY; ?>); }
					if (pct) { pct.textContent = <?php echo wp_json_encode(__('%1$s%% · %2$s of %3$s', 'y4apk')); ?>.replace('%1$s%%', l.pct + '%').replace('%2$s', l.done.toLocaleString()).replace('%3$s', l.total.toLocaleString()); }
					var chip = row.querySelector('.y4i-chip');
					if (chip && l.pct >= <?php echo (int) self::READY; ?>) { chip.className = 'y4i-chip ok'; chip.textContent = <?php echo wp_json_encode(__('Yes', 'y4apk')); ?>; chip.removeAttribute('title'); }
				});
			};
			var step = function () {
				var f = new FormData();
				f.append('action', 'y4apk_i18n_run');
				f.append('_ajax_nonce', <?php echo wp_json_encode(wp_create_nonce('y4apk-i18n-run')); ?>);
				fetch(ajaxurl, {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
					var d = j && j.data ? j.data : {};
					update(d.langs);
					if (j && !j.success && d.code === 'busy' && running && d.wait && d.wait <= 900) {
						// The AI's limit for the minute: wait, then go on (as the background does)
						var left = d.wait;
						var tickWait = function () {
							if (!running) { return; }
							st.textContent = <?php echo wp_json_encode(__('The AI is at its limit for now (%1$s). Going on by itself in %2$s s…', 'y4apk')); ?>.replace('%1$s', d.message).replace('%2$s', left);
							if (left-- <= 0) { step(); } else { setTimeout(tickWait, 1000); }
						};
						tickWait();
						return;
					}
					if (!j || !j.success) { st.textContent = (d.message || <?php echo wp_json_encode(__('Something went wrong. Try again in a minute.', 'y4apk')); ?>) + (d.code === 'busy' ? ' ' + <?php echo wp_json_encode(__('It goes on by itself in the background when the AI lets it.', 'y4apk')); ?> : ''); stop(); return; }
					if (!d.left) { st.textContent = <?php echo wp_json_encode(__('Everything is translated. Clear the page cache so visitors get it.', 'y4apk')); ?>; stop(); return; }
					st.textContent = <?php echo wp_json_encode(__('Translating… %s left. You can leave this page: it goes on in the background.', 'y4apk')); ?>.replace('%s', d.left.toLocaleString());
					if (running) { setTimeout(step, d.busy ? 8000 : 400); }
				}).catch(function () { st.textContent = <?php echo wp_json_encode(__('The connection dropped. Try again.', 'y4apk')); ?>; stop(); });
			};
			var stop = function () { running = false; btn.textContent = <?php echo wp_json_encode(__('Translate now', 'y4apk')); ?>; };
			btn.addEventListener('click', function () {
				if (running) { stop(); return; }
				running = true; btn.textContent = <?php echo wp_json_encode(__('Stop', 'y4apk')); ?>; st.textContent = <?php echo wp_json_encode(__('Asking the AI…', 'y4apk')); ?>; step();
			});
		})();
		</script>
		<?php
	}

	private static function render_texts($code) {
		$all = self::languages();
		$items = self::items();
		$dict = get_option(self::DICT . $code, []);
		$dict = is_array($dict) ? $dict : [];
		$st = self::state();
		$mine = (array) ($st[$code]['mine'] ?? []);
		$bad = (array) ($st[$code]['bad'] ?? []);
		$q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
		$show = isset($_GET['show']) ? sanitize_key(wp_unslash($_GET['show'])) : 'all';
		$rows = [];
		foreach ($items as $key => $it) {
			$has = isset($dict[$key]) && $dict[$key] !== '';
			$en = self::kept($key);
			if (($show === 'missing' && ($has || $en)) || ($show === 'done' && !$has) || ($show === 'mine' && empty($mine[$key])) || ($show === 'bad' && ($has || $en || empty($bad[$key]))) || ($show === 'english' && !$en)) {
				continue;
			}
			if ($q !== '' && stripos($it['s'], $q) === false && stripos((string) ($dict[$key] ?? ''), $q) === false) {
				continue;
			}
			$rows[$key] = $it;
		}
		$per = 40;
		$pages = max(1, (int) ceil(count($rows) / $per));
		$page = max(1, min($pages, isset($_GET['p']) ? (int) $_GET['p'] : 1));
		$slice = array_slice($rows, ($page - 1) * $per, $per, true);
		$here = add_query_arg(array_filter(['page' => self::SLUG, 'edit' => $code, 'q' => $q, 'show' => $show !== 'all' ? $show : '', 'p' => $page > 1 ? $page : '']), admin_url('admin.php'));
		$rtl = !empty($all[$code][3]);
		?>
		<h1><?php echo esc_html(sprintf(__('Texts in %s', 'y4apk'), $all[$code][1])); ?> <a class="page-title-action" href="<?php echo esc_url(add_query_arg('page', self::SLUG, admin_url('admin.php'))); ?>"><?php esc_html_e('All languages', 'y4apk'); ?></a></h1>
		<?php if (!empty($_GET['saved'])) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache so visitors get it.', 'y4apk'); ?></p></div>
		<?php endif; ?>
		<?php if (!empty($_GET['wrong'])) : ?>
			<div class="notice notice-error"><p><?php echo esc_html(sprintf(_n('%s line was not saved: it must keep the same %%s, %%d, tags and {words} as the English.', '%s lines were not saved: they must keep the same %%s, %%d, tags and {words} as the English.', (int) $_GET['wrong'], 'y4apk'), number_format_i18n((int) $_GET['wrong']))); ?></p></div>
		<?php endif; ?>
		<p class="y4i-lead"><?php esc_html_e('Change any line and Save: a line you wrote is never replaced by the AI. Empty a line to have the AI translate it again. Tick “Keep in English” for words that should stay as they are in every language (the site’s categories, menu and home page heading are kept so at first).', 'y4apk'); ?></p>
		<form class="y4i-filter" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
			<input type="hidden" name="edit" value="<?php echo esc_attr($code); ?>">
			<input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Find a text', 'y4apk'); ?>">
			<select name="show">
				<?php foreach (['all' => __('All', 'y4apk'), 'missing' => __('Not translated yet', 'y4apk'), 'done' => __('Translated', 'y4apk'), 'mine' => __('Written by you', 'y4apk'), 'bad' => __('The AI got wrong', 'y4apk'), 'english' => __('Kept in English', 'y4apk')] as $k => $label) : ?>
					<option value="<?php echo esc_attr($k); ?>" <?php selected($show, $k); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e('Show', 'y4apk'); ?></button>
			<span class="y4i-pct"><?php echo esc_html(sprintf(__('%1$s texts · %2$s%% translated', 'y4apk'), number_format_i18n(count($rows)), self::percent($code, $st))); ?></span>
			<a href="<?php echo esc_url(self::add_code(home_url('/'), $code)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View the site in it', 'y4apk'); ?></a>
		</form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="y4apk_i18n_texts">
			<input type="hidden" name="lang" value="<?php echo esc_attr($code); ?>">
			<input type="hidden" name="back" value="<?php echo esc_attr($here); ?>">
			<?php wp_nonce_field('y4apk-i18n-texts'); ?>
			<table class="widefat striped y4i-texts">
				<thead><tr><th class="y4i-src"><?php esc_html_e('English', 'y4apk'); ?></th><th><?php echo esc_html($all[$code][0]); ?></th></tr></thead>
				<tbody>
				<?php if (!$slice) : ?>
					<tr><td colspan="2"><?php esc_html_e('Nothing here.', 'y4apk'); ?></td></tr>
				<?php endif; ?>
				<?php $i = 0; foreach ($slice as $key => $it) :
					$has = isset($dict[$key]) && $dict[$key] !== '';
					$en = self::kept($key);
					$where = $it['f'] === 'site' ? __('the site’s own text (category, menu, home page)', 'y4apk') : ($it['f'] === 'seen' ? __('seen on a page', 'y4apk') : ($it['f'] === 'wp' ? __('dates', 'y4apk') : $it['f']));
					?>
					<tr class="<?php echo $en ? 'is-english' : ($has ? '' : 'is-missing'); ?><?php echo !empty($mine[$key]) ? ' is-mine' : ''; ?>">
						<td class="y4i-src"><div><?php echo esc_html($it['s']); ?></div><small><?php echo esc_html($where . ($it['c'] !== '' && $it['c'] !== self::SITE && strpos($it['c'], 'wp') !== 0 ? ' · ' . $it['c'] : '')); ?></small></td>
						<td>
							<input type="hidden" name="k[<?php echo (int) $i; ?>]" value="<?php echo esc_attr(base64_encode($key)); ?>">
							<textarea name="t[<?php echo (int) $i; ?>]" rows="<?php echo strlen($it['s']) > 90 ? 3 : 1; ?>" lang="<?php echo esc_attr($all[$code][2]); ?>"<?php echo $rtl ? ' dir="rtl"' : ''; ?>><?php echo esc_textarea(trim((string) ($dict[$key] ?? ''))); ?></textarea>
							<span class="y4i-who"><?php echo $en ? esc_html__('shown in English', 'y4apk') : (!empty($mine[$key]) ? esc_html__('written by you', 'y4apk') : ($has ? esc_html__('by the AI', 'y4apk') : (!empty($bad[$key]) && $bad[$key] >= 2 ? esc_html__('the AI got it wrong twice: write it yourself', 'y4apk') : esc_html__('not translated yet', 'y4apk')))); ?></span>
							<label class="y4i-en"><input type="checkbox" name="en[<?php echo (int) $i; ?>]" value="1" <?php checked($en); ?>> <?php esc_html_e('Keep in English (every language)', 'y4apk'); ?></label>
						</td>
					</tr>
				<?php $i++; endforeach; ?>
				</tbody>
			</table>
			<p style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
				<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
				<?php if ($pages > 1) : ?>
					<span><?php echo esc_html(sprintf(__('Page %1$s of %2$s', 'y4apk'), number_format_i18n($page), number_format_i18n($pages))); ?></span>
					<?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('p', $page - 1, $here)); ?>">← <?php esc_html_e('Previous', 'y4apk'); ?></a><?php endif; ?>
					<?php if ($page < $pages) : ?><a class="button" href="<?php echo esc_url(add_query_arg('p', $page + 1, $here)); ?>"><?php esc_html_e('Next', 'y4apk'); ?> →</a><?php endif; ?>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}
}

Y4APK_I18n::init();
