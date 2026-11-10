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
$coin = $W::coin_icon(20);
$cname = $W::coin_name();
$coins = $W::coins($bal['credit']); // 2.68: what a member spends is coins; earnings stay money
$hold = $w['hold'];
$gifts = $w['gifts'];
$packs = $w['packs'];
$pick_gift = $c['old']['gift'] ?? '';
if (!isset($gifts[$pick_gift])) {
	$pick_gift = (string) array_key_first($gifts);
}
$methods = $W::methods_on();
$pt = $w['pay_to'];
$terms = class_exists('Y4APK_Help') ? Y4APK_Help::url('terms') . '#wallet' : '';
// 2.68.1: any amount; from the VIP page (?need=coins) the amount that covers what is missing
$min_buy = (float) $set['min_topup'];
$buy_amount = isset($c['old']['amount']) && $c['old']['amount'] !== '' ? (float) $c['old']['amount'] : 0;
$need = isset($_GET['need']) ? absint($_GET['need']) : 0;
if (!$buy_amount && $need > $coins) {
	$buy_amount = max($min_buy, ceil(($need - $coins) * 100 / $W::coins_per()) / 100);
}
/** A history line's amount: coins for what was spent or bought, money for earnings. */
$amount_of = function ($credit, $earned) use ($W) {
	$out = [];
	if ((int) $credit !== 0) {
		$out[] = ((int) $credit > 0 ? '+' : '') . $W::fmt_coins($credit);
	}
	if ((int) $earned !== 0) {
		$out[] = ((int) $earned > 0 ? '+' : '') . $W::fmt($earned);
	}
	return implode(' · ', $out);
};
?>
<div class="wal">

	<section class="wal-hero">
		<div class="wal-hero-t">
			<small><?php echo esc_html(sprintf(__('Your %s', 'y4apk'), $cname)); ?></small>
			<b class="wal-total"><?php echo $W::coin_icon(40); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html(number_format_i18n($coins)); ?></span></b>
			<?php if ($bal['earned'] > 0 || $cr['ok']) : ?>
			<div class="wal-split">
				<span><i class="is-earned"></i><?php echo esc_html(sprintf(__('Earnings %s', 'y4apk'), $W::fmt($bal['earned']))); ?></span>
				<?php if ($hold['held'] > 0) : ?><span><i class="is-held"></i><?php echo esc_html(sprintf(__('%s on hold', 'y4apk'), $W::fmt($hold['held']))); ?></span><?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<div class="wal-hero-acts">
			<a class="wal-hbtn is-main" href="#topup"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg><span><?php esc_html_e('Buy coins', 'y4apk'); ?></span></a>
			<a class="wal-hbtn" href="<?php echo esc_url(Y4APK_VIP::url()); ?>"><?php echo Y4APK_VIP::crown(18); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('VIP', 'y4apk'); ?></span></a>
			<a class="wal-hbtn" href="<?php echo esc_url($W::tip_url()); ?>"><?php echo $heart; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('Gift us', 'y4apk'); ?></span></a>
		</div>
	</section>

	<?php if ($c['ok'] === 'tipped') : $g_sent = $W::gift_label(isset($_GET['g']) ? sanitize_key(wp_unslash($_GET['g'])) : ''); ?>
		<div class="acc-note acc-note-ok" role="status"><?php echo esc_html($to ? sprintf(__('Thank you! Your %1$s is on its way to @%2$s.', 'y4apk'), $g_sent !== '' ? $g_sent : __('gift', 'y4apk'), $to->user_login) : sprintf(__('Thank you for supporting %s!', 'y4apk'), $site)); ?></div>
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
				<h2><?php echo esc_html(sprintf($o->kind === 'tip' ? __('Tip #%1$s · %2$s', 'y4apk') : __('Order #%1$s · %2$s', 'y4apk'), $o->id, $o->kind === 'tip' ? Y4APK_VIP::money($o->amount) : Y4APK_Pay::what($o) . ' · ' . Y4APK_VIP::money($o->amount))); ?></h2>
				<p><b><?php echo esc_html($w['cancel'] && !$o_done && $o->status === 'waiting' ? __('You left the payment page: nothing was paid.', 'y4apk') : Y4APK_Pay::status_text($o)); ?></b></p>
				<?php if ($o_wait) : ?><p class="wal-small"><?php esc_html_e('Crypto takes a few minutes to confirm. This page checks by itself; you can also close it, we email you when it is done.', 'y4apk'); ?></p><?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($set['on']) : ob_start(); // gifts and coin packs; a creator sees their earnings above them ?>
	<section class="acc-card wal-tip<?php echo $w['tipping'] ? ' is-open' : ''; ?>" id="tip">
		<div class="wal-head">
			<?php if ($to) : ?>
				<?php echo Y4APK_Social::avatar($to, 48); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div><h2><?php echo esc_html(sprintf(__('Send %s a gift', 'y4apk'), Y4APK_Social::name($to))); ?></h2><p><?php echo esc_html(sprintf(__('@%s gets it in their earnings, less the site’s share.', 'y4apk'), $to->user_login)); ?></p></div>
			<?php else : ?>
				<span class="wal-head-i is-red"><?php echo $heart; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div><h2><?php echo esc_html(sprintf(__('Send %s a gift', 'y4apk'), $site)); ?></h2><p><?php esc_html_e('Every gift helps pay for the servers and keeps new files coming.', 'y4apk'); ?></p></div>
			<?php endif; ?>
		</div>
		<?php if ($gifts) : ?>
		<form method="post" action="<?php echo esc_url($W::url(['to' => $to ? $to->user_login : 'site'])); ?>#tip" class="wal-form" data-wal-tip data-have="<?php echo esc_attr($coins); ?>">
			<input type="hidden" name="y4_do" value="wallet_tip">
			<input type="hidden" name="to" value="<?php echo esc_attr($to ? $to->user_login : 'site'); ?>">
			<?php echo $M::nonce('wallet'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="wal-gifts" role="radiogroup" aria-label="<?php esc_attr_e('Gifts', 'y4apk'); ?>">
				<?php foreach ($gifts as $k => $g) : ?>
					<label class="wal-gift<?php echo $g['coins'] > $coins ? ' is-short' : ''; ?>">
						<input type="radio" name="gift" value="<?php echo esc_attr($k); ?>" data-coins="<?php echo (int) $g['coins']; ?>" data-name="<?php echo esc_attr($g['icon'] . ' ' . $g['name']); ?>" <?php checked($k, $pick_gift); ?>>
						<span class="wal-gift-i" aria-hidden="true"><?php echo esc_html($g['icon']); ?></span>
						<b><?php echo esc_html($g['name']); ?></b>
						<small><?php echo $W::coin_icon(14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(number_format_i18n($g['coins'])); ?></small>
					</label>
				<?php endforeach; ?>
			</div>
			<div class="wal-row">
				<div class="acc-field acc-input"><label for="wal-name"><?php esc_html_e('Your name on the gift', 'y4apk'); ?></label><input type="text" id="wal-name" name="name" maxlength="40" value="<?php echo esc_attr($c['old']['name'] ?? Y4APK_Social::name($me)); ?>" placeholder="<?php esc_attr_e('Leave empty to stay anonymous', 'y4apk'); ?>"></div>
				<div class="acc-field acc-input"><label for="wal-msg"><?php esc_html_e('Message (optional)', 'y4apk'); ?></label><input type="text" id="wal-msg" name="msg" maxlength="160" value="<?php echo esc_attr($c['old']['msg'] ?? ''); ?>" placeholder="<?php esc_attr_e('Thanks for the mod!', 'y4apk'); ?>"></div>
			</div>
			<button type="submit" class="acc-btn acc-btn-primary wal-go" data-wal-from><span data-wal-label="<?php echo esc_attr__('Send %s', 'y4apk'); ?>"><?php esc_html_e('Send', 'y4apk'); ?></span><small data-wal-cost></small></button>
			<p class="wal-small" data-wal-short hidden><?php echo esc_html(sprintf(__('Not enough coins: you have %s.', 'y4apk'), $W::fmt_coins($bal['credit']))); ?> <a href="#topup"><?php esc_html_e('Buy coins', 'y4apk'); ?></a></p>
		</form>
		<?php else : ?>
			<p class="wal-small"><?php esc_html_e('Gifts open soon.', 'y4apk'); ?></p>
		<?php endif; ?>
		<?php if (!$to && $w['fans']) : ?>
		<div class="wal-fans">
			<h3><?php esc_html_e('Thanks to', 'y4apk'); ?></h3>
			<ul>
				<?php foreach ($w['fans'] as $f) : $fg = $W::gift_label($f->gift ?? ''); ?>
					<li><b><?php echo esc_html($f->name !== '' ? $f->name : __('Anonymous', 'y4apk')); ?></b><span><?php echo esc_html($fg !== '' ? $fg : $W::fmt_coins($f->amount)); ?></span><?php if ($f->msg !== '') : ?><q><?php echo esc_html($f->msg); ?></q><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</section>

	<section class="acc-card wal-topup" id="topup">
		<div class="wal-head">
			<span class="wal-head-i is-gold"><?php echo $W::coin_icon(26); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div><h2><?php echo esc_html(sprintf(__('Buy %s', 'y4apk'), $cname)); ?></h2><p><?php esc_html_e('Spend them on VIP and on gifts to creators. Pick a pack or type any amount: bigger amounts give more coins.', 'y4apk'); ?></p></div>
		</div>
		<?php if ($need > $coins) : ?><p class="acc-note"><?php echo esc_html(sprintf(__('You need %1$s more for that VIP plan.', 'y4apk'), number_format_i18n($need - $coins) . ' ' . $cname)); ?></p><?php endif; ?>
		<?php if ($w['pay']) : ?>
		<form method="post" action="<?php echo esc_url($W::url()); ?>#topup" class="wal-form" data-wal-topup data-rates="<?php echo esc_attr(wp_json_encode($W::rates())); ?>" data-min="<?php echo esc_attr($min_buy); ?>">
			<input type="hidden" name="y4_do" value="wallet_topup">
			<?php echo $M::nonce('wallet'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="wal-packs">
				<?php foreach ($packs as $i => $p) : ?>
					<label class="wal-pack">
						<input type="radio" name="pack" value="<?php echo esc_attr($p['price']); ?>" data-price="<?php echo esc_attr(Y4APK_VIP::money($p['price'])); ?>" <?php checked(!$buy_amount && $i === min(1, count($packs) - 1)); ?>>
						<?php if ($p['extra'] > 0) : ?><em><?php echo esc_html(sprintf(__('+%s%%', 'y4apk'), number_format_i18n($p['extra']))); ?></em><?php endif; ?>
						<span class="wal-pack-n"><?php echo $coin; // phpcs:ignore WordPress.Security.EscapeOutput ?><b><?php echo esc_html(number_format_i18n($p['coins'])); ?></b></span>
						<span class="wal-pack-p"><?php echo esc_html(Y4APK_VIP::money($p['price'])); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<div class="wal-own">
				<label for="wal-amount-in"><?php esc_html_e('Or any amount', 'y4apk'); ?></label>
				<div class="wal-own-in"><span class="wal-cur"><?php echo esc_html(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?></span><input type="text" id="wal-amount-in" name="amount" inputmode="decimal" autocomplete="off" placeholder="<?php echo esc_attr(number_format((float) $min_buy, 2, '.', '')); ?>" value="<?php echo $buy_amount ? esc_attr(number_format($buy_amount, 2, '.', '')) : ''; ?>"></div>
				<span class="wal-own-out" data-wal-gets data-t="<?php echo esc_attr__('= %s coins', 'y4apk'); ?>" data-low="<?php echo esc_attr(sprintf(__('From %s', 'y4apk'), Y4APK_VIP::money($min_buy))); ?>"></span>
			</div>
			<button type="submit" class="acc-btn acc-btn-primary wal-go"><span data-wal-label="<?php echo esc_attr__('Pay %s with crypto', 'y4apk'); ?>"><?php esc_html_e('Pay with crypto', 'y4apk'); ?></span></button>
			<p class="wal-small"><?php esc_html_e('USDT, BTC, ETH and 300 more, on a secure payment page; the coins are added the moment the payment is confirmed.', 'y4apk'); ?></p>
			<p class="wal-small wal-terms"><?php echo esc_html(sprintf(__('%s have no cash value: they cannot be cashed out, refunded, or moved to another account except as gifts. Under 18? Ask a parent or guardian before buying.', 'y4apk'), $cname)); ?><?php if ($terms !== '') : ?> <a href="<?php echo esc_url($terms); ?>" target="_blank"><?php esc_html_e('Terms', 'y4apk'); ?></a><?php endif; ?></p>
		</form>
		<?php else : ?>
			<p class="wal-small"><?php esc_html_e('Buying coins opens soon.', 'y4apk'); ?></p>
		<?php endif; ?>
	</section>
	<?php $spend_html = ob_get_clean(); ob_start(); ?>

	<section class="acc-card wal-earn" id="earn">
		<div class="wal-head">
			<span class="wal-head-i is-green"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16Z"/></svg></span>
			<div><h2><?php esc_html_e('Creator earnings', 'y4apk'); ?></h2><p><?php echo esc_html($W::dl_on()
				? __('Creators earn from gifts and from every download of the games and apps they share.', 'y4apk') // 2.67.1: no rate shown
				: __('Creators earn from the gifts members send them.', 'y4apk')); ?></p></div>
		</div>
		<?php if ($cr['ok']) : ?>
			<div class="wal-stats">
				<div><small><?php esc_html_e('Ready to withdraw', 'y4apk'); ?></small><b><?php echo esc_html($W::fmt($hold['free'])); ?></b></div>
				<?php if ($hold['held'] > 0) : ?>
				<div><small><?php esc_html_e('On hold', 'y4apk'); ?></small><b><?php echo esc_html($W::fmt($hold['held'])); ?></b><em><?php echo esc_html(sprintf(_n('gifts wait %s day', 'gifts wait %s days', (int) $set['hold_days'], 'y4apk'), number_format_i18n((int) $set['hold_days']))); ?></em></div>
				<?php endif; ?>
				<?php if ($w['dl']) : ?>
				<div><small><?php esc_html_e('Downloads today', 'y4apk'); ?></small><b><?php echo esc_html(number_format_i18n($w['dl']['today'])); ?></b><em><?php esc_html_e('added to earnings tomorrow', 'y4apk'); ?></em></div>
				<div><small><?php esc_html_e('Downloads, 30 days', 'y4apk'); ?></small><b><?php echo esc_html(number_format_i18n($w['dl']['month'])); ?></b></div>
				<?php endif; ?>
			</div>
		<?php else : $pct = $cr['need'] ? min(100, (int) floor(100 * $cr['followers'] / $cr['need'])) : 0; ?>
			<div class="wal-goal">
				<p><b><?php echo esc_html(sprintf(__('%1$s of %2$s followers', 'y4apk'), number_format_i18n($cr['followers']), number_format_i18n($cr['need']))); ?></b> · <?php echo esc_html($cr['mode'] === 'off' ? __('Earning is turned off for this account.', 'y4apk') : __('Reach it to start earning from gifts and downloads.', 'y4apk')); ?></p>
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
			<?php elseif ($hold['free'] < $W::to_m($set['min_payout'])) : ?>
			<p class="wal-small"><?php echo esc_html(sprintf(__('You can withdraw from %1$s (you have %2$s ready).', 'y4apk'), Y4APK_VIP::money($set['min_payout']), $W::fmt($hold['free']))); ?><?php if ($hold['held'] > 0) : ?> <?php echo esc_html(sprintf(__('%1$s from recent gifts frees up on %2$s.', 'y4apk'), $W::fmt($hold['held']), wp_date(get_option('date_format'), $hold['next']))); ?><?php endif; ?></p>
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
						<div class="acc-field acc-input"><label for="wal-amount"><?php esc_html_e('Amount', 'y4apk'); ?></label><input type="text" id="wal-amount" name="amount" inputmode="decimal" value="<?php echo esc_attr($W::plain($hold['free'])); ?>"></div>
					</div>
					<div class="acc-field acc-input"><label for="wal-to" data-wal-what><?php echo esc_html($methods[$pick][1] ?? ''); ?></label><input type="text" id="wal-to" name="pay_to" maxlength="120" autocomplete="off" value="<?php echo esc_attr($c['old']['pay_to'] ?? ($pt['method'] === $pick ? $pt['to'] : '')); ?>"></div>
					<button type="submit" class="acc-btn acc-btn-primary wal-go"><span><?php esc_html_e('Ask to withdraw', 'y4apk'); ?></span></button>
					<p class="wal-small"><?php esc_html_e('Check the details: money sent to a wrong address cannot be brought back. For crypto, the network must match.', 'y4apk'); ?><?php if ($hold['held'] > 0) : ?> <?php echo esc_html(sprintf(__('%1$s from recent gifts frees up on %2$s.', 'y4apk'), $W::fmt($hold['held']), wp_date(get_option('date_format'), $hold['next']))); ?><?php endif; ?></p>
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
				<li><span><?php echo esc_html($W::label($row)); ?></span><b class="<?php echo $amt < 0 ? 'is-minus' : 'is-plus'; ?>"><?php echo esc_html($amount_of($row->credit, $row->earned)); ?></b><time><?php echo esc_html(wp_date(get_option('date_format'), (int) $row->made)); ?></time></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<?php if ($w['orders']) : ?>
	<section class="acc-card wal-log">
		<h2><?php esc_html_e('Payments', 'y4apk'); ?></h2>
		<ul class="wal-list">
			<?php foreach ($w['orders'] as $o) : ?>
				<li><span><?php echo esc_html($o->kind === 'tip' ? sprintf(__('Tip #%s', 'y4apk'), $o->id) : sprintf(__('#%1$s · %2$s', 'y4apk'), $o->id, Y4APK_Pay::what($o))); ?><small><?php echo esc_html(Y4APK_Pay::status_text($o)); ?></small></span><b><?php echo esc_html(Y4APK_VIP::money($o->amount)); ?></b><time><?php echo esc_html(wp_date(get_option('date_format'), (int) $o->made)); ?></time></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>
</div>
<script>
(function () {
	var fmt = function (n) { try { return n.toLocaleString(<?php echo wp_json_encode(str_replace('_', '-', get_locale())); ?>); } catch (e) { return String(n); } };
	var tip = document.querySelector('[data-wal-tip]');
	if (tip) {
		var have = parseInt(tip.getAttribute('data-have'), 10) || 0;
		var from = tip.querySelector('[data-wal-from]'), label = from.querySelector('[data-wal-label]'), cost = from.querySelector('[data-wal-cost]'), shortNote = tip.querySelector('[data-wal-short]');
		var paint = function () {
			var r = tip.querySelector('input[name="gift"]:checked');
			var n = r ? parseInt(r.getAttribute('data-coins'), 10) : 0, enough = n > 0 && n <= have;
			label.textContent = label.getAttribute('data-wal-label').replace('%s', r ? r.getAttribute('data-name') : '');
			cost.textContent = n ? fmt(n) + ' ' + <?php echo wp_json_encode($cname); ?> : '';
			from.disabled = !enough;
			shortNote.hidden = enough || !n;
		};
		tip.addEventListener('change', paint); paint();
	}
	var top = document.querySelector('[data-wal-topup]');
	if (top) { // 2.68.1: a pack, or any amount from the smallest, with the coins it gets
		var tl = top.querySelector('[data-wal-label]'), own = top.querySelector('input[name="amount"]'), gets = top.querySelector('[data-wal-gets]'), go = top.querySelector('.wal-go');
		var rates = JSON.parse(top.getAttribute('data-rates') || '[]'), min = parseFloat(top.getAttribute('data-min')) || 0, cur = <?php echo wp_json_encode(Y4APK_VIP::currencies()[Y4APK_VIP::settings()['currency']] ?? '$'); ?>;
		var money = function (n) { return cur + n.toFixed(2); };
		var coinsFor = function (a) { var r = rates[0], i; for (i = 1; i < rates.length; i++) { if (rates[i][0] <= a + 1e-9 && rates[i][1] > r[1]) { r = rates[i]; } } return [Math.floor(Math.round(a * r[1] * 10000) / 10000), r[2]]; };
		var paintTop = function () {
			var v = own.value.trim() !== '' ? parseFloat(own.value.replace(',', '.')) : NaN;
			if (!isNaN(v)) {
				top.querySelectorAll('input[name="pack"]').forEach(function (r) { r.checked = false; });
				var ok = v >= min - 1e-9 && v <= 1000, c = coinsFor(v);
				gets.textContent = ok ? gets.getAttribute('data-t').replace('%s', fmt(c[0])) + (c[1] > 0 ? ' (+' + c[1] + '%)' : '') : gets.getAttribute('data-low');
				gets.classList.toggle('is-low', !ok);
				tl.textContent = tl.getAttribute('data-wal-label').replace('%s', money(Math.round(v * 100) / 100));
				go.disabled = !ok;
				return;
			}
			gets.textContent = ''; go.disabled = false;
			var r = top.querySelector('input[name="pack"]:checked');
			if (r) { tl.textContent = tl.getAttribute('data-wal-label').replace('%s', r.getAttribute('data-price')); } else { go.disabled = true; }
		};
		top.querySelectorAll('input[name="pack"]').forEach(function (r) { r.addEventListener('change', function () { own.value = ''; paintTop(); }); });
		own.addEventListener('input', paintTop); paintTop();
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
