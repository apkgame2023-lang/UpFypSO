<?php
function y4apk_mime_types( $mimes ) {
    $mimes['svg'] = 'image/svg+xml';
    $mimes['apk']  = 'application/vnd.android.package-archive';
    $mimes['apks'] = 'application/vnd.android.package-archive';
    $mimes['xapk'] = 'application/vnd.android.package-archive';
    return $mimes;
}
add_filter( 'upload_mimes', 'y4apk_mime_types' );

function set_post_views($post_id){
	$count_key = 'wpb_post_views_count';
	$count = get_post_meta($post_id, $count_key, true);
	if ($count == '') {
		$count = 0;
		delete_post_meta($post_id, $count_key);
		add_post_meta($post_id, $count_key, '0');
	} else {
		$count++;
		update_post_meta($post_id, $count_key, $count);
	}
}
remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0);

function track_post_views($post_id){
	if (!is_single()) return;
	if (empty($post_id)) {
		global $post;
		$post_id = $post->ID;
	}
	set_post_views($post_id);
}
add_action('wp_head', 'track_post_views');

function get_post_views($post_id){
	$count_key = 'wpb_post_views_count';
	$count = get_post_meta($post_id, $count_key, true);
	if ($count == '') {
		delete_post_meta($post_id, $count_key);
		add_post_meta($post_id, $count_key, '0');
		return "0";
	}
	return $count;
}

function download_endpoint(){
	add_rewrite_endpoint('download', EP_PERMALINK);
}
add_action('init', 'download_endpoint');

function download_page(){
	global $wp_query;

	if (isset($wp_query->query_vars['download'])) {
		$requested_url = $_SERVER['REQUEST_URI'];

		if (preg_match('/\/download\/(\d+)/', $requested_url, $matches)) {
			$download_id = $matches[1];

			$template_path = get_template_directory() . '/template/download.php';
		} else if (strpos($requested_url, '/download') !== false) {
			$template_path = get_template_directory() . '/template/download.php';
		}

		if (isset($template_path) && file_exists($template_path)) {
			global $custom_download_id;
			$custom_download_id = isset($download_id) ? $download_id : null;
			include $template_path;
			exit;
		}
	}
}
add_action('template_redirect', 'download_page');

function save_post_rating() {
	$response = array();
	$post_id = $_POST['post_id'];
	$rating_count = round($_POST['rating']);
	if(new_rating()){
		$new_rating = (get_post_meta( $post_id, 'total_rating', true ) ? get_post_meta( $post_id, 'total_rating', true ) : 0) + $rating_count; 
		$new_votes = (get_post_meta( $post_id, 'total_votes', true ) ? get_post_meta( $post_id, 'total_votes', true ) : 0) + 1; 
		update_post_meta( $post_id, 'total_rating', $new_rating );
		update_post_meta( $post_id, 'total_votes', $new_votes );
		update_post_meta( $post_id, 'avg_rating', round(($new_rating / $new_votes), 1) );

		$avg_rating = get_post_meta( $post_id, 'avg_rating', true );
		$total_votes = get_post_meta( $post_id, 'total_votes', true );
		$total_votes = number_format($total_votes, 0, ",", ",");

		if( !isset($_COOKIE['new_rating']) ) {
			$expire = strtotime('+1 year');
			setcookie("new_rating", $post_id, $expire, "/");
		} else {
			$new_rating = explode(",", $_COOKIE['new_rating']);
			$new_rating[] = $post_id;
			$expire = strtotime('+1 year');
			setcookie("new_rating", implode(",", $new_rating), $expire, "/");
		}

		$response['success'] = true;
        $response['data']['new_rating'] = $avg_rating;
        $response['data']['new_votes'] = $total_votes;
	} else {
		$response['error'] = true;
        $response['errorInfo'] = __('You already voted this article!', 'y4apk');
	}

	if( function_exists('w3tc_flush_post') ) {
		w3tc_flush_post( $post_id );
	} elseif( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $post_id );
	} elseif( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
		wpfc_clear_post_cache_by_id( $post_id );
	} elseif( defined('LSCWP_V') ) {
		do_action( 'litespeed_purge_post', $post_id );
	} elseif( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}

	echo json_encode($response);	
	die();
}
add_action('wp_ajax_save_post_rating', 'save_post_rating');
add_action('wp_ajax_nopriv_save_post_rating', 'save_post_rating');

