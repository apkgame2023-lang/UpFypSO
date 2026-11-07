<?php
/**
 * Search suggestions (2.44): as a visitor types in the search box, the games,
 * apps, scripts, Roblox games and articles that match, with their icons, show
 * under it; before they type, their own recent searches and what is popular.
 *
 * Pages stay the same for everyone (the page cache), so the box asks suggest.php
 * (in the theme's folder) as the visitor types: WordPress with SHORTINIT
 * (database only), a LIKE on the titles of published posts, a few rows, sent
 * with Cache-Control so Cloudflare keeps each answer for a while. What it needs
 * that only WordPress knows — the address of each kind of page, which categories
 * are games, apps or Roblox, the uploads folder, the Roblox games, the popular
 * posts — is kept here in two options, written again every hour and whenever
 * they change. Recent searches stay in the visitor's browser (localStorage);
 * nothing about searches is sent anywhere else or kept on the server.
 */

defined('ABSPATH') || exit;

class Y4APK_Suggest {
	const CFG  = 'y4apk_suggest_cfg';  // JSON: what suggest.php needs to build addresses and kinds
	const TOP  = 'y4apk_suggest_top';  // JSON: popular items and the Roblox games, ready to show
	const HOOK = 'y4apk_suggest_refresh';

	public static function init() {
		add_action('init', [__CLASS__, 'schedule']);
		add_action(self::HOOK, [__CLASS__, 'refresh']);
		add_action('wp_footer', [__CLASS__, 'script'], 30);
		foreach (['created_category', 'edited_category', 'delete_category', 'created_' . 'roblox_game', 'edited_roblox_game', 'switch_theme', 'customize_save_after', 'permalink_structure_changed'] as $h) {
			add_action($h, [__CLASS__, 'later']);
		}
		add_action('transition_post_status', function ($new, $old) {
			if ($new === 'publish' || $old === 'publish') {
				self::later();
			}
		}, 10, 2);
	}

