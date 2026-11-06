<?php
/**
 * Y4APK Members ▸ where members come from (2.43): for the Dashboard's Members part.
 *
 * At sign-up the account gets y4apk_o_* fields (one usermeta row each, so the
 * Dashboard counts them with GROUP BY however many members there are): the country (Cloudflare's CF-IPCountry
 * header; else the browser's language region, marked as a guess), the kind of
 * device, its system and browser (from the User-Agent and client hints), whether
 * it was the installed Y4APK app or the home-screen web app, and where the visit
 * began: the site that sent the visitor (its host only, never the full address)
 * and the first page of the visit on this site. A member who joined before 2.43
 * gets the device fields at their next sign-in (where they came from stays
 * "not recorded"). No IP address and no full User-Agent are kept.
 *
 * The first page and referrer come from the browser: a tiny script on every page
 * keeps them in sessionStorage for the visit (y4apk_land), and the sign-up form
 * sends them along. Pages stay the same for everyone, so the page cache is fine.
 */

defined('ABSPATH') || exit;

class Y4APK_Insights {
	const PRE    = 'y4apk_o_';  // + cc, cc_by, device, os, browser, app, source, ref, land, lang, at
	const DEVICE = ['cc', 'cc_by', 'device', 'os', 'browser', 'app'];

	public static function init() {
		add_action('wp_head', [__CLASS__, 'land_script'], 0);
		add_action('user_register', [__CLASS__, 'on_register'], 20);
		add_action('wp_login', [__CLASS__, 'on_login'], 20, 2);
	}

	/** Where this visit began, kept for the visit in the browser (the sign-up form sends it). */
	public static function land_script() {
		echo '<script id="y4apk-pwa-land">try{if(!sessionStorage.getItem("y4apk_land")){sessionStorage.setItem("y4apk_land",JSON.stringify({r:document.referrer.slice(0,300),p:location.pathname.slice(0,200)}))}}catch(e){}</script>' . "\n";
	}

	public static function on_register($user_id) {
		$user = get_userdata($user_id);
		if (!$user || !Y4APK_Members::is_member($user) || is_admin()) {
			return;
		}
		self::put($user_id, array_merge(self::device(), self::posted_land(), ['at' => (string) time()]));
	}

	/** A member from before 2.43: the device, at their first sign-in since. */
	public static function on_login($login, $user) {
		if ($user instanceof WP_User && Y4APK_Members::is_member($user) && get_user_meta($user->ID, self::PRE . 'device', true) === '') {
			self::put($user->ID, self::device());
		}
	}

	private static function put($user_id, $fields) {
		foreach ($fields as $k => $v) {
			if ((string) $v !== '') {
				update_user_meta($user_id, self::PRE . $k, (string) $v);
			}
		}
	}

	/** One member's origin: [field => value], '' where not recorded. */
	public static function of($user_id) {
		$out = [];
		foreach (array_merge(self::DEVICE, ['source', 'ref', 'land', 'lang', 'at']) as $k) {
			$out[$k] = (string) get_user_meta($user_id, self::PRE . $k, true);
		}
		return $out;
	}

