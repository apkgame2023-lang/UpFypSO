<?php
$post_id = get_the_ID();
if (class_exists('Y4APK_Roblox') && Y4APK_Roblox::is_roblox_post($post_id)) {
	Y4APK_Roblox::facts($post_id); // a script: its game, the key, players and views
	return;
}
$app_name = get_post_meta($post_id, 'wp_title_GP', true);
$app_publisher = get_post_meta($post_id, 'wp_developers_GP', true);
$app_mod_feature = get_post_meta($post_id, 'wp_mods', true);
$app_version = get_post_meta($post_id, 'wp_version_GP', true);
$app_size = get_post_meta($post_id, 'wp_sizes_GP', true);
$app_age = get_post_meta($post_id, 'wp_contentrated_GP', true);
$app_price = get_post_meta($post_id, 'price', true);
$app_requires = get_post_meta($post_id, 'wp_requires_GP', true);
$app_os = get_post_meta($post_id, 'platform', true);
$post_categories = get_the_category($post_id);

if (empty($app_os)) {
	$app_os = 'Android';
} else {
	$app_os = ucfirst(strtolower($app_os));
}

$os_icon = "i__android";

if(strtolower($app_os) == 'android') {
	$os_icon = 'i__android';
} elseif (strtolower($app_os) == 'ios') {
	$os_icon = 'i__apple';
} elseif (strtolower($app_os) == 'windows') {
	$os_icon = 'i__windows';
}
?>
<div class="f-app-meta">
    <div class="grid-list grid4">
		<?php if(!empty($app_name)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__apps"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Name', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_name; ?></span>
            </div>
        </div>
		<?php endif; if(!empty($post_categories)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__cat"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Category', 'y4apk'); ?></span>
				<?php foreach($post_categories as $cat) : if($cat->parent != 0) : ?>
				<a class="f-app-meta-data" href="<?php echo esc_url(get_category_link( $cat->term_id )); ?>"><?php echo esc_html($cat->name); ?></a>
				<?php endif; endforeach; ?>
            </div>
        </div>
		<?php endif; if(!empty($app_publisher)) : ?>
        <div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__cat-tools"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Developer', 'y4apk'); ?></span>
                <span>
                <span class="f-app-meta-data"><?php echo $app_publisher; ?></span>
                </span>
            </div>
        </div>
		<?php endif; if(!empty($app_mod_feature)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__hot"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('MOD Feature', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_mod_feature; ?></span>
            </div>
        </div>
		<?php endif; if(!empty($app_version)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__vers"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Version', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_version; ?></span>
            </div>
        </div>
		<?php endif; if(!empty($app_size)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__info"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Size', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_size; ?></span>
            </div>
        </div>
		<?php endif; if(!empty($app_age)) : ?>
		<div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#i__age"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('Age', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_age; ?></span>
            </div>
        </div>
		<?php endif; if(!empty($app_os) || !empty($app_requires)) : ?>
        <div class="b-grid">
            <div class="block">
                <i class="f-app-meta-icon c-blue">
                    <svg width="24" height="24">
                        <use xlink:href="#<?php echo $os_icon; ?>"></use>
                    </svg>
                </i>
                <span class="fw-b c-blue"><?php esc_html_e('OS', 'y4apk'); ?></span>
                <span class="f-app-meta-data"><?php echo $app_os; ?> <?php echo "$app_requires+"; ?></span>
            </div>
        </div>
		<?php endif; ?>
    </div>
</div>