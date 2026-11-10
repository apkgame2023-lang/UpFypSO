<?php
/**
 * Paid scripts and files (2.69): a creator puts a price in coins on a script
 * they wrote, or on a game or app of their own (one that is not on Google
 * Play: a mod of somebody else's game is never sold). A member unlocks it once
 * with coins and keeps it on their account.
 *
 *   Price     Set by the creator on /account/unlock/?item=ID (from My uploads),
 *             with a tick that it is their own work. Kept in _y4apk_price. A
 *             post whose maker is no longer a creator, or with the wallet off,
 *             is free again.
 *   Buying    /account/unlock/?item=ID: the coins leave the buyer (VIP pays 10%
 *             less), the creator gets 70% of what was paid in their earnings
 *             (held like gifts), the site keeps 30% (settings).
 *   Refunds   Within 7 days: the buyer asks (it does not work, it is not what
 *             it says) and the owner says yes or no in Wallet ▸ Sales; a post
 *             deleted within the 7 days is refunded to everyone by itself.
 *
 * The code and the files are never in a page someone has not paid for: the
 * page shows a card with the price instead, so a page kept by the cache for
 * visitors has nothing to copy. Pages of a paid post are not cached for
 * signed-in members (they may hold what was paid for).
 *
 * Expiring links (2.69.1): a paid file on R2 moves to paid/<random>/<name>,
 * and its download folder's page stops pointing at it (it sends to the post).
 * Whoever has it gets the file through /account/unlock/?item=ID&file=N: a
 * link R2 signs for 30 minutes, made at each tap. paid/ must not be public on
 * the files domain (a Cloudflare WAF rule; Wallet ▸ Sales checks it). Free
 * again, the file goes back where it was and the folder points at it again.
 */

defined('ABSPATH') || exit;

class Y4APK_Paid {

	const PRICE = '_y4apk_price';  // coins (0 or none: free)
	const OWN   = '_y4apk_own';    // when the creator said it is their own work
	const DBV   = '1';
	const MIN   = 10;
	const MAX   = 50000;
	const FILES = '_y4apk_paid_files'; // 2.69.1: [row => ['key' => hidden R2 key, 'from' => its first key, 'folder' => download folder]]
	const SYNC  = 'y4apk_paid_sync';   // the background step that hides (or brings back) a post's files
	const TTL   = 1800;                // seconds a download link works
	const CHECK = 'y4apk_paid_check';  // is paid/ blocked on the public files domain: ['at', 'open', 'code']

