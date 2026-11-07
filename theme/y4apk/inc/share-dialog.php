<?php
/**
 * The share window (2.50.6), as the profile page has it: a card with the
 * page's picture and name, its link to copy, and Facebook, X, WhatsApp,
 * Telegram, LINE, Email — plus the device's own share sheet ("More") where
 * there is one. Used by the Roblox game pages and the game, app and script
 * posts, instead of the device's share sheet straight away.
 *
 * The same for every visitor (pages are cached): nothing about the visitor
 * is in it. Its styles and script come once per page, inline, kept out of
 * the optimiser's delay so the button works at once.
 */

defined('ABSPATH') || exit;

class Y4APK_Share_Dialog {

	private static $printed = false;

	private static function svg($path, $size = 22) {
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
	}

	private static function paths() {
		return [
			'facebook' => '<path d="M14 8h3V4h-3a5 5 0 0 0-5 5v2H7v4h2v9h4v-9h3l1-4h-4V9a1 1 0 0 1 1-1Z"/>',
			'x'        => '<path d="M18.9 1.2h3.7l-8 9.2L24 22.8h-7.4l-5.8-7.6-6.6 7.6H.5l8.6-9.8L0 1.2h7.6l5.2 6.9 6.1-6.9Zm-1.3 19.5h2L6.5 3.2H4.3l13.3 17.5Z"/>',
			'whatsapp' => '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1-1.7-.8c-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2l-.5-.3Z"/>',
			'telegram' => '<path d="M21.9 4.3 18.7 19.4c-.2 1-.9 1.3-1.7.8l-4.8-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.5 13.2l-4.7-1.5c-1-.3-1-1 .2-1.5L20.6 3c.9-.3 1.6.2 1.3 1.3Z"/>',
			'line'     => '<path d="M12 3C6.5 3 2 6.6 2 11c0 3.9 3.5 7.2 8.3 7.9.3.1.8.2.9.5.1.3.1.7 0 .9l-.1.9c0 .3-.2 1 .9.5s5.9-3.5 8.1-6A7.3 7.3 0 0 0 22 11c0-4.4-4.5-8-10-8Zm-3.8 10.4H6.3a.5.5 0 0 1-.5-.5V9a.5.5 0 0 1 1 0v3.4h1.4a.5.5 0 0 1 0 1Zm2-.5a.5.5 0 0 1-1 0V9a.5.5 0 0 1 1 0v3.9Zm4.7 0a.5.5 0 0 1-.9.3l-2-2.7v2.4a.5.5 0 0 1-1 0V9a.5.5 0 0 1 .9-.3l2 2.7V9a.5.5 0 0 1 1 0v3.9Zm3.1-2.5a.5.5 0 0 1 0 1h-1.4v.9h1.4a.5.5 0 0 1 0 1h-1.9a.5.5 0 0 1-.5-.5V9c0-.3.2-.5.5-.5h1.9a.5.5 0 0 1 0 1h-1.4v.9h1.4Z"/>',
			'email'    => '<path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>',
			'link'     => '<path d="M3.9 12A3.1 3.1 0 0 1 7 8.9h4V7H7a5 5 0 0 0 0 10h4v-1.9H7A3.1 3.1 0 0 1 3.9 12ZM8 13h8v-2H8v2Zm9-6h-4v1.9h4a3.1 3.1 0 0 1 0 6.2h-4V17h4a5 5 0 0 0 0-10Z"/>',
			'copy'     => '<path d="M8 3h11a2 2 0 0 1 2 2v11h-2V5H8V3Zm-3 4h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/>',
			'more'     => '<path d="M6 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/>',
		];
	}

