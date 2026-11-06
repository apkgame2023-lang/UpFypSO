<?php
/**
 * /executors/ and /executors/<name>/ (2.44): the Roblox executors, and one of them.
 */
defined('ABSPATH') || exit;

$X = 'Y4APK_Executors';
$st = $X::statuses();
$plats = $X::platforms();
$dl = $X::downloads();
$single = is_singular($X::CPT);
$short = function ($n) { return class_exists('Y4APK_Roblox') ? Y4APK_Roblox::short_number($n) : number_format_i18n($n); };
$chips = function ($id, $d) use ($X, $plats) {
	$h = '<span class="y4x-tag' . ($X::price_text($d) === __('Free', 'y4apk') ? ' is-free' : '') . '">' . esc_html($X::price_text($d)) . '</span>';
	if ($d['sunc'] !== '') {
		$h .= '<span class="y4x-tag is-sunc">sUNC ' . (int) $d['sunc'] . '%</span>';
	}
	$h .= '<span class="y4x-tag">' . esc_html($d['key'] === 'key' ? __('Key system', 'y4apk') : __('Keyless', 'y4apk')) . '</span>';
	$h .= '<span class="y4x-plats">';
	foreach ($d['platforms'] as $p) {
		$h .= '<span title="' . esc_attr($plats[$p]) . '">' . $X::platform_icon($p) . '<i class="sr-only">' . esc_html($plats[$p]) . '</i></span>';
	}
	return $h . '</span>';
};
get_header();
?>
<div class="section y4x">
	<div class="wrp">
