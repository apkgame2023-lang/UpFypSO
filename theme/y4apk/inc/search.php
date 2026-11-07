<?php
/**
 * Site search (2.54), the way a search engine answers: everything on Y4APK —
 * games and apps, members' own versions of them, Roblox scripts (a member's or
 * the site's), executors, articles, news and pages — ranked by how well it
 * matches, then by how much it is read.
 *
 * What counts most, in order: the whole name (the game's or app's own name,
 * or the post's title) exactly as typed; a name that starts with it; the words
 * together; every word somewhere in the name; a script of a Roblox game whose
 * name matches; the words only in the text. Popular and recent posts go up a
 * little, and a word such as "script", "executor" or "app" lifts that kind.
 * Nothing found: the closest name ("minecarft" → Minecraft) is searched
 * instead, as Google does. Members whose name matches and the matching Roblox
 * games show beside the results.
 *
 * WordPress's own search query (every post's text, newest first) is not run on
 * the search page; this one reads titles and names first, the text only up to
 * a few hundred rows, and keeps each answer for 15 minutes.
 */

defined('ABSPATH') || exit;

class Y4APK_Search {

	const PER   = 20;
	const CACHE = 900;
	const VER   = '2'; // 2.54.1: MOD features, developer, members' own words
	/** The answer for this page, once worked out. */
	public static $r = null;

	const STOP  = ['mod', 'mods', 'apk', 'apks', 'xapk', 'download', 'downloads', 'free', 'latest', 'version', 'new', 'for', 'the', 'a', 'an', 'of', 'and', 'with', 'in', 'on', 'to',
		'android', 'ios', 'game', 'games', 'app', 'apps', 'script', 'scripts', 'hack', 'hacked', 'premium', 'pro', 'full', 'unlocked', 'unlimited', 'money', 'menu', 'update', 'no', 'key', 'keyless'];

	public static function init() {
		add_filter('posts_pre_query', [__CLASS__, 'skip_main'], 10, 2);
		add_action('wp_enqueue_scripts', function () {
			if (is_search()) {
				wp_enqueue_style('y4apk-search', get_template_directory_uri() . '/assets/css/search.css', ['core'], Y4APK_THEME_VERSION);
			}
		}, 20);
	}

	/** The search page's own query is answered here (results()), not by WordPress's text search. */
	public static function skip_main($posts, $q) {
		if ($posts === null && !is_admin() && $q->is_main_query() && $q->is_search() && !wp_doing_ajax()) {
			$q->found_posts = 0;
			$q->max_num_pages = 0;
			return [];
		}
		return $posts;
	}

	/* ---------------------------------------------------------------- *
	 * Words
	 * ---------------------------------------------------------------- */

	public static function norm($s) {
		$s = html_entity_decode(wp_strip_all_tags((string) $s), ENT_QUOTES, 'UTF-8');
		$s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
		$s = preg_replace('~[^\p{L}\p{N}\p{M}]+~u', ' ', $s);
		return trim(preg_replace('~\s+~u', ' ', (string) $s));
	}

	private static function len($s) {
		return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
	}

	/** [all words, the words that decide a match (not "mod", "apk", "v2.7" …)] */
	private static function words($qn) {
		$all = array_values(array_unique(array_filter(explode(' ', $qn), 'strlen')));
		$all = array_slice($all, 0, 8);
		$keys = array_values(array_filter($all, function ($w) {
			return !in_array($w, self::STOP, true) && !preg_match('~^v?\d+$~', $w);
		}));
		return [$all, $keys ?: $all];
	}

