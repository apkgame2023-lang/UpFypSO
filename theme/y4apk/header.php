<?php
$url = $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="initial-scale=1.0, maximum-scale=5.0, width=device-width">
    <?php wp_head(); if (is_singular()) set_post_views(get_the_ID()); // not the first post of a list page (2.32) ?>
	
	<?php // Font Awesome only if something still needs it: the theme's own icons are inline (2.22).
	if (apply_filters('y4apk_load_fontawesome', false)) : ?>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<?php endif; ?>
	
</head>
<body>
	<div id="main-cont">
		<?php if (strpos($url, '/download/') == false) get_template_part('template/header'); ?>