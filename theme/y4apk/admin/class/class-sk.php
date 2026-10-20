<?php
class SK {
    public $post_id;
    public $gplay_url;
    public $apksos_url;
    public $at_url;
    public $apk_id;
    private $apk_info = [];
    private $response = [];
    private $file_version = "";

    public function __construct() {
        $this->check_permission();
    }

    /**
     * The importer used to require a valid remote license key here. Y4APK owns
     * this build, so the only gate left is the WordPress capability check.
     */
    private function check_permission() {
        if (!current_user_can('manage_options')) {
            $response = [];
            $response['error']['message'] = __('Error: You are not allowed to import apps.', 'y4apk');
            die(json_encode($response));
        }
    }

    private function validate_gplay_url() {
        if(empty($this->gplay_url) || !strpos($this->gplay_url, 'play.google.com')) {
            $response = [];
            $response['error']['message'] = __('Error: Please enter valid URL.', 'y4apk');
            die(json_encode($response));
        }
    }

    private function assign_extract_urls() {
        if(preg_match("/\bid=([^\&]+)/", $this->gplay_url, $matches)) {
            $this->apk_id = $matches[1];
            $this->gplay_url = "https://play.google.com/store/apps/details?id=" . $this->apk_id;
            $this->apksos_url = "https://apksos.com/app/" . $this->apk_id;
			$this->at_url = "https://ws2.aptoide.com/api/7/app/getMeta/package_name=" . $this->apk_id;
        } else {
            $response = [];
            $response['error']['message'] = __('Error: APK Package ID not found in URL!', 'y4apk');
            die(json_encode($response));
        }
    }

    private function validate_url_status_code() {
        if(!get_http_response_code($this->gplay_url)) {
            $response = [];
            $response['error']['message'] = __('Error: URL not avilable on Play Store.', 'y4apk');
            die(json_encode($response));
        }

        if(!get_http_response_code($this->at_url) || !get_http_response_code($this->apksos_url)) {
            $this->response['warning']['text'] = __('Warning: Some data not extracted due to expected URL not valid in our extractor.', 'y4apk');
        }
    }

