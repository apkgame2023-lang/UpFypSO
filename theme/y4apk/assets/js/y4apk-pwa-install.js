/*
 * Y4APK "install the app" banner.
 *
 * Which banner, if any, depends on what the visitor's browser can do:
 *   - Chrome/Edge/Samsung Internet raise `beforeinstallprompt` → one-tap install.
 *     The boot script in <head> captures that event early (it fires before this
 *     file loads, and optimisers may delay this file further).
 *   - iOS Safari has no install API → explain Share ▸ Add to Home Screen.
 *   - Android browsers that cannot install apps, which includes the in-app
 *     browsers of TikTok, Facebook and LINE → link to the APK, if one is set.
 *
 * Visitors who already have the app are not asked to install it again. Inside
 * the app: nothing. In the browser on Android, if the site allows it, a banner
 * on the same schedule that leaves the choice to them — nothing opens by itself:
 *   - the APK is on the phone → an Open button that hands this page to it;
 *   - the web app is installed → a reminder that it is on the home screen, since
 *     browsers give pages no way to open it.
 * Chrome reports both through getInstalledRelatedApps(), from the manifest's
 * related_applications, and stops reporting an app once it is uninstalled, so
 * the install banner comes back by itself.
 *
 * Closing the banner hides it for cfg.again minutes, on every page. After that it
 * returns on the next page, or on this one if the visitor is still reading it.
 *
 * Config arrives as window.Y4APK_PWA, set by the boot script in <head> (see
 * inc/pwa.php). No jQuery: it must run on pages where the theme does not load
 * its own scripts.
 */
