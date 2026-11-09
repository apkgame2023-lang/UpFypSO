<?php
/**
 * /account/pay/?order=&k= (2.65): paying an order on the site. Pick a coin;
 * NOWPayments answers with the amount and the address, shown here with a QR
 * code; the page follows the payment until it is done, then goes back to the
 * VIP page or the wallet. Included by template/account.php ($c, $M,
 * $messages). Y4APK_Pay::route() filled $c['pay'].
 */
defined('ABSPATH') || exit;

$P = 'Y4APK_Pay';
$p = $c['pay'];
$o = $p['order'];
$meta = $p['meta'];
$live = $p['live'];
$coin = $live ? ($P::COINS[$meta['coin']] ?? [strtoupper($meta['coin']), '', '#29b1d8']) : null;
$badge = function ($k, $c) {
	$sym = ['USDT' => '₮', 'USDC' => '$', 'BTC' => '₿', 'ETH' => 'Ξ', 'LTC' => 'Ł', 'DOGE' => 'Ð', 'XRP' => 'X', 'TRX' => 'T', 'BNB' => 'B', 'SOL' => 'S', 'TON' => 'T'][$c[0]] ?? mb_substr($c[0], 0, 1);
	return '<span class="pay-coin-i" style="--c:' . esc_attr($c[2]) . '" aria-hidden="true">' . esc_html($sym) . '</span>';
};
$step = $live ? 2 : 1;
$copy_i = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1Zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Zm0 16H8V7h11v14Z"/></svg>';
?>
<div class="pay">
	<a class="pay-back" href="<?php echo esc_url(add_query_arg('cancel', 1, $p['back'])); ?>">← <?php esc_html_e('Back', 'y4apk'); ?></a>

	<section class="pay-card">
		<header class="pay-head">
			<div class="pay-head-t">
				<small><?php echo esc_html($p['what']); ?></small>
				<b class="pay-total"><?php echo esc_html(Y4APK_VIP::money($o->amount)); ?></b>
				<span class="pay-ord"><?php echo esc_html(sprintf(__('Order #%s', 'y4apk'), $o->id)); ?></span>
			</div>
			<ol class="pay-steps" aria-label="<?php esc_attr_e('Steps', 'y4apk'); ?>">
				<?php foreach ([1 => __('Coin', 'y4apk'), 2 => __('Send', 'y4apk'), 3 => __('Done', 'y4apk')] as $n => $label) : ?>
					<li class="<?php echo $n < $step ? 'is-done' : ($n === $step ? 'is-on' : ''); ?>"<?php echo $n === 3 ? ' data-pay-step3' : ''; ?>><span><?php echo $n < $step ? '✓' : (int) $n; ?></span><?php echo esc_html($label); ?></li>
				<?php endforeach; ?>
			</ol>
		</header>

		<?php $messages(); ?>

		<?php if (!$live) : ?>
			<h2 class="pay-h"><?php esc_html_e('Choose a coin to pay with', 'y4apk'); ?></h2>
			<form method="post" action="<?php echo esc_url($P::checkout_url($o)); ?>" class="pay-coins">
				<input type="hidden" name="y4_do" value="pay_coin">
				<?php echo $M::nonce('pay'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php foreach ($p['coins'] as $k => $cc) :
					$low = $cc[3] !== null && (float) $o->amount < $cc[3] * 1.03;
					?>
					<button type="submit" name="coin" value="<?php echo esc_attr($k); ?>" class="pay-coin<?php echo $low ? ' is-low' : ''; ?>"<?php echo $low ? ' disabled' : ''; ?>>
						<?php echo $badge($k, $cc); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="pay-coin-t"><b><?php echo esc_html($cc[0]); ?></b><small><?php echo esc_html($cc[1]); ?></small></span>
						<?php if ($low) : ?><em><?php echo esc_html(sprintf(__('From %s', 'y4apk'), Y4APK_VIP::money($cc[3]))); ?></em><?php elseif ($k === 'usdttrc20') : ?><em class="is-best"><?php esc_html_e('Lowest fees', 'y4apk'); ?></em><?php endif; ?>
					</button>
				<?php endforeach; ?>
			</form>
			<p class="pay-note"><?php esc_html_e('Pay from any wallet or exchange (Binance, Trust Wallet, OKX…). Tip: USDT on TRC20 or BEP20 costs the least to send.', 'y4apk'); ?></p>
		<?php else : ?>
			<div class="pay-live" data-pay-state="<?php echo esc_url($p['state']); ?>" data-pay-exp="<?php echo (int) $o->expires; ?>">
				<div class="pay-now">
					<?php echo $badge($meta['coin'], $coin); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="pay-coin-t"><b><?php echo esc_html($coin[0]); ?></b><small><?php echo esc_html(sprintf(__('on %s', 'y4apk'), $coin[1])); ?></small></span>
					<form method="post" action="<?php echo esc_url($P::checkout_url($o)); ?>" class="pay-change"><input type="hidden" name="y4_do" value="pay_change"><?php echo $M::nonce('pay'); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit"><?php esc_html_e('Change coin', 'y4apk'); ?></button></form>
				</div>
				<div class="pay-grid">
					<div class="pay-qr" data-pay-qr="<?php echo esc_attr($meta['address']); ?>" role="img" aria-label="<?php esc_attr_e('QR code of the address', 'y4apk'); ?>"></div>
					<div class="pay-fields">
						<div class="pay-field">
							<label><?php esc_html_e('Send exactly', 'y4apk'); ?></label>
							<div class="pay-copy is-big"><code><?php echo esc_html($meta['amount'] . ' ' . $coin[0]); ?></code><button type="button" data-pay-copy="<?php echo esc_attr($meta['amount']); ?>"><?php echo $copy_i; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('Copy', 'y4apk'); ?></span></button></div>
							<small class="pay-usd"><?php echo esc_html('≈ ' . Y4APK_VIP::money($o->amount)); ?></small>
						</div>
						<div class="pay-field">
							<label><?php echo esc_html(sprintf(__('To this %s address', 'y4apk'), $coin[1])); ?></label>
							<div class="pay-copy"><code class="pay-addr"><?php echo esc_html($meta['address']); ?></code><button type="button" data-pay-copy="<?php echo esc_attr($meta['address']); ?>"><?php echo $copy_i; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('Copy', 'y4apk'); ?></span></button></div>
						</div>
						<?php if (!empty($meta['extra'])) : ?>
						<div class="pay-field is-memo">
							<label><?php esc_html_e('Memo / Tag — required', 'y4apk'); ?></label>
							<div class="pay-copy"><code><?php echo esc_html($meta['extra']); ?></code><button type="button" data-pay-copy="<?php echo esc_attr($meta['extra']); ?>"><?php echo $copy_i; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e('Copy', 'y4apk'); ?></span></button></div>
							<small><?php esc_html_e('Without it the payment cannot be matched to you.', 'y4apk'); ?></small>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<p class="pay-warn"><b><?php echo esc_html(sprintf(__('Send only %1$s on the %2$s network.', 'y4apk'), $coin[0], $coin[1])); ?></b> <?php esc_html_e('From an exchange (Binance…), the amount that arrives must be the amount above: its withdrawal fee comes on top.', 'y4apk'); ?></p>
				<div class="pay-status" data-pay-status="<?php echo esc_attr($o->status); ?>">
					<span class="pay-spin" aria-hidden="true"></span>
					<span class="pay-status-t"><b data-pay-text><?php echo esc_html(Y4APK_Pay::status_text($o)); ?></b><small><?php esc_html_e('This page checks by itself. You can also close it: we email you when it is done.', 'y4apk'); ?></small></span>
					<span class="pay-timer" data-pay-timer title="<?php esc_attr_e('Time left to send', 'y4apk'); ?>"></span>
				</div>
				<div class="pay-done" data-pay-done hidden>
					<span class="pay-done-i" aria-hidden="true">✓</span>
					<b><?php esc_html_e('Paid! Thank you.', 'y4apk'); ?></b>
					<small><?php esc_html_e('Taking you back…', 'y4apk'); ?></small>
				</div>
				<form method="post" action="<?php echo esc_url($P::checkout_url($o)); ?>" class="pay-alt"><input type="hidden" name="y4_do" value="pay_page"><?php echo $M::nonce('pay'); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit"><?php esc_html_e('Having trouble? Pay on NOWPayments’ page instead', 'y4apk'); ?></button></form>
			</div>
		<?php endif; ?>
	</section>

	<p class="pay-foot"><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2Zm-6 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm3.1-9H8.9V6a3.1 3.1 0 0 1 6.2 0v2Z"/></svg><?php esc_html_e('Payments are processed securely by NOWPayments. We never see your wallet or card.', 'y4apk'); ?></p>
</div>
<script>
(function () {
	var T = <?php echo wp_json_encode(['copied' => __('Copied', 'y4apk'), 'up' => __('Time is up: if you have not sent yet, change the coin to get a new address.', 'y4apk')]); ?>;
	document.querySelectorAll('[data-pay-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.getAttribute('data-pay-copy'), s = b.querySelector('span'), was = s.textContent;
			(navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
				s.textContent = T.copied; b.classList.add('is-ok'); setTimeout(function () { s.textContent = was; b.classList.remove('is-ok'); }, 1500);
			}, function () { window.prompt('', t); });
		});
	});
	var qr = document.querySelector('[data-pay-qr]');
	var drawQr = function () { // the QR library comes in the page's footer
		if (!qr || qr.firstChild || !window.qrcode) { return; }
		try { var q = qrcode(0, 'M'); q.addData(qr.getAttribute('data-pay-qr')); q.make(); qr.innerHTML = q.createSvgTag({cellSize: 4, margin: 0, scalable: true}); } catch (e) {}
	};
	drawQr();
	if (qr && !qr.firstChild) { document.addEventListener('DOMContentLoaded', drawQr); window.addEventListener('load', drawQr); }
	var live = document.querySelector('[data-pay-state]');
	if (!live) { return; }
	var url = live.getAttribute('data-pay-state'), exp = +live.getAttribute('data-pay-exp') * 1000, timer = live.querySelector('[data-pay-timer]');
	var text = live.querySelector('[data-pay-text]'), box = live.querySelector('[data-pay-status]'), done = live.querySelector('[data-pay-done]');
	var tick = function () {
		var left = Math.max(0, Math.round((exp - Date.now()) / 1000));
		if (left <= 0) { timer.textContent = '0:00'; timer.classList.add('is-up'); timer.title = T.up; return; }
		timer.textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
		setTimeout(tick, 1000);
	};
	if (exp) { tick(); }
	var n = 0, ask = function () {
		if (++n > 900) { return; } // about two hours
		fetch(url, {credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || !j.ok) { return; }
			text.textContent = j.text; box.setAttribute('data-pay-status', j.status);
			if (j.done) {
				box.hidden = true; done.hidden = false;
				var s3 = document.querySelector('[data-pay-step3]'); if (s3) { s3.className = 'is-on'; }
				setTimeout(function () { location.href = j.back; }, 1600);
				return;
			}
			if (j.status === 'expired' || j.status === 'failed') { location.reload(); return; }
			setTimeout(ask, j.status === 'waiting' ? 8000 : 5000);
		}, function () { setTimeout(ask, 10000); });
	};
	setTimeout(ask, 4000);
})();
</script>
