<?php
use Kirki\Util\Helper;

if ( !defined( 'ABSPATH' ) ) exit;
if ( !class_exists( 'Kirki' ) ) return;

/**
 * Bounded choice lists for the Customizer pickers.
 *
 * Kirki's own helpers call get_posts()/get_terms() with no limit and leave meta
 * caching on. WP_Query then follows the post query with a single
 * `SELECT ... FROM wp_postmeta WHERE post_id IN (…every published post id…)`,
 * and this theme stores roughly fifteen meta rows per app — so on a site with a
 * few thousand posts that one statement drags tens of thousands of rows out of
 * wp_postmeta. That is the query the host flagged as database abuse and
 * nullrouted the database for.
 *
 * A picker does not need every post ever published and needs no post meta at
 * all, so these fetch a recent slice with the caches turned off. Raise the
 * limits with the `y4apk_choices_post_limit` / `y4apk_choices_term_limit`
 * filters if a picker really has to reach further back.
 */
if (!function_exists('y4apk_post_choices')) {
	function y4apk_post_choices() {
		static $choices = null;

		if ($choices !== null) {
			return $choices;
		}

		$posts = get_posts([
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => (int) apply_filters('y4apk_choices_post_limit', 200),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'suppress_filters'       => false,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		$choices = [];
		foreach ($posts as $post) {
			$choices[$post->ID] = $post->post_title;
		}

		return $choices;
	}
}

if (!function_exists('y4apk_term_choices')) {
	function y4apk_term_choices($taxonomies) {
		static $cache = [];

		$key = implode(',', (array) $taxonomies);

		if (isset($cache[$key])) {
			return $cache[$key];
		}

		$terms = get_terms([
			'taxonomy'               => $taxonomies,
			'hide_empty'             => false,
			'number'                 => (int) apply_filters('y4apk_choices_term_limit', 300),
			'orderby'                => 'name',
			'update_term_meta_cache' => false,
		]);

		$choices = [];

		if (!is_wp_error($terms)) {
			foreach ($terms as $term) {
				$choices[$term->term_id] = $term->name;
			}
		}

		$cache[$key] = $choices;

		return $choices;
	}
}


Kirki::add_config(
	'y4apk_config',
	[
		'option_type' => 'theme_mod',
		'capability'  => 'manage_options',
	]
);

new \Kirki\Panel(
	'y4apk',
	[
		'priority' => 1,
		'title' => __('Y4APK Panel', 'y4apk'),
	]
);

new \Kirki\Section(
	'header',
	[
		'title' => __('Header', 'y4apk'),
		'description' => __('<h2>Customize Header</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Image(
	[
		'settings'    => 'logo_img',
		'label'       => __( 'Site Logo', 'y4apk' ),
		'description' => __('Customize featured section banner image. Image dimensions width: 144px height: 48px.', 'y4apk'),
		'section'     => 'header',
		'default'     => get_template_directory_uri() . '/assets/img/y4apk.svg',
	]
);

new \Kirki\Field\Select(
	[
		'settings'    => 'menu_select_1',
		'label'       => __( 'Menu 1', 'y4apk' ),
		'description' => __( 'Here you can select the menu 1. For example: Games category or Apps Category.', 'y4apk' ),
		'section'     => 'header',
		'placeholder' => __( 'Select Category', 'y4apk' ),
		'choices'     => y4apk_term_choices('category'),
	]
);

new \Kirki\Field\Select(
	[
		'settings'    => 'menu_select_2',
		'label'       => __( 'Menu 2', 'y4apk' ),
		'description' => __( 'Here you can select the menu 2. For example: Games category or Apps Category.', 'y4apk' ),
		'section'     => 'header',
		'placeholder' => __( 'Select Category', 'y4apk' ),
		'choices'     => y4apk_term_choices('category'),
	]
);

new \Kirki\Field\Code(
	[
		'settings'    => 'head_code',
		'label'       => __( 'Head Code', 'y4apk' ),
		'description' => __( 'Add the head code to your entire website. For instance, you can include Adsense or any tracking codes you want. This code will appear above <code>&lt;/head&gt;</code> tag.', 'y4apk' ),
		'section'     => 'header',
		'choices'     => [
			'language' => 'html',
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings'    => 'footer_code',
		'label'       => __( 'Footer (Body) Code', 'y4apk' ),
		'description' => __( 'Add the footer code to your entire website. For instance, you can include Adsense or any tracking codes you want. This code will appear above <code>&lt;/body&gt;</code> tag.', 'y4apk' ),
		'section'     => 'header',
		'choices'     => [
			'language' => 'html',
		],
	]
);

new \Kirki\Section(
	'home_hero',
	[
		'title' => __('Home Hero', 'y4apk'),
		'description' => __('<h2>Customize Home Hero</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'hero_title',
		'label'    => __('Hero Title', 'y4apk'),
		'description' => __('Customize the hero title.', 'y4apk'),
		'section'  => 'home_hero',
		'default'  => __( 'Large Category of the most popular games with mods', 'y4apk' ),
	]
);

new \Kirki\Field\Image(
	[
		'settings'    => 'hero_img',
		'label'       => __( 'Hero Banner', 'y4apk' ),
		'description' => __('Customize hero section banner image. Image dimensions width: 604px height: 328px.', 'y4apk'),
		'section'     => 'home_hero'
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'hero_btn_name',
		'label'    => __( 'Hero Button Name', 'y4apk' ),
		'description' => __('Customize the hero button name.', 'y4apk'),
		'section'  => 'home_hero',
		'default'  => __( 'All Games', 'y4apk' ),
	]
);

new \Kirki\Field\URL(
	[
		'settings' => 'hero_btn_url',
		'label'    => __( 'Hero Button URL', 'y4apk' ),
		'description' => __('Customize the hero button URL.', 'y4apk'),
		'section'  => 'home_hero',
		'default'  => 'https://y4apk.com/category/games/',
	]
);

new \Kirki\Section(
	'home_featured_posts',
	[
		'title' => __('Home Featured ', 'y4apk'),
		'description' => __('<h2>Customzie Featured Posts</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings'    => 'home_featured_posts_swt',
		'label'       => __( 'Featured Posts Section', 'y4apk' ),
		'description' => __( 'Toggle to show/hide featured posts section in home.', 'y4apk' ),
		'section'     => 'home_featured_posts',
		'default'     => 'off',
		'choices'     => [
			'on'  => __( 'Show', 'y4apk' ),
			'off' => __( 'Hide', 'y4apk' ),
		],
	]
);

new \Kirki\Field\Repeater(
	[
		'settings' => 'featured_posts',
		'label' => 'Featured Posts',
		'description' => __('Here you can add featured posts upto 4 with selected post, custom background image and shadow color.', 'y4apk'),
		'section' => 'home_featured_posts',
		'row_label' => [
			'type' => 'field',
			'value' => __('Post', 'y4apk'),
		],
		'fields' => [
			'post_id' => [
				'type' => 'select',
				'label' => __('Post', 'y4apk'),
				'description' => __('Select post which you want to display in featured posts section.', 'y4apk'),
				'placeholder' => __('Choose a post', 'y4apk'),
				'choices' => y4apk_post_choices()
			],
			'bg_image' => [
				'type' => 'image',
				'label' => __('Background Image', 'y4apk'),
				'description' => __('Upload suitable background image for featured post. Image dimensions Width: 580px and height: 288px.', 'y4apk'),
			],
			'bg_color' => [
				'type' => 'color',
				'label' => __('Background Color', 'y4apk'),
				'description' => __('Select suitable background color based on background image.', 'y4apk'),
			]
		],
		'choices' => [
			'limit' => 4
		],
		'active_callback' => [['setting' => 'home_featured_posts_swt', 'operator' => '==', 'value' => true,]],
		'default' => [
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_angrybirds.webp',
				'bg_color' => '#E53D2D'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_mk.webp',
				'bg_color' => '#F0B835'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_pubg.webp',
				'bg_color' => '#3AC1E0'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_dayr.webp',
				'bg_color' => '#7DB542'
			],
		]
	]
);

new \Kirki\Section(
	'home_posts',
	[
		'title' => __('Home Posts ', 'y4apk'),
		'description' => __('<h2>Customzie Home Posts</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Repeater(
	[
		'settings' => 'terms',
		'label' => 'Category/Tags Section',
		'description' => __('Here you can create and customize multiple category/tags section with new title, description, category/tags, how many posts appear and style for each category/tags.', 'y4apk'),
		'section' => 'home_posts',
		'row_label' => [
			'type' => 'field',
			'value' => __('Category/Tag', 'y4apk'),
		],

		'fields' => [
			'term_title' => [
				'type' => 'text',
				'label' => __('Title', 'y4apk'),
				'description' => __('Assign text for title', 'y4apk'),
				'default' => '',
			],
			'term_limit' => [
				'type' => 'text',
				'label' => __('Number of posts', 'y4apk'),
				'description' => __('Set posts limit will be shown', 'y4apk'),
				'default' => '9',
			],
			'term_id' => [
				'type' => 'select',
				'label' => __('Term', 'y4apk'),
				'description' => __('Select category/tag term of posts will shown in home.', 'y4apk'),
				'placeholder' => __( 'Choose a term', 'y4apk' ),
				'choices' => y4apk_term_choices(['category', 'post_tag']),
			],
			'term_sort' => [
				'type' => 'select',
				'label' => __('Sort by', 'y4apk'),
				'description' => __('Select any one option to filter the posts order.', 'y4apk'),
				'placeholder' => __( 'Choose an option', 'y4apk' ),
				'choices' => [
					'latest' => __('Latest', 'y4apk'),
					'modified' => __('Modified', 'y4apk'),
					'popular' => __('Popular', 'y4apk'),
					'a_to_z' => __('A to Z ↓', 'y4apk'),
					'z_to_a' => __('A to Z ↑', 'y4apk')
				],
			],
			'term_popular_posts' => [
				'type' => 'checkbox',
				'label' => __('Popular Posts Section', 'y4apk'),
				'description' => __('Tick box to show popular posts in below this section. It will automatically generate popular posts by views.', 'y4apk'),
				'default' => false,
			],
			'term_btm_ad' => [
				'type' => 'checkbox',
				'label' => __('Bottom Ad', 'y4apk'),
				'description' => __('Tick box to show ads below this section.', 'y4apk'),
				'default' => false,
			],
		],
	]
);

new \Kirki\Section(
	'home_recommend',
	[
		'title' => __('Home Recommend ', 'y4apk'),
		'description' => __('<h2>Customzie Home Recommend</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings'    => 'home_recommend_swt',
		'label'       => __( 'Recommend Section', 'y4apk' ),
		'description' => __( 'Toggle to show/hide recommend posts section in home.', 'y4apk' ),
		'section'     => 'home_recommend',
		'default'     => 'off',
		'choices'     => [
			'on'  => __( 'Show', 'y4apk' ),
			'off' => __( 'Hide', 'y4apk' ),
		],
	]
);

new \Kirki\Field\Image(
	[
		'settings'    => 'home_recommend_logo',
		'label'       => __('Recommend Logo', 'y4apk'),
		'description' => __('Customize recommed section post logo image. Image dimensions width: 108px height: 80px.', 'y4apk'),
		'section'     => 'home_recommend',
		'default'     => get_template_directory_uri() . '/assets/img/recommend-logo.svg',
		'active_callback' => [['setting' => 'home_recommend_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Repeater(
	[
		'settings' => 'recommend_posts',
		'label' => 'Featured Posts',
		'description' => __('Here you can add featured posts upto 4 with selected post, custom background image and shadow color.', 'y4apk'),
		'section' => 'home_recommend',
		'row_label' => [
			'type' => 'field',
			'value' => __('Post', 'y4apk'),
		],
		'fields' => [
			'post_id' => [
				'type' => 'select',
				'label' => __('Post', 'y4apk'),
				'description' => __('Select post which you want to display in featured posts section.', 'y4apk'),
				'placeholder' => __('Choose a post', 'y4apk'),
				'choices' => y4apk_post_choices()
			],
			'bg_image' => [
				'type' => 'image',
				'label' => __('Background Image', 'y4apk'),
				'description' => __('Upload suitable background image for featured post. Image dimensions Width: 416px and height: 496px.', 'y4apk'),
			],
			'bg_color' => [
				'type' => 'color',
				'label' => __('Shadow Color', 'y4apk'),
				'description' => __('Select suitable background color based on background image.', 'y4apk'),
			]
		],
		'choices' => [
			'limit' => 4
		],
		'active_callback' => [['setting' => 'home_recommend_swt', 'operator' => '==', 'value' => true,]],
		'default' => [
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_plans2.webp',
				'bg_color' => '#6BCF6A'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_rayman.webp',
				'bg_color' => '#F46CF0'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_clashclans.webp',
				'bg_color' => '#FF9533'
			],
			[
				'bg_image' => get_template_directory_uri() . '/assets/img/bg_surfers.webp',
				'bg_color' => '#FF387D'
			],
		]
	]
);

new \Kirki\Section(
	'home_seo',
	[
		'title' => __('Home SEO ', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Pro\Field\HeadlineToggle(
	[
		'settings' => 'home_seo_swt',
		'label'    => __( 'Home SEO', 'y4apk' ),
		'tooltip'  => __('Here you can add your SEO title and description for homepage.', 'y4apk'),
		'section'  => 'home_seo',
		'default'  => false,
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'home_seo_title',
		'label'    => __( 'Home SEO Title', 'y4apk' ),
		'description'	=> __('Customize home seo title.', 'y4apk'),
		'section'  => 'home_seo',
		'default'  => __( 'Download free games for Android', 'y4apk' ),
		'active_callback' => [['setting' => 'home_seo_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Textarea(
	[
		'settings'    => 'home_seo_description',
		'label'       => __( 'Home SEO Description', 'y4apk' ),
		'description'	=> __('Customize home seo description.', 'y4apk'),
		'section'     => 'home_seo',
		'default'     => __( 'Y4APK.COM - Is the best website to provide high-quality APK Niche focused WordPress Theme with affordable cost. Y4APK Theme also one of the beautiful WordPress Theme developed by Y4APK. Follow this website to grab your favorite themes.', 'y4apk' ),
		'active_callback' => [['setting' => 'home_seo_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Section(
	'single_post',
	[
		'title' => __('Single Post', 'y4apk'),
		'description' => __('<h2>Single Post Customization</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Slider(
	[
		'settings' => 'screenshots_limit',
		'label' => __('Screenshots limit', 'y4apk'),
		'description' => __('Set how many screenshots will shown in single post.', 'y4apk'),
		'section' => 'single_post',
		'default' => 5,
		'choices' => [
			'min' => 1,
			'max' => 30,
			'step' => 1,
		],
	]
);

new \Kirki\Pro\Field\HeadlineToggle(
	[
		'settings' => 'security_check_swt',
		'label'    => __( 'Security Check Box', 'y4apk' ),
		'section'  => 'single_post',
		'default'  => true,
	]
);

new \Kirki\Pro\Field\HeadlineToggle(
	[
		'settings' => 'recommend_swt',
		'label'    => __( 'Recommended Posts', 'y4apk' ),
		'section'  => 'single_post',
		'default'  => true,
	]
);

new \Kirki\Field\Slider(
	[
		'settings' => 'recommend_post_limit',
		'label' => __('Recommended Posts Limit', 'y4apk'),
		'label' => __('Set recommended posts limit on single post.', 'y4apk'),
		'section' => 'single_post',
		'default' => 6,
		'choices' => [
			'min' => 1,
			'max' => 30,
			'step' => 1,
		],
		'active_callback' => [['setting' => 'recommend_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Section(
	'archive',
	[
		'title' => __('Archive Page', 'y4apk'),
		'description' => __('<h2>Customize Archive Page</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Select(
	[
		'settings'    => 'archive_sort',
		'label'       => __( 'Sort Order Post', 'y4apk' ),
		'description' => __('Sort post order for archive page posts.', 'y4apk'),
		'section'     => 'archive',
		'default'     => 'latest',
		'placeholder' => __( 'Choose an option', 'y4apk' ),
		'choices'     => [
			'latest' => __('Latest', 'y4apk'),
			'modified' => __('Modified', 'y4apk'),
			'popular' => __('Popular', 'y4apk'),
			'a_to_z' => __('A to Z ↓', 'y4apk'),
			'z_to_a' => __('A to Z ↑', 'y4apk')
		],
	]
);

new \Kirki\Field\Slider(
	[
		'settings' => 'archive_posts_limit',
		'label' => __( 'Archive Posts Limit', 'y4apk' ),
		'description' => __( 'Set post showing limit on archive page posts.', 'y4apk' ),
		'section' => 'archive',
		'default' => 12,
		'choices' => [
			'min' => 1,
			'max' => 50,
			'step' => 1,
		],
	]
);

new \Kirki\Section(
	'download',
	[
		'title' => __('Download Page', 'y4apk'),
		'description' => __('<h2>Customize Download Page</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Slider(
	[
		'settings'    => 'download_timer',
		'label'       => __( 'Download Timer', 'y4apk' ),
		'description'       => __( 'Set download link timer for download page link.', 'y4apk' ),
		'section'     => 'download',
		'default'     => 7,
		'choices'     => [
			'min'  => 1,
			'max'  => 20,
			'step' => 1,
		],
	]
);

new \Kirki\Section(
	'faq',
	[
		'title' => __('FAQ', 'y4apk'),
		'description' => __('<h2>Customize FAQ</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Repeater(
	[
		'settings'    => 'faqs',
		'label'       => __('FAQs', 'y4apk'),
		'description' => __( 'Here you can able to customize the default FAQs.', 'y4apk' ),
		'section'     => 'faq',
		'row_label'    => ['type'  => 'field','value' => __( 'FAQ', 'y4apk' )
	],

		'fields'      => [
			'question'   => [
				'type'        => 'text',
				'label'       => __( 'FAQ Question', 'y4apk' ),
				'description' => __( 'Enter your FAQ question.', 'y4apk' )
			],
			'answer'   => [
				'type'        => 'textarea',
				'label'       => __( 'FAQ Answer', 'y4apk' ),
				'description' => __( 'Enter your FAQ Answer.', 'y4apk' )
			],
		],
		'default'	=> [
	[
		'question' => 'Is worth to buy Y4APK Theme from Y4APK?',
		'answer' => 'Our demo site will said the answer to you.'
	],
	[
		'question' => 'Why themes are costlier in Y4APK?',
		'answer' => 'Cheap quality only available on cheaper price. So we give perfect value to our themes based on theme quality of build.'
	],
	[
		'question' => 'Why other websites sold themes for low cost?',
		'answer' => 'Just compare our themes to others, then you will realize.'
	]
]
	]
);

new \Kirki\Section(
	'advertisement',
	[
		'title' => __('Advertisement', 'y4apk'),
		'description' => __('<h2>Customize Ads</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'home_top_ads_swt',
		'label' => __('Home Top Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide home top section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'home_top_ads',
		'label' => __('Home Top Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here. ', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'home_top_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'home_botm_ads_swt',
		'label' => __('Home Bottom Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide home bottom section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'home_botm_ads',
		'label' => __('Home Bottom Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'home_botm_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'single_top_ads_swt',
		'label' => __('single Top Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide single top section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'single_top_ads',
		'label' => __('Single Top Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'single_top_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'single_botm_ads_swt',
		'label' => __('Single Bottom Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide single bottom section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'single_botm_ads',
		'label' => __('Single Bottom Ad', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'single_botm_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'archive_top_ads_swt',
		'label' => __('Archive Top Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide archive (Category, Tags, Author and Search) page top section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'archive_top_ads',
		'label' => __('Archives Top Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'archive_top_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'archive_botm_ads_swt',
		'label' => __('Archives Bottom Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide archive (Category, Tags, Author and Search) page bottom section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'archive_botm_ads',
		'label' => __('Archives Bottom Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'archive_botm_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'download_top_ads_swt',
		'label' => __('Download Top Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide download page top section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'download_top_ads',
		'label' => __('Download Top Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'download_top_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Field\Checkbox_Switch(
	[
		'settings' => 'download_botm_ads_swt',
		'label' => __('Download Bottom Ad', 'y4apk'),
		'description' => __('Toggle button to show/hide download page bottom section advertisement.', 'y4apk'),
		'section' => 'advertisement',
		'default' => 'off',
		'choices' => [
			'on' => __('Show', 'y4apk'),
			'off' => __('Hide', 'y4apk'),
		],
	]
);

new \Kirki\Field\Code(
	[
		'settings' => 'download_botm_ads',
		'label' => __('Download Bottom Ad Code', 'y4apk'),
		'description' => __('Paste the ad code here.', 'y4apk'),
		'section' => 'advertisement',
		'choices'     => [ 'language' => 'html' ],
		'active_callback' => [['setting' => 'download_botm_ads_swt', 'operator' => '==', 'value' => true,]],
	]
);

new \Kirki\Section(
	'social',
	[
		'title' => __('Social Links', 'y4apk'),
		'description' => '<h2>Customize Social Media Links</h2>',
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'facebook_url',
		'label' => __('Facebook URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'twitter_url',
		'label' => __('Twitter URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'youtube_url',
		'label' => __('YouTube URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'telegram_url',
		'label' => __('Telegram URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'tiktok_url',
		'label' => __('TikTok URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'pinterest_url',
		'label' => __('Pinterest URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'whatsapp_url',
		'label' => __('WhatsApp URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'instagram_url',
		'label' => __('Instagram URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'github_url',
		'label' => __('Github URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'linkedin_url',
		'label' => __('LinkedIn URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'twitch_url',
		'label' => __('Twitch URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'vk_url',
		'label' => __('VK URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'reddit_url',
		'label' => __('Reddit URL', 'y4apk'),
		'section' => 'social',
	]
);

new \Kirki\Section(
	'footer',
	[
		'title' => __('Footer', 'y4apk'),
		'description' => __('<h2>Customize footer section</h2>', 'y4apk'),
		'panel' => 'y4apk',
	]
);

new \Kirki\Field\Text(
	[
		'settings' => 'footer_copyright',
		'label'    => __( 'Footer Copyright', 'y4apk' ),
		'description' => __( 'Enter text to show copyright text will shown in a footer.', 'y4apk' ),
		'section'  => 'footer',
		'default'  => __('<span class="fw-b">© 2023 Y4APK.COM</span><span class="copyright-addon">The best place for MOD games and programs</span>', 'y4apk'),
	]
);