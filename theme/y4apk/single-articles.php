<?php
$post_type = get_post_type( get_the_ID() );
$cpt_archive_url = get_post_type_archive_link($post_type);

get_header();
?>
<div class="static-page">
    <div class="wrp content">
        <div class="str">
            <aside class="str-side" style="display:none;"></aside>
            <div class="str-main">
                <a href="<?php echo $cpt_archive_url; ?>" class="static-back" style="display:none;">
                    <svg width="48" height="20">
                        <use xlink:href="#i__back"></use>
                    </svg>
                </a>
				<?php if(function_exists('breadcrumbsX')) breadcrumbsX(); ?>
                <h1 class="heading" style="margin-top: 2rem"><?php the_title(); ?></h1>
                <div class="cpt-thumbnail">
					<img width="1024" height="500" src="<?php the_post_thumbnail_url(); ?>" alt="<?php the_title(); ?>" loading="lazy" />
				</div>
                <div class="text">
                    <?php 
					if(function_exists('single_top_ad')) single_top_ad();
						the_content();
					if(function_exists('single_bottom_ad')) single_bottom_ad();
					comments_template();
					?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php get_template_part('template/footer'); get_footer(); ?>