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
                <div class="attachment-name"><?php esc_html_e('DOWNLOAD MOD', 'y4apk'); ?></div>
                <?php endif; ?>
                <a class="btn btn-block attachment-link" href="<?php echo get_permalink() . 'download/' . $index; ?>/" rel="nofollow">
					<?php echo $download['download_name']; ?> <?php echo $download['download_version']; ?><?php echo y4apk_size_label(isset($download['download_size']) ? $download['download_size'] : ''); ?>
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
                <div class="attachment-name"><?php esc_html_e('DOWNLOAD OBB', 'y4apk'); ?></div>
                <?php endif; ?>
                <a class="btn btn-block attachment-link" href="<?php echo get_permalink() . 'download/' . $index; ?>/" rel="nofollow">
					<?php echo $download['download_name']; ?> <?php echo $download['download_version']; ?><?php echo y4apk_size_label(isset($download['download_size']) ? $download['download_size'] : ''); ?>
				</a>
                <?php endif; endforeach; if ($hasObb) : // Close the b-grid block if OBB download exists ?>
            </div>
        </div>
        <?php endif; endif; endif; ?>
    </div>
    <?php do_action('y4apk_after_download_links', get_the_ID()); ?>
</div>