	/** Which kind the words ask for, if any. */
	private static function intent($all) {
		foreach (['script' => ['script', 'scripts', 'roblox', 'hub', 'loadstring'], 'y4apk_executor' => ['executor', 'executors', 'injector'], 'app' => ['app', 'apps'], 'game' => ['game', 'games'],
			'articles' => ['how', 'guide', 'tutorial', 'tips']] as $kind => $hint) {
			if (array_intersect($all, $hint)) {
				return $kind;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- *
	 * Finding and ranking
	 * ---------------------------------------------------------------- */

	private static function types() {
		$t = ['post', 'page'];
		foreach (['articles', 'news', 'y4apk_executor'] as $cpt) {
			if (post_type_exists($cpt)) {
				$t[] = $cpt;
			}
		}
		return $t;
	}

	/** The categories that make a post a game, an app or a Roblox script (the list inc/suggest.php keeps). */
	private static function families() {
		static $f = null;
		if ($f !== null) {
			return $f;
		}
		$cfg = json_decode((string) get_option('y4apk_suggest_cfg', ''), true);
		$f = ['game' => [], 'app' => [], 'roblox' => [], 'names' => []];
		foreach (['game', 'app', 'roblox', 'names'] as $k) {
			if (isset($cfg[$k]) && is_array($cfg[$k])) {
				$f[$k] = $cfg[$k];
			}
		}
		if (!$f['game'] && !$f['app'] && class_exists('Y4APK_Share')) {
			foreach (Y4APK_Share::roots() as $k => $r) {
				if (!empty($r[0])) {
					$f[$k] = array_merge([(int) $r[0]->term_id], array_map('intval', (array) get_term_children($r[0]->term_id, 'category')));
				}
			}
		}
		if (!$f['roblox'] && class_exists('Y4APK_Roblox')) {
			$f['roblox'] = array_map('intval', (array) Y4APK_Roblox::categories());
		}
		return $f;
	}

	/** [kind, category name] of each post. */
	private static function kinds($rows) {
		global $wpdb;
		$ids = array_map('intval', array_keys($rows));
		if (!$ids) {
			return [];
		}
		$f = self::families();
		$cats = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			foreach ((array) $wpdb->get_results("SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id IN (" . implode(',', $chunk) . ") AND tt.taxonomy = 'category'") as $c) {
				$cats[(int) $c->object_id][] = (int) $c->term_id;
			}
		}
		$out = [];
		foreach ($rows as $id => $r) {
			$kind = $r->post_type;
			$cat = '';
			if ($kind === 'post') {
				foreach (['roblox' => 'script', 'game' => 'game', 'app' => 'app'] as $k => $as) {
					foreach ($cats[$id] ?? [] as $c) {
						if (in_array($c, array_map('intval', $f[$k]), true)) {
							$kind = $as;
							$cat = $k === 'roblox' ? '' : (string) ($f['names'][$c] ?? '');
							break 2;
						}
					}
				}
				if ($r->parent) {
					$kind = 'version';
				}
			}
			$out[$id] = [$kind, $cat];
		}
		return $out;
	}

	/** The columns every pass reads; $details adds the MOD features, the developer and a member's own words (2.54.1). */
	private static function select($details = false) {
		global $wpdb;
		return "SELECT p.ID, p.post_title, p.post_type, p.post_date, p.post_author, gp.meta_value AS gname, CAST(v.meta_value AS UNSIGNED) AS views, par.meta_value AS parent"
			. ($details ? ', md.meta_value AS mods, dv.meta_value AS dev, sh.meta_value AS share' : '') . "
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} gp ON gp.post_id = p.ID AND gp.meta_key = 'wp_title_GP'
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'wpb_post_views_count'
			LEFT JOIN {$wpdb->postmeta} par ON par.post_id = p.ID AND par.meta_key = '_y4apk_parent'"
			. ($details ? "
			LEFT JOIN {$wpdb->postmeta} md ON md.post_id = p.ID AND md.meta_key = 'wp_mods'
			LEFT JOIN {$wpdb->postmeta} dv ON dv.post_id = p.ID AND dv.meta_key = 'wp_developers_GP'
			LEFT JOIN {$wpdb->postmeta} sh ON sh.post_id = p.ID AND sh.meta_key = '_y4apk_share'" : '');
	}

	/** A member's own words about their upload (kept apart once Write with AI has written its article). */
	private static function member_words($share) {
		$d = is_string($share) && $share !== '' ? @unserialize($share, ['allowed_classes' => false]) : $share;
		return is_array($d) ? trim((string) ($d['desc'] ?? '')) : '';
	}

	/** Does the text hold every word (all of them, or any one)? */
	private static function holds($text, $keys, $all = true) {
		$t = self::norm($text);
		if ($t === '') {
			return false;
		}
		foreach ($keys as $k) {
			$in = strpos($t, $k) !== false;
			if ($all && !$in) {
				return false;
			}
			if (!$all && $in) {
				return true;
			}
		}
		return $all;
	}

	/** Every post that matches, ranked: ['items' => [[id, score, kind, cat], …], 'games' => [term ids], 'fix' => '', 'q' => '…']. */
	public static function find($q) {
		$qn = self::norm($q);
		if ($qn === '') {
			return ['items' => [], 'games' => [], 'fix' => '', 'q' => $qn];
		}
		$key = 'y4apk_s_' . md5(self::VER . '|' . $qn);
		$ext = wp_using_ext_object_cache();
		$hit = $ext ? wp_cache_get($key, 'y4apk_search') : get_transient($key);
		if (is_array($hit)) {
			return $hit;
		}
		$res = self::rank($qn);
		if (!$res['items'] && ($fix = self::closest($qn)) !== '') {
			$res = self::rank(self::norm($fix));
			$res['fix'] = $fix;
		}
		$res['q'] = $qn;
		if ($ext) {
			wp_cache_set($key, $res, 'y4apk_search', self::CACHE);
		} elseif ($res['items'] && self::len($qn) <= 40) {
			set_transient($key, $res, self::CACHE); // only answers that found something: random words from bots leave nothing behind
		}
		return $res;
	}

	private static function rank($qn) {
		global $wpdb;
		list($all, $keys) = self::words($qn);
		$types = self::types();
		$tin = implode(',', array_fill(0, count($types), '%s'));
		$like = function ($w) use ($wpdb) { return '%' . $wpdb->esc_like($w) . '%'; };
		$rows = [];
		$from = [];

		// 1. The name or the title holds every word
		$w = [];
		$a = $types;
		foreach ($keys as $k) {
			$w[] = '(p.post_title LIKE %s OR gp.meta_value LIKE %s)';
			$a[] = $like($k);
			$a[] = $like($k);
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare(self::select() . " WHERE p.post_status = 'publish' AND p.post_type IN ($tin) AND " . implode(' AND ', $w) . ' ORDER BY views DESC LIMIT 400', $a)) as $r) {
			$rows[(int) $r->ID] = $r;
			$from[(int) $r->ID] = 'name';
		}

		// 2. Scripts of a Roblox game whose name matches
		$games = [];
		if (class_exists('Y4APK_Roblox') && taxonomy_exists(Y4APK_Roblox::TAX)) {
			$w = [];
			$a = [Y4APK_Roblox::TAX];
			foreach ($keys as $k) {
				$w[] = 't.name LIKE %s';
				$a[] = $like($k);
			}
			$terms = (array) $wpdb->get_results($wpdb->prepare("SELECT t.term_id, tt.term_taxonomy_id, tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = %s AND tt.count > 0 AND " . implode(' AND ', $w) . ' ORDER BY tt.count DESC LIMIT 12', $a));
			$games = array_map(function ($t) { return (int) $t->term_id; }, $terms);
			$ttids = array_map(function ($t) { return (int) $t->term_taxonomy_id; }, $terms);
			if ($ttids) {
				foreach ((array) $wpdb->get_results(self::select() . " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE p.post_status = 'publish' AND p.post_type = 'post' AND tr.term_taxonomy_id IN (" . implode(',', $ttids) . ') ORDER BY views DESC LIMIT 200') as $r) {
					if (!isset($rows[(int) $r->ID])) {
						$rows[(int) $r->ID] = $r;
						$from[(int) $r->ID] = 'game';
					}
				}
			}
		}

		// 3. The words in the MOD features, the developer, a member's own words or the text (a few hundred posts at most)
		if (self::len(implode('', $keys)) >= 3) {
			$w = [];
			$a = $types;
			foreach ($keys as $k) {
				$w[] = '(p.post_content LIKE %s OR p.post_excerpt LIKE %s OR md.meta_value LIKE %s OR dv.meta_value LIKE %s OR sh.meta_value LIKE %s)';
				array_push($a, $like($k), $like($k), $like($k), $like($k), $like($k));
			}
			$not = $rows ? ' AND p.ID NOT IN (' . implode(',', array_map('intval', array_keys($rows))) . ')' : '';
			foreach ((array) $wpdb->get_results($wpdb->prepare(self::select(true) . " WHERE p.post_status = 'publish' AND p.post_type IN ($tin) AND " . implode(' AND ', $w) . $not . ' ORDER BY views DESC LIMIT 200', $a)) as $r) {
				$id = (int) $r->ID;
				if (isset($rows[$id])) {
					continue;
				}
				$details = trim($r->mods . ' ' . $r->dev);
				if (self::holds($details, $keys)) {
					$from[$id] = 'details';
				} elseif (self::holds(self::member_words($r->share), $keys)) {
					$from[$id] = 'words';
				} elseif ($r->share && !self::holds(get_post_field('post_content', $id) . ' ' . get_post_field('post_excerpt', $id) . ' ' . $details . ' ' . self::member_words($r->share) . ' ' . $r->post_title, $keys)) {
					continue; // the words were only in the names of the member's answers ("files", "package" …), not in anything written
				} else {
					$from[$id] = 'text';
				}
				$rows[$id] = $r;
			}
		}

		// Pages: only the ones a visitor can read (not the account and upload screens)
		foreach ($rows as $id => $r) {
			if ($r->post_type === 'page' && ($from[$id] !== 'name' || get_post_field('post_password', $id) !== '')) {
				unset($rows[$id]);
			}
		}

		$kinds = self::kinds($rows);
		$want = self::intent($all);
		$now = time();
		$out = [];
		foreach ($rows as $id => $r) {
			$best = 0;
			if ($from[$id] === 'name') {
				foreach ([[self::norm($r->gname), 1.0], [self::norm($r->post_title), .95]] as $h) {
					list($t, $weight) = $h;
					if ($t === '') {
						continue;
					}
					if ($t === $qn) {
						$s = 1000;
					} elseif (strpos($t, $qn) === 0) {
						$s = 700 + 100 * self::len($qn) / max(1, self::len($t));
					} elseif (strpos(' ' . $t, ' ' . $qn) !== false) {
						$s = 560;
					} elseif (strpos($t, $qn) !== false) {
						$s = 460;
					} else {
						$s = 0;
						$found = 0;
						foreach ($keys as $k) {
							if (strpos($t, $k) !== false) {
								$found++;
								$s += strpos(' ' . $t, ' ' . $k) !== false ? 15 : 0;
							}
						}
						if ($found === count($keys)) {
							$s += 300;
							$every = true;
							foreach ($all as $k) {
								$every = $every && strpos($t, $k) !== false;
							}
							$s += $every ? 40 : 0;
						} else {
							$s = 0;
						}
					}
					$best = max($best, $s * $weight);
				}
				$best = $best ?: 200; // a word was in the name in another form
			} elseif ($from[$id] === 'game') {
				$best = 260;
			} elseif ($from[$id] === 'details') {
				$best = 150; // in its MOD features or its developer
			} elseif ($from[$id] === 'words') {
				$best = 110; // in what the member wrote about it
			} else {
				$best = 90;
			}
			$kind = $kinds[$id][0] ?? $r->post_type;
			$best += min(90, 15 * log10(1 + (int) $r->views));
			$best += max(0, 30 - ($now - (int) strtotime($r->post_date . ' UTC')) / (30 * DAY_IN_SECONDS));
			if ($want !== '' && ($kind === $want || ($want === 'game' && $kind === 'version') || ($want === 'script' && $kind === 'script'))) {
				$best += 120;
			}
			if ($kind === 'version') {
				$best -= 25; // the main post first; the members' own versions just under it
			}
			if ($kind === 'page') {
				$best -= 40;
			}
			$out[] = [$id, round($best, 1), $kind, $kinds[$id][1] ?? ''];
		}
		usort($out, function ($a, $b) { return $b[1] <=> $a[1] ?: $b[0] <=> $a[0]; });
		return ['items' => $out, 'games' => $games, 'fix' => ''];
	}

	/** The closest known name, for a search that found nothing ("minecarft" → "Minecraft"). */
	private static function closest($qn) {
		$n = self::len($qn);
		if ($n < 3 || $n > 40 || !preg_match('~^[\x20-\x7e]+$~', $qn)) {
			return ''; // the distance below counts letters of the Latin alphabet
		}
		$names = get_transient('y4apk_s_names');
		if (!is_array($names)) {
			global $wpdb;
			$names = [];
			foreach ((array) $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = 'wp_title_GP' AND p.post_status = 'publish' LIMIT 20000") as $v) {
				$names[] = html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8');
			}
			if (class_exists('Y4APK_Roblox') && taxonomy_exists(Y4APK_Roblox::TAX)) {
				foreach ((array) get_terms(['taxonomy' => Y4APK_Roblox::TAX, 'hide_empty' => true, 'fields' => 'names', 'number' => 2000]) as $v) {
					$names[] = html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8');
				}
			}
			if (post_type_exists('y4apk_executor')) {
				foreach ((array) $wpdb->get_col("SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'y4apk_executor' AND post_status = 'publish' LIMIT 500") as $v) {
					$names[] = html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8');
				}
			}
			$names = array_values(array_unique(array_filter($names, function ($v) { return trim($v) !== '' && strlen($v) <= 80; })));
			set_transient('y4apk_s_names', $names, 12 * HOUR_IN_SECONDS);
		}
		$qw = count(explode(' ', $qn));
		$limit = $n <= 4 ? 1 : ($n <= 8 ? 2 : 3);
		$best = '';
		$bd = PHP_INT_MAX;
		foreach ($names as $name) {
			$d = self::norm($name);
			$head = implode(' ', array_slice(explode(' ', $d), 0, $qw)); // the same number of words as the search
			if ($head === '' || abs(strlen($head) - $n) > $limit || !preg_match('~^[\x20-\x7e]+$~', $head)) {
				continue;
			}
			$dist = levenshtein($qn, $head);
			if ($dist > 0 && $dist <= $limit && ($dist < $bd || ($dist === $bd && strlen($name) < strlen($best)))) {
				$bd = $dist;
				$best = $qw < count(explode(' ', $d)) ? implode(' ', array_slice(preg_split('~\s+~', trim($name)), 0, $qw)) : $name;
			}
		}
		return $best;
	}

	/* ---------------------------------------------------------------- *
	 * The page
	 * ---------------------------------------------------------------- */

	public static function labels($plural = false) {
		return $plural
			? ['game' => __('Games', 'y4apk'), 'app' => __('Apps', 'y4apk'), 'script' => __('Scripts', 'y4apk'), 'version' => __('Members’ versions', 'y4apk'), 'y4apk_executor' => __('Executors', 'y4apk'),
				'articles' => __('Articles', 'y4apk'), 'news' => __('News', 'y4apk'), 'page' => __('Pages', 'y4apk'), 'member' => __('Members', 'y4apk'), 'post' => __('Other', 'y4apk')]
			: ['game' => __('Game', 'y4apk'), 'app' => __('App', 'y4apk'), 'script' => __('Script', 'y4apk'), 'version' => __('Member version', 'y4apk'), 'y4apk_executor' => __('Executor', 'y4apk'),
				'articles' => __('Article', 'y4apk'), 'news' => __('News', 'y4apk'), 'page' => __('Page', 'y4apk'), 'post' => ''];
	}

	/** Everything the search page shows. */
	public static function results() {
		if (self::$r !== null) {
			return self::$r;
		}
		$t0 = microtime(true);
		$q = trim((string) get_search_query(false));
		$q = function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);
		$res = self::find($q);
		$people = self::people($q);
		if ($res['fix'] !== '' && $people && $people[0]['exact']) {
			$res = ['items' => [], 'games' => [], 'fix' => '', 'q' => $res['q']]; // "dez" is a member's name, not a misspelt game
		}
		$type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
		$counts = [];
		foreach ($res['items'] as $it) {
			$counts[$it[2]] = ($counts[$it[2]] ?? 0) + 1;
		}
		if ($people) {
			$counts['member'] = count($people);
		}
		if ($type !== '' && empty($counts[$type])) {
			$type = '';
		}
		$list = $type === '' ? $res['items'] : ($type === 'member' ? [] : array_values(array_filter($res['items'], function ($it) use ($type) { return $it[2] === $type; })));
		$more = [];
		if ($type === '' && !empty($counts['version'])) { // "All": two members' versions of a game, and a link to the rest (as Google folds one site's pages)
			$vids = array_map(function ($it) { return (int) $it[0]; }, array_filter($list, function ($it) { return $it[2] === 'version'; }));
			update_meta_cache('post', $vids);
			$per = [];
			$kept = [];
			foreach ($list as $it) {
				if ($it[2] === 'version') {
					$parent = (int) get_post_meta($it[0], '_y4apk_parent', true);
					$per[$parent] = ($per[$parent] ?? 0) + 1;
					if ($per[$parent] > 2) {
						$more[$parent] = ($more[$parent] ?? 0) + 1;
						continue;
					}
					if ($per[$parent] === 2) {
						$it[4] = $parent; // the "more" link goes after this one
					}
				}
				$kept[] = $it;
			}
			$list = $kept;
		}
		$paged = max(1, isset($_GET['pg']) ? (int) $_GET['pg'] : (int) get_query_var('paged')); // ?pg=2: /page/2/ would 404 (WordPress's own query is not run here)
		$pages = (int) ceil(count($list) / self::PER);
		$paged = min($paged, max(1, $pages));
		$slice = array_slice($list, ($paged - 1) * self::PER, self::PER);
		$ids = array_map(function ($it) { return (int) $it[0]; }, $slice);
		if ($ids) {
			_prime_post_caches($ids, false, true);
		}
		$words = self::words($res['fix'] !== '' ? self::norm($res['fix']) : $res['q']);
		$items = [];
		foreach ($slice as $it) {
			$row = self::item($it, $words[1]);
			if (!empty($it[4]) && !empty($more[$it[4]])) {
				$row['more'] = ['n' => $more[$it[4]], 'of' => html_entity_decode(get_post_meta($it[4], 'wp_title_GP', true) ?: get_the_title($it[4]), ENT_QUOTES, 'UTF-8')];
			}
			$items[] = $row;
		}
		$top = $paged === 1 && $type === '' && $items && $slice[0][1] >= 700 ? array_shift($items) : null;
		self::$r = [
			'q'      => $q,
			'fix'    => $res['fix'],
			'type'   => $type,
			'counts' => $counts,
			'total'  => count($res['items']) + count($people),
			'channel' => $people && $people[0]['exact'] && $type === '' && max(1, isset($_GET['pg']) ? (int) $_GET['pg'] : 1) === 1 ? $people[0] : null,
			'members' => $type === 'member' ? $people : [],
			'shown'  => $type === 'member' ? count($people) : ($type === '' ? count($res['items']) + count($people) : count($list)),
			'top'    => $top,
			'items'  => $items,
			'paged'  => $paged,
			'pages'  => $pages,
			'games'  => $paged === 1 ? self::games($res['games']) : [],
			'people' => $paged === 1 && $type === '' ? array_slice($people, 0, 6) : [],
			'words'  => $words[1],
			'time'   => microtime(true) - $t0,
		];
		return self::$r;
	}

