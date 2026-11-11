<?php
/**
 * Y4APK ▸ Broken links (2.66). Included by Y4APK_Links::render().
 */
defined('ABSPATH') || exit;

global $wpdb;
$L = 'Y4APK_Links';
$c = $L::counts();
$s = $L::settings();
$tabs = [
	'bad'     => [__('Broken', 'y4apk'), $c['bad']],
	'unsure'  => [__('Not sure yet', 'y4apk'), $c['unsure']],
	'ignored' => [__('Ignored', 'y4apk'), $c['ignored']],
	'settings' => [__('Settings', 'y4apk'), null],
];
$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'bad'; // phpcs:ignore WordPress.Security.NonceVerification
$note = isset($_GET['note']) ? sanitize_key($_GET['note']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$fixed = (int) get_transient('y4apk_links_fixed');
$notes = [
	'fixed'     => [__('It works now: it left the list.', 'y4apk'), 'success'],
	'still'     => [__('Still not working.', 'y4apk'), 'warning'],
	'gone'      => [__('That link is no longer in the post.', 'y4apk'), 'info'],
	'ignore'    => [__('Ignored: it stays out of the list until its address changes.', 'y4apk'), 'success'],
	'unignore'  => [__('Back in the list.', 'y4apk'), 'success'],
	'rechecked' => [sprintf(_n('Looked again: %s works now.', 'Looked again: %s work now.', $fixed, 'y4apk'), number_format_i18n($fixed)), 'success'],
	'pass'      => [__('Started: every link is looked at again over the next hours, in the background.', 'y4apk'), 'success'],
	'saved'     => [__('Saved.', 'y4apk'), 'success'],
];
$form = function ($do, $id = 0) use ($tab) {
	echo '<input type="hidden" name="action" value="y4apk_links"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
	if ($id) {
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
	}
	wp_nonce_field('y4apk_links_admin');
};
$ago = function ($ts) {
	return $ts ? sprintf(__('%s ago', 'y4apk'), human_time_diff((int) $ts)) : '—';
};
$checked_n = max(0, $c['all'] - $c['new']);
?>
<div class="wrap y4ui y4lnk">
	<?php Y4APK_Admin_UI::head([
		'icon'    => 'link',
		'tint'    => 'amber',
		'title'   => esc_html__('Broken download links', 'y4apk'),
		'sub'     => esc_html__('Every download on the site is looked at in the background, a few dozen every 10 minutes: the download folder, its page, and the file on R2 (or the other site it sends to). Broken ones show here so you can fix them before visitors find them. A fixed link leaves the list by itself.', 'y4apk'),
		'actions' => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . (function () use ($form) { ob_start(); $form('recheck_bad'); return ob_get_clean(); })() . '<button class="button button-large"' . ($c['bad'] + $c['unsure'] ? '' : ' disabled') . '>' . esc_html__('Look at the broken ones again', 'y4apk') . '</button></form>',
	]); ?>
	<?php if ($note && isset($notes[$note])) : ?>
		<div class="notice notice-<?php echo esc_attr($notes[$note][1]); ?> is-dismissible"><p><?php echo esc_html($notes[$note][0]); ?></p></div>
	<?php endif; ?>

	<div class="y4ui-kpis">
		<a class="y4ui-kpi<?php echo $c['bad'] ? ' is-red' : ' is-green'; ?>" href="<?php echo esc_url($L::admin_url(['tab' => 'bad'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Broken', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($c['bad'])); ?></span><span class="y4ui-kpi-s"><?php echo esc_html($c['bad'] ? __('downloads that do not work', 'y4apk') : __('all good so far', 'y4apk')); ?></span></a>
		<a class="y4ui-kpi" href="<?php echo esc_url($L::admin_url(['tab' => 'unsure'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Not sure yet', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($c['unsure'])); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('no clear answer; broken after %d tries', 'y4apk'), $L::TRIES)); ?></span></a>
		<div class="y4ui-kpi"><span class="y4ui-kpi-l"><?php esc_html_e('Links looked at', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($checked_n)); ?></span><span class="y4ui-kpi-s"><?php echo esc_html($c['new'] ? sprintf(__('%s still to look at', 'y4apk'), number_format_i18n($c['new'])) : sprintf(__('of %s downloads', 'y4apk'), number_format_i18n($c['all']))); ?></span></div>
		<div class="y4ui-kpi"><span class="y4ui-kpi-l"><?php esc_html_e('Last run', 'y4apk'); ?></span><span class="y4ui-kpi-n" style="font-size:20px"><?php echo esc_html($s['on'] ? $ago($s['last_run']) : __('Off', 'y4apk')); ?></span><span class="y4ui-kpi-s"><?php echo esc_html($s['pass_done'] ? sprintf(__('all posts read %s', 'y4apk'), $ago($s['pass_done'])) : __('reading the posts for the first time', 'y4apk')); ?></span></div>
	</div>

	<div class="y4ui-bar-row">
		<nav class="y4ui-tabs">
			<?php foreach ($tabs as $k => $tb) : ?>
				<a href="<?php echo esc_url($L::admin_url(['tab' => $k])); ?>" class="<?php echo $tab === $k ? 'is-on' : ''; ?>"><?php echo esc_html($tb[0]); ?><?php if ($tb[1]) : ?> <span class="y4ui-chip<?php echo $k === 'bad' ? ' is-red' : ''; ?>"><?php echo esc_html(number_format_i18n($tb[1])); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
	</div>

	<?php if ($tab === 'settings') : ?>
		<div class="y4ui-card">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Settings', 'y4apk'); ?></h2></div></div>
			<form class="y4ui-card-b" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php $form('settings'); ?>
				<label class="y4ui-opt"><input type="checkbox" name="on" value="1" <?php checked((int) $s['on']); ?>> <span><b><?php esc_html_e('Look at the download links in the background', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php echo esc_html(sprintf(__('Up to %1$d links every 10 minutes. Working links are looked at again every %2$d days, broken ones every %3$d hours.', 'y4apk'), $L::BATCH, $L::OK_AGAIN / DAY_IN_SECONDS, $L::BAD_AGAIN / HOUR_IN_SECONDS)); ?></span></span></label>
				<div class="y4ui-savebar"><span class="y4ui-hint"><?php esc_html_e('Only the posts’ own links are visited; nothing about visitors is sent.', 'y4apk'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
			</form>
		</div>
		<div class="y4ui-card">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Look at everything again', 'y4apk'); ?></h2><p><?php esc_html_e('Reads every post’s downloads again and looks at each link once more, in the background over the next hours. Use it after moving many files.', 'y4apk'); ?></p></div></div>
			<form class="y4ui-card-b" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('pass'); ?><button class="button button-large"><?php esc_html_e('Start a full check', 'y4apk'); ?></button></form>
		</div>
	<?php else :
		$t = $L::t();
		$paged = max(1, absint($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
		$per = 50;
		$where = $tab === 'ignored' ? 'ignored = 1' : $wpdb->prepare('ignored = 0 AND state = %s', $tab);
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where"); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY since ASC, id ASC LIMIT %d OFFSET %d", $per, ($paged - 1) * $per)); // phpcs:ignore WordPress.DB.PreparedSQL
		?>
		<div class="y4ui-card">
			<?php if (!$rows) : ?>
				<div class="y4ui-empty"><?php
					echo esc_html($tab === 'bad' ? ($c['all'] ? __('No broken downloads found. Nice.', 'y4apk') : __('Nothing looked at yet: the first links are checked within 10 minutes, then a few dozen every 10 minutes.', 'y4apk'))
						: ($tab === 'unsure' ? __('Nothing here.', 'y4apk') : __('No ignored links.', 'y4apk')));
				?></div>
			<?php else : ?>
			<div class="y4ui-scroll"><table class="y4ui-table y4lnk-table">
				<thead><tr><th><?php esc_html_e('Post', 'y4apk'); ?></th><th><?php esc_html_e('Download', 'y4apk'); ?></th><th><?php esc_html_e('What is wrong', 'y4apk'); ?></th><th><?php esc_html_e('Since', 'y4apk'); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ($rows as $r) :
					$p = get_post((int) $r->post_id);
					$host = (string) wp_parse_url($r->url, PHP_URL_HOST);
					$path = (string) wp_parse_url($r->url, PHP_URL_PATH);
					$short = $r->url === '' ? '—' : $host . (strlen($path) > 46 ? '/…' . substr($path, -40) : $path);
					$fix = class_exists('Y4APK_App_Updates') ? add_query_arg(['page' => Y4APK_App_Updates::SLUG, 'view' => 'file', 'post' => (int) $r->post_id], admin_url('admin.php')) : '';
					?>
					<tr>
						<td class="y4lnk-post"><?php if ($p) : ?><a href="<?php echo esc_url(get_permalink($p)); ?>" target="_blank"><b><?php echo esc_html(wp_trim_words(get_the_title($p), 10)); ?></b></a><?php endif; ?>
							<div class="y4lnk-acts"><?php if ($p && current_user_can('edit_post', $p->ID)) : ?><a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php esc_html_e('Edit post', 'y4apk'); ?></a><?php endif; ?><?php if ($fix && $p) : ?> · <a href="<?php echo esc_url($fix); ?>"><?php esc_html_e('Upload a new file', 'y4apk'); ?></a><?php endif; ?></div></td>
						<td><span class="y4ui-chip is-blue"><?php echo esc_html(strtoupper($r->name ?: '?')); ?></span><?php if ($r->version !== '') : ?> <span class="y4ui-kpi-s"><?php echo esc_html($r->version); ?></span><?php endif; ?>
							<br><?php if ($r->url !== '') : ?><a class="y4lnk-url" href="<?php echo esc_url($r->url); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr($r->url); ?>"><?php echo esc_html($short); ?></a><?php else : ?><span class="y4ui-kpi-s">—</span><?php endif; ?></td>
						<td><span class="y4ui-chip <?php echo $r->state === 'bad' ? 'is-red' : 'is-amber'; ?>"><?php echo esc_html($r->why ?: __('Not looked at yet', 'y4apk')); ?></span></td>
						<td><span class="y4ui-kpi-s"><?php echo esc_html($r->since ? wp_date(get_option('date_format'), (int) $r->since) : '—'); ?><br><?php echo esc_html(sprintf(__('looked %s', 'y4apk'), $ago($r->checked))); ?></span></td>
						<td class="y4lnk-btns">
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('recheck', $r->id); ?><button class="button"><?php esc_html_e('Look again', 'y4apk'); ?></button></form>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form((int) $r->ignored ? 'unignore' : 'ignore', $r->id); ?><button class="button button-link"><?php echo esc_html((int) $r->ignored ? __('Stop ignoring', 'y4apk') : __('Ignore', 'y4apk')); ?></button></form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php if ($total > $per) : ?>
				<div class="y4lnk-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => (int) ceil($total / $per)]); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php if ($tab === 'unsure') : ?>
			<p class="y4ui-note"><?php echo esc_html(sprintf(__('These did not answer clearly (a time-out, or a site that blocks robots). Each is looked at again every %1$d hours; after %2$d tries in a row it counts as broken.', 'y4apk'), $L::BAD_AGAIN / HOUR_IN_SECONDS, $L::TRIES)); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<style>
.y4lnk-table td{vertical-align:top}
.y4lnk-post b{font-size:14px}
.y4lnk-acts{margin-top:4px;font-size:12px;color:#646970}
.y4lnk-url{display:inline-block;margin-top:4px;max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#646970;vertical-align:top}
.y4lnk-btns{white-space:nowrap;text-align:right}
.y4lnk-btns form{display:inline-block;margin:0 0 0 6px}
.y4lnk-table td:nth-child(3) .y4ui-chip{display:inline-block;max-width:16rem;padding:4px 9px;border-radius:8px;white-space:normal;line-height:1.35}
.y4lnk-pages{padding:12px 16px}
.y4lnk .y4ui-head-acts form{margin:0}
</style>