	/** What the request says about the device: country, kind, system, browser, app. */
	public static function device() {
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
		$cc = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $_SERVER['HTTP_CF_IPCOUNTRY']), 0, 2)) : '';
		$by = 'cf';
		if ($cc === '' || $cc === 'XX' || $cc === 'T1') {
			$cc = '';
			$by = '';
			$lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
			if (preg_match('/^[a-z]{2,3}-([A-Z]{2})\b/i', $lang, $m)) {
				$cc = strtoupper($m[1]);
				$by = 'lang';
			}
		}
		$app = isset($_POST['y4_app']) ? sanitize_key(wp_unslash($_POST['y4_app'])) : '';
		if (stripos($ua, 'Y4APK') !== false) {
			$app = 'apk';
		}
		return [
			'cc'      => $cc,
			'cc_by'   => $by,
			'device'  => self::kind($ua),
			'os'      => self::os($ua),
			'browser' => self::browser($ua),
			'app'     => in_array($app, ['apk', 'pwa'], true) ? $app : '',
		];
	}

	public static function kind($ua) {
		$mobile_hint = isset($_SERVER['HTTP_SEC_CH_UA_MOBILE']) ? trim((string) $_SERVER['HTTP_SEC_CH_UA_MOBILE']) : '';
		if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
			return 'tablet';
		}
		if ($mobile_hint === '?1' || preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua)) {
			return 'phone';
		}
		return $ua === '' ? '' : 'desktop';
	}

	public static function os($ua) {
		$rules = ['Android' => '/Android/i', 'iOS' => '/iPhone|iPad|iPod/i', 'Windows' => '/Windows/i', 'ChromeOS' => '/CrOS/', 'macOS' => '/Macintosh|Mac OS X/i', 'Linux' => '/Linux|X11/i'];
		foreach ($rules as $name => $rx) {
			if (preg_match($rx, $ua)) {
				return $name;
			}
		}
		return $ua === '' ? '' : 'Other';
	}

	public static function browser($ua) {
		// In-app browsers first: they also say Chrome or Safari.
		$rules = ['Facebook' => '/FBAN|FBAV|FB_IAB/', 'Instagram' => '/Instagram/', 'TikTok' => '/musical_ly|TikTok|BytedanceWebview/i', 'LINE' => '/\bLine\//', 'Discord' => '/Discord/i',
			'Samsung Internet' => '/SamsungBrowser/', 'Edge' => '/Edg(e|A|iOS)?\//', 'Opera' => '/OPR\/|Opera|OPT\//', 'Firefox' => '/Firefox|FxiOS/', 'Chrome' => '/Chrome|CriOS/', 'Safari' => '/Safari/'];
		foreach ($rules as $name => $rx) {
			if (preg_match($rx, $ua)) {
				return $name;
			}
		}
		return $ua === '' ? '' : 'Other';
	}

	/** The visit's referrer host and first page, from the sign-up form: ['source', 'ref', 'land', 'lang']. */
	private static function posted_land() {
		$ref = isset($_POST['y4_land_ref']) ? (string) wp_unslash($_POST['y4_land_ref']) : '';
		$page = isset($_POST['y4_land_page']) ? (string) wp_unslash($_POST['y4_land_page']) : '';
		$host = strtolower((string) wp_parse_url($ref, PHP_URL_HOST));
		if ($host === '' && strpos($ref, 'android-app://') === 0) {
			$host = strtolower(substr($ref, 14, 60));
		}
		$host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', preg_replace('/[^a-z0-9.\-]/', '', $host));
		$site = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
		$path = '/' . ltrim((string) wp_parse_url($page, PHP_URL_PATH), '/');
		$lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower(substr(preg_replace('/[^A-Za-z\-].*$/', '', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE']), 0, 2)) : '';
		return [
			'source' => self::source($host, $site),
			'ref'    => $host === $site ? '' : substr($host, 0, 60),
			'land'   => substr(sanitize_text_field($path), 0, 120),
			'lang'   => preg_match('/^[a-z]{2}$/', $lang) ? $lang : '',
		];
	}

	/** A referrer host as a source people recognise. */
	public static function source($host, $site = '') {
		if ($host === '' || ($site !== '' && $host === $site)) {
			return 'direct';
		}
		$map = [
			'google'   => '/(^|\.)google\.|googlequicksearchbox|com\.google\.android/',
			'youtube'  => '/(^|\.)(youtube\.com|youtu\.be)$|com\.google\.android\.youtube/',
			'tiktok'   => '/tiktok\.com$|musical\.ly|com\.zhiliaoapp/',
			'facebook' => '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com|instagram\.com)$|com\.facebook|com\.instagram/',
			'discord'  => '/(^|\.)(discord\.com|discord\.gg|discordapp\.com)$/',
			'telegram' => '/(^|\.)(t\.me|telegram\.org|telegram\.me)$/',
			'x'        => '/(^|\.)(x\.com|twitter\.com|t\.co)$/',
			'search'   => '/(^|\.)(bing\.com|duckduckgo\.com|yahoo\.com|yandex\.[a-z]+|baidu\.com|naver\.com|coccoc\.com|ecosia\.org|brave\.com)$/',
		];
		foreach ($map as $name => $rx) {
			if (preg_match($rx, $host)) {
				return $name;
			}
		}
		return 'other';
	}

	public static function source_names() {
		return ['google' => 'Google', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'facebook' => __('Facebook & Instagram', 'y4apk'), 'discord' => 'Discord', 'telegram' => 'Telegram', 'x' => 'X',
			'search' => __('Other search engines', 'y4apk'), 'direct' => __('Typed it in, bookmark or app', 'y4apk'), 'other' => __('Other websites', 'y4apk'), '' => __('Not recorded', 'y4apk')];
	}

	/** "Thailand" for TH (the server's intl when it has it), else the code. */
	public static function country_name($cc) {
		if ($cc === '') {
			return __('Unknown', 'y4apk');
		}
		if (class_exists('Locale')) {
			$n = Locale::getDisplayRegion('-' . $cc, 'en');
			if (is_string($n) && $n !== '' && $n !== $cc) {
				return $n;
			}
		}
		return $cc;
	}
}

Y4APK_Insights::init();
