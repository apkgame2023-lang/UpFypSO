<?php 
get_header();
$page_links = generate_page_links();
?>
<div class="static-page">
    <div class="wrp content">
        <div class="str">
            <aside class="str-side" style="display:none;">
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
                <h1 class="heading"><?php the_title(); ?></h1>
                <div class="text">
                    <?php the_content(); ?>
                </div>
                <div class="static_pages"></div>
            </div>
        </div>
    </div>
</div>
<?php get_template_part('template/footer'); get_footer(); ?>