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
                <div class="h-menu-item h-menu-games">
                    <a class="h-link" href="<?php echo $menu_link_1; ?>">
                        <?php echo $menu_name_1; ?> 
                        <svg width="16" height="16" class="carrot">
                            <use xlink:href="#i__carrot"></use>
                        </svg>
                    </a>
                    <div class="h-menu-drop" style="display:none;">
                        <div class="wrp">
                            <a class="h-menu-drop-all btn btn-border" href="<?php echo $menu_link_1; ?>" style="display:none;"><?php esc_html_e('All', 'y4apk'); ?> <?php echo $menu_name_1; ?></a>
                            <?php if(!empty($subcategories_1) && $hasMenu1CategoryIcon) { ?>
                            <ul class="h-menu-subitems">
                                <?php foreach($subcategories_1 as $subcategory) { 
                                    $category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
                                    if($category_icon) {
                                    ?>
                                <li class="h-menu-subitem">
                                    <a href="<?php echo get_category_link($subcategory->term_id); ?>">
                                        <i class="h-menu-subitem-icon">
                                            <svg width="24" height="24">
                                                <image xlink:href="<?php echo $category_icon; ?>" width="100%" height="100%" />
                                            </svg>
                                        </i>
                                        <span class="h-menu-subitem-text"><?php echo $subcategory->name; ?></span> <span class="muted"><?php echo $subcategory->count; ?></span>
                                    </a>
                                </li>
                                <?php } } ?>
                            </ul>
                            <?php } ?>
                            <?php if($subcategories_1 && !$hasMenu1CategoryIcon) { ?>
                            <div class="h-menu-other">
                                <?php foreach($subcategories_1 as $subcategory) { 
                                    $category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
                                    if(!$category_icon) {
                                    ?>
                                <a href="<?php echo get_category_link($subcategory->term_id); ?>"><?php echo $subcategory->name; ?> <span>(<?php echo $subcategory->count?>)</span></a>
                                <?php } } ?>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="h-menu-item h-menu-apps" style="display:none;">
                    <a class="h-link" href="<?php echo $menu_link_2; ?>">
                        <?php echo $menu_name_2; ?> 
                        <svg width="16" height="16" class="carrot">
                            <use xlink:href="#i__carrot"></use>
                        </svg>
                    </a>
                    <div class="h-menu-drop" style="display:none;">
                        <div class="wrp">
                            <a class="h-menu-drop-all btn btn-border" href="<?php echo $menu_link_2; ?>" style="display:none;">All <?php echo $menu_name_2; ?></a>
                            <?php if(!empty($subcategories_2) && $hasMenu2CategoryIcon) { ?>
                            <ul class="h-menu-subitems">
                                <?php foreach($subcategories_2 as $subcategory) { 
                                    $category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
                                    if($category_icon) {
                                    ?>
                                <li class="h-menu-subitem">
                                    <a href="<?php echo get_category_link($subcategory->term_id); ?>">
                                        <i class="h-menu-subitem-icon">
                                            <svg width="24" height="24">
                                                <image xlink:href="<?php echo $category_icon; ?>" width="100%" height="100%" />
                                            </svg>
                                        </i>
                                        <span class="h-menu-subitem-text"><?php echo $subcategory->name; ?></span> <span class="muted"><?php echo $subcategory->count; ?></span>
                                    </a>
                                </li>
                                <?php } } ?>
                            </ul>
                            <?php } ?>
                            <?php if($subcategories_2 && !$hasMenu2CategoryIcon) { ?>
                            <div class="h-menu-other">
                                <?php foreach($subcategories_2 as $subcategory) { 
                                    $category_icon = get_term_meta($subcategory->term_id, 'category_icon', true);
                                    if(!$category_icon) {
                                    ?>
                                <a href="<?php echo get_category_link($subcategory->term_id); ?>"><?php echo $subcategory->name; ?> <span>(<?php echo $subcategory->count?>)</span></a>
                                <?php } } ?>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
				<?php if ($roblox_menu) Y4APK_Roblox::menu(); ?>
				<?php if($header) : ?>
				<div class="h-menu-item h-menu-links" style="display: none">
					<?php foreach($header as $menu) : ?>
						<a href="<?php echo $menu->url; ?>"><?php echo $menu->title; ?></a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
            </div>
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
                <form class="search-dropbox-body" role="search" method="get" action="<?php echo get_site_url(); ?>">
                    <div class="q_search">
                        <label for="search_text" class="sr-only"><?php esc_html_e('Search games and apps...', 'y4apk'); ?></label>
                        <input type="search" name="s" minlength="3" maxlength="156" required="" placeholder="<?php esc_html_e('Search games and apps...', 'y4apk'); ?>" autocomplete="off" id="search_text">
                        <button type="submit" title="Find">
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
        <?php if (class_exists('Y4APK_Members')) :
            // The same for every visitor, so a cached page is right for all: a member's picture
            // and menu are put in by the script below, from the y4apk_me cookie (2.41).
            $acct_icons = array(
                'me'     => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z"/>',
                'home'   => '<path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z"/>',
                'upload' => '<path d="M11 16V7.8L8.4 10.4 7 9l5-5 5 5-1.4 1.4L13 7.8V16h-2Zm-6 2h14v2H5v-2Z"/>',
                'users'  => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 4v2h14v-2c0-2.3-3.7-4-7-4Zm7.5-2a3.5 3.5 0 1 0-1.3-6.7 6 6 0 0 1 0 6.4c.4.2.8.3 1.3.3Zm1.6 2.1c1.2.9 1.9 2.1 1.9 3.9v2h3v-2c0-2-2.4-3.4-4.9-3.9Z"/>',
                'edit'   => '<path d="M3 17.3V21h3.8l11-11.1-3.7-3.7L3 17.3Zm17.7-10.2a1 1 0 0 0 0-1.4l-2.4-2.4a1 1 0 0 0-1.4 0l-1.8 1.8 3.7 3.8 1.9-1.8Z"/>',
                'gear'   => '<path d="M19.4 13a7.6 7.6 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.4 7.4 0 0 0-1.7-1L15 3h-4l-.4 2.9a7.4 7.4 0 0 0-1.7 1l-2.5-1-2 3.5L4.6 11a7.6 7.6 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1L11 21h4l.4-2.9c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5-2.2-1.6ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/>',
                'out'    => '<path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5V3Zm6.6 4.4-1.4 1.4 2.2 2.2H9v2h8.4l-2.2 2.2 1.4 1.4L21.2 12l-4.6-4.6Z"/>',
                'down'   => '<path d="m7 10 5 5 5-5H7Z"/>',
                'next'   => '<path d="m9.4 6-1.4 1.4 4.6 4.6-4.6 4.6L9.4 18l6-6-6-6Z"/>',
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
                'profile'   => home_url('/@'),
                'ajax'      => admin_url('admin-ajax.php'),
            )); ?>;
            var T = <?php echo wp_json_encode(array(
                'menu'      => __('Your account', 'y4apk'),
                'view'      => __('View profile', 'y4apk'),
                'home'      => __('Dashboard', 'y4apk'),
                'submit'    => __('Share a script', 'y4apk'),
                'following' => __('Following', 'y4apk'),
                'customize' => __('Edit profile', 'y4apk'),
                'settings'  => __('Account settings', 'y4apk'),
                'out'       => __('Sign out', 'y4apk'),
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
            var list = '<nav class="h-acct-list">';
            [['home', 'home'], ['submit', 'upload'], ['following', 'users'], ['customize', 'edit'], ['settings', 'gear']].forEach(function (x) {
                list += '<a href="' + U[x[0]] + '">' + svg(x[1]) + '<span>' + T[x[0]] + '</span></a>';
            });
            list += '</nav><a class="h-acct-out" href="' + U.logout + '" data-out>' + svg('out') + '<span>' + T.out + '</span></a>';
            menu.insertAdjacentHTML('beforeend', list);
            box.innerHTML = ''; box.appendChild(btn); box.appendChild(menu); box.classList.add('is-in');
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
        <a class="logotype" href="<?php echo get_site_url('/'); ?>" title="<?php echo strtoupper($_SERVER['SERVER_NAME']); ?>" style="background-image: url(<?php echo $logo; ?>)">
        <span class="sr-only"><?php echo strtoupper($_SERVER['SERVER_NAME']); ?></span>
        </a>
    </header>
</div>