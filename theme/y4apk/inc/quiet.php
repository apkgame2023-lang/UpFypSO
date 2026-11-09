<?php
/**
 * Quiet pages (2.61.1): what a page's source says about how the site is made,
 * kept out of it.
 *
 * Plugins sign the pages they touch ("Search Engine Optimization by Rank Math
 * PRO", "Google Analytics snippet added by Site Kit", "Page cached by LiteSpeed
 * Cache 7.9.3 on …"), and WordPress names itself in a few places. Here:
 *
 * - Every HTML comment leaves the page, and so does every
 *   <meta name="generator">: one output buffer, on the pages visitors get.
 *   Scripts, styles, <pre> and <textarea> stay exactly as they are, and so do
 *   Internet Explorer's [if] comments. The cache keeps the clean page.
 * - LiteSpeed Cache's own line is added after that buffer; its filters take
 *   it off (litespeed_comment, and litespeed_buffer_after as a second net).
 * - The REST API and oEmbed discovery links (in <head> and the Link header),
 *   X-Powered-By (PHP's version) and X-Pingback are not sent.
 * - "?ver=" equal to WordPress's version becomes a short code that still
 *   changes when WordPress is updated (browsers fetch the new files), so the
 *   version is not on the page.
 *
 * The /wp-content/ and /wp-includes/ addresses stay: renaming them breaks
 * plugins and updates. They say which software runs the site, as a page's
 * look does, never who runs it.
 */

defined('ABSPATH') || exit;

class Y4APK_Quiet {

	public static function init() {
		add_action('template_redirect', [__CLASS__, 'start'], 0);

		add_filter('rank_math/frontend/remove_credit_notice', '__return_true');
		add_filter('litespeed_comment', '__return_false');
		add_filter('litespeed_buffer_after', [__CLASS__, 'drop_litespeed_line'], 99);

		remove_action('wp_head', 'rest_output_link_wp_head', 10);
		remove_action('template_redirect', 'rest_output_link_header', 11);
		remove_action('wp_head', 'wp_oembed_add_discovery_links');
		remove_action('template_redirect', 'wp_shortlink_header', 11);
		add_filter('wp_headers', [__CLASS__, 'headers']);
		add_action('send_headers', [__CLASS__, 'no_powered_by']);

		add_filter('style_loader_src', [__CLASS__, 'version'], 99);
		add_filter('script_loader_src', [__CLASS__, 'version'], 99);
	}

	/** The page visitors get goes through clean() on its way out. */
	public static function start() {
		if (is_admin() || is_feed() || is_robots() || is_trackback() || is_embed() || wp_doing_ajax()) {
			return;
		}
		ob_start([__CLASS__, 'clean']);
	}

	/** Comments and generator tags out of an HTML page; anything else goes as it came. */
	public static function clean($html) {
		if (!is_string($html) || $html === '' || !preg_match('~^\s*(?:<!DOCTYPE\s+html|<html[\s>])~i', $html)) {
			return $html;
		}
		foreach (headers_list() as $h) {
			if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
				return $html;
			}
		}
		$parts = preg_split('~(<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>|<pre\b[^>]*>.*?</pre\s*>|<textarea\b[^>]*>.*?</textarea\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($parts)) {
			return $html;
		}
		foreach ($parts as $i => $part) {
			if ($i % 2) {
				// A script, style, pre or textarea: kept, but for the plugin's name on a structured-data script.
				$parts[$i] = preg_replace('~^(<script\b[^>]*?)\s+class=(["\'])rank-math-schema(?:-pro)?\2~i', '$1', $part, 1);
				continue;
			}
			$clean = preg_replace([
				'~[ \t]*<!--(?!\[if\b|<!).*?-->[ \t]*(?:\r?\n)?~s',
				'~[ \t]*<meta\s+name=(["\'])generator\1[^>]*>[ \t]*(?:\r?\n)?~i',
			], '', $part);
			if (is_string($clean)) {
				$parts[$i] = $clean;
			}
		}
		return implode('', $parts);
	}

	/** LiteSpeed Cache's "Page cached by …" line, should a version still add it. */
	public static function drop_litespeed_line($buffer) {
		return is_string($buffer) ? preg_replace('~\s*<!-- Page (?:un)?(?:cached|optimized|supported)[^>]*?LiteSpeed[^>]*-->\s*$~i', '', $buffer) : $buffer;
	}

	public static function headers($headers) {
		unset($headers['X-Pingback']);
		return $headers;
	}

	public static function no_powered_by() {
		if (function_exists('header_remove') && !headers_sent()) {
			header_remove('X-Powered-By');
		}
	}

	/** "?ver=6.8.3" (WordPress's own version) becomes "?ver=" and a short code made from it. */
	public static function version($src) {
		static $wp = null, $code = null;
		if ($wp === null) {
			$wp = (string) get_bloginfo('version');
			$code = substr(md5($wp . '|' . (defined('AUTH_SALT') ? AUTH_SALT : home_url())), 0, 8);
		}
		if (!is_string($src) || $wp === '' || strpos($src, 'ver=' . $wp) === false) {
			return $src;
		}
		return preg_replace('~([?&]ver=)' . preg_quote($wp, '~') . '(?=&|#|$)~', '${1}' . $code, $src);
	}
}

Y4APK_Quiet::init();