    private function get_apk_data() {
        $scraper = new Scraper();

        $gplay_html = $scraper->scrape_web_html($this->gplay_url);
        $apksos_html = $scraper->scrape_web_html($this->apksos_url);
        $at_json = $scraper->scrape_web_json($this->at_url);

        $at_decode = json_decode($at_json, true);

        $this->apk_info = array();

        // APK ID
        $this->apk_info = ['apk_info' => ['apk_id' => $this->apk_id]];

        // APK Logo
        $gp_logo = $scraper->match('/<meta property="og:image" content="(.*?)">/msi', $gplay_html, 1);
        $at_logo = $at_decode['data']['icon'];

        $this->apk_info['apk_info']['apk_logo'] = (!empty($gp_logo)) ? $gp_logo : ((!empty($at_logo)) ? $at_logo : '');

        // APK Thumbnail
        $at_thumbnail = $at_decode['data']['graphic'];

        $this->apk_info['apk_info']['apk_thumbnail'] = (!empty($at_thumbnail)) ? $at_thumbnail : '';

        // APK Name
        $gp_name = $scraper->match('/<h1 class.*?itemprop="name">(.*?)<\/h1>/msi', $gplay_html, 1);
        $at_name = $at_decode['data']['name'];

        $this->apk_info['apk_info']['apk_name'] = (!empty($gp_name)) ? $gp_name : ((!empty($at_name)) ? $at_name : '');

        // APK Version
        $as_version = trim(strip_tags($scraper->match('/<li>Version.*?:(.*?)<\/li>/msi', $apksos_html, 1)));
        $at_version = $at_decode['data']['file']['vername'];

		$this->apk_info['apk_info']['apk_version'] = (!empty($at_version)) ? $at_version : ((!empty($as_version)) ? $as_version : '');

        // APK Category
        $gp_get_category = trim(strip_tags($scraper->match('/"applicationCategory":"(.*?)",/msi', $gplay_html, 1)));
        $as_category = trim(strip_tags($scraper->match('/"applicationCategory": "(.*?)",/msi', $apksos_html, 1)));
        $gp_category = get_parsed_category($gp_get_category);

        $this->apk_info['apk_info']['apk_category'] = (!empty($as_category)) ? $as_category : ((!empty($gp_category)) ? $gp_category : '');

        // APK Sub category
        $gp_get_category = trim(strip_tags($scraper->match('/"applicationCategory":"(.*?)",/msi', $gplay_html, 1)));
        $as_sub_category = trim(strip_tags($scraper->match('/"applicationSubCategory": "(.*?)",/msi', $apksos_html, 1)));
        $gp_sub_category = get_parsed_subcategory($gp_get_category);

        $this->apk_info['apk_info']['apk_sub_category'] = (!empty($as_sub_category) ? $as_sub_category : $gp_sub_category) ?? '';

        // APK Requires
        $at_sdk = $at_decode['data']['file']['hardware']['sdk'];
        $as_requires = trim(strip_tags($scraper->match('/Requires:\s*Android\s+(\d+(?:\.\d+)*)(?:\+)?/msi', $apksos_html, 1)));
        $at_requires = get_android_version_by_sdk($at_sdk);

        $this->apk_info['apk_info']['apk_requires'] = (!empty($as_requires)) ? $as_requires : ((!empty($at_requires)) ? $at_requires : '');

        // APK Size
        $as_size = (preg_match('/"fileSize": "(.*?)",/msi', $apksos_html, $matches)) ? trim(strip_tags($matches[1])) : '';
        $at_size = $at_decode['data']['size'];
        $at_size = bytes_converter($at_size);

        $this->apk_info['apk_info']['apk_size'] = (!empty($as_size)) ? $as_size : ((!empty($at_size)) ? $at_size : '');

        // APK Price
        $gp_price = trim(strip_tags($scraper->match('/"price":"(.*?)",/msi', $gplay_html, 1)));
        $as_price = trim(strip_tags($scraper->match('/<li>\s*Price:\s*(.*?)\s*<\/li>/msi', $apksos_html, 1)));

        $this->apk_info['apk_info']['apk_price'] = (!empty($gp_price)) ? $gp_price : ((!empty($as_price)) ? $as_price : '');

        // APK Developer
        $gp_developer = $scraper->match('/<div class="Vbfug auoIOc"><a.*?><span>(.*?)<\/span><\/a><\/div>/msi', $gplay_html, 1);
        $as_developer = trim(strip_tags($scraper->match('/"publisher":\s*{\s*"@type":\s*"Thing",\s*"name":\s*"(.*?)"/msi', $apksos_html, 1)));
        $at_developer = $at_decode['data']['developer']['name'];

        $this->apk_info['apk_info']['apk_developer'] = (!empty($gp_developer)) ? $gp_developer : ((!empty($as_developer)) ? $as_developer : ((!empty($at_developer)) ? $at_developer : ''));

        // APK Installs
        $gp_installs = trim(strip_tags($scraper->match('/ws<\/div><\/div><div class="wVqUob"><div class="ClM7O">(.*?)<\/div>/msi', $gplay_html, 1)));

        $this->apk_info['apk_info']['apk_installs'] = (!empty($gp_installs)) ? $gp_installs : '';

        // APK Age
        $gp_age = trim(strip_tags($scraper->match('/<span itemprop="contentRating"><span>Rated for (.*?)<\/span>/msi', $gplay_html, 1)));

        $this->apk_info['apk_info']['apk_age'] = (!empty($gp_age)) ? $gp_age : '';

        // APK Rating
        $gp_rating = trim(strip_tags($scraper->match('/<div class="jILTFe">(.*?)<\/div>/msi', $gplay_html, 1)));
        $as_rating = trim(strip_tags($scraper->match('/"ratingValue": "(.*?)"/msi', $apksos_html, 1)));
        $at_rating = $at_decode['data']['stats']['prating']['avg'];

        $this->apk_info['apk_info']['apk_rating'] = (!empty($gp_rating)) ? $gp_rating : ((!empty($as_rating)) ? $as_rating : ((!empty($at_rating)) ? $at_rating : ''));

        // APK Rating Count
        $gp_rating_count = trim(strip_tags($scraper->match('/"ratingCount":"(.*?)"/msi', $gplay_html, 1)));
        $as_rating_count = trim(strip_tags($scraper->match('/"ratingCount": "(.*?)"/msi', $apksos_html, 1)));
        $at_rating_count = $at_decode['data']['stats']['prating']['total'];

        $this->apk_info['apk_info']['apk_rating_count'] = (!empty($gp_rating_count)) ? $gp_rating_count : ((!empty($as_rating_count)) ? $as_rating_count : ((!empty($at_rating_count)) ? $at_rating_count : ''));

        // APK What's new
        $gp_whatsnew = $scraper->match('/<div itemprop="description">(.*?)<\/div>/msi', $gplay_html, 1);
        $as_whatsnew = $scraper->match('/<b>What\'s new<\/b>\s*<hr>\s*<p>(.*?)<\/p>/s', $apksos_html, 1);
        $at_whatsnew = $at_decode['data']['media']['news'];

        $this->apk_info['apk_info']['apk_whatsnew'] = (!empty($gp_whatsnew)) ? $gp_whatsnew : ((!empty($as_whatsnew)) ? $as_whatsnew : ((!empty($at_whatsnew)) ? $at_whatsnew : ''));

        // APK Screenshots
        $gp_screenshots = $scraper->match_all('/<div jscontroller="RQJprf" class="Atcj9b"><img src="(.*?)" srcset=".*?"/msi', $gplay_html, 1);

        $this->apk_info['apk_info']['apk_screenshots'] = (!empty($gp_screenshots)) ? $gp_screenshots : '';

        // APK Content
        $get_gp_content = $scraper->match('/<div class="bARER" data-g-id="description">(.*?)<\/div>.*?<div class="TKjAsc">/msi', $gplay_html, 1);
        $gp_content = preg_replace('/<a.*?">(.*?)<\/a>/is', '$1', $get_gp_content);

        $this->apk_info['apk_info']['apk_content'] = (!empty($gp_content)) ? $gp_content : '';

        // APK Description
        $gp_description = substr(trim(strip_tags($scraper->match('/<meta.*?itemprop="description".*?content="(.*?)">/msi', $gplay_html, 1))), 0, 160);

        $this->apk_info['apk_info']['apk_description'] = (!empty($gp_description)) ? $gp_description : '';

        // Get APK, OBB, Patch
        if(y4apk_options('is_get_apk', true)) {
            $this->apk_info['apk_file_info']['get_apk'] = true;
            if(isset($at_decode['info']) && $at_decode['info'] != "FAIL" && isset($at_decode['data'])) {
                if(isset($at_decode['data']['file']['path'])) {
                    $this->apk_info['apk_file_info']['apk']['apk_file'] = $at_decode['data']['file']['path'];
                    $this->file_version = $at_decode['data']['file']['vername'];
                    $this->apk_info['apk_file_info']['apk']['apk_file_version'] = $this->file_version;
                    $this->apk_info['apk_file_info']['has_apk_file'] = true;
                }
                if(isset($at_decode['data']['obb']['main'])) {
                    $this->apk_info['apk_file_info']['obb']['apk_obb'] = $at_decode['data']['obb']['main']['path'];
                    $this->apk_info['apk_file_info']['obb']['apk_obb_version'] = $this->file_version;
                    $this->apk_info['apk_file_info']['has_obb_file'] = true;
                }
                if(isset($at_decode['data']['obb']['patch'])) {
                    $this->apk_info['apk_file_info']['patch']['apk_patch'] = $at_decode['data']['obb']['patch']['path'];
                    $this->apk_info['apk_file_info']['obb']['apk_patch_version'] = $this->file_version;
                    $this->apk_info['apk_file_info']['has_patch_file'] = true;
                }
            }
        } else {
            $this->apk_info['apk_file_info']['get_apk'] = false;
        }
    }

