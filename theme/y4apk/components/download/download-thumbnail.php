<?php
$app_name = get_post_meta(get_the_ID(), 'wp_title_GP', true);
$app_name = $app_name ?: get_the_title();
$app_required = get_post_meta(get_the_ID(), 'wp_requires_GP', true);
$app_age = get_post_meta(get_the_ID(), 'wp_contentrated_GP', true);
if (empty($app_os)) {
	$app_os = 'Andrid';
} else {
	$app_os = ucfirst(strtolower($app_os));
}
?>
<div class="p-file-data section">
    <div class="p-file-data-l">
        <i class="c-blue">
            <svg width="24" height="24">
                <use xlink:href="#i__android"></use>
            </svg>
        </i>
        <span class="c-blue fw-b"><?php echo $app_os; ?></span>
        <span> <?php echo $app_required; ?>+</span>
    </div>
    <figure class="page_file-img">
        <img src="<?php the_post_thumbnail_url(); ?>" alt="<?php echo $app_name; ?>" loading="lazy">
    </figure>
    <div class="p-file-data-r">
        <i class="c-blue">
            <svg width="24" height="24">
                <use xlink:href="#i__age"></use>
            </svg>
        </i>
        <span class="c-blue fw-b"><?php esc_html_e('Age', 'y4apk'); ?></span>
        <span><?php echo $app_age; ?></span>
    </div>
</div>