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
	const SLOTS = 4;  // shown at least
	const MAX   = 12; // cards in a row at most (2.59: add more than four)

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

	/** A colour to start a card with. */
	private static function tint($n) {
		$tints = ['#E53D2D', '#F0B835', '#3AC1E0', '#7DB542', '#8E44EC', '#FF387D', '#FF9533', '#2A78D6'];
		return $tints[$n % count($tints)];
	}

	/** The cards, filled from the setting: at least four slots (empty ones keep a colour to start from), up to twelve. */
	private static function rows($set) {
		$s = self::sets()[$set];
		$saved = get_theme_mod($s[0], self::defaults($set));
		$saved = is_array($saved) ? array_values(array_filter($saved, 'is_array')) : [];
		$out = [];
		for ($n = 0; $n < max(self::SLOTS, min(self::MAX, count($saved))); $n++) {
			$r = $saved[$n] ?? [];
			$out[] = [
				'post_id'  => absint($r['post_id'] ?? 0),
				'title'    => (string) ($r['title'] ?? ''),
				'link'     => (string) ($r['link'] ?? ''),
				'bg_image' => (string) ($r['bg_image'] ?? ''),
				'bg_color' => sanitize_hex_color($r['bg_color'] ?? '') ?: self::tint($n),
				'icon'     => (string) ($r['icon'] ?? ''), // 2.70.2
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
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title LIKE %s
				 ORDER BY (post_title LIKE %s) DESC, CHAR_LENGTH(post_title) ASC, post_date DESC LIMIT 15", // 2.70.2: "Roblox MOD APK" before the scripts that only name Roblox
				'%' . $wpdb->esc_like($q) . '%', $wpdb->esc_like($q) . '%'
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

	/**
	 * The cards sent for one row, in the order they stand on the page: those
	 * with a post, and (2.70.2) those that are only a link with a name, such as
	 * /roblox/. A card with neither is left out.
	 */
	private static function clean_cards($list) {
		$rows = [];
		foreach (array_slice(array_values(array_filter((array) $list, 'is_array')), 0, self::MAX) as $r) {
			$id = absint($r['post_id'] ?? 0);
			$title = mb_substr(sanitize_text_field($r['title'] ?? ''), 0, 60);
			$link = self::clean_link($r['link'] ?? '');
			if (!self::post_info($id)) {
				if ($link === '' || $title === '') {
					continue;
				}
				$id = 0;
			}
			$rows[] = [
				'post_id'  => $id ? (string) $id : '',
				'title'    => $title,
				'link'     => $link,
				'bg_image' => esc_url_raw(trim((string) ($r['bg_image'] ?? ''))),
				'bg_color' => sanitize_hex_color($r['bg_color'] ?? '') ?: '#29b1d8',
				'icon'     => esc_url_raw(trim((string) ($r['icon'] ?? ''))),
			];
		}
		return $rows;
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
			set_theme_mod($s[0], self::clean_cards($p['cards'][$set] ?? []));
		}

		// 2.60: the owner's own rows, in their order (one with no heading and no card is left out)
		if (class_exists('Y4APK_Home_Rows')) {
			$own = [];
			foreach (array_slice(array_values(array_filter((array) ($p['rows'] ?? []), 'is_array')), 0, Y4APK_Home_Rows::MAX) as $r) {
				$cards = self::clean_cards($r['cards'] ?? []);
				$title = mb_substr(sanitize_text_field($r['title'] ?? ''), 0, 60);
				if ($title === '' && !$cards) {
					continue;
				}
				$id = (string) ($r['id'] ?? '');
				$own[] = [
					'id'    => preg_match('/^[a-z0-9]{1,20}$/', $id) ? $id : 'r' . strtolower(wp_generate_password(6, false, false)),
					'title' => $title,
					'link'  => self::clean_link($r['link'] ?? ''),
					'show'  => !empty($r['show']),
					'style' => ($r['style'] ?? '') === 'tall' ? 'tall' : 'wide',
					'where' => isset(Y4APK_Home_Rows::places()[$r['where'] ?? '']) ? $r['where'] : 'top',
					'cards' => $cards,
				];
			}
			set_theme_mod(Y4APK_Home_Rows::MOD, $own);
		}
		y4apk_purge_pages();
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
		$own = class_exists('Y4APK_Home_Rows') ? Y4APK_Home_Rows::get() : [];
		foreach ($own as $row) {
			foreach ($row['cards'] as $r) {
				$pid = absint($r['post_id'] ?? 0);
				if ($pid && !isset($posts[$pid]) && ($info = self::post_info($pid))) {
					$posts[$pid] = $info;
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
		$card = function ($prefix, $shape, $n, $r, $info) use ($img_field) {
			$f = $prefix . '[' . $n . ']';
			?>
			<div class="y4hm-card" data-card data-shape="<?php echo esc_attr($shape); ?>">
				<div class="y4hm-bar">
					<b data-card-label><?php echo esc_html(is_int($n) ? sprintf(__('Card %s', 'y4apk'), $n + 1) : __('New card', 'y4apk')); ?></b>
					<span class="y4hm-bar-acts">
						<button type="button" class="y4hm-ic" data-move="-1" title="<?php esc_attr_e('Move left', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Move left', 'y4apk'); ?>">←</button>
						<button type="button" class="y4hm-ic" data-move="1" title="<?php esc_attr_e('Move right', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Move right', 'y4apk'); ?>">→</button>
						<button type="button" class="y4hm-ic is-del" data-drop-card title="<?php esc_attr_e('Remove this card', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Remove this card', 'y4apk'); ?>">✕</button>
					</span>
				</div>
				<div class="y4hm-prev" data-prev style="--c: <?php echo esc_attr($r['bg_color']); ?>">
					<i class="y4hm-prev-ic" data-prev-ic data-post-ic="<?php echo esc_attr($info ? $info['icon'] : ''); ?>"><?php $ic = ($r['icon'] ?? '') !== '' ? $r['icon'] : ($info ? $info['icon'] : ''); if ($ic !== '') : ?><img src="<?php echo esc_url($ic, ['http', 'https', 'data']); ?>" alt=""><?php endif; ?></i>
					<img class="y4hm-prev-bg" data-prev-bg src="<?php echo esc_url($r['bg_image']); ?>" alt=""<?php echo $r['bg_image'] === '' ? ' hidden' : ''; ?>>
					<b data-prev-name><?php echo esc_html($r['title'] !== '' ? $r['title'] : ($info ? $info['name'] : __('Choose a post', 'y4apk'))); ?></b>
					<small><?php esc_html_e('Download', 'y4apk'); ?></small>
					<span class="y4hm-n" data-card-n><?php echo is_int($n) ? (int) $n + 1 : ''; ?></span>
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
					<label><?php esc_html_e('Icon', 'y4apk'); ?> <em><?php esc_html_e('optional: a square picture; empty: the post’s icon', 'y4apk'); ?></em></label>
					<div class="y4hm-img">
						<input type="url" class="regular-text code" name="<?php echo esc_attr($f); ?>[icon]" value="<?php echo esc_attr($r['icon'] ?? ''); ?>" placeholder="https://" data-img data-icon>
						<button type="button" class="button" data-pick><?php esc_html_e('Choose', 'y4apk'); ?></button>
					</div>
					<label><?php esc_html_e('Colour', 'y4apk'); ?></label>
					<input type="color" name="<?php echo esc_attr($f); ?>[bg_color]" value="<?php echo esc_attr($r['bg_color']); ?>" data-color>
				</div>
			</div>
			<?php
		};
		?>
		<div class="wrap y4hm y4ui">
			<?php Y4APK_Admin_UI::head([
				'icon'    => 'home',
				'tint'    => 'green',
				'title'   => esc_html__('Home page', 'y4apk'),
				'sub'     => esc_html__('The top of the home page and its cards, each with a preview. These are the same settings as Customize ▸ Y4APK Panel, so either place shows the same.', 'y4apk'),
				'actions' => '<a class="button button-large" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener">' . Y4APK_Admin_UI::icon('eye', 16) . ' ' . esc_html__('View the home page', 'y4apk') . '</a>'
					. '<button class="button button-primary button-large" form="y4hm-form">' . esc_html__('Save', 'y4apk') . '</button>',
			]); ?>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(__('Saved.', 'y4apk') . ' ' . y4apk_cache_note()); ?></p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="y4hm-form">
				<input type="hidden" name="action" value="y4apk_home_save">
				<?php wp_nonce_field('y4apk-home-save'); ?>

				<section class="y4ui-card">
					<div class="y4ui-card-h"><div>
						<h2><?php esc_html_e('Top of the page', 'y4apk'); ?></h2>
						<p><?php esc_html_e('The first thing visitors see: a heading, a button and a picture.', 'y4apk'); ?></p>
					</div></div>
					<div class="y4ui-card-b y4hm-top">
						<div class="y4ui-fields is-two">
							<div class="y4ui-field is-wide"><label for="y4hm-ht"><?php esc_html_e('Heading', 'y4apk'); ?></label><input id="y4hm-ht" type="text" name="hero_title" value="<?php echo esc_attr($hero_title); ?>" data-hero-in="title"></div>
							<div class="y4ui-field"><label for="y4hm-hb"><?php esc_html_e('Button', 'y4apk'); ?></label><input id="y4hm-hb" type="text" name="hero_btn_name" value="<?php echo esc_attr($hero_btn); ?>" data-hero-in="btn"></div>
							<div class="y4ui-field"><label for="y4hm-hu"><?php esc_html_e('Button link', 'y4apk'); ?></label><input id="y4hm-hu" type="text" class="code" name="hero_btn_url" value="<?php echo esc_attr($hero_url); ?>"><p class="description"><?php esc_html_e('A full address, or one on this site such as /games/.', 'y4apk'); ?></p></div>
							<div class="y4ui-field is-wide"><span class="y4ui-label"><?php esc_html_e('Picture', 'y4apk'); ?></span><?php $img_field('hero_img', $hero_img, __('Choose from Media Library', 'y4apk')); ?><p class="description"><?php esc_html_e('Empty: the theme’s own drawing.', 'y4apk'); ?></p></div>
						</div>
						<div class="y4hm-hero" aria-hidden="true">
							<span class="y4hm-hero-tag"><?php esc_html_e('Preview', 'y4apk'); ?></span>
							<div class="y4hm-hero-t">
								<b data-hero="title"><?php echo esc_html(wp_strip_all_tags($hero_title)); ?></b>
								<span class="y4hm-hero-btn" data-hero="btn"><?php echo esc_html($hero_btn); ?></span>
							</div>
							<div class="y4hm-hero-pic"><img src="<?php echo esc_url($hero_img); ?>" alt="" data-hero="img"<?php echo $hero_img === '' ? ' hidden' : ''; ?>><span class="y4hm-hero-own" data-hero="own"<?php echo $hero_img !== '' ? ' hidden' : ''; ?>><?php esc_html_e('The theme’s own drawing', 'y4apk'); ?></span></div>
						</div>
					</div>
				</section>

				<?php foreach (self::sets() as $set => $s) :
					$show = get_theme_mod($s[1], false);
					$show = $show && $show !== 'off';
					?>
					<section class="y4ui-card">
						<div class="y4ui-card-h">
							<div>
								<h2><?php echo esc_html($s[2]); ?></h2>
								<p><?php echo esc_html($s[3]); ?> <?php esc_html_e('A card needs a post, or a link and a name (such as /roblox/ for a category).', 'y4apk'); ?></p>
							</div>
							<label class="y4hm-switch"><input type="checkbox" name="show[<?php echo esc_attr($set); ?>]" value="1" <?php checked($show); ?>><span></span><?php esc_html_e('Show on the home page', 'y4apk'); ?></label>
						</div>
						<div class="y4ui-card-b">
						<?php if ($set === 'recommend') : ?>
							<div class="y4hm-logo"><b><?php esc_html_e('Picture beside the cards', 'y4apk'); ?></b><?php $img_field('home_recommend_logo', $rec_logo, __('Choose', 'y4apk')); ?></div>
						<?php endif; ?>
							<?php $list = self::rows($set); ?>
							<div class="y4hm-grid" data-cards="<?php echo esc_attr($set); ?>" data-prefix="<?php echo esc_attr('cards[' . $set . ']'); ?>" data-shape="<?php echo esc_attr($s[4]); ?>" data-max="<?php echo (int) self::MAX; ?>">
								<?php foreach ($list as $n => $r) {
									$card('cards[' . $set . ']', $s[4], $n, $r, $r['post_id'] ? ($posts[$r['post_id']] ?? null) : null);
								} ?>
								<button type="button" class="y4hm-add" data-add-card<?php echo count($list) >= self::MAX ? ' hidden' : ''; ?>>
									<span aria-hidden="true">+</span><b><?php esc_html_e('Add a card', 'y4apk'); ?></b>
									<small><?php echo esc_html(sprintf(__('Up to %s. With more than four, the row on the home page slides sideways.', 'y4apk'), number_format_i18n(self::MAX))); ?></small>
								</button>
							</div>
						</div>
					</section>
				<?php endforeach; ?>

				<?php if (class_exists('Y4APK_Home_Rows')) :
					$empty_card = ['post_id' => 0, 'title' => '', 'link' => '', 'bg_image' => '', 'bg_color' => '#29b1d8'];
					$add_tile = function ($count) {
						?>
						<button type="button" class="y4hm-add" data-add-card<?php echo $count >= self::MAX ? ' hidden' : ''; ?>>
							<span aria-hidden="true">+</span><b><?php esc_html_e('Add a card', 'y4apk'); ?></b>
							<small><?php echo esc_html(sprintf(__('Up to %s. With more than four, the row on the home page slides sideways.', 'y4apk'), number_format_i18n(self::MAX))); ?></small>
						</button>
						<?php
					};
					$row_html = function ($row) use ($card, $add_tile, $posts, $empty_card) {
						$rid = $row['id'];
						$name = 'rows[' . $rid . ']';
						$cards = $row['cards'] ?: [$empty_card, $empty_card, $empty_card, $empty_card];
						?>
						<div class="y4hm-row" data-row>
							<input type="hidden" name="<?php echo esc_attr($name); ?>[id]" value="<?php echo esc_attr($rid); ?>">
							<div class="y4hm-row-h">
								<span class="y4hm-row-n" data-row-n></span>
								<div class="y4hm-row-fields">
									<div class="y4ui-field"><label><?php esc_html_e('Heading', 'y4apk'); ?></label><input type="text" maxlength="60" name="<?php echo esc_attr($name); ?>[title]" value="<?php echo esc_attr($row['title']); ?>" placeholder="<?php esc_attr_e('For example: Top Roblox games', 'y4apk'); ?>" data-row-title></div>
									<div class="y4ui-field"><label><?php esc_html_e('Cards', 'y4apk'); ?></label><select name="<?php echo esc_attr($name); ?>[style]" data-row-style><?php foreach (Y4APK_Home_Rows::styles() as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($row['style'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></div>
									<div class="y4ui-field"><label><?php esc_html_e('Where on the home page', 'y4apk'); ?></label><select name="<?php echo esc_attr($name); ?>[where]"><?php foreach (Y4APK_Home_Rows::places() as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($row['where'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></div>
									<div class="y4ui-field"><label><?php esc_html_e('“See all” link', 'y4apk'); ?> <em><?php esc_html_e('optional', 'y4apk'); ?></em></label><input type="text" class="code" name="<?php echo esc_attr($name); ?>[link]" value="<?php echo esc_attr($row['link']); ?>" placeholder="/games/"></div>
								</div>
								<div class="y4hm-row-acts">
									<label class="y4hm-switch"><input type="checkbox" name="<?php echo esc_attr($name); ?>[show]" value="1" <?php checked($row['show']); ?>><span></span><?php esc_html_e('Show', 'y4apk'); ?></label>
									<button type="button" class="y4hm-ic" data-row-move="-1" title="<?php esc_attr_e('Move up', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Move up', 'y4apk'); ?>">↑</button>
									<button type="button" class="y4hm-ic" data-row-move="1" title="<?php esc_attr_e('Move down', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Move down', 'y4apk'); ?>">↓</button>
									<button type="button" class="y4hm-ic is-del" data-drop-row title="<?php esc_attr_e('Remove this row', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Remove this row', 'y4apk'); ?>">✕</button>
								</div>
							</div>
							<div class="y4hm-grid" data-cards="row-<?php echo esc_attr($rid); ?>" data-prefix="<?php echo esc_attr($name . '[cards]'); ?>" data-shape="<?php echo esc_attr($row['style']); ?>" data-max="<?php echo (int) self::MAX; ?>">
								<?php foreach (array_values($cards) as $n => $r) {
									$pid = absint($r['post_id'] ?? 0);
									$r = array_merge($empty_card, $r, ['post_id' => $pid]);
									$card($name . '[cards]', $row['style'], $n, $r, $pid ? ($posts[$pid] ?? null) : null);
								}
								$add_tile(count($cards)); ?>
							</div>
						</div>
						<?php
					};
					?>
					<section class="y4ui-card">
						<div class="y4ui-card-h">
							<div>
								<h2><?php esc_html_e('Your own rows', 'y4apk'); ?></h2>
								<p><?php echo esc_html(sprintf(__('Rows of cards you make yourself, each with its heading, its kind of card and its place on the home page. Up to %s rows.', 'y4apk'), number_format_i18n(Y4APK_Home_Rows::MAX))); ?></p>
							</div>
							<button type="button" class="button button-primary" data-add-row<?php echo count($own) >= Y4APK_Home_Rows::MAX ? ' hidden' : ''; ?>>+ <?php esc_html_e('Add a row', 'y4apk'); ?></button>
						</div>
						<div class="y4ui-card-b">
							<div class="y4hm-rows" data-rows data-max="<?php echo (int) Y4APK_Home_Rows::MAX; ?>">
								<?php foreach ($own as $row) { $row_html($row); } ?>
							</div>
							<p class="y4hm-rows-empty" data-rows-empty<?php echo $own ? ' hidden' : ''; ?>><?php esc_html_e('No rows of your own yet. “Add a row”, give it a heading and its cards, then Save.', 'y4apk'); ?></p>
						</div>
					</section>
					<template data-row-tpl><?php $row_html(['id' => '__R__', 'title' => '', 'link' => '', 'show' => true, 'style' => 'wide', 'where' => 'top', 'cards' => []]); ?></template>
				<?php endif; ?>
				<template data-card-tpl><?php $card('__P__', '__S__', '__N__', ['post_id' => 0, 'title' => '', 'link' => '', 'bg_image' => '', 'bg_color' => '#29b1d8'], null); ?></template>

				<div class="y4ui-savebar">
					<button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button>
					<span class="y4ui-hint"><?php echo esc_html(y4apk_page_cache() ? __('Saving clears the page cache: visitors see it at once.', 'y4apk') : __('After saving, clear the page cache so visitors see it.', 'y4apk')); ?></span>
				</div>
			</form>
		</div>
		<style>
			/* The top of the page: its fields, and how it looks */
			.y4hm-top { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; align-items: start; }
			.y4hm-hero { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: center; gap: 12px; min-height: 230px; padding: 28px 24px; border-radius: 14px; overflow: hidden; background: radial-gradient(120% 120% at 100% 0, #e6f7fd 0, #f6fbfd 45%, #fff 100%); border: 1px solid #e6eef2; }
			.y4hm-hero-tag { position: absolute; top: 10px; left: 12px; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #135e96; background: #e8f1fb; }
			.y4hm-hero-t b { display: block; margin: 12px 0 14px; font-size: 22px; line-height: 1.25; color: #172021; overflow-wrap: anywhere; }
			.y4hm-hero-btn { display: inline-block; padding: 9px 18px; border-radius: 99px; font-size: 12px; font-weight: 800; letter-spacing: .02em; text-transform: uppercase; color: #fff; background: #29b1d8; box-shadow: 0 8px 18px -8px rgba(41, 177, 216, .8); }
			.y4hm-hero-pic { display: grid; place-items: center; min-height: 150px; }
			.y4hm-hero-pic img { max-width: 100%; max-height: 190px; object-fit: contain; }
			.y4hm-hero-own { padding: 10px 14px; border: 1px dashed #b7d7e4; border-radius: 12px; color: #4f7787; font-size: 12px; text-align: center; }
			@media (max-width: 1100px) { .y4hm-top { grid-template-columns: minmax(0, 1fr); } }
			.y4hm-switch { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
			.y4hm-switch input { position: absolute; opacity: 0; pointer-events: none; }
			.y4hm-switch span { position: relative; width: 36px; height: 20px; border-radius: 20px; background: #c3c4c7; transition: background .15s; }
			.y4hm-switch span::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; }
			.y4hm-switch input:checked + span { background: #2271b1; }
			.y4hm-switch input:checked + span::after { transform: translateX(16px); }
			.y4hm-switch input:focus-visible + span { outline: 2px solid #2271b1; outline-offset: 2px; }
			.y4hm-logo { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 0 16px; max-width: 760px; }
			.y4hm-logo .y4hm-img { flex: 1; min-width: 260px; }
			.y4hm-img { display: flex; gap: 6px; align-items: center; }
			.y4hm-img input { flex: 1; min-width: 0; }
			.y4hm-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
			@media (max-width: 1400px) { .y4hm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
			@media (max-width: 700px) { .y4hm-grid { grid-template-columns: minmax(0, 1fr); } }
			.y4hm-card { display: flex; flex-direction: column; min-width: 0; border: 1px solid #e0e0e3; border-radius: 14px; overflow: visible; background: #fafbfc; }
			.y4hm-prev { position: relative; overflow: hidden; height: 136px; margin: 12px 12px 0; padding: 0 16px 14px; border-radius: 14px; display: flex; flex-direction: column; justify-content: flex-end; color: #fff; background: linear-gradient(120deg, var(--c), color-mix(in srgb, var(--c) 70%, #000)); }
			.y4hm-card[data-shape="tall"] .y4hm-prev { height: 200px; }
			.y4hm-prev-bg { position: absolute; right: 0; bottom: 0; height: 100%; width: auto; max-width: 70%; object-fit: contain; object-position: right bottom; }
			.y4hm-prev-ic { position: absolute; top: 10px; left: 10px; width: 44px; height: 44px; border-radius: 12px; overflow: hidden; background: rgba(255, 255, 255, .25); box-shadow: 0 0 0 3px #fff; z-index: 1; }
			.y4hm-prev-ic img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.y4hm-prev b, .y4hm-prev small { position: relative; z-index: 1; text-shadow: 0 1px 6px rgba(0, 0, 0, .35); }
			.y4hm-prev b { font-size: 17px; line-height: 1.2; max-width: 60%; }
			.y4hm-prev small { margin-top: 4px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .9; }
			.y4hm-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px 0 14px; }
			.y4hm-bar b { font-size: 12px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #646970; }
			.y4hm-bar-acts { display: inline-flex; gap: 4px; }
			.y4hm-ic { display: grid; place-items: center; width: 28px; height: 28px; padding: 0; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; color: #50575e; font-size: 13px; line-height: 1; cursor: pointer; }
			.y4hm-ic:hover { border-color: #2271b1; color: #2271b1; }
			.y4hm-ic.is-del:hover { border-color: #d63638; color: #d63638; background: #fcf0f1; }
			.y4hm-ic:disabled { opacity: .35; cursor: default; border-color: #dcdcde; color: #50575e; background: #fff; }
			.y4hm-add { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; min-height: 240px; padding: 20px; border: 2px dashed #c3c4c7; border-radius: 14px; background: #fff; color: #2271b1; text-align: center; cursor: pointer; transition: border-color .15s, background-color .15s; }
			.y4hm-add:hover { border-color: #2271b1; background: #f3f8fe; }
			.y4hm-add span { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; background: #e8f1fb; font-size: 26px; line-height: 1; }
			.y4hm-add b { font-size: 14px; }
			.y4hm-add small { max-width: 220px; color: #646970; line-height: 1.5; }
			.y4hm-prev { margin-top: 10px !important; }
			/* The owner's own rows (2.60) */
			.y4hm-row { padding: 18px 0 22px; border-top: 1px solid #f0f0f1; }
			.y4hm-row:first-child { padding-top: 0; border-top: 0; }
			.y4hm-row-h { display: flex; align-items: flex-end; gap: 14px; margin: 0 0 16px; flex-wrap: wrap; }
			.y4hm-row-n { flex: none; align-self: center; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: #e8f1fb; color: #135e96; font-weight: 700; }
			.y4hm-row-fields { flex: 1; min-width: 0; display: grid; grid-template-columns: minmax(180px, 1.6fr) minmax(170px, 1.2fr) minmax(200px, 1.5fr) minmax(150px, 1fr); gap: 12px; }
			.y4hm-row-fields .y4ui-field > label { font-size: 12px; }
			.y4hm-row-fields .y4ui-field > label em { font-weight: 400; font-style: normal; color: #787c82; }
			.y4hm-row-fields select, .y4hm-row-fields input { width: 100%; max-width: none; }
			.y4hm-row-acts { display: flex; align-items: center; gap: 6px; padding-bottom: 4px; }
			.y4hm-row-acts .y4hm-switch { margin-right: 6px; }
			.y4hm-rows-empty { margin: 0; padding: 28px 16px; border: 2px dashed #dcdcde; border-radius: 14px; color: #646970; text-align: center; }
			@media (max-width: 1280px) { .y4hm-row-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
			@media (max-width: 700px) { .y4hm-row-fields { grid-template-columns: minmax(0, 1fr); } }
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
			// The top of the page: its preview follows what is typed
			$('[data-hero-in="title"]').on('input', function () { $('[data-hero="title"]').text($('<div>').html(this.value).text() || '…'); });
			$('[data-hero-in="btn"]').on('input', function () { $('[data-hero="btn"]').text(this.value || '…'); });
			$('input[name="hero_img"]').on('input change', function () { var v = $.trim(this.value); $('[data-hero="img"]').attr('src', v).prop('hidden', !v); $('[data-hero="own"]').prop('hidden', !!v); });
			var initCard = function (el) {
				var card = $(el), q = card.find('[data-q]'), hits = card.find('[data-hits]'), timer = null, seq = 0, base = card.find('[data-prev-name]').text();
				var name = function () { var v = $.trim(card.find('[data-name]').val()); card.find('[data-prev-name]').text(v || base || T.choose); };
				var paintIcon = function () { // 2.70.2: the card's own icon, else its post's
					var own = $.trim(card.find('[data-icon]').val() || ''), ic = own || card.find('[data-prev-ic]').attr('data-post-ic') || '';
					card.find('[data-prev-ic]').html(ic ? $('<img alt="">').attr('src', ic) : '');
				};
				var choose = function (p) {
					card.find('[data-id]').val(p ? p.id : '');
					card.find('[data-chosen-t]').text(p ? p.title : '');
					card.find('[data-chosen]').prop('hidden', !p); card.find('[data-find]').prop('hidden', !!p);
					card.find('[data-prev-ic]').attr('data-post-ic', p && p.icon ? p.icon : '');
					paintIcon();
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
				card.find('[data-img]').not('[data-icon]').on('input change', function () { var v = $.trim(this.value); card.find('[data-prev-bg]').attr('src', v).prop('hidden', !v); });
				card.find('[data-icon]').on('input change', paintIcon);
			};
			$('[data-card]').each(function () { initCard(this); });
			// 2.70.2: say so before Save leaves out a card that has something in it but neither a post nor a link with a name
			$('#y4hm-form').on('submit', function (e) {
				var lost = [];
				$(this).find('[data-card]').each(function () {
					var c = $(this), id = c.find('[data-id]').val(), link = $.trim(c.find('[data-link]').val()), nm = $.trim(c.find('[data-name]').val());
					var something = nm || link || $.trim(c.find('[data-img]').not('[data-icon]').val());
					if (!id && something && !(link && nm)) { lost.push(nm || c.find('[data-card-label]').text()); }
				});
				if (lost.length && !window.confirm(<?php echo wp_json_encode(__('These cards have no post, and no link with a name, so Save leaves them out: %s. Choose a post (Change ▸ search ▸ click it), or give it a link and a name. Save anyway?', 'y4apk')); ?>.replace('%s', lost.join(', ')))) { e.preventDefault(); }
			});
			// 2.59: more than four cards — add, move, remove; their order is the home page's
			var made = 100;
			var renumber = function (grid) {
				var cards = grid.children('[data-card]');
				cards.each(function (i) {
					$(this).find('[data-card-n]').text(i + 1);
					$(this).find('[data-card-label]').text(<?php echo wp_json_encode(__('Card %s', 'y4apk')); ?>.replace('%s', i + 1));
					$(this).find('[data-move="-1"]').prop('disabled', i === 0);
					$(this).find('[data-move="1"]').prop('disabled', i === cards.length - 1);
					$(this).find('[data-drop-card]').prop('disabled', cards.length < 2);
				});
				grid.children('[data-add-card]').prop('hidden', cards.length >= +grid.data('max'));
			};
			$('[data-cards]').each(function () { renumber($(this)); });
			$('.y4hm').on('click', '[data-add-card]', function () {
				var grid = $(this).closest('[data-cards]');
				var html = $('template[data-card-tpl]').html().split('__P__').join(grid.attr('data-prefix')).split('__S__').join(grid.attr('data-shape')).replace(/__N__/g, String(made++));
				var card = $($.parseHTML($.trim(html)));
				card.insertBefore(this);
				initCard(card[0]);
				renumber(grid);
				card.find('[data-q]').trigger('focus');
			});
			$('.y4hm').on('click', '[data-move]', function () {
				var card = $(this).closest('[data-card]'), grid = card.parent();
				if (+$(this).data('move') < 0) { card.prev('[data-card]').before(card); } else { card.next('[data-card]').after(card); }
				renumber(grid);
			});
			$('.y4hm').on('click', '[data-drop-card]', function () {
				var card = $(this).closest('[data-card]'), grid = card.parent();
				if (grid.children('[data-card]').length < 2) { return; }
				card.remove();
				renumber(grid);
			});
			// 2.60: the owner's own rows
			var rowsBox = $('[data-rows]');
			var renumberRows = function () {
				var rows = rowsBox.children('[data-row]');
				rows.each(function (i) {
					$(this).find('[data-row-n]').text(i + 1);
					$(this).find('[data-row-move="-1"]').prop('disabled', i === 0);
					$(this).find('[data-row-move="1"]').prop('disabled', i === rows.length - 1);
				});
				$('[data-add-row]').prop('hidden', rows.length >= +rowsBox.data('max'));
				$('[data-rows-empty]').prop('hidden', rows.length > 0);
			};
			renumberRows();
			$('.y4hm').on('click', '[data-add-row]', function () {
				var html = $('template[data-row-tpl]').html().split('__R__').join('n' + Date.now().toString(36));
				var row = $($.parseHTML($.trim(html)));
				rowsBox.append(row);
				row.find('[data-card]').each(function () { initCard(this); });
				renumber(row.find('[data-cards]'));
				renumberRows();
				row.find('[data-row-title]').trigger('focus');
			});
			$('.y4hm').on('change', '[data-row-style]', function () {
				var grid = $(this).closest('[data-row]').find('[data-cards]');
				grid.attr('data-shape', this.value);
				grid.children('[data-card]').attr('data-shape', this.value);
			});
			$('.y4hm').on('click', '[data-row-move]', function () {
				var row = $(this).closest('[data-row]');
				if (+$(this).data('row-move') < 0) { row.prev('[data-row]').before(row); } else { row.next('[data-row]').after(row); }
				renumberRows();
			});
			$('.y4hm').on('click', '[data-drop-row]', function () {
				if (!window.confirm(<?php echo wp_json_encode(__('Remove this row and its cards? (Nothing changes until you Save.)', 'y4apk')); ?>)) { return; }
				$(this).closest('[data-row]').remove();
				renumberRows();
			});
		});
		</script>
		<?php
	}
}

Y4APK_Home_Admin::init();
