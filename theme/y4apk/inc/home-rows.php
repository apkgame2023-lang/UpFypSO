<?php
/**
 * The owner's own rows of cards on the home page (2.60), set on Y4APK ▸ Home
 * page next to "Best of the best" and "Recommend": each has a heading (a "See
 * all" link if wanted), wide cards (like Best of the best) or tall ones (like
 * Recommend), up to twelve, and a place — under Best of the best, after the
 * first list of games, or at the end. More than four slide sideways, as those
 * two rows do.
 *
 * Kept in the theme setting y4apk_home_rows: [['id', 'title', 'link', 'show',
 * 'style' => wide|tall, 'where' => top|middle|bottom, 'cards' => [...]], …].
 */

defined('ABSPATH') || exit;

class Y4APK_Home_Rows {

	const MOD   = 'y4apk_home_rows';
	const MAX   = 10; // rows
	const CARDS = 12; // cards in a row

	private static $css = false;

	/** Where a row can go: key => label. */
	public static function places() {
		return [
			'top'    => __('Under “Best of the best”', 'y4apk'),
			'middle' => __('After the first list of games (by “Recommend”)', 'y4apk'),
			'bottom' => __('At the end, before the site’s text', 'y4apk'),
		];
	}

	public static function styles() {
		return [
			'wide' => __('Wide cards (like Best of the best)', 'y4apk'),
			'tall' => __('Tall cards (like Recommend)', 'y4apk'),
		];
	}

