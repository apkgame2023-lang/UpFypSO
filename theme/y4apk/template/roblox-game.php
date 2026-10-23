<?php
/**
 * /roblox/<game>/: one Roblox game's scripts, 24 a page (Y4APK_Roblox).
 * The main query is the game's posts, with the page's search, Keyless and sort.
 */
global $wp_query;
$term = get_queried_object();
$f = Y4APK_Roblox::filters();
$paged = max(1, (int) get_query_var('paged'));
$stats = Y4APK_Roblox::stats($term);
$totals = Y4APK_Roblox::totals($term);
$icon = Y4APK_Roblox::icon_url($term, 'medium');
$banner = Y4APK_Roblox::banner_url($term);
$play = Y4APK_Roblox::play_url($term);
$hub = Y4APK_Roblox::hub_url();
$base = get_term_link($term);
$likes = $stats['up'] !== null && ($stats['up'] + $stats['down']) > 0 ? round(100 * $stats['up'] / ($stats['up'] + $stats['down'])) : null;
$keep = array_filter(['q' => $f['q'], 'sort' => $f['sort'] !== 'updated' ? $f['sort'] : '']);
$top = get_posts(['post_type' => 'post', 'numberposts' => 3, 'meta_key' => 'wpb_post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC',
	'tax_query' => [['taxonomy' => Y4APK_Roblox::TAX, 'field' => 'term_id', 'terms' => $term->term_id]]]);
$others = Y4APK_Roblox::games(6, $term->term_id);

