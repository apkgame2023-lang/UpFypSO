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
		];
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
		$url = esc_url_raw((string) $s['url'], ['http', 'https']);
		if ($url === '' || empty($s['script_lock'])) {
			return null;
		}
		return [$url, in_array($s['every'], ['visit', 'day', 'always'], true) ? $s['every'] : 'visit'];
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
		if (!is_singular('post') || get_query_var('download', null) !== null) {
			return; // game pages only, not their download pages
		}
		$s = self::settings();
		$url = esc_url_raw((string) $s['url']);
		if ($url === '' || $s['mode'] === 'off') {
			return;
		}
		if ($s['mode'] === 'unlock') {
			self::unlock_box($url, (string) $s['image']);
		} else {
			self::download_script($url, (string) $s['every']);
		}
	}

	/**
	 * Opens the link in a new tab when a download button (a link to this
	 * site's /download/N/ pages) is tapped; the tap itself goes on as usual.
	 * Carries the y4apk-pwa keyword and the don't-delay markers: a speed plugin
	 * that holds scripts back until the first tap would otherwise miss the tap
	 * that matters.
	 */
	private static function download_script($url, $every) {
		$every = in_array($every, ['visit', 'day', 'always'], true) ? $every : 'visit';
		?>
<script id="y4apk-pwa-direct-link" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
/* y4apk-pwa direct link — exclude from JavaScript delay */
(function () {
	var url = <?php echo wp_json_encode($url, JSON_UNESCAPED_SLASHES); ?>, every = <?php echo wp_json_encode($every); ?>, key = 'y4apk_direct_link';
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
		if (!a || a.host !== location.host || !/\/download\/\d+\/?$/.test(a.pathname)) return;
		if (<?php echo self::in_app_js(); ?> || !due()) return; // never inside the installed app
		done();
		if (window.y4apkTrack) window.y4apkTrack('direct_link', 'download');
		window.open(url, '_blank', 'noopener');
	});
})();
</script>
		<?php
	}

	/** The full-screen box, as it was in single.php up to 2.12. */
	private static function unlock_box($url, $image) {
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
    window.open(<?php echo wp_json_encode($url, JSON_UNESCAPED_SLASHES); ?>,"_blank");
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

		update_option(self::OPTION, [
			'mode'  => (isset($in['mode']) && in_array($in['mode'], ['download', 'unlock', 'off'], true)) ? $in['mode'] : 'download',
			'url'   => esc_url_raw(isset($in['url']) ? trim((string) $in['url']) : '', ['http', 'https']),
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
		<div class="wrap">
			<h1><?php esc_html_e('Direct Link', 'y4apk'); ?></h1>
			<?php if (!empty($_GET['saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache (10Web ▸ Clear Cache) so visitors get the change.', 'y4apk'); ?></p></div>
			<?php endif; ?>
			<?php if ($s['mode'] !== 'off' && !$s['url']) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e('No link entered, so nothing is shown.', 'y4apk'); ?></p></div>
			<?php endif; ?>
			<p style="max-width:860px"><?php esc_html_e('Opens your ad link — a Monetag Direct Link (Monetag ▸ Direct Link ▸ Copy link) or any other — in a new tab.', 'y4apk'); ?></p>
			<p style="max-width:860px"><?php esc_html_e('Not inside the installed Y4APK app: the app has only one window, so the ad would take it over and closing the ad would close the app. Visitors using a browser are not affected.', 'y4apk'); ?></p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_save_direct_link">
				<?php wp_nonce_field('y4apk-save-direct-link'); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="dl-url"><?php esc_html_e('Link', 'y4apk'); ?></label></th>
						<td><input id="dl-url" type="url" class="large-text code" name="url" value="<?php echo esc_attr($s['url']); ?>" placeholder="https://cawsg.com/4/1234567"></td></tr>
					<tr><th scope="row"><?php esc_html_e('When', 'y4apk'); ?></th>
						<td><fieldset>
							<?php foreach ($modes as $value => $label) : ?>
								<label style="display:block;margin:.4rem 0"><input type="radio" name="mode" value="<?php echo esc_attr($value); ?>" <?php checked($s['mode'], $value); ?>> <strong><?php echo esc_html($label[0]); ?></strong></label>
								<?php if ($label[1]) : ?><p class="description" style="margin:0 0 .6rem 1.6rem"><?php echo esc_html($label[1]); ?></p><?php endif; ?>
							<?php endforeach; ?>
						</fieldset></td></tr>
					<tr><th scope="row"><label for="dl-every"><?php esc_html_e('How often', 'y4apk'); ?></label></th>
						<td><select id="dl-every" name="every">
								<option value="visit" <?php selected($s['every'], 'visit'); ?>><?php esc_html_e('Once per visit', 'y4apk'); ?></option>
								<option value="day" <?php selected($s['every'], 'day'); ?>><?php esc_html_e('Once a day', 'y4apk'); ?></option>
								<option value="always" <?php selected($s['every'], 'always'); ?>><?php esc_html_e('Every tap', 'y4apk'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('For download buttons. A visit lasts until the browser tab is closed. The Unlock box is always once per visit.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><label for="dl-image"><?php esc_html_e('Unlock box image', 'y4apk'); ?></label></th>
						<td><input id="dl-image" type="url" class="large-text code" name="image" value="<?php echo esc_attr($s['image']); ?>">
							<p class="description"><?php esc_html_e('Only used by the Unlock box.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e('Script Code', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="script_lock" value="1" <?php checked(!empty($s['script_lock'])); ?>> <strong><?php esc_html_e('Lock the script until the ad is tapped', 'y4apk'); ?></strong></label>
							<p class="description" style="max-width:640px"><?php esc_html_e('On a Roblox script post, the code in the download section is hidden behind “Almost there — 1 step to unlock”. Tapping it opens the Link above in a new tab (the same link), then shows the code. It follows the “How often” setting, and never runs inside the installed app.', 'y4apk'); ?></p></td></tr>
				</tbody></table>
				<?php submit_button(__('Save', 'y4apk')); ?>
			</form>
		</div>
		<?php
	}
}

Y4APK_Direct_Link::init();
