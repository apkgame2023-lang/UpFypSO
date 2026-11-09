<?php
/**
 * /account/wallet/ (2.64): the balance, topping up, tips (to the site or a
 * creator), a creator's earnings and withdrawals, and the history. Included by
 * template/account.php ($c, $M, $messages). Y4APK_Wallet::route() filled
 * $c['wallet'].
 */
defined('ABSPATH') || exit;

$W = 'Y4APK_Wallet';
$w = $c['wallet'];
$set = $w['set'];
$me = $c['user'];
$bal = $w['bal'];
$cr = $w['creator'];
$to = $w['to'];
$site = $W::site_name();
$heart = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="m12 21.35-1.45-1.32C5.4 15.36 2 12.28 2 8.5A5.5 5.5 0 0 1 7.5 3c1.74 0 3.41.81 4.5 2.09A6 6 0 0 1 16.5 3 5.5 5.5 0 0 1 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35Z"/></svg>';
$check = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>';
$tip_amounts = $W::amounts($to ? $set['tips'] : $set['site_tips']);
$topups = array_values(array_filter($W::amounts($set['topups']), function ($a) use ($set) { return $a >= (float) $set['min_topup']; }));
if (!$topups) {
	$topups = [(float) $set['min_topup']];
}
$crypto_min = max((float) $set['min_topup'], (float) $set['min_tip']);
$old_amount = isset($c['old']['amount']) ? (float) $c['old']['amount'] : 0;
$pick_tip = $old_amount ?: ($tip_amounts[min(1, count($tip_amounts) - 1)] ?? 1);
$methods = $W::methods_on();
$pt = $w['pay_to'];
?>
<div class="wal">

	<section class="wal-hero">
		<div class="wal-hero-t">
			<small><?php esc_html_e('Your money on the site', 'y4apk'); ?></small>
			<b class="wal-total"><?php echo esc_html($W::fmt($bal['total'])); ?></b>
			<div class="wal-split">
				<span><i class="is-credit"></i><?php echo esc_html(sprintf(__('Balance %s', 'y4apk'), $W::fmt($bal['credit']))); ?></span>
				<?php if ($bal['earned'] > 0 || $cr['ok']) : ?><span><i class="is-earned"></i><?php echo esc_html(sprintf(__('Earnings %s', 'y4apk'), $W::fmt($bal['earned']))); ?></span><?php endif; ?>
			</div>
		</div>
		<div class="wal-hero-acts">
			<a class="wal-hbtn is-main" href="#topup"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg><span><?php esc_html_e('Top up', 'y4apk'); ?></span></a>
			<a class="wal-hbtn" href="<?php echo esc_url(Y4APK_VIP::url()); ?>"><?php echo Y4APK_VIP::crown(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('VIP', 'y4apk'); ?></span></a>
			<a class="wal-hbtn" href="<?php echo esc_url($W::tip_url()); ?>"><?php echo $heart; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('Tip us', 'y4apk'); ?></span></a>
		</div>
	</section>

	<?php if ($c['ok'] === 'tipped') : ?>
		<div class="acc-note acc-note-ok" role="status"><?php echo esc_html($to ? sprintf(__('Thank you! Your tip is on its way to @%s.', 'y4apk'), $to->user_login) : sprintf(__('Thank you for supporting %s!', 'y4apk'), $site)); ?></div>
	<?php elseif ($c['ok'] === 'payout') : ?>
		<div class="acc-note acc-note-ok" role="status"><?php esc_html_e('Withdrawal asked for. It is paid by hand, usually within a few days: we email you when it is sent.', 'y4apk'); ?></div>
	<?php endif; ?>
	<?php $messages(); ?>

	<?php if (!empty($w['order'])) : // back from the payment page
		$o = $w['order'];
		$o_done = in_array($o->status, Y4APK_Pay::DONE, true);
		$o_wait = Y4APK_Pay::waiting($o) && !($w['cancel'] && $o->status === 'waiting');
		?>
		<section class="acc-card wal-order is-<?php echo $o_done ? 'done' : ($o_wait ? 'wait' : 'bad'); ?>" id="order"<?php echo $o_wait ? ' data-wal-wait' : ''; ?>>
			<span class="wal-order-i" aria-hidden="true"><?php echo $o_done ? $check : ($o_wait ? '<span class="wal-spin"></span>' : '!'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div>
				<h2><?php echo esc_html(sprintf($o->kind === 'tip' ? __('Tip #%1$s · %2$s', 'y4apk') : __('Top-up #%1$s · %2$s', 'y4apk'), $o->id, Y4APK_VIP::money($o->amount))); ?></h2>
				<p><b><?php echo esc_html($w['cancel'] && !$o_done && $o->status === 'waiting' ? __('You left the payment page: nothing was paid.', 'y4apk') : Y4APK_Pay::status_text($o)); ?></b></p>
				<?php if ($o_wait) : ?><p class="wal-small"><?php esc_html_e('Crypto takes a few minutes to confirm. This page checks by itself; you can also close it, we email you when it is done.', 'y4apk'); ?></p><?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($set['on']) : ob_start(); // tips and top-up; a creator sees their earnings above them ?>
	<section class="acc-card wal-tip<?php echo $w['tipping'] ? ' is-open' : ''; ?>" id="tip">
		<div class="wal-head">
			<?php if ($to) : ?>
				<?php echo Y4APK_Social::avatar($to, 48); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div><h2><?php echo esc_html(sprintf(__('Tip %s', 'y4apk'), Y4APK_Social::name($to))); ?></h2><p><?php echo esc_html(sprintf(__('@%s gets it in their earnings, less the site’s share.', 'y4apk'), $to->user_login)); ?></p></div>
			<?php else : ?>
				<span class="wal-head-i is-red"><?php echo $heart; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div><h2><?php echo esc_html(sprintf(__('Tip %s', 'y4apk'), $site)); ?></h2><p><?php esc_html_e('Any amount helps pay for the servers and keeps new files coming.', 'y4apk'); ?></p></div>
			<?php endif; ?>
		</div>
		<form method="post" action="<?php echo esc_url($W::url(['to' => $to ? $to->user_login : 'site'])); ?>#tip" class="wal-form" data-wal-tip data-have="<?php echo esc_attr($W::plain($bal['total'])); ?>" data-crypto="<?php echo esc_attr($w['pay'] ? $crypto_min : 0); ?>">
			<input type="hidden" name="y4_do" value="wallet_tip">
			<input type="hidden" name="to" value="<?php echo esc_attr($to ? $to->user_login : 'site'); ?>">
			<?php echo $M::nonce('wallet'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="wal-chips">
				<?php foreach ($tip_amounts as $a) : ?>
					<label class="wal-chip"><input type="radio" name="amount_pick" value="<?php echo esc_attr($a); ?>" <?php checked(abs($a - $pick_tip) < .001); ?>><span><?php echo esc_html(Y4APK_VIP::money($a)); ?></span></label>
				<?php endforeach; ?>
				<label class="wal-chip wal-chip-other"><span class="wal-cur"><?php echo esc_html(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?></span><input type="text" name="amount" inputmode="decimal" placeholder="<?php esc_attr_e('Other', 'y4apk'); ?>" value="<?php echo $old_amount && !in_array($old_amount, $tip_amounts, false) ? esc_attr($old_amount) : ''; ?>" aria-label="<?php esc_attr_e('Other amount', 'y4apk'); ?>"></label>
			</div>
			<div class="wal-row">
				<div class="acc-field acc-input"><label for="wal-name"><?php esc_html_e('Your name on the tip', 'y4apk'); ?></label><input type="text" id="wal-name" name="name" maxlength="40" value="<?php echo esc_attr($c['old']['name'] ?? Y4APK_Social::name($me)); ?>" placeholder="<?php esc_attr_e('Leave empty to stay anonymous', 'y4apk'); ?>"></div>
				<div class="acc-field acc-input"><label for="wal-msg"><?php esc_html_e('Message (optional)', 'y4apk'); ?></label><input type="text" id="wal-msg" name="msg" maxlength="160" value="<?php echo esc_attr($c['old']['msg'] ?? ''); ?>" placeholder="<?php esc_attr_e('Thanks for the mod!', 'y4apk'); ?>"></div>
			</div>
			<div class="wal-acts">
				<button type="submit" name="via" value="wallet" class="acc-btn acc-btn-primary wal-go" data-wal-from><?php echo $heart; // phpcs:ignore WordPress.Security.EscapeOutput ?><span data-wal-label="<?php echo esc_attr__('Tip %s from your balance', 'y4apk'); ?>"></span></button>
				<?php if ($w['pay']) : ?>
				<button type="submit" name="via" value="crypto" class="acc-btn acc-btn-ghost wal-go2" data-wal-crypto><span data-wal-label="<?php echo esc_attr__('Pay %s with crypto', 'y4apk'); ?>"></span></button>
				<?php endif; ?>
			</div>
			<p class="wal-small" data-wal-short hidden><?php echo esc_html(sprintf(__('Not enough in your balance (%s).', 'y4apk'), $W::fmt($bal['total']))); ?> <a href="#topup"><?php esc_html_e('Top up', 'y4apk'); ?></a></p>
			<?php if ($w['pay']) : ?><p class="wal-small"><?php echo esc_html(sprintf(__('Tips from %s can also be paid straight away with crypto. Smaller tips come out of your balance.', 'y4apk'), Y4APK_VIP::money($crypto_min))); ?></p><?php endif; ?>
		</form>
		<?php if (!$to && $w['fans']) : ?>
		<div class="wal-fans">
			<h3><?php esc_html_e('Thanks to', 'y4apk'); ?></h3>
			<ul>
				<?php foreach ($w['fans'] as $f) : ?>
					<li><b><?php echo esc_html($f->name !== '' ? $f->name : __('Anonymous', 'y4apk')); ?></b><span><?php echo esc_html($W::fmt($f->amount)); ?></span><?php if ($f->msg !== '') : ?><q><?php echo esc_html($f->msg); ?></q><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</section>

	<section class="acc-card wal-topup" id="topup">
		<div class="wal-head">
			<span class="wal-head-i"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg></span>
			<div><h2><?php esc_html_e('Top up your balance', 'y4apk'); ?></h2><p><?php esc_html_e('Pay once, then spend it on the site: VIP at any price, tips of any size, nothing more to pay each time.', 'y4apk'); ?></p></div>
		</div>
		<?php if ($w['pay']) : ?>
		<form method="post" action="<?php echo esc_url($W::url()); ?>#topup" class="wal-form" data-wal-topup>
			<input type="hidden" name="y4_do" value="wallet_topup">
			<?php echo $M::nonce('wallet'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="wal-chips">
				<?php foreach ($topups as $i => $a) : ?>
					<label class="wal-chip"><input type="radio" name="amount_pick" value="<?php echo esc_attr($a); ?>" <?php checked($i, min(1, count($topups) - 1)); ?>><span><?php echo esc_html(Y4APK_VIP::money($a)); ?></span><?php if ($W::bonus($a) > 0) : ?><em><?php echo esc_html('+' . $W::fmt($W::bonus($a))); ?></em><?php endif; ?></label>
				<?php endforeach; ?>
				<label class="wal-chip wal-chip-other"><span class="wal-cur"><?php echo esc_html(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?></span><input type="text" name="amount" inputmode="decimal" placeholder="<?php esc_attr_e('Other', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Other amount', 'y4apk'); ?>"></label>
			</div>
			<button type="submit" class="acc-btn acc-btn-primary wal-go"><span><?php esc_html_e('Pay with crypto', 'y4apk'); ?></span></button>
			<p class="wal-small"><?php echo esc_html(sprintf(__('From %s: the payment company’s smallest payment. USDT, BTC, ETH and 300 more coins, on a secure payment page; the balance is added the moment the payment is confirmed.', 'y4apk'), Y4APK_VIP::money($set['min_topup']))); ?><?php if ((float) $set['bonus_pct'] > 0) : ?> <?php echo esc_html(sprintf(__('Bonus: +%1$s%% on top-ups from %2$s.', 'y4apk'), number_format_i18n((float) $set['bonus_pct']), Y4APK_VIP::money($set['bonus_from']))); ?><?php endif; ?></p>
		</form>
		<?php else : ?>
			<p class="wal-small"><?php esc_html_e('Topping up opens soon.', 'y4apk'); ?></p>
		<?php endif; ?>
	</section>
	<?php $spend_html = ob_get_clean(); ob_start(); ?>

	<section class="acc-card wal-earn" id="earn">
		<div class="wal-head">
			<span class="wal-head-i is-green"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15.9V19h-2v-1.1c-1.6-.3-2.9-1.3-3-3h1.9c.1.9.8 1.5 2.1 1.5 1.4 0 1.8-.7 1.8-1.2 0-.6-.3-1.2-2-1.6-1.9-.5-3.1-1.2-3.1-2.8 0-1.3 1-2.2 2.3-2.5V7h2v1.3c1.4.3 2.2 1.3 2.3 2.7H15.4c-.1-.8-.5-1.4-1.6-1.4-1.1 0-1.7.5-1.7 1.1 0 .6.5 1 2 1.4 1.5.4 3.1 1 3.1 2.9 0 1.4-1 2.3-2.2 2.6Z"/></svg></span>
			<div><h2><?php esc_html_e('Creator earnings', 'y4apk'); ?></h2><p><?php echo esc_html($W::dl_on()
				? sprintf(__('Creators earn from tips and from every download of the games and apps they share (%s per 1,000 downloads).', 'y4apk'), Y4APK_VIP::money($set['dl_rate']))
				: __('Creators earn from the tips members send them.', 'y4apk')); ?></p></div>
		</div>
		<?php if ($cr['ok']) : ?>
			<div class="wal-stats">
				<div><small><?php esc_html_e('Ready to withdraw', 'y4apk'); ?></small><b><?php echo esc_html($W::fmt($bal['earned'])); ?></b></div>
				<?php if ($w['dl']) : ?>
				<div><small><?php esc_html_e('Downloads today', 'y4apk'); ?></small><b><?php echo esc_html(number_format_i18n($w['dl']['today'])); ?></b><em><?php esc_html_e('added to earnings tomorrow', 'y4apk'); ?></em></div>
				<div><small><?php esc_html_e('Downloads, 30 days', 'y4apk'); ?></small><b><?php echo esc_html(number_format_i18n($w['dl']['month'])); ?></b></div>
				<?php endif; ?>
			</div>
		<?php else : $pct = $cr['need'] ? min(100, (int) floor(100 * $cr['followers'] / $cr['need'])) : 0; ?>
			<div class="wal-goal">
				<p><b><?php echo esc_html(sprintf(__('%1$s of %2$s followers', 'y4apk'), number_format_i18n($cr['followers']), number_format_i18n($cr['need']))); ?></b> · <?php echo esc_html($cr['mode'] === 'off' ? __('Earning is turned off for this account.', 'y4apk') : __('Reach it to start earning from tips and downloads.', 'y4apk')); ?></p>
				<span class="wal-bar"><i style="width:<?php echo esc_attr($pct); ?>%"></i></span>
				<p class="wal-small"><a href="<?php echo esc_url($M::url('share/game')); ?>"><?php esc_html_e('Share games and apps', 'y4apk'); ?></a> · <a href="<?php echo esc_url($M::profile_url($me)); ?>"><?php esc_html_e('Your profile', 'y4apk'); ?></a></p>
			</div>
		<?php endif; ?>

		<?php if ($bal['earned'] > 0 || $w['payouts']) : ?>
			<h3 class="wal-sub"><?php esc_html_e('Withdraw', 'y4apk'); ?></h3>
			<?php if ($w['open']) : ?>
				<p class="wal-wait"><span class="wal-spin"></span><?php echo esc_html(sprintf(__('%1$s by %2$s asked on %3$s: paid by hand, usually within a few days.', 'y4apk'), $W::fmt($w['open']->amount), $W::methods()[$w['open']->method][0] ?? $w['open']->method, wp_date(get_option('date_format'), (int) $w['open']->made))); ?></p>
			<?php elseif (!$methods) : ?>
				<p class="wal-small"><?php esc_html_e('Withdrawals open soon.', 'y4apk'); ?></p>
			<?php elseif ($bal['earned'] < $W::to_m($set['min_payout'])) : ?>
				<p class="wal-small"><?php echo esc_html(sprintf(__('You can withdraw from %1$s (you have %2$s).', 'y4apk'), Y4APK_VIP::money($set['min_payout']), $W::fmt($bal['earned']))); ?></p>
			<?php else : $pick = $c['old']['method'] ?? ($pt['method'] !== '' && isset($methods[$pt['method']]) ? $pt['method'] : array_key_first($methods)); ?>
				<form method="post" action="<?php echo esc_url($W::url()); ?>#earn" class="wal-form wal-out" data-wal-out>
					<input type="hidden" name="y4_do" value="wallet_payout">
					<?php echo $M::nonce('wallet'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="wal-row">
						<div class="acc-field acc-input"><label for="wal-method"><?php esc_html_e('Paid by', 'y4apk'); ?></label>
							<select id="wal-method" name="method">
								<?php foreach ($methods as $k => $m) : ?><option value="<?php echo esc_attr($k); ?>" data-what="<?php echo esc_attr($m[1]); ?>" <?php selected($pick, $k); ?>><?php echo esc_html($m[0]); ?></option><?php endforeach; ?>
							</select>
						</div>
						<div class="acc-field acc-input"><label for="wal-amount"><?php esc_html_e('Amount', 'y4apk'); ?></label><input type="text" id="wal-amount" name="amount" inputmode="decimal" value="<?php echo esc_attr($W::plain($bal['earned'])); ?>"></div>
					</div>
					<div class="acc-field acc-input"><label for="wal-to" data-wal-what><?php echo esc_html($methods[$pick][1] ?? ''); ?></label><input type="text" id="wal-to" name="pay_to" maxlength="120" autocomplete="off" value="<?php echo esc_attr($c['old']['pay_to'] ?? ($pt['method'] === $pick ? $pt['to'] : '')); ?>"></div>
					<button type="submit" class="acc-btn acc-btn-primary wal-go"><span><?php esc_html_e('Ask to withdraw', 'y4apk'); ?></span></button>
					<p class="wal-small"><?php esc_html_e('Check the details: money sent to a wrong address cannot be brought back. For crypto, the network must match.', 'y4apk'); ?></p>
				</form>
			<?php endif; ?>
			<?php if ($w['payouts']) : ?>
				<ul class="wal-list">
					<?php foreach ($w['payouts'] as $p) : ?>
						<li><span><?php echo esc_html(sprintf(__('Withdrawal #%1$s · %2$s', 'y4apk'), $p->id, $W::methods()[$p->method][0] ?? $p->method)); ?><?php if ($p->note !== '' && $p->status !== 'requested') : ?><small><?php echo esc_html($p->note); ?></small><?php endif; ?></span>
							<em class="is-<?php echo esc_attr($p->status); ?>"><?php echo esc_html(['requested' => __('Waiting', 'y4apk'), 'paid' => __('Paid', 'y4apk'), 'rejected' => __('Not paid', 'y4apk')][$p->status] ?? $p->status); ?></em>
							<b><?php echo esc_html($W::fmt($p->amount)); ?></b><time><?php echo esc_html(wp_date(get_option('date_format'), (int) $p->made)); ?></time></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
	$earn_html = ob_get_clean();
	$creator_first = ($cr['ok'] || $bal['earned'] > 0) && !$w['tipping'];
	echo $creator_first ? $earn_html . $spend_html : $spend_html . $earn_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built above from escaped parts
	?>
	<?php else : ?>
		<section class="acc-card"><p class="wal-small"><?php esc_html_e('The wallet is closed at the moment.', 'y4apk'); ?></p></section>
	<?php endif; ?>

	<?php if ($w['log']) : ?>
	<section class="acc-card wal-log">
		<h2><?php esc_html_e('History', 'y4apk'); ?></h2>
		<ul class="wal-list">
			<?php foreach ($w['log'] as $row) : $amt = (int) $row->credit + (int) $row->earned; ?>
				<li><span><?php echo esc_html($W::label($row)); ?></span><b class="<?php echo $amt < 0 ? 'is-minus' : 'is-plus'; ?>"><?php echo esc_html(($amt > 0 ? '+' : '') . $W::fmt($amt)); ?></b><time><?php echo esc_html(wp_date(get_option('date_format'), (int) $row->made)); ?></time></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<?php if ($w['orders']) : ?>
	<section class="acc-card wal-log">
		<h2><?php esc_html_e('Payments', 'y4apk'); ?></h2>
		<ul class="wal-list">
			<?php foreach ($w['orders'] as $o) : ?>
				<li><span><?php echo esc_html(sprintf($o->kind === 'tip' ? __('Tip #%s', 'y4apk') : __('Top-up #%s', 'y4apk'), $o->id)); ?><small><?php echo esc_html(Y4APK_Pay::status_text($o)); ?></small></span><b><?php echo esc_html(Y4APK_VIP::money($o->amount)); ?></b><time><?php echo esc_html(wp_date(get_option('date_format'), (int) $o->made)); ?></time></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>
</div>
<script>
(function () {
	var cur = <?php echo wp_json_encode(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?>;
	var money = function (n) { return cur + (Math.round(n * 100) / 100).toFixed(2); };
	var amountOf = function (f) {
		var other = f.querySelector('input[name="amount"]'), v = other && other.value.trim() !== '' ? parseFloat(other.value.replace(',', '.')) : NaN;
		if (!isNaN(v)) { return v; }
		var r = f.querySelector('input[name="amount_pick"]:checked');
		return r ? parseFloat(r.value) : 0;
	};
	document.querySelectorAll('.wal-chips').forEach(function (box) {
		var other = box.querySelector('input[name="amount"]');
		box.querySelectorAll('input[name="amount_pick"]').forEach(function (r) { r.addEventListener('change', function () { if (other) { other.value = ''; } box.dispatchEvent(new Event('input', {bubbles: true})); }); });
		if (other) { other.addEventListener('focus', function () { box.querySelectorAll('input[name="amount_pick"]').forEach(function (r) { r.checked = false; }); }); }
	});
	var tip = document.querySelector('[data-wal-tip]');
	if (tip) {
		var have = parseFloat(tip.getAttribute('data-have')) || 0, min = parseFloat(tip.getAttribute('data-crypto')) || 0;
		var from = tip.querySelector('[data-wal-from]'), crypto = tip.querySelector('[data-wal-crypto]'), shortNote = tip.querySelector('[data-wal-short]');
		var paint = function () {
			var a = amountOf(tip), enough = a > 0 && a <= have + 1e-9;
			from.querySelector('[data-wal-label]').textContent = from.querySelector('[data-wal-label]').getAttribute('data-wal-label').replace('%s', money(a));
			from.disabled = !enough;
			shortNote.hidden = enough || !(a > 0);
			if (crypto) {
				crypto.querySelector('[data-wal-label]').textContent = crypto.querySelector('[data-wal-label]').getAttribute('data-wal-label').replace('%s', money(a));
				crypto.hidden = !(min > 0 && a >= min);
			}
		};
		tip.addEventListener('input', paint); tip.addEventListener('change', paint); paint();
	}
	var out = document.querySelector('[data-wal-out]');
	if (out) {
		var sel = out.querySelector('select[name="method"]'), what = out.querySelector('[data-wal-what]');
		sel.addEventListener('change', function () { what.textContent = sel.options[sel.selectedIndex].getAttribute('data-what'); out.querySelector('input[name="pay_to"]').value = ''; });
	}
	// Waiting for a payment to be confirmed: look again every 10 seconds, for 20 minutes at most
	if (document.querySelector('[data-wal-wait]')) {
		var n = 0;
		try { n = +sessionStorage.getItem('y4apk_wal_wait') || 0; sessionStorage.setItem('y4apk_wal_wait', n + 1); } catch (e) {}
		if (n < 120) { setTimeout(function () { location.reload(); }, 10000); }
	} else {
		try { sessionStorage.removeItem('y4apk_wal_wait'); } catch (e) {}
	}
})();
</script>
