<?php get_header(); ?>
<div class="section list-content">
    <div class="wrp">
        <section class="section">
            <div class="list-heading">
                <div class="heading-bg">
                    <div class="speedbar-over">
                        <div class="speedbar">
                            <a href="<?php echo get_site_url(); ?>"><?php esc_html_e('Home', 'y4apk'); ?></a> → <?php esc_html_e('404 Found', 'y4apk'); ?>
                        </div>
                    </div>
                    <h1 class="heading"></h1>
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
            <div class="alert-page block">
                <div class="alert-page_in">
                    <i class="alert-img">
                    <img src="<?php echo get_template_directory_uri() . '/assets/img/info-error.png'; ?>" width="582" height="375" alt="">
                    </i>
                    <div class="fw-b title c-blue"><?php esc_html_e('Warning! An error was detected', 'y4apk'); ?></div>
                    <?php esc_html_e('Publications at this address of the website are not found or you do not have permissions to view the information at this address.', 'y4apk'); ?>
                </div>
            </div>
        </section>
    </div>
</div>
<?php get_template_part('template/footer'); get_footer(); ?>