	/** One result as the page shows it. */
	private static function item($it, $keys) {
		list($id, $score, $kind, $cat) = $it;
		$post = get_post($id);
		$name = (string) get_post_meta($id, 'wp_title_GP', true);
		$ver = '';
		if (in_array($kind, ['game', 'app', 'version'], true) && preg_match('/\d+(?:\.\d+)+|\d+/', (string) get_post_meta($id, 'wp_version_GP', true), $m)) {
			$ver = $m[0];
		}
		if ($kind === 'script' && class_exists('Y4APK_Roblox')) {
			$g = get_the_terms($id, Y4APK_Roblox::TAX);
			$cat = $g && !is_wp_error($g) ? html_entity_decode($g[0]->name, ENT_QUOTES, 'UTF-8') : $cat;
		}
		if ($kind === 'version') {
			$parent = (int) get_post_meta($id, '_y4apk_parent', true);
			$cat = $parent ? sprintf(__('A version of %s', 'y4apk'), html_entity_decode(get_post_meta($parent, 'wp_title_GP', true) ?: get_the_title($parent), ENT_QUOTES, 'UTF-8')) : $cat;
		}
		$by = null;
		if (in_array($kind, ['script', 'version', 'game', 'app'], true) && class_exists('Y4APK_Members') && ($u = Y4APK_Members::author($id))) {
			$by = ['name' => class_exists('Y4APK_Social') ? Y4APK_Social::name($u) : $u->user_login, 'login' => $u->user_login, 'url' => Y4APK_Members::profile_url($u),
				'avatar' => class_exists('Y4APK_Social') ? Y4APK_Social::avatar($u, 20) : ''];
		}
		$text = has_excerpt($id) ? $post->post_excerpt : $post->post_content;
		$words = self::member_words(get_post_meta($id, '_y4apk_share', true));
		if ($words !== '' && !self::holds(wp_strip_all_tags($text), $keys, false) && self::holds($words, $keys, false)) {
			$text = $words; // the article has none of the words; what the member wrote has
		}
		$mods = trim(wp_strip_all_tags((string) get_post_meta($id, 'wp_mods', true)));
		return [
			'id'      => $id,
			'kind'    => $kind,
			'title'   => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
			'name'    => $name !== '' ? html_entity_decode($name, ENT_QUOTES, 'UTF-8') : '',
			'url'     => get_permalink($id),
			'icon'    => function_exists('y4apk_icon_url') ? (string) y4apk_icon_url($id, 'thumbnail') : (string) get_the_post_thumbnail_url($id, 'thumbnail'),
			'cat'     => $cat,
			'ver'     => $ver,
			'by'      => $by,
			'snippet' => self::snippet($text, $keys),
			'date'    => get_post_modified_time('U', true, $id),
			'views'   => (int) get_post_meta($id, 'wpb_post_views_count', true),
			'mods'    => in_array($kind, ['game', 'app', 'version'], true) && !in_array(strtolower($mods), ['', 'original', '-', '—'], true) ? html_entity_decode(wp_trim_words($mods, 14), ENT_QUOTES, 'UTF-8') : '',
		];
	}

