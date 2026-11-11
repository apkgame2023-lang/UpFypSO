<?php
/**
 * Members' profiles in search engines (2.77).
 *
 * A profile (/@name) is not a post, so Rank Math took it for the home page:
 * the home page's title, description and — worst — its canonical address, so
 * search engines read every profile as a copy of the home page and kept none.
 * Here a profile gets its own:
 *
 *   - title "Name (@name) · Y4APK", a description from its numbers and bio;
 *   - canonical address /@name (the /th/… copies stay noindex, pointing to it);
 *   - Open Graph (type profile, the member's picture) for shared links;
 *   - ProfilePage structured data, as Google asks for social profiles: the
 *     person, their picture and links, followers, posts and follows;
 *   - only profiles with at least one published post are offered to search
 *     engines (an empty profile is thin, and what spammers make); the others
 *     say noindex;
 *   - a sitemap of those profiles, /y4apk-profiles-sitemap.xml, listed in Rank
 *     Math's sitemap index and in robots.txt.
 *
 * Nothing private is shown: what the profile page shows anyway.
 */

defined('ABSPATH') || exit;

class Y4APK_Profile_SEO {

	const SITEMAP   = 'y4apk-profiles-sitemap.xml';
	const MIN_POSTS = 1;
	const MAX_URLS  = 45000;

	private static $ld_done = false;

	public static function init() {
		add_filter('rank_math/frontend/title', [__CLASS__, 'f_title'], 30);
		add_filter('rank_math/frontend/description', [__CLASS__, 'f_description'], 30);
		add_filter('rank_math/frontend/canonical', [__CLASS__, 'f_canonical'], 30); // the /th/… copies: made the original by Languages (99)
		add_filter('rank_math/opengraph/url', [__CLASS__, 'f_canonical'], 30);
		add_filter('rank_math/opengraph/facebook/og_title', [__CLASS__, 'f_title'], 30);
		add_filter('rank_math/opengraph/facebook/og_description', [__CLASS__, 'f_description'], 30);
		add_filter('rank_math/opengraph/twitter/twitter_title', [__CLASS__, 'f_title'], 30);
		add_filter('rank_math/opengraph/twitter/twitter_description', [__CLASS__, 'f_description'], 30);
		add_filter('rank_math/opengraph/facebook/og_type', [__CLASS__, 'f_og_type'], 30);
		add_filter('rank_math/frontend/robots', [__CLASS__, 'f_robots'], 30);
		add_filter('rank_math/json_ld', [__CLASS__, 'f_json_ld'], 100, 2);
		add_filter('document_title_parts', [__CLASS__, 'f_title_parts'], 30);
		add_action('wp_head', [__CLASS__, 'head'], 3);
		add_action('init', [__CLASS__, 'serve_sitemap'], 30);
		add_filter('rank_math/sitemap/index', [__CLASS__, 'f_sitemap_index'], 20);
		add_filter('robots_txt', [__CLASS__, 'f_robots_txt'], 20, 2);
	}

	/** The profile being shown: ['user', 'count', 'followers', 'following', 'views'], or null. */
	public static function current() {
		$c = class_exists('Y4APK_Members') ? Y4APK_Members::$ctx : null;
		if (!is_array($c) || ($c['view'] ?? '') !== 'profile' || empty($c['profile'])) {
			return null;
		}
		return ['user' => $c['profile'], 'count' => (int) ($c['count'] ?? 0), 'followers' => (int) ($c['followers'] ?? 0), 'following' => (int) ($c['following_n'] ?? 0), 'views' => (int) ($c['views'] ?? 0)];
	}

	/** Offered to search engines: a public member with something published. */
	public static function indexable($user, $count) {
		$ok = $user instanceof WP_User && Y4APK_Members::is_public($user) && (int) $count >= self::MIN_POSTS;
		return (bool) apply_filters('y4apk_profile_indexable', $ok, $user, $count);
	}

	/** The profile's own address (page 2… its own), as search engines should keep it. */
	public static function url($user) {
		$url = Y4APK_Members::profile_url($user);
		$paged = (int) get_query_var('paged');
		if ($paged > 1) {
			$url = trailingslashit($url) . 'page/' . $paged . '/';
		}
		return class_exists('Y4APK_I18n') ? Y4APK_I18n::original_url($url) : $url;
	}

	public static function title_text($p) {
		$u = $p['user'];
		$name = Y4APK_Social::name($u);
		$who = $name !== $u->user_login ? $name . ' (@' . $u->user_login . ')' : '@' . $u->user_login;
		$paged = (int) get_query_var('paged');
		return $who . ($paged > 1 ? ' · ' . sprintf(__('Page %s', 'y4apk'), number_format_i18n($paged)) : '') . ' · ' . get_bloginfo('name');
	}