function get_star_rating($post_id){
	$array = array();
	$total_rating = ( get_post_meta( $post_id, 'total_rating', true ) ) ? get_post_meta( $post_id, 'total_rating', true ) : 0;
	$total_votes = ( get_post_meta( $post_id, 'total_votes', true ) ) ? get_post_meta( $post_id, 'total_votes', true ) : 0;
	$avg_rating = ( get_post_meta( $post_id, 'avg_rating', true ) ) ? get_post_meta( $post_id, 'avg_rating', true ) : 0;
	$star_width = ($avg_rating > 0) ? strval(($avg_rating * 20) . '%') : '0%';
	
	if ($avg_rating == 0 && $total_votes > 0) {
        $nar = round($total_rating / $total_votes, 1);
        update_post_meta( $post_id, 'avg_rating', $nar);
    }

	$array['rating_total'] = $total_rating;
	$array['rating_votes'] =  $total_votes;
	$array['rating_average'] =  $avg_rating;
	$array['rating_width'] = $star_width;
	
	return (object) $array;
}

function new_rating() {
	$post_id = get_the_ID();
    if( !isset($_COOKIE['new_rating']) || !isset($post_id) )  return true;
    $new_rating = explode(",", $_COOKIE['new_rating']);
    return !in_array($post_id, $new_rating);
}

function edit_category_icon_meta_box($term) {
    $category_icon = get_term_meta($term->term_id, 'category_icon', true);
    ?>
    <tr class="form-field">
        <th valign="top" scope="row"><label for="category-icon">Icon</label></th>
        <td>
            <input type="text" name="category-icon" id="category-icon" class="regular-text" value="<?php echo esc_attr($category_icon); ?>" />
            <p><input type="button" name="category-icon-upload_button" id="category-icon-upload-button" class="button-secondary" value="Upload Icon" /></p>
			<p class="description" id="category-icon-description">We recommend to download category name related SVG icons from <a href="https://www.svgrepo.com" target="_blank">SVG Repo</a> for free and edit icon with this color <code>#29b1d8</code>. Then upload icon to this category.</p>
        </td>
    </tr>
    <?php
}
 
add_action('category_edit_form_fields', 'edit_category_icon_meta_box', 10, 2);

function add_category_icon_meta_box($term) {
    $category_icon = get_term_meta($term->term_id, 'category_icon', true);
    ?>
    <div class="form-field">
		<label for="category-icon">Icon</label>
		<input type="text" name="category-icon" id="category-icon" class="regular-text" value="<?php echo esc_attr($category_icon); ?>" />
		<p><input type="button" name="category-icon-upload_button" id="category-icon-upload-button" class="button-secondary" value="Upload Icon" /></p>
		<p id="category-icon-description">We recommend to download category name related SVG icons from <a href="https://www.svgrepo.com" target="_blank">SVG Repo</a> for free and edit icon with this color <code>#29b1d8</code>. Then upload icon to this category.</p>
    </div>
    <?php
}
add_action('category_add_form_fields', 'add_category_icon_meta_box', 10, 2);

function save_category_icon_meta($term_id) {
	if (!isset($_POST['category-icon'])) {
        return;
    }
        update_term_meta($term_id, 'category_icon', $_POST['category-icon']);
}
add_action('edited_category', 'save_category_icon_meta', 10, 2);
add_action('create_category', 'save_category_icon_meta', 10, 2);

function enqueue_category_icon_scripts($hook) {
    if ($hook === 'term.php' || $hook === 'edit-tags.php') {
        wp_enqueue_media();
        wp_enqueue_script('category-icon-upload', get_template_directory_uri() . '/assets/js/admin/admin.min.js', array('jquery'), '0.1');
    }
}
add_action('admin_enqueue_scripts', 'enqueue_category_icon_scripts');

function generate_page_links() {
    $pages = get_pages();

    $output = '';

    foreach ($pages as $page) {
        $page_link = get_permalink($page->ID);
        $page_title = $page->post_title;
        $active_class = is_page($page->ID) ? 'fw-b' : '';

        $output .= '<a ' . (!empty($active_class) ? 'class="' . $active_class . '"' : '') . ' href="' . esc_url($page_link) . '">' . $page_title . '</a>';
    }

    return $output;
}

function archive_sort($query){
	$archive_post_limit = get_theme_mod('archive_posts_limit', 12);
	$archive_sort_order = get_theme_mod('archive_sort', 'latest');
	
	// The archive's own list only: other queries on the page (2.32: a Roblox game's "most viewed") keep their order.
	if ( !is_admin() && $query->is_main_query() && ( is_archive() || is_post_type_archive() ) ) {

		switch ($archive_sort_order) {
    		case 'latest':
				$query->set('orderby', 'date');
				$query->set('order', 'DESC');
        		break;
    		case 'modified':
				$query->set('orderby', 'modified');
				$query->set('order', 'DESC');
        		break;
    		case 'popular':
				$query->set('meta_key', 'wpb_post_views_count');
				$query->set('orderby', 'meta_value_num');
				$query->set('order', 'DESC');
        		break;
    		case 'a_to_z':
				$query->set('orderby', 'title');
				$query->set('order', 'ASC');
        		break;
    		case 'z_to_a':
				$query->set('orderby', 'title');
				$query->set('order', 'DESC');
        		break;
			default:
				$query->set('orderby', 'date');
				$query->set('order', 'DESC');
				break;
		}
		$query->set('posts_per_page', $archive_post_limit);
	}
};
add_action( 'pre_get_posts', 'archive_sort');

