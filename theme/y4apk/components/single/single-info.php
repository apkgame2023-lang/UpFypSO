<?php 
$app_name = get_post_meta(get_the_ID(), 'wp_title_GP', true);
$app_name = $app_name ?: get_the_title();
$app_price = get_post_meta(get_the_ID(), 'price', true);
$is_security_check = get_theme_mod('security_check_swt', true);
$modified_date = get_the_modified_date('d-m-Y, H:i', get_the_ID());
if (date('Ymd') === get_the_modified_date('Ymd', get_the_ID())) {
    $modified_date = 'Today, ' . get_the_modified_date('H:i', get_the_ID());
} elseif (date('Ymd', strtotime('-1 day')) === get_the_modified_date('Ymd', get_the_ID())) {
    $modified_date = 'Yesterday, ' . get_the_modified_date('H:i', get_the_ID());
}

$user_info = get_userdata(1); 

$categories = get_the_category(get_the_ID());
$subcategory_id = null;
foreach ($categories as $category) {
	if ($category->parent !== 0) {
		$subcategory_id = $category->term_id;
		break;
	}
}

$args = array(
	'cat' => $subcategory_id,
	'post__not_in' => array(get_the_ID()),
	'posts_per_page' => 2,
	'orderby' => 'date',
	'order' => 'ASC',
);

$pages_query = new WP_Query($args);

if($is_security_check) :
?>
<div class="block f-app-seo">
    <div class="f-app-seo-virus">
        <svg class="f-app-seo-shield" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48" height="48">
            <defs>
                <linearGradient id="shield-gradient" x1="-31" y1="-88.25" x2="-32.14" y2="-87.52" gradientTransform="matrix(38, 0, 0, -45, 1225.03, -3931.84)" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#6c92e6"></stop>
                    <stop offset="1" stop-color="#6bd65d"></stop>
                </linearGradient>
            </defs>
            <path fill="url(#shield-gradient)" d="M31.47,18a1.54,1.54,0,0,0-1,.46L21.5,27.38l-3.94-3.94a1.5,1.5,0,0,0-2.12,2.12l5,5a1.49,1.49,0,0,0,2.12,0l10-10a1.5,1.5,0,0,0,0-2.12A1.56,1.56,0,0,0,31.47,18M24,5.43a30.11,30.11,0,0,0,16,6.45V22.76c0,5.74-2.21,15-16,21.07C10.21,37.77,8,28.5,8,22.76V11.88A30.11,30.11,0,0,0,24,5.43M24.05,2A1.5,1.5,0,0,0,23,2.38S15.49,9,6.5,9A1.5,1.5,0,0,0,5,10.5V22.76c0,6.68,3.09,17.67,18.42,24.12a1.46,1.46,0,0,0,1.16,0C39.91,40.43,43,29.44,43,22.76V10.5A1.5,1.5,0,0,0,41.5,9C32.51,9,25,2.38,25,2.38A1.51,1.51,0,0,0,24.05,2"></path>
        </svg>
        <div class="fw-b"><?php esc_html_e('Safety Check:', 'y4apk'); ?></div>
        <p><?php esc_html_e('The game/app', 'y4apk'); ?> <?php echo $app_name; ?> <?php esc_html_e('has been tested and does not contain any viruses!', 'y4apk'); ?></p>
    </div>
    <div class="f-app-seo-text"> <?php esc_html_e('Site', 'y4apk'); ?> <?php echo get_bloginfo('name'); ?> <?php esc_html_e('checks every game and apps, goes through a long check for performance, each mod contains a lot of useful features that will simplify your package files.', 'y4apk'); ?> <br> <?php esc_html_e('Updated:', 'y4apk'); ?> <?php echo $modified_date; ?> <?php esc_html_e('/ Price:', 'y4apk'); ?> <?php echo $app_price ?: "0"; ?> <?php esc_html_e('USD / Author:', 'y4apk'); ?> <?php echo $user_info->display_name; ?></div>
</div>
<?php
endif;

if ($pages_query->have_posts()) :
	$prev_post = $pages_query->posts[0];
	$next_post = $pages_query->posts[1];
?>
<div class="app-next-prev" style="display:none;">
	<div class="wrp">
		<?php if ($next_post) : ?>
		<a class="app-next" href="<?php echo get_permalink($next_post->ID); ?>"><i class="btn blue-style btn-circle"><svg width="24" height="24"><use xlink:href="#i__arrow-right"></use></svg></i><span class="fw-b" title="Next Page"><?php echo get_post_meta( $next_post->ID, 'wp_title_GP', true ); ?></span></a>
		<?php endif; if ($prev_post) : ?>
		<a class="app-prev" href="<?php echo get_permalink($prev_post->ID); ?>"><i class="btn red-style btn-circle"><svg width="24" height="24"><use xlink:href="#i__arrow-left"></use></svg></i><span class="fw-b" title="Previous Page"><?php echo get_post_meta( $prev_post->ID, 'wp_title_GP', true ); ?></span></a>
		<?php endif; ?>
	</div>
</div>
<?php wp_reset_postdata(); endif; ?>