	public static function description_text($p) {
		$u = $p['user'];
		$name = Y4APK_Social::name($u);
		$bits = [
			sprintf(_n('%s post', '%s posts', $p['count'], 'y4apk'), number_format_i18n($p['count'])),
			sprintf(_n('%s follower', '%s followers', $p['followers'], 'y4apk'), number_format_i18n($p['followers'])),
		];
		if ($p['views'] > 0) {
			$bits[] = sprintf(_n('%s view', '%s views', $p['views'], 'y4apk'), number_format_i18n($p['views']));
		}
		$d = sprintf(__('%1$s (@%2$s) on %3$s: %4$s.', 'y4apk'), $name, $u->user_login, get_bloginfo('name'), implode(', ', $bits));
		$bio = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(Y4APK_Social::bio($u))));
		if ($bio !== '') {
			$d .= ' ' . $bio;
		}
		return mb_strlen($d) > 158 ? rtrim(mb_substr($d, 0, 155)) . '…' : $d;
	}

	/* ---------------------------------------------------------------- *
	 * Rank Math (and WordPress without it)
	 * ---------------------------------------------------------------- */

	public static function f_title($t) {
		$p = self::current();
		return $p ? self::title_text($p) : $t;
	}

	public static function f_description($d) {
		$p = self::current();
		return $p ? self::description_text($p) : $d;
	}

	public static function f_canonical($url) {
		$p = self::current();
		return $p ? self::url($p['user']) : $url;
	}

	public static function f_og_type($t) {
		return self::current() ? 'profile' : $t;
	}

	public static function f_robots($robots) {
		$p = self::current();
		if (!$p) {
			return $robots;
		}
		$robots = is_array($robots) ? $robots : [];
		$listed = !isset($_GET['tab']) && !isset($_GET['sort']) && !isset($_GET['q']);
		$robots['index'] = $listed && self::indexable($p['user'], $p['count']) ? 'index' : 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	public static function f_title_parts($parts) {
		$p = self::current();
		if ($p) {
			$parts = ['title' => self::title_text($p)]; // the site's name is in it already
		}
		return $parts;
	}

	/** Rank Math's graph took the profile for the home page: its page goes, a ProfilePage comes. */
	public static function f_json_ld($data, $jsonld = null) {
		$p = self::current();
		if (!$p) {
			return $data;
		}
		$data = is_array($data) ? $data : [];
		foreach ($data as $k => $node) {
			$type = is_array($node) ? (array) ($node['@type'] ?? []) : [];
			if (array_intersect($type, ['WebPage', 'CollectionPage', 'SearchResultsPage', 'ItemList', 'BreadcrumbList', 'Article', 'BlogPosting'])) {
				unset($data[$k]);
			}
		}
		$data['Y4APKProfilePage'] = self::profile_page($p);
		self::$ld_done = true;
		return $data;
	}

	/** schema.org ProfilePage, as Google describes it for profiles on social sites. */
	public static function profile_page($p) {
		$u = $p['user'];
		$url = self::url($u);
		$home = class_exists('Y4APK_I18n') ? Y4APK_I18n::original_url(home_url('/')) : home_url('/');
		$person = [
			'@type'         => 'Person',
			'@id'           => $url . '#person',
			'name'          => Y4APK_Social::name($u),
			'alternateName' => '@' . $u->user_login,
			'identifier'    => (string) $u->ID,
			'url'           => $url,
			'interactionStatistic' => [
				['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/FollowAction', 'userInteractionCount' => $p['followers']],
			],
			'agentInteractionStatistic' => [
				['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/WriteAction', 'userInteractionCount' => $p['count']],
				['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/FollowAction', 'userInteractionCount' => $p['following']],
			],
		];
		$bio = trim(wp_strip_all_tags(Y4APK_Social::bio($u)));
		if ($bio !== '') {
			$person['description'] = $bio;
		}
		$pic = Y4APK_Social::picture($u);
		if ($pic !== '') {
			$person['image'] = $pic;
		}
		$same = [];
		foreach (Y4APK_Social::links($u) as $l) {
			if (is_string($l) && preg_match('~^https://~i', $l)) {
				$same[] = esc_url_raw($l);
			}
		}
		if ($same) {
			$person['sameAs'] = array_values(array_unique($same));
		}
		$latest = get_posts(['author' => $u->ID, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids']);
		return [
			'@type'        => 'ProfilePage',
			'@id'          => $url . '#profilepage',
			'url'          => $url,
			'name'         => self::title_text($p),
			'isPartOf'     => ['@id' => trailingslashit($home) . '#website'],
			'dateCreated'  => mysql2date('c', $u->user_registered . ' +00:00', false),
			'dateModified' => $latest ? get_post_modified_time('c', true, $latest[0]) : mysql2date('c', $u->user_registered . ' +00:00', false),
			'mainEntity'   => $person,
		];
	}

	/** What Rank Math does not say for a profile (its picture) — and, without Rank Math, all of it. */
	public static function head() {
		$p = self::current();
		if (!$p) {
			return;
		}
		$u = $p['user'];
		$pic = Y4APK_Social::picture($u);
		$out = '';
		if (!defined('RANK_MATH_VERSION')) {
			$out .= '<link rel="canonical" href="' . esc_url(self::url($u)) . '">' . "\n";
			$out .= '<meta name="description" content="' . esc_attr(self::description_text($p)) . '">' . "\n";
			$out .= '<meta property="og:type" content="profile">' . "\n";
			$out .= '<meta property="og:title" content="' . esc_attr(self::title_text($p)) . '">' . "\n";
			$out .= '<meta property="og:description" content="' . esc_attr(self::description_text($p)) . '">' . "\n";
			$out .= '<meta property="og:url" content="' . esc_url(self::url($u)) . '">' . "\n";
			if (!self::indexable($u, $p['count'])) {
				$out .= '<meta name="robots" content="noindex, follow">' . "\n";
			}
		}
		if ($pic !== '') {
			$out .= '<meta property="og:image" content="' . esc_url($pic) . '">' . "\n";
		}
		$out .= '<meta property="profile:username" content="' . esc_attr($u->user_login) . '">' . "\n";
		if (!self::$ld_done) {
			$out .= '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org'] + self::profile_page($p), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}

	/* ---------------------------------------------------------------- *
	 * The profiles' sitemap
	 * ---------------------------------------------------------------- */

	public static function sitemap_url() {
		$home = class_exists('Y4APK_I18n') ? Y4APK_I18n::original_url(home_url('/')) : home_url('/');
		return trailingslashit($home) . self::SITEMAP;
	}

	/** [user_id => last change (GMT)] of the profiles search engines are offered, newest first. */
	public static function listed() {
		global $wpdb;
		$rows = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT p.post_author AS id, MAX(p.post_modified_gmt) AS changed, COUNT(*) AS n FROM {$wpdb->posts} p
			JOIN {$wpdb->usermeta} m ON m.user_id = p.post_author AND m.meta_key = %s AND m.meta_value LIKE %s
			WHERE p.post_type = 'post' AND p.post_status = 'publish'
			GROUP BY p.post_author HAVING n >= %d ORDER BY changed DESC LIMIT %d",
			$wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like('"' . Y4APK_Members::ROLE . '"') . '%', self::MIN_POSTS, self::MAX_URLS));
		$ids = array_map(function ($r) { return (int) $r->id; }, $rows);
		if ($ids) {
			cache_users($ids);
		}
		$out = [];
		foreach ($rows as $r) {
			$u = get_userdata((int) $r->id);
			if ($u && self::indexable($u, (int) $r->n)) {
				$out[(int) $r->id] = (string) $r->changed;
			}
		}
		return $out;
	}

	public static function serve_sitemap() {
		$path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$home = untrailingslashit((string) wp_parse_url((string) get_option('home'), PHP_URL_PATH));
		if ($path !== $home . '/' . self::SITEMAP || is_admin()) {
			return;
		}
		$xml = get_transient('y4apk_profiles_sitemap');
		if (!is_string($xml)) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach (self::listed() as $id => $changed) {
				$u = get_userdata($id);
				$xml .= "\t<url><loc>" . esc_url(Y4APK_Members::profile_url($u)) . '</loc><lastmod>' . esc_html(mysql2date('c', $changed . ' +00:00', false)) . "</lastmod></url>\n";
			}
			$xml .= '</urlset>' . "\n";
			set_transient('y4apk_profiles_sitemap', $xml, 6 * HOUR_IN_SECONDS);
		}
		status_header(200);
		header('Content-Type: application/xml; charset=UTF-8');
		header('X-Robots-Tag: noindex, follow');
		header('Cache-Control: public, max-age=3600');
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		exit;
	}

	/** Rank Math's sitemap index lists ours too. */
	public static function f_sitemap_index($xml) {
		$listed = self::listed();
		if (!$listed) {
			return $xml;
		}
		$last = reset($listed);
		return $xml . "\n\t<sitemap>\n\t\t<loc>" . esc_url(self::sitemap_url()) . "</loc>\n\t\t<lastmod>" . esc_html(mysql2date('c', $last . ' +00:00', false)) . "</lastmod>\n\t</sitemap>";
	}

	public static function f_robots_txt($txt, $public) {
		if ($public && strpos((string) $txt, self::SITEMAP) === false) {
			$txt = rtrim((string) $txt) . "\nSitemap: " . self::sitemap_url() . "\n";
		}
		return $txt;
	}
}

Y4APK_Profile_SEO::init();