<?php if (!$single) :
	$all = $X::all();
	$working = 0;
	foreach ($all as $p) {
		$working += $X::data($p->ID)['status'] === 'working' ? 1 : 0;
	}
	?>
		<header class="y4x-hero">
			<div>
				<p class="y4x-kicker"><?php esc_html_e('Roblox', 'y4apk'); ?></p>
				<h1><?php esc_html_e('Executors', 'y4apk'); ?></h1>
				<p><?php esc_html_e('The apps that run scripts in Roblox. Pick one for your device: we mark the ones that work with the current Roblox.', 'y4apk'); ?></p>
			</div>
			<ul class="y4x-hero-stats">
				<li><b><?php echo esc_html(number_format_i18n(count($all))); ?></b><span><?php esc_html_e('executors', 'y4apk'); ?></span></li>
				<li class="is-working"><b><?php echo esc_html(number_format_i18n($working)); ?></b><span><?php esc_html_e('working now', 'y4apk'); ?></span></li>
			</ul>
		</header>
		<div class="y4x-bar" data-y4x-filters>
			<label class="y4x-find"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 0 1 6 12l4.7 4.8-1.4 1.4-4.8-4.7A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg><input type="search" placeholder="<?php esc_attr_e('Search executors', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Search executors', 'y4apk'); ?>" data-y4x-q></label>
			<div class="y4x-seg" role="group" aria-label="<?php esc_attr_e('System', 'y4apk'); ?>">
				<button type="button" class="is-on" data-y4x-p=""><?php esc_html_e('All', 'y4apk'); ?></button>
				<?php foreach ($plats as $k => $n) : ?><button type="button" data-y4x-p="<?php echo esc_attr($k); ?>"><?php echo $X::platform_icon($k); ?><?php echo esc_html($n); ?></button><?php endforeach; ?>
			</div>
			<div class="y4x-seg" role="group" aria-label="<?php esc_attr_e('Status', 'y4apk'); ?>">
				<button type="button" class="is-on" data-y4x-s=""><?php esc_html_e('Any status', 'y4apk'); ?></button>
				<button type="button" data-y4x-s="working"><i class="y4x-dot is-working"></i><?php echo esc_html($st['working'][0]); ?></button>
				<button type="button" data-y4x-s="outdated"><i class="y4x-dot is-outdated"></i><?php echo esc_html($st['outdated'][0]); ?></button>
			</div>
			<span class="y4x-count" data-y4x-count><?php echo esc_html(sprintf(_n('%s executor', '%s executors', count($all), 'y4apk'), number_format_i18n(count($all)))); ?></span>
		</div>
		<?php if (!$all) : ?>
		<div class="y4x-empty"><?php esc_html_e('No executors here yet. Come back soon.', 'y4apk'); ?></div>
		<?php else : ?>
		<div class="y4x-grid" data-y4x-grid>
			<?php foreach ($all as $p) :
				$d = $X::data($p->ID);
				$banner = $X::banner($p->ID);
				$s = $st[$d['status']] ?? $st['unknown'];
				$n = (int) ($dl[$p->ID] ?? 0);
				?>
			<article class="y4x-card is-<?php echo esc_attr($d['status']); ?>" data-name="<?php echo esc_attr(mb_strtolower($p->post_title . ' ' . $d['developer'])); ?>" data-p="<?php echo esc_attr(implode(' ', $d['platforms'])); ?>" data-s="<?php echo esc_attr($d['status']); ?>">
				<a class="y4x-art" href="<?php echo esc_url(get_permalink($p)); ?>" tabindex="-1" aria-hidden="true"<?php echo $banner ? ' style="background-image:url(' . esc_url($banner) . ')"' : ''; ?>><?php if (!$banner) : ?><span class="y4x-art-name"><?php echo esc_html($p->post_title); ?></span><?php endif; ?></a>
				<p class="y4x-ribbon"><i class="y4x-dot is-<?php echo esc_attr($d['status']); ?>"></i><b><?php echo esc_html($s[0]); ?></b><?php if ($s[1]) : ?><span>· <?php echo esc_html($s[1]); ?></span><?php endif; ?></p>
				<div class="y4x-body">
					<div class="y4x-who">
						<?php echo $X::mark($p->ID, 40); ?>
						<div><h2><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html($p->post_title); ?></a></h2>
							<small><?php echo esc_html(implode(' · ', array_filter([$d['developer'], $n ? sprintf(_n('%s download', '%s downloads', $n, 'y4apk'), $short($n)) : '']))); ?></small></div>
						<a class="y4x-get" href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html($d['status'] === 'outdated' ? __('View', 'y4apk') : __('Get', 'y4apk')); ?> →</a>
					</div>
					<div class="y4x-tags"><?php echo $chips($p->ID, $d); ?></div>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<p class="y4x-empty" data-y4x-none hidden><?php esc_html_e('No executor matches. Try another system or status.', 'y4apk'); ?></p>
		<?php endif; ?>
		<p class="y4x-note"><?php esc_html_e('Executors are made by other people, not by Y4APK. Roblox does not allow them and may ban accounts that use them: use them at your own risk, on an account you can lose.', 'y4apk'); ?></p>
		<script id="y4apk-pwa-executors" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false">
		(function () {
			var bar = document.querySelector('[data-y4x-filters]'), grid = document.querySelector('[data-y4x-grid]');
			if (!bar || !grid) { return; }
			var p = '', s = '', q = '', cards = [].slice.call(grid.children), count = bar.querySelector('[data-y4x-count]'), none = document.querySelector('[data-y4x-none]');
			var one = <?php echo wp_json_encode(__('%s executor', 'y4apk')); ?>, many = <?php echo wp_json_encode(__('%s executors', 'y4apk')); ?>;
			function run() {
				var n = 0;
				cards.forEach(function (c) { var ok = (!p || (' ' + c.getAttribute('data-p') + ' ').indexOf(' ' + p + ' ') > -1) && (!s || c.getAttribute('data-s') === s) && (!q || c.getAttribute('data-name').indexOf(q) > -1); c.hidden = !ok; n += ok ? 1 : 0; });
				count.textContent = (n === 1 ? one : many).replace('%s', n); none.hidden = n > 0;
			}
			bar.addEventListener('click', function (e) {
				var b = e.target.closest('button'); if (!b) { return; }
				var k = b.hasAttribute('data-y4x-p') ? 'data-y4x-p' : 'data-y4x-s';
				[].forEach.call(b.parentNode.children, function (x) { x.classList.toggle('is-on', x === b); });
				if (k === 'data-y4x-p') { p = b.getAttribute(k); } else { s = b.getAttribute(k); }
				run();
			});
			bar.querySelector('[data-y4x-q]').addEventListener('input', function () { q = this.value.trim().toLowerCase(); run(); });
		})();
		</script>
