<?php
/**
 * Help and legal pages (2.52), the way the big platforms lay them out:
 * Terms of Service, Privacy Policy, DMCA Policy, a copyright form, Contact
 * and Feedback — each with its own card at the top, "the short version" and
 * an "On this page" list on the legal ones, and forms that a person answers
 * in Y4APK ▸ Support.
 *
 * They are ordinary WordPress pages (so Rank Math, the sitemap and links treat
 * them as pages), made once: a page the site already has at one of the usual
 * addresses (/privacy-policy/, /dmca-policy/, /feedback/ …) is used as it is,
 * the others are made. The theme draws them; Y4APK ▸ Support can switch a page
 * back to its own text.
 *
 * The forms work on cached pages: they send with AJAX, with the bot trap and
 * the Turnstile check of the sign-in forms, a few messages an hour from one
 * address, and no address of the site's owner anywhere on the page.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/help-texts.php';

class Y4APK_Help {

	const VERSION = '2'; // 2.53: the FAQ page
	const CPT     = 'y4apk_ticket';
	const MAP     = 'y4apk_help_pages';  // [key => page ID]
	const OWN     = 'y4apk_help_own';    // [key => 1]: that page shows its own text
	const UPDATED = '2026-11-11';         // the legal pages' "Last updated"
	const SLUG    = 'y4apk-support';
	const PRE     = '_y4t_';             // a message's details (post meta)
	const HIDDEN  = '_y4apk_dmca_hidden'; // on a post hidden for a copyright notice: [ticket, status before]

	/** The page being shown ('' when it is not one of these). */
	public static $page = '';

	public static function init() {
		add_action('init', [__CLASS__, 'register']);
		add_filter('template_include', [__CLASS__, 'template'], 30);
		add_filter('rank_math/frontend/description', [__CLASS__, 'description'], 20);
		add_filter('body_class', [__CLASS__, 'body_class']);
		add_action('wp_ajax_y4apk_help_send', [__CLASS__, 'ajax_send']);
		add_action('wp_ajax_nopriv_y4apk_help_send', [__CLASS__, 'ajax_send']);
		add_action('wp_ajax_y4apk_help_mine', [__CLASS__, 'ajax_mine']);
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'setup']);
			add_action('admin_menu', [__CLASS__, 'menu'], 109);
			add_action('admin_post_y4apk_help', [__CLASS__, 'handle_admin']);
		}
	}

	/* ---------------------------------------------------------------- *
	 * The pages
	 * ---------------------------------------------------------------- */

	/** key => [address, title, tag, icon, colour, the addresses a site may already use, lead] */
	public static function pages() {
		return [
			'terms'     => ['slug' => 'terms', 'title' => __('Terms of Service', 'y4apk'), 'tag' => __('Legal', 'y4apk'), 'icon' => 'doc', 'tint' => 'blue',
				'find' => ['terms', 'terms-of-service', 'terms-and-conditions', 'terms-of-use', 'tos'], 'lead' => __('The rules for using Y4APK: your account, what you share, downloads and scripts.', 'y4apk')],
			'privacy'   => ['slug' => 'privacy-policy', 'title' => __('Privacy Policy', 'y4apk'), 'tag' => __('Legal', 'y4apk'), 'icon' => 'shield', 'tint' => 'green',
				'find' => ['privacy-policy', 'privacy'], 'lead' => __('What Y4APK collects when you use it, why, who can see it, and the choices you have.', 'y4apk')],
			'dmca'      => ['slug' => 'dmca', 'title' => __('DMCA Policy', 'y4apk'), 'tag' => __('Legal', 'y4apk'), 'icon' => 'scale', 'tint' => 'violet',
				'find' => ['dmca', 'dmca-policy', 'copyright-policy'], 'lead' => __('How we handle copyright notices and counter-notices.', 'y4apk')],
			'copyright' => ['slug' => 'copyright', 'title' => __('Copyright', 'y4apk'), 'tag' => __('Copyright', 'y4apk'), 'icon' => 'copyright', 'tint' => 'amber',
				'find' => ['copyright', 'report-copyright', 'dmca-notice'], 'h1' => __('Report a copyright violation', 'y4apk'), 'lead' => __('Is your game, app or other work on Y4APK without your permission? Tell us what to remove.', 'y4apk')],
			'contact'   => ['slug' => 'contact', 'title' => __('Contact', 'y4apk'), 'tag' => __('Get in touch', 'y4apk'), 'icon' => 'mail', 'tint' => 'red',
				'find' => ['contact', 'contact-us'], 'h1' => __('Contact us', 'y4apk'), 'lead' => __('Questions, problems, copyright notices or ideas for working together: send us a message and a person will read it.', 'y4apk')],
			'feedback'  => ['slug' => 'feedback', 'title' => __('Feedback', 'y4apk'), 'tag' => __('Feedback', 'y4apk'), 'icon' => 'chat', 'tint' => 'teal',
				'find' => ['feedback', 'send-feedback'], 'h1' => __('Send feedback', 'y4apk'), 'lead' => __('Tell us what you like and what we should do better. We read every message.', 'y4apk')],
			'faq'       => ['slug' => 'faq', 'title' => __('FAQ', 'y4apk'), 'tag' => __('Help', 'y4apk'), 'icon' => 'help', 'tint' => 'sky',
				'find' => ['faq', 'faqs', 'frequently-asked-questions'], 'h1' => __('Frequently asked questions', 'y4apk'), 'lead' => __('Answers about downloads, MODs, scripts and your account.', 'y4apk')],
		];
	}

	public static function legal() {
		return ['terms', 'privacy', 'dmca'];
	}

	/** The page's address (in the page's language). */
	public static function url($key) {
		static $primed = false;
		$map = get_option(self::MAP, []);
		if (!$primed && is_array($map) && $map && function_exists('_prime_post_caches')) {
			$primed = true;
			_prime_post_caches(array_values(array_filter(array_map('intval', $map))), false, false); // 2.74: the help pages in one query
		}
		if (is_array($map) && !empty($map[$key]) && get_post_status((int) $map[$key]) === 'publish') {
			return get_permalink((int) $map[$key]);
		}
		$p = self::pages();
		return home_url('/' . (isset($p[$key]) ? $p[$key]['slug'] : $key) . '/');
	}

	/** Once (and after an update of this part): the pages, found or made. */
	public static function setup() {
		if (get_option('y4apk_help_v') === self::VERSION || !current_user_can('manage_options')) {
			return;
		}
		update_option('y4apk_help_v', self::VERSION);
		self::make_pages();
	}

	public static function make_pages() {
		$map = get_option(self::MAP, []);
		$map = is_array($map) ? $map : [];
		foreach (self::pages() as $key => $p) {
			if (!empty($map[$key]) && get_post_status((int) $map[$key]) === 'publish') {
				continue;
			}
			$id = 0;
			foreach ($p['find'] as $slug) {
				$page = get_page_by_path($slug, OBJECT, 'page');
				if (!$page || in_array((int) $page->ID, array_map('intval', $map), true)) {
					continue;
				}
				if ($page->post_status === 'publish') {
					$id = (int) $page->ID; // the site's own page at that address
					break;
				}
				if ($slug === $p['slug'] && in_array($page->post_status, ['draft', 'pending'], true)) {
					wp_update_post(['ID' => $page->ID, 'post_status' => 'publish']); // WordPress's own unpublished "Privacy Policy": its address, kept
					$id = (int) $page->ID;
					break;
				}
			}
			if (!$id) {
				$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p['title'], 'post_name' => $p['slug'], 'post_excerpt' => $p['lead'],
					'post_content' => '', 'comment_status' => 'closed', 'ping_status' => 'closed']);
			}
			if ($id) {
				$map[$key] = $id;
			}
		}
		update_option(self::MAP, $map);
		// WordPress's own "Privacy Policy page" (links in the login screen, the comment form, plugins)
		$priv = (int) get_option('wp_page_for_privacy_policy');
		if (!empty($map['privacy']) && ($priv === 0 || get_post_status($priv) !== 'publish')) {
			update_option('wp_page_for_privacy_policy', (int) $map['privacy']);
		}
		return $map;
	}

	/** Which of these pages is this (by its ID)? */
	public static function key_of($post_id) {
		$map = get_option(self::MAP, []);
		$key = is_array($map) ? array_search((int) $post_id, array_map('intval', $map), true) : false;
		return $key === false ? '' : (string) $key;
	}

	public static function template($template) {
		if (!is_page()) {
			return $template;
		}
		$key = self::key_of(get_queried_object_id());
		$own = get_option(self::OWN, []);
		if ($key === '' || (is_array($own) && !empty($own[$key]))) {
			return $template;
		}
		self::$page = $key;
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-help', get_template_directory_uri() . '/assets/css/help.css', ['core'], Y4APK_THEME_VERSION);
		}, 20);
		return locate_template('template/help.php') ?: $template;
	}

	public static function description($desc) {
		if (self::$page !== '' && trim((string) $desc) === '') {
			return self::pages()[self::$page]['lead'];
		}
		return $desc;
	}

	public static function body_class($c) {
		if (self::$page !== '') {
			$c[] = 'y4h-page';
		}
		return $c;
	}

	/** The links in the footer: these pages first, then the menu's other items (FAQ …). */
	public static function footer_links($menu) {
		$map = get_option(self::MAP, []);
		$ids = is_array($map) ? array_map('intval', $map) : [];
		$out = [];
		foreach (['terms', 'privacy', 'dmca', 'copyright', 'contact', 'feedback', 'faq'] as $k) {
			$out[] = [self::url($k), self::pages()[$k]['title']];
		}
		$paths = [];
		foreach (self::pages() as $p) {
			foreach ($p['find'] as $slug) {
				$paths[] = '/' . $slug . '/';
			}
		}
		foreach (is_array($menu) ? $menu : [] as $item) {
			$path = trailingslashit((string) wp_parse_url((string) $item->url, PHP_URL_PATH));
			$path = class_exists('Y4APK_I18n') ? preg_replace('~^/[a-z]{2,3}(-[a-z]{2})?/~', '/', $path) : $path;
			if ((isset($item->object) && $item->object === 'page' && in_array((int) $item->object_id, $ids, true)) || in_array($path, $paths, true)) {
				continue; // one of these pages: already listed
			}
			$out[] = [(string) $item->url, (string) $item->title];
		}
		return $out;
	}

	/* ---------------------------------------------------------------- *
	 * Messages: a private post type, answered in Y4APK ▸ Support
	 * ---------------------------------------------------------------- */

	public static function register() {
		register_post_type(self::CPT, ['label' => __('Support', 'y4apk'), 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'exclude_from_search' => true,
			'publicly_queryable' => false, 'query_var' => false, 'rewrite' => false, 'supports' => ['title', 'editor', 'author']]);
	}

	/** What a message is about: key => [label, icon, hint]. */
	public static function topics() {
		return [
			'general'  => [__('General question', 'y4apk'), 'help'],
			'problem'  => [__('Report content or a bug', 'y4apk'), 'bug'],
			'copy'     => [__('Copyright notice', 'y4apk'), 'copyright'],
			'privacy'  => [__('Privacy or data request', 'y4apk'), 'shield'],
			'account'  => [__('Account help & appeals', 'y4apk'), 'user'],
			'business' => [__('Advertising & partnerships', 'y4apk'), 'megaphone'],
		];
	}

	public static function statuses() {
		return ['new' => __('New', 'y4apk'), 'open' => __('Answered', 'y4apk'), 'done' => __('Closed', 'y4apk')];
	}

	/** The hosts a copyright notice may point to: this site, its file and download addresses. */
	private static function own_hosts() {
		$hosts = [strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)), 'y4apk.com', 'www.y4apk.com', 'files.y4apk.com', 'y4apk.net', 'files.y4apk.net'];
		if (class_exists('Y4APK_App_Updates') && method_exists('Y4APK_App_Updates', 'own_domains')) {
			$hosts = array_merge($hosts, (array) Y4APK_App_Updates::own_domains());
		}
		return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
	}

	private static function fail($msg, $field = '') {
		wp_send_json_error(['message' => $msg, 'field' => $field]);
	}

	public static function ajax_send() {
		nocache_headers();
		$in = wp_unslash($_POST);
		$kind = isset($in['kind']) && in_array($in['kind'], ['contact', 'copyright', 'feedback'], true) ? $in['kind'] : '';
		if ($kind === '') {
			self::fail(__('Something went wrong. Reload the page and try again.', 'y4apk'));
		}
		$user = wp_get_current_user();
		$signed = $user->exists();
		if (!class_exists('Y4APK_Members')) {
			self::fail(__('Something went wrong. Reload the page and try again.', 'y4apk'));
		}
		$text = function ($k, $max) use ($in) {
			return isset($in[$k]) ? trim(mb_substr(sanitize_textarea_field((string) $in[$k]), 0, $max)) : '';
		};
		$line = function ($k, $max) use ($in) {
			return isset($in[$k]) ? trim(mb_substr(sanitize_text_field((string) $in[$k]), 0, $max)) : '';
		};
		$email = $signed ? (string) $user->user_email : sanitize_email($line('email', 100));
		$name = $signed ? (class_exists('Y4APK_Social') ? Y4APK_Social::name($user) : $user->display_name) : $line('name', 80);
		$meta = ['kind' => $kind, 'status' => 'new', 'email' => $email, 'name' => $name, 'user' => $signed ? (int) $user->ID : 0, 'lang' => class_exists('Y4APK_I18n') ? Y4APK_I18n::current() : 'en'];
		$page = esc_url_raw($line('page', 300));
		$meta['page'] = $page !== '' && self::is_ours($page) ? $page : '';

		if ($kind === 'feedback') {
			$rating = isset($in['rating']) ? (int) $in['rating'] : 0;
			if ($rating < 1 || $rating > 5) {
				self::fail(__('Pick how Y4APK is for you.', 'y4apk'), 'rating');
			}
			$message = $text('message', 2000);
			if (!$signed && $email !== '' && !is_email($email)) {
				self::fail(__('Enter a valid email address, or leave it empty.', 'y4apk'), 'email');
			}
			$meta['rating'] = $rating;
			$meta['topic'] = 'feedback';
		} elseif ($kind === 'contact') {
			$topic = isset($in['topic']) && isset(self::topics()[$in['topic']]) ? (string) $in['topic'] : 'general';
			if (!$signed && !is_email($email)) {
				self::fail(__('Enter a valid email address, so we can answer.', 'y4apk'), 'email');
			}
			$message = $text('message', 5000);
			if (mb_strlen($message) < 10) {
				self::fail(__('Write a little more, so we can help (10 characters or more).', 'y4apk'), 'message');
			}
			$link = esc_url_raw($line('link', 500));
			$meta['topic'] = $topic;
			$meta['link'] = $link;
		} else { // copyright notice
			if (!$signed && !is_email($email)) {
				self::fail(__('Enter a valid email address, so we can answer.', 'y4apk'), 'email');
			}
			$name = $line('name', 80) ?: $name;
			$work = $text('work', 2000);
			$links = [];
			foreach (preg_split('/[\s,]+/', $text('urls', 8000)) as $u) {
				$u = esc_url_raw(trim($u), ['http', 'https']);
				if ($u !== '' && self::is_ours($u) && count($links) < 100) {
					$links[$u] = 1;
				}
			}
			$sig = $line('signature', 80);
			$role = isset($in['role']) && $in['role'] === 'agent' ? 'agent' : 'owner';
			if ($name === '') {
				self::fail(__('Enter your full name.', 'y4apk'), 'name');
			}
			if (mb_strlen($work) < 5) {
				self::fail(__('Tell us which work is yours, and where the original is.', 'y4apk'), 'work');
			}
			if (!$links) {
				self::fail(__('Add at least one link to a page or file on Y4APK.', 'y4apk'), 'urls');
			}
			if (empty($in['good']) || empty($in['true']) || empty($in['allowed'])) {
				self::fail(__('Tick the three statements: the law needs them.', 'y4apk'), 'good');
			}
			if ($sig === '') {
				self::fail(__('Type your full name as your signature.', 'y4apk'), 'signature');
			}
			$message = $work;
			$meta['topic'] = 'copy';
			$meta['name'] = $name;
			$meta['dmca'] = ['company' => $line('company', 120), 'role' => $role, 'work' => $work, 'urls' => array_keys($links), 'signature' => $sig, 'at' => time()];
		}
		// What was written is right: now bots (the trap of the sign-in forms, and Turnstile for someone not
		// signed in), then a few messages an hour — only one that would be sent counts
		if (!Y4APK_Members::human_ok(!$signed && $kind !== 'feedback')) {
			self::fail(__('Please wait a few seconds and try again.', 'y4apk'));
		}
		$who = $signed ? 'u' . $user->ID : Y4APK_Members::client_ip();
		if (!Y4APK_Members::hit('help:' . $kind . ':' . $who, $kind === 'feedback' ? 10 : 5, HOUR_IN_SECONDS)) {
			self::fail(__('You have sent several messages already. Try again in an hour.', 'y4apk'));
		}
		$ref = 'Y4-' . strtoupper(wp_generate_password(6, false, false));
		$meta['ref'] = $ref;
		$labels = self::topics();
		$title = ($meta['topic'] === 'feedback' ? __('Feedback', 'y4apk') : $labels[$meta['topic']][0]) . ' · ' . ($name !== '' ? $name : ($email !== '' ? $email : __('a visitor', 'y4apk')));
		$id = class_exists('Y4APK_I18n') ? Y4APK_I18n::untranslated(function () use ($title, $message, $signed, $user) {
			return wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $message, 'post_author' => $signed ? (int) $user->ID : 0], true);
		}) : wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $message, 'post_author' => $signed ? (int) $user->ID : 0], true);
		if (is_wp_error($id) || !$id) {
			self::fail(__('Something went wrong. Try again in a minute.', 'y4apk'));
		}
		foreach ($meta as $k => $v) {
			update_post_meta($id, self::PRE . $k, is_array($v) ? $v : wp_slash($v));
		}
		self::tell_owner($id);
		if ($email !== '' && is_email($email) && $kind !== 'feedback') {
			self::receipt($id);
		}
		wp_send_json_success(['ref' => $ref]);
	}

	/** Is an address on this site (its pages, its files)? */
	private static function is_ours($url) {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		return $host !== '' && in_array($host, self::own_hosts(), true);
	}

	private static function m($id, $k, $d = '') {
		$v = get_post_meta($id, self::PRE . $k, true);
		return $v === '' || $v === null ? $d : $v;
	}

	/** The site's owner hears of a new message (at most every 10 minutes; feedback is only counted). */
	private static function tell_owner($id) {
		if (self::m($id, 'kind') === 'feedback' || !class_exists('Y4APK_Members') || !Y4APK_Members::hit('help_owner', 1, 10 * MINUTE_IN_SECONDS)) {
			return;
		}
		Y4APK_I18n::untranslated(function () use ($id) {
			$topic = self::topics()[self::m($id, 'topic', 'general')][0] ?? __('Message', 'y4apk');
			Y4APK_Members::mail(get_option('admin_email'), sprintf(__('New message: %s', 'y4apk'), $topic), [
				'title' => sprintf(__('New message: %s', 'y4apk'), $topic), 'preheader' => wp_trim_words((string) get_post_field('post_content', $id), 16),
				'lines' => [sprintf(__('From %1$s (%2$s).', 'y4apk'), self::m($id, 'name', __('a visitor', 'y4apk')), self::m($id, 'ref'))],
				'quote' => wp_trim_words((string) get_post_field('post_content', $id), 80),
				'button' => [__('Open in Y4APK ▸ Support', 'y4apk'), add_query_arg(['page' => self::SLUG, 't' => $id], admin_url('admin.php'))],
				'why' => __('You get these for messages sent through the Contact and copyright forms (at most one every 10 minutes).', 'y4apk')]);
			return null;
		});
	}

	/** "We got your message": its number, in the sender's language (not their message: the form cannot send our emails for others). */
	private static function receipt($id) {
		$email = (string) self::m($id, 'email');
		$ref = (string) self::m($id, 'ref');
		$topic = self::topics()[self::m($id, 'topic', 'general')][0] ?? '';
		Y4APK_Members::mail($email, sprintf(__('We got your message (%s)', 'y4apk'), $ref), [
			'title' => __('We got your message', 'y4apk'), 'preheader' => sprintf(__('Its number is %s.', 'y4apk'), $ref), 'hi' => (string) self::m($id, 'name'),
			'lines' => [sprintf(__('Thanks for writing to us about “%s”. A person reads every message, and we answer by email.', 'y4apk'), $topic),
				sprintf(__('Your message’s number is %s. If you write again about it, add the number.', 'y4apk'), $ref)],
			'why' => __('You got this email because this address was entered in a form on our site. If it was not you, ignore it.', 'y4apk')]);
	}

	/** A signed-in member's own messages, for "Your requests" on the Contact page. */
	public static function ajax_mine() {
		nocache_headers();
		$user = wp_get_current_user();
		if (!$user->exists()) {
			wp_send_json_success(['items' => []]);
		}
		$items = [];
		foreach (get_posts(['post_type' => self::CPT, 'author' => $user->ID, 'numberposts' => 8, 'post_status' => 'publish']) as $p) {
			if (self::m($p->ID, 'kind') === 'feedback') {
				continue;
			}
			$thread = (array) self::m($p->ID, 'thread', []);
			$last = $thread ? end($thread) : null;
			$items[] = ['ref' => self::m($p->ID, 'ref'), 'topic' => self::topics()[self::m($p->ID, 'topic', 'general')][0] ?? '', 'status' => self::m($p->ID, 'status', 'new'),
				'when' => sprintf(__('%s ago', 'y4apk'), human_time_diff(get_post_time('U', true, $p))), 'reply' => $last ? wp_trim_words((string) $last['text'], 30) : ''];
		}
		wp_send_json_success(['items' => $items]);
	}

	/* ---------------------------------------------------------------- *
	 * Admin: Y4APK ▸ Support
	 * ---------------------------------------------------------------- */

	private static function count_new() {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'new'
			JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value <> 'feedback' WHERE p.post_type = %s AND p.post_status = 'publish'", self::PRE . 'status', self::PRE . 'kind', self::CPT));
	}

	public static function menu() {
		$n = self::count_new();
		add_submenu_page(Y4APK_MENU, __('Support', 'y4apk'), __('Support', 'y4apk') . ($n ? ' <span class="awaiting-mod">' . number_format_i18n($n) . '</span>' : ''), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	/** A link in a copyright notice → the post it is about (a page, its download page, a member's version, a file folder). */
	public static function post_of($url) {
		$url = class_exists('Y4APK_I18n') ? (string) preg_replace('~^(https?://[^/]+)/[a-z]{2,3}(?:-[a-z]{2})?/~', '$1/', (string) $url) : (string) $url;
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
		if ($host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
			$id = url_to_postid(preg_replace('~/download(/\d+)?/?$~', '/', $url));
			if ($id) {
				return (int) $id;
			}
			$parts = array_values(array_filter(explode('/', preg_replace('~/download(/\d+)?$~', '', $path))));
			$slug = $parts ? sanitize_title(end($parts)) : '';
			if ($slug !== '') {
				$p = get_posts(['name' => $slug, 'post_type' => ['post', 'articles', 'news', 'y4apk_executor'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
				return $p ? (int) $p[0] : 0;
			}
			return 0;
		}
		// A file or its download folder: the post whose download row points to it
		$folder = preg_replace('~^(up/)?([^/]+).*$~', '$2', $path);
		if ($folder === '' || strlen($folder) < 4) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'repeatable_download_link' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like($folder) . '%'));
	}

	public static function handle_admin() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-help');
		$in = wp_unslash($_POST);
		$id = isset($in['t']) ? (int) $in['t'] : 0;
		$do = isset($in['do']) ? sanitize_key($in['do']) : '';
		$back = add_query_arg(array_filter(['page' => self::SLUG, 't' => $id ?: '']), admin_url('admin.php'));
		if ($do === 'pages') { // which pages show the theme's words
			$own = [];
			foreach (array_keys(self::pages()) as $k) {
				if (empty($in['theme'][$k])) {
					$own[$k] = 1;
				}
			}
			update_option(self::OWN, $own);
			if (!empty($in['remake'])) {
				self::make_pages();
			}
			y4apk_purge_pages();
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'view' => 'pages', 'saved' => 1], admin_url('admin.php')));
			exit;
		}
		if (!$id || get_post_type($id) !== self::CPT) {
			wp_safe_redirect($back);
			exit;
		}
		$note = '';
		if ($do === 'reply') {
			$text = trim(sanitize_textarea_field((string) ($in['reply'] ?? '')));
			$email = (string) self::m($id, 'email');
			if ($text !== '' && is_email($email)) {
				$thread = (array) self::m($id, 'thread', []);
				$thread[] = ['at' => time(), 'text' => $text];
				update_post_meta($id, self::PRE . 'thread', $thread);
				$lang = (string) self::m($id, 'lang', 'en');
				$send = function () use ($id, $email, $text) {
					$ref = (string) self::m($id, 'ref');
					return Y4APK_Members::mail($email, sprintf(__('Re: your message (%s)', 'y4apk'), $ref), [
						'title' => __('An answer to your message', 'y4apk'), 'preheader' => wp_trim_words($text, 14), 'hi' => (string) self::m($id, 'name'),
						'lines' => preg_split('/\n{2,}/', $text), 'quote' => wp_trim_words((string) get_post_field('post_content', $id), 60),
						'note' => sprintf(__('Your message’s number is %s. To answer, send a new message from our Contact page with this number.', 'y4apk'), $ref),
						'why' => __('You got this email because you wrote to us through our site.', 'y4apk')]);
				};
				$ok = class_exists('Y4APK_I18n') && $lang !== 'en' ? Y4APK_I18n::in($lang, $send) : $send();
				update_post_meta($id, self::PRE . 'status', empty($in['close']) ? 'open' : 'done');
				$note = $ok ? 'sent' : 'mailfail';
			}
		} elseif ($do === 'status' && isset(self::statuses()[$in['status'] ?? ''])) {
			update_post_meta($id, self::PRE . 'status', $in['status']);
			$note = 'saved';
		} elseif ($do === 'hide' || $do === 'restore') {
			$posts = array_map('intval', (array) ($in['posts'] ?? []));
			$n = 0;
			foreach ($posts as $pid) {
				if (!$pid || !in_array(get_post_type($pid), ['post', 'articles', 'news', 'y4apk_executor'], true)) {
					continue;
				}
				$h = get_post_meta($pid, self::HIDDEN, true);
				if ($do === 'hide' && get_post_status($pid) === 'publish') {
					update_post_meta($pid, self::HIDDEN, ['ticket' => $id, 'was' => 'publish', 'at' => time()]);
					wp_update_post(['ID' => $pid, 'post_status' => 'draft']);
					$n++;
				} elseif ($do === 'restore' && is_array($h)) {
					wp_update_post(['ID' => $pid, 'post_status' => $h['was'] ?: 'publish']);
					delete_post_meta($pid, self::HIDDEN);
					$n++;
				}
			}
			$note = $do === 'hide' ? 'hidden' : 'restored';
			y4apk_purge_pages();
			$back = add_query_arg('n', $n, $back);
		} elseif ($do === 'delete') {
			wp_delete_post($id, true);
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'deleted' => 1], admin_url('admin.php')));
			exit;
		}
		wp_safe_redirect(add_query_arg('note', $note, $back));
		exit;
	}

	public static function render() {
		if (!current_user_can('manage_options')) {
			return;
		}
		if (get_option('y4apk_help_v') !== self::VERSION) {
			self::setup();
		}
		echo '<div class="wrap y4s y4ui">';
		Y4APK_Admin_UI::css();
		self::admin_styles();
		$id = isset($_GET['t']) ? (int) $_GET['t'] : 0;
		$view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'open';
		if ($id && get_post_type($id) === self::CPT) {
			self::render_one($id);
		} elseif ($view === 'pages') {
			self::render_pages();
		} else {
			self::render_list($view);
		}
		echo '</div>';
	}

	private static function admin_styles() {
		?>
		<style>
		.y4s-list td{vertical-align:top}
		.y4s-list .y4s-msg{margin-top:4px;color:#50575e;max-width:760px;line-height:1.5}
		.y4s-list .y4s-topic{font-size:14px;font-weight:700;text-decoration:none}
		.y4s-list .y4s-who{display:flex;align-items:center;gap:10px;min-width:0}
		.y4s-list .y4s-av{flex:none;display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#e8f1fb;color:#135e96;font-weight:700}
		.y4s-list .y4s-who small{display:block;color:#8c8f94}
		.y4s-list tr.is-new td:first-child{box-shadow:inset 3px 0 0 #d63638}
		.y4s-list td:last-child{width:1%;white-space:nowrap;text-align:right}
		.y4s-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e;white-space:nowrap}
		.y4s-chip.new{background:#fcf0f1;color:#a00}.y4s-chip.open{background:#f0f6fc;color:#135e96}.y4s-chip.done{background:#edfaef;color:#00691b}
		.y4s-chip.copy{background:#fcf9e8;color:#7a5d00}
		.y4s-card{padding:18px 20px;margin:0 0 16px;border:1px solid #dcdcde;border-radius:14px;background:#fff}
		.y4s-card h2{margin:0 0 12px;font-size:16px;font-weight:700}
		.y4s-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:16px;align-items:start}
		@media (max-width:960px){.y4s-grid{grid-template-columns:1fr}}
		.y4s-body{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.6}
		.y4s-dl{display:grid;grid-template-columns:110px 1fr;gap:6px 10px;margin:0}
		.y4s-dl dt{color:#646970}.y4s-dl dd{margin:0;word-break:break-word}
		.y4s-thread li{padding:10px 12px;border-radius:8px;background:#f0f6fc;margin:0 0 8px;white-space:pre-wrap}
		.y4s-thread small{display:block;color:#646970;margin-bottom:4px}
		.y4s-links td{vertical-align:middle}
		.y4s-stars{color:#dba617;letter-spacing:1px}
		.y4s-bars{display:grid;gap:4px;max-width:420px}
		.y4s-bars div{display:grid;grid-template-columns:60px 1fr 40px;gap:8px;align-items:center}
		.y4s-bars i{display:block;height:8px;border-radius:8px;background:#2271b1}
		</style>
		<?php
	}

	private static function render_list($view) {
		$q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
		$tabs = ['open' => __('To answer', 'y4apk'), 'copy' => __('Copyright', 'y4apk'), 'feedback' => __('Feedback', 'y4apk'), 'done' => __('Closed', 'y4apk'), 'all' => __('All', 'y4apk'), 'pages' => __('Pages', 'y4apk')];
		$meta = [];
		if ($view === 'open') {
			$meta = [['key' => self::PRE . 'status', 'value' => ['new', 'open'], 'compare' => 'IN'], ['key' => self::PRE . 'kind', 'value' => 'feedback', 'compare' => '!=']];
		} elseif ($view === 'copy') {
			$meta = [['key' => self::PRE . 'topic', 'value' => 'copy']];
		} elseif ($view === 'feedback') {
			$meta = [['key' => self::PRE . 'kind', 'value' => 'feedback']];
		} elseif ($view === 'done') {
			$meta = [['key' => self::PRE . 'status', 'value' => 'done']];
		}
		$paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
		$query = new WP_Query(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 30, 'paged' => $paged, 's' => $q, 'meta_query' => $meta ? array_merge(['relation' => 'AND'], $meta) : []]);
		// How many in each tab (2.58)
		$count = function ($m) {
			$c = new WP_Query(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $m ? array_merge(['relation' => 'AND'], $m) : []]);
			return (int) $c->found_posts;
		};
		$n = [
			'open'     => $count([['key' => self::PRE . 'status', 'value' => ['new', 'open'], 'compare' => 'IN'], ['key' => self::PRE . 'kind', 'value' => 'feedback', 'compare' => '!=']]),
			'copy'     => $count([['key' => self::PRE . 'topic', 'value' => 'copy'], ['key' => self::PRE . 'status', 'value' => ['new', 'open'], 'compare' => 'IN']]),
			'feedback' => $count([['key' => self::PRE . 'kind', 'value' => 'feedback']]),
			'done'     => $count([['key' => self::PRE . 'status', 'value' => 'done']]),
		];
		$new = self::count_new();
		$tab = function ($k) { return add_query_arg(['page' => self::SLUG, 'view' => $k], admin_url('admin.php')); };
		Y4APK_Admin_UI::head([
			'icon'    => 'support',
			'tint'    => 'violet',
			'title'   => esc_html__('Support', 'y4apk'),
			'sub'     => sprintf(__('Messages from the <a href="%1$s" target="_blank">Contact</a>, <a href="%2$s" target="_blank">Copyright</a> and <a href="%3$s" target="_blank">Feedback</a> pages. An answer you write here is emailed to the sender (in their language’s words around yours); your own address is never shown.', 'y4apk'), esc_url(self::url('contact')), esc_url(self::url('copyright')), esc_url(self::url('feedback'))),
			'actions' => '<a class="button button-large" href="' . esc_url($tab('pages')) . '">' . Y4APK_Admin_UI::icon('page', 16) . ' ' . esc_html__('Help & legal pages', 'y4apk') . '</a>',
		]);
		?>
		<?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Deleted.', 'y4apk'); ?></p></div><?php endif; ?>
		<div class="y4ui-kpis">
			<a class="y4ui-kpi<?php echo $view === 'open' ? ' is-on' : ''; ?><?php echo $new ? ' is-red' : ''; ?>" href="<?php echo esc_url($tab('open')); ?>">
				<span class="y4ui-kpi-l"><?php echo Y4APK_Admin_UI::icon('mail', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e('To answer', 'y4apk'); ?></span>
				<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($n['open'])); ?></span>
				<span class="y4ui-kpi-s"><?php echo esc_html($new ? sprintf(_n('%s not opened yet', '%s not opened yet', $new, 'y4apk'), number_format_i18n($new)) : __('Nothing new', 'y4apk')); ?></span>
			</a>
			<a class="y4ui-kpi<?php echo $view === 'copy' ? ' is-on' : ''; ?>" href="<?php echo esc_url($tab('copy')); ?>">
				<span class="y4ui-kpi-l"><?php echo Y4APK_Admin_UI::icon('shield', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e('Copyright notices', 'y4apk'); ?></span>
				<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($n['copy'])); ?></span>
				<span class="y4ui-kpi-s"><?php esc_html_e('open: look at them first', 'y4apk'); ?></span>
			</a>
			<a class="y4ui-kpi<?php echo $view === 'feedback' ? ' is-on' : ''; ?>" href="<?php echo esc_url($tab('feedback')); ?>">
				<span class="y4ui-kpi-l"><?php echo Y4APK_Admin_UI::icon('star', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e('Feedback', 'y4apk'); ?></span>
				<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($n['feedback'])); ?></span>
				<span class="y4ui-kpi-s"><?php esc_html_e('ratings and words from visitors', 'y4apk'); ?></span>
			</a>
			<a class="y4ui-kpi is-green<?php echo $view === 'done' ? ' is-on' : ''; ?>" href="<?php echo esc_url($tab('done')); ?>">
				<span class="y4ui-kpi-l"><?php echo Y4APK_Admin_UI::icon('check', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e('Closed', 'y4apk'); ?></span>
				<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($n['done'])); ?></span>
				<span class="y4ui-kpi-s"><?php esc_html_e('answered or done', 'y4apk'); ?></span>
			</a>
		</div>
		<div class="y4ui-bar-row">
			<nav class="y4ui-tabs" aria-label="<?php esc_attr_e('Messages', 'y4apk'); ?>">
				<?php foreach ($tabs as $k => $label) : if ($k === 'pages') { continue; } ?>
					<a class="<?php echo $k === $view ? 'is-on' : ''; ?>" href="<?php echo esc_url($tab($k)); ?>"><?php echo esc_html($label); ?><?php if ($k === 'open' && $new) : ?> <span class="y4ui-n is-red"><?php echo esc_html(number_format_i18n($new)); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="get" class="y4ui-find"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
				<input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Find a message, a name or a reference', 'y4apk'); ?>"> <button class="button"><?php esc_html_e('Find', 'y4apk'); ?></button></form>
		</div>
		<?php if ($view === 'feedback') { self::feedback_summary(); } ?>
		<div class="y4ui-card">
		<table class="y4ui-table y4s-list">
			<thead><tr><th style="width:130px"><?php esc_html_e('Status', 'y4apk'); ?></th><th><?php esc_html_e('Message', 'y4apk'); ?></th><th style="width:220px"><?php esc_html_e('From', 'y4apk'); ?></th><th style="width:130px"><?php esc_html_e('Sent', 'y4apk'); ?></th><th></th></tr></thead>
			<tbody>
			<?php if (!$query->posts) : ?><tr><td colspan="5" class="y4ui-empty"><?php echo esc_html($view === 'open' ? __('Nothing to answer. All done!', 'y4apk') : __('Nothing here.', 'y4apk')); ?></td></tr><?php endif; ?>
			<?php foreach ($query->posts as $p) :
				$st = self::m($p->ID, 'status', 'new');
				$kind = self::m($p->ID, 'kind');
				$topic = self::m($p->ID, 'topic', 'general');
				$label = $kind === 'feedback' ? __('Feedback', 'y4apk') : (self::topics()[$topic][0] ?? '');
				$url = add_query_arg(['page' => self::SLUG, 't' => $p->ID], admin_url('admin.php'));
				?>
				<tr class="<?php echo $st === 'new' ? 'is-new' : ''; ?>">
					<td><span class="y4s-chip <?php echo esc_attr($st); ?>"><?php echo esc_html(self::statuses()[$st] ?? $st); ?></span></td>
					<td><a class="y4s-topic" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a> <span class="y4s-chip<?php echo $topic === 'copy' ? ' copy' : ''; ?>"><?php echo esc_html(self::m($p->ID, 'ref')); ?></span>
						<?php if ($kind === 'feedback') : ?> <span class="y4s-stars"><?php echo esc_html(str_repeat('★', (int) self::m($p->ID, 'rating')) . str_repeat('☆', 5 - (int) self::m($p->ID, 'rating'))); ?></span><?php endif; ?>
						<div class="y4s-msg"><?php echo esc_html(wp_trim_words($p->post_content, 30) ?: '—'); ?></div></td>
					<td><?php $who = (string) self::m($p->ID, 'name', '—'); ?><span class="y4s-who"><span class="y4s-av" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr($who, 0, 1))); ?></span><span><?php echo esc_html($who); ?><?php echo self::m($p->ID, 'user') ? '<small>' . esc_html('@' . (string) get_the_author_meta('user_login', (int) self::m($p->ID, 'user'))) . '</small>' : ''; ?></span></span></td>
					<td><?php echo esc_html(sprintf(__('%s ago', 'y4apk'), human_time_diff(get_post_time('U', true, $p)))); ?></td>
					<td><a class="button" href="<?php echo esc_url($url); ?>"><?php echo esc_html(in_array($st, ['new', 'open'], true) && $kind !== 'feedback' ? __('Answer', 'y4apk') : __('Open', 'y4apk')); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ($query->max_num_pages > 1) : ?>
			<p><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages]); ?></p>
		<?php endif;
	}

	private static function feedback_summary() {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT r.meta_value AS rating, COUNT(*) AS n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date_gmt > %s GROUP BY r.meta_value", self::PRE . 'rating', self::CPT, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
		$by = array_fill(1, 5, 0);
		$sum = 0;
		$all = 0;
		foreach ((array) $rows as $r) {
			$k = (int) $r->rating;
			if ($k >= 1 && $k <= 5) {
				$by[$k] = (int) $r->n;
				$sum += $k * (int) $r->n;
				$all += (int) $r->n;
			}
		}
		?>
		<div class="y4s-card">
			<h2><?php echo esc_html($all ? sprintf(_n('Last 30 days: %1$s of 5 from %2$s person', 'Last 30 days: %1$s of 5 from %2$s people', $all, 'y4apk'), number_format_i18n($sum / $all, 1), number_format_i18n($all)) : __('No feedback in the last 30 days yet.', 'y4apk')); ?></h2>
			<?php if ($all) : ?><div class="y4s-bars"><?php for ($k = 5; $k >= 1; $k--) : ?>
				<div><span class="y4s-stars"><?php echo esc_html(str_repeat('★', $k)); ?></span><i style="width:<?php echo (int) round(100 * $by[$k] / max(1, max($by))); ?>%"></i><span><?php echo esc_html(number_format_i18n($by[$k])); ?></span></div>
			<?php endfor; ?></div><?php endif; ?>
		</div>
		<?php
	}

	private static function render_one($id) {
		$p = get_post($id);
		$st = self::m($id, 'status', 'new');
		$kind = self::m($id, 'kind');
		$topic = self::m($id, 'topic', 'general');
		$label = $kind === 'feedback' ? __('Feedback', 'y4apk') : (self::topics()[$topic][0] ?? '');
		$dmca = self::m($id, 'dmca', []);
		$thread = (array) self::m($id, 'thread', []);
		$notes = ['sent' => __('Answer sent.', 'y4apk'), 'mailfail' => __('The answer is saved, but the email could not be sent. Check Y4APK ▸ Members ▸ Emails.', 'y4apk'),
			'saved' => __('Saved.', 'y4apk'), 'hidden' => __('Hidden: the posts are drafts now.', 'y4apk') . ' ' . y4apk_cache_note(), 'restored' => __('Put back.', 'y4apk') . ' ' . y4apk_cache_note()];
		$note = isset($_GET['note']) ? sanitize_key(wp_unslash($_GET['note'])) : '';
		$form = function ($do, $inner, $confirm = '') use ($id) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>';
			echo '<input type="hidden" name="action" value="y4apk_help"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="t" value="' . (int) $id . '">';
			wp_nonce_field('y4apk-help');
			echo $inner; // built below from escaped parts
			echo '</form>';
		};
		?>
		<?php Y4APK_Admin_UI::head([
			'icon'  => $topic === 'copy' ? 'shield' : ($kind === 'feedback' ? 'star' : 'mail'),
			'tint'  => 'violet',
			'title' => esc_html($label) . ' <span class="y4ui-chip is-code">' . esc_html(self::m($id, 'ref')) . '</span>',
			'back'  => [add_query_arg('page', self::SLUG, admin_url('admin.php')), __('All messages', 'y4apk')],
			'sub'   => esc_html(sprintf(__('From %1$s · %2$s ago', 'y4apk'), self::m($id, 'name', '—'), human_time_diff(get_post_time('U', true, $p)))),
		]); ?>
		<?php if (isset($notes[$note])) : ?><div class="notice notice-<?php echo $note === 'mailfail' ? 'warning' : 'success'; ?> is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div><?php endif; ?>
		<div class="y4s-grid">
			<div>
				<div class="y4s-card">
					<?php if ($kind === 'feedback') : ?><p class="y4s-stars" style="font-size:20px;margin:0 0 8px"><?php echo esc_html(str_repeat('★', (int) self::m($id, 'rating')) . str_repeat('☆', 5 - (int) self::m($id, 'rating'))); ?></p><?php endif; ?>
					<div class="y4s-body"><?php echo esc_html($p->post_content !== '' ? $p->post_content : '—'); ?></div>
				</div>
				<?php if ($topic === 'copy' && is_array($dmca) && !empty($dmca['urls'])) : ?>
					<div class="y4s-card">
						<h2><?php esc_html_e('Copyright notice', 'y4apk'); ?></h2>
						<dl class="y4s-dl">
							<dt><?php esc_html_e('Sent by', 'y4apk'); ?></dt><dd><?php echo esc_html(self::m($id, 'name') . ($dmca['company'] !== '' ? ' · ' . $dmca['company'] : '') . ' · ' . ($dmca['role'] === 'agent' ? __('acting for the owner', 'y4apk') : __('the owner', 'y4apk'))); ?></dd>
							<dt><?php esc_html_e('Signature', 'y4apk'); ?></dt><dd><?php echo esc_html($dmca['signature']); ?> · <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $dmca['at'])); ?></dd>
							<dt><?php esc_html_e('Statements', 'y4apk'); ?></dt><dd><?php esc_html_e('Good faith, accuracy and authority (under penalty of perjury): all three ticked.', 'y4apk'); ?></dd>
						</dl>
						<?php
						$rows = '';
						foreach ($dmca['urls'] as $u) {
							$pid = self::post_of($u);
							$status = $pid ? get_post_status($pid) : '';
							$rows .= '<tr><td>' . ($pid ? '<input type="checkbox" name="posts[]" value="' . (int) $pid . '" checked>' : '') . '</td><td><a href="' . esc_url($u) . '" target="_blank" rel="noopener noreferrer">' . esc_html($u) . '</a></td><td>'
								. ($pid ? '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid)) . '</a> <span class="y4s-chip ' . ($status === 'publish' ? 'new' : 'done') . '">' . esc_html($status === 'publish' ? __('live', 'y4apk') : (get_post_meta($pid, self::HIDDEN, true) ? __('hidden for this notice', 'y4apk') : $status)) . '</span>' : esc_html__('not found: look for it yourself', 'y4apk')) . '</td></tr>';
						}
						$form('hide', '<table class="widefat y4s-links" style="margin:12px 0"><thead><tr><th style="width:30px"></th><th>' . esc_html__('Link in the notice', 'y4apk') . '</th><th>' . esc_html__('Post', 'y4apk') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
							. '<p><button class="button button-primary">' . esc_html__('Hide the ticked posts', 'y4apk') . '</button> <button class="button" name="do" value="restore">' . esc_html__('Put the ticked posts back', 'y4apk') . '</button></p>'
							. '<p class="description">' . esc_html__('Hiding makes a post a draft (its file stays on R2 until you delete it). Putting it back is for a counter-notice: wait 10–14 working days after you forward it.', 'y4apk') . '</p>',
							__('Hide the ticked posts?', 'y4apk'));
						?>
					</div>
				<?php endif; ?>
				<?php if ($thread) : ?>
					<div class="y4s-card"><h2><?php esc_html_e('Your answers', 'y4apk'); ?></h2><ul class="y4s-thread" style="margin:0">
						<?php foreach ($thread as $r) : ?><li><small><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $r['at'])); ?></small><?php echo esc_html($r['text']); ?></li><?php endforeach; ?>
					</ul></div>
				<?php endif; ?>
				<?php if (is_email((string) self::m($id, 'email'))) : ?>
					<div class="y4s-card"><h2><?php esc_html_e('Answer by email', 'y4apk'); ?></h2>
						<?php $form('reply', '<textarea name="reply" rows="7" class="large-text" required placeholder="' . esc_attr__('Write your answer…', 'y4apk') . '"></textarea>'
							. '<p><label><input type="checkbox" name="close" value="1"> ' . esc_html__('Close it after sending', 'y4apk') . '</label></p><p><button class="button button-primary">' . esc_html__('Send answer', 'y4apk') . '</button></p>'); ?>
					</div>
				<?php endif; ?>
			</div>
			<div>
				<div class="y4s-card">
					<dl class="y4s-dl">
						<dt><?php esc_html_e('Status', 'y4apk'); ?></dt><dd><span class="y4s-chip <?php echo esc_attr($st); ?>"><?php echo esc_html(self::statuses()[$st] ?? $st); ?></span></dd>
						<dt><?php esc_html_e('From', 'y4apk'); ?></dt><dd><?php echo esc_html(self::m($id, 'name', '—')); ?><?php echo self::m($id, 'user') ? ' · <a href="' . esc_url(home_url('/@' . get_the_author_meta('user_login', (int) self::m($id, 'user')))) . '" target="_blank">@' . esc_html((string) get_the_author_meta('user_login', (int) self::m($id, 'user'))) . '</a>' : ''; ?></dd>
						<dt><?php esc_html_e('Email', 'y4apk'); ?></dt><dd><?php echo esc_html(self::m($id, 'email', '—')); ?></dd>
						<?php if (self::m($id, 'link') !== '') : ?><dt><?php esc_html_e('Link', 'y4apk'); ?></dt><dd><a href="<?php echo esc_url(self::m($id, 'link')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(self::m($id, 'link')); ?></a></dd><?php endif; ?>
						<?php if (self::m($id, 'page') !== '') : ?><dt><?php esc_html_e('From the page', 'y4apk'); ?></dt><dd><a href="<?php echo esc_url(self::m($id, 'page')); ?>" target="_blank"><?php echo esc_html(self::m($id, 'page')); ?></a></dd><?php endif; ?>
						<dt><?php esc_html_e('Language', 'y4apk'); ?></dt><dd><?php echo esc_html(class_exists('Y4APK_I18n') ? (Y4APK_I18n::languages()[self::m($id, 'lang', 'en')][1] ?? self::m($id, 'lang', 'en')) : self::m($id, 'lang', 'en')); ?></dd>
						<dt><?php esc_html_e('Sent', 'y4apk'); ?></dt><dd><?php echo esc_html(get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $p)); ?></dd>
					</dl>
				</div>
				<div class="y4s-card">
					<?php
					$opts = '';
					foreach (self::statuses() as $k => $l) {
						$opts .= '<option value="' . esc_attr($k) . '"' . selected($st, $k, false) . '>' . esc_html($l) . '</option>';
					}
					$form('status', '<p><select name="status">' . $opts . '</select> <button class="button">' . esc_html__('Save', 'y4apk') . '</button></p>');
					$form('delete', '<p><button class="button-link button-link-delete">' . esc_html__('Delete this message', 'y4apk') . '</button></p>', __('Delete this message for good?', 'y4apk'));
					?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_pages() {
		$map = get_option(self::MAP, []);
		$own = get_option(self::OWN, []);
		?>
		<?php Y4APK_Admin_UI::head([
			'icon'  => 'page',
			'tint'  => 'violet',
			'title' => esc_html__('Help & legal pages', 'y4apk'),
			'back'  => [add_query_arg('page', self::SLUG, admin_url('admin.php')), __('Messages', 'y4apk')],
			'sub'   => esc_html__('Terms, Privacy, DMCA, Copyright, Contact, Feedback and the FAQ: where they are, and whether they show the theme’s words.', 'y4apk'),
		]); ?>
		<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(__('Saved.', 'y4apk') . ' ' . y4apk_cache_note()); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="y4apk_help"><input type="hidden" name="do" value="pages"><?php wp_nonce_field('y4apk-help'); ?>
			<div class="y4ui-card">
			<table class="y4ui-table">
				<thead><tr><th><?php esc_html_e('Page', 'y4apk'); ?></th><th><?php esc_html_e('Address', 'y4apk'); ?></th><th><?php esc_html_e('Shows the theme’s words', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<?php foreach (self::pages() as $k => $p) : $pid = (int) ($map[$k] ?? 0); ?>
					<tr><td><b><?php echo esc_html($p['title']); ?></b></td>
						<td><?php if ($pid && get_post_status($pid) === 'publish') : ?><a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank"><?php echo esc_html(wp_parse_url(get_permalink($pid), PHP_URL_PATH)); ?></a> · <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php esc_html_e('Edit page', 'y4apk'); ?></a><?php else : ?><?php esc_html_e('not made yet', 'y4apk'); ?><?php endif; ?></td>
						<td><label><input type="checkbox" name="theme[<?php echo esc_attr($k); ?>]" value="1" <?php checked(empty($own[$k])); ?>> <?php esc_html_e('yes (untick to show the page’s own text instead)', 'y4apk'); ?></label></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="y4ui-savebar">
				<button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button>
				<label><input type="checkbox" name="remake" value="1"> <?php esc_html_e('Make any page that is missing (or was deleted) again', 'y4apk'); ?></label>
			</div>
			<p class="description" style="max-width:860px"><?php esc_html_e('A page your site already had at one of the usual addresses (/privacy-policy/, /dmca-policy/, /feedback/ …) was used as it is: its address and SEO settings stay; its own text is kept in the editor and comes back if you untick it here.', 'y4apk'); ?></p>
		</form>
		<?php
	}
}

Y4APK_Help::init();
