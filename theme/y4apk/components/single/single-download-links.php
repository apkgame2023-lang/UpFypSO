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
// The look (2.53.6): an arrow in each button, the size apart in a chip, a badge over each box's heading
$y4dl_arrow = '<svg class="dl-arrow" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6L18 11l-6 6-6-6 1.4-1.4 3.6 3.6V4ZM5 19h14v2H5v-2Z"/></svg>';
$y4dl_badge = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M11 4h2v9.2l3.6-3.6L18 11l-6 6-6-6 1.4-1.4 3.6 3.6V4ZM5 19h14v2H5v-2Z"/></svg>';
?>
<div class="f-app-download" id="download-now">
    <?php if (class_exists('Y4APK_Scripts')) Y4APK_Scripts::render(get_the_ID()); // Script Code (2.31): what a script post offers, before any download ?>
    <?php if (class_exists('Y4APK_Share') && Y4APK_Share::is_share(get_the_ID()) && ($y4_by = Y4APK_Members::author(get_the_ID()))) : // a member's game or app (2.44) ?>
    <a class="y4-shared" href="<?php echo esc_url(Y4APK_Members::profile_url($y4_by)); ?>"><?php echo Y4APK_Social::avatar($y4_by, 36); ?><span><small><?php esc_html_e('Shared by', 'y4apk'); ?></small><b><?php echo esc_html(Y4APK_Social::name($y4_by)); ?></b></span></a>
    <?php endif; ?>
    <div class="grid-list <?php echo $grid2_class; ?>">
		<?php 
		if (!empty($download_links)) :
			$hasApk = false; // reset Variable to track if APK downloads exist
			foreach ($download_links as $index => $download) :
				$download_name = strtolower($download['download_name']);
				if ($download_name === 'apk' || $download_name === 'apks' || $download_name === 'xapk' || $download_name === 'apkm' || $download_name === 'ipa' || $download_name === 'ios' || $download_name === 'mod') :
					if (!$hasApk) :
						// Display the b-grid block only once if APK downloads exist
						$hasApk = true;
		?>
        <div class="b-grid">
            <div class="block f-app-dw">
                <span class="muted"></span>
                <span class="dl-badge" aria-hidden="true"><?php echo $y4dl_badge; ?></span>
                <div class="attachment-name"><?php esc_html_e('DOWNLOAD MOD', 'y4apk'); ?></div>
                <?php endif; ?>
                <a class="btn btn-block attachment-link" href="<?php echo get_permalink() . 'download/' . $index; ?>/" rel="nofollow">
					<?php echo $y4dl_arrow; ?><span class="dl-name"><?php echo esc_html(trim($download['download_name'] . ' ' . ($download['download_version'] ?? ''))); ?></span><?php echo y4apk_dl_size(isset($download['download_size']) ? $download['download_size'] : ''); ?>
				</a>
                <?php endif; endforeach; if ($hasApk) : // Close the b-grid block if APK downloads exist ?>
            </div>
        </div>
        <?php endif; 
		if (!empty($download_links)) :
			$hasObb = false; // reset Variable to track if OBB download exists
			foreach ($download_links as $index => $download) :
				$download_name = strtolower($download['download_name']);
					if ($download_name === 'obb' || $download_name === 'zip') : // Check if download_name is "OBB" or "zip"
						if (!$hasObb) : // Display the b-grid block if OBB download exists
							$hasObb = true;
		?>
        <div class="b-grid">
            <div class="block f-app-obb">
                <span class="muted"></span>
                <span class="dl-badge" aria-hidden="true"><?php echo $y4dl_badge; ?></span>
                <div class="attachment-name"><?php esc_html_e('DOWNLOAD OBB', 'y4apk'); ?></div>
                <?php endif; ?>
                <a class="btn btn-block attachment-link" href="<?php echo get_permalink() . 'download/' . $index; ?>/" rel="nofollow">
					<?php echo $y4dl_arrow; ?><span class="dl-name"><?php echo esc_html(trim($download['download_name'] . ' ' . ($download['download_version'] ?? ''))); ?></span><?php echo y4apk_dl_size(isset($download['download_size']) ? $download['download_size'] : ''); ?>
				</a>
                <?php endif; endforeach; if ($hasObb) : // Close the b-grid block if OBB download exists ?>
            </div>
        </div>
        <?php endif; endif; endif; ?>
    </div>
    <?php do_action('y4apk_after_download_links', get_the_ID()); ?>
</div>