	/**
	 * The window, hidden until a [data-y4sd-open="<id>"] button opens it.
	 * $a: id, heading, name, sub (under the name), image (its picture), bg (a
	 * wide picture behind the card, optional), url, text (what goes with the link).
	 */
	public static function render($a) {
		$a = array_merge(['id' => 'y4sd-share', 'heading' => __('Share', 'y4apk'), 'name' => '', 'sub' => '', 'image' => '', 'bg' => '', 'url' => '', 'text' => ''], $a);
		$url = (string) $a['url'];
		$text = (string) ($a['text'] !== '' ? $a['text'] : $a['name']);
		$e = rawurlencode($url);
		$t = rawurlencode($text);
		$p = self::paths();
		$apps = [
			'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $e],
			'x'        => ['X', 'https://twitter.com/intent/tweet?url=' . $e . '&text=' . $t],
			'whatsapp' => ['WhatsApp', 'https://wa.me/?text=' . rawurlencode($text . ' ' . $url)],
			'telegram' => ['Telegram', 'https://t.me/share/url?url=' . $e . '&text=' . $t],
			'line'     => ['LINE', 'https://social-plugins.line.me/lineit/share?url=' . $e],
			'email'    => [__('Email', 'y4apk'), 'mailto:?subject=' . $t . '&body=' . rawurlencode($text . "\n" . $url)],
		];
		$sub = $a['sub'] !== '' ? $a['sub'] : preg_replace('~^https?://(www\.)?~', '', untrailingslashit($url));
		$id = sanitize_html_class($a['id']);
		?>
		<dialog class="y4sd" id="<?php echo esc_attr($id); ?>" aria-label="<?php echo esc_attr($a['heading']); ?>">
			<div class="y4sd-head"><h2><?php echo esc_html($a['heading']); ?></h2><button type="button" class="y4sd-x" data-y4sd-close aria-label="<?php esc_attr_e('Close', 'y4apk'); ?>">×</button></div>
			<div class="y4sd-body">
				<div class="y4sd-card<?php echo $a['bg'] !== '' ? ' has-bg' : ''; ?>"<?php echo $a['bg'] !== '' ? ' style="--y4sd-bg:url(\'' . esc_url($a['bg']) . '\')"' : ''; ?>>
					<?php if ($a['image'] !== '') : ?><img src="<?php echo esc_url($a['image']); ?>" alt="" width="56" height="56" loading="lazy" decoding="async"><?php endif; ?>
					<span class="y4sd-who"><b><?php echo esc_html($a['name']); ?></b><small><?php echo esc_html($sub); ?></small></span>
				</div>
				<label class="y4sd-label" for="<?php echo esc_attr($id); ?>-url"><?php esc_html_e('Link to the page', 'y4apk'); ?></label>
				<div class="y4sd-link">
					<?php echo self::svg($p['link'], 18); ?>
					<input id="<?php echo esc_attr($id); ?>-url" type="text" value="<?php echo esc_attr($url); ?>" readonly data-y4sd-url>
					<button type="button" class="y4sd-copy" data-y4sd-copy data-done="<?php esc_attr_e('Copied', 'y4apk'); ?>"><?php echo self::svg($p['copy'], 16); ?><span><?php esc_html_e('Copy', 'y4apk'); ?></span></button>
				</div>
				<p class="y4sd-label"><?php esc_html_e('Share to', 'y4apk'); ?></p>
				<div class="y4sd-apps">
					<?php foreach ($apps as $k => $app) : ?>
					<a class="y4sd-app y4sd-to-<?php echo esc_attr($k); ?>" href="<?php echo esc_url($app[1], ['https', 'mailto']); ?>" target="_blank" rel="noopener nofollow"><span class="y4sd-ico"><?php echo self::svg($p[$k]); ?></span><small><?php echo esc_html($app[0]); ?></small></a>
					<?php endforeach; ?>
					<button type="button" class="y4sd-app y4sd-more" data-y4sd-native data-title="<?php echo esc_attr($text); ?>" hidden><span class="y4sd-ico"><?php echo self::svg($p['more']); ?></span><small><?php esc_html_e('More', 'y4apk'); ?></small></button>
				</div>
			</div>
		</dialog>
		<?php
		self::assets();
	}

