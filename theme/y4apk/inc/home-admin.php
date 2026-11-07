<?php
/**
 * Y4APK ▸ Home page (2.53.3): the home page's top and its cards, edited here
 * instead of in the Customizer — the hero's words, button and picture, the
 * four "Best of the best" cards and the four "Recommend" cards, each with a
 * live preview, a post search, its own name and link, picture and colour.
 *
 * It writes the same settings as Customize ▸ Y4APK Panel (hero_title,
 * featured_posts, recommend_posts …), so either place shows the same.
 */

defined('ABSPATH') || exit;

class Y4APK_Home_Admin {

	const SLUG  = 'y4apk-home';
	const SLOTS = 4;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'register_page'], 101);
		add_action('admin_post_y4apk_home_save', [__CLASS__, 'handle_save']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
		add_action('wp_ajax_y4apk_home_find', [__CLASS__, 'ajax_find']);
	}

	public static function register_page() {
		add_submenu_page(Y4APK_MENU, __('Home page', 'y4apk'), __('Home page', 'y4apk'), 'edit_theme_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function enqueue() {
		if (isset($_GET['page']) && $_GET['page'] === self::SLUG) {
			wp_enqueue_media();
		}
	}

	/** The two rows of cards: [setting, show-setting, title, picture size, shape]. */
	private static function sets() {
		return [
			'featured'  => ['featured_posts', 'home_featured_posts_swt', __('Best of the best', 'y4apk'), __('Under the top of the page. Picture about 580 × 288.', 'y4apk'), 'wide'],
			'recommend' => ['recommend_posts', 'home_recommend_swt', __('Recommend', 'y4apk'), __('Further down the page. Picture about 416 × 496.', 'y4apk'), 'tall'],
		];
	}

	/** What the home page shows when nothing was ever saved (the same as components/home/home-*.php). */
	private static function defaults($set) {
		$i = get_template_directory_uri() . '/assets/img/';
		return $set === 'featured'
			? [['bg_image' => $i . 'bg_angrybirds.webp', 'bg_color' => '#E53D2D'], ['bg_image' => $i . 'bg_mk.webp', 'bg_color' => '#F0B835'], ['bg_image' => $i . 'bg_pubg.webp', 'bg_color' => '#3AC1E0'], ['bg_image' => $i . 'bg_dayr.webp', 'bg_color' => '#7DB542']]
			: [['bg_image' => $i . 'bg_plans2.webp', 'bg_color' => '#6BCF6A'], ['bg_image' => $i . 'bg_rayman.webp', 'bg_color' => '#F46CF0'], ['bg_image' => $i . 'bg_clashclans.webp', 'bg_color' => '#FF9533'], ['bg_image' => $i . 'bg_surfers.webp', 'bg_color' => '#FF387D']];
	}

	/** Four slots, filled from the setting (empty ones keep a colour to start from). */
	private static function rows($set) {
		$s = self::sets()[$set];
		$saved = get_theme_mod($s[0], self::defaults($set));
		$saved = is_array($saved) ? array_values(array_filter($saved, 'is_array')) : [];
		$tints = ['#E53D2D', '#F0B835', '#3AC1E0', '#7DB542'];
		$out = [];
		for ($n = 0; $n < self::SLOTS; $n++) {
			$r = $saved[$n] ?? [];
			$out[] = [
				'post_id'  => absint($r['post_id'] ?? 0),
				'title'    => (string) ($r['title'] ?? ''),
				'link'     => (string) ($r['link'] ?? ''),
				'bg_image' => (string) ($r['bg_image'] ?? ''),
				'bg_color' => sanitize_hex_color($r['bg_color'] ?? '') ?: $tints[$n],
			];
		}
		return $out;
	}

	/** A post as the page needs it: its name on the card, title, picture and address. */
	private static function post_info($id) {
		$id = absint($id);
		if (!$id || get_post_type($id) !== 'post' || get_post_status($id) !== 'publish') {
			return null;
		}
		$name = (string) get_post_meta($id, 'wp_title_GP', true);
		return [
			'id'    => $id,
			'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
			'name'  => $name !== '' ? $name : html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
			'icon'  => function_exists('y4apk_icon_url') ? (string) y4apk_icon_url($id, 'thumbnail') : '',
			'url'   => get_permalink($id),
		];
	}

	/** The post search: a few words of the title, a post's address, or its number. */
	public static function ajax_find() {
		if (!current_user_can('edit_theme_options') || !check_ajax_referer('y4apk-home-find', 'nonce', false)) {
			wp_send_json_error([], 403);
		}
		$q = trim(sanitize_text_field(wp_unslash($_POST['q'] ?? '')));
		$ids = [];
		if (ctype_digit($q)) {
			$ids = [(int) $q];
		} elseif (preg_match('~^https?://~i', $q)) {
			$ids = [url_to_postid($q)];
		} elseif (mb_strlen($q) >= 2) {
			global $wpdb; // titles only, on the posts table's own index: no post bodies, no meta
			$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_date DESC LIMIT 12",
				'%' . $wpdb->esc_like($q) . '%'
			)));
		}
		wp_send_json_success(array_values(array_filter(array_map([__CLASS__, 'post_info'], $ids))));
	}

	/** A link as typed: a full address, or one on this site (/roblox/). */
	private static function clean_link($v) {
		$v = trim((string) $v);
		if ($v === '') {
			return '';
		}
		if ($v[0] === '/' && substr($v, 0, 2) !== '//') {
			$clean = esc_url_raw('https://x.invalid' . $v); // cleaned like any address, then kept as a path
			return $clean === '' ? '' : (string) substr($clean, strlen('https://x.invalid'));
		}
		return esc_url_raw($v, ['http', 'https']);
	}

	public static function handle_save() {
		if (!current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'));
		}
		check_admin_referer('y4apk-home-save');
		$p = wp_unslash($_POST);

		// The top of the page
		$title = wp_kses(trim((string) ($p['hero_title'] ?? '')), ['span' => ['class' => [], 'style' => []], 'b' => [], 'strong' => [], 'em' => [], 'br' => []]);
		$title !== '' ? set_theme_mod('hero_title', $title) : remove_theme_mod('hero_title');
		$btn = sanitize_text_field($p['hero_btn_name'] ?? '');
		$btn !== '' ? set_theme_mod('hero_btn_name', $btn) : remove_theme_mod('hero_btn_name');
		$url = self::clean_link($p['hero_btn_url'] ?? '');
		$url !== '' ? set_theme_mod('hero_btn_url', $url[0] === '/' ? home_url($url) : $url) : remove_theme_mod('hero_btn_url');
		$img = esc_url_raw(trim((string) ($p['hero_img'] ?? '')));
		$img !== '' ? set_theme_mod('hero_img', $img) : remove_theme_mod('hero_img');
		$logo = esc_url_raw(trim((string) ($p['home_recommend_logo'] ?? '')));
		$logo !== '' ? set_theme_mod('home_recommend_logo', $logo) : remove_theme_mod('home_recommend_logo');

		// The cards: the slots with a post, in their order
		foreach (self::sets() as $set => $s) {
			set_theme_mod($s[1], !empty($p['show'][$set]));
			$rows = [];
			foreach (array_slice((array) ($p['cards'][$set] ?? []), 0, self::SLOTS) as $r) {
				$id = absint($r['post_id'] ?? 0);
				if (!self::post_info($id)) {
					continue;
				}
				$rows[] = [
					'post_id'  => (string) $id,
					'title'    => mb_substr(sanitize_text_field($r['title'] ?? ''), 0, 60),
					'link'     => self::clean_link($r['link'] ?? ''),
					'bg_image' => esc_url_raw(trim((string) ($r['bg_image'] ?? ''))),
					'bg_color' => sanitize_hex_color($r['bg_color'] ?? '') ?: '#29b1d8',
				];
			}
			set_theme_mod($s[0], $rows);
		}
		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
		exit;
	}

	/* ---------------------------------------------------------------- */

	public static function render() {
		$hero_title = get_theme_mod('hero_title', 'Large Category of the most popular games with mods');
		$hero_btn   = get_theme_mod('hero_btn_name', 'All Games');
		$hero_url   = get_theme_mod('hero_btn_url', home_url('/category/games/'));
		$hero_img   = (string) get_theme_mod('hero_img', '');
		$rec_logo   = (string) get_theme_mod('home_recommend_logo', get_template_directory_uri() . '/assets/img/recommend-logo.svg');
		$posts = [];
		foreach (self::sets() as $set => $s) {
			foreach (self::rows($set) as $r) {
				if ($r['post_id'] && ($info = self::post_info($r['post_id']))) {
					$posts[$r['post_id']] = $info;
				}
			}
		}
		$img_field = function ($name, $value, $label) {
			?>
			<div class="y4hm-img">
				<input type="url" class="regular-text code" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" placeholder="https://" data-img>
				<button type="button" class="button" data-pick><?php echo esc_html($label); ?></button>
			</div>
			<?php
		};
		?>
		<div class="wrap y4hm">
			<h1><?php esc_html_e('Home page', 'y4apk'); ?></h1>
			<p class="y4hm-lead"><?php esc_html_e('The top of the home page and its cards. These are the same settings as Customize ▸ Y4APK Panel, so either place shows the same.', 'y4apk'); ?></p>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved. Clear the page cache (10Web ▸ Clear Cache) so visitors see it.', 'y4apk'); ?></p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_home_save">
				<?php wp_nonce_field('y4apk-home-save'); ?>

				<section class="y4hm-box">
					<h2><?php esc_html_e('Top of the page', 'y4apk'); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="y4hm-ht"><?php esc_html_e('Heading', 'y4apk'); ?></label></th><td><input id="y4hm-ht" type="text" class="large-text" name="hero_title" value="<?php echo esc_attr($hero_title); ?>"></td></tr>
						<tr><th><label for="y4hm-hb"><?php esc_html_e('Button', 'y4apk'); ?></label></th><td><input id="y4hm-hb" type="text" class="regular-text" name="hero_btn_name" value="<?php echo esc_attr($hero_btn); ?>"></td></tr>
						<tr><th><label for="y4hm-hu"><?php esc_html_e('Button link', 'y4apk'); ?></label></th><td><input id="y4hm-hu" type="text" class="regular-text code" name="hero_btn_url" value="<?php echo esc_attr($hero_url); ?>"><p class="description"><?php esc_html_e('A full address, or one on this site such as /games/.', 'y4apk'); ?></p></td></tr>
						<tr><th><?php esc_html_e('Picture', 'y4apk'); ?></th><td><?php $img_field('hero_img', $hero_img, __('Choose from Media Library', 'y4apk')); ?><p class="description"><?php esc_html_e('Empty: the theme’s own drawing.', 'y4apk'); ?></p></td></tr>
					</table>
				</section>

				<?php foreach (self::sets() as $set => $s) :
					$show = get_theme_mod($s[1], false);
					$show = $show && $show !== 'off';
					?>
					<section class="y4hm-box">
						<div class="y4hm-head">
							<h2><?php echo esc_html($s[2]); ?></h2>
							<label class="y4hm-switch"><input type="checkbox" name="show[<?php echo esc_attr($set); ?>]" value="1" <?php checked($show); ?>><span></span><?php esc_html_e('Show on the home page', 'y4apk'); ?></label>
						</div>
						<p class="description"><?php echo esc_html($s[3]); ?> <?php esc_html_e('A card without a post is left out.', 'y4apk'); ?></p>
						<?php if ($set === 'recommend') : ?>
							<p class="y4hm-logo"><b><?php esc_html_e('Picture beside the cards', 'y4apk'); ?></b><?php $img_field('home_recommend_logo', $rec_logo, __('Choose', 'y4apk')); ?></p>
						<?php endif; ?>
						<div class="y4hm-grid">
							<?php foreach (self::rows($set) as $n => $r) :
								$info = $r['post_id'] ? ($posts[$r['post_id']] ?? null) : null;
								$f = 'cards[' . $set . '][' . $n . ']';
								?>
								<div class="y4hm-card" data-card data-shape="<?php echo esc_attr($s[4]); ?>">
									<div class="y4hm-prev" data-prev style="--c: <?php echo esc_attr($r['bg_color']); ?>">
										<i class="y4hm-prev-ic" data-prev-ic><?php if ($info && $info['icon']) : ?><img src="<?php echo esc_url($info['icon'], ['http', 'https', 'data']); ?>" alt=""><?php endif; ?></i>
										<img class="y4hm-prev-bg" data-prev-bg src="<?php echo esc_url($r['bg_image']); ?>" alt=""<?php echo $r['bg_image'] === '' ? ' hidden' : ''; ?>>
										<b data-prev-name><?php echo esc_html($r['title'] !== '' ? $r['title'] : ($info ? $info['name'] : __('Choose a post', 'y4apk'))); ?></b>
										<small><?php esc_html_e('Download', 'y4apk'); ?></small>
										<span class="y4hm-n"><?php echo (int) $n + 1; ?></span>
									</div>
									<div class="y4hm-fields">
										<label><?php esc_html_e('Post', 'y4apk'); ?></label>
										<input type="hidden" name="<?php echo esc_attr($f); ?>[post_id]" value="<?php echo $info ? (int) $info['id'] : ''; ?>" data-id>
										<div class="y4hm-chosen" data-chosen<?php echo $info ? '' : ' hidden'; ?>>
											<span data-chosen-t><?php echo $info ? esc_html($info['title']) : ''; ?></span>
											<button type="button" class="button-link" data-change><?php esc_html_e('Change', 'y4apk'); ?></button>
											<button type="button" class="button-link y4hm-del" data-clear><?php esc_html_e('Remove', 'y4apk'); ?></button>
										</div>
										<div class="y4hm-find" data-find<?php echo $info ? ' hidden' : ''; ?>>
											<input type="search" class="widefat" placeholder="<?php esc_attr_e('Search a game or app, or paste its link', 'y4apk'); ?>" autocomplete="off" data-q>
											<ul class="y4hm-hits" data-hits hidden></ul>
										</div>
										<label><?php esc_html_e('Card name', 'y4apk'); ?> <em><?php esc_html_e('optional', 'y4apk'); ?></em></label>
										<input type="text" class="widefat" maxlength="60" name="<?php echo esc_attr($f); ?>[title]" value="<?php echo esc_attr($r['title']); ?>" placeholder="<?php echo esc_attr($info ? $info['name'] : ''); ?>" data-name>
										<label><?php esc_html_e('Link', 'y4apk'); ?> <em><?php esc_html_e('optional', 'y4apk'); ?></em></label>
										<input type="text" class="widefat code" name="<?php echo esc_attr($f); ?>[link]" value="<?php echo esc_attr($r['link']); ?>" placeholder="<?php echo esc_attr($info ? wp_make_link_relative($info['url']) : '/roblox/'); ?>" data-link>
										<label><?php esc_html_e('Picture', 'y4apk'); ?></label>
										<?php $img_field($f . '[bg_image]', $r['bg_image'], __('Choose', 'y4apk')); ?>
										<label><?php esc_html_e('Colour', 'y4apk'); ?></label>
										<input type="color" name="<?php echo esc_attr($f); ?>[bg_color]" value="<?php echo esc_attr($r['bg_color']); ?>" data-color>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>

				<p class="y4hm-save"><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></p>
			</form>
		</div>
		<style>
			.y4hm { max-width: 1240px; }
			.y4hm-lead { max-width: 760px; color: #50575e; }
			.y4hm-box { margin: 16px 0 20px; padding: 20px 24px 24px; background: #fff; border: 1px solid #dcdcde; border-radius: 12px; }
			.y4hm-box h2 { margin: 0 0 6px; font-size: 17px; }
			.y4hm-box .form-table th { width: 140px; }
			.y4hm-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
			.y4hm-switch { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
			.y4hm-switch input { position: absolute; opacity: 0; pointer-events: none; }
			.y4hm-switch span { position: relative; width: 36px; height: 20px; border-radius: 20px; background: #c3c4c7; transition: background .15s; }
			.y4hm-switch span::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; }
			.y4hm-switch input:checked + span { background: #2271b1; }
			.y4hm-switch input:checked + span::after { transform: translateX(16px); }
			.y4hm-switch input:focus-visible + span { outline: 2px solid #2271b1; outline-offset: 2px; }
			.y4hm-logo { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 12px 0 0; }
			.y4hm-img { display: flex; gap: 6px; align-items: center; }
			.y4hm-img input { flex: 1; min-width: 0; }
			.y4hm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; margin-top: 16px; }
			.y4hm-card { display: flex; flex-direction: column; border: 1px solid #e0e0e3; border-radius: 12px; overflow: visible; background: #fafbfc; }
			.y4hm-prev { position: relative; overflow: hidden; height: 136px; margin: 12px 12px 0; padding: 0 16px 14px; border-radius: 14px; display: flex; flex-direction: column; justify-content: flex-end; color: #fff; background: linear-gradient(120deg, var(--c), color-mix(in srgb, var(--c) 70%, #000)); }
			.y4hm-card[data-shape="tall"] .y4hm-prev { height: 200px; }
			.y4hm-prev-bg { position: absolute; right: 0; bottom: 0; height: 100%; width: auto; max-width: 70%; object-fit: contain; object-position: right bottom; }
			.y4hm-prev-ic { position: absolute; top: 10px; left: 10px; width: 44px; height: 44px; border-radius: 12px; overflow: hidden; background: rgba(255, 255, 255, .25); box-shadow: 0 0 0 3px #fff; z-index: 1; }
			.y4hm-prev-ic img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.y4hm-prev b, .y4hm-prev small { position: relative; z-index: 1; text-shadow: 0 1px 6px rgba(0, 0, 0, .35); }
			.y4hm-prev b { font-size: 17px; line-height: 1.2; max-width: 60%; }
			.y4hm-prev small { margin-top: 4px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .9; }
			.y4hm-n { position: absolute; top: 10px; right: 10px; z-index: 1; display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; font-size: 12px; font-weight: 700; color: #1d2327; background: rgba(255, 255, 255, .9); }
			.y4hm-fields { padding: 12px 14px 16px; }
			.y4hm-fields > label { display: block; margin: 10px 0 4px; font-weight: 600; }
			.y4hm-fields > label em { font-weight: 400; font-style: normal; color: #787c82; }
			.y4hm-chosen { display: flex; align-items: center; gap: 10px; padding: 6px 10px; border-radius: 8px; background: #fff; border: 1px solid #dcdcde; }
			.y4hm-chosen span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.y4hm-del { color: #b32d2e !important; }
			.y4hm-find { position: relative; }
			.y4hm-hits { position: absolute; z-index: 20; left: 0; right: 0; top: 100%; margin: 4px 0 0; padding: 4px; list-style: none; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; box-shadow: 0 8px 24px rgba(0, 0, 0, .12); max-height: 280px; overflow: auto; }
			.y4hm-hits li { margin: 0; }
			.y4hm-hits button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px 8px; border: 0; border-radius: 6px; background: none; text-align: left; cursor: pointer; }
			.y4hm-hits button:hover, .y4hm-hits button:focus { background: #f0f6fc; outline: 0; }
			.y4hm-hits img { width: 28px; height: 28px; border-radius: 6px; object-fit: cover; flex: none; }
			.y4hm-hits .none { padding: 8px; color: #787c82; }
			.y4hm-fields input[type="color"] { width: 64px; height: 32px; padding: 2px; }
			.y4hm-save { position: sticky; bottom: 0; margin: 0; padding: 12px 0; background: linear-gradient(transparent, #f0f0f1 30%); }
		</style>
		<script>
		jQuery(function ($) {
			var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, NONCE = <?php echo wp_json_encode(wp_create_nonce('y4apk-home-find')); ?>;
			var T = <?php echo wp_json_encode(['none' => __('Nothing found. Try fewer words.', 'y4apk'), 'choose' => __('Choose a post', 'y4apk'), 'pick' => __('Choose a picture', 'y4apk')]); ?>;
			// Pictures from the Media Library
			$('.y4hm').on('click', '[data-pick]', function (e) {
				e.preventDefault();
				var input = $(this).siblings('[data-img]'), frame = wp.media({title: T.pick, multiple: false, library: {type: 'image'}});
				frame.on('select', function () { input.val(frame.state().get('selection').first().toJSON().url).trigger('input'); });
				frame.open();
			});
			$('[data-card]').each(function () {
				var card = $(this), q = card.find('[data-q]'), hits = card.find('[data-hits]'), timer = null, seq = 0, base = card.find('[data-prev-name]').text();
				var name = function () { var v = $.trim(card.find('[data-name]').val()); card.find('[data-prev-name]').text(v || base || T.choose); };
				var choose = function (p) {
					card.find('[data-id]').val(p ? p.id : '');
					card.find('[data-chosen-t]').text(p ? p.title : '');
					card.find('[data-chosen]').prop('hidden', !p); card.find('[data-find]').prop('hidden', !!p);
					card.find('[data-prev-ic]').html(p && p.icon ? $('<img alt="">').attr('src', p.icon) : '');
					card.find('[data-name]').attr('placeholder', p ? p.name : '');
					card.find('[data-link]').attr('placeholder', p ? p.url.replace(/^https?:\/\/[^\/]+/, '') : '/roblox/');
					base = p ? p.name : ''; name(); hits.prop('hidden', true).empty();
					if (!p) { q.val('').trigger('focus'); }
				};
				card.find('[data-change]').on('click', function () { card.find('[data-chosen]').prop('hidden', true); card.find('[data-find]').prop('hidden', false); q.trigger('focus'); });
				card.find('[data-clear]').on('click', function () { choose(null); });
				q.on('input', function () {
					clearTimeout(timer);
					var v = $.trim(q.val());
					if (v.length < 2) { hits.prop('hidden', true).empty(); return; }
					timer = setTimeout(function () {
						var mine = ++seq;
						$.post(AJAX, {action: 'y4apk_home_find', nonce: NONCE, q: v}).done(function (r) {
							if (mine !== seq) { return; }
							hits.empty().prop('hidden', false);
							var list = r && r.success ? r.data : [];
							if (!list.length) { hits.append($('<li class="none">').text(T.none)); return; }
							list.forEach(function (p) {
								var b = $('<button type="button">').append(p.icon ? $('<img alt="">').attr('src', p.icon) : '', $('<span>').text(p.title));
								b.on('click', function () { choose(p); });
								hits.append($('<li>').append(b));
							});
						});
					}, 250);
				});
				q.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); hits.find('button').first().trigger('click'); } });
				$(document).on('click', function (e) { if (!$(e.target).closest(card.find('[data-find]')).length) { hits.prop('hidden', true); } });
				card.find('[data-name]').on('input', name);
				card.find('[data-color]').on('input', function () { card.find('[data-prev]').css('--c', this.value); });
				card.find('[data-img]').on('input change', function () { var v = $.trim(this.value); card.find('[data-prev-bg]').attr('src', v).prop('hidden', !v); });
			});
		});
		</script>
		<?php
	}
}

Y4APK_Home_Admin::init();
