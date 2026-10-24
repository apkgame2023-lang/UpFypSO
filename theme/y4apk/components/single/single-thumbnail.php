<?php
$app_name = get_post_meta(get_the_ID(), 'wp_title_GP', true);
$app_name = $app_name ?: get_the_title();
$screenshots = get_post_meta(get_the_ID(), 'ss_images', true);
if (!$screenshots && class_exists('Y4APK_Roblox') && Y4APK_Roblox::is_roblox_post(get_the_ID())) {
	$screenshots = Y4APK_Roblox::game_shots(get_the_ID()); // a member's script: its game's screenshots from Roblox
}
$screenshots_limit = get_theme_mod('screenshots_limit', 5);
$rating_data = get_star_rating(get_the_ID());
?>
<div class="f-app-view-over">
    <div class="f-app-view">
        <div class="f-app-view-list">
            <div class="f-app-view-intro">
                <div class="f-app-view-img">
                    <figure>
                        <img src="<?php echo esc_url(y4apk_icon_url(), ['http', 'https', 'data']); ?>" width="116" height="116" alt="<?php echo $app_name; ?>" fetchpriority="high" decoding="async">
                    </figure>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 176 120" width="176" height="120">
                        <path fill="currentColor" d="M0,0V4H10A16,16,0,0,1,26,19.74V96a24,24,0,0,0,24,24h76a24,24,0,0,0,24-24V19.74A16,16,0,0,1,166,4h10V0Z"></path>
                    </svg>
                </div>
                <h1 class="f-app-title"><?php the_title(); ?></h1>
                <div class="f-app-view-rate">
                    <div class="rate-star">
                        <div id="rating-layer" data-post_id="<?php the_ID(); ?>" data-rated="false">
                            <div class="rating">
                                <ul class="unit-rating">
                                    <li class="current-rating" style="width:<?php echo $rating_data->rating_width; ?>;"><?php echo $rating_data->rating_width; ?></li>
                                    <li>
                                        <a href="#" title="Useless" class="r1-unit" onclick="doRate('1', '<?php the_ID(); ?>'); return false;">1</a>
                                    </li>
                                    <li>
                                        <a href="#" title="Poor" class="r2-unit" onclick="doRate('2', '<?php the_ID(); ?>'); return false;">2</a>
                                    </li>
                                    <li>
                                        <a href="#" title="Fair" class="r3-unit" onclick="doRate('3', '<?php the_ID(); ?>'); return false;">3</a>
                                    </li>
                                    <li>
                                        <a href="#" title="Good" class="r4-unit" onclick="doRate('4', '<?php the_ID(); ?>'); return false;">4</a>
                                    </li>
                                    <li>
                                        <a href="#" title="Excellent" class="r5-unit" onclick="doRate('5', '<?php the_ID(); ?>'); return false;">5</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <span class="fw-b"><?php echo $rating_data->rating_average; ?></span> (<span id="vote-num"><?php echo number_format($rating_data->rating_votes, 0, ",", ","); ?></span>)
                </div>
                <?php if (class_exists('Y4APK_App_Updates') && Y4APK_App_Updates::post_part_on('fresh')) :
                    $fresh = Y4APK_App_Updates::freshness(get_the_ID()); ?>
                <div class="f-app-fresh"<?php if ($fresh['same']) : ?> title="<?php echo esc_attr(sprintf(__('v%s — the same version as on Google Play', 'y4apk'), $fresh['version'])); ?>"<?php endif; ?>><span class="f-app-fresh-dot" aria-hidden="true"></span><span class="f-app-fresh-text"><?php
                    printf(esc_html__('Updated %s ago', 'y4apk'), esc_html($fresh['ago']));
                    if ($fresh['same']) {
                        echo ' · ✓ ' . esc_html__('Latest version', 'y4apk');
                    } ?></span></div>
                <?php endif; ?>
                <div class="f-app-view-dw">
                    <a class="btn blue-style anchor" href="#download-now"><?php esc_html_e('Download', 'y4apk'); ?> ↓</a>
                </div>
                <div class="f-app-view-intro-bg">
                    <img src="<?php echo esc_url(y4apk_icon_url(), ['http', 'https', 'data']); ?>" width="116" height="116" alt="Background" loading="lazy">
                </div>
                <svg class="intro-scroll" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 72" width="36" height="72" style="display:none;">
                    <path fill="#fff" d="M31.31,11.3A15.88,15.88,0,0,1,20,16h0a20,20,0,0,0,0,40h0A16,16,0,0,1,36,72V56h0V0A15.88,15.88,0,0,1,31.31,11.3Z"></path>
                    <path class="intro-scroll-arrow" fill="currentColor" d="M12.75,32.25a.75.75,0,0,0-.53.22l-3,3a.75.75,0,0,0,0,1.06l3,3a.75.75,0,0,0,1.06-1.06l-1.72-1.72H22.25a.75.75,0,0,0,0-1.5H11.56l1.72-1.72a.75.75,0,0,0-.53-1.28"></path>
                    <path fill="currentColor" d="M23.5,28.5a7.53,7.53,0,0,0-7.29,5.75h1.55a6,6,0,1,1,0,3.5H16.21A7.5,7.5,0,1,0,23.5,28.5"></path>
                </svg>
            </div>
            <?php if ($screenshots) : ?>
            <div class="f-app-view-screens">
                <ul class="xfieldimagegallery screenshots">
                    <?php
					foreach ($screenshots as $index => $screenshot) :
						if ($index >= $screenshots_limit) break;        	
					?>
                    <li>
						<a href="<?php echo esc_url($screenshot['ss_url']); ?>" target="_blank">
							<img width="100%" height="auto" src="<?php echo esc_url($screenshot['ss_url']); ?>" alt="<?php echo esc_attr(wp_strip_all_tags($app_name)); ?>" loading="lazy">
						</a>
					</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>