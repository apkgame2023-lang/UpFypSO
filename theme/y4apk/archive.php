<?php
global $wp_query;
$current_page = get_query_var('paged') ? get_query_var('paged') : 1;
$total_pages = $wp_query->max_num_pages;

$archive_desc = get_the_archive_description();

get_header();
?>
<div class="section list-content">
    <div class="wrp">
        <section class="section">
            <div class="list-heading">
                <div class="heading-bg">
                    <?php if (function_exists("breadcrumbsX")) breadcrumbsX(); ?>
                    <h1 class="heading"><?php the_archive_title(); ?></h1>
                    <i style="display:none;" class="heading-bg-img">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 792 496" width="792" height="496">
                            <defs>
                                <linearGradient id="linear-gradient" x1="305.72" y1="-247.32" x2="305.38" y2="-246.21" gradientTransform="matrix(758.22, 66.41, 44.84, -511.85, -220207.41, -146338.64)" gradientUnits="userSpaceOnUse">
                                    <stop offset="0" stop-color="#2188d4"></stop>
                                    <stop offset="1" stop-color="#39c1e0"></stop>
                                </linearGradient>
                            </defs>
                            <path class="heading-bg-lblue" fill="#39c1e0" style="opacity:0.1;" d="M1.57,192.59S4.12,121.25,27,73.71a35.54,35.54,0,0,1,11-13.42A56.7,56.7,0,0,1,55.59,52c63.88-17.34,180.5-18.31,180.5-18.31,48.45-.41,151.28-2.84,186.8,14.39,4.2,2,15.61,7.58,20.76,18,0,0,5.18,10.47.06,24.13-18.08,48.3-160.62,142.9-160.62,142.9S138.85,328.9,69.8,338.28C52.61,340.61,40,336,40,336a44,44,0,0,1-21.12-16.17c-21-28.58-18-106.37-17.28-127.28"></path>
                            <path class="heading-bg-blue" fill="url(#linear-gradient)" d="M18.68,236.39s17-120.2,64.16-196.53a60.48,60.48,0,0,1,20.92-20.75,96.2,96.2,0,0,1,31.31-10.8C246.22-9.62,443.61,9.5,443.61,9.5c82,8,256.37,22.15,313.38,57.64,6.74,4.19,25,15.58,31.9,34.16,0,0,6.88,18.63-4.21,40.83C745.5,220.6,487.57,355.19,487.57,355.19s-261,136.2-379.45,139.75c-29.49.89-50.06-9.09-50.06-9.09a74.83,74.83,0,0,1-32.83-31.11C-5.16,402.66,13.7,271.62,18.68,236.39"></path>
                        </svg>
                    </i>
                </div>
            </div>
            <?php if (have_posts()) : ?>
            <div class="list-app">
                <?php
				if(function_exists('archive_top_ad')) archive_top_ad();
				while (have_posts()) : the_post();
					get_template_part('components/post-card/box-1');
				endwhile; wp_reset_postdata();
				?>
            </div>
            <?php else : ?>
            <p><?php esc_html_e('There is no posts to show!', 'y4apk'); ?></p>
            <?php endif; if(function_exists('archive_bottom_ad')) archive_bottom_ad(); ?>
        </section>
        <?php y4apk_pagination($current_page, $total_pages); ?>
    </div>
</div>
<?php if(!empty($archive_desc)) : ?>
<span style="display:block;text-align:center;font-size:14px;padding:15px;"><?php echo $archive_desc; ?></span>
<?php endif; get_template_part('template/footer'); get_footer(); ?>