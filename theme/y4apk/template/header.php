<?php
$logo = get_theme_mod('logo_img', get_template_directory_uri() . '/assets/img/y4apk.svg');
    
$menu_id_1 = get_theme_mod( 'menu_select_1');
$menu_name_1 = get_cat_name($menu_id_1);
$menu_link_1 = get_category_link($menu_id_1);
    
$menu_id_2 = get_theme_mod( 'menu_select_2');
$menu_name_2 = get_cat_name($menu_id_2);
$menu_link_2 = get_category_link($menu_id_2);
    
$menu_locations = get_nav_menu_locations();
$header = wp_get_nav_menu_items($menu_locations['header'] ?? 0);
$footer = wp_get_nav_menu_items($menu_locations['footer'] ?? 0);
    
// The Roblox category has its own "Roblox" menu (2.32), not a Games tile.
$roblox_menu = class_exists('Y4APK_Roblox') && Y4APK_Roblox::in_menu();
$roblox_cats = $roblox_menu ? Y4APK_Roblox::categories() : array();

$subcategories_1 = get_categories(array(
	'child_of' => $menu_id_1,
	'exclude' => $roblox_cats,
));

$subcategories_2 = get_categories(array(
    'child_of' => $menu_id_2,
    'exclude' => $roblox_cats,
));
    
$hasMenu1CategoryIcon = false;
$hasMenu2CategoryIcon = false;
    
if ($subcategories_1) {
	foreach ($subcategories_1 as $subcategory) {
		$category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
    
        if ($category_icon) {
			$hasMenu1CategoryIcon = true;
          break;
		}
	}
}
    
if ($subcategories_2) {
	foreach ($subcategories_2 as $subcategory) {
		$category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
    
        if ($category_icon) {
			$hasMenu2CategoryIcon = true;
			break;
		}
	}
}

/**
 * The Games and the Apps menu (2.53): like the Roblox one, 12 categories (the ones with the most posts),
 * then "All Games →" and "Share a game +".
 */
