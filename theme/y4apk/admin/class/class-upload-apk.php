<?php
class UploadAPK{
    private $post_id;
    private $apk;
    private $obb;
    private $patch;
    private $download_info = [];
    private $upload_dir;
    private $response = [];

    public function __construct($post_id, $apk, $obb, $patch){
        $this->post_id = $post_id;
        $this->apk = $apk;
        $this->obb = $obb;
        $this->patch = $patch;
        $this->upload_dir = wp_upload_dir();
    }   

    private function upload_storage($file_dl, $file_version){
        $file_storage = get_option('at_upload_storage', 'my-server');

        switch ($file_storage) {
            case 'my-server':
                $upload = $this->storageWP($file_dl, $file_version);
                break;
            case 'gdrive':
                $upload = $this->storageGD($file_dl, $file_version);
                break;
            case 'ftp':
                $upload = $this->storageFTP($file_dl, $file_version);
                break;
        }

        return $upload;
    }

    private function storageWP($file_dl, $file_version){
        if (isset($file_dl) && !empty($file_dl)) {
            $file = file_get_contents($file_dl);

            $file_name = pathinfo($file_dl, PATHINFO_FILENAME);
            $file_full_name = pathinfo($file_dl, PATHINFO_BASENAME);
            $file_extension = pathinfo($file_dl, PATHINFO_EXTENSION);
            $file_mime = wp_check_filetype($file_full_name, null);
            $file_path = $this->upload_dir['path'] . '/' . $file_full_name;

            $attachment = array(
                'post_title' => $file_name,
                'post_mime_type' => $file_mime["type"],
                'post_content' => '',
                'post_status' => 'inherit'
            );
            if ($file) {
                $existing_attachment_id = attachment_url_to_postid($this->upload_dir["url"] . "/" . $file_full_name);
                
                require_once ABSPATH . 'wp-admin/includes/image.php';

                if ($existing_attachment_id) {
                    $attachment_id = wp_update_attachment_metadata( $existing_attachment_id, $attachment, $this->post_id );
                    $this->response['warning']['text'] = __('File already exists!', 'y4apk');
                } else {
                    file_put_contents($file_path, $file);
                    $attachment_id = wp_insert_attachment($attachment, $file_path);
                }

                if (!is_wp_error($attachment_id)) {
                    $file_url = $this->upload_dir['url'] . '/' . $file_full_name;
					$file_size = bytes_converter(wp_filesize($this->upload_dir['path'] . '/' . $file_full_name));
                    $download_info_data = [
                        'download_name' => strtoupper($file_extension),
                        'download_version' => $file_version,
                        'download_mod_info' => y4apk_options('mod_feature', true),
                        'download_size' => $file_size,
                        'download_url' => $file_url,
                    ];
                
                    array_push($this->download_info, $download_info_data);
                
                    $this->set_download_links();
                
                    $this->response['success']['text'] = strtoupper($file_extension) . __(' File uploaded to your server.', 'y4apk');
                } else {
                    $this->response['warning']['text'] = __('File not uploaded due to WordPress error.', 'y4apk');
                }
            } else {
                $this->response['warning']['text'] = __('File not found or protected URL!', 'y4apk');
            }
        } else {
            $this->response['warning']['text'] = __('File direct URL not found or invalid', 'y4apk');
        }
    }

