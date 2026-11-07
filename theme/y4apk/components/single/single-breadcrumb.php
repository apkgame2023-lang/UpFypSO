<?php
$app_name = get_post_meta( get_the_ID(), 'wp_title_GP', true );
$app_name = $app_name ?: get_the_title();
$post_categories = get_the_category(get_the_ID());

$parent_cat_ID = null;
foreach ($post_categories as $cat) {
    if ($cat->category_parent == 0) {
        $parent_cat_ID = $cat->term_id;
        break;
    }
}

$child_cats = get_categories(array('parent' => $parent_cat_ID, 'hide_empty' => false));
?>
<div class="f-app-main-head">
    <?php if($child_cats) : foreach($child_cats as $cat) : if (in_category($cat->term_id)) : ?>
    <a href="<?php echo get_category_link( $cat->term_id ); ?>" class="f-app-back" style="display:none;">
        <svg width="48" height="20">
            <use xlink:href="#i__back"></use>
        </svg>
    </a>
    <?php break; endif; endforeach; endif; ?>
    <?php // 2.50.6: the share window, as the profile page's
    $y4sd_id = get_the_ID();
    $y4sd_name = trim(html_entity_decode(wp_strip_all_tags((string) get_post_meta($y4sd_id, 'wp_title_GP', true)), ENT_QUOTES, 'UTF-8'));
    $y4sd_title = html_entity_decode(wp_strip_all_tags(get_the_title($y4sd_id)), ENT_QUOTES, 'UTF-8'); ?>
    <div class="share-drop">
        <button type="button" class="share-drop-btn" data-y4sd-open="y4sd-post" aria-label="<?php esc_attr_e('Share', 'y4apk'); ?>">
            <svg width="24" height="24">
                <use xlink:href="#i__share"></use>
            </svg>
        </button>
    </div>
    <?php if (class_exists('Y4APK_Share_Dialog')) {
        Y4APK_Share_Dialog::render(['id' => 'y4sd-post', 'heading' => __('Share', 'y4apk'), 'name' => $y4sd_name !== '' ? $y4sd_name : $y4sd_title,
            'image' => (string) get_the_post_thumbnail_url($y4sd_id, 'thumbnail'), 'url' => (string) get_permalink($y4sd_id), 'text' => $y4sd_title]);
    } ?>
    <?php if(function_exists('breadcrumbsX')) breadcrumbsX(); ?>
</div>