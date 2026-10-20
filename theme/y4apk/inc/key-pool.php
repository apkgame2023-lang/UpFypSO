<?php
/**
 * Y4APK ▸ Write with AI: several API keys for one AI (2.30) — the rules of
 * sync-system/node/api_rotator.js, in PHP for the theme. An AI's key box takes
 * more than one key, separated by commas. The key in use stays in use until
 * the AI pushes back:
 *
 *   429 (its limit)          the key rests for retry-after, Gemini's "retry in
 *                            Ns", or an hour for a daily limit, and the next
 *                            key answers at once
 *   key refused, no credit   the key is set aside for a day, the next answers
 *
 * A key comes back by itself when its rest is over. Only when every key rests
 * does the AI itself wait, and Write with AI goes on with the next AI in turn
 * (Gemini → Groq → …), as before. With one key nothing changes.
 *
 * Limits belong to the account or project, not to the key: two keys of one
 * Google Cloud project share one Gemini limit. Pool keys of separate projects
 * or accounts you are allowed to use together (a team's paid projects, say);
 * extra free accounts made to get past a free tier's limit are against
 * Google's and Groq's terms.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Key_Pool {

	const REST    = 'y4apk_key_rest_';   // + AI + key id: ['until', 'why', 'kind' => busy | key | credit]
	const CURRENT = 'y4apk_key_current'; // [AI => key id]: the key in use

	/** The keys in a saved value: "key1, key2" — commas, semicolons, spaces or new lines between them. */
	public static function split($value) {
		return array_values(array_unique(preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY)));
	}

	/** A key's short id, for option names and logs; never the key itself. */
	public static function id($key) {
		return substr(md5((string) $key), 0, 12);
	}

	public static function mask($key) {
		return '••••' . substr((string) $key, -4);
	}

	private static function rest_name($provider, $key) {
		return self::REST . $provider . '_' . self::id($key);
	}

	/** The key's rest (['until', 'why', 'kind']), or null when it may be asked. */
	public static function resting($provider, $key) {
		$r = get_transient(self::rest_name($provider, $key));
		return is_array($r) && $r['until'] > time() ? $r : null;
	}

	/** The keys to ask, in turn: the one in use first, then the ones after it; resting keys left out. */
	public static function order($provider, array $keys) {
		$current = get_option(self::CURRENT, []);
		$at = 0;
		foreach ($keys as $i => $key) {
			if (is_array($current) && isset($current[$provider]) && $current[$provider] === self::id($key)) {
				$at = $i;
			}
		}
		$out = [];
		$n = count($keys);
		for ($step = 0; $step < $n; $step++) {
			$key = $keys[($at + $step) % $n];
			if (!self::resting($provider, $key)) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** This key answered: it stays the one in use. */
	public static function used($provider, $key) {
		$current = get_option(self::CURRENT, []);
		$current = is_array($current) ? $current : [];
		if (!isset($current[$provider]) || $current[$provider] !== self::id($key)) {
			$current[$provider] = self::id($key);
			update_option(self::CURRENT, $current, false);
		}
	}

	public static function rest($provider, $key, $seconds, $why, $kind) {
		$seconds = max(20, min(DAY_IN_SECONDS, (int) $seconds));
		set_transient(self::rest_name($provider, $key), [
			'until' => time() + $seconds,
			'why'   => self::mask($key) . ': ' . $why,
			'kind'  => $kind,
		], $seconds);
	}

	/** Forget the rests (new keys saved: every one is asked again at once). */
	public static function clear($provider, array $keys) {
		foreach ($keys as $key) {
			delete_transient(self::rest_name($provider, $key));
		}
	}

	/**
	 * Every key rests: a WP_Error as Write with AI expects one — 'busy' with the
	 * wait until the first key is back, or, when every key was refused or has
	 * no credit, that error, so the queue stops for it as it does for one key.
	 */
	public static function exhausted($provider, array $keys, $name) {
		$soonest = null;
		$refused = true;
		$why = [];
		foreach ($keys as $key) {
			$r = self::resting($provider, $key);
			if (!$r) {
				continue;
			}
			$why[] = $r['why'];
			$refused = $refused && $r['kind'] !== 'busy';
			if ($r['kind'] === 'busy' && ($soonest === null || $r['until'] < $soonest)) {
				$soonest = $r['until'];
			}
		}
		$refused = $refused && $why; // none resting any more: a rest just ended, ask again soon
		$why = mb_substr(implode(' · ', $why), 0, 600);
		if ($refused) {
			return new WP_Error('key', sprintf(__('%1$s did not accept any of its %2$d keys: %3$s', 'y4apk'), $name, count($keys), $why));
		}
		$wait = max(20, (int) $soonest - time());
		return new WP_Error('busy', sprintf(__('Every %1$s key is at its limit; the first is back in %2$s. %3$s', 'y4apk'),
			$name, human_time_diff(time(), time() + $wait), $why), ['wait' => $wait, 'status' => 429]);
	}

	/** For Settings: each key in turn, as ['key' => masked, 'state' => text]. */
	public static function describe($provider, array $keys) {
		$current = get_option(self::CURRENT, []);
		$in_use = is_array($current) && isset($current[$provider]) ? $current[$provider] : self::id(reset($keys));
		$out = [];
		foreach ($keys as $key) {
			$r = self::resting($provider, $key);
			if ($r) {
				$state = $r['kind'] === 'busy'
					? sprintf(__('at its limit, back in %s', 'y4apk'), human_time_diff(time(), $r['until']))
					: sprintf(__('set aside for %s (refused or no credit)', 'y4apk'), human_time_diff(time(), $r['until']));
			} else {
				$state = self::id($key) === $in_use ? __('in use', 'y4apk') : __('ready', 'y4apk');
			}
			$out[] = ['key' => self::mask($key), 'state' => $state];
		}
		return $out;
	}
}
