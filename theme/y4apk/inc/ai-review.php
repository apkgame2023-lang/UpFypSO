<?php
/**
 * AI review of members' scripts (2.70). A script that does not pass the
 * checks for going live at once (Members ▸ Publish at once) is read by the
 * site's AI (the one Write with AI uses), a minute after it is sent:
 *
 *   approve    a real Roblox script, its name, game and words fit, nothing
 *              harmful: it goes live (under its game, made if it is new).
 *   send_back  not a script (words, a request, an email…), empty or broken,
 *              the wrong game, spam: back to the member with what to change,
 *              by email, as the Send back button does.
 *   unsafe     steals accounts, sends data away, hides what it does: it waits
 *              in Review with what the AI saw.
 *   unsure     it waits in Review.
 *
 * The AI also reads the code a loadstring loads (the first 6,000 characters,
 * fetched by this server from public addresses only). Email addresses are
 * replaced by "[email]" before anything is sent to it. A script that matches
 * the risky patterns (Y4APK_Members::RISKY) is never published by the AI.
 * What it said shows on the script's card in Review; "Ask the AI" asks again.
 */

defined('ABSPATH') || exit;

class Y4APK_AI_Review {

	const META = '_y4apk_ai';        // ['v' => verdict, 'why' => reason, 'at' => time, 'by' => provider]
	const HOOK = 'y4apk_ai_review';
	const TRIES = '_y4apk_ai_tries';

	public static function init() {
		add_action(self::HOOK, [__CLASS__, 'run']);
		add_action('transition_post_status', [__CLASS__, 'queued'], 20, 3);
		add_action('admin_post_y4apk_review_ai', [__CLASS__, 'ask_now']);
	}

	public static function on() {
		return !empty(Y4APK_Members::settings()['ai_review']) && class_exists('Y4APK_Share') && Y4APK_Share::writer_ready();
	}

	/** A member's script waits for review: the AI reads it a minute later (not on the member's page load). */
	public static function queued($new, $old, $post) {
		if ($new !== 'pending' || $post->post_type !== 'post' || !get_post_meta($post->ID, Y4APK_Members::MEMBER_POST, true)
			|| (class_exists('Y4APK_Share') && Y4APK_Share::is_share($post->ID)) || !self::on()) {
			return;
		}
		delete_post_meta($post->ID, self::META);
		delete_post_meta($post->ID, self::TRIES);
		if (!wp_next_scheduled(self::HOOK, [(int) $post->ID])) {
			wp_schedule_single_event(time() + 60, self::HOOK, [(int) $post->ID]);
		}
	}

	/** Review ▸ "Ask the AI": now, on this script. */
	public static function ask_now() {
		$id = absint($_POST['post'] ?? 0);
		if (!current_user_can('manage_options') || !check_admin_referer('y4apk_review_ai_' . $id)) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$r = self::run($id, true);
		wp_safe_redirect(add_query_arg(['page' => 'y4apk-review', 'msg' => is_array($r) ? 'ai_' . $r['v'] : 'ai_err'], admin_url('admin.php')));
		exit;
	}

	/** Email addresses out, before anything goes to the AI. */
	public static function mask($text) {
		return preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', (string) $text);
	}