	/** About 170 letters of the text around the first word searched. */
	private static function snippet($html, $keys) {
		$text = trim(preg_replace('~\s+~u', ' ', html_entity_decode(wp_strip_all_tags(strip_shortcodes((string) $html)), ENT_QUOTES, 'UTF-8')));
		if ($text === '') {
			return '';
		}
		$at = -1;
		foreach ($keys as $k) {
			$p = function_exists('mb_stripos') ? mb_stripos($text, $k, 0, 'UTF-8') : stripos($text, $k);
			if ($p !== false && ($at < 0 || $p < $at)) {
				$at = (int) $p;
			}
		}
		$from = $at > 60 ? $at - 60 : 0;
		$part = function_exists('mb_substr') ? mb_substr($text, $from, 170, 'UTF-8') : substr($text, $from, 170);
		if ($from > 0) {
			$part = '…' . preg_replace('~^\S*\s~u', '', $part);
		}
		if (self::len($text) > $from + 170) {
			$part = preg_replace('~\s\S*$~u', '', $part) . '…';
		}
		return $part;
	}

	/** Text with the words searched in bold (escaped). */
	public static function mark($text, $keys) {
		$h = esc_html($text);
		$keys = array_filter($keys, function ($k) { return self::len($k) >= 2; });
		usort($keys, function ($a, $b) { return self::len($b) <=> self::len($a); });
		if (!$keys) {
			return $h;
		}
		$re = '~(' . implode('|', array_map(function ($k) { return preg_quote(esc_html($k), '~'); }, $keys)) . ')~iu';
		return (string) preg_replace($re, '<mark>$1</mark>', $h);
	}

