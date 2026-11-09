<?php
/**
 * Messages between members (2.67): /account/messages/.
 *
 * One conversation per pair of members (table y4apk_dm_threads, the lower
 * user ID first) and its messages (y4apk_dm_msgs). Message text is kept
 * encrypted (Y4APK_Mail::hide, the site's own key), so it cannot be read from
 * a database copy; the owner reads only what a member reports.
 *
 * The page talks to /wp-json/y4apk/v1/dm/… (signed-in members, the REST
 * nonce): the list, a conversation (new messages are asked for every few
 * seconds while it is open), send, unsend, block, report, leave. The header's
 * unread count comes from admin-ajax (y4apk_dm_count), as the header is the
 * same for every visitor.
 *
 * Rules: members with a confirmed email, not banned; each member chooses who
 * may write to them (everyone, people they follow, or only people they wrote
 * to); a block stops both ways; limits per minute and per day, and on new
 * conversations (fewer for accounts less than a day old).
 */
if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_DM {

	const DBV    = '1';
	const OPTION = 'y4apk_dm';
	const SLUG   = 'y4apk-messages';
	const WHO    = 'y4apk_dm_who';    // usermeta: 'all' (default), 'follow' (people I follow), 'none' (only people I wrote to)
	const MAIL   = 'y4apk_dm_mail';   // usermeta: '0' = no emails about new messages
	const MAILED = 'y4apk_dm_mailed'; // usermeta: when the last one was sent
	const MAX    = 2000;              // characters in a message

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action('rest_api_init', [__CLASS__, 'rest']);
		add_action('wp_ajax_y4apk_dm_count', [__CLASS__, 'ajax_count']);
		add_action('delete_user', [__CLASS__, 'forget']);
		if (is_admin()) {
			add_action('admin_menu', [__CLASS__, 'menu'], 31);
			add_action('admin_post_y4apk_dm', [__CLASS__, 'handle_admin']);
		}
	}

	public static function t($name) {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_dm_' . $name;
	}

	public static function settings() {
		$o = get_option(self::OPTION, []);
		return wp_parse_args(is_array($o) ? $o : [], ['on' => 1, 'per_min' => 20, 'per_day' => 400, 'new_day' => 15, 'young_new' => 3, 'mail' => 1]);
	}

	public static function on() {
		return (bool) self::settings()['on'];
	}

	public static function url($args = []) {
		return Y4APK_Members::url('messages', $args);
	}

	public static function setup() {
		if (get_option('y4apk_dm_db') === self::DBV) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		dbDelta('CREATE TABLE ' . self::t('threads') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			a bigint(20) unsigned NOT NULL,
			b bigint(20) unsigned NOT NULL,
			last_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_from bigint(20) unsigned NOT NULL DEFAULT 0,
			last_at int(10) unsigned NOT NULL DEFAULT 0,
			a_read bigint(20) unsigned NOT NULL DEFAULT 0,
			b_read bigint(20) unsigned NOT NULL DEFAULT 0,
			a_left bigint(20) unsigned NOT NULL DEFAULT 0,
			b_left bigint(20) unsigned NOT NULL DEFAULT 0,
			a_block tinyint(1) unsigned NOT NULL DEFAULT 0,
			b_block tinyint(1) unsigned NOT NULL DEFAULT 0,
			made int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY pair (a,b),
			KEY a_last (a,last_at),
			KEY b_last (b,last_at)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('msgs') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			from_id bigint(20) unsigned NOT NULL,
			body mediumtext NOT NULL,
			made int(10) unsigned NOT NULL DEFAULT 0,
			gone tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY thread (thread_id,id),
			KEY from_made (from_id,made)
		) $cs;");
		dbDelta('CREATE TABLE ' . self::t('reports') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			msg_id bigint(20) unsigned NOT NULL,
			thread_id bigint(20) unsigned NOT NULL,
			by_id bigint(20) unsigned NOT NULL,
			about_id bigint(20) unsigned NOT NULL,
			why varchar(40) NOT NULL DEFAULT '',
			copy mediumtext NOT NULL,
			made int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(8) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			UNIQUE KEY one (msg_id,by_id),
			KEY status (status,id)
		) $cs;");
		update_option('y4apk_dm_db', self::DBV, false);
	}

	/* ---------------------------------------------------------------- *
	 * Conversations
	 * ---------------------------------------------------------------- */

	/** The conversation of two members (made when $make), or null. */
	public static function thread($u1, $u2, $make = false) {
		global $wpdb;
		$a = min((int) $u1, (int) $u2);
		$b = max((int) $u1, (int) $u2);
		if (!$a || $a === $b) {
			return null;
		}
		$t = self::t('threads');
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE a = %d AND b = %d", $a, $b));
		if (!$row && $make) {
			$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (a, b, made) VALUES (%d, %d, %d)", $a, $b, time()));
			$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE a = %d AND b = %d", $a, $b));
		}
		return $row ?: null;
	}

	public static function thread_by_id($id, $uid) {
		global $wpdb;
		$t = self::t('threads');
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d AND (a = %d OR b = %d)", (int) $id, (int) $uid, (int) $uid)) ?: null;
	}

	/** 'a' or 'b': which side of the conversation $uid is. */
	private static function side($row, $uid) {
		return (int) $row->a === (int) $uid ? 'a' : 'b';
	}

	private static function other($row, $uid) {
		return (int) $row->a === (int) $uid ? (int) $row->b : (int) $row->a;
	}

	/**
	 * May $from write to $to now? [true, ''] or [false, why]: 'off', 'me' (my
	 * account), 'confirm', 'gone', 'blocked', 'you_blocked', 'closed' (their choice).
	 */
	public static function can_send($from, $to, $row = null) {
		if (!self::on()) {
			return [false, 'off'];
		}
		if (!$from instanceof WP_User || !Y4APK_Members::is_member($from) || get_user_meta($from->ID, Y4APK_Members::BANNED, true)) {
			return [false, 'me'];
		}
		if (Y4APK_Members::unconfirmed($from)) {
			return [false, 'confirm'];
		}
		if (!$to instanceof WP_User || !Y4APK_Members::is_member($to) || get_user_meta($to->ID, Y4APK_Members::BANNED, true) || (int) $to->ID === (int) $from->ID) {
			return [false, 'gone'];
		}
		if ($row) {
			$me = self::side($row, $from->ID);
			$them = $me === 'a' ? 'b' : 'a';
			if ((int) $row->{$me . '_block'}) {
				return [false, 'you_blocked'];
			}
			if ((int) $row->{$them . '_block'}) {
				return [false, 'blocked'];
			}
		}
		$who = (string) get_user_meta($to->ID, self::WHO, true) ?: 'all';
		if ($who === 'all') {
			return [true, ''];
		}
		if ($row && self::has_written($row->id, $to->ID)) {
			return [true, '']; // a reply in a conversation they have written in
		}
		if ($who === 'follow' && class_exists('Y4APK_Social') && Y4APK_Social::is_following($to->ID, $from->ID)) {
			return [true, ''];
		}
		return [false, $who === 'follow' ? 'closed' : 'private'];
	}

	private static function has_written($thread_id, $uid) {
		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('msgs') . ' WHERE thread_id = %d AND from_id = %d LIMIT 1', (int) $thread_id, (int) $uid));
	}

	public static function why_text($why, $to = null) {
		$name = $to instanceof WP_User ? '@' . $to->user_login : __('this member', 'y4apk');
		$t = [
			'off'         => __('Messages are switched off at the moment.', 'y4apk'),
			'me'          => __('Your account cannot send messages.', 'y4apk'),
			'confirm'     => __('Confirm your email to send messages: open the link we sent you.', 'y4apk'),
			'gone'        => __('This member cannot get messages.', 'y4apk'),
			'blocked'     => sprintf(__('You cannot send messages to %s.', 'y4apk'), $name),
			'you_blocked' => sprintf(__('You blocked %s. Unblock them to write again.', 'y4apk'), $name),
			'closed'      => sprintf(__('%s only gets messages from people they follow.', 'y4apk'), $name),
			'private'     => sprintf(__('%s does not take new messages.', 'y4apk'), $name),
			'slow'        => __('That was a lot of messages at once. Wait a minute.', 'y4apk'),
			'day'         => __('You have sent a lot of messages today. Try again tomorrow.', 'y4apk'),
			'new'         => __('You have started a lot of new conversations today. Try again tomorrow.', 'y4apk'),
			'empty'       => __('Write something first.', 'y4apk'),
		];
		return $t[$why] ?? $t['gone'];
	}

	/** Plain text, as kept: no tags, at most MAX characters, no long runs of blank lines. */
	public static function clean($text) {
		$s = str_replace(["\r\n", "\r"], "\n", (string) $text);
		$s = wp_check_invalid_utf8($s, true);
		$s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
		$s = preg_replace("/\n{3,}/", "\n\n", trim($s));
		return mb_substr($s, 0, self::MAX);
	}

	/** A message's text as HTML: escaped, links clickable (never followed by search engines), line breaks kept. */
	public static function html($text) {
		$h = esc_html($text);
		$h = preg_replace_callback('~\bhttps?://[^\s<>"\']+~i', function ($m) {
			$url = rtrim($m[0], '.,;:!?)');
			$tail = substr($m[0], strlen($url));
			return '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow ugc noopener noreferrer">' . $url . '</a>' . $tail;
		}, $h);
		return nl2br($h, false);
	}

	/** Sends a message: ['ok' => true, 'msg' => …, 'thread' => id] or ['ok' => false, 'why' => …]. */
	public static function send($from, $to, $text) {
		global $wpdb;
		$text = self::clean($text);
		if ($text === '') {
			return ['ok' => false, 'why' => 'empty'];
		}
		$row = self::thread($from->ID, $to->ID);
		list($ok, $why) = self::can_send($from, $to, $row);
		if (!$ok) {
			return ['ok' => false, 'why' => $why];
		}
		$s = self::settings();
		$fresh = !$row || !self::has_written($row->id, $from->ID);
		if ($fresh) {
			$young = time() - strtotime($from->user_registered . ' UTC') < DAY_IN_SECONDS;
			if (!Y4APK_Members::hit('dm_new_' . $from->ID, $young ? (int) $s['young_new'] : (int) $s['new_day'], DAY_IN_SECONDS)) {
				return ['ok' => false, 'why' => 'new'];
			}
		}
		if (!Y4APK_Members::hit('dm_min_' . $from->ID, (int) $s['per_min'], MINUTE_IN_SECONDS)) {
			return ['ok' => false, 'why' => 'slow'];
		}
		if (!Y4APK_Members::hit('dm_day_' . $from->ID, (int) $s['per_day'], DAY_IN_SECONDS)) {
			return ['ok' => false, 'why' => 'day'];
		}
		$row = $row ?: self::thread($from->ID, $to->ID, true);
		$now = time();
		$wpdb->insert(self::t('msgs'), ['thread_id' => (int) $row->id, 'from_id' => (int) $from->ID, 'body' => Y4APK_Mail::hide($text), 'made' => $now]);
		$id = (int) $wpdb->insert_id;
		if (!$id) {
			return ['ok' => false, 'why' => 'gone'];
		}
		$me = self::side($row, $from->ID);
		$wpdb->update(self::t('threads'), ['last_id' => $id, 'last_from' => (int) $from->ID, 'last_at' => $now, $me . '_read' => $id], ['id' => (int) $row->id]);
		self::notify($from, $to);
		return ['ok' => true, 'thread' => (int) $row->id, 'msg' => self::out((object) ['id' => $id, 'from_id' => $from->ID, 'body' => '', 'made' => $now, 'gone' => 0], $from->ID, $text)];
	}

	/** A message for the page. */
	private static function out($m, $uid, $plain = null) {
		$gone = (int) $m->gone;
		$text = $gone ? '' : ($plain !== null ? $plain : Y4APK_Mail::reveal((string) $m->body));
		return ['id' => (int) $m->id, 'mine' => (int) $m->from_id === (int) $uid, 'at' => (int) $m->made, 'gone' => $gone, 'html' => $gone ? '' : self::html($text)];
	}

	/** A member, as the page shows them. */
	public static function who($u) {
		if (!$u instanceof WP_User) {
			return ['id' => 0, 'login' => '', 'name' => __('Former member', 'y4apk'), 'pic' => '', 'hue' => 0, 'url' => '', 'online' => false, 'seen' => ''];
		}
		$social = class_exists('Y4APK_Social');
		return ['id' => (int) $u->ID, 'login' => $u->user_login, 'name' => $social ? Y4APK_Social::name($u) : $u->user_login, 'pic' => $social ? Y4APK_Social::picture($u) : '',
			'hue' => abs(crc32($u->user_login)) % 360, 'url' => Y4APK_Members::profile_url($u),
			'online' => $social && Y4APK_Social::is_online($u), 'seen' => $social ? Y4APK_Social::seen_text($u) : ''];
	}

	/** The member's conversations, newest first. */
	public static function inbox($uid, $limit = 60) {
		global $wpdb;
		$t = self::t('threads');
		$m = self::t('msgs');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT t.*, m.body, m.gone, m.from_id FROM $t t LEFT JOIN $m m ON m.id = t.last_id
			WHERE ((t.a = %d AND t.last_id > t.a_left) OR (t.b = %d AND t.last_id > t.b_left)) AND t.last_id > 0
			ORDER BY t.last_at DESC LIMIT %d", $uid, $uid, (int) $limit));
		$out = [];
		foreach ((array) $rows as $r) {
			$me = self::side($r, $uid);
			$text = (int) $r->gone ? __('Message unsent', 'y4apk') : wp_strip_all_tags(Y4APK_Mail::reveal((string) $r->body));
			$out[] = ['t' => (int) $r->id, 'with' => self::who(get_userdata(self::other($r, $uid))), 'at' => (int) $r->last_at, 'mine' => (int) $r->last_from === (int) $uid,
				'text' => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 90), 'unread' => (int) $r->last_from !== (int) $uid && (int) $r->last_id > (int) $r->{$me . '_read'}];
		}
		return $out;
	}

	/** Conversations with something new for the member. */
	public static function unread($uid) {
		global $wpdb;
		$t = self::t('threads');
		if (get_option('y4apk_dm_db') !== self::DBV) {
			return 0;
		}
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $t WHERE (a = %d AND last_from <> %d AND last_id > a_read AND last_id > a_left AND a_block = 0)
			OR (b = %d AND last_from <> %d AND last_id > b_read AND last_id > b_left AND b_block = 0)", $uid, $uid, $uid, $uid));
	}

	/** A conversation for the page: who, what may be done, its messages (the newest, or after / before an ID). */
	public static function view($row, $user, $after = 0, $before = 0) {
		global $wpdb;
		$uid = (int) $user->ID;
		$me = self::side($row, $uid);
		$them = $me === 'a' ? 'b' : 'a';
		$other = get_userdata(self::other($row, $uid));
		$m = self::t('msgs');
		if ($after) {
			$msgs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $m WHERE thread_id = %d AND id > %d ORDER BY id ASC LIMIT 100", (int) $row->id, (int) $after));
		} else {
			$msgs = array_reverse((array) $wpdb->get_results($before
				? $wpdb->prepare("SELECT * FROM $m WHERE thread_id = %d AND id < %d ORDER BY id DESC LIMIT 40", (int) $row->id, (int) $before)
				: $wpdb->prepare("SELECT * FROM $m WHERE thread_id = %d ORDER BY id DESC LIMIT 40", (int) $row->id)));
		}
		$out = [];
		foreach ((array) $msgs as $x) {
			$out[] = self::out($x, $uid);
		}
		if ((int) $row->last_id > (int) $row->{$me . '_read'} && !$before) { // read now
			$wpdb->update(self::t('threads'), [$me . '_read' => (int) $row->last_id], ['id' => (int) $row->id]);
		}
		list($ok, $why) = self::can_send($user, $other, $row);
		$first = $out ? $out[0]['id'] : 0;
		$older = $first && !$after ? (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $m WHERE thread_id = %d AND id < %d LIMIT 1", (int) $row->id, $first)) : false;
		// Unsent since: the page hides those it still shows
		$gone = $after ? array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $m WHERE thread_id = %d AND gone = 1 AND id <= %d ORDER BY id DESC LIMIT 50", (int) $row->id, (int) $after))) : [];
		return ['t' => (int) $row->id, 'with' => self::who($other), 'msgs' => $out, 'older' => $older, 'seen' => (int) $row->{$them . '_read'}, 'gone' => $gone,
			'can' => $ok, 'why' => $ok ? '' : self::why_text($why, $other), 'blocked' => (bool) (int) $row->{$me . '_block'}];
	}

	/* ---------------------------------------------------------------- *
	 * The page's calls: /wp-json/y4apk/v1/dm/…
	 * ---------------------------------------------------------------- */

	public static function rest() {
		$member = function () {
			$u = wp_get_current_user();
			return $u->exists() && Y4APK_Members::is_member($u);
		};
		$routes = [
			'inbox'  => ['GET', 'r_inbox'],
			'thread' => ['GET', 'r_thread'],
			'send'   => ['POST', 'r_send'],
			'unsend' => ['POST', 'r_unsend'],
			'block'  => ['POST', 'r_block'],
			'report' => ['POST', 'r_report'],
			'leave'  => ['POST', 'r_leave'],
		];
		foreach ($routes as $path => $r) {
			register_rest_route('y4apk/v1', '/dm/' . $path, ['methods' => $r[0], 'callback' => [__CLASS__, $r[1]], 'permission_callback' => $member]);
		}
	}

	private static function no_cache($data, $status = 200) {
		$r = new WP_REST_Response($data, $status);
		$r->header('Cache-Control', 'no-store, private');
		$r->header('X-LiteSpeed-Cache-Control', 'no-cache');
		return $r;
	}

	public static function r_inbox() {
		$u = wp_get_current_user();
		return self::no_cache(['list' => self::inbox($u->ID), 'unread' => self::unread($u->ID)]);
	}

	/** ?t=ID, or ?to=login (a conversation not started yet: who, and whether one may write) */
	public static function r_thread(WP_REST_Request $q) {
		$u = wp_get_current_user();
		$row = $q['t'] ? self::thread_by_id((int) $q['t'], $u->ID) : null;
		if (!$row && $q['to']) {
			$to = get_user_by('login', sanitize_user((string) $q['to']));
			if (!$to || !Y4APK_Members::is_member($to) || (int) $to->ID === (int) $u->ID) {
				return self::no_cache(['error' => self::why_text('gone')], 404);
			}
			$row = self::thread($u->ID, $to->ID);
			if (!$row) {
				list($ok, $why) = self::can_send($u, $to, null);
				return self::no_cache(['t' => 0, 'with' => self::who($to), 'msgs' => [], 'older' => false, 'seen' => 0, 'gone' => [], 'can' => $ok, 'why' => $ok ? '' : self::why_text($why, $to), 'blocked' => false]);
			}
		}
		if (!$row) {
			return self::no_cache(['error' => self::why_text('gone')], 404);
		}
		return self::no_cache(self::view($row, $u, absint($q['after']), absint($q['before'])));
	}

	public static function r_send(WP_REST_Request $q) {
		$u = wp_get_current_user();
		$to = null;
		if ($q['t'] && ($row = self::thread_by_id((int) $q['t'], $u->ID))) {
			$to = get_userdata(self::other($row, $u->ID));
		} elseif ($q['to']) {
			$to = get_user_by('login', sanitize_user((string) $q['to']));
		}
		if (!$to) {
			return self::no_cache(['ok' => false, 'error' => self::why_text('gone')], 404);
		}
		$r = self::send($u, $to, (string) $q['text']);
		if (!$r['ok']) {
			return self::no_cache(['ok' => false, 'error' => self::why_text($r['why'], $to)], 403);
		}
		return self::no_cache($r);
	}

	public static function r_unsend(WP_REST_Request $q) {
		global $wpdb;
		$u = wp_get_current_user();
		$m = self::t('msgs');
		$n = $wpdb->query($wpdb->prepare("UPDATE $m SET gone = 1, body = '' WHERE id = %d AND from_id = %d", absint($q['id']), $u->ID));
		return self::no_cache(['ok' => (bool) $n]);
	}

	public static function r_block(WP_REST_Request $q) {
		global $wpdb;
		$u = wp_get_current_user();
		$row = $q['t'] ? self::thread_by_id((int) $q['t'], $u->ID) : null;
		if (!$row && $q['to'] && ($to = get_user_by('login', sanitize_user((string) $q['to'])))) {
			$row = self::thread($u->ID, $to->ID, true);
		}
		if (!$row) {
			return self::no_cache(['ok' => false], 404);
		}
		$wpdb->update(self::t('threads'), [self::side($row, $u->ID) . '_block' => $q['on'] ? 1 : 0], ['id' => (int) $row->id]);
		return self::no_cache(['ok' => true, 't' => (int) $row->id]);
	}

	public static function r_report(WP_REST_Request $q) {
		global $wpdb;
		$u = wp_get_current_user();
		$msg = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('msgs') . ' WHERE id = %d', absint($q['id'])));
		$row = $msg ? self::thread_by_id((int) $msg->thread_id, $u->ID) : null;
		if (!$row || (int) $msg->from_id === (int) $u->ID || (int) $msg->gone || !Y4APK_Members::hit('dm_report_' . $u->ID, 20, DAY_IN_SECONDS)) {
			return self::no_cache(['ok' => false], 400);
		}
		$why = in_array((string) $q['why'], ['spam', 'scam', 'abuse', 'other'], true) ? (string) $q['why'] : 'other';
		$wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('reports') . ' (msg_id, thread_id, by_id, about_id, why, copy, made) VALUES (%d, %d, %d, %d, %s, %s, %d)',
			(int) $msg->id, (int) $msg->thread_id, $u->ID, (int) $msg->from_id, $why, (string) $msg->body, time())); // its text as it was, still encrypted
		if ($q['block']) {
			$wpdb->update(self::t('threads'), [self::side($row, $u->ID) . '_block' => 1], ['id' => (int) $row->id]);
		}
		return self::no_cache(['ok' => true]);
	}

	/** Out of my list until a new message (the other side keeps theirs). */
	public static function r_leave(WP_REST_Request $q) {
		global $wpdb;
		$u = wp_get_current_user();
		$row = self::thread_by_id((int) $q['t'], $u->ID);
		if (!$row) {
			return self::no_cache(['ok' => false], 404);
		}
		$me = self::side($row, $u->ID);
		$wpdb->update(self::t('threads'), [$me . '_left' => (int) $row->last_id, $me . '_read' => (int) $row->last_id], ['id' => (int) $row->id]);
		return self::no_cache(['ok' => true]);
	}

	/** The header's count: admin-ajax, so a page cached for everyone can still ask. */
	public static function ajax_count() {
		$u = wp_get_current_user();
		nocache_headers();
		wp_send_json_success(['n' => Y4APK_Members::is_member($u) && self::on() ? self::unread($u->ID) : 0]);
	}

	/* ---------------------------------------------------------------- *
	 * Emails: "@name sent you a message" (never what it says)
	 * ---------------------------------------------------------------- */

	private static function notify($from, $to) {
		if (!self::settings()['mail'] || get_user_meta($to->ID, self::MAIL, true) === '0' || (class_exists('Y4APK_Social') && Y4APK_Social::is_online($to))
			|| time() - (int) get_user_meta($to->ID, self::MAILED, true) < 6 * HOUR_IN_SECONDS || Y4APK_Members::unconfirmed($to)) {
			return;
		}
		update_user_meta($to->ID, self::MAILED, time());
		$site = Y4APK_Members::settings()['from_name'];
		Y4APK_Members::mail($to->user_email, sprintf(__('@%1$s sent you a message on %2$s', 'y4apk'), $from->user_login, $site), [
			'title' => __('You have a new message', 'y4apk'), 'preheader' => sprintf(__('From @%s', 'y4apk'), $from->user_login), 'hi' => $to->user_login,
			'lines' => [sprintf(__('@%1$s sent you a message on %2$s. Open it to read and reply.', 'y4apk'), $from->user_login, $site)],
			'button' => [__('Open messages', 'y4apk'), self::url()],
			'why' => __('At most one of these every 6 hours. Turn them off in Account settings ▸ Messages.', 'y4apk'),
		]);
	}

	/* ---------------------------------------------------------------- *
	 * The page and the member's choices
	 * ---------------------------------------------------------------- */

	public static function route(&$ctx) {
		$ctx['screen'] = 'messages';
		$u = $ctx['user'];
		$to = isset($_GET['to']) ? sanitize_user(wp_unslash($_GET['to'])) : '';
		$open = isset($_GET['t']) ? absint($_GET['t']) : 0;
		$first = null;
		if ($to !== '' && ($tu = get_user_by('login', $to)) && (int) $tu->ID !== (int) $u->ID) {
			$row = self::thread($u->ID, $tu->ID);
			$first = $row ? self::view($row, $u) : null;
			if (!$first) {
				list($ok, $why) = self::can_send($u, $tu, null);
				$first = ['t' => 0, 'with' => self::who($tu), 'msgs' => [], 'older' => false, 'seen' => 0, 'gone' => [], 'can' => $ok, 'why' => $ok ? '' : self::why_text($why, $tu), 'blocked' => false];
			}
		} elseif ($open && ($row = self::thread_by_id($open, $u->ID))) {
			$first = self::view($row, $u);
		}
		$ctx['dm'] = ['inbox' => self::inbox($u->ID), 'open' => $first, 'on' => self::on()];
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-dm', get_template_directory_uri() . '/assets/css/messages.css', ['y4apk-account'], Y4APK_THEME_VERSION);
			wp_enqueue_script('y4apk-dm', get_template_directory_uri() . '/assets/js/messages.js', [], Y4APK_THEME_VERSION, true);
		}, 20);
	}

	/** Account settings ▸ Messages. */
	public static function save_prefs($user, $in) {
		$who = isset($in['dm_who']) ? sanitize_key($in['dm_who']) : 'all';
		update_user_meta($user->ID, self::WHO, in_array($who, ['all', 'follow', 'none'], true) ? $who : 'all');
		update_user_meta($user->ID, self::MAIL, empty($in['dm_mail']) ? '0' : '1');
	}

	/** A closed account: its messages and conversations go. */
	public static function forget($user_id) {
		global $wpdb;
		if (get_option('y4apk_dm_db') !== self::DBV) {
			return;
		}
		$t = self::t('threads');
		$ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE a = %d OR b = %d", $user_id, $user_id)));
		if ($ids) {
			$in = implode(',', $ids);
			$wpdb->query('DELETE FROM ' . self::t('msgs') . " WHERE thread_id IN ($in)");
			$wpdb->query("DELETE FROM $t WHERE id IN ($in)");
		}
		$wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('reports') . ' WHERE by_id = %d', $user_id));
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Messages: reports, numbers, settings
	 * ---------------------------------------------------------------- */

	public static function menu() {
		global $wpdb;
		$n = get_option('y4apk_dm_db') === self::DBV ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('reports') . " WHERE status = 'open'") : 0;
		add_submenu_page(Y4APK_MENU, __('Messages', 'y4apk'), __('Messages', 'y4apk') . ($n ? ' <span class="awaiting-mod">' . number_format_i18n($n) . '</span>' : ''), 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function admin_url($args = []) {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render() {
		require __DIR__ . '/dm-admin.php';
	}

	public static function handle_admin() {
		global $wpdb;
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk_dm_admin');
		$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
		$id = absint($_POST['id'] ?? 0);
		$r = self::t('reports');
		$note = '';
		$rep = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $r WHERE id = %d", $id)) : null;
		if ($do === 'dismiss' && $rep) {
			$wpdb->update($r, ['status' => 'done'], ['id' => $id]);
			$note = 'dismissed';
		} elseif ($do === 'remove' && $rep) {
			$wpdb->update(self::t('msgs'), ['gone' => 1, 'body' => ''], ['id' => (int) $rep->msg_id]);
			$wpdb->update($r, ['status' => 'done'], ['msg_id' => (int) $rep->msg_id]);
			$note = 'removed';
		} elseif ($do === 'ban' && $rep) {
			$u = get_userdata((int) $rep->about_id);
			if ($u && Y4APK_Members::is_member($u)) {
				update_user_meta($u->ID, Y4APK_Members::BANNED, time());
				if (class_exists('WP_Session_Tokens')) {
					WP_Session_Tokens::get_instance($u->ID)->destroy_all();
				}
			}
			$wpdb->update($r, ['status' => 'done'], ['about_id' => (int) $rep->about_id]);
			$note = 'banned';
		} elseif ($do === 'settings') {
			$in = wp_unslash($_POST);
			update_option(self::OPTION, [
				'on'        => empty($in['on']) ? 0 : 1,
				'per_min'   => max(3, min(120, (int) ($in['per_min'] ?? 20))),
				'per_day'   => max(20, min(5000, (int) ($in['per_day'] ?? 400))),
				'new_day'   => max(1, min(200, (int) ($in['new_day'] ?? 15))),
				'young_new' => max(0, min(50, (int) ($in['young_new'] ?? 3))),
				'mail'      => empty($in['mail']) ? 0 : 1,
			], false);
			$note = 'saved';
		}
		wp_safe_redirect(self::admin_url(['tab' => sanitize_key(wp_unslash($_POST['tab'] ?? 'reports')), 'note' => $note]));
		exit;
	}
}
Y4APK_DM::init();
