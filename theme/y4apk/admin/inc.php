<?php
$theme_dir = get_template_directory();
require_once $theme_dir . '/admin/template-actions.php';
require_once $theme_dir . '/admin/ajax.php';
require_once $theme_dir . '/admin/class/class-sk.php';
require_once $theme_dir . '/admin/class/class-upload-apk.php';
require_once $theme_dir . '/admin/class/class-scraper.php';
require_once $theme_dir . '/admin/class/class-gdrive.php';
require_once $theme_dir . '/admin/class/class-ftp.php';

function y4apk_admin_scripts(){
    if (isset($_GET['page']) && in_array($_GET['page'], array('y4apk-gplay', 'y4apk-settings'))) {
        wp_enqueue_style('bootstrap-css', 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css');
        wp_enqueue_style('admin-at', get_template_directory_uri() . '/assets/css/admin/at.min.css', array('bootstrap-css'), Y4APK_THEME_VERSION);

        wp_enqueue_script('admin-at', get_template_directory_uri() . '/assets/js/admin/at.min.js', array('jquery'), Y4APK_THEME_VERSION, true);

        wp_localize_script(
            'admin-at', // JS script name must need
            'y4apk_ajax_vars',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('y4apk_nonce')
            )
        );
    }
}
add_action('admin_enqueue_scripts', 'y4apk_admin_scripts');

function y4apk_admin_menus()
{
    $menu_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M6 2H18C19.1 2 20 2.9 20 4V7.32C20 7.87 19.55 8.32 19 8.32H5C4.45 8.32 4 7.87 4 7.32V4C4 2.9 4.9 2 6 2Z" fill="#292D32"/><path d="M4 10.3203V11.8803C4 12.9603 4.58 13.9603 5.53 14.4903L8.49 16.1603C9.12 16.5103 9.51 17.1803 9.51 17.9003V20.0003C9.51 21.1003 10.41 22.0003 11.51 22.0003H12.51C13.61 22.0003 14.51 21.1003 14.51 20.0003V17.9003C14.51 17.1803 14.9 16.5103 15.53 16.1603L18.49 14.4903C19.43 13.9603 20.02 12.9603 20.02 11.8803V10.3203C20.02 9.77031 19.57 9.32031 19.02 9.32031H5C4.45 9.32031 4 9.76031 4 10.3203Z" fill="#292D32"/></svg>';
    $menu_icon_url = "data:image/svg+xml;base64," . base64_encode($menu_svg);

    add_menu_page('Y4APK', 'Y4APK', 'manage_options', 'y4apk-gplay', 'y4apk_gplay_page', $menu_icon_url, 82);
    add_submenu_page('y4apk-gplay', 'Google Play Importer', 'Google Play', 'manage_options', 'y4apk-gplay');
    add_submenu_page('y4apk-gplay', 'Settings', 'Settings', 'manage_options', 'y4apk-settings', 'y4apk_settings_page');
}
add_action('admin_menu', 'y4apk_admin_menus');

/* Google Drive Auth Actions by URL */
add_action('init', 'verify_gdrive_access');

function verify_gdrive_access()
{
    $code = isset($_GET['code']) ? $_GET['code'] : null;
    $at_upload = isset($_GET['at_upload']) ? $_GET['at_upload'] : null;

    if ($code && $at_upload == 'gdrive') {

        if (!current_user_can('administrator'))
            return;

        $gdrive = new Y4APK_GoogleDrive();
        if ($gdrive->getClient()) {
            $token = $gdrive->getClient()->fetchAccessTokenWithAuthCode($code);

            $gdrive->getClient()->setAccessToken($token);

            update_option('at_gdrive_token', json_encode($token));
            header("Location: " . admin_url('admin.php?page=y4apk-settings'));
            exit;
        }
    }
}
add_action('admin_init', 'admin_init_gdrive_url_action');

