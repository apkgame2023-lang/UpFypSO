<?php
/**
 * The search page (2.54): results from everything on Y4APK, ranked by
 * Y4APK_Search (inc/search.php) — tabs for each kind, the best match on top,
 * each result with its picture, kind, who shared it and the words around what
 * was searched; the matching Roblox games and members beside them.
 */
defined('ABSPATH') || exit;

(function () {
$R = Y4APK_Search::results();
$L = Y4APK_Search::labels();
$P = Y4APK_Search::labels(true);
$q = $R['q'];
$shown_q = $R['fix'] !== '' ? $R['fix'] : $q;
$ic = function ($d, $size = 18) {
	return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true"><path d="' . esc_attr($d) . '"/></svg>';
};
$I = [
	'search' => 'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5Zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14Z',
	'down'   => 'M11 4h2v9.2l3.6-3.6L18 11l-6 6-6-6 1.4-1.4 3.6 3.6V4ZM5 19h14v2H5v-2Z',
	'arrow'  => 'M13.2 5.3 19.9 12l-6.7 6.7-1.4-1.4 4.3-4.3H4v-2h12.1l-4.3-4.3 1.4-1.4Z',
	'eye'    => 'M12 5C6.5 5 2.7 9.3 1.5 12c1.2 2.7 5 7 10.5 7s9.3-4.3 10.5-7C21.3 9.3 17.5 5 12 5Zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Zm0-2.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
	'user'   => 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z',
	'rbx'    => 'M5.2 2 2 18.8 18.8 22 22 5.2 5.2 2Zm8.5 13.2-4.9-1 1-4.9 4.9 1-1 4.9Z',
];
$icon = function ($it, $size) {
	if ($it['icon'] !== '') {
		return '<img src="' . esc_url($it['icon'], ['http', 'https', 'data']) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async">';
	}
	$t = $it['name'] !== '' ? $it['name'] : $it['title'];
	$h = abs(crc32($t)) % 360;
	return '<span class="y4q-letter" style="background:hsl(' . $h . ',55%,46%)">' . esc_html(function_exists('mb_substr') ? mb_strtoupper(mb_substr($t, 0, 1)) : strtoupper(substr($t, 0, 1))) . '</span>';
};
$meta = function ($it) use ($L) {
	$m = [];
	if (!empty($L[$it['kind']])) {
		$m[] = '<span class="y4q-kind y4q-kind-' . esc_attr($it['kind']) . '">' . esc_html($L[$it['kind']]) . '</span>';
	}
	if ($it['cat'] !== '') {
		$m[] = '<span>' . esc_html($it['cat']) . '</span>';
	}
	if ($it['ver'] !== '') {
		$m[] = '<span>v' . esc_html($it['ver']) . '</span>';
	}
	if ($it['by']) {
		$m[] = '<span class="y4q-by">' . $it['by']['avatar'] . esc_html($it['by']['name']) . '</span>';
	}
	return implode('<i aria-hidden="true">·</i>', $m);
};
$go_label = function ($kind) {
	return in_array($kind, ['game', 'app', 'version'], true) ? __('Download', 'y4apk') : ($kind === 'script' ? __('Get the script', 'y4apk') : __('Open', 'y4apk'));
};
get_header();
?>
<main class="y4q">
	<div class="wrp">
		<nav class="y4q-crumbs" aria-label="<?php esc_attr_e('Breadcrumb', 'y4apk'); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'y4apk'); ?></a><span aria-hidden="true">→</span><span><?php esc_html_e('Search', 'y4apk'); ?></span></nav>

		<form class="y4q-form" role="search" action="<?php echo esc_url(home_url('/')); ?>" method="get">
			<label for="y4q-q" class="sr-only"><?php esc_html_e('Search games and apps...', 'y4apk'); ?></label>
			<span class="y4q-form-ic"><?php echo $ic($I['search'], 22); ?></span>
			<input id="y4q-q" type="search" name="s" value="<?php echo esc_attr($q); ?>" maxlength="100" placeholder="<?php esc_attr_e('Search games, apps, scripts, members…', 'y4apk'); ?>" autocomplete="off" enterkeyhint="search">
			<?php if ($R['type'] !== '') : ?><input type="hidden" name="type" value="<?php echo esc_attr($R['type']); ?>"><?php endif; ?>
			<button type="submit"><?php esc_html_e('Search', 'y4apk'); ?></button>
		</form>

		<?php if ($R['total']) : ?>
			<nav class="y4q-tabs" aria-label="<?php esc_attr_e('Kinds of results', 'y4apk'); ?>">
				<a href="<?php echo esc_url(Y4APK_Search::url('', $q)); ?>"<?php echo $R['type'] === '' ? ' class="is-on" aria-current="page"' : ''; ?>><?php esc_html_e('All', 'y4apk'); ?><small><?php echo esc_html(number_format_i18n($R['total'])); ?></small></a>
				<?php foreach ($P as $k => $label) : if (empty($R['counts'][$k])) { continue; } ?>
					<a href="<?php echo esc_url(Y4APK_Search::url($k, $q)); ?>"<?php echo $R['type'] === $k ? ' class="is-on" aria-current="page"' : ''; ?>><?php echo esc_html($label); ?><small><?php echo esc_html(number_format_i18n($R['counts'][$k])); ?></small></a>
				<?php endforeach; ?>
			</nav>
			<p class="y4q-stats"><?php echo esc_html(sprintf(_n('%1$s result (%2$s seconds)', '%1$s results (%2$s seconds)', $R['shown'], 'y4apk'), number_format_i18n($R['shown']), number_format_i18n($R['time'], 2))); ?></p>
		<?php endif; ?>

		<?php if ($R['fix'] !== '') : ?>
			<p class="y4q-fix"><?php echo wp_kses(sprintf(__('Showing results for %s', 'y4apk'), '<a href="' . esc_url(Y4APK_Search::url('', $R['fix'])) . '"><b>' . esc_html($R['fix']) . '</b></a>'), ['a' => ['href' => []], 'b' => []]); ?>
				<small><?php echo wp_kses(sprintf(__('No results for %s', 'y4apk'), '<b>' . esc_html($q) . '</b>'), ['b' => []]); ?></small></p>
		<?php endif; ?>

		<div class="y4q-body<?php echo $R['games'] || count($R['people']) > ($R['channel'] ? 1 : 0) ? ' has-side' : ''; ?>">
			<div class="y4q-main">
				<?php if ($R['channel']) : $c = $R['channel']; ?>
					<article class="y4q-channel">
						<a class="y4q-channel-av" href="<?php echo esc_url($c['url']); ?>" tabindex="-1" aria-hidden="true"><?php echo $c['big']; // escaped by Y4APK_Social::avatar ?></a>
						<div class="y4q-top-t">
							<span class="y4q-eyebrow"><?php esc_html_e('Member', 'y4apk'); ?></span>
							<h2><a href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['name']); ?></a></h2>
							<p class="y4q-meta"><span>@<?php echo esc_html($c['login']); ?></span><i aria-hidden="true">·</i><span><?php echo esc_html(sprintf(_n('%s follower', '%s followers', $c['followers'], 'y4apk'), Y4APK_Search::short($c['followers']))); ?></span><i aria-hidden="true">·</i><span><?php echo esc_html(sprintf(_n('%s post', '%s posts', $c['posts'], 'y4apk'), number_format_i18n($c['posts']))); ?></span></p>
							<?php if ($c['bio'] !== '') : ?><p class="y4q-snip"><?php echo esc_html($c['bio']); ?></p><?php endif; ?>
						</div>
						<a class="y4q-top-go" href="<?php echo esc_url($c['url']); ?>"><?php echo $ic($I['user'], 18); ?><span><?php esc_html_e('View profile', 'y4apk'); ?></span></a>
					</article>
				<?php endif; ?>

				<?php if ($R['members']) : ?>
					<ul class="y4q-members">
						<?php foreach ($R['members'] as $u) : ?>
							<li><a href="<?php echo esc_url($u['url']); ?>">
								<span class="y4q-av"><?php echo $u['avatar']; // escaped by Y4APK_Social::avatar ?></span>
								<span><b><?php echo Y4APK_Search::mark($u['name'], [ltrim($q, '@')]); ?></b><small>@<?php echo esc_html($u['login']); ?></small>
									<small><?php echo esc_html(sprintf(_n('%s follower', '%s followers', $u['followers'], 'y4apk'), Y4APK_Search::short($u['followers']))); ?> · <?php echo esc_html(sprintf(_n('%s post', '%s posts', $u['posts'], 'y4apk'), number_format_i18n($u['posts']))); ?></small></span>
							</a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ($R['top']) : $t = $R['top']; ?>
					<article class="y4q-top">
						<a class="y4q-top-ic" href="<?php echo esc_url($t['url']); ?>" tabindex="-1" aria-hidden="true"><?php echo $icon($t, 88); ?></a>
						<div class="y4q-top-t">
							<span class="y4q-eyebrow"><?php esc_html_e('Best match', 'y4apk'); ?></span>
							<h2><a href="<?php echo esc_url($t['url']); ?>"><?php echo Y4APK_Search::mark($t['name'] !== '' && $t['kind'] !== 'version' ? $t['name'] : $t['title'], $R['words']); ?></a></h2>
							<p class="y4q-meta"><?php echo $meta($t); ?></p>
							<?php if ($t['mods'] !== '') : ?><p class="y4q-mods"><b><?php esc_html_e('MOD', 'y4apk'); ?></b><span><?php echo Y4APK_Search::mark($t['mods'], $R['words']); ?></span></p><?php endif; ?>
							<?php if ($t['snippet'] !== '') : ?><p class="y4q-snip"><?php echo Y4APK_Search::mark($t['snippet'], $R['words']); ?></p><?php endif; ?>
						</div>
						<a class="y4q-top-go" href="<?php echo esc_url($t['url'] . (in_array($t['kind'], ['game', 'app', 'version', 'script'], true) ? '#download-now' : '')); ?>"><?php echo $ic(in_array($t['kind'], ['game', 'app', 'version'], true) ? $I['down'] : $I['arrow'], 18); ?><span><?php echo esc_html($go_label($t['kind'])); ?></span></a>
					</article>
				<?php endif; ?>

				<?php if ($R['items']) : ?>
					<ol class="y4q-list">
						<?php foreach ($R['items'] as $it) : ?>
							<li class="y4q-item">
								<a class="y4q-item-ic" href="<?php echo esc_url($it['url']); ?>" tabindex="-1" aria-hidden="true"><?php echo $icon($it, 64); ?></a>
								<div class="y4q-item-t">
									<h3><a href="<?php echo esc_url($it['url']); ?>"><?php echo Y4APK_Search::mark($it['title'], $R['words']); ?></a></h3>
									<p class="y4q-meta"><?php echo $meta($it); ?><?php if ($it['views'] > 0) : ?><i aria-hidden="true">·</i><span class="y4q-views"><?php echo $ic($I['eye'], 13) . esc_html(Y4APK_Search::short($it['views'])); ?></span><?php endif; ?></p>
									<?php if ($it['mods'] !== '') : ?><p class="y4q-mods"><b><?php esc_html_e('MOD', 'y4apk'); ?></b><span><?php echo Y4APK_Search::mark($it['mods'], $R['words']); ?></span></p><?php endif; ?>
									<?php if ($it['snippet'] !== '') : ?><p class="y4q-snip"><?php echo Y4APK_Search::mark($it['snippet'], $R['words']); ?></p><?php endif; ?>
								</div>
							</li>
							<?php if (!empty($it['more'])) : ?>
								<li class="y4q-more"><a href="<?php echo esc_url(Y4APK_Search::url('version', $q)); ?>"><?php echo esc_html(sprintf(_n('%1$s more members’ version of %2$s', '%1$s more members’ versions of %2$s', $it['more']['n'], 'y4apk'), number_format_i18n($it['more']['n']), $it['more']['of'])); ?> →</a></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<?php if (!$R['total']) : ?>
					<div class="y4q-none">
						<span class="y4q-none-ic"><?php echo $ic($I['search'], 34); ?></span>
						<h2><?php echo $q === '' ? esc_html__('What are you looking for?', 'y4apk') : wp_kses(sprintf(__('Nothing found for %s', 'y4apk'), '<b>' . esc_html($q) . '</b>'), ['b' => []]); ?></h2>
						<ul>
							<li><?php esc_html_e('Check the spelling, or use fewer words.', 'y4apk'); ?></li>
							<li><?php esc_html_e('Search the game’s or app’s own name, such as “Minecraft” or “Blox Fruits”.', 'y4apk'); ?></li>
							<li><?php esc_html_e('Search a member by their @name.', 'y4apk'); ?></li>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ($R['pages'] > 1) : ?>
					<nav class="y4q-pages" aria-label="<?php esc_attr_e('Pages', 'y4apk'); ?>">
						<?php if ($R['paged'] > 1) : ?><a class="y4q-pg-step" href="<?php echo esc_url(Y4APK_Search::url($R['type'], $q, $R['paged'] - 1)); ?>" rel="prev">← <?php esc_html_e('Previous', 'y4apk'); ?></a><?php endif; ?>
						<?php for ($n = max(1, $R['paged'] - 3); $n <= min($R['pages'], $R['paged'] + 3); $n++) : ?>
							<?php if ($n === $R['paged']) : ?><span class="is-on" aria-current="page"><?php echo (int) $n; ?></span><?php else : ?><a href="<?php echo esc_url(Y4APK_Search::url($R['type'], $q, $n)); ?>"><?php echo (int) $n; ?></a><?php endif; ?>
						<?php endfor; ?>
						<?php if ($R['paged'] < $R['pages']) : ?><a class="y4q-pg-step" href="<?php echo esc_url(Y4APK_Search::url($R['type'], $q, $R['paged'] + 1)); ?>" rel="next"><?php esc_html_e('Next', 'y4apk'); ?> →</a><?php endif; ?>
					</nav>
				<?php endif; ?>
			</div>

			<?php
			$others = array_filter($R['people'], function ($u) use ($R) { return !$R['channel'] || $u['login'] !== $R['channel']['login']; });
			if ($R['games'] || $others) : ?>
				<aside class="y4q-side">
					<?php if ($R['games']) : ?>
						<section class="y4q-panel">
							<h2><?php echo $ic($I['rbx'], 16); ?><?php esc_html_e('Roblox games', 'y4apk'); ?></h2>
							<?php foreach ($R['games'] as $g) : ?>
								<a class="y4q-row" href="<?php echo esc_url($g['url']); ?>">
									<?php if ($g['icon'] !== '') : ?><img src="<?php echo esc_url($g['icon'], ['http', 'https', 'data']); ?>" alt="" width="40" height="40" loading="lazy"><?php endif; ?>
									<span><b><?php echo Y4APK_Search::mark($g['name'], $R['words']); ?></b><small><?php echo esc_html(sprintf(_n('%s script', '%s scripts', $g['count'], 'y4apk'), number_format_i18n($g['count']))); ?></small></span>
								</a>
							<?php endforeach; ?>
						</section>
					<?php endif; ?>
					<?php if ($others) : ?>
						<section class="y4q-panel">
							<h2><?php echo $ic($I['user'], 16); ?><?php esc_html_e('Members', 'y4apk'); ?></h2>
							<?php foreach ($R['people'] as $u) : if ($R['channel'] && $u['login'] === $R['channel']['login']) { continue; } ?>
								<a class="y4q-row" href="<?php echo esc_url($u['url']); ?>">
									<span class="y4q-av"><?php echo $u['avatar']; // escaped by Y4APK_Social::avatar ?></span>
									<span><b><?php echo Y4APK_Search::mark($u['name'], [ltrim($q, '@')]); ?></b><small>@<?php echo esc_html($u['login']); ?><?php if ($u['posts']) : ?> · <?php echo esc_html(sprintf(_n('%s post', '%s posts', $u['posts'], 'y4apk'), number_format_i18n($u['posts']))); ?><?php endif; ?></small></span>
								</a>
							<?php endforeach; ?>
						</section>
					<?php endif; ?>
				</aside>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php get_template_part('template/footer'); get_footer();
})();