	public static function init() {
		add_action('init', [__CLASS__, 'setup']);
		add_action('template_redirect', [__CLASS__, 'no_cache'], 5);
		add_action('wp_trash_post', [__CLASS__, 'removed']);
		add_action('before_delete_post', [__CLASS__, 'removed']);
		add_action(self::SYNC, [__CLASS__, 'sync']);
		add_action('save_post_post', [__CLASS__, 'saved'], 99);
	}

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_unlocks';
	}

	public static function setup() {
		if (get_option('y4apk_paid_db') === self::DBV) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . self::t() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			creator_id bigint(20) unsigned NOT NULL DEFAULT 0,
			coins int(10) unsigned NOT NULL DEFAULT 0,
			paid bigint(20) NOT NULL DEFAULT 0,
			net bigint(20) NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'ok',
			reason varchar(190) NOT NULL DEFAULT '',
			made int(10) unsigned NOT NULL DEFAULT 0,
			done int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY post_user (post_id,user_id),
			KEY user_id (user_id),
			KEY creator_id (creator_id),
			KEY status (status)
		) " . $wpdb->get_charset_collate() . ';');
		update_option('y4apk_paid_db', self::DBV, false);
	}

	/* ---------------------------------------------------------------- *
	 * What is for sale
	 * ---------------------------------------------------------------- */

	public static function on() {
		return class_exists('Y4APK_Wallet') && Y4APK_Wallet::on();
	}

	/** The post's price in coins, 0 when it is free (no price, the wallet off, or its maker no longer a creator). */
	public static function price($post_id) {
		$n = (int) get_post_meta((int) $post_id, self::PRICE, true);
		if ($n < 1 || !self::on()) {
			return 0;
		}
		$p = get_post($post_id);
		return $p && Y4APK_Wallet::creator_ok((int) $p->post_author) ? $n : 0;
	}

	/** What $user_id pays for $coins: VIP pays less. */
	public static function pay_for($coins, $user_id) {
		$off = (float) Y4APK_Wallet::settings()['vip_off'];
		return $off > 0 && $user_id && class_exists('Y4APK_VIP') && Y4APK_VIP::is_vip($user_id) ? self::vip_price($coins) : (int) $coins;
	}

	public static function vip_price($coins) {
		return (int) ceil(round($coins * (100 - (float) Y4APK_Wallet::settings()['vip_off']) / 100, 4));
	}

	/** 'script', 'file', or '' when the post is neither of a member's. */
	public static function kind($post_id) {
		if (class_exists('Y4APK_Share') && Y4APK_Share::is_share($post_id)) {
			return 'file';
		}
		return get_post_meta($post_id, Y4APK_Members::MEMBER_POST, true) && class_exists('Y4APK_Scripts') && Y4APK_Scripts::get($post_id) ? 'script' : '';
	}

	/** Why $user may not sell $post ('' when they may). */
	public static function why_not($post, $user) {
		if (!$post || !$user || (int) $post->post_author !== (int) $user->ID) {
			return __('Only the member who shared it can sell it.', 'y4apk');
		}
		$kind = self::kind($post->ID);
		if ($kind === '') {
			return __('Only scripts and files members share can be sold.', 'y4apk');
		}
		if (!self::on()) {
			return __('The wallet is closed at the moment.', 'y4apk');
		}
		$cr = Y4APK_Wallet::creator($user->ID);
		if (!$cr['ok']) {
			return sprintf(__('Selling is for creators: reach %s followers first.', 'y4apk'), number_format_i18n($cr['need']));
		}
		if ($kind === 'file') {
			$d = Y4APK_Share::data($post->ID);
			if (!empty($d['play']) || !empty($d['update_of']) || get_post_meta($post->ID, Y4APK_Share::PARENT, true)) {
				return __('A game or app from Google Play is somebody else’s work, so it cannot be sold. Only your own apps and tools can.', 'y4apk');
			}
		}
		return '';
	}

	/** Has $user_id the right to it: they made it, they look after the site, or they unlocked it. */
	public static function has($post_id, $user_id) {
		$user_id = (int) $user_id;
		if (!$user_id) {
			return false;
		}
		$p = get_post($post_id);
		if ($p && (int) $p->post_author === $user_id) {
			return true;
		}
		if (user_can($user_id, 'edit_post', $post_id)) {
			return true;
		}
		return (bool) self::bought($post_id, $user_id);
	}

	/** The member's unlock of a post that still counts, or null. */
	public static function bought($post_id, $user_id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . " WHERE post_id = %d AND user_id = %d AND status IN ('ok', 'asked', 'denied') ORDER BY id DESC LIMIT 1", (int) $post_id, (int) $user_id));
	}

	/** The page must show the card instead of the code or the files. */
	public static function locked($post_id) {
		return self::price($post_id) > 0 && !self::has($post_id, get_current_user_id());
	}

	public static function sales($post_id) {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . " WHERE post_id = %d AND status <> 'refunded'", (int) $post_id));
	}

	public static function url($post_id) {
		return Y4APK_Members::url('unlock', ['item' => (int) $post_id]); // not ?p= (WordPress's own: it opens the post)
	}

	/** A paid post's page holds what was paid for, for a signed-in member: never cached. */
	public static function no_cache() {
		if (!is_user_logged_in() || !is_singular('post') || self::price(get_queried_object_id()) < 1) {
			return;
		}
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		nocache_headers();
		header('X-LiteSpeed-Cache-Control: no-cache');
		do_action('litespeed_control_set_nocache', 'y4apk paid post');
	}

	/* ---------------------------------------------------------------- *
	 * Setting the price
	 * ---------------------------------------------------------------- */

	/** true, or a WP_Error. 0 stops selling. */
	public static function set_price($post_id, $user, $coins, $own) {
		$post = get_post($post_id);
		$coins = (int) $coins;
		if ($coins > 0) {
			$why = self::why_not($post, $user);
			if ($why !== '') {
				return new WP_Error('y4apk_price', $why);
			}
			if ($coins < self::MIN || $coins > self::MAX) {
				return new WP_Error('y4apk_price', sprintf(__('A price is %1$s to %2$s coins.', 'y4apk'), number_format_i18n(self::MIN), number_format_i18n(self::MAX)));
			}
			if (!$own) {
				return new WP_Error('y4apk_price', __('Tick that it is your own work: only what you made yourself can be sold.', 'y4apk'));
			}
			update_post_meta($post_id, self::PRICE, $coins);
			update_post_meta($post_id, self::OWN, time());
		} elseif ($post && (int) $post->post_author === (int) $user->ID) {
			delete_post_meta($post_id, self::PRICE);
		}
		clean_post_cache($post_id);
		do_action('litespeed_purge_post', (int) $post_id); // the page visitors get changes with the price
		if (self::kind($post_id) === 'file') {
			self::sync($post_id, true); // 2.69.1: hide its files (or bring them back)
		}
		return true;
	}

	/* ---------------------------------------------------------------- *
	 * Hidden files and links that expire (2.69.1)
	 * ---------------------------------------------------------------- */

	/** What has been hidden for the post. */
	public static function files($post_id) {
		$f = get_post_meta((int) $post_id, self::FILES, true);
		return is_array($f) ? $f : [];
	}

	/**
	 * Hides a paid post's files on R2 (or puts a free one's back): small ones
	 * at once when $now, big ones in the background (R2 copies a file to move
	 * it, and a few GB take longer than a page may).
	 */
	public static function sync($post_id, $now = false) {
		$post_id = (int) $post_id;
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store() || get_post_type($post_id) !== 'post') {
			return;
		}
		$paid = (int) get_post_meta($post_id, self::PRICE, true) > 0;
		$files = self::files($post_id);
		$rows = get_post_meta($post_id, 'repeatable_download_link', true);
		$later = false;
		if (!$now) {
			ignore_user_abort(true);
			if (function_exists('set_time_limit')) {
				@set_time_limit(1200); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		if ($paid) {
			foreach (is_array($rows) ? $rows : [] as $i => $row) {
				$folder = Y4APK_R2::folder_of((string) ($row['download_url'] ?? ''));
				$key = $folder !== '' ? Y4APK_R2::folder_key($folder) : '';
				if ($key === '') {
					continue; // not one of our files, or hidden already (its page points at the post)
				}
				$size = Y4APK_R2::size_of($key);
				if ($now && $size > Y4APK_R2::RENAME_NOW) {
					$later = true;
					continue;
				}
				$to = strpos($key, 'paid/') === 0 ? $key : 'paid/' . strtolower(wp_generate_password(24, false, false)) . '/' . basename($key);
				$moved = Y4APK_R2::move($key, $to, $size, false);
				if (is_wp_error($moved) || is_wp_error(Y4APK_R2::point_folder($folder, get_permalink($post_id)))) {
					$later = true;
					continue;
				}
				if ($to !== $key) {
					Y4APK_R2::remove($key);
				}
				if (!empty($files[$i]['key']) && $files[$i]['key'] !== $to) {
					Y4APK_R2::remove($files[$i]['key']); // the file was replaced: the old hidden one goes
				}
				$files[$i] = ['key' => $to, 'from' => !empty($files[$i]['from']) && $files[$i]['key'] === $key ? $files[$i]['from'] : $key, 'folder' => $folder];
				update_post_meta($post_id, self::FILES, $files);
			}
		} else {
			foreach ($files as $i => $f) {
				$size = Y4APK_R2::size_of($f['key']);
				if ($size <= 0) {
					unset($files[$i]); // gone from R2: nothing to bring back
					continue;
				}
				if ($now && $size > Y4APK_R2::RENAME_NOW) {
					$later = true;
					continue;
				}
				$moved = Y4APK_R2::move($f['key'], $f['from'], $size, false);
				if (is_wp_error($moved) || is_wp_error(Y4APK_R2::point_folder($f['folder'], Y4APK_R2::public_url($f['from'])))) {
					$later = true;
					continue;
				}
				Y4APK_R2::remove($f['key']);
				unset($files[$i]);
			}
			$files ? update_post_meta($post_id, self::FILES, $files) : delete_post_meta($post_id, self::FILES);
		}
		if ($later && !wp_next_scheduled(self::SYNC, [$post_id])) {
			wp_schedule_single_event(time() + ($now ? 30 : 10 * MINUTE_IN_SECONDS), self::SYNC, [$post_id]);
		}
	}

	/** A paid post saved (a file replaced in App updates puts an R2 address in its folder again): hide it again. */
	public static function saved($post_id) {
		if (wp_is_post_revision($post_id) || (!get_post_meta($post_id, self::PRICE, true) && !get_post_meta($post_id, self::FILES, true))) {
			return;
		}
		if (!wp_next_scheduled(self::SYNC, [(int) $post_id])) {
			wp_schedule_single_event(time() + 60, self::SYNC, [(int) $post_id]);
		}
	}

	/** Where a download button of a paid post goes: this site, which hands out a link that expires. */
	public static function file_link($post_id, $index) {
		return Y4APK_Members::url('unlock', ['item' => (int) $post_id, 'file' => (int) $index]);
	}

	/** A link to the file of row $index that works for TTL seconds, or '' (a file elsewhere: its own address). */
	public static function signed($post_id, $index) {
		$rows = get_post_meta($post_id, 'repeatable_download_link', true);
		$row = is_array($rows) && isset($rows[$index]) ? $rows[$index] : null;
		if (!$row) {
			return '';
		}
		$files = self::files($post_id);
		$key = $files[$index]['key'] ?? '';
		if ($key === '' && class_exists('Y4APK_R2')) {
			$folder = Y4APK_R2::folder_of((string) ($row['download_url'] ?? ''));
			$key = $folder !== '' ? Y4APK_R2::folder_key($folder) : ''; // not hidden yet
		}
		if ($key === '' || !class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return (string) ($row['download_url'] ?? '');
		}
		$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($key));
		return Y4APK_R2::presign('GET', $key, ['response-content-disposition' => 'attachment; filename="' . $name . '"'], self::TTL);
	}

	/** Is paid/ blocked on the public files domain? Puts a small file there, asks for it, takes it off. */
	public static function check_public() {
		if (!class_exists('Y4APK_R2') || !Y4APK_R2::can_store()) {
			return null;
		}
		$key = 'paid/check-' . strtolower(wp_generate_password(12, false, false)) . '.txt';
		$put = Y4APK_R2::put($key, 'y4apk', 'text/plain', 'no-store');
		if (is_wp_error($put)) {
			return null;
		}
		$r = wp_remote_get(Y4APK_R2::public_url($key), ['timeout' => 15, 'redirection' => 0]);
		Y4APK_R2::remove($key);
		$code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
		$open = $code === 200 && trim((string) wp_remote_retrieve_body($r)) === 'y4apk';
		$c = ['at' => time(), 'open' => $open, 'code' => $code];
		update_option(self::CHECK, $c, false);
		return $c;
	}

	/* ---------------------------------------------------------------- *
	 * Buying, refunds
	 * ---------------------------------------------------------------- */

	/** The unlock's id, or a WP_Error that says why not. */
	public static function buy($user_id, $post_id) {
		global $wpdb;
		$user_id = (int) $user_id;
		$post = get_post($post_id);
		$price = $post && $post->post_status === 'publish' ? self::price($post->ID) : 0;
		if ($price < 1) {
			return new WP_Error('y4apk_unlock', __('This is not for sale.', 'y4apk'));
		}
		if (self::has($post->ID, $user_id)) {
			return new WP_Error('y4apk_unlock', __('You have it already.', 'y4apk'));
		}
		$creator = (int) $post->post_author;
		if (class_exists('Y4APK_Wallet') && Y4APK_Wallet::same_connection($user_id, $creator)) {
			return new WP_Error('y4apk_unlock', __('You cannot buy from an account on the same connection as the creator’s.', 'y4apk'));
		}
		if (!Y4APK_Members::hit('unlock:' . $user_id, 20, HOUR_IN_SECONDS)) {
			return new WP_Error('y4apk_unlock', __('That is a lot at once. Try again in a while.', 'y4apk'));
		}
		$coins = self::pay_for($price, $user_id);
		$paid = Y4APK_Wallet::from_coins($coins);
		$net = (int) floor($paid * (100 - (float) Y4APK_Wallet::settings()['sale_share']) / 100);
		$id = Y4APK_Wallet::tx(function () use ($wpdb, $user_id, $post, $creator, $coins, $paid, $net) {
			if (!Y4APK_Wallet::take_coins($user_id, $paid, 'unlock', (string) $post->ID, $creator)) {
				return false;
			}
			if (self::bought($post->ID, $user_id)) {
				return false; // a second tap waited on the first (the wallet row is locked): it is undone
			}
			$wpdb->insert(self::t(), ['post_id' => $post->ID, 'user_id' => $user_id, 'creator_id' => $creator, 'coins' => $coins, 'paid' => $paid, 'net' => $net, 'status' => 'ok', 'made' => time()],
				['%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d']);
			$id = (int) $wpdb->insert_id;
			if (!$id) {
				return false;
			}
			if ($net > 0) {
				Y4APK_Wallet::credit($creator, 0, $net, 'sale', (string) $post->ID, $user_id);
			}
			return $id;
		});
		if (!$id) {
			return self::bought($post->ID, $user_id) ? new WP_Error('y4apk_unlock', __('You have it already.', 'y4apk'))
				: new WP_Error('y4apk_unlock', sprintf(__('Not enough %s. Buy coins first.', 'y4apk'), Y4APK_Wallet::coin_name()));
		}
		$c = get_userdata($creator);
		if ($c && !get_transient('y4apk_salemail_' . $creator)) { // one email in 30 minutes at most
			set_transient('y4apk_salemail_' . $creator, 1, 30 * MINUTE_IN_SECONDS);
			Y4APK_Wallet::mail_to($c, __('You sold something', 'y4apk'), [
				sprintf(__('A member unlocked “%1$s”. %2$s is in your earnings.', 'y4apk'), wp_strip_all_tags(get_the_title($post)), Y4APK_Wallet::fmt($net)),
			], __('You got this email because you sell scripts or files on the site.', 'y4apk'));
		}
		return $id;
	}

	public static function row($id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id));
	}

	public static function refund_days() {
		return max(0, (int) Y4APK_Wallet::settings()['refund_days']);
	}

	/** Until when the buyer can ask for their coins back (0: not any more). */
	public static function refund_until($row) {
		$until = (int) $row->made + self::refund_days() * DAY_IN_SECONDS;
		return $row->status === 'ok' && $until > time() ? $until : 0;
	}

	/** The buyer asks for their coins back: true or a WP_Error. */
	public static function ask_refund($user_id, $post_id, $reason) {
		$row = self::bought($post_id, $user_id);
		if (!$row || !self::refund_until($row)) {
			return new WP_Error('y4apk_refund', sprintf(__('Refunds can be asked within %s days of unlocking.', 'y4apk'), number_format_i18n(self::refund_days())));
		}
		$reason = mb_substr(sanitize_text_field($reason), 0, 190);
		if (mb_strlen($reason) < 5) {
			return new WP_Error('y4apk_refund', __('Say what is wrong with it.', 'y4apk'));
		}
		global $wpdb;
		$wpdb->update(self::t(), ['status' => 'asked', 'reason' => $reason], ['id' => (int) $row->id, 'status' => 'ok']);
		$owner = (string) get_option('admin_email');
		if ($owner !== '' && Y4APK_Members::hit('refund_owner', 1, 15 * MINUTE_IN_SECONDS)) {
			Y4APK_Members::mail($owner, __('A refund is asked for', 'y4apk'), [
				'title' => __('A refund is asked for', 'y4apk'),
				'lines' => [sprintf(__('A member asks for their coins back for “%s”.', 'y4apk'), wp_strip_all_tags(get_the_title($post_id)))],
				'button' => [__('Open the sales', 'y4apk'), Y4APK_Wallet::admin_url(['tab' => 'sales'])],
			]);
		}
		return true;
	}

	/**
	 * Gives the coins back and takes the creator's share back out of their
	 * earnings (it is still held: the refund days are fewer than the hold).
	 */
	public static function refund($id, $why = '') {
		$row = self::row($id);
		if (!$row || !in_array($row->status, ['ok', 'asked', 'denied'], true)) {
			return false;
		}
		global $wpdb;
		$done = Y4APK_Wallet::tx(function () use ($wpdb, $row, $why) {
			$n = $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET status = 'refunded', done = %d, reason = IF(reason = '', %s, reason) WHERE id = %d AND status = %s",
				time(), mb_substr($why, 0, 190), (int) $row->id, $row->status));
			if ($n !== 1) {
				return false;
			}
			Y4APK_Wallet::credit((int) $row->user_id, (int) $row->paid, 0, 'refund', (string) $row->post_id, (int) $row->creator_id);
			$have = max(0, Y4APK_Wallet::balance((int) $row->creator_id)['earned']);
			$back = min((int) $row->net, $have);
			if ($back > 0) {
				Y4APK_Wallet::credit((int) $row->creator_id, 0, -$back, 'sale_back', (string) $row->post_id, (int) $row->user_id);
			}
			return true;
		});
		if (!$done) {
			return false;
		}
		$title = wp_strip_all_tags(get_the_title((int) $row->post_id)) ?: __('a deleted post', 'y4apk');
		$b = get_userdata((int) $row->user_id);
		if ($b) {
			Y4APK_Wallet::mail_to($b, __('Your coins are back', 'y4apk'), array_filter([
				sprintf(__('%1$s for “%2$s” are back in your wallet.', 'y4apk'), Y4APK_Wallet::fmt_coins((int) $row->paid), $title),
				$why !== '' ? __('Why:', 'y4apk') . ' ' . $why : '',
			]), __('You got this email because you unlocked something on the site.', 'y4apk'));
		}
		$c = get_userdata((int) $row->creator_id);
		if ($c) {
			Y4APK_Wallet::mail_to($c, __('A sale was refunded', 'y4apk'), array_filter([
				sprintf(__('A member got their coins back for “%1$s”, so %2$s left your earnings.', 'y4apk'), $title, Y4APK_Wallet::fmt((int) $row->net)),
				$why !== '' ? __('Why:', 'y4apk') . ' ' . $why : '',
			]), __('You got this email because you sell scripts or files on the site.', 'y4apk'));
		}
		return true;
	}

	/** The owner says no: the buyer keeps it, the creator keeps the coins. */
	public static function deny($id, $why = '') {
		global $wpdb;
		$row = self::row($id);
		if (!$row || $row->status !== 'asked') {
			return false;
		}
		$wpdb->update(self::t(), ['status' => 'denied', 'done' => time()], ['id' => (int) $row->id]);
		$b = get_userdata((int) $row->user_id);
		if ($b) {
			Y4APK_Wallet::mail_to($b, __('About your refund', 'y4apk'), array_filter([
				sprintf(__('We looked at “%s” and it works as it says, so the coins stay with the creator.', 'y4apk'), wp_strip_all_tags(get_the_title((int) $row->post_id))),
				$why !== '' ? __('Note:', 'y4apk') . ' ' . $why : '',
			]), __('You got this email because you asked for a refund.', 'y4apk'));
		}
		return true;
	}

	/** A paid post deleted: everyone who unlocked it in the refund days gets their coins back. */
	public static function removed($post_id) {
		global $wpdb;
		if (!get_option('y4apk_paid_db') || get_post_type($post_id) !== 'post') {
			return;
		}
		$since = time() - self::refund_days() * DAY_IN_SECONDS;
		$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::t() . " WHERE post_id = %d AND status IN ('ok', 'asked') AND made > %d", (int) $post_id, $since));
		foreach ($ids as $id) {
			self::refund((int) $id, __('It was taken off the site.', 'y4apk'));
		}
	}

	/* ---------------------------------------------------------------- *
	 * On the post's page
	 * ---------------------------------------------------------------- */

	/** The card in place of the code ($kind 'script') or the download buttons ('file'). */
	public static function card($post_id, $kind) {
		$price = self::price($post_id);
		$p = get_post($post_id);
		$by = $p ? get_userdata((int) $p->post_author) : null;
		$n = self::sales($post_id);
		$off = (float) Y4APK_Wallet::settings()['vip_off'];
		$go = is_user_logged_in() ? self::url($post_id) : Y4APK_Members::url('login');
		ob_start();
		?>
		<div class="y4-paid" id="unlock">
			<span class="y4-paid-lock" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M17 9h-1V7a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Zm-5 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm2-9h-4V7a2 2 0 1 1 4 0v2Z"/></svg></span>
			<div class="y4-paid-t">
				<b><?php echo esc_html($kind === 'file' ? __('Paid file', 'y4apk') : __('Paid script', 'y4apk')); ?><?php if ($by) : ?> · <a href="<?php echo esc_url(Y4APK_Members::profile_url($by)); ?>">@<?php echo esc_html($by->user_login); ?></a><?php endif; ?></b>
				<small><?php esc_html_e('Made and sold by its creator. Unlock it once: it stays on your account.', 'y4apk'); ?></small>
			</div>
			<div class="y4-paid-price">
				<span><?php echo Y4APK_Wallet::coin_icon(22); // phpcs:ignore WordPress.Security.EscapeOutput ?><b><?php echo esc_html(number_format_i18n($price)); ?></b></span>
				<?php if ($off > 0) : ?><small><?php echo Y4APK_VIP::crown(13); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(sprintf(__('VIP %s', 'y4apk'), number_format_i18n(self::vip_price($price)))); ?></small><?php endif; ?>
			</div>
			<a class="y4-paid-go" href="<?php echo esc_url($go); ?>" rel="nofollow"><?php echo esc_html(is_user_logged_in() ? __('Unlock', 'y4apk') : __('Sign in to unlock', 'y4apk')); ?></a>
			<?php if ($n > 0) : ?><p class="y4-paid-n"><?php echo esc_html(sprintf(_n('%s member unlocked it', '%s members unlocked it', $n, 'y4apk'), number_format_i18n($n))); ?></p><?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Under what was paid for: "Unlocked", the refund, or the creator's own sales. */
	public static function note($post_id) {
		$uid = get_current_user_id();
		if (!$uid || self::price($post_id) < 1) {
			return '';
		}
		$p = get_post($post_id);
		if ($p && (int) $p->post_author === $uid) {
			$n = self::sales($post_id);
			return '<p class="y4-paid-note is-own">' . Y4APK_Wallet::coin_icon(16) . esc_html(sprintf(_n('Your paid post · %s sale', 'Your paid post · %s sales', $n, 'y4apk'), number_format_i18n($n))) . ' · <a href="' . esc_url(self::url($post_id)) . '">' . esc_html__('Price', 'y4apk') . '</a></p>';
		}
		$row = self::bought($post_id, $uid);
		if (!$row) {
			return '';
		}
		$until = self::refund_until($row);
		return '<p class="y4-paid-note">' . Y4APK_Wallet::coin_icon(16) . esc_html(sprintf(__('Unlocked on %s', 'y4apk'), wp_date(get_option('date_format'), (int) $row->made)))
			. ($until ? ' · <a href="' . esc_url(self::url($post_id)) . '#refund">' . esc_html__('Not working? Ask for a refund', 'y4apk') . '</a>' : '')
			. ($row->status === 'asked' ? ' · ' . esc_html__('Refund asked', 'y4apk') : '') . '</p>';
	}

	/* ---------------------------------------------------------------- *
	 * /account/unlock/?item=ID
	 * ---------------------------------------------------------------- */

	public static function route(&$ctx) {
		$ctx['screen'] = 'unlock';
		$uid = (int) $ctx['user']->ID;
		$post = get_post(absint($_GET['item'] ?? 0));
		if (!$post || $post->post_type !== 'post' || self::kind($post->ID) === '' || !in_array($post->post_status, ['publish', 'pending', 'draft'], true)) {
			$ctx['screen'] = 'notfound';
			return;
		}
		$own = (int) $post->post_author === $uid;
		if (isset($_GET['file'])) { // 2.69.1: a download button: a link that expires, made now
			if (!self::has($post->ID, $uid) || self::kind($post->ID) !== 'file') {
				wp_safe_redirect(self::url($post->ID));
				exit;
			}
			if (!Y4APK_Members::hit('paid_dl:' . $uid, 60, HOUR_IN_SECONDS)) {
				$ctx['errors'][] = __('That is a lot of downloads at once. Try again in a while.', 'y4apk');
			} else {
				$to = self::signed($post->ID, absint($_GET['file']));
				if ($to !== '') {
					header('Referrer-Policy: no-referrer');
					wp_redirect($to); // phpcs:ignore WordPress.Security.SafeRedirect -- R2, or the file's own address
					exit;
				}
				$ctx['errors'][] = __('That file is not there any more.', 'y4apk');
			}
		}
		$do = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['y4_do']) ? sanitize_key(wp_unslash($_POST['y4_do'])) : '';
		if ($do !== '' && !wp_verify_nonce(isset($_POST['_y4n']) ? sanitize_text_field(wp_unslash($_POST['_y4n'])) : '', 'y4apk_acct_unlock')) {
			$ctx['errors'][] = __('The page was open too long. Try again.', 'y4apk');
			$do = '';
		}
		if ($do !== '' && Y4APK_Members::unconfirmed($ctx['user'])) {
			$ctx['errors'][] = __('Confirm your email first: open the link we sent you, then try again.', 'y4apk');
			$do = '';
		}
		if ($do === 'price' && $own) {
			$coins = absint($_POST['price'] ?? 0);
			$r = self::set_price($post->ID, $ctx['user'], empty($_POST['sell']) ? 0 : $coins, !empty($_POST['own']));
			if (is_wp_error($r)) {
				$ctx['errors'][] = $r->get_error_message();
				$ctx['old'] += ['price' => $coins, 'own' => !empty($_POST['own'])];
			} else {
				wp_safe_redirect(add_query_arg('ok', empty($_POST['sell']) ? 'free' : 'priced', self::url($post->ID)));
				exit;
			}
		}
		if ($do === 'unlock' && !$own) {
			$r = self::buy($uid, $post->ID);
			if (is_wp_error($r)) {
				$ctx['errors'][] = $r->get_error_message();
			} else {
				wp_safe_redirect(get_permalink($post) . '#download-now');
				exit;
			}
		}
		if ($do === 'refund' && !$own) {
			$r = self::ask_refund($uid, $post->ID, (string) wp_unslash($_POST['reason'] ?? ''));
			if (is_wp_error($r)) {
				$ctx['errors'][] = $r->get_error_message();
			} else {
				wp_safe_redirect(add_query_arg('ok', 'refund', self::url($post->ID)) . '#refund');
				exit;
			}
		}
		$price = self::price($post->ID);
		$ctx['unlock'] = [
			'post'   => $post,
			'kind'   => self::kind($post->ID),
			'own'    => $own,
			'price'  => $price,
			'stored' => (int) get_post_meta($post->ID, self::PRICE, true),
			'pay'    => $price ? self::pay_for($price, $uid) : 0,
			'vip'    => class_exists('Y4APK_VIP') && Y4APK_VIP::is_vip($uid),
			'have'   => Y4APK_Wallet::coins(Y4APK_Wallet::balance($uid)['credit']),
			'row'    => $own ? null : self::bought($post->ID, $uid),
			'why'    => $own ? self::why_not($post, $ctx['user']) : '',
			'sales'  => self::sales($post->ID),
			'earned' => $own ? self::earned($post->ID) : 0,
		];
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_style('y4apk-wallet', get_template_directory_uri() . '/assets/css/wallet.css', ['y4apk-account'], Y4APK_THEME_VERSION);
		}, 20);
	}

	/** What a post's sales made its creator (micro), less refunds. */
	public static function earned($post_id) {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(net) FROM ' . self::t() . " WHERE post_id = %d AND status <> 'refunded'", (int) $post_id));
	}
}

Y4APK_Paid::init();
