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

/**
 * Views (2.37): counted by the visitor's browser (views.php), so a page from the
 * page cache counts too; WordPress counted only the visits it built, and twice
 * (here and in header.php). Once an hour per post and browser; editors are not counted.
 */
function track_post_views() {
	if (!is_single() || current_user_can('edit_posts')) {
		return;
	}
	// Named y4apk-pwa-…: the speed plugin's delay already leaves y4apk-pwa scripts alone.
	?>
<script id="y4apk-pwa-views" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var id = <?php echo (int) get_queried_object_id(); ?>, key = 'y4v' + id, now = Date.now();
	try {
		if (now - (+localStorage.getItem(key) || 0) < 3600000) { return; }
		localStorage.setItem(key, now);
	} catch (e) {}
	var url = <?php echo wp_json_encode(get_template_directory_uri() . '/views.php'); ?>, body = JSON.stringify({p: id});
	if (!(navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], {type: 'application/json'})))) {
		fetch(url, {method: 'POST', body: body, keepalive: true, headers: {'Content-Type': 'application/json'}}).catch(function () {});
	}
})();
</script>
	<?php
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
/**
 * A home page card (Home Featured, Home Recommend): its name and its link (2.53). Each may be set on the
 * card in Customize; otherwise the post's game or app name and its page.
 */
function y4apk_card($row) {
	$id = absint($row['post_id'] ?? 0);
	$name = trim((string) ($row['title'] ?? ''));
	if ($name === '' && $id) {
		$name = (string) get_post_meta($id, 'wp_title_GP', true);
		$name = $name !== '' ? $name : get_the_title($id);
	}
	$link = trim((string) ($row['link'] ?? ''));
	if ($link !== '' && $link[0] === '/' && substr($link, 0, 2) !== '//') {
		$link = home_url($link); // /roblox/ → this site's address (and the visitor's language)
	}
	$link = $link !== '' ? esc_url_raw($link) : '';
	return [$name, $link !== '' ? $link : ($id ? get_permalink($id) : home_url('/'))];
}

/**
 * A home card's small icon (2.70.2): its own icon, else its post's, else the
 * first letter of its name (a card that is only a link, such as /roblox/).
 */
function y4apk_card_icon($row, $name = '') {
	$icon = trim((string) ($row['icon'] ?? ''));
	if ($icon !== '') {
		return $icon;
	}
	$id = absint($row['post_id'] ?? 0);
	if ($id) {
		return y4apk_icon_url($id, 'thumbnail');
	}
	$name = trim((string) $name);
	$letter = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
	$hue = abs(crc32($name)) % 360;
	return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="26" fill="hsl(' . $hue . ',55%,46%)"/>'
		. '<text x="60" y="60" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="62" font-weight="700" fill="#fff">' . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</text></svg>');
}

/**
 * A home card row with more than four cards (2.59): it slides sideways — with ‹ › on computers, by swiping on
 * phones (as before). The look once, the few lines that work the arrows after each row.
 */
function y4apk_row_script() {
	static $n = 0;
	$n++;
	if ($n === 1) {
		echo '<style id="y4apk-row-css">.y4row{position:relative}.y4row-nav{display:none}'
			. '@media(min-width:56rem){.y4row.is-many .best-app-over,.y4row.is-many .recommend-over{overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scrollbar-width:none;-ms-overflow-style:none}'
			. '.y4row.is-many .best-app-over::-webkit-scrollbar,.y4row.is-many .recommend-over::-webkit-scrollbar{display:none}'
			. '.y4row.is-many .best-app-over{margin:-1.5rem -.5rem -4rem;padding:1rem 0 3.5rem}.y4row.is-many .best-app-list{margin:0}'
			. '.y4row.is-many .best-app-item,.y4row.is-many .recommend-app{scroll-snap-align:start}'
			. '.y4row-nav{position:absolute;top:50%;z-index:5;display:grid;place-items:center;width:2.75rem;height:2.75rem;margin-top:-1.375rem;padding:0;border:0;border-radius:50%;background:#fff;color:#172021;box-shadow:0 .5rem 1.5rem -.25rem rgba(0,0,0,.25),0 0 0 1px rgba(0,0,0,.03);cursor:pointer;transition:opacity .2s ease,transform .2s ease,color .2s ease}'
			. '.y4row-nav:hover{color:#29b1d8;transform:scale(1.06)}.y4row-nav.is-prev{left:-1.375rem}.y4row-nav.is-next{right:-1.375rem}.y4row-nav:disabled{opacity:0;pointer-events:none}}'
			. '@media(min-width:56rem) and (max-width:61.9375rem){.y4row.is-many .recommend-over{margin:-1.5rem 0 -4rem;padding:1rem 1rem 3.5rem}.y4row.is-many .recommend-list{margin:0}.y4row-nav.is-prev{left:.25rem}.y4row-nav.is-next{right:.25rem}}'
			. '@media(min-width:62rem){.y4row.is-many .recommend-over{margin:-1.5rem -.5rem -4rem;padding:1rem 0 3.5rem}.y4row.is-many .recommend-list{margin:0}}</style>';
	}
	echo '<script id="y4apk-pwa-row' . (int) $n . '" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>'
		. '(function(){[].forEach.call(document.querySelectorAll(".y4row.is-many"),function(r){if(r.y4row){return;}r.y4row=1;'
		. 'var o=r.querySelector(".best-app-over,.recommend-over"),p=r.querySelector("[data-row-prev]"),n=r.querySelector("[data-row-next]");if(!o||!p||!n){return;}'
		. 'var w=function(){var it=o.querySelector(".best-app-item,.recommend-app");return it?it.getBoundingClientRect().width:o.clientWidth/4;};'
		. 'var upd=function(){p.disabled=o.scrollLeft<4;n.disabled=o.scrollLeft+o.clientWidth>=o.scrollWidth-4;};'
		. 'p.addEventListener("click",function(){o.scrollBy({left:-w(),behavior:"smooth"});});n.addEventListener("click",function(){o.scrollBy({left:w(),behavior:"smooth"});});'
		. 'o.addEventListener("scroll",upd,{passive:true});window.addEventListener("resize",upd);upd();});})();</script>';
}

