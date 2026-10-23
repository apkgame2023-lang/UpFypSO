<?php
$is_recommended_post = get_theme_mod('recommend_swt', true);
$post_per_page = get_theme_mod('recommend_post_limit', 6);
$post_categories = get_the_category(get_the_ID());

// Find the parent category
$parent_cat_ID = null;
foreach ($post_categories as $cat) {
    if ($cat->category_parent === 0) {
        $parent_cat_ID = $cat->term_id;
        break;
    }
}

$child_cats = get_categories(array('parent' => $parent_cat_ID, 'hide_empty' => false));

// Find the sub category
$sub_cat_id = null;
if (!empty($child_cats)) {
    foreach ($child_cats as $child_cat) {
        if ( $child_cat->parent !== 0 && in_category($child_cat->term_id) ) {
            $sub_cat_id = $child_cat->term_id;
            break;
        }
    }
}

$related_posts = null;

if ($sub_cat_id) {
    $related_posts = new WP_Query(array(
        'cat' => $sub_cat_id,
        'posts_per_page' => $post_per_page,
        'post__not_in' => array(get_the_ID()),
        'tax_query' => class_exists('Y4APK_Roblox') ? Y4APK_Roblox::not_roblox() : array(),
    ));
}

if ($is_recommended_post && $related_posts && $related_posts->have_posts()) :
?>
<div class="p-file-related">
	<h3 class="section-title"><?php esc_html_e('Similar', 'y4apk'); ?> <?php echo esc_html( get_cat_name($parent_cat_ID) ); ?></h3>
	<div class="list-app">
		<?php
		while ($related_posts->have_posts()): $related_posts->the_post();
			get_template_part('components/post-card/box-1');
		endwhile;
		wp_reset_postdata();
		?>
  </div>
</div>
<?php endif; ?>