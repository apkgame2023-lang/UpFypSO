<div class="f-app-main-text">
	<?php 
	if(function_exists('single_top_ad')) single_top_ad();
	?>
	<div class="y4-desc" data-y4-desc><?php the_content(); ?></div>
	<?php // 2.66.5: a long article opens like a YouTube description: the start, then "Show more" ?>
	<button type="button" class="y4-desc-more" data-y4-desc-more hidden aria-expanded="false" data-more="<?php esc_attr_e('Show more', 'y4apk'); ?>" data-less="<?php esc_attr_e('Show less', 'y4apk'); ?>"><span><?php esc_html_e('Show more', 'y4apk'); ?></span><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="m7 10 5 5 5-5H7Z"/></svg></button>
	<script>(function(){var d=document.querySelector('[data-y4-desc]'),b=document.querySelector('[data-y4-desc-more]');if(!d||!b)return;
	var cut=function(){d.classList.remove('is-cut');var full=d.scrollHeight;d.classList.add('is-cut');var h=d.clientHeight;if(full<=h+48){d.classList.remove('is-cut');b.hidden=true;return false;}b.hidden=false;return true;};
	var set=function(open){d.classList.toggle('is-cut',!open);d.classList.toggle('is-open',open);b.setAttribute('aria-expanded',open?'true':'false');b.querySelector('span').textContent=open?b.getAttribute('data-less'):b.getAttribute('data-more');};
	var go=function(){if(location.hash&&d.querySelector(location.hash.replace(/[^\w#-]/g,''))){return;}if(cut())set(false);};
	if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',go);else go();
	b.addEventListener('click',function(){var open=d.classList.contains('is-cut');set(open);if(!open){var r=d.getBoundingClientRect();if(r.top<0)window.scrollBy({top:r.top-90,behavior:'smooth'});}});
	d.addEventListener('click',function(e){if(d.classList.contains('is-cut')&&!e.target.closest('a'))set(true);});})();</script>
</div>