/**
 * The categories row at the top of /games/ and /apps/ (2.53.2): every category of Games (or Apps), the ones
 * with the most posts first, like the menu — the menu shows only 12. On a category's own page the same row,
 * with that one marked.
 */
function y4apk_category_chips() {
	if (!is_category()) {
		return;
	}
	$term = get_queried_object();
	if (!$term || empty($term->term_id)) {
		return;
	}
	$up = get_ancestors($term->term_id, 'category', 'taxonomy');
	$top = $up ? (int) end($up) : (int) $term->term_id;
	$skip = class_exists('Y4APK_Roblox') && Y4APK_Roblox::in_menu() ? array_map('intval', (array) Y4APK_Roblox::categories()) : [];
	if (in_array($top, $skip, true) || in_array((int) $term->term_id, $skip, true)) {
		return; // Roblox has its own pages
	}
	$kids = get_categories(['parent' => $top, 'hide_empty' => true, 'exclude' => $skip]);
	if (count($kids) < 2) {
		return;
	}
	usort($kids, function ($a, $b) { return ($b->count <=> $a->count) ?: strcasecmp($a->name, $b->name); });
	$top_term = get_term($top, 'category');
	echo '<nav class="y4-cats" aria-label="' . esc_attr__('Categories', 'y4apk') . '">';
	echo '<a href="' . esc_url(get_category_link($top)) . '"' . ($top === (int) $term->term_id ? ' class="is-on" aria-current="page"' : '') . '><span>'
		. esc_html(sprintf(__('All %s', 'y4apk'), html_entity_decode($top_term && !is_wp_error($top_term) ? $top_term->name : '', ENT_QUOTES, 'UTF-8'))) . '</span></a>';
	foreach ($kids as $c) {
		$icon = (string) get_term_meta($c->term_id, 'category_icon', true);
		$on = (int) $c->term_id === (int) $term->term_id || in_array((int) $c->term_id, array_map('intval', $up), true);
		echo '<a href="' . esc_url(get_category_link($c->term_id)) . '"' . ($on ? ' class="is-on" aria-current="page"' : '') . '>'
			. ($icon !== '' ? '<img src="' . esc_url($icon) . '" alt="" width="24" height="24" loading="lazy" decoding="async">' : '')
			. '<span>' . esc_html(html_entity_decode($c->name, ENT_QUOTES, 'UTF-8')) . '</span><small>' . esc_html(number_format_i18n($c->count)) . '</small></a>';
	}
	// Computers: one row, the rest behind "More" (2.53.3)
	echo '<button type="button" class="y4-cats-more" hidden aria-expanded="false" data-more="' . esc_attr__('More', 'y4apk') . '" data-less="' . esc_attr__('Less', 'y4apk') . '">'
		. '<span></span><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M7.4 8.6 12 13.2l4.6-4.6L18 10l-6 6-6-6 1.4-1.4Z"/></svg></button>';
	echo '</nav>';
	// One row on computers (the ones that don't fit wait behind "More"); on phones the row scrolls sideways to the marked one
	echo '<script id="y4apk-pwa-cats" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>'
		. '(function(){var n=document.querySelector(".y4-cats");if(!n){return;}'
		. 'var b=n.querySelector(".y4-cats-more"),t=b.querySelector("span"),cs=[].slice.call(n.querySelectorAll("a")),on=n.querySelector("a.is-on"),wide=window.matchMedia("(min-width: 48rem)"),open=false;'
		. 'var fit=function(){cs.forEach(function(c){c.hidden=false;});b.hidden=true;n.classList.remove("is-open");'
		. 'if(!wide.matches){if(on&&n.scrollWidth>n.clientWidth){n.scrollLeft=Math.max(0,on.offsetLeft-16);}return;}'
		. 'var top=cs[0].offsetTop;if(cs[cs.length-1].offsetTop===top){return;}'
		. 'b.hidden=false;b.setAttribute("aria-expanded",open?"true":"false");'
		. 'if(open){n.classList.add("is-open");t.textContent=b.getAttribute("data-less");return;}'
		. 'var hid=0;for(var i=cs.length-1;i>0&&b.offsetTop!==top;i--){cs[i].hidden=true;hid++;}'
		. 'if(on&&on.hidden){open=true;return fit();}'
		. 't.textContent=b.getAttribute("data-more")+" ("+hid+")";};'
		. 'b.addEventListener("click",function(){open=!open;fit();});'
		. 'fit();var w=0;window.addEventListener("resize",function(){clearTimeout(w);w=setTimeout(fit,120);});'
		. 'if(document.fonts&&document.fonts.ready){document.fonts.ready.then(fit);}})();</script>';
}

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
/** A download button's size, as a chip of its own (2.53.6). */
function y4apk_dl_size($size) {
	$size = trim((string) $size);
	return $size === '' || $size === '—' || $size === '-' ? '' : '<span class="dl-size" itemprop="fileSize">' . esc_html($size) . '</span>';
}