	/** The code a script loads from an address (public addresses only, the first $max characters), or ''. */
	private static function fetch($url, $max = 6000) {
		if (!preg_match('~^https?://~i', $url) || !wp_http_validate_url($url)) {
			return '';
		}
		$r = wp_safe_remote_get($url, ['timeout' => 8, 'redirection' => 2, 'limit_response_size' => 60000, 'user-agent' => 'Roblox/WinInet']);
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return '';
		}
		return mb_substr(self::mask((string) wp_remote_retrieve_body($r)), 0, $max);
	}

	/**
	 * Reads one waiting script and does what the AI says. The verdict
	 * (['v', 'why', …]), or null when there was nothing to do or the AI could
	 * not answer now (it is asked again later, up to 6 times).
	 */
	public static function run($id, $now = false) {
		$id = (int) $id;
		$post = get_post($id);
		if (!$post || $post->post_status !== 'pending' || !get_post_meta($id, Y4APK_Members::MEMBER_POST, true) || !self::on()) {
			return null;
		}
		if (!$now && get_post_meta($id, Y4APK_Members_Admin::HOLD, true)) {
			return null; // hidden by visitors' reports: the owner looks
		}
		$rows = Y4APK_Scripts::get($id);
		$game = Y4APK_Roblox::game_of($id);
		$wanted = (string) get_post_meta($id, Y4APK_Members::WANTED, true);
		$desc = preg_match('~<p>(.*?)</p>~s', $post->post_content, $m) ? html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8') : '';
		$parts = [
			'Game: ' . ($game ? $game->name : ($wanted !== '' ? $wanted . ' (not on the site yet)' : 'not chosen')),
			'Script name: ' . (Y4APK_Roblox::hub_name($id) ?: wp_strip_all_tags(get_the_title($id))),
			'Title: ' . wp_strip_all_tags(get_the_title($id)),
			'Description: ' . ($desc !== '' ? $desc : '(none)'),
		];
		$fetched = 0;
		foreach ($rows as $n => $row) {
			$parts[] = 'Code ' . ($n + 1) . ($row['label'] !== '' ? ' (' . $row['label'] . ')' : '') . ":\n```lua\n" . mb_substr($row['code'], 0, 4000) . "\n```";
			if ($fetched < 2 && preg_match_all('~https?://[^\s"\'\)\]]+~i', $row['code'], $u)) {
				foreach (array_slice(array_unique($u[0]), 0, 2 - $fetched) as $url) {
					$body = self::fetch($url);
					$parts[] = 'What ' . preg_replace('~^(https?://[^/]+).*$~', '$1/…', $url) . ' sends back' . ($body !== '' ? " (first part):\n```lua\n" . $body . "\n```" : ': (could not be read)');
					$fetched++;
				}
			}
		}
		if (!$rows) {
			$parts[] = 'Code: (none)';
		}
		$system = "You check Roblox scripts that members share on a Roblox script website, before they are published. Roblox exploit scripts (hubs, auto farm, loadstrings run in an executor) are what this site is for: never refuse a script for being an exploit or for having a key system.\n"
			. "Answer with JSON only, nothing else: {\"verdict\": \"approve\" | \"send_back\" | \"unsafe\" | \"unsure\", \"reason\": \"...\"}\n"
			. "- approve: the code is a real Roblox Lua script (usually loadstring(game:HttpGet(\"https://…\"))() or plain Lua), it is safe, and the name, game and description fit it. Small spelling mistakes are fine. reason: a few words.\n"
			. "- send_back: it is not a script (plain words, a request for a game, an email or phone number — shown as [email] —, a link to a web page instead of code), the code is empty or cannot work, the game is clearly wrong, or the name or description is spam, ads or insulting. reason: one or two short, friendly sentences to the member saying exactly what to change, in English.\n"
			. "- unsafe: the code or the code it loads steals accounts or cookies (.ROBLOSECURITY, tokens), sends data to Discord webhooks, IP loggers or other servers, makes purchases, or is heavily obfuscated to hide what it does. reason: what you saw, for the site owner.\n"
			. "- unsure: you cannot tell. reason: why.\n"
			. "Code loaded from an address that could not be read is not a reason to refuse by itself.";
		$user = self::mask(implode("\n\n", $parts));
		$a = Y4APK_Writer::ask_text($system, $user, 600);
		if (is_wp_error($a)) {
			$tries = (int) get_post_meta($id, self::TRIES, true) + 1;
			update_post_meta($id, self::TRIES, $tries);
			if ($tries < 6 && !wp_next_scheduled(self::HOOK, [$id])) {
				wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, self::HOOK, [$id]);
			}
			update_post_meta($id, self::META, ['v' => 'error', 'why' => $a->get_error_message(), 'at' => time(), 'by' => '']);
			return null;
		}
		$text = (string) $a[0];
		$j = preg_match('~\{.*\}~s', $text, $mm) ? json_decode($mm[0], true) : null;
		$v = is_array($j) && in_array($j['verdict'] ?? '', ['approve', 'send_back', 'unsafe', 'unsure'], true) ? $j['verdict'] : 'unsure';
		$why = is_array($j) ? trim(mb_substr(sanitize_text_field((string) ($j['reason'] ?? '')), 0, 300)) : '';
		$out = ['v' => $v, 'why' => $why, 'at' => time(), 'by' => Y4APK_Writer::provider_name($a[1])];

		if ($v === 'approve') {
			foreach ($rows as $row) {
				if (preg_match(Y4APK_Members::RISKY, $row['code'])) {
					$out = ['v' => 'unsure', 'why' => __('The AI found nothing wrong, but the code does something the checks always leave to you (it buys, writes files, loads by number or hides its code). Read it before you approve.', 'y4apk'), 'at' => time(), 'by' => $out['by']];
					update_post_meta($id, self::META, $out);
					return $out;
				}
			}
			$term = $game ? (int) $game->term_id : 0;
			if (!$term && $wanted !== '') {
				$t = Y4APK_Roblox::find_or_create($wanted);
				$term = is_wp_error($t) ? 0 : (int) $t;
			}
			if (!$term) {
				$out = ['v' => 'unsure', 'why' => __('The AI would approve it, but its game could not be found or made: choose the game and approve it.', 'y4apk'), 'at' => time(), 'by' => $out['by']];
				update_post_meta($id, self::META, $out);
				return $out;
			}
			update_post_meta($id, self::META, $out);
			Y4APK_Members_Admin::publish_script($id, $term, true);
		} elseif ($v === 'send_back') {
			update_post_meta($id, self::META, $out);
			Y4APK_Members_Admin::send_back($id, $why !== '' ? $why : __('This does not look like a Roblox script. Paste the script’s code, for example loadstring(game:HttpGet("…"))().', 'y4apk'));
		} else {
			update_post_meta($id, self::META, $out);
		}
		return $out;
	}

	/** What the AI said, for the script's card in Review (HTML), or ''. */
	public static function note($id) {
		$a = get_post_meta($id, self::META, true);
		$labels = ['approve' => __('AI: looks fine', 'y4apk'), 'send_back' => __('AI: sent back', 'y4apk'), 'unsafe' => __('AI: unsafe', 'y4apk'), 'unsure' => __('AI: not sure', 'y4apk'), 'error' => __('AI: could not answer', 'y4apk')];
		$out = '';
		if (is_array($a) && isset($labels[$a['v']])) {
			$out .= '<p class="y4rv-ai is-' . esc_attr($a['v']) . '"><b>' . esc_html($labels[$a['v']]) . '</b>' . ($a['why'] !== '' ? ' — ' . esc_html($a['why']) : '')
				. ' <span>' . esc_html(trim(($a['by'] ?? '') . ' · ' . sprintf(__('%s ago', 'y4apk'), human_time_diff((int) $a['at'])), ' ·')) . '</span></p>';
		} elseif (self::on() && wp_next_scheduled(self::HOOK, [(int) $id])) {
			$out .= '<p class="y4rv-ai"><b>' . esc_html__('AI: reading it in a minute', 'y4apk') . '</b></p>';
		}
		if (self::on()) {
			$out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="y4rv-ai-ask"><input type="hidden" name="action" value="y4apk_review_ai"><input type="hidden" name="post" value="' . (int) $id . '">'
				. wp_nonce_field('y4apk_review_ai_' . (int) $id, '_wpnonce', true, false) . '<button class="button button-small">' . esc_html__('Ask the AI', 'y4apk') . '</button></form>';
		}
		return $out;
	}
}

Y4APK_AI_Review::init();