	/** The Roblox games whose name matches: their pages, with a picture and the number of scripts. */
	private static function games($ids) {
		$out = [];
		foreach (array_slice($ids, 0, 6) as $id) {
			$t = get_term($id);
			if ($t && !is_wp_error($t)) {
				$out[] = ['name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'url' => get_term_link($t), 'icon' => class_exists('Y4APK_Roblox') ? Y4APK_Roblox::icon_url($t) : '', 'count' => (int) $t->count];
			}
		}
		return $out;
	}

	/** Members whose name or @name matches (members only, never the site's own accounts), their channel's details. */
	private static function people($q, $max = 20) {
		if (!class_exists('Y4APK_Members') || self::len(trim($q)) < 2) {
			return [];
		}
		$q = ltrim(trim($q), '@');
		$qn = self::norm($q);
		$found = [];
		$args = ['role' => Y4APK_Members::ROLE, 'number' => $max, 'orderby' => 'post_count', 'order' => 'DESC'];
		foreach ([
			$args + ['search' => '*' . $q . '*', 'search_columns' => ['user_login', 'user_nicename', 'display_name']],
			$args + ['meta_query' => [['key' => class_exists('Y4APK_Social') ? Y4APK_Social::NAME : 'y4apk_name', 'value' => $q, 'compare' => 'LIKE']]],
		] as $a) {
			foreach (get_users($a) as $u) {
				if (!isset($found[$u->ID]) && Y4APK_Members::is_public($u)) {
					$found[$u->ID] = $u;
				}
			}
		}
		$S = class_exists('Y4APK_Social');
		$out = [];
		foreach ($found as $u) {
			$name = $S ? Y4APK_Social::name($u) : $u->user_login;
			$exact = strtolower($u->user_login) === strtolower($q) || self::norm($name) === $qn;
			$out[] = ['name' => $name, 'login' => $u->user_login, 'url' => Y4APK_Members::profile_url($u),
				'avatar' => $S ? Y4APK_Social::avatar($u, 40) : '', 'big' => $exact && $S ? Y4APK_Social::avatar($u, 88) : '',
				'posts' => (int) count_user_posts($u->ID, 'post', true), 'followers' => $S ? (int) Y4APK_Social::followers_count($u->ID) : 0,
				'bio' => $exact && $S ? wp_trim_words(wp_strip_all_tags((string) Y4APK_Social::bio($u)), 24) : '', 'exact' => $exact];
		}
		usort($out, function ($a, $b) { return ($b['exact'] <=> $a['exact']) ?: ($b['followers'] <=> $a['followers']) ?: ($b['posts'] <=> $a['posts']); });
		return array_slice($out, 0, $max);
	}

	/** The search page's address for a kind (and the same words). */
	public static function url($type = '', $q = null, $page = 1) {
		$args = ['s' => $q === null ? get_search_query(false) : $q];
		if ($type !== '') {
			$args['type'] = $type;
		}
		if ($page > 1) {
			$args['pg'] = (int) $page;
		}
		return add_query_arg(array_map('rawurlencode', $args), home_url('/'));
	}

	/** A short number: 1.2K, 3.4M. */
	public static function short($n) {
		$n = (int) $n;
		return $n >= 1000000 ? round($n / 1000000, 1) . 'M' : ($n >= 1000 ? round($n / 1000, 1) . 'K' : (string) $n);
	}
}

Y4APK_Search::init();
