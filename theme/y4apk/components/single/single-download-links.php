<?php
$download_links = get_post_meta(get_the_ID(), 'repeatable_download_link', true);

$hasApk = false; // Variable to track if APK downloads exist
$hasObb = false; // Variable to track if OBB downloads exist
        
if ($download_links) {
	foreach ($download_links as $index => $download) {
		$download_name = strtolower($download['download_name']);
		if ($download_name === 'apk' || $download_name === 'apks' || $download_name === 'xapk' || $download_name === 'apkm' || $download_name === 'ipa' || $download_name === 'ios' || $download_name === 'mod') {
			$hasApk = true;
			break; // Exit the loop if APK download is found
		}
	}
	foreach ($download_links as $index => $download) {
		$download_name = strtolower($download['download_name']);
		if ($download_name === 'obb' || $download_name === 'zip') {
			$hasObb = true;
			break; // Exit the loop if OBB or ZIP download is found
		}
	}
}
        
$grid2_class = "";   // no download files at all
if ($hasApk && $hasObb) {
	// Both APK and OBB exist
	$grid2_class = "grid2";
} elseif ($hasApk) {
	// Only APK exists
	$grid2_class = "";
} elseif ($hasObb) {
	// Only OBB exists
	$grid2_class = "";
}

if(function_exists('single_bottom_ad')) single_bottom_ad();
?>
<div class="f-app-download" id="download-now">
    <?php if (class_exists('Y4APK_Scripts')) Y4APK_Scripts::render(get_the_ID()); // Script Code (2.31): what a script post offers, before any download ?>
    <?php if (class_exists('Y4APK_Share') && Y4APK_Share::is_share(get_the_ID()) && ($y4_by = Y4APK_Members::author(get_the_ID()))) : // a member's game or app (2.44) ?>
    <a class="y4-shared" href="<?php echo esc_url(Y4APK_Members::profile_url($y4_by)); ?>"><?php echo Y4APK_Social::avatar($y4_by, 36); ?><span><small><?php esc_html_e('Shared by', 'y4apk'); ?></small><b><?php echo esc_html(Y4APK_Social::name($y4_by)); ?></b></span></a>
    <?php endif; ?>
    <?php
	// The files (2.53.5): a card for each, in two steps when a game has its data (OBB) apart
	$y4dl = ['apk' => [], 'obb' => []];
	foreach ((array) $download_links as $index => $download) {
		$type = strtolower(trim((string) ($download['download_name'] ?? '')));
		$group = in_array($type, ['apk', 'apks', 'xapk', 'apkm', 'ipa', 'ios', 'mod'], true) ? 'apk' : (in_array($type, ['obb', 'zip'], true) ? 'obb' : '');
		if ($group !== '') {
			$y4dl[$group][$index] = $download;
		}
	}
	$y4dl_steps = $y4dl['apk'] && $y4dl['obb'];
	if ($y4dl['apk'] || $y4dl['obb']) :
		$y4dl_name = (string) get_post_meta(get_the_ID(), 'wp_title_GP', true);
		$y4dl_name = $y4dl_name !== '' ? $y4dl_name : get_the_title();
		$y4dl_icon = function_exists('y4apk_icon_url') ? (string) y4apk_icon_url(get_the_ID(), 'thumbnail') : '';
		$y4dl_down = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6L18 11l-6 6-6-6 1.4-1.4 3.6 3.6V4ZM5 19h14v2H5v-2Z"/></svg>';
		?>
    <section class="y4dl" aria-label="<?php esc_attr_e('Download', 'y4apk'); ?>">
        <div class="y4dl-head">
            <?php if ($y4dl_icon !== '') : ?><img class="y4dl-icon" src="<?php echo esc_url($y4dl_icon, ['http', 'https', 'data']); ?>" width="56" height="56" alt="" loading="lazy" decoding="async"><?php endif; ?>
            <div class="y4dl-head-t">
                <span class="y4dl-eyebrow"><?php esc_html_e('Free download · No account needed', 'y4apk'); ?></span>
                <h2><?php echo esc_html(sprintf(__('Download %s', 'y4apk'), html_entity_decode($y4dl_name, ENT_QUOTES, 'UTF-8'))); ?></h2>
            </div>
        </div>
        <?php foreach (['apk' => [__('Install the game or app', 'y4apk'), ''], 'obb' => [__('Then add its data (OBB)', 'y4apk'), __('Unzip it into Android/obb', 'y4apk')]] as $y4dl_g => $y4dl_label) :
			if (!$y4dl[$y4dl_g]) { continue; } ?>
        <div class="y4dl-group y4dl-g-<?php echo esc_attr($y4dl_g); ?>">
            <?php if ($y4dl_steps) : ?><p class="y4dl-step"><b><?php echo $y4dl_g === 'apk' ? '1' : '2'; ?></b><span><?php echo esc_html($y4dl_label[0]); ?></span><?php if ($y4dl_label[1] !== '') : ?><small><?php echo esc_html($y4dl_label[1]); ?></small><?php endif; ?></p><?php endif; ?>
            <?php foreach ($y4dl[$y4dl_g] as $index => $download) :
				$y4dl_type = strtoupper(trim((string) $download['download_name']));
				$y4dl_type = $y4dl_type === 'IOS' ? 'iOS' : mb_substr($y4dl_type, 0, 4);
				$y4dl_size = trim((string) ($download['download_size'] ?? ''));
				$y4dl_size = in_array($y4dl_size, ['', '—', '-'], true) ? '' : $y4dl_size;
				?>
            <a class="y4dl-file" href="<?php echo esc_url(get_permalink() . 'download/' . $index . '/'); ?>" rel="nofollow">
                <span class="y4dl-type y4dl-t-<?php echo esc_attr(strtolower($y4dl_type)); ?>"><?php echo esc_html($y4dl_type); ?></span>
                <span class="y4dl-info">
                    <b><?php echo esc_html(trim($download['download_name'] . ' ' . ($download['download_version'] ?? ''))); ?></b>
                    <?php if ($y4dl_size !== '') : ?><small><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm7 1.5V9h5.5L13 3.5Z"/></svg><span itemprop="fileSize"><?php echo esc_html($y4dl_size); ?></span></small><?php endif; ?>
                </span>
                <span class="y4dl-go"><span><?php esc_html_e('Download', 'y4apk'); ?></span><?php echo $y4dl_down; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php do_action('y4apk_after_download_links', get_the_ID()); ?>
</div>