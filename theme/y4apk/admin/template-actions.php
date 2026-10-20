<?php
function y4apk_admin_header(){ ?>
    <div class="y4apk-header">
        <div class="y4apk-logo">
            <a href="https://y4apk.com">
                <img src="<?php echo get_template_directory_uri() . '/assets/img/admin/y4apk-icon.png'; ?>"
                    alt="Y4APK Icon" width="40" height="40" />
            </a>
        </div>
        <h1 class="y4apk-title">Y4APK <span class="small-version">v<?php echo esc_html(Y4APK_THEME_VERSION); ?></span></h1>
    </div>
<?php }

function y4apk_gplay_page(){
    y4apk_admin_header(); ?>
    <div class="container">
        <div>
            <div class="row">
                <div class="col-lg-12 text-center">
                    <h2 class="h4">
                        <?php esc_html_e('Google Play Importer', 'y4apk'); ?>
                    </h2>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-12">
                    <form action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" id="at-gplay-form">
                        <div class="form-group">
                            <label for="at-gplay-url">
                                <?php esc_html_e('Google Play URL', 'y4apk'); ?>
                            </label>
                            <input type="url" class="form-control" id="at-gplay-url"
                                placeholder="https://play.google.com/store/apps/details?id=com.whatsapp" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block" id="at-gplay-submit">
                            <span id="at-gplay-spinner" class="spinner-border spinner-border-sm" role="status"
                                aria-hidden="true" style="display:none"></span>
                            <?php esc_html_e('Post Now', 'y4apk'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="row mt-3 gp-status" style="display:none">
                <div class="col-lg-12">
                    <div class="h5">
                        <?php esc_html_e('Status:', 'y4apk'); ?>
                    </div>
                    <ul class="gp-status-info" style="list-style-type: none;"></ul>
                </div>
            </div>

            <div class="row mt-3 gp-error" style="display:none">
                <div class="col-lg-12">
                    <div class="h5">
                        <?php esc_html_e('Error:', 'y4apk'); ?>
                    </div>
                    <ul class="gp-error-info" style="list-style-type: none;"></ul>
                </div>
            </div>

            <div class="row mt-3">
                <h3 class="h5 mx-auto">Post Title Preview</h3>
                <div class="col-lg-12">
                    <div class="p-3 mb-2 bg-light text-dark">Eg. [Start Text] [App Name] [MOD APK] ([MOD Feature]) [Version] [End Text]</div>
                </div>
            </div>

            <div class="row mt-3">
                <h3 class="h5 mx-auto mt-3">Advanced Options</h3>
                <div class="col-lg-12">
                    <div class="form-group">
                        <label for="at-post-start-title">
                            <?php esc_html_e('Post title starting text', 'y4apk'); ?>
                        </label>
                        <input type="text" class="form-control" id="at-post-start-title" value="<?php echo esc_attr(get_option('at_post_title_start')); ?>"
                            placeholder="Eg. [Start text] Spotify MOD APK">
                    </div>
                    <div class="form-group">
                        <label for="at-post-end-title">
                            <?php esc_html_e('Post title ending text', 'y4apk'); ?>
                        </label>
                        <input type="text" class="form-control" id="at-post-end-title" value="<?php echo esc_attr(get_option('at_post_title_end')); ?>"
                            placeholder="Eg. Spotify MOD APK [End text]">
                    </div>
                    <div class="form-group">
                        <label for="at-mod-feature">    
                            <?php esc_html_e('MOD Feature', 'y4apk'); ?>
                        </label>
                        <input type="text" class="form-control" id="at-mod-feature" value="<?php echo esc_attr(get_option('at_mod_feature')); ?>" placeholder="Eg. Premium Unlocked">
                    </div>
                </div>
            </div>
			
			<div class="row mt-3">
				<h3 class="h6">Note:</h3>
				<div class="col-lg-12">
					<p>-> APK files upload speed based on your hosting perfomance.</p>
					<p>-> Wait until finish the apk download process.</p>
					<p>-> If you omit get APK, APK data will import within 5 seconds.</p>
					<p>-> Some APK data not import due to restriction.</p>
					<p>-> Curently, OBB upload feature not implemented.</p>
					<p>-> If you face any error, just try again. Then if you face error multiple times, contact y4apk support team.</p>
				</div>
			</div>

            <div class="row mt-3">
                <h3 class="h5 mx-auto">Active settings</h3>
                <div class="col-lg-12">
                        <table class="table table-borderless">
                            <tbody>
                                <tr>
                                    <td>
                                        <strong>Add MOD APK to post title?</strong>
                                    </td>
                                    <td>
                                        <?php echo (y4apk_options('is_mod_title')) ? '<p class="badge badge-success" style="font-size: 1rem;">Yes</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">No</p>'; ?> 
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <strong>Add MOD feature to title?</strong>
                                    </td>
                                    <td>
                                        <?php echo (y4apk_options('is_mod_feature_title')) ? '<p class="badge badge-success" style="font-size: 1rem;">Yes</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">No</p>'; ?> 
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <strong>Add App Version to title?</strong>
                                    </td>
                                    <td>
                                        <?php echo (y4apk_options('is_title_version')) ? '<p class="badge badge-success" style="font-size: 1rem;">Yes</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">No</p>'; ?> 
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <strong>Content as wp blocks?</strong>
                                    </td>
                                    <td>
                                        <?php echo (y4apk_options('is_blocks_content')) ? '<p class="badge badge-success" style="font-size: 1rem;">Yes</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">No</p>'; ?> 
                                    </td>
                                </tr>
								<tr>
                                    <td>
                                        <strong>Import APK file?</strong>
                                    </td>
                                    <td>
                                        <?php echo (y4apk_options('is_get_apk')) ? '<p class="badge badge-success" style="font-size: 1rem;">Yes</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">No</p>'; ?> 
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <strong>APK upload server</strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary" style="font-size: 1rem;"><?php echo strtoupper(y4apk_options('upload_storage', 'my-server')); ?></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                </div>
            </div>
        </div>
    </div>
<?php }

function y4apk_settings_page(){
    y4apk_admin_header();
    ?>
    <div class="container">
        <div>
            <div class="row">
                <div class="col-lg-12 text-center my-3">
                    <h2 class="h4">
                        <?php esc_html_e('Settings', 'y4apk'); ?>
                    </h2>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <form action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" id="at-settings-form">
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is-mod-title" <?php checked(get_option('at_is_mod_title'), 1); ?>>
                                <label class="custom-control-label" for="is-mod-title">
                                    <?php esc_html_e('Add MOD APK text to title?', 'y4apk'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is-mod-feature-title" <?php checked(get_option('at_is_mod_feature_title'), 1); ?>>
                                <label class="custom-control-label" for="is-mod-feature-title">
                                    <?php esc_html_e('Add MOD feature to title?', 'y4apk'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is-title-version" <?php checked(get_option('at_is_title_version'), 1); ?>>
                                <label class="custom-control-label" for="is-title-version">
                                    <?php esc_html_e('Add App Version to title?', 'y4apk'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is-blocks-content" <?php checked(get_option('at_is_blocks_content'), 1); ?>>
                                <label class="custom-control-label" for="is-blocks-content">
                                    <?php esc_html_e('Import post content as wordpress blocks?', 'y4apk'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is-get-apk" <?php checked(get_option('at_is_get_apk'), 1); ?>>
                                <label class="custom-control-label" for="is-get-apk">
                                    <?php esc_html_e('Import APK file?', 'y4apk'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="upload-storage">
                                <?php esc_html_e('APK Upload Server', 'y4apk'); ?>
                            </label>
                            <select class="custom-select d-block" id="upload-storage"
                                value="<?php echo esc_attr(get_option('at_upload_storage', 'my-server')); ?>">
                                <option value="my-server" <?php selected(get_option('at_upload_storage'), 'my-server'); ?>>
                                    <?php esc_html_e('My Server', 'y4apk'); ?>
                                </option>
                                <option value="gdrive" <?php selected(get_option('at_upload_storage'), 'gdrive'); ?>>
                                    <?php esc_html_e('Google Drive', 'y4apk'); ?>
                                </option>
                                <option value="ftp" <?php selected(get_option('at_upload_storage'), 'ftp'); ?>>
                                    <?php esc_html_e('FTP', 'y4apk'); ?>
                                </option>
                            </select>
                        </div>
                        <div class="row">
                            <h2 class="h4 mx-auto my-3">
                                <?php esc_html_e('Storage Settings', 'y4apk'); ?>
                            </h2>
                        </div>
                        <div class="form-group">
                            <h3 class="h5">
                                <?php esc_html_e('Google Drive'); ?>
                            </h3>
                            <div>Add Google Drive credidentials to upload the APK file driectly to your Google Drive. For instruction, read guide <a href="https://y4apk.com/apk-extractor-setup/" target="_blank nofollow">here.</a>
                            </div>

                            <label for="gdrive-client-id" class="mt-3">
                                <?php esc_html_e('G-Drive Client ID', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="gdrive-client-id"
                                placeholder="xxxx.apps.googleusercontent.com"
                                value="<?php echo esc_attr(get_option('at_gdrive_client_id')); ?>">

                            <label for="gdrive-client-secret" class="mt-2">
                                <?php esc_html_e('G-Drive Client Secret', 'y4apk'); ?>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control hide-word" id="gdrive-client-secret"
                                    placeholder="xxxxxx-xxxxxxxxxx"
                                    value="<?php echo esc_attr(get_option('at_gdrive_client_secret')); ?>">
                                <div class="input-group-append">
                                    <span class="input-group-text">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            class="bi bi-eye-fill" viewBox="0 0 16 16">
                                            <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"></path>
                                            <path
                                                d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8m8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7">
                                            </path>
                                        </svg>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            class="bi bi-eye-slash-fill" viewBox="0 0 16 16" style="display: none">
                                            <path
                                                d="m10.79 12.912-1.614-1.615a3.5 3.5 0 0 1-4.474-4.474l-2.06-2.06C.938 6.278 0 8 0 8s3 5.5 8 5.5a7.029 7.029 0 0 0 2.79-.588M5.21 3.088A7.028 7.028 0 0 1 8 2.5c5 0 8 5.5 8 5.5s-.939 1.721-2.641 3.238l-2.062-2.062a3.5 3.5 0 0 0-4.474-4.474L5.21 3.089z">
                                            </path>
                                            <path
                                                d="M5.525 7.646a2.5 2.5 0 0 0 2.829 2.829l-2.83-2.829zm4.95.708-2.829-2.83a2.5 2.5 0 0 1 2.829 2.829zm3.171 6-12-12 .708-.708 12 12z">
                                            </path>
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <label for="gdrive-folder" class="mt-2">
                                <?php esc_html_e('Folder Name', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="gdrive-folder" placeholder="File upload folder name"
                                value="<?php echo esc_attr(get_option('at_gdrive_folder_name')); ?>">
                            
                            <?php
                            $gdt = y4apk_options('gdrive_token');
                            if($gdt && y4apk_options('gdrive_client_id', true) && y4apk_options('gdrive_client_secret')) { ?>
                            <div class="h6 d-block mt-3">
                                <?php esc_html_e('Status:', 'y4apk'); ?>
                                <span class="badge badge-success">
                                    <?php esc_html_e('Connected', 'y4apk'); ?>
                                </span>
                            </div>
                            <div class="mt-3">
                                <a href="<?php echo admin_url('admin.php?page=y4apk-settings&action=disconnect_gdrive'); ?>" class="btn btn-danger btn-sm">
                                <?php esc_html_e('Disconnect G-Drive'); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </div>
                            <?php } else { ?>
                                <div class="h6 d-block mt-3">
                                    <?php esc_html_e('Status:', 'y4apk'); ?>
                                    <span class="badge badge-danger">
                                        <?php esc_html_e('Disconnected', 'y4apk'); ?>
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <a href="<?php echo admin_url('admin.php?page=y4apk-settings&action=connect_gdrive'); ?>" class="btn btn-success btn-sm">
                                        <?php esc_html_e('Connect G-Drive'); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                </div>
                            <?php } ?>
                        </div>
                        <br />
                        <div class="form-group">
                            <h3 class="h5">
                                <?php esc_html_e('FTP'); ?>
                            </h3>
                            <div>Add FTP credidentials to upload the APK file driectly to your FTP. For instruction, read guide <a href="https://y4apk.com/apk-extractor-setup/" target="_blank nofollow">here.</a></div>
                            <label for="ftp-server-ip" class="mt-3">
                                <?php esc_html_e('Server name or IP*', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="ftp-server-ip" placeholder="128.xx.xx.xx"
                                value="<?php echo esc_attr(get_option('at_ftp_server_ip')); ?>">

                            <label for="ftp-port" class="mt-2">
                                <?php esc_html_e('Port', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="ftp-port" placeholder="xxxx"
                                value="<?php echo esc_attr(get_option('at_ftp_port')); ?>">

                            <label for="ftp-username" class="mt-2">
                                <?php esc_html_e('Username*', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="ftp-username" placeholder="Enter username"
                                value="<?php echo esc_attr(get_option('at_ftp_username')); ?>">

                            <label for="ftp-password" class="mt-2">
                                <?php esc_html_e('Password*', 'y4apk'); ?>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control hide-word" id="ftp-password" placeholder="Enter password"
                                    value="<?php echo esc_attr(get_option('at_ftp_password')); ?>">
                                <div class="input-group-append">
                                    <span class="input-group-text">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            class="bi bi-eye-fill" viewBox="0 0 16 16">
                                            <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"></path>
                                            <path
                                                d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8m8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7">
                                            </path>
                                        </svg>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            class="bi bi-eye-slash-fill" viewBox="0 0 16 16" style="display: none">
                                            <path
                                                d="m10.79 12.912-1.614-1.615a3.5 3.5 0 0 1-4.474-4.474l-2.06-2.06C.938 6.278 0 8 0 8s3 5.5 8 5.5a7.029 7.029 0 0 0 2.79-.588M5.21 3.088A7.028 7.028 0 0 1 8 2.5c5 0 8 5.5 8 5.5s-.939 1.721-2.641 3.238l-2.062-2.062a3.5 3.5 0 0 0-4.474-4.474L5.21 3.089z">
                                            </path>
                                            <path
                                                d="M5.525 7.646a2.5 2.5 0 0 0 2.829 2.829l-2.83-2.829zm4.95.708-2.829-2.83a2.5 2.5 0 0 1 2.829 2.829zm3.171 6-12-12 .708-.708 12 12z">
                                            </path>
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <label for="ftp-directory" class="mt-2">
                                <?php esc_html_e('Directory', 'y4apk'); ?>
                            </label>
                            <input type="text" class="form-control" id="ftp-directory" placeholder="Enter directory path"
                                value="<?php echo esc_attr(get_option('at_ftp_directory')); ?>">
                            <p class="mt-2"><?php echo __('Enter the directory path which is access via FTP.', 'y4apk'); ?></p>
                            <div class="mt-2">
                                <strong>Samples:</strong>
                                <li>public_html</li>
                                <li>/site.com/</li>
                                <li>/domain/mainsite.com/public_html/</li>
                            </div>

                            <label for="ftp-url" class="mt-2">
                                <?php esc_html_e('URL', 'y4apk'); ?>
                            </label>
                            <input type="url" class="form-control" id="ftp-url" placeholder="Enter FTP URL"
                                value="<?php echo esc_attr(get_option('at_ftp_url')); ?>">
                            <p class="mt-2"><?php echo __('Enter the URL which is accesible to download files.', 'y4apk'); ?></p>
                            <div class="mt-3">
                                <a href="<?php echo admin_url('admin.php?page=y4apk-settings&action=test_ftp'); ?>" target="_blank" class="btn btn-success btn-sm">
                                    <?php esc_html_e('Test connection'); ?> <span
                                        class="dashicons dashicons-external"></span>
                                </a>
                            </div>

                            <div class="row">
                                <h2 class="h4 mx-auto my-3">
                                    <?php esc_html_e('Extractor Requirements', 'y4apk'); ?>
                                </h2>
                            </div>

                            <div class="row mt-3">
                                <?php
                                $allow_url_fopen = ini_get('allow_url_fopen');
                                $max_execution_time = ini_get('max_execution_time');
                                $max_input_time = ini_get('max_input_time');
                                $memory_limit = ini_get('memory_limit');
                                $post_max_size = ini_get('post_max_size');
                                $upload_max_filesize = ini_get('upload_max_filesize');

                                function is_server_setting_valid($value, $limit) {
                                    if( $value == -1 ) {
                                        return 999999;
                                    }

                                    if (preg_match('/^(\d+)(.)$/', $value, $matches)) {
                                        if ($matches[2] == 'G') {
                                            $value = $matches[1] * 1024 * 1024 * 1024;
                                        }
                                        else if ($matches[2] == 'M') {
                                            $value = $matches[1] * 1024 * 1024;
                                        } else if ($matches[2] == 'K') {
                                            $value = $matches[1] * 1024;
                                        }
                                    }
                                    return ($value >= $limit * 1024 * 1024);
                                }
                                ?>
                                <div class="col-lg-12">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td><strong>allow_url_fopen</strong></td>
                                            <td><?php echo ($allow_url_fopen == 1) ? '<p class="badge badge-success" style="font-size: 1rem;">Enabled</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">Disabled</p>'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>max_execution_time</strong></td>
                                            <td><?php echo ($max_execution_time >= 300) ? '<p class="badge badge-success" style="font-size: 1rem;">' . (($max_execution_time <= 0) ? 'No limit' : $max_execution_time) . '</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">' . $max_execution_time . '</p> <em> / ' . __('Recommended: 300 or Greater than 300', 'y4apk') . '</em>'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>max_input_time</strong></td>
                                            <td><?php echo ($max_input_time >= 300) ? '<p class="badge badge-success" style="font-size: 1rem;">' . $max_input_time . '</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">' . (($max_input_time <= 0) ? 'No limit' : $max_input_time) . '</p> <em>/ ' . __('Recommended: 300 or Greater than 300', 'y4apk') . '</em>'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>memory_limit</strong></td>
                                            <td><?php echo (is_server_setting_valid($memory_limit, 4096) ? '<p class="badge badge-success" style="font-size: 1rem;">' . (($memory_limit <= 0) ? 'No limit' : $memory_limit) . '</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">' . $memory_limit . '</p> <em>/ ' . __('Recommended: 4GB or Greater than 4GB', 'y4apk') . '</em>'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>post_max_size</strong></td>
                                            <td><?php echo (is_server_setting_valid($post_max_size, 4096) ? '<p class="badge badge-success" style="font-size: 1rem;">' . $post_max_size . '</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">' . $post_max_size . '</p> <em>/ ' . __('Recommended: 4GB or Greater than 4GB', 'y4apk') . '</em>'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>upload_max_filesize</strong></td>
                                            <td><?php echo (is_server_setting_valid($upload_max_filesize, 4096) ? '<p class="badge badge-success" style="font-size: 1rem;">' . $upload_max_filesize . '</p>' : '<p class="badge badge-danger" style="font-size: 1rem;">' . $upload_max_filesize . '</p> <em>/ ' . __('Recommended: 4GB or Greater than 4GB', 'y4apk') . '</em>'); ?></td>
                                        </tr>
                                    </table>
									<div><strong>Notes:</strong> Requirements are must need for extractor, if it's less than required limit, APK extractor will not work properly. So follow instructions to improve full fill the requirements. For instruction, read guide <a href="https://y4apk.com/apk-extractor-setup/" target="_blank nofollow">here.</a></div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block mt-3" id="at-settings-submit">
                            <span id="at-settings-spinner" class="spinner-border spinner-border-sm" role="status"
                                aria-hidden="true" style="display:none"></span>
                            <?php esc_html_e('Save Changes', 'y4apk'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php }