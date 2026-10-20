<?php
/* Register CPT News Post Type */
function news_post_type() {
    $labels = array(
        'name'                => _x( 'News', 'Post Type General Name', 'y4apk' ),
        'singular_name'       => _x( 'News', 'Post Type Singular Name', 'y4apk' ),
        'menu_name'           => __( 'News', 'y4apk' ),
        'parent_item_colon'   => __( 'Parent News:', 'y4apk' ),
        'all_items'           => __( 'All News', 'y4apk' ),
        'view_item'           => __( 'View Info', 'y4apk' ),
        'add_new_item'        => __( 'Add New', 'y4apk' ),
        'add_new'             => __( 'Add New', 'y4apk' ),
        'edit_item'           => __( 'Edit Info', 'y4apk' ),
        'update_item'         => __( 'Update Info', 'y4apk' ),
        'search_items'        => __( 'Search', 'y4apk' ),
        'not_found'           => __( 'Not found', 'y4apk' ),
        'not_found_in_trash'  => __( 'Not found in Trash', 'y4apk' ),
    );
    $args = array(
        'label'               => __( 'News', 'y4apk' ),
        'description'         => __( 'Info News', 'y4apk' ),
        'labels'              => $labels,
        'show_in_rest'        => true, // To use Gutenberg editor.
        'supports'            => array( 'title', 'editor', 'thumbnail', 'comments' ),
        'hierarchical'        => false,
        'public'              => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => true,
        'show_in_admin_bar'   => true,
        'menu_position'       => 8,
        'menu_icon'           => 'dashicons-media-document',
        'can_export'          => true,
        'has_archive'         => true,
        'exclude_from_search' => false,
        'publicly_queryable'  => true,
        'capability_type'     => 'post',
        'rewrite'             => array( 'slug' => 'news' ),
		'taxonomies'          => array( 'news_tag' ),
    );
    register_post_type( 'news', $args );
}
add_action( 'init', 'news_post_type' );

/* Register CPT News Category/Tags */
function news_taxonomy() {
    // Register articles tags
    $labels = array(
        'name'              => _x( 'News Tags', 'tags general name', 'y4apk' ),
        'singular_name'     => _x( 'News Tag', 'tags singular name', 'y4apk' ),
        'search_items'      => __( 'Search Tags', 'y4apk' ),
        'all_items'         => __( 'All Tags', 'y4apk' ),
        'edit_item'         => __( 'Edit Tag', 'y4apk' ),
        'update_item'       => __( 'Update Tag', 'y4apk' ),
        'add_new_item'      => __( 'Add New Tag', 'y4apk' ),
        'new_item_name'     => __( 'New Tag Name', 'y4apk' ),
        'menu_name'         => __( 'Tags', 'y4apk' ),
    );

    $args = array(
        'hierarchical'      => false,
        'labels'            => $labels,
        'public'            => true,
        'show_ui'           => true,
		'show_in_rest'      => true,
        'show_admin_column' => true,
		'show_in_nav_menus'	=> false,
        'query_var'         => true,
        'rewrite'           => array( 'slug' => 'tnews' ), // Customize the slug
    );

    register_taxonomy( 'news_tag', 'news', $args );
}

add_action( 'init', 'news_taxonomy' );