get_header();
?>
<div class="section list-content rbx">
	<div class="wrp">
		<div class="list-heading rbx-crumbs"><div class="heading-bg"><?php if (function_exists('breadcrumbsX')) breadcrumbsX(); ?></div></div>

		<section class="rbx-hero<?php echo $banner ? ' has-banner' : ''; ?>">
			<img class="rbx-hero-bg" src="<?php echo esc_url($banner ?: $icon, ['http', 'https', 'data']); ?>" alt="" aria-hidden="true">
			<div class="rbx-hero-in">
				<img class="rbx-hero-icon" src="<?php echo esc_url($icon, ['http', 'https', 'data']); ?>" alt="<?php echo esc_attr($term->name); ?>" width="160" height="160" fetchpriority="high">
				<div class="rbx-hero-text">
					<div class="rbx-tags">
						<?php if ($hub) : ?><a class="rbx-tag" href="<?php echo esc_url($hub); ?>"><?php esc_html_e('All games', 'y4apk'); ?></a><?php endif; ?>
						<span class="rbx-tag"><?php esc_html_e('Roblox', 'y4apk'); ?></span>
						<?php if ($stats['creator'] !== '') : ?><span class="rbx-tag"><?php echo esc_html(sprintf(__('by %s', 'y4apk'), $stats['creator'])); ?></span><?php endif; ?>
					</div>
					<h1 class="rbx-hero-title"><?php echo esc_html($term->name); ?></h1>
					<p class="rbx-hero-sub"><?php printf(esc_html__('Free scripts for %s on Roblox.', 'y4apk'), '<strong>' . esc_html($term->name) . '</strong>'); ?></p>
					<ul class="rbx-stats">
						<li><?php echo Y4APK_Roblox::icon('code'); ?><b><?php echo esc_html(number_format_i18n($term->count)); ?></b> <?php echo esc_html(_n('Script', 'Scripts', $term->count, 'y4apk')); ?></li>
						<?php if ($totals['views']) : ?><li><?php echo Y4APK_Roblox::icon('eye'); ?><b><?php echo esc_html(Y4APK_Roblox::short_number($totals['views'])); ?></b> <?php esc_html_e('Views', 'y4apk'); ?></li><?php endif; ?>
						<?php if ($stats['playing'] !== null) : ?><li><?php echo Y4APK_Roblox::icon('users'); ?><b><?php echo esc_html(Y4APK_Roblox::short_number($stats['playing'])); ?></b> <?php esc_html_e('Playing', 'y4apk'); ?></li><?php endif; ?>
						<?php if ($likes !== null) : ?><li><?php echo Y4APK_Roblox::icon('thumb'); ?><b><?php echo esc_html($likes); ?>%</b> <?php esc_html_e('Likes', 'y4apk'); ?></li><?php endif; ?>
					</ul>
				</div>
				<div class="rbx-hero-actions">
					<?php if ($play) : ?><a class="rbx-btn rbx-btn-play" href="<?php echo esc_url($play); ?>" target="_blank" rel="nofollow noopener"><?php echo Y4APK_Roblox::icon('play'); ?><?php esc_html_e('Play on Roblox', 'y4apk'); ?></a><?php endif; ?>
					<button type="button" class="rbx-btn rbx-btn-ghost" data-rbx-share data-done="<?php esc_attr_e('Link copied', 'y4apk'); ?>"><?php echo Y4APK_Roblox::icon('share'); ?><span><?php esc_html_e('Share', 'y4apk'); ?></span></button>
				</div>
			</div>
		</section>

		<div class="block rbx-summary">
			<p><?php
				printf(esc_html__('Y4APK lists %1$s for Roblox', 'y4apk'), '<strong>' . esc_html(sprintf(_n('%1$s %2$s script', '%1$s %2$s scripts', $term->count, 'y4apk'), number_format_i18n($term->count), $term->name)) . '</strong>');
				if ($totals['updated']) {
					printf(esc_html__(', last updated on %s', 'y4apk'), '<strong>' . esc_html(mysql2date(get_option('date_format'), get_date_from_gmt($totals['updated']))) . '</strong>');
				}
				echo '.';
				if ($totals['keyless']) {
					echo ' ' . sprintf(esc_html(_n('%s of them is keyless.', '%s of them are keyless.', $totals['keyless'], 'y4apk')), '<strong>' . esc_html(number_format_i18n($totals['keyless'])) . '</strong>');
				}
				if (count($top) > 1) {
					$links = array_map(function ($p) { return '<a href="' . esc_url(get_permalink($p)) . '">' . esc_html(wp_strip_all_tags(get_post_meta($p->ID, 'wp_title_GP', true) ?: get_the_title($p))) . '</a>'; }, $top);
					$last = array_pop($links);
					echo ' ' . sprintf(esc_html__('Most viewed right now: %1$s and %2$s.', 'y4apk'), implode(', ', $links), $last);
				}
				echo ' ' . esc_html__('Open a script, copy its loadstring and run it in your executor.', 'y4apk');
			?></p>
			<?php if (trim($term->description) !== '') echo wpautop(wp_kses_post($term->description)); ?>
		</div>

		<form class="rbx-tools" method="get" action="<?php echo esc_url($base); ?>" role="search">
			<label class="rbx-search"><?php echo Y4APK_Roblox::icon('search'); ?><span class="sr-only"><?php esc_html_e('Search', 'y4apk'); ?></span>
				<input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" maxlength="80" placeholder="<?php echo esc_attr(sprintf(__('Search %s scripts…', 'y4apk'), $term->name)); ?>"></label>
			<?php if ($f['keyless']) : ?><input type="hidden" name="key" value="no"><?php endif; ?>
			<div class="rbx-filters">
				<a class="rbx-filter<?php echo $f['keyless'] ? '' : ' is-on'; ?>" href="<?php echo esc_url(add_query_arg($keep, $base)); ?>"><?php esc_html_e('All', 'y4apk'); ?> <span><?php echo esc_html(number_format_i18n($term->count)); ?></span></a>
				<a class="rbx-filter<?php echo $f['keyless'] ? ' is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg($keep + ['key' => 'no'], $base)); ?>"><?php echo Y4APK_Roblox::icon('key'); ?><?php esc_html_e('Keyless', 'y4apk'); ?> <span><?php echo esc_html(number_format_i18n($totals['keyless'])); ?></span></a>
				<label class="rbx-sort"><span class="sr-only"><?php esc_html_e('Sort', 'y4apk'); ?></span>
					<select name="sort" onchange="this.form.submit()">
						<option value="updated" <?php selected($f['sort'], 'updated'); ?>><?php esc_html_e('Recently updated', 'y4apk'); ?></option>
						<option value="new" <?php selected($f['sort'], 'new'); ?>><?php esc_html_e('Newest', 'y4apk'); ?></option>
						<option value="views" <?php selected($f['sort'], 'views'); ?>><?php esc_html_e('Most viewed', 'y4apk'); ?></option>
					</select></label>
			</div>
		</form>

		<?php if (have_posts()) : ?>
			<p class="rbx-count"><?php
				$from = ($paged - 1) * Y4APK_Roblox::PER_PAGE + 1;
				$to = min($paged * Y4APK_Roblox::PER_PAGE, (int) $wp_query->found_posts);
				echo esc_html(sprintf(__('Showing %1$s–%2$s of %3$s', 'y4apk'), number_format_i18n($from), number_format_i18n($to), sprintf(_n('%s script', '%s scripts', (int) $wp_query->found_posts, 'y4apk'), number_format_i18n((int) $wp_query->found_posts))))
					. ($f['q'] !== '' ? ' ' . esc_html(sprintf(__('for “%s”', 'y4apk'), $f['q'])) : '');
			?></p>
			<div class="rbx-grid">
				<?php while (have_posts()) : the_post();
					Y4APK_Roblox::card(get_the_ID(), false);
				endwhile; ?>
			</div>
			<?php y4apk_pagination($paged, (int) $wp_query->max_num_pages); ?>
		<?php else : ?>
			<div class="block rbx-empty">
				<p><?php echo esc_html($f['q'] !== '' ? sprintf(__('No %1$s script matches “%2$s”.', 'y4apk'), $term->name, $f['q']) : __('No script here yet.', 'y4apk')); ?></p>
				<?php if ($f['q'] !== '' || $f['keyless']) : ?><a class="rbx-btn rbx-btn-light" href="<?php echo esc_url($base); ?>"><?php echo esc_html(sprintf(__('See all %s scripts', 'y4apk'), $term->name)); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ($others) : ?>
		<section class="section rbx-more-games">
			<div class="rbx-head">
				<h2 class="section-title rbx-title"><?php esc_html_e('More Roblox games', 'y4apk'); ?></h2>
				<?php if ($hub) : ?><a class="rbx-more" href="<?php echo esc_url($hub); ?>"><?php esc_html_e('All games', 'y4apk'); ?><?php echo Y4APK_Roblox::icon('arrow'); ?></a><?php endif; ?>
			</div>
			<div class="rbx-games rbx-games-row">
				<?php foreach ($others as $t) {
					Y4APK_Roblox::tile($t);
				} ?>
			</div>
		</section>
		<?php endif; ?>
	</div>
</div>
<script>
document.addEventListener('click', function (e) {
	var b = e.target.closest ? e.target.closest('[data-rbx-share]') : null;
	if (!b) { return; }
	var data = { title: document.title, url: location.href.split('#')[0] };
	if (navigator.share) { navigator.share(data).catch(function () {}); return; }
	if (navigator.clipboard) {
		navigator.clipboard.writeText(data.url).then(function () {
			var s = b.querySelector('span'), t = s.textContent;
			s.textContent = b.getAttribute('data-done');
			setTimeout(function () { s.textContent = t; }, 2000);
		});
	}
});
</script>
<?php get_template_part('template/footer'); get_footer(); ?>
