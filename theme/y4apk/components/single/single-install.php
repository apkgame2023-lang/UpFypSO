<?php
// Install steps under the downloads: the APK, plus the OBB folder when the post has an OBB.
if (!class_exists('Y4APK_App_Updates') || !Y4APK_App_Updates::post_part_on('install')) return;
$links = get_post_meta(get_the_ID(), 'repeatable_download_link', true);
$apk = $obb = null;
foreach (is_array($links) ? $links : [] as $l) {
    $type = strtolower(trim(isset($l['download_name']) ? (string) $l['download_name'] : ''));
    if (!$apk && Y4APK_App_Updates::is_apk($l)) $apk = $l;
    if (!$obb && in_array($type, ['obb', 'zip'], true)) $obb = $l;
}
if (!$apk) return;
$package = (string) get_post_meta(get_the_ID(), 'wp_GP_ID', true);
$requires = (string) get_post_meta(get_the_ID(), 'wp_requires_GP', true);
$file = function ($l) { return trim($l['download_name'] . ' ' . $l['download_version']) . (!empty($l['download_size']) && !in_array(trim($l['download_size']), ['—', '-'], true) ? ' (' . $l['download_size'] . ')' : ''); };
?>
<details class="block f-app-install" open>
    <summary><span class="fw-b"><?php esc_html_e('How to install', 'y4apk'); ?></span>
        <small><?php echo $obb ? esc_html__('APK + OBB · about 2 minutes', 'y4apk') : esc_html__('APK · about 1 minute', 'y4apk'); ?></small></summary>
    <ol>
        <li><b><?php echo $obb ? esc_html__('Download both files above:', 'y4apk') : esc_html__('Download the file above:', 'y4apk'); ?></b>
            <?php echo esc_html($file($apk) . ($obb ? ' + ' . $file($obb) : '')); ?>.</li>
        <li><b><?php esc_html_e('Install the APK.', 'y4apk'); ?></b> <?php esc_html_e('If Android asks, allow “Install unknown apps” for your browser.', 'y4apk'); ?></li>
        <?php if ($obb) : ?>
        <li><b><?php esc_html_e('Unzip the OBB', 'y4apk'); ?></b> <?php esc_html_e('and move the folder', 'y4apk'); ?>
            <code class="pkg"><?php echo esc_html($package !== '' ? $package : __('named after the game', 'y4apk')); ?></code> <?php esc_html_e('to', 'y4apk'); ?> <code>Android/obb/</code>.</li>
        <?php endif; ?>
        <li><b><?php esc_html_e('Open the game.', 'y4apk'); ?></b>
            <?php if ($requires !== '') printf(esc_html__('Needs Android %s or newer.', 'y4apk'), esc_html($requires)); ?></li>
    </ol>
</details>