$cat_menu = function ($kind, $name, $link, $subs, $icons, $share, $share_label) {
	$subs = is_array($subs) ? $subs : [];
	if ($icons) { // with icons: only the categories that have one, as before
		$subs = array_filter($subs, function ($c) { return (bool) get_term_meta($c->term_id, 'category_icon', true); });
	}
	usort($subs, function ($a, $b) { return ($b->count <=> $a->count) ?: strcasecmp($a->name, $b->name); });
	$subs = array_slice(array_values($subs), 0, 12);
	$all = y4apk_all_of($name, $kind); // 2.70.3: "All games", "All apps" as whole phrases, so they can be translated
	?>
                <div class="h-menu-item h-menu-<?php echo esc_attr($kind); ?>"<?php echo $kind === 'apps' ? ' style="display:none;"' : ''; ?>>
                    <a class="h-link" href="<?php echo esc_url($link); ?>">
                        <?php echo $name; ?> 
                        <svg width="16" height="16" class="carrot">
                            <use xlink:href="#i__carrot"></use>
                        </svg>
                    </a>
                    <div class="h-menu-drop" style="display:none;">
                        <div class="wrp">
                            <a class="h-menu-drop-all btn btn-border" href="<?php echo esc_url($link); ?>" style="display:none;"><?php echo esc_html($all); ?></a>
                            <?php if ($subs && $icons) : ?>
                            <ul class="h-menu-subitems">
                                <?php foreach ($subs as $c) : ?>
                                <li class="h-menu-subitem">
                                    <a href="<?php echo esc_url(get_category_link($c->term_id)); ?>">
                                        <i class="h-menu-subitem-icon">
                                            <svg width="24" height="24">
                                                <image xlink:href="<?php echo esc_url(get_term_meta($c->term_id, 'category_icon', true)); ?>" width="100%" height="100%" />
                                            </svg>
                                        </i>
                                        <span class="h-menu-subitem-text"><?php echo $c->name; ?></span> <span class="muted"><?php echo esc_html(number_format_i18n($c->count)); ?></span>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php elseif ($subs) : ?>
                            <div class="h-menu-other">
                                <?php foreach ($subs as $c) : ?>
                                <a href="<?php echo esc_url(get_category_link($c->term_id)); ?>"><?php echo $c->name; ?> <span>(<?php echo esc_html(number_format_i18n($c->count)); ?>)</span></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="h-menu-more">
                                <a class="h-menu-roblox-all" href="<?php echo esc_url($link); ?>"><?php echo esc_html($all); ?> →</a>
                                <?php if (class_exists('Y4APK_Share') && Y4APK_Share::open() && class_exists('Y4APK_Members')) : ?><a class="h-menu-roblox-all" href="<?php echo esc_url(Y4APK_Members::url('share/' . $share)); ?>"><?php echo esc_html($share_label); ?> +</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
	<?php
};
?>
<div class="header-panel">
    <header class="header wrp">
        <button class="menu-btn" style="display:none;">
        <span class="burger">
        <i></i>
        <i></i>
        <i></i>
        </span>
        <span class="sr-only"><?php esc_html_e('Menu', 'y4apk'); ?></span>
        </button>
        <div class="header-menu" style="display:none;">
            <div class="h-tools">
                <button class="menu-btn-close" style="display:none;">
                    <svg width="24" height="24" class="i__close">
                        <use xlink:href="#i__close"></use>
                    </svg>
                    <span class="sr-only"><?php esc_html_e('Close', 'y4apk'); ?></span>
                </button>
                <?php if($header) : foreach($header as $menu) : ?>
                <div class="h-tools-item h-faq" style="display:none;">
                    <a class="h-link" href="<?php echo $menu->url; ?>"><?php echo $menu->title; ?></a>
                </div>
                <?php endforeach; endif; if($footer) : ?>
                <div class="h-tools-item h-more dropdown" style="display:none;">
                    <span class="h-link dropdown-btn" data-toggle="dropdown" role="button">
                        <svg width="24" height="24">
                            <use xlink:href="#i__more"></use>
                        </svg>
                        <span class="sr-only">...</span>
                    </span>
						
					<div class="dropdown-menu" style="display:none;">
						<?php
						foreach($footer as $menu) :
							$menu_name = $menu->title;
							$menu_url = $menu->url;
						?>
						<a href="<?php echo $menu_url; ?>"><?php echo $menu_name; ?></a>
						<?php endforeach; ?>
					</div>
                </div>
				<?php endif; ?>
            </div>
            <div class="h-menu">
                <?php if ($roblox_menu) : // three tabs on phones: Games, Apps, Roblox ?>
                <input class="y4-m-radio" id="select-mobile-games" type="radio" name="y4apk-m-menu" checked style="display:none;">
                <input class="select-m-menu y4-m-radio" id="select-mobile-menu" type="radio" name="y4apk-m-menu" style="display:none;">
                <input class="y4-m-radio" id="select-mobile-roblox" type="radio" name="y4apk-m-menu" style="display:none;">
                <div class="select-m-menu-tabs y4-tabs3" style="display:none;">
                <label for="select-mobile-games"><?php echo $menu_name_1; ?></label>
                <label for="select-mobile-menu"><?php echo $menu_name_2; ?></label>
                <label for="select-mobile-roblox"><?php esc_html_e('Roblox', 'y4apk'); ?></label>
                <i class="select-m-menu-marker"></i>
                </div>
                <?php else : ?>
                <input class="select-m-menu" id="select-mobile-menu" type="checkbox" style="display:none;">
                <label class="select-m-menu-tabs" for="select-mobile-menu" style="display:none;">
                <span><?php echo $menu_name_1; ?></span>
                <span><?php echo $menu_name_2; ?></span>
                <i class="select-m-menu-marker"></i>
                </label>
                <?php endif; ?>
                <?php $cat_menu('games', $menu_name_1, $menu_link_1, $subcategories_1, $hasMenu1CategoryIcon, 'game', __('Share a game', 'y4apk')); ?>
                <?php $cat_menu('apps', $menu_name_2, $menu_link_2, $subcategories_2, $hasMenu2CategoryIcon, 'app', __('Share an app', 'y4apk')); ?>
				<?php if ($roblox_menu) Y4APK_Roblox::menu(); ?>
				<?php if($header) : ?>
				<div class="h-menu-item h-menu-links" style="display: none">
					<?php foreach($header as $menu) : ?>
						<a href="<?php echo $menu->url; ?>"><?php echo $menu->title; ?></a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
            </div>
            <?php if (class_exists('Y4APK_I18n')) { Y4APK_I18n::menu_row(); } // phones: the same menu as the globe (2.51) ?>
        </div>
        <div class="search-drop dropdown">
            <a class="h-search" href="javascript:void(0);" data-toggle="dropdown" role="button">
                <svg width="24" height="24" class="i__search">
                    <use xlink:href="#i__search"></use>
                </svg>
                <svg width="24" height="24" class="i__close">
                    <use xlink:href="#i__close"></use>
                </svg>
                <span class="sr-only"><?php esc_html_e('Search', 'y4apk'); ?></span>
            </a>
            <div class="search-dropbox" style="display:none;">
                <form class="search-dropbox-body" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                    <div class="q_search">
                        <label for="search_text" class="sr-only"><?php esc_html_e('Search games and apps...', 'y4apk'); ?></label>
                        <input type="search" name="s" minlength="3" maxlength="156" required="" placeholder="<?php esc_html_e('Search games and apps...', 'y4apk'); ?>" autocomplete="off" id="search_text">
                        <button type="submit" title="<?php esc_attr_e('Search', 'y4apk'); ?>">
                            <svg width="24" height="24">
                                <use xlink:href="#i__search"></use>
                            </svg>
                            <span class="sr-only"><?php esc_html_e('Search', 'y4apk'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="search-drop-overlay" style="display:none;"></div>
        <?php if (class_exists('Y4APK_I18n')) { Y4APK_I18n::button(); } // the globe: language and location (2.51) ?>
        <?php if (class_exists('Y4APK_Members')) :
            // The same for every visitor, so a cached page is right for all: a member's picture
            // and menu are put in by the script below, from the y4apk_me cookie (2.41).
            $acct_icons = array(
                'me'     => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z"/>',
                'home'   => '<path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z"/>',
                'upload' => '<path d="M11 16V7.8L8.4 10.4 7 9l5-5 5 5-1.4 1.4L13 7.8V16h-2Zm-6 2h14v2H5v-2Z"/>',
                'pad'    => '<path d="M7 6h10a5 5 0 0 1 4.9 6l-1 5a3 3 0 0 1-5.2 1.4L13.6 16h-3.2l-2.1 2.4A3 3 0 0 1 3.1 17l-1-5A5 5 0 0 1 7 6Zm1 3v2H6v2h2v2h2v-2h2v-2h-2V9H8Zm8.5 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>',
                'users'  => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 4v2h14v-2c0-2.3-3.7-4-7-4Zm7.5-2a3.5 3.5 0 1 0-1.3-6.7 6 6 0 0 1 0 6.4c.4.2.8.3 1.3.3Zm1.6 2.1c1.2.9 1.9 2.1 1.9 3.9v2h3v-2c0-2-2.4-3.4-4.9-3.9Z"/>',
                'edit'   => '<path d="M3 17.3V21h3.8l11-11.1-3.7-3.7L3 17.3Zm17.7-10.2a1 1 0 0 0 0-1.4l-2.4-2.4a1 1 0 0 0-1.4 0l-1.8 1.8 3.7 3.8 1.9-1.8Z"/>',
                'gear'   => '<path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.03 7.03 0 0 0-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84a.47.47 0 0 0-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.87a.47.47 0 0 0 .12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.48.48 0 0 0-.12-.61l-2.01-1.58ZM12 15.6a3.6 3.6 0 1 1 0-7.2 3.6 3.6 0 0 1 0 7.2Z"/>',
                'out'    => '<path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5V3Zm6.6 4.4-1.4 1.4 2.2 2.2H9v2h8.4l-2.2 2.2 1.4 1.4L21.2 12l-4.6-4.6Z"/>',
                'down'   => '<path d="m7 10 5 5 5-5H7Z"/>',
                'crown'  => '<path d="M3 7.5 7.5 11 12 4l4.5 7L21 7.5 19.2 18H4.8L3 7.5ZM5 19.5h14V21H5v-1.5Z"/>',
                'wallet' => '<path d="M21 7.28V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2.28A2 2 0 0 0 22 15V9a2 2 0 0 0-1-1.72ZM20 9v6h-7V9h7ZM5 19V5h14v2h-6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h6v2H5Zm11-5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
                'help'   => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 17h-2v-2h2v2Zm2.1-7.8-.9.9c-.7.7-1.2 1.3-1.2 2.9h-2v-.5c0-1.1.5-2.1 1.2-2.8l1.2-1.3c.4-.4.6-.9.6-1.4a2 2 0 0 0-4 0H8a4 4 0 0 1 8 0c0 .9-.4 1.7-.9 2.2Z"/>',
                'chat'   => '<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2ZM7 9h10v2H7V9Zm6 5H7v-2h6v2Zm4-6H7V6h10v2Z"/>',
                'next'   => '<path d="m9.4 6-1.4 1.4 4.6 4.6-4.6 4.6L9.4 18l6-6-6-6Z"/>',
                'mail'   => '<path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>',
                'globe'  => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8ZM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4ZM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3Zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 0 1 5.1 16ZM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8Zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4Zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4Zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6Zm1.7-5.6a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4Z"/>',
            );
        ?>
        <div class="h-acct" data-acct>
            <a class="h-acct-btn" href="<?php echo esc_url(Y4APK_Members::url('login')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><?php echo $acct_icons['me']; ?></svg>
                <span class="h-acct-label"><?php esc_html_e('Sign in', 'y4apk'); ?></span>
            </a>
        </div>
        <script id="y4apk-pwa-acct" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
        (function () {
            var box = document.querySelector('[data-acct]'), m = document.cookie.match(/(?:^|;\s*)y4apk_me=([^;]+)/), me;
            if (!box || !m) { return; }
            try { me = JSON.parse(decodeURIComponent(m[1])); } catch (e) { return; }
            if (!me || typeof me.l !== 'string' || !/^[a-z0-9_]{3,20}$/.test(me.l)) { return; }
            var I = <?php echo wp_json_encode($acct_icons); ?>;
            var U = <?php echo wp_json_encode(array(
                'home'      => Y4APK_Members::url(),
                'submit'    => Y4APK_Members::url('submit'),
                'following' => Y4APK_Members::url('following'),
                'customize' => Y4APK_Members::url('customize'),
                'settings'  => Y4APK_Members::url('settings'),
                'logout'    => Y4APK_Members::url('logout'),
                'help'      => class_exists('Y4APK_Help') ? Y4APK_Help::url('faq') : '',
                'feedback'  => class_exists('Y4APK_Help') ? Y4APK_Help::url('feedback') : '',
                'profile'   => home_url('/@'),
                'vip'       => class_exists('Y4APK_VIP') ? Y4APK_VIP::url() : '',
                'wallet'    => class_exists('Y4APK_Wallet') && Y4APK_Wallet::on() ? Y4APK_Wallet::url() : '', // 2.64
                'messages'  => class_exists('Y4APK_DM') && Y4APK_DM::on() ? Y4APK_DM::url() : '', // 2.67
                'ajax'      => admin_url('admin-ajax.php'),
            )); ?>;
            var T = <?php echo wp_json_encode(array(
                'menu'      => __('Your account', 'y4apk'),
                'view'      => __('View profile', 'y4apk'),
                'home'      => __('Dashboard', 'y4apk'),
                'submit'    => __('Upload now', 'y4apk'),
                'following' => __('Following', 'y4apk'),
                'customize' => __('Edit profile', 'y4apk'),
                'settings'  => __('Account settings', 'y4apk'),
                'wallet'    => __('Wallet and earnings', 'y4apk'),
                'messages'  => __('Messages', 'y4apk'),
                'confirm'   => __('Confirm your email', 'y4apk'),
                'confirmWhy'=> __('Open the link we sent you', 'y4apk'),
                'out'       => __('Sign out', 'y4apk'),
                'help'      => __('Help and contact', 'y4apk'),
                'feedback'  => __('Send feedback', 'y4apk'),
                'vipGet'    => __('Get VIP', 'y4apk'),
                'vipWhy'    => __('No ads, no waiting', 'y4apk'),
                'vipOn'     => __('You are VIP', 'y4apk'),
                /* translators: %s: a date */
                'vipUntil'  => __('Until %s', 'y4apk'),
                // The language menu from here too, as YouTube has it in its account menu (2.51)
                'lang'      => class_exists('Y4APK_I18n') && count(Y4APK_I18n::ready()) > 1 ? sprintf(__('Language: %s', 'y4apk'), Y4APK_I18n::languages()[Y4APK_I18n::current()][0]) : '',
            )); ?>;
            var svg = function (k, s) { return '<svg viewBox="0 0 24 24" width="' + (s || 18) + '" height="' + (s || 18) + '" fill="currentColor" aria-hidden="true">' + I[k] + '</svg>'; };
            var av = function (size) {
                var n = String(me.n || me.l), h = (+me.h || 0) % 360, el;
                if (typeof me.a === 'string' && /^https:\/\/[^\s"'<>]+$/.test(me.a)) {
                    el = document.createElement('img'); el.src = me.a; el.alt = ''; el.width = el.height = size; el.decoding = 'async';
                    el.onerror = function () { el.replaceWith(letter()); };
                } else { el = letter(); }
                function letter() {
                    var t = document.createElement('span'); t.className = 'h-acct-av'; t.textContent = Array.from(n)[0].toUpperCase();
                    t.style.cssText = 'width:' + size + 'px;height:' + size + 'px;font-size:' + Math.round(size * .46) + 'px;background:linear-gradient(135deg,hsl(' + h + ',70%,52%),hsl(' + ((h + 40) % 360) + ',65%,38%))';
                    return t;
                }
                el.className = 'h-acct-av';
                return el;
            };
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'h-acct-btn is-in'; btn.setAttribute('aria-haspopup', 'true'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-label', T.menu);
            btn.appendChild(av(32));
            var nm = document.createElement('span'); nm.className = 'h-acct-name'; nm.textContent = me.n || me.l; btn.appendChild(nm);
            btn.insertAdjacentHTML('beforeend', '<span class="h-acct-caret">' + svg('down', 18) + '</span>');
            var menu = document.createElement('div');
            menu.className = 'h-acct-menu'; menu.hidden = true;
            var head = document.createElement('a'); head.className = 'h-acct-me'; head.href = U.profile + encodeURIComponent(me.l);
            head.appendChild(av(44));
            var who = document.createElement('span'); who.className = 'h-acct-who';
            var b = document.createElement('b'); b.textContent = me.n || me.l; var sm = document.createElement('small'); sm.textContent = T.view;
            who.appendChild(b); who.appendChild(sm); head.appendChild(who);
            head.insertAdjacentHTML('beforeend', svg('next', 18));
            menu.appendChild(head);
            if (me.c === 0) { // 2.66: the email is not confirmed yet
                var ca = document.createElement('a'), ct = document.createElement('span'), cs = document.createElement('small');
                ca.className = 'h-acct-confirm'; ca.href = U.home;
                ca.innerHTML = svg('mail', 22);
                ct.textContent = T.confirm; cs.textContent = T.confirmWhy; ct.appendChild(cs); ca.appendChild(ct);
                ca.insertAdjacentHTML('beforeend', svg('next', 16));
                menu.appendChild(ca);
                btn.classList.add('has-dot');
            }
            if (U.vip) { // 2.62
                var vipOn = +me.v > Date.now() / 1000, va = document.createElement('a');
                va.className = 'h-acct-vip' + (vipOn ? ' is-on' : ''); va.href = U.vip;
                va.innerHTML = svg('crown', 22) + '<span></span>' + svg('next', 16);
                var vt = va.querySelector('span'), vs = document.createElement('small');
                vt.textContent = vipOn ? T.vipOn : T.vipGet;
                vs.textContent = vipOn ? T.vipUntil.replace('%s', new Date(me.v * 1000).toLocaleDateString()) : T.vipWhy;
                vt.appendChild(vs); menu.appendChild(va);
            }
            var list = '<nav class="h-acct-list">';
            [['home', 'home'], ['messages', 'chat'], ['submit', 'upload'], ['wallet', 'wallet'], ['following', 'users'], ['customize', 'edit'], ['settings', 'gear']].forEach(function (x) {
                if (U[x[0]]) { list += '<a href="' + U[x[0]] + '">' + svg(x[1]) + '<span>' + T[x[0]] + '</span>' + (x[0] === 'messages' ? '<b class="h-acct-n" data-dm-count hidden></b>' : '') + '</a>'; }
            });
            list += '</nav>' + (T.lang ? '<button type="button" class="h-acct-lang" data-y4l-open>' + svg('globe') + '<span></span>' + svg('next', 16) + '</button>' : '')
                + (U.help ? '<nav class="h-acct-list"><a href="' + U.help + '">' + svg('help') + '<span>' + T.help + '</span></a><a href="' + U.feedback + '">' + svg('chat') + '<span>' + T.feedback + '</span></a></nav>' : '')
                + '<a class="h-acct-out" href="' + U.logout + '" data-out>' + svg('out') + '<span>' + T.out + '</span></a>';
            menu.insertAdjacentHTML('beforeend', list);
            if (T.lang) { menu.querySelector('.h-acct-lang span').textContent = T.lang; }
            box.innerHTML = ''; box.appendChild(btn); box.appendChild(menu); box.classList.add('is-in');
            if (U.messages) { // 2.67: unread messages, asked at most once a minute
                var dmPut = function (n) { menu.querySelectorAll('[data-dm-count]').forEach(function (x) { x.textContent = n > 99 ? '99+' : n; x.hidden = !n; }); btn.classList.toggle('has-msg', n > 0); };
                var dmC = null; try { dmC = JSON.parse(sessionStorage.getItem('y4apk_dm_n') || 'null'); } catch (e) {}
                if (dmC && Date.now() - dmC.t < 60000) { dmPut(+dmC.n || 0); }
                else if (window.fetch) { fetch(U.ajax + '?action=y4apk_dm_count', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.success) { dmPut(+j.data.n || 0); try { sessionStorage.setItem('y4apk_dm_n', JSON.stringify({ n: +j.data.n || 0, t: Date.now() })); } catch (e) {} } }).catch(function () {}); }
            }
            var open = function (on) { menu.hidden = !on; btn.setAttribute('aria-expanded', on ? 'true' : 'false'); box.classList.toggle('open', on); };
            btn.addEventListener('click', function (e) { e.stopPropagation(); open(menu.hidden); });
            document.addEventListener('click', function (e) { if (!menu.hidden && !box.contains(e.target)) { open(false); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); } });
            // Sign out in one click: a fresh token first (this page may be a stored copy); without one, the page that asks
            menu.querySelector('[data-out]').addEventListener('click', function (e) {
                if (!window.fetch || !window.FormData) { return; }
                e.preventDefault();
                var a = this, f = new FormData(); f.append('action', 'y4apk_fresh'); f.append('do[]', 'logout');
                fetch(U.ajax, {method: 'POST', body: f, credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); })
                    .then(function (j) { location.href = j && j.n && j.n.logout ? U.logout + '?_y4n=' + encodeURIComponent(j.n.logout) : a.href; })
                    .catch(function () { location.href = a.href; });
            });
        })();
        </script>
        <?php endif; ?>
        <a class="logotype" href="<?php echo esc_url(home_url('/')); ?>" title="<?php echo strtoupper($_SERVER['SERVER_NAME']); ?>" style="background-image: url(<?php echo $logo; ?>)">
        <span class="sr-only"><?php echo strtoupper($_SERVER['SERVER_NAME']); ?></span>
        </a>
    </header>
</div>