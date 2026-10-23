<?php
/**
 * /roblox/: every Roblox game and the latest scripts (Y4APK_Roblox). The page's
 * own title and text are the top of it, so they are edited like any page.
 */
the_post();
$paged = max(1, (int) get_query_var('paged'));
$games = Y4APK_Roblox::games();
$scripts = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => Y4APK_Roblox::PER_PAGE, 'paged' => $paged,
	'orderby' => 'modified', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'tax_query' => Y4APK_Roblox::only_roblox()]);
$latest = $scripts->posts && $paged === 1 ? $scripts->posts[0] : null;
$intro = trim(get_the_content());

get_header();
?>
<div class="section list-content rbx">
	<div class="wrp">
		<div class="list-heading rbx-crumbs"><div class="heading-bg"><?php if (function_exists('breadcrumbsX')) breadcrumbsX(); ?></div></div>

		<section class="rbx-hero rbx-hero-hub">
			<div class="rbx-hero-in">
				<div class="rbx-hero-text">
					<div class="rbx-tags"><span class="rbx-tag"><?php esc_html_e('Roblox', 'y4apk'); ?></span><span class="rbx-tag"><?php esc_html_e('Free scripts', 'y4apk'); ?></span></div>
					<h1 class="rbx-hero-title"><?php the_title(); ?></h1>
					<?php if ($intro !== '') : ?><div class="rbx-hero-sub"><?php the_content(); ?></div><?php endif; ?>
					<ul class="rbx-stats">
						<li><?php echo Y4APK_Roblox::icon('play'); ?><b><?php echo esc_html(number_format_i18n(count($games))); ?></b> <?php echo esc_html(_n('Game', 'Games', count($games), 'y4apk')); ?></li>
						<li><?php echo Y4APK_Roblox::icon('code'); ?><b><?php echo esc_html(number_format_i18n((int) $scripts->found_posts)); ?></b> <?php echo esc_html(_n('Script', 'Scripts', (int) $scripts->found_posts, 'y4apk')); ?></li>
						<?php if ($latest) : ?><li><?php echo Y4APK_Roblox::icon('clock'); ?><?php echo esc_html(sprintf(__('Updated %s', 'y4apk'), Y4APK_Roblox::ago(get_post_modified_time('U', true, $latest)))); ?></li><?php endif; ?>
					</ul>
				</div>
				<?php if (count($games) >= 3) : ?>
				<div class="rbx-hero-art" aria-hidden="true">
					<?php foreach (array_slice($games, 0, 3) as $g) : ?><img src="<?php echo esc_url(Y4APK_Roblox::icon_url($g, 'medium'), ['http', 'https', 'data']); ?>" alt="" width="136" height="136"><?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
		</section>

		<?php if ($games && $paged === 1) : ?>
		<section class="section">
			<div class="rbx-head">
				<h2 class="section-title rbx-title"><?php esc_html_e('Games', 'y4apk'); ?></h2>
				<?php if (count($games) > 6) : ?>
				<label class="rbx-search rbx-find"><?php echo Y4APK_Roblox::icon('search'); ?><span class="sr-only"><?php esc_html_e('Find a game', 'y4apk'); ?></span>
					<input type="search" placeholder="<?php esc_attr_e('Find a game…', 'y4apk'); ?>" data-rbx-find></label>
				<?php endif; ?>
			</div>
			<div class="rbx-games" data-rbx-games>
				<?php foreach ($games as $g) {
					Y4APK_Roblox::tile($g);
				} ?>
			</div>
			<p class="rbx-none" hidden><?php esc_html_e('No game by that name yet.', 'y4apk'); ?></p>
			<?php if (count($games) > 18) : ?>
				<p class="rbx-all-wrap"><button type="button" class="rbx-btn rbx-btn-light" data-rbx-all><?php echo esc_html(sprintf(__('Show all %s games', 'y4apk'), number_format_i18n(count($games)))); ?></button></p>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<section class="section">
			<div class="rbx-head"><h2 class="section-title rbx-title"><?php esc_html_e('Latest scripts', 'y4apk'); ?></h2></div>
			<?php if ($scripts->have_posts()) : ?>
				<div class="rbx-grid">
					<?php foreach ($scripts->posts as $p) {
						Y4APK_Roblox::card($p->ID, true);
					} ?>
				</div>
				<?php y4apk_pagination($paged, (int) $scripts->max_num_pages); ?>
			<?php else : ?>
				<div class="block rbx-empty"><p><?php esc_html_e('No script here yet.', 'y4apk'); ?></p></div>
			<?php endif; ?>
		</section>
	</div>
</div>
<script>
(function () {
	var box = document.querySelector('[data-rbx-games]'), find = document.querySelector('[data-rbx-find]'),
		all = document.querySelector('[data-rbx-all]'), none = document.querySelector('.rbx-none');
	if (!box) { return; }
	if (all) {
		all.addEventListener('click', function () { box.classList.add('is-all'); all.parentNode.remove(); });
	}
	if (find) {
		find.addEventListener('input', function () {
			var q = find.value.trim().toLowerCase(), shown = 0;
			box.classList.toggle('is-filtering', q !== '');
			box.querySelectorAll('.rbx-game').forEach(function (g) {
				var hit = q === '' || g.getAttribute('data-name').indexOf(q) !== -1;
				g.hidden = !hit;
				shown += hit ? 1 : 0;
			});
			none.hidden = shown > 0;
		});
	}
})();
</script>
<?php get_template_part('template/footer'); get_footer(); ?>