	/** The rows, cleaned. */
	public static function get() {
		$rows = get_theme_mod(self::MOD, []);
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $r) {
			if (!is_array($r)) {
				continue;
			}
			$out[] = [
				'id'    => preg_match('/^[a-z0-9]{1,20}$/', (string) ($r['id'] ?? '')) ? (string) $r['id'] : 'r' . count($out),
				'title' => (string) ($r['title'] ?? ''),
				'link'  => (string) ($r['link'] ?? ''),
				'show'  => !empty($r['show']),
				'style' => ($r['style'] ?? '') === 'tall' ? 'tall' : 'wide',
				'where' => isset(self::places()[$r['where'] ?? '']) ? $r['where'] : 'top',
				'cards' => array_values(array_filter((array) ($r['cards'] ?? []), 'is_array')),
			];
		}
		return array_slice($out, 0, self::MAX);
	}

	/** The rows for one place on the home page. */
	public static function render($where) {
		foreach (self::get() as $r) {
			if ($r['show'] && $r['where'] === $where) {
				self::row($r);
			}
		}
	}

	private static function row($r) {
		$cards = array_values(array_filter($r['cards'], function ($c) {
			$id = absint($c['post_id'] ?? 0);
			return $id ? get_post_status($id) === 'publish' : (trim((string) ($c['link'] ?? '')) !== '' && trim((string) ($c['title'] ?? '')) !== ''); // 2.70.2: or a link with a name
		}));
		if (!$cards) {
			return;
		}
		$many = count($cards) > 4;
		$tall = $r['style'] === 'tall';
		self::css();
		$link = $r['link'];
		if ($link !== '' && $link[0] === '/' && substr($link, 0, 2) !== '//') {
			$link = home_url($link);
		}
		?>
		<div class="section y4hrow y4hrow-<?php echo esc_attr($r['style']); ?>">
			<h3 class="section-title"><?php if ($link !== '') : ?><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($r['title']); ?>
				<i class="section-more"><svg class="section-more-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 36" width="128" height="36"><path d="M15.85,18a11.92,11.92,0,0,1,16.83-1,9.44,9.44,0,0,1,1,1,11.83,11.83,0,0,0,8.94,4,11.72,11.72,0,0,0,8.91-4A12,12,0,0,1,68.4,17a11.45,11.45,0,0,1,1,1"></path><g><path d="M109.12,22c-5.2-5.74-9.37-8-13-8a11.73,11.73,0,0,0-8.92,4,12,12,0,0,1-17.85,0"></path><polyline points="102 23 110 23 110 15"></polyline></g></svg></i></a>
			<?php else : echo esc_html($r['title']); endif; ?></h3>
			<div class="<?php echo $tall ? 'y4hrow-in' : 'best-app y4hrow-in'; ?>">
				<div class="y4row<?php echo $many ? ' is-many' : ''; ?>">
					<?php if ($many) { echo self::nav(); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="<?php echo $tall ? 'recommend-over' : 'best-app-over'; ?>">
						<div class="<?php echo $tall ? 'recommend-list' : 'best-app-list'; ?>">
							<?php foreach ($cards as $c) {
								$tall ? self::tall_card($c) : self::wide_card($c);
							} ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
		if ($many && function_exists('y4apk_row_script')) {
			y4apk_row_script();
		}
	}

	public static function nav() {
		return '<button type="button" class="y4row-nav is-prev" data-row-prev aria-label="' . esc_attr__('Previous', 'y4apk') . '"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z"/></svg></button>'
			. '<button type="button" class="y4row-nav is-next" data-row-next aria-label="' . esc_attr__('Next', 'y4apk') . '"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M8.6 16.6 10 18l6-6-6-6-1.4 1.4 4.6 4.6z"/></svg></button>';
	}

	/** A card as "Best of the best" shows it. */
	private static function wide_card($c) {
		list($name, $link) = y4apk_card($c);
		$bg = (string) ($c['bg_image'] ?? '');
		$color = sanitize_hex_color($c['bg_color'] ?? '') ?: '#29b1d8';
		?>
		<div class="best-app-item">
			<a href="<?php echo esc_url($link); ?>">
				<div class="best-app-img">
					<figure><img src="<?php echo esc_url(y4apk_card_icon($c, $name), ['http', 'https', 'data']); ?>" width="60" height="60" alt="<?php echo esc_attr($name); ?>" loading="lazy"></figure>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 76" width="90" height="76"><path fill="currentColor" d="M64,0H0V76A16,16,0,0,1,16,60.26H40a24,24,0,0,0,24-24V19.74A16,16,0,0,1,80,4H90V0Z"></path></svg>
				</div>
				<span class="best-app-title"><?php echo esc_html($name); ?></span>
				<span class="best-app-download"><?php esc_html_e('Download', 'y4apk'); ?></span>
				<div class="best-app-bg" style="background-color: <?php echo esc_attr($color); ?>;"><?php if ($bg !== '') : ?><img src="<?php echo esc_url($bg); ?>" width="190" height="144" alt="" decoding="async" loading="lazy"><?php endif; ?></div>
				<div class="best-app-shadow" style="box-shadow: 0 0 2rem 0 <?php echo esc_attr($color); ?>;"></div>
			</a>
		</div>
		<?php
	}

	/** A card as "Recommend" shows it. */
	private static function tall_card($c) {
		list($name, $link) = y4apk_card($c);
		$bg = (string) ($c['bg_image'] ?? '');
		$color = sanitize_hex_color($c['bg_color'] ?? '') ?: '#29b1d8';
		?>
		<div class="recommend-app">
			<a href="<?php echo esc_url($link); ?>">
				<div class="recommend-app-img">
					<figure><img src="<?php echo esc_url(y4apk_card_icon($c, $name), ['http', 'https', 'data']); ?>" width="60" height="60" alt="<?php echo esc_attr($name); ?>" loading="lazy"></figure>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 80" width="90" height="80"><path fill="currentColor" d="M20,0A20,20,0,0,0,0,20V80H4v-.26A16,16,0,0,1,19.83,64H44A20,20,0,0,0,64,44V19.74h0A16,16,0,0,1,80,4H90V0Z"></path></svg>
				</div>
				<div class="recommend-app-cont"><div class="recommend-app-cont-in">
					<span class="recommend-app-title"><?php echo esc_html($name); ?></span>
					<span class="recommend-app-download"><?php esc_html_e('Download', 'y4apk'); ?></span>
				</div></div>
				<div class="recommend-app-bg" style="background-color: <?php echo esc_attr($color); ?>;"><?php if ($bg !== '') : ?><img src="<?php echo esc_url($bg); ?>" width="208" height="248" alt="" decoding="async" loading="lazy"><?php endif; ?></div>
				<div class="recommend-app-shadow" style="box-shadow: 0 0 2rem 0 <?php echo esc_attr($color); ?>;"></div>
			</a>
		</div>
		<?php
	}

	/** The rows' own fit: a heading above, no room kept for the labels the two first rows have. */
	private static function css() {
		if (self::$css) {
			return;
		}
		self::$css = true;
		echo '<style id="y4apk-hrow-css">.y4hrow .best-app{margin:0;padding:.5rem 0 0}'
			. '@media(max-width:55.9375rem){.y4hrow .best-app{padding:.5rem 0 0;margin:0;overflow:visible}.y4hrow .best-app-over,.y4hrow .recommend-over{margin-left:-1.5rem;margin-right:-1.5rem}}'
			. '@media(min-width:56rem) and (max-width:61.9375rem){.y4hrow-tall .recommend-over{padding-left:0;padding-right:0}}'
			. '@media(min-width:56rem){.y4hrow-tall .y4row.is-many .recommend-over{margin:-1.5rem -.5rem -4rem;padding:1rem 0 3.5rem}.y4hrow-tall .y4row.is-many .recommend-list{margin:0}}</style>';
	}
}
