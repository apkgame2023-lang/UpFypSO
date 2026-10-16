function IsJsonString(str) {
    try {
        JSON.parse(str);
    } catch (e) {
        return false;
    }
    return true;
}

jQuery(document).ready(function ($) {
    $(document).ready(function () {
        $(".input-group-text").on("click", function () {
            let password_field = $(this).closest('.input-group').find('input.hide-word');
            if (password_field.attr('type') === 'password') {
                password_field.attr('type', 'text');
                $(this).find(".bi-eye-fill").hide();
                $(this).find(".bi-eye-slash-fill").show();
            } else if (password_field.attr('type') === 'text') {
                password_field.attr('type', 'password');
                $(this).find(".bi-eye-slash-fill").hide();
                $(this).find(".bi-eye-fill").show();
            }
        });

        $(".input-group-text").hover(function () {
            $(this).css("cursor", "pointer");
        }, function () {
            $(this).css("cursor", "auto");
        });
    });

    $(document).on('submit', '#at-gplay-form', function (e) {
        e.preventDefault();
        $('.gp-status').hide();
        $('.gp-error').hide();
        if ($('.gp-status-info')) {
            $('.gp-status-info').empty();
        }
        if ($('.gp-error-info')) {
            $('.gp-error-info').empty();
        }
        $('#at-gplay-spinner').show();
        $('#at-gplay-submit').prop('disabled', true);

        let has_apk = false;
        let has_obb = false;
        let has_patch = false;
        let gplay_url = $('#at-gplay-url').val();
        let post_title_start = $('#at-post-start-title').val();
        let post_title_end = $('#at-post-end-title').val();
        let mod_feature = $('#at-mod-feature').val();

        $('#at-gplay-url').prop('disabled', true);
        append_success_info('APK Extraction started...');

        var request_create_post = $.ajax({
            url: y4apk_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'y4apk_create_post',
                gp_url: gplay_url,
                nonce: y4apk_ajax_vars.nonce,
                post_title_start: post_title_start,
                post_title_end: post_title_end,
                mod_feature: mod_feature
            }
        });

        request_create_post.done(function (data, textStatus, jqXHR) {
            var parsed_data = JSON.parse(data);
            var apk_file_info = parsed_data;
            var error_info = parsed_data?.error;
            var warning_info = parsed_data?.warning;
            var success_info = parsed_data?.success;

            if (error_info) {
                handle_ajax_error(error_info.message);
                return;
            } else if (warning_info) {
                append_warning_info(warning_info?.text);
            }

            append_success_info(success_info?.text);

            if (apk_file_info?.get_apk == true) {

                if (apk_file_info?.has_apk_file) {
                    has_apk = true;
                }
                if (apk_file_info?.has_obb_file) {
                    has_obb = true;
                }
                if (apk_file_info?.has_patch_file) {
                    has_patch = true;
                }
                if (has_apk || has_obb || has_patch) {
                    append_success_info('APK files found!');
                    append_success_info('Available files started uploading! Don\'t reload this page. Please wait...');

                    var request_upload_apk = $.ajax({
                        url: y4apk_ajax_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'y4apk_upload_apk',
                            nonce: y4apk_ajax_vars.nonce,
                            post_id: apk_file_info.post_id,
                            apk: apk_file_info?.apk,
                            obb: apk_file_info?.obb,
                            patch: apk_file_info?.patch,
                        }
                    });

                    request_upload_apk.done(function (data, textStatus, jqXHR) {
                        var parsed_data = JSON.parse(data);
                        var error_info = parsed_data?.error;
                        var warning_info = parsed_data?.warning;
                        var success_info = parsed_data?.success;

                        if (error_info) {
                            handle_ajax_error(error_info?.message);
                        } else if (warning_info) {
                            append_warning_info(warning_info?.text);
                        } else if (success_info) {
                            append_success_info(success_info?.text);
                        }

                        append_success_info('Import process complete :)');

                        $('#at-gplay-url').prop('disabled', null);
                        $('#at-gplay-submit').prop('disabled', null);
                        $('#at-gplay-spinner').hide();
                        $('#at-gplay-url').val('');
                    });

                    request_upload_apk.fail(function (xhr, status, error) {
						if (xhr.status === 500) {
							handle_ajax_error('Internal Server Error (500)!. ' + 'Error info: ' + xhr.responseText);
						} else if(xhr.status === 524){
							handle_ajax_error('Server connection timeout.');
						} else {
							handle_ajax_error('Error:', status, error);
						}
                    });

                } else {
                    append_warning_info('APK file not found!');
                    append_success_info('Import process complete :)');
                    // Final steps
                    $('#at-gplay-url').prop('disabled', null);
                    $('#at-gplay-submit').prop('disabled', null);
                    $('#at-gplay-spinner').hide();
                    $('#at-gplay-url').val('');
                }
            } else {
                append_success_info('Import process complete :)');
                // Final steps
                $('#at-gplay-url').prop('disabled', null);
                $('#at-gplay-submit').prop('disabled', null);
                $('#at-gplay-spinner').hide();
                $('#at-gplay-url').val('');
            }
        });

        request_create_post.fail(function (xhr, status, error) {
            if (xhr.status === 500) {
				handle_ajax_error('Internal Server Error (500)!. Try again for upload APK.' + 'Error info: ' + xhr.responseText);
			} else if(xhr.status === 524){
				handle_ajax_error('Server connection timeout.Try again for upload APK.');
			} else {
				handle_ajax_error('Error:', status, error);
			}
        });
    });

    $(document).on('submit', '#at-settings-form', function (e) {
        e.preventDefault();

        $('#at-settings-spinner').show();
        $('#at-settings-submit').prop('disabled', true);

        let is_mod_title = $('#is-mod-title').is(':checked');
        let is_mod_feature_title = $('#is-mod-feature-title').is(':checked');
        let is_title_version = $('#is-title-version').is(':checked');
        let is_blocks_content = $('#is-blocks-content').is(':checked');
        let is_get_apk = $('#is-get-apk').is(':checked');
        let upload_storage = $('#upload-storage').val();
        let gdrive_client_id = $('#gdrive-client-id').val();
        let gdrive_client_secret = $('#gdrive-client-secret').val();
        let gdrive_folder_name = $('#gdrive-folder').val();
        let ftp_server_ip = $('#ftp-server-ip').val();
        let ftp_port = $('#ftp-port').val();
        let ftp_username = $('#ftp-username').val();
        let ftp_password = $('#ftp-password').val();
        let ftp_directory = $('#ftp-directory').val();
        let ftp_url = $('#ftp-url').val();

        var request_save_settings = $.ajax({
            url: y4apk_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'save_y4apk_settings',
                nonce: y4apk_ajax_vars.nonce,
                is_mod_title: is_mod_title ? 1 : 0,
                is_mod_feature_title: is_mod_feature_title ? 1 : 0,
                is_title_version: is_title_version ? 1 : 0,
                is_blocks_content: is_blocks_content ? 1 : 0,
                is_get_apk: is_get_apk ? 1 : 0,
                upload_storage: upload_storage,
                gdrive_client_id: gdrive_client_id,
                gdrive_client_secret: gdrive_client_secret,
                gdrive_folder_name: gdrive_folder_name,
                ftp_server_ip: ftp_server_ip,
                ftp_port: ftp_port,
                ftp_username: ftp_username,
                ftp_password: ftp_password,
                ftp_directory: ftp_directory,
                ftp_url: ftp_url
            }
        });

        request_save_settings.done(function (data, textStatus, jqXHR) {
            $('#at-settings-spinner').hide();
            $('#at-settings-submit').prop('disabled', null);
            location.reload()
        });

        request_save_settings.fail(function (jqXHR, textStatus, errorThrown) {
            alert(errorThrown);
        });
    });

    function append_success_info(info_text) {
        $('.gp-status').show();
        $('.gp-status-info').append('<li><span class="dashicons dashicons-yes-alt h4 text-success"></span>&nbsp;&nbsp;' + info_text + '</li>');
    }

    function append_warning_info(info_text) {
        $('.gp-status').show();
        $('.gp-status-info').append('<li><span class="dashicons dashicons-warning h4 text-warning"></span>&nbsp;&nbsp;' + info_text + '</li>');
    }

    function handle_ajax_error(error_message) {
        $('.gp-error').show();
        $('.gp-error-info').append('<li><span class="dashicons dashicons-dismiss h4 text-danger"></span>&nbsp;&nbsp;' + error_message + '</li>');
        $('#at-gplay-url').prop('disabled', null);
        $('#at-gplay-submit').prop('disabled', null);
        $('#at-gplay-spinner').hide();
        $('#at-gplay-url').val('');
    }
});