<?php
$app_mod_feature = get_post_meta(get_the_ID(), 'wp_mods', true);
$app_os = get_post_meta(get_the_ID(), 'platform', true);

if (empty($app_os)) {
	$app_os = 'ANDROID';
} else {
	$app_os = ucfirst(strtolower($app_os));
}
?>
<div class="p-file-cont section">
	<?php 
	get_template_part('components/download/download-thumbnail');
	get_template_part('components/download/download-link'); 
	?>
</div>
<?php if($app_mod_feature) : ?>
<div class="p-file-novirus block">
	<svg class="p-file-novirus-shield" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48" height="48">
		<defs>
			<linearGradient id="shield-gradient" x1="-31" y1="-88.25" x2="-32.14" y2="-87.52" gradientTransform="matrix(38, 0, 0, -45, 1225.03, -3931.84)" gradientUnits="userSpaceOnUse">
				<stop offset="0" stop-color="#6c92e6"></stop>
				<stop offset="1" stop-color="#6bd65d"></stop>
			</linearGradient>
		</defs>
		<path fill="url(#shield-gradient)" d="M31.47,18a1.54,1.54,0,0,0-1,.46L21.5,27.38l-3.94-3.94a1.5,1.5,0,0,0-2.12,2.12l5,5a1.49,1.49,0,0,0,2.12,0l10-10a1.5,1.5,0,0,0,0-2.12A1.56,1.56,0,0,0,31.47,18M24,5.43a30.11,30.11,0,0,0,16,6.45V22.76c0,5.74-2.21,15-16,21.07C10.21,37.77,8,28.5,8,22.76V11.88A30.11,30.11,0,0,0,24,5.43M24.05,2A1.5,1.5,0,0,0,23,2.38S15.49,9,6.5,9A1.5,1.5,0,0,0,5,10.5V22.76c0,6.68,3.09,17.67,18.42,24.12a1.46,1.46,0,0,0,1.16,0C39.91,40.43,43,29.44,43,22.76V10.5A1.5,1.5,0,0,0,41.5,9C32.51,9,25,2.38,25,2.38A1.51,1.51,0,0,0,24.05,2"></path>
	</svg>
	<div class="fw-b"><?php esc_html_e('MOD INFORMATION:'); ?></div>
	<p><?php echo $app_mod_feature; ?></p>
</div>
<?php endif; ?>