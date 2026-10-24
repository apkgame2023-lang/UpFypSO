<?php
$theme_data = wp_get_theme();
$theme_dir = get_template_directory();

$theme_name = $theme_data->get('Name');
$theme_version = $theme_data->get('Version');
$text_domain = $theme_data->get('TextDomain');

define('Y4APK_THEME_NAME', $theme_name);
define('Y4APK_THEME_VERSION', $theme_version);
define('Y4APK_TRANSLATE', $text_domain);
define('Y4APK_MENU', 'y4apk-dashboard'); // the Y4APK admin menu (its first page: Dashboard)

require $theme_dir . '/inc/breadcrumbs.php';
require $theme_dir . '/inc/core.php';
require $theme_dir . '/inc/inits.php';
require $theme_dir . '/inc/scripts.php';
require $theme_dir . '/inc/roblox.php';
require $theme_dir . '/inits/cpt/articles-cpt.php';
require $theme_dir . '/inits/cpt/news-cpt.php';
require $theme_dir . '/inits/advertisements.php';
require $theme_dir . '/inc/pagination.php';
require $theme_dir . '/inc/comments.php';
require $theme_dir . '/inc/snippet.php';
require $theme_dir . '/inc/extractor/googleplay.php';
require $theme_dir . '/inc/updater.php';
require $theme_dir . '/inc/migrate.php';
require $theme_dir . '/inc/logo.php';
require $theme_dir . '/inc/pwa.php';
require $theme_dir . '/inc/direct-link.php';
require $theme_dir . '/inc/stats.php';
require $theme_dir . '/inc/app-updates.php';
require $theme_dir . '/inc/push.php';
require $theme_dir . '/inc/r2.php';
require $theme_dir . '/inc/writer.php';
require $theme_dir . '/inc/discover.php';
require $theme_dir . '/inc/members.php';
require $theme_dir . '/inc/members-admin.php';
require $theme_dir . '/inc/dashboard.php';
require $theme_dir . '/inc/tgm/tgm.php';
require $theme_dir . '/inc/rest-api.php';
require $theme_dir . '/inc/rest-auth-debug.php';

if (!function_exists('mw_setup')) {
	add_action( 'after_setup_theme', 'mw_setup' );
	function mw_setup() {
		load_theme_textdomain( 'y4apk' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'post-formats', array( 'aside', 'image', 'video', 'quote', 'link', 'status' ) );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' ) );
		add_theme_support( 'customize-selective-refresh-widgets' );
		add_theme_support( 'align-wide' );
		add_theme_support('editor-styles');

		register_nav_menus(
			array(
				'header' => __('Header Menu', 'y4apk'),
				'footer' => __('Footer Menu', 'y4apk'),
			)
		);
	}
}

add_filter('get_the_archive_title', function ($title) {
	if (is_category()) {
		$title = single_cat_title('', false);
	} elseif (is_tag()) {
		$title = single_tag_title('', false);
	} elseif (is_author()) {
		$title = '<span class="vcard">' . get_the_author() . '</span>';
	} elseif (is_tax()) {
		$title = sprintf(__('%1$s'), single_term_title('', false));
	} elseif (is_post_type_archive()) {
		$title = post_type_archive_title('', false);
	}
	return $title;
});

function y4apk_clean($string) {
   $string = str_replace(' ', '-', $string);
   $string = preg_replace('/[^A-Za-z0-9\-]/', '', $string);
   return preg_replace('/-+/', '-', $string); 
}

function y4apk_enqueue_style(){
	$url = $_SERVER['REQUEST_URI'];
	
	wp_enqueue_style( 'core', get_template_directory_uri().'/assets/css/core.css', array(), Y4APK_THEME_VERSION, 'all' );
    wp_enqueue_style( 'styles', get_template_directory_uri().'/assets/css/styles.css', array(), Y4APK_THEME_VERSION, 'all' );
	if (is_page() || is_singular('articles') || is_singular('news')) {
		wp_enqueue_style( 'static', get_template_directory_uri().'/assets/css/static.css', array(), Y4APK_THEME_VERSION, 'all' );
	}
	if (strpos($url, '/download/') == false) {
	wp_enqueue_style( 'fullstory', get_template_directory_uri().'/assets/css/fullstory.css', array(), Y4APK_THEME_VERSION, 'all' );
	}
	if (strpos($url, '/download/') !== false) {
		wp_enqueue_style( 'download-style', get_template_directory_uri().'/assets/css/download.css', array('core'), Y4APK_THEME_VERSION, 'all' );
	}
}
add_action( 'wp_enqueue_scripts', 'y4apk_enqueue_style' );

function enqueue_footer_scripts() {
	$url = $_SERVER['REQUEST_URI'];
	if (strpos($url, '/download/') == false) {
		wp_enqueue_script( 'general-js', get_template_directory_uri() . '/assets/js/general.js', array(), Y4APK_THEME_VERSION, true );
		wp_enqueue_script( 'main-js', get_template_directory_uri() . '/assets/js/main.js', array('general-js'), Y4APK_THEME_VERSION, true );
		wp_enqueue_script( 'bootstrap-js', get_template_directory_uri() . '/assets/js/bootstrap.js', array('main-js'), Y4APK_THEME_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_footer_scripts' );