function admin_init_gdrive_url_action()
{
    $action = isset($_GET['action']) ? $_GET['action'] : NULL;

    if ($action == "connect_gdrive") {
        $gdrive = new Y4APK_GoogleDrive();
        if ($gdrive->getClient()) {
            if (!get_option('at_gdrive_token')) {
                header("Location: " . $gdrive->getClient()->createAuthUrl());
                exit;
            }
        }
    }

    if ($action == "disconnect_gdrive") {
        delete_option('at_gdrive_token');
        header("Location: " . admin_url('admin.php?page=y4apk-settings'));
        exit;
    }

    if ($action == "test_ftp") {
        $ftp_server = y4apk_options('ftp_server_ip', true);
        $ftp_port = y4apk_options('ftp_port', true) ? y4apk_options('ftp_port', true) : 21;
        $ftp_username = y4apk_options('ftp_username', true);
        $ftp_password = y4apk_options('ftp_password', true);
        $ftp_directory = y4apk_options('ftp_directory', true) ? trailingslashit(y4apk_options('ftp_directory', true)) : '';
        $ftp_access_url = untrailingslashit(y4apk_options('ftp_url', true));

        $conn_id = @ftp_connect($ftp_server, $ftp_port, 30) or die(sprintf(__('Could not connect to "%s". Check again', 'y4apk'), $ftp_server));

        if (!$ftp_access_url)
            die(__('Enter valid URL in URL field!', 'y4apk'));

        if (@ftp_login($conn_id, $ftp_username, $ftp_password)) {
            ftp_pasv($conn_id, true) or die(__('Cannot switch to passive mode', 'y4apk'));

            $filename = 'sample-file.txt';
            $contents = 'Thank you be a part of y4apk. FTP successfully working :)';
            $tmp_file = tmpfile();
            fwrite($tmp_file, $contents);
            rewind($tmp_file);
            $tmp_data = stream_get_meta_data($tmp_file);

            if (@ftp_put($conn_id, $ftp_directory . $filename, $tmp_data['uri'], FTP_ASCII)) {
                echo '<p><b>' . __('sample file has been created to your server.', 'y4apk') . '</b></p>';

                if (!$ftp_access_url) {
                    echo '<p>' . __('Access URL is must needed for generate the direct link of uploaded files. So fill the access URL and test again!', 'y4apk') . '</p>';
                } else {
                    echo '<p>' . sprintf(__('Access the file through this %s', 'y4apk'), '<a href="' . $ftp_access_url . '/' . $filename . '" target="_blank">' . __('link', 'y4apk') . '</a>') . '. ' . __('If you cannot access the link you must enter the URL field correctly.', 'y4apk') . '</p>';
                    echo '<p>' . __('If you accessed the link and the text "Thank you be a part of y4apk. FTP successfully working :)" appeared then the connection was successful.', 'y4apk') . '</p>';
                }
            } else {
                echo __('The test file could not be generated. The directory you have placed may not exist.', 'y4apk') . ' - ' . error_get_last()['message'];
            }
            fclose($tmp_file);
        } else {
            echo __('Incorrect FTP server data. Check again please!', 'y4apk');
        }
        ftp_close($conn_id);

        exit;
    }

}

/* Initializations */
function y4apk_options($option, $default = false)
{
    $value = get_option('at_' . $option, false);

    if ($value !== false) {
        return $value;
    } else {
        return ($default !== false) ? $default : '0';
    }
}

function get_http_response_code($url)
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);

    return $response_code === 200;
}

function bytes_converter($bytes)
{
    if (empty($bytes))
        return '';

    $kb = $bytes / 1024;
    $mb = $kb / 1024;
    $gb = $mb / 1024;

    if ($bytes < 1024) {
        return $bytes . 'B';
    } elseif ($kb < 1024) {
        return round($kb, 2) . 'KB';
    } elseif ($mb < 1024) {
        return round($mb, 2) . 'MB';
    } else {
        return round($gb, 2) . 'GB';
    }
}

function get_parsed_category($category)
{
    $parent_category = '';

    if (!empty($category) && strpos($category, 'GAME_') !== false) {
        $parent_category = __('Games', 'y4apk');
    } else {
        $parent_category = __('Apps', 'y4apk');
    }

    return $parent_category;
}

function get_parsed_subcategory($category)
{
    $sub_category = '';

    if (!empty($category)) {
        $sub_category = strtolower(str_replace('GAME_', '', $category));
        $sub_category = str_replace('_', '-', $sub_category);
    }

    return $sub_category;
}

function get_android_version_by_sdk($sdk_version)
{
    $android_versions = [
        1 => '1.0',
        2 => '1.1',
        3 => '1.5',
        4 => '1.6',
        5 => '2.0',
        6 => '2.0.1',
        7 => '2.1',
        8 => '2.2',
        9 => '2.3',
        10 => '2.3.3',
        11 => '3.0',
        12 => '3.1',
        13 => '3.2',
        14 => '4.0',
        15 => '4.0.3',
        16 => '4.1',
        17 => '4.2',
        18 => '4.3',
        19 => '4.4',
        20 => '4.4W',
        21 => '5.0',
        22 => '5.1',
        23 => '6.0',
        24 => '7.0',
        25 => '7.1',
        26 => '8.0',
        27 => '8.1',
        28 => '9.0',
        29 => '10.0',
        30 => '11.0',
        31 => '12.0',
        32 => '12.1',
        33 => '13.0',
        34 => '14.0',
    ];

    return isset($android_versions[$sdk_version]) ? $android_versions[$sdk_version] : '';
}