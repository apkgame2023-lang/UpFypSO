<?php
function y4apk_seo_schema(){
	$post_id = get_the_ID();
	$post_title = get_the_title();
	$post_content = get_the_content();
	$post_description = json_encode(trim(strip_tags((wp_trim_words( $post_content, 10, ' ...')))));
	$post_thumbnail_url = get_the_post_thumbnail_url();
	$post_url = get_permalink();
	$rating_data = get_star_rating($post_id);
	
	$app_name = get_post_meta($post_id, 'wp_title_GP', true);
	$app_version = get_post_meta(get_the_ID(), 'wp_version_GP', true);
	$app_publisher = get_post_meta($post_id, 'wp_developers_GP', true);
	$app_size = get_post_meta($post_id, 'wp_sizes', true);
	$app_price = get_post_meta($post_id, 'price', true);
	$app_os = get_post_meta($post_id, 'platform', true);
	$app_os = (!empty($app_os)) ? $app_os : 'Android';
	$app_requires = get_post_meta($post_id, 'wp_requires_GP', true);
	$app_rated_years = get_post_meta($post_id, 'wp_contentrated_GP', true);
	$app_rated_years = (!empty($app_rated_years)) ? $app_rated_years : '16+';
	$app_rating_avg = $rating_data->rating_average;
	$app_rating_votes = $rating_data->rating_votes;
	
	$published_time = get_the_time('c');
	$modified_time = get_the_modified_time('c');
	$categories = get_the_category();
	$site_title = get_bloginfo('name');
	$site_lang = get_bloginfo('language');
	$post_author_id = get_post_field('post_author', $post_id);
	$post_author_name = get_the_author_meta('display_name', $post_author_id);
	$post_author_url = get_author_posts_url($post_author_id);
	
	$category_name = '';

	if (!empty($categories)) {
		foreach ($categories as $cat) {
			if($cat->parent == 0) {
				$category_name = $cat->name;
				break;
			}
		}
	}
	
	if (is_singular('post')) : ?>
		<script type="application/ld+json">
		{
			"@context": "https://schema.org",
			"@type": "SoftwareApplication",
			"headline": "<?php echo $app_name; ?>",
			"name": "<?php echo $app_name; ?>",
			"operatingSystem": "<?php echo $app_os; ?> <?php echo $app_requires; ?>",
			"applicationCategory": "<?php echo $category_name; ?>",
			"description": <?php echo $post_description; ?>,
			"softwareVersion": "<?php echo $app_version; ?>",
			"fileSize": "<?php echo $app_size; ?>",
			"contentRating": "<?php echo $app_rated_years; ?>",
			"datePublished": "<?php echo $published_time; ?>",
			"dateModified": "<?php echo $modified_time; ?>",
			"author": {
				"@type": "Person",
				"name": "<?php echo $post_author_name; ?>"
			},
			"thumbnailUrl": "<?php $post_thumbnail_url; ?>",
			"inLanguage": "<?php echo $site_lang; ?>",
			<?php if($app_rating_avg > 0) { ?>
			"aggregateRating": {
				"@type": "AggregateRating",
				"ratingValue": "<?php echo $app_rating_avg; ?>",
				"bestRating": "5",
				"worstRating": "1",
				"ratingCount": "<?php echo $app_rating_votes; ?>"
			},
			<?php } ?>
			"offers": {
				"@type": "Offer",
				"price": "<?php echo (!empty($app_price)) ? $app_price : '0'; ?>",
				"priceCurrency": "USD"
			}
		}
		</script>
		<?php elseif ( is_custom_post_type() && !is_post_type_archive() ) : ?>
		<script type="application/ld+json">
		{
			"@context": "https://schema.org",
			"@type": "Article",
			"mainEntityOfPage": {
				"@type": "WebPage",
				"@id": "<?php echo $post_url; ?>"
			},
			"headline": "<?php echo $post_title; ?>",
			"image": [
				"<?php echo $post_thumbnail_url; ?>"
			],
			"datePublished": "<?php echo $published_time; ?>",
			"dateModified": "<?php echo $modified_time; ?>",
			"author": {
				"@type": "Person",
				"name": "<?php echo $post_author_name; ?>",
				"url": "<?php echo $post_author_url; ?>"
			},
			"publisher": {
				"@type": "Organization",
				"name": "<?php echo $site_title; ?>"
			},
			"description": <?php echo $post_description; ?>,
			"articleBody": <?php echo json_encode(trim(strip_tags($post_content))); ?>,
			"url": "<?php echo $post_url; ?>"
		}
		</script>
	<?php endif;
}
add_action('wp_head', 'y4apk_seo_schema', 1);