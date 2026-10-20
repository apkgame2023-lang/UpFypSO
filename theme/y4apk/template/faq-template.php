<?php
/*
* Template Name: FAQ page template
* Template Post Type: page
*/
get_header(); 

$page_links = generate_page_links();
$faqs = get_theme_mod('faqs', [
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
]); 
?>
<div class="static-page">
    <div class="wrp content">
        <div class="str">
            <aside class="str-side" style="display: none;">
                <nav class="side-menu">
                    <?php echo $page_links; ?>
                </nav>
            </aside>
            <div class="str-main">
                <a href="<?php echo get_site_url(); ?>" class="static-back" style="display:none;">
                    <svg width="48" height="20">
                        <use xlink:href="#i__back"></use>
                    </svg>
                </a>
                <h1 class="heading"><?php esc_html_e('FAQ', 'y4apk'); ?></h1>
                <?php foreach ( $faqs as $index => $faq ) : ?>
                <div class="text">
                    <h3 class="cd-faq-trigger"><?php echo $index + 1; ?>. <?php echo $faq['question']; ?></h3>
                    <p>
                        <span style="font-size:12pt;"><?php echo $faq['answer']; ?></span>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php get_template_part('template/footer'); get_footer(); ?>