<?php
add_action('wp_head', function () {echo '<meta name="robots" content="noindex, nofollow">';}, 1);
get_header();
$download_timer = get_theme_mod('download_timer', 7);
?>
<div class="p-file">
	<div class="section">
		<div class="p-file-back">
			<a href="<?php echo get_permalink(); ?>">
				<svg width="48" height="20">
					<use xlink:href="#i__back"></use>
				</svg>
			</a>
		</div>
		<?php get_template_part('components/download/download-information'); ?>
  </div>
	<?php 
	get_template_part('components/download/download-related-posts'); 
	get_template_part('components/download/download-footer'); 
	?>
</div>
<!-- SVG Icons -->
<svg aria-hidden="true" style="display:none;" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
  <defs>
    <symbol id="i__back" viewBox="0 0 48 20">
      <path fill="currentColor" d="M46.27,9.24a1,1,0,0,0-1.41.1A10.71,10.71,0,0,1,36.78,13h-.16a10.78,10.78,0,0,1-8.1-3.66A12.83,12.83,0,0,0,18.85,5C14.94,5,10.73,7.42,6,12.4V5A1,1,0,0,0,4,5V15a1,1,0,0,0,1,1H15a1,1,0,0,0,0-2H7.24c4.42-4.71,8.22-7,11.62-7A10.71,10.71,0,0,1,27,10.66,12.81,12.81,0,0,0,36.61,15h.18a12.7,12.7,0,0,0,9.58-4.35A1,1,0,0,0,46.27,9.24Z"></path>
    </symbol>
    <symbol id="i__android" viewBox="0 0 24 24">
      <path fill="currentColor" d="M13.5,11.5a.5.5,0,1,1-.5.5.5.5,0,0,1,.5-.5m-3,0a.5.5,0,1,1-.5.5.5.5,0,0,1,.5-.5m-1-3a.51.51,0,0,0-.42.26.52.52,0,0,0,0,.5l.64,1.11A3.52,3.52,0,0,0,8.5,13a1,1,0,0,0,1,1h5a1,1,0,0,0,1-1,3.52,3.52,0,0,0-1.2-2.64l.64-1.11a.51.51,0,0,0-.19-.69.5.5,0,0,0-.68.19l-.61,1.07a3.44,3.44,0,0,0-2.91,0L9.94,8.75a.49.49,0,0,0-.46-.26m-1.23-5h7.5A1.24,1.24,0,0,1,17,4.73V19H7V4.75A1.24,1.24,0,0,1,8.23,3.5h0m0-1.5A2.76,2.76,0,0,0,5.5,4.75v14.5A2.76,2.76,0,0,0,8.25,22h7.5a2.76,2.76,0,0,0,2.75-2.75V4.75A2.76,2.76,0,0,0,15.75,2Z"></path>
    </symbol>
    <symbol id="i__age" viewBox="0 0 24 24">
      <path fill="currentColor" d="M6,15.5H18a.47.47,0,0,1,.49.47v.78a3,3,0,0,1-1.88,2.72,10,10,0,0,1-4.62,1,10,10,0,0,1-4.62-1A3,3,0,0,1,5.5,16.75V16A.47.47,0,0,1,6,15.5H6M6,14a2,2,0,0,0-2,2v.76a4.67,4.67,0,0,0,2.65,4A11.32,11.32,0,0,0,12,22a11.29,11.29,0,0,0,5.35-1.22,4.84,4.84,0,0,0,2.53-3.28H20V16a2,2,0,0,0-2-2ZM12,3.5A3.5,3.5,0,1,1,8.5,7,3.5,3.5,0,0,1,12,3.5h0M12,2a5,5,0,1,0,5,5,5,5,0,0,0-5-5"></path>
    </symbol>
  </defs>
</svg>
<script>
	function countdown(sec) {
		sec--;
		if( sec > 0 ) {
	        document.getElementById("timer").innerHTML = ''+sec+'';
			window.setTimeout("countdown("+sec+")", 1200);
		} else {
			document.getElementById("buttondw").classList.add('del-timer');
		}
 }
		function change_button(sec) {
			sec--;
			if ( sec > 0 ) {
				document.getElementById("check").innerHTML = 'Started...';
				window.setTimeout("change_button("+sec+")", 1000);
			} else {
				document.getElementById("check").innerHTML = 'Download Again';
			}
		}
    
		countdown(<?php echo $download_timer; ?>);
</script>
</body>
</html>