(function () {
	'use strict';

	var cfg = window.Y4APK_PWA;
	if (!cfg || !cfg.title) return;

	var CLOSED_KEY = 'y4apk_install_dismissed'; // when it was last closed (same key and value as before 2.11)
	var STARTED_KEY = 'y4apk_install_started';  // when the visitor tapped Install or Download
	var AGAIN_MS = Math.max(0, cfg.again || 0) * 60000;
	var STARTED_MS = Math.max(AGAIN_MS, 10 * 60000); // time to finish installing
	var LONGEST_WAIT = 60 * 60000; // beyond this, wait for the next page instead

	function load(key) {
		try { return parseInt(window.localStorage.getItem(key) || '0', 10) || 0; } catch (e) { return 0; }
	}
	function save(key, value) {
		try { window.localStorage.setItem(key, String(value)); } catch (e) {}
	}

	// Worked out by the boot script in <head>, which also recognises the APK.
	function inApp() {
		return !!window.__y4apkInApp ||
			!!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
	}
	if (inApp()) return;

	var ua = window.navigator.userAgent || '';
	var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var isIOSSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|FBAN|FBAV|Instagram|Line\/|TikTok|musical_ly/i.test(ua);
	var isAndroid = /Android/i.test(ua);

	var banner = null;
	var bannerMode = '';
	var started = false;    // the first delay is over
	var closedHere = false; // closed on this page (matters when cfg.again is 0)
	var timer = 0;

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) node.className = cls;
		if (text) node.textContent = text;
		return node;
	}

	/** Milliseconds until the banner may show again; 0 = now. */
	function waitLeft() {
		var now = Date.now();
		var closed = load(CLOSED_KEY);
		var tapped = load(STARTED_KEY);
		return Math.max(0,
			closed ? closed + AGAIN_MS - now : 0,
			tapped ? tapped + STARTED_MS - now : 0);
	}

	function hide() {
		if (!banner) return;
		var old = banner;
		banner = null;
		bannerMode = '';
		old.classList.remove('y4apk-install--in');
		setTimeout(function () { old.remove(); }, 250);
	}

	function close() {
		save(CLOSED_KEY, Date.now());
		closedHere = true;
		hide();
		if (AGAIN_MS) later(AGAIN_MS);
	}

	function installing() {
		save(STARTED_KEY, Date.now());
		hide();
	}

	function later(ms) {
		clearTimeout(timer);
		if (ms > LONGEST_WAIT) return;
		timer = setTimeout(function () {
			if (!document.hidden) return check();
			document.addEventListener('visibilitychange', function onVisible() {
				if (document.hidden) return;
				document.removeEventListener('visibilitychange', onVisible);
				check();
			});
		}, ms + 500);
	}

	/** Hands the current page to the installed APK; back here if that fails. */
	function appLink() {
		var l = window.location;
		return 'intent://' + l.host + l.pathname + l.search + '#Intent;scheme=https;package=' + cfg.package +
			';S.browser_fallback_url=' + encodeURIComponent(l.href) + ';end';
	}

	function show(mode) {
		if (banner) return;

		var owner = mode === 'open' || mode === 'home';
		var title = owner ? cfg.ownerTitle : cfg.title;
		var text = { ios: cfg.iosText, open: cfg.openText, home: cfg.homeText }[mode] || cfg.text;
		banner = el('div', 'y4apk-install');
		bannerMode = mode;
		banner.setAttribute('role', 'dialog');
		banner.setAttribute('aria-label', title);
		banner.dataset.mode = mode;

		var icon = el('img', 'y4apk-install__icon');
		icon.src = cfg.icon;
		icon.alt = '';
		icon.width = 48;
		icon.height = 48;

		var body = el('div', 'y4apk-install__body');
		body.appendChild(el('strong', 'y4apk-install__title', title));
		body.appendChild(el('span', 'y4apk-install__text', text));

		var x = el('button', 'y4apk-install__close', '×');
		x.type = 'button';
		x.setAttribute('aria-label', cfg.closeLabel || 'Close');
		x.addEventListener('click', close);

		banner.appendChild(icon);
		banner.appendChild(body);

		if (mode === 'prompt') {
			var install = el('button', 'y4apk-install__cta', cfg.installLabel);
			install.type = 'button';
			install.addEventListener('click', function () {
				var prompt = window.__y4apkInstallPrompt;
				window.__y4apkInstallPrompt = null; // each offer can be used once
				if (!prompt) return close();
				prompt.prompt();
				prompt.userChoice.then(function (choice) {
					if (choice && choice.outcome === 'accepted') installing(); else close();
				}, close);
			});
			banner.appendChild(install);
		} else if (mode === 'apk' || mode === 'open') {
			var link = el('a', 'y4apk-install__cta', mode === 'open' ? cfg.openLabel : cfg.apkLabel);
			link.href = mode === 'open' ? appLink() : cfg.apkUrl;
			link.rel = 'nofollow';
			link.addEventListener('click', mode === 'open' ? close : installing);
			banner.appendChild(link);
		}

		banner.appendChild(x);
		document.body.appendChild(banner);
		requestAnimationFrame(function () { banner && banner.classList.add('y4apk-install--in'); });
	}

	function installedApps() {
		if (!navigator.getInstalledRelatedApps) return Promise.resolve([]);
		return navigator.getInstalledRelatedApps().catch(function () { return []; });
	}

	function pick(apps) {
		var hasApk = apps.some(function (app) {
			return app.platform === 'play' && (!cfg.package || app.id === cfg.package);
		});
		var hasWebApp = apps.some(function (app) { return app.platform === 'webapp'; });
		if (hasApk || hasWebApp) {
			if (!cfg.owners || !isAndroid) return '';
			return hasApk && cfg.package ? 'open' : 'home';
		}
		if (window.__y4apkInstallPrompt) return 'prompt';
		if (isIOSSafari) return 'ios';
		if (isAndroid && cfg.apkUrl) return 'apk';
		return '';
	}

	function check() {
		clearTimeout(timer);
		if (banner || inApp() || (closedHere && !AGAIN_MS)) return;
		var wait = waitLeft();
		if (wait > 0) return later(wait);
		installedApps().then(function (apps) {
			var mode = pick(apps);
			if (mode && !banner && !waitLeft()) show(mode);
		});
	}

	// The browser offering an install beats the APK link, even when the offer
	// only arrives later.
	document.addEventListener('y4apk:installable', function () {
		if (!started) return;
		if (bannerMode === 'apk') {
			banner.remove();
			banner = null;
			bannerMode = '';
		}
		check();
	});
	window.addEventListener('appinstalled', installing);

	// Let the visitor see the page first.
	setTimeout(function () {
		started = true;
		check();
	}, cfg.delay || 4000);
})();