    private function assign_post_fields() {
        # Add Category
        $parent_cat_slug = sanitize_title_with_dashes(y4apk_clean($this->apk_info['apk_info']['apk_category']));
        $child_cat_slug = sanitize_title_with_dashes(y4apk_clean($this->apk_info['apk_info']['apk_sub_category']));

        $parent_category_id = null;
        $child_category_id = null;

        // Check the parent category exists
        $parent_category = get_term_by("slug", $parent_cat_slug, "category");

        if($parent_category) {
            $parent_category_id = $parent_category->term_id;
        } else {
            $parent_category_id = wp_insert_term($parent_cat_slug, "category")["term_id"];
        }

        // Check the child category exists
        $child_category = get_term_by("slug", $child_cat_slug, "category");

        if($child_category) {
            $child_category_id = $child_category->term_id;
        } else {
            $child_category_id = wp_insert_term($child_cat_slug, "category", ["parent" => $parent_category_id,])["term_id"];
        }

        # Add Post Title
        $is_start_title = y4apk_options('post_title_start', true);

        $is_mod_title = y4apk_options('is_mod_title', true);

        $is_mod_feature_title = y4apk_options('is_mod_feature_title', true);
        $is_mod_feature = y4apk_options('mod_feature', true);

        $is_title_version = y4apk_options('is_title_version', true);
        $title_version = $this->apk_info['apk_info']['apk_version'];

        $is_end_title = y4apk_options('post_title_end');

        $is_blocks_content = y4apk_options('is_blocks_content', true);

        $start_title = (!empty($is_start_title)) ? $is_start_title.' ' : '';
        $main_title = $this->apk_info['apk_info']['apk_name'].' ';
        $mod_title = ($is_mod_title) ? 'MOD APK'.' ' : '';
        $mod_feature_title = ($is_mod_feature_title && !empty($is_mod_feature)) ? '('.$is_mod_feature.') ' : '';
        $version_title = ($is_title_version && !empty($title_version)) ? 'v'.$title_version.' ' : '';
        $end_title = (!empty($is_end_title)) ? $is_end_title : '';

        $post_title = $start_title.$main_title.$mod_title.$mod_feature_title.$version_title.$end_title;

		# Add Post Content
        if($is_blocks_content) {
            function create_block($block_name, $content = "") {
                $block_content = "<!-- wp:" . $block_name . " --> " . $content . " <!-- /wp:" . $block_name . " -->";
                return $block_content;
            }

            function create_blocks($blocks = []) {
                $block_contents = "";
                foreach($blocks as $block) {
                    $block_contents .= create_block(
                        $block["name"],
                        $block["content"]
                    );
                }
                return $block_contents;
            }

            $paragraphs = explode("<br>", $this->apk_info['apk_info']['apk_content']);

            $blocks = [];

            foreach($paragraphs as $paragraph) {
                $trimmed_paragraph = trim($paragraph);
                if(!empty($trimmed_paragraph)) {
                    $blocks[] = [
                        "name" => "paragraph",
                        "content" => $trimmed_paragraph,
                    ];
                }
            }

            $post_content = create_blocks($blocks);
        } else {
            $post_content = $this->apk_info['apk_info']['apk_content'];
        }

        # Create permalink
        $post_permalink = sanitize_title_with_dashes(y4apk_clean($this->apk_info['apk_info']['apk_name']));

		# Create post
        $new_post = [
            "post_title" => $post_title,
            "post_name" => $post_permalink,
            "post_content" => $post_content,
            "post_status" => "draft",
            "post_category" => [$parent_category_id, $child_category_id],
            "post_type" => "post",
        ];

        $post_id = wp_insert_post($new_post);
        $this->post_id = $post_id;

        # Add Meta Data
        add_post_meta($post_id, "wp_title_GP", $this->apk_info['apk_info']['apk_name']);
		add_post_meta($post_id, "wp_version_GP", $this->apk_info['apk_info']['apk_version']);
        add_post_meta($post_id, "wp_developers_GP", $this->apk_info['apk_info']['apk_developer']);
        add_post_meta($post_id, "wp_sizes_GP", $this->apk_info['apk_info']['apk_size']);
        add_post_meta($post_id, "price", $this->apk_info['apk_info']['apk_price']);
        add_post_meta($post_id, "platform", 'ANDROID');
        add_post_meta($post_id, "wp_requires_GP", $this->apk_info['apk_info']['apk_requires']);
        add_post_meta($post_id, "wp_contentrated_GP", $this->apk_info['apk_info']['apk_age']);
        add_post_meta($post_id, "avg_rating", floatval($this->apk_info['apk_info']['apk_rating']));
        add_post_meta($post_id, "total_votes", intval($this->apk_info['apk_info']['apk_rating_count']));
  
		add_post_meta($post_id, "wp_description_GP", $this->apk_info['apk_info']['apk_description']);
		add_post_meta($post_id, "wp_installs_GP", $this->apk_info['apk_info']['apk_installs']);
		add_post_meta($post_id, "wp_GP_ID", $this->apk_id);
        if(!empty($is_mod_feature)) add_post_meta($post_id, "wp_mods", $is_mod_feature);
		
		# Add screenshots
        $screenshots = array();
        $get_screenshots = $this->apk_info['apk_info']['apk_screenshots'];

        for($i = 0; $i < count($get_screenshots); $i++) {
            if(!empty($get_screenshots[$i])) {
                $screenshots[$i]["ss_url"] = esc_url($get_screenshots[$i]);
            }
        }
        add_post_meta($post_id, "ss_images", $screenshots);

        # Add Thumbnail
        $media_uploader = wp_upload_dir();
        $thumbnail_name = "{$this->apk_info['apk_info']['apk_name']}-thumbnail";
        $thumbnail_full_name = "{$this->apk_info['apk_info']['apk_name']}-thumbnail.webp";
        $thumbnail_path = $media_uploader["path"]."/".$thumbnail_full_name;
        $fetch_thumbnail = file_get_contents($this->apk_info['apk_info']['apk_logo']);

        $existing_attachment_id = attachment_url_to_postid($media_uploader["url"]."/".$thumbnail_full_name);

        if($existing_attachment_id) {
            $thumbnail_id = $existing_attachment_id;
        } else {
            $save_thumbnail = fopen($thumbnail_path, "w");
            fwrite($save_thumbnail, $fetch_thumbnail);
            fclose($save_thumbnail);

            $file_type = wp_check_filetype(basename($thumbnail_full_name), null);

            $thumbnail_attachment = [
                "post_mime_type" => $file_type["type"],
                "post_title" => $thumbnail_name,
                "post_content" => "",
                "post_status" => "inherit",
            ];

            $thumbnail_id = wp_insert_attachment(
                $thumbnail_attachment,
                $thumbnail_path
            );

            require_once ABSPATH."wp-admin/includes/image.php";

            $thumbnail_data = wp_generate_attachment_metadata(
                $thumbnail_id,
                $thumbnail_path
            );

            wp_update_attachment_metadata($thumbnail_id, $thumbnail_data);
        }

        set_post_thumbnail($post_id, $thumbnail_id);
    }

    private function validate_url($gplay_url) {
        $this->gplay_url = trim($gplay_url);

        $this->validate_gplay_url();

        $this->assign_extract_urls();

        $this->validate_url_status_code();
    }

    public function create_post($url) {
        $this->validate_url($url);
        $this->get_apk_data();
        $this->assign_post_fields();

        $this->response = array_merge($this->response, $this->apk_info['apk_file_info']);
        $this->response['post_id'] = $this->post_id;
        $this->response['success']['text'] = __('Post created', 'y4apk');
        return json_encode($this->response);
    }
}