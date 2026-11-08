<?php
/**
 * Direct Link ads: a Monetag "Direct Link" (SmartLink) or any similar address,
 * opened in a new tab. Y4APK ▸ Direct Link picks how:
 *
 *   download — tapping a download button on a game page opens the link in a
 *              new tab while the visitor carries on to the download page. The
 *              game page itself is never covered, which keeps it clear of
 *              Google's "intrusive interstitial" rule for mobile results.
 *   unlock   — the full-screen "Unlock Content" box on game pages that waits
 *              for a tap on the ad, once per visit (how the theme did it
 *              before 2.13).
 *   off
 *
 * Neither runs inside the installed app: it has a single window, and an ad
 * opened from it takes that window over, so closing the ad closed the app
 * instead of leading on to the download (2.13.0).
 *
 * Front end cost: one autoloaded option and, on game pages only, a few hundred
 * bytes in the footer.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Direct_Link {

	const OPTION = 'y4apk_direct_link';
	const SLUG   = 'y4apk-direct-link';

	private static $settings = null;

	public static function init() {
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'ensure_option']);
			add_action('admin_menu', [__CLASS__, 'register_page'], 102);
			add_action('admin_post_y4apk_save_direct_link', [__CLASS__, 'handle_save']);
			return;
		}
		add_action('wp_footer', [__CLASS__, 'front'], 20);
	}

	public static function defaults() {
		return [
			'mode'  => 'download',
			// What the Unlock box opened before this setting existed.
			'url'   => home_url('/update-ads'),
			'every' => 'visit',
			'image' => 'https://y4apk.com/wp-content/uploads/2026/04/Ads.png',
			'script_lock' => 1, // Script Code is hidden until the ad is tapped (2.34)
			'links' => [],      // several links (2.43): [['id', 'url', 'name', 'weight', 'downloads', 'scripts']]; empty: the one 'url'
			'pick'  => 'random', // which link a tap opens: 'random' (by weight) or 'turn' (each in turn)
		];
	}

	/**
	 * The ad links, in order: [['id', 'url', 'name', 'weight', 'downloads', 'scripts']]. For $use
	 * 'downloads' or 'scripts', only the links used there. Before 2.43 there was one Link ('url').
	 */
	public static function links($use = '') {
		$s = self::settings();
		$list = is_array($s['links']) && $s['links'] ? $s['links'] : ((string) $s['url'] !== '' ? [['id' => 'a1', 'url' => $s['url']]] : []);
		$out = [];
		foreach ($list as $l) {
			$url = esc_url_raw(isset($l['url']) ? (string) $l['url'] : '', ['http', 'https']);
			if ($url === '') {
				continue;
			}
			$row = ['id' => isset($l['id']) ? preg_replace('/[^a-z0-9]/', '', (string) $l['id']) : 'a1', 'url' => $url, 'name' => isset($l['name']) ? (string) $l['name'] : '',
				'weight' => max(1, min(10, isset($l['weight']) ? (int) $l['weight'] : 1)), 'downloads' => !isset($l['downloads']) || !empty($l['downloads']), 'scripts' => !isset($l['scripts']) || !empty($l['scripts'])];
			if ($use === '' || $row[$use]) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** The links for the page's script: [{u, w, i}]. */
	private static function js_links($use) {
		return array_map(function ($l) { return ['u' => $l['url'], 'w' => $l['weight'], 'i' => $l['id']]; }, self::links($use));
	}

	/**
	 * JS function expression: (list) → the link to open, at random by weight or each in
	 * turn (the turn kept per browser), and counted per link in Analytics.
	 */
	public static function pick_js() {
		$turn = self::settings()['pick'] === 'turn' ? 'true' : 'false';
		return "(function (list) {"
			. " if (!list || !list.length) { return ''; }"
			. " var l = list[0];"
			. " if ($turn) { var k = 'y4apk_dl_turn', n = 0; try { n = parseInt(localStorage.getItem(k) || '0', 10) || 0; localStorage.setItem(k, String(n + 1)); } catch (e) {} l = list[n % list.length]; }"
			. " else { var t = 0, r; list.forEach(function (x) { t += x.w; }); r = Math.random() * t; for (var i = 0; i < list.length; i++) { r -= list[i].w; if (r < 0) { l = list[i]; break; } } }"
			. " if (window.y4apkTrack) { window.y4apkTrack('direct_link_n', l.i); }"
			. " return l.u; })";
	}

	public static function settings() {
		if (self::$settings === null) {
			$saved = get_option(self::OPTION, []);
			self::$settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);
		}
		return self::$settings;
	}

	/** Stored once and autoloaded, so game pages never query for a missing option. */
	public static function ensure_option() {
		if (get_option(self::OPTION, null) === null) {
			add_option(self::OPTION, self::defaults(), '', 'yes');
		}
	}

	/* ---------------------------------------------------------------- *
	 * Front end
	 * ---------------------------------------------------------------- */

	/* ---------------------------------------------------------------- *
	 * Shared with the Script Code unlock (inc/scripts.php)
	 * ---------------------------------------------------------------- */

	/**
	 * The ad for the Script Code unlock: [url, every], or null when there is
	 * nothing to tap (no link, or the lock turned off). The link is used
	 * whatever the download-button "When" mode is — the two gates are separate.
	 */
	public static function script_gate() {
		$s = self::settings();
		$links = self::js_links('scripts');
		if (!$links || empty($s['script_lock'])) {
			return null;
		}
		return [$links, in_array($s['every'], ['visit', 'day', 'always'], true) ? $s['every'] : 'visit'];
	}

	/** The in-app test, for code that cannot open a new tab (reused by the unlock). */
	public static function in_app_expr() {
		return self::in_app_js();
	}

	/**
	 * JS expression: is the page running inside the installed app? The app has
	 * a single window, so an ad opened from it takes that window over, and the
	 * close button on the ad then closes the whole app. Set by the app's boot
	 * script (which also recognises the APK by its referrer), with the
	 * display mode as a fallback for when the app is switched off.
	 */
	private static function in_app_js() {
		return "(window.__y4apkInApp || navigator.standalone === true"
			. " || (function () { try { return sessionStorage.getItem('y4apk_in_app') === '1'; } catch (e) { return false; } })()"
			. " || !!(window.matchMedia && matchMedia('(display-mode: standalone), (display-mode: fullscreen), (display-mode: minimal-ui)').matches))";
	}

	public static function front() {
		$exec = is_singular('y4apk_executor'); // an executor's page (2.44): its download buttons too
		if ((!is_singular('post') && !$exec) || get_query_var('download', null) !== null) {
			return; // game pages only, not their download pages
		}
		$s = self::settings();
		$links = self::js_links('downloads');
		if (!$links || $s['mode'] === 'off') {
			return;
		}
		if ($s['mode'] === 'unlock' && !$exec) {
			self::unlock_box($links, (string) $s['image']);
		} else {
			self::download_script($links, (string) $s['every']);
		}
	}

	/**
	 * Opens the link in a new tab when a download button (a link to this
	 * site's /download/N/ pages) is tapped; the tap itself goes on as usual.
	 * Carries the y4apk-pwa keyword and the don't-delay markers: a speed plugin
	 * that holds scripts back until the first tap would otherwise miss the tap
	 * that matters.
	 */
	private static function download_script($links, $every) {
		$every = in_array($every, ['visit', 'day', 'always'], true) ? $every : 'visit';
		?>
<script id="y4apk-pwa-direct-link" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
/* y4apk-pwa direct link — exclude from JavaScript delay */
(function () {
	var links = <?php echo wp_json_encode($links, JSON_UNESCAPED_SLASHES); ?>, pick = <?php echo self::pick_js(); ?>, every = <?php echo wp_json_encode($every); ?>, key = 'y4apk_direct_link';
	function due() {
		try {
			if (every === 'visit') return !sessionStorage.getItem(key);
			if (every === 'day') return Date.now() - (parseInt(localStorage.getItem(key) || '0', 10) || 0) > 864e5;
		} catch (e) {}
		return true;
	}
	function done() {
		try {
			if (every === 'visit') sessionStorage.setItem(key, '1');
			if (every === 'day') localStorage.setItem(key, String(Date.now()));
		} catch (e) {}
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a || !(a.hasAttribute('data-y4x-dl') || (a.host === location.host && /\/download\/\d+\/?$/.test(a.pathname)))) return;
		if (<?php echo self::in_app_js(); ?> || !due()) return; // never inside the installed app
		done();
		if (window.y4apkTrack) window.y4apkTrack('direct_link', 'download');
		window.open(pick(links), '_blank', 'noopener');
	});
})();
</script>
		<?php
	}

	/** The full-screen box, as it was in single.php up to 2.12. */
	private static function unlock_box($links, $image) {
		?>
<style>
.unlock-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);
  display:flex;justify-content:center;align-items:center;
  z-index:999999;animation:f .25s forwards;opacity:0
}
@keyframes f{to{opacity:1}}

