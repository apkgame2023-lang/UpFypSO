<?php
global $custom_download_id;
$download_id = absint($custom_download_id);

$app_name = get_post_meta( $post->ID, 'wp_title_GP', true );
$app_name = $app_name ?: get_the_title();
$download_links = get_post_meta(get_the_ID(), 'repeatable_download_link', true);
$download_timer = get_theme_mod('download_timer', 7);

foreach($download_links as $index => $download) : if ($index === $download_id) : ?>

<h1 class="p-file-title title">
	<?php esc_html_e('Download', 'Y4Apk'); ?> <?php echo $app_name; ?> [<?php echo $download['download_name']; ?>] <?php echo $download['download_version']; ?>
</h1>
<?php if(function_exists('download_top_ad')) download_top_ad(); ?>
<div class="p-file-hide" id="buttondw">
    <div class="p-file-timer" style="display:none;">
        <span class="p-file-timer-sec fw-b" id="timer">1</span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72" width="72" height="72">
            <defs>
                <linearGradient id="timer-gradient" x1="-512.07" y1="-2048.22" x2="-511.51" y2="-2048.45" gradientTransform="matrix(64, 0, 0, -64, 32808.44, -131050.69)" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#656ed6" stop-opacity="0"></stop>
                    <stop offset="1" stop-color="#39c1e0"></stop>
                </linearGradient>
                <linearGradient id="timer-gradient-2" x1="-511.51" y1="-2048.25" x2="-512.51" y2="-2048.25" gradientTransform="matrix(64, 0, 0, -64, 32804.44, -131055.69)" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#c867f4"></stop>
                    <stop offset="1" stop-color="#e53d2d" stop-opacity="0"></stop>
                </linearGradient>
                <linearGradient id="timer-gradient-3" x1="-511.51" y1="-2048.25" x2="-512.51" y2="-2048.25" gradientTransform="matrix(64, 0, 0, -64, 32800.44, -131047.69)" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#fc7352" stop-opacity="0"></stop>
                    <stop offset="1" stop-color="#f0b835"></stop>
                </linearGradient>
            </defs>
            <circle fill="url(#timer-gradient)" cx="40" cy="37" r="32"></circle>
            <circle fill="url(#timer-gradient-2)" cx="36" cy="32" r="32"></circle>
            <circle fill="url(#timer-gradient-3)" cx="32" cy="40" r="32"></circle>
        </svg>
    </div>
    <div class="p-file-timer-btn" style="opacity:0;pointer-events:none;visibility:hidden;">
        <a class="btn blue-style" id="check" onclick="change_button(<?php echo $download_timer; ?>);" href="<?php echo $download['download_url']; ?>" target="_blank">
			<?php esc_html_e('Download', 'Y4Apk'); ?> (<?php echo $download['download_size']; ?>)
		</a>
    </div>
</div>
<?php if(function_exists('download_bottom_ad')) download_bottom_ad(); ?>
<?php endif; endforeach; ?>