if( class_exists( 'WPSEO_Options' ) ){
    function wp_version_GP() {
		$post_id = get_the_ID();
		$app_version = get_post_meta($post_id, 'wp_version_GP', true);
		return $app_version;
	}
	
	function register_custom_yoast_variables() {
		wpseo_register_var_replacement( '%%wp_version_GP%%', 'wp_version_GP', 'advanced' );
	}
	
	add_action('wpseo_register_extra_replacements', 'register_custom_yoast_variables');
	
	add_filter('wpseo_json_ld_output', function ($output) {
		if (is_singular(['post', 'articles', 'news'])) {
			$output = false;
		}

		return $output;
	}, 99, 2);
}

if (class_exists('RankMath')) {
	add_action( 'rank_math/vars/register_extra_replacements', function(){
		rank_math_register_var_replacement(
			'wp_version_GP',
			[
				'name'        => esc_html__( 'App Version', 'y4apk' ),
				'description' => esc_html__( 'Display App version.', 'y4apk' ),
				'variable'    => 'wp_version_GP',
				'example'     => version_callback(),
			],
			'version_callback'
		);
	});
    function version_callback(){
		$post_id = get_the_ID();
        $app_version = get_post_meta($post_id, 'wp_version_GP', true);
		if(!empty($app_version)) {
			return $app_version;
		} else {
			return '[Version]';
		}
    }
	
	add_filter('rank_math/json_ld', function ($data, $jsonld) {
		if (is_singular(['post', 'articles', 'news'])) {
			return [];
		}

		return $data;
	}, 99, 2);
}

//Vegam in WP
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_null' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );

add_filter('wp_resource_hints', function (array $urls, string $relation): array {
    if ($relation !== 'dns-prefetch') {
        return $urls;
    }
    $urls = array_filter($urls, function (string $url): bool {
        return strpos($url, 's.w.org') === false;
    });
    return $urls;
}, 10, 2);

remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0 );
remove_filter('wp_robots', 'wp_robots_max_image_preview_large');

function is_custom_post_type( $post = NULL ) {
    $all_custom_post_types = get_post_types( array ( '_builtin' => FALSE ) );

    if ( empty ( $all_custom_post_types ) )
        return FALSE;

    $custom_types      = array_keys( $all_custom_post_types );
    $current_post_type = get_post_type( $post );

    if ( ! $current_post_type )
        return FALSE;

    return in_array( $current_post_type, $custom_types );
}

add_filter('rank_math/json_ld', function ($data, $jsonld) {
    if (is_singular(['post', 'articles', 'news'])) {
        return [];
    }

    return $data;
}, 99, 2);

add_filter('wpseo_json_ld_output', function ($output) {
    if (is_singular(['post', 'articles', 'news'])) {
        $output = false;
    }

    return $output;
}, 99, 2);

function head_code() {
    $head_code = get_theme_mod('head_code');
    if (!empty($head_code)) {
        echo $head_code;
    }
}
add_action('wp_head', 'head_code');

function footer_code() {
    $footer_code = get_theme_mod('footer_code');
    if (!empty($footer_code)) {
        echo $footer_code;
    }
}
add_action('wp_footer', 'footer_code');

/**
 * A post's icon: its featured image, else a tile with the app's first letter
 * (a post with no image — an app gone from Google Play — showed a broken
 * image on every card).
 */
function y4apk_icon_url($post_id = null, $size = 'post-thumbnail') {
	$post_id = $post_id ? $post_id : get_the_ID();
	$url = get_the_post_thumbnail_url($post_id, $size);
	if ($url) {
		return $url;
	}
	$name = trim(html_entity_decode(wp_strip_all_tags((string) get_post_meta($post_id, 'wp_title_GP', true)), ENT_QUOTES, 'UTF-8'));
	$name = $name !== '' ? $name : trim(html_entity_decode(wp_strip_all_tags(get_the_title($post_id)), ENT_QUOTES, 'UTF-8'));
	$letter = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
	$hue = abs(crc32($name)) % 360;
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="26" fill="hsl(' . $hue . ',55%,46%)"/>'
		. '<text x="60" y="60" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="62" font-weight="700" fill="#fff">' . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</text></svg>';
	return 'data:image/svg+xml,' . rawurlencode($svg);
}

/** " (31.37MB)" after a download's name, or nothing when its size is not known ("" or "—"). */
function y4apk_size_label($size) {
	$size = trim((string) $size);
	return $size === '' || $size === '—' || $size === '-' ? '' : ' (<span itemprop="fileSize">' . esc_html($size) . '</span>)';
}

