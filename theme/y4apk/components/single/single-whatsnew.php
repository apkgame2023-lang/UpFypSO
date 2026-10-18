<?php
// Google Play's "What's new" for the version this post offers (App updates reads it with the version check).
if (!class_exists('Y4APK_App_Updates') || !Y4APK_App_Updates::post_part_on('whatsnew')) return;
$news = Y4APK_App_Updates::whats_new(get_the_ID());
if (!$news) return;
?>
<div class="block f-app-new">
    <div class="f-app-new-head">
        <span class="f-app-new-badge"><?php esc_html_e('NEW', 'y4apk'); ?></span>
        <div>
            <div class="fw-b"><?php printf(esc_html__("What's new in v%s", 'y4apk'), esc_html($news['version'])); ?></div>
            <small><?php echo $news['updated']
                ? esc_html(sprintf(__('From Google Play · updated %s', 'y4apk'), date_i18n(get_option('date_format'), $news['updated'])))
                : esc_html__('From Google Play', 'y4apk'); ?></small>
        </div>
    </div>
    <ul><?php foreach ($news['lines'] as $line) : ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?></ul>
    <?php if (!empty($news['more'])) : ?>
    <details class="f-app-new-more">
        <summary><?php printf(esc_html(_n('Show %s more', 'Show %s more', count($news['more']), 'y4apk')), esc_html(number_format_i18n(count($news['more'])))); ?></summary>
        <ul><?php foreach ($news['more'] as $line) : ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
</div>
