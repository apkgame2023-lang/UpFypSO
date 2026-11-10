<?php
/**
 * /account/unlock/?item=ID (2.69): unlock a paid script or file with coins, ask
 * for a refund, or (its creator) set its price. Included by
 * template/account.php ($c, $M, $messages). Y4APK_Paid::route() filled
 * $c['unlock'].
 */
defined('ABSPATH') || exit;

$W = 'Y4APK_Wallet';
$u = $c['unlock'];
$post = $u['post'];
$title = wp_strip_all_tags(get_the_title($post));
$by = get_userdata((int) $post->post_author);
$cname = $W::coin_name();
$set = $W::settings();
$share = (float) $set['sale_share'];
$off = (float) $set['vip_off'];
$link = $post->post_status === 'publish' ? get_permalink($post) : '';
$art = Y4APK_Paid::kind($post->ID) === 'file' && class_exists('Y4APK_Share') ? Y4APK_Share::icon_url($post->ID) : (class_exists('Y4APK_Roblox') ? Y4APK_Roblox::thumb($post->ID) : '');
$terms = class_exists('Y4APK_Help') ? Y4APK_Help::url('terms') . '#wallet' : '';
$lock = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M17 9h-1V7a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Zm-5 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm2-9h-4V7a2 2 0 1 1 4 0v2Z"/></svg>';
?>
<div class="wal unl">
	<section class="acc-card unl-head">
		<?php if ($art !== '') : ?><img class="unl-art<?php echo $u['kind'] === 'file' ? ' is-icon' : ''; ?>" src="<?php echo esc_url($art, ['http', 'https', 'data']); ?>" alt="" width="<?php echo $u['kind'] === 'file' ? 72 : 128; ?>" height="72"><?php endif; ?>
		<div>
			<small><?php echo esc_html($u['kind'] === 'file' ? __('File', 'y4apk') : __('Script', 'y4apk')); ?><?php if ($by) : ?> · <?php echo esc_html(sprintf(__('by @%s', 'y4apk'), $by->user_login)); ?><?php endif; ?></small>
			<h1><?php echo esc_html($title); ?></h1>
			<?php if ($u['price']) : ?><p class="unl-price"><?php echo $W::coin_icon(20); // phpcs:ignore WordPress.Security.EscapeOutput ?><b><?php echo esc_html(number_format_i18n($u['price'])); ?></b><?php if ($off > 0) : ?><span><?php echo Y4APK_VIP::crown(14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(sprintf(__('VIP %s', 'y4apk'), number_format_i18n(Y4APK_Paid::vip_price($u['price'])))); ?></span><?php endif; ?><?php if ($u['sales']) : ?><em><?php echo esc_html(sprintf(_n('%s sale', '%s sales', $u['sales'], 'y4apk'), number_format_i18n($u['sales']))); ?></em><?php endif; ?></p><?php endif; ?>
		</div>
	</section>

	<?php if ($c['ok'] === 'priced') : ?>
		<div class="acc-note acc-note-ok" role="status"><?php esc_html_e('Saved: it is for sale. Members see the price where the code or the download was.', 'y4apk'); ?></div>
	<?php elseif ($c['ok'] === 'free') : ?>
		<div class="acc-note acc-note-ok" role="status"><?php esc_html_e('Saved: it is free for everyone again. Those who unlocked it keep it.', 'y4apk'); ?></div>
	<?php elseif ($c['ok'] === 'refund') : ?>
		<div class="acc-note acc-note-ok" role="status"><?php esc_html_e('Refund asked. The site’s team looks at it, usually within a few days, and emails you.', 'y4apk'); ?></div>
	<?php endif; ?>
	<?php $messages(); ?>

	<?php if ($u['own']) : // the creator: the price ?>
	<section class="acc-card">
		<div class="wal-head">
			<span class="wal-head-i is-gold"><?php echo $W::coin_icon(26); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div><h2><?php esc_html_e('Sell it for coins', 'y4apk'); ?></h2><p><?php echo esc_html(sprintf(__('Members unlock it once with %1$s. You get %2$s%% of what they pay in your earnings (it can be withdrawn after the hold, like gifts); the site keeps %3$s%%.', 'y4apk'), $cname, number_format_i18n(100 - $share), number_format_i18n($share))); ?></p></div>
		</div>
		<?php if ($u['why'] !== '') : ?>
			<p class="acc-note"><?php echo esc_html($u['why']); ?></p>
			<?php if ($u['stored']) : ?><p class="wal-small"><?php esc_html_e('It is free for everyone until then.', 'y4apk'); ?></p><?php endif; ?>
		<?php else : $pv = $c['old']['price'] ?? ($u['stored'] ?: 100); ?>
		<form method="post" action="<?php echo esc_url(Y4APK_Paid::url($post->ID)); ?>" class="wal-form" data-unl-price data-share="<?php echo esc_attr($share); ?>" data-off="<?php echo esc_attr($off); ?>" data-per="<?php echo esc_attr($W::coins_per()); ?>">
			<input type="hidden" name="y4_do" value="price">
			<?php echo $M::nonce('unlock'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<label class="unl-switch"><input type="checkbox" name="sell" value="1" <?php checked((bool) $u['stored'] || !empty($c['old']['price'])); ?> data-unl-sell><span><b><?php esc_html_e('For sale', 'y4apk'); ?></b><small><?php esc_html_e('Off: free for everyone, as before.', 'y4apk'); ?></small></span></label>
			<div class="unl-set" data-unl-set>
				<div class="wal-own">
					<label for="unl-price"><?php esc_html_e('Price', 'y4apk'); ?></label>
					<div class="wal-own-in"><?php echo $W::coin_icon(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><input type="number" id="unl-price" name="price" min="<?php echo (int) Y4APK_Paid::MIN; ?>" max="<?php echo (int) Y4APK_Paid::MAX; ?>" step="1" value="<?php echo esc_attr($pv); ?>"></div>
					<span class="wal-own-out" data-unl-gets data-t="<?php echo esc_attr__('You get %1$s a sale · VIP members pay %2$s', 'y4apk'); ?>"></span>
				</div>
				<label class="unl-own"><input type="checkbox" name="own" value="1" <?php checked(!empty($c['old']['own']) || get_post_meta($post->ID, Y4APK_Paid::OWN, true)); ?>><span><?php echo esc_html($u['kind'] === 'file'
					? __('It is my own work: I made this app or tool myself, and I have the right to sell it. Selling somebody else’s game, app or mod is not allowed: it is taken off and the coins go back.', 'y4apk')
					: __('It is my own work: I wrote this script myself, and I have the right to sell it. Selling somebody else’s script is not allowed: it is taken off and the coins go back.', 'y4apk')); ?></span></label>
			</div>
			<button type="submit" class="acc-btn acc-btn-primary wal-go"><span><?php esc_html_e('Save', 'y4apk'); ?></span></button>
			<p class="wal-small"><?php echo esc_html(sprintf(__('A buyer can ask for their coins back within %s days if it does not work or is not what it says; then your share comes back out of your earnings.', 'y4apk'), number_format_i18n(Y4APK_Paid::refund_days()))); ?></p>
		</form>
		<?php endif; ?>
		<?php if ($u['sales']) : ?>
		<div class="wal-stats" style="margin-top:1rem">
			<div><small><?php esc_html_e('Sales', 'y4apk'); ?></small><b><?php echo esc_html(number_format_i18n($u['sales'])); ?></b></div>
			<div><small><?php esc_html_e('You made', 'y4apk'); ?></small><b><?php echo esc_html($W::fmt($u['earned'])); ?></b></div>
		</div>
		<?php endif; ?>
		<?php if ($link !== '') : ?><p class="wal-small"><a href="<?php echo esc_url($link); ?>"><?php esc_html_e('Open the page', 'y4apk'); ?></a> · <a href="<?php echo esc_url($M::url()); ?>"><?php esc_html_e('My uploads', 'y4apk'); ?></a></p><?php endif; ?>
	</section>

	<?php elseif ($u['row']) : $row = $u['row']; $until = Y4APK_Paid::refund_until($row); // already unlocked ?>
	<section class="acc-card">
		<div class="wal-head">
			<span class="wal-head-i is-green"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg></span>
			<div><h2><?php esc_html_e('Unlocked', 'y4apk'); ?></h2><p><?php echo esc_html(sprintf(__('You unlocked it on %1$s for %2$s. It stays on your account.', 'y4apk'), wp_date(get_option('date_format'), (int) $row->made), $W::fmt_coins((int) $row->paid))); ?></p></div>
		</div>
		<?php $dl = $u['kind'] === 'file' ? get_post_meta($post->ID, 'repeatable_download_link', true) : []; ?>
		<?php if ($dl && is_array($dl) && $post->post_status === 'publish') : // 2.69.1: each tap makes a link that expires ?>
			<div class="unl-files">
				<?php foreach ($dl as $i => $row) : ?>
					<a class="acc-btn acc-btn-primary wal-go unl-go" href="<?php echo esc_url(Y4APK_Paid::file_link($post->ID, $i)); ?>" target="_blank" rel="nofollow noopener"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M11 4h2v9.2l3.6-3.6L18 11l-6 6-6-6 1.4-1.4 3.6 3.6V4ZM5 19h14v2H5v-2Z"/></svg><span><?php echo esc_html(trim(sprintf(__('Download %s', 'y4apk'), trim(($row['download_name'] ?? '') . ' ' . ($row['download_version'] ?? ''))) . (!empty($row['download_size']) ? ' · ' . $row['download_size'] : ''))); ?></span></a>
				<?php endforeach; ?>
				<p class="wal-small"><?php echo esc_html(sprintf(__('Each tap makes a download link that works for %s minutes, for you only: a shared link soon stops working.', 'y4apk'), number_format_i18n(Y4APK_Paid::TTL / 60))); ?></p>
			</div>
		<?php elseif ($link !== '') : ?><a class="acc-btn acc-btn-primary wal-go" href="<?php echo esc_url($link . '#download-now'); ?>"><span><?php echo esc_html($u['kind'] === 'file' ? __('Go to the download', 'y4apk') : __('Go to the script', 'y4apk')); ?></span></a><?php endif; ?>
	</section>
	<section class="acc-card" id="refund">
		<h2><?php esc_html_e('Refund', 'y4apk'); ?></h2>
		<?php if ($row->status === 'asked') : ?>
			<p class="wal-wait"><span class="wal-spin"></span><?php esc_html_e('Asked. The site’s team looks at it and emails you.', 'y4apk'); ?></p>
		<?php elseif ($row->status === 'denied') : ?>
			<p class="wal-small"><?php esc_html_e('The site’s team looked at it and found that it works as it says, so the coins stayed with the creator.', 'y4apk'); ?></p>
		<?php elseif ($until) : ?>
			<form method="post" action="<?php echo esc_url(Y4APK_Paid::url($post->ID)); ?>#refund" class="wal-form">
				<input type="hidden" name="y4_do" value="refund">
				<?php echo $M::nonce('unlock'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="wal-small" style="margin:0"><?php echo esc_html(sprintf(__('Until %s you can ask for your coins back if it does not work or is not what it says.', 'y4apk'), wp_date(get_option('date_format'), $until))); ?></p>
				<div class="acc-field acc-input"><label for="unl-why"><?php esc_html_e('What is wrong?', 'y4apk'); ?></label><input type="text" id="unl-why" name="reason" maxlength="190" required placeholder="<?php esc_attr_e('e.g. It does nothing in the game', 'y4apk'); ?>"></div>
				<button type="submit" class="acc-btn wal-go2"><?php esc_html_e('Ask for a refund', 'y4apk'); ?></button>
			</form>
		<?php else : ?>
			<p class="wal-small"><?php echo esc_html(sprintf(__('Refunds can be asked within %s days of unlocking.', 'y4apk'), number_format_i18n(Y4APK_Paid::refund_days()))); ?></p>
		<?php endif; ?>
	</section>

	<?php elseif (!$u['price']) : // nothing to pay ?>
	<section class="acc-card">
		<p><?php esc_html_e('This is free: no need to unlock it.', 'y4apk'); ?></p>
		<?php if ($link !== '') : ?><a class="acc-btn acc-btn-primary" href="<?php echo esc_url($link . '#download-now'); ?>"><?php esc_html_e('Open it', 'y4apk'); ?></a><?php endif; ?>
	</section>

	<?php else : $short = $u['pay'] > $u['have']; // unlock ?>
	<section class="acc-card unl-buy">
		<div class="wal-head">
			<span class="wal-head-i is-gold"><?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div><h2><?php echo esc_html(sprintf(__('Unlock for %s', 'y4apk'), $W::fmt_coins($W::from_coins($u['pay'])))); ?></h2><p><?php echo esc_html($u['kind'] === 'file'
				? __('Pay once and download it whenever you like, without ads.', 'y4apk')
				: __('Pay once and copy it whenever you like.', 'y4apk')); ?><?php if ($u['vip'] && $off > 0) : ?> <?php echo esc_html(sprintf(__('VIP: %s%% off.', 'y4apk'), number_format_i18n($off))); ?><?php endif; ?></p></div>
		</div>
		<p class="unl-have"><?php echo esc_html(sprintf(__('You have %s', 'y4apk'), $W::fmt_coins($W::from_coins($u['have'])))); ?></p>
		<?php if ($short) : ?>
			<a class="acc-btn acc-btn-primary wal-go unl-get" href="<?php echo esc_url($W::url(['need' => $u['pay']]) . '#topup'); ?>"><?php echo $W::coin_icon(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html(sprintf(__('Get %s', 'y4apk'), $cname)); ?></span></a>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url(Y4APK_Paid::url($post->ID)); ?>" class="wal-form">
				<input type="hidden" name="y4_do" value="unlock">
				<?php echo $M::nonce('unlock'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<button type="submit" class="acc-btn acc-btn-primary wal-go unl-go"><?php echo $W::coin_icon(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html(sprintf(__('Unlock for %s', 'y4apk'), number_format_i18n($u['pay']))); ?></span></button>
			</form>
		<?php endif; ?>
		<?php if (!$u['vip'] && $off > 0) : ?><p class="wal-small"><a href="<?php echo esc_url(Y4APK_VIP::url()); ?>"><?php echo esc_html(sprintf(__('VIP members pay %s%% less', 'y4apk'), number_format_i18n($off))); ?></a></p><?php endif; ?>
		<p class="wal-small wal-terms"><?php echo esc_html(sprintf(__('Sold by its creator. You can ask for your coins back within %s days if it does not work or is not what it says. It is for you: sharing it is not allowed.', 'y4apk'), number_format_i18n(Y4APK_Paid::refund_days()))); ?><?php if ($terms !== '') : ?> <a href="<?php echo esc_url($terms); ?>" target="_blank"><?php esc_html_e('Terms', 'y4apk'); ?></a><?php endif; ?></p>
	</section>
	<?php endif; ?>
</div>
<?php if ($u['own'] && $u['why'] === '') : ?>
<script>
(function () {
	var f = document.querySelector('[data-unl-price]');
	if (!f) { return; }
	var sell = f.querySelector('[data-unl-sell]'), box = f.querySelector('[data-unl-set]'), price = f.querySelector('#unl-price'), gets = f.querySelector('[data-unl-gets]');
	var share = parseFloat(f.getAttribute('data-share')) || 0, off = parseFloat(f.getAttribute('data-off')) || 0, per = parseFloat(f.getAttribute('data-per')) || 100;
	var cur = <?php echo wp_json_encode(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?>;
	var paint = function () {
		box.hidden = !sell.checked;
		var n = parseInt(price.value, 10) || 0;
		gets.textContent = n > 0 ? gets.getAttribute('data-t').replace('%1$s', cur + (Math.floor(n / per * (100 - share)) / 100).toFixed(2)).replace('%2$s', String(Math.ceil(n * (100 - off) / 100))) : '';
	};
	sell.addEventListener('change', paint); price.addEventListener('input', paint); paint();
})();
</script>
<?php endif; ?>