    private function storageGD($file_dl, $file_version){
        if( !get_option('at_gdrive_token', null) ) {
            $this->response['warning']['text'] = __( 'You have not connected to Google Drive', 'y4apk' );
            return;
        }
        $gdrive = new Y4APK_GoogleDrive();
        $file = file_get_contents($file_dl);
        $folder_name = get_option('at_gdrive_folder_name', true);
        $file_name = pathinfo($file_dl, PATHINFO_FILENAME);
        $file_full_name = pathinfo($file_dl, PATHINFO_BASENAME);
        $file_extension = pathinfo($file_dl, PATHINFO_EXTENSION);
        $file_mime = wp_check_filetype($file_full_name, null);

        $folder_id = ($folder_name) ? $gdrive->createFolder($folder_name) : null;
        if ($file !== false) {
            $temporary_file_path = wp_tempnam($file_full_name);
            $save_temp_file = file_put_contents($temporary_file_path, $file);

            if ($save_temp_file !== false) {
                $upload_result = $gdrive->insertFileToDrive($temporary_file_path, $file_full_name, $folder_id);
                if(isset($upload_result['url'])){
                    $file_url = $upload_result['url'];
					$file_size = bytes_converter(wp_filesize($temporary_file_path));
					
                    $download_info_data = [
                        'download_name' => strtoupper($file_extension),
                        'download_version' => $file_version,
                        'download_mod_info' => y4apk_options('mod_feature', true),
                        'download_size' => $file_size,
                        'download_url' => $file_url,
                    ];

                    array_push($this->download_info, $download_info_data);

                    $this->set_download_links();

                    $this->response['success']['text'] = strtoupper($file_extension) . __(' File uploaded to Google Drive.', 'y4apk');
                } else if (isset($upload_result['error'])) {
                    $error_message = $upload_result['error'];
                    $this->response['warning']['text'] = __($error_message, 'y4apk');
                }
                unlink($temporary_file_path);
            } else {
                $this->response['warning']['text'] = __('Failed to save the file to the temporary path.', 'y4apk');
            }
        } else {
            $this->response['warning']['text'] = __('Failed to download the file.', 'y4apk');
        }
    }

    private function storageFTP($file_dl, $file_version) {
        if (!y4apk_options('ftp_server_ip', true) || !y4apk_options('ftp_username', true) || !y4apk_options('ftp_password', true) || !y4apk_options('ftp_url', true)) {
            $this->response['warning']['text'] = __('Enter FTP connection details to upload files to the FTP server.', 'y4apk');
            return;
        }
    
        $ftp = new FTP();
		$file = file_get_contents($file_dl);
        $file_name = pathinfo($file_dl, PATHINFO_FILENAME);
        $file_full_name = pathinfo($file_dl, PATHINFO_BASENAME);
        $file_extension = pathinfo($file_dl, PATHINFO_EXTENSION);
    
        if ($file !== false) {
            $temporary_file_path = wp_tempnam($file_full_name);
            $save_temp_file = file_put_contents($temporary_file_path, $file);
    
            if ($save_temp_file !== false) {
                $upload_result = $ftp->Upload($temporary_file_path, $file_full_name);

                if (isset($upload_result['url'])) {
                    $file_url = $upload_result['url'];
					$file_size = bytes_converter(wp_filesize($temporary_file_path));

                    $download_info_data = [
                        'download_name' => strtoupper($file_extension),
                        'download_version' => $file_version,
                        'download_mod_info' => y4apk_options('mod_feature', true),
                        'download_size' => $file_size,
                        'download_url' => $file_url,
                    ];

                    array_push($this->download_info, $download_info_data);

                    $this->set_download_links();

                    $this->response['success']['text'] = strtoupper($file_extension) . __(' File uploaded to FTP server successfully.', 'y4apk');
                } elseif (isset($upload_result['error'])) {
                    $error_message = $upload_result['error'];
                    $this->response['warning']['text'] = __('File FTP upload failed. Error: ', 'y4apk') . $error_message;
                } else {
                    $this->response['warning']['text'] = __('FTP process done with errors. Check the FTP credentials.', 'y4apk');
                }    
                unlink($temporary_file_path);
            } else {
                $this->response['warning']['text'] = __('Failed to save the file to the temporary path.', 'y4apk');
            }
        } else {
            $this->response['warning']['text'] = __('Failed to download the file.', 'y4apk');
        }
    }

    private function set_download_links(){    
        update_post_meta($this->post_id, "repeatable_download_link", $this->download_info);
        
    }
    
    public function upload_apk(){
        if (isset($this->apk) && $this->apk !== null) {
            $this->upload_storage($this->apk['apk_file'], $this->apk['apk_file_version']);
        }
        return json_encode($this->response);
    }
}