function y4apk_size_label($size) {
	$size = trim((string) $size);
	return $size === '' || $size === '—' || $size === '-' ? '' : ' (<span itemprop="fileSize">' . esc_html($size) . '</span>)';
}


/**
 * 2.43: a table in a post scrolls sideways inside its own box on a phone, instead of
 * making the page wider than the screen (which zooms the phone and the app out).
 */
function y4apk_scroll_tables($content) {
	if (stripos($content, '<table') === false || is_admin() || is_feed()) {
		return $content;
	}
	return preg_replace_callback('~(<div class="table-box">\s*)?<table\b.*?</table>~is', function ($m) {
		return !empty($m[1]) ? $m[0] : '<div class="table-box">' . $m[0] . '</div>';
	}, $content);
}
add_filter('the_content', 'y4apk_scroll_tables', 20);

/**
 * 2.61: the page cache. The site left 10Web Booster for LiteSpeed Cache, the
 * plugin of Hostinger's LiteSpeed server; Cloudflare's free plan keeps files
 * (pictures, CSS, JS), not pages. A change every page shows (the home page,
 * the language menu, a link in the header) clears the whole page cache from
 * here, so visitors get it at once instead of after a Purge All by hand.
 */
function y4apk_page_cache() {
	return defined('LSCWP_V') || has_action('litespeed_purge_all');
}

/** Clear every cached page; false when there is no page cache this can clear. */
function y4apk_purge_pages() {
	if (!y4apk_page_cache()) {
		return false;
	}
	do_action('litespeed_purge_all', 'Y4APK settings');
	return true;
}

/** What follows "Saved.": the page cache is cleared, or the owner clears it. */
function y4apk_cache_note() {
	return y4apk_page_cache()
		? __('The page cache is cleared: visitors get it now.', 'y4apk')
		: __('Clear the page cache so visitors get it.', 'y4apk');
}

/* ------------------------------------------------------------------ *
 * 2.70.1: the site's addresses after a plugin update
 *
 * A plugin that rebuilt WordPress's address rules at the wrong moment (while
 * updating) left out the theme's (/account/…, /upload/, /@name…) and the
 * categories' (/games/): those pages answered "404" for a while, and
 * LiteSpeed kept the 404 pages for an hour. Now a page that finds the rules
 * without the theme's own rebuilds them (once in 5 minutes at most) and
 * clears the page cache; and a 404 page is kept by LiteSpeed for one minute
 * only, so a passing problem passes with it.
 * ------------------------------------------------------------------ */

add_action('wp_loaded', function () {
	$rules = get_option('rewrite_rules');
	if (!is_array($rules) || !$rules || isset($rules['^account/?$']) || get_transient('y4apk_rules_fix')) {
		return; // empty rules are rebuilt by WordPress itself
	}
	set_transient('y4apk_rules_fix', 1, 5 * MINUTE_IN_SECONDS);
	flush_rewrite_rules(false);
	update_option('y4apk_rules_fixed', time(), false);
	y4apk_purge_pages(); // the 404 pages kept while the rules were missing
}, 99);

add_action('template_redirect', function () {
	if (is_404()) {
		do_action('litespeed_control_set_ttl', 60);
	}
}, 99);
