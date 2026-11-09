<?php
/**
 * /account/vip/ (2.62): what VIP gives, the plans, the code box and the
 * member's own VIP history. Included by template/account.php ($c, $M, $v,
 * $messages, $si). Y4APK_VIP::route() filled $c['vip'].
 */
defined('ABSPATH') || exit;

$vp = $c['vip'];
$set = $vp['set'];
$me = $c['user'];
$until = (int) $vp['until'];
$on = $until > time();
$left = $on ? (int) ceil(($until - time()) / DAY_IN_SECONDS) : 0;
$plans = $set['plans'];
$buy = (string) $set['buy_url'];
$pick = 0;
foreach ($plans as $i => $p) {
	if ((int) $p['days'] === 30) {
		$pick = $i; // a month, when there is one
	}
}
$check = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>';
$perks = [
	['noad', __('No ads anywhere', 'y4apk'), __('Pages, downloads and scripts without ads or pop-up tabs.', 'y4apk'),
		'<path d="M4.27 3 3 4.27l3.03 3.03A9.95 9.95 0 0 0 2 12c0 5.52 4.48 10 10 10 1.73 0 3.36-.44 4.78-1.22L19.73 21 21 19.73 4.27 3ZM12 20a8 8 0 0 1-6.43-12.77l11.2 11.2A7.96 7.96 0 0 1 12 20Zm0-16a8 8 0 0 1 6.43 12.77l1.43 1.43A9.95 9.95 0 0 0 22 12c0-5.52-4.48-10-10-10-2.25 0-4.33.75-6 2.01l1.43 1.43A7.96 7.96 0 0 1 12 4Z"/>'],
	['bolt', __('Download at once', 'y4apk'), __('No countdown: the file starts the moment you tap.', 'y4apk'),
		'<path d="M7 2v11h3v9l7-12h-4l4-8H7Z"/>'],
	['crown', __('VIP badge', 'y4apk'), __('A gold VIP badge on your profile, for everyone to see.', 'y4apk'),
		'<path d="M3 7.5 7.5 11 12 4l4.5 7L21 7.5 19.2 18H4.8L3 7.5ZM5 19.5h14V21H5v-1.5Z"/>'],
	['heart', __('Keep the site going', 'y4apk'), __('VIP pays for the servers and new files, every day.', 'y4apk'),
		'<path d="m12 21.35-1.45-1.32C5.4 15.36 2 12.28 2 8.5A5.5 5.5 0 0 1 7.5 3c1.74 0 3.41.81 4.5 2.09A6 6 0 0 1 16.5 3 5.5 5.5 0 0 1 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35Z"/>'],
];
$added = (int) $vp['added'];
?>
<div class="vip">
	<section class="vip-hero<?php echo $on ? ' is-on' : ''; ?>">
		<div class="vip-who">
			<?php if ($me) : ?>
				<?php echo class_exists('Y4APK_Social') ? Y4APK_Social::avatar($me, 60) : $M::avatar($me->user_login, 60); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="vip-who-t">
					<b><?php echo esc_html(class_exists('Y4APK_Social') ? Y4APK_Social::name($me) : $me->display_name); ?><?php echo $on ? ' ' . Y4APK_VIP::badge() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></b>
					<small><?php echo esc_html($on
						? sprintf(_n('VIP until %1$s · %2$s day left', 'VIP until %1$s · %2$s days left', $left, 'y4apk'), wp_date(get_option('date_format'), $until), number_format_i18n($left))
						: __('Not VIP yet', 'y4apk')); ?></small>
				</div>
			<?php else : ?>
				<span class="vip-guest" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5Z"/></svg></span>
				<div class="vip-who-t">
					<a href="<?php echo esc_url($M::url('login')); ?>"><b><?php esc_html_e('Sign in', 'y4apk'); ?></b></a>
					<small><?php esc_html_e('VIP belongs to your account: sign in or create one first.', 'y4apk'); ?></small>
				</div>
			<?php endif; ?>
		</div>
		<span class="vip-gem" aria-hidden="true"><?php echo Y4APK_VIP::crown(46); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
	</section>

	<?php if ($added) : ?>
		<div class="acc-note acc-note-ok" role="status"><?php echo esc_html(sprintf(_n('VIP added: %s day. Enjoy!', 'VIP added: %s days. Enjoy!', $added, 'y4apk'), number_format_i18n($added))); ?></div>
	<?php endif; ?>
	<?php $messages(); ?>

	<?php if (!empty($vp['order'])) : // 2.63: back from the payment page
		$o = $vp['order'];
		$o_done = in_array($o->status, Y4APK_Pay::DONE, true);
		$o_wait = Y4APK_Pay::waiting($o) && !($vp['cancel'] && $o->status === 'waiting');
		?>
		<section class="acc-card vip-order is-<?php echo $o_done ? 'done' : ($o_wait ? 'wait' : 'bad'); ?>" id="order"<?php echo $o_wait ? ' data-vip-wait' : ''; ?>>
			<span class="vip-order-i" aria-hidden="true"><?php echo $o_done ? $check : ($o_wait ? '<span class="vip-spin"></span>' : '!'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="vip-order-t">
				<h2><?php echo esc_html(sprintf(__('Order #%1$s · %2$s', 'y4apk'), $o->id, Y4APK_VIP::plan_label($o->days)) . ($o->gift ? ' · ' . __('gift', 'y4apk') : '')); ?></h2>
				<p><b><?php echo esc_html($vp['cancel'] && !$o_done && $o->status === 'waiting' ? __('You left the payment page: nothing was paid. Pick a plan to try again.', 'y4apk') : Y4APK_Pay::status_text($o)); ?></b></p>
				<?php if ($o_wait) : ?><p class="vip-small"><?php esc_html_e('Crypto takes a few minutes to confirm. This page checks by itself; you can also close it, we email you when it is done.', 'y4apk'); ?></p><?php endif; ?>
				<?php if ($o_done && $o->gift && $o->code !== '') : ?>
					<div class="vip-giftcode"><code><?php echo esc_html(Y4APK_VIP::pretty($o->code)); ?></code><button type="button" class="acc-btn acc-btn-ghost" data-vip-copy="<?php echo esc_attr(Y4APK_VIP::pretty($o->code)); ?>"><?php esc_html_e('Copy', 'y4apk'); ?></button></div>
					<p class="vip-small"><?php esc_html_e('Give this code to anyone: they type it on this page and get the days. It works once.', 'y4apk'); ?></p>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<div class="vip-intro">
		<h1><?php echo esc_html($on ? __('You are VIP', 'y4apk') : __('Go VIP', 'y4apk')); ?></h1>
		<p><?php echo esc_html($on ? __('Add more days any time: they go on the end of the ones you have.', 'y4apk') : __('No ads and no waiting, on every game, app and script.', 'y4apk')); ?></p>
	</div>

	<ul class="vip-perks">
		<?php foreach ($perks as $pk) : ?>
			<li class="vip-perk is-<?php echo esc_attr($pk[0]); ?>">
				<span class="vip-perk-i"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><?php echo $pk[3]; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg></span>
				<span class="vip-perk-t"><b><?php echo esc_html($pk[1]); ?></b><small><?php echo esc_html($pk[2]); ?></small></span>
				<span class="vip-perk-ok"><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ($plans) : ?>
	<section class="vip-plans-box" aria-label="<?php esc_attr_e('Plans', 'y4apk'); ?>">
		<h2><?php esc_html_e('Choose a plan', 'y4apk'); ?></h2>
		<?php if ($vp['pay']) : // 2.63: paid on the site, VIP at once ?>
		<form method="post" action="<?php echo esc_url(Y4APK_VIP::url()); ?>" class="vip-buy">
			<input type="hidden" name="y4_do" value="vip_buy">
			<?php echo $M::nonce('vip'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
		<div class="vip-plans">
			<?php foreach ($plans as $i => $p) : $save = Y4APK_VIP::saving($p); ?>
				<label class="vip-plan">
					<input type="radio" name="vip_plan" value="<?php echo (int) $p['days']; ?>" <?php checked($i, $pick); ?> data-label="<?php echo esc_attr(Y4APK_VIP::money($p['price']) . ' / ' . Y4APK_VIP::plan_label($p['days'])); ?>">
					<?php if ($save !== '') : ?><span class="vip-plan-save"><?php echo esc_html($save); ?></span><?php endif; ?>
					<span class="vip-plan-n"><?php echo esc_html(Y4APK_VIP::plan_label($p['days'])); ?></span>
					<span class="vip-plan-p"><?php echo esc_html(Y4APK_VIP::money($p['price'])); ?></span>
					<?php if ($p['was'] !== '') : ?><s class="vip-plan-was"><?php echo esc_html(Y4APK_VIP::money($p['was'])); ?></s><?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php if ($vp['pay']) : ?>
			<label class="vip-gift"><input type="checkbox" name="gift" value="1"><span><b><?php esc_html_e('Buy as a gift', 'y4apk'); ?></b><small><?php esc_html_e('You get a code to give to a friend; nothing is added to your account.', 'y4apk'); ?></small></span></label>
			<div class="vip-pay-bar">
				<?php if ($me) : ?>
				<button class="acc-btn acc-btn-primary vip-pay" type="submit">
					<span><?php esc_html_e('Pay now', 'y4apk'); ?></span>
					<small data-vip-price><?php echo esc_html(isset($plans[$pick]) ? Y4APK_VIP::money($plans[$pick]['price']) . ' / ' . Y4APK_VIP::plan_label($plans[$pick]['days']) : ''); ?></small>
				</button>
				<?php else : ?>
				<a class="acc-btn acc-btn-primary vip-pay" href="<?php echo esc_url($M::url('login')); ?>"><span><?php esc_html_e('Sign in to pay', 'y4apk'); ?></span><small><?php esc_html_e('VIP is kept with your account', 'y4apk'); ?></small></a>
				<?php endif; ?>
				<p class="vip-pay-note"><?php esc_html_e('Crypto: USDT, BTC, ETH and 300 more, on a secure payment page. VIP is added the moment the payment is confirmed.', 'y4apk'); ?></p>
				<?php if ($buy !== '') : ?><p class="vip-pay-note"><a href="<?php echo esc_url($buy); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e('Other ways to pay', 'y4apk'); ?></a></p><?php endif; ?>
			</div>
		</form>
		<?php elseif ($buy !== '') : ?>
			<div class="vip-pay-bar">
				<a class="acc-btn acc-btn-primary vip-pay" href="<?php echo esc_url($buy); ?>" target="_blank" rel="noopener nofollow">
					<span><?php echo esc_html($set['buy_label'] !== '' ? $set['buy_label'] : __('Get VIP', 'y4apk')); ?></span>
					<small data-vip-price><?php echo esc_html(isset($plans[$pick]) ? Y4APK_VIP::money($plans[$pick]['price']) . ' / ' . Y4APK_VIP::plan_label($plans[$pick]['days']) : ''); ?></small>
				</a>
				<p class="vip-pay-note"><?php esc_html_e('You get a code there: type it in the box below.', 'y4apk'); ?></p>
			</div>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<section class="acc-card vip-redeem" id="redeem">
		<h2><?php esc_html_e('Redeem a code', 'y4apk'); ?></h2>
		<?php if ($me) : ?>
			<form method="post" action="<?php echo esc_url(Y4APK_VIP::url()); ?>#redeem" class="vip-redeem-f">
				<input type="hidden" name="y4_do" value="vip_redeem">
				<?php echo $M::nonce('vip'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<label class="sr-only" for="vip-code"><?php esc_html_e('Your code', 'y4apk'); ?></label>
				<input id="vip-code" name="code" type="text" value="<?php echo esc_attr($v('code')); ?>" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="24" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
				<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Redeem', 'y4apk'); ?></button>
			</form>
			<p class="vip-small"><?php esc_html_e('Each code works once. Its days are added to your VIP straight away.', 'y4apk'); ?></p>
		<?php else : ?>
			<p class="vip-small"><?php esc_html_e('Sign in to redeem a code: VIP is kept with your account.', 'y4apk'); ?></p>
			<div class="vip-redeem-acts">
				<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Sign in', 'y4apk'); ?></a>
				<a class="acc-btn acc-btn-ghost" href="<?php echo esc_url($M::url('register')); ?>"><?php esc_html_e('Create an account', 'y4apk'); ?></a>
			</div>
		<?php endif; ?>
	</section>

	<?php if ($me && $vp['log']) : ?>
	<section class="acc-card vip-log">
		<h2><?php esc_html_e('Your VIP history', 'y4apk'); ?></h2>
		<ul>
			<?php foreach (array_slice($vp['log'], 0, 20) as $row) : ?>
				<li>
					<span><?php echo esc_html(Y4APK_VIP::how_text($row)); ?></span>
					<b class="<?php echo (int) $row['days'] < 0 ? 'is-minus' : ''; ?>"><?php echo esc_html(((int) $row['days'] >= 0 ? '+' : '') . sprintf(_n('%s day', '%s days', abs((int) $row['days']), 'y4apk'), number_format_i18n((int) $row['days']))); ?></b>
					<time><?php echo esc_html(wp_date(get_option('date_format'), (int) $row['at'])); ?></time>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<?php if ($me && !empty($vp['orders'])) : // 2.63 ?>
	<section class="acc-card vip-log vip-orders">
		<h2><?php esc_html_e('Your purchases', 'y4apk'); ?></h2>
		<ul>
			<?php foreach ($vp['orders'] as $o) : $o_done = in_array($o->status, Y4APK_Pay::DONE, true); ?>
				<li>
					<span><?php echo esc_html(sprintf(__('#%1$s · %2$s', 'y4apk'), $o->id, Y4APK_VIP::plan_label($o->days)) . ($o->gift ? ' · ' . __('gift', 'y4apk') : '')); ?><small class="vip-st<?php echo $o_done ? ' is-done' : ''; ?>"><?php echo esc_html(Y4APK_Pay::status_text($o)); ?></small><?php if ($o_done && $o->gift && $o->code !== '') : ?><code><?php echo esc_html(Y4APK_VIP::pretty($o->code)); ?></code><?php endif; ?></span>
					<b><?php echo esc_html(Y4APK_VIP::money($o->amount)); ?></b>
					<time><?php echo esc_html(wp_date(get_option('date_format'), (int) $o->made)); ?></time>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<section class="vip-faq">
		<details><summary><?php esc_html_e('Does VIP renew by itself?', 'y4apk'); ?></summary><p><?php esc_html_e('No. It ends on its date and nothing is charged again. Add days whenever you like.', 'y4apk'); ?></p></details>
		<details><summary><?php esc_html_e('I still see an ad', 'y4apk'); ?></summary><p><?php esc_html_e('Make sure you are signed in on this browser. VIP works on every device you sign in on.', 'y4apk'); ?></p></details>
		<details><summary><?php esc_html_e('Can I give VIP to a friend?', 'y4apk'); ?></summary><p><?php esc_html_e('Yes: give them a code. Whoever redeems it gets the days.', 'y4apk'); ?></p></details>
	</section>
</div>
<script>
(function () {
	var price = document.querySelector('[data-vip-price]');
	document.querySelectorAll('.vip-plan input').forEach(function (r) {
		r.addEventListener('change', function () { if (price && r.checked) { price.textContent = r.getAttribute('data-label'); } });
	});
	document.querySelectorAll('[data-vip-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.getAttribute('data-vip-copy'), label = b.textContent;
			(navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.textContent = '✓'; setTimeout(function () { b.textContent = label; }, 1500); }, function () { window.prompt('', t); });
		});
	});
	// Waiting for the payment to be confirmed: look again every 10 seconds, for 20 minutes at most
	if (document.querySelector('[data-vip-wait]')) {
		var n = 0;
		try { n = +sessionStorage.getItem('y4apk_vip_wait') || 0; sessionStorage.setItem('y4apk_vip_wait', n + 1); } catch (e) {}
		if (n < 120) { setTimeout(function () { location.reload(); }, 10000); }
	} else {
		try { sessionStorage.removeItem('y4apk_vip_wait'); } catch (e) {}
	}
	var code = document.getElementById('vip-code');
	if (code) {
		code.addEventListener('input', function () {
			var raw = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
			var pretty = raw.replace(/(.{4})(?=.)/g, '$1-');
			if (pretty !== code.value) { code.value = pretty; }
		});
	}
})();
</script>
