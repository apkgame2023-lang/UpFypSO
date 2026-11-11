<?php
global $custom_download_id;
$download_id = absint($custom_download_id);

$app_name = get_post_meta( $post->ID, 'wp_title_GP', true );
$app_name = $app_name ?: get_the_title();
$download_links = get_post_meta(get_the_ID(), 'repeatable_download_link', true);
$download_links = is_array($download_links) ? $download_links : []; // a post without download links stores ''
if (class_exists('Y4APK_Paid') && Y4APK_Paid::locked(get_the_ID())) { // 2.69: a paid file, not unlocked
	echo Y4APK_Paid::card(get_the_ID(), 'file'); // phpcs:ignore WordPress.Security.EscapeOutput
	$download_links = [];
}
$download_timer = function_exists('y4apk_is_vip') && y4apk_is_vip() ? 1 : get_theme_mod('download_timer', 7); // VIP (2.62): no countdown

foreach($download_links as $index => $download) : if ($index === $download_id) : ?>

<h1 class="p-file-title title">
	<?php esc_html_e('Download', 'Y4Apk'); ?> <?php echo $app_name; ?> [<?php echo $download['download_name']; ?>] <?php echo $download['download_version']; ?>
</h1>
<?php if(function_exists('download_top_ad')) download_top_ad(); ?>
<?php if (class_exists('Y4APK_Links') && Y4APK_Links::gone(get_the_ID(), $index)) : // 2.75: the file is gone: say so, never a "not found" page
	$y4_others = [];
	foreach ($download_links as $y4_i => $y4_d) {
		if ($y4_i !== $index && is_array($y4_d) && trim((string) ($y4_d['download_url'] ?? '')) !== '' && !Y4APK_Links::gone(get_the_ID(), $y4_i)) {
			$y4_others[$y4_i] = trim(($y4_d['download_name'] ?? '') . ' ' . ($y4_d['download_version'] ?? ''));
		}
	}
	?>
<div class="y4-dl-gone" role="status">
	<svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
	<b><?php esc_html_e('This file is not available right now', 'y4apk'); ?></b>
	<p><?php esc_html_e('It is no longer on our server. We already know and a new one is on the way: come back later, or take another download below.', 'y4apk'); ?></p>
	<?php if ($y4_others) : ?>
	<div class="y4-dl-gone-alt">
		<?php foreach ($y4_others as $y4_i => $y4_label) : ?>
		<a class="btn blue-style" href="<?php echo esc_url(get_permalink() . 'download/' . (int) $y4_i . '/'); ?>" rel="nofollow"><?php esc_html_e('Download', 'Y4Apk'); ?> <?php echo esc_html($y4_label); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<a class="y4-dl-gone-back" href="<?php echo esc_url(get_permalink()); ?>">← <?php echo esc_html(sprintf(__('Back to %s', 'y4apk'), wp_strip_all_tags($app_name))); ?></a>
</div>
<?php else : ?>
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
        <a class="btn blue-style" id="check" onclick="change_button(<?php echo $download_timer; ?>);" href="<?php echo esc_url(class_exists('Y4APK_Paid') && Y4APK_Paid::price(get_the_ID()) > 0 ? Y4APK_Paid::file_link(get_the_ID(), $index) : (class_exists('Y4APK_VIP') ? Y4APK_VIP::file_url($download['download_url']) : $download['download_url'])); // 2.69.1: a paid file: a link that expires ?>" target="_blank">
			<?php esc_html_e('Download', 'Y4Apk'); ?><?php echo y4apk_size_label(isset($download['download_size']) ? $download['download_size'] : ''); ?>
		</a>
    </div>
</div>
<?php endif; // 2.75 ?>
<?php if (class_exists('Y4APK_VIP') && !Y4APK_VIP::is_vip()) : // 2.62: the way round the wait ?>
<a class="y4-vip-cta" href="<?php echo esc_url(Y4APK_VIP::url()); ?>"><?php echo Y4APK_VIP::crown(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('No wait, no ads: get VIP', 'y4apk'); ?></span></a>
<?php endif; ?>
<?php if(function_exists('download_bottom_ad')) download_bottom_ad(); ?>
<?php endif; endforeach; ?>