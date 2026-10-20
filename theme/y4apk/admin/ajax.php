<?php
function y4apk_create_post() {
	$nonce = sanitize_text_field( $_POST['nonce'] );
	if ( ! wp_verify_nonce( $nonce, 'y4apk_nonce' ) ) return json_encode(['error' => 'Khatam! Tata! Good Bye!']);

	$apk_url = sanitize_url($_POST['gp_url']);
	$post_title_start = sanitize_text_field($_POST['post_title_start']);
	$post_title_end = sanitize_text_field($_POST['post_title_end']);
	$mod_feature = sanitize_text_field($_POST['mod_feature']);

	update_option('at_post_title_start', $post_title_start) || add_option('at_post_title_start', $post_title_start);
	update_option('at_post_title_end', $post_title_end) || add_option('at_post_title_end', $post_title_end);
	update_option('at_mod_feature', $mod_feature) || add_option('at_mod_feature', $mod_feature);

    $sk = new SK;
    echo $sk->create_post($apk_url);
	exit;
}
add_action( 'wp_ajax_y4apk_create_post', 'y4apk_create_post' );
add_action( 'wp_ajax_nopriv_y4apk_create_post', 'y4apk_create_post' );

function y4apk_upload_apk() {
	$nonce = sanitize_text_field( $_POST['nonce'] );
	if ( ! wp_verify_nonce( $nonce, 'y4apk_nonce' ) ) return json_encode(['error' => 'Khatam! Tata! Good Bye!']);

	$post_id = sanitize_text_field( $_POST['post_id'] );
	$apk = isset($_POST['apk']) ? $_POST['apk'] : null;
	$obb = isset($_POST['obb']) ? $_POST['obb'] : null;
	$patch = isset($_POST['patch']) ? $_POST['patch'] : null;	

    $ua = new UploadAPK($post_id, $apk, $obb, $patch);
    echo $ua->upload_apk();
	exit;
}
add_action( 'wp_ajax_y4apk_upload_apk', 'y4apk_upload_apk' );
add_action( 'wp_ajax_nopriv_y4apk_upload_apk', 'y4apk_upload_apk' );

function action_get_filesize() {
	$nonce = sanitize_text_field( $_POST['nonce'] );
	if ( ! wp_verify_nonce( $nonce, 'y4apk_nonce' ) ) return json_encode(['error' => 'Khatam! Tata! Good Bye!']);
	
	$progress = get_option('file_progress');
	
	echo json_encode($progress);
	exit;
}
add_action( 'wp_ajax_action_get_filesize', 'action_get_filesize' );
add_action( 'wp_ajax_nopriv_action_get_filesize', 'action_get_filesize' );

function save_y4apk_settings() {
	$nonce = sanitize_text_field( $_POST['nonce'] );
	if ( ! wp_verify_nonce( $nonce, 'y4apk_nonce' ) ) return json_encode(['error' => 'Khatam! Tata! Good Bye!']);

	$is_mod_title = $_POST['is_mod_title'];
	$is_mod_feature_title = $_POST['is_mod_feature_title'];
	$is_title_version = $_POST['is_title_version'];
	$is_blocks_content = $_POST['is_blocks_content'];
	$is_get_apk = $_POST['is_get_apk'];
	$upload_storage = sanitize_text_field($_POST['upload_storage']);
	$gdrive_client_id = sanitize_text_field($_POST['gdrive_client_id']);
	$gdrive_client_secret = sanitize_text_field($_POST['gdrive_client_secret']);
	$gdrive_folder_name = sanitize_text_field($_POST['gdrive_folder_name']);
	$ftp_server_ip = sanitize_text_field($_POST['ftp_server_ip']);
	$ftp_port = sanitize_text_field($_POST['ftp_port']);
	$ftp_username = sanitize_text_field($_POST['ftp_username']);
	$ftp_password = sanitize_text_field($_POST['ftp_password']);
	$ftp_directory = sanitize_text_field($_POST['ftp_directory']);
	$ftp_url = sanitize_text_field($_POST['ftp_url']);

	update_option('at_is_mod_title', $is_mod_title) || add_option('at_is_mod_title', $is_mod_title);
	update_option('at_is_mod_feature_title', $is_mod_feature_title) || add_option('at_is_mod_feature_title', $is_mod_feature_title);
	update_option('at_is_title_version', $is_title_version) || add_option('at_is_title_version', $is_title_version);
	update_option('at_is_blocks_content', $is_blocks_content) || add_option('at_is_blocks_content', $is_blocks_content);
	update_option('at_is_get_apk', $is_get_apk) || add_option('at_is_get_apk', $is_get_apk);
	update_option('at_upload_storage', $upload_storage) || add_option('at_upload_storage', $upload_storage);
	update_option('at_gdrive_client_id', $gdrive_client_id) || add_option('at_gdrive_client_id', $gdrive_client_id);
	update_option('at_gdrive_client_secret', $gdrive_client_secret) || add_option('at_gdrive_client_secret', $gdrive_client_secret);
	update_option('at_gdrive_folder_name', $gdrive_folder_name) || add_option('at_gdrive_folder_name', $gdrive_folder_name);
	update_option('at_ftp_server_ip', $ftp_server_ip) || add_option('at_ftp_server_ip', $ftp_server_ip);
	update_option('at_ftp_port', $ftp_port) || add_option('at_ftp_port', $ftp_port);
	update_option('at_ftp_username', $ftp_username) || add_option('at_ftp_username', $ftp_username);
	update_option('at_ftp_password', $ftp_password) || add_option('at_ftp_password', $ftp_password);
	update_option('at_ftp_directory', $ftp_directory) || add_option('at_ftp_directory', $ftp_directory);
	update_option('at_ftp_url', $ftp_url) || add_option('at_ftp_url', $ftp_url);
}

add_action('wp_ajax_save_y4apk_settings', 'save_y4apk_settings');
add_action('wp_ajax_nopriv_save_y4apk_settings', 'save_y4apk_settings');