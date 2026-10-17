<?php
add_action('add_meta_boxes', 'add_post_metaboxes', 1);

add_action('save_post', 'save_app_info');
add_action('save_post', 'save_download_links');
add_action('save_post', 'save_screenshots');

function add_post_metaboxes(){
    add_meta_box('download-links', __('Download Links', 'y4apk'), 'download_links_callback', 'post', 'normal', 'high');
    add_meta_box('app-info', __('App Information', 'y4apk'), 'app_info_callback', 'post', 'normal', 'high');
    add_meta_box('screenshots', __('Screenshots', 'y4apk'), 'screenshots_callback', 'post', 'normal', 'high');
}

function y4apk_post_editor_styles() {
    if (is_admin() && get_current_screen()->id === 'post') {
        wp_enqueue_style('y4apk-admin', get_template_directory_uri() . '/assets/css/admin/admin.min.css', array(), Y4APK_THEME_VERSION, 'all');
        wp_enqueue_script('y4apk-admin', get_template_directory_uri() . '/assets/js/admin/admin.js', array('jquery'), Y4APK_THEME_VERSION, true);
    }
}
add_action('admin_enqueue_scripts', 'y4apk_post_editor_styles');

function app_info_callback($post){
	$post_id = $post->ID;
    $app_fields = [
        'wp_title_GP' => __('App Name', 'y4apk'),
        'wp_developers_GP' => __('App Publisher', 'y4apk'),
        'wp_GP_ID' => __('Google Play ID', 'y4apk'),
        'wp_mods' => __('App MOD Feature', 'y4apk'),
        'wp_version_GP' => __('App Version', 'y4apk'),
        'wp_sizes_GP' => __('App Size', 'y4apk'),
        'wp_contentrated_GP' => __('App Rated Year\'s', 'y4apk'),
        'wp_requires_GP' => __('App Required OS', 'y4apk'),
		'price' => __('App Price', 'y4apk'),
    ];
	$app_os = get_post_meta($post_id, 'platform', true);
	$rating_data = get_star_rating( $post_id );
	wp_nonce_field('app_info_nonce', 'app_info_nonce');
    ?>

    <table class="form-table" role="presentation">
        <tbody>
			<?php foreach ($app_fields as $field_key => $field_label) : ?>
            <tr>
                <th scope="row"><label for="<?= $field_key ?>"><?= $field_label ?></label></th>
                <td><input type="text" id="<?= $field_key ?>" name="<?= $field_key ?>" value="<?= esc_attr(get_post_meta($post_id, $field_key, true)); ?>" class="regular-text" /></td>
            </tr>
			<?php endforeach; ?>
			<tr>
                <th scope="row"><label for="platform">App OS</label></th>
				<td>
					<select id="platform" name="platform" value="<?php echo $app_os; ?>">
						<option value="ANDROID" <?php selected($app_os, 'ANDROID'); ?> >Android</option>
						<option value="IOS" <?php selected($app_os, 'IOS'); ?> >iOS</option>
						<option value="WINDOWS" <?php selected($app_os, 'WINDOWS'); ?> >Windows</option>
					</select>
				</td>
            </tr>
			<tr>
                <th scope="row"><label for="avg_rating">Total Rating</label></th>
                <td>
					<input type="range" step="1" min="1" max="5" name="avg_rating" value="<?= $rating_data->rating_average; ?>" class="range-input"/>
					<span class="tooltip"><?= $rating_data->rating_average; ?></span>
				</td>
            </tr>
			<tr>
                <th scope="row"><label for="total_votes">Total Votes</label></th>
                <td><input type="text" id="total_votes" name="total_votes" value="<?= esc_attr($rating_data->rating_votes); ?>" class="regular-text" /></td>
            </tr>
        </tbody>
    </table>

    <?php
}

