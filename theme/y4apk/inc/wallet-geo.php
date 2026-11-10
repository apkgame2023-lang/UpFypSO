<?php
/**
 * Download earnings by country (2.67.1): what a creator gets per 1,000
 * downloads follows what the site's ad networks pay in each country.
 *
 * Wallet ▸ Settings' "Paid per 1,000 downloads" stays the average. Each
 * country's rate is that average times how its CPM (revenue per 1,000
 * impressions, Adsterra and Monetag together, the last 30 days) compares with
 * the CPM of all countries: a country that pays the site twice the average
 * pays creators twice the average. Few impressions from a country: its rate
 * stays near the average. The owner sets the lowest and highest it may go.
 *
 * Where the numbers come from:
 *   - Adsterra: its Publishers API, once a day (stats.json, grouped by country,
 *     the key in X-API-Key; Adsterra ▸ Profile ▸ API).
 *   - Monetag (or Adsterra): a CSV exported from its Statistics page grouped by
 *     country, uploaded in Wallet ▸ Settings.
 *
 * A download's country is Cloudflare's CF-IPCountry header; only the two
 * letters are kept, with the day and the creator (y4apk_dl_geo), never the
 * address.
 */
if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Geo {

	const OPTION = 'y4apk_geo';       // settings: on, adsterra key, lowest, highest, enough
	const DATA   = 'y4apk_geo_data';  // ['adsterra' => ['at', 'how', 'c' => [CC => [imp, rev]]], 'monetag' => …]
	const NETS   = ['adsterra' => 'Adsterra', 'monetag' => 'Monetag'];

	public static function settings() {
		$o = get_option(self::OPTION, []);
		$o = wp_parse_args(is_array($o) ? $o : [], ['on' => 0, 'adsterra_key' => '', 'low' => '0.25', 'high' => '4', 'enough' => '2000']);
		$o['adsterra_key'] = class_exists('Y4APK_Mail') ? Y4APK_Mail::reveal($o['adsterra_key']) : $o['adsterra_key'];
		if (defined('Y4APK_ADSTERRA_KEY')) {
			$o['adsterra_key'] = (string) Y4APK_ADSTERRA_KEY;
		}
		return $o;
	}

	public static function on() {
		return (bool) self::settings()['on'] && self::table();
	}

	/** The country of this visit: "TH", or '' (unknown, Tor, not through Cloudflare). */
	public static function visitor_country() {
		$c = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
		return preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, ['XX', 'T1'], true) ? $c : '';
	}

	/* ---------------------------------------------------------------- *
	 * The numbers from the networks
	 * ---------------------------------------------------------------- */

	public static function data() {
		$d = get_option(self::DATA, []);
		return is_array($d) ? $d : [];
	}

	private static function keep($net, $how, $rows) {
		$d = self::data();
		$d[$net] = ['at' => time(), 'how' => $how, 'c' => $rows];
		update_option(self::DATA, $d, false);
		delete_transient('y4apk_geo_rates');
	}

	/** Adsterra's Publishers API: the last 30 days by country. true, or an error message. */
	public static function fetch_adsterra() {
		$key = self::settings()['adsterra_key'];
		if ($key === '') {
			return __('No Adsterra API key.', 'y4apk');
		}
		$url = add_query_arg(['start_date' => wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), 'finish_date' => wp_date('Y-m-d', time() - DAY_IN_SECONDS), 'group_by' => 'country'],
			apply_filters('y4apk_adsterra_api', 'https://api3.adsterratools.com/publisher/stats.json'));
		$r = wp_remote_get($url, ['timeout' => 20, 'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json']]);
		if (is_wp_error($r)) {
			return sprintf(__('Adsterra did not answer (%s).', 'y4apk'), $r->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		if ($code === 401 || $code === 403) {
			return __('Adsterra refused the API key.', 'y4apk');
		}
		$j = json_decode((string) wp_remote_retrieve_body($r), true);
		$list = is_array($j) ? ($j['items'] ?? ($j['data'] ?? (isset($j[0]) ? $j : []))) : [];
		$rows = self::rows_from($list);
		if (!$rows) {
			return sprintf(__('Adsterra answered %d with no countries in it.', 'y4apk'), $code);
		}
		self::keep('adsterra', 'api', $rows);
		return true;
	}

	/** Rows like [{country, impression(s), revenue|profit}] → [CC => [imp, rev]]. */
	private static function rows_from($list) {
		$rows = [];
		foreach ((array) $list as $it) {
			if (!is_array($it)) {
				continue;
			}
			$it = array_change_key_case($it, CASE_LOWER);
			$cc = self::code((string) ($it['country_code'] ?? ($it['country'] ?? ($it['geo'] ?? ''))));
			$imp = self::number($it['impression'] ?? ($it['impressions'] ?? ($it['views'] ?? 0)));
			$rev = self::number($it['revenue'] ?? ($it['profit'] ?? ($it['earnings'] ?? ($it['income'] ?? 0))));
			if ($cc === '' || $imp <= 0) {
				continue;
			}
			$rows[$cc] = [($rows[$cc][0] ?? 0) + $imp, ($rows[$cc][1] ?? 0) + $rev];
		}
		return $rows;
	}

	/** A CSV a network exported, grouped by country: [CC => [imp, rev]], or an error message. */
	public static function read_csv($text) {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", (string) $text));
		$lines = array_values(array_filter(explode("\n", $text), 'strlen'));
		if (count($lines) < 2) {
			return __('The file has no rows.', 'y4apk');
		}
		$sep = ',';
		foreach ([";", "\t", ','] as $s) {
			if (substr_count($lines[0], $s) > substr_count($lines[0], $sep)) {
				$sep = $s;
			}
		}
		$head = array_map(function ($h) { return strtolower(trim($h, " \"'")); }, str_getcsv($lines[0], $sep));
		$find = function ($words) use ($head) {
			foreach ($head as $i => $h) {
				foreach ($words as $w) {
					if (strpos($h, $w) !== false) {
						return $i;
					}
				}
			}
			return -1;
		};
		$ci = $find(['country', 'geo']);
		$ii = $find(['impression', 'views']);
		$ri = $find(['revenue', 'profit', 'earning', 'income', 'payout']);
		if ($ci < 0 || $ii < 0 || $ri < 0) {
			return __('Export the statistics grouped by Country: the file needs Country, Impressions and Revenue (or Profit) columns.', 'y4apk');
		}
		$list = [];
		foreach (array_slice($lines, 1) as $line) {
			$c = str_getcsv($line, $sep);
			if (isset($c[$ci], $c[$ii], $c[$ri])) {
				$list[] = ['country' => $c[$ci], 'impressions' => $c[$ii], 'revenue' => $c[$ri]];
			}
		}
		$rows = self::rows_from($list);
		return $rows ?: __('No country in the file could be read.', 'y4apk');
	}

	/** "2,316" → 2316; "$5.397" → 5.397; "1.234,5" → 1234.5. */
	private static function number($v) {
		if (is_int($v) || is_float($v)) {
			return (float) $v;
		}
		$s = preg_replace('/[^0-9.,\-]/', '', (string) $v);
		if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
			$s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
		} elseif (strpos($s, ',') !== false) {
			$s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
		}
		return (float) $s;
	}

	/** "TH", "th", "Thailand", "Thailand (TH)" → "TH"; '' when not a country. */
	public static function code($v) {
		$v = trim((string) $v);
		if (preg_match('/^[A-Za-z]{2}$/', $v)) {
			$v = strtoupper($v);
			return in_array($v, ['XX', 'T1', 'ZZ'], true) ? '' : $v;
		}
		if (preg_match('/\(([A-Za-z]{2})\)\s*$/', $v, $m)) {
			return strtoupper($m[1]);
		}
		$names = self::names();
		$k = strtolower(preg_replace('/[^a-z]/i', '', remove_accents($v)));
		return $names[$k] ?? '';
	}

	/** Country names → codes, from the server's intl (every country), else the biggest ones. */
	private static function names() {
		static $n = null;
		if ($n !== null) {
			return $n;
		}
		$n = get_transient('y4apk_geo_names');
		if (is_array($n) && $n) {
			return $n;
		}
		$n = [];
		if (class_exists('Locale')) {
			foreach (range('A', 'Z') as $a) {
				foreach (range('A', 'Z') as $b) {
					$name = Locale::getDisplayRegion('-' . $a . $b, 'en');
					if ($name !== '' && $name !== $a . $b && !preg_match('/^Unknown/i', $name)) {
						$n[strtolower(preg_replace('/[^a-z]/i', '', remove_accents($name)))] = $a . $b;
					}
				}
			}
		}
		$n += ['unitedstates' => 'US', 'usa' => 'US', 'unitedkingdom' => 'GB', 'uk' => 'GB', 'greatbritain' => 'GB', 'russia' => 'RU', 'russianfederation' => 'RU', 'southkorea' => 'KR',
			'korearepublicof' => 'KR', 'vietnam' => 'VN', 'thailand' => 'TH', 'indonesia' => 'ID', 'philippines' => 'PH', 'india' => 'IN', 'brazil' => 'BR', 'mexico' => 'MX',
			'germany' => 'DE', 'france' => 'FR', 'japan' => 'JP', 'canada' => 'CA', 'australia' => 'AU', 'malaysia' => 'MY', 'turkey' => 'TR', 'turkiye' => 'TR', 'egypt' => 'EG',
			'pakistan' => 'PK', 'bangladesh' => 'BD', 'saudiarabia' => 'SA', 'unitedarabemirates' => 'AE', 'iran' => 'IR', 'iraq' => 'IQ', 'algeria' => 'DZ', 'morocco' => 'MA',
			'nigeria' => 'NG', 'southafrica' => 'ZA', 'argentina' => 'AR', 'colombia' => 'CO', 'peru' => 'PE', 'chile' => 'CL', 'spain' => 'ES', 'italy' => 'IT', 'poland' => 'PL',
			'ukraine' => 'UA', 'netherlands' => 'NL', 'singapore' => 'SG', 'taiwan' => 'TW', 'hongkong' => 'HK', 'china' => 'CN', 'myanmar' => 'MM', 'burma' => 'MM', 'cambodia' => 'KH',
			'laos' => 'LA', 'nepal' => 'NP', 'srilanka' => 'LK', 'kazakhstan' => 'KZ', 'uzbekistan' => 'UZ', 'venezuela' => 'VE', 'ecuador' => 'EC', 'bolivia' => 'BO', 'kenya' => 'KE'];
		set_transient('y4apk_geo_names', $n, WEEK_IN_SECONDS);
		return $n;
	}

	/* ---------------------------------------------------------------- *
	 * The rates
	 * ---------------------------------------------------------------- */

	/**
	 * ['avg' => CPM of all, 'c' => [CC => ['imp', 'rev', 'cpm', 'x' (times the average)]], 'imp' => all]
	 * from both networks together.
	 */
	public static function rates() {
		$r = get_transient('y4apk_geo_rates');
		if (is_array($r)) {
			return $r;
		}
		$s = self::settings();
		$sum = [];
		foreach (self::data() as $net) {
			foreach ((array) ($net['c'] ?? []) as $cc => $v) {
				$sum[$cc] = [($sum[$cc][0] ?? 0) + (float) $v[0], ($sum[$cc][1] ?? 0) + (float) $v[1]];
			}
		}
		$imp = array_sum(array_column($sum, 0));
		$rev = array_sum(array_column($sum, 1));
		$avg = $imp > 0 ? $rev / $imp * 1000 : 0;
		$low = max(0.05, (float) $s['low']);
		$high = max($low, (float) $s['high']);
		$enough = max(1, (float) $s['enough']);
		$c = [];
		foreach ($sum as $cc => $v) {
			$cpm = $v[0] > 0 ? $v[1] / $v[0] * 1000 : 0;
			$raw = $avg > 0 ? $cpm / $avg : 1;
			$x = 1 + ($raw - 1) * ($v[0] / ($v[0] + $enough)); // few impressions: near the average
			$c[$cc] = ['imp' => (int) $v[0], 'rev' => round($v[1], 4), 'cpm' => round($cpm, 3), 'x' => round(max($low, min($high, $x)), 3)];
		}
		uasort($c, function ($a, $b) {
			return $b['imp'] <=> $a['imp'];
		});
		$r = ['avg' => round($avg, 3), 'imp' => (int) $imp, 'c' => $c];
		set_transient('y4apk_geo_rates', $r, DAY_IN_SECONDS);
		return $r;
	}

	/** Times the average for a country (1 when unknown or not on). */
	public static function times($cc) {
		if (!self::on() || $cc === '') {
			return 1.0;
		}
		$r = self::rates();
		return isset($r['c'][$cc]) ? (float) $r['c'][$cc]['x'] : 1.0;
	}

	/* ---------------------------------------------------------------- *
	 * Counting by country
	 * ---------------------------------------------------------------- */

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'y4apk_dl_geo';
	}

	private static function table() {
		return get_option('y4apk_wallet_db') >= '2';
	}

	public static function count($day, $user_id, $cc) {
		global $wpdb;
		if (self::table()) {
			$wpdb->query($wpdb->prepare('INSERT INTO ' . self::t() . ' (day, user_id, cc, n) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1', $day, (int) $user_id, $cc));
		}
	}

	/**
	 * A creator's day of downloads in micro units, at $rate (micro per 1,000) for
	 * the average: each country's downloads at its own rate; downloads counted
	 * before the countries were (or with none known) at the average.
	 */
	public static function earned($user_id, $day, $n, $rate) {
		global $wpdb;
		if (!self::on()) {
			return intdiv((int) $n * $rate, 1000);
		}
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT cc, n FROM ' . self::t() . ' WHERE user_id = %d AND day = %s', (int) $user_id, $day));
		$known = 0;
		$total = 0.0;
		foreach ($rows as $r) {
			$known += (int) $r->n;
			$total += (int) $r->n * $rate * self::times((string) $r->cc);
		}
		$total += max(0, (int) $n - $known) * $rate; // the rest at the average
		return (int) floor($total / 1000);
	}

	/** Where a creator's downloads came from, the last 30 days: [CC => n], biggest first. */
	public static function by_country($user_id, $limit = 8) {
		global $wpdb;
		if (!self::table()) {
			return [];
		}
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT cc, SUM(n) AS n FROM ' . self::t() . ' WHERE user_id = %d AND day >= %s GROUP BY cc ORDER BY n DESC LIMIT %d',
			(int) $user_id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), (int) $limit));
		$out = [];
		foreach ($rows as $r) {
			$out[(string) $r->cc] = (int) $r->n;
		}
		return $out;
	}

	public static function name($cc) {
		if ($cc === '') {
			return __('Unknown', 'y4apk');
		}
		return class_exists('Locale') ? (Locale::getDisplayRegion('-' . $cc, determine_locale()) ?: $cc) : $cc;
	}

	/* ---------------------------------------------------------------- *
	 * Wallet ▸ Settings ▸ Pay by country
	 * ---------------------------------------------------------------- */

	/** The form: settings, the API key, a CSV, or "Get Adsterra's numbers now". Returns the note's key. */
	public static function handle() {
		$in = wp_unslash($_POST);
		$old = get_option(self::OPTION, []);
		$old = is_array($old) ? $old : [];
		$key = trim((string) ($in['adsterra_key'] ?? ''));
		$new = array_merge($old, [
			'on'     => empty($in['geo_on']) ? 0 : 1,
			'low'    => (string) max(0.05, min(1, (float) ($in['low'] ?? 0.25))),
			'high'   => (string) max(1, min(20, (float) ($in['high'] ?? 4))),
			'enough' => (string) max(100, min(1000000, (int) ($in['enough'] ?? 2000))),
		]);
		if (!empty($in['forget_key'])) {
			$new['adsterra_key'] = '';
		} elseif ($key !== '') {
			$new['adsterra_key'] = class_exists('Y4APK_Mail') ? Y4APK_Mail::hide(substr($key, 0, 200)) : substr($key, 0, 200);
		}
		update_option(self::OPTION, $new, false);
		delete_transient('y4apk_geo_rates');
		$note = 'geo_saved';
		$net = sanitize_key($in['csv_net'] ?? '');
		if (!empty($_FILES['csv']['tmp_name']) && isset(self::NETS[$net]) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
			$rows = (int) $_FILES['csv']['size'] <= 2 * MB_IN_BYTES ? self::read_csv((string) file_get_contents($_FILES['csv']['tmp_name'])) : __('The file is too big.', 'y4apk');
			if (is_array($rows)) {
				self::keep($net, 'csv', $rows);
				$note = 'geo_csv';
			} else {
				set_transient('y4apk_geo_err', $rows, MINUTE_IN_SECONDS);
				$note = 'geo_err';
			}
		}
		if (!empty($in['fetch']) || ($key !== '' && empty($in['forget_key']))) {
			$ok = self::fetch_adsterra();
			if ($ok !== true) {
				set_transient('y4apk_geo_err', $ok, MINUTE_IN_SECONDS);
				$note = 'geo_err';
			} elseif ($note !== 'geo_csv') {
				$note = 'geo_fetched';
			}
		}
		return $note;
	}

	/** Once a day, before the day's downloads are added: Adsterra's numbers again. */
	public static function daily() {
		if (self::settings()['on'] && self::settings()['adsterra_key'] !== '') {
			$r = self::fetch_adsterra();
			if ($r !== true) {
				update_option('y4apk_geo_last_err', ['at' => time(), 'why' => $r], false);
			} else {
				delete_option('y4apk_geo_last_err');
			}
		}
		global $wpdb;
		if (self::table()) {
			$wpdb->query($wpdb->prepare('DELETE FROM ' . self::t() . ' WHERE day < %s', wp_date('Y-m-d', time() - 400 * DAY_IN_SECONDS)));
		}
	}
}