	private static function assets() {
		if (self::$printed) {
			return;
		}
		self::$printed = true;
		?>
<style id="y4apk-share-dialog">
.y4sd{text-align:left;line-height:1.4;letter-spacing:normal;width:min(28rem,calc(100vw - 1.5rem));max-height:min(36rem,calc(100vh - 2rem));padding:0;border:0;border-radius:1.25rem;color:#172021;background:#fff;box-shadow:0 2rem 4rem -1rem rgba(0,0,0,.45);font-family:inherit}
.y4sd[open]{animation:y4sd-in .18s ease-out}
.y4sd::backdrop{background:rgba(10,15,25,.55);backdrop-filter:blur(3px)}
@keyframes y4sd-in{from{opacity:0;transform:translateY(.75rem) scale(.98)}to{opacity:1;transform:none}}
.y4sd-head{position:sticky;top:0;z-index:1;display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#fff;border-bottom:1px solid rgba(23,32,33,.08)}
.y4sd-head h2{margin:0;font-size:1.125rem;font-weight:700;color:#172021}
.y4sd-x{width:2rem;height:2rem;border:0;border-radius:50%;font:inherit;font-size:1.375rem;line-height:1;color:rgba(23,32,33,.6);background:#f1f4f5;cursor:pointer}
.y4sd-x:hover{background:#e6ebed}
.y4sd-body{padding:1.25rem}
.y4sd-card{position:relative;display:flex;align-items:center;gap:.875rem;overflow:hidden;padding:1rem 1.125rem;margin-bottom:1.25rem;border-radius:1rem;color:#fff;background:radial-gradient(circle at 100% 0,rgba(41,177,216,.55),transparent 60%),linear-gradient(135deg,#183f7a,#0c1730);isolation:isolate}
.y4sd-card::before{content:"";position:absolute;inset:0;z-index:-1;background:repeating-linear-gradient(115deg,rgba(255,255,255,.06) 0 2px,transparent 2px 26px)}
.y4sd-card.has-bg{background:linear-gradient(90deg,rgba(8,14,32,.92),rgba(8,14,32,.55)),var(--y4sd-bg) center/cover no-repeat}
.y4sd-card.has-bg::before{display:none}
.y4sd-card img{flex:none;width:3.5rem;height:3.5rem;border-radius:.875rem;object-fit:cover;box-shadow:0 0 0 3px #29b1d8;background:#0c1730}
.y4sd-who{min-width:0}
.y4sd-who b{display:block;overflow:hidden;font-size:1.0625rem;text-overflow:ellipsis;white-space:nowrap}
.y4sd-who small{display:block;overflow:hidden;font-size:.8125rem;text-overflow:ellipsis;white-space:nowrap;color:rgba(255,255,255,.75)}
.y4sd-label{display:block;margin:0 0 .5rem;font-size:.75rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:rgba(23,32,33,.55)}
.y4sd-link{display:flex;align-items:center;gap:.5rem;padding:.3125rem .3125rem .3125rem .875rem;margin-bottom:1.25rem;border-radius:.875rem;background:#f3f7f8;box-shadow:inset 0 0 0 1px rgba(23,32,33,.08)}
.y4sd-link>svg{flex:none;color:rgba(23,32,33,.45)}
.y4sd-link input{flex:1;min-width:0;height:2.375rem;padding:0;border:0;font:inherit;font-size:.875rem;color:#172021;background:none;outline:none;text-overflow:ellipsis;box-shadow:none}
.y4sd-copy{display:inline-flex;align-items:center;gap:.375rem;flex:none;height:2.375rem;padding:0 1rem;border:0;border-radius:.625rem;font:inherit;font-size:.875rem;font-weight:700;color:#fff;background:#29b1d8;cursor:pointer;transition:background-color .2s ease,transform .1s ease}
.y4sd-copy:hover{background:#1aa1c7}.y4sd-copy:active{transform:scale(.97)}.y4sd-copy.is-done{background:#22a35a}
.y4sd-apps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem .5rem}
.y4sd-app{display:grid;justify-items:center;gap:.375rem;padding:.25rem 0;border:0;border-radius:.875rem;font:inherit;color:#172021;background:none;cursor:pointer;text-decoration:none}
.y4sd-app[hidden]{display:none}
.y4sd-app small{font-size:.75rem;font-weight:700;color:rgba(23,32,33,.7)}
.y4sd-ico{display:grid;place-items:center;width:3rem;height:3rem;border-radius:50%;color:#fff;background:#172021;transition:transform .15s ease,box-shadow .2s ease}
.y4sd-ico svg{width:1.375rem;height:1.375rem}
.y4sd-app:hover .y4sd-ico{transform:translateY(-2px);box-shadow:0 .5rem 1rem -.375rem rgba(0,0,0,.45)}
.y4sd-to-facebook .y4sd-ico{background:#1877f2}.y4sd-to-x .y4sd-ico{background:#0f1419}.y4sd-to-whatsapp .y4sd-ico{background:#25d366}.y4sd-to-telegram .y4sd-ico{background:#2aabee}.y4sd-to-line .y4sd-ico{background:#06c755}.y4sd-to-email .y4sd-ico{background:#64748b}.y4sd-more .y4sd-ico{color:#172021;background:#e8eef0}
@media (max-width:47.99rem){.y4sd-link input{font-size:1rem}}
@media (prefers-reduced-motion:reduce){.y4sd[open]{animation:none}}
</style>
<script id="y4apk-pwa-share" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	if (window.y4apkShare) { return; }
	window.y4apkShare = 1;
	function show() {
		[].forEach.call(document.querySelectorAll('[data-y4sd-native]'), function (b) { b.hidden = !navigator.share; });
	}
	document.addEventListener('click', function (e) {
		var t = e.target, o = t.closest && t.closest('[data-y4sd-open]');
		if (o) {
			e.preventDefault();
			var d = document.getElementById(o.getAttribute('data-y4sd-open'));
			if (d) { show(); if (d.showModal) { d.showModal(); } else { d.setAttribute('open', ''); } }
			return;
		}
		var d2 = t.closest && t.closest('dialog.y4sd');
		if (!d2) { return; }
		if (t === d2 || t.closest('[data-y4sd-close]')) { d2.close ? d2.close() : d2.removeAttribute('open'); return; } // the close button, or a click on the backdrop
		var cp = t.closest('[data-y4sd-copy]');
		if (cp) {
			var inp = d2.querySelector('[data-y4sd-url]'), s = cp.querySelector('span');
			cp.was = cp.was || s.textContent;
			var done = function () { cp.classList.add('is-done'); s.textContent = cp.getAttribute('data-done'); clearTimeout(cp.t); cp.t = setTimeout(function () { cp.classList.remove('is-done'); s.textContent = cp.was; }, 1800); };
			(navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(inp.value) : Promise.reject()).then(done).catch(function () {
				inp.select(); try { if (document.execCommand('copy')) { done(); } } catch (x) {}
			});
			return;
		}
		var nat = t.closest('[data-y4sd-native]');
		if (nat && navigator.share) { navigator.share({ title: nat.getAttribute('data-title'), url: d2.querySelector('[data-y4sd-url]').value }).catch(function () {}); }
	});
	document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('[data-y4sd-url]')) { e.target.select(); } });
})();
</script>
		<?php
	}
}
