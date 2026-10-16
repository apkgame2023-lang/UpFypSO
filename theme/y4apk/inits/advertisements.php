<?php
function home_top_ad(){
	$is_home_top_ad = get_theme_mod("home_top_ads_swt", false);
	$home_top_ad_code = get_theme_mod("home_top_ads");
	if($is_home_top_ad){
		echo '<div style="margin-bottom: 1.5rem;" class="advertisement">';
		echo $home_top_ad_code;
		echo '</div>';
	}
}

function home_bottom_ad(){
	$is_home_btm_ad = get_theme_mod("home_botm_ads_swt", false);
	$home_btm_ad_code = get_theme_mod("home_botm_ads");
	if($is_home_btm_ad){
		echo '<div style="margin-top: 1.5rem;" class="advertisement">';
		echo $home_btm_ad_code;
		echo '</div>';
	}
}

function single_top_ad(){
	$is_single_top_ad = get_theme_mod('single_top_ads_swt', false); 
	$single_top_ad_code = get_theme_mod("single_top_ads");
	if($is_single_top_ad){
		echo '<div style="margin: 1.5rem 0;" class="advertisement">';
		echo $single_top_ad_code;
		echo '</div>';
	}
}

function single_bottom_ad(){
	$is_single_bottom_ad = get_theme_mod('single_botm_ads_swt', false); 
	$single_bottom_ad_code = get_theme_mod("single_botm_ads");
	if($is_single_bottom_ad){
		echo '<div style="margin: 1.5rem 0;" class="advertisement">';
		echo $single_bottom_ad_code;
		echo '</div>';
	}
}

function archive_top_ad(){
	$is_archive_top_ad = get_theme_mod('archive_top_ads_swt', false);
	$archive_top_ad_code = get_theme_mod('archive_top_ads');
	if($is_archive_top_ad){
		echo '<div style="margin: 1rem 0; position: relative;" class="advertisement">';
		echo $archive_top_ad_code;
		echo '</div>';
	}
}

function archive_bottom_ad(){
	$is_archive_bottom_ad = get_theme_mod('archive_botm_ads_swt', false);
	$archive_bottom_ad_code = get_theme_mod('archive_botm_ads');
	if($is_archive_bottom_ad){
		echo '<div style="margin: 1rem 0;" class="advertisement">';
		echo $archive_bottom_ad_code;
		echo '</div>';
	}
}

function download_top_ad(){
	$is_download_top_ad = get_theme_mod('download_top_ads_swt', false);
	$download_top_ad_code = get_theme_mod('download_top_ads');
	if($is_download_top_ad){
		echo '<div style="margin: 1.5rem 0;" class="advertisement">';
		echo $download_top_ad_code;
		echo '</div>';
	}
}

function download_bottom_ad(){
	$is_download_bottom_ad = get_theme_mod('download_botm_ads_swt', false);
	$download_bottom_ad_code = get_theme_mod('download_botm_ads');
	if($is_download_bottom_ad){
		echo '<div style="margin: 1.5rem 0;" class="advertisement">';
		echo $download_bottom_ad_code;
		echo '</div>';
	}
}