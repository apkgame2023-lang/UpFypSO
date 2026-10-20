<?php
// The second argument of get_theme_mod() is the default value. It used to hold
// the text domain by mistake, so an unconfigured site got the string
// "apktemplates" here and the foreach below threw instead of rendering.
$terms = get_theme_mod('terms', []);
$terms = is_array($terms) ? $terms : [];
$is_home_recommend = get_theme_mod('home_recommend_swt', false);

foreach($terms as $index => $term) :
    $term_title = $term['term_title'];
    $term_limit = $term['term_limit'];
    $term_id = $term['term_id'];
	$term_sort_order = $term['term_sort'];
	$is_popular_posts = $term['term_popular_posts'];
    $is_bottom_ad = $term['term_btm_ad'];

	$post_args = array();
	$popular_post_args = array();

	switch ($term_sort_order) {
    	case 'latest':
        	$post_args['orderby'] = 'date';
        	$post_args['order'] = 'DESC';
        	break;
    	case 'modified':
        	$post_args['orderby'] = 'modified';
        	$post_args['order'] = 'DESC';
        	break;
    	case 'popular':
        	$post_args['meta_key'] = 'wpb_post_views_count';
        	$post_args['orderby'] = 'meta_value_num';
        	$post_args['order'] = 'DESC';
        	break;
    	case 'a_to_z':
        	$post_args['orderby'] = 'title';
        	$post_args['order'] = 'ASC';
        	break;
    	case 'z_to_a':
        	$post_args['orderby'] = 'title';
        	$post_args['order'] = 'DESC';
        	break;
		default:
        	$post_args['orderby'] = 'modified';
        	$post_args['order'] = 'DESC';
			break;
	}

	$post_args['posts_per_page'] = $term_limit;
	$post_args['tax_query'] = [
    	'relation' => 'AND',
	];

	$popular_post_args = [
		"posts_per_page" => 6,
		'meta_key' => 'wpb_post_views_count',
		'orderby' => 'meta_value_num',
		"tax_query" => [
			"relation" => "AND",
		],
	];

    if (!empty($term_id)) {
        $term_obj = get_term_by("id", $term_id, "category");

        if (!$term_obj) {
            $term_obj = get_term_by("id", $term_id, "post_tag");
        }

        if ($term_obj) {
            $tax_query_item = [
                "taxonomy" => $term_obj->taxonomy,
                "field" => "id",
                "terms" => $term_obj->term_id,
            ];
            $post_args["tax_query"][] = $tax_query_item;
        }

		if ($term_obj) {
			$popular_tax_query_item = [
				"taxonomy" => $term_obj->taxonomy,
				"field" => "id",
				"terms" => $term_obj->term_id,
			];
			$popular_post_args["tax_query"][] = $popular_tax_query_item;
		}
	}
	
    $term_posts = new WP_Query($post_args);

	$popular_posts = new WP_Query($popular_post_args);

    if ($term_posts->have_posts()) :
?>
<div class="section section-newgames">
	<?php if($term_title) : ?>
	<h3 class="section-title">
		<a href="<?php echo get_category_link($term_id); ?>"><?php echo $term_title; ?></a>
		<i class="section-more">
			<svg class="section-more-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 36" width="128" height="36"><path d="M15.85,18a11.92,11.92,0,0,1,16.83-1,9.44,9.44,0,0,1,1,1,11.83,11.83,0,0,0,8.94,4,11.72,11.72,0,0,0,8.91-4A12,12,0,0,1,68.4,17a11.45,11.45,0,0,1,1,1"></path><g><path d="M109.12,22c-5.2-5.74-9.37-8-13-8a11.73,11.73,0,0,0-8.92,4,12,12,0,0,1-17.85,0"></path><polyline points="102 23 110 23 110 15"></polyline></g></svg>
		</i>
	</h3>
	<?php endif; ?>
	<div class="list-app">
		<?php
		while ($term_posts->have_posts()) : $term_posts->the_post();
			get_template_part('components/post-card/box-1');
		endwhile;
		wp_reset_postdata();
		?>
	</div>
	<?php if(function_exists('home_bottom_ad') && $is_bottom_ad) home_bottom_ad(); ?>
</div>
<?php endif; if($is_popular_posts && $popular_posts->have_posts()) : ?>
<div class="section section-bestweek">
	<h3 class="section-title">
		<a href="<?php echo get_category_link($term_id); ?>"><?php esc_html_e('Popular'); ?> <?php echo get_cat_name($term_id); ?></a>
		<i class="section-more">
			<svg class="section-more-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 36" width="128" height="36">
				<path d="M15.85,18a11.92,11.92,0,0,1,16.83-1,9.44,9.44,0,0,1,1,1,11.83,11.83,0,0,0,8.94,4,11.72,11.72,0,0,0,8.91-4A12,12,0,0,1,68.4,17a11.45,11.45,0,0,1,1,1"></path>
				<g>
					<path d="M109.12,22c-5.2-5.74-9.37-8-13-8a11.73,11.73,0,0,0-8.92,4,12,12,0,0,1-17.85,0"></path>
					<polyline points="102 23 110 23 110 15"></polyline>
				</g>
			</svg>
		</i>
	</h3>
	<div class="list-app one-row">
		<?php
		while ($popular_posts->have_posts()) : $popular_posts->the_post();
			get_template_part('components/post-card/box-1');
		endwhile;
		wp_reset_postdata();
		?>
	</div>
</div>
<?php endif; if($index === 0 && $is_home_recommend) :
	get_template_part('components/home/home-recommend');
endif; endforeach; ?>