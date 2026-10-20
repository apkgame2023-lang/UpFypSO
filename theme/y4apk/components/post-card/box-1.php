<?php 
$app_name = get_post_meta(get_the_ID(), 'wp_title_GP', true); 
$app_name = $app_name ?: get_the_title();
?>
<div class="app">
    <a class="app-cont" href="<?php the_permalink();?>">
        <figure class="app-img">
            <img src="<?php echo esc_url(y4apk_icon_url(get_the_ID(), 'medium'), ['http', 'https', 'data']); ?>" alt="<?php echo $app_name; ?>" loading="lazy" width="148" height="148">
        </figure>
        <span class="app-title"><?php echo $app_name; ?></span>
    </a>
</div>