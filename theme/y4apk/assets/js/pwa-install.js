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
 * Already running as an installed app, or dismissed recently → nothing.
 *
 * Config arrives as window.Y4APK_PWA (see inc/pwa.php). No jQuery: it must run
 * on pages where the theme does not load its own scripts.
 */
(function () {
	'use strict';

	var cfg = window.Y4APK_PWA || {};
	var STORE_KEY = 'y4apk_install_dismissed';
	var SNOOZE_MS = 14 * 24 * 60 * 60 * 1000;

	function store(action, value) {
		try {
			return action === 'get' ? window.localStorage.getItem(STORE_KEY)
				: window.localStorage.setItem(STORE_KEY, value);
		} catch (e) {
			return null;
		}
	}

	var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
		window.navigator.standalone === true;
	var dismissedAt = parseInt(store('get') || '0', 10);
	if (standalone || (dismissedAt && Date.now() - dismissedAt < SNOOZE_MS)) {
		return;
	}

	var ua = window.navigator.userAgent || '';
	var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var isIOSSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|FBAN|FBAV|Instagram|Line\/|TikTok|musical_ly/i.test(ua);
	var isAndroid = /Android/i.test(ua);

	var shown = false;

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) node.className = cls;
		if (text) node.textContent = text;
		return node;
	}

	function dismiss(banner) {
		store('set', String(Date.now()));
		banner.classList.remove('y4apk-install--in');
		setTimeout(function () { banner.remove(); }, 250);
	}

	function show(mode) {
		if (shown) return;
		shown = true;

		var banner = el('div', 'y4apk-install');
		banner.setAttribute('role', 'dialog');
		banner.setAttribute('aria-label', cfg.title || 'Install app');

		var icon = el('img', 'y4apk-install__icon');
		icon.src = cfg.icon;
		icon.alt = '';
		icon.width = 48;
		icon.height = 48;

		var body = el('div', 'y4apk-install__body');
		body.appendChild(el('strong', 'y4apk-install__title', cfg.title));
		body.appendChild(el('span', 'y4apk-install__text', mode === 'ios' ? cfg.iosText : cfg.text));

		var close = el('button', 'y4apk-install__close', '×');
		close.type = 'button';
		close.setAttribute('aria-label', cfg.closeLabel || 'Close');
		close.addEventListener('click', function () { dismiss(banner); });

		banner.appendChild(icon);
		banner.appendChild(body);

		if (mode === 'prompt') {
			var install = el('button', 'y4apk-install__cta', cfg.installLabel);
			install.type = 'button';
			install.addEventListener('click', function () {
				var prompt = window.__y4apkInstallPrompt;
				if (!prompt) { dismiss(banner); return; }
				prompt.prompt();
				prompt.userChoice.then(function () {
					window.__y4apkInstallPrompt = null;
					dismiss(banner);
				});
			});
			banner.appendChild(install);
		} else if (mode === 'apk') {
			var link = el('a', 'y4apk-install__cta', cfg.apkLabel);
			link.href = cfg.apkUrl;
			link.rel = 'nofollow';
			link.addEventListener('click', function () { store('set', String(Date.now())); });
			banner.appendChild(link);
		}

		banner.appendChild(close);
		document.body.appendChild(banner);
		requestAnimationFrame(function () { banner.classList.add('y4apk-install--in'); });
	}

	function decide() {
		if (window.__y4apkInstallPrompt) return show('prompt');
		if (isIOSSafari) return show('ios');
		if (isAndroid && cfg.apkUrl) return show('apk');
	}

	// Let the visitor see the page first. After that, the browser offering an
	// install always wins, even if it only offers one later on.
	var ready = false;
	document.addEventListener('y4apk:installable', function () {
		if (ready) show('prompt');
	});
	window.addEventListener('appinstalled', function () {
		store('set', String(Date.now()));
		var banner = document.querySelector('.y4apk-install');
		if (banner) banner.remove();
	});

	setTimeout(function () {
		ready = true;
		decide();
	}, cfg.delay || 4000);
})();