function save_app_info($post_id){
	if (!isset($_POST['app_info_nonce']) || !wp_verify_nonce($_POST['app_info_nonce'], 'app_info_nonce')) {
        return;
    }
	
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
	
    $allowed_post_types = ['post'];

    if (!in_array(get_post_type($post_id), $allowed_post_types)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $app_meta_data = [
        'wp_title_GP',
        'wp_developers_GP',
        'wp_mods',
        'wp_version_GP',
        'wp_sizes_GP',
        'wp_contentrated_GP',
        'wp_requires_GP',
		'price',
		'platform',
    ];

    foreach ($app_meta_data as $meta_key) {
        if (isset($_POST[$meta_key])) {
            update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
        }
    }

    // Google Play ID (Y4APK ▸ App updates): a Play link or a bare ID. A new one
    // is looked up again; an empty one lets the background search try.
    if (isset($_POST['wp_GP_ID'])) {
        $raw = sanitize_text_field(wp_unslash($_POST['wp_GP_ID']));
        $package = class_exists('Y4APK_App_Updates') ? Y4APK_App_Updates::package_from($raw) : $raw;
        if ($package !== (string) get_post_meta($post_id, 'wp_GP_ID', true)) {
            foreach (['_y4apk_latest_version', '_y4apk_latest_source', '_y4apk_latest_checked', '_y4apk_needs_update', '_y4apk_gp_link', '_y4apk_gp_suggest'] as $stale) {
                delete_post_meta($post_id, $stale);
            }
            if ($package === '') {
                delete_post_meta($post_id, 'wp_GP_ID');
            } else {
                update_post_meta($post_id, 'wp_GP_ID', $package);
                update_post_meta($post_id, '_y4apk_gp_link', 'confirmed');
            }
        }
    }
	
	$new_average = (empty($_POST['avg_rating']) ? 5 : $_POST['avg_rating'] );
	$new_votes = (empty($_POST['total_votes']) ? 2 : $_POST['total_votes'] );
	update_post_meta($post_id, 'avg_rating', $new_average);
	update_post_meta($post_id, 'total_votes', $new_votes);
	update_post_meta($post_id, 'total_rating', round($new_average * $new_votes));
}

function download_links_callback($post){
    $download_links = get_post_meta($post->ID, 'repeatable_download_link', true);
	wp_nonce_field('download_links_nonce', 'download_links_nonce');
    ?>
    <div class="table-container">
        <table id="download-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th></th>
                    <th><strong><?php _e('Type APK or OBB', 'y4apk'); ?></strong></th>
                    <th><strong><?php _e('Version', 'y4apk'); ?></strong></th>
                    <th><strong><?php _e('MOD Info', 'y4apk'); ?></strong></th>
                    <th><strong><?php _e('Size', 'y4apk'); ?></strong></th>
                    <th><strong><?php _e('URL', 'y4apk'); ?></strong></th>
                    <th><strong><?php _e('Actions', 'y4apk'); ?></strong></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($download_links) : ?>
                    <?php foreach ($download_links as $field) : ?>
                        <tr>
                            <td class="handle_icon"><span class="handle">&#9776;</span></td>
                            <td><input type="text" class="widefat" name="download_name[]" value="<?= esc_attr($field['download_name']); ?>" /></td>
                            <td><input type="text" class="widefat" name="download_version[]" value="<?= esc_attr($field['download_version']); ?>" /></td>
                            <td><input type="text" class="widefat" name="download_mod_info[]" value="<?= esc_attr($field['download_mod_info']); ?>" /></td>
                            <td><input type="text" class="widefat" name="download_size[]" value="<?= esc_attr($field['download_size']); ?>" /></td>
                            <td><input type="text" class="widefat" name="download_url[]" value="<?= esc_url($field['download_url']); ?>" /></td>
                            <td><button type="button" class="remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td class="handle_icon"><span class="handle">&#9776;</span></td>
                        <td><input type="text" class="widefat" name="download_name[]" value="APK" /></td>
                        <td><input type="text" class="widefat" name="download_version[]" value="<?= esc_attr(get_post_meta($post->ID, 'wp_version_GP', true)); ?>" /></td>
                        <td><input type="text" class="widefat" name="download_mod_info[]" value="<?= esc_attr(get_post_meta($post->ID, 'wp_mods', true)); ?>" /></td>
                        <td><input type="text" class="widefat" name="download_size[]" value="<?= esc_attr(get_post_meta($post->ID, 'wp_sizes_GP', true)); ?>" /></td>
                        <td><input type="text" class="widefat" name="download_url[]" value="" /></td>
                        <td><button type="button" class="remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button></td>
                    </tr>
                <?php endif; ?>
                <tr class="empty-d-row" style="display:none;">
                    <td class="handle_icon"><span class="handle">&#9776;</span></td>
                    <td><input type="text" class="widefat" name="download_name[]" /></td>
                    <td><input type="text" class="widefat" name="download_version[]" /></td>
                    <td><input type="text" class="widefat" name="download_mod_info[]" /></td>
                    <td><input type="text" class="widefat" name="download_size[]" /></td>
                    <td><input type="text" class="widefat" name="download_url[]" /></td>
                    <td><button type="button" class="remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <button type="button" id="add-row" class="add-btn components-button is-secondary" style="flex-grow: 1; justify-content: center; margin-top: 16px;"><?php _e('Add Link', 'y4apk'); ?></button>
    <?php
}

function save_download_links($post_id){
    if (!isset($_POST['download_links_nonce']) || !wp_verify_nonce($_POST['download_links_nonce'], 'download_links_nonce')) {
        return;
    }
	
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $allowed_post_types = ['post'];

    if (!in_array(get_post_type($post_id), $allowed_post_types)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $download_links = get_post_meta($post_id, 'repeatable_download_link', true);
    $new_data = [];

    $download_version = $_POST['download_version'];
    $download_name = $_POST['download_name'];
    $download_size = $_POST['download_size'];
    $download_url = $_POST['download_url'];
    $download_mod_info = $_POST['download_mod_info'];

    $count = count($download_name);

    for ($i = 0; $i < $count; $i++) {
        if ($download_name[$i] !== '') {
            $new_data[$i]['download_name'] = sanitize_text_field($download_name[$i]);
            if ($download_size[$i] !== '') {
                $new_data[$i]['download_size'] = sanitize_text_field($download_size[$i]);
                if ($download_url[$i] !== '') {
                    $new_data[$i]['download_url'] = esc_url($download_url[$i]);
                    if ($download_version[$i] !== '') {
                        $new_data[$i]['download_version'] = sanitize_text_field($download_version[$i]);
                        if ($download_mod_info[$i] !== '') {
                            $new_data[$i]['download_mod_info'] = sanitize_text_field($download_mod_info[$i]);
                        }
                    }
                }
            }
        }
    }

    if (!empty($new_data) && $new_data !== $download_links) {
        update_post_meta($post_id, 'repeatable_download_link', $new_data);
    } elseif (empty($new_data) && $download_links) {
        delete_post_meta($post_id, 'repeatable_download_link', $download_links);
    }
}

function screenshots_callback($post){
    $screenshots = get_post_meta($post->ID, 'ss_images', true);
    wp_nonce_field('screenshots_nonce', 'screenshots_nonce');
    // Counts every row rendered below, including the hidden template row that
    // "Add Screenshot" clones. It used to be set only inside the branch for
    // posts that already had screenshots, so on a post without any the template
    // row came out with an empty id and its Upload button pointed at nothing.
    $counter = 1;
    ?>
    <table id="screenshots-table" class="form-table">
        <tbody>
            <?php if ($screenshots) : ?>
                <?php foreach ($screenshots as $field) : ?>
                    <tr>
                        <td>
							<input id="screenshot-url-<?php echo $counter; ?>" class="widefat" type="text" name="ss_url[]" value="<?= esc_url($field['ss_url']); ?>" placeholder="Enter Image URL" />
						</td>
                        <td>
							<button type="button" id="screenshot-btn-<?php echo $counter; ?>" class="upload-screenshot-btn components-button is-secondary" data-target="screenshot-url-<?php echo $counter; ?>" style="justify-content: center;"><?php _e('Upload', 'y4apk'); ?></button>
							<button type="button" class="remove-screenshot-btn remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button>
						</td>
                    </tr>
                <?php
				$counter++;
				endforeach; ?>
            <?php else : ?>
                <tr>
                    <td>
						<input id="screenshot-url-1" class="widefat" type="text" name="ss_url[]" value="" placeholder="Enter Image URL" />
					</td>
                    <td>
						<button type="button" id="screenshot-btn-1" class="upload-screenshot-btn components-button is-secondary" data-target="screenshot-url-1" style="justify-content: center;"><?php _e('Upload', 'y4apk'); ?></button>
						<button type="button" class="remove-screenshot-btn remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button>
					</td>
                </tr>
                <?php $counter++; ?>
            <?php endif; ?>
            <tr class="empty-screenshot-row" style="display:none;">
                <td>
					<input id="ss-url-<?php echo $counter; ?>" class="widefat" type="text" name="ss_url[]" value="" placeholder="Enter Image URL" />
				</td>
                <td>
					<button type="button" id="screenshot-btn-<?php echo $counter; ?>" class="upload-screenshot-btn components-button is-secondary" data-target="screenshot-url-<?php echo $counter; ?>"><?php _e('Upload', 'y4apk'); ?></button>
					<button type="button" id="remove-screenshot-btn" class="remove-screenshot-btn remove-btn components-button is-destructive"><?php _e('Remove', 'y4apk'); ?></button>
				</td>
            </tr>
        </tbody>
    </table>
    <button type="button" id="add-screenshot-btn" class="add-btn components-button is-secondary" style="justify-content: center; margin-top: 16px;"><?php _e('Add Screenshot', 'y4apk'); ?></button>
    <?php
}

function save_screenshots($post_id){
    if (!isset($_POST['screenshots_nonce']) || !wp_verify_nonce($_POST['screenshots_nonce'], 'screenshots_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $allowed_post_types = ['post'];

    if (!in_array(get_post_type($post_id), $allowed_post_types)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $screenshots = get_post_meta($post_id, 'ss_images', true);
    $new_data = [];

    $screenshots_url = $_POST['ss_url'];
    $count = count($screenshots_url);

    for ($i = 0; $i < $count; $i++) {
        if ($screenshots_url[$i] !== '') {
            $new_data[$i]['ss_url'] = esc_url($screenshots_url[$i]);
        }
    }

    if (!empty($new_data) && $new_data !== $screenshots) {
        update_post_meta($post_id, 'ss_images', $new_data);
    } elseif (empty($new_data) && $screenshots) {
        delete_post_meta($post_id, 'ss_images', $screenshots);
    }
}