<?php
function breadcrumbsX() {
	$post_id = get_the_ID();
	
	$app_name = get_post_meta($post_id, 'wp_title_GP', true);
	$app_name = $app_name ?: get_the_title();
	
	$text = [
		'home' => __('Home', 'y4apk'),
		'page' => __('Page %s', 'y4apk'),
		'search' => __('You searched for %s', 'y4apk'),
		'404' => __('Error 404', 'y4apk')
	];
	
	$item_list = array();
	
	$item_list[] = array(
		"@type" => "ListItem",
        "position" => 1,
        "name" => __('Home', 'y4apk'),
        "item" => get_site_url()
    );
	
	if(is_singular('post')) {
		$start = '<div class="f-app-breadcrumbs">';
		$end = '</div>';
	
		$wrap_start = '<div class="f-app-breadcrumbs-in">';
		$wrap_end = '</div>';
	} else {
		$start = '<div class="speedbar-over">';
		$end = '</div>';
	
		$wrap_start = '<div class="speedbar">';
		$wrap_end = '</div>';
	}
	
	$before = '';
	$after = '';
	
	$link = '<a href="%1$s">%2$s</a>';
	$current = '%1$s';
	
	$sep = '&nbsp;&nbsp;→&nbsp;&nbsp;';
	
	$is_current_page = true;
	$show_home = true;
	$show_on_home = true;
	
	$home_url = home_url('/');
	if( is_home() && is_front_page() ) {
		if( $show_on_home ){
			echo $start . $wrap_start;
			echo $before . sprintf( $link, $home_url, $text['home'] ) . $after;
			echo $wrap_end . $end;
		}
	} else {
		echo $start . $wrap_start;
		if( $show_home ) echo $before . sprintf( $link, $home_url, $text['home'] ) . $after;
		
		if( is_singular('post') ) {
			$parent_cat_id = null;
			$post_categories = get_the_category();
			
			if(!empty($post_categories) ) {
				foreach($post_categories as $cat) {
					if($cat->parent == 0) {
						echo $sep . $before . sprintf( $link, get_category_link($cat->term_id), $cat->name ) . $after;
						$parent_cat_id = $cat->term_id;
						
						$item_list[] = array(
							"@type" => "ListItem",
							"position" => count($item_list) + 1,
							"name" => esc_html($cat->name),
							"item" => esc_url(get_category_link($cat->term_id))
						);
					}
				}
				
				if(isset($parent_cat_id)) {
					$child_categories = get_categories( array( 'parent' => $parent_cat_id ) );
				
					if(!empty($child_categories)) {
						foreach($child_categories as $cat) {
							if(in_category($cat->term_id)) {
								echo $sep . $before . sprintf( $link, get_category_link($cat->term_id), $cat->name ) . $after;
								
								$item_list[] = array(
									"@type" => "ListItem",
									"position" => count($item_list) + 1,
									"name" => $cat->name,
									"item" => get_category_link($cat->term_id)
								);
							}
						}
					}
				}
			}
			
			if($is_current_page) echo $sep . $before . sprintf( $current, $app_name ) . $after;
			
			$item_list[] = array(
				"@type" => "ListItem",
				"position" => count($item_list) + 1,
				"name" => $app_name
			);
			
		} elseif( is_custom_post_type() && !is_post_type_archive() ) {
			$post_type = get_post_type();
			$cpt = get_post_type_object($post_type);
			$cpt_archive_url = get_post_type_archive_link($post_type);
			
			echo $sep . $before . sprintf($link, $cpt_archive_url, $cpt->labels->name) . $after;
			if($is_current_page) echo $sep . $before . sprintf( $current, get_the_title() ) . $after;
			
			$item_list[] = array(
				"@type" => "ListItem",
				"position" => count($item_list) + 1,
				"name" => esc_html($cpt->labels->name),
				"item" => esc_url($cpt_archive_url)
			);
			
			$item_list[] = array(
				"@type" => "ListItem",
				"position" => count($item_list) + 1,
				"name" => get_the_title()
			);
			
		} elseif( is_archive() && !is_post_type_archive() ) {
			$paged = get_query_var('paged');
			$current_term = get_queried_object();
			$archive_ancestors = get_ancestors($current_term->term_id, $current_term->taxonomy);
			$archive_ancestors = array_reverse($archive_ancestors);
			
			foreach($archive_ancestors as $ancestor_id) {
				$ancestor = get_term($ancestor_id, $current_term->taxonomy);
				echo $sep . $before . sprintf( $link, get_term_link($ancestor), $ancestor->name ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => esc_html($ancestor->name),
					"item" => esc_url(get_term_link($ancestor))
				);
			}
			
			if($paged) {
				$term = get_term($current_term->term_id, $current_term->taxonomy);
	
				echo $sep . $before . sprintf( $link, get_term_link($term), $term->name ) . $after;
				if($is_current_page) echo $sep . $before . sprintf( $current, sprintf($text['page'], $paged) ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => esc_html($term->name),
					"item" => esc_url(get_term_link($term))
				);

				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => sprintf($text['page'], $paged)
				);
			} else {
				if($is_current_page) echo $sep . $before . sprintf( $current, $current_term->name ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => esc_html($current_term->name)
				);
			}
			
		} elseif( is_post_type_archive() ) {
			$paged = get_query_var('paged');
			$post_type = get_post_type();
			$cpt = get_post_type_object($post_type);
			$cpt_archive_url = get_post_type_archive_link($post_type);
			
			if($paged) {
				echo $sep . $before . sprintf( $link, $cpt_archive_url, $cpt->labels->name ) . $after;
				if($is_current_page) echo $sep . $before . sprintf( $current, sprintf($text['page'], $paged) ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => esc_html($cpt->labels->name),
					"item" => esc_url($cpt_archive_url)
				);

				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => sprintf($text['page'], $paged)
				);
			} else {
				if($is_current_page) echo $sep . $before . sprintf( $current, $cpt->labels->name ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => esc_html($cpt->labels->name)
				);
			}
		
		} elseif( is_search() ) {
			$paged = get_query_var('paged');
			$search_query = get_search_query();
			
			if($paged) {
				echo $sep . $before . sprintf( $link, $home_url . '?s=' . $search_query, sprintf( $text['search'], $search_query ) ) . $after;
				if($is_current_page) echo $sep . $before . sprintf( $current, sprintf($text['page'], $paged) ) . $after;
			} else {
				if($is_current_page) echo $sep . $before . sprintf( $current, sprintf( $text['search'], $search_query ) ) . $after;
			}
			
			$item_list[] = array(
				"@type" => "ListItem",
				"position" => count($item_list) + 1,
				"name" => sprintf($text['search'], $search_query)
			);
			
		} elseif( is_page() ) {
			$page_parent_id = wp_get_post_parent_id();
			
			if(!empty($page_parent_id)) {
				$page_ancestors = get_post_ancestors($post_id);
				$page_ancestors = array_reverse($page_ancestors);
				
				foreach ($page_ancestors as $ancestor_id) {
					$ancestor_title = get_the_title($ancestor_id);
					$ancestor_link = get_permalink($ancestor_id);

					echo $sep . $before . sprintf( $link, $ancestor_link, $ancestor_title ) . $after;
					
					$item_list[] = array(
						"@type" => "ListItem",
						"position" => count($item_list) + 1,
						"name" => esc_html($ancestor_title),
						"item" => esc_url($ancestor_link)
					);
				}
				
				if($is_current_page) echo $sep . $before . sprintf( $current, get_the_title() ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => get_the_title()
				);
				
			} else {
				if($is_current_page) echo $sep . $before . sprintf( $current, get_the_title() ) . $after;
				
				$item_list[] = array(
					"@type" => "ListItem",
					"position" => count($item_list) + 1,
					"name" => get_the_title()
				);
			}

		} elseif( is_404() ) {
			if($is_current_page) echo $sep . $before . sprintf( $current, $text['404'] ) . $after;
		}
	
		echo $wrap_end . $end;
	}
	
    echo '<script type="application/ld+json">';
    echo json_encode(
        array(
            "@context" => "https://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => $item_list
        ),
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    echo '</script>';
}