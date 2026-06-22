<?php

if(!defined('ABSPATH')){
exit;
}

function upfypso_setup(){

add_theme_support('title-tag');

add_theme_support('post-thumbnails');

add_theme_support('custom-logo');

add_theme_support('menus');

register_nav_menu(
'primary',
'Primary Menu'
);

}

add_action(
'after_setup_theme',
'upfypso_setup'
);

function upfypso_assets(){

wp_enqueue_style(

'upfypso-style',

get_stylesheet_uri(),

array(),

'2.0.0'

);

}

add_action(

'wp_enqueue_scripts',

'upfypso_assets'

);