<?php else :
	the_post();
	$id = get_the_ID();
	$d = $X::data($id);
	$banner = $X::banner($id);
	$s = $st[$d['status']] ?? $st['unknown'];
	$n = (int) ($dl[$id] ?? 0);
	$scripts = $X::scripts_of($id);
	$upd = get_post_modified_time('U', true);
	?>
		<nav class="y4x-crumb"><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'y4apk'); ?></a> → <a href="<?php echo esc_url(get_post_type_archive_link($X::CPT)); ?>"><?php esc_html_e('Executors', 'y4apk'); ?></a> → <span><?php the_title(); ?></span></nav>
		<section class="y4x-top is-<?php echo esc_attr($d['status']); ?>">
			<div class="y4x-top-art"<?php echo $banner ? ' style="background-image:url(' . esc_url($banner) . ')"' : ''; ?>></div>
			<div class="y4x-top-body">
				<?php echo $X::mark($id, 88); ?>
				<div class="y4x-top-text">
					<p class="y4x-pill is-<?php echo esc_attr($d['status']); ?>"><i class="y4x-dot is-<?php echo esc_attr($d['status']); ?>"></i><?php echo esc_html($s[0]); ?><?php if ($s[1]) : ?> · <?php echo esc_html($s[1]); ?><?php endif; ?></p>
					<h1><?php the_title(); ?></h1>
					<p class="y4x-top-meta"><?php echo esc_html(implode(' · ', array_filter([$d['developer'] !== '' ? sprintf(__('by %s', 'y4apk'), $d['developer']) : '', $d['version'] !== '' ? 'v' . $d['version'] : '', sprintf(__('updated %s ago', 'y4apk'), human_time_diff($upd)), $n ? sprintf(_n('%s download', '%s downloads', $n, 'y4apk'), $short($n)) : '']))); ?></p>
					<div class="y4x-tags"><?php echo $chips($id, $d); ?></div>
				</div>
			</div>
		</section>

		<div class="y4x-cols">
			<div class="y4x-main">
				<section class="y4x-box">
					<h2><?php esc_html_e('Download', 'y4apk'); ?></h2>
					<?php if (!$d['files']) : ?>
						<p class="y4x-muted"><?php esc_html_e('No download yet.', 'y4apk'); ?></p>
					<?php else : ?>
					<div class="y4x-dls">
						<?php foreach ($d['files'] as $f) : ?>
						<?php $fu = $X::file_url($f); $away = empty($f['key']) && strpos($fu, home_url()) !== 0 && !(class_exists('Y4APK_R2') && strpos($fu, Y4APK_R2::settings()['dir_url']) === 0); ?>
						<a class="y4x-dl is-<?php echo esc_attr($f['platform']); ?>" href="<?php echo esc_url($fu); ?>" rel="nofollow noopener" data-y4x-dl="<?php echo (int) $id; ?>"<?php echo $away ? ' target="_blank"' : ' download'; ?>>
							<span class="y4x-dl-ico"><?php echo $X::platform_icon($f['platform']); ?></span>
							<span class="y4x-dl-text"><b><?php echo esc_html($f['label'] !== '' ? $f['label'] : sprintf(__('Download for %s', 'y4apk'), $plats[$f['platform']])); ?></b><small><?php echo esc_html(implode(' · ', array_filter([$plats[$f['platform']], $f['version'] !== '' ? 'v' . $f['version'] : '', $f['size']]))); ?></small></span>
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6 1.4 1.4-6 6-6-6 1.4-1.4 3.6 3.6V4Zm-6 14h14v2H5v-2Z"/></svg>
						</a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
					<ol class="y4x-how">
						<li><b>1</b><span><?php esc_html_e('Download it and install it on your device', 'y4apk'); ?></span></li>
						<li><b>2</b><span><?php esc_html_e('Open Roblox, then the executor', 'y4apk'); ?></span></li>
						<li><b>3</b><span><?php echo wp_kses(sprintf(__('Paste a script from <a href="%s">Y4APK</a> and press Execute', 'y4apk'), esc_url(class_exists('Y4APK_Roblox') && Y4APK_Roblox::hub_url() ? Y4APK_Roblox::hub_url() : home_url('/'))), ['a' => ['href' => []]]); ?></span></li>
					</ol>
				</section>
				<?php if (trim(get_the_content()) !== '') : ?>
				<section class="y4x-box text"><?php the_content(); ?></section>
				<?php endif; ?>
				<?php if ($scripts) : ?>
				<section class="y4x-box">
					<h2><?php echo esc_html(sprintf(__('Scripts tested with %s', 'y4apk'), get_the_title())); ?></h2>
					<div class="y4x-scripts">
						<?php foreach ($scripts as $sid) : $thumb = get_the_post_thumbnail_url($sid, 'medium'); ?>
						<a class="y4x-script" href="<?php echo esc_url(get_permalink($sid)); ?>"><span class="y4x-script-art"<?php echo $thumb ? ' style="background-image:url(' . esc_url($thumb) . ')"' : ''; ?>></span><b><?php echo esc_html(get_the_title($sid)); ?></b></a>
						<?php endforeach; ?>
					</div>
				</section>
				<?php endif; ?>
			</div>
			<aside class="y4x-side">
				<section class="y4x-box">
					<h2><?php esc_html_e('At a glance', 'y4apk'); ?></h2>
					<dl class="y4x-facts">
						<div><dt><?php esc_html_e('Status', 'y4apk'); ?></dt><dd><i class="y4x-dot is-<?php echo esc_attr($d['status']); ?>"></i><?php echo esc_html($s[0]); ?></dd></div>
						<div><dt><?php esc_html_e('Runs on', 'y4apk'); ?></dt><dd><?php echo esc_html(implode(', ', array_map(function ($p) use ($plats) { return $plats[$p]; }, $d['platforms'])) ?: '—'); ?></dd></div>
						<div><dt><?php esc_html_e('Price', 'y4apk'); ?></dt><dd><?php echo esc_html($X::price_text($d)); ?></dd></div>
						<div><dt><?php esc_html_e('Key', 'y4apk'); ?></dt><dd><?php echo esc_html($d['key'] === 'key' ? __('Key system', 'y4apk') : __('Keyless', 'y4apk')); ?></dd></div>
						<?php if ($d['sunc'] !== '') : ?><div class="is-wide"><dt>sUNC</dt><dd><span class="y4x-meter"><i style="width:<?php echo (int) $d['sunc']; ?>%"></i></span><b><?php echo (int) $d['sunc']; ?>%</b></dd></div><?php endif; ?>
					</dl>
					<p class="y4x-muted"><?php esc_html_e('sUNC: how much of the standard script functions it supports. Higher runs more scripts.', 'y4apk'); ?></p>
				</section>
				<a class="y4x-back" href="<?php echo esc_url(get_post_type_archive_link($X::CPT)); ?>">← <?php esc_html_e('All executors', 'y4apk'); ?></a>
			</aside>
		</div>
		<p class="y4x-note"><?php esc_html_e('Executors are made by other people, not by Y4APK. Roblox does not allow them and may ban accounts that use them: use them at your own risk, on an account you can lose.', 'y4apk'); ?></p>
		<script id="y4apk-pwa-executor" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false">
		document.addEventListener('click', function (e) { var a = e.target.closest('[data-y4x-dl]'); if (a && window.y4apkTrack) { window.y4apkTrack('executor_dl', a.getAttribute('data-y4x-dl')); } });
		</script>
<?php endif; ?>
	</div>
</div>
<?php
get_template_part('template/footer');
get_footer();
