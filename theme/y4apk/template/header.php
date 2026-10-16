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
    
$subcategories_1 = get_categories(array(
	'child_of' => $menu_id_1,
));

$subcategories_2 = get_categories(array(
    'child_of' => $menu_id_2,
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
                <input class="select-m-menu" id="select-mobile-menu" type="checkbox" style="display:none;">
                <label class="select-m-menu-tabs" for="select-mobile-menu" style="display:none;">
                <span><?php echo $menu_name_1; ?></span>
                <span><?php echo $menu_name_2; ?></span>
                <i class="select-m-menu-marker"></i>
                </label>
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
        <a class="logotype" href="<?php echo get_site_url('/'); ?>" title="<?php echo strtoupper($_SERVER['SERVER_NAME']); ?>" style="background-image: url(<?php echo $logo; ?>)">
        <span class="sr-only"><?php echo strtoupper($_SERVER['SERVER_NAME']); ?></span>
        </a>
    </header>
</div>