.unlock-popup{
  background:#fff;border-radius:14px;
  padding:16px;width:90%;max-width:300px;
  text-align:center;color:#222;font-family:Arial;
  animation:p .25s forwards;transform:scale(.92);opacity:0;
  box-shadow:0 8px 25px rgba(0,0,0,.12)
}
@keyframes p{to{transform:scale(1);opacity:1}}

.unlock-popup h3{
  font-size:16px;margin-bottom:4px;color:#111
}
.unlock-popup p{
  font-size:12px;color:#666;margin-bottom:6px
}

.ad-card{
  background:#f9fbff;border-radius:10px;
  padding:10px;display:flex;gap:8px;
  align-items:center;cursor:pointer;
  margin-top:8px;border:1px solid #e3e8f0;
  transition:.2s
}
.ad-card:hover{transform:translateY(-1px);background:#f1f6ff}

.ad-thumb img{
  width:50px;height:50px;border-radius:8px;
  object-fit:cover
}

.ad-title{
  font-size:13px;font-weight:600;color:#111
}
.ad-desc{
  font-size:11px;color:#666
}

/* Progress */
.progress-bar{
  width:100%;height:5px;background:#e5eaf1;
  border-radius:5px;margin:10px 0;overflow:hidden
}
.progress{
  height:100%;width:0;
  background:linear-gradient(90deg,#4facfe,#007bff);
  transition:.3s
}

#progress-text{
  font-size:11px;color:#555
}

@media(max-width:360px){
  .unlock-popup{padding:14px;max-width:260px}
  .ad-thumb img{width:45px;height:45px}
}
</style>

<div class="unlock-overlay" id="unlockOverlay">
  <div class="unlock-popup">
    <h3>Unlock Content</h3>
    <p>Tap the ad below to continue</p>

    <div class="ad-card" id="adClick">
      <div class="ad-thumb">
        <img src="<?php echo esc_url($image); ?>">
      </div>
      <div>
        <div class="ad-title">Continue to Access</div>
        <div class="ad-desc">Fast & secure access</div>
      </div>
    </div>

    <div class="progress-bar">
      <div class="progress" id="p"></div>
    </div>
    <div id="progress-text">Progress 0/1</div>
  </div>
</div>

<script>
(function(){
  let o=document.getElementById("unlockOverlay"),
      a=document.getElementById("adClick"),
      p=document.getElementById("p"),
      t=document.getElementById("progress-text");

  if(sessionStorage.getItem("unlockDone") || <?php echo self::in_app_js(); ?>) return o.style.display="none";

  let c=0;
  a.onclick=()=>{
    if(c)return;c=1;
    if(window.y4apkTrack) window.y4apkTrack("direct_link","unlock");
    window.open((<?php echo self::pick_js(); ?>)(<?php echo wp_json_encode($links, JSON_UNESCAPED_SLASHES); ?>),"_blank");
    p.style.width="100%";
    let i=3;
    t.innerText=`Progress 1/1 (${i}s...)`;
    let s=setInterval(()=>{
      i--;t.innerText=`Progress 1/1 (${i}s...)`;
      if(i<=0){
        clearInterval(s);
        t.innerText="✔ Done!";
        sessionStorage.setItem("unlockDone","1");
        o.style.display="none";
      }
    },1000);
  }
})();
</script>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Admin: Y4APK ▸ Direct Link
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		add_submenu_page(Y4APK_MENU, __('Direct Link', 'y4apk'), __('Direct Link', 'y4apk'),
			'manage_options', self::SLUG, [__CLASS__, 'render_page']);
	}

	public static function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-save-direct-link');
		$in = wp_unslash($_POST);

		$links = [];
		foreach (isset($in['links']) && is_array($in['links']) ? $in['links'] : [] as $row) {
			$url = esc_url_raw(isset($row['url']) ? trim((string) $row['url']) : '', ['http', 'https']);
			if ($url === '' || count($links) >= 20) {
				continue;
			}
			$id = isset($row['id']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) $row['id'])) : '';
			$links[] = ['id' => $id !== '' ? substr($id, 0, 12) : strtolower(wp_generate_password(6, false, false)), 'url' => $url, 'name' => sanitize_text_field(isset($row['name']) ? (string) $row['name'] : ''),
				'weight' => max(1, min(10, isset($row['weight']) ? (int) $row['weight'] : 1)), 'downloads' => empty($row['downloads']) ? 0 : 1, 'scripts' => empty($row['scripts']) ? 0 : 1];
		}
		update_option(self::OPTION, [
			'mode'  => (isset($in['mode']) && in_array($in['mode'], ['download', 'unlock', 'off'], true)) ? $in['mode'] : 'download',
			'url'   => $links ? $links[0]['url'] : '', // the first, for anything that still reads the one Link
			'links' => $links,
			'pick'  => isset($in['pick']) && $in['pick'] === 'turn' ? 'turn' : 'random',
			'every' => (isset($in['every']) && in_array($in['every'], ['visit', 'day', 'always'], true)) ? $in['every'] : 'visit',
			'image' => esc_url_raw(isset($in['image']) ? trim((string) $in['image']) : '', ['http', 'https']),
			'script_lock' => empty($in['script_lock']) ? 0 : 1,
		], true);
		self::$settings = null;

		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php')));
		exit;
	}

	public static function render_page() {
		$s = self::settings();
		$modes = [
			'download' => [__('When a download button is tapped (recommended)', 'y4apk'),
				__('Tapping a download button on a game page opens the link in a new tab, and the visitor carries on to the download page. Nothing covers the game page, so Google sees nothing intrusive.', 'y4apk')],
			'unlock'   => [__('“Unlock Content” box on game pages', 'y4apk'),
				__('A full-screen box over every game page until the ad is tapped, once per visit. Google may rank pages covered like this lower in mobile results.', 'y4apk')],
			'off'      => [__('Off', 'y4apk'), ''],
		];
		?>
		<?php
		$links = self::links();
		$opens = self::opens_by_link();
		$total = array_sum(array_map(function ($l) use ($opens) { return (int) ($opens[$l['id']] ?? 0); }, $links));
		$rows = $links ?: [['id' => '', 'url' => '', 'name' => '', 'weight' => 1, 'downloads' => true, 'scripts' => true]];
		$on = $s['mode'] !== 'off' && $links;
		?>
		<div class="wrap y4dl y4ui">
			<?php Y4APK_Admin_UI::head([
				'icon'    => 'link',
				'tint'    => 'amber',
				'title'   => esc_html__('Direct Link', 'y4apk'),
				'sub'     => esc_html__('Opens your ad link — a Monetag Direct Link (Monetag ▸ Direct Link ▸ Copy link) or any other — in a new tab. Not inside the installed Y4APK app: the app has only one window, so the ad would take it over and closing the ad would close the app. Visitors using a browser are not affected.', 'y4apk'),
				'actions' => '<span class="y4ui-chip ' . ($on ? 'is-green' : '') . '">' . esc_html($on ? __('On', 'y4apk') : __('Off', 'y4apk')) . '</span>'
					. '<button class="button button-primary button-large" form="y4dl-form">' . esc_html__('Save', 'y4apk') . '</button>',
			]); ?>
			<?php if (!empty($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache (10Web ▸ Clear Cache) so visitors get the change.', 'y4apk'); ?></p></div>
			<?php endif; ?>
			<?php if ($s['mode'] !== 'off' && !$links) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e('No link entered, so nothing is shown.', 'y4apk'); ?></p></div>
			<?php endif; ?>
			<div class="y4ui-kpis">
				<div class="y4ui-kpi">
					<span class="y4ui-kpi-l"><?php esc_html_e('Opens, last 30 days', 'y4apk'); ?></span>
					<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($total)); ?></span>
					<span class="y4ui-kpi-s"><?php esc_html_e('counted per link in Analytics', 'y4apk'); ?></span>
				</div>
				<div class="y4ui-kpi">
					<span class="y4ui-kpi-l"><?php esc_html_e('Links', 'y4apk'); ?></span>
					<span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n(count($links))); ?> <small>/ 20</small></span>
					<span class="y4ui-kpi-s"><?php echo esc_html($s['pick'] === 'turn' ? __('each in turn', 'y4apk') : __('one at random, by weight', 'y4apk')); ?></span>
				</div>
				<div class="y4ui-kpi">
					<span class="y4ui-kpi-l"><?php esc_html_e('Opens when', 'y4apk'); ?></span>
					<span class="y4ui-kpi-n" style="font-size:18px"><?php echo esc_html($s['mode'] === 'download' ? __('A download button is tapped', 'y4apk') : ($s['mode'] === 'unlock' ? __('Unlock Content box', 'y4apk') : __('Off', 'y4apk'))); ?></span>
					<span class="y4ui-kpi-s"><?php echo esc_html(['visit' => __('once per visit', 'y4apk'), 'day' => __('once a day', 'y4apk'), 'always' => __('every tap', 'y4apk')][$s['every']] ?? ''); ?></span>
				</div>
				<div class="y4ui-kpi">
					<span class="y4ui-kpi-l"><?php esc_html_e('Script Code', 'y4apk'); ?></span>
					<span class="y4ui-kpi-n" style="font-size:18px"><?php echo esc_html(!empty($s['script_lock']) ? __('Locked until the ad', 'y4apk') : __('Shown at once', 'y4apk')); ?></span>
					<span class="y4ui-kpi-s"><?php esc_html_e('on Roblox script posts', 'y4apk'); ?></span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="y4dl-form">
				<input type="hidden" name="action" value="y4apk_save_direct_link">
				<?php wp_nonce_field('y4apk-save-direct-link'); ?>
				<div class="y4ui-cols">
					<div>
						<section class="y4ui-card">
							<div class="y4ui-card-h"><div>
								<h2><?php esc_html_e('Links', 'y4apk'); ?></h2>
								<p><?php esc_html_e('Up to 20. Each tap opens one of them: a link with weight 4 is picked four times as often as one with weight 1. Untick “Download buttons” or “Scripts” to keep a link to one place.', 'y4apk'); ?></p>
							</div><button type="button" class="button" data-add-link>+ <?php esc_html_e('Add a link', 'y4apk'); ?></button></div>
							<div class="y4ui-scroll">
							<table class="y4ui-table y4dl-links">
								<thead><tr><th><?php esc_html_e('Link', 'y4apk'); ?></th><th style="width:22%"><?php esc_html_e('Name (for you)', 'y4apk'); ?></th><th style="width:84px"><?php esc_html_e('Weight', 'y4apk'); ?></th><th style="width:96px"><?php esc_html_e('Download buttons', 'y4apk'); ?></th><th style="width:70px"><?php esc_html_e('Scripts', 'y4apk'); ?></th><th style="width:110px"><?php esc_html_e('Opens, 30 days', 'y4apk'); ?></th><th style="width:44px"></th></tr></thead>
								<tbody data-links>
									<?php foreach ($rows as $i => $l) : ?>
									<tr data-link>
										<td><input type="hidden" name="links[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr($l['id']); ?>"><input type="url" class="code" style="width:100%" name="links[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr($l['url']); ?>" placeholder="https://cawsg.com/4/1234567"></td>
										<td><input type="text" style="width:100%" name="links[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($l['name']); ?>" placeholder="Monetag 1" maxlength="40"></td>
										<td><select name="links[<?php echo (int) $i; ?>][weight]"><?php for ($w = 1; $w <= 10; $w++) : ?><option value="<?php echo (int) $w; ?>" <?php selected($l['weight'], $w); ?>><?php echo (int) $w; ?></option><?php endfor; ?></select></td>
										<td><label class="y4dl-yes"><input type="checkbox" name="links[<?php echo (int) $i; ?>][downloads]" value="1" <?php checked($l['downloads']); ?>> <?php esc_html_e('yes', 'y4apk'); ?></label></td>
										<td><label class="y4dl-yes"><input type="checkbox" name="links[<?php echo (int) $i; ?>][scripts]" value="1" <?php checked($l['scripts']); ?>> <?php esc_html_e('yes', 'y4apk'); ?></label></td>
										<td><?php echo $l['id'] !== '' ? '<b class="y4dl-opens">' . esc_html(number_format_i18n($opens[$l['id']] ?? 0)) . '</b>' : '—'; ?></td>
										<td><button type="button" class="y4dl-rm" data-remove aria-label="<?php esc_attr_e('Remove this link', 'y4apk'); ?>" title="<?php esc_attr_e('Remove this link', 'y4apk'); ?>">✕</button></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							</div>
						</section>

						<section class="y4ui-card">
							<div class="y4ui-card-h"><div>
								<h2><?php esc_html_e('When', 'y4apk'); ?></h2>
								<p><?php esc_html_e('Where the link opens on game pages.', 'y4apk'); ?></p>
							</div></div>
							<div class="y4ui-card-b">
								<div class="y4ui-opts is-three">
									<?php foreach ($modes as $value => $label) : ?>
										<label class="y4ui-opt"><input type="radio" name="mode" value="<?php echo esc_attr($value); ?>" <?php checked($s['mode'], $value); ?>><span><b><?php echo esc_html($label[0]); ?></b><?php if ($label[1]) : ?><small><?php echo esc_html($label[1]); ?></small><?php else : ?><small><?php esc_html_e('No ad link anywhere.', 'y4apk'); ?></small><?php endif; ?></span></label>
									<?php endforeach; ?>
								</div>
							</div>
						</section>
					</div>

					<aside class="y4ui-side">
						<section class="y4ui-card">
							<div class="y4ui-card-h"><div><h2><?php esc_html_e('Settings', 'y4apk'); ?></h2></div></div>
							<div class="y4ui-card-b y4ui-fields">
								<div class="y4ui-field">
									<span class="y4ui-label"><?php esc_html_e('Which link a tap opens', 'y4apk'); ?></span>
									<div class="y4ui-opts">
										<label class="y4ui-opt"><input type="radio" name="pick" value="random" <?php checked($s['pick'], 'random'); ?>><span><b><?php esc_html_e('One at random, by weight', 'y4apk'); ?></b></span></label>
										<label class="y4ui-opt"><input type="radio" name="pick" value="turn" <?php checked($s['pick'], 'turn'); ?>><span><b><?php esc_html_e('Each in turn', 'y4apk'); ?></b><small><?php esc_html_e('(the first tap opens link 1, the next link 2…, per browser; weight is not used)', 'y4apk'); ?></small></span></label>
									</div>
								</div>
								<div class="y4ui-field">
									<label for="dl-every"><?php esc_html_e('How often', 'y4apk'); ?></label>
									<select id="dl-every" name="every" style="width:100%">
										<option value="visit" <?php selected($s['every'], 'visit'); ?>><?php esc_html_e('Once per visit', 'y4apk'); ?></option>
										<option value="day" <?php selected($s['every'], 'day'); ?>><?php esc_html_e('Once a day', 'y4apk'); ?></option>
										<option value="always" <?php selected($s['every'], 'always'); ?>><?php esc_html_e('Every tap', 'y4apk'); ?></option>
									</select>
									<p class="description"><?php esc_html_e('For download buttons. A visit lasts until the browser tab is closed. The Unlock box is always once per visit.', 'y4apk'); ?></p>
								</div>
								<div class="y4ui-field">
									<span class="y4ui-label"><?php esc_html_e('Script Code', 'y4apk'); ?></span>
									<label class="y4ui-opt"><input type="checkbox" name="script_lock" value="1" <?php checked(!empty($s['script_lock'])); ?>><span><b><?php esc_html_e('Lock the script until the ad is tapped', 'y4apk'); ?></b><small><?php esc_html_e('On a Roblox script post, the code in the download section is hidden behind “Almost there — 1 step to unlock”. Tapping it opens the Link above in a new tab (the same link), then shows the code. It follows the “How often” setting, and never runs inside the installed app.', 'y4apk'); ?></small></span></label>
								</div>
								<div class="y4ui-field">
									<label for="dl-image"><?php esc_html_e('Unlock box image', 'y4apk'); ?></label>
									<input id="dl-image" type="url" class="code" style="width:100%" name="image" value="<?php echo esc_attr($s['image']); ?>">
									<p class="description"><?php esc_html_e('Only used by the Unlock box.', 'y4apk'); ?></p>
								</div>
							</div>
						</section>
					</aside>
				</div>
				<div class="y4ui-savebar">
					<button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button>
					<span class="y4ui-hint"><?php esc_html_e('After saving, clear the page cache (10Web ▸ Clear Cache) so visitors get the change.', 'y4apk'); ?></span>
				</div>
			</form>
		</div>
		<style>
		.y4dl-links td{padding-top:10px;padding-bottom:10px}
		.y4dl-yes{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
		.y4dl-opens{font-size:14px}
		.y4dl-rm{display:grid;place-items:center;width:30px;height:30px;padding:0;border:0;border-radius:8px;background:transparent;color:#b32d2e;font-size:14px;cursor:pointer}
		.y4dl-rm:hover{background:#fcf0f1}
		</style>
		<script>
		(function () {
			var body = document.querySelector('[data-links]'), n = 1000;
			document.addEventListener('click', function (e) {
				if (e.target.closest('[data-add-link]')) {
					var row = body.querySelector('[data-link]').cloneNode(true);
					n++;
					[].forEach.call(row.querySelectorAll('[name]'), function (el) {
						el.name = el.name.replace(/links\[\d+\]/, 'links[' + n + ']');
						if (el.type === 'checkbox') { el.checked = true; } else if (el.tagName === 'SELECT') { el.value = '1'; } else { el.value = ''; }
					});
					row.cells[5].textContent = '—';
					body.appendChild(row);
					row.querySelector('input[type=url]').focus();
				}
				var rm = e.target.closest('[data-remove]');
				if (rm) {
					var tr = rm.closest('tr');
					if (body.querySelectorAll('[data-link]').length > 1) { tr.remove(); } else { [].forEach.call(tr.querySelectorAll('input[type=url],input[type=text]'), function (el) { el.value = ''; }); }
				}
			});
		})();
		</script>
		<?php
	}

	/** Opens per link in the last 30 days, from Analytics: [link id => n]. */
	private static function opens_by_link() {
		global $wpdb;
		if (!class_exists('Y4APK_Stats')) {
			return [];
		}
		$t = Y4APK_Stats::table();
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) {
			return [];
		}
		$out = [];
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT dim, SUM(n) AS n FROM $t WHERE event = 'direct_link_n' AND day >= %s GROUP BY dim", wp_date('Y-m-d', time() - 29 * DAY_IN_SECONDS))) as $r) {
			$out[(string) $r->dim] = (int) $r->n;
		}
		return $out;
	}
}

Y4APK_Direct_Link::init();
