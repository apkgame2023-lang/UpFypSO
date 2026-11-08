<?php get_header(); ?>
<div class="main-head">
	<?php get_template_part('components/home/home-hero'); ?>
</div>
<div class="section-list wrp">
	<?php if (class_exists('Y4APK_Home_Rows')) { Y4APK_Home_Rows::render('top'); } // 2.60: the owner's own rows ?>
	<?php get_template_part('components/home/home-category'); ?>
	<?php if (class_exists('Y4APK_Home_Rows')) {
		$y4_terms = get_theme_mod('terms', []);
		if (!is_array($y4_terms) || !$y4_terms) {
			Y4APK_Home_Rows::render('middle'); // no list of games: they go here
		}
		Y4APK_Home_Rows::render('bottom');
	} ?>
	<?php get_template_part('components/home/home-seo'); ?>
</div>
<?php get_template_part('template/footer'); get_footer(); ?>