	public static function schedule() {
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + 60, 'hourly', self::HOOK);
		}
		if (get_option(self::CFG, '') === '') {
			self::refresh(); // the first time: now
		}
	}

	/** Something changed: written again in a minute (once, however many changes). */
	public static function later() {
		if (!wp_next_scheduled(self::HOOK, ['once'])) {
			wp_schedule_single_event(time() + 60, self::HOOK, ['once']);
		}
	}

	/** Category IDs with their children. */
	private static function family($ids) {
		$out = [];
		foreach ((array) $ids as $id) {
			if ($id) {
				$out[] = (int) $id;
				$out = array_merge($out, array_map('intval', (array) get_term_children((int) $id, 'category')));
			}
		}
		return array_values(array_unique($out));
	}

	public static function refresh($why = '') {
		if (class_exists('Y4APK_I18n')) { // written for every language: in the site's own words and addresses (2.51)
			return Y4APK_I18n::untranslated(function () use ($why) {
				return self::write($why);
			});
		}
		return self::write($why);
	}

	private static function write($why = '') {
		$roots = class_exists('Y4APK_Share') ? Y4APK_Share::roots() : ['game' => [get_category_by_slug('games'), []], 'app' => [get_category_by_slug('apps'), []]];
		$names = [];
		foreach (get_categories(['hide_empty' => false]) as $t) {
			$names[(int) $t->term_id] = html_entity_decode($t->name, ENT_QUOTES, 'UTF-8');
		}
		$types = ['post' => ''];
		foreach (['articles', 'news', 'y4apk_executor'] as $cpt) {
			if (post_type_exists($cpt)) {
				$o = get_post_type_object($cpt);
				$types[$cpt] = is_array($o->rewrite) && !empty($o->rewrite['slug']) ? trim($o->rewrite['slug'], '/') : $cpt;
			}
		}
		$up = wp_get_upload_dir();
		$cfg = [
			'home'   => untrailingslashit(home_url()),
			'perma'  => (string) get_option('permalink_structure'),
			'types'  => $types,
			'up'     => untrailingslashit($up['baseurl']),
			'game'   => self::family($roots['game'][0] ? [$roots['game'][0]->term_id] : []),
			'app'    => self::family($roots['app'][0] ? [$roots['app'][0]->term_id] : []),
			'roblox' => class_exists('Y4APK_Roblox') ? array_map('intval', Y4APK_Roblox::categories()) : [],
			'names'  => $names,
		];
		update_option(self::CFG, wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false);

		// Popular: the most read posts of the last year, and the Roblox games by their scripts
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'wpb_post_views_count'
			LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_y4apk_parent'
			WHERE p.post_type = 'post' AND p.post_status = 'publish' AND k.post_id IS NULL AND p.post_modified > %s ORDER BY CAST(v.meta_value AS UNSIGNED) DESC LIMIT 8", gmdate('Y-m-d H:i:s', time() - YEAR_IN_SECONDS)));
		$top = [];
		foreach ($ids as $id) {
			$top[] = self::item((int) $id, $cfg);
		}
		$games = [];
		if (class_exists('Y4APK_Roblox') && taxonomy_exists(Y4APK_Roblox::TAX)) {
			foreach (get_terms(['taxonomy' => Y4APK_Roblox::TAX, 'hide_empty' => true, 'number' => 400, 'orderby' => 'count', 'order' => 'DESC']) as $t) {
				if (is_object($t)) {
					$games[] = ['t' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'u' => get_term_link($t), 'i' => Y4APK_Roblox::icon_url($t), 'n' => (int) $t->count, 'k' => 'rbx'];
				}
			}
		}
		update_option(self::TOP, wp_json_encode(['top' => $top, 'games' => $games], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false);
	}

	/** One post as the box shows it (as suggest.php builds them). */
	private static function item($id, $cfg) {
		$cats = wp_get_post_categories($id);
		$kind = get_post_type($id) === 'post' ? 'post' : get_post_type($id);
		$cat = '';
		foreach (['roblox' => 'script', 'game' => 'game', 'app' => 'app'] as $k => $as) {
			foreach ($cats as $c) {
				if (in_array((int) $c, $cfg[$k], true)) {
					$kind = $as;
					$cat = $k === 'roblox' ? '' : ($cfg['names'][(int) $c] ?? '');
					break 2;
				}
			}
		}
		$name = html_entity_decode((string) get_post_meta($id, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8');
		return ['t' => html_entity_decode(wp_strip_all_tags(get_the_title($id)), ENT_QUOTES, 'UTF-8'), 'n' => $name, 'u' => get_permalink($id), 'i' => (string) get_the_post_thumbnail_url($id, 'thumbnail'),
			'v' => class_exists('Y4APK_App_Updates') ? Y4APK_App_Updates::number(get_post_meta($id, 'wp_version_GP', true)) : '', 'k' => $kind, 'c' => $cat];
	}

	/** The box's script and words, on every page (the same for everyone). */
	public static function script() {
		$cfg = [
			'url' => get_template_directory_uri() . '/suggest.php',
			'search' => home_url('/'),
			// The list is made in the background, without a language: on a /th/ page its links get /th/ here (2.51)
			'base' => class_exists('Y4APK_I18n') ? Y4APK_I18n::original_url(home_url('/')) : home_url('/'),
			'T' => [
				'recent'  => __('Recent searches', 'y4apk'),
				'clear'   => __('Clear', 'y4apk'),
				'popular' => __('Popular now', 'y4apk'),
				'games'   => __('Roblox games', 'y4apk'),
				'results' => __('Games, apps and scripts', 'y4apk'),
				'all'     => __('See all results for “%s”', 'y4apk'),
				'none'    => __('No quick matches. Press Enter to search everything.', 'y4apk'),
				'kinds'   => ['game' => __('Game', 'y4apk'), 'app' => __('App', 'y4apk'), 'script' => __('Script', 'y4apk'), 'rbx' => __('Roblox game', 'y4apk'), 'articles' => __('Article', 'y4apk'), 'news' => __('News', 'y4apk'), 'y4apk_executor' => __('Executor', 'y4apk'), 'post' => ''],
				'scripts' => __('%d scripts', 'y4apk'),
				'remove'  => __('Remove', 'y4apk'),
			],
		];
		?>
<script id="y4apk-pwa-suggest" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var C = <?php echo wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>, T = C.T, LS = 'y4apk_recent';
	if (!window.fetch || !document.querySelector) { return; }
	var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
	var recent = function () { try { var r = JSON.parse(localStorage.getItem(LS) || '[]'); return Array.isArray(r) ? r.filter(function (x) { return typeof x === 'string'; }).slice(0, 8) : []; } catch (e) { return []; } };
	var remember = function (q) { q = String(q || '').trim().slice(0, 60); if (q.length < 2) { return; } try { var r = recent().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); }); r.unshift(q); localStorage.setItem(LS, JSON.stringify(r.slice(0, 8))); } catch (e) {} };
	var forget = function (q) { try { localStorage.setItem(LS, JSON.stringify(q === null ? [] : recent().filter(function (x) { return x !== q; }))); } catch (e) {} };
	var cache = {}, home = null;
	var here = function (u) { u = String(u || ''); return C.base === C.search || u.indexOf(C.search) === 0 || u.indexOf(C.base) !== 0 ? u : C.search + u.slice(C.base.length); };
	var get = function (q, signal) {
		if (cache[q]) { return Promise.resolve(cache[q]); }
		return fetch(C.url + '?q=' + encodeURIComponent(q), { signal: signal, credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) { cache[q] = d; return d; });
	};
	var mark = function (text, q) {
		var h = esc(text), words = q.toLowerCase().split(/\s+/).filter(Boolean).sort(function (a, b) { return b.length - a.length; });
		words.forEach(function (w) { var re = new RegExp('(' + esc(w).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'); h = h.replace(re, '\u0001$1\u0002'); });
		return h.replace(/\u0001/g, '<mark>').replace(/\u0002/g, '</mark>');
	};
	var icon = function (x) {
		if (x.i) { return '<img src="' + esc(x.i) + '" alt="" width="44" height="44" loading="lazy" decoding="async">'; }
		var t = x.n || x.t || '?', h = 0; for (var i = 0; i < t.length; i++) { h = (h * 31 + t.charCodeAt(i)) % 360; }
		return '<span class="y4sg-letter" style="background:hsl(' + h + ',55%,46%)">' + esc(Array.from(t)[0].toUpperCase()) + '</span>';
	};
	var row = function (x, q, n) {
		var kind = T.kinds[x.k] || '', meta = [];
		if (kind) { meta.push('<span class="y4sg-kind y4sg-kind-' + esc(x.k) + '">' + esc(kind) + '</span>'); }
		if (x.c) { meta.push(esc(x.c)); }
		if (x.v) { meta.push('v' + esc(x.v)); }
		if (x.k === 'rbx' && x.n) { meta.push(esc(T.scripts.replace('%d', x.n))); }
		return '<a class="y4sg-item" href="' + esc(here(x.u)) + '" role="option" data-y4sg-i>' + (n ? '<b class="y4sg-rank">' + n + '</b>' : '') + '<span class="y4sg-ico">' + icon(x) + '</span>' +
			'<span class="y4sg-text"><b>' + (q ? mark(x.t, q) : esc(x.t)) + '</b><small>' + meta.join('<i>·</i>') + '</small></span>' +
			'<svg class="y4sg-go" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M13.2 5.3 19.9 12l-6.7 6.7-1.4-1.4 4.3-4.3H4v-2h12.1l-4.3-4.3 1.4-1.4Z"/></svg></a>';
	};
	function attach(input) {
		var form = input.form; if (!form || input.y4sg) { return; }
		input.y4sg = true; input.setAttribute('autocomplete', 'off'); input.removeAttribute('minlength');
		var box = document.createElement('div'); box.className = 'y4sg'; box.setAttribute('role', 'listbox'); box.hidden = true;
		(input.parentNode.nextSibling ? input.parentNode.parentNode.insertBefore(box, input.parentNode.nextSibling) : input.parentNode.parentNode.appendChild(box));
		input.setAttribute('role', 'combobox'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-autocomplete', 'list');
		var timer = 0, ctl = null, act = -1, last = null;
		var items = function () { return [].slice.call(box.querySelectorAll('[data-y4sg-i]')); };
		var show = function (html) { box.innerHTML = html; box.hidden = !html; input.setAttribute('aria-expanded', html ? 'true' : 'false'); act = -1; };
		function idle() {
			var r = recent(), h = '';
			if (r.length) {
				h += '<div class="y4sg-head"><span>' + esc(T.recent) + '</span><button type="button" class="y4sg-clear" data-y4sg-clear>' + esc(T.clear) + '</button></div><div class="y4sg-chips">' +
					r.map(function (q) { return '<span class="y4sg-chip"><a href="' + esc(C.search + '?s=' + encodeURIComponent(q)) + '" data-y4sg-i data-q="' + esc(q) + '"><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.2 3.2.8-1.3-4.5-2.7V7Z"/></svg>' + esc(q) + '</a><button type="button" data-y4sg-del="' + esc(q) + '" aria-label="' + esc(T.remove) + '">×</button></span>'; }).join('') + '</div>';
			}
			var put = function (d) {
				if (input.value.trim() !== '') { return; }
				var p = d && d.top ? d.top.slice(0, 6) : [];
				show(h + (p.length ? '<div class="y4sg-head"><span><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M13.5 1s.7 2.5.7 4.5c0 1.9-1.3 3.5-3.2 3.5S7.7 7.4 7.7 5.5l.1-.9A11.3 11.3 0 0 0 5 12a7 7 0 0 0 14 0c0-5-2.4-9.5-5.5-11ZM11.7 19a2.9 2.9 0 0 1-2.9-2.8c0-1.4.9-2.4 2.4-2.7 1.5-.3 3.1-1 4-2.3.3 1.1.5 2.2.5 3.4A4 4 0 0 1 11.7 19Z"/></svg>' + esc(T.popular) + '</span></div>' + p.map(function (x, i) { return row(x, '', i + 1); }).join('') : ''));
			};
			if (home) { put(home); } else { put(null); get('').then(function (d) { home = d; put(d); }).catch(function () {}); }
		}
		function run() {
			var q = input.value.trim().replace(/\s+/g, ' ');
			if (q === '') { return idle(); }
			if (ctl) { ctl.abort(); }
			ctl = window.AbortController ? new AbortController() : null;
			box.classList.add('is-busy');
			get(q.toLowerCase(), ctl && ctl.signal).then(function (d) {
				if (input.value.trim().replace(/\s+/g, ' ') !== q) { return; }
				box.classList.remove('is-busy'); last = q;
				var h = '';
				if (d.games && d.games.length) { h += '<div class="y4sg-head"><span>' + esc(T.games) + '</span></div>' + d.games.map(function (x) { return row(x, q); }).join(''); }
				if (d.items && d.items.length) { h += '<div class="y4sg-head"><span>' + esc(T.results) + '</span></div>' + d.items.map(function (x) { return row(x, q); }).join(''); }
				if (!h) { h = '<p class="y4sg-none">' + esc(T.none) + '</p>'; }
				h += '<a class="y4sg-all" href="' + esc(C.search + '?s=' + encodeURIComponent(q)) + '" data-y4sg-i data-q="' + esc(q) + '"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 0 1 6 12l4.7 4.8-1.4 1.4-4.8-4.7A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg><span>' + esc(T.all).replace('%s', '<b>' + esc(q) + '</b>') + '</span><kbd>Enter</kbd></a>';
				show(h);
			}).catch(function (e) { if (!e || e.name !== 'AbortError') { box.classList.remove('is-busy'); } });
		}
		var move = function (d) { var it = items(); if (!it.length) { return; } act = (act + d + it.length) % it.length; it.forEach(function (x, i) { x.classList.toggle('is-act', i === act); }); it[act].scrollIntoView({ block: 'nearest' }); };
		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, input.value.trim() ? 140 : 0); });
		input.addEventListener('focus', function () { if (box.hidden) { run(); } });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); if (box.hidden) { run(); } else { move(1); } }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter' && act > -1) { var it = items()[act]; if (it) { e.preventDefault(); remember(it.getAttribute('data-q') || input.value); location.href = it.href; } }
			else if (e.key === 'Escape' && !box.hidden) { show(''); }
		});
		box.addEventListener('mousedown', function (e) { if (e.target.closest('button')) { e.preventDefault(); } });
		box.addEventListener('click', function (e) {
			var del = e.target.closest('[data-y4sg-del]'), clr = e.target.closest('[data-y4sg-clear]'), a = e.target.closest('a');
			if (del) { forget(del.getAttribute('data-y4sg-del')); idle(); input.focus(); return; }
			if (clr) { forget(null); idle(); input.focus(); return; }
			if (a) { remember(a.getAttribute('data-q') || input.value); }
		});
		form.addEventListener('submit', function () { remember(input.value); });
		document.addEventListener('click', function (e) { if (!box.hidden && !form.contains(e.target)) { show(''); } });
	}
	var go = function () { [].forEach.call(document.querySelectorAll('form[role="search"] input[name="s"], .search-box input[name="s"]'), attach); };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', go); } else { go(); }
})();
</script>
		<?php
	}
}

Y4APK_Suggest::init();
