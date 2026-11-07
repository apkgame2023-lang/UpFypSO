<?php
$is_home_seo = true;

$home_seo_title = __('Download free games for Android', 'y4apk');

$home_seo_description = __('Y4Apk is a trusted platform for downloading MOD APK games and premium apps for Android. Discover the latest modded games and unlocked premium applications with enhanced features, unlimited resources, and ad-free experiences. All APK files available on Y4Apk are carefully tested to ensure they are safe, secure, and 100% working. Enjoy fast downloads, regular updates, and a huge collection of the best Android games and apps completely free.', 'y4apk');

if($is_home_seo) :
?>

<div class="section section-seo">
<h2 class="section-seo-title"><?php echo $home_seo_title; ?></h2>
<div class="section-seo-text"><?php echo $home_seo_description; ?></div>
</div>

<?php endif; ?>