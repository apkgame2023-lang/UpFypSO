<?php
$home_recommend_logo_url = get_theme_mod('home_recommend_logo', get_template_directory_uri() . '/assets/img/recommend-logo.svg');

$recommend_posts = get_theme_mod('recommend_posts', [
	[
		'bg_image' => get_template_directory_uri() . '/assets/img/bg_plans2.webp',
		'bg_color' => '#6BCF6A'
	],
	[
		'bg_image' => get_template_directory_uri() . '/assets/img/bg_rayman.webp',
		'bg_color' => '#F46CF0'
	],
	[
		'bg_image' => get_template_directory_uri() . '/assets/img/bg_clashclans.webp',
		'bg_color' => '#FF9533'
	],
	[
		'bg_image' => get_template_directory_uri() . '/assets/img/bg_surfers.webp',
		'bg_color' => '#FF387D'
	],
]);

if(!empty($recommend_posts)) :
?>
<div class="section section-recommend recommend-anim">
    <div class="recommend-block">
        <div class="recommend-label">
			<?php if($home_recommend_logo_url) : ?>
            <div class="recommend-label-icon"><img src="<?php echo $home_recommend_logo_url; ?>" width="108" height="80" alt="Recommend Section Background" loading="lazy"></div>
			<?php endif; ?>
            <h3 class="recommend-label-title"><?php esc_html_e('Recommend', 'y4apk'); ?></h3>
            <i class="recommend-label-stars"></i>
            <svg class="recommend-star" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
                <path class="recommend-star-border" d="M200,23.35a60,60,0,0,0-53.91,33.57v0a60,60,0,0,1-45.33,32.94h0a60,60,0,0,0-50.59,69.63v.1a60,60,0,0,1-17.3,53.27l-.07.07a60,60,0,0,0,0,86.06l.07.07a60,60,0,0,1,17.3,53.27v.1a60,60,0,0,0,50.59,69.63h0a60,60,0,0,1,45.33,32.94v0A60,60,0,0,0,228,481.69h0a60,60,0,0,1,56.06,0h0a60,60,0,0,0,81.88-26.61v0a60,60,0,0,1,45.33-32.94h0a60,60,0,0,0,50.59-69.63v-.1a60,60,0,0,1,17.3-53.27l.07-.07a60,60,0,0,0,0-86.06l-.07-.07a60,60,0,0,1-17.3-53.27v-.1A60,60,0,0,0,411.29,89.9h0A60,60,0,0,1,365.92,57v0A60,60,0,0,0,284,30.31h0a60,60,0,0,1-28,7,60,60,0,0,1-28-6.95,59.65,59.65,0,0,0-28-7"></path>
                <path class="recommend-star-bg" style="display:none;" fill="#FBFDFD" d="M280.67,57.34a52.85,52.85,0,0,1,72.09,23.45,52.75,52.75,0,0,0,39.93,29,52.81,52.81,0,0,1,44.77,59.78,53.09,53.09,0,0,0,15,48.56,52.8,52.8,0,0,1,1.07,74.67,53.08,53.08,0,0,0-16.35,48.11,52.83,52.83,0,0,1-44.56,61.3,52.82,52.82,0,0,0-39.92,29,52.84,52.84,0,0,1-72.07,23.42,52.78,52.78,0,0,0-49.34,0,52.82,52.82,0,0,1-71.51-22.31,53,53,0,0,0-40.48-30.14,52.81,52.81,0,0,1-44.76-60.09,53,53,0,0,0-15.7-48.86A52.81,52.81,0,0,1,58.64,219a53,53,0,0,0,16-48.76,52.85,52.85,0,0,1,45.23-60.5A52.91,52.91,0,0,0,159.65,80a52.84,52.84,0,0,1,71.68-22.64,52.78,52.78,0,0,0,49.34,0Z"></path>
            </svg>
        </div>
        <div class="recommend-over">
            <div class="recommend-list">
				<?php
				foreach($recommend_posts as $post) :
					$post_name = get_post_meta($post['post_id'], "wp_title_GP", true);
					$post_name = $post_name ?: get_the_title($post['post_id']);
                    $bg_image_url = $post['bg_image'];
                    $bg_color = $post['bg_color'];
				?>
                <div class="recommend-app">
                    <a href="<?php echo get_permalink($post['post_id']); ?>">
                        <div class="recommend-app-img">
                            <figure>
								<img src="<?php echo get_the_post_thumbnail_url($post['post_id'], 'thumbnail'); ?>" width="60" height="60" alt="<?php echo $post_name; ?>" loading="lazy">
							</figure>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 80" width="90" height="80">
                                <path fill="currentColor" d="M20,0A20,20,0,0,0,0,20V80H4v-.26A16,16,0,0,1,19.83,64H44A20,20,0,0,0,64,44V19.74h0A16,16,0,0,1,80,4H90V0Z"></path>
                            </svg>
                        </div>
                        <div class="recommend-app-cont">
                            <div class="recommend-app-cont-in">
                                <span class="recommend-app-title"><?php echo $post_name; ?></span>
                                <span class="recommend-app-download"><?php esc_html_e('Download', 'y4apk'); ?></span>
                            </div>
                        </div>
                        <div class="recommend-app-bg" style="background-color: <?php echo $bg_color; ?>;">
							<img src="<?php echo $bg_image_url; ?>" width="208" height="248" alt="<?php echo $post_name; ?>" decoding="async" loading="lazy">
						</div>
                        <div class="recommend-app-shadow" style="box-shadow: 0 0 2rem 0 <?php echo $bg_color; ?>;"></div>
                    </a>